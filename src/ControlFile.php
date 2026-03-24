<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

use ZipArchive;
use Bolk\TextFiglet\Exception\ControlFileException;

final class ControlFile
{
    /**
     * @var list<list<array{type: string, from: int, to: int, out_from?: int, out_to?: int}>>
     */
    private array $stages = [];

    private Encoding $encoding = Encoding::Default;

    /** @var array{0: int, 1: int, 2: int, 3: int} */
    private array $graphicSetPrefixes = [0, 0x80, 0, 0];

    /** @var array{0: bool, 1: bool, 2: bool, 3: bool} */
    private array $graphicSetDbcs = [false, false, false, false];

    private int $activeLeftSet = 0;
    private int $activeRightSet = 1;


    public static function load(string $filename): self
    {
        $filename = self::resolveFilename($filename);
        if (!file_exists($filename)) {
            throw new ControlFileException(
                'Control file "' . $filename . '" cannot be found'
            );
        }

        $lines = self::readLines($filename);

        $controlFile = new self();
        $controlFile->parse($lines);
        return $controlFile;
    }

    public static function fromString(string $content): self
    {
        $controlFile = new self();
        $controlFile->parse(explode("\n", $content));
        return $controlFile;
    }

    /** @param list<string> $lines */
    private function parse(array $lines): void
    {
        $currentStage = [];
        $first = true;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if ($first && str_starts_with($line, 'flc2a')) {
                $first = false;
                continue;
            }
            $first = false;

            switch ($line[0]) {
                case 't':
                    $currentStage[] = $this->parseTCommand(substr($line, 1));
                    break;
                case 'f':
                    $this->stages[] = $currentStage;
                    $currentStage = [];
                    break;
                case 'h':
                    $this->encoding = Encoding::Hz;
                    break;
                case 'j':
                    $this->encoding = Encoding::ShiftJis;
                    break;
                case 'b':
                    $this->encoding = Encoding::Dbcs;
                    break;
                case 'u':
                    $this->encoding = Encoding::Utf8;
                    break;
                case 'g':
                    $this->parseGCommand(substr($line, 1));
                    break;
                default:
                    if (preg_match('/^\s*(-?(?:0[xX][0-9a-fA-F]+|0[0-7]+|\d+))\s+(-?(?:0[xX][0-9a-fA-F]+|0[0-7]+|\d+))/', $line, $matches)) {
                        $currentStage[] = [
                            'type' => 'single',
                            'from' => $this->parseNumericValue($matches[1]),
                            'to' => $this->parseNumericValue($matches[2]),
                        ];
                    }
            }
        }

