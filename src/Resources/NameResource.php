<?php

namespace Ldiebold\Isolate\Resources;

/**
 * A name resource: base + suffix(n). The base may be a literal, the isolate
 * name (base_from), or read from a config path (e.g. the current database
 * name). May normalize its value and emit a side effect such as create_database.
 */
class NameResource extends Resource
{
    public function literalBase(): ?string
    {
        return isset($this->definition['base']) ? (string) $this->definition['base'] : null;
    }

    public function baseFrom(): ?string
    {
        return isset($this->definition['base_from']) ? (string) $this->definition['base_from'] : null;
    }

    public function configPath(): ?string
    {
        return isset($this->definition['config']) ? (string) $this->definition['config'] : null;
    }

    public function normalizer(): ?string
    {
        return isset($this->definition['normalize']) ? (string) $this->definition['normalize'] : null;
    }

    public function sideEffect(): ?string
    {
        return isset($this->definition['side_effect']) ? (string) $this->definition['side_effect'] : null;
    }
}
