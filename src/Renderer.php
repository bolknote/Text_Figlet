<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @internal */
final class Renderer
{
    private const ANSI_COLOR_MAP = [
        '30' => '#000', '31' => '#a00', '32' => '#0a0', '33' => '#a50',
        '34' => '#00a', '35' => '#a0a', '36' => '#0aa', '37' => '#aaa',
        '90' => '#555', '91' => '#f55', '92' => '#5f5', '93' => '#ff5',
        '94' => '#55f', '95' => '#f5f', '96' => '#5ff',
    ];

    private const ANSI_COLOR_MAP_FULL = [
        '30' => '#000000', '31' => '#aa0000', '32' => '#00aa00', '33' => '#aa5500',
        '34' => '#0000aa', '35' => '#aa00aa', '36' => '#00aaaa', '37' => '#aaaaaa',
        '90' => '#555555', '91' => '#ff5555', '92' => '#55ff55', '93' => '#ffff55',
        '94' => '#5555ff', '95' => '#ff55ff', '96' => '#55ffff',
    ];

    /**
     * @param list<string> $lines
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
     * @param list<string> $lines
     */
    private static function toText(array $lines): string
    {
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     */
    private static function toHtml(array $lines): string
    {
        $result = implode("\n", $lines);
        $html = htmlspecialchars($result, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = str_replace(' ', '&nbsp;', $html);
        $html = self::ansiToSpan($html);
        return '<nobr>' . nl2br($html) . '</nobr>';
    }

    /**
     * @param list<string> $lines
     */
    private static function toHtml3(array $lines): string
    {
        $rows = [];
        foreach ($lines as $line) {
            $encoded = self::toHtmlEntities($line);
            $encoded = self::ansiToFont($encoded);
            $rows[] = '<tr><td><tt>' . $encoded . '</tt></td></tr>';
        }

        return '<table border="0" cellpadding="0" cellspacing="0">' . "\n"
            . implode("\n", $rows) . "\n"
            . '</table>' . "\n";
    }

    private static function toHtmlEntities(string $text): string
    {
        $result = '';
        $len = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $ord = mb_ord($char, 'UTF-8');

            if ($char === "\e") {
                $result .= $char;
            } elseif ($char === ' ') {
                $result .= '&#160;';
            } elseif ($ord > 127) {
                $result .= '&#' . $ord . ';';
            } else {
                $result .= htmlspecialchars($char, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return $result;
    }

    private static function ansiToSpan(string $text): string
    {
        $map = self::ANSI_COLOR_MAP;

        return (string) preg_replace_callback(
            '/\e\[(\d+)m(.*?)(?=\e\[|\z)/s',
            static function (array $match) use ($map): string {
                $code = $match[1];
                $content = $match[2];
                if ($code === '0' || !isset($map[$code])) {
                    return $content;
                }
                return '<span style="color:' . $map[$code] . '">' . $content . '</span>';
            },
            $text,
        );
    }

    private static function ansiToFont(string $text): string
    {
        $map = self::ANSI_COLOR_MAP_FULL;

        return (string) preg_replace_callback(
            '/\e\[(\d+)m(.*?)(?=\e\[|\z)/s',
            static function (array $match) use ($map): string {
                $code = $match[1];
                $content = $match[2];
                if ($code === '0' || !isset($map[$code])) {
                    return $content;
                }
                return '<font color="' . $map[$code] . '">' . $content . '</font>';
            },
            $text,
        );
    }
}
