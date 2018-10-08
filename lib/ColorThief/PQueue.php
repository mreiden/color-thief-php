<?php declare(strict_types=1);

namespace ColorThief;

/* Simple priority queue */
class PQueue
{
    private $contents = [];
    private $sorted = false;
    private $comparator = null;

    public function __construct(callable $comparator)
    {
        $this->setComparator($comparator);
    }

    private function sort(): void
    {
        usort($this->contents, $this->comparator);
        $this->sorted = true;
    }

    public function push($object): void
    {
        array_push($this->contents, $object);
        $this->sorted = false;
    }

    public function peek(?int $index = null)
    {
        if (!$this->sorted) {
            $this->sort();
        }

        if ($index === null) {
            $index = $this->size() - 1;
        }

        return $this->contents[$index];
    }

    public function pop()
    {
        if (!$this->sorted) {
            $this->sort();
        }

        return array_pop($this->contents);
    }

    public function size(): int
    {
        return count($this->contents);
    }

    public function map(callable $function): array
    {
        return array_map($function, $this->contents);
    }

    public function setComparator(callable $function): void
    {
        $this->comparator = $function;
        $this->sorted = false;
    }

    public function debug(): array
    {
        if (!$this->sorted) {
            $this->sort();
        }

        return $this->contents;
    }
}
