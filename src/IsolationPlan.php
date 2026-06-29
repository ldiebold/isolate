<?php

namespace Ldiebold\Isolate;

/**
 * Resolved plan for a single instance number: every env value to write plus the
 * side effects (e.g. database creation) that must be applied.
 */
readonly class IsolationPlan
{
    /**
     * @param  array<string, string>  $envMap
     * @param  array<int, SideEffect>  $sideEffects
     * @param  array<string, int>  $ports  resolved port values keyed by env key
     */
    public function __construct(
        public int $number,
        public array $envMap = [],
        public array $sideEffects = [],
        public array $ports = [],
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->envMap);
    }

    public function get(string $key): ?string
    {
        return $this->envMap[$key] ?? null;
    }
}
