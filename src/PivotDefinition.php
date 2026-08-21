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

namespace Zuko\BitMasks;

/**
 * Describes a junction-table ("pivot") backed flag set: a thin
 * `(foreignPivotKey, flagKey)` table holding one row per set flag, where
 * `flagKey` stores the int-backed enum case's value.
 *
 * This is the storage strategy for flag sets too large or too dynamic for a
 * (wide) bitmask column — the flag count is effectively unbounded and reverse
 * lookups ("which owners carry flag X?") ride a plain composite index.
 */
final class PivotDefinition
{
    /**
     * @param  string  $name  logical attribute name exposed on the model
     * @param  string  $table  junction table name
     * @param  string  $foreignPivotKey  owner-key column in the junction table
     * @param  string  $flagKey  flag-id column in the junction table
     * @param  string  $ownerKey  local key on the owning model that $foreignPivotKey references
     * @param  class-string<\BackedEnum>|null  $enum  bound flag enum (flag id = case value)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly string $foreignPivotKey,
        public readonly string $flagKey,
        public readonly string $ownerKey,
        public readonly ?string $enum = null,
    ) {
    }
}
