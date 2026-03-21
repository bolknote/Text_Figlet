<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/**
 * Horizontal and vertical character smushing logic.
 *
 * Ported from figlet.c — the smush rules implement the original FIGlet
 * smushing algorithm (horizontal rules 1-32, vertical rules 1-16).
 *
 * @internal
 */
final class SmushEngine
{
    /** @var array<string, int> */
    private const HIERARCHY_CLASSES = [
        '|' => 1, '/' => 2, '\\' => 2, '[' => 3, ']' => 3,
        '{' => 4, '}' => 4, '(' => 5, ')' => 5, '<' => 6, '>' => 6,
    ];

    /** @var array<string, string> */
    private const OPPOSITE_PAIRS = [
        '[]' => '|', '][' => '|', '{}' => '|', '}{' => '|', '()' => '|', ')(' => '|',
    ];

    /** @var array<string, string> */
    private const BIG_X_MAP = [
        '/\\' => '|', '\\/' => 'Y', '><' => 'X',
    ];

    public function __construct(
        private readonly string $hardblank,
        private readonly int $hSmushRules,
        private readonly int $vSmushRules,
        private readonly int $printDirection,
        private readonly LayoutMode $hLayout,
    ) {
    }

    /**
     * Determine which cell provides color after a smush operation.
     *
     * When the result character matches exactly one side, that side's
     * color wins. For symmetric smushes (both sides have the same char),
     * the colored cell takes priority, falling back to the left/top cell.
     */
    public function pickSmushColor(
        string $result,
        string $leftCh,
        string $rightCh,
        Cell $leftCell,
        Cell $rightCell,
    ): Cell {
        if ($result === $leftCh && $result !== $rightCh) {
            return $leftCell;
        }
        if ($result === $rightCh && $result !== $leftCh) {
            return $rightCell;
        }
        return $leftCell->hasColor() ? $leftCell : $rightCell;
    }

    /**
     * Try to smush two characters horizontally (figlet.c: smushem).
     */
    public function smushem(string $left, string $right, int $leftWidth, int $rightWidth): ?string
    {
        if ($left === ' ') {
            return $right;
        }
        if ($right === ' ') {
            return $left;
        }
        if ($leftWidth < 2 || $rightWidth < 2) {
            return null;
        }
        if ($this->hLayout !== LayoutMode::Smushing) {
            return null;
        }

        if ($this->hSmushRules === 0) {
            if ($left === $this->hardblank) {
                return $right;
            }
            if ($right === $this->hardblank) {
                return $left;
            }
            return $this->printDirection !== 0 ? $left : $right;
        }

        return $this->applyHSmushRules($left, $right);
    }

    /**
     * Try to smush two characters vertically (figlet.c: vSmushChar).
     */
    public function vSmushChar(string $top, string $bottom): ?string
    {
        $rules = $this->vSmushRules;

        if ($rules === 0) {
            if ($top === ' ') {
                return $bottom;
            }
            return $bottom === ' ' ? $top : $bottom;
        }

        if (($rules & 1) !== 0 && $top === $bottom) {
            return $top;
        }

        if (($rules & 2) !== 0) {
            $result = $this->smushUnderscore($top, $bottom);
            if ($result !== null) {
                return $result;
            }
        }

        if (($rules & 4) !== 0) {
            $result = $this->smushHierarchy($top, $bottom);
            if ($result !== null) {
                return $result;
            }
        }

        if (($rules & 8) !== 0 && (($top === '-' && $bottom === '_') || ($top === '_' && $bottom === '-'))) {
            return '=';
        }

        if (($rules & 16) !== 0 && $top === '|' && $bottom === '|') {
            return '|';
        }

        return null;
    }

    private function applyHSmushRules(string $left, string $right): ?string
    {
        if ($left === $this->hardblank && $right === $this->hardblank) {
            return ($this->hSmushRules & 32) !== 0 ? $left : null;
        }
        if ($left === $this->hardblank || $right === $this->hardblank) {
            return null;
        }

        if (($this->hSmushRules & 1) !== 0 && $left === $right) {
            return $left;
        }

        if (($this->hSmushRules & 2) !== 0) {
            $result = $this->smushUnderscore($left, $right);
            if ($result !== null) {
                return $result;
            }
        }

        if (($this->hSmushRules & 4) !== 0) {
            $result = $this->smushHierarchy($left, $right);
            if ($result !== null) {
                return $result;
            }
        }

        $pair = $left . $right;

        if (($this->hSmushRules & 8) !== 0 && isset(self::OPPOSITE_PAIRS[$pair])) {
            return self::OPPOSITE_PAIRS[$pair];
        }

        if (($this->hSmushRules & 16) !== 0 && isset(self::BIG_X_MAP[$pair])) {
            return self::BIG_X_MAP[$pair];
        }

        return null;
    }

    private function smushUnderscore(string $left, string $right): ?string
    {
        if ($left === '_' && isset(self::HIERARCHY_CLASSES[$right])) {
            return $right;
        }
        if ($right === '_' && isset(self::HIERARCHY_CLASSES[$left])) {
            return $left;
        }
        return null;
    }

    private function smushHierarchy(string $left, string $right): ?string
    {
        $leftClass = self::HIERARCHY_CLASSES[$left] ?? 0;
        $rightClass = self::HIERARCHY_CLASSES[$right] ?? 0;

        if ($leftClass === 0 || $rightClass === 0 || $leftClass === $rightClass) {
            return null;
        }

        return $rightClass > $leftClass ? $right : $left;
    }
}
