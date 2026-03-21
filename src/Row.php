<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @psalm-api */
final class Row
{
    /** @var list<Cell>|null Cell objects (used when colors are present) */
    private ?array $cells;

    /** @var string|null Plain text (used when no colors) */
    private ?string $text;

    private ?bool $colorCached = null;

    private static ?bool $supports256 = null;

    /** @var array<int, int> */
    private static array $downgradeCache = [];

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
            return $this->cells[$position] ?? new Cell(' ');
        }
        $ch = mb_substr($this->text ?? '', $position, 1, 'UTF-8');
        return new Cell($ch !== '' ? $ch : ' ');
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
            $space = new Cell(' ');
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
                    $cells[] = new Cell($to, $cell->fg, $cell->bg);
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
        $colorActive = false;

        foreach ($this->cells as $cell) {
            $fgKey = $cell->fg ?? -2;
            $bgKey = $cell->bg ?? -2;

            if ($fgKey !== $prevFg || $bgKey !== $prevBg) {
                if ($cell->fg !== null || $cell->bg !== null) {
                    $result .= $this->buildSgr($cell->fg, $cell->bg);
                    $colorActive = true;
                } elseif ($colorActive) {
                    $result .= "\e[0m";
                    $colorActive = false;
                }
                $prevFg = $fgKey;
                $prevBg = $bgKey;
            }

            $result .= $cell->char;
        }

        if ($colorActive) {
            $result .= "\e[0m";
        }

        return $result;
    }

    private function supports256Colors(): bool
    {
        if (self::$supports256 !== null) {
            return self::$supports256;
        }

        $colorterm = getenv('COLORTERM');
        if ($colorterm === 'truecolor' || $colorterm === '24bit') {
            return self::$supports256 = true;
        }

        $term = getenv('TERM');
        if (!is_string($term)) {
            return self::$supports256 = false;
        }
        return self::$supports256 = str_contains($term, '256color');
    }

    private function downgradeColor(int $color): int
    {
        if ($color < 16) {
            return $color;
        }

        if (isset(self::$downgradeCache[$color])) {
            return self::$downgradeCache[$color];
        }

        $rgb = $this->ansi256ToRgb($color);
        $best = $this->nearestBase16($rgb);

        return self::$downgradeCache[$color] = $best;
    }

    /** @return array{int, int, int} */
    private function ansi256ToRgb(int $color): array
    {
        $toVal = static fn(int $level): int => $level === 0 ? 0 : 55 + 40 * $level;

        if ($color < 232) {
            $idx = $color - 16;
            return [$toVal(intdiv($idx, 36)), $toVal(intdiv($idx % 36, 6)), $toVal(abs($idx % 6))];
        }

        $gray = 8 + 10 * ($color - 232);
        return [$gray, $gray, $gray];
    }

    private const BASE16_RGB = [
        [0, 0, 0], [170, 0, 0], [0, 170, 0], [170, 85, 0],
        [0, 0, 170], [170, 0, 170], [0, 170, 170], [170, 170, 170],
        [85, 85, 85], [255, 85, 85], [85, 255, 85], [255, 255, 85],
        [85, 85, 255], [255, 85, 255], [85, 255, 255], [255, 255, 255],
    ];

    /** @var list<array{float, float, float}>|null */
    private static ?array $base16Lab = null;

    /** @return array{float, float, float} */
    private function rgbToLab(int $r, int $g, int $b): array
    {
        $toLinear = static function (int $c): float {
            $v = (float) $c / 255.0;
            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        $rl = $toLinear($r);
        $gl = $toLinear($g);
        $bl = $toLinear($b);

        $xyzX = 0.4124564 * $rl + 0.3575761 * $gl + 0.1804375 * $bl;
        $xyzY = 0.2126729 * $rl + 0.7151522 * $gl + 0.0721750 * $bl;
        $xyzZ = 0.0193339 * $rl + 0.1191920 * $gl + 0.9503041 * $bl;

        $labF = (static fn(float $val): float => $val > 0.008856 ? $val ** (1.0 / 3.0) : 7.787 * $val + 16.0 / 116.0);
        $labX = $labF($xyzX / 0.95047);
        $labY = $labF($xyzY / 1.0);
        $labZ = $labF($xyzZ / 1.08883);

        return [116.0 * $labY - 16.0, 500.0 * ($labX - $labY), 200.0 * ($labY - $labZ)];
    }

    /** @param array{float, float, float} $lab */
    private function labChroma(array $lab): float
    {
        return sqrt($lab[1] ** 2.0 + $lab[2] ** 2.0);
    }

    /** @param array{int, int, int} $rgb */
    private function nearestBase16(array $rgb): int
    {
        if (self::$base16Lab === null) {
            self::$base16Lab = [];
            foreach (self::BASE16_RGB as $ref) {
                self::$base16Lab[] = $this->rgbToLab($ref[0], $ref[1], $ref[2]);
            }
        }

        $lab = $this->rgbToLab($rgb[0], $rgb[1], $rgb[2]);
        $srcChroma = $this->labChroma($lab);
        $srcHue = $srcChroma > 5.0 ? atan2($lab[2], $lab[1]) : 0.0;

        $best = 0;
        $bestDist = PHP_FLOAT_MAX;

        foreach (self::$base16Lab as $idx => $ref) {
            $dist = ($lab[0] - $ref[0]) ** 2.0 + ($lab[1] - $ref[1]) ** 2.0 + ($lab[2] - $ref[2]) ** 2.0;

            if ($srcChroma > 5.0) {
                $tgtChroma = $this->labChroma($ref);
                if ($tgtChroma < 5.0) {
                    $dist += ($srcChroma * 4.0) ** 2.0;
                } else {
                    $tgtHue = atan2($ref[2], $ref[1]);
                    $deltaHue = abs($srcHue - $tgtHue);
                    if ($deltaHue > M_PI) {
                        $deltaHue = 2.0 * M_PI - $deltaHue;
                    }
                    $huePenalty = $srcChroma * 5.0 * sin($deltaHue / 2.0);
                    $dist += $huePenalty ** 2.0;
                }
            }

            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $idx;
            }
        }

        return $best;
    }

    private function buildSgr(?int $fg, ?int $bg): string
    {
        if (!$this->supports256Colors()) {
            if ($fg !== null && $fg >= 16) {
                $fg = $this->downgradeColor($fg);
            }
            if ($bg !== null && $bg >= 16) {
                $bg = $this->downgradeColor($bg);
            }
        }

        $seq = "\e[0";

        if ($fg !== null) {
            if ($fg < 8) {
                $seq .= ';' . (30 + $fg);
            } elseif ($fg < 16) {
                $seq .= ';' . (82 + $fg);
            } else {
                $seq .= ';38;5;' . $fg;
            }
        }

        if ($bg !== null) {
            if ($bg < 8) {
                $seq .= ';' . (40 + $bg);
            } elseif ($bg < 16) {
                $seq .= ';' . (92 + $bg);
            } else {
                $seq .= ';48;5;' . $bg;
            }
        }

        return $seq . 'm';
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

        $cells = [];
        $fg = null;
        $bg = null;
        $bold = false;
        $negative = false;
        $hasColor = false;
        $len = strlen($text);

        for ($i = 0; $i < $len;) {
            if ($text[$i] === "\e" && $i + 1 < $len && $text[$i + 1] === '[') {
                $i = self::consumeSgr($text, $i + 2, $len, $fg, $bg, $bold, $negative);
                if ($fg !== null || $bg !== null) {
                    $hasColor = true;
                }
                continue;
            }

            [$char, $i] = self::readUtf8Char($text, $i);
            if ($char === null) {
                continue;
            }

            $cells[] = $negative
                ? new Cell($char, $bg, $fg)
                : new Cell($char, $fg, $bg);
        }

        if (!$hasColor) {
            return self::fromString(implode('', array_map(static fn(Cell $cell): string => $cell->char, $cells)));
        }

        return new self($cells);
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

        if ($seqLen === 0 || $i + $seqLen > strlen($text)) {
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
    ): int {
        $params = '';
        while ($i < $len && ($text[$i] === ';' || ($text[$i] >= '0' && $text[$i] <= '9'))) {
            $params .= $text[$i];
            $i++;
        }
        if ($i < $len && $text[$i] === 'm') {
            $i++;
            self::parseSgr($params, $fg, $bg, $bold, $negative);
        }
        return $i;
    }

    private static function parseSgr(string $params, ?int &$fg, ?int &$bg, bool &$bold, bool &$negative): void
    {
        if ($params === '' || $params === '0') {
            $fg = null;
            $bg = null;
            $bold = false;
            $negative = false;
            return;
        }

        $codes = array_map(intval(...), explode(';', $params));
        $count = count($codes);

        for ($i = 0; $i < $count; $i++) {
            self::applySgrCode($codes[$i], $fg, $bg, $bold, $negative);
        }
    }

    private static function applySgrCode(int $code, ?int &$fg, ?int &$bg, bool &$bold, bool &$negative): void
    {
        match (true) {
            $code === 0 => (static function () use (&$fg, &$bg, &$bold, &$negative): void {
                $fg = null;
                $bg = null;
                $bold = false;
                $negative = false;
            })(),
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
            $code >= 256 && $code <= 511 => $fg = $code - 256,
            $code >= 512 && $code <= 767 => $bg = $code - 512,
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
            $cells[] = new Cell(mb_substr($text, $i, 1, 'UTF-8'));
        }
        return $cells;
    }
}
