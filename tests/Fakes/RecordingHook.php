<?php

namespace Ldiebold\Isolate\Tests\Fakes;

class RecordingHook
{
    public int $count = 0;

    /**
     * @var array<int, array<int, mixed>>
     */
    public array $calls = [];

    public function __invoke(mixed ...$arguments): void
    {
        $this->count++;
        $this->calls[] = $arguments;
    }
}
