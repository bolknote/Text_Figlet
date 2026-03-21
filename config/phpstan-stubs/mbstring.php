<?php

// PHPStan's bundled stubs for these mbstring functions declare a narrower return
// type than the actual PHP runtime behaviour. This stub restores the real types
// so that defensive false-return checks in userland code are not reported as dead
// code. Psalm's stubs already agree with the signatures declared here.

/**
 * @param non-negative-int $codepoint
 */
function mb_chr(int $codepoint, string $encoding = ''): string|false
{
}

/**
 * @param string|array<array-key, string> $string
 * @param array<array-key, string>|string|null $from_encoding
 * @return ($string is array ? array<array-key, string>|false : string|false)
 */
function mb_convert_encoding(string|array $string, string $to_encoding, array|string|null $from_encoding = null): string|array|false
{
}
