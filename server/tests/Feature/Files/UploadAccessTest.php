<?php

use App\Models\Applicant;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\UploadAccess;
use App\Support\Files\Uploads;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Uploaded files (ADR 0087): the bucket is private, and the app streams a file
| only to somebody signed in to the workspace it belongs to who may see its
| record. Everything else — another workspace, no permission, no session, a path
| outside the upload folders — is a 404.
*/

beforeEach(fn () => Storage::fake(Uploads::DISK));

/** Store a file in an upload folder and return its path. */
function storedUpload(string $folder, string $extension = 'pdf', string $contents = '%PDF-1.4 stub'): string
{
    $path = $folder.'/'.Str::random(40).'.'.$extension;
    Storage::disk(Uploads::DISK)->put($path, $contents);

    return $path;
}

/** A second workspace, with a record made inside it. */
function inOtherWorkspace(Closure $make): mixed
{
    $current = app(Tenancy::class)->organization();
    $other = Organization::factory()->create();
    $made = app(Tenancy::class)->runFor($other, $make);

    if ($current !== null) {
        app(Tenancy::class)->set($current);
    }

    return $made;
}

/** Behave like a real request: no tenant left bound from the test's setup. */
function fileRequest(string $url)
{
    app(Tenancy::class)->forget();

    return test()->get($url);
}

test('a recruiter opens a résumé in their own workspace', function () {
    actingAsUserWith(['recruitment.view']);
    $path = storedUpload('applicant-resumes');
    $applicant = Applicant::factory()->create(['resume' => $path]);

    $response = fileRequest($applicant->resumeUrl())->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private')
        ->and($response->streamedContent())->toBe('%PDF-1.4 stub');
});

test('a recruiter in another workspace cannot open that résumé', function () {
    actingAsUserWith(['recruitment.view']);
    $path = storedUpload('applicant-resumes');
    inOtherWorkspace(fn () => Applicant::factory()->create(['resume' => $path]));

    fileRequest(route('files.show', ['path' => $path]))->assertNotFound();
});

test('somebody in the workspace without recruitment access cannot open a résumé', function () {
    actingAsUserWith([]);
    $path = storedUpload('applicant-resumes');
    Applicant::factory()->create(['resume' => $path]);

    fileRequest(route('files.show', ['path' => $path]))->assertNotFound();
});

test('nobody signed out can open a file', function () {
    testOrganization();
    $path = storedUpload('applicant-resumes');
    Applicant::factory()->create(['resume' => $path]);

    fileRequest(route('files.show', ['path' => $path]))->assertRedirect();
});

test('an employee opens their own document but not a colleague\'s', function () {
    $user = actingAsUserWith([]);
    $own = Employee::factory()->create(['user_id' => $user->id]);
    $colleague = Employee::factory()->create();
    $mine = storedUpload('employee-documents');
    $theirs = storedUpload('employee-documents');
    $own->documents()->create(['title' => 'Contract', 'type' => 'contract', 'file' => $mine, 'uploaded_by' => $user->id]);
    $colleague->documents()->create(['title' => 'Contract', 'type' => 'contract', 'file' => $theirs, 'uploaded_by' => $user->id]);

    fileRequest(route('files.show', ['path' => $mine]))->assertOk();
    fileRequest(route('files.show', ['path' => $theirs]))->assertNotFound();
});

test('HR opens any employee document in the workspace, and none outside it', function () {
    $user = actingAsUserWith(['employees.view']);
    $path = storedUpload('employee-documents');
    Employee::factory()->create()->documents()->create(['title' => 'ID', 'type' => 'contract', 'file' => $path, 'uploaded_by' => $user->id]);
    $foreign = storedUpload('employee-documents');
    inOtherWorkspace(fn () => Employee::factory()->create()->documents()->create(['title' => 'ID', 'type' => 'contract', 'file' => $foreign, 'uploaded_by' => $user->id]));

    fileRequest(route('files.show', ['path' => $path]))->assertOk();
    fileRequest(route('files.show', ['path' => $foreign]))->assertNotFound();
});