        $this->stages[] = $currentStage;
    }

    /** @return array{type: string, from: int, to: int, out_from?: int, out_to?: int} */
    private function parseTCommand(string $args): array
    {
        $tokens = $this->tokenizeTArgs(ltrim($args));
        if (count($tokens) > 2) {
            $tokens = array_slice($tokens, 0, 2);
        }

        if (count($tokens) === 2) {
            if (str_contains($tokens[0], '-') && str_contains($tokens[1], '-')) {
                $inParts = $this->parseRange($tokens[0]);
                $outParts = $this->parseRange($tokens[1]);
                if ($inParts !== null && $outParts !== null) {
                    return [
                        'type' => 'range',
                        'from' => $inParts[0],
                        'to' => $inParts[1],
                        'out_from' => $outParts[0],
                        'out_to' => $outParts[1],
                    ];
                }
            }
            return [
                'type' => 'single',
                'from' => $this->parseCharValue($tokens[0]),
                'to' => $this->parseCharValue($tokens[1]),
            ];
        }

        throw new ControlFileException('Invalid t command: t' . $args);
    }

    /** @return list<string> */
    private function tokenizeTArgs(string $args): array
    {
        $tokens = [];
        $current = '';
        $len = strlen($args);
        $pos = 0;

        while ($pos < $len && ($args[$pos] === ' ' || $args[$pos] === "\t")) {
            $pos++;
        }

        while ($pos < $len) {
            if ($args[$pos] === '\\' && $pos + 1 < $len) {
                $current .= $args[$pos] . $args[$pos + 1];
                $pos += 2;
            } elseif ($args[$pos] === ' ' || $args[$pos] === "\t") {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                while ($pos < $len && ($args[$pos] === ' ' || $args[$pos] === "\t")) {
                    $pos++;
                }
            } else {
                $current .= $args[$pos];
                $pos++;
            }
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /** @return array{0: int, 1: int}|null */
    private function parseRange(string $token): ?array
    {
        if (preg_match('/^(.+?)-(.+)$/', $token, $matches)) {
            return [$this->parseCharValue($matches[1]), $this->parseCharValue($matches[2])];
        }
        return null;
    }

    private function parseCharValue(string $token): int
    {
        if (strlen($token) === 1) {
            return ord($token);
        }

        if ($token[0] === '\\') {
            return $this->parseBackslashEscape(substr($token, 1));
        }

        if (is_numeric($token)) {
            return (int) $token;
        }

        return ord($token[0]);
    }

    private function parseBackslashEscape(string $value): int
    {
        if ($value === '') {
            return ord('\\');
        }

        return match ($value) {
            'a' => 7, 'b' => 8, 'e' => 27, 'f' => 12,
            'n' => 10, 'r' => 13, 't' => 9, 'v' => 11,
            '\\' => 92, ' ' => 32,
            default => $this->parseNumericEscape($value),
        };
    }

    private function parseNumericEscape(string $value): int
    {
        if (str_starts_with($value, '0x') || str_starts_with($value, '0X')) {
            return (int) hexdec(substr($value, 2));
        }
        if ($value[0] === '0') {
            return (int) octdec($value);
        }
        return (int) $value;
    }

    private function parseGCommand(string $args): void
    {
        $this->encoding = Encoding::Iso2022;
        $args = ltrim($args);

        if ($args === '') {
            return;
        }

        $subcommand = $args[0];
        $rest = ltrim(substr($args, 1));

        if ($this->applyActiveGraphicSetCommand($subcommand, $rest)) {
            return;
        }

        $slot = $this->parseGraphicSetSlotIndex($subcommand);
        if ($slot === null) {
            return;
        }

        $slotConfig = $this->parseGraphicSetSlotConfig($rest);
        if ($slotConfig === null) {
            return;
        }

        $this->setGraphicSetSlot($slot, $slotConfig['prefix'], $slotConfig['double_byte']);
    }

    private function applyActiveGraphicSetCommand(string $subcommand, string $rest): bool
    {
        $slot = $this->parseGraphicSetSlotSelector($rest);

        return match (strtoupper($subcommand)) {
            'L' => $this->assignActiveGraphicSet(true, $slot),
            'R' => $this->assignActiveGraphicSet(false, $slot),
            default => false,
        };
    }

    private function assignActiveGraphicSet(bool $leftSide, ?int $slot): bool
    {
        if ($slot === null) {
            return true;
        }

        if ($leftSide) {
            $this->activeLeftSet = $slot;
            return true;
        }

        $this->activeRightSet = $slot;
        return true;
    }

    private function parseGraphicSetSlotSelector(string $rest): ?int
    {
        if ($rest === '' || $rest[0] < '0' || $rest[0] > '3') {
            return null;
        }

        return (int) $rest[0];
    }

    private function parseGraphicSetSlotIndex(string $subcommand): ?int
    {
        if ($subcommand < '0' || $subcommand > '3') {
            return null;
        }

        return (int) $subcommand;
    }

    /** @return array{prefix: int, double_byte: bool}|null */
    private function parseGraphicSetSlotConfig(string $rest): ?array
    {
        if (!str_starts_with($rest, '9')) {
            return null;
        }

        return match ($rest[1] ?? '') {
            '6' => [
                'prefix' => ($this->parseCharsetDesignator(ltrim(substr($rest, 2))) << 16) | 0x80,
                'double_byte' => false,
            ],
            '4' => $this->parse94GraphicSetSlotConfig(substr($rest, 2)),
            default => null,
        };
    }

    /** @return array{prefix: int, double_byte: bool} */
    private function parse94GraphicSetSlotConfig(string $rest): array
    {
        if (str_starts_with($rest, 'x94')) {
            $designator = $this->parseCharsetDesignator(ltrim(substr($rest, 3)));

            return [
                'prefix' => $designator << 16,
                'double_byte' => true,
            ];
        }

        $designator = $this->parseCharsetDesignator(ltrim($rest));

        return [
            'prefix' => $designator << 16,
            'double_byte' => false,
        ];
    }

    private function setGraphicSetSlot(int $slot, int $prefix, bool $doubleByte): void
    {
        $this->graphicSetPrefixes = $this->withGraphicSetPrefix($this->graphicSetPrefixes, $slot, $prefix);
        $this->graphicSetDbcs = $this->withGraphicSetDoubleByte(
            $this->graphicSetDbcs,
            $slot,
            $doubleByte,
        );
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $graphicSetPrefixes
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function withGraphicSetPrefix(array $graphicSetPrefixes, int $slot, int $prefix): array
    {
        return match ($slot) {
            0 => [$prefix, $graphicSetPrefixes[1], $graphicSetPrefixes[2], $graphicSetPrefixes[3]],
            1 => [$graphicSetPrefixes[0], $prefix, $graphicSetPrefixes[2], $graphicSetPrefixes[3]],
            2 => [$graphicSetPrefixes[0], $graphicSetPrefixes[1], $prefix, $graphicSetPrefixes[3]],
            default => [$graphicSetPrefixes[0], $graphicSetPrefixes[1], $graphicSetPrefixes[2], $prefix],
        };
    }

    /**
     * @param array{0: bool, 1: bool, 2: bool, 3: bool} $graphicSetDbcs
     * @return array{0: bool, 1: bool, 2: bool, 3: bool}
     */
    private function withGraphicSetDoubleByte(array $graphicSetDbcs, int $slot, bool $doubleByte): array
    {
        return match ($slot) {
            0 => [$doubleByte, $graphicSetDbcs[1], $graphicSetDbcs[2], $graphicSetDbcs[3]],
            1 => [$graphicSetDbcs[0], $doubleByte, $graphicSetDbcs[2], $graphicSetDbcs[3]],
            2 => [$graphicSetDbcs[0], $graphicSetDbcs[1], $doubleByte, $graphicSetDbcs[3]],
            default => [$graphicSetDbcs[0], $graphicSetDbcs[1], $graphicSetDbcs[2], $doubleByte],
        };
    }

    private function parseNumericValue(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;

        if (str_starts_with($unsigned, '0x') || str_starts_with($unsigned, '0X')) {
            $result = (int) hexdec(substr($unsigned, 2));
            return $negative ? -$result : $result;
        }
        if (preg_match('/^0[0-7]+$/', $unsigned)) {
            $result = (int) octdec($unsigned);
            return $negative ? -$result : $result;
        }
        $result = (int) $unsigned;
        return $negative ? -$result : $result;
    }

    private function parseCharsetDesignator(string $rest): int
    {
        $rest = ltrim($rest);
        if ($rest === '') {
            return 0;
        }
        return ord($rest[0]);
    }

    public function apply(string $input): string
    {
        $codes = $this->decodeInput($input);

        foreach ($this->stages as $stage) {
            if ($stage !== []) {
                $codes = $this->applyStage($stage, $codes);
            }
        }

        return $this->encodeCodes($codes);
    }

    /** @return list<int> */
    private function decodeInput(string $input): array
    {
        return match ($this->encoding) {
            Encoding::Hz => $this->decodeHZ($input),
            Encoding::ShiftJis => $this->decodeShiftJIS($input),
            Encoding::Dbcs => $this->decodeDBCS($input),
            Encoding::Iso2022 => $this->decodeISO2022($input),
            default => $this->decodeUTF8($input),
        };
    }

    /** @return list<int> */
    private function decodeUTF8(string $str): array
    {
        return Utf8Decoder::decode($str);
    }

    /** @return list<int> */
    private function decodeHZ(string $str): array
    {
        $codes = [];
        $len = strlen($str);
        $doubleByteMode = false;

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($str[$i]);

            if ($byte === 0x7E && $i + 1 < $len) {
                $next = $str[$i + 1];
                if ($next === '{') { $doubleByteMode = true; $i++; continue; }
                if ($next === '}') { $doubleByteMode = false; $i++; continue; }
                if ($next === '~') { $codes[] = 0x7E; $i++; continue; }
                $i++;
                continue;
            }

            $codes[] = $doubleByteMode && $i + 1 < $len ? $byte * 256 + ord($str[++$i]) : $byte;
        }

        return $codes;
    }

    /** @return list<int> */
    private function decodeShiftJIS(string $str): array
    {
        $codes = [];
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($str[$i]);
            if (($byte >= 128 && $byte <= 159) || ($byte >= 224 && $byte <= 239)) {
                $codes[] = ($i + 1 < $len) ? $byte * 256 + ord($str[++$i]) : $byte;
            } else {
                $codes[] = $byte;
            }
        }

        return $codes;
    }

    /** @return list<int> */
    private function decodeDBCS(string $str): array
    {
        $codes = [];
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($str[$i]);
            if ($byte >= 128) {
                $codes[] = ($i + 1 < $len) ? $byte * 256 + ord($str[++$i]) : $byte;
            } else {
                $codes[] = $byte;
            }
        }

        return $codes;
    }

    /** @return list<int> */
    private function decodeISO2022(string $str): array
    {
        $codes = [];
        $length = strlen($str);
        $position = 0;
        $activeLeftSlot = $this->activeLeftSet;
        $activeRightSlot = $this->activeRightSet;
        $graphicSetPrefixes = $this->graphicSetPrefixes;
        $graphicSetDbcs = $this->graphicSetDbcs;
        $singleShiftSlots = null;

        while ($position < $length) {
            $token = $this->readIso2022Token($str, $position, $length);

            if ($this->applyIso2022ShiftToken($token, $activeLeftSlot, $activeRightSlot, $singleShiftSlots)) {
                continue;
            }

            $designationResult = $this->applyIso2022DesignationToken(
                $token,
                $str,
                $position,
                $length,
                $graphicSetPrefixes,
                $graphicSetDbcs,
            );
            if ($designationResult !== null) {
                $graphicSetPrefixes = $designationResult['prefixes'];
                $graphicSetDbcs = $designationResult['double_byte'];
                continue;
            }

            $currentLeftSlot = $activeLeftSlot;
            $currentRightSlot = $activeRightSlot;
            if ($singleShiftSlots !== null) {
                $activeLeftSlot = $singleShiftSlots['left'];
                $activeRightSlot = $singleShiftSlots['right'];
                $singleShiftSlots = null;
            }

            $codes[] = $this->decodeIso2022CodePoint(
                $token,
                $str,
                $position,
                $length,
                $currentLeftSlot,
                $currentRightSlot,
                $graphicSetPrefixes,
                $graphicSetDbcs,
            );
        }

        return $codes;
    }

    private function readIso2022Token(string $input, int &$position, int $length): int
    {
        $token = ord($input[$position++]);

        if ($token !== 27 || $position >= $length) {
            return $token;
        }

        $token = ord($input[$position++]) + 0x100;
        if ($token === 0x124 && $position < $length) {
            return ord($input[$position++]) + 0x200;
        }

        return $token;
    }

    /**
     * @param array{left: int, right: int}|null $singleShiftSlots
     */
    private function applyIso2022ShiftToken(
        int $token,
        int &$activeLeftSlot,
        int &$activeRightSlot,
        ?array &$singleShiftSlots,
    ): bool {
        if ($token === 14) {
            $activeLeftSlot = 1;
            return true;
        }

        if ($token === 15) {
            $activeLeftSlot = 0;
            return true;
        }

        if ($token === 142 || $token === 0x14E) {
            $singleShiftSlots = $this->rememberSingleShiftSlots($singleShiftSlots, $activeLeftSlot, $activeRightSlot);
            $activeLeftSlot = 2;
            $activeRightSlot = 2;
            return true;
        }

        if ($token === 143 || $token === 0x14F) {
            $singleShiftSlots = $this->rememberSingleShiftSlots($singleShiftSlots, $activeLeftSlot, $activeRightSlot);
            $activeLeftSlot = 3;
            $activeRightSlot = 3;
            return true;
        }

        return match ($token) {
            0x16E => $this->setIso2022ShiftSlot($activeLeftSlot, 2),
            0x16F => $this->setIso2022ShiftSlot($activeLeftSlot, 3),
            0x17E => $this->setIso2022ShiftSlot($activeRightSlot, 1),
            0x17D => $this->setIso2022ShiftSlot($activeRightSlot, 2),
            0x17C => $this->setIso2022ShiftSlot($activeRightSlot, 3),
            default => false,
        };
    }

    private function setIso2022ShiftSlot(int &$slot, int $value): bool
    {
        $slot = $value;
        return true;
    }

    /**
     * @param array{left: int, right: int}|null $singleShiftSlots
     * @return array{left: int, right: int}
     */
    private function rememberSingleShiftSlots(?array $singleShiftSlots, int $activeLeftSlot, int $activeRightSlot): array
    {
        if ($singleShiftSlots !== null) {
            return $singleShiftSlots;
        }

        return [
            'left' => $activeLeftSlot,
            'right' => $activeRightSlot,
        ];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $graphicSetPrefixes
     * @param array{0: bool, 1: bool, 2: bool, 3: bool} $graphicSetDbcs
     * @return array{
     *     prefixes: array{0: int, 1: int, 2: int, 3: int},
     *     double_byte: array{0: bool, 1: bool, 2: bool, 3: bool}
     * }|null
     */
    private function applyIso2022DesignationToken(
        int $token,
        string $input,
        int &$position,
        int $length,
        array $graphicSetPrefixes,
        array $graphicSetDbcs,
    ): ?array {
        if ($token >= 0x128 && $token <= 0x12B) {
            $slot = $token - 0x128;
            $designator = $this->readIso2022Designator($input, $position, $length);
            return $this->updatedIso2022GraphicSet(
                $graphicSetPrefixes,
                $graphicSetDbcs,
                $slot,
                ($designator === 66 ? 0 : $designator) << 16,
                false,
            );
        }

        if ($token >= 0x12D && $token <= 0x12F) {
            $slot = $token - 0x12C;
            $designator = $this->readIso2022Designator($input, $position, $length);
            return $this->updatedIso2022GraphicSet(
                $graphicSetPrefixes,
                $graphicSetDbcs,
                $slot,
                (($designator === 65 ? 0 : $designator) << 16) | 0x80,
                false,
            );
        }

        if ($token >= 0x228 && $token <= 0x22B) {
            $slot = $token - 0x228;
            $designator = $this->readIso2022Designator($input, $position, $length);
            return $this->updatedIso2022GraphicSet(
                $graphicSetPrefixes,
                $graphicSetDbcs,
                $slot,
                $designator << 16,
                true,
            );
        }

        if ($token >= 0x200) {
            return $this->updatedIso2022GraphicSet(
                $graphicSetPrefixes,
                $graphicSetDbcs,
                0,
                ($token - 0x200) << 16,
                true,
            );
        }

        return null;
    }

    private function readIso2022Designator(string $input, int &$position, int $length): int
    {
        if ($position >= $length) {
            return 0;
        }

        return ord($input[$position++]);
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $graphicSetPrefixes
     * @param array{0: bool, 1: bool, 2: bool, 3: bool} $graphicSetDbcs
     * @return array{
     *     prefixes: array{0: int, 1: int, 2: int, 3: int},
     *     double_byte: array{0: bool, 1: bool, 2: bool, 3: bool}
     * }
     */
    private function updatedIso2022GraphicSet(
        array $graphicSetPrefixes,
        array $graphicSetDbcs,
        int $slot,
        int $prefix,
        bool $doubleByte,
    ): array {
        return [
            'prefixes' => $this->withGraphicSetPrefix($graphicSetPrefixes, $slot, $prefix),
            'double_byte' => $this->withGraphicSetDoubleByte($graphicSetDbcs, $slot, $doubleByte),
        ];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $graphicSetPrefixes
     * @param array{0: bool, 1: bool, 2: bool, 3: bool} $graphicSetDbcs
     */
    private function decodeIso2022CodePoint(
        int $token,
        string $input,
        int &$position,
        int $length,
        int $activeLeftSlot,
        int $activeRightSlot,
        array $graphicSetPrefixes,
        array $graphicSetDbcs,
    ): int {
        if ($token >= 0x21 && $token <= 0x7E) {
            return $this->decodeIso2022GraphicCode(
                $token,
                $input,
                $position,
                $length,
                $activeLeftSlot,
                $graphicSetPrefixes,
                $graphicSetDbcs,
                true,
            );
        }

        if ($token >= 0xA0) {
            return $this->decodeIso2022GraphicCode(
                $token,
                $input,
                $position,
                $length,
                $activeRightSlot,
                $graphicSetPrefixes,
                $graphicSetDbcs,
                false,
            );
        }

        return $token;
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $graphicSetPrefixes
     * @param array{0: bool, 1: bool, 2: bool, 3: bool} $graphicSetDbcs
     */
    private function decodeIso2022GraphicCode(
        int $token,
        string $input,
        int &$position,
        int $length,
        int $slot,
        array $graphicSetPrefixes,
        array $graphicSetDbcs,
        bool $isLeftSide,
    ): int {
        $prefix = $graphicSetPrefixes[$slot];

        if ($graphicSetDbcs[$slot]) {
            return $prefix | ($token << 8) | $this->readIso2022Designator($input, $position, $length);
        }

        return $prefix | ($isLeftSide ? $token : ($token & 0x7F));
    }

    /**
     * @param list<array{type: string, from: int, to: int, out_from?: int, out_to?: int}> $stage
     * @param list<int> $codes
     * @return list<int>
     */
    private function applyStage(array $stage, array $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $mapped = false;
            foreach ($stage as $rule) {
                if ($rule['type'] === 'single' && $code === $rule['from']) {
                    $result[] = $rule['to'];
                    $mapped = true;
                    break;
                }
                if ($rule['type'] === 'range' && $code >= $rule['from'] && $code <= $rule['to']) {
                    $result[] = ($rule['out_from'] ?? 0) + $code - $rule['from'];
                    $mapped = true;
                    break;
                }
            }
            if (!$mapped) {
                $result[] = $code;
            }
        }
        return $result;
    }

    /** @param list<int> $codes */
    private function encodeCodes(array $codes): string
    {
        $result = '';
        foreach ($codes as $code) {
            if ($code < 0x80) {
                $result .= chr($code & 0x7F);
            } elseif ($code < 0x800) {
                $result .= chr((0xC0 | ($code >> 6)) & 0xFF)
                         . chr(0x80 | ($code & 0x3F));
            } elseif ($code < 0x10000) {
                $result .= chr((0xE0 | ($code >> 12)) & 0xFF)
                         . chr(0x80 | (($code >> 6) & 0x3F))
                         . chr(0x80 | ($code & 0x3F));
            } else {
                $result .= chr((0xF0 | ($code >> 18)) & 0xFF)
                         . chr(0x80 | (($code >> 12) & 0x3F))
                         . chr(0x80 | (($code >> 6) & 0x3F))
                         . chr(0x80 | ($code & 0x3F));
            }
        }
        return $result;
    }

    public function getEncoding(): Encoding
    {
        return $this->encoding;
    }

    /** @return list<list<array{type: string, from: int, to: int, out_from?: int, out_to?: int}>> */
    public function getStages(): array
    {
        return $this->stages;
    }

    private static function resolveFilename(string $filename): string
    {
        if (file_exists($filename)) {
            return $filename;
        }

        $fontDir = __DIR__ . '/../fonts/';

        if (!str_contains($filename, '/') && file_exists($fontDir . $filename)) {
            return $fontDir . $filename;
        }

        if (!str_contains($filename, '.') && file_exists($filename . '.flc')) {
            return $filename . '.flc';
        }

        if (!str_contains($filename, '/') && !str_contains($filename, '.')
            && file_exists($fontDir . $filename . '.flc')) {
            return $fontDir . $filename . '.flc';
        }

        return $filename;
    }

    /** @return list<string> */
    private static function readLines(string $filename): array
    {
        $stream = self::openStream($filename);

        try {
            $lines = [];
            while (($line = fgets($stream)) !== false) {
                $lines[] = rtrim($line, "\r\n");
            }
        } finally {
            fclose($stream);
        }

        return $lines;
    }

    /** @return resource */
    private static function openStream(string $filename)
    {
        $stream = fopen($filename, 'rb');
        if ($stream === false) {
            throw new ControlFileException('Cannot read control file ' . $filename);
        }

        $magic = fread($stream, 2);
        if ($magic === "\x1f\x8b") {
            fclose($stream);
            if (!extension_loaded('zlib')) {
                throw new ControlFileException(
                    'Cannot load gzip compressed control files: zlib extension is not available'
                );
            }

            $stream = fopen('compress.zlib://' . $filename, 'rb');
            if ($stream === false) {
                throw new ControlFileException('Cannot read control file ' . $filename);
            }

            return $stream;
        }

        if ($magic !== 'PK') {
            rewind($stream);
            return $stream;
        }

        fclose($stream);

        return self::openZipStream($filename);
    }

    /** @return resource */
    private static function openZipStream(string $filename)
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ControlFileException(
                'Cannot load ZIP compressed control files: ZIP extension is not available'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            throw new ControlFileException('Cannot read control file ' . $filename);
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        $name = ZipMember::selectName($zip, [$base . '.flc']);
        $zip->close();

        if ($name === null) {
            throw new ControlFileException('ZIP archive is empty: ' . $filename);
        }

        $resolved = realpath($filename);
        $realPath = $resolved !== false ? $resolved : $filename;
        $stream = fopen('zip://' . $realPath . '#' . $name, 'rb');
        if ($stream === false) {
            throw new ControlFileException('Cannot read control file ' . $filename);
        }

        return $stream;
    }
}
