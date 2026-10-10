<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\TrainingBudget;
use App\Models\TrainingCost;
use App\Models\TrainingProgram;
use App\Support\Training\TrainingBudgets;
use Inertia\Testing\AssertableInertia as Assert;

/*
| What training costs (ADR 0075): costs recorded on a program, a program's
| budget against its spend, and a year's budget against what was spent in it —
| sums of the costs, never typed in.
*/

function tbProgram(array $attributes = []): TrainingProgram
{
    return TrainingProgram::create([
        'name' => 'Sales Bootcamp',
        'start_date' => '2030-03-02',
        'end_date' => '2030-03-06',
        'budget' => 100000,
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->hr = actingAsSuperAdmin();
});

test('HR records, corrects and removes a cost, and each is on the trail', function () {
    $program = tbProgram();

    $this->post(route('training.costs.store', $program), [
        'category' => 'trainer_fee',
        'description' => 'Two facilitators',
        'amount' => 45000,
        'incurred_on' => '2030-03-02',
    ])->assertRedirect();
    assertToast('success', 'Cost recorded.');

    $cost = $program->costs()->firstOrFail();

    expect((float) $cost->amount)->toBe(45000.0)
        ->and($cost->recorded_by)->toBe($this->hr->id);

    $this->patch(route('training.costs.update', $cost), ['category' => 'venue', 'amount' => 50000.5, 'incurred_on' => '2030-03-03'])->assertRedirect();
    expect($cost->refresh()->category)->toBe('venue')
        ->and((float) $cost->amount)->toBe(50000.5);

    $this->delete(route('training.costs.destroy', $cost))->assertRedirect();
    expect(TrainingCost::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('log_name', 'training')->pluck('description')->implode(' | '))
        ->toContain('Recorded a ₱45,000.00 cost on Sales Bootcamp')
        ->toContain('Updated a cost on Sales Bootcamp')
        ->toContain('Removed a ₱50,000.50 cost from Sales Bootcamp');
});

test('a cost needs a known category, a positive amount and a day', function () {
    $program = tbProgram();

    $this->post(route('training.costs.store', $program), ['category' => 'yacht', 'amount' => 0, 'incurred_on' => 'soon'])
        ->assertSessionHasErrors(['category', 'amount', 'incurred_on']);
});

test('a program’s money: its budget against its spend, per seat and per completion', function () {
    $program = tbProgram();
    $program->costs()->create(['category' => 'trainer_fee', 'amount' => 60000, 'incurred_on' => '2030-03-02']);
    $program->costs()->create(['category' => 'meals', 'amount' => 12000, 'incurred_on' => '2030-03-04']);

    foreach (['completed', 'completed', 'enrolled', 'dropped'] as $status) {
        $program->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => $status]);
    }

    // A request to join takes no seat, so it does not dilute the cost per seat.
    $program->enrollments()->create(['employee_id' => Employee::factory()->create()->id, 'status' => 'requested']);

    expect(TrainingBudgets::forProgram($program))->toMatchArray([
        'budget' => 100000.0,
        'spent' => 72000.0,
        'remaining' => 28000.0,
        'over_budget' => false,
        'used_percent' => 72,
        'per_participant' => 24000.0,
        'per_completion' => 36000.0,
        'by_category' => ['trainer_fee' => 60000.0, 'meals' => 12000.0],
    ]);

    $this->get(route('training.show', $program))->assertInertia(fn (Assert $page) => $page
        ->where('money.spent', 72000)
        ->has('costs', 2));
});

test('a year’s money adds up the costs incurred in it, archived programs included', function () {
    $program = tbProgram();
    $archived = tbProgram(['name' => 'Old Course', 'start_date' => '2029-11-01', 'end_date' => '2029-11-02', 'budget' => null]);
    tbProgram(['name' => 'Planned', 'start_date' => '2030-09-01', 'end_date' => '2030-09-02', 'budget' => 20000]);

    $program->costs()->create(['category' => 'trainer_fee', 'amount' => 30000, 'incurred_on' => '2030-03-02']);
    $program->costs()->create(['category' => 'venue', 'amount' => 10000, 'incurred_on' => '2030-04-15']);
    $archived->costs()->create(['category' => 'materials', 'amount' => 5000, 'incurred_on' => '2030-01-10']);
    $archived->costs()->create(['category' => 'materials', 'amount' => 9999, 'incurred_on' => '2029-12-31']);
    $archived->delete();

    TrainingBudget::create(['year' => 2030, 'amount' => 50000]);

    $year = TrainingBudgets::forYear(2030);

    expect($year['budget'])->toBe(50000.0)
        ->and($year['spent'])->toBe(45000.0)
        ->and($year['remaining'])->toBe(5000.0)
        ->and($year['used_percent'])->toBe(90)
        ->and($year['allocated'])->toBe(120000.0)
        ->and($year['by_category'])->toBe(['trainer_fee' => 30000.0, 'venue' => 10000.0, 'materials' => 5000.0])
        ->and($year['by_month'][0])->toBe(5000.0)
        ->and($year['by_month'][2])->toBe(30000.0)
        ->and($year['by_month'][3])->toBe(10000.0)
        ->and(collect($year['programs'])->pluck('name')->all())->toContain('Sales Bootcamp', 'Old Course', 'Planned');
});

test('HR sets a year’s budget, and changing it keeps one row', function () {
    $this->post(route('training.budget.store'), ['year' => 2030, 'amount' => 500000, 'notes' => 'Compliance first'])->assertRedirect();
    assertToast('success', '2030 budget saved.');

    $this->post(route('training.budget.store'), ['year' => 2030, 'amount' => 550000]);

    expect(TrainingBudget::query()->count())->toBe(1)
        ->and((float) TrainingBudget::query()->value('amount'))->toBe(550000.0);

    $this->get(route('training.budget', ['year' => 2030]))->assertInertia(fn (Assert $page) => $page
        ->component('training/budget')
        ->where('summary.year', 2030)
        ->where('summary.budget', 550000)
        ->where('years', fn ($years) => collect($years)->contains(2030)));
});

test('viewers read the money; only managers change it; participants never see it', function () {
    $program = tbProgram();

    actingAsUserWith(['training.view']);
    $this->get(route('training.budget'))->assertOk();
    $this->post(route('training.budget.store'), ['year' => 2030, 'amount' => 1])->assertForbidden();
    $this->post(route('training.costs.store', $program), ['category' => 'venue', 'amount' => 1, 'incurred_on' => '2030-01-01'])->assertForbidden();

    actingAsUserWith(['training.participate']);
    $this->get(route('training.budget'))->assertForbidden();
    $this->get(route('training.catalog'))->assertInertia(fn (Assert $page) => $page->missing('programs.0.costs'));
});

test('the program list export carries who can join, the certificate, sessions, budget and spend', function () {
    $program = tbProgram(['enrollment_mode' => 'approval', 'issues_certificate' => true, 'certificate_validity_months' => 12]);
    $program->costs()->create(['category' => 'venue', 'amount' => 2500, 'incurred_on' => '2030-03-02']);

    $csv = $this->get(route('training.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('"Who can join",Certificate,Sessions,Budget,Spent')
        ->toContain('"On approval","Valid 12 months",0,100000.00,2500.00');
});
