<?php

namespace Ldiebold\Isolate\Console;

use Illuminate\Console\Command;
use Ldiebold\Isolate\Isolate;

class StatusCommand extends Command
{
    protected $signature = 'isolate:status';

    protected $description = 'Show the current instance number and resolved ports/names.';

    public function handle(Isolate $isolate): int
    {
        $currentNumber = $isolate->currentNumber();
        $number = $currentNumber ?? 0;

        $plan = $isolate->resolver($currentNumber)->resolve($number);

        $this->info($currentNumber === null
            ? 'No ISOLATE_NUMBER recorded; showing vanilla (instance 0).'
            : "Current instance: {$number}");

        $rows = [];

        foreach ($plan->envMap as $key => $value) {
            $rows[] = [$key, $value];
        }

        $this->table(['Key', 'Value'], $rows);

        return self::SUCCESS;
    }
}
