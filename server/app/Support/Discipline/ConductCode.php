<?php

namespace App\Support\Discipline;

use App\Models\ConductOffense;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * A starting code of conduct (ADR 0105), the offenses most Philippine company
 * handbooks list, each on the Labor Code ground it rests on and with a common
 * ladder of penalties. A company adds it with one click and edits it to its own
 * handbook; it is a start, not advice.
 */
final class ConductCode
{
    /**
     * @var list<array{code: string, title: string, description: string, gravity: string, ground: string, penalties: list<array{action: string, days?: int}>, reckoning_months?: int}>
     */
    public const STANDARD = [
        ['code' => 'ATT-01', 'title' => 'Habitual tardiness', 'description' => 'Late more often in a month than the company allows.', 'gravity' => 'minor', 'ground' => 'gross_neglect', 'penalties' => [['action' => 'verbal_warning'], ['action' => 'written_warning'], ['action' => 'suspension', 'days' => 3], ['action' => 'suspension', 'days' => 7], ['action' => 'dismissal']]],
        ['code' => 'ATT-02', 'title' => 'Absence without leave', 'description' => 'Absent from work without approved leave or notice.', 'gravity' => 'less_serious', 'ground' => 'gross_neglect', 'penalties' => [['action' => 'written_warning'], ['action' => 'suspension', 'days' => 3], ['action' => 'suspension', 'days' => 7], ['action' => 'dismissal']]],
        ['code' => 'ATT-03', 'title' => 'Abandonment of work', 'description' => 'Absent without leave for a long stretch with a clear intent not to return.', 'gravity' => 'grave', 'ground' => 'gross_neglect', 'penalties' => [['action' => 'dismissal']]],
        ['code' => 'ATT-04', 'title' => 'Falsifying attendance', 'description' => 'Clocking in or out for somebody else, or having somebody clock for you; tampering with a time record.', 'gravity' => 'grave', 'ground' => 'fraud', 'penalties' => [['action' => 'suspension', 'days' => 15], ['action' => 'dismissal']]],
        ['code' => 'CON-01', 'title' => 'Insubordination', 'description' => 'Refusing a lawful and reasonable order connected with the work.', 'gravity' => 'serious', 'ground' => 'willful_disobedience', 'penalties' => [['action' => 'written_warning'], ['action' => 'suspension', 'days' => 7], ['action' => 'dismissal']]],
        ['code' => 'CON-02', 'title' => 'Discourtesy', 'description' => 'Rude or disrespectful conduct towards colleagues, customers or visitors.', 'gravity' => 'minor', 'ground' => 'company_rule', 'penalties' => [['action' => 'verbal_warning'], ['action' => 'written_warning'], ['action' => 'suspension', 'days' => 1], ['action' => 'suspension', 'days' => 3]]],
        ['code' => 'CON-03', 'title' => 'Fighting or violence at work', 'description' => 'Assault, threats or a fight on company premises or during work.', 'gravity' => 'grave', 'ground' => 'serious_misconduct', 'penalties' => [['action' => 'suspension', 'days' => 15], ['action' => 'dismissal']]],
        ['code' => 'CON-04', 'title' => 'Sexual harassment', 'description' => 'Conduct punished by R.A. 7877 and the Safe Spaces Act (R.A. 11313).', 'gravity' => 'grave', 'ground' => 'serious_misconduct', 'penalties' => [['action' => 'dismissal']]],
        ['code' => 'CON-05', 'title' => 'Under the influence at work', 'description' => 'Reporting for work, or working, under the influence of alcohol or prohibited drugs.', 'gravity' => 'serious', 'ground' => 'serious_misconduct', 'penalties' => [['action' => 'suspension', 'days' => 7], ['action' => 'dismissal']]],
        ['code' => 'DUT-01', 'title' => 'Negligence', 'description' => 'Carelessness in one\'s duties that causes loss, damage or delay.', 'gravity' => 'less_serious', 'ground' => 'gross_neglect', 'penalties' => [['action' => 'written_warning'], ['action' => 'suspension', 'days' => 5], ['action' => 'dismissal']]],
        ['code' => 'DUT-02', 'title' => 'Breaking safety rules', 'description' => 'Ignoring the workplace\'s occupational safety and health rules (R.A. 11058).', 'gravity' => 'less_serious', 'ground' => 'willful_disobedience', 'penalties' => [['action' => 'written_warning'], ['action' => 'suspension', 'days' => 3], ['action' => 'dismissal']]],
        ['code' => 'DUT-03', 'title' => 'Dress code or ID', 'description' => 'Not in the prescribed uniform, or without the company ID.', 'gravity' => 'minor', 'ground' => 'company_rule', 'penalties' => [['action' => 'verbal_warning'], ['action' => 'written_warning'], ['action' => 'suspension', 'days' => 1]], 'reckoning_months' => 6],
        ['code' => 'INT-01', 'title' => 'Theft or dishonesty', 'description' => 'Stealing from the company, a colleague or a customer; lying to gain an advantage.', 'gravity' => 'grave', 'ground' => 'fraud', 'penalties' => [['action' => 'dismissal']]],
        ['code' => 'INT-02', 'title' => 'Disclosing confidential information', 'description' => 'Sharing the company\'s or its customers\' confidential or personal information without authority (R.A. 10173).', 'gravity' => 'serious', 'ground' => 'fraud', 'penalties' => [['action' => 'suspension', 'days' => 7], ['action' => 'dismissal']]],
    ];

    /**
     * Add every standard offense whose code the company doesn't use yet.
     * Returns how many were added.
     */
    public static function addStandard(): int
    {
        $taken = ConductOffense::withTrashed()->pluck('code')->map(fn (string $code): string => mb_strtolower($code))->all();
        $added = 0;

        DB::transaction(function () use ($taken, &$added): void {
            foreach (self::STANDARD as $offense) {
                if (in_array(mb_strtolower($offense['code']), $taken, true)) {
                    continue;
                }

                ConductOffense::create([...$offense, 'reckoning_months' => $offense['reckoning_months'] ?? 12]);
                $added++;
            }
        });

        if ($added > 0) {
            ActivityLogger::log(
                event: 'created',
                description: "Added {$added} standard offenses to the code of conduct",
                logName: 'discipline',
            );
        }

        return $added;
    }
}
