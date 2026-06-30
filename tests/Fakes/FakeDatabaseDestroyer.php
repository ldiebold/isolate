<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Contracts\DatabaseDestroyer;
use Ldiebold\Isolate\Database\ConnectionConfig;
use Ldiebold\Isolate\Database\DropResult;

final class FakeDatabaseDestroyer implements DatabaseDestroyer
{
    /**
     * @var array<int, string>
     */
    public array $dropped = [];

    public function __construct(
        private string $driver,
        private DropResult $result,
    ) {}

    public function supports(string $driver): bool
    {
        return $driver === $this->driver;
    }

    public function destroy(ConnectionConfig $maintenance, string $database): DropResult
    {
        $this->dropped[] = $database;

        return $this->result;
    }
}
