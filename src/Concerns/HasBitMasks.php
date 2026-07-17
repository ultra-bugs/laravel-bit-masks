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

namespace Zuko\BitMasks\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Zuko\BitMasks\BitMask;
use Zuko\BitMasks\Casts\AsBitMask;

/**
 * Eloquent integration for bitmask columns.
 *
 * Declare the mask columns on the model and the trait wires up everything
 * else (casting to {@see BitMask}, instance helpers and query scopes):
 *
 *   class Subscriber extends Model
 *   {
 *       use HasBitMasks;
 *
 *       protected $bitMasks = [
 *           'networks' => Network::class, // column bound to a flag enum
 *           'toggles',                    // plain mask column
 *       ];
 *   }
 *
 *   $subscriber->networks;                                   // BitMask instance
 *   $subscriber->hasMask('networks', Network::Gmail);        // bool
 *   $subscriber->addMask('networks', Network::Yahoo)->save();
 *   Subscriber::whereMaskHas('networks', Network::Gmail)->get();
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasBitMasks
{
    /**
     * Register the AsBitMask cast for every declared mask column.
     */
    public function initializeHasBitMasks(): void
    {
        foreach ($this->bitMaskColumns() as $column => $enum) {
            if (! $this->hasCast($column)) {
                $this->mergeCasts([
                    $column => $enum === null ? AsBitMask::class : AsBitMask::using($enum),
                ]);
            }
        }
    }

    /**
     * Normalized map of declared mask columns: ['column' => enum-class|null].
     *
     * Reads the model's `$bitMasks` property, which accepts both plain column
     * names and 'column' => FlagEnum::class pairs.
     *
     * @return array<string, class-string<BackedEnum>|null>
     */
    public function bitMaskColumns(): array
    {
        $columns = [];

        foreach (property_exists($this, 'bitMasks') ? (array) $this->bitMasks : [] as $key => $value) {
            if (is_int($key)) {
                $columns[$value] = null;
            } else {
                $columns[$key] = $value;
            }
        }

        return $columns;
    }

    /**
     * The flag enum bound to the given column, if any.
     *
     * @return class-string<BackedEnum>|null
     */
    public function bitMaskEnum(string $column): ?string
    {
        return $this->bitMaskColumns()[$column] ?? null;
    }

    /**
     * Current value of the column as a BitMask (never null).
     */
    public function bitMask(string $column): BitMask
    {
        return BitMask::from($this->getAttribute($column), $this->bitMaskEnum($column));
    }

    /**
     * Whether ALL of the given flags are set on the column.
     */
    public function hasMask(string $column, mixed ...$flags): bool
    {
        return $this->bitMask($column)->has(...$flags);
    }

    /**
     * Whether AT LEAST ONE of the given flags is set on the column.
     */
    public function hasAnyMask(string $column, mixed ...$flags): bool
    {
        return $this->bitMask($column)->hasAny(...$flags);
    }

    /**
     * Whether NONE of the given flags are set on the column.
     */
    public function missingMask(string $column, mixed ...$flags): bool
    {
        return $this->bitMask($column)->hasNone(...$flags);
    }

    /**
     * Set the given flags on the column. Does not persist — chain ->save().
     */
    public function addMask(string $column, mixed ...$flags): static
    {
        $this->setAttribute($column, $this->bitMask($column)->add(...$flags));

        return $this;
    }

    /**
     * Unset the given flags on the column. Does not persist — chain ->save().
     */
    public function removeMask(string $column, mixed ...$flags): static
    {
        $this->setAttribute($column, $this->bitMask($column)->remove(...$flags));

        return $this;
    }

    /**
     * Toggle the given flags on the column. Does not persist — chain ->save().
     */
    public function toggleMask(string $column, mixed ...$flags): static
    {
        $this->setAttribute($column, $this->bitMask($column)->toggle(...$flags));

        return $this;
    }

    /**
     * Replace the column value with the given flags entirely.
     */
    public function setMask(string $column, mixed $flags): static
    {
        $this->setAttribute($column, BitMask::resolve($flags));

        return $this;
    }

    /**
     * Reset the column to an empty mask.
     */
    public function clearMask(string $column): static
    {
        $this->setAttribute($column, 0);

        return $this;
    }

    /**
     * Scope: rows where ALL given flags are set. SQL: (column & mask) = mask
     */
    public function scopeWhereMaskHas(Builder $query, string $column, mixed $flags, string $boolean = 'and'): Builder
    {
        $mask = BitMask::resolve($flags);

        return $query->whereRaw('(' . $this->bitMaskColumnSql($query, $column) . ' & ?) = ?', [$mask, $mask], $boolean);
    }

    /**
     * Scope: OR variant of whereMaskHas().
     */
    public function scopeOrWhereMaskHas(Builder $query, string $column, mixed $flags): Builder
    {
        return $this->scopeWhereMaskHas($query, $column, $flags, 'or');
    }

    /**
     * Scope: rows where AT LEAST ONE given flag is set. SQL: (column & mask) != 0
     */
    public function scopeWhereMaskHasAny(Builder $query, string $column, mixed $flags, string $boolean = 'and'): Builder
    {
        return $query->whereRaw('(' . $this->bitMaskColumnSql($query, $column) . ' & ?) != 0', [BitMask::resolve($flags)], $boolean);
    }

    /**
     * Scope: OR variant of whereMaskHasAny().
     */
    public function scopeOrWhereMaskHasAny(Builder $query, string $column, mixed $flags): Builder
    {
        return $this->scopeWhereMaskHasAny($query, $column, $flags, 'or');
    }

    /**
     * Scope: rows where NONE of the given flags are set. SQL: (column & mask) = 0
     */
    public function scopeWhereMaskMissing(Builder $query, string $column, mixed $flags, string $boolean = 'and'): Builder
    {
        return $query->whereRaw('(' . $this->bitMaskColumnSql($query, $column) . ' & ?) = 0', [BitMask::resolve($flags)], $boolean);
    }

    /**
     * Scope: OR variant of whereMaskMissing().
     */
    public function scopeOrWhereMaskMissing(Builder $query, string $column, mixed $flags): Builder
    {
        return $this->scopeWhereMaskMissing($query, $column, $flags, 'or');
    }

    /**
     * Scope: rows whose mask equals the given flags exactly.
     */
    public function scopeWhereMaskEquals(Builder $query, string $column, mixed $flags, string $boolean = 'and'): Builder
    {
        return $query->where($query->qualifyColumn($column), '=', BitMask::resolve($flags), $boolean);
    }

    /**
     * Scope: OR variant of whereMaskEquals().
     */
    public function scopeOrWhereMaskEquals(Builder $query, string $column, mixed $flags): Builder
    {
        return $this->scopeWhereMaskEquals($query, $column, $flags, 'or');
    }

    /**
     * Grammar-quoted, table-qualified column reference for raw bitwise SQL.
     */
    protected function bitMaskColumnSql(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));
    }
}
