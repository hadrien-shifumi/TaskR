<?php

namespace App\Support;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Str;

/**
 * Normalizes the issues-page URL filter parameters.
 *
 * The filters live in the URL and must stay shareable: an invalid value is
 * ignored silently, never a 422. The issues page and the saved-view
 * creation share these helpers so a saved view behaves exactly like the
 * URL it was saved from.
 */
class IssueFilterSanitizer
{
    /** Sort keys accepted by the issues list; anything else falls back to created. */
    public const SORT_KEYS = ['created', 'priority', 'due_date', 'status', 'project'];

    /**
     * Normalize the sort key. Unknown keys are ignored silently — the
     * default order applies.
     */
    public static function sort(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::SORT_KEYS, true)
            ? $value
            : null;
    }

    /**
     * Normalize the sort direction. Unknown directions are ignored
     * silently — the default direction applies.
     */
    public static function direction(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['asc', 'desc'], true) ? $value : null;
    }

    /**
     * Normalize a single-ULID filter (project, status). Anything
     * that is not a ULID is ignored silently.
     */
    public static function ulid(mixed $value): ?string
    {
        return is_string($value) && Str::isUlid($value) ? $value : null;
    }

    /**
     * Normalize a numeric-id filter (assignee): a positive integer or
     * nothing. Scalar-tolerant — form posts and test arrays may carry
     * native ints.
     */
    public static function positiveInt(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^\d+$/', $value) === 1 ? $value : null;
    }

    /**
     * Normalize the label filter: a single ULID, a `label[]` list, or a
     * comma-separated list all work. Invalid values are dropped silently.
     *
     * @return string|array<int, string>|null
     */
    public static function labels(mixed $label): string|array|null
    {
        $values = is_array($label) ? $label : explode(',', (string) $label);

        $ids = array_values(array_filter(
            array_map(fn (mixed $value) => is_string($value) ? trim($value) : '', $values),
            fn (string $value) => $value !== '' && Str::isUlid($value),
        ));

        if ($ids === []) {
            return null;
        }

        return count($ids) === 1 ? $ids[0] : $ids;
    }

    /**
     * Normalize a due-date bound. Anything that is not a strict `Y-m-d`
     * date is ignored silently.
     */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException|InvalidArgumentException) {
            return null;
        }

        // Rejects overflowing dates such as 2026-13-45, which Carbon rolls over.
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * Normalize a status-category filter: a known category value or nothing.
     */
    public static function category(mixed $value): ?string
    {
        return is_string($value) && in_array($value, array_column(StatusCategory::cases(), 'value'), true)
            ? $value
            : null;
    }

    /**
     * Normalize a priority filter: a known priority value or nothing.
     */
    public static function priority(mixed $value): ?string
    {
        return is_string($value) && in_array($value, array_column(Priority::cases(), 'value'), true)
            ? $value
            : null;
    }

    /**
     * Normalize a boolean-ish filter (`overdue`, `blocked`): the URL forms
     * `1` and `true` both mean "on" and are stored canonically as `1`;
     * anything else means the filter is absent.
     */
    public static function flag(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['1', 'true'], true) ? '1' : null;
    }

    /**
     * Normalize the free-text search: trimmed, dropped when it empties.
     */
    public static function search(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
