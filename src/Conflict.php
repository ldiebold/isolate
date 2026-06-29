<?php

namespace Ldiebold\Isolate;

/**
 * A detected resource conflict for a candidate plan: the kind of resource, the
 * env key / identifier it maps to, the conflicting value and a human message.
 */
readonly class Conflict
{
    public function __construct(
        public ConflictKind $kind,
        public string $resource,
        public int|string $value,
        public string $message,
    ) {}

    public static function port(string $resource, int $port, string $message): self
    {
        return new self(ConflictKind::Port, $resource, $port, $message);
    }

    public static function database(string $resource, string $database, string $message): self
    {
        return new self(ConflictKind::Database, $resource, $database, $message);
    }

    public static function redisPrefix(string $resource, string $prefix, string $message): self
    {
        return new self(ConflictKind::RedisPrefix, $resource, $prefix, $message);
    }
}
