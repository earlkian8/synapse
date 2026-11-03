<?php

namespace App\Support\Recruitment\Skills\Esco;

use Illuminate\Support\Str;

/**
 * One ESCO skill as the ATS keeps it (ADR 0100): its preferred label, the
 * other labels ESCO gives it, and the skills just broader and narrower.
 * Everything ESCO sends is cut to size and cleaned here, once, so nothing
 * downstream has to trust its shape.
 */
final class EscoConcept
{
    /** Every ESCO skill's URI starts so; anything else is not a skill. */
    public const URI_PREFIX = 'http://data.europa.eu/esco/skill/';

    private const MAX_LABEL = 160;

    private const MAX_ALT_LABELS = 40;

    private const MAX_RELATED = 25;

    private const MAX_DESCRIPTION = 1000;

    /**
     * @param  list<string>  $altLabels
     * @param  list<array{uri: string, label: string}>  $broader  the skills just broader
     * @param  list<array{uri: string, label: string}>  $narrower  the skills just narrower
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $label,
        public readonly array $altLabels = [],
        public readonly ?string $description = null,
        public readonly ?string $skillType = null,
        public readonly array $broader = [],
        public readonly array $narrower = [],
    ) {}

    /**
     * A concept from one result of ESCO's search (with `full=true`), or null
     * when the result is not a skill, or has no label in this language.
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromApi(array $result, string $language): ?self
    {
        $uri = is_string($result['uri'] ?? null) ? $result['uri'] : '';

        if (! str_starts_with($uri, self::URI_PREFIX)) {
            return null;
        }

        $label = self::clean(self::inLanguage($result['preferredLabel'] ?? null, $language) ?? ($result['title'] ?? null));

        if ($label === null) {
            return null;
        }

        $links = is_array($result['_links'] ?? null) ? $result['_links'] : [];
        $description = self::inLanguage($result['description'] ?? null, $language);

        return self::fromArray([
            'uri' => $uri,
            'label' => $label,
            'alt_labels' => self::listInLanguage($result['alternativeLabel'] ?? null, $language),
            'description' => is_array($description) ? ($description['literal'] ?? null) : $description,
            'skill_type' => self::skillType($links['hasSkillType'] ?? $result['hasSkillType'] ?? null),
            'broader' => self::links($links['broaderSkill'] ?? null),
            'narrower' => self::links($links['narrowerSkill'] ?? null),
        ]);
    }

    /**
     * A concept as it was stored, cleaned again so a hand-edited row can't
     * carry more than ESCO would.
     *
     * @param  array{uri: string, label: string, alt_labels?: mixed, description?: mixed, skill_type?: mixed, broader?: mixed, narrower?: mixed}  $data
     */
    public static function fromArray(array $data): self
    {
        $label = self::clean($data['label']) ?? $data['uri'];
        $seen = [mb_strtolower($label) => true];
        $alt = [];

        foreach (is_array($data['alt_labels'] ?? null) ? $data['alt_labels'] : [] as $text) {
            $text = self::clean($text);

            if ($text !== null && ! isset($seen[mb_strtolower($text)]) && count($alt) < self::MAX_ALT_LABELS) {
                $seen[mb_strtolower($text)] = true;
                $alt[] = $text;
            }
        }

        $description = self::clean($data['description'] ?? null, self::MAX_DESCRIPTION);
        $type = is_string($data['skill_type'] ?? null) && in_array($data['skill_type'], ['skill', 'knowledge'], true) ? $data['skill_type'] : null;

        return new self(
            uri: $data['uri'],
            label: $label,
            altLabels: $alt,
            description: $description,
            skillType: $type,
            broader: self::related($data['broader'] ?? null),
            narrower: self::related($data['narrower'] ?? null),
        );
    }

    /**
     * @return array{uri: string, label: string, alt_labels: list<string>, description: string|null, skill_type: string|null, broader: list<array{uri: string, label: string}>, narrower: list<array{uri: string, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'label' => $this->label,
            'alt_labels' => $this->altLabels,
            'description' => $this->description,
            'skill_type' => $this->skillType,
            'broader' => $this->broader,
            'narrower' => $this->narrower,
        ];
    }

    /**
     * The preferred label, then the others.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return [$this->label, ...$this->altLabels];
    }

    /**
     * Where a person can read about the skill on ESCO's own portal.
     */
    public function portalUrl(): string
    {
        return 'https://esco.ec.europa.eu/en/classification/skill?uri='.rawurlencode($this->uri);
    }

    /**
     * One line of text: no control characters, no runs of spaces, cut to size.
     * Null when nothing is left.
     */
    public static function clean(mixed $text, int $max = self::MAX_LABEL): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $text = Str::squish((string) preg_replace('/[\p{C}]+/u', ' ', $text));

        return $text === '' ? null : Str::limit($text, $max, '');
    }

    private static function inLanguage(mixed $values, string $language): mixed
    {
        if (! is_array($values)) {
            return is_string($values) ? $values : null;
        }

        return $values[$language] ?? ($language === 'en' ? ($values['en-us'] ?? null) : null);
    }

    /**
     * @return list<string>
     */
    private static function listInLanguage(mixed $values, string $language): array
    {
        $list = is_array($values) ? self::inLanguage($values, $language) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * ESCO's skill type ("…/skill-type/knowledge") as a word.
     */
    private static function skillType(mixed $types): ?string
    {
        $first = is_array($types) ? ($types[0] ?? null) : null;
        $uri = is_array($first) ? ($first['uri'] ?? $first['href'] ?? null) : $first;

        return is_string($uri) ? basename(parse_url($uri, PHP_URL_PATH) ?: $uri) : null;
    }

    /**
     * ESCO's links to other skills, as {uri, label}.
     *
     * @return list<array{uri: string, label: string}>
     */
    private static function links(mixed $links): array
    {
        $related = [];

        foreach (is_array($links) ? $links : [] as $link) {
            if (is_array($link)) {
                $related[] = ['uri' => $link['uri'] ?? null, 'label' => $link['title'] ?? null];
            }
        }

        return $related;
    }

    /**
     * @return list<array{uri: string, label: string}>
     */
    private static function related(mixed $links): array
    {
        $related = [];

        foreach (is_array($links) ? $links : [] as $link) {
            $uri = is_array($link) && is_string($link['uri'] ?? null) ? $link['uri'] : '';
            $label = is_array($link) ? self::clean($link['label'] ?? null) : null;

            if (str_starts_with($uri, self::URI_PREFIX) && $label !== null && count($related) < self::MAX_RELATED) {
                $related[$uri] = ['uri' => $uri, 'label' => $label];
            }
        }

        return array_values($related);
    }
}
