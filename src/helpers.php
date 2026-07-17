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

use Zuko\BitMasks\BitMask;

if (! function_exists('bitmask')) {
    /**
     * Create a BitMask from any flag-ish value (int, enum case, BitMask,
     * iterable of those). Optionally bind a flag enum for name resolution.
     *
     *   bitmask(0b101)->bits();                       // [0, 2]
     *   bitmask([Network::Gmail, Network::Yahoo]);    // BitMask(3) bound to Network
     *   bitmask(5, Network::class)->names();          // ['Gmail', 'Outlook']
     *
     * @param  class-string<BackedEnum>|null  $enum
     */
    function bitmask(mixed $flags = 0, ?string $enum = null): BitMask
    {
        return BitMask::from($flags, $enum);
    }
}
