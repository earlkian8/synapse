<?php

namespace App\Http\Controllers\Awards;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Recognition\RecognitionResponses;
use App\Http\Requests\Recognition\ReviewNominationRequest;
use App\Models\AwardNomination;
use App\Queries\NominationCases;
use App\Queries\RecognitionFeed;
use App\Support\Awards\AwardCitationWriter;
use App\Support\Recognition\NominationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Awards → Nominations (ADR 0071): the queue of nominations colleagues made, as
 * cases — one per person and award, every nominator in it ({@see NominationCases})
 * — waiting ones first, then what was decided in the last 90 days. Approving gives
 * the award (its citation editable, an AI draft on offer); turning one down
 * tells the nominator why. Gated by `awards.manage`; the rules are
 * {@see NominationWorkflow}'s.
 */
class NominationReviewController extends Controller
{
    use RecognitionResponses;

    public function index(Request $request, AwardCitationWriter $writer): Response
    {
        $me = $request->user()->id;

        // One case per person and award, however many colleagues nominated them:
        // the waiting ones in the order they came in, then the latest decisions.
        $pending = NominationCases::pending();
        $decided = NominationCases::decided();

        return Inertia::render('awards/nominations', [
            'cases' => $pending->concat($decided)->map(fn (Collection $case): array => [
                ...RecognitionFeed::nominationCase($case),
                // Part of it: a nominator in it, or the nominee — another reviewer decides it.
                'is_mine' => $case->contains(fn (AwardNomination $n): bool => $n->nominated_by === $me || $n->employee?->user_id === $me),
            ])->all(),
            'counts' => [
                'pending' => NominationCases::pendingCount(),
                'approved' => $decided->filter(fn (Collection $case): bool => $case->first()->status === 'approved')->count(),
                'rejected' => $decided->filter(fn (Collection $case): bool => $case->first()->status === 'rejected')->count(),
            ],
            'ai_available' => $writer->enabled(),
        ]);
    }

    public function approve(ReviewNominationRequest $request, AwardNomination $nomination, NominationWorkflow $workflow): RedirectResponse
    {
        return $this->attempt(
            fn () => $workflow->approve($nomination, $request->user(), $request->validated('citation'), $request->validated('awarded_on')),
            "Approved — {$nomination->employee?->full_name} has the {$nomination->awardType?->name}. Everyone who nominated them has been told.",
        );
    }

    public function reject(ReviewNominationRequest $request, AwardNomination $nomination, NominationWorkflow $workflow): RedirectResponse
    {
        return $this->attempt(
            fn () => $workflow->reject($nomination, $request->user(), $request->validated('note')),
            'Turned down — everyone who nominated them has been told.',
        );
    }
}
