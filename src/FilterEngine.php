<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @internal */
final class FilterEngine
{
    /**
     * @param list<string> $figure
     * @return list<string>
     */
    public static function apply(Filter $filter, array $figure): array
    {
        return match ($filter) {
            Filter::Crop => self::crop($figure),
            Filter::Flip => self::flip($figure),
            Filter::Flop => self::flop($figure),
            Filter::Rotate180 => self::flip(self::flop($figure)),
            Filter::RotateLeft => self::rotateLeft($figure),
            Filter::RotateRight => self::rotateRight($figure),
            Filter::Border => self::border($figure),
            Filter::Rainbow => self::rainbow($figure),
            Filter::Metal => self::metal($figure),
        };
    }

    private const MIRROR_CHARS = [
        '(' => ')', ')' => '(',
        '[' => ']', ']' => '[',
        '{' => '}', '}' => '{',
        '<' => '>', '>' => '<',
        '/' => '\\', '\\' => '/',
        '┌' => '┐', '┐' => '┌',
        '└' => '┘', '┘' => '└',
        '┏' => '┓', '┓' => '┏',
        '┗' => '┛', '┛' => '┗',
        '╔' => '╗', '╗' => '╔',
        '╚' => '╝', '╝' => '╚',
        '├' => '┤', '┤' => '├',
        '┣' => '┫', '┫' => '┣',
        '╠' => '╣', '╣' => '╠',
        '╸' => '╺', '╺' => '╸',
        '╴' => '╶', '╶' => '╴',
        '▌' => '▐', '▐' => '▌',
    ];

    private const FLOP_CHARS = [
        '/' => '\\', '\\' => '/',
        '┌' => '└', '└' => '┌',
        '┐' => '┘', '┘' => '┐',
        '┏' => '┗', '┗' => '┏',
        '┓' => '┛', '┛' => '┓',
        '╔' => '╚', '╚' => '╔',
        '╗' => '╝', '╝' => '╗',
        '┬' => '┴', '┴' => '┬',
        '┳' => '┻', '┻' => '┳',
        '╦' => '╩', '╩' => '╦',
        '╻' => '╹', '╹' => '╻',
        '╷' => '╵', '╵' => '╷',
        '▀' => '▄', '▄' => '▀',
    ];

    /** @var list<array{string, string, string, string}> */
    private const PAIR_2X2 = [
        ['▄', '▀', '▀', '▄'],
    ];

    /** @var list<array{string, string, string, string, string, string, string, string}> */
    private const PAIR_2X4 = [
        [':', ' ', '.', '.', ' ', ':', '\'', '\''],
        ['/', ' ', '-', '.', ' ', '/', '\'', '-'],
        ['\\', ' ', '.', '-', ' ', '\\', '-', '\''],
        ['\\', '_', '_', '/', '‾', '\\', '/', '‾'],
        ['_', '\\', '‾', '/', '\\', '‾', '/', '_'],
        ['|', ' ', '_', '_', ' ', '|', '‾', '‾'],
        ['_', '|', '‾', '|', '|', '‾', '|', '_'],
        ['|', '_', '_', '|', '‾', '|', '|', '‾'],
        ['▄', ' ', ' ', '▄', ' ', '▀', '▀', ' '],
        ['█', ' ', '▄', '▄', ' ', '█', '▀', '▀'],
        ['█', '▄', '▄', '█', '▀', '█', '█', '▀'],
    ];

