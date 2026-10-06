<?php

namespace App\Support\Search;

/**
 * One row in the palette: what it is, a line of context, a short hint on the
 * right (an employee number, a status) and where opening it goes.
 */
final readonly class SearchResult
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $subtitle,
        public ?string $hint,
        public string $href,
    ) {}

    /**
     * @return array{id: string, title: string, subtitle: string|null, hint: string|null, href: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'hint' => $this->hint,
            'href' => $this->href,
        ];
    }
}
