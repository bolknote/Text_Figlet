<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @psalm-api */
final class Row
{
    /** @var list<Cell>|null */
    private ?array $cells;

    private ?string $text;

    private ?bool $colorCached = null;

    /** @param list<Cell> $cells */
    public function __construct(array $cells = [])
    {
        if ($cells === []) {
            $this->cells = null;
            $this->text = '';
        } else {
            $this->cells = $cells;
            $this->text = null;
        }
    }

    public function length(): int
    {
        if ($this->cells !== null) {
            return count($this->cells);
        }
        return mb_strlen($this->text ?? '', 'UTF-8');
    }

    public function charAt(int $position): string
    {
        if ($this->cells !== null) {
            return isset($this->cells[$position]) ? $this->cells[$position]->char : '';
        }
        return mb_substr($this->text ?? '', $position, 1, 'UTF-8');
    }

    public function cellAt(int $position): Cell
    {
        if ($this->cells !== null) {
            return $this->cells[$position] ?? Cell::get(' ');
        }
        $ch = mb_substr($this->text ?? '', $position, 1, 'UTF-8');
        return Cell::get($ch !== '' ? $ch : ' ');
    }

    public function slice(int $start, ?int $length = null): self
    {
        if ($this->cells !== null) {
            return new self(array_slice($this->cells, $start, $length));
        }
        $row = new self();
        $row->text = mb_substr($this->text ?? '', $start, $length, 'UTF-8');
        return $row;
    }

    public function replaceAt(int $position, Cell $cell): self
    {
        if ($this->cells !== null) {
            $cells = $this->cells;
            $cells[$position] = $cell;
            return new self(array_values($cells));
        }

        if ($cell->hasColor()) {
            $expanded = new self($this->expandToCells());
            return $expanded->replaceAt($position, $cell);
        }

        $text = $this->text ?? '';
        $new = mb_substr($text, 0, $position, 'UTF-8') . $cell->char . mb_substr($text, $position + 1, null, 'UTF-8');
        $row = new self();
        $row->text = $new;
        return $row;
    }

    public function append(self $other): self
    {
        if ($this->cells !== null || $other->cells !== null) {
            $left = $this->cells ?? $this->expandToCells();
            $right = $other->cells ?? $other->expandToCells();
            return new self(array_merge($left, $right));
        }

        $row = new self();
        $row->text = ($this->text ?? '') . ($other->text ?? '');
        return $row;
    }

    public function pad(int $totalLength): self
    {
        $currentLength = $this->length();
        $pad = $totalLength - $currentLength;
        if ($pad <= 0) {
            return $this;
        }

        if ($this->cells !== null) {
            $space = Cell::get(' ');
            return new self(array_merge($this->cells, array_fill(0, $pad, $space)));
        }

        $row = new self();
        $row->text = ($this->text ?? '') . str_repeat(' ', $pad);
        return $row;
    }

    public function replaceChar(string $from, string $to): self
    {
        if ($this->cells !== null) {
            $cells = [];
            $changed = false;
            foreach ($this->cells as $cell) {
                if ($cell->char === $from) {
                    $cells[] = Cell::get($to, $cell->fg, $cell->bg, $cell->fgBase16, $cell->bgBase16);
                    $changed = true;
                } else {
                    $cells[] = $cell;
                }
            }
            return $changed ? new self($cells) : $this;
        }

        $text = $this->text ?? '';
        $newText = str_replace($from, $to, $text);
        if ($newText === $text) {
            return $this;
        }
        $row = new self();
        $row->text = $newText;
        return $row;
    }

    public function hasColor(): bool
    {
        if ($this->colorCached !== null) {
            return $this->colorCached;
        }
        if ($this->cells === null) {
            return $this->colorCached = false;
        }
        foreach ($this->cells as $cell) {
            if ($cell->hasColor()) {
                return $this->colorCached = true;
            }
        }
        return $this->colorCached = false;
    }

