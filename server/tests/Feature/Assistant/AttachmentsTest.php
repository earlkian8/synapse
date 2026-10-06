<?php

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\Attachments\ConversationAttachments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/*
| Chat uploads are kept, numbered and resolvable — but only inside the
| conversation they were sent in (ADR 0068 §4).
*/

beforeEach(function () {
    Storage::fake('assistant');
    Storage::fake('public');
});

function sendWithFiles(User $user, string $message, array $files, ?int $conversationId = null): array
{
    RateLimiter::clear('assistant-min:'.$user->id);
    RateLimiter::clear('assistant-day:'.$user->id);

    return test()->post(route('assistant'), array_filter([
        'message' => $message,
        'files' => $files,
        'conversation_id' => $conversationId,
    ]), ['Accept' => 'application/json'])->assertOk()->json();
}

test('an upload is kept on the private assistant disk, not the public one', function () {
    $user = actingAsUserWith(['employees.view']);
    fakeAssistantModel();

    $turn = sendWithFiles($user, 'read this', [UploadedFile::fake()->create('Ana_CV.pdf', 40, 'application/pdf')]);

    $row = AssistantMessage::where('role', 'user')->latest('id')->first();
    $stored = $row->attachments[0];

    expect($stored)->toMatchArray(['name' => 'Ana_CV.pdf', 'mime' => 'application/pdf'])
        ->and($stored['path'])->toStartWith(testOrganization()->id.'/'.$turn['conversation_id'].'/')
        ->and(Storage::disk('assistant')->exists($stored['path']))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a stored upload still presents as its name', function () {
    $user = actingAsUserWith(['employees.view']);
    fakeAssistantModel();

    $turn = sendWithFiles($user, 'read this', [UploadedFile::fake()->create('Ana_CV.pdf', 40, 'application/pdf')]);

    $shown = $this->getJson(route('assistant.conversations.show', $turn['conversation_id']))->json('conversation.messages.0.attachments');

    expect($shown)->toBe(['Ana_CV.pdf']);
});

test('a legacy row of bare names still presents', function () {
    actingAsUserWith(['employees.view']);
    $conversation = AssistantConversation::create(['user_id' => auth()->id(), 'title' => 'Old']);
    $message = $conversation->messages()->create(['role' => 'user', 'body' => 'x', 'attachments' => ['old.pdf']]);

    expect($message->present()['attachments'])->toBe(['old.pdf']);
});

test('attachments are numbered across the conversation and resolve by number or name', function () {
    $user = actingAsUserWith(['employees.view']);
    fakeAssistantModel();

    $first = sendWithFiles($user, 'one', [UploadedFile::fake()->create('first.pdf', 10, 'application/pdf')]);
    sendWithFiles($user, 'two', [UploadedFile::fake()->create('Second CV.pdf', 10, 'application/pdf')], $first['conversation_id']);

    $inbox = app(ConversationAttachments::class);
    $inbox->use(AssistantConversation::find($first['conversation_id']), $user);

    expect(array_map(fn ($a) => [$a->number, $a->name], $inbox->all()))->toBe([[1, 'first.pdf'], [2, 'Second CV.pdf']])
        ->and($inbox->resolve(2)?->name)->toBe('Second CV.pdf')
        ->and($inbox->resolve('#1')?->name)->toBe('first.pdf')
        ->and($inbox->resolve('second cv.pdf')?->number)->toBe(2)
        ->and($inbox->resolve('second cv')?->number)->toBe(2)
        ->and($inbox->resolve(3))->toBeNull()
        ->and($inbox->resolve('../../etc/passwd'))->toBeNull();
});

