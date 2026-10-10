<?php

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Tenancy;
use App\Support\Training\TrainingCertificates;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Certificates earned by completing training (ADR 0075): issued and withdrawn
| with the completion, numbered and dated, backfilled when a program starts
| issuing them, judged valid / expiring / expired / renewed, reminded about once,
| printable by HR and by their holder — and never removed from the profile.
*/

function tcProgram(array $attributes = []): TrainingProgram
{
    return TrainingProgram::create([
        'name' => 'First Aid',
        'provider' => 'Red Cross',
        'start_date' => today()->subDays(10),
        'end_date' => today()->subDay(),
        'issues_certificate' => true,
        'certificate_validity_months' => 24,
        ...$attributes,
    ]);
}

function tcSeat(TrainingProgram $program, array $employee = [], string $status = 'enrolled'): TrainingEnrollment
{
    return $program->enrollments()->create([
        'employee_id' => Employee::factory()->create(['middle_name' => null, 'suffix' => null, ...$employee])->id,
        'status' => $status,
    ]);
}

/** A training certificate held by an employee, issued and expiring on the given days. */
function tcCertificate(Employee $employee, string $name, string $issued, ?string $expires): EmployeeCertification
{
    $program = tcProgram(['name' => $name.' '.uniqid()]);
    $enrollment = $program->enrollments()->create(['employee_id' => $employee->id, 'status' => 'completed', 'completed_at' => $issued]);

    return EmployeeCertification::create([
        'employee_id' => $employee->id,
        'training_enrollment_id' => $enrollment->id,
        'name' => $name,
        'certificate_no' => 'TC-'.$enrollment->id,
        'issued_date' => $issued,
        'expiry_date' => $expires,
    ]);
}

beforeEach(function () {
    $this->hr = actingAsSuperAdmin();
});

test('completing someone issues a numbered certificate dated that day, valid for the program’s months', function () {
    $program = tcProgram();
    $seat = tcSeat($program);

    $this->patch(route('training.enrollments.update', $seat), ['status' => 'completed', 'score' => 95])->assertRedirect();

    $certificate = $seat->refresh()->certificate;
    $issued = $seat->completed_at->setTimezone('Asia/Manila')->startOfDay();

    expect($certificate)->not->toBeNull()
        ->and($certificate->name)->toBe('First Aid')
        ->and($certificate->issuer)->toBe('Red Cross')
        ->and($certificate->certificate_no)->toBe(sprintf('TC-%s-%05d', $issued->format('Y'), $certificate->id))
        ->and($certificate->issued_date->toDateString())->toBe($issued->toDateString())
        ->and($certificate->expiry_date->toDateString())->toBe($issued->addMonthsNoOverflow(24)->toDateString())
        ->and($certificate->employee_id)->toBe($seat->employee_id);
});

test('undoing the completion, or taking the person off the roster, withdraws the certificate', function () {
    $program = tcProgram();
    $undone = tcSeat($program);
    $removed = tcSeat($program);

    $this->patch(route('training.enrollments.bulk'), ['action' => 'complete', 'enrollment_ids' => [$undone->id, $removed->id]]);

    expect(EmployeeCertification::query()->fromTraining()->count())->toBe(2);

    $this->patch(route('training.enrollments.update', $undone), ['status' => 'enrolled']);
    $this->delete(route('training.enrollments.destroy', $removed));

    expect(EmployeeCertification::query()->fromTraining()->count())->toBe(0);
});

test('a program that issues none issues none; turning it on issues one to everyone who already finished', function () {
    $program = tcProgram(['issues_certificate' => false, 'certificate_validity_months' => null]);
    $done = tcSeat($program);
    tcSeat($program);

    $this->patch(route('training.enrollments.update', $done), ['status' => 'completed']);

    expect($done->refresh()->certificate)->toBeNull();

    $this->post(route('training.update', $program), [
        'name' => 'First Aid',
        'issues_certificate' => true,
        'certificate_validity_months' => 12,
    ])->assertRedirect();
    assertToast('success', '1 certificate issued to people who had completed it.');

    expect($done->refresh()->certificate?->expiry_date)->not->toBeNull();

    // Turning it off keeps what was issued, and clears the validity.
    $this->post(route('training.update', $program), ['name' => 'First Aid', 'issues_certificate' => false, 'certificate_validity_months' => 12]);

    expect($program->refresh()->certificate_validity_months)->toBeNull()
        ->and(EmployeeCertification::query()->count())->toBe(1);
});

test('a certificate with no validity never expires', function () {
    $program = tcProgram(['certificate_validity_months' => null]);
    $seat = tcSeat($program);

    $this->patch(route('training.enrollments.update', $seat), ['status' => 'completed']);

    expect($seat->refresh()->certificate->expiry_date)->toBeNull()
        ->and(TrainingCertificates::standing($seat->certificate))->toBe('valid');
});

