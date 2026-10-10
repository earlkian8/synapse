<?php

use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\AssistantConversation;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Attachments\ConversationAttachments;
use App\Services\Assistant\Modules\EmployeeRecordsModule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/*
| Filing a chat attachment onto a record (ADR 0068 §4): the CV the user sent is
| read by the model for the candidate's details and stored as their résumé —
| the turn that was reported as failing, end to end.
*/

beforeEach(function () {
    Storage::fake('assistant');
    Storage::fake('public');
});

function filingRecruiter(): void
{
    actingAsUserWith(['recruitment.view', 'recruitment.create', 'recruitment.update', 'recruitment.manage-pipeline']);
    seedDefaultPipeline();
    JobPosting::factory()->create(['title' => 'Cybersecurity Analyst', 'status' => 'open']);
}

/** The calls a model makes after reading Earl's CV. */
function filingCalls(string $resume = '1'): array
{
    return [
        [modelCall('add_application', [
            'posting' => 'Cybersecurity Analyst', 'first_name' => 'Earl', 'last_name' => 'Bancayrin',
            'email' => 'earl@example.com', 'headline' => 'Security Engineer', 'years_experience' => 3,
            'source' => 'other', 'resume_attachment' => $resume,
        ])],
        [modelCall('move_application', ['applicant' => 'Earl Bancayrin', 'stage' => 'Offer'])],
        [['text' => 'Done.']],
    ];
}

function sendCv(array $script, string $mode = 'balanced', string $name = 'Bancayrin_Curriculum_Vitae.pdf', string $mime = 'application/pdf'): array
{
    RateLimiter::clear('assistant-min:'.auth()->id());
    RateLimiter::clear('assistant-day:'.auth()->id());
    fakeAssistantModel($script);

    return test()->post(route('assistant'), [
        'message' => 'put this person in cybersecurity analyst as an offer',
        'mode' => $mode,
        'files' => [UploadedFile::fake()->createWithContent($name, '%PDF-1.4 Earl Bancayrin CV')->mimeType($mime)],
    ], ['Accept' => 'application/json'])->assertOk()->json();
}

test('the reported turn: one card for the whole request, and Confirm files the CV and places the candidate at Offer', function () {
    filingRecruiter();

    $turn = sendCv(filingCalls());
    $card = collect($turn['message']['actions'])->firstWhere('kind', 'confirm');

    expect($card['plan'])->toHaveCount(2)
        ->and(Applicant::count())->toBe(0);

    $this->postJson(route('assistant.actions.confirm'), ['token' => $card['confirmation']['token']])->assertOk();

    $applicant = Applicant::where('email', 'earl@example.com')->first();
    $application = JobApplication::with('pipelineStage')->where('applicant_id', $applicant?->id)->first();

    expect($applicant->headline)->toBe('Security Engineer')
        ->and($applicant->resume)->toStartWith('applicant-resumes/')
        ->and(Storage::disk('public')->get($applicant->resume))->toBe('%PDF-1.4 Earl Bancayrin CV')
        ->and($application->pipelineStage->name)->toBe('Offer')
        ->and(ActivityLog::where('description', 'like', '%via assistant%')->count())->toBeGreaterThanOrEqual(2);
});

test('in Auto mode the same turn is done straight away', function () {
    filingRecruiter();

    $turn = sendCv(filingCalls(), mode: 'auto');

    $applicant = Applicant::where('email', 'earl@example.com')->first();

    expect($applicant?->resume)->not->toBeNull()
        ->and(JobApplication::with('pipelineStage')->first()->pipelineStage->name)->toBe('Offer')
        ->and($turn['message']['body'])->toBe('Done.');
});

test('a file of a type a résumé cannot be is refused in the screen’s own terms, and nobody is created', function () {
    filingRecruiter();

    $turn = sendCv([filingCalls()[0], [['text' => 'Could not.']]], mode: 'auto', name: 'notes.txt', mime: 'text/plain');

    expect(Applicant::count())->toBe(0)
        ->and($turn['message']['steps'][0]['detail'])->toContain('pdf, doc, docx, jpg, jpeg, png');
});

