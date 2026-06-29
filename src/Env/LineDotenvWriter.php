<?php

namespace Ldiebold\Isolate\Env;

use Ldiebold\Isolate\Contracts\EnvWriter;

/**
 * Line-oriented .env upsert: each key is replaced on its existing `^KEY=` line
 * or appended, preserving comments, blank lines and ordering. Values are quoted
 * only when they contain whitespace or characters that would otherwise break
 * parsing.
 */
class LineDotenvWriter implements EnvWriter
{
    public function upsert(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.$this->format((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace_callback(
                    $pattern,
                    static fn (): string => $line,
                    $contents,
                    1,
                );

                continue;
            }

            $contents = $this->append($contents, $line);
        }

        return $contents;
    }

    protected function append(string $contents, string $line): string
    {
        if ($contents === '') {
            return $line."\n";
        }

        $separator = str_ends_with($contents, "\n") ? '' : "\n";

        return $contents.$separator.$line."\n";
    }

    protected function format(string $value): string
    {
        if ($value === '' || ! $this->needsQuoting($value)) {
            return $value;
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"'.$escaped.'"';
    }

    protected function needsQuoting(string $value): bool
    {
        return preg_match('/[\s#"\']/', $value) === 1;
    }
}
