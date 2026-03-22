<?php

declare(strict_types=1);

namespace Bolk\TextFiglet\Tests;

use Bolk\TextFiglet\AnsiColor;
use Bolk\TextFiglet\ExportFormat;
use Bolk\TextFiglet\LayoutMode;
use Bolk\TextFiglet\Renderer;
use Bolk\TextFiglet\SmushEngine;
use ReflectionMethod;
use ReflectionProperty;
use Bolk\TextFiglet\Cell;
use Bolk\TextFiglet\Row;
use PHPUnit\Framework\TestCase;

final class CellRowTest extends TestCase
{
    // --- Cell ---

    public function testCellConstructionDefaults(): void
    {
        $cell = new Cell('A');
        $this->assertSame('A', $cell->char);
        $this->assertNull($cell->fg);
        $this->assertNull($cell->bg);
    }

    public function testCellConstructionWithColors(): void
    {
        $cell = new Cell('X', 4, 7);
        $this->assertSame('X', $cell->char);
        $this->assertSame(4, $cell->fg);
        $this->assertSame(7, $cell->bg);
    }

    public function testCellWithChar(): void
    {
        $cell = new Cell('A', 5, 2);
        $new = $cell->withChar('B');
        $this->assertSame('B', $new->char);
        $this->assertSame(5, $new->fg);
        $this->assertSame(2, $new->bg);
        $this->assertSame('A', $cell->char);
    }

    public function testCellWithFg(): void
    {
        $cell = new Cell('A', 5, 2);
        $new = $cell->withFg(10);
        $this->assertSame(10, $new->fg);
        $this->assertSame('A', $new->char);
        $this->assertSame(2, $new->bg);
        $layered = new Cell('A', 5, 2, 3, 4);
        $cleared = $layered->withFg(10);
        $this->assertNull($cleared->fgBase16);
        $this->assertSame(4, $cleared->bgBase16);
    }

    public function testCellWithBg(): void
    {
        $cell = new Cell('A', 5, 2);
        $new = $cell->withBg(10);
        $this->assertSame(10, $new->bg);
        $this->assertSame('A', $new->char);
        $this->assertSame(5, $new->fg);
    }

    public function testCellHasColor(): void
    {
        $this->assertFalse((new Cell('A'))->hasColor());
        $this->assertTrue((new Cell('A', 1))->hasColor());
        $this->assertTrue((new Cell('A', null, 2))->hasColor());
        $this->assertTrue((new Cell('A', 1, 2))->hasColor());
    }

    // --- Row basics ---

    public function testRowEmptyConstructor(): void
    {
        $row = new Row([]);
        $this->assertSame(0, $row->length());
        $this->assertSame('', $row->toText());
    }

    public function testRowFromString(): void
    {
        $row = Row::fromString('Hello');
        $this->assertSame(5, $row->length());
        $this->assertSame('Hello', $row->toText());
    }

    public function testRowFromStringUtf8(): void
    {
        $row = Row::fromString('Привет');
        $this->assertSame(6, $row->length());
        $this->assertSame('Привет', $row->toText());
    }

    public function testRowFromStringEmpty(): void
    {
        $row = Row::fromString('');
        $this->assertSame(0, $row->length());
        $this->assertSame('', $row->toText());
    }

    public function testRowEmpty(): void
    {
        $row = Row::empty(5);
        $this->assertSame(5, $row->length());
        $this->assertSame('     ', $row->toText());
    }

    public function testRowEmptyZero(): void
    {
        $row = Row::empty(0);
        $this->assertSame(0, $row->length());
    }

    public function testRowEmptyNegative(): void
    {
        $row = Row::empty(-1);
        $this->assertSame(0, $row->length());
    }

    // --- Row charAt / cellAt ---

    public function testRowCharAt(): void
    {
        $row = Row::fromString('ABC');
        $this->assertSame('A', $row->charAt(0));
        $this->assertSame('B', $row->charAt(1));
        $this->assertSame('C', $row->charAt(2));
        $this->assertSame('', $row->charAt(3));
    }

    public function testRowCellAtPlain(): void
    {
        $row = Row::fromString('AB');
        $cell = $row->cellAt(0);
        $this->assertSame('A', $cell->char);
        $this->assertNull($cell->fg);
        $this->assertNull($cell->bg);
    }

    public function testRowCellAtColored(): void
    {
        $row = new Row([new Cell('A', 5, 2), new Cell('B', 10)]);
        $this->assertSame(5, $row->cellAt(0)->fg);
        $this->assertSame(2, $row->cellAt(0)->bg);
        $this->assertSame(10, $row->cellAt(1)->fg);
        $this->assertNull($row->cellAt(1)->bg);
    }

    public function testRowCellAtOutOfBounds(): void
    {
        $row = Row::fromString('A');
        $cell = $row->cellAt(5);
        $this->assertSame(' ', $cell->char);
    }

    // --- Row slice ---

    public function testRowSlicePlain(): void
    {
        $row = Row::fromString('Hello World');
        $this->assertSame('World', $row->slice(6)->toText());
        $this->assertSame('Hell', $row->slice(0, 4)->toText());
        $this->assertSame('lo W', $row->slice(3, 4)->toText());
    }

    public function testRowSliceColored(): void
    {
        $row = new Row([
            new Cell('A', 1), new Cell('B', 2), new Cell('C', 3),
        ]);
        $sliced = $row->slice(1, 2);
        $this->assertSame(2, $sliced->length());
        $this->assertSame(2, $sliced->cellAt(0)->fg);
        $this->assertSame(3, $sliced->cellAt(1)->fg);
    }