test('a certificate is valid, expiring within 60 days, expired — or renewed by a later one of the same name', function () {
    $ana = Employee::factory()->create();
    $valid = tcCertificate($ana, 'Forklift', today()->subYear()->toDateString(), today()->addDays(200)->toDateString());
    $expiring = tcCertificate($ana, 'Welding', today()->subYear()->toDateString(), today()->addDays(30)->toDateString());
    $expired = tcCertificate($ana, 'First Aid', today()->subYears(3)->toDateString(), today()->subDay()->toDateString());
    $renewal = tcCertificate($ana, 'First Aid', today()->subWeek()->toDateString(), today()->addYears(2)->toDateString());

    $renewed = TrainingCertificates::renewedIds(collect([$valid, $expiring, $expired, $renewal]));

    expect(TrainingCertificates::standing($valid))->toBe('valid')
        ->and(TrainingCertificates::standing($expiring))->toBe('expiring')
        ->and(TrainingCertificates::standing($expired, isset($renewed[$expired->id])))->toBe('renewed')
        ->and(TrainingCertificates::standing($renewal, isset($renewed[$renewal->id])))->toBe('valid');

    $this->get(route('training.certificates.index'))->assertInertia(fn (Assert $page) => $page
        ->component('training/certificates')
        ->has('certificates', 4)
        ->where('stats.valid', 2)
        ->where('stats.expiring', 1)
        ->where('stats.expired', 0)
        ->where('stats.renewed', 1));
});

test('holders are told once when a certificate is about to expire, and so is HR — a renewed one is left alone', function () {
    Notification::fake();

    $holder = User::factory()->create(['is_active' => true]);
    $ana = Employee::factory()->create(['user_id' => $holder->id]);
    tcCertificate($ana, 'Welding', today()->subYear()->toDateString(), today()->addDays(30)->toDateString());
    tcCertificate($ana, 'Forklift', today()->subYear()->toDateString(), today()->addDays(300)->toDateString());

    $ben = Employee::factory()->create();
    tcCertificate($ben, 'Welding', today()->subYears(2)->toDateString(), today()->addDays(10)->toDateString());
    tcCertificate($ben, 'Welding', today()->subWeek()->toDateString(), today()->addYears(2)->toDateString());

    $organization = app(Tenancy::class)->organization();
    $this->artisan('training:certificate-reminders', ['--organization' => $organization->id])
        ->expectsOutput('1 expiring certificate flagged.')
        ->assertSuccessful();

    Notification::assertSentTo($holder, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Your certificate expires soon'
        && str_contains($n->body, 'Welding'));
    Notification::assertSentTo($this->hr, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'A training certificate expires soon');

    $this->artisan('training:certificate-reminders', ['--organization' => $organization->id])
        ->expectsOutput('0 expiring certificates flagged.');
});

test('a certificate prints for HR and for its holder, and for nobody else', function () {
    $program = tcProgram();
    $seat = tcSeat($program, ['first_name' => 'Lara', 'last_name' => 'Quinto']);
    $this->patch(route('training.enrollments.update', $seat), ['status' => 'completed']);
    $certificate = $seat->refresh()->certificate;

    $this->get(route('training.certificates.show', $certificate))->assertInertia(fn (Assert $page) => $page
        ->component('training/certificate')
        ->where('certificate.employee.full_name', 'Lara Quinto')
        ->where('certificate.standing', 'valid')
        ->where('back', '/training/certificates'));

    $holder = actingAsUserWith(['training.participate']);
    Employee::query()->whereKey($seat->employee_id)->update(['user_id' => $holder->id]);

    $this->get(route('training.certificates.show', $certificate))->assertInertia(fn (Assert $page) => $page
        ->where('back', '/training/me?tab=certificates'));

    actingAsUserWith(['training.participate']);
    $this->get(route('training.certificates.show', $certificate))->assertNotFound();

    $manual = EmployeeCertification::create(['employee_id' => $seat->employee_id, 'name' => 'PRC License']);
    actingAsUserWith(['training.view']);
    $this->get(route('training.certificates.show', $manual))->assertNotFound();
});

test('a certificate from training is withdrawn in Training, not removed from the profile', function () {
    $program = tcProgram();
    $seat = tcSeat($program);
    $this->patch(route('training.enrollments.update', $seat), ['status' => 'completed']);
    $certificate = $seat->refresh()->certificate;

    $this->delete(route('employees.certifications.destroy', [$seat->employee_id, $certificate->id]))->assertRedirect();
    assertToast('warning', 'This certificate was earned in training.');

    expect($certificate->fresh())->not->toBeNull();
});

test('My training lists the person’s own certificates', function () {
    $user = actingAsUserWith(['training.participate']);
    $mine = Employee::factory()->create(['user_id' => $user->id]);
    tcCertificate($mine, 'Welding', today()->subYear()->toDateString(), today()->addDays(30)->toDateString());
    tcCertificate(Employee::factory()->create(), 'Forklift', today()->subYear()->toDateString(), null);

    $this->get(route('training.me', ['tab' => 'certificates']))->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'certificates')
        ->has('certificates', 1)
        ->where('certificates.0.name', 'Welding')
        ->where('certificates.0.standing', 'expiring'));
});

test('the register exports to CSV', function () {
    $program = tcProgram();
    $seat = tcSeat($program, ['first_name' => 'Lara', 'last_name' => 'Quinto']);
    $this->patch(route('training.enrollments.update', $seat), ['status' => 'completed', 'score' => 88]);

    $csv = $this->get(route('training.certificates.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('Certificate No.')
        ->toContain('Lara Quinto')
        ->toContain('First Aid')
        ->toContain('88.00');
});
