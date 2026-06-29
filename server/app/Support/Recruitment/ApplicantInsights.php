<?php

namespace App\Support\Recruitment;

use App\Models\ApplicantDocument;
use App\Models\JobApplication;
use App\Support\Ai\GeminiClient;
use App\Support\Ai\GeminiException;
use App\Support\Reports\ReportInsights;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * LLM decision support for a single applicant. It compiles a compact digest of
 * the application — the candidate's profile, the role and its criteria, the
 * rule-based {@see ApplicantScorer} breakdown, ratings and interview history —
 * and, crucially, **attaches the actual documents** (résumé, cover letter,
 * certificates, portfolio…) so the model reads them and grounds its read of the
 * candidate in what they actually submitted.
 *
 * Privacy: government-ID documents are never sent to the model. Only file types
 * the model can read natively (PDF, images) are attached; office documents are
 * named in the digest but not uploaded.
 *
 * Cost-disciplined like {@see ReportInsights}: one model
 * call per request, a bounded amount of attached data, strict-JSON out, and
 * graceful, retryable degradation on quota/overload.
 */
class ApplicantInsights
{
    /** Document types we refuse to send to a third-party model (PII). */
    private const BLOCKED_DOCUMENT_TYPES = ['government_id'];

    /** MIME types the model reads natively, keyed by file extension. */
    private const READABLE_MIMES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /** Cap on total attached bytes, so one request stays inside the inline limit. */
    private const MAX_ATTACHED_BYTES = 15 * 1024 * 1024;

    public function __construct(private readonly GeminiClient $gemini) {}

    /** Whether AI insights can be generated at all (an API key is configured). */
    public function enabled(): bool
    {
        return $this->gemini->configured();
    }

