<?php

declare(strict_types=1);

namespace Bolk\TextFiglet\Tests;

use Bolk\TextFiglet\Utf8Decoder;
use PHPUnit\Framework\TestCase;

final class Utf8DecoderTest extends TestCase
{
    public function testAsciiRoundTripCodes(): void
    {
        $this->assertSame([72, 105], Utf8Decoder::decode('Hi'));
    }

    public function testDecodeWithPercentU(): void
    {
        $this->assertSame([0x41], Utf8Decoder::decode('%u0041', true));
        $this->assertSame([37, 117, 0x30, 0x30, 0x34, 0x31], Utf8Decoder::decode('%u0041', false));
    }

    public function testTwoByteSequence(): void
    {
        $this->assertSame([0xA2], Utf8Decoder::decode("\xC2\xA2"));
    }

    public function testTruncatedMultibyteReplaced(): void
    {
        $this->assertSame([128], Utf8Decoder::decode("\xC2"));
        $this->assertSame([128], Utf8Decoder::decode("\xE0\xA4"));
        $this->assertSame([128], Utf8Decoder::decode("\xF0\x90\x80"));
    }

    public function testFourByteCodePoint(): void
    {
        $this->assertSame([0x1F4A9], Utf8Decoder::decode("\xF0\x9F\x92\xA9"));
    }

    public function testInvalidContinuationYieldsReplacement(): void
    {
        $this->assertSame([128, 65], Utf8Decoder::decode("\xC2\x41"));
    }
}
