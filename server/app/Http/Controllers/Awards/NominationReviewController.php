<?php

namespace App\Http\Controllers\Awards;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Recognition\RecognitionResponses;
use App\Http\Requests\Recognition\ReviewNominationRequest;
use App\Models\AwardNomination;
use App\Queries\RecognitionFeed;
use App\Support\Awards\AwardCitationWriter;
use App\Support\Recognition\NominationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Awards → Nominations (ADR 0071): the queue of nominations colleagues made,
 * waiting ones first, then what was decided in the last 90 days. Approving gives
 * the award (its citation editable, an AI draft on offer); turning one down
 * tells the nominator why. Gated by `awards.manage`; the rules are
 * {@see NominationWorkflow}'s.
 */
class NominationReviewController extends Controller
{
    use RecognitionResponses;

    public function index(Request $request, AwardCitationWriter $writer): Response
    {
        $with = ['employee.position:id,title', 'employee.department:id,name', 'awardType', 'award', 'nominator:id,first_name,last_name', 'reviewer:id,first_name,last_name'];

        // Waiting ones in the order they came in, then the latest decisions.
        $nominations = AwardNomination::query()->pending()->with($with)->oldest('id')->limit(200)->get()->concat(
            AwardNomination::query()->where('status', '!=', 'pending')->where('updated_at', '>=', now()->subDays(90))->with($with)->latest('id')->limit(100)->get(),
        );

        return Inertia::render('awards/nominations', [
            'nominations' => $nominations->map(fn (AwardNomination $n): array => [
                ...RecognitionFeed::nomination($n),
                'is_mine' => $n->nominated_by === $request->user()->id || $n->employee?->user_id === $request->user()->id,
            ])->all(),
            'counts' => [
                'pending' => AwardNomination::query()->pending()->count(),
                'approved' => $nominations->where('status', 'approved')->count(),
                'rejected' => $nominations->where('status', 'rejected')->count(),
            ],
            'ai_available' => $writer->enabled(),
        ]);
    }

    public function approve(ReviewNominationRequest $request, AwardNomination $nomination, NominationWorkflow $workflow): RedirectResponse
    {
        return $this->attempt(
            fn () => $workflow->approve($nomination, $request->user(), $request->validated('citation'), $request->validated('awarded_on')),
            "Approved — {$nomination->employee?->full_name} has the {$nomination->awardType?->name}.",
        );
    }

    public function reject(ReviewNominationRequest $request, AwardNomination $nomination, NominationWorkflow $workflow): RedirectResponse
    {
        return $this->attempt(
            fn () => $workflow->reject($nomination, $request->user(), $request->validated('note')),
            'Turned down — the nominator has been told.',
        );
    }
}
