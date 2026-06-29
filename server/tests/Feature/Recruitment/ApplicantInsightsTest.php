<?php

use App\Models\JobApplication;
use App\Support\Ai\GeminiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| The applicant AI-insights endpoint: it reads the candidate's résumé and
| supporting documents with the LLM and persists a grounded read on the
| application. Gemini is faked here — no real model call is made.
| See App\Support\Recruitment\ApplicantInsights.
*/

/** A canned Gemini generateContent response wrapping the given JSON insight. */
function fakeGeminiInsight(array $insight): array
{
    return [
        'candidates' => [
            ['content' => ['parts' => [['text' => json_encode($insight)]]]],
        ],
    ];
}

test('it generates, persists, and reads documents — excluding the government ID', function () {
    Storage::fake('public');
    actingAsSuperAdmin();

    Http::fake(['*generativelanguage*' => Http::response(fakeGeminiInsight([
        'headline' => 'Strong backend fit',
        'summary' => 'Solid match for the role.',
        'strengths' => ['Deep PHP experience'],
        'concerns' => ['No leadership history'],
        'document_insights' => 'The résumé shows six years at two SaaS firms.',
        'interview_questions' => ['Walk through your hardest migration.'],
        'recommendation' => 'Advance to an interview.',
    ]))]);

    $application = JobApplication::factory()->create(['stage' => 'screening']);

    Storage::disk('public')->put('applicant-resumes/cv.pdf', '%PDF-1.4 fake');
    Storage::disk('public')->put('applicant-documents/cover.pdf', '%PDF-1.4 cover');
    Storage::disk('public')->put('applicant-documents/id.png', 'PNGDATA');

    $application->applicant->update(['resume' => 'applicant-resumes/cv.pdf']);
    $application->applicant->documents()->create([
        'title' => 'My Cover Letter',
        'type' => 'cover_letter',
        'file' => 'applicant-documents/cover.pdf',
    ]);
    $application->applicant->documents()->create([
        'title' => 'My ID',
        'type' => 'government_id',
        'file' => 'applicant-documents/id.png',
    ]);

    $response = $this->postJson(route('recruitment.applications.insights', $application));

    $response->assertOk()
        ->assertJsonPath('insights.available', true)
        ->assertJsonPath('insights.headline', 'Strong backend fit');

    $read = $response->json('insights.documents_read');
    expect($read)->toContain('Résumé')
        ->toContain('My Cover Letter')
        ->not->toContain('My ID');

    // Persisted on the application so reopening the drawer spends no further call.
    expect($application->fresh()->ai_insights)->not->toBeNull()
        ->and($application->fresh()->ai_insights['headline'])->toBe('Strong backend fit');
});

test('it degrades gracefully when the AI key is not configured', function () {
    actingAsSuperAdmin();

    // Swap in an unconfigured client (the bound singleton was built at boot).
    $this->app->instance(GeminiClient::class, new GeminiClient(apiKey: null, model: 'gemini-2.5-flash'));

    $application = JobApplication::factory()->create();

    $this->postJson(route('recruitment.applications.insights', $application))
        ->assertOk()
        ->assertJsonPath('insights.available', false)
        ->assertJsonPath('insights.retryable', false);

    expect($application->fresh()->ai_insights)->toBeNull();
});
