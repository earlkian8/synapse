<?php

use App\Jobs\BuildDataExport;
use App\Models\DataExport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Support\DataExport\ArchiveBuilder;
use App\Support\DataExport\DataExportCatalogue;
use App\Support\PermissionRegistry;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('exports');
    Storage::fake('public');
});

/**
 * Build an export of this workspace for a super admin, the way the screen does,
 * and open the archive it wrote.
 *
 * @param  list<string>  $datasets
 * @return array{0: DataExport, 1: ZipArchive}
 */
function buildArchive(array $datasets, string $format = 'csv', bool $files = false): array
{
    $user = User::query()->first() ?? actingAsSuperAdmin();

    $export = DataExport::create([
        'requested_by' => $user->id,
        'status' => DataExport::QUEUED,
        'format' => $format,
        'datasets' => $datasets,
        'include_files' => $files,
    ]);

    BuildDataExport::dispatchSync($export->id);
    $export->refresh();

    expect($export->status)->toBe(DataExport::READY, (string) $export->error);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('exports')->path($export->path)))->toBeTrue();

    return [$export, $zip];
}

/**
 * A CSV file in the archive, as a list of rows keyed by its header.
 *
 * @return list<array<string, string>>
 */
function archiveCsv(ZipArchive $zip, string $name): array
{
    $contents = $zip->getFromName($name);
    expect($contents)->not->toBeFalse("{$name} is missing from the archive.");

    $handle = fopen('php://memory', 'w+b');
    fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $contents));
    rewind($handle);

    $header = fgetcsv($handle, escape: '');
    $rows = [];

    while (($line = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = array_combine($header, $line);
    }

    fclose($handle);

    return $rows;
}

test("the archive holds this workspace's rows and nobody else's", function () {
    actingAsSuperAdmin();
    Employee::factory()->count(2)->create();

    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => Employee::factory()->count(3)->create(['organization_id' => $other->id]));

    [, $zip] = buildArchive(['employees']);

    $rows = archiveCsv($zip, 'employees/employees.csv');

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('organization_id')->unique()->all())->toBe([(string) testOrganization()->id]);
});

test('archived records are exported too', function () {
    actingAsSuperAdmin();
    Department::factory()->create();
    Department::factory()->create()->delete();

    [, $zip] = buildArchive(['structure']);

    $rows = archiveCsv($zip, 'structure/departments.csv');

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->filter(fn (array $row): bool => $row['deleted_at'] !== '')->count())->toBe(1);
});

test('the users of the workspace are exported without their secrets', function () {
    $admin = actingAsSuperAdmin();

    // The factory makes each account a member of whichever workspace is bound.
    $other = Organization::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => User::factory()->create());

    [, $zip] = buildArchive(['users']);

    $rows = archiveCsv($zip, 'users/users.csv');

    expect(collect($rows)->pluck('email')->all())->toBe([$admin->email])
        ->and(array_keys($rows[0]))->not->toContain('password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'email_verification_code');
});