test('a clock-in photo is for its owner and attendance viewers only', function () {
    $user = actingAsUserWith([]);
    $own = Employee::factory()->create(['user_id' => $user->id]);
    $mine = storedUpload('attendance/punches', 'jpg', 'jpeg-bytes');
    $theirs = storedUpload('attendance/punches', 'jpg', 'jpeg-bytes');
    AttendancePunch::factory()->create(['employee_id' => $own->id, 'photo' => $mine]);
    AttendancePunch::factory()->create(['employee_id' => Employee::factory()->create()->id, 'photo' => $theirs]);

    fileRequest(route('files.show', ['path' => $mine]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    fileRequest(route('files.show', ['path' => $theirs]))->assertNotFound();
});

test('an employee photo is for the workspace, not for other workspaces', function () {
    actingAsUserWith([]);
    $path = storedUpload('employee-photos', 'png', 'png-bytes');
    $employee = Employee::factory()->create(['photo' => $path]);
    $foreign = storedUpload('employee-photos', 'png', 'png-bytes');
    inOtherWorkspace(fn () => Employee::factory()->create(['photo' => $foreign]));

    fileRequest($employee->photo_url)->assertOk();
    fileRequest(route('files.show', ['path' => $foreign]))->assertNotFound();
});

test('a profile photo is seen by people who share a workspace with its owner', function () {
    actingAsUserWith([]);
    $path = storedUpload('profile-photos', 'png', 'png-bytes');
    User::factory()->create(['profile_photo' => $path]);
    $foreign = storedUpload('profile-photos', 'png', 'png-bytes');
    inOtherWorkspace(fn () => User::factory()->create(['profile_photo' => $foreign]));

    fileRequest(route('files.show', ['path' => $path]))->assertOk();
    fileRequest(route('files.show', ['path' => $foreign]))->assertNotFound();
});

test('nothing outside the upload folders is ever served', function (string $path) {
    actingAsSuperAdmin();
    Storage::disk(Uploads::DISK)->put('exports/secret.zip', 'secret');

    fileRequest('/files/'.$path)->assertNotFound();
})->with(['exports/secret.zip', 'applicant-resumes/../exports/secret.zip', 'applicant-resumes/', '.env']);

test('a file of any other type is downloaded, never shown as a page', function () {
    $user = actingAsUserWith(['employees.view']);
    $path = storedUpload('employee-documents', 'html', '<script>alert(1)</script>');
    Employee::factory()->create()->documents()->create(['title' => 'Notes', 'type' => 'contract', 'file' => $path, 'uploaded_by' => $user->id]);

    $response = fileRequest(route('files.show', ['path' => $path]))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('an unchanged file is answered with 304, after the access check', function () {
    actingAsUserWith(['recruitment.view']);
    $path = storedUpload('applicant-resumes');
    Applicant::factory()->create(['resume' => $path]);
    $modified = fileRequest(route('files.show', ['path' => $path]))->headers->get('Last-Modified');

    app(Tenancy::class)->forget();
    $this->get(route('files.show', ['path' => $path]), ['If-Modified-Since' => $modified])->assertStatus(304);
});

test('a company logo is served to anybody, and its route reaches logos only', function () {
    $organization = testOrganization();
    $path = storedUpload('organization-logos', 'png', 'png-bytes');
    $organization->update(['logo' => $path]);

    expect($organization->fresh()->logo_url)->toBe(route('files.logo', ['file' => basename($path)]));

    fileRequest($organization->fresh()->logo_url)->assertOk()->assertHeader('Content-Type', 'image/png');
    fileRequest('/logos/..%2F.env')->assertNotFound();
});

test('the app is given links on its own route, opened with its token for its workspace', function () {
    $user = actingAsUserWith([]);
    $organization = testOrganization();
    $path = storedUpload('employee-photos', 'png', 'png-bytes');
    $employee = Employee::factory()->create(['photo' => $path, 'user_id' => $user->id]);
    $foreign = storedUpload('employee-photos', 'png', 'png-bytes');
    inOtherWorkspace(fn () => Employee::factory()->create(['photo' => $foreign]));

    $token = $user->createToken('mobile');
    $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

    app(Tenancy::class)->forget();
    auth()->forgetGuards();
    $link = $this->withToken($token->plainTextToken)->getJson('/api/me')->assertOk()->json('user.employee.photo');

    expect($link)->toContain('/api/files/');

    app(Tenancy::class)->forget();
    $this->withToken($token->plainTextToken)->get($link)->assertOk();
    app(Tenancy::class)->forget();
    $this->withToken($token->plainTextToken)->get(route('api.files.show', ['path' => $foreign]))->assertNotFound();
});

test('with no workspace bound, nothing but your own profile photo is allowed', function () {
    $user = actingAsUserWith(['recruitment.view']);
    $path = storedUpload('applicant-resumes');
    Applicant::factory()->create(['resume' => $path]);
    $user->update(['profile_photo' => $own = storedUpload('profile-photos', 'png', 'png-bytes')]);

    app(Tenancy::class)->forget();

    expect(app(UploadAccess::class)->allows($user, $path))->toBeFalse()
        ->and(app(UploadAccess::class)->allows($user, $own))->toBeTrue();
});

test('links never point at the storage bucket', function () {
    actingAsUserWith(['recruitment.view']);
    $applicant = Applicant::factory()->create(['resume' => 'applicant-resumes/x.pdf']);

    expect($applicant->resumeUrl())->toBe(route('files.show', ['path' => 'applicant-resumes/x.pdf']))
        ->and($applicant->resumeUrl())->not->toContain('supabase');
});
