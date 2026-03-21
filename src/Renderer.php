<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @internal */
final class Renderer
{
    private const FG_COLORS = [
        0 => '#000', 1 => '#a00', 2 => '#0a0', 3 => '#a50',
        4 => '#00a', 5 => '#a0a', 6 => '#0aa', 7 => '#aaa',
        8 => '#555', 9 => '#f55', 10 => '#5f5', 11 => '#ff5',
        12 => '#55f', 13 => '#f5f', 14 => '#5ff', 15 => '#fff',
    ];

    private const FG_COLORS_FULL = [
        0 => '#000000', 1 => '#aa0000', 2 => '#00aa00', 3 => '#aa5500',
        4 => '#0000aa', 5 => '#aa00aa', 6 => '#00aaaa', 7 => '#aaaaaa',
        8 => '#555555', 9 => '#ff5555', 10 => '#55ff55', 11 => '#ffff55',
        12 => '#5555ff', 13 => '#ff55ff', 14 => '#55ffff', 15 => '#ffffff',
    ];

    /**
     * @param list<Row> $lines
     */
    public static function export(array $lines, ExportFormat $format): string
    {
        return match ($format) {
            ExportFormat::Text => self::toText($lines),
            ExportFormat::Html => self::toHtml($lines),
            ExportFormat::Html3 => self::toHtml3($lines),
        };
    }

    /**
     * @param list<Row> $lines
     */
    private static function toText(array $lines): string
    {
        $hasColor = false;
        foreach ($lines as $row) {
            if ($row->hasColor()) {
                $hasColor = true;
                break;
            }
        }

        $parts = [];
        foreach ($lines as $row) {
            $parts[] = $hasColor ? $row->toAnsi() : $row->toText();
        }

        return implode("\n", $parts) . "\n";
    }

    /**
     * @param list<Row> $lines
     */
    private static function toHtml(array $lines): string
    {
        $html = '';
        foreach ($lines as $idx => $row) {
            if ($idx > 0) {
                $html .= "\n";
            }
            $html .= self::rowToHtml($row, self::FG_COLORS);
        }

        return '<nobr>' . nl2br($html) . '</nobr>';
    }

    /**
     * @param list<Row> $lines
     */
    private static function toHtml3(array $lines): string
    {
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = '<tr>' . self::rowToHtml3($line, self::FG_COLORS_FULL) . '</tr>';
        }

        return '<table border="0" cellpadding="0" cellspacing="0">' . "\n"
            . implode("\n", $rows) . "\n"
            . '</table>' . "\n";
    }

    /**
     * Build Html3 row following the libcaca approach: group cells by
     * background color into <td bgcolor> with colspan for alignment,
     * then wrap each foreground run with <font color>.
     *
     * @param array<int, string> $colorMap
     */
    private static function rowToHtml3(Row $row, array $colorMap): string
    {
        if (!$row->hasColor()) {
            $text = $row->toText();
            $span = mb_strlen($text);
            $colAttr = $span > 1 ? ' colspan="' . $span . '"' : '';
            return '<td' . $colAttr . '><tt>' . self::textToEntities($text) . '</tt></td>';
        }

        $cells = $row->cells();
        $len = count($cells);
        $html = '';
        $i = 0;

        while ($i < $len) {
            $bg = $cells[$i]->bg;
            $bgHex = $bg !== null ? ($colorMap[$bg] ?? self::ansi256ToHex($bg)) : null;
            $tdAttr = $bgHex !== null ? ' bgcolor="' . $bgHex . '"' : '';

            $span = 0;
            $inner = '';
            while ($i < $len && $cells[$i]->bg === $bg) {
                $fg = $cells[$i]->fg;
                $fgHex = $fg !== null ? ($colorMap[$fg] ?? self::ansi256ToHex($fg)) : null;
                $run = '';
                $runLen = 0;

                while ($i < $len && $cells[$i]->bg === $bg && $cells[$i]->fg === $fg) {
                    $run .= self::charToEntity($cells[$i]->char);
                    $runLen++;
                    $i++;
                }

                $span += $runLen;
                $inner .= $fgHex !== null
                    ? '<font color="' . $fgHex . '">' . $run . '</font>'
                    : $run;
            }

            if ($span > 1) {
                $tdAttr .= ' colspan="' . $span . '"';
            }
            $html .= '<td' . $tdAttr . '><tt>' . $inner . '</tt></td>';
        }

        return $html;
    }

    /**
     * @param array<int, string> $colorMap base 16 colors for this format
     */
    private static function rowToHtml(Row $row, array $colorMap): string
    {
        if (!$row->hasColor()) {
            $encoded = htmlspecialchars($row->toText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return str_replace(' ', '&nbsp;', $encoded);
        }

        $cells = $row->cells();
        $len = count($cells);
        $html = '';
        $i = 0;

        while ($i < $len) {
            $fg = $cells[$i]->fg;
            $bg = $cells[$i]->bg;
            $group = '';

            while ($i < $len && $cells[$i]->fg === $fg && $cells[$i]->bg === $bg) {
                $encoded = htmlspecialchars($cells[$i]->char, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $group .= $encoded === ' ' ? '&nbsp;' : $encoded;
                $i++;
            }

            $fgHex = $fg !== null ? ($colorMap[$fg] ?? self::ansi256ToHex($fg)) : null;
            $bgHex = $bg !== null ? ($colorMap[$bg] ?? self::ansi256ToHex($bg)) : null;

            if ($fgHex !== null || $bgHex !== null) {
                $style = '';
                if ($fgHex !== null) {
                    $style .= 'color:' . $fgHex;
                }
                if ($bgHex !== null) {
                    $style .= ($style !== '' ? ';' : '') . 'background:' . $bgHex;
                }
                $html .= '<span style="' . $style . '">' . $group . '</span>';
            } else {
                $html .= $group;
            }
        }

        return $html;
    }

    private static function ansi256ToHex(int $index): string
    {
        if ($index < 0) {
            return '#000000';
        }
        if ($index < 16) {
            return self::FG_COLORS_FULL[$index];
        }

        if ($index < 232) {
            $idx = $index - 16;
            $red = intdiv($idx, 36);
            $green = intdiv($idx % 36, 6);
            $blue = $idx % 6;
            $toVal = static fn(int $level): int => $level === 0 ? 0 : 55 + 40 * $level;
            return sprintf('#%02x%02x%02x', $toVal($red), $toVal($green), $toVal($blue));
        }

        $gray = 8 + 10 * ($index - 232);
        return sprintf('#%02x%02x%02x', $gray, $gray, $gray);
    }

    private static function textToEntities(string $text): string
    {
        $result = '';
        $len = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $result .= self::charToEntity($char);
        }

        return $result;
    }

    private static function charToEntity(string $char): string
    {
        if ($char === ' ') {
            return '&#160;';
        }

        $ord = mb_ord($char, 'UTF-8');

        if ($ord > 127) {
            return '&#' . $ord . ';';
        }

        return htmlspecialchars($char, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
