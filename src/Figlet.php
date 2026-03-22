<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

use Throwable;
use ValueError;
use Normalizer;
use ZipArchive;
use Com\Tecnick\Unicode\Bidi;
use Bolk\TextFiglet\Exception\FontLoadException;
use Bolk\TextFiglet\Exception\FontNotFoundException;

final class Figlet
{
    public const string VERSION = '2.8.0';

    protected int $height = 0;
    protected int $baseline = 0;
    protected int $oldLayout = 0;
    protected int $fullLayout = -1;
    protected int $codetagCount = 0;
    protected int $printDirection = 0;
    protected string $hardblank = '';
    protected bool $isTlf = false;

    /** @var array<int, list<Row>> */
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

    private function buildSmushEngine(): SmushEngine
    {
        return new SmushEngine(
            $this->hardblank,
            $this->hSmushRules,
            $this->vSmushRules,
            $this->printDirection,
            $this->hLayoutOverride ?? $this->hLayout,
        );
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

        $width = TerminalWidthDetector::detectViaIoctl();
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

    /** @return list<int> */
    public function getLoadedCodepoints(): array
    {
        $keys = array_keys($this->font);
        sort($keys);
        return $keys;
    }

    public function getCharWidth(int $codepoint): ?int
    {
        return $this->fontCharWidths[$codepoint] ?? null;
    }

    public function loadFont(string $filename, bool $loadGerman = true): void
    {
        $previousState = [
            'height' => $this->height,
            'baseline' => $this->baseline,
            'oldLayout' => $this->oldLayout,
            'fullLayout' => $this->fullLayout,
            'codetagCount' => $this->codetagCount,
            'printDirection' => $this->printDirection,
            'hardblank' => $this->hardblank,
            'isTlf' => $this->isTlf,
            'font' => $this->font,
            'fontCharWidths' => $this->fontCharWidths,
            'fontComment' => $this->fontComment,
            'hSmushRules' => $this->hSmushRules,
            'vSmushRules' => $this->vSmushRules,
            'hLayout' => $this->hLayout,
            'vLayout' => $this->vLayout,
            'fontName' => $this->fontName,
        ];

        try {
            $this->font = [];
            $this->fontCharWidths = [];
            $this->fontName = pathinfo($filename, PATHINFO_FILENAME);

            if (!file_exists($filename)) {
                $fontDir = __DIR__ . '/../fonts/';

                $resolved = $this->resolveFont($filename, $fontDir);
                if ($resolved !== null) {
                    $filename = $resolved;
                } else {
                    throw new FontNotFoundException(
                        'Figlet font file "' . $filename . '" cannot be found'
                    );
                }
            }

            $this->fontComment = '';

            $stream = fopen($filename, 'rb');
            if ($stream === false) {
                throw new FontLoadException('Cannot open figlet font file ' . $filename);
            }

            $magic = fread($stream, 2);
            fclose($stream);

            if ($magic === "\x1f\x8b") {
                if (!extension_loaded('zlib')) {
                    throw new FontLoadException(
                        'Cannot load gzip compressed fonts: zlib extension is not available'
                    );
                }
                $stream = fopen('compress.zlib://' . $filename, 'rb');
                if ($stream === false) {
                    throw new FontLoadException('Cannot open figlet font file ' . $filename);
                }
            } elseif ($magic === 'PK') {
                $stream = $this->openZipFont($filename);
            } else {
                $stream = fopen($filename, 'rb');
                if ($stream === false) {
                    throw new FontLoadException('Cannot open figlet font file ' . $filename);
                }
                flock($stream, LOCK_SH);
            }

            try {
                $this->parseFont($stream, $loadGerman);
            } finally {
                fclose($stream);
            }
        } catch (Throwable $e) {
            $this->height = $previousState['height'];
            $this->baseline = $previousState['baseline'];
            $this->oldLayout = $previousState['oldLayout'];
            $this->fullLayout = $previousState['fullLayout'];
            $this->codetagCount = $previousState['codetagCount'];
            $this->printDirection = $previousState['printDirection'];
            $this->hardblank = $previousState['hardblank'];
            $this->isTlf = $previousState['isTlf'];
            $this->font = $previousState['font'];
            $this->fontCharWidths = $previousState['fontCharWidths'];
            $this->fontComment = $previousState['fontComment'];
            $this->hSmushRules = $previousState['hSmushRules'];
            $this->vSmushRules = $previousState['vSmushRules'];
            $this->hLayout = $previousState['hLayout'];
            $this->vLayout = $previousState['vLayout'];
            $this->fontName = $previousState['fontName'];
            throw $e;
        }
    }

    private const FONT_EXTENSIONS = ['.flf', '.tlf', '.flf.gz', '.tlf.gz'];

    private function resolveFont(string $filename, string $fontDir): ?string
    {
        if (!str_contains($filename, '/') && file_exists($fontDir . $filename)) {
            return $fontDir . $filename;
        }

        $hasExt = str_contains($filename, '.');
        $hasDir = str_contains($filename, '/');

        if (!$hasExt) {
            foreach (self::FONT_EXTENSIONS as $ext) {
                if (file_exists($filename . $ext)) {
                    return $filename . $ext;
                }
            }
        }

        if (!$hasDir && !$hasExt) {
            foreach (self::FONT_EXTENSIONS as $ext) {
                if (file_exists($fontDir . $filename . $ext)) {
                    return $fontDir . $filename . $ext;
                }
            }
        }

        return null;
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
            $text = '';
            foreach ($letter as $r) {
                $text .= $r->toText();
            }
            if (trim($text) !== '') {
                $this->font[$code] = $letter;
                $this->fontCharWidths[$code] = $letter !== [] ? $letter[0]->length() : 0;
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

            $parsedCode = $this->parseCharCode($code);
            if ($parsedCode < 0) {
                $this->skipChar($stream);
            } else {
                $this->loadCharFromFile($stream, $parsedCode);
            }
        }
    }

    private function resolveCharCode(int $code): int
    {
        if (isset($this->font[$code]) || $code <= 0xFF
            || !str_contains($this->fontComment, 'GB2312')) {
            return $code;
        }

        try {
            $chr = mb_chr($code);
            if ($chr === false) {
                return $code;
            }
            $euc = mb_convert_encoding($chr, 'EUC-CN', 'UTF-8');
            if (!is_string($euc)) {
                return $code;
            }
        } catch (ValueError) {
            return $code;
        }

        $bdf = strlen($euc) === 2
            ? ((ord($euc[0]) - 0x80) << 8) | (ord($euc[1]) - 0x80)
            : $code;

        return isset($this->font[$bdf]) ? $bdf : $code;
    }

    private function parseCharCode(string $code): int
    {
        if (preg_match('/^-?0x/i', $code) === 1) {
            $negative = $code[0] === '-';
            $value = (int) hexdec(substr($code, $negative ? 3 : 2));
            return $negative ? -$value : $value;
        }
        if (preg_match('/^-?0[0-7]+$/', $code) === 1) {
            $negative = $code[0] === '-';
            $value = (int) octdec($negative ? substr($code, 1) : $code);
            return $negative ? -$value : $value;
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
        $this->fontCharWidths[$code] = $letter !== [] ? $letter[0]->length() : 0;
    }

    private function deriveLayoutModes(): void
    {
        if ($this->fullLayout !== -1) {
            $bits = $this->fullLayout & 0xFFFFFFFF;
            $this->hSmushRules = $bits & 63;
            $this->vSmushRules = ($bits >> 8) & 31;

            if (($bits & 128) !== 0) {
                $this->hLayout = LayoutMode::Smushing;
            } elseif (($bits & 64) !== 0) {
                $this->hLayout = LayoutMode::Fitting;
            } else {
                $this->hLayout = LayoutMode::FullSize;
            }

            if (($bits & 16384) !== 0) {
                $this->vLayout = LayoutMode::Smushing;
            } elseif (($bits & 8192) !== 0) {
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
                $this->hSmushRules = $this->oldLayout & 31;
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

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($str, Normalizer::FORM_C);
            if (is_string($normalized)) {
                $str = $normalized;
            }
        }

        $str = $this->applyBidi($str);

        $eng = $this->buildSmushEngine();
        $inputLines = explode("\n", $str);
        $figures = [];

        foreach ($inputLines as $inputLine) {
            foreach ($this->renderLineWithWrapping($eng, $inputLine) as $fig) {
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
            $combined = $this->combineFiguresVertically($eng, $combined, $figures[$i], $effectiveVLayout);
        }

        $combined = $this->applyJustification($combined);

        foreach ($combined as $idx => $row) {
            $combined[$idx] = $row->replaceChar($this->hardblank, ' ');
        }

        $combined = $this->applyFilters($combined);

        return Renderer::export($combined, $format);
    }

    /** @param list<list<Row>> $figures */
    private function allEmpty(array $figures): bool
    {
        foreach ($figures as $fig) {
            foreach ($fig as $row) {
                if (trim($row->toText()) !== '') {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return list<list<Row>> */
    private function renderLineWithWrapping(SmushEngine $eng, string $line): array
    {
        $codes = $this->splitString($line);

        if ($codes === []) {
            return [array_fill(0, $this->height, new Row([]))];
        }

        if ($this->outputWidth === null) {
            return [$this->renderCodes($eng, $codes)];
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

            $rendered = $this->renderCodes($eng, $current);
            $width = $this->figureWidth($rendered);

            if ($width > $this->outputWidth - 1 && count($current) > 1) {
                if ($lastSpaceIdx >= 0) {
                    $beforeSpace = array_slice($current, 0, $lastSpaceIdx);
                    $afterSpace = array_slice($current, $lastSpaceIdx + 1);

                    while ($i + 1 < $codeCount && $codes[$i + 1] === 32) {
                        $i++;
                    }

                    if ($beforeSpace !== []) {
                        $figures[] = $this->renderCodes($eng, $beforeSpace);
                    }
                    $current = $afterSpace;
                } else {
                    $overflow = array_pop($current);
                    if ($current !== []) {
                        $figures[] = $this->renderCodes($eng, $current);
                    }
                    $current = [$overflow];
                }
                $lastSpaceIdx = -1;
            }
        }

        if ($current !== []) {
            $figures[] = $this->renderCodes($eng, $current);
        }

        return $figures;
    }

    /** @param list<Row> $figure */
    private function figureWidth(array $figure): int
    {
        $max = 0;
        foreach ($figure as $row) {
            $max = max($max, $row->length());
        }
        return $max;
    }

    /**
     * Render a sequence of character codes into a FIGure.
     *
     * Port of the smushamt/addchar loop from figlet.c.
     *
     * @param list<int> $codes
     * @return list<Row>
     */
    private function renderCodes(SmushEngine $eng, array $codes): array
    {
        $effectiveHLayout = $this->hLayoutOverride ?? $this->hLayout;
        /** @var list<Row> $outLines */
        $outLines = [];
        $outWidth = 0;
        $prevCharWidth = 0;

        foreach ($codes as $lt) {
            $lt = $this->resolveCharCode($lt);
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
                $eng, $outLines, $charLines, $outWidth, $charWidth, $prevCharWidth, $effectiveHLayout,
            );

            $outLines = $this->addCharToOutput(
                $eng, $outLines, $charLines, $outWidth, $charWidth, $smushAmount,
            );
            $outWidth = $outLines !== [] ? $outLines[0]->length() : 0;
            $prevCharWidth = $charWidth;
        }

        if ($outLines === []) {
            return array_fill(0, $this->height, new Row([]));
        }

        if ($effectiveHLayout !== LayoutMode::FullSize) {
            $outLines = $this->stripLeadingBlankColumns($outLines);
        }

        return $outLines;
    }

    /**
     * Port of smushamt() from figlet.c.
     *
     * @param list<Row> $outLines
     * @param list<Row> $charLines
     */
    private function calcSmushAmount(
        SmushEngine $eng,
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
                $outLines[$row] ?? new Row([]),
                $charLines[$row] ?? new Row([]),
                $outWidth,
                $charWidth,
            );
            $amount = $boundary['amount'];

            if ($boundary['left_edge'] === ' ') {
                $amount++;
            } elseif ($boundary['right_edge'] !== ' ' && $mode === LayoutMode::Smushing) {
                if ($eng->smushem($boundary['left_edge'], $boundary['right_edge'], $prevCharWidth, $charWidth) !== null) {
                    $amount++;
                }
            }

            $maxSmush = min($maxSmush, $amount);
        }

        return $maxSmush;
    }

    /** @return array{left_edge: string, right_edge: string, amount: int} */
    private function measureSmushBoundary(
        Row $outputRow,
        Row $characterRow,
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
        Row $outputRow,
        Row $characterRow,
        int $characterWidth,
    ): array {
        $trimmedCharWidth = $characterRow->length();
        while ($trimmedCharWidth > 0 && $characterRow->charAt($trimmedCharWidth - 1) === ' ') {
            $trimmedCharWidth--;
        }
        $leftEdge = $trimmedCharWidth > 0 ? $characterRow->charAt($trimmedCharWidth - 1) : ' ';
        $leadingOutputSpace = 0;
        $outputLength = $outputRow->length();
        while ($leadingOutputSpace < $outputLength && $outputRow->charAt($leadingOutputSpace) === ' ') {
            $leadingOutputSpace++;
        }
        $rightEdge = $leadingOutputSpace < $outputLength ? $outputRow->charAt($leadingOutputSpace) : ' ';
        return [
            'left_edge' => $leftEdge,
            'right_edge' => $rightEdge,
            'amount' => $leadingOutputSpace + $characterWidth - max($trimmedCharWidth, 1),
        ];
    }

    /** @return array{left_edge: string, right_edge: string, amount: int} */
    private function measureLeftToRightSmushBoundary(
        Row $outputRow,
        Row $characterRow,
        int $outputWidth,
        int $characterWidth,
    ): array {
        $trimmedOutputWidth = $outputRow->length();
        while ($trimmedOutputWidth > 0 && $outputRow->charAt($trimmedOutputWidth - 1) === ' ') {
            $trimmedOutputWidth--;
        }
        $leftEdge = $trimmedOutputWidth > 0 ? $outputRow->charAt($trimmedOutputWidth - 1) : ' ';
        $leadingCharSpace = 0;
        while ($leadingCharSpace < $characterWidth && $characterRow->charAt($leadingCharSpace) === ' ') {
            $leadingCharSpace++;
        }
        $rightEdge = $leadingCharSpace < $characterWidth ? $characterRow->charAt($leadingCharSpace) : ' ';
        return [
            'left_edge' => $leftEdge,
            'right_edge' => $rightEdge,
            'amount' => $leadingCharSpace + $outputWidth - max($trimmedOutputWidth, 1),
        ];
    }

    /**
     * @param list<Row> $outLines
     * @param list<Row> $charLines
     * @return list<Row>
     */
    private function addCharToOutput(
        SmushEngine $eng,
        array $outLines,
        array $charLines,
        int $outWidth,
        int $charWidth,
        int $smushAmount,
    ): array {
        $result = [];

        for ($row = 0; $row < $this->height; $row++) {
            $outRow = $outLines[$row] ?? new Row([]);
            $charRow = $charLines[$row] ?? new Row([]);

            $result[] = $this->printDirection !== 0
                ? $this->smushRowRtl($eng, $outRow, $charRow, $outWidth, $charWidth, $smushAmount)
                : $this->smushRowLtr($eng, $outRow, $charRow, $outWidth, $charWidth, $smushAmount);
        }

        return $result;
    }

    private function smushRowLtr(SmushEngine $eng, Row $outRow, Row $charRow, int $outWidth, int $charWidth, int $smushAmount): Row
    {
        $line = $outRow;
        for ($k = 0; $k < $smushAmount; $k++) {
            $column = max(0, $outWidth - $smushAmount + $k);
            $leftCh = $line->charAt($column);
            $rightCh = $charRow->charAt($k);
            if ($leftCh === '') { $leftCh = ' '; }
            if ($rightCh === '') { $rightCh = ' '; }
            $smushed = $eng->smushem($leftCh, $rightCh, $outWidth, $charWidth);
            if ($column < $line->length()) {
                $resultCh = $smushed ?? $rightCh;
                $colorCell = $eng->pickSmushColor(
                    $resultCh, $leftCh, $rightCh,
                    $line->cellAt($column), $charRow->cellAt($k),
                );
                $line = $line->replaceAt($column, Cell::get($resultCh, $colorCell->fg, $colorCell->bg, $colorCell->fgBase16, $colorCell->bgBase16));
            }
        }
        return $line->append($charRow->slice($smushAmount));
    }

    private function smushRowRtl(SmushEngine $eng, Row $outRow, Row $charRow, int $outWidth, int $charWidth, int $smushAmount): Row
    {
        $temp = $charRow;
        for ($k = 0; $k < $smushAmount; $k++) {
            $pos = $charWidth - $smushAmount + $k;
            $leftCh = $temp->charAt($pos);
            $rightCh = $outRow->charAt($k);
            if ($leftCh === '') { $leftCh = ' '; }
            if ($rightCh === '') { $rightCh = ' '; }
            $smushed = $eng->smushem($leftCh, $rightCh, $charWidth, $outWidth);
            $resultCh = $smushed ?? $rightCh;
            $colorCell = $eng->pickSmushColor(
                $resultCh, $leftCh, $rightCh,
                $temp->cellAt($pos), $outRow->cellAt($k),
            );
            $temp = $temp->replaceAt($pos, Cell::get($resultCh, $colorCell->fg, $colorCell->bg, $colorCell->fgBase16, $colorCell->bgBase16));
        }
        return $temp->append($outRow->slice($smushAmount));
    }

    /**
     * @param list<Row> $figure
     * @return list<Row>
     */
    private function stripLeadingBlankColumns(array $figure): array
    {
        $minLeading = PHP_INT_MAX;
        foreach ($figure as $row) {
            $leading = 0;
            $len = $row->length();
            while ($leading < $len && $row->charAt($leading) === ' ') {
                $leading++;
            }
            $minLeading = min($minLeading, $leading);
            if ($minLeading === 0) {
                return $figure;
            }
        }

        if ($minLeading < PHP_INT_MAX) {
            foreach ($figure as $idx => $row) {
                $figure[$idx] = $row->slice($minLeading);
            }
        }

        return $figure;
    }

    /**
     * @param list<Row> $top
     * @param list<Row> $bottom
     * @return list<Row>
     */
    private function combineFiguresVertically(SmushEngine $eng, array $top, array $bottom, LayoutMode $vMode): array
    {
        if ($vMode === LayoutMode::FullSize) {
            return array_merge($top, $bottom);
        }

        $maxWidth = 0;
        foreach (array_merge($top, $bottom) as $row) {
            $maxWidth = max($maxWidth, $row->length());
        }
        foreach ($top as $idx => $row) {
            $top[$idx] = $row->pad($maxWidth);
        }
        foreach ($bottom as $idx => $row) {
            $bottom[$idx] = $row->pad($maxWidth);
        }

        $overlap = $this->calcVerticalOverlap($eng, $top, $bottom, $maxWidth, $vMode);

        return $this->buildVerticalMerge($eng, $top, $bottom, $overlap, $maxWidth, $vMode);
    }

    /**
     * Port of vertical smush overlap calculation from figlet.c.
     *
     * @param list<Row> $top
     * @param list<Row> $bottom
     */
    private function calcVerticalOverlap(SmushEngine $eng, array $top, array $bottom, int $maxWidth, LayoutMode $vMode): int
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
            if ($this->canVerticallySmush($eng, $top, $bottom, $smushOverlap, $maxWidth)) {
                if (($this->vSmushRules & 16) !== 0) {
                    while ($smushOverlap < $maxOverlap
                        && $this->canVerticallySmush($eng, $top, $bottom, $smushOverlap + 1, $maxWidth)
                    ) {
                        $smushOverlap++;
                    }
                }
                return $smushOverlap;
            }
        }

        return $fittingOverlap;
    }

    /**
     * @param list<Row> $top
     * @param list<Row> $bottom
     */
    private function canVerticallyFit(array $top, array $bottom, int $overlap, int $maxWidth): bool
    {
        $topHeight = count($top);
        for ($col = 0; $col < $maxWidth; $col++) {
            for ($row = 0; $row < $overlap; $row++) {
                $topIdx = $topHeight - $overlap + $row;
                $topRow = $topIdx >= 0 ? ($top[$topIdx] ?? new Row([])) : new Row([]);
                $topCh = $topRow->charAt($col);
                $topChar = $this->vNormalize($topCh !== '' ? $topCh : ' ');
                $bRow = $bottom[$row] ?? new Row([]);
                $bCh = $bRow->charAt($col);
                $bottomChar = $this->vNormalize($bCh !== '' ? $bCh : ' ');
                if ($topChar !== ' ' && $bottomChar !== ' ') {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param list<Row> $top
     * @param list<Row> $bottom
     */
    private function canVerticallySmush(SmushEngine $eng, array $top, array $bottom, int $overlap, int $maxWidth): bool
    {
        $topHeight = count($top);
        for ($col = 0; $col < $maxWidth; $col++) {
            for ($row = 0; $row < $overlap; $row++) {
                $topIdx = $topHeight - $overlap + $row;
                $topRow = $topIdx >= 0 ? ($top[$topIdx] ?? new Row([])) : new Row([]);
                $tCh = $topRow->charAt($col);
                $topChar = $this->vNormalize($tCh !== '' ? $tCh : ' ');
                $bRow = $bottom[$row] ?? new Row([]);
                $bCh = $bRow->charAt($col);
                $bottomChar = $this->vNormalize($bCh !== '' ? $bCh : ' ');
                if ($topChar !== ' ' && $bottomChar !== ' ' && $eng->vSmushChar($topChar, $bottomChar) === null) {
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
     * @param list<Row> $top
     * @param list<Row> $bottom
     * @return list<Row>
     */
    private function buildVerticalMerge(SmushEngine $eng, array $top, array $bottom, int $overlap, int $maxWidth, LayoutMode $vMode): array
    {
        $topHeight = count($top);
        $result = array_slice($top, 0, $topHeight - $overlap);

        for ($row = 0; $row < $overlap; $row++) {
            $topRowIdx = $topHeight - $overlap + $row;
            $cells = [];
            for ($col = 0; $col < $maxWidth; $col++) {
                $topCell = ($top[$topRowIdx] ?? new Row([]))->cellAt($col);
                $bottomCell = ($bottom[$row] ?? new Row([]))->cellAt($col);
                $topChar = $topCell->char;
                $bottomChar = $bottomCell->char;
                $topNorm = $this->vNormalize($topChar);
                $bottomNorm = $this->vNormalize($bottomChar);

                if ($vMode === LayoutMode::Smushing && $topNorm !== ' ' && $bottomNorm !== ' ') {
                    $vResult = $eng->vSmushChar($topNorm, $bottomNorm) ?? $bottomNorm;
                    $colorCell = $eng->pickSmushColor($vResult, $topNorm, $bottomNorm, $topCell, $bottomCell);
                    $cells[] = Cell::get($vResult, $colorCell->fg, $colorCell->bg, $colorCell->fgBase16, $colorCell->bgBase16);
                } else {
                    $cells[] = match (true) {
                        $topNorm === ' ' && $bottomNorm === ' ' => $topCell,
                        $topNorm === ' ' => $bottomCell,
                        $bottomNorm === ' ' => $topCell,
                        default => $bottomCell,
                    };
                }
            }
            $result[] = new Row($cells);
        }

        return array_merge($result, array_slice($bottom, $overlap));
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
     * @param list<Row> $figure
     * @return list<Row>
     */
    private function applyJustification(array $figure): array
    {
        $align = $this->justification;
        if ($align === Justification::Auto) {
            $align = $this->printDirection !== 0 ? Justification::Right : Justification::Left;
        }

        if ($align === Justification::Left) {
            return $figure;
        }

        $width = ($this->outputWidth ?? 80) - 1;
        $result = [];

        foreach ($figure as $row) {
            $padding = $width - $row->length();
            if ($padding <= 0) {
                $result[] = $row;
                continue;
            }

            $pad = $align === Justification::Right ? $padding : intdiv($padding, 2);
            $result[] = Row::empty($pad)->append($row);
        }

        return $result;
    }

    /** @return list<int> */
    private function splitString(string $str): array
    {
        return Utf8Decoder::decode($str, true);
    }

    /**
     * @param resource $stream
     * @return list<Row>|false
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

            if (!$this->isTlf && !mb_check_encoding($line, 'UTF-8')) {
                /** @var string */
                $line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');
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

            $out[] = Row::fromAnsi($line);
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
     * @param list<Row> $figure
     * @return list<Row>
     */
    private function applyFilters(array $figure): array
    {
        foreach ($this->filters as $filter) {
            $figure = FilterEngine::apply($filter, $figure);
        }

        return $figure;
    }

    private function applyBidi(string $str): string
    {
        if (!class_exists(Bidi::class)) {
            return $str;
        }

        try {
            $bidi = new Bidi($str);
            return $bidi->getString();
        } catch (Throwable) {
            return $str;
        }
    }
}
