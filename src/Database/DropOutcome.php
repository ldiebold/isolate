<?php

namespace Ldiebold\Isolate\Database;

enum DropOutcome: string
{
    case Dropped = 'dropped';
    case Missing = 'missing';
    case Skipped = 'skipped';
}
