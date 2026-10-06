<?php

use App\Services\Assistant\RequestIntent;

/*
| Whether a message asks or instructs (ADR 0068 §6). It decides whether a write
| may run straight away, so a polite instruction must read as an instruction and
| a question must never read as one.
*/

test('polite and greeted instructions are instructions', function (string $message) {
    expect(RequestIntent::isQuestion($message))->toBeFalse();
})->with([
    'hey can you put this person in cybersecurity analyst',
    'hey can you put this (cv pdf) into the cybersecurity analyst job post',
    'please approve Maria\'s leave',
    'put him in Offer',
    'pls attach the cv to Ana',
    'Hi! Could you add Ana Cruz to the Cybersecurity Analyst posting?',
    'kindly upload this to her 201 file',
    'would you schedule an interview with Ana on Friday 2pm',
    'put this person in cybersecurity analyst as an offer',
    'Paki-approve po yung leave ni Maria',
]);

test('questions are questions', function (string $message) {
    expect(RequestIntent::isQuestion($message))->toBeTrue();
})->with([
    'can you tell me who is on leave?',
    'what is Maria\'s phone?',
    'kumusta si Maria',
    'hey, how many people are on probation',
    'is Ana still in screening?',
    'could you explain how leave accrual works',
    'who should we promote?',
    // Read requests are questions: a write proposed on one waits (ADR 0049).
    'can you check if Ana is still in screening?',
    'could you find Ana\'s phone number?',
    'show me who is late today?',
    'list who is on probation?',
    'show me who is late today',
    'check Juan\'s application',
    'give me the list of new hires',
    'draft an announcement about the holiday',
]);

test('an empty message is not a question', function () {
    expect(RequestIntent::isQuestion('   '))->toBeFalse();
});
