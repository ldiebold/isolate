<?php

namespace Ldiebold\Isolate;

/**
 * A non-env consequence of a resolved plan, applied by an Applier after the
 * .env has been written (e.g. creating the per-instance database).
 */
readonly class SideEffect
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public SideEffectKind $kind,
        public array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function createDatabase(array $payload): self
    {
        return new self(SideEffectKind::CreateDatabase, $payload);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }
}
