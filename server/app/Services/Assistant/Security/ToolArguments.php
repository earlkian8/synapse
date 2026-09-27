<?php

namespace App\Services\Assistant\Security;

/**
 * A function call's arguments, held to the schema the tool declared before any
 * module sees them.
 *
 * The model is treated as a hostile caller: it may be steered by text in a
 * record or a document, and it may simply get things wrong. Each module already
 * validates what it acts on; this is the layer beneath that, applied the same
 * way to every tool so no module has to get it right on its own:
 *
 * - **Only declared parameters survive.** An argument the tool never asked for
 *   (`organization_id`, `user_id`, `role`) is dropped, so it cannot reach a
 *   handler that happens to read loosely.
 * - **Types are the declared types.** A string is a string of bounded length
 *   with the invisible characters removed; an enum is one of its values (case
 *   folded) or nothing; a number is a number; a list is a short list.
 * - **Nothing is deep or wide.** Nesting and list lengths are capped, so a call
 *   cannot smuggle a payload the size of the database through one argument.
 */
final class ToolArguments
{
    /** The longest string any argument may carry. */
    private const MAX_STRING = 4000;

    /** The most items one list argument may carry. */
    private const MAX_ITEMS = 50;

    /** How deep objects may nest. */
    private const MAX_DEPTH = 4;

    /**
     * @param  array<string, mixed>  $declaration  A Gemini function declaration.
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function clean(array $declaration, array $args): array
    {
        $schema = $declaration['parameters'] ?? null;

        if (! is_array($schema)) {
            return [];
        }

        $clean = self::value($schema, $args, 0);

        return is_array($clean) ? $clean : [];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function value(array $schema, mixed $value, int $depth): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (strtoupper((string) ($schema['type'] ?? 'STRING'))) {
            'OBJECT' => self::object($schema, $value, $depth),
            'ARRAY' => self::list($schema, $value, $depth),
            'INTEGER' => is_numeric($value) ? (int) $value : null,
            'NUMBER' => is_numeric($value) ? (float) $value : null,
            'BOOLEAN' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            default => self::string($schema, $value),
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private static function object(array $schema, mixed $value, int $depth): ?array
    {
        if (! is_array($value) || $depth > self::MAX_DEPTH) {
            return null;
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $clean = [];

        foreach ($properties as $name => $property) {
            if (! array_key_exists($name, $value) || ! is_array($property)) {
                continue;
            }

            $item = self::value($property, $value[$name], $depth + 1);

            if ($item !== null) {
                $clean[$name] = $item;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<mixed>|null
     */
    private static function list(array $schema, mixed $value, int $depth): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || $depth > self::MAX_DEPTH) {
            return null;
        }

        $items = is_array($schema['items'] ?? null) ? $schema['items'] : ['type' => 'STRING'];
        $clean = [];

        foreach (array_slice($value, 0, self::MAX_ITEMS) as $entry) {
            $item = self::value($items, $entry, $depth + 1);

            if ($item !== null) {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function string(array $schema, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = UntrustedText::multiline((string) $value, self::MAX_STRING);

        if ($text === null) {
            return null;
        }

        $enum = $schema['enum'] ?? null;

        if (! is_array($enum)) {
            return $text;
        }

        // An enum is one of its values or nothing — never a free-form string
        // that a handler might pass on unchecked.
        foreach ($enum as $allowed) {
            if (strcasecmp((string) $allowed, $text) === 0) {
                return (string) $allowed;
            }
        }

        return null;
    }
}