test('sign-in secrets are never exported', function () {
    actingAsSuperAdmin();
    testOrganization()->rotateJoinCode();
    $employee = Employee::factory()->create();

    DB::table('employee_invitations')->insert([
        'organization_id' => testOrganization()->id,
        'employee_id' => $employee->id,
        'email' => 'invitee@example.com',
        'token' => 'secret-token',
        'code' => '123456',
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [, $zip] = buildArchive(['company', 'employees']);

    $company = archiveCsv($zip, 'company/organizations.csv');
    $invitations = archiveCsv($zip, 'employees/employee_invitations.csv');

    expect($company)->toHaveCount(1)
        ->and(array_keys($company[0]))->not->toContain('join_code')
        ->and($invitations[0]['email'])->toBe('invitee@example.com')
        ->and(array_keys($invitations[0]))->not->toContain('token', 'code');
});

test('a manifest and a read-me describe the archive', function () {
    actingAsSuperAdmin();
    Employee::factory()->count(2)->create();

    [$export, $zip] = buildArchive(['employees']);

    $manifest = json_decode($zip->getFromName('manifest.json'), true);

    expect($manifest['format'])->toBe('csv')
        ->and($manifest['organization']['id'])->toBe(testOrganization()->id)
        ->and($manifest['datasets'][0]['key'])->toBe('employees')
        ->and(collect($manifest['datasets'][0]['tables'])->firstWhere('table', 'employees'))->toMatchArray(['file' => 'employees/employees.csv', 'rows' => 2])
        ->and($zip->getFromName('README.txt'))->toContain(testOrganization()->name)
        ->and($export->summary['datasets']['employees']['tables']['employees'])->toBe(2);
});

test('the JSON format writes each table as an array of records', function () {
    actingAsSuperAdmin();
    Employee::factory()->create(['employee_no' => 'EMP-00001']);

    [, $zip] = buildArchive(['employees'], 'json');

    $rows = json_decode($zip->getFromName('employees/employees.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['employee_no'])->toBe('EMP-00001')
        ->and(json_decode($zip->getFromName('employees/employee_documents.json'), true))->toBe([]);
});

test('CSV files open safely in a spreadsheet', function () {
    actingAsSuperAdmin();
    Department::factory()->create(['name' => '=HYPERLINK("https://evil.test","Click")']);

    [, $zip] = buildArchive(['structure']);

    expect($zip->getFromName('structure/departments.csv'))->toStartWith("\xEF\xBB\xBF")
        ->and(archiveCsv($zip, 'structure/departments.csv')[0]['name'])->toBe('\'=HYPERLINK("https://evil.test","Click")');

    [, $json] = buildArchive(['structure'], 'json');

    expect(json_decode($json->getFromName('structure/departments.json'), true)[0]['name'])->toBe('=HYPERLINK("https://evil.test","Click")');
});

test('uploaded files come along when asked for', function () {
    actingAsSuperAdmin();
    Storage::disk('public')->put('employee-photos/ana.jpg', 'photo-bytes');
    Employee::factory()->create(['photo' => 'employee-photos/ana.jpg']);
    Employee::factory()->create(['photo' => 'employee-photos/gone.jpg']);
    Employee::factory()->create(['photo' => 'https://example.com/elsewhere.jpg']);

    [$export, $zip] = buildArchive(['employees'], files: true);

    expect($zip->getFromName('files/employee-photos/ana.jpg'))->toBe('photo-bytes')
        ->and($export->summary['files'])->toMatchArray(['count' => 1, 'missing' => 1]);

    [, $without] = buildArchive(['employees']);

    expect($without->getFromName('files/employee-photos/ana.jpg'))->toBeFalse();
});

test('profile photos come along with the user accounts', function () {
    $admin = actingAsSuperAdmin();
    Storage::disk('public')->put('profile-photos/me.jpg', 'me-bytes');
    $admin->forceFill(['profile_photo' => 'profile-photos/me.jpg'])->save();

    [$export, $zip] = buildArchive(['users'], files: true);

    expect($zip->getFromName('files/profile-photos/me.jpg'))->toBe('me-bytes')
        ->and($export->summary['files']['count'])->toBe(1);
});

test('uploads that would outrun the time a build may take are left out, and counted', function () {
    app()->instance(ArchiveBuilder::class, new ArchiveBuilder(fileBudgetSeconds: 0));

    actingAsSuperAdmin();
    Storage::disk('public')->put('employee-photos/ana.jpg', 'photo-bytes');
    Employee::factory()->create(['photo' => 'employee-photos/ana.jpg']);

    [$export, $zip] = buildArchive(['employees'], files: true);

    expect($zip->getFromName('files/employee-photos/ana.jpg'))->toBeFalse()
        ->and($export->summary['files'])->toMatchArray(['count' => 0, 'skipped' => 1])
        ->and($zip->getFromName('README.txt'))->toContain('not included');
});

test('a failed build is recorded and the requester told', function () {
    $user = actingAsSuperAdmin();

    $export = DataExport::create([
        'requested_by' => $user->id,
        'status' => DataExport::QUEUED,
        'format' => 'csv',
        'datasets' => ['no-such-dataset'],
    ]);

    BuildDataExport::dispatchSync($export->id);

    expect($export->fresh()->status)->toBe(DataExport::FAILED)
        ->and($export->fresh()->error)->not->toBeEmpty()
        ->and($user->notifications()->sole()->data['level'])->toBe('error');
});

// ── The catalogue ────────────────────────────────────────────────────────────

test('every table that holds a workspace\'s records is exported or deliberately left out', function () {
    $catalogued = DataExportCatalogue::tables();
    $excluded = array_keys(DataExportCatalogue::EXCLUDED);

    $tenantTables = collect(Schema::getTableListing(schemaQualified: false))
        ->filter(fn (string $table): bool => Schema::hasColumn($table, 'organization_id'))
        ->values();

    expect($tenantTables->reject(fn (string $table): bool => in_array($table, $catalogued, true) || in_array($table, $excluded, true))->values()->all())
        ->toBe([]);
});

test('every catalogued table exists and every dataset is guarded by a real permission', function () {
    foreach (DataExportCatalogue::tables() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} does not exist.");
    }

    foreach (DataExportCatalogue::DATASETS as $key => $dataset) {
        expect(PermissionRegistry::names())->toContain($dataset['permission']);
    }
});
