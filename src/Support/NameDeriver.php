<?php

namespace Ldiebold\Isolate\Support;

use Illuminate\Support\Str;

/**
 * Derives per-instance names from a base. At n = 0 no suffix is appended so
 * values return to their base. The instance "name" is config('isolate.name')
 * when set, otherwise a slug of the application name.
 */
class NameDeriver
{
    public function __construct(
        protected ?string $name,
        protected ?string $appName,
        protected string $suffixFormat = '_{n}',
    ) {}

    /**
     * The base instance name used by "name" resources with base_from isolate.name.
     */
    public function name(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        $slug = Str::slug((string) $this->appName);

        return $slug !== '' ? $slug : 'app';
    }

    /**
     * The suffix for instance n ('' for n = 0).
     */
    public function suffix(int $n): string
    {
        if ($n === 0) {
            return '';
        }

        return str_replace('{n}', (string) $n, $this->suffixFormat);
    }

    /**
     * Append the instance suffix to a base value.
     */
    public function derive(string $base, int $n): string
    {
        return $base.$this->suffix($n);
    }

    /**
     * Remove the suffix of the currently-applied instance from a value so a base
     * read back from a previously-isolated value re-derives idempotently. Only
     * the exact suffix for $currentN is stripped, avoiding false matches.
     */
    public function stripSuffix(string $value, ?int $currentN): string
    {
        if ($currentN === null || $currentN === 0) {
            return $value;
        }

        $suffix = $this->suffix($currentN);

        if ($suffix !== '' && str_ends_with($value, $suffix)) {
            return substr($value, 0, -strlen($suffix));
        }

        return $value;
    }
}
