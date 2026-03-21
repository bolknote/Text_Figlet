<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @psalm-api */
final class Cell
{
    public function __construct(
        public readonly string $char,
        public readonly ?int $fg = null,
        public readonly ?int $bg = null,
    ) {
    }

    public function withChar(string $char): self
    {
        return new self($char, $this->fg, $this->bg);
    }

    public function withFg(?int $fg): self
    {
        return new self($this->char, $fg, $this->bg);
    }

    public function withBg(?int $bg): self
    {
        return new self($this->char, $this->fg, $bg);
    }

    public function hasColor(): bool
    {
        return $this->fg !== null || $this->bg !== null;
    }
}
