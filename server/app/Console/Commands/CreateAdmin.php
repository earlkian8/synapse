<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\AdminPasswords;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The way in for the first admin (ADR 0085), and for anyone a server operator
 * needs to let in without the portal. The password is typed at a hidden prompt,
 * never passed as an argument (which shell history and `ps` would keep). The
 * authenticator is set up at the admin's first sign-in.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {email : The new admin\'s email address}
        {--name= : Their full name (asked for when left out)}
        {--role=super_admin : super_admin or admin}';

    protected $description = 'Create an admin portal account';

    public function handle(): int
    {
        $data = [
            'email' => mb_strtolower(trim((string) $this->argument('email'))),
            'name' => trim((string) ($this->option('name') ?: $this->ask('Full name'))),
            'role' => (string) $this->option('role'),
        ];

        $details = Validator::make($data, [
            'email' => ['required', 'email:rfc', 'max:255', 'unique:admins,email'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'in:'.implode(',', array_keys(Admin::ROLES))],
        ]);

        if ($details->fails()) {
            return $this->failWith($details->errors()->all());
        }

        $password = (string) $this->secret('Password (12+ characters, upper and lower case, a number and a symbol)');
        $confirmation = (string) $this->secret('Password again');

        $strength = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'max:255', 'confirmed', AdminPasswords::rule()]],
        );

        if ($strength->fails()) {
            return $this->failWith($strength->errors()->all());
        }

        $admin = Admin::create([
            ...$data,
            'password' => $password,
            'is_active' => true,
            // Chosen by its holder at the prompt, so nothing to replace.
            'must_change_password' => false,
        ]);

        AdminAudit::record('admin_created', 'Created '.mb_strtolower($admin->roleLabel())." account for {$admin->name} at the server console", subject: $admin, properties: ['role' => $admin->role, 'via' => 'console'], subjectLabel: "{$admin->name} ({$admin->email})");

        $this->info("Created {$admin->roleLabel()} {$admin->email}. They set up an authenticator app at their first sign-in.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $messages
     */
    private function failWith(array $messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
