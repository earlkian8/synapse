<?php

namespace App\Support\Recruitment\Skills\Esco;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A thin wrapper over ESCO's public search API (ADR 0100). One endpoint does
 * everything the ATS needs: `/search` with `full=true` returns each skill with
 * its other labels and its broader and narrower skills, and each occupation
 * with its essential and optional skills, so a lookup is one request.
 *
 * The base URL lives in config (a self-hosted ESCO API works the same), only
 * the text it is given leaves the server, and a failure is an
 * {@see EscoUnavailable} the caller degrades on.
 */
class EscoClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $language = 'en',
        private readonly ?string $version = null,
        private readonly int $timeout = 6,
    ) {}

    public function language(): string
    {
        return $this->language;
    }

    /**
     * Search ESCO's skills or occupations, best match first.
     *
     * @param  'skill'|'occupation'  $type
     * @return list<array<string, mixed>>
     *
     * @throws EscoUnavailable
     */
    public function search(string $text, string $type, int $limit = 10, bool $full = true): array
    {
        $query = array_filter([
            'text' => $text,
            'type' => $type,
            'language' => $this->language,
            'limit' => max(1, min($limit, 20)),
            'full' => $full ? 'true' : 'false',
            'selectedVersion' => $this->version,
        ], fn ($value): bool => $value !== null);

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(min($this->timeout, 4))
                ->acceptJson()
                ->get(rtrim($this->baseUrl, '/').'/search', $query);
        } catch (ConnectionException $e) {
            Log::warning('ESCO unreachable.', ['type' => $type, 'reason' => $e->getMessage()]);

            throw new EscoUnavailable('ESCO is unreachable.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('ESCO returned an error.', ['type' => $type, 'status' => $response->status()]);

            throw new EscoUnavailable("ESCO answered {$response->status()}.");
        }

        $results = $response->json('_embedded.results');

        if (! is_array($response->json()) || ($results !== null && ! is_array($results))) {
            throw new EscoUnavailable('ESCO’s answer was not its JSON.');
        }

        return array_values(array_filter($results ?? [], 'is_array'));
    }
}
