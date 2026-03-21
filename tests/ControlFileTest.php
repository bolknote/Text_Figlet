<?php

declare(strict_types=1);

namespace Bolk\TextFiglet\Tests;

use Override;
use ZipArchive;
use ReflectionMethod;
use Bolk\TextFiglet\ControlFile;
use Bolk\TextFiglet\Encoding;
use Bolk\TextFiglet\Exception\ControlFileException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class ControlFileTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    private function fixturePath(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
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
        $path = sys_get_temp_dir() . '/control_' . str_replace('.', '_', uniqid('', true)) . $suffix;
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;
        return $path;
    }

    private function writeZipArchive(string $contents, bool $empty = false): string
    {
        $path = $this->writeTempFile('', '.flc');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        if (!$empty) {
            $zip->addFromString('control.flc', $contents);
        }

        $zip->close();

        return $path;
    }

    private function invokeControlFileMethod(ControlFile $controlFile, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($controlFile, $method);
        return $ref->invoke($controlFile, ...$args);
    }

    public function testSingleCharMapping(): void
    {
        $controlFile = ControlFile::fromString("t A B\n");
        $result = $controlFile->apply('A');
        $this->assertSame('B', $result);
    }

    public function testSwapCharacters(): void
    {
        $controlFile = ControlFile::fromString("t A B\nt B A\n");
        $this->assertSame('B', $controlFile->apply('A'));
        $this->assertSame('A', $controlFile->apply('B'));
        $this->assertSame('BA', $controlFile->apply('AB'));
    }

    public function testRangeMapping(): void
    {
        $controlFile = ControlFile::load($this->fixturePath('range.flc'));
        $this->assertSame('hello', $controlFile->apply('HELLO'));
        $this->assertSame('abc', $controlFile->apply('ABC'));
    }

    public function testRangeMappingPreservesOther(): void
    {
        $controlFile = ControlFile::load($this->fixturePath('range.flc'));
        $this->assertSame('hello 123', $controlFile->apply('HELLO 123'));
    }

    public function testMultiStage(): void
    {
        $controlFile = ControlFile::load($this->fixturePath('multistage.flc'));
        // First stage: a-z -> A-Z, then Q -> ~
        $this->assertSame('~', $controlFile->apply('q'));
        $this->assertSame('~', $controlFile->apply('Q'));
        $this->assertSame('A', $controlFile->apply('a'));
    }

    public function testUnicodeMappingFormat(): void
    {
        $controlFile = ControlFile::fromString("65 66\n");
        $this->assertSame('B', $controlFile->apply('A'));
    }

    public function testBackslashEscapes(): void
    {
        // \\ = backslash (92), \ = space (32)
        $controlFile = ControlFile::fromString("t A \\\\");
        $this->assertSame('\\', $controlFile->apply('A'));
    }

    public function testBackslashSpaceEscape(): void
    {
        $controlFile = ControlFile::fromString("t A \\ ");
        $this->assertSame(' ', $controlFile->apply('A'));
    }

    public function testBackslashHexEscape(): void
    {
        $controlFile = ControlFile::fromString("t \\0x41 \\0x42");
        $this->assertSame('B', $controlFile->apply('A'));
    }

    public function testBackslashOctalEscape(): void
    {
        $controlFile = ControlFile::fromString("t \\0101 B");
        $this->assertSame('B', $controlFile->apply('A'));
    }

    public function testNamedBackslashEscape(): void
    {
        $controlFile = ControlFile::fromString("t \\n !");
        $this->assertSame('!', $controlFile->apply("\n"));
    }

    public function testBareBackslashEscape(): void
    {
        $controlFile = ControlFile::fromString("t A \\\n");
        $this->assertSame('\\', $controlFile->apply('A'));
    }

    public function testBackslashDecimalEscape(): void
    {
        $controlFile = ControlFile::fromString("t \\65 B");
        $this->assertSame('B', $controlFile->apply('A'));
    }

    public function testFlcSignature(): void
    {
        $controlFile = ControlFile::load($this->fixturePath('signature.flc'));
        $this->assertSame('Y', $controlFile->apply('X'));
        $this->assertSame('A', $controlFile->apply('A'));
    }

    public function testBundledControlFileLookupByName(): void
    {
        $controlFile = ControlFile::load('utf8');
        $this->assertSame(Encoding::Utf8, $controlFile->getEncoding());
    }

    public function testCommentLines(): void
    {
        $controlFile = ControlFile::fromString("# This is a comment\n\nt A B\n# Another comment\n");
        $this->assertSame('B', $controlFile->apply('A'));
    }

    public function testHzMode(): void
    {
        $controlFile = ControlFile::fromString("h\n");
        $this->assertSame(Encoding::Hz, $controlFile->getEncoding());
    }

    public function testShiftJisMode(): void
    {
        $controlFile = ControlFile::fromString("j\n");
        $this->assertSame(Encoding::ShiftJis, $controlFile->getEncoding());
    }

    public function testDbcsMode(): void
    {
        $controlFile = ControlFile::fromString("b\n");
        $this->assertSame(Encoding::Dbcs, $controlFile->getEncoding());
    }

    public function testUtf8Mode(): void
    {
        $controlFile = ControlFile::fromString("u\n");
        $this->assertSame(Encoding::Utf8, $controlFile->getEncoding());
    }

    public function testLastEncodingWins(): void
    {
        $controlFile = ControlFile::fromString("h\nj\nb\nu\n");
        $this->assertSame(Encoding::Utf8, $controlFile->getEncoding());
    }

    public function testHzModeDecode(): void
    {
        $controlFile = ControlFile::fromString("h\n");
        // ~~ becomes ~
        $this->assertSame('~', $controlFile->apply('~~'));
        // Regular ASCII passes through
        $this->assertSame('ABC', $controlFile->apply('ABC'));
    }

    public function testHzModeDecodesDoubleByteSequence(): void
    {
        $controlFile = ControlFile::fromString("h\n16706 65\n");
        $this->assertSame('A', $controlFile->apply("~{AB~}"));
    }

    public function testUtf8ModeHandlesMultiByteCharacters(): void
    {
        $controlFile = ControlFile::fromString('');
        $this->assertSame('é€🚀', $controlFile->apply('é€🚀'));
    }

    public function testUtf8ModeHandlesInvalidLeadingByte(): void
    {
        $controlFile = ControlFile::fromString('');
        $this->assertSame("\xC2\x80", $controlFile->apply("\xFF"));
    }

    #[RequiresPhpExtension('zlib')]
    public function testLoadGzipCompressedControlFile(): void
    {
        $encoded = gzencode("t A B\n");
        self::assertNotFalse($encoded);
        $path = $this->writeTempFile($encoded, '.flc.gz');

        $controlFile = ControlFile::load($path);
        $this->assertSame('B', $controlFile->apply('A'));
    }

    #[RequiresPhpExtension('zip')]
    public function testLoadZipCompressedControlFile(): void
    {
        $path = $this->writeZipArchive("t A B\n");

        $controlFile = ControlFile::load($path);
        $this->assertSame('B', $controlFile->apply('A'));
    }

    #[RequiresPhpExtension('zip')]
    public function testEmptyZipControlFileThrows(): void
    {
        $path = $this->writeTempFile("PK\x05\x06" . str_repeat("\x00", 18), '.flc');

        $this->expectException(ControlFileException::class);
        $this->expectExceptionMessage('ZIP archive is empty');
        ControlFile::load($path);
    }

    public function testShiftJisModeDecodesDoubleAndSingleByteInput(): void
    {
        $controlFile = ControlFile::fromString("j\n33440 65\n130 66\n");
        $this->assertSame('A', $controlFile->apply("\x82\xA0"));
        $this->assertSame('B', $controlFile->apply("\x82"));
    }

    public function testDbcsModeDecodesDoubleAndSingleByteInput(): void
    {
        $controlFile = ControlFile::fromString("b\n33088 65\n129 66\n");
        $this->assertSame('A', $controlFile->apply("\x81\x40"));
        $this->assertSame('B', $controlFile->apply("\x81"));
    }

    public function testControlFileNotFound(): void
    {
        $this->expectException(ControlFileException::class);
        ControlFile::load('/nonexistent/path/test.flc');
    }

    public function testUnreadableControlFileThrows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'control_file_test_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "t A B\n");
        chmod($tmp, 0);

        try {
            $this->expectException(ControlFileException::class);
            $this->expectExceptionMessage('Cannot read control file');
            set_error_handler(static fn (): bool => true);
            ControlFile::load($tmp);
        } finally {
            restore_error_handler();
            chmod($tmp, 0644);
            unlink($tmp);
        }
    }

    public function testEmptyControlFile(): void
    {
        $controlFile = ControlFile::fromString('');
        $this->assertSame('Hello', $controlFile->apply('Hello'));
    }

    public function testGCommandParsed(): void
    {
        $controlFile = ControlFile::fromString("g 0 94 B\ng 1 96 A\n");
        $this->assertNotEmpty($controlFile->getStages());
    }

    public function testRealFrangoControlFileMapsFinalSigma(): void
    {
        $controlFile = ControlFile::load('frango.flc');
        $this->assertSame('ς', $controlFile->apply('V'));
    }

    public function testBundledIso2022ControlFileLoads(): void
    {
        $controlFile = ControlFile::load('jis0201');
        $this->assertSame(Encoding::Iso2022, $controlFile->getEncoding());
    }

    public function testGCommandHalfSelectorsAndShortCommandAreAccepted(): void
    {
        $controlFile = ControlFile::fromString("g L 2\ng R 3\ng 0\n");
        $this->assertSame('ABC', $controlFile->apply('ABC'));
    }

    public function testOnlyFirstRuleAppliesPerStage(): void
    {
        $controlFile = ControlFile::fromString("t A B\nt A C\n");
        // First matching rule wins
        $this->assertSame('B', $controlFile->apply('A'));
    }

    public function testIdentityMappingNoChange(): void
    {
        $controlFile = ControlFile::fromString("t Z Z\n");
        $this->assertSame('Z', $controlFile->apply('Z'));
    }

    public function testLongInputTokenUsesFirstCharacter(): void
    {
        $controlFile = ControlFile::fromString("t AB C\n");
        $this->assertSame('C', $controlFile->apply('A'));
    }

    public function testInvalidTCommandThrows(): void
    {
        $this->expectException(ControlFileException::class);
        $this->expectExceptionMessage('Invalid t command');
        ControlFile::fromString("t A\n");
    }

    public function testInternalParserHelpers(): void
    {
        $controlFile = ControlFile::fromString('');

        $this->assertNull($this->invokeControlFileMethod($controlFile, 'parseRange', 'ABC'));
        $this->assertSame(65, $this->invokeControlFileMethod($controlFile, 'parseCharValue', '65'));
        $this->assertSame(65, $this->invokeControlFileMethod($controlFile, 'parseCharValue', 'AB'));
        $this->assertSame(['A', '\\n', 'B'], $this->invokeControlFileMethod($controlFile, 'tokenizeTArgs', "  A \\n B"));
        $this->assertSame(92, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', ''));
        $this->assertSame(7, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', 'a'));
        $this->assertSame(8, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', 'b'));
        $this->assertSame(92, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', '\\'));
        $this->assertSame(32, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', ' '));
        $this->assertSame(27, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', 'e'));
        $this->assertSame(12, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', 'f'));
        $this->assertSame(13, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', 'r'));
        $this->assertSame(9, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', 't'));
        $this->assertSame(11, $this->invokeControlFileMethod($controlFile, 'parseBackslashEscape', 'v'));
        $this->assertSame(65, $this->invokeControlFileMethod($controlFile, 'parseNumericEscape', '0X41'));
    }

    public function testInternalGCommandAndDecoderBranches(): void
    {
        $controlFile = ControlFile::fromString('');

        $this->invokeControlFileMethod($controlFile, 'parseGCommand', '2 94x94 Z');
        $this->invokeControlFileMethod($controlFile, 'parseGCommand', '3 weird Q');

        $this->assertSame([], $this->invokeControlFileMethod($controlFile, 'decodeHZ', '~x'));
        $this->assertSame([65], $this->invokeControlFileMethod($controlFile, 'decodeShiftJIS', 'A'));
        $this->assertSame([65], $this->invokeControlFileMethod($controlFile, 'decodeDBCS', 'A'));
    }

    public function testBareGCommandSetsIso2022Encoding(): void
    {
        $cf = ControlFile::fromString("g\n");
        $this->assertSame(Encoding::Iso2022, $cf->getEncoding());
    }

    public function testGCommandWithInvalidSubcommandIsIgnored(): void
    {
        $cf = ControlFile::fromString("gX\nt A B\n");
        $this->assertSame('B', $cf->apply('A'));
    }

    public function testGCommandWithSlot3(): void
    {
        $cf = ControlFile::fromString("g3 94 B\n");
        $this->assertSame(Encoding::Iso2022, $cf->getEncoding());
    }

    public function testOctalValueInDirectMapping(): void
    {
        $cf = ControlFile::fromString("0101 0102\n");
        $this->assertSame('B', $cf->apply('A'));
    }

    public function testGCommandWithoutDesignatorCharacter(): void
    {
        $cf = ControlFile::fromString("g0 96\n");
        $this->assertSame(Encoding::Iso2022, $cf->getEncoding());
    }

    public function testIso2022SingleShiftSS3(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = chr(143) . chr(0x41);
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testIso2022EscDollarDirectDesignation(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = "\x1b\x24\x42" . chr(0x21) . chr(0x21);
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testIso2022EscDollar94x94Designation(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = "\x1b\x24\x28\x42" . chr(0x21) . chr(0x22);
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testIso2022Esc96CharDesignation(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = "\x1b\x2D\x41" . chr(0xA1);
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testIso2022DoubleByteGr(): void
    {
        $cf = ControlFile::fromString("g1 94x94 B\ngR 1\n");
        $input = chr(0xA1) . chr(0xA2);
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testIso2022ControlCharacterPassthrough(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = chr(0x01) . 'A';
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testLoadControlFileByPathWithoutExtension(): void
    {
        $basePath = sys_get_temp_dir() . '/ctrl_' . str_replace('.', '_', uniqid('', true));
        $flcPath = $basePath . '.flc';
        file_put_contents($flcPath, "t A B\n");

        try {
            $cf = ControlFile::load($basePath);
            $this->assertSame('B', $cf->apply('A'));
        } finally {
            @unlink($flcPath);
        }
    }

    public function testGCommandWithoutSlotNumber(): void
    {
        $cf = ControlFile::fromString("gL\n");
        $this->assertSame(Encoding::Iso2022, $cf->getEncoding());
        $this->assertSame('A', $cf->apply('A'));
    }

    public function testIso2022LockingShiftEscSequences(): void
    {
        $cf = ControlFile::fromString("gL 0\n");

        $this->assertNotEmpty($cf->apply("\x1bnA"));
        $this->assertNotEmpty($cf->apply("\x1boA"));
        $this->assertNotEmpty($cf->apply("\x1b\x7E" . chr(0xA1)));
        $this->assertNotEmpty($cf->apply("\x1b\x7D" . chr(0xA1)));
        $this->assertNotEmpty($cf->apply("\x1b\x7C" . chr(0xA1)));
    }

    public function testIso2022RepeatedSingleShift(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $input = chr(142) . chr(142) . chr(0x41);
        $result = $cf->apply($input);
        $this->assertNotEmpty($result);
    }

    public function testIso2022DesignationAtEndOfInput(): void
    {
        $cf = ControlFile::fromString("gL 0\n");
        $result = $cf->apply("\x1b\x28");
        $this->assertSame('', $result);
    }

    public function testMissingFileThrowsException(): void
    {
        $path = $this->writeTempFile('test', '.flc');
        chmod($path, 0000);
        $this->expectException(ControlFileException::class);
        $this->expectExceptionMessage('Cannot read control file');
        set_error_handler(static fn(): bool => true);
        try {
            ControlFile::load($path);
        } finally {
            restore_error_handler();
            chmod($path, 0644);
        }
    }

    public function testMissingGzFileThrowsException(): void
    {
        $path = $this->writeTempFile('test', '.flc.gz');
        chmod($path, 0000);
        $this->expectException(ControlFileException::class);
        $this->expectExceptionMessage('Cannot read control file');
        set_error_handler(static fn(): bool => true);
        try {
            ControlFile::load($path);
        } finally {
            restore_error_handler();
            chmod($path, 0644);
        }
    }

}
