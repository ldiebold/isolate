<?php

namespace Ldiebold\Isolate\Teardown;

/**
 * What a single teardown run should do: drop one explicit instance, or scan and
 * drop every existing instance (--all). `force` permits dropping the active
 * instance when a number is named; `limit` bounds the --all scan.
 */
readonly class TeardownRequest
{
    public function __construct(
        public ?int $number = null,
        public bool $all = false,
        public bool $force = false,
        public ?int $limit = null,
    ) {}
}
