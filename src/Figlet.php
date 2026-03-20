<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

use FFI;
use FFI\Exception as FFIException;
use ZipArchive;
use Bolk\TextFiglet\Exception\FontLoadException;
use Bolk\TextFiglet\Exception\FontNotFoundException;

final class Figlet
{
    public const string VERSION = '2.6.1';

    protected int $height = 0;
    protected int $baseline = 0;
    protected int $oldLayout = 0;
    protected int $fullLayout = -1;
    protected int $codetagCount = 0;
    protected int $printDirection = 0;
    protected string $hardblank = '';
    protected bool $isTlf = false;

    /** @var array<int, list<string>> */
    protected array $font = [];
    /** @var array<int, int> */
    protected array $fontCharWidths = [];

    public string $fontComment = '';

    protected int $hSmushRules = 0;
    protected int $vSmushRules = 0;
    protected LayoutMode $hLayout = LayoutMode::FullSize;
    protected LayoutMode $vLayout = LayoutMode::FullSize;

    private ?LayoutMode $hLayoutOverride = null;
    private ?LayoutMode $vLayoutOverride = null;

    private ?int $outputWidth = null;
    private bool $paragraphMode = false;
    private Justification $justification = Justification::Auto;

    /** @var list<ControlFile> */
    private array $controlFiles = [];

    /** @var list<Filter> */
    private array $filters = [];

    private string $fontName = '';

    private const HIERARCHY_CLASSES = [
        '|' => 1, '/' => 2, '\\' => 2, '[' => 3, ']' => 3,
        '{' => 4, '}' => 4, '(' => 5, ')' => 5, '<' => 6, '>' => 6,
    ];

    private const OPPOSITE_PAIRS = [
        '[]' => '|', '][' => '|', '{}' => '|', '}{' => '|', '()' => '|', ')(' => '|',
    ];

    private const BIG_X_MAP = [
        '/\\' => '|', '\\/' => 'Y', '><' => 'X',
    ];

