<?php

namespace App\Services\Ksef;

/**
 * KSeF odrzucił zapytanie limitem (HTTP 429) — trzeba odczekać i spróbować ponownie.
 */
class KsefRateLimitException extends KsefException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(__('KSeF limits the number of requests. Try again in :seconds s.', ['seconds' => max(1, $retryAfter)]), 429);
    }
}