    private static function len(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    private static function charAt(string $text, int $pos): string
    {
        return mb_substr($text, $pos, 1, 'UTF-8');
    }

    private static function slice(string $text, int $start, ?int $length = null): string
    {
        return mb_substr($text, $start, $length, 'UTF-8');
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function crop(array $figure): array
    {
        $figure = self::cropVertical($figure);
        return self::cropHorizontal($figure);
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function cropVertical(array $figure): array
    {
        $top = -1;
        $bottom = -1;

        foreach ($figure as $idx => $row) {
            if (trim($row) !== '') {
                if ($top === -1) {
                    $top = $idx;
                }
                $bottom = $idx;
            }
        }

        if ($top === -1) {
            return [''];
        }

        return array_slice($figure, $top, $bottom - $top + 1);
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function cropHorizontal(array $figure): array
    {
        $minLeft = PHP_INT_MAX;
        $maxRight = 0;

        foreach ($figure as $row) {
            $rowLen = self::len($row);
            if ($rowLen === 0) {
                continue;
            }

            $left = 0;
            while ($left < $rowLen && self::charAt($row, $left) === ' ') {
                $left++;
            }

            $right = $rowLen;
            while ($right > 0 && self::charAt($row, $right - 1) === ' ') {
                $right--;
            }

            if ($right > $left) {
                $minLeft = min($minLeft, $left);
                $maxRight = max($maxRight, $right);
            }
        }

        if ($minLeft >= $maxRight) {
            return $figure;
        }

        $result = [];
        foreach ($figure as $row) {
            $result[] = self::slice($row, $minLeft, $maxRight - $minLeft);
        }

        return $result;
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function flip(array $figure): array
    {
        $result = [];
        foreach ($figure as $row) {
            $rowLen = self::len($row);
            $reversed = '';
            for ($i = $rowLen - 1; $i >= 0; $i--) {
                $char = self::charAt($row, $i);
                $reversed .= self::MIRROR_CHARS[$char] ?? $char;
            }
            $result[] = $reversed;
        }

        return $result;
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function flop(array $figure): array
    {
        $result = [];
        foreach (array_reverse($figure) as $row) {
            $rowLen = self::len($row);
            $mapped = '';
            for ($i = 0; $i < $rowLen; $i++) {
                $char = self::charAt($row, $i);
                $mapped .= self::FLOP_CHARS[$char] ?? $char;
            }
            $result[] = $mapped;
        }

        return $result;
    }

    /**
     * Pair-based 90° rotation matching libcaca's caca_rotate_right.
     * Two horizontal characters map to one vertical position.
     * New dimensions: width = height * 2, height = ceil(width / 2).
     *
     * @param list<string> $figure
     * @return list<string>
     */
    private static function rotateRight(array $figure): array
    {
        return self::rotatePairBased($figure, 'right');
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function rotateLeft(array $figure): array
    {
        return self::rotatePairBased($figure, 'left');
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function rotatePairBased(array $figure, string $direction): array
    {
        $grid = self::toGrid($figure);
        $height = count($grid);
        if ($height === 0) {
            return [''];
        }
        $width = count($grid[0]);
        $halfWidth = intdiv($width + 1, 2);

        $newWidth = $height * 2;
        $newHeight = $halfWidth;

        /** @var array<int, string> $flat */
        $flat = array_fill(0, $newWidth * $newHeight, ' ');

        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $halfWidth; $col++) {
                $pair = [
                    $grid[$row][$col * 2],
                    ($col * 2 + 1 < $width) ? $grid[$row][$col * 2 + 1] : ' ',
                ];

                self::transformPair($pair, $direction);

                if ($direction === 'right') {
                    $dest = ($height * $col + $height - 1 - $row) * 2;
                } else {
                    $dest = ($height * ($halfWidth - 1 - $col) + $row) * 2;
                }

                $flat[$dest] = $pair[0];
                $flat[$dest + 1] = $pair[1];
            }
        }

        $lines = [];
        for ($row = 0; $row < $newHeight; $row++) {
            $line = '';
            for ($col = 0; $col < $newWidth; $col++) {
                $line .= $flat[$row * $newWidth + $col];
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param array{0: string, 1: string} $pair
     */
    private static function transformPair(array &$pair, string $direction): void
    {
        foreach (self::PAIR_2X2 as $group) {
            for ($idx = 0; $idx < 4; $idx += 2) {
                /** @psalm-suppress InvalidArrayOffset */
                if ($pair[0] === $group[$idx] && $pair[1] === $group[$idx + 1]) {
                    $next = $direction === 'right'
                        ? (($idx - 2) & 3)
                        : (($idx + 2) & 3);
                    $pair[0] = $group[$next];
                    $pair[1] = $group[$next + 1];
                    return;
                }
            }
        }

        foreach (self::PAIR_2X4 as $group) {
            for ($idx = 0; $idx < 8; $idx += 2) {
                /** @psalm-suppress InvalidArrayOffset */
                if ($pair[0] === $group[$idx] && $pair[1] === $group[$idx + 1]) {
                    $next = $direction === 'right'
                        ? (($idx - 2) & 7)
                        : (($idx + 2) & 7);
                    $pair[0] = $group[$next];
                    $pair[1] = $group[$next + 1];
                    return;
                }
            }
        }
    }

    /**
     * @param list<string> $figure
     * @return list<list<string>>
     */
    private static function toGrid(array $figure): array
    {
        $maxWidth = 0;
        foreach ($figure as $row) {
            $maxWidth = max($maxWidth, self::len($row));
        }

        $grid = [];
        foreach ($figure as $row) {
            $chars = [];
            $rowLen = self::len($row);
            for ($i = 0; $i < $maxWidth; $i++) {
                $chars[] = $i < $rowLen ? self::charAt($row, $i) : ' ';
            }
            $grid[] = $chars;
        }

        return $grid;
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function border(array $figure): array
    {
        $maxWidth = 0;
        foreach ($figure as $row) {
            $maxWidth = max($maxWidth, self::len($row));
        }

        $result = ['┌' . str_repeat('─', $maxWidth) . '┐'];
        foreach ($figure as $row) {
            $pad = $maxWidth - self::len($row);
            $result[] = '│' . $row . str_repeat(' ', $pad) . '│';
        }
        $result[] = '└' . str_repeat('─', $maxWidth) . '┘';

        return $result;
    }

    /**
     * @param list<string> $colors
     */
    private static function colorize(string $row, int $offset, array $colors, int $divisor): string
    {
        $count = count($colors);
        $rowLen = self::len($row);
        $colored = '';
        $hasColor = false;

        for ($col = 0; $col < $rowLen; $col++) {
            $char = self::charAt($row, $col);
            if ($char !== ' ') {
                $idx = intdiv($col, $divisor) + $offset;
                $key = (($idx % $count) + $count) % $count;
                $colored .= $colors[$key] . $char;
                $hasColor = true;
            } else {
                $colored .= $char;
            }
        }

        return $hasColor ? $colored . "\e[0m" : $colored;
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function rainbow(array $figure): array
    {
        $palette = ["\e[95m", "\e[91m", "\e[93m", "\e[92m", "\e[96m", "\e[94m"];
        $result = [];

        foreach ($figure as $row => $line) {
            $result[] = self::colorize($line, $row, $palette, 2);
        }

        return $result;
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private static function metal(array $figure): array
    {
        $palette = ["\e[94m", "\e[34m", "\e[37m", "\e[90m"];
        $result = [];

        foreach ($figure as $row => $line) {
            $result[] = self::colorize($line, intdiv($row, 2), $palette, 8);
        }

        return $result;
    }
}
