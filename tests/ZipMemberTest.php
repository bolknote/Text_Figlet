<?php

declare(strict_types=1);

namespace Bolk\TextFiglet\Tests;

use Bolk\TextFiglet\ZipMember;
use Override;
use ZipArchive;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('zip')]
final class ZipMemberTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

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

    private function tempZipPath(): string
    {
        $path = sys_get_temp_dir() . '/zipmember_' . str_replace('.', '_', uniqid('', true)) . '.zip';
        $this->tempPaths[] = $path;
        return $path;
    }

    public function testSelectsFirstMatchingPreferredName(): void
    {
        $path = $this->tempZipPath();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('b.bin', 'x');
        $zip->addFromString('a.flf', 'flf');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, ['a.flf', 'a.tlf']);
        } finally {
            $zip->close();
        }

        $this->assertSame('a.flf', $name);
    }

    public function testSecondPreferredWhenFirstMissing(): void
    {
        $path = $this->tempZipPath();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('z.tlf', 'tlf');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, ['missing.flf', 'z.tlf']);
        } finally {
            $zip->close();
        }

        $this->assertSame('z.tlf', $name);
    }

    public function testSkipsEmptyPreferredString(): void
    {
        $path = $this->tempZipPath();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('only.txt', 'hi');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, ['', 'missing.flc']);
        } finally {
            $zip->close();
        }

        $this->assertSame('only.txt', $name);
    }

    public function testPreferredLocatesDirectoryEntrySkipsToNextPreferred(): void
    {
        $path = $this->tempZipPath();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addEmptyDir('nest');
        $zip->addFromString('real.flf', 'x');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, ['nest/', 'real.flf']);
        } finally {
            $zip->close();
        }

        $this->assertSame('real.flf', $name);
    }

    public function testFallbackSkipsLeadingDirectoryEntries(): void
    {
        $path = $this->tempZipPath();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addEmptyDir('emptydir');
        $zip->addFromString('inside.txt', 'x');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, ['none.flc']);
        } finally {
            $zip->close();
        }

        $this->assertSame('inside.txt', $name);
    }

    public function testReturnsNullForEmptyArchive(): void
    {
        $path = $this->tempZipPath();
        file_put_contents($path, "PK\x05\x06" . str_repeat("\x00", 18));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, ['a.flf']);
        } finally {
            $zip->close();
        }

        $this->assertNull($name);
    }

    public function testReturnsNullWhenOnlyDirectoryMembersExist(): void
    {
        $path = $this->tempZipPath();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addEmptyDir('solo');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, []);
        } finally {
            $zip->close();
        }

        $this->assertNull($name);
    }

    public function testEmptyPreferredListSkipsStraightToFallback(): void
    {
        $path = $this->tempZipPath();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('first.dat', '1');
        $zip->addFromString('second.dat', '2');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, []);
        } finally {
            $zip->close();
        }

        $this->assertSame('first.dat', $name);
    }

    public function testWhenOnlyPreferredMatchIsDirectoryFallsBackToFirstFile(): void
    {
        $path = $this->tempZipPath();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addEmptyDir('onlydir');
        $zip->addFromString('payload.flc', 'x');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        try {
            $name = ZipMember::selectName($zip, ['onlydir/']);
        } finally {
            $zip->close();
        }

        $this->assertSame('payload.flc', $name);
    }
}
