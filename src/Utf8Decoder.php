<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @internal */
final class Utf8Decoder
{
    /**
     * @return list<int>
     */
    public static function decode(string $str, bool $decodePercentEscapes = false): array
    {
        $codes = [];
        $length = strlen($str);

        for ($i = 0; $i < $length; $i++) {
            if ($decodePercentEscapes && substr($str, $i, 2) === '%u') {
                $codes[] = (int) hexdec(substr($str, $i + 2, 4));
                $i += 5;
                continue;
            }

            $byte = ord($str[$i]);

            if ($byte < 0x80) {
                $codes[] = $byte;
                continue;
            }

            if (($byte & 0xE0) === 0xC0) {
                $cont1 = self::continuationValue($str, $i + 1, $length);
                if ($cont1 === null) {
                    $codes[] = 128;
                    $i = self::skipContinuationBytes($str, $i + 1, $length) - 1;
                    continue;
                }

                $codes[] = (($byte & 0x1F) << 6) | $cont1;
                $i += 1;
                continue;
            }

            if (($byte & 0xF0) === 0xE0) {
                $cont1 = self::continuationValue($str, $i + 1, $length);
                $cont2 = self::continuationValue($str, $i + 2, $length);
                if ($cont1 === null || $cont2 === null) {
                    $codes[] = 128;
                    $i = self::skipContinuationBytes($str, $i + 1, $length) - 1;
                    continue;
                }

                $codes[] = (($byte & 0x0F) << 12) | ($cont1 << 6) | $cont2;
                $i += 2;
                continue;
            }

            if (($byte & 0xF8) === 0xF0) {
                $cont1 = self::continuationValue($str, $i + 1, $length);
                $cont2 = self::continuationValue($str, $i + 2, $length);
                $cont3 = self::continuationValue($str, $i + 3, $length);
                if ($cont1 === null || $cont2 === null || $cont3 === null) {
                    $codes[] = 128;
                    $i = self::skipContinuationBytes($str, $i + 1, $length) - 1;
                    continue;
                }

                $codes[] = (($byte & 0x07) << 18) | ($cont1 << 12) | ($cont2 << 6) | $cont3;
                $i += 3;
                continue;
            }

            $codes[] = 128;
        }

        return $codes;
    }

    private static function continuationValue(string $str, int $offset, int $length): ?int
    {
        if ($offset >= $length) {
            return null;
        }

        $byte = ord($str[$offset]);
        if (($byte & 0xC0) !== 0x80) {
            return null;
        }

        return $byte & 0x3F;
    }

    private static function skipContinuationBytes(string $str, int $offset, int $length): int
    {
        while ($offset < $length && self::continuationValue($str, $offset, $length) !== null) {
            $offset++;
        }

        return $offset;
    }
}