test('another conversation’s upload cannot be reached', function () {
    $user = actingAsUserWith(['employees.view']);
    fakeAssistantModel();

    sendWithFiles($user, 'one', [UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf')]);
    $other = AssistantConversation::create(['user_id' => $user->id, 'title' => 'Other']);

    $inbox = app(ConversationAttachments::class);
    $inbox->use($other, $user);

    expect($inbox->all())->toBe([])
        ->and($inbox->resolve(1))->toBeNull()
        ->and($inbox->resolve('secret.pdf'))->toBeNull();
});

test('another user’s conversation yields nothing, even when bound', function () {
    $owner = actingAsUserWith(['employees.view']);
    fakeAssistantModel();
    $turn = sendWithFiles($owner, 'one', [UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf')]);

    $intruder = User::factory()->create();
    $inbox = app(ConversationAttachments::class);
    $inbox->use(AssistantConversation::find($turn['conversation_id']), $intruder);

    expect($inbox->all())->toBe([])->and($inbox->resolve(1))->toBeNull();
});

test('a tampered path outside the conversation is never read', function () {
    $user = actingAsUserWith(['employees.view']);
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'title' => 'T']);
    Storage::disk('assistant')->put('999/1/other.pdf', 'x');
    $conversation->messages()->create(['role' => 'user', 'body' => 'x', 'attachments' => [
        ['name' => 'other.pdf', 'mime' => 'application/pdf', 'size' => 1, 'path' => '999/1/other.pdf'],
    ]]);

    $inbox = app(ConversationAttachments::class);
    $inbox->use($conversation, $user);

    expect($inbox->resolve(1))->toBeNull();
});

test('deleting a conversation deletes its uploads', function () {
    $user = actingAsUserWith(['employees.view']);
    fakeAssistantModel();
    $turn = sendWithFiles($user, 'one', [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);

    $this->deleteJson(route('assistant.conversations.destroy', $turn['conversation_id']))->assertOk();

    expect(Storage::disk('assistant')->allFiles())->toBe([]);
});

test('clearing every conversation deletes every upload', function () {
    $user = actingAsUserWith(['employees.view']);
    fakeAssistantModel();
    sendWithFiles($user, 'one', [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
    sendWithFiles($user, 'two', [UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')]);

    $this->deleteJson(route('assistant.conversations.clear'))->assertOk();

    expect(Storage::disk('assistant')->allFiles())->toBe([]);
});

test('a later turn that refers to “the CV” gets the earlier file re-read', function () {
    $user = actingAsUserWith(['employees.view']);
    $model = fakeAssistantModel();
    $first = sendWithFiles($user, 'here you go', [UploadedFile::fake()->create('Ana_CV.pdf', 10, 'application/pdf')]);

    sendWithFiles($user, 'now add the person in the CV to the pool', [], $first['conversation_id']);

    $lastUserTurn = collect($model->sent[1]['contents'])->last(fn ($c) => $c['role'] === 'user');
    $inline = collect($lastUserTurn['parts'])->filter(fn ($p) => isset($p['inline_data']));

    expect($inline)->toHaveCount(1)
        ->and($model->sent[1]['system'])->toContain('[1] Ana_CV.pdf');
});

test('a later turn that does not refer to a file sends none', function () {
    $user = actingAsUserWith(['employees.view']);
    $model = fakeAssistantModel();
    $first = sendWithFiles($user, 'here you go', [UploadedFile::fake()->create('Ana_CV.pdf', 10, 'application/pdf')]);

    sendWithFiles($user, 'how many people are on leave today', [], $first['conversation_id']);

    $lastUserTurn = collect($model->sent[1]['contents'])->last(fn ($c) => $c['role'] === 'user');

    expect(collect($lastUserTurn['parts'])->filter(fn ($p) => isset($p['inline_data'])))->toHaveCount(0);
});

test('“file leave for Maria” is not about a file, and re-sends none', function () {
    $user = actingAsUserWith(['employees.view']);
    $model = fakeAssistantModel();
    $first = sendWithFiles($user, 'here you go', [UploadedFile::fake()->create('Ana_CV.pdf', 10, 'application/pdf')]);

    sendWithFiles($user, 'file a leave for Maria on Friday, and document the reason', [], $first['conversation_id']);

    $lastUserTurn = collect($model->sent[1]['contents'])->last(fn ($c) => $c['role'] === 'user');

    expect(collect($lastUserTurn['parts'])->filter(fn ($p) => isset($p['inline_data'])))->toHaveCount(0);
});

test('a file is still found by the number it is listed under when an earlier row is rejected', function () {
    $user = actingAsUserWith(['employees.view']);
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'title' => 'T']);
    $folder = testOrganization()->id.'/'.$conversation->id;
    Storage::disk('assistant')->put("{$folder}/a.pdf", 'a');
    Storage::disk('assistant')->put("{$folder}/c.pdf", 'c');
    $conversation->messages()->create(['role' => 'user', 'body' => 'x', 'attachments' => [
        ['name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1, 'path' => "{$folder}/a.pdf"],
        ['name' => 'b.pdf', 'mime' => 'application/pdf', 'size' => 1, 'path' => '999/1/b.pdf'],
        ['name' => 'c.pdf', 'mime' => 'application/pdf', 'size' => 1, 'path' => "{$folder}/c.pdf"],
    ]]);

    $inbox = app(ConversationAttachments::class);
    $inbox->use($conversation, $user);
    $listed = $inbox->all();

    expect(array_map(fn ($f) => [$f->number, $f->name], $listed))->toBe([[1, 'a.pdf'], [2, 'c.pdf']])
        ->and($inbox->resolve(2)?->name)->toBe('c.pdf');
});
