<?php

namespace Zuko\BitMasks\Tests\Fixtures;

/**
 * Pivot-mask flag enum. Cases are backed by arbitrary flag ids (not bit
 * positions or powers of two) — the junction table stores one row per id — so
 * the set of flags can grow without the 63-bit ceiling of a single column.
 */
enum PivotNetwork: int
{
    case Gmail = 1;

    case Yahoo = 2;

    case Outlook = 3;

    case Proton = 100;

    case Icloud = 250;
}
