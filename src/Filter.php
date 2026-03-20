<?php

declare(strict_types=1);

namespace Bolk\TextFiglet;

enum Filter
{
    case Crop;
    case Rainbow;
    case Metal;
    case Flip;
    case Flop;
    case Rotate180;
    case RotateLeft;
    case RotateRight;
    case Border;
}
