<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @internal */
final class FilterEngine
{
    /**
     * @param list<Row> $figure
     * @return list<Row>
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

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function crop(array $figure): array
    {
        $figure = self::cropVertical($figure);
        return self::cropHorizontal($figure);
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function cropVertical(array $figure): array
    {
        $top = -1;
        $bottom = -1;

        foreach ($figure as $idx => $row) {
            if (trim($row->toText()) !== '') {
                if ($top === -1) {
                    $top = $idx;
                }
                $bottom = $idx;
            }
        }

        if ($top === -1) {
            return [new Row([])];
        }

        return array_slice($figure, $top, $bottom - $top + 1);
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function cropHorizontal(array $figure): array
    {
        $minLeft = PHP_INT_MAX;
        $maxRight = 0;

        foreach ($figure as $row) {
            $rowLen = $row->length();
            if ($rowLen === 0) {
                continue;
            }

            $left = 0;
            while ($left < $rowLen && $row->charAt($left) === ' ') {
                $left++;
            }

            $right = $rowLen;
            while ($right > 0 && $row->charAt($right - 1) === ' ') {
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
            $result[] = $row->slice($minLeft, $maxRight - $minLeft);
        }

        return $result;
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function flip(array $figure): array
    {
        $result = [];
        foreach ($figure as $row) {
            $rowLen = $row->length();
            $cells = [];
            for ($i = $rowLen - 1; $i >= 0; $i--) {
                $cell = $row->cellAt($i);
                $mirrorChar = self::MIRROR_CHARS[$cell->char] ?? $cell->char;
                $cells[] = new Cell($mirrorChar, $cell->fg, $cell->bg);
            }
            $result[] = new Row($cells);
        }

        return $result;
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function flop(array $figure): array
    {
        $result = [];
        foreach (array_reverse($figure) as $row) {
            $rowLen = $row->length();
            $cells = [];
            for ($i = 0; $i < $rowLen; $i++) {
                $cell = $row->cellAt($i);
                $mappedChar = self::FLOP_CHARS[$cell->char] ?? $cell->char;
                $cells[] = new Cell($mappedChar, $cell->fg, $cell->bg);
            }
            $result[] = new Row($cells);
        }

        return $result;
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function rotateRight(array $figure): array
    {
        return self::rotatePairBased($figure, 'right');
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function rotateLeft(array $figure): array
    {
        return self::rotatePairBased($figure, 'left');
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function rotatePairBased(array $figure, string $direction): array
    {
        $grid = self::toGrid($figure);
        $height = count($grid);
        if ($height === 0) {
            return [new Row([])];
        }
        $width = count($grid[0]);
        $halfWidth = intdiv($width + 1, 2);

        $newWidth = $height * 2;
        $newHeight = $halfWidth;

        $space = new Cell(' ');
        /** @var array<int, Cell> $flat */
        $flat = array_fill(0, $newWidth * $newHeight, $space);

        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $halfWidth; $col++) {
                $cell0 = $grid[$row][$col * 2];
                $cell1 = ($col * 2 + 1 < $width) ? $grid[$row][$col * 2 + 1] : $space;

                $pair = [$cell0->char, $cell1->char];
                self::transformPair($pair, $direction);

                if ($direction === 'right') {
                    $dest = ($height * $col + $height - 1 - $row) * 2;
                } else {
                    $dest = ($height * ($halfWidth - 1 - $col) + $row) * 2;
                }

                $flat[$dest] = new Cell($pair[0], $cell0->fg, $cell0->bg);
                $flat[$dest + 1] = new Cell($pair[1], $cell1->fg, $cell1->bg);
            }
        }

        $lines = [];
        for ($row = 0; $row < $newHeight; $row++) {
            $cells = [];
            for ($col = 0; $col < $newWidth; $col++) {
                $cells[] = $flat[$row * $newWidth + $col];
            }
            $lines[] = new Row($cells);
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
     * @param list<Row> $figure
     * @return list<list<Cell>>
     */
    private static function toGrid(array $figure): array
    {
        $maxWidth = 0;
        foreach ($figure as $row) {
            $maxWidth = max($maxWidth, $row->length());
        }

        $space = new Cell(' ');
        $grid = [];
        foreach ($figure as $row) {
            $cells = [];
            $rowLen = $row->length();
            for ($i = 0; $i < $maxWidth; $i++) {
                $cells[] = $i < $rowLen ? $row->cellAt($i) : $space;
            }
            $grid[] = $cells;
        }

        return $grid;
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function border(array $figure): array
    {
        $maxWidth = 0;
        foreach ($figure as $row) {
            $maxWidth = max($maxWidth, $row->length());
        }

        $topBorder = Row::fromString('┌' . str_repeat('─', $maxWidth) . '┐');
        $bottomBorder = Row::fromString('└' . str_repeat('─', $maxWidth) . '┘');
        $left = Row::fromString('│');
        $right = Row::fromString('│');

        $result = [$topBorder];
        foreach ($figure as $row) {
            $result[] = $left->append($row->pad($maxWidth))->append($right);
        }
        $result[] = $bottomBorder;

        return $result;
    }

    private const RAINBOW_PALETTE = [13, 9, 11, 10, 14, 12];
    private const METAL_PALETTE = [12, 4, 7, 8];

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function rainbow(array $figure): array
    {
        return self::colorize($figure, self::RAINBOW_PALETTE, 2, false);
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private static function metal(array $figure): array
    {
        return self::colorize($figure, self::METAL_PALETTE, 8, true);
    }

    /**
     * @param list<Row> $figure
     * @param list<int> $palette
     * @return list<Row>
     */
    private static function colorize(array $figure, array $palette, int $divisor, bool $halfRow): array
    {
        $count = count($palette);
        $result = [];

        foreach ($figure as $rowIdx => $row) {
            $offset = $halfRow ? intdiv($rowIdx, 2) : $rowIdx;
            $cells = [];
            foreach ($row->cells() as $col => $cell) {
                if ($cell->char !== ' ') {
                    $idx = intdiv($col, $divisor) + $offset;
                    $key = (($idx % $count) + $count) % $count;
                    $cells[] = new Cell($cell->char, $palette[$key], $cell->bg);
                } else {
                    $cells[] = $cell;
                }
            }
            $result[] = new Row($cells);
        }

        return $result;
    }
}