    /** @return list<Cell> */
    public function cells(): array
    {
        if ($this->cells !== null) {
            return $this->cells;
        }
        return $this->expandToCells();
    }

    public function toText(): string
    {
        if ($this->cells === null) {
            return $this->text ?? '';
        }
        $result = '';
        foreach ($this->cells as $cell) {
            $result .= $cell->char;
        }
        return $result;
    }

    public function toAnsi(): string
    {
        if ($this->cells === null || !$this->hasColor()) {
            return $this->toText();
        }

        $result = '';
        $prevFg = -1;
        $prevBg = -1;
        $prevFg16 = -2;
        $prevBg16 = -2;
        $colorActive = false;

        foreach ($this->cells as $cell) {
            $fgKey = $cell->fg ?? -2;
            $bgKey = $cell->bg ?? -2;
            $fg16Key = $cell->fgBase16 ?? -2;
            $bg16Key = $cell->bgBase16 ?? -2;

            if ($fgKey !== $prevFg || $bgKey !== $prevBg || $fg16Key !== $prevFg16 || $bg16Key !== $prevBg16) {
                if ($cell->fg !== null || $cell->bg !== null) {
                    $result .= AnsiColor::buildSgr($cell->fg, $cell->bg, $cell->fgBase16, $cell->bgBase16);
                    $colorActive = true;
                } elseif ($colorActive) {
                    $result .= "\e[0m";
                    $colorActive = false;
                }
                $prevFg = $fgKey;
                $prevBg = $bgKey;
                $prevFg16 = $fg16Key;
                $prevBg16 = $bg16Key;
            }

            $result .= $cell->char;
        }

        if ($colorActive) {
            $result .= "\e[0m";
        }

        return $result;
    }

    public static function fromString(string $text): self
    {
        $row = new self();
        $row->text = $text;
        return $row;
    }

    public static function fromAnsi(string $text): self
    {
        if (!str_contains($text, "\e")) {
            return self::fromString($text);
        }

        $state = new AnsiRowParseState();
        $len = \strlen($text);

        for ($i = 0; $i < $len;) {
            $i = self::fromAnsiAdvance($text, $i, $len, $state);
        }

        if (!$state->hasColor) {
            return self::fromString(implode('', array_map(static fn(Cell $cell): string => $cell->char, $state->cells)));
        }

        return new self($state->cells);
    }

    private static function fromAnsiAdvance(string $text, int $i, int $len, AnsiRowParseState $state): int
    {
        if ($text[$i] === "\e" && $i + 1 < $len && $text[$i + 1] === '[') {
            return self::fromAnsiHandleCsi($text, $i, $len, $state);
        }

        [$char, $next] = self::readUtf8Char($text, $i);
        if ($char === null) {
            return $next;
        }

        $state->cells[] = $state->negative
            ? Cell::get($char, $state->bg, $state->fg, $state->bgBase16, $state->fgBase16)
            : Cell::get($char, $state->fg, $state->bg, $state->fgBase16, $state->bgBase16);

        return $next;
    }

    private static function fromAnsiHandleCsi(string $text, int $i, int $len, AnsiRowParseState $state): int
    {
        $j = $i + 2;
        $num = '';
        while ($j < $len && $text[$j] >= '0' && $text[$j] <= '9') {
            $num .= $text[$j];
            $j++;
        }
        if ($j < $len && $text[$j] === 'C') {
            $n = $num === '' ? 1 : (int) $num;
            for ($k = 0; $k < $n; $k++) {
                $state->cells[] = Cell::get(' ');
            }

            return $j + 1;
        }

        $next = self::consumeSgr(
            $text,
            $i + 2,
            $len,
            $state->fg,
            $state->bg,
            $state->bold,
            $state->negative,
            $state->fgBase16,
            $state->bgBase16,
        );
        if ($state->fg !== null || $state->bg !== null) {
            $state->hasColor = true;
        }

        return $next;
    }

