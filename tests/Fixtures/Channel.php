<?php

namespace Zuko\BitMasks\Tests\Fixtures;

use Zuko\BitMasks\Concerns\BitMaskFlags;

enum Channel: int
{
    use BitMaskFlags;

    case Email = 1 << 0;

    case YahooMail = 1 << 1;

    case PushNotification = 1 << 2;
}
