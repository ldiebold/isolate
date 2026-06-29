<?php

namespace Ldiebold\Isolate\Resources;

/**
 * A config-safe derived resource resolved after ports and names. Supports a
 * built-in port rewrite (rewrite_port_of + port_from), a {KEY} template, or a
 * class-string DerivedResolver. Closures are only allowed via the manager.
 */
class DerivedResource extends Resource
{
    public function rewritePortOf(): ?string
    {
        return isset($this->definition['rewrite_port_of'])
            ? (string) $this->definition['rewrite_port_of']
            : null;
    }

    public function portFrom(): ?string
    {
        return isset($this->definition['port_from']) ? (string) $this->definition['port_from'] : null;
    }

    public function template(): ?string
    {
        return isset($this->definition['template']) ? (string) $this->definition['template'] : null;
    }

    public function resolverClass(): ?string
    {
        return isset($this->definition['resolver']) ? (string) $this->definition['resolver'] : null;
    }
}
