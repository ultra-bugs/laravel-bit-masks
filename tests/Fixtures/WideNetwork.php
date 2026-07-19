<?php

namespace Zuko\BitMasks\Tests\Fixtures;

/**
 * Wide-mask flag enum: cases are backed by their GLOBAL INDEX (not a power of
 * two), so flags can span more storage columns than a single 63-bit integer.
 *
 * With 63 usable bits per column: indices 0..62 live in column 1, 63..125 in
 * column 2.
 */
enum WideNetwork: int
{
    case Gmail = 0;      // column 1, bit 0

    case Yahoo = 1;      // column 1, bit 1

    case Outlook = 2;    // column 1, bit 2

    case Hotmail = 62;   // column 1, bit 62 (last of column 1)

    case Proton = 63;    // column 2, bit 0 (first of column 2)

    case Fastmail = 64;  // column 2, bit 1

    case Icloud = 125;   // column 2, bit 62 (last of column 2)
}
