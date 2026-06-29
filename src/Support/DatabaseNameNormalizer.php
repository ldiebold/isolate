<?php

namespace Ldiebold\Isolate\Support;

use Illuminate\Support\Str;

/**
 * Produces a safe, deterministic cross-driver database identifier: lowercase
 * [a-z0-9_], letter-led, capped at 63 bytes with a stable hash on truncation.
 */
class DatabaseNameNormalizer
{
    protected const MAX_LENGTH = 63;

    protected const HASH_LENGTH = 6;

    public function normalize(string $name): string
    {
        $value = (string) Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_');

        if ($value === '') {
            $value = 'db';
        } elseif (! ctype_alpha($value[0])) {
            $value = 'db_'.$value;
        }

        if (strlen($value) > self::MAX_LENGTH) {
            $value = $this->truncateWithHash($value, $name);
        }

        return $value;
    }

    protected function truncateWithHash(string $value, string $original): string
    {
        $hash = substr(hash('crc32b', $original), 0, self::HASH_LENGTH);
        $keep = self::MAX_LENGTH - self::HASH_LENGTH - 1;

        return rtrim(substr($value, 0, $keep), '_').'_'.$hash;
    }
}
