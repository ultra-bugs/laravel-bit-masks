<?php
/*
 *          M""""""""`M            dP
 *          Mmmmmm   .M            88
 *          MMMMP  .MMM  dP    dP  88  .dP   .d8888b.
 *          MMP  .MMMMM  88    88  88888"    88'  `88
 *          M' .MMMMMMM  88.  .88  88  `8b.  88.  .88
 *          M         M  `88888P'  dP   `YP  `88888P'
 *          MMMMMMMMMMM    -*-  Created by Zuko  -*-
 *
 *          * * * * * * * * * * * * * * * * * * * * *
 *          * -    - -   F.R.E.E.M.I.N.D   - -    - *
 *          * -  Copyright © 2026 (Z) Programing  - *
 *          *    -  -  All Rights Reserved  -  -    *
 *          * * * * * * * * * * * * * * * * * * * * *
 */

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
