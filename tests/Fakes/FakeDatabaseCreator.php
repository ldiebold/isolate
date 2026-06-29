<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Contracts\DatabaseCreator;
use Ldiebold\Isolate\Database\ConnectionConfig;
use Ldiebold\Isolate\Database\CreateResult;

final class FakeDatabaseCreator implements DatabaseCreator
{
    /**
     * @var array<int, string>
     */
    public array $created = [];

    public function __construct(
        private string $driver,
        private CreateResult $result,
    ) {}

    public function supports(string $driver): bool
    {
        return $driver === $this->driver;
    }

    public function ensureExists(ConnectionConfig $maintenance, string $database): CreateResult
    {
        $this->created[] = $database;

        return $this->result;
    }
}
