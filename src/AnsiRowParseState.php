<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

/** @internal Mutable scratch state while parsing ANSI text into Row cells. */
final class AnsiRowParseState
{
    /** @var list<Cell> */
    public array $cells = [];

    public ?int $fg = null;

    public ?int $bg = null;

    public bool $bold = false;

    public bool $negative = false;

    public ?int $fgBase16 = null;

    public ?int $bgBase16 = null;

    public bool $hasColor = false;
}
