<?php

namespace Ldiebold\Isolate\Appliers;

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\Contracts\Applier;
use Ldiebold\Isolate\Contracts\EnvWriter;
use Ldiebold\Isolate\IsolationPlan;

/**
 * Writes the resolved env map to the application's .env, seeding from
 * .env.example when no .env exists yet, and reports the changes it made.
 */
class DotenvApplier implements Applier
{
    public function __construct(
        protected EnvWriter $writer,
        protected Filesystem $files,
        protected string $envPath,
        protected string $examplePath,
    ) {}

    public function apply(IsolationPlan $plan): ApplyResult
    {
        $result = new ApplyResult;

        [$contents, $seeded] = $this->readBaseline();

        if ($seeded) {
            $result->addChange('Seeded .env from .env.example');
        }

        $before = $this->parse($contents);
        $updated = $this->writer->upsert($contents, $plan->envMap);
        $this->files->put($this->envPath, $updated);

        foreach ($plan->envMap as $key => $value) {
            $old = $before[$key] ?? null;

            if ($old === $value) {
                continue;
            }

            $result->addChange($old === null
                ? "Added {$key}={$value}"
                : "Updated {$key}={$value}");
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    protected function readBaseline(): array
    {
        if ($this->files->exists($this->envPath)) {
            return [$this->files->get($this->envPath), false];
        }

        if ($this->files->exists($this->examplePath)) {
            return [$this->files->get($this->examplePath), true];
        }

        return ['', false];
    }

    /**
     * Best-effort parse of current values for change reporting.
     *
     * @return array<string, string>
     */
    protected function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches) === 1) {
                $values[$matches[1]] = $this->unquote($matches[2]);
            }
        }

        return $values;
    }

    protected function unquote(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
        }

        return $value;
    }
}
