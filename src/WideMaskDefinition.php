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

use BackedEnum;
use InvalidArgumentException;
use Zuko\BitMasks\Support\FlagName;

/**
 * Describes a single logical bitmask that spans one or more BIGINT storage
 * columns, so a caller can address up to (columns × 63) flags through one name.
 *
 * A flag is identified by its GLOBAL INDEX (0-based). Index n routes to storage
 * column `n / bitsPerColumn` and local bit `n % bitsPerColumn`; the bit is stored
 * as the power of two `1 << (n % bitsPerColumn)` inside that column.
 *
 * This differs from the single-column {@see BitMask} convention where a flag is
 * the power-of-two value itself: a wide mask cannot be collapsed into one PHP int
 * (a signed 64-bit int only holds 63 usable bits), so flags are indices and the
 * value is kept decomposed per column.
 *
 * A bound int-backed enum therefore uses the GLOBAL INDEX as each case's backing
 * value (e.g. `case Gmail = 0; case Foo = 64;`), not `1 << n`.
 */
final class WideMaskDefinition
{
    /**
     * @param  string  $name  logical attribute name exposed on the model
     * @param  list<string>  $columns  ordered storage column names
     * @param  class-string<BackedEnum>|null  $enum  bound flag enum, if any
     * @param  int  $bitsPerColumn  usable bits per column (63 = bit 63 reserved for sign)
     */
    public function __construct(
        public readonly string $name,
        private readonly array $columns,
        public readonly ?string $enum = null,
        public readonly int $bitsPerColumn = BitMask::MAX_BIT + 1,
    ) {
        if ($this->columns === []) {
            throw new InvalidArgumentException(sprintf('Wide mask [%s] must declare at least one storage column.', $name));
        }

        if ($this->bitsPerColumn < 1 || $this->bitsPerColumn > BitMask::MAX_BIT + 1) {
            throw new InvalidArgumentException(sprintf('bitsPerColumn for wide mask [%s] must be between 1 and %d.', $name, BitMask::MAX_BIT + 1));
        }
    }

    /**
     * Ordered storage column names.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Whether this mask spans more than one storage column.
     */
    public function isWide(): bool
    {
        return count($this->columns) > 1;
    }

    /**
     * Total number of addressable flags across every storage column.
     */
    public function capacity(): int
    {
        return count($this->columns) * $this->bitsPerColumn;
    }

    /**
     * Route a global flag index to its [column name, bit value] pair.
     *
     * @return array{0: string, 1: int}
     */
    public function route(int $index): array
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Flag index must be a non-negative integer.');
        }

        $columnIndex = intdiv($index, $this->bitsPerColumn);

        if ($columnIndex >= count($this->columns)) {
            throw new InvalidArgumentException(sprintf(
                'Flag index %d exceeds the capacity of wide mask [%s] (%d flags across %d columns).',
                $index,
                $this->name,
                $this->capacity(),
                count($this->columns)
            ));
        }

        return [$this->columns[$columnIndex], 1 << ($index % $this->bitsPerColumn)];
    }

    /**
     * Resolve any flag-ish value into the global index it represents.
     *
     * Accepts int-backed enum cases and non-negative ints (both interpreted as
     * global indices), plus flag NAMES when an enum is bound. Unlike
     * {@see BitMask::resolve()}, a plain int here is a single flag index, not a
     * combined power-of-two mask.
     */
    public function flagIndex(mixed $flag): int
    {
        if ($flag instanceof BackedEnum) {
            if (! is_int($flag->value)) {
                throw new InvalidArgumentException(sprintf('[%s] is a string-backed enum. Wide-mask flags must be int-backed.', $flag::class));
            }

            return $flag->value;
        }

        if (is_int($flag)) {
            if ($flag < 0) {
                throw new InvalidArgumentException('Flag index must be a non-negative integer.');
            }

            return $flag;
        }

        if (is_string($flag)) {
            if (ctype_digit($flag)) {
                return (int) $flag;
            }

            if ($this->enum !== null) {
                return $this->flagIndex(FlagName::resolve($this->enum, $flag));
            }

            throw new InvalidArgumentException(sprintf('Cannot resolve flag name [%s] on wide mask [%s]: no flag enum is bound.', $flag, $this->name));
        }

        throw new InvalidArgumentException(sprintf('Cannot resolve [%s] into a wide-mask flag index.', get_debug_type($flag)));
    }

    /**
     * Decompose any flag-ish value into per-column OR masks.
     *
     * The returned array always contains every storage column (0 when untouched),
     * keyed by column name in definition order — ready for persistence, equality
     * checks and bitwise predicates.
     *
     * Accepts: null, global-index ints, int-backed enum cases, {@see WideBitMask}
     * instances, and (nested) iterables of any of those.
     *
     * @return array<string, int>
     */
    public function contributions(mixed $flags): array
    {
        $columns = array_fill_keys($this->columns, 0);

        $this->accumulate($flags, $columns);

        return $columns;
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function accumulate(mixed $flags, array &$columns): void
    {
        if ($flags === null) {
            return;
        }

        if ($flags instanceof WideBitMask) {
            foreach ($flags->columns() as $column => $value) {
                if (array_key_exists($column, $columns)) {
                    $columns[$column] |= $value;
                }
            }

            return;
        }

        if ($flags instanceof BackedEnum || is_int($flags) || is_string($flags)) {
            [$column, $bit] = $this->route($this->flagIndex($flags));
            $columns[$column] |= $bit;

            return;
        }

        if (is_iterable($flags)) {
            foreach ($flags as $flag) {
                $this->accumulate($flag, $columns);
            }

            return;
        }

        throw new InvalidArgumentException(sprintf('Cannot resolve [%s] into wide-mask flags.', get_debug_type($flags)));
    }
}