    /** @return array{string|null, int} */
    private static function readUtf8Char(string $text, int $i): array
    {
        $byte = ord($text[$i]);

        if ($byte < 0x80) {
            return [$text[$i], $i + 1];
        }

        $seqLen = match (true) {
            ($byte & 0xE0) === 0xC0 => 2,
            ($byte & 0xF0) === 0xE0 => 3,
            ($byte & 0xF8) === 0xF0 => 4,
            default => 0,
        };

        if ($seqLen === 0 || $i + $seqLen > \strlen($text)) {
            return [null, $i + 1];
        }

        return [substr($text, $i, $seqLen), $i + $seqLen];
    }

    private static function consumeSgr(
        string $text,
        int $i,
        int $len,
        ?int &$fg,
        ?int &$bg,
        bool &$bold,
        bool &$negative,
        ?int &$fgBase16,
        ?int &$bgBase16,
    ): int {
        $params = '';
        while ($i < $len && ($text[$i] === ';' || ($text[$i] >= '0' && $text[$i] <= '9'))) {
            $params .= $text[$i];
            $i++;
        }
        if ($i < $len && $text[$i] === 'm') {
            $i++;
            self::parseSgr($params, $fg, $bg, $bold, $negative, $fgBase16, $bgBase16);
        }
        return $i;
    }

    private static function parseSgr(
        string $params,
        ?int &$fg,
        ?int &$bg,
        bool &$bold,
        bool &$negative,
        ?int &$fgBase16,
        ?int &$bgBase16,
    ): void {
        if ($params === '' || $params === '0') {
            $fg = null;
            $bg = null;
            $bold = false;
            $negative = false;
            $fgBase16 = null;
            $bgBase16 = null;

            return;
        }

        $captureFg16 = null;
        $captureBg16 = null;
        $sawFgCompact = false;
        $sawBgCompact = false;
        $anyFgOp = false;
        $anyBgOp = false;
        $lastChannel = 'fg';

        $codes = array_map(intval(...), explode(';', $params));
        $count = count($codes);

        for ($i = 0; $i < $count; $i++) {
            $c = $codes[$i];

            if ($c === 38 && $i + 2 < $count && $codes[$i + 1] === 5) {
                $sawFgCompact = true;
                $fg = $codes[$i + 2];
                $anyFgOp = true;
                $lastChannel = 'fg';
                $i += 2;
                continue;
            }

            if ($c === 48 && $i + 2 < $count && $codes[$i + 1] === 5) {
                $sawBgCompact = true;
                $bg = $codes[$i + 2];
                $anyBgOp = true;
                $lastChannel = 'bg';
                $i += 2;
                continue;
            }

            if ($c === 38 && $i + 4 < $count && $codes[$i + 1] === 2) {
                $sawFgCompact = true;
                $fg = AnsiColor::truecolorFromRgb($codes[$i + 2], $codes[$i + 3], $codes[$i + 4]);
                $anyFgOp = true;
                $lastChannel = 'fg';
                $i += 4;
                continue;
            }

            if ($c === 48 && $i + 4 < $count && $codes[$i + 1] === 2) {
                $sawBgCompact = true;
                $bg = AnsiColor::truecolorFromRgb($codes[$i + 2], $codes[$i + 3], $codes[$i + 4]);
                $anyBgOp = true;
                $lastChannel = 'bg';
                $i += 4;
                continue;
            }

            if ($c >= AnsiColor::COMPACT_BASE && $c < AnsiColor::TRUECOLOR_BASE) {
                $idx = $c - AnsiColor::COMPACT_BASE;
                if ($lastChannel === 'bg') {
                    $sawBgCompact = true;
                    $bg = $idx;
                    $anyBgOp = true;
                } else {
                    $sawFgCompact = true;
                    $fg = $idx;
                    $anyFgOp = true;
                }
                continue;
            }

            if ($c >= AnsiColor::TRUECOLOR_BASE && $c <= AnsiColor::TRUECOLOR_COMPACT_MAX) {
                $tcValue = AnsiColor::TRUECOLOR_INTERNAL_BASE + ($c - AnsiColor::TRUECOLOR_BASE);
                if ($lastChannel === 'bg') {
                    $sawBgCompact = true;
                    $bg = $tcValue;
                    $anyBgOp = true;
                } else {
                    $sawFgCompact = true;
                    $fg = $tcValue;
                    $anyFgOp = true;
                }
                continue;
            }

            if ($c === 0) {
                $fg = null;
                $bg = null;
                $bold = false;
                $negative = false;
                $captureFg16 = null;
                $captureBg16 = null;
                $sawFgCompact = false;
                $sawBgCompact = false;
                $anyFgOp = true;
                $anyBgOp = true;
                $lastChannel = 'fg';
                continue;
            }

            self::applySgrCode($c, $fg, $bg, $bold, $negative);

            if ($c === 39) {
                $captureFg16 = null;
                $anyFgOp = true;
                $lastChannel = 'fg';
                continue;
            }

            if ($c === 49) {
                $captureBg16 = null;
                $anyBgOp = true;
                $lastChannel = 'bg';
                continue;
            }

            if ($c === 1 || $c === 22 || ($c >= 30 && $c <= 37) || ($c >= 90 && $c <= 97)) {
                $anyFgOp = true;
                $lastChannel = 'fg';
                if (!$sawFgCompact && $fg !== null && $fg < 16) {
                    $captureFg16 = $fg;
                }
                continue;
            }

            if (($c >= 40 && $c <= 47) || ($c >= 100 && $c <= 107)) {
                $anyBgOp = true;
                $lastChannel = 'bg';
                if (!$sawBgCompact && $bg !== null && $bg < 16) {
                    $captureBg16 = $bg;
                }
            }
        }

        if ($anyFgOp) {
            if ($fg === null) {
                $fgBase16 = null;
            } elseif ($captureFg16 !== null) {
                $fgBase16 = $captureFg16;
            } else {
                $fgBase16 = null;
            }
        }

        if ($anyBgOp) {
            if ($bg === null) {
                $bgBase16 = null;
            } elseif ($captureBg16 !== null) {
                $bgBase16 = $captureBg16;
            } else {
                $bgBase16 = null;
            }
        }
    }

