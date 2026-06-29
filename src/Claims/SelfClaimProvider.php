<?php

namespace Ldiebold\Isolate\Claims;

/**
 * This app's own recorded instance number (ISOLATE_NUMBER). Preferred during
 * selection so re-running isolate keeps the current number (idempotent re-run).
 */
class SelfClaimProvider
{
    public function __construct(protected ?int $number = null) {}

    public function number(): ?int
    {
        return $this->number;
    }
}
