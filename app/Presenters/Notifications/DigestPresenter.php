<?php

namespace App\Presenters\Notifications;

use App\Support\DigestContent;
use App\Support\DigestContext;

/**
 * CamelCase shape of an aggregated digest, ready for Inertia or the mail.
 *
 * The aggregator already built every row through the issue and comment
 * presenters; this layer only wraps the payload with its window metadata.
 */
class DigestPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(DigestContent $content, DigestContext $context): array
    {
        return [
            ...$content->toArray(),
            'isEmpty' => $content->isEmpty(),
            'windowStart' => $context->windowStart()->toISOString(),
            'generatedAt' => $context->now->toISOString(),
            'thresholdDays' => $context->thresholdDays(),
            'timezone' => $context->preference->timezone,
        ];
    }
}