    /**
     * Generate decision-support insights for one application. The application is
     * expected to have `applicant.documents`, `jobPosting` and `interviews` loaded.
     *
     * @param  array<string, mixed>  $fit  The ApplicantScorer result for this application.
     * @return array<string, mixed>
     */
    public function generate(JobApplication $application, array $fit): array
    {
        if (! $this->gemini->configured()) {
            return $this->unavailable('AI insights aren’t configured. Set GEMINI_API_KEY to enable them.', retryable: false);
        }

        [$attachments, $readNames] = $this->attachments($application);

        $parts = [['text' => $this->digest($application, $fit, $readNames)], ...$attachments];

        try {
            $response = $this->gemini->generate(
                [['role' => 'user', 'parts' => $parts]],
                [],
                $this->systemInstruction(),
            );
        } catch (GeminiException $exception) {
            return $this->unavailable($this->friendlyError($exception), retryable: $exception->isBusy());
        } catch (Throwable) {
            return $this->unavailable('Couldn’t reach the AI service. Try again shortly.', retryable: true);
        }

        $parsed = $this->parseJson($this->extractText($response));

        if ($parsed === null) {
            return $this->unavailable('The AI response couldn’t be parsed. Try again.', retryable: true);
        }

        return [
            'available' => true,
            'headline' => (string) ($parsed['headline'] ?? 'Candidate insights'),
            'summary' => (string) ($parsed['summary'] ?? ''),
            'strengths' => $this->stringList($parsed['strengths'] ?? null),
            'concerns' => $this->stringList($parsed['concerns'] ?? null),
            'document_insights' => (string) ($parsed['document_insights'] ?? ''),
            'interview_questions' => $this->stringList($parsed['interview_questions'] ?? null),
            'recommendation' => (string) ($parsed['recommendation'] ?? ''),
            'documents_read' => $readNames,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The model's brief: a senior recruiter, grounded only in the digest and the
     * attached documents, answering with strict JSON.
     */
    private function systemInstruction(): string
    {
        return <<<'PROMPT'
        You are a senior technical recruiter embedded in an HR ERP, assessing ONE
        candidate for ONE specific role. You are given a digest (the candidate's
        profile, the role and its required criteria, a rule-based fit breakdown,
        ratings and interview history) and the candidate's actual attached documents
        (résumé, cover letter, certificates, portfolio). READ the documents and base
        your assessment on what they really contain — not on the headline alone.

        Respond with STRICT minified JSON and nothing else, in this exact shape:
        {"headline":"...","summary":"...","strengths":["..."],"concerns":["..."],"document_insights":"...","interview_questions":["..."],"recommendation":"..."}

        Rules:
        - "headline": a short verdict (max ~8 words), e.g. "Strong backend fit, light on leadership".
        - "summary": 2-3 sentences — how well this candidate matches THIS role, specific and grounded.
        - "strengths": 2-5 concrete strengths evidenced by the documents or record.
        - "concerns": 2-5 gaps, risks or red flags (missing required skills, employment gaps,
          claims unsupported by the résumé). Be honest; an empty list is rarely right.
        - "document_insights": what the attached documents actually reveal — notable experience,
          skills, achievements, or discrepancies between the résumé and the stated profile. If no
          readable documents were attached, say so plainly.
        - "interview_questions": 3-5 sharp, role-specific questions that probe the concerns.
        - "recommendation": one sentence on the next step and why.
        - Ground every claim in the digest or the attached documents. Do not invent employers,
          dates, or credentials. No markdown, no preamble, no code fences.
        PROMPT;
    }

    /**
     * Compile the compact, model-facing text digest.
     *
     * @param  array<string, mixed>  $fit
     * @param  list<string>  $readNames
     */
    private function digest(JobApplication $application, array $fit, array $readNames): string
    {
        $applicant = $application->applicant;
        $posting = $application->jobPosting;
        $lines = [];

        $lines[] = 'ROLE: '.($posting?->title ?? 'Unknown role');

        if ($posting?->min_years_experience !== null) {
            $lines[] = 'REQUIRED MIN EXPERIENCE: '.$posting->min_years_experience.' years';
        }
        if (! empty($posting?->skills)) {
            $lines[] = 'REQUIRED SKILLS: '.implode(', ', $posting->skills);
        }
        if (filled($posting?->requirements)) {
            $lines[] = 'ROLE REQUIREMENTS: '.$this->trim($posting->requirements, 800);
        }
        if (filled($posting?->description)) {
            $lines[] = 'ROLE DESCRIPTION: '.$this->trim($posting->description, 800);
        }

        $lines[] = '';
        $lines[] = 'CANDIDATE: '.($applicant?->full_name ?? 'Unknown');
        if (filled($applicant?->headline)) {
            $lines[] = 'HEADLINE: '.$applicant->headline;
        }
        if ($applicant?->years_experience !== null) {
            $lines[] = 'STATED EXPERIENCE: '.$applicant->years_experience.' years';
        }
        if (filled($applicant?->current_location)) {
            $lines[] = 'LOCATION: '.$applicant->current_location;
        }
        if (filled($applicant?->linkedin_url)) {
            $lines[] = 'LINKEDIN: '.$applicant->linkedin_url;
        }
        if (filled($applicant?->portfolio_url)) {
            $lines[] = 'PORTFOLIO: '.$applicant->portfolio_url;
        }
        if (filled($applicant?->notes)) {
            $lines[] = 'RECRUITER NOTES: '.$this->trim($applicant->notes, 600);
        }

        $lines[] = '';
        $lines[] = 'PIPELINE STAGE: '.$application->stage;
        $lines[] = 'RECRUITER RATING: '.($application->rating !== null ? $application->rating.'/5' : 'not yet rated');
        if (filled($application->cover_note)) {
            $lines[] = 'COVER NOTE: '.$this->trim($application->cover_note, 1200);
        }

        $lines[] = '';
        $lines[] = 'RULE-BASED FIT SCORE: '.($fit['value'] ?? 0).'/100 ('.($fit['band'] ?? 'n/a').')';
        foreach ($fit['breakdown'] ?? [] as $item) {
            $lines[] = "  - {$item['label']}: {$item['points']}/{$item['max']} ({$item['detail']})";
        }

        $interviews = $application->relationLoaded('interviews') ? $application->interviews : collect();
        if ($interviews->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'INTERVIEW HISTORY:';
            foreach ($interviews as $interview) {
                $feedback = filled($interview->feedback) ? ' — '.$this->trim($interview->feedback, 300) : '';
                $lines[] = "  - {$interview->mode} ({$interview->result}){$feedback}";
            }
        }

        $lines[] = '';
        $lines[] = $readNames === []
            ? 'ATTACHED DOCUMENTS: none readable were attached.'
            : 'ATTACHED DOCUMENTS (read these): '.implode(', ', $readNames);

        return implode("\n", $lines);
    }

    /**
     * Read the résumé and supporting documents into Gemini inline-data parts,
     * skipping government IDs, unreadable file types, and anything past the size
     * budget. Returns the parts and the human names of what was actually attached.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function attachments(JobApplication $application): array
    {
        $applicant = $application->applicant;

        if ($applicant === null) {
            return [[], []];
        }

        $parts = [];
        $names = [];
        $budget = self::MAX_ATTACHED_BYTES;

        // The résumé first — it is the most important document and the one HR cares
        // most about the model reading.
        if (filled($applicant->resume)) {
            $part = $this->filePart($applicant->resume, $budget);
            if ($part !== null) {
                $parts[] = $part['part'];
                $names[] = 'Résumé';
                $budget -= $part['bytes'];
            }
        }

        $documents = $applicant->relationLoaded('documents') ? $applicant->documents : collect();

        foreach ($documents as $document) {
            if (in_array($document->type, self::BLOCKED_DOCUMENT_TYPES, true)) {
                continue;
            }

            $part = $this->filePart($document->file, $budget);
            if ($part === null) {
                continue;
            }

            $parts[] = $part['part'];
            $names[] = $this->documentLabel($document);
            $budget -= $part['bytes'];
        }

        return [$parts, $names];
    }

    /**
     * Build a single inline-data part from a stored file, or null when the file is
     * missing, an unreadable type, or would blow the remaining size budget.
     *
     * @return array{part: array<string, mixed>, bytes: int}|null
     */
    private function filePart(?string $path, int $budget): ?array
    {
        if (! filled($path)) {
            return null;
        }

        $mime = self::READABLE_MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        if ($mime === null) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path) || $disk->size($path) > $budget) {
            return null;
        }

        $contents = $disk->get($path);

        if ($contents === null) {
            return null;
        }

        return [
            'part' => ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($contents)]],
            'bytes' => strlen($contents),
        ];
    }

    private function documentLabel(ApplicantDocument $document): string
    {
        return filled($document->title)
            ? $document->title
            : ucwords(str_replace('_', ' ', (string) $document->type));
    }

    private function trim(?string $value, int $limit): string
    {
        return mb_strimwidth(trim((string) $value), 0, $limit, '…');
    }

    /**
     * Normalise a model-provided list into clean, non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            $value,
        )));
    }

    /**
     * Concatenate the text parts of a Gemini response.
     *
     * @param  array<string, mixed>  $response
     */
    private function extractText(array $response): string
    {
        $parts = data_get($response, 'candidates.0.content.parts', []);

        if (! is_array($parts)) {
            return '';
        }

        $texts = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $texts[] = (string) $part['text'];
            }
        }

        return trim(implode("\n", $texts));
    }

    /**
     * Parse the model's JSON, tolerating code fences and surrounding prose.
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/^```(?:json)?|```$/m', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $reason, bool $retryable): array
    {
        return ['available' => false, 'reason' => $reason, 'retryable' => $retryable];
    }

    private function friendlyError(GeminiException $exception): string
    {
        if ($exception->isQuota()) {
            $retry = $exception->retryAfterSeconds();

            return $retry !== null
                ? "The AI service is rate-limited. Try again in about {$retry}s."
                : 'The AI service hit its rate limit. Try again in a moment.';
        }

        if ($exception->isOverloaded()) {
            return 'The AI service is briefly overloaded. Try again in a moment.';
        }

        return 'The AI service returned an error. Try again shortly.';
    }
}
