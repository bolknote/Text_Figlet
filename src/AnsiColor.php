<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/**
 * Terminal color detection, conversion (truecolor / 256 / 16), and SGR output.
 *
 * Extracted from Row to keep Row focused on cell storage and ANSI parsing.
 *
 * @psalm-api
 */
final class AnsiColor
{
    public const COMPACT_BASE = 256;
    public const TRUECOLOR_BASE = 512;
    public const TRUECOLOR_COMPACT_MAX = self::TRUECOLOR_BASE + 0xFFFFFF;

    /** Internal offset for truecolor values: stored as BASE + (B<<16 | G<<8 | R). */
    public const TRUECOLOR_INTERNAL_BASE = 16777216;

    public const COLOR_LEVEL_16 = 0;
    public const COLOR_LEVEL_256 = 1;
    public const COLOR_LEVEL_TRUECOLOR = 2;

    private static ?int $colorSupport = null;

    /** @var array<int, int> */
    private static array $downgradeCache = [];

    /** @var array<int, int> */
    private static array $nearest256Cache = [];

    /** @var list<array{float, float, float}>|null */
    private static ?array $base16Lab = null;

    /** @var list<array{int, int, int}>|null */
    private static ?array $ansi256Rgb = null;

    /** @var list<array{int, int, int}> */
    private const BASE16_RGB = [
        [0, 0, 0], [170, 0, 0], [0, 170, 0], [170, 85, 0],
        [0, 0, 170], [170, 0, 170], [0, 170, 170], [170, 170, 170],
        [85, 85, 85], [255, 85, 85], [85, 255, 85], [255, 255, 85],
        [85, 85, 255], [255, 85, 255], [85, 255, 255], [255, 255, 255],
    ];

    /** Reset all static caches. Call after changing TERM/COLORTERM mid-process. */
    public static function resetCaches(): void
    {
        self::$colorSupport = null;
        self::$downgradeCache = [];
        self::$nearest256Cache = [];
        self::$base16Lab = null;
        self::$ansi256Rgb = null;
    }

    /** Convert an internal color index (base-16, 256, or truecolor) to a hex string. */
    public static function colorToHex(int $index): string
    {
        if ($index < 0) {
            return '#000000';
        }
        if (self::isTruecolor($index)) {
            [$r, $g, $b] = self::truecolorToRgb($index);
            return sprintf('#%02x%02x%02x', $r, $g, $b);
        }
        if ($index > 255) {
            return '#000000';
        }
        $rgb = self::ansi256ToRgb($index);
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    public static function isTruecolor(int $color): bool
    {
        return $color >= self::TRUECOLOR_INTERNAL_BASE;
    }

    public static function truecolorFromRgb(int $r, int $g, int $b): int
    {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));

