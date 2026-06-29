<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\SubmitApplicationRequest;
use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Support\ActivityLogger;
use App\Support\ApplicantDocumentStore;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public, unauthenticated careers surface.
 *
 * Each organisation has a careers board at `/careers/{slug}`; every open posting
 * has its own shareable page at `/careers/{slug}/jobs/{hashid}` where a candidate
 * applies with their CV and supporting files. Submissions are stamped with the
 * posting's organisation via {@see Tenancy::runFor()} — there is no logged-in
 * tenant here. The apply route is rate-limited and honeypot-guarded.
 */
class CareersController extends Controller
{
    /**
     * `/careers` with no organisation: convenience redirect for single-tenant
     * installs; ambiguous (and so hidden) when several organisations exist.
     */
    public function landing(): RedirectResponse
    {
        $organizations = Organization::query()->limit(2)->get();

        abort_unless($organizations->count() === 1, 404);

        return redirect()->route('careers.board', $organizations->first());
    }

    /**
     * An organisation's public board of open roles.
     */
    public function board(Organization $organization): Response
    {
        $postings = JobPosting::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'open')
            // Hide roles whose deadline has passed (the auto-close job flips them
            // to "closed" daily; this keeps the board correct in between runs).
            ->where(fn ($query) => $query
                ->whereNull('closing_date')
                ->orWhereDate('closing_date', '>=', now()->toDateString()))
            ->with(['department:id,name', 'position:id,title'])
            ->latest()
            ->get();

        return Inertia::render('careers/index', [
            'organization' => $this->organizationPayload($organization),
            'postings' => $postings->map(fn (JobPosting $posting) => $this->postingCard($posting))->all(),
        ]);
    }

    /**
     * A single open posting with its application form.
     */
    public function show(Request $request, Organization $organization, JobPosting $jobPosting): Response
    {
        $this->ensureOpenPosting($organization, $jobPosting);

        $jobPosting->load(['department:id,name', 'position:id,title']);

        return Inertia::render('careers/show', [
            'organization' => $this->organizationPayload($organization),
            'posting' => $this->postingDetail($jobPosting),
            'documentTypes' => $this->documentTypeOptions(),
            // Set by the post-submission redirect so the page shows a confirmation.
            'applied' => $request->boolean('applied'),
        ]);
    }

    /**
     * Receive a public application.
     */
    public function apply(SubmitApplicationRequest $request, Organization $organization, JobPosting $jobPosting): RedirectResponse
    {
        $this->ensureOpenPosting($organization, $jobPosting);

        // Bots fill the hidden honeypot — accept silently so they learn nothing.
        if ($request->filled('hp_field')) {
            return $this->confirmation($organization, $jobPosting);
        }

        $data = $request->validated();

        $application = app(Tenancy::class)->runFor($organization, function () use ($request, $data, $jobPosting): ?object {
            $profile = Arr::only($data, [
                'first_name', 'last_name', 'phone', 'current_location',
                'headline', 'linkedin_url', 'portfolio_url', 'years_experience',
            ]);

            // Reuse the candidate's pool record by email; otherwise create one.
            $applicant = Applicant::where('email', $data['email'])->first();

            if ($applicant) {
                $applicant->fill($profile);
            } else {
                $applicant = new Applicant([...$profile, 'email' => $data['email'], 'source' => 'website']);
            }

            if ($request->hasFile('resume')) {
                if ($applicant->resume) {
                    Storage::disk('public')->delete($applicant->resume);
                }
                $applicant->resume = $request->file('resume')->store('applicant-resumes', 'public');
            }

            $applicant->save();

            ApplicantDocumentStore::store($applicant, $data['documents'] ?? [], null);

            // A candidate applies to a given posting at most once.
            if ($jobPosting->applications()->where('applicant_id', $applicant->id)->exists()) {
                return null;
            }

            $application = $jobPosting->applications()->create([
                'applicant_id' => $applicant->id,
                'stage' => 'applied',
                'expected_salary' => $data['expected_salary'] ?? null,
                'cover_note' => $data['cover_note'] ?? null,
                'applied_at' => now(),
            ]);

            ActivityLogger::log(
                event: 'created',
                description: "{$applicant->full_name} applied for \"{$jobPosting->title}\" via the careers page",
                subject: $jobPosting,
                logName: 'recruitment',
                subjectLabel: $jobPosting->title,
            );

            Notifier::toRole(
                'hr-manager',
                'New application',
                "{$applicant->full_name} applied for {$jobPosting->title} via the careers page.",
                url: '/recruitment/'.$jobPosting->getRouteKey(),
                category: 'recruitment',
            );

            return $application;
        });

        if ($application === null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => "You've already applied to this role."]);

            return back();
        }

        return $this->confirmation($organization, $jobPosting);
    }

    /**
     * 404 unless the posting belongs to the organisation and is open to applicants.
     */
    private function ensureOpenPosting(Organization $organization, JobPosting $jobPosting): void
    {
        abort_unless(
            $jobPosting->organization_id === $organization->id
                && $jobPosting->status === 'open'
                && ! $jobPosting->isExpired(),
            404,
        );
    }

    /**
     * Redirect back to the posting with a success confirmation.
     */
    private function confirmation(Organization $organization, JobPosting $jobPosting): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Application received — thank you!']);

        return redirect()->route('careers.show', [$organization, $jobPosting, 'applied' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationPayload(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'logo_url' => $organization->logo_url,
            'initials' => $organization->initials(),
        ];
    }

    /**
     * Compact posting shape for the board (no internal pipeline counts).
     *
     * @return array<string, mixed>
     */
    private function postingCard(JobPosting $posting): array
    {
        return [
            'hashid' => $posting->hashid,
            'title' => $posting->title,
            'employment_type' => $posting->employment_type,
            'openings' => $posting->openings,
            'closing_date' => $posting->closing_date?->toDateString(),
            'department' => $posting->department?->name,
            'position' => $posting->position?->title,
            'excerpt' => $posting->description ? mb_strimwidth((string) $posting->description, 0, 160, '…') : null,
        ];
    }

    /**
     * Full public posting shape for the detail/apply page.
     *
     * @return array<string, mixed>
     */
    private function postingDetail(JobPosting $posting): array
    {
        return [
            ...$this->postingCard($posting),
            'description' => $posting->description,
            'requirements' => $posting->requirements,
        ];
    }

    /**
     * Supporting-document categories offered on the public form.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function documentTypeOptions(): array
    {
        return [
            ['value' => 'cover_letter', 'label' => 'Cover letter'],
            ['value' => 'certificate', 'label' => 'Certificate'],
            ['value' => 'transcript', 'label' => 'Transcript of records'],
            ['value' => 'portfolio', 'label' => 'Portfolio'],
            ['value' => 'government_id', 'label' => 'Government ID'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }
}
