<?php

namespace Ldiebold\Isolate\Exceptions;

use Ldiebold\Isolate\Conflict;

class ConflictException extends IsolateException
{
    /**
     * @param  array<int, Conflict>  $conflicts
     */
    public function __construct(
        public readonly array $conflicts,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $this->summarize());
    }

    public static function for(Conflict $conflict): self
    {
        return new self([$conflict], $conflict->message);
    }

    protected function summarize(): string
    {
        if ($this->conflicts === []) {
            return 'A resource conflict was detected.';
        }

        return collect($this->conflicts)
            ->map(static fn (Conflict $conflict): string => $conflict->message)
            ->implode(' ');
    }
}
