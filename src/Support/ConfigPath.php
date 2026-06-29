<?php

namespace Ldiebold\Isolate\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Resolves the {default} token in a config path to the default database
 * connection name, so resources can target the active connection generically.
 */
class ConfigPath
{
    public static function resolve(string $path, Repository $config): string
    {
        if (! str_contains($path, '{default}')) {
            return $path;
        }

        $default = (string) $config->get('database.default', 'mysql');

        return str_replace('{default}', $default, $path);
    }
}
