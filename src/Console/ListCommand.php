<?php

namespace Ldiebold\Isolate\Console;

use Illuminate\Console\Command;
use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Isolate;

class ListCommand extends Command
{
    protected $signature = 'isolate:list {--limit=10 : How many instance numbers to inspect}';

    protected $description = 'List candidate instance numbers and detected conflict reasons.';

    public function handle(Isolate $isolate): int
    {
        $currentNumber = $isolate->currentNumber();
        $resolver = $isolate->resolver($currentNumber);
        $selector = $isolate->numberSelector($resolver, $currentNumber);

        $limit = min((int) $this->option('limit'), $isolate->maxInstances());

        $rows = [];

        for ($n = 0; $n < $limit; $n++) {
            if ($selector->browserBlocked($n)) {
                $rows[] = [$n, 'restricted', 'A browser-facing port maps onto a restricted port.'];

                continue;
            }

            $conflicts = $selector->conflictsFor($resolver->resolve($n));

            $rows[] = [
                $n,
                $conflicts === [] ? 'free' : 'conflict',
                $conflicts === []
                    ? '—'
                    : collect($conflicts)->map(static fn (Conflict $conflict): string => $conflict->message)->implode('; '),
            ];
        }

        $this->table(['#', 'Status', 'Detail'], $rows);

        return self::SUCCESS;
    }
}
