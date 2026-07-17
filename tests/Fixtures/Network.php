<?php

namespace Zuko\BitMasks\Tests\Fixtures;

use Zuko\BitMasks\Concerns\BitMaskFlags;

enum Network: int
{
    use BitMaskFlags;

    case Gmail = 1 << 0;

    case Yahoo = 1 << 1;

    case Outlook = 1 << 2;

    case Hotmail = 1 << 3;
}
