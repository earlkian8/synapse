<?php

/*
|--------------------------------------------------------------------------
| Legal documents (ADR 0063)
|--------------------------------------------------------------------------
|
| Who runs this deployment of SYNAPSE, as the Privacy Policy and the Terms of
| Service name them. Set these for every real deployment: the documents are
| only as good as the details they give people to contact.
|
*/

return [

    // The person or business that operates this deployment.
    'operator' => env('LEGAL_OPERATOR', env('APP_NAME', 'SYNAPSE')),

    // Where questions about the Terms of Service go.
    'contact_email' => env('LEGAL_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com')),

    // The Data Protection Officer, or whoever answers privacy requests.
    'privacy_email' => env('LEGAL_PRIVACY_EMAIL', env('LEGAL_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com'))),

    // Whether this deployment's Gemini API key is on a paid plan. On a paid plan
    // Google does not use prompts or answers to improve its products; on the
    // free plan it may, and people may review them. The Privacy Policy says
    // which applies, so set this truthfully — and use a paid plan for real
    // employee data.
    'ai_paid_plan' => (bool) env('LEGAL_AI_PAID_PLAN', false),

    // A postal address, if the operator publishes one.
    'address' => env('LEGAL_ADDRESS'),

];
