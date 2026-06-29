<?php

namespace Ldiebold\Isolate\Support;

use Ldiebold\Isolate\Exceptions\InvalidConfigurationException;

/**
 * Validates the band layout: port bases must be at least band_size apart and
 * max_instances must be a count no greater than band_size.
 */
class BandValidator
{
    protected const MIN_PORT = 1024;

    public function __construct(
        protected int $bandSize,
        protected int $maxInstances,
    ) {}

    /**
     * Validate the cap and the spacing of every port base.
     *
     * @param  array<int, int>  $bases
     *
     * @throws InvalidConfigurationException
     */
    public function validate(array $bases): void
    {
        if ($this->bandSize < 1) {
            throw new InvalidConfigurationException(
                "isolate.band_size must be at least 1; got {$this->bandSize}."
            );
        }

        if ($this->maxInstances < 1) {
            throw new InvalidConfigurationException(
                "isolate.max_instances must be at least 1; got {$this->maxInstances}."
            );
        }

        if ($this->maxInstances > $this->bandSize) {
            throw new InvalidConfigurationException(
                "isolate.max_instances ({$this->maxInstances}) must be <= isolate.band_size "
                ."({$this->bandSize}); otherwise instance bands overlap."
            );
        }

        foreach ($bases as $base) {
            if ($base < self::MIN_PORT) {
                throw new InvalidConfigurationException(
                    "Port base {$base} is below ".self::MIN_PORT
                    .'; use an unprivileged port base (>= '.self::MIN_PORT.').'
                );
            }
        }

        $this->assertBasesSpaced($bases);
    }

    /**
     * Guard the final resolved set: no two env keys may resolve to the same port.
     *
     * @param  array<string, int>  $portValues
     *
     * @throws InvalidConfigurationException
     */
    public function assertUniquePorts(array $portValues): void
    {
        $seen = [];

        foreach ($portValues as $key => $port) {
            if (isset($seen[$port])) {
                throw new InvalidConfigurationException(
                    "Resolved port {$port} is assigned to both {$seen[$port]} and {$key}."
                );
            }

            $seen[$port] = $key;
        }
    }

    /**
     * @param  array<int, int>  $bases
     *
     * @throws InvalidConfigurationException
     */
    protected function assertBasesSpaced(array $bases): void
    {
        $sorted = array_values($bases);
        sort($sorted);

        $count = count($sorted);

        for ($i = 1; $i < $count; $i++) {
            $previous = $sorted[$i - 1];
            $current = $sorted[$i];
            $gap = $current - $previous;

            if ($gap === 0) {
                throw new InvalidConfigurationException(
                    "Duplicate port base {$current}; every port resource needs a distinct base."
                );
            }

            if ($gap < $this->bandSize) {
                throw new InvalidConfigurationException(
                    "Port bases {$previous} and {$current} are only {$gap} apart; they must be at "
                    ."least band_size ({$this->bandSize}) apart so instances 0.."
                    .($this->maxInstances - 1).' never collide.'
                );
            }
        }
    }
}
