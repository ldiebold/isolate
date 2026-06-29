<?php

namespace Ldiebold\Isolate\Database;

enum CreateOutcome: string
{
    case Created = 'created';
    case Existed = 'existed';
    case Skipped = 'skipped';
}