    // --- Row replaceAt ---

    public function testRowReplaceAtPlain(): void
    {
        $row = Row::fromString('ABC');
        $new = $row->replaceAt(1, new Cell('X'));
        $this->assertSame('AXC', $new->toText());
        $this->assertSame('ABC', $row->toText());
    }

    public function testRowReplaceAtColorExpands(): void
    {
        $row = Row::fromString('ABC');
        $new = $row->replaceAt(1, new Cell('X', 5));
        $this->assertSame('AXC', $new->toText());
        $this->assertTrue($new->hasColor());
        $this->assertSame(5, $new->cellAt(1)->fg);
    }

    public function testRowReplaceAtColored(): void
    {
        $row = new Row([new Cell('A', 1), new Cell('B', 2), new Cell('C', 3)]);
        $new = $row->replaceAt(1, new Cell('X', 5));
        $this->assertSame('X', $new->charAt(1));
        $this->assertSame(5, $new->cellAt(1)->fg);
        $this->assertSame(1, $new->cellAt(0)->fg);
    }

    // --- Row append ---

    public function testRowAppendPlain(): void
    {
        $a = Row::fromString('Hello');
        $b = Row::fromString(' World');
        $result = $a->append($b);
        $this->assertSame('Hello World', $result->toText());
        $this->assertSame(11, $result->length());
    }

    public function testRowAppendMixed(): void
    {
        $plain = Row::fromString('Hi');
        $colored = new Row([new Cell('!', 1)]);
        $result = $plain->append($colored);
        $this->assertSame('Hi!', $result->toText());
        $this->assertTrue($result->hasColor());
    }

    // --- Row pad ---

    public function testRowPadPlain(): void
    {
        $row = Row::fromString('AB');
        $padded = $row->pad(5);
        $this->assertSame('AB   ', $padded->toText());
        $this->assertSame(5, $padded->length());
    }

    public function testRowPadNoOp(): void
    {
        $row = Row::fromString('ABCDE');
        $padded = $row->pad(3);
        $this->assertSame($row, $padded);
    }

    public function testRowPadColored(): void
    {
        $row = new Row([new Cell('A', 1)]);
        $padded = $row->pad(3);
        $this->assertSame(3, $padded->length());
        $this->assertSame(1, $padded->cellAt(0)->fg);
        $this->assertNull($padded->cellAt(1)->fg);
    }

    // --- Row replaceChar ---

    public function testRowReplaceCharPlain(): void
    {
        $row = Row::fromString('A$B$C');
        $new = $row->replaceChar('$', ' ');
        $this->assertSame('A B C', $new->toText());
    }

    public function testRowReplaceCharNoMatch(): void
    {
        $row = Row::fromString('ABC');
        $same = $row->replaceChar('$', ' ');
        $this->assertSame($row, $same);
    }

    public function testRowReplaceCharColored(): void
    {
        $row = new Row([new Cell('$', 1), new Cell('A', 2)]);
        $new = $row->replaceChar('$', ' ');
        $this->assertSame(' ', $new->charAt(0));
        $this->assertSame(1, $new->cellAt(0)->fg);
        $this->assertSame(2, $new->cellAt(1)->fg);
    }

    // --- Row hasColor ---

    public function testRowHasColorPlain(): void
    {
        $this->assertFalse(Row::fromString('Hello')->hasColor());
    }

    public function testRowHasColorWithColor(): void
    {
        $row = new Row([new Cell('A', 5)]);
        $this->assertTrue($row->hasColor());
    }

    public function testRowHasColorAllNull(): void
    {
        $row = new Row([new Cell('A'), new Cell('B')]);
        $this->assertFalse($row->hasColor());
    }

    // --- Row cells ---

    public function testRowCellsPlain(): void
    {
        $row = Row::fromString('AB');
        $cells = $row->cells();
        $this->assertCount(2, $cells);
        $this->assertSame('A', $cells[0]->char);
        $this->assertSame('B', $cells[1]->char);
    }

    // --- Row fromAnsi ---

    public function testFromAnsiNoEscapes(): void
    {
        $row = Row::fromAnsi('Hello');
        $this->assertSame('Hello', $row->toText());
        $this->assertFalse($row->hasColor());
    }

    public function testFromAnsiSimpleFg(): void
    {
        $row = Row::fromAnsi("\e[31mRed\e[0m");
        $this->assertSame('Red', $row->toText());
        $this->assertTrue($row->hasColor());
        $this->assertSame(1, $row->cellAt(0)->fg);
        $this->assertSame(1, $row->cellAt(1)->fg);
        $this->assertSame(1, $row->cellAt(2)->fg);
    }

    public function testFromAnsiBrightFg(): void
    {
        $row = Row::fromAnsi("\e[91mBright\e[0m");
        $this->assertSame('Bright', $row->toText());
        $this->assertSame(9, $row->cellAt(0)->fg);
    }

    public function testFromAnsiBoldMakesBright(): void
    {
        $row = Row::fromAnsi("\e[1;31mBoldRed\e[0m");
        $this->assertSame(9, $row->cellAt(0)->fg);
    }

    public function testFromAnsiReset(): void
    {
        $row = Row::fromAnsi("\e[31mR\e[0mN");
        $this->assertSame(1, $row->cellAt(0)->fg);
        $this->assertNull($row->cellAt(1)->fg);
    }