    private function charLength(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    private function charAt(string $text, int $position): string
    {
        return mb_substr($text, $position, 1, 'UTF-8');
    }

    private function charSlice(string $text, int $start, ?int $length = null): string
    {
        return mb_substr($text, $start, $length, 'UTF-8');
    }

    private function replaceCharAt(string $text, int $position, string $char): string
    {
        return mb_substr($text, 0, $position, 'UTF-8') . $char . mb_substr($text, $position + 1, null, 'UTF-8');
    }

    private function latin1ToUtf8(string $input): string
    {
        /** @var string */
        return mb_convert_encoding($input, 'UTF-8', 'ISO-8859-1');
    }

    public function setHorizontalLayout(LayoutMode $mode): self
    {
        $this->hLayoutOverride = $mode;
        return $this;
    }

    public function setVerticalLayout(LayoutMode $mode): self
    {
        $this->vLayoutOverride = $mode;
        return $this;
    }

    public function getHorizontalLayout(): LayoutMode
    {
        return $this->hLayoutOverride ?? $this->hLayout;
    }

    public function getVerticalLayout(): LayoutMode
    {
        return $this->vLayoutOverride ?? $this->vLayout;
    }

    public function setWidth(int $width): self
    {
        $this->outputWidth = $width > 0 ? $width : null;
        return $this;
    }

    public function setParagraphMode(bool $enabled): self
    {
        $this->paragraphMode = $enabled;
        return $this;
    }

    public function setJustification(Justification $justification): self
    {
        $this->justification = $justification;
        return $this;
    }

    public function loadControlFile(string $filename): self
    {
        $this->controlFiles[] = ControlFile::load($filename);
        return $this;
    }

    public function clearControlFiles(): self
    {
        $this->controlFiles = [];
        return $this;
    }

    public function addFilter(Filter $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    public function clearFilters(): self
    {
        $this->filters = [];
        return $this;
    }

    public static function terminalWidth(): int
    {
        $columns = getenv('COLUMNS');
        if ($columns !== false && is_numeric($columns) && (int) $columns > 0) {
            return (int) $columns;
        }

        $width = self::terminalWidthViaIoctl();
        if ($width > 0) {
            return $width;
        }

        if (function_exists('exec')) {
            $result = exec('tput cols 2>/dev/null');
            if ($result !== false && is_numeric($result) && (int) $result > 0) {
                return (int) $result;
            }

            $result = exec('stty size 2>/dev/null');
            if ($result !== false && preg_match('/\d+ (\d+)/', $result, $match) && (int) $match[1] > 0) {
                return (int) $match[1];
            }
        }

        return 80;
    }

    private static function terminalWidthViaIoctl(): int
    {
        if (!extension_loaded('ffi') || PHP_OS_FAMILY === 'Windows') {
            return 0;
        }

        try {
            $ffi = FFI::cdef(<<<'CDEF'
                struct winsize {
                    unsigned short ws_row;
                    unsigned short ws_col;
                    unsigned short ws_xpixel;
                    unsigned short ws_ypixel;
                };
                int ioctl(int fd, unsigned long request, ...);
                CDEF);

            $win = $ffi->new('struct winsize');
            $tiocgwinsz = PHP_OS_FAMILY === 'Linux' ? 0x5413 : 0x40087468;

            // Probe terminal width via standard POSIX fds: stdout (1), stderr (2), stdin (0).
            foreach ([1, 2, 0] as $fd) {
                /** @phpstan-ignore method.notFound, property.notFound */
                if ($ffi->ioctl($fd, $tiocgwinsz, FFI::addr($win)) !== -1 && $win->ws_col > 0) {
                    /** @phpstan-ignore return.type */
                    return $win->ws_col;
                }
            }
        } catch (FFIException) {
            return 0;
        }

        return 0;
    }

    public function getFontName(): string
    {
        return $this->fontName;
    }

    public function getInfoCode(int $code): string
    {
        return match ($code) {
            0 => self::VERSION,
            1 => $this->versionInt(),
            2 => dirname(__DIR__) . '/fonts',
            3 => $this->fontName,
            4 => (string) ($this->outputWidth ?? 80),
            default => '',
        };
    }

    private function versionInt(): string
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', self::VERSION, $match) !== 1) {
            return preg_replace('/\D+/', '', self::VERSION) ?? '';
        }

        return sprintf('%d%02d%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getBaseline(): int
    {
        return $this->baseline;
    }

    public function getFullLayout(): int
    {
        return $this->fullLayout;
    }

    public function getOldLayout(): int
    {
        return $this->oldLayout;
    }

    public function getCodetagCount(): int
    {
        return $this->codetagCount;
    }

    public function getPrintDirection(): int
    {
        return $this->printDirection;
    }

    public function loadFont(string $filename, bool $loadGerman = true): void
    {
        $this->font = [];
        $this->fontCharWidths = [];
        $this->fontName = pathinfo($filename, PATHINFO_FILENAME);

        if (!file_exists($filename)) {
            $fontDir = __DIR__ . '/../fonts/';

            if (!str_contains($filename, '/') && file_exists($fontDir . $filename)) {
                $filename = $fontDir . $filename;
            } elseif (!str_contains($filename, '.') && file_exists($filename . '.flf')) {
                $filename .= '.flf';
            } elseif (!str_contains($filename, '.') && file_exists($filename . '.tlf')) {
                $filename .= '.tlf';
            } elseif (!str_contains($filename, '/') && !str_contains($filename, '.')) {
                if (file_exists($fontDir . $filename . '.flf')) {
                    $filename = $fontDir . $filename . '.flf';
                } elseif (file_exists($fontDir . $filename . '.tlf')) {
                    $filename = $fontDir . $filename . '.tlf';
                } else {
                    throw new FontNotFoundException(
                        'Figlet font file "' . $filename . '" cannot be found'
                    );
                }
            } else {
                throw new FontNotFoundException(
                    'Figlet font file "' . $filename . '" cannot be found'
                );
            }
        }

        $this->fontComment = '';
        $compressed = false;

        if (str_ends_with($filename, '.gz')) {
            if (!extension_loaded('zlib')) {
                throw new FontLoadException(
                    'Cannot load gzip compressed fonts: zlib extension is not available'
                );
            }
            $filename = 'compress.zlib://' . $filename;
            $compressed = true;
        }

        $stream = fopen($filename, 'rb');
        if ($stream === false) {
            throw new FontLoadException('Cannot open figlet font file ' . $filename);
        }

        if (!$compressed) {
            $magic = fread($stream, 2);
            if ($magic === 'PK') {
                fclose($stream);
                $stream = $this->openZipFont($filename);
            } else {
                flock($stream, LOCK_SH);
                rewind($stream);
            }
        }

        try {
            $this->parseFont($stream, $loadGerman);
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    private function openZipFont(string $filename)
    {
        if (!class_exists(ZipArchive::class)) {
            throw new FontLoadException(
                'Cannot load ZIP compressed fonts: ZIP extension is not available'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            throw new FontLoadException('Cannot open figlet font file ' . $filename);
        }

        $name = $zip->getNameIndex(0);
        $zip->close();

        if ($name === false) {
            throw new FontLoadException('ZIP archive is empty: ' . $filename);
        }

        $resolved = realpath($filename);
        $realPath = $resolved !== false ? $resolved : $filename;
        $stream = fopen('zip://' . $realPath . '#' . $name, 'rb');
        if ($stream === false) {
            throw new FontLoadException('Cannot open figlet font file ' . $filename);
        }

        return $stream;
    }

    /** @param resource $stream */
    private function parseFont(mixed $stream, bool $loadGerman): void
    {
        $this->parseFontHeader($stream);

        for ($i = 32; $i < 127; $i++) {
            $this->loadCharFromFile($stream, $i);
        }

        $this->loadGermanChars($stream, $loadGerman);
        $this->loadExtendedChars($stream);
    }

    /** @param resource $stream */
    private function parseFontHeader(mixed $stream): void
    {
        $headerLine = fgets($stream, 2048);
        if ($headerLine === false) {
            throw new FontLoadException('Cannot read font header');
        }

        $header = explode(' ', rtrim($headerLine));

        if (str_starts_with($header[0], 'tlf2a')) {
            $this->isTlf = true;
        } elseif (str_starts_with($header[0], 'flf2a')) {
            $this->isTlf = false;
        } else {
            throw new FontLoadException('Unknown FIGlet font format');
        }

        $this->hardblank = $header[0][-1];
        $this->height = (int) ($header[1] ?? 0);
        $this->baseline = (int) ($header[2] ?? 0);
        $this->oldLayout = (int) ($header[4] ?? 0);
        $cmtCount = (int) ($header[5] ?? 0);
        $this->printDirection = (int) ($header[6] ?? 0);
        $this->fullLayout = (int) ($header[7] ?? -1);
        $this->codetagCount = (int) ($header[8] ?? 0);

        $this->deriveLayoutModes();

        for ($i = 0; $i < $cmtCount; $i++) {
            $this->fontComment .= (string) fgets($stream, 2048);
        }
    }

    /** @param resource $stream */
    private function loadGermanChars(mixed $stream, bool $loadGerman): void
    {
        foreach ([196, 214, 220, 228, 246, 252, 223] as $code) {
            if (!$loadGerman) {
                $this->skipChar($stream);
                continue;
            }

            $letter = $this->parseChar($stream);
            if ($letter === false) {
                return;
            }
            if (trim(implode('', $letter)) !== '') {
                $this->font[$code] = $letter;
                $this->fontCharWidths[$code] = $letter !== [] ? $this->charLength($letter[0]) : 0;
            }
        }
    }

    /** @param resource $stream */
    private function loadExtendedChars(mixed $stream): void
    {
        while (!feof($stream)) {
            $raw = fgets($stream, 1024);
            $line = $raw !== false ? rtrim($raw) : '';
            [$code] = explode(' ', $line, 2);
            if ($code === '') {
                continue;
            }

            if (preg_match('/^-0x/i', $code)) {
                $this->skipChar($stream);
            } else {
                $this->loadCharFromFile($stream, $this->parseCharCode($code));
            }
        }
    }

    private function parseCharCode(string $code): int
    {
        if (preg_match('/^0x/i', $code)) {
            return (int) hexdec(substr($code, 2));
        }
        if (($code[0] === '0' && $code !== '0') || str_starts_with($code, '-0')) {
            return (int) octdec($code);
        }
        return (int) $code;
    }

    /** @param resource $stream */
    private function loadCharFromFile(mixed $stream, int $code): void
    {
        $letter = $this->parseChar($stream);
        if ($letter === false) {
            return;
        }
        $this->font[$code] = $letter;
        $this->fontCharWidths[$code] = $letter !== [] ? $this->charLength($letter[0]) : 0;
    }

    private function deriveLayoutModes(): void
    {
        if ($this->fullLayout >= 0) {
            $this->hSmushRules = $this->fullLayout & 63;
            $this->vSmushRules = ($this->fullLayout >> 8) & 31;

            if (($this->fullLayout & 128) !== 0) {
                $this->hLayout = LayoutMode::Smushing;
            } elseif (($this->fullLayout & 64) !== 0) {
                $this->hLayout = LayoutMode::Fitting;
            } else {
                $this->hLayout = LayoutMode::FullSize;
            }

            if (($this->fullLayout & 16384) !== 0) {
                $this->vLayout = LayoutMode::Smushing;
            } elseif (($this->fullLayout & 8192) !== 0) {
                $this->vLayout = LayoutMode::Fitting;
            } else {
                $this->vLayout = LayoutMode::FullSize;
            }
        } else {
            if ($this->oldLayout < 0) {
                $this->hLayout = LayoutMode::FullSize;
                $this->hSmushRules = 0;
            } elseif ($this->oldLayout === 0) {
                $this->hLayout = LayoutMode::Fitting;
                $this->hSmushRules = 0;
            } else {
                $this->hLayout = LayoutMode::Smushing;
                $this->hSmushRules = $this->oldLayout & 63;
            }
            $this->vLayout = LayoutMode::FullSize;
            $this->vSmushRules = 0;
        }
    }

    public function render(string $str, ExportFormat $format = ExportFormat::Text): string
    {
        foreach ($this->controlFiles as $cf) {
            $str = $cf->apply($str);
        }

        if ($this->paragraphMode) {
            $str = $this->applyParagraphMode($str);
        }

        $inputLines = explode("\n", $str);
        $figures = [];

        foreach ($inputLines as $inputLine) {
            foreach ($this->renderLineWithWrapping($inputLine) as $fig) {
                $figures[] = $fig;
            }
        }

        if ($figures === [] || ($str === '' && $this->allEmpty($figures))) {
            return '';
        }

        $effectiveVLayout = $this->vLayoutOverride ?? $this->vLayout;

        $combined = $figures[0];
        $figCount = count($figures);
        for ($i = 1; $i < $figCount; $i++) {
            $combined = $this->combineFiguresVertically($combined, $figures[$i], $effectiveVLayout);
        }

        $combined = $this->applyJustification($combined);

        foreach ($combined as &$row) {
            $row = str_replace($this->hardblank, ' ', $row);
        }
        unset($row);

        $combined = $this->applyFilters($combined);

        return Renderer::export($combined, $format);
    }

    /** @param list<list<string>> $figures */
    private function allEmpty(array $figures): bool
    {
        foreach ($figures as $fig) {
            foreach ($fig as $row) {
                if (trim($row) !== '') {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return list<list<string>> */
    private function renderLineWithWrapping(string $line): array
    {
        $codes = $this->splitString($line);

        if ($codes === []) {
            return [array_fill(0, $this->height, '')];
        }

        if ($this->outputWidth === null) {
            return [$this->renderCodes($codes)];
        }

        $figures = [];
        $current = [];
        $lastSpaceIdx = -1;
        $codeCount = count($codes);

        for ($i = 0; $i < $codeCount; $i++) {
            $current[] = $codes[$i];

            if ($codes[$i] === 32) {
                $lastSpaceIdx = count($current) - 1;
            }

            $rendered = $this->renderCodes($current);
            $width = $this->figureWidth($rendered);

            if ($width > $this->outputWidth - 1 && count($current) > 1) {
                if ($lastSpaceIdx >= 0) {
                    $beforeSpace = array_slice($current, 0, $lastSpaceIdx);
                    $afterSpace = array_slice($current, $lastSpaceIdx + 1);

                    while ($i + 1 < $codeCount && $codes[$i + 1] === 32) {
                        $i++;
                    }

                    if ($beforeSpace !== []) {
                        $figures[] = $this->renderCodes($beforeSpace);
                    }
                    $current = $afterSpace;
                } else {
                    $overflow = array_pop($current);
                    if ($current !== []) {
                        $figures[] = $this->renderCodes($current);
                    }
                    $current = [$overflow];
                }
                $lastSpaceIdx = -1;
            }
        }

        if ($current !== []) {
            $figures[] = $this->renderCodes($current);
        }

        return $figures;
    }

    /** @param list<string> $figure */
    private function figureWidth(array $figure): int
    {
        $max = 0;
        foreach ($figure as $row) {
            $max = max($max, $this->charLength($row));
        }
        return $max;
    }

    /**
     * Render a sequence of character codes into a FIGure.
     * Uses the C-style smushamt/addchar algorithm.
     *
     * @param list<int> $codes
     * @return list<string>
     */
    private function renderCodes(array $codes): array
    {
        $effectiveHLayout = $this->hLayoutOverride ?? $this->hLayout;
        $outLines = [];
        $outWidth = 0;
        $prevCharWidth = 0;

        foreach ($codes as $lt) {
            if (!isset($this->font[$lt])) {
                if (isset($this->font[0])) {
                    $lt = 0;
                } else {
                    continue;
                }
            }

            $charLines = $this->font[$lt];
            $charWidth = $this->fontCharWidths[$lt];

            if ($outLines === []) {
                $outLines = $charLines;
                $outWidth = $charWidth;
                $prevCharWidth = $charWidth;
                continue;
            }

            $smushAmount = $this->calcSmushAmount(
                $outLines, $charLines, $outWidth, $charWidth, $prevCharWidth, $effectiveHLayout,
            );

            $outLines = $this->addCharToOutput(
                $outLines, $charLines, $outWidth, $charWidth, $smushAmount,
            );
            $outWidth = $outLines !== [] ? $this->charLength($outLines[0]) : 0;
            $prevCharWidth = $charWidth;
        }

        if ($outLines === []) {
            return array_fill(0, $this->height, '');
        }

        if ($effectiveHLayout !== LayoutMode::FullSize) {
            $outLines = $this->stripLeadingBlankColumns($outLines);
        }

        return $outLines;
    }

    /**
     * Calculate how many columns the new character can overlap with the output.
     * Direct port of figlet C's smushamt().
     *
     * @param list<string> $outLines
     * @param list<string> $charLines
     */
    private function calcSmushAmount(
        array $outLines,
        array $charLines,
        int $outWidth,
        int $charWidth,
        int $prevCharWidth,
        LayoutMode $mode,
    ): int {
        if ($mode === LayoutMode::FullSize) {
            return 0;
        }

        $maxSmush = $charWidth;

        for ($row = 0; $row < $this->height; $row++) {
            $boundary = $this->measureSmushBoundary(
                $outLines[$row] ?? '',
                $charLines[$row] ?? '',
                $outWidth,
                $charWidth,
            );
            $amount = $boundary['amount'];

            if ($boundary['left_edge'] === ' ') {
                $amount++;
            } elseif ($boundary['right_edge'] !== ' ' && $mode === LayoutMode::Smushing) {
                if ($this->smushem($boundary['left_edge'], $boundary['right_edge'], $prevCharWidth, $charWidth) !== null) {
                    $amount++;
                }
            }

            $maxSmush = min($maxSmush, $amount);
        }

        return $maxSmush;
    }

    /** @return array{left_edge: string, right_edge: string, amount: int} */
    private function measureSmushBoundary(
        string $outputRow,
        string $characterRow,
        int $outputWidth,
        int $characterWidth,
    ): array {
        if ($this->printDirection !== 0) {
            return $this->measureRightToLeftSmushBoundary($outputRow, $characterRow, $characterWidth);
        }

        return $this->measureLeftToRightSmushBoundary($outputRow, $characterRow, $outputWidth, $characterWidth);
    }

    /** @return array{left_edge: string, right_edge: string, amount: int} */
    private function measureRightToLeftSmushBoundary(
        string $outputRow,
        string $characterRow,
        int $characterWidth,
    ): array {
        $trimmedCharWidth = $this->charLength($characterRow);
        while ($trimmedCharWidth > 0 && $this->charAt($characterRow, $trimmedCharWidth - 1) === ' ') {
            $trimmedCharWidth--;
        }
        $leftEdge = $trimmedCharWidth > 0 ? $this->charAt($characterRow, $trimmedCharWidth - 1) : ' ';
        $leadingOutputSpace = 0;
        $outputLength = $this->charLength($outputRow);
        while ($leadingOutputSpace < $outputLength && $this->charAt($outputRow, $leadingOutputSpace) === ' ') {
            $leadingOutputSpace++;
        }
        $rightEdge = $leadingOutputSpace < $outputLength ? $this->charAt($outputRow, $leadingOutputSpace) : ' ';
        return [
            'left_edge' => $leftEdge,
            'right_edge' => $rightEdge,
            'amount' => $leadingOutputSpace + $characterWidth - $trimmedCharWidth,
        ];
    }

    /** @return array{left_edge: string, right_edge: string, amount: int} */
    private function measureLeftToRightSmushBoundary(
        string $outputRow,
        string $characterRow,
        int $outputWidth,
        int $characterWidth,
    ): array {
        $trimmedOutputWidth = $this->charLength($outputRow);
        while ($trimmedOutputWidth > 0 && $this->charAt($outputRow, $trimmedOutputWidth - 1) === ' ') {
            $trimmedOutputWidth--;
        }
        $leftEdge = $trimmedOutputWidth > 0 ? $this->charAt($outputRow, $trimmedOutputWidth - 1) : ' ';
        $leadingCharSpace = 0;
        while ($leadingCharSpace < $characterWidth && $this->charAt($characterRow, $leadingCharSpace) === ' ') {
            $leadingCharSpace++;
        }
        $rightEdge = $leadingCharSpace < $characterWidth ? $this->charAt($characterRow, $leadingCharSpace) : ' ';
        return [
            'left_edge' => $leftEdge,
            'right_edge' => $rightEdge,
            'amount' => $leadingCharSpace + $outputWidth - $trimmedOutputWidth,
        ];
    }

    /**
     * Apply overlap: overwrite smushamount columns at the junction, then concatenate.
     * Direct port of figlet C's addchar() LTR path.
     *
     * @param list<string> $outLines
     * @param list<string> $charLines
     * @return list<string>
     */
    private function addCharToOutput(
        array $outLines,
        array $charLines,
        int $outWidth,
        int $charWidth,
        int $smushAmount,
    ): array {
        $result = [];

        for ($row = 0; $row < $this->height; $row++) {
            $outRow = $outLines[$row] ?? '';
            $charRow = $charLines[$row] ?? '';

            $result[] = $this->printDirection !== 0
                ? $this->smushRowRtl($outRow, $charRow, $outWidth, $charWidth, $smushAmount)
                : $this->smushRowLtr($outRow, $charRow, $outWidth, $charWidth, $smushAmount);
        }

        return $result;
    }

    private function smushRowLtr(string $outRow, string $charRow, int $outWidth, int $charWidth, int $smushAmount): string
    {
        $line = $outRow;
        for ($k = 0; $k < $smushAmount; $k++) {
            $column = max(0, $outWidth - $smushAmount + $k);
            $leftCh = $this->charAt($line, $column);
            $rightCh = $this->charAt($charRow, $k);
            if ($leftCh === '') { $leftCh = ' '; }
            if ($rightCh === '') { $rightCh = ' '; }
            $smushed = $this->smushem($leftCh, $rightCh, $outWidth, $charWidth);
            if ($column < $this->charLength($line)) {
                $line = $this->replaceCharAt($line, $column, $smushed ?? $rightCh);
            }
        }
        return $line . $this->charSlice($charRow, $smushAmount);
    }

    private function smushRowRtl(string $outRow, string $charRow, int $outWidth, int $charWidth, int $smushAmount): string
    {
        $temp = $charRow;
        for ($k = 0; $k < $smushAmount; $k++) {
            $pos = $charWidth - $smushAmount + $k;
            $leftCh = $this->charAt($temp, $pos);
            $rightCh = $this->charAt($outRow, $k);
            if ($leftCh === '') { $leftCh = ' '; }
            if ($rightCh === '') { $rightCh = ' '; }
            $smushed = $this->smushem($leftCh, $rightCh, $charWidth, $outWidth);
            $temp = $this->replaceCharAt($temp, $pos, $smushed ?? $rightCh);
        }
        return $temp . $this->charSlice($outRow, $smushAmount);
    }

    private function smushem(string $left, string $right, int $leftWidth, int $rightWidth): ?string
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
        if (($this->hLayoutOverride ?? $this->hLayout) !== LayoutMode::Smushing) {
            return null;
        }

        $rules = $this->hSmushRules;

        if ($rules === 0) {
            if ($left === $this->hardblank) {
                return $right;
            }
            if ($right === $this->hardblank) {
                return $left;
            }
            return $this->printDirection !== 0 ? $left : $right;
        }

        return $this->applyHSmushRules($left, $right, $rules);
    }

    private function applyHSmushRules(string $left, string $right, int $rules): ?string
    {
        if ($left === $this->hardblank && $right === $this->hardblank) {
            return ($rules & 32) !== 0 ? $left : null;
        }
        if ($left === $this->hardblank || $right === $this->hardblank) {
            return null;
        }

        if (($rules & 1) !== 0 && $left === $right) {
            return $left;
        }

        if (($rules & 2) !== 0) {
            $result = $this->smushUnderscore($left, $right);
            if ($result !== null) {
                return $result;
            }
        }

        if (($rules & 4) !== 0) {
            $result = $this->smushHierarchy($left, $right);
            if ($result !== null) {
                return $result;
            }
        }

        $pair = $left . $right;

        if (($rules & 8) !== 0 && isset(self::OPPOSITE_PAIRS[$pair])) {
            return self::OPPOSITE_PAIRS[$pair];
        }

        if (($rules & 16) !== 0 && isset(self::BIG_X_MAP[$pair])) {
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

    /**
     * Remove leading columns that are blank in ALL rows.
     * Hardblanks are treated as visible (per spec).
     *
     * @param list<string> $figure
     * @return list<string>
     */
    private function stripLeadingBlankColumns(array $figure): array
    {
        $minLeading = PHP_INT_MAX;
        foreach ($figure as $row) {
            $leading = 0;
            $len = $this->charLength($row);
            while ($leading < $len && $this->charAt($row, $leading) === ' ') {
                $leading++;
            }
            $minLeading = min($minLeading, $leading);
            if ($minLeading === 0) {
                return $figure;
            }
        }

        if ($minLeading < PHP_INT_MAX) {
            foreach ($figure as &$row) {
                $row = $this->charSlice($row, $minLeading);
            }
        }

        return $figure;
    }

    /**
     * @param list<string> $top
     * @param list<string> $bottom
     * @return list<string>
     */
    private function combineFiguresVertically(array $top, array $bottom, LayoutMode $vMode): array
    {
        if ($vMode === LayoutMode::FullSize) {
            return array_merge($top, $bottom);
        }

        $maxWidth = 0;
        foreach (array_merge($top, $bottom) as $row) {
            $maxWidth = max($maxWidth, $this->charLength($row));
        }
        foreach ($top as &$row) {
            $pad = $maxWidth - $this->charLength($row);
            if ($pad > 0) {
                $row .= str_repeat(' ', $pad);
            }
        }
        unset($row);
        foreach ($bottom as &$row) {
            $pad = $maxWidth - $this->charLength($row);
            if ($pad > 0) {
                $row .= str_repeat(' ', $pad);
            }
        }
        unset($row);

        $overlap = $this->calcVerticalOverlap($top, $bottom, $maxWidth, $vMode);

        return $this->buildVerticalMerge($top, $bottom, $overlap, $maxWidth, $vMode);
    }

    /**
     * @param list<string> $top
     * @param list<string> $bottom
     */
    private function calcVerticalOverlap(array $top, array $bottom, int $maxWidth, LayoutMode $vMode): int
    {
        $topHeight = count($top);
        $maxOverlap = min($topHeight, count($bottom));
        $fittingOverlap = 0;

        for ($tryOverlap = 1; $tryOverlap <= $maxOverlap; $tryOverlap++) {
            if (!$this->canVerticallyFit($top, $bottom, $tryOverlap, $maxWidth)) {
                break;
            }
            $fittingOverlap = $tryOverlap;
        }

        if ($vMode === LayoutMode::Smushing && $fittingOverlap < $maxOverlap) {
            $smushOverlap = $fittingOverlap + 1;
            if ($this->canVerticallySmush($top, $bottom, $smushOverlap, $maxWidth)) {
                return $smushOverlap;
            }
        }

        return $fittingOverlap;
    }

    /**
     * @param list<string> $top
     * @param list<string> $bottom
     */
    private function canVerticallyFit(array $top, array $bottom, int $overlap, int $maxWidth): bool
    {
        $topHeight = count($top);
        for ($col = 0; $col < $maxWidth; $col++) {
            for ($row = 0; $row < $overlap; $row++) {
                $topIdx = $topHeight - $overlap + $row;
                $topRow = $topIdx >= 0 ? ($top[$topIdx] ?? '') : '';
                $topCh = $this->charAt($topRow, $col);
                $topChar = $this->vNormalize($topCh !== '' ? $topCh : ' ');
                $bRow = $bottom[$row] ?? '';
                $bCh = $this->charAt($bRow, $col);
                $bottomChar = $this->vNormalize($bCh !== '' ? $bCh : ' ');
                if ($topChar !== ' ' && $bottomChar !== ' ') {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param list<string> $top
     * @param list<string> $bottom
     */
    private function canVerticallySmush(array $top, array $bottom, int $overlap, int $maxWidth): bool
    {
        $topHeight = count($top);
        for ($col = 0; $col < $maxWidth; $col++) {
            for ($row = 0; $row < $overlap; $row++) {
                $topIdx = $topHeight - $overlap + $row;
                $topRow = $topIdx >= 0 ? ($top[$topIdx] ?? '') : '';
                $tCh = $this->charAt($topRow, $col);
                $topChar = $this->vNormalize($tCh !== '' ? $tCh : ' ');
                $bRow = $bottom[$row] ?? '';
                $bCh = $this->charAt($bRow, $col);
                $bottomChar = $this->vNormalize($bCh !== '' ? $bCh : ' ');
                if ($topChar !== ' ' && $bottomChar !== ' ' && $this->vSmushChar($topChar, $bottomChar) === null) {
                    return false;
                }
            }
        }
        return true;
    }

    private function vNormalize(string $char): string
    {
        return $char === $this->hardblank ? ' ' : $char;
    }

    /**
     * @param list<string> $top
     * @param list<string> $bottom
     * @return list<string>
     */
    private function buildVerticalMerge(array $top, array $bottom, int $overlap, int $maxWidth, LayoutMode $vMode): array
    {
        $topHeight = count($top);
        $result = array_slice($top, 0, $topHeight - $overlap);

        for ($row = 0; $row < $overlap; $row++) {
            $topRow = $topHeight - $overlap + $row;
            $merged = '';
            for ($col = 0; $col < $maxWidth; $col++) {
                $tCh = $this->charAt($top[$topRow] ?? '', $col);
                $topChar = $tCh !== '' ? $tCh : ' ';
                $bCh = $this->charAt($bottom[$row] ?? '', $col);
                $bottomChar = $bCh !== '' ? $bCh : ' ';
                $topNorm = $this->vNormalize($topChar);
                $bottomNorm = $this->vNormalize($bottomChar);

                $merged .= match (true) {
                    $topNorm === ' ' => ($bottomNorm === ' ') ? $topChar : $bottomChar,
                    $bottomNorm === ' ' => $topChar,
                    $vMode === LayoutMode::Smushing => $this->vSmushChar($topNorm, $bottomNorm) ?? $bottomNorm,
                    default => $bottomChar,
                };
            }
            $result[] = $merged;
        }

        return array_merge($result, array_slice($bottom, $overlap));
    }

    private function vSmushChar(string $top, string $bottom): ?string
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

    private function applyParagraphMode(string $str): string
    {
        $result = '';
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            if ($str[$i] !== "\n") {
                $result .= $str[$i];
                continue;
            }

            $keepNewline = $i + 1 >= $len
                || ($i > 0 && $str[$i - 1] === "\n")
                || $str[$i + 1] === ' '
                || $str[$i + 1] === "\n";

            $result .= $keepNewline ? "\n" : ' ';
        }

        return $result;
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private function applyJustification(array $figure): array
    {
        if ($this->outputWidth === null) {
            return $figure;
        }

        $align = $this->justification;
        if ($align === Justification::Auto) {
            $align = $this->printDirection !== 0 ? Justification::Right : Justification::Left;
        }

        if ($align === Justification::Left) {
            return $figure;
        }

        $width = $this->outputWidth - 1;
        $result = [];

        foreach ($figure as $row) {
            $padding = $width - $this->charLength($row);
            if ($padding <= 0) {
                $result[] = $row;
                continue;
            }

            $pad = $align === Justification::Right ? $padding : intdiv($padding, 2);
            $result[] = str_repeat(' ', $pad) . $row;
        }

        return $result;
    }

    /** @return list<int> */
    private function splitString(string $str): array
    {
        $codes = [];
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            if (substr($str, $i, 2) === '%u') {
                $codes[] = (int) hexdec(substr($str, $i + 2, 4));
                $i += 5;
                continue;
            }

            $byte = ord($str[$i]);

            if ($byte < 0x80) {
                $codes[] = $byte;
            } elseif (($byte & 0xE0) === 0xC0) {
                $codePoint = ($byte & 0x1F) << 6;
                $codePoint |= (ord($str[++$i]) & 0x3F);
                $codes[] = $codePoint;
            } elseif (($byte & 0xF0) === 0xE0) {
                $codePoint = ($byte & 0x0F) << 12;
                $codePoint |= (ord($str[++$i]) & 0x3F) << 6;
                $codePoint |= (ord($str[++$i]) & 0x3F);
                $codes[] = $codePoint;
            } elseif (($byte & 0xF8) === 0xF0) {
                $codePoint = ($byte & 0x07) << 18;
                $codePoint |= (ord($str[++$i]) & 0x3F) << 12;
                $codePoint |= (ord($str[++$i]) & 0x3F) << 6;
                $codePoint |= (ord($str[++$i]) & 0x3F);
                $codes[] = $codePoint;
            }
        }

        return $codes;
    }

    /**
     * @param resource $stream
     * @return list<string>|false
     */
    private function parseChar(mixed $stream): array|false
    {
        $out = [];

        for ($i = 0; $i < $this->height; $i++) {
            if (feof($stream)) {
                return false;
            }

            $raw = fgets($stream, 2048);
            $line = $raw !== false ? rtrim($raw, "\r\n") : '';

            if (!$this->isTlf) {
                $line = $this->latin1ToUtf8($line);
            }
            $pos = mb_strlen($line, 'UTF-8') - 1;
            while ($pos >= 0 && mb_substr($line, $pos, 1, 'UTF-8') === ' ') {
                $pos--;
            }
            if ($pos >= 0) {
                $endChar = mb_substr($line, $pos, 1, 'UTF-8');
                while ($pos >= 0 && mb_substr($line, $pos, 1, 'UTF-8') === $endChar) {
                    $pos--;
                }
            }
            $line = $pos >= 0 ? mb_substr($line, 0, $pos + 1, 'UTF-8') : '';

            $out[] = $line;
        }

        return $out;
    }

    /** @param resource $stream */
    private function skipChar(mixed $stream): void
    {
        for ($i = 0; $i < $this->height && !feof($stream); $i++) {
            fgets($stream, 2048);
        }
    }

    /**
     * @param list<string> $figure
     * @return list<string>
     */
    private function applyFilters(array $figure): array
    {
        foreach ($this->filters as $filter) {
            $figure = FilterEngine::apply($filter, $figure);
        }

        return $figure;
    }
}
