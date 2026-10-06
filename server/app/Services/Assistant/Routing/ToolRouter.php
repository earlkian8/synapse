<?php

namespace App\Services\Assistant\Routing;

use App\Models\User;
use App\Services\Assistant\Contracts\AssistantModule;
use App\Services\Assistant\Retrieval\ContextBrief;
use Illuminate\Support\Str;

/**
 * Which modules' tools a turn carries (ADR 0068 §2).
 *
 * Sending every tool to every request cost an HR manager ~155 KB — about 40k
 * tokens — per round trip, which is what made a second round trip unaffordable.
 * The router picks the few modules a message is about, locally and for free;
 * every other module the user may use is listed in a one-line catalogue, and
 * the model can load it with `load_tools` when the guess was wrong. So a miss
 * costs one cheap round trip, never a failed request.
 *
 * Routing decides what is *shown*. It never decides what may *run*: the
 * allow-list is still every tool the user's permissions offer.
 */
final class ToolRouter
{
    /** The most modules a turn starts with (the system guide comes on top). */
    public const MAX_MODULES = 6;

    /** Always carried: small, and the answer to "how do I…". */
    public const ALWAYS = ['guide'];

    /** Modules with a tool that files an attachment onto a record. */
    public const ATTACHMENT_MODULES = ['recruitment', 'employee-records'];

    /**
     * Per module: the catalogue line, and the words people use for it. Hints
     * may be phrases; single words also match their plural.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const MODULES = [
        'employees' => ['the people directory: find, view, create and update employees, direct reports, headcounts', ['employee', 'staff', 'people', 'person', 'worker', 'profile', 'contact', 'phone', 'email', 'manager', 'headcount', 'probation', 'probationary', 'regular', 'regularise', 'regularize', 'tenure', 'job title', 'hire date', 'my record', 'colleague', 'team member']],
        'leave' => ['leave requests, approvals, cancellations, balances and entitlements', ['leave', 'vacation', 'sick', 'absence', 'day off', 'days off', 'pto', 'balance', 'entitlement', 'time off', 'bakasyon']],
        'attendance' => ['attendance, punches, shifts and rosters, exceptions, sign-offs, re-applying rules', ['attendance', 'punch', 'clock', 'late', 'tardy', 'tardiness', 'overtime', 'absent', 'shift', 'roster', 'timesheet', 'sign off', 'sign-off', 'undertime', 'dtr', 'time in', 'time out']],
        'onboarding' => ['onboarding cases, checklists, tasks and programs, chasing outstanding work', ['onboarding', 'onboard', 'checklist', 'task', 'new hire', 'orientation', 'first day']],
        'recruitment' => ['job postings, candidates and their CVs, applications and pipeline stages, interviews, ranking, hiring', ['recruitment', 'recruit', 'job', 'posting', 'vacancy', 'vacancies', 'opening', 'candidate', 'applicant', 'application', 'cv', 'resume', 'résumé', 'interview', 'hire', 'hiring', 'offer', 'screening', 'shortlist', 'talent', 'pipeline stage', 'job post']],
        'performance' => ['appraisals: open, rate, submit, acknowledge; review cycles; performance summaries', ['performance', 'appraisal', 'review', 'rating', 'evaluation', 'evaluate', 'scorecard', 'cycle']],
        'training' => ['training programs and enrollments', ['training', 'course', 'seminar', 'workshop', 'enroll', 'enrol', 'enrollment', 'learning', 'upskill']],
        'awards' => ['awards and recognition, nominees', ['award', 'recognition', 'recognise', 'recognize', 'nominee', 'nominate', 'employee of the month']],
        'events' => ['company events, invitees, reminders', ['event', 'meeting', 'party', 'invitee', 'rsvp', 'celebration', 'gathering', 'townhall', 'town hall']],
        'offboarding' => ['offboarding cases and clearance items', ['offboarding', 'offboard', 'resignation', 'resign', 'exit', 'clearance', 'separation', 'terminate', 'termination', 'last day', 'quit']],
        'reports' => ['run the workspace reports', ['report', 'turnover', 'statistics', 'stats', 'analytics', 'breakdown']],
        'departments' => ['departments and positions (the org structure)', ['department', 'dept', 'team', 'position', 'org chart', 'structure', 'division', 'unit']],
        'company' => ['the company profile and its time zone', ['company', 'organization', 'organisation', 'timezone', 'time zone', 'business name', 'company profile']],
        'schedules' => ['work schedules and holidays', ['schedule', 'holiday', 'work hours', 'working days', 'rest day', 'workweek']],
        'attendance-policies' => ['attendance policies: grace periods, lateness, overtime and undertime rules', ['policy', 'policies', 'grace', 'grace period', 'lateness rule', 'overtime rule']],
        'locations' => ['work locations and geofences', ['location', 'site', 'office', 'branch', 'geofence', 'address']],
        'leave-types' => ['leave types and their rules', ['leave type', 'leave types', 'leave policy', 'leave credits']],
        'award-types' => ['award types', ['award type', 'award types', 'award category']],
        'performance-framework' => ['performance frameworks, criteria, rating scales and review cycles', ['framework', 'criteria', 'criterion', 'rating scale', 'kpi', 'competency', 'competencies', 'review cycle']],
        'recruitment-pipelines' => ['hiring pipeline templates and their stages', ['pipeline', 'stages', 'pipeline template']],
        'offboarding-programs' => ['clearance templates for offboarding', ['clearance template', 'offboarding program', 'template']],
        'users' => ['user accounts: create, re-email, activate or deactivate, roles of a user', ['user', 'account', 'login', 'deactivate', 'activate', 'sign in', 'username']],
        'roles' => ['roles and their permissions', ['role', 'permission', 'access level', 'privilege']],
        'activity-logs' => ['the activity log (who changed what, when)', ['activity', 'audit', 'log', 'history', 'who changed', 'changed by']],
        'trash' => ['the trash bin: find, restore, delete for good', ['trash', 'deleted', 'restore', 'bin', 'recycle', 'undelete']],
        'attrition-risk' => ['attrition risk scores and models', ['attrition', 'flight risk', 'turnover risk', 'likely to leave', 'retention']],
        'promotion-readiness' => ['promotion readiness scores and models', ['promotion', 'promote', 'ready for promotion', 'readiness']],
        'performance-forecast' => ['performance forecasts and models', ['forecast', 'predict', 'prediction', 'projected']],
        'employee-records' => ["employees' certifications and 201-file documents (adding a document from an attachment)", ['certification', 'certificate', 'license', 'licence', 'document', '201', 'contract', 'file', 'nbi', 'diploma']],
        'workspace-access' => ['app access: invitations, join requests, the join code', ['invite', 'invitation', 'join', 'join code', 'join request', 'app access']],
        'notifications' => ['your notifications and settings; sending announcements', ['notification', 'notify', 'announce', 'announcement', 'inbox', 'alert', 'broadcast', 'message everyone']],
        'guide' => ['how the system works: where things are, what you can do, setup progress', []],
        'dashboard' => ['the dashboard: overview, what needs attention, recent activity, attendance trend', ['dashboard', 'overview', 'today', 'attention', 'how are we', 'trend', 'pending', 'to do', 'todo']],
    ];

    /**
     * Words in tool names that say nothing about the subject.
     *
     * @var list<string>
     */
    private const GENERIC = [
        'find', 'get', 'list', 'count', 'set', 'update', 'create', 'add', 'remove', 'delete', 'archive', 'my',
        'summary', 'of', 'a', 'to', 'the', 'and', 'from', 'by', 'all', 'for', 'in', 'on', 'apply', 'run', 'use',
        'make', 'default', 'status', 'mark', 'read', 'send', 'give', 'take', 'start', 'open', 'close', 'new',
    ];

