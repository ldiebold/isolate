<?php

namespace Ldiebold\Isolate\Redis;

enum FlushOutcome: string
{
    case Flushed = 'flushed';
    case Empty = 'empty';
    case Skipped = 'skipped';
}