    public function testFromAnsiBg(): void
    {
        $row = Row::fromAnsi("\e[41mX\e[0m");
        $this->assertSame(1, $row->cellAt(0)->bg);
    }

    public function testFromAnsiBrightBg(): void
    {
        $row = Row::fromAnsi("\e[101mX\e[0m");
        $this->assertSame(9, $row->cellAt(0)->bg);
    }

    public function testFromAnsiDefaultFg(): void
    {
        $row = Row::fromAnsi("\e[31mR\e[39mD");
        $this->assertSame(1, $row->cellAt(0)->fg);
        $this->assertNull($row->cellAt(1)->fg);
    }

    public function testFromAnsiDefaultBg(): void
    {
        $row = Row::fromAnsi("\e[41mR\e[49mD");
        $this->assertSame(1, $row->cellAt(0)->bg);
        $this->assertNull($row->cellAt(1)->bg);
    }

    public function testFromAnsiNormalAfterBold(): void
    {
        $row = Row::fromAnsi("\e[1;31mB\e[22mN\e[0m");
        $this->assertSame(9, $row->cellAt(0)->fg);
        $this->assertSame(1, $row->cellAt(1)->fg);
    }

    public function testFromAnsiMultipleParams(): void
    {
        $row = Row::fromAnsi("\e[0;31;42mX\e[0m");
        $this->assertSame(1, $row->cellAt(0)->fg);
        $this->assertSame(2, $row->cellAt(0)->bg);
    }

    public function testFromAnsiUtf8WithColor(): void
    {
        $row = Row::fromAnsi("\e[31m┏━┃\e[0m");
        $this->assertSame('┏━┃', $row->toText());
        $this->assertSame(3, $row->length());
        $this->assertSame(1, $row->cellAt(0)->fg);
    }

    public function testFromAnsiResetOnly(): void
    {
        $row = Row::fromAnsi("\e[0mHello\e[0m");
        $this->assertSame('Hello', $row->toText());
        $this->assertFalse($row->hasColor());
    }

    public function testFromAnsiLibcacaStyle(): void
    {
        $row = Row::fromAnsi("\e[0;1;35;95mX\e[0m");
        $this->assertSame(13, $row->cellAt(0)->fg);
    }

    // --- Row toAnsi ---

    public function testToAnsiPlain(): void
    {
        $row = Row::fromString('Hello');
        $this->assertSame('Hello', $row->toAnsi());
    }

    public function testToAnsiSingleColor(): void
    {
        $row = new Row([new Cell('A', 1), new Cell('B', 1)]);
        $ansi = $row->toAnsi();
        $this->assertStringContainsString("\e[", $ansi);
        $this->assertStringContainsString("\e[0m", $ansi);
        $parsed = Row::fromAnsi($ansi);
        $this->assertSame('AB', $parsed->toText());
        $this->assertSame(1, $parsed->cellAt(0)->fg);
        $this->assertSame(1, $parsed->cellAt(1)->fg);
    }

    public function testToAnsiMultipleColors(): void
    {
        $row = new Row([new Cell('R', 1), new Cell('G', 2), new Cell('B', 4)]);
        $ansi = $row->toAnsi();
        $parsed = Row::fromAnsi($ansi);
        $this->assertSame('RGB', $parsed->toText());
        $this->assertSame(1, $parsed->cellAt(0)->fg);
        $this->assertSame(2, $parsed->cellAt(1)->fg);
        $this->assertSame(4, $parsed->cellAt(2)->fg);
    }

    public function testToAnsiBrightColors(): void
    {
        $row = new Row([new Cell('X', 12)]);
        $ansi = $row->toAnsi();
        $parsed = Row::fromAnsi($ansi);
        $this->assertSame(12, $parsed->cellAt(0)->fg);
    }

    public function testToAnsiWithBg(): void
    {
        $row = new Row([new Cell('X', 1, 2)]);
        $ansi = $row->toAnsi();
        $parsed = Row::fromAnsi($ansi);
        $this->assertSame(1, $parsed->cellAt(0)->fg);
        $this->assertSame(2, $parsed->cellAt(0)->bg);
    }

    public function testToAnsiColorResetTransition(): void
    {
        $row = new Row([new Cell('C', 1), new Cell(' '), new Cell('P')]);
        $ansi = $row->toAnsi();
        $this->assertStringContainsString("\e[0m", $ansi);
        $parsed = Row::fromAnsi($ansi);
        $this->assertSame('C P', $parsed->toText());
        $this->assertSame(1, $parsed->cellAt(0)->fg);
        $this->assertNull($parsed->cellAt(1)->fg);
    }

    public function testToAnsiRoundtrip(): void
    {
        $original = new Row([
            new Cell('A', 9), new Cell('B', 10), new Cell(' '),
            new Cell('C', null, 3), new Cell('D', 1, 2),
        ]);
        $ansi = $original->toAnsi();
        $parsed = Row::fromAnsi($ansi);
        $this->assertSame($original->toText(), $parsed->toText());
        $this->assertSame($original->length(), $parsed->length());
    }

    // --- fromAnsi: multi-byte UTF-8 with color ---

    public function testFromAnsiTwoByteUtf8WithColor(): void
    {
        // é = \xC3\xA9 (2-byte UTF-8)
        $row = Row::fromAnsi("\e[31mé\e[0m");
        $this->assertSame('é', $row->toText());
        $this->assertSame(1, $row->length());
        $this->assertSame(1, $row->cellAt(0)->fg);
    }