    /**
     * The modules this turn starts with, best match first.
     *
     * @param  array<int, AssistantModule>  $modules  Available to the user.
     * @param  list<string>  $recentModules  Used by the last assistant turn.
     * @return list<string>
     */
    public function select(User $user, array $modules, string $message, array $recentModules = [], bool $hasAttachments = false, ?ContextBrief $brief = null): array
    {
        $available = [];

        foreach ($modules as $module) {
            $available[$module->key()] = $module;
        }

        $text = ' '.$this->normalise($message).' ';
        $words = $this->words($text);
        $scores = [];

        foreach ($available as $key => $module) {
            $score = 0;

            foreach (self::MODULES[$key][1] ?? [] as $hint) {
                $score += $this->mentions($text, $words, $hint) ? 3 : 0;
            }

            foreach ($this->toolWords($module, $user) as $word) {
                $score += isset($words[$word]) ? 1 : 0;
            }

            if (in_array($key, $recentModules, true)) {
                $score += 2;
            }

            if ($hasAttachments && in_array($key, self::ATTACHMENT_MODULES, true)) {
                $score += 2;
            }

            if ($key === 'employees' && $brief !== null && ! $brief->isAboutWorkspace() && $brief->subject !== null) {
                $score += 2;
            }

            if ($score > 0) {
                $scores[$key] = $score;
            }
        }

        arsort($scores);

        $selected = array_slice(array_keys($scores), 0, self::MAX_MODULES);

        foreach (self::ALWAYS as $key) {
            if (isset($available[$key]) && ! in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    /** The catalogue line for a module the turn did not load. */
    public function summary(string $key): string
    {
        return self::MODULES[$key][0] ?? str_replace('-', ' ', $key);
    }

    private function normalise(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', Str::lower($text)) ?? '';
    }

    /**
     * The message's words, each also under its singular.
     *
     * @return array<string, true>
     */
    private function words(string $text): array
    {
        $words = [];

        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $words[$word] = true;
            $words[$this->singular($word)] = true;
        }

        return $words;
    }

    /**
     * @param  array<string, true>  $words
     */
    private function mentions(string $text, array $words, string $hint): bool
    {
        $hint = $this->normalise($hint);

        return str_contains(trim($hint), ' ')
            ? str_contains($text, ' '.trim($hint).' ')
            : isset($words[$hint]) || isset($words[$this->singular($hint)]);
    }

    private function singular(string $word): string
    {
        return match (true) {
            strlen($word) > 4 && str_ends_with($word, 'ies') => substr($word, 0, -3).'y',
            strlen($word) > 4 && str_ends_with($word, 'ses') => substr($word, 0, -2),
            strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }

    /**
     * The subject words in a module's tool names ("find_job_postings" →
     * job, posting), so a module is found by what its tools are about even
     * where the hint list is silent.
     *
     * @return list<string>
     */
    private function toolWords(AssistantModule $module, User $user): array
    {
        $words = [];

        foreach ($module->tools($user) as $declaration) {
            foreach (explode('_', (string) ($declaration['name'] ?? '')) as $word) {
                if (strlen($word) > 2 && ! in_array($word, self::GENERIC, true)) {
                    $words[$this->singular($word)] = true;
                }
            }
        }

        return array_keys($words);
    }
}
