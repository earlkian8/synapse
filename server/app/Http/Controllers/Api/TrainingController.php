<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingCertificateResource;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\Organization;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Queries\MyTraining;
use App\Support\Tenancy;
use App\Support\Training\TrainingCertificates;
use App\Support\Training\TrainingException;
use App\Support\Training\TrainingSelfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Training on the phone (ADR 0094): My training, Browse, one program with the
 * person's own place on it, joining or asking to join, withdrawing, a session
 * for the phone's calendar, and one of their certificates. Gated by
 * `training.participate` and self-scoped like the app's other reads: someone
 * else's enrollment, session or certificate is a 404. {@see MyTraining} reads
 * it, the same as the web's My training, and {@see TrainingSelfService} makes
 * every change, so the rules and the words are the web's.
 */
class TrainingController extends Controller
{
    /** How long a session's calendar link works. */
    private const LINK_MINUTES = 10;

    public function __construct(
        private readonly MyTraining $training,
        private readonly TrainingSelfService $selfService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $enrollments = $this->training->enrollments($employee);

        return response()->json(['data' => [
            'enrollments' => $enrollments->map(fn (TrainingEnrollment $e): array => MyTraining::enrollment($e))->values()->all(),
            'sessions' => $this->training->upcomingSessions($enrollments)->map(fn (TrainingSession $s): array => MyTraining::session($s))->values()->all(),
            'certificates' => TrainingCertificateResource::listing($this->training->certificates($employee), $request),
            'expiring_days' => TrainingCertificates::EXPIRING_DAYS,
        ]]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $programs = $this->training->catalog();
        $mine = $this->training->standings($this->employee($request), $programs);

        return response()->json(['data' => $programs->map(fn (TrainingProgram $p): array => [
            ...MyTraining::listing($p),
            'mine' => $mine->has($p->id) ? ['id' => $mine[$p->id]->id, 'status' => $mine[$p->id]->status] : null,
        ])->values()->all()]);
    }

    public function show(Request $request, TrainingProgram $trainingProgram): JsonResponse
    {
        return response()->json(['data' => $this->program($request, $trainingProgram)]);
    }

    public function join(Request $request, TrainingProgram $trainingProgram): JsonResponse
    {
        $employee = $this->employee($request);

        if ($employee === null) {
            return response()->json(['message' => 'Your account isn’t linked to an employee record. Ask HR to link it.'], 422);
        }

        abort_unless($this->training->visible($trainingProgram, $employee), 404);

        try {
            $enrollment = $this->selfService->join($trainingProgram, $employee, $request->user(), ' via the app');
        } catch (TrainingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->training->program($trainingProgram, $employee),
            'message' => $enrollment->status === 'enrolled'
                ? "You’re in. {$trainingProgram->name} is on My training."
                : 'Request sent. You’ll be told when HR decides.',
        ], 201);
    }

    public function withdraw(Request $request, TrainingEnrollment $enrollment): JsonResponse
    {
        $employee = $this->employee($request);
        abort_unless($employee !== null && $enrollment->employee_id === $employee->id, 404);

        $requested = $enrollment->status === 'requested';
        $program = $enrollment->program;

        try {
            $this->selfService->withdraw($enrollment, ' via the app');
        } catch (TrainingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            // Null once the program is one they could only see by being on it.
            'data' => $program !== null && $this->training->visible($program, $employee) ? $this->training->program($program, $employee) : null,
            'message' => $requested ? 'Request withdrawn.' : 'You’ve left the program.',
        ]);
    }

    /**
     * A link to one of the person's sessions as a calendar file, which works for
     * a few minutes without a token — the phone hands it to its browser, which
     * offers to add it to the calendar.
     */
    public function sessionLink(Request $request, TrainingSession $session): JsonResponse
    {
        abort_unless($this->training->holdsSeat($session, $this->employee($request)), 404);

        $expires = now()->addMinutes(self::LINK_MINUTES);

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('api.documents.training-session', $expires, ['session' => $session->id, 'user' => $request->user()->id]),
            'expires_at' => $expires->toIso8601String(),
        ]]);
    }

    /**
     * One of the person's certificates, with what the printed one says.
     */
    public function certificate(Request $request, EmployeeCertification $certificate): JsonResponse
    {
        $employee = $this->employee($request);
        abort_unless($employee !== null && $certificate->fromTraining() && $certificate->employee_id === $employee->id, 404);

        $theirs = $this->training->certificates($employee);
        $certificate = $theirs->firstWhere('id', $certificate->id);
        abort_if($certificate === null, 404);

        $organization = app(Tenancy::class)->organization();

        return response()->json(['data' => [
            ...TrainingCertificateResource::listing(collect([$certificate]), $request, $theirs)[0],
            'completed_on' => $certificate->enrollment?->completed_at?->toDateString(),
            'organization' => $organization instanceof Organization ? $organization->name : null,
            'expiring_days' => TrainingCertificates::EXPIRING_DAYS,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function program(Request $request, TrainingProgram $program): array
    {
        $employee = $this->employee($request);
        abort_unless($this->training->visible($program, $employee), 404);

        return $this->training->program($program, $employee);
    }

    private function employee(Request $request): ?Employee
    {
        return $request->user()->employee()->first();
    }
}