    private static function applySgrCode(int $code, ?int &$fg, ?int &$bg, bool &$bold, bool &$negative): void
    {
        match (true) {
            $code === 1 => (static function () use (&$fg, &$bold): void {
                $bold = true;
                if ($fg !== null && $fg < 8) {
                    $fg += 8;
                }
            })(),
            $code === 7 => $negative = true,
            $code === 22 => (static function () use (&$fg, &$bold): void {
                $bold = false;
                if ($fg !== null && $fg >= 8 && $fg < 16) {
                    $fg -= 8;
                }
            })(),
            $code === 27 => $negative = false,
            $code >= 30 && $code <= 37 => $fg = $code - 30 + ($bold ? 8 : 0),
            $code === 39 => $fg = null,
            $code >= 40 && $code <= 47 => $bg = $code - 40,
            $code === 49 => $bg = null,
            $code >= 90 && $code <= 97 => $fg = $code - 90 + 8,
            $code >= 100 && $code <= 107 => $bg = $code - 100 + 8,
            default => null,
        };
    }

    public static function empty(int $length): self
    {
        if ($length <= 0) {
            return new self();
        }
        $row = new self();
        $row->text = str_repeat(' ', $length);
        return $row;
    }

    /** @return list<Cell> */
    private function expandToCells(): array
    {
        $text = $this->text ?? '';
        $cells = [];
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $cells[] = Cell::get(mb_substr($text, $i, 1, 'UTF-8'));
        }
        return $cells;
    }
}
