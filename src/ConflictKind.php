<?php

namespace Ldiebold\Isolate;

enum ConflictKind: string
{
    case Port = 'port';
    case Database = 'database';
    case RedisPrefix = 'redis_prefix';
}
