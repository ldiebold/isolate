<?php

namespace Ldiebold\Isolate\Support;

/**
 * Pure string helpers for derived resources: {KEY} substitution and rewriting
 * only the port of a URL while preserving scheme, auth, host, path, query and
 * fragment.
 */
class TemplateResolver
{
    /**
     * Replace every {KEY} token with its value. Unknown tokens are left intact.
     *
     * @param  array<string, string>  $values
     */
    public function substitute(string $template, array $values): string
    {
        $replacements = [];

        foreach ($values as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Rewrite only the port of an absolute URL. Returns the input unchanged when
     * it is not an absolute URL (no scheme + host), so relative or malformed
     * values are never corrupted.
     */
    public function rewritePort(string $url, int $port): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = $parts['scheme'].'://';

        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':'.$parts['pass'] : '';
        $auth = $user !== '' ? $user.$pass.'@' : '';

        $host = $parts['host'];
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.$auth.$host.':'.$port.$path.$query.$fragment;
    }
}
