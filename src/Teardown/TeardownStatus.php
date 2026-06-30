<?php

namespace Ldiebold\Isolate\Teardown;

enum TeardownStatus: string
{
    /** A database that exists and will be dropped. */
    case WillDrop = 'will_drop';

    /** Protected: instance 0 or a name that resolves to the vanilla database. */
    case Vanilla = 'vanilla';

    /** Protected: the active instance (drop it by naming it explicitly with --force). */
    case ActiveProtected = 'active';

    /** Nothing to drop: the database does not exist. */
    case Missing = 'missing';
}
