<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

use FFI;
use FFI\Exception as FFIException;

/** @internal */
final class TerminalWidthDetector
{
    public static function detectViaIoctl(): int
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

            foreach ([1, 2, 0] as $fd) {
                if ($ffi->ioctl($fd, $tiocgwinsz, FFI::addr($win)) !== -1 && $win->ws_col > 0) {
                    return (int) $win->ws_col;
                }
            }
        } catch (FFIException) {
            return 0;
        }

        return 0;
    }
}