    public function testFromAnsiFourByteUtf8WithColor(): void
    {
        // 😀 = \xF0\x9F\x98\x80 (4-byte UTF-8)
        $row = Row::fromAnsi("\e[32m😀\e[0m");
        $this->assertSame('😀', $row->toText());
        $this->assertSame(1, $row->length());
        $this->assertSame(2, $row->cellAt(0)->fg);
    }

    public function testFromAnsiInvalidByteSkipped(): void
    {
        // \xFF is an invalid UTF-8 lead byte, should be skipped
        $row = Row::fromAnsi("\e[31mA\xFFB\e[0m");
        $this->assertSame('AB', $row->toText());
        $this->assertSame(2, $row->length());
        $this->assertSame(1, $row->cellAt(0)->fg);
        $this->assertSame(1, $row->cellAt(1)->fg);
    }

    public function testFromAnsiMixedMultiByteWithColor(): void
    {
        // Mix of 1-byte, 2-byte, 3-byte, 4-byte characters with color
        $row = Row::fromAnsi("\e[33mAé┃😀\e[0m");
        $this->assertSame('Aé┃😀', $row->toText());
        $this->assertSame(4, $row->length());
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(3, $row->cellAt($i)->fg);
        }
    }

    // --- parseSgr: bold off (code 22) ---

    public function testFromAnsiBoldOffReducesBrightFg(): void
    {
        // Bold makes red (1) into bright red (9), then code 22 reduces it back
        $row = Row::fromAnsi("\e[1;31mB\e[22mN\e[0m");
        $this->assertSame(9, $row->cellAt(0)->fg);
        $this->assertSame(1, $row->cellAt(1)->fg);
    }

    public function testFromAnsiBoldOffOnAlreadyBrightFg(): void
    {
        // Start bold + red => fg=9, then set new color green => fg=10 (bold), then unbold => fg=2
        $row = Row::fromAnsi("\e[1;31mR\e[32mG\e[22mN\e[0m");
        $this->assertSame(9, $row->cellAt(0)->fg);
        $this->assertSame(10, $row->cellAt(1)->fg);
        $this->assertSame(2, $row->cellAt(2)->fg);
    }

    public function testFromAnsiBoldOffWithNullFgIsNoop(): void
    {
        // Code 22 with no fg set should not crash or produce negative values
        $row = Row::fromAnsi("\e[22mA\e[31mR\e[0m");
        $this->assertNull($row->cellAt(0)->fg);
        $this->assertSame(1, $row->cellAt(1)->fg);
    }

    // --- parseSgr: compact 256-color codes (context-based, 256+N) ---

    public function testFromAnsiCompact256Fg(): void
    {
        // 256 + 196 = 452, default context = fg
        $row = Row::fromAnsi("\e[452mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertSame(196, $row->cellAt(0)->fg);
    }

    public function testFromAnsiCompact256Bg(): void
    {
        // 256 + 22 = 278, preceded by bg16 (41) → context = bg
        $row = Row::fromAnsi("\e[41;278mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertSame(22, $row->cellAt(0)->bg);
    }

    public function testFromAnsiCompact256FgAndBg(): void
    {
        // fg: 31 sets context=fg, 256+200=456 → fg=200
        // bg: 41 sets context=bg, 256+50=306 → bg=50
        $row = Row::fromAnsi("\e[31;456;41;306mX\e[0m");
        $this->assertSame(200, $row->cellAt(0)->fg);
        $this->assertSame(50, $row->cellAt(0)->bg);
    }

    public function testFromAnsiCompact256WithBase16(): void
    {
        // 31 = fg red (context=fg), 256+42=298 preceded by 41 (context=bg)
        $row = Row::fromAnsi("\e[31;41;298mX\e[0m");
        $this->assertSame(1, $row->cellAt(0)->fg);
        $this->assertSame(42, $row->cellAt(0)->bg);
    }

    public function testFromAnsiLegacyTruecolorFg(): void
    {
        $row = Row::fromAnsi("\e[38;2;1;2;3mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertGreaterThanOrEqual(16777216, $row->cellAt(0)->fg ?? 0);
    }

    public function testFromAnsiLegacyTruecolorBg(): void
    {
        $row = Row::fromAnsi("\e[48;2;4;5;6mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertGreaterThanOrEqual(16777216, $row->cellAt(0)->bg ?? 0);
    }

    /** Compact truecolor fg: 512 + bgr24, default context = fg. */
    public function testFromAnsiCompactTruecolorFg(): void
    {
        // RGB(1,2,3) → bgr24=(3<<16)|(2<<8)|1=197121, code=197633
        $row = Row::fromAnsi("\e[197633mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertGreaterThanOrEqual(16777216, $row->cellAt(0)->fg ?? 0);
    }

    /** Compact truecolor bg: 512 + bgr24, preceded by bg16. */
    public function testFromAnsiCompactTruecolorBg(): void
    {
        // RGB(4,5,6) → bgr24=(6<<16)|(5<<8)|4=394500, code=395012
        $row = Row::fromAnsi("\e[40;395012mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertGreaterThanOrEqual(16777216, $row->cellAt(0)->bg ?? 0);
    }

    /** Context-based truecolor: channel determined by preceding 16-color code. */
    public function testFromAnsiContextTruecolorFgAndBg(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $saved = $supRef->getValue();
        $supRef->setValue(null, 2);

        try {
            // RGB(0,254,254) → bgr24=(254<<16)|(254<<8)|0=16711168, code=16711680
            $row = Row::fromAnsi("\e[31;16711680;41;16711680mX\e[0m");
            $this->assertSame('X', $row->toText());

            $ansi = $row->toAnsi();
            $this->assertStringContainsString('38;2;0;254;254', $ansi);
            $this->assertStringContainsString('48;2;0;254;254', $ansi);
        } finally {
            $supRef->setValue(null, $saved);
        }
    }

    /** Context-based: bg-only delta uses bg16 to set channel. */
    public function testFromAnsiContextTruecolorBgOnly(): void
    {
        // RGB(0,254,254) → bgr24=16711168, code=16711680
        $row = Row::fromAnsi("\e[41;16711680mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertNull($row->cellAt(0)->fg);
        $this->assertGreaterThanOrEqual(16777216, $row->cellAt(0)->bg ?? 0);
    }

    /** Compact truecolor: 512 + rgb24, min/max range. */
    public function testFromAnsiCompactTruecolorMinMaxRgb(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $saved = $supRef->getValue();
        $supRef->setValue(null, 2);

        try {
            // FG min: 512 + 0 = 512 (black)
            $rowMin = Row::fromAnsi("\e[512mX\e[0m");
            $ansiMin = $rowMin->toAnsi();
            $this->assertStringContainsString('38;2;0;0;0', $ansiMin);

            // FG max: 512 + 16777215 = 16777727 (white)
            $rowMax = Row::fromAnsi("\e[16777727mX\e[0m");
            $ansiMax = $rowMax->toAnsi();
            $this->assertStringContainsString('38;2;255;255;255', $ansiMax);

            // BG min: 512 preceded by bg16
            $rowBgMin = Row::fromAnsi("\e[40;512mX\e[0m");
            $ansiBgMin = $rowBgMin->toAnsi();
            $this->assertStringContainsString('48;2;0;0;0', $ansiBgMin);

            // BG max: 16777727 preceded by bg16
            $rowBgMax = Row::fromAnsi("\e[47;16777727mX\e[0m");
            $ansiBgMax = $rowBgMax->toAnsi();
            $this->assertStringContainsString('48;2;255;255;255', $ansiBgMax);
        } finally {
            $supRef->setValue(null, $saved);
        }
    }

    /** 511 is last compact 256-color slot (256+255); 512 is first truecolor. */
    public function testCompact511Is256Not512IsTruecolor(): void
    {
        // 256 + 255 = 511 → 256-color index 255 (default context = fg)
        $row511 = Row::fromAnsi("\e[511mX\e[0m");
        $this->assertSame(255, $row511->cellAt(0)->fg);

        // 512 → truecolor rgb24=0 (black, default context = fg)
        $row512 = Row::fromAnsi("\e[512mX\e[0m");
        $this->assertGreaterThanOrEqual(16777216, $row512->cellAt(0)->fg ?? 0);
    }

    public function testFromAnsiIgnoresOutOfRangeCompactTruecolor(): void
    {
        // 16,777,727 is the max valid compact truecolor code (512 + 0xFFFFFF).
        // Larger values must be ignored as unknown SGR parameters.
        $row = Row::fromAnsi("\e[16777728mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertNull($row->cellAt(0)->fg);
        $this->assertNull($row->cellAt(0)->bg);
    }

    public function testFromAnsiIgnoresOutOfRangeCompactTruecolorButContinuesParsing(): void
    {
        $row = Row::fromAnsi("\e[16777728;31mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertSame(1, $row->cellAt(0)->fg);
        $this->assertNull($row->cellAt(0)->bg);
    }

    public function testFromAnsiCompactTruecolorMaxUsesBackgroundContext(): void
    {
        $row = Row::fromAnsi("\e[41;16777727mX\e[0m");
        $this->assertSame('X', $row->toText());
        $this->assertNull($row->cellAt(0)->fg);
        $this->assertGreaterThanOrEqual(16777216, $row->cellAt(0)->bg ?? 0);
    }

    // --- CUF (Cursor Forward) parsing ---

    public function testFromAnsiCufInsertSpaces(): void
    {
        $row = Row::fromAnsi("\e[31mA\e[3CB\e[0m");
        $this->assertSame(5, $row->length());
        $this->assertSame('A', $row->cellAt(0)->char);
        $this->assertSame(' ', $row->cellAt(1)->char);
        $this->assertSame(' ', $row->cellAt(2)->char);
        $this->assertSame(' ', $row->cellAt(3)->char);
        $this->assertSame('B', $row->cellAt(4)->char);
    }

    public function testFromAnsiCufDefaultIsOne(): void
    {
        $row = Row::fromAnsi("A\e[CB");
        $this->assertSame(3, $row->length());
        $this->assertSame('A', $row->cellAt(0)->char);
        $this->assertSame(' ', $row->cellAt(1)->char);
        $this->assertSame('B', $row->cellAt(2)->char);
    }

    public function testFromAnsiCufTrailingSpaces(): void
    {
        $row = Row::fromAnsi("\e[31mA\e[m\e[6C");
        $this->assertSame(7, $row->length());
        $this->assertSame('A', $row->cellAt(0)->char);
        for ($i = 1; $i <= 6; $i++) {
            $this->assertSame(' ', $row->cellAt($i)->char, "Cell $i should be space");
        }
    }

    public function testFromAnsiCufSpacesHaveNoColor(): void
    {
        $row = Row::fromAnsi("\e[31mA\e[3CB\e[0m");
        $this->assertNull($row->cellAt(1)->fg);
        $this->assertNull($row->cellAt(2)->fg);
        $this->assertNull($row->cellAt(3)->fg);
    }

    // --- buildSgr: 256-color output sequences and color downgrading ---
    /** @return array{ReflectionProperty, ReflectionProperty, ReflectionProperty} */
    private function getColorStaticRefs(): array
    {
        $supportRef = new ReflectionProperty(AnsiColor::class, 'colorSupport');
        $cacheRef = new ReflectionProperty(AnsiColor::class, 'downgradeCache');
        $nearest256Ref = new ReflectionProperty(AnsiColor::class, 'nearest256Cache');
        return [$supportRef, $cacheRef, $nearest256Ref];
    }

    public function testToAnsi256ColorFgProducesExtendedSequence(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $saved = $supRef->getValue();
        $supRef->setValue(null, 1);

        try {
            $row = new Row([new Cell('X', 200)]);
            $ansi = $row->toAnsi();
            $this->assertStringContainsString('38;5;200', $ansi);
        } finally {
            $supRef->setValue(null, $saved);
        }
    }

    public function testToAnsi256ColorBgProducesExtendedSequence(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $saved = $supRef->getValue();
        $supRef->setValue(null, 1);

        try {
            $row = new Row([new Cell('X', null, 50)]);
            $ansi = $row->toAnsi();
            $this->assertStringContainsString('48;5;50', $ansi);
        } finally {
            $supRef->setValue(null, $saved);
        }
    }

    public function testToAnsiDowngrade256ColorFgToBase16(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, 0);
        $cacheRef->setValue(null, []);

        try {
            $row = new Row([new Cell('X', 196)]);
            $ansi = $row->toAnsi();
            $this->assertStringNotContainsString('38;5;', $ansi);
            $this->assertStringContainsString("\e[0;", $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
        }
    }

    public function testToAnsiDowngrade256ColorBgToBase16(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, 0);
        $cacheRef->setValue(null, []);

        try {
            $row = new Row([new Cell('X', null, 22)]);
            $ansi = $row->toAnsi();
            $this->assertStringNotContainsString('48;5;', $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
        }
    }

    /** Context-based roundtrip: fg + bg truecolor via 512+rgb24 with 16-color context. */
    public function testToAnsiTruecolorProduces24BitSequenceContext(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $saved = $supRef->getValue();
        $supRef->setValue(null, 2);

        try {
            // fg: 30 (context=fg), 512+197121=197633 → RGB(1,2,3)
            // bg: 40 (context=bg), 512+394500=395012 → RGB(4,5,6)
            $row = Row::fromAnsi("\e[30;197633;40;395012mX\e[0m");
            $ansi = $row->toAnsi();
            $this->assertStringContainsString('38;2;1;2;3', $ansi);
            $this->assertStringContainsString('48;2;4;5;6', $ansi);
        } finally {
            $supRef->setValue(null, $saved);
        }
    }

    /** Context-based roundtrip: same truecolor for both fg and bg. */
    public function testToAnsiTruecolorSameValueFgBg(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $saved = $supRef->getValue();
        $supRef->setValue(null, 2);

        try {
            // 512+197121=197633 → RGB(1,2,3) for both channels
            $row = Row::fromAnsi("\e[30;197633;40;197633mX\e[0m");
            $ansi = $row->toAnsi();
            $this->assertStringContainsString('38;2;1;2;3', $ansi);
            $this->assertStringContainsString('48;2;1;2;3', $ansi);
        } finally {
            $supRef->setValue(null, $saved);
        }
    }

    public function testToAnsiTruecolorFallsBackTo256(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $saved = $supRef->getValue();
        $supRef->setValue(null, 1);

        try {
            // RGB(18,52,86) → bgr24=(86<<16)|(52<<8)|18=5636114, code=5636626
            $row = Row::fromAnsi("\e[5636626mX\e[0m");
            $ansi = $row->toAnsi();
            $this->assertStringContainsString('38;5;', $ansi);
            $this->assertStringNotContainsString('38;2;', $ansi);
        } finally {
            $supRef->setValue(null, $saved);
        }
    }

    public function testToAnsiTruecolorFallsBackTo16(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, 0);
        $cacheRef->setValue(null, []);

        try {
            // RGB(1,2,3) → bgr24=197121, code=197633
            $row = Row::fromAnsi("\e[197633mX\e[0m");
            $ansi = $row->toAnsi();
            $this->assertStringNotContainsString('38;2;', $ansi);
            $this->assertStringNotContainsString('38;5;', $ansi);
            $this->assertStringContainsString("\e[0;", $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
        }
    }

    /** TLF multi-layer SGR: use embedded base-16 for 16-color output, not RGB downgrade of 256/truecolor. */
    public function testToAnsiSixteenColorUsesTlfEmbeddedBase16Layer(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, 0);
        $cacheRef->setValue(null, []);

        try {
            $row = Row::fromAnsi("\e[31;308mX\e[0m");
            $this->assertSame(52, $row->cellAt(0)->fg);
            $this->assertSame(1, $row->cellAt(0)->fgBase16);
            $ansi = $row->toAnsi();
            $this->assertStringContainsString(';31m', $ansi);
            $this->assertStringNotContainsString('38;5;', $ansi);

            $rowTc = Row::fromAnsi("\e[31;512mX\e[0m");
            $this->assertSame(1, $rowTc->cellAt(0)->fgBase16);
            $this->assertGreaterThanOrEqual(AnsiColor::TRUECOLOR_INTERNAL_BASE, $rowTc->cellAt(0)->fg ?? 0);
            $ansiTc = $rowTc->toAnsi();
            $this->assertStringContainsString(';31m', $ansiTc);
            $this->assertStringNotContainsString('38;2;', $ansiTc);
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
        }
    }

    public function testToAnsiDowngradeUsesCache(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, 0);
        $cacheRef->setValue(null, []);

        try {
            $row1 = new Row([new Cell('A', 196)]);
            $ansi1 = $row1->toAnsi();
            $row2 = new Row([new Cell('B', 196)]);
            $ansi2 = $row2->toAnsi();
            $this->assertSame(
                str_replace('A', 'B', $ansi1),
                $ansi2,
            );
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
        }
    }

    public function testToAnsiDowngradeColorBelow16PassesThrough(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, 0);
        $cacheRef->setValue(null, []);

        try {
            $row = new Row([new Cell('X', 5)]);
            $ansi = $row->toAnsi();
            $parsed = Row::fromAnsi($ansi);
            $this->assertSame(5, $parsed->cellAt(0)->fg);
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
        }
    }

    // --- applySgrCode: negative/inverse video (codes 7 and 27) ---

    public function testFromAnsiNegativeVideoSwapsFgBg(): void
    {
        $row = Row::fromAnsi("\e[32;45m\e[7mX\e[0m");
        $this->assertSame(5, $row->cellAt(0)->fg);
        $this->assertSame(2, $row->cellAt(0)->bg);
    }

    public function testFromAnsiNegativeVideoThenNormal(): void
    {
        $row = Row::fromAnsi("\e[32;45m\e[7mX\e[27mY\e[0m");
        $this->assertSame(5, $row->cellAt(0)->fg);
        $this->assertSame(2, $row->cellAt(0)->bg);
        $this->assertSame(2, $row->cellAt(1)->fg);
        $this->assertSame(5, $row->cellAt(1)->bg);
    }

    // --- applySgrCode: bold applied to already-set fg (code 1 with fg < 8 already active) ---

    public function testFromAnsiBoldBoostsAlreadySetFg(): void
    {
        $row = Row::fromAnsi("\e[31m\e[1mX\e[0m");
        $this->assertSame(9, $row->cellAt(0)->fg);
    }

    public function testFromAnsiBoldDoesNotBoostFgOf8OrMore(): void
    {
        $row = Row::fromAnsi("\e[91m\e[1mX\e[0m");
        $this->assertSame(9, $row->cellAt(0)->fg);
    }

    // --- applySgrCode: unrecognized SGR code (default arm) ---

    public function testFromAnsiUnrecognizedSgrCodeIsIgnored(): void
    {
        $row = Row::fromAnsi("\e[31m\e[50mX\e[0m");
        $this->assertSame(1, $row->cellAt(0)->fg);
    }

    // --- buildSgr: bright background (bg in range 8-15) ---

    public function testToAnsiBrightBackground(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $saved = $supRef->getValue();
        $supRef->setValue(null, 1);

        try {
            $row = new Row([new Cell('X', null, 9)]);
            $ansi = $row->toAnsi();
            $this->assertStringContainsString(';' . (92 + 9), $ansi);
            $parsed = Row::fromAnsi($ansi);
            $this->assertSame(9, $parsed->cellAt(0)->bg);
        } finally {
            $supRef->setValue(null, $saved);
        }
    }

    // --- color support detection: TERM / COLORTERM env-var paths ---

    public function testToAnsiColortermTruecolorEmits38_5ForAnsi256Fg(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $supRef->setValue(null, null);

        $savedColorterm = getenv('COLORTERM');
        putenv('COLORTERM=truecolor');

        try {
            $row = new Row([new Cell('X', 100)]);
            $ansi = $row->toAnsi();
            $this->assertStringContainsString('38;5;100', $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            putenv($savedColorterm !== false ? 'COLORTERM=' . $savedColorterm : 'COLORTERM');
        }
    }

    public function testToAnsiColorterm24bitEmits38_5ForAnsi256Fg(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $supRef->setValue(null, null);

        $savedColorterm = getenv('COLORTERM');
        putenv('COLORTERM=24bit');

        try {
            $row = new Row([new Cell('X', 200)]);
            $ansi = $row->toAnsi();
            $this->assertStringContainsString('38;5;200', $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            putenv($savedColorterm !== false ? 'COLORTERM=' . $savedColorterm : 'COLORTERM');
        }
    }

    public function testToAnsiTerm256colorEmits38_5ForAnsi256Fg(): void
    {
        [$supRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $supRef->setValue(null, null);

        $savedColorterm = getenv('COLORTERM');
        $savedTerm = getenv('TERM');
        putenv('COLORTERM=');
        putenv('TERM=xterm-256color');

        try {
            $row = new Row([new Cell('X', 100)]);
            $ansi = $row->toAnsi();
            $this->assertStringContainsString('38;5;100', $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            putenv($savedColorterm !== false ? 'COLORTERM=' . $savedColorterm : 'COLORTERM');
            putenv($savedTerm !== false ? 'TERM=' . $savedTerm : 'TERM');
        }
    }

    public function testToAnsiTermXtermDowngradesAnsi256FgWithout38_5(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, null);
        $cacheRef->setValue(null, []);

        $savedColorterm = getenv('COLORTERM');
        $savedTerm = getenv('TERM');
        putenv('COLORTERM=');
        putenv('TERM=xterm');

        try {
            $row = new Row([new Cell('X', 100)]);
            $ansi = $row->toAnsi();
            $this->assertStringNotContainsString('38;5;', $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
            putenv($savedColorterm !== false ? 'COLORTERM=' . $savedColorterm : 'COLORTERM');
            putenv($savedTerm !== false ? 'TERM=' . $savedTerm : 'TERM');
        }
    }

    public function testToAnsiAbsentTermDowngradesAnsi256FgWithout38_5(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, null);
        $cacheRef->setValue(null, []);

        $savedColorterm = getenv('COLORTERM');
        $savedTerm = getenv('TERM');
        putenv('COLORTERM=');
        putenv('TERM');

        try {
            $row = new Row([new Cell('X', 100)]);
            $ansi = $row->toAnsi();
            $this->assertStringNotContainsString('38;5;', $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
            putenv($savedColorterm !== false ? 'COLORTERM=' . $savedColorterm : 'COLORTERM');
            putenv($savedTerm !== false ? 'TERM=' . $savedTerm : 'TERM');
        }
    }

    // --- downgradeColor / ansi256ToRgb: grayscale range (color >= 232) ---

    public function testToAnsiDowngradeGrayscale256ColorToBase16(): void
    {
        [$supRef, $cacheRef] = $this->getColorStaticRefs();
        $savedSup = $supRef->getValue();
        $savedCache = $cacheRef->getValue();
        $supRef->setValue(null, 0);
        $cacheRef->setValue(null, []);

        try {
            $row = new Row([new Cell('X', 232)]);
            $ansi = $row->toAnsi();
            $this->assertStringNotContainsString('38;5;', $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
            $cacheRef->setValue(null, $savedCache);
        }
    }

    public function testDowngradeColorForBasicColors(): void
    {
        $supRef = new ReflectionProperty(AnsiColor::class, 'colorSupport');
        $savedSup = $supRef->getValue();
        $supRef->setValue(null, 0);

        try {
            $row = new Row([new Cell('X', 5)]);
            $ansi = $row->toAnsi();
            $this->assertStringContainsString("\e[0;35mX", $ansi);
        } finally {
            $supRef->setValue(null, $savedSup);
        }
    }

    public function testDowngradeColorBelow16Directly(): void
    {
        $ref = new ReflectionMethod(AnsiColor::class, 'downgradeColor');
        $this->assertSame(5, $ref->invoke(null, 5));
    }

    public function testRendererFallbackColorForNegativeIndex(): void
    {
        $row = new Row([new Cell('A', -1)]);
        $html = Renderer::export([$row], ExportFormat::Html);
        $this->assertStringContainsString('<span style="color:#000000">A</span>', $html);
    }

    public function testRendererHtmlExportsTruecolorCells(): void
    {
        $row = Row::fromAnsi("\e[38;2;1;2;3;48;2;4;5;6mX\e[0m");
        $html = Renderer::export([$row], ExportFormat::Html);
        $this->assertStringContainsString('color:#010203;background:#040506', $html);
    }

    // --- SmushEngine::pickSmushColor ---

    public function testPickSmushColorLeftWins(): void
    {
        $eng = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);
        $left = new Cell('A', 1);
        $right = new Cell('B', 2);
        $result = $eng->pickSmushColor('A', 'A', 'B', $left, $right);
        $this->assertSame(1, $result->fg);
    }

    public function testPickSmushColorRightWins(): void
    {
        $eng = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);
        $left = new Cell('A', 1);
        $right = new Cell('B', 2);
        $result = $eng->pickSmushColor('B', 'A', 'B', $left, $right);
        $this->assertSame(2, $result->fg);
    }

    public function testPickSmushColorSymmetricPrefersColored(): void
    {
        $eng = new SmushEngine('$', 0, 0, 0, LayoutMode::Smushing);
        $left = new Cell('X');
        $right = new Cell('X', 5);
        $result = $eng->pickSmushColor('X', 'X', 'X', $left, $right);
        $this->assertSame(5, $result->fg);
    }

    // --- Row::toAnsi() early return when cells mode but no color ---

    public function testToAnsiOnColorlessCellModeRowReturnsPlainText(): void
    {
        $row = new Row([new Cell('H'), new Cell('i')]);
        $this->assertFalse($row->hasColor());
        $this->assertSame('Hi', $row->toAnsi());
    }

    // --- SmushEngine: two hardblanks without rule 32 → null ---

    public function testSmushEmBothHardblankWithoutRule32ReturnsNull(): void
    {
        $eng = new SmushEngine('$', 1, 0, 0, LayoutMode::Smushing);
        $result = $eng->smushem('$', '$', 2, 2);
        $this->assertNull($result);
    }

    public function testSmushEmBothHardblankWithRule32ReturnsLeft(): void
    {
        $eng = new SmushEngine('$', 32, 0, 0, LayoutMode::Smushing);
        $result = $eng->smushem('$', '$', 2, 2);
        $this->assertSame('$', $result);
    }

    // --- SmushEngine: applyHSmushRules all rules miss → null ---

    public function testSmushEmAllHSmushRulesMissReturnsNull(): void
    {
        $eng = new SmushEngine('$', 1, 0, 0, LayoutMode::Smushing);
        $result = $eng->smushem('A', 'B', 2, 2);
        $this->assertNull($result);
    }
}
