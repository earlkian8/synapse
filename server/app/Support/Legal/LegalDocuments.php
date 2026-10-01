<?php

namespace App\Support\Legal;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * SYNAPSE's Privacy Policy and Terms of Service (ADR 0063).
 *
 * The words are Markdown under `resources/legal/<key>.md`, reviewed like any
 * other change; this class is what each document is called, when its current
 * version took effect, and the operator's details (config/legal.php) written
 * into it where the text says `{{operator}}`, `{{contact_email}}`,
 * `{{privacy_email}}`, `{{address}}` or `{{ai_training}}`. The details come from configuration
 * rather than the text, so every deployment names whoever actually runs it.
 *
 * Changing what a document says means changing its `effective` date in the
 * same change — the date on the page is the reader's only way to tell.
 */
final class LegalDocuments
{
    /**
     * @var array<string, array{title: string, summary: string, effective: string, highlights: list<string>}>
     */
    public const DOCUMENTS = [
        'privacy' => [
            'title' => 'Privacy Policy',
            'summary' => 'What personal information SYNAPSE holds, why, who it is shared with, how long it is kept, and the rights you have over it.',
            'effective' => '2026-10-01',
            'highlights' => [
                'Your employer decides what goes into its workspace, and is responsible for it. We process it for them, only to run the service.',
                'Each person sees only what their role allows, and every change is recorded in an audit trail.',
                'AI features send the least they need to Google\'s Gemini. Pay, government ID numbers, bank details, home addresses and dates of birth are never sent through the assistant.',
                'Predictions and scores support people\'s decisions. They never make one on their own.',
                'You can ask to see, correct, download or delete your information, or complain to the National Privacy Commission.',
            ],
        ],
        'terms' => [
            'title' => 'Terms of Service',
            'summary' => 'The agreement between you, your organisation and the operator of SYNAPSE: what the service is, what each of us is responsible for, and what happens if something goes wrong.',
            'effective' => '2026-10-01',
            'highlights' => [
                'Your organisation owns its data. We use it only to provide the service.',
                'Keep your account to yourself, and use SYNAPSE only for your organisation\'s lawful HR work.',
                'AI and predictions can be wrong. People make the decisions, and should check them.',
                'Attendance gives payroll figures in minutes. Your organisation checks them before paying anyone.',
                'Philippine law governs these terms.',
            ],
        ],
    ];

    /**
     * One document, ready to read — or null for a key that is not one.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $document = self::DOCUMENTS[$key] ?? null;

        if ($document === null) {
            return null;
        }

        $effective = CarbonImmutable::parse($document['effective']);

        return [
            'key' => $key,
            'title' => $document['title'],
            'summary' => $document['summary'],
            'highlights' => $document['highlights'],
            'effective' => $effective->toDateString(),
            'effective_label' => $effective->format('F j, Y'),
            'body' => self::body($key),
            'operator' => (string) config('legal.operator'),
            'others' => collect(self::DOCUMENTS)
                ->except($key)
                ->map(fn (array $other, string $otherKey): array => ['key' => $otherKey, 'title' => $other['title'], 'href' => self::href($otherKey)])
                ->values()
                ->all(),
        ];
    }

    /** The public address of a document. */
    public static function href(string $key): string
    {
        return "/{$key}";
    }

    /** Where a document's words are kept. */
    public static function path(string $key): string
    {
        return resource_path("legal/{$key}.md");
    }

    /**
     * A document's Markdown with the operator's details written in.
     */
    public static function body(string $key): string
    {
        $address = config('legal.address');

        return strtr((string) @file_get_contents(self::path($key)), [
            '{{operator}}' => (string) config('legal.operator'),
            '{{contact_email}}' => (string) config('legal.contact_email'),
            '{{privacy_email}}' => (string) config('legal.privacy_email'),
            '{{address}}' => filled($address) ? Str::squish((string) $address) : 'available on request, by email',
            '{{ai_training}}' => config('legal.ai_paid_plan')
                ? 'This deployment uses a paid Gemini plan, under which Google does not use it to improve its products.'
                : 'This deployment uses Google\'s free Gemini plan, under which Google may use what is sent to improve its products, and people at Google may review it. Your organisation can ask us to move to a paid plan.',
        ]);
    }
}
