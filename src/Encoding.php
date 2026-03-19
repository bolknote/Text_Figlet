<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

enum Encoding: int
{
    case Default = 0;
    case Utf8 = 1;
    case Hz = 2;
    case ShiftJis = 3;
    case Dbcs = 4;
    case Iso2022 = 5;
}
