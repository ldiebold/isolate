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
     * The suffix for instance n ('' for n = 0). When $width > 0 the number is
     * zero-padded to that width (e.g. width 2 ⇒ "_07"), so equal-width instance
     * suffixes are mutually non-overlapping and safe to match by prefix.
     */
    public function suffix(int $n, int $width = 0): string
    {
        if ($n === 0) {
            return '';
        }

        $number = $width > 0
            ? str_pad((string) $n, $width, '0', STR_PAD_LEFT)
            : (string) $n;

        return str_replace('{n}', $number, $this->suffixFormat);
    }

    /**
     * Append the instance suffix to a base value. When the base already ends in
     * a separator (e.g. a "laravel-database-" cache prefix or "laravel_horizon:"
     * Horizon prefix) and the suffix opens with one, the suffix's leading
     * separator is dropped so the join never doubles up: "laravel-database-7"
     * rather than "laravel-database-_7".
     */
    public function derive(string $base, int $n, int $width = 0): string
    {
        $suffix = $this->suffix($n, $width);

        if ($suffix === '') {
            return $base;
        }

        if ($this->endsWithSeparator($base)) {
            $trimmed = $this->trimLeadingSeparators($suffix);

            if ($trimmed !== '' && $trimmed !== $suffix) {
                return $base.$trimmed;
            }
        }

        return $base.$suffix;
    }

    /**
     * Remove the suffix of the currently-applied instance from a value so a base
     * read back from a previously-isolated value re-derives idempotently. Only
     * the exact suffix for $currentN is stripped, avoiding false matches. Both
     * the collapsed form ("...-7") and the legacy doubled form ("...-_7") are
     * recognised so values written by older versions migrate cleanly.
     */
    public function stripSuffix(string $value, ?int $currentN, int $width = 0): string
    {
        if ($currentN === null || $currentN === 0) {
            return $value;
        }

        $suffix = $this->suffix($currentN, $width);

        if ($suffix === '') {
            return $value;
        }

        if (str_ends_with($value, $suffix)) {
            return substr($value, 0, -strlen($suffix));
        }

        $trimmed = $this->trimLeadingSeparators($suffix);

        if ($trimmed !== '' && $trimmed !== $suffix && str_ends_with($value, $trimmed)) {
            $base = substr($value, 0, -strlen($trimmed));

            if ($this->endsWithSeparator($base)) {
                return $base;
            }
        }

        return $value;
    }

    /**
     * Whether the value's final character is a separator (any non-alphanumeric).
     */
    protected function endsWithSeparator(string $value): bool
    {
        return $value !== '' && ! ctype_alnum($value[strlen($value) - 1]);
    }

    /**
     * Strip every leading non-alphanumeric character from a value.
     */
    protected function trimLeadingSeparators(string $value): string
    {
        return (string) preg_replace('/^[^a-zA-Z0-9]+/', '', $value);
    }
}
