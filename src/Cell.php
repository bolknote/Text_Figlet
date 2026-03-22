<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @psalm-api */
final class Cell
{
    /** @var array<string, self> */
    private static array $intern = [];

    private static bool $internEnabled = true;

    private const INTERN_MAX = 16384;

    public function __construct(
        public readonly string $char,
        public readonly ?int $fg = null,
        public readonly ?int $bg = null,
        /** @var int|null Embedded base-16 fg from TLF (before compact layers); used for 16-color terminals. */
        public readonly ?int $fgBase16 = null,
        /** @var int|null Embedded base-16 bg from TLF (before compact layers). */
        public readonly ?int $bgBase16 = null,
    ) {
    }

    /**
     * Returns a shared (interned) instance for the given combination.
     * Identical calls return the same object, reducing allocations when the
     * same (char, fg, bg, …) combination appears many times during rendering.
     * The pool is flushed when it exceeds INTERN_MAX entries.
     */
    public static function get(
        string $char,
        ?int $fg = null,
        ?int $bg = null,
        ?int $fgBase16 = null,
        ?int $bgBase16 = null,
    ): self {
        if (!self::$internEnabled) {
            return new self($char, $fg, $bg, $fgBase16, $bgBase16);
        }
        $charLen = \strlen($char);
        $key = $charLen . ':' . $char . ':' . ($fg ?? 'x') . ':' . ($bg ?? 'x') . ':' . ($fgBase16 ?? 'x') . ':' . ($bgBase16 ?? 'x');
        if (isset(self::$intern[$key])) {
            return self::$intern[$key];
        }
        if (\count(self::$intern) >= self::INTERN_MAX) {
            self::$intern = [];
        }
        return self::$intern[$key] = new self($char, $fg, $bg, $fgBase16, $bgBase16);
    }

    /** @psalm-api */
    public static function setInternEnabled(bool $enabled): void
    {
        self::$internEnabled = $enabled;
    }

    /** @psalm-api */
    public static function clearIntern(): void
    {
        self::$intern = [];
    }

    /** @psalm-api */
    public static function internCount(): int
    {
        return \count(self::$intern);
    }

    public function withChar(string $char): self
    {
        return self::get($char, $this->fg, $this->bg, $this->fgBase16, $this->bgBase16);
    }

    public function withFg(?int $fg): self
    {
        return self::get($this->char, $fg, $this->bg, null, $this->bgBase16);
    }

    public function withBg(?int $bg): self
    {
        return self::get($this->char, $this->fg, $bg, $this->fgBase16);
    }

    public function hasColor(): bool
    {
        return $this->fg !== null || $this->bg !== null;
    }
}
