<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

/** A narrative candidate with every retrieval source that nominated it. */
final class Candidate
{
    /** @var array<string,true> */
    private array $sources = [];

    public function __construct(
        public readonly int $narrativeId,
        string $source,
    ) {
        $this->addSource($source);
    }

    public function addSource(string $source): void
    {
        $source = strtolower(trim($source));
        $source = preg_replace('/[^a-z0-9_]+/', '_', $source) ?? '';
        $source = trim($source, '_');

        if ($source !== '') {
            $this->sources[$source] = true;
        }
    }

    /** @return list<string> */
    public function sourceNames(): array
    {
        return array_keys($this->sources);
    }
}
