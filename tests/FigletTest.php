<?php

declare(strict_types=1);

namespace Bolk\TextFiglet\Tests;

use Normalizer;
use Bolk\TextFiglet\Cell;
use Bolk\TextFiglet\ControlFile;
use Bolk\TextFiglet\Encoding;
use Override;
use ZipArchive;
use ReflectionProperty;
use ReflectionMethod;
use Bolk\TextFiglet\Exception\FontLoadException;
use Bolk\TextFiglet\Exception\FontNotFoundException;
use Bolk\TextFiglet\ExportFormat;
use Bolk\TextFiglet\Figlet;
use Bolk\TextFiglet\Filter;
use Bolk\TextFiglet\FilterEngine;
use Bolk\TextFiglet\Justification;
use Bolk\TextFiglet\LayoutMode;
use Bolk\TextFiglet\Row;
use Bolk\TextFiglet\SmushEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
final class FigletTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    private function fontPath(string $name = 'makisupa.flf'): string
    {
        return __DIR__ . '/../fonts/' . $name;
    }

    private function fixturePath(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    private function loadedFiglet(string $font = 'makisupa.flf'): Figlet
    {
        $figlet = new Figlet();
        $figlet->loadFont($this->fontPath($font));
        return $figlet;
    }

    private function fixturedFiglet(string $fixture): Figlet
    {
        $figlet = new Figlet();
        $figlet->loadFont($this->fixturePath($fixture));
        return $figlet;
    }

    #[Override]
    protected function tearDown(): void
    {
        $dirs = [];

        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                $dirs[dirname($path)] = true;
                unlink($path);
            }
        }

        foreach (array_keys($dirs) as $dir) {
            @rmdir($dir);
        }

        $this->tempPaths = [];
    }

    /**
     * Decompress a gzip font to a temp directory and return that directory path.
     * Used for reference tool comparison: figlet/toilet can't read raw gzip.
     */
    private function decompressGzipFont(string $fontPath): string
    {
        $plain = file_get_contents('compress.zlib://' . $fontPath);
        if ($plain === false) {
            $this->markTestSkipped('Cannot decompress gzip font');
        }

        $dir = sys_get_temp_dir() . '/figlet_ref_' . str_replace('.', '_', uniqid('', true));
        mkdir($dir, 0700, true);

        $dest = $dir . '/' . basename($fontPath);
        file_put_contents($dest, $plain);
        $this->tempPaths[] = $dest;

        return $dir;
    }

    private function writeTempFile(string $contents, string $suffix): string
    {
        $path = sys_get_temp_dir() . '/figlet_' . str_replace('.', '_', uniqid('', true)) . $suffix;
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;
        return $path;
    }

    /**
     * @param array<int, string> $glyphs
     * @param list<array{code: int|string, glyph: string}> $codeTags
     * @param list<string> $commentLines
     */
    private function buildSimpleFont(
        int $oldLayout = -1,
        ?int $fullLayout = null,
        int $printDirection = 0,
        array $glyphs = [],
        array $codeTags = [],
        array $commentLines = [],
    ): string {
        $header = sprintf(
            'flf2a$ 1 1 1 %d %d %d',
            $oldLayout,
            count($commentLines),
            $printDirection,
        );

        if ($fullLayout !== null) {
            $header .= sprintf(' %d 0', $fullLayout);
        }

        $lines = [$header, ...$commentLines];

        for ($code = 32; $code < 127; $code++) {
            $glyph = $glyphs[$code] ?? match ($code) {
                32 => ' ',
                126 => 't',
                default => chr($code),
            };
            $lines[] = $glyph . '~';
        }

        foreach ([196, 214, 220, 228, 246, 252, 223] as $code) {
            $lines[] = ($glyphs[$code] ?? 'g') . '~';
        }

        foreach ($codeTags as $entry) {
            $lines[] = (string) $entry['code'];
            $lines[] = $entry['glyph'] . '~';
        }

        return implode("\n", $lines) . "\n";
    }

    private function writeZipArchive(string $fontContents, bool $empty = false): string
    {
        $path = $this->writeTempFile('', '.zip');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        if (!$empty) {
            $zip->addFromString('font.flf', $fontContents);
        }

        $zip->close();

        return $path;
    }

    private function getFigletProperty(Figlet $figlet, string $property): mixed
    {
        $ref = new ReflectionProperty($figlet, $property);
        return $ref->getValue($figlet);
    }

    private function setFigletProperty(Figlet $figlet, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($figlet, $property);
        $ref->setValue($figlet, $value);
    }

    private function invokeFigletMethod(Figlet $figlet, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($figlet, $method);
        return $ref->invoke($figlet, ...$args);
    }

    /**
     * @param list<Row> $rows
     * @return list<string>
     */
    private function rowsToStrings(array $rows): array
    {
        return array_map(static fn(Row $r): string => $r->toText(), $rows);
    }

    /**
     * @param list<string> $strings
     * @return list<Row>
     */
    private function stringsToRows(array $strings): array
    {
        return array_map(Row::fromString(...), $strings);
    }

    /**
     * @return list<Row>
     */
    private function invokeRowListMethod(Figlet $figlet, string $method, mixed ...$args): array
    {
        $result = $this->invokeFigletMethod($figlet, $method, ...$args);
        self::assertIsArray($result);
        /** @var list<Row> $result */
        return $result;
    }

    // --- Basic loading ---

    public function testLoadFont(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont($this->fontPath());
        $this->assertSame(16, $figlet->getHeight());
    }

    public function testLoadFontByNameWithoutPath(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont('makisupa.flf');
        $this->assertSame(16, $figlet->getHeight());
    }

    public function testFontNotFound(): void
    {
        $this->expectException(FontNotFoundException::class);
        $figlet = new Figlet();
        $figlet->loadFont('nonexistent_font.flf');
    }

    public function testInvalidFontFormat(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'figlet_test_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "NOT_A_FIGLET_FONT\n");
        try {
            $this->expectException(FontLoadException::class);
            $this->expectExceptionMessage('Unknown FIGlet font format');
            $figlet = new Figlet();
            $figlet->loadFont($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testUnreadableFontThrows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'figlet_test_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "flf2a$ 1 1 1 -1 0 0\n");
        chmod($tmp, 0);

        try {
            $this->expectException(FontLoadException::class);
            $this->expectExceptionMessage('Cannot open figlet font file');

            set_error_handler(static fn (): bool => true);
            $figlet = new Figlet();
            $figlet->loadFont($tmp);
        } finally {
            restore_error_handler();
            chmod($tmp, 0644);
            unlink($tmp);
        }
    }

    public function testDirectoryFontPathThrowsReadHeaderError(): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(FontLoadException::class);
            $this->expectExceptionMessage('Cannot read font header');

            $figlet = new Figlet();
            $figlet->loadFont(sys_get_temp_dir());
        } finally {
            restore_error_handler();
        }
    }

    // --- Header parsing ---

    public function testFullLayoutParsed(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $this->assertSame(143, $figlet->getFullLayout());
        $this->assertSame(15, $figlet->getOldLayout());
        $this->assertSame(0, $figlet->getCodetagCount());
    }

    public function testFullLayoutAbsent(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertSame(0, $figlet->getFullLayout());
    }

    public function testMakisupaHeaderParsed(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertSame(16, $figlet->getHeight());
        $this->assertSame(-1, $figlet->getOldLayout());
        $this->assertSame(0, $figlet->getPrintDirection());
    }

    public function testCodetagCount(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertSame(73, $figlet->getCodetagCount());
    }

    public function testBaselineParsed(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertSame(16, $figlet->getBaseline());
    }

    public function testGetLoadedCodepoints(): void
    {
        $figlet = $this->loadedFiglet();
        $codepoints = $figlet->getLoadedCodepoints();
        $this->assertContains(ord('A'), $codepoints);
        $this->assertContains(ord('z'), $codepoints);
        $this->assertSame($codepoints, array_unique($codepoints));
        $sorted = $codepoints;
        sort($sorted);
        $this->assertSame($sorted, $codepoints);
    }

    public function testGetLoadedCodepointsContainsFullAsciiRange(): void
    {
        $font = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($font);
        $codepoints = $figlet->getLoadedCodepoints();
        for ($i = 32; $i < 127; $i++) {
            $this->assertContains($i, $codepoints, "Missing ASCII codepoint $i");
        }
    }

    public function testGetLoadedCodepointsIncludesGermanSlots(): void
    {
        $font = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($font);
        $codepoints = $figlet->getLoadedCodepoints();
        foreach ([196, 214, 220, 228, 246, 252, 223] as $gc) {
            $this->assertContains($gc, $codepoints, "Missing German codepoint $gc");
        }
    }

    public function testGetLoadedCodepointsIncludesExtendedChars(): void
    {
        $font = $this->writeTempFile($this->buildSimpleFont(
            codeTags: [['code' => 0x0410, 'glyph' => 'A']],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($font);
        $this->assertContains(0x0410, $figlet->getLoadedCodepoints());
    }

    public function testGetLoadedCodepointsResetOnReload(): void
    {
        $font1 = $this->writeTempFile($this->buildSimpleFont(
            codeTags: [['code' => 0x0410, 'glyph' => 'A']],
        ), '.flf');
        $font2 = $this->writeTempFile($this->buildSimpleFont(), '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($font1);
        $this->assertContains(0x0410, $figlet->getLoadedCodepoints());

        $figlet->loadFont($font2);
        $this->assertNotContains(0x0410, $figlet->getLoadedCodepoints());
    }

    public function testGetCharWidth(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertIsInt($figlet->getCharWidth(ord('A')));
        $this->assertGreaterThan(0, $figlet->getCharWidth(ord('A')));
        $this->assertNull($figlet->getCharWidth(0x10FFFF));
    }

    public function testGetCharWidthReflectsGlyphSize(): void
    {
        $font = $this->writeTempFile($this->buildSimpleFont(
            glyphs: [ord('W') => 'WIDE'],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($font);
        $this->assertSame(4, $figlet->getCharWidth(ord('W')));
        $this->assertSame(1, $figlet->getCharWidth(ord('A')));
    }

    public function testGetCharWidthGermanSlots(): void
    {
        $font = $this->writeTempFile($this->buildSimpleFont(
            glyphs: [223 => 'SS'],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($font);
        $this->assertSame(2, $figlet->getCharWidth(223));
    }

    public function testGetCharWidthNullAfterReloadWithoutChar(): void
    {
        $font1 = $this->writeTempFile($this->buildSimpleFont(
            codeTags: [['code' => 0x4E00, 'glyph' => 'HAN']],
        ), '.flf');
        $font2 = $this->writeTempFile($this->buildSimpleFont(), '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($font1);
        $this->assertSame(3, $figlet->getCharWidth(0x4E00));

        $figlet->loadFont($font2);
        $this->assertNull($figlet->getCharWidth(0x4E00));
    }

    public function testLoadControlFileAndClearControlFiles(): void
    {
        $font = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $control = $this->writeTempFile("t A B\n", '.flc');

        $figlet = new Figlet();
        $figlet->loadFont($font);

        $plainA = $figlet->render('A');
        $plainB = $figlet->render('B');

        $this->assertSame($figlet, $figlet->loadControlFile($control));
        $this->assertSame($plainB, $figlet->render('A'));

        $this->assertSame($figlet, $figlet->clearControlFiles());
        $this->assertSame($plainA, $figlet->render('A'));
    }

    public function testLoadBundledControlFileByNameWithoutPath(): void
    {
        $font = $this->writeTempFile($this->buildSimpleFont(
            codeTags: [['code' => 0x03C2, 'glyph' => 'S']],
        ), '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($font);
        $figlet->loadControlFile('frango');

        $this->assertSame("S\n", $figlet->render('V'));
    }

    public function testBundledHzControlFileChangesInputDecoding(): void
    {
        $font = $this->writeTempFile($this->buildSimpleFont(
            codeTags: [['code' => 16706, 'glyph' => 'X']],
        ), '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($font);
        $figlet->loadControlFile('hz');

        $this->assertSame("X\n", $figlet->render("~{AB~}"));
    }

    public function testBundledGb16fsFontRendersHzInput(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont($this->fontPath('gb16fs.flf'));
        $figlet->loadControlFile('hz');

        $this->assertNotSame('', trim($figlet->render("~{AB~}")));
    }

    #[RequiresPhpExtension('zlib')]
    public function testLoadGzipCompressedFont(): void
    {
        $font = $this->buildSimpleFont();
        $plainPath = $this->writeTempFile($font, '.flf');
        $encoded = gzencode($font);
        self::assertNotFalse($encoded);
        $gzPath = $this->writeTempFile($encoded, '.flf.gz');

        $plain = new Figlet();
        $plain->loadFont($plainPath);

        $compressed = new Figlet();
        $compressed->loadFont($gzPath);

        $this->assertSame($plain->render('AB'), $compressed->render('AB'));
    }

    #[RequiresPhpExtension('zlib')]
    public function testGzipFontDetectedByMagicBytesNotExtension(): void
    {
        $font = $this->buildSimpleFont();
        $plainPath = $this->writeTempFile($font, '.flf');
        $encoded = gzencode($font);
        self::assertNotFalse($encoded);
        $noExtPath = $this->writeTempFile($encoded, '.flf');

        $plain = new Figlet();
        $plain->loadFont($plainPath);

        $compressed = new Figlet();
        $compressed->loadFont($noExtPath);

        $this->assertSame($plain->render('AB'), $compressed->render('AB'));
    }

    #[RequiresPhpExtension('zip')]
    public function testLoadZipCompressedFont(): void
    {
        $font = $this->buildSimpleFont();
        $plainPath = $this->writeTempFile($font, '.flf');
        $zipPath = $this->writeZipArchive($font);

        $plain = new Figlet();
        $plain->loadFont($plainPath);

        $compressed = new Figlet();
        $compressed->loadFont($zipPath);

        $this->assertSame($plain->render('AB'), $compressed->render('AB'));
    }

    #[RequiresPhpExtension('zip')]
    public function testEmptyZipArchiveThrows(): void
    {
        $zipPath = $this->writeTempFile("PK\x05\x06" . str_repeat("\x00", 18), '.zip');

        $this->expectException(FontLoadException::class);
        $this->expectExceptionMessage('ZIP archive is empty');

        $figlet = new Figlet();
        $figlet->loadFont($zipPath);
    }

    #[RequiresPhpExtension('zip')]
    public function testInvalidZipArchiveThrows(): void
    {
        $zipPath = $this->writeTempFile('PKxx', '.zip');

        $this->expectException(FontLoadException::class);
        $this->expectExceptionMessage('Cannot open figlet font file');

        $figlet = new Figlet();
        $figlet->loadFont($zipPath);
    }

    public function testLoadFontWithoutGermanCharactersSkipsGermanBlock(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(glyphs: [196 => 'A']), '.flf');

        $withGerman = new Figlet();
        $withGerman->loadFont($fontPath);

        $withoutGerman = new Figlet();
        $withoutGerman->loadFont($fontPath, false);

        $this->assertSame("A\n", $withGerman->render('Ä'));
        $this->assertSame("\n", $withoutGerman->render('Ä'));
    }

    public function testDerivedFittingLayoutFromOldLayoutZero(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(oldLayout: 0), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame(LayoutMode::Fitting, $figlet->getHorizontalLayout());
    }

    public function testDerivedVerticalFittingLayoutFromFullLayout(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(fullLayout: 8192), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame(LayoutMode::Fitting, $figlet->getVerticalLayout());
    }

    public function testDerivedHorizontalFittingLayoutFromFullLayout(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(fullLayout: 64), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame(LayoutMode::Fitting, $figlet->getHorizontalLayout());
    }

    public function testCodetagsSupportDecimalHexAndOctalFormats(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            codeTags: [
                ['code' => '0101', 'glyph' => 'O'],
                ['code' => '233', 'glyph' => 'E'],
                ['code' => '0x2603', 'glyph' => 'S'],
                ['code' => '128640', 'glyph' => 'R'],
            ],
        ), '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame("O\n", $figlet->render('A'));
        $this->assertSame("E\n", $figlet->render('é'));
        $this->assertSame("S\n", $figlet->render('☃'));
        $this->assertSame("R\n", $figlet->render('🚀'));
    }

    public function testNegativeHexCodetagIsSkipped(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            codeTags: [
                ['code' => '-0x2603', 'glyph' => 'X'],
            ],
        ), '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame("\n", $figlet->render('☃'));
    }

    public function testBlankCodetagLineIsIgnored(): void
    {
        $font = $this->buildSimpleFont() . "\n0x2603\nS~\n";
        $fontPath = $this->writeTempFile($font, '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame("S\n", $figlet->render('☃'));
    }

    public function testTruncatedFontDoesNotCrash(): void
    {
        $fontPath = $this->writeTempFile("flf2a$ 1 1 1 -1 0 0\nA~\n", '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame("\n", $figlet->render('A'));
    }

    // --- Layout mode derivation ---

    public function testFullWidthLayoutFromOldLayout(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertSame(LayoutMode::FullSize, $figlet->getHorizontalLayout());
    }

    public function testSmushingLayoutFromFullLayout(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $this->assertSame(LayoutMode::Smushing, $figlet->getHorizontalLayout());
    }

    public function testUniversalSmushingLayout(): void
    {
        $figlet = $this->fixturedFiglet('universal.flf');
        $this->assertSame(LayoutMode::Smushing, $figlet->getHorizontalLayout());
    }

    public function testSmushingLayoutFromPositiveOldLayout(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(oldLayout: 1), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame(LayoutMode::Smushing, $figlet->getHorizontalLayout());
    }

    public function testVerticalSmushingLayoutFromFullLayout(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(fullLayout: 16384), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame(LayoutMode::Smushing, $figlet->getVerticalLayout());
    }

    // --- Layout mode override ---

    public function testSetHorizontalLayout(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertSame(LayoutMode::FullSize, $figlet->getHorizontalLayout());

        $figlet->setHorizontalLayout(LayoutMode::Fitting);
        $this->assertSame(LayoutMode::Fitting, $figlet->getHorizontalLayout());

        $figlet->setHorizontalLayout(LayoutMode::Smushing);
        $this->assertSame(LayoutMode::Smushing, $figlet->getHorizontalLayout());
    }

    public function testSetVerticalLayout(): void
    {
        $figlet = $this->loadedFiglet();
        $figlet->setVerticalLayout(LayoutMode::Fitting);
        $this->assertSame(LayoutMode::Fitting, $figlet->getVerticalLayout());
    }

    public function testLayoutOverrideChangesOutput(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');

        $smushed = $figlet->render('AB');
        $figlet->setHorizontalLayout(LayoutMode::FullSize);
        $fullSize = $figlet->render('AB');

        $smushedWidth = max(array_map(strlen(...), explode("\n", $smushed)));
        $fullSizeWidth = max(array_map(strlen(...), explode("\n", $fullSize)));

        $this->assertGreaterThan($smushedWidth, $fullSizeWidth);
    }

    /**
     * Regression: oldLayout=32 without fullLayout must yield universal smushing
     * (hSmushRules=0), not hardblank-only (hSmushRules=32).
     * figlet.c masks with &31, dropping bit 5.
     */
    public function testOldLayout32YieldsUniversalSmushing(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(oldLayout: 32), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame(LayoutMode::Smushing, $figlet->getHorizontalLayout());
        $this->assertSame(0, $this->getFigletProperty($figlet, 'hSmushRules'));
    }

    /**
     * Regression: negative fullLayout (e.g. -2) must be treated as unsigned
     * bitmask, matching figlet.c's two's complement behavior.
     */
    public function testNegativeFullLayoutTreatedAsUnsignedBitmask(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(oldLayout: -1, fullLayout: -2), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame(LayoutMode::Smushing, $figlet->getHorizontalLayout());
        $this->assertSame(62, $this->getFigletProperty($figlet, 'hSmushRules'));
    }

    /**
     * Regression: double.flf has fullLayout=-2; must match figlet output.
     */
    public function testDoubleFlf(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont(__DIR__ . '/../fonts/double.flf');
        $lines = explode("\n", $figlet->render('Hi'));

        $this->assertSame(LayoutMode::Smushing, $figlet->getHorizontalLayout());
        $this->assertSame('__  ____', rtrim($lines[0]));
    }

    /**
     * Regression: colossal.flf has oldLayout=32 (no fullLayout).
     * With the correct &31 mask, "Hi" row 0 is 13 chars wide (universal smushing).
     * The old &63 mask gave 14 (hardblank-only prevented overlap).
     */
    public function testColossalSmushingRegression(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont(__DIR__ . '/../fonts/colossal.flf');
        $lines = explode("\n", $figlet->render('Hi'));

        $this->assertSame(13, strlen(rtrim($lines[0])));
    }

    /**
     * Regression: .flf fonts with Latin-1 box-drawing characters must be
     * properly converted to UTF-8, not double-encoded (which produces â).
     */
    public function testLatin1ToUtf8EncodingRegression(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont(__DIR__ . '/../fonts/tubes-regular.flf');
        $out = $figlet->render('H');

        $this->assertStringNotContainsString('â', $out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    /**
     * Regression: blank-row off-by-one in calcSmushAmount.
     * eftichess H and e are 0-width; the first real char (l) must not
     * over-smush by 1 column into the empty output.
     */
    public function testBlankRowSmushAmountRegression(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont(__DIR__ . '/../fonts/eftichess.flf');
        $lines = explode("\n", $figlet->render('llo'));

        $this->assertSame(27, strlen(rtrim($lines[0])));
    }

    /**
     * Regression: gb16fs uses GB2312 BDF code tags, not Unicode.
     * Unicode input must be converted to GB2312 codes for glyph lookup.
     */
    public function testGb2312UnicodeToCodeTagMapping(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont(__DIR__ . '/../fonts/gb16fs.flf');
        $out = $figlet->render('中');

        $this->assertNotSame('', trim($out));
        $lines = explode("\n", rtrim($out, "\n"));
        $this->assertSame(16, count($lines));
    }

    public function testGb2312AsciiPassthrough(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont(__DIR__ . '/../fonts/gb16fs.flf');
        $out = $figlet->render('A');

        $this->assertNotSame('', trim($out));
        $lines = explode("\n", rtrim($out, "\n"));
        $this->assertSame(16, count($lines));
    }

    public function testGb2312MultipleCjkChars(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont(__DIR__ . '/../fonts/gb16fs.flf');

        $single = $figlet->render('中');
        $singleWidth = mb_strlen(explode("\n", $single)[0]);

        $double = $figlet->render('中国');
        $doubleWidth = mb_strlen(explode("\n", $double)[0]);

        $this->assertGreaterThan($singleWidth, $doubleWidth);
        $this->assertNotSame('', trim($double));
    }

    public function testGb2312UnknownCharFallback(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont(__DIR__ . '/../fonts/gb16fs.flf');
        $out = $figlet->render("\u{0410}");

        $lines = explode("\n", rtrim($out, "\n"));
        $this->assertSame(16, count($lines));
    }

    // --- Basic rendering ---

    public function testRenderAscii(): void
    {
        $figlet = $this->loadedFiglet();
        $result = $figlet->render('Hi');
        $this->assertNotEmpty($result);
        $lines = explode("\n", $result);
        $this->assertCount(17, $lines);
    }

    public function testRenderSingleChar(): void
    {
        $figlet = $this->loadedFiglet();
        $first = $figlet->render('A');
        $second = $figlet->render('A');
        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
        $this->assertStringNotContainsString("\x00", $first);
    }

    public function testFontComment(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertNotEmpty($figlet->fontComment);
        $this->assertStringContainsString('Makisupa', $figlet->fontComment);
    }

    public function testHtmlOutput(): void
    {
        $figlet = $this->loadedFiglet();
        $result = $figlet->render('A', ExportFormat::Html);
        $this->assertStringStartsWith('<nobr>', $result);
        $this->assertStringEndsWith('</nobr>', $result);
        $this->assertStringContainsString('&nbsp;', $result);
    }

    public function testHtml3Output(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $result = $figlet->render('A', ExportFormat::Html3);
        $this->assertStringStartsWith('<table ', $result);
        $this->assertStringContainsString('<tr><td', $result);
        $this->assertStringContainsString('<tt>', $result);
        $this->assertStringContainsString('</tt></td></tr>', $result);
        $this->assertStringContainsString('&#160;', $result);
        $this->assertStringEndsWith("</table>\n", $result);
    }

    public function testHtml3ColspanForPlainRow(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['ABCDE'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 5]);

        $html = $figlet->render('A', ExportFormat::Html3);
        $this->assertMatchesRegularExpression('/colspan="5"/', $html);
    }

    public function testHtml3ColspanForColoredRow(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([
                new Cell('A', null, 1),
                new Cell('B', null, 1),
                new Cell('C', null, 1),
                new Cell('D', null, 2),
                new Cell('E', null, 2),
            ])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 5]);

        $html = $figlet->render('A', ExportFormat::Html3);
        $this->assertMatchesRegularExpression('/colspan="3"/', $html);
        $this->assertMatchesRegularExpression('/colspan="2"/', $html);
    }

    public function testHtml3WithRainbow(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Rainbow);

        $result = $figlet->render('A', ExportFormat::Html3);
        $this->assertStringContainsString('<font color="', $result);
        $this->assertStringContainsString('</font>', $result);
        $this->assertStringNotContainsString("\e[", $result);
    }

    public function testHtmlWithRainbow(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Rainbow);

        $result = $figlet->render('A', ExportFormat::Html);
        $this->assertStringContainsString('<span style="color:', $result);
        $this->assertStringContainsString('</span>', $result);
        $this->assertStringNotContainsString("\e[", $result);
    }

    public function testHtml3NonAsciiEntities(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $result = $figlet->render('A', ExportFormat::Html3);
        $this->assertMatchesRegularExpression('/&#\d+;/', $result);
    }

    public function testExportFormatTextIsDefault(): void
    {
        $figlet = $this->loadedFiglet();
        $plain = $figlet->render('A');
        $explicit = $figlet->render('A', ExportFormat::Text);
        $this->assertSame($plain, $explicit);
    }

    public function testUtf8Support(): void
    {
        $figlet = $this->loadedFiglet();
        $ascii = $figlet->render('A');
        $legacy = $figlet->render('%u0041');
        $this->assertSame($ascii, $legacy);
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $figlet = $this->loadedFiglet();
        $result = $figlet->render('');
        $this->assertSame('', $result);
    }

    public function testMultipleCharsProduceLongerOutput(): void
    {
        $figlet = $this->loadedFiglet();
        $one = $figlet->render('A');
        $two = $figlet->render('AB');
        $oneWidth = max(array_map(strlen(...), explode("\n", $one)));
        $twoWidth = max(array_map(strlen(...), explode("\n", $two)));
        $this->assertGreaterThan($oneWidth, $twoWidth);
    }

    public function testMissingCharacterFallback(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        // Character 999 doesn't exist, should not crash
        $result = $figlet->render("\xF0\x8F\xA7\x87");
        $this->assertSame($result, $result);
    }

    // --- Controlled smushing (6 rules) ---

    public function testControlledSmushingRule1EqualCharacter(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $result = $figlet->render('||');
        $this->assertNotEmpty($result);
    }

    public function testControlledSmushingRule2Underscore(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        // Rendering chars that produce _ next to | should smush
        $result = $figlet->render('_|');
        $this->assertNotEmpty($result);
    }

    public function testExactHorizontalRule8PairSmushing(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            fullLayout: 128 + 8,
            glyphs: [91 => ' [', 93 => '] '],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame('|', rtrim($figlet->render('[]')));
    }

    public function testExactHorizontalRule16BigXSmushing(): void
    {
        $slashFont = $this->writeTempFile($this->buildSimpleFont(
            fullLayout: 128 + 16,
            glyphs: [47 => ' /', 92 => '\\ '],
        ), '.flf');
        $slashFiglet = new Figlet();
        $slashFiglet->loadFont($slashFont);

        $reverseFont = $this->writeTempFile($this->buildSimpleFont(
            fullLayout: 128 + 16,
            glyphs: [47 => '/ ', 92 => ' \\', 62 => '> ', 60 => '< '],
        ), '.flf');
        $reverseFiglet = new Figlet();
        $reverseFiglet->loadFont($reverseFont);

        $this->assertSame('|', rtrim($slashFiglet->render('/\\')));
        $this->assertSame('Y', rtrim($reverseFiglet->render('\\/')));
        $this->assertSame('X', rtrim($reverseFiglet->render('><')));
    }

    public function testControlledSmushingReducesWidth(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $smushed = $figlet->render('AB');

        $figlet->setHorizontalLayout(LayoutMode::FullSize);
        $full = $figlet->render('AB');

        $smWidth = max(array_map(strlen(...), explode("\n", $smushed)));
        $fullWidth = max(array_map(strlen(...), explode("\n", $full)));

        $this->assertLessThanOrEqual($fullWidth, $smWidth);
    }

    // --- Universal smushing ---

    public function testUniversalSmushingOverlaps(): void
    {
        $figlet = $this->fixturedFiglet('universal.flf');
        $result = $figlet->render('AB');

        $figlet->setHorizontalLayout(LayoutMode::FullSize);
        $full = $figlet->render('AB');

        $smWidth = max(array_map(strlen(...), explode("\n", $result)));
        $fullWidth = max(array_map(strlen(...), explode("\n", $full)));

        $this->assertLessThan($fullWidth, $smWidth);
    }

    // --- Vertical operations ---

    public function testVerticalFullSize(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setVerticalLayout(LayoutMode::FullSize);
        $result = $figlet->render("A\nB");
        $lines = explode("\n", $result);
        // Full size: 2 * height lines + trailing newline
        $this->assertCount(5, $lines);
    }

    public function testVerticalFitting(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setVerticalLayout(LayoutMode::Fitting);
        $fullSize = $figlet->render("A\nB");
        $figlet->setVerticalLayout(LayoutMode::FullSize);
        $full = $figlet->render("A\nB");

        $fittingLines = count(explode("\n", $fullSize));
        $fullLines = count(explode("\n", $full));

        $this->assertLessThanOrEqual($fullLines, $fittingLines);
    }

    public function testVerticalSmushingDashUnderscore(): void
    {
        $figlet = $this->fixturedFiglet('vertical.flf');
        // This font has vertical smushing rules 1+4 (equal char + horizontal line)
        $result = $figlet->render("-\n_");
        // Stacked '-' and '_' should produce '='
        $this->assertStringContainsString('=', $result);
    }

    public function testVerticalSmushingEqualChar(): void
    {
        $figlet = $this->fixturedFiglet('vertical.flf');
        $result = $figlet->render("|\n|");
        $lines = explode("\n", $result);
        // Equal chars should smush, reducing total height
        $this->assertLessThan(5, count($lines));
    }

    public function testExactVerticalRule2UnderscoreSmushing(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(fullLayout: 16384 + (2 << 8)), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame("|\n", $figlet->render("_\n|"));
    }

    public function testExactVerticalRule3HierarchySmushing(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(fullLayout: 16384 + (4 << 8)), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame("<\n", $figlet->render("(\n<"));
    }

    public function testExactVerticalRule5Smushing(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(fullLayout: 16384 + (16 << 8)), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame("|\n", $figlet->render("|\n|"));
    }

    public function testVerticalSupersmushingContinuesBeyondOneRow(): void
    {
        $height = 4;
        $fullLayout = 16384 | 4096;
        $header = "flf2a\$ $height $height 6 -1 0 0 $fullLayout 0";
        $lines = [$header];

        for ($code = 32; $code < 127; $code++) {
            for ($row = 0; $row < $height; $row++) {
                $endmark = ($row === $height - 1) ? '~~' : '~';
                if ($code === 124) {
                    $lines[] = '| ' . $endmark;
                } elseif ($code === 32) {
                    $lines[] = '$ ' . $endmark;
                } else {
                    $lines[] = ($row === 0 ? chr($code) : ' ') . ' ' . $endmark;
                }
            }
        }

        for ($i = 0; $i < 7; $i++) {
            for ($row = 0; $row < $height; $row++) {
                $lines[] = ($row === $height - 1) ? '~~' : '~';
            }
        }

        $fontPath = $this->writeTempFile(implode("\n", $lines) . "\n", '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $result = $figlet->render("|\n|");
        $resultLines = explode("\n", rtrim($result, "\n"));

        $this->assertCount($height, $resultLines, 'Supersmushing should merge all | rows');
    }

    // --- Word wrapping ---

    public function testWordWrap(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setWidth(10);
        $result = $figlet->render('Hello World');
        $lines = explode("\n", $result);
        // Should have wrapped into multiple FIGures (more than height lines)
        $this->assertGreaterThan(2, count($lines));
    }

    public function testNoWordWrapWhenWidthSufficient(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setWidth(1000);
        $result = $figlet->render('Hi');
        $lines = explode("\n", $result);
        $this->assertCount(3, $lines);
    }

    public function testWordWrapPreservesLeadingBlanks(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setWidth(20);
        $leading = $figlet->render(' A');
        $noLeading = $figlet->render('A');
        // Leading space should make output wider
        $leadWidth = max(array_map(strlen(...), explode("\n", $leading)));
        $noLeadWidth = max(array_map(strlen(...), explode("\n", $noLeading)));
        $this->assertGreaterThanOrEqual($noLeadWidth, $leadWidth);
    }

    public function testWordWrapLongWordWithoutSpaces(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->setWidth(3);

        $this->assertCount(3, explode("\n", $figlet->render('ABCD')));
    }

    // --- Paragraph mode ---

    public function testParagraphModeSingleNewlineBecomesSpace(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setParagraphMode(true);

        $withNewline = $figlet->render("A\nB");
        $withSpace = $figlet->render("A B");

        $this->assertSame($withSpace, $withNewline);
    }

    public function testParagraphModeDoubleNewlineStays(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setParagraphMode(true);

        $result = $figlet->render("A\n\nB");
        $lines = explode("\n", $result);
        // Double newline stays → two separate FIGures stacked
        $this->assertGreaterThan(2, count($lines));
    }

    public function testParagraphModeTrailingNewlineIsPreserved(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');

        // applyParagraphMode: newline at end of input (i + 1 >= len) → kept as newline
        $result = $this->invokeFigletMethod($figlet, 'applyParagraphMode', "A\n");
        $this->assertSame("A\n", $result);
    }

    public function testParagraphModeNewlineBeforeSpaceIsPreserved(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');

        // applyParagraphMode: newline followed by ' ' → kept as newline (not space)
        $result = $this->invokeFigletMethod($figlet, 'applyParagraphMode', "A\n B");
        $this->assertSame("A\n B", $result);
    }

    public function testParagraphModeNewlineBeforeNewlineIsPreserved(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');

        // applyParagraphMode: newline where next char is also newline → kept as newline
        $result = $this->invokeFigletMethod($figlet, 'applyParagraphMode', "A\n\nB");
        $this->assertSame("A\n\nB", $result);
    }

    // --- Justification ---

    public function testJustificationLeft(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setWidth(40)->setJustification(Justification::Left);
        $result = $figlet->render('A');
        $lines = explode("\n", $result);
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                // Left-justified: no leading spaces
                $this->assertSame(ltrim($line), $line);
            }
        }
    }

    public function testJustificationRight(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setWidth(40)->setJustification(Justification::Right);
        $result = $figlet->render('A');
        $lines = explode("\n", $result);
        $hasRightPadding = false;
        foreach ($lines as $line) {
            if (trim($line) !== '' && $line !== ltrim($line)) {
                $hasRightPadding = true;
                break;
            }
        }
        $this->assertTrue($hasRightPadding);
    }

    public function testJustificationCenter(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setWidth(40)->setJustification(Justification::Center);
        $result = $figlet->render('A');
        $lines = explode("\n", $result);
        $hasCenterPadding = false;
        foreach ($lines as $line) {
            if (trim($line) !== '' && $line !== ltrim($line)) {
                $hasCenterPadding = true;
                break;
            }
        }
        $this->assertTrue($hasCenterPadding);
    }

    public function testJustificationAutoLTR(): void
    {
        $figlet = $this->fixturedFiglet('smushing.flf');
        $figlet->setWidth(40)->setJustification(Justification::Auto);
        $result = $figlet->render('A');
        $lines = explode("\n", $result);
        // LTR font → flush left
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $this->assertSame(ltrim($line), $line);
            }
        }
    }

    public function testJustificationAutoRTL(): void
    {
        $figlet = $this->fixturedFiglet('rtl.flf');
        $figlet->setWidth(40)->setJustification(Justification::Auto);
        $result = $figlet->render('A');
        $lines = explode("\n", $result);
        // RTL font → flush right → should have leading spaces
        $hasLeadingSpaces = false;
        foreach ($lines as $line) {
            if (trim($line) !== '' && $line !== ltrim($line)) {
                $hasLeadingSpaces = true;
                break;
            }
        }
        $this->assertTrue($hasLeadingSpaces);
    }

    public function testJustificationDoesNotPadWhenFigureExceedsWidth(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(glyphs: [65 => 'AA']), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->setWidth(1)->setJustification(Justification::Right);

        $this->assertSame("AA\n", $figlet->render('A'));
    }

    // --- RTL ---

    public function testRtlFont(): void
    {
        $figlet = $this->fixturedFiglet('rtl.flf');
        $abResult = $figlet->render('AB');
        $baResult = $figlet->render('BA');
        $this->assertNotSame($abResult, $baResult);
    }

    // --- Internal helpers ---

    public function testInternalSmushemBranches(): void
    {
        $eng = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);

        $this->assertSame('A', $eng->smushem(' ', 'A', 2, 2));
        $this->assertSame('A', $eng->smushem('A', ' ', 2, 2));
        $this->assertNull($eng->smushem('A', 'B', 1, 2));

        $engFit = new SmushEngine('$', 0, 0, 0, LayoutMode::Fitting);
        $this->assertNull($engFit->smushem('A', 'B', 2, 2));

        $engR0 = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);
        $this->assertSame('A', $engR0->smushem('$', 'A', 2, 2));
        $this->assertSame('A', $engR0->smushem('A', '$', 2, 2));
        $this->assertSame('B', $engR0->smushem('A', 'B', 2, 2));

        $engRtl = new SmushEngine('$', 0, 0, 1, LayoutMode::Smushing);
        $this->assertSame('A', $engRtl->smushem('A', 'B', 2, 2));

        $eng32 = new SmushEngine('$', 32, 0, 1, LayoutMode::Smushing);
        $this->assertSame('$', $eng32->smushem('$', '$', 2, 2));

        $eng4 = new SmushEngine('$', 4, 0, 0, LayoutMode::Smushing);
        $this->assertSame('{', $eng4->smushem(']', '{', 2, 2));
        $this->assertSame('{', $eng4->smushem('{', '[', 2, 2));
        $this->assertSame('<', $eng4->smushem('(', '<', 2, 2));
        $this->assertSame('<', $eng4->smushem('<', '(', 2, 2));

        $eng8 = new SmushEngine('$', 8, 0, 0, LayoutMode::Smushing);
        $this->assertSame('|', $eng8->smushem(']', '[', 2, 2));
        $this->assertSame('|', $eng8->smushem('}', '{', 2, 2));
        $this->assertSame('|', $eng8->smushem(')', '(', 2, 2));
    }

    public function testInternalCalcSmushAmountAndAddCharToOutputRtl(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'hLayout', LayoutMode::Smushing);
        $this->setFigletProperty($figlet, 'hSmushRules', 16);
        $this->setFigletProperty($figlet, 'printDirection', 1);

        $eng = new SmushEngine('$', 16, 0, 1, LayoutMode::Smushing);

        $this->assertSame(
            0,
            $this->invokeFigletMethod($figlet, 'calcSmushAmount', $eng, $this->stringsToRows(['AA']), $this->stringsToRows(['BB']), 2, 2, 2, LayoutMode::FullSize),
        );
        $this->assertSame(
            1,
            $this->invokeFigletMethod($figlet, 'calcSmushAmount', $eng, $this->stringsToRows(['\\ ']), $this->stringsToRows([' /']), 2, 2, 2, LayoutMode::Smushing),
        );
        $this->assertSame(
            2,
            $this->invokeFigletMethod($figlet, 'calcSmushAmount', $eng, $this->stringsToRows([' A']), $this->stringsToRows(['  ']), 2, 2, 2, LayoutMode::Fitting),
        );

        $this->assertSame(
            [' | '],
            $this->rowsToStrings($this->invokeRowListMethod($figlet, 'addCharToOutput', $eng, $this->stringsToRows(['\\ ']), $this->stringsToRows([' /']), 2, 2, 1)),
        );
    }

    public function testInternalVSmushCharBranches(): void
    {
        $eng0 = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);
        $this->assertSame('B', $eng0->vSmushChar(' ', 'B'));
        $this->assertSame('A', $eng0->vSmushChar('A', ' '));
        $this->assertSame('B', $eng0->vSmushChar('A', 'B'));

        $eng1 = new SmushEngine('$', 0, 1, 0, LayoutMode::Smushing);
        $this->assertSame('|', $eng1->vSmushChar('|', '|'));

        $eng2 = new SmushEngine('$', 0, 2, 0, LayoutMode::Smushing);
        $this->assertSame('|', $eng2->vSmushChar('|', '_'));

        $eng4 = new SmushEngine('$', 0, 4, 0, LayoutMode::Smushing);
        $this->assertSame('<', $eng4->vSmushChar('(', '<'));
        $this->assertSame('<', $eng4->vSmushChar('<', '('));

        $eng8 = new SmushEngine('$', 0, 8, 0, LayoutMode::Smushing);
        $this->assertSame('=', $eng8->vSmushChar('_', '-'));

        $eng16 = new SmushEngine('$', 0, 16, 0, LayoutMode::Smushing);
        $this->assertSame('|', $eng16->vSmushChar('|', '|'));

        $eng2 = new SmushEngine('$', 0, 2, 0, LayoutMode::Smushing);
        $this->assertNull($eng2->vSmushChar('A', 'B'));
    }

    public function testInternalAllEmptyAndVerticalMergeBranches(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'vSmushRules', 0);

        $eng = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);

        $this->assertTrue($this->invokeFigletMethod($figlet, 'allEmpty', [$this->stringsToRows([' ', '']), $this->stringsToRows(['   '])]));
        $this->assertFalse($this->invokeFigletMethod($figlet, 'allEmpty', [$this->stringsToRows(['X'])]));

        $this->assertSame(
            ['$'],
            $this->rowsToStrings($this->invokeRowListMethod($figlet, 'combineFiguresVertically', $eng, $this->stringsToRows(['$']), $this->stringsToRows([' ']), LayoutMode::Fitting)),
        );
        $this->assertSame(
            ['A'],
            $this->rowsToStrings($this->invokeRowListMethod($figlet, 'combineFiguresVertically', $eng, $this->stringsToRows(['$']), $this->stringsToRows(['A']), LayoutMode::Fitting)),
        );
        $this->assertSame(
            ['A'],
            $this->rowsToStrings($this->invokeRowListMethod($figlet, 'combineFiguresVertically', $eng, $this->stringsToRows(['A']), $this->stringsToRows(['$']), LayoutMode::Fitting)),
        );

        $engV2 = new SmushEngine('$', 0, 2, 0, LayoutMode::Smushing);
        $this->assertSame(
            ['A', 'B'],
            $this->rowsToStrings($this->invokeRowListMethod($figlet, 'combineFiguresVertically', $engV2, $this->stringsToRows(['A']), $this->stringsToRows(['B']), LayoutMode::Smushing)),
        );
    }

    public function testInternalRenderCodesFallbackAndEmptyResult(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'font', [0 => $this->stringsToRows(['?'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [0 => 1]);

        $eng = new SmushEngine('', 0, 0, 0, LayoutMode::FullSize);
        $this->assertSame(['?'], $this->rowsToStrings($this->invokeRowListMethod($figlet, 'renderCodes', $eng, [999])));

        $this->setFigletProperty($figlet, 'font', []);
        $this->setFigletProperty($figlet, 'fontCharWidths', []);
        $this->assertSame([''], $this->rowsToStrings($this->invokeRowListMethod($figlet, 'renderCodes', $eng, [999])));
    }

    public function testInternalRenderLineWithWrappingBranches(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'font', [
            32 => $this->stringsToRows([' ']),
            65 => $this->stringsToRows(['A']),
            66 => $this->stringsToRows(['B']),
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [
            32 => 1,
            65 => 1,
            66 => 1,
        ]);
        $this->setFigletProperty($figlet, 'outputWidth', 1);

        $eng = new SmushEngine('', 0, 0, 0, LayoutMode::FullSize);
        $raw1 = $this->invokeFigletMethod($figlet, 'renderLineWithWrapping', $eng, ' A');
        self::assertIsArray($raw1);
        /** @var list<list<Row>> $raw1 */
        $result1 = array_map($this->rowsToStrings(...), $raw1);
        $this->assertSame([['A']], $result1);
        $raw2 = $this->invokeFigletMethod($figlet, 'renderLineWithWrapping', $eng, 'A   B');
        self::assertIsArray($raw2);
        /** @var list<list<Row>> $raw2 */
        $result2 = array_map($this->rowsToStrings(...), $raw2);
        $this->assertSame([['A'], ['B']], $result2);
    }

    // --- TLF (TOIlet) font support ---

    public function testLoadTlfFont(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $this->assertSame(3, $figlet->getHeight());
        $this->assertSame(3, $figlet->getBaseline());
        $this->assertSame(-1, $figlet->getOldLayout());
        $this->assertSame(0, $figlet->getPrintDirection());
    }

    public function testTlfFontByNameWithoutExtension(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont('emboss');
        $this->assertSame(3, $figlet->getHeight());
    }

    public function testTlfRenderSingleChar(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $result = $figlet->render('A');
        $lines = explode("\n", $result);
        $this->assertSame('┏━┃', $lines[0]);
        $this->assertSame('┏━┃', $lines[1]);
        $this->assertSame('┛ ┛', $lines[2]);
    }

    public function testTlfRenderMultipleChars(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $result = $figlet->render('Hi');
        $lines = explode("\n", $result);
        $this->assertSame('┃ ┃┛', $lines[0]);
        $this->assertSame('┏━┃┃', $lines[1]);
        $this->assertSame('┛ ┛┛', $lines[2]);
    }

    public function testTlfRenderHello(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $result = $figlet->render('Hello');
        $lines = explode("\n", $result);
        $this->assertSame('┃ ┃┏━┛┃  ┃  ┏━┃', $lines[0]);
        $this->assertSame('┏━┃┏━┛┃  ┃  ┃ ┃', $lines[1]);
        $this->assertSame('┛ ┛━━┛━━┛━━┛━━┛', $lines[2]);
    }

    public function testTlfWidthMeasuredInCharactersNotBytes(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $figlet->setWidth(20);
        $result = $figlet->render('Hello World');
        $lines = explode("\n", $result);
        $maxWidth = 0;
        foreach ($lines as $line) {
            $maxWidth = max($maxWidth, mb_strlen($line, 'UTF-8'));
        }
        $this->assertLessThanOrEqual(20, $maxWidth);
    }

    public function testTlfKerning(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $figlet->setHorizontalLayout(LayoutMode::Fitting);
        $result = $figlet->render('Hi');
        $lines = explode("\n", $result);
        $this->assertSame('┃ ┃┛', $lines[0]);
    }

    public function testTlfRtl(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $this->setFigletProperty($figlet, 'printDirection', 1);
        $result = $figlet->render('Hi');
        $lines = explode("\n", $result);
        $this->assertSame('┛┃ ┃', trim($lines[0]));
        $this->assertSame('┃┏━┃', trim($lines[1]));
        $this->assertSame('┛┛ ┛', trim($lines[2]));
        $this->assertSame(79, mb_strlen($lines[0]));
    }

    public function testTlfJustification(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $figlet->setWidth(20)->setJustification(Justification::Right);
        $result = $figlet->render('A');
        $lines = explode("\n", $result);
        $this->assertSame(20 - 1 - 3, mb_strlen($lines[0], 'UTF-8') - mb_strlen(ltrim($lines[0]), 'UTF-8'));
    }

    public function testTlfGermanChars(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $result = $figlet->render('%u00C4');
        $lines = explode("\n", $result);
        $this->assertSame('┏━┃┏━┛', $lines[0]);
        $this->assertSame('┏━┃┏━┛', $lines[1]);
        $this->assertSame('┛ ┛━━┛', $lines[2]);
    }

    // --- ISO 2022 control file support ---

    public function testIso2022ControlFileGCommands(): void
    {
        $cf = ControlFile::fromString("g0 94 J\ngL 0\n");
        $this->assertSame(Encoding::Iso2022, $cf->getEncoding());
    }

    public function testIso2022DefaultStateAscii(): void
    {
        $cf = ControlFile::fromString("g0 94 B\ngL 0\n");
        $result = $cf->apply('A');
        $this->assertNotEmpty($result);
    }

    public function testIso2022GlCharacterDecoding(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $result = $cf->apply('A');
        $this->assertSame('A', $result);
    }

    public function testIso2022ShiftOutShiftIn(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = chr(14) . 'A' . chr(15) . 'B';
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testIso2022JisControlFile(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont($this->fontPath('banner.flf'));
        $cf = ControlFile::load($this->fixturePath('jis0201.flc'));
        $stages = $cf->getStages();
        $this->assertNotEmpty($stages);
    }

    public function testIso2022JisControlFileMapsAsciiToJisRoman(): void
    {
        $cf = ControlFile::load($this->fixturePath('jis0201.flc'));
        $result = $cf->apply('A');
        $codes = [];
        $len = strlen($result);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($result[$i]);
            if ($byte < 0x80) {
                $codes[] = $byte;
            } elseif (($byte & 0xE0) === 0xC0) {
                $codes[] = (($byte & 0x1F) << 6) | (ord($result[++$i]) & 0x3F);
            } elseif (($byte & 0xF0) === 0xE0) {
                $codes[] = (($byte & 0x0F) << 12) | ((ord($result[++$i]) & 0x3F) << 6) | (ord($result[++$i]) & 0x3F);
            }
        }
        $this->assertSame([0x41], $codes);
    }

    public function testIso2022GrRangeDecoding(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = chr(0xC1);
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testIso2022Charset96(): void
    {
        $cf = ControlFile::fromString("g1 96 A\ngR 1\n");
        $this->assertSame(Encoding::Iso2022, $cf->getEncoding());
    }

    public function testIso2022Charset94x94(): void
    {
        $cf = ControlFile::fromString("g0 94x94 B\ngL 0\n");
        $this->assertSame(Encoding::Iso2022, $cf->getEncoding());
    }

    public function testIso2022EscSequenceCharsetDesignation(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = "\x1b(BA";
        $result = $cf->apply($input);
        $this->assertSame('A', $result);
    }

    public function testIso2022SingleShiftSS2(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = chr(142) . chr(0x41) . chr(0x42);
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    // --- Fluent API ---

    public function testFluentApi(): void
    {
        $figlet = new Figlet();
        $result = $figlet
            ->setWidth(80)
            ->setJustification(Justification::Center)
            ->setParagraphMode(true)
            ->setHorizontalLayout(LayoutMode::Smushing)
            ->setVerticalLayout(LayoutMode::Fitting);

        $this->assertInstanceOf(Figlet::class, $result);
    }

    // --- Additional coverage ---

    public function testLoadFontByBareNameResolvingToFlf(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont('standard');
        $this->assertGreaterThan(0, $figlet->getHeight());
    }

    public function testFontNotFoundByBareName(): void
    {
        $this->expectException(FontNotFoundException::class);
        $figlet = new Figlet();
        $figlet->loadFont('this_font_definitely_does_not_exist');
    }

    public function testLoadFontByPathWithoutExtensionFlf(): void
    {
        $path = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $pathWithoutExt = substr($path, 0, -4);

        $figlet = new Figlet();
        $figlet->loadFont($pathWithoutExt);
        $this->assertSame(1, $figlet->getHeight());
    }

    public function testLoadFontByPathWithoutExtensionTlf(): void
    {
        $source = file_get_contents($this->fontPath('emboss.tlf'));
        self::assertNotFalse($source);
        $path = $this->writeTempFile($source, '.tlf');
        $pathWithoutExt = substr($path, 0, -4);

        $figlet = new Figlet();
        $figlet->loadFont($pathWithoutExt);
        $this->assertSame(3, $figlet->getHeight());
    }

    public function testTlfSmushingUsesCSetAt(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $figlet->setHorizontalLayout(LayoutMode::Smushing);
        $smushed = $figlet->render('HH');
        $figlet->setHorizontalLayout(LayoutMode::FullSize);
        $full = $figlet->render('HH');

        $smushedWidth = max(array_map(static fn(string $l): int => mb_strlen($l, 'UTF-8'), explode("\n", $smushed)));
        $fullWidth = max(array_map(static fn(string $l): int => mb_strlen($l, 'UTF-8'), explode("\n", $full)));
        $this->assertLessThan($fullWidth, $smushedWidth);
    }

    public function testHorizontalRule2UnderscoreSmushingViaRender(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            fullLayout: 128 + 2,
            glyphs: [65 => 'A_', 66 => '|B'],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $this->assertSame('A|B', rtrim($figlet->render('AB')));
    }

    public function testVerticalCombineWithDifferentWidthsPads(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'vSmushRules', 0);

        $eng = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);
        $this->assertSame(
            ['C ', 'AB'],
            $this->rowsToStrings($this->invokeRowListMethod($figlet, 'combineFiguresVertically', $eng, $this->stringsToRows(['C']), $this->stringsToRows(['AB']), LayoutMode::Fitting)),
        );
        $this->assertSame(
            ['AB', 'C '],
            $this->rowsToStrings($this->invokeRowListMethod($figlet, 'combineFiguresVertically', $eng, $this->stringsToRows(['AB']), $this->stringsToRows(['C']), LayoutMode::Fitting)),
        );
    }

    // --- Filters ---

    public function testAddFilterReturnsSelf(): void
    {
        $figlet = new Figlet();
        $this->assertInstanceOf(Figlet::class, $figlet->addFilter(Filter::Crop));
    }

    public function testClearFiltersReturnsSelf(): void
    {
        $figlet = new Figlet();
        $figlet->addFilter(Filter::Crop);
        $this->assertInstanceOf(Figlet::class, $figlet->clearFilters());
    }

    public function testFilterCrop(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            glyphs: [65 => ' A '],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Crop);

        $result = $figlet->render('A');
        $this->assertSame("A\n", $result);
    }

    public function testFilterCropRemovesBlankRows(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 3);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['', 'A', ''])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);
        $figlet->addFilter(Filter::Crop);

        $this->assertSame("A\n", $figlet->render('A'));
    }

    public function testFilterCropAllBlank(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [32 => $this->stringsToRows(['   '])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [32 => 3]);
        $figlet->addFilter(Filter::Crop);

        $this->assertSame("\n", $figlet->render(' '));
    }

    public function testFilterFlip(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            glyphs: [65 => '(A>'],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Flip);

        $result = rtrim($figlet->render('A'));
        $this->assertSame('<A)', $result);
    }

    public function testFilterFlipMirrorsSlashes(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            glyphs: [65 => '/\\'],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Flip);

        $this->assertSame("/\\\n", $figlet->render('A'));
    }

    public function testFilterFlop(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 2);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['TOP', 'BOT'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 3]);
        $figlet->addFilter(Filter::Flop);

        $lines = explode("\n", $figlet->render('A'));
        $this->assertSame('BOT', $lines[0]);
        $this->assertSame('TOP', $lines[1]);
    }

    public function testFilterRotate180(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 2);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['(A', 'B)'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 2]);
        $figlet->addFilter(Filter::Rotate180);

        $lines = explode("\n", $figlet->render('A'));
        $this->assertSame('(B', $lines[0]);
        $this->assertSame('A)', $lines[1]);
    }

    public function testFilterBorder(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Border);

        $result = $figlet->render('A');
        $lines = explode("\n", $result);

        $this->assertStringStartsWith('┌', $lines[0]);
        $this->assertStringEndsWith('┐', $lines[0]);
        $this->assertStringStartsWith('│', $lines[1]);
        $this->assertStringEndsWith('│', $lines[1]);
        $nonEmpty = array_filter($lines, static fn(string $l): bool => $l !== '');
        $bottomLine = end($nonEmpty);
        $this->assertNotFalse($bottomLine);
        $this->assertStringStartsWith('└', $bottomLine);
        $this->assertStringEndsWith('┘', $bottomLine);
    }

    public function testFilterBorderDimensions(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            glyphs: [65 => 'AA'],
        ), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $plain = $figlet->render('A');
        $plainLines = explode("\n", rtrim($plain));
        $plainWidth = mb_strlen($plainLines[0], 'UTF-8');
        $plainHeight = count($plainLines);

        $figlet->addFilter(Filter::Border);
        $bordered = $figlet->render('A');
        $borderedLines = explode("\n", rtrim($bordered));
        $borderedWidth = mb_strlen($borderedLines[0], 'UTF-8');
        $borderedHeight = count($borderedLines);

        $this->assertSame($plainWidth + 2, $borderedWidth);
        $this->assertSame($plainHeight + 2, $borderedHeight);
    }

    public function testFilterRotateRightDimensions(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 2);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['AB', 'CD'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 2]);
        $figlet->addFilter(Filter::RotateRight);

        $lines = explode("\n", rtrim($figlet->render('A')));
        $this->assertCount(1, $lines);
        $this->assertSame(4, mb_strlen($lines[0], 'UTF-8'));
    }

    public function testFilterRotateRightPairBased(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['ABCD'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 4]);
        $figlet->addFilter(Filter::RotateRight);

        $lines = explode("\n", rtrim($figlet->render('A')));
        $this->assertCount(2, $lines);
        $this->assertSame(2, mb_strlen($lines[0], 'UTF-8'));
    }

    public function testFilterRotateLeftPairBased(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['ABCD'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 4]);
        $figlet->addFilter(Filter::RotateLeft);

        $lines = explode("\n", rtrim($figlet->render('A')));
        $this->assertCount(2, $lines);
        $this->assertSame(2, mb_strlen($lines[0], 'UTF-8'));
    }

    public function testFilterRotateMatchesToilet(): void
    {
        $figlet = new Figlet();
        $figlet->loadFont($this->fontPath('future.tlf'));

        $figlet->addFilter(Filter::RotateRight);
        $right = $figlet->render('Hi');
        $figlet->clearFilters();

        $figlet->addFilter(Filter::RotateLeft);
        $left = $figlet->render('Hi');

        $rightLines = explode("\n", rtrim($right));
        $leftLines = explode("\n", rtrim($left));

        $this->assertSame('╹ ┣━╻ ', $rightLines[0]);
        $this->assertSame('╹╹┫┃╻╻', $rightLines[1]);
        $this->assertSame('╻╻┫┃╹╹', $leftLines[0]);
        $this->assertSame('╻ ┣━╹', rtrim($leftLines[1]));
    }

    public function testFilterRainbow(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Rainbow);

        $result = $figlet->render('A');
        $this->assertStringContainsString("\e[", $result);
        $this->assertStringContainsString("\e[0m", $result);
    }

    public function testFilterRainbowSkipsSpaces(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [32 => $this->stringsToRows(['   '])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [32 => 3]);
        $figlet->addFilter(Filter::Rainbow);

        $result = rtrim($figlet->render(' '));
        $this->assertStringNotContainsString("\e[", $result);
    }

    public function testFilterMetal(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Metal);

        $result = $figlet->render('A');
        $this->assertStringContainsString("\e[", $result);
        $this->assertStringContainsString("\e[0m", $result);
    }

    public function testFilterMetalSkipsSpaces(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [32 => $this->stringsToRows(['   '])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [32 => 3]);
        $figlet->addFilter(Filter::Metal);

        $result = rtrim($figlet->render(' '));
        $this->assertStringNotContainsString("\e[", $result);
    }

    public function testFilterChaining(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $figlet->addFilter(Filter::Border);
        $bordered = $figlet->render('A');

        $figlet->addFilter(Filter::Flop);
        $borderedAndFlopped = $figlet->render('A');

        $borderedLines = explode("\n", rtrim($bordered));
        $floppedLines = explode("\n", rtrim($borderedAndFlopped));

        $this->assertStringStartsWith('┌', $borderedLines[0]);
        $this->assertStringStartsWith('┌', $floppedLines[0]);
    }

    public function testClearFiltersRemovesAllFilters(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $plain = $figlet->render('A');

        $figlet->addFilter(Filter::Border);
        $bordered = $figlet->render('A');
        $this->assertNotSame($plain, $bordered);

        $figlet->clearFilters();
        $cleared = $figlet->render('A');
        $this->assertSame($plain, $cleared);
    }

    public function testFilterWithTlfFont(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $figlet->addFilter(Filter::Flop);
        $result = $figlet->render('A');
        $lines = explode("\n", rtrim($result));
        $this->assertSame('┓ ┓', $lines[0]);
        $this->assertSame('┗━┃', $lines[1]);
        $this->assertSame('┗━┃', $lines[2]);
    }

    public function testFilterFlipWithTlfFont(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $plain = $figlet->render('A');
        $plainLines = explode("\n", rtrim($plain));

        $figlet->addFilter(Filter::Flip);
        $result = $figlet->render('A');
        $lines = explode("\n", rtrim($result));

        $this->assertCount(count($plainLines), $lines);
        foreach ($lines as $i => $line) {
            $this->assertSame(
                mb_strlen($plainLines[$i], 'UTF-8'),
                mb_strlen($line, 'UTF-8'),
            );
        }
    }

    public function testFilterRotateRightEmptyFigure(): void
    {
        $this->assertSame([''], $this->rowsToStrings(FilterEngine::apply(Filter::RotateRight, [])));
    }

    public function testFilterRotateLeftEmptyFigure(): void
    {
        $this->assertSame([''], $this->rowsToStrings(FilterEngine::apply(Filter::RotateLeft, [])));
    }

    // --- Terminal width ---

    public function testTerminalWidthReturnsPositiveInt(): void
    {
        $width = Figlet::terminalWidth();
        $this->assertGreaterThan(0, $width);
    }

    public function testTerminalWidthReadsColumnsEnvVar(): void
    {
        $previous = getenv('COLUMNS');
        try {
            putenv('COLUMNS=142');
            $this->assertSame(142, Figlet::terminalWidth());
        } finally {
            if ($previous !== false) {
                putenv('COLUMNS=' . $previous);
            } else {
                putenv('COLUMNS');
            }
        }
    }

    public function testTerminalWidthViaIoctlReturnsNonNegativeInt(): void
    {
        $figlet = new Figlet();
        $result = $this->invokeFigletMethod($figlet, 'terminalWidthViaIoctl');
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[RequiresPhpExtension('ffi')]
    #[RequiresOperatingSystem('Darwin|Linux')]
    public function testTerminalWidthViaIoctlWithFfi(): void
    {
        $figlet = new Figlet();
        $result = $this->invokeFigletMethod($figlet, 'terminalWidthViaIoctl');
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    // --- Font name & infocode ---

    public function testGetFontName(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertSame('makisupa', $figlet->getFontName());
    }

    public function testGetFontNameFromTlf(): void
    {
        $figlet = $this->loadedFiglet('emboss.tlf');
        $this->assertSame('emboss', $figlet->getFontName());
    }

    public function testGetInfoCode0ReturnsVersion(): void
    {
        $figlet = new Figlet();
        $this->assertSame(Figlet::VERSION, $figlet->getInfoCode(0));
    }

    public function testGetInfoCode1ReturnsVersionCode(): void
    {
        $figlet = new Figlet();
        $matched = preg_match('/^(\d+)\.(\d+)\.(\d+)$/', Figlet::VERSION, $match);
        $this->assertSame(1, $matched);
        $expected = sprintf('%d%02d%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
        $this->assertSame($expected, $figlet->getInfoCode(1));
    }

    public function testGetInfoCode2ReturnsFontDirectory(): void
    {
        $figlet = new Figlet();
        $dir = $figlet->getInfoCode(2);
        $this->assertStringEndsWith('fonts', $dir);
        $this->assertTrue(is_dir($dir));
    }

    public function testGetInfoCode3ReturnsFontName(): void
    {
        $figlet = $this->loadedFiglet();
        $this->assertSame('makisupa', $figlet->getInfoCode(3));
    }

    public function testGetInfoCode4ReturnsWidth(): void
    {
        $figlet = new Figlet();
        $this->assertSame('80', $figlet->getInfoCode(4));

        $figlet->setWidth(120);
        $this->assertSame('120', $figlet->getInfoCode(4));
    }

    public function testGetInfoCodeDefaultReturnsEmpty(): void
    {
        $figlet = new Figlet();
        $this->assertSame('', $figlet->getInfoCode(99));
    }

    public function testFilterRotateRightTransformsPair2x2(): void
    {
        $this->assertSame(['▀▄'], $this->rowsToStrings(FilterEngine::apply(Filter::RotateRight, $this->stringsToRows(['▄▀']))));
    }

    public function testFilterRotateLeftTransformsPair2x2(): void
    {
        $this->assertSame(['▀▄'], $this->rowsToStrings(FilterEngine::apply(Filter::RotateLeft, $this->stringsToRows(['▄▀']))));
    }

    public function testFilterRotateRightTransformsPair2x4(): void
    {
        $this->assertSame(["''"], $this->rowsToStrings(FilterEngine::apply(Filter::RotateRight, $this->stringsToRows([': ']))));
    }

    public function testFilterRotateLeftTransformsPair2x4(): void
    {
        $this->assertSame(['..'], $this->rowsToStrings(FilterEngine::apply(Filter::RotateLeft, $this->stringsToRows([': ']))));
    }

    public function testParseCharWithTrailingSpacesAfterEndmark(): void
    {
        $lines = ["flf2a\$ 1 1 1 -1 0 0"];
        for ($code = 32; $code < 127; $code++) {
            $glyph = $code === 32 ? ' ' : chr($code);
            $lines[] = $glyph . '~  ';
        }
        for ($i = 0; $i < 7; $i++) {
            $lines[] = 'g~  ';
        }
        $fontPath = $this->writeTempFile(implode("\n", $lines) . "\n", '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $this->assertSame("A\n", $figlet->render('A'));
    }

    // --- Color font (TLF with ANSI escapes) ---

    private function buildColorTlfFont(): string
    {
        $header = "tlf2a\$ 1 1 1 -1 0 0";
        $lines = [$header];

        for ($code = 32; $code < 127; $code++) {
            if ($code === 65) {
                $lines[] = "\e[31mA\e[0m@";
            } elseif ($code === 66) {
                $lines[] = "\e[34mB\e[0m@";
            } elseif ($code === 32) {
                $lines[] = ' @';
            } else {
                $lines[] = chr($code) . '@';
            }
        }

        for ($i = 0; $i < 7; $i++) {
            $lines[] = 'g@';
        }

        return implode("\n", $lines) . "\n";
    }

    public function testColorTlfFontLoadsColors(): void
    {
        $fontPath = $this->writeTempFile($this->buildColorTlfFont(), '.tlf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $result = $figlet->render('A');
        $this->assertStringContainsString("\e[", $result);
        $this->assertStringContainsString("\e[0m", $result);
    }

    public function testColorTlfFontPreservesColorInText(): void
    {
        $fontPath = $this->writeTempFile($this->buildColorTlfFont(), '.tlf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $result = $figlet->render('A');
        $cleaned = (string) preg_replace('/\e\[[0-9;]*m/', '', $result);
        $this->assertSame("A\n", $cleaned);
    }

    public function testColorFontHtmlExportProducesSpans(): void
    {
        $fontPath = $this->writeTempFile($this->buildColorTlfFont(), '.tlf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $result = $figlet->render('A', ExportFormat::Html);
        $this->assertStringContainsString('<span style="color:#a00">', $result);
        $this->assertStringNotContainsString("\e[", $result);
    }

    public function testColorFontHtml3ExportProducesFontTags(): void
    {
        $fontPath = $this->writeTempFile($this->buildColorTlfFont(), '.tlf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $result = $figlet->render('A', ExportFormat::Html3);
        $this->assertStringContainsString('<font color="#aa0000">', $result);
        $this->assertStringNotContainsString("\e[", $result);
    }

    public function testPlainFontTextHasNoAnsiCodes(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $result = $figlet->render('AB');
        $this->assertStringNotContainsString("\e[", $result);
    }

    public function testColorPreservedThroughSmushing(): void
    {
        $header = "tlf2a\$ 1 1 1 0 0 0 192 0";
        $lines = [$header];

        for ($code = 32; $code < 127; $code++) {
            if ($code === 65) {
                $lines[] = "\e[31mA \e[0m@";
            } elseif ($code === 66) {
                $lines[] = "\e[34m B\e[0m@";
            } elseif ($code === 32) {
                $lines[] = '  @';
            } else {
                $lines[] = chr($code) . ' @';
            }
        }

        for ($i = 0; $i < 7; $i++) {
            $lines[] = 'g @';
        }

        $fontPath = $this->writeTempFile(implode("\n", $lines) . "\n", '.tlf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $result = $figlet->render('AB');
        $cleaned = (string) preg_replace('/\e\[[0-9;]*m/', '', $result);
        $this->assertStringContainsString('A', $cleaned);
        $this->assertStringContainsString('B', $cleaned);
    }

    // --- Rainbow/Metal with color export ---

    public function testRainbowSetsPerCellColors(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['AAAAAA'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 6]);
        $figlet->addFilter(Filter::Rainbow);

        $result = $figlet->render('A');
        $this->assertStringContainsString("\e[", $result);
        $this->assertStringContainsString("\e[0m", $result);
        $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $result);
        $this->assertSame("AAAAAA\n", $stripped);
    }

    public function testMetalSetsPerCellColors(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['AAAAAAAAAA'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 10]);
        $figlet->addFilter(Filter::Metal);

        $result = $figlet->render('A');
        $this->assertStringContainsString("\e[", $result);
        $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $result);
        $this->assertSame("AAAAAAAAAA\n", $stripped);
    }

    public function testRainbowHtmlExportProducesColorSpans(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Rainbow);

        $html = $figlet->render('A', ExportFormat::Html);
        $this->assertStringContainsString('<span style="color:', $html);
        $this->assertStringNotContainsString("\e[", $html);
    }

    public function testMetalHtml3ExportProducesColorFonts(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(), '.flf');
        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->addFilter(Filter::Metal);

        $html = $figlet->render('A', ExportFormat::Html3);
        $this->assertStringContainsString('<font color="', $html);
        $this->assertStringNotContainsString("\e[", $html);
    }

    // --- Color preserved through filters ---

    public function testColorPreservedThroughFlip(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $figlet->addFilter(Filter::Rainbow);
        $figlet->addFilter(Filter::Flip);

        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['(A>'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 3]);

        $result = $figlet->render('A');
        $this->assertStringContainsString("\e[", $result);
        $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $result);
        $this->assertSame("<A)\n", $stripped);
    }

    public function testColorPreservedThroughBorder(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $figlet->addFilter(Filter::Rainbow);
        $figlet->addFilter(Filter::Border);

        $this->setFigletProperty($figlet, 'font', [65 => $this->stringsToRows(['A'])]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);

        $result = $figlet->render('A');
        $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $result);
        $lines = explode("\n", rtrim($stripped));
        $this->assertStringStartsWith('┌', $lines[0]);
    }

    // --- Renderer: colored row with null-fg cells ---

    public function testHtmlExportColoredRowWithNullFgCells(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('A', 1), new Cell(' '), new Cell('B')])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 3]);

        $html = $figlet->render('A', ExportFormat::Html);
        $this->assertStringContainsString('<span style="color:#a00">A</span>', $html);
        $this->assertStringContainsString('&nbsp;B', $html);
        $this->assertStringNotContainsString("\e[", $html);
    }

    public function testHtml3ExportColoredRowWithNullFgCells(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('X', 2), new Cell('Y')])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 2]);

        $html = $figlet->render('A', ExportFormat::Html3);
        $this->assertStringContainsString('<font color="#00aa00">X</font>', $html);
        $this->assertStringContainsString('Y', $html);
    }

    // --- Vertical smushing: default branch (controlled overlap, non-smushable chars) ---

    public function testVerticalControlledFittingDefaultBranch(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'height', 1);

        $eng = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);
        $result = $this->invokeRowListMethod(
            $figlet,
            'buildVerticalMerge',
            $eng,
            $this->stringsToRows(['A']),
            $this->stringsToRows(['B']),
            1,
            1,
            LayoutMode::Fitting,
        );
        $merged = $this->rowsToStrings($result);
        $this->assertSame(['B'], $merged);
    }

    // --- Renderer: ansi256ToHex for 256-color cells ---

    public function testHtmlExport256ColorFgCellProducesCorrectHex(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        // Color 200: idx=184, r=toVal(5)=255, g=toVal(0)=0, b=toVal(4)=215 → #ff00d7
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('A', 200)])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);

        $html = $figlet->render('A', ExportFormat::Html);
        $this->assertStringContainsString('#ff00d7', $html);
        $this->assertStringContainsString('<span style="color:#ff00d7">A</span>', $html);
    }

    public function testHtmlExport256ColorGrayscaleCellProducesCorrectHex(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        // Color 240: gray = 8 + 10*(240-232) = 88 → #585858
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('A', 240)])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);

        $html = $figlet->render('A', ExportFormat::Html);
        $this->assertStringContainsString('#585858', $html);
    }

    public function testHtml3Export256ColorFgCellProducesCorrectHex(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('A', 200)])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);

        $html = $figlet->render('A', ExportFormat::Html3);
        $this->assertStringContainsString('#ff00d7', $html);
        $this->assertStringContainsString('<font color="#ff00d7">A</font>', $html);
    }

    // --- Renderer: rowToHtml background-only cell ---

    public function testHtmlExportBgOnlyCellProducesBackgroundStyle(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('A', null, 2)])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);

        $html = $figlet->render('A', ExportFormat::Html);
        $this->assertStringContainsString('background:#0a0', $html);
        $this->assertStringNotContainsString('color:', $html);
    }

    public function testHtmlExportBothFgAndBgCellProducesCombinedStyle(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('A', 1, 2)])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);

        $html = $figlet->render('A', ExportFormat::Html);
        $this->assertStringContainsString('color:#a00;background:#0a0', $html);
    }

    public function testHtml3ExportBothFgAndBg(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('A', 1, 2)])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);

        $html = $figlet->render('A', ExportFormat::Html3);
        $this->assertStringContainsString('bgcolor="#00aa00"', $html);
        $this->assertStringContainsString('<font color="#aa0000">A</font>', $html);
    }

    public function testHtml3ExportBgOnlyCellUsesBgcolor(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [
            65 => [new Row([new Cell('A', null, 3)])],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);

        $html = $figlet->render('A', ExportFormat::Html3);
        $this->assertStringContainsString('bgcolor="#aa5500"', $html);
        $this->assertStringNotContainsString('<font', $html);
        $this->assertStringContainsString('A', $html);
    }

    // --- TOIlet compatibility (emoji.tlf: dual 16/256-color) ---

    public function testToiletCompatibilityEmoji16Color(): void
    {
        /** @var list<string> $whichOutput */
        $whichOutput = [];
        exec('which toilet', $whichOutput, $whichCode);
        if ($whichCode !== 0 || $whichOutput === []) {
            $this->markTestSkipped('toilet is not installed');
        }
        $toilet = $whichOutput[0];

        $toiletFontDir = dirname($this->fontPath('emoji.tlf'));

        $supports256Prop = new ReflectionProperty(Row::class, 'supports256');
        $downgradeCacheProp = new ReflectionProperty(Row::class, 'downgradeCache');
        $saved256 = $supports256Prop->getValue();
        $savedCache = $downgradeCacheProp->getValue();

        try {
            $supports256Prop->setValue(null, false);
            $downgradeCacheProp->setValue(null, []);

            $emoji = "\u{2764}\u{1F525}\u{2B50}";

            $figlet = new Figlet();
            $figlet->loadFont('emoji');
            $ourOutput = $figlet->render($emoji);

            $toiletCmd = sprintf(
                '%s -w 10000 -d %s -f emoji -- %s',
                escapeshellarg($toilet),
                escapeshellarg($toiletFontDir),
                escapeshellarg($emoji),
            );
            /** @var list<string> $toiletOutputLines */
            $toiletOutputLines = [];
            exec($toiletCmd, $toiletOutputLines, $toiletCode);
            $this->assertSame(0, $toiletCode, 'toilet exited with error');

            $ourLines = explode("\n", rtrim($ourOutput, "\n"));
            $toiletLines = $toiletOutputLines;
            while ($toiletLines !== [] && $toiletLines[count($toiletLines) - 1] === '') {
                array_pop($toiletLines);
            }

            $this->assertCount(count($ourLines), $toiletLines, 'Line count mismatch');

            foreach ($ourLines as $idx => $ourLine) {
                $ourRow = Row::fromAnsi($ourLine);
                $toiletRow = Row::fromAnsi($toiletLines[$idx]);

                $len = max($ourRow->length(), $toiletRow->length());
                for ($col = 0; $col < $len; $col++) {
                    $ourCell = $ourRow->cellAt($col);
                    $toiletCell = $toiletRow->cellAt($col);

                    $this->assertSame(
                        $ourCell->char,
                        $toiletCell->char,
                        "Char mismatch at row $idx, col $col",
                    );
                    $this->assertSame(
                        $ourCell->fg,
                        $toiletCell->fg,
                        "FG color mismatch at row $idx, col $col (char '{$ourCell->char}')",
                    );
                    $this->assertSame(
                        $ourCell->bg,
                        $toiletCell->bg,
                        "BG color mismatch at row $idx, col $col (char '{$ourCell->char}')",
                    );
                }
            }
        } finally {
            $supports256Prop->setValue(null, $saved256);
            $downgradeCacheProp->setValue(null, $savedCache);
        }
    }

    // --- NFC Normalization ---

    public function testNfcNormalizationComposesCharacters(): void
    {
        $fontPath = $this->writeTempFile($this->buildSimpleFont(
            codeTags: [['code' => 0xE9, 'glyph' => 'e']],
        ), '.flf');

        $figlet = new Figlet();
        $figlet->loadFont($fontPath);

        $precomposed = $figlet->render("\xC3\xA9");
        $decomposed = $figlet->render("e\xCC\x81");

        if (class_exists(Normalizer::class)) {
            $this->assertSame($precomposed, $decomposed);
        } else {
            $this->assertNotSame($precomposed, $decomposed);
        }
    }

    public function testTerminalWidth(): void
    {
        $savedColumns = getenv('COLUMNS');
        putenv('COLUMNS=120');

        try {
            $this->assertSame(120, Figlet::terminalWidth());
            
            // Just test that the function doesn't crash when COLUMNS is absent.
            // It will fall back to ioctl, tput, stty, or 80.
            putenv('COLUMNS=');
            $width = Figlet::terminalWidth();
            $this->assertGreaterThan(0, $width);
        } finally {
            putenv($savedColumns !== false ? 'COLUMNS=' . $savedColumns : 'COLUMNS');
        }
    }

    /**
     * Fonts excluded from reference comparison (.flf→figlet, .tlf→toilet):
     *
     * - Latin-1/multi-byte: we produce correct UTF-8; figlet outputs raw bytes
     * - CP437: neither figlet nor this library supports the encoding
     */
    private const REFERENCE_SKIP = [
        'konto', 'kontoslant', 'stronger_than_all',
    ];

    /** @return iterable<string, array{string}> */
    public static function referenceFontProvider(): iterable
    {
        $fontsDir = realpath(__DIR__ . '/../fonts');
        if ($fontsDir === false) {
            return;
        }

        $flf = glob($fontsDir . '/*.flf');
        $tlf = glob($fontsDir . '/*.tlf');
        $files = array_merge(
            $flf !== false ? $flf : [],
            $tlf !== false ? $tlf : [],
        );
        sort($files);

        $skip = array_merge(['emoji'], self::REFERENCE_SKIP);

        foreach ($files as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (in_array($name, $skip, true)) {
                continue;
            }
            yield $name => [$path];
        }
    }

    private function normalizeForComparison(string $s): string
    {
        $s = str_replace("\xc2\xa0", ' ', $s);
        $s = (string) preg_replace("/\x1b\\[[0-9;]*m/", '', $s);
        $lines = array_map(rtrim(...), explode("\n", $s));
        while ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    #[DataProvider('referenceFontProvider')]
    public function testReferenceCompatibility(string $fontPath): void
    {
        $name = pathinfo($fontPath, PATHINFO_FILENAME);
        $ext = pathinfo($fontPath, PATHINFO_EXTENSION);

        $figlet = new Figlet();
        $figlet->loadFont($fontPath);
        $figlet->setWidth(10000);
        $ours = $this->normalizeForComparison($figlet->render('Hello'));

        $refLines = [];
        $exitCode = 0;

        $isGzip = file_get_contents($fontPath, false, null, 0, 2) === "\x1f\x8b";
        $refFontDir = ($ext === 'flf' && $isGzip)
            ? $this->decompressGzipFont($fontPath)
            : dirname($fontPath);

        if ($ext === 'tlf') {
            $cmd = 'toilet -w 10000 -d ' . escapeshellarg($refFontDir)
                . ' -f ' . escapeshellarg($name) . ' Hello 2>&1';
        } else {
            $cmd = 'figlet -w 10000 -d ' . escapeshellarg($refFontDir)
                . ' -f ' . escapeshellarg($name) . ' Hello 2>&1';
        }

        exec($cmd, $refLines, $exitCode);

        if ($exitCode !== 0) {
            $this->markTestSkipped("Reference tool cannot render font $name");
        }

        $theirs = $this->normalizeForComparison(implode("\n", $refLines));

        $tool = $ext === 'tlf' ? 'toilet' : 'figlet';
        $this->assertSame($theirs, $ours, "Output differs from $tool for font $name");
    }
}