test('an attachment that is not in this conversation is refused, and nobody is created', function () {
    filingRecruiter();

    $turn = sendCv([filingCalls('7')[0], [['text' => 'Could not.']]], mode: 'auto');

    expect(Applicant::count())->toBe(0)
        ->and($turn['message']['steps'][0]['detail'])->toContain('No attachment “7”');
});

test('supporting documents are filed with their type, and a new résumé replaces the old one', function () {
    filingRecruiter();
    sendCv([
        [modelCall('add_applicant', ['first_name' => 'Earl', 'last_name' => 'Bancayrin', 'source' => 'other', 'document_attachments' => [['attachment' => '1', 'type' => 'certificate']]])],
        [modelCall('update_applicant', ['applicant' => 'Earl Bancayrin', 'resume_attachment' => '1'])],
        [modelCall('update_applicant', ['applicant' => 'Earl Bancayrin', 'resume_attachment' => 'Bancayrin_Curriculum_Vitae.pdf', 'phone' => '0917'])],
        [['text' => 'Done.']],
    ], mode: 'auto');

    $applicant = Applicant::first();

    expect($applicant->documents()->pluck('type')->all())->toBe(['certificate'])
        ->and($applicant->phone)->toBe('0917')
        ->and(Storage::disk('public')->files('applicant-resumes'))->toHaveCount(1);
});

test('add_application can place a candidate straight at a stage', function () {
    filingRecruiter();
    fakeAssistantModel([
        [modelCall('add_application', ['posting' => 'Cybersecurity Analyst', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'stage' => 'Interview'])],
        [['text' => 'Done.']],
    ]);

    app(Assistant::class)->handle(auth()->user(), 'add Ana Cruz to cybersecurity analyst at interview');

    expect(JobApplication::with('pipelineStage')->first()->pipelineStage->name)->toBe('Interview');
});

// ── Employee records ─────────────────────────────────────────────────────────

test('a document is filed on an employee’s 201 file only after Confirm', function () {
    actingAsUserWith(['employees.view', 'employees.manage-documents']);
    $maria = Employee::factory()->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null]);
    RateLimiter::clear('assistant-min:'.auth()->id());
    RateLimiter::clear('assistant-day:'.auth()->id());
    fakeAssistantModel([
        [modelCall('add_employee_document', ['employee' => 'Maria Santos', 'attachment' => '1', 'type' => 'contract', 'title' => 'Employment contract 2026'])],
        [['text' => 'Queued.']],
    ]);

    $turn = $this->post(route('assistant'), [
        'message' => 'add this contract to Maria Santos 201 file',
        'mode' => 'auto',
        'files' => [UploadedFile::fake()->createWithContent('contract.pdf', '%PDF contract')->mimeType('application/pdf')],
    ], ['Accept' => 'application/json'])->assertOk()->json();

    expect($maria->documents()->count())->toBe(0);

    $token = collect($turn['message']['actions'])->firstWhere('kind', 'confirm')['confirmation']['token'];
    $this->postJson(route('assistant.actions.confirm'), ['token' => $token])->assertOk();

    $document = $maria->documents()->first();

    expect($document->title)->toBe('Employment contract 2026')
        ->and($document->type)->toBe('contract')
        ->and(Storage::disk('public')->get($document->file))->toBe('%PDF contract')
        ->and(ActivityLog::where('description', 'like', 'Added document%via assistant')->exists())->toBeTrue();
});

test('filing a document needs the documents permission', function () {
    $viewer = actingAsUserWith(['employees.view']);

    $names = array_column(app(EmployeeRecordsModule::class)->tools($viewer), 'name');

    expect($names)->not->toContain('add_employee_document');
});

test('an attachment resolves only in the conversation the plan came from', function () {
    actingAsUserWith(['employees.view']);
    $conversation = AssistantConversation::create(['user_id' => auth()->id(), 'title' => 'x']);

    $inbox = app(ConversationAttachments::class);
    $inbox->use($conversation, auth()->user());

    expect($inbox->resolve('1'))->toBeNull();
});
