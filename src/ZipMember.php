<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

use ZipArchive;

/**
 * Pick which ZIP member to open for FIGlet fonts / control files.
 *
 * FIGlet's zipio (cmatsuoka/figlet) reads only the first local file record in the archive.
 * When several members exist, we first try inner paths built from the archive basename
 * (e.g. `mypack.zip` → `mypack.flf`) before falling back to the first non-directory entry.
 */
final class ZipMember
{
    /**
     * @param list<string> $preferredInnerNames Paths inside the archive, tried in order
     */
    public static function selectName(ZipArchive $zip, array $preferredInnerNames): ?string
    {
        foreach ($preferredInnerNames as $inner) {
            if ($inner === '') {
                continue;
            }
            $idx = $zip->locateName($inner);
            if ($idx !== false) {
                $name = $zip->getNameIndex($idx);
                if (is_string($name) && $name !== '' && !str_ends_with($name, '/')) {
                    return $name;
                }
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '' || str_ends_with($name, '/')) {
                continue;
            }
            return $name;
        }

        return null;
    }
}
