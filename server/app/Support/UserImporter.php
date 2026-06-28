<?php

namespace App\Support;

use App\Http\Controllers\UserManagement\UserController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bulk-creates user accounts from an uploaded CSV, the canonical operation behind
 * the User Management "Import" action. It mirrors the single-user create flow
 * ({@see UserController::store}) per row —
 * validate, create unverified, optionally assign a role, send a verification
 * email + welcome notification — but is **resilient per row**: one bad line never
 * aborts the rest, and every rejected row is reported back with its reason so the
 * admin can fix and re-import.
 *
 * Roles are only honoured when the acting user may assign them; an unknown role
 * name is a row error. Passwords are never imported (the holder verifies their
 * email, then sets one) — so no plaintext secret ever travels through a CSV.
 */
class UserImporter
{
    /** Hard cap on data rows per file — keeps the synchronous request bounded. */
    public const MAX_ROWS = 200;

    /** The columns we read, in template order. Only first/last name + email are required. */
    public const COLUMNS = [
        'first_name', 'middle_name', 'last_name', 'suffix',
        'email', 'phone_number', 'employee_id', 'is_active', 'role',
    ];

    /**
     * Parse and import the uploaded CSV.
     *
     * @return array{created: int, failed: int, total: int, verification_failed: int, errors: list<array{row: int, email: ?string, messages: list<string>}>}
     */
    public function import(UploadedFile $file, User $actor): array
    {
        $rows = $this->readRows($file);

        $result = ['created' => 0, 'failed' => 0, 'total' => 0, 'verification_failed' => 0, 'errors' => []];

        if ($rows === []) {
            $result['errors'][] = ['row' => 1, 'email' => null, 'messages' => ['The file has no data rows.']];

            return $result;
        }

        if (count($rows) > self::MAX_ROWS) {
            $result['errors'][] = ['row' => 1, 'email' => null, 'messages' => [
                'The file has '.count($rows).' rows; please import at most '.self::MAX_ROWS.' at a time.',
            ]];

            return $result;
        }

        $canAssignRoles = $actor->can('roles.assign');
        $roleLookup = $canAssignRoles ? $this->roleLookup() : collect();

        // Track emails already seen in THIS file so an in-file duplicate is caught
        // even before the database unique rule would (the first row still imports).
        $seenEmails = [];

        foreach ($rows as $entry) {
            $result['total']++;
            $line = $entry['line'];
            $data = $entry['data'];
            $email = $data['email'] !== '' ? Str::lower($data['email']) : null;

            $messages = $this->validateRow($data, $line, $seenEmails, $canAssignRoles, $roleLookup);

            if ($messages !== []) {
                $result['failed']++;
                $result['errors'][] = ['row' => $line, 'email' => $email, 'messages' => $messages];

                continue;
            }

            if ($email !== null) {
                $seenEmails[$email] = true;
            }

            try {
                $sent = $this->createUser($data, $email, $actor, $canAssignRoles, $roleLookup);
                $result['created']++;

                if (! $sent) {
                    $result['verification_failed']++;
                }
            } catch (\Throwable $e) {
                report($e);
                $result['failed']++;
                $result['errors'][] = ['row' => $line, 'email' => $email, 'messages' => ['Could not be created — an unexpected error occurred.']];
            }
        }

        if ($result['created'] > 0) {
            ActivityLogger::log(
                event: 'imported',
                description: "Imported {$result['created']} ".($result['created'] === 1 ? 'user' : 'users').' via CSV',
                properties: ['created' => $result['created'], 'failed' => $result['failed'], 'total' => $result['total']],
                logName: 'user_management',
            );
        }

        return $result;
    }

