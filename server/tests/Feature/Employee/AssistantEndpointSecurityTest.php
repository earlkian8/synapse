<?php

use App\Models\AssistantConversation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;

/*
| The assistant's HTTP surface, rather than its tools: who may reach a
| conversation, and how often anyone may spend a Gemini call.
|
| No model call is made — every request here is refused before it would reach
| Gemini, which is the property being asserted.
*/

test('the assistant endpoint is closed to guests', function () {
    $this->post(route('assistant'), ['message' => 'hello'])->assertRedirect(route('login'));
});

test('a conversation belonging to another user in the same organisation is not reachable', function () {
    // Sign in first so a tenant is bound, then plant a colleague's conversation
    // inside it — same organisation, different person.
    $intruder = actingAsUserWith([]);
    $colleague = User::factory()->create();

    $conversation = AssistantConversation::create([
        'user_id' => $colleague->id,
        'title' => 'Private',
    ]);

    // 404 rather than 403: an id belonging to someone else should not even be
    // confirmed to exist.
    $this->getJson(route('assistant.conversations.show', $conversation))->assertNotFound();
    $this->patchJson(route('assistant.conversations.update', $conversation), ['title' => 'Renamed'])->assertNotFound();
    $this->deleteJson(route('assistant.conversations.destroy', $conversation))->assertNotFound();
    $this->postJson(route('assistant.regenerate', $conversation))->assertNotFound();

    expect($conversation->fresh()->title)->toBe('Private')
        ->and($intruder->id)->not->toBe($colleague->id);
});

test('sending into somebody else’s conversation starts a fresh one instead', function () {
    $user = actingAsUserWith([]);
    $colleague = User::factory()->create();

    $theirs = AssistantConversation::create([
        'user_id' => $colleague->id,
        'title' => 'Theirs',
    ]);

    $this->postJson(route('assistant'), [
        'message' => 'hello',
        'conversation_id' => $theirs->id,
    ]);

    // The id was ignored rather than honoured: their thread gained nothing, and
    // the turn landed in one of the caller's own.
    expect($theirs->fresh()->messages()->count())->toBe(0)
        ->and(AssistantConversation::where('user_id', $user->id)->exists())->toBeTrue();
});

test('a chat turn is rate limited per user', function () {
    $user = actingAsUserWith([]);

    RateLimiter::clear('assistant-min:'.$user->id);
    RateLimiter::clear('assistant-day:'.$user->id);

    // The limiter allows 12 a minute; the 13th is refused before any model call.
    for ($i = 0; $i < 12; $i++) {
        expect($this->postJson(route('assistant'), ['message' => "turn {$i}"])->status())
            ->not->toBe(429, "turn {$i} was throttled early");
    }

    $this->postJson(route('assistant'), ['message' => 'one too many'])->assertStatus(429);

    RateLimiter::clear('assistant-min:'.$user->id);
    RateLimiter::clear('assistant-day:'.$user->id);
});

test('an empty turn is refused without spending a model call', function () {
    actingAsUserWith([]);

    $this->postJson(route('assistant'), ['message' => '   '])->assertStatus(422);
});

test('an oversized message is rejected by validation', function () {
    actingAsUserWith([]);

    // These are web routes, so a failed rule redirects back rather than
    // answering 422 — what matters is that nothing was persisted and no model
    // call was spent.
    $this->post(route('assistant'), ['message' => str_repeat('a', 4001)])->assertRedirect();

    expect(AssistantConversation::query()->count())->toBe(0);
});

test('an executable attachment is refused', function () {
    actingAsUserWith([]);

    $this->post(route('assistant'), [
        'message' => 'run this',
        'files' => [UploadedFile::fake()->create('payload.php', 4, 'application/x-php')],
    ])->assertRedirect();

    expect(AssistantConversation::query()->count())->toBe(0);
});