        return self::TRUECOLOR_INTERNAL_BASE + (($b << 16) | ($g << 8) | $r);
    }

    /** @return array{int, int, int} */
    public static function truecolorToRgb(int $color): array
    {
        $bgr = $color - self::TRUECOLOR_INTERNAL_BASE;
        return [$bgr & 0xff, ($bgr >> 8) & 0xff, ($bgr >> 16) & 0xff];
    }

    /** Build an SGR escape sequence for the given colors, adapting to the terminal tier. */
    public static function buildSgr(?int $fg, ?int $bg, ?int $fgBase16 = null, ?int $bgBase16 = null): string
    {
        $level = self::colorSupportLevel();

        $seq = "\e[0";

        if ($fg !== null) {
            $fgEff = $level === self::COLOR_LEVEL_16 && $fgBase16 !== null
                ? $fgBase16
                : self::normalizeColorForLevel($fg, $level);
            if ($level === self::COLOR_LEVEL_TRUECOLOR && self::isTruecolor($fgEff)) {
                [$r, $g, $b] = self::truecolorToRgb($fgEff);
                $seq .= ';38;2;' . $r . ';' . $g . ';' . $b;
            } elseif ($fgEff < 8) {
                $seq .= ';' . (30 + $fgEff);
            } elseif ($fgEff < 16) {
                $seq .= ';' . (82 + $fgEff);
            } else {
                $seq .= ';38;5;' . $fgEff;
            }
        }

        if ($bg !== null) {
            $bgEff = $level === self::COLOR_LEVEL_16 && $bgBase16 !== null
                ? $bgBase16
                : self::normalizeColorForLevel($bg, $level);
            if ($level === self::COLOR_LEVEL_TRUECOLOR && self::isTruecolor($bgEff)) {
                [$r, $g, $b] = self::truecolorToRgb($bgEff);
                $seq .= ';48;2;' . $r . ';' . $g . ';' . $b;
            } elseif ($bgEff < 8) {
                $seq .= ';' . (40 + $bgEff);
            } elseif ($bgEff < 16) {
                $seq .= ';' . (92 + $bgEff);
            } else {
                $seq .= ';48;5;' . $bgEff;
            }
        }

        return $seq . 'm';
    }

    /**
     * Detected terminal color tier, cached for the process lifetime.
     * Use {@see resetCaches()} when changing TERM/COLORTERM mid-process.
     */
    public static function colorSupportLevel(): int
    {
        if (self::$colorSupport !== null) {
            return self::$colorSupport;
        }

        $colorterm = getenv('COLORTERM');
        if (is_string($colorterm)) {
            $colorterm = strtolower($colorterm);
            if ($colorterm === 'truecolor' || $colorterm === '24bit') {
                return self::$colorSupport = self::COLOR_LEVEL_TRUECOLOR;
            }
        }

        $term = getenv('TERM');
        if (!is_string($term)) {
            return self::$colorSupport = self::COLOR_LEVEL_16;
        }

        return self::$colorSupport = str_contains($term, '256color')
            ? self::COLOR_LEVEL_256
            : self::COLOR_LEVEL_16;
    }

    /** @return array{int, int, int} */
    private static function ansi256ToRgb(int $color): array
    {
        if ($color >= 0 && $color < 16) {
            return self::BASE16_RGB[$color];
        }

        $toVal = static fn(int $level): int => $level === 0 ? 0 : 55 + 40 * $level;

        if ($color < 232) {
            $idx = $color - 16;
            return [$toVal(intdiv($idx, 36)), $toVal(intdiv($idx % 36, 6)), $toVal($idx % 6)];
        }

        $gray = 8 + 10 * ($color - 232);
        return [$gray, $gray, $gray];
    }

    /** @return array{int, int, int} */
    private static function colorToRgb(int $color): array
    {
        return self::isTruecolor($color)
            ? self::truecolorToRgb($color)
            : self::ansi256ToRgb($color);
    }

    private static function normalizeColorForLevel(int $color, int $level): int
    {
        if ($level === self::COLOR_LEVEL_TRUECOLOR) {
            return $color;
        }

        if ($level === self::COLOR_LEVEL_256) {
            if ($color < 256) {
                return $color;
            }

            return self::nearestAnsi256(self::colorToRgb($color));
        }

        return self::downgradeColor($color);
    }

    private static function downgradeColor(int $color): int
    {
        if ($color < 16) {
            return $color;
        }

        if (isset(self::$downgradeCache[$color])) {
            return self::$downgradeCache[$color];
        }

        $rgb = self::colorToRgb($color);
        $best = self::nearestBase16($rgb);

        return self::$downgradeCache[$color] = $best;
    }

    /** @param array{int, int, int} $rgb */
    private static function nearestBase16(array $rgb): int
    {
        if (self::$base16Lab === null) {
            self::$base16Lab = [];
            foreach (self::BASE16_RGB as $ref) {
                self::$base16Lab[] = self::rgbToLab($ref[0], $ref[1], $ref[2]);
            }
        }

        $lab = self::rgbToLab($rgb[0], $rgb[1], $rgb[2]);
        $srcChroma = self::labChroma($lab);
        $srcHue = $srcChroma > 5.0 ? atan2($lab[2], $lab[1]) : 0.0;

        $best = 0;
        $bestDist = PHP_FLOAT_MAX;

        foreach (self::$base16Lab as $idx => $ref) {
            $dist = ($lab[0] - $ref[0]) ** 2.0 + ($lab[1] - $ref[1]) ** 2.0 + ($lab[2] - $ref[2]) ** 2.0;

            if ($srcChroma > 5.0) {
                $tgtChroma = self::labChroma($ref);
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

    /** @return list<array{int, int, int}> */
    private static function getAnsi256Rgb(): array
    {
        if (self::$ansi256Rgb !== null) {
            return self::$ansi256Rgb;
        }

        /** @var list<array{int, int, int}> $table */
        $table = [];
        $toVal = static fn(int $level): int => $level === 0 ? 0 : 55 + 40 * $level;

        for ($i = 0; $i < 16; $i++) {
            $table[] = self::BASE16_RGB[$i];
        }
        for ($i = 16; $i < 232; $i++) {
            $idx = $i - 16;
            $table[] = [$toVal(intdiv($idx, 36)), $toVal(intdiv($idx % 36, 6)), $toVal($idx % 6)];
        }
        for ($i = 232; $i < 256; $i++) {
            $gray = 8 + 10 * ($i - 232);
            $table[] = [$gray, $gray, $gray];
        }

        return self::$ansi256Rgb = $table;
    }

    /** @param array{int, int, int} $rgb */
    private static function nearestAnsi256(array $rgb): int
    {
        $key = ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
        if (isset(self::$nearest256Cache[$key])) {
            return self::$nearest256Cache[$key];
        }

        $palette = self::getAnsi256Rgb();
        $best = 0;
        $bestDist = PHP_INT_MAX;

        for ($idx = 0; $idx < 256; $idx++) {
            $ref = $palette[$idx];
            $dist = ($rgb[0] - $ref[0]) ** 2 + ($rgb[1] - $ref[1]) ** 2 + ($rgb[2] - $ref[2]) ** 2;
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $idx;
                if ($dist === 0) {
                    break;
                }
            }
        }

        return self::$nearest256Cache[$key] = $best;
    }

    /** @return array{float, float, float} */
    private static function rgbToLab(int $r, int $g, int $b): array
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
    private static function labChroma(array $lab): float
    {
        return sqrt($lab[1] ** 2.0 + $lab[2] ** 2.0);
    }
}