    /**
     * Validate one row, returning a flat list of human messages (empty = valid).
     *
     * @param  array<string, string>  $data
     * @param  array<string, bool>  $seenEmails
     * @param  Collection<string, Role>  $roleLookup
     * @return list<string>
     */
    private function validateRow(array $data, int $line, array $seenEmails, bool $canAssignRoles, $roleLookup): array
    {
        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:32'],
            // No global uniqueness: an existing identity is linked into this org
            // (see createUser). Only a duplicate *within this org* is rejected.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'employee_id' => ['nullable', 'string', 'max:255'],
        ], [], [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'employee_id' => 'employee ID',
        ]);

        $messages = collect($validator->errors()->all());

        $email = Str::lower($data['email']);

        // In-file duplicate (caught before the per-row create).
        if ($email !== '' && isset($seenEmails[$email])) {
            $messages->push('The email is duplicated earlier in this file.');
        }

        // Already a member of this organisation (the identity may exist in others).
        $orgId = app(Tenancy::class)->id();

        if ($email !== '' && $orgId !== null) {
            $existing = User::where('email', $email)->first();

            if ($existing !== null && $existing->isMemberOf($orgId)) {
                $messages->push('A user with this email is already in this organisation.');
            }
        }

        // Role column: only meaningful when the importer may assign roles.
        if ($data['role'] !== '' && $canAssignRoles && ! $roleLookup->has(Str::lower($data['role']))) {
            $messages->push("The role \"{$data['role']}\" does not exist.");
        }

        return $messages->unique()->values()->all();
    }

    /**
     * Create one user and fire its side-effects. Returns whether the verification
     * email was dispatched.
     *
     * @param  array<string, string>  $data
     * @param  Collection<string, Role>  $roleLookup
     */
    private function createUser(array $data, ?string $email, User $actor, bool $canAssignRoles, $roleLookup): bool
    {
        $organization = app(Tenancy::class)->organization();

        // Reuse an existing identity (someone who works for another company) by
        // adding them to this organisation, or create a new one.
        $user = User::where('email', $email)->first();
        $isExisting = $user !== null;

        if (! $isExisting) {
            $user = new User([
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?: null,
                'last_name' => $data['last_name'],
                'suffix' => $data['suffix'] ?: null,
                'email' => $email,
                'phone_number' => $data['phone_number'] ?: null,
                'employee_id' => $data['employee_id'] ?: null,
                'is_active' => $this->parseBool($data['is_active'], true),
            ]);

            $user->save();
        }

        OrganizationProvisioner::addMember($organization, $user, default: ! $isExisting);

        if ($data['role'] !== '' && $canAssignRoles) {
            $role = $roleLookup->get(Str::lower($data['role']));

            if ($role) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        }

        // An existing identity already has a verified address and password; only a
        // freshly created account needs to verify and gets the welcome email.
        if ($isExisting) {
            Notifier::toUser(
                $user,
                "You've been added to {$organization->name}",
                "Your SYNAPSE account now has access to {$organization->name}.",
                url: '/dashboard',
                level: 'success',
                category: 'account',
                actor: $actor,
            );

            return true;
        }

        $sent = $this->sendVerification($user);

        Notifier::toUser(
            $user,
            'Welcome to SYNAPSE',
            'Your account has been created. Please check your inbox to verify your email address, then sign in to get started.',
            url: '/dashboard',
            level: 'success',
            category: 'account',
            actor: $actor,
        );

        return $sent;
    }

    /**
     * Read the CSV into rows of [line => spreadsheet row number, data => column map].
     * The header row is matched case-insensitively; unknown columns are ignored and
     * missing ones default to empty strings.
     *
     * @return list<array{line: int, data: array<string, string>}>
     */
    private function readRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [];
        }

        $rows = [];
        $map = null;
        $line = 0;

        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;

            // Skip fully blank lines.
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue;
            }

            if ($map === null) {
                $map = $this->headerMap($cells);

                continue;
            }

            $data = [];

            foreach (self::COLUMNS as $column) {
                $index = $map[$column] ?? null;
                $data[$column] = $index !== null && isset($cells[$index]) ? trim((string) $cells[$index]) : '';
            }

            $data['email'] = Str::lower($data['email']);
            $rows[] = ['line' => $line, 'data' => $data];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Build a column-name → header-index map, tolerant of case, spaces and a BOM.
     *
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $name) {
            $key = Str::of((string) $name)
                ->replace("\u{FEFF}", '')
                ->trim()
                ->lower()
                ->replace([' ', '-'], '_')
                ->toString();

            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * The tenant's roles keyed by BOTH their lowercased machine name and label, so
     * a CSV can reference either "hr-manager" or "HR Manager".
     *
     * @return Collection<string, Role>
     */
    private function roleLookup()
    {
        return Role::all()->reduce(function ($lookup, Role $role) {
            $lookup->put(Str::lower($role->name), $role);
            $lookup->put(Str::lower($role->label), $role);

            return $lookup;
        }, collect());
    }

    /**
     * Interpret a loose truthy/falsy cell, falling back to $default when blank.
     */
    private function parseBool(string $value, bool $default): bool
    {
        $value = Str::lower(trim($value));

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'y', 'active'], true);
    }

    /**
     * Email the verification link, best-effort (a mail outage never fails the row).
     */
    private function sendVerification(User $user): bool
    {
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
