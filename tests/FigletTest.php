<?php

declare(strict_types=1);

namespace Bolk\TextFiglet\Tests;

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
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempPaths = [];
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
        $this->assertStringContainsString('<tr><td><tt>', $result);
        $this->assertStringContainsString('</tt></td></tr>', $result);
        $this->assertStringContainsString('&#160;', $result);
        $this->assertStringEndsWith("</table>\n", $result);
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
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'hLayout', LayoutMode::Smushing);

        $this->assertSame('A', $this->invokeFigletMethod($figlet, 'smushem', ' ', 'A', 2, 2));
        $this->assertSame('A', $this->invokeFigletMethod($figlet, 'smushem', 'A', ' ', 2, 2));
        $this->assertNull($this->invokeFigletMethod($figlet, 'smushem', 'A', 'B', 1, 2));

        $this->setFigletProperty($figlet, 'hLayoutOverride', LayoutMode::Fitting);
        $this->assertNull($this->invokeFigletMethod($figlet, 'smushem', 'A', 'B', 2, 2));
        $this->setFigletProperty($figlet, 'hLayoutOverride', null);

        $this->setFigletProperty($figlet, 'hSmushRules', 0);
        $this->setFigletProperty($figlet, 'printDirection', 0);
        $this->assertSame('A', $this->invokeFigletMethod($figlet, 'smushem', '$', 'A', 2, 2));
        $this->assertSame('A', $this->invokeFigletMethod($figlet, 'smushem', 'A', '$', 2, 2));
        $this->assertSame('B', $this->invokeFigletMethod($figlet, 'smushem', 'A', 'B', 2, 2));

        $this->setFigletProperty($figlet, 'printDirection', 1);
        $this->assertSame('A', $this->invokeFigletMethod($figlet, 'smushem', 'A', 'B', 2, 2));

        $this->setFigletProperty($figlet, 'hSmushRules', 32);
        $this->assertSame('$', $this->invokeFigletMethod($figlet, 'smushem', '$', '$', 2, 2));

        $this->setFigletProperty($figlet, 'hSmushRules', 4);
        $this->assertSame('{', $this->invokeFigletMethod($figlet, 'smushem', ']', '{', 2, 2));
        $this->assertSame('{', $this->invokeFigletMethod($figlet, 'smushem', '{', '[', 2, 2));
        $this->assertSame('<', $this->invokeFigletMethod($figlet, 'smushem', '(', '<', 2, 2));
        $this->assertSame('<', $this->invokeFigletMethod($figlet, 'smushem', '<', '(', 2, 2));

        $this->setFigletProperty($figlet, 'hSmushRules', 8);
        $this->assertSame('|', $this->invokeFigletMethod($figlet, 'smushem', ']', '[', 2, 2));
        $this->assertSame('|', $this->invokeFigletMethod($figlet, 'smushem', '}', '{', 2, 2));
        $this->assertSame('|', $this->invokeFigletMethod($figlet, 'smushem', ')', '(', 2, 2));
    }

    public function testInternalCalcSmushAmountAndAddCharToOutputRtl(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'hLayout', LayoutMode::Smushing);
        $this->setFigletProperty($figlet, 'hSmushRules', 16);
        $this->setFigletProperty($figlet, 'printDirection', 1);

        $this->assertSame(
            0,
            $this->invokeFigletMethod($figlet, 'calcSmushAmount', ['AA'], ['BB'], 2, 2, 2, LayoutMode::FullSize),
        );
        $this->assertSame(
            1,
            $this->invokeFigletMethod($figlet, 'calcSmushAmount', ['\\ '], [' /'], 2, 2, 2, LayoutMode::Smushing),
        );
        $this->assertSame(
            2,
            $this->invokeFigletMethod($figlet, 'calcSmushAmount', [' A'], ['  '], 2, 2, 2, LayoutMode::Fitting),
        );

        $this->assertSame(
            [' | '],
            $this->invokeFigletMethod($figlet, 'addCharToOutput', ['\\ '], [' /'], 2, 2, 1),
        );
    }

    public function testInternalVSmushCharBranches(): void
    {
        $figlet = new Figlet();

        $this->setFigletProperty($figlet, 'vSmushRules', 0);
        $this->assertSame('B', $this->invokeFigletMethod($figlet, 'vSmushChar', ' ', 'B'));
        $this->assertSame('A', $this->invokeFigletMethod($figlet, 'vSmushChar', 'A', ' '));
        $this->assertSame('B', $this->invokeFigletMethod($figlet, 'vSmushChar', 'A', 'B'));

        $this->setFigletProperty($figlet, 'vSmushRules', 1);
        $this->assertSame('|', $this->invokeFigletMethod($figlet, 'vSmushChar', '|', '|'));

        $this->setFigletProperty($figlet, 'vSmushRules', 2);
        $this->assertSame('|', $this->invokeFigletMethod($figlet, 'vSmushChar', '|', '_'));

        $this->setFigletProperty($figlet, 'vSmushRules', 4);
        $this->assertSame('<', $this->invokeFigletMethod($figlet, 'vSmushChar', '(', '<'));
        $this->assertSame('<', $this->invokeFigletMethod($figlet, 'vSmushChar', '<', '('));

        $this->setFigletProperty($figlet, 'vSmushRules', 8);
        $this->assertSame('=', $this->invokeFigletMethod($figlet, 'vSmushChar', '_', '-'));

        $this->setFigletProperty($figlet, 'vSmushRules', 16);
        $this->assertSame('|', $this->invokeFigletMethod($figlet, 'vSmushChar', '|', '|'));

        $this->setFigletProperty($figlet, 'vSmushRules', 2);
        $this->assertNull($this->invokeFigletMethod($figlet, 'vSmushChar', 'A', 'B'));
    }

    public function testInternalAllEmptyAndVerticalMergeBranches(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'vSmushRules', 0);

        $this->assertTrue($this->invokeFigletMethod($figlet, 'allEmpty', [[' ', ''], ['   ']]));
        $this->assertFalse($this->invokeFigletMethod($figlet, 'allEmpty', [['X']]));

        $this->assertSame(
            ['$'],
            $this->invokeFigletMethod($figlet, 'combineFiguresVertically', ['$'], [' '], LayoutMode::Fitting),
        );
        $this->assertSame(
            ['A'],
            $this->invokeFigletMethod($figlet, 'combineFiguresVertically', ['$'], ['A'], LayoutMode::Fitting),
        );
        $this->assertSame(
            ['A'],
            $this->invokeFigletMethod($figlet, 'combineFiguresVertically', ['A'], ['$'], LayoutMode::Fitting),
        );

        $this->setFigletProperty($figlet, 'vSmushRules', 2);
        $this->assertSame(
            ['A', 'B'],
            $this->invokeFigletMethod($figlet, 'combineFiguresVertically', ['A'], ['B'], LayoutMode::Smushing),
        );
    }

    public function testInternalRenderCodesFallbackAndEmptyResult(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'font', [0 => ['?']]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [0 => 1]);

        $this->assertSame(['?'], $this->invokeFigletMethod($figlet, 'renderCodes', [999]));

        $this->setFigletProperty($figlet, 'font', []);
        $this->setFigletProperty($figlet, 'fontCharWidths', []);
        $this->assertSame([''], $this->invokeFigletMethod($figlet, 'renderCodes', [999]));
    }

    public function testInternalRenderLineWithWrappingBranches(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'font', [
            32 => [' '],
            65 => ['A'],
            66 => ['B'],
        ]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [
            32 => 1,
            65 => 1,
            66 => 1,
        ]);
        $this->setFigletProperty($figlet, 'outputWidth', 1);

        $this->assertSame([['A']], $this->invokeFigletMethod($figlet, 'renderLineWithWrapping', ' A'));
        $this->assertSame([['A'], ['B']], $this->invokeFigletMethod($figlet, 'renderLineWithWrapping', 'A   B'));
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
        $this->assertSame('┛┃ ┃', $lines[0]);
        $this->assertSame('┃┏━┃', $lines[1]);
        $this->assertSame('┛┛ ┛', $lines[2]);
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

        $this->assertSame(
            ['C ', 'AB'],
            $this->invokeFigletMethod($figlet, 'combineFiguresVertically', ['C'], ['AB'], LayoutMode::Fitting),
        );
        $this->assertSame(
            ['AB', 'C '],
            $this->invokeFigletMethod($figlet, 'combineFiguresVertically', ['AB'], ['C'], LayoutMode::Fitting),
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
        $this->setFigletProperty($figlet, 'font', [65 => ['', 'A', '']]);
        $this->setFigletProperty($figlet, 'fontCharWidths', [65 => 1]);
        $figlet->addFilter(Filter::Crop);

        $this->assertSame("A\n", $figlet->render('A'));
    }

    public function testFilterCropAllBlank(): void
    {
        $figlet = new Figlet();
        $this->setFigletProperty($figlet, 'height', 1);
        $this->setFigletProperty($figlet, 'hardblank', '$');
        $this->setFigletProperty($figlet, 'font', [32 => ['   ']]);
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
        $this->setFigletProperty($figlet, 'font', [65 => ['TOP', 'BOT']]);
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
        $this->setFigletProperty($figlet, 'font', [65 => ['(A', 'B)']]);
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
        $this->setFigletProperty($figlet, 'font', [65 => ['AB', 'CD']]);
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
        $this->setFigletProperty($figlet, 'font', [65 => ['ABCD']]);
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
        $this->setFigletProperty($figlet, 'font', [65 => ['ABCD']]);
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
        $this->setFigletProperty($figlet, 'font', [32 => ['   ']]);
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
        $this->setFigletProperty($figlet, 'font', [32 => ['   ']]);
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
        $this->assertSame([''], FilterEngine::apply(Filter::RotateRight, []));
    }

    public function testFilterRotateLeftEmptyFigure(): void
    {
        $this->assertSame([''], FilterEngine::apply(Filter::RotateLeft, []));
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
        $this->assertSame(['▀▄'], FilterEngine::apply(Filter::RotateRight, ['▄▀']));
    }

    public function testFilterRotateLeftTransformsPair2x2(): void
    {
        $this->assertSame(['▀▄'], FilterEngine::apply(Filter::RotateLeft, ['▄▀']));
    }

    public function testFilterRotateRightTransformsPair2x4(): void
    {
        $this->assertSame(["''"], FilterEngine::apply(Filter::RotateRight, [': ']));
    }

    public function testFilterRotateLeftTransformsPair2x4(): void
    {
        $this->assertSame(['..'], FilterEngine::apply(Filter::RotateLeft, [': ']));
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
}
