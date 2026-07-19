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
use Zuko\BitMasks\WideBitMask;
use Zuko\BitMasks\WideMaskDefinition;

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
 * Wide masks — a single logical mask spanning several BIGINT columns, for more
 * than 63 flags — are declared with an array value:
 *
 *   protected $bitMasks = [
 *       'networks' => ['columns' => 2, 'enum' => Network::class],
 *   ];
 *
 *   $email->networks = [Network::Gmail, Network::Foo]; // writes networks_1/networks_2
 *   $email->networks;                                  // WideBitMask instance
 *   Email::whereMaskHas('networks', Network::Foo)->get();
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasBitMasks
{
    /**
     * Per-class cache of parsed definitions: ['single' => [...], 'wide' => [...]].
     *
     * @var array<class-string, array{single: array<string, class-string<BackedEnum>|null>, wide: array<string, WideMaskDefinition>}>
     */
    protected static array $bitMaskDefinitions = [];

    /**
     * Register the appropriate cast for every declared mask column.
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

        // Wide masks are virtual: their storage columns hold plain integers.
        foreach ($this->wideBitMaskDefinitions() as $definition) {
            foreach ($definition->columns() as $column) {
                if (! $this->hasCast($column)) {
                    $this->mergeCasts([$column => 'integer']);
                }
            }
        }
    }

    /**
     * Normalized map of declared single-column masks: ['column' => enum-class|null].
     *
     * @return array<string, class-string<BackedEnum>|null>
     */
    public function bitMaskColumns(): array
    {
        return $this->parsedBitMaskDefinitions()['single'];
    }

    /**
     * Normalized map of declared wide masks: ['name' => WideMaskDefinition].
     *
     * @return array<string, WideMaskDefinition>
     */
    public function wideBitMaskDefinitions(): array
    {
        return $this->parsedBitMaskDefinitions()['wide'];
    }

    /**
     * Whether the given name refers to a declared wide mask.
     */
    public function isWideBitMask(string $name): bool
    {
        return isset($this->parsedBitMaskDefinitions()['wide'][$name]);
    }

    /**
     * The wide-mask definition for the given name, or null.
     */
    public function wideBitMaskDefinition(string $name): ?WideMaskDefinition
    {
        return $this->parsedBitMaskDefinitions()['wide'][$name] ?? null;
    }

    /**
     * Parse and cache the model's `$bitMasks` declaration once per class.
     *
     * @return array{single: array<string, class-string<BackedEnum>|null>, wide: array<string, WideMaskDefinition>}
     */
    protected function parsedBitMaskDefinitions(): array
    {
        if (isset(static::$bitMaskDefinitions[static::class])) {
            return static::$bitMaskDefinitions[static::class];
        }

        $single = [];
        $wide = [];

        foreach (property_exists($this, 'bitMasks') ? (array) $this->bitMasks : [] as $key => $value) {
            if (is_int($key)) {
                $single[$value] = null;
            } elseif (is_array($value)) {
                $wide[$key] = $this->makeWideDefinition($key, $value);
            } else {
                $single[$key] = $value;
            }
        }

        return static::$bitMaskDefinitions[static::class] = ['single' => $single, 'wide' => $wide];
    }

    /**
     * Build a WideMaskDefinition from a model's array declaration.
     *
     * Accepts `'columns'` as an integer count (deriving `{name}_1..{name}_N`) or
     * an explicit list of column names; an optional `'enum'` flag enum; and an
     * optional `'bits'` per-column width.
     *
     * @param  array<string, mixed>  $config
     */
    protected function makeWideDefinition(string $name, array $config): WideMaskDefinition
    {
        $columns = $config['columns'] ?? 2;

        if (is_int($columns)) {
            $columns = array_map(static fn (int $i) => $name . '_' . $i, range(1, $columns));
        }

        return new WideMaskDefinition(
            name: $name,
            columns: array_values($columns),
            enum: $config['enum'] ?? null,
            bitsPerColumn: $config['bits'] ?? BitMask::MAX_BIT + 1,
        );
    }

    /**
     * The flag enum bound to the given single-column mask, if any.
     *
     * @return class-string<BackedEnum>|null
     */
    public function bitMaskEnum(string $column): ?string
    {
        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $definition->enum;
        }

        return $this->bitMaskColumns()[$column] ?? null;
    }

    /**
     * Current value of a single-column mask as a BitMask (never null).
     */
    public function bitMask(string $column): BitMask
    {
        return BitMask::from($this->getAttribute($column), $this->bitMaskColumns()[$column] ?? null);
    }

    /**
     * Current value of a wide mask as a WideBitMask (never null).
     */
    public function wideBitMask(string $name): WideBitMask
    {
        $definition = $this->wideBitMaskDefinition($name);

        return WideBitMask::fromColumns($this->getAttributes(), $definition);
    }

    /**
     * Read wide masks as WideBitMask instances; everything else via Eloquent.
     */
    public function getAttribute($key)
    {
        if ($key !== null && $this->isWideBitMask($key)) {
            return $this->wideBitMask($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * Fan a wide-mask assignment out to its storage columns; delegate otherwise.
     */
    public function setAttribute($key, $value)
    {
        if ($this->isWideBitMask($key)) {
            $wide = WideBitMask::from($value, $this->wideBitMaskDefinition($key));

            foreach ($wide->columns() as $column => $columnValue) {
                parent::setAttribute($column, $columnValue);
            }

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Whether ALL of the given flags are set on the mask.
     */
    public function hasMask(string $column, mixed ...$flags): bool
    {
        return $this->maskValue($column)->has(...$flags);
    }

    /**
     * Whether AT LEAST ONE of the given flags is set on the mask.
     */
    public function hasAnyMask(string $column, mixed ...$flags): bool
    {
        return $this->maskValue($column)->hasAny(...$flags);
    }

    /**
     * Whether NONE of the given flags are set on the mask.
     */
    public function missingMask(string $column, mixed ...$flags): bool
    {
        return $this->maskValue($column)->hasNone(...$flags);
    }

    /**
     * Set the given flags on the mask. Does not persist — chain ->save().
     */
    public function addMask(string $column, mixed ...$flags): static
    {
        $this->setAttribute($column, $this->maskValue($column)->add(...$flags));

        return $this;
    }

    /**
     * Unset the given flags on the mask. Does not persist — chain ->save().
     */
    public function removeMask(string $column, mixed ...$flags): static
    {
        $this->setAttribute($column, $this->maskValue($column)->remove(...$flags));

        return $this;
    }

    /**
     * Toggle the given flags on the mask. Does not persist — chain ->save().
     */
    public function toggleMask(string $column, mixed ...$flags): static
    {
        $this->setAttribute($column, $this->maskValue($column)->toggle(...$flags));

        return $this;
    }

    /**
     * Replace the mask value with the given flags entirely.
     */
    public function setMask(string $column, mixed $flags): static
    {
        if ($this->isWideBitMask($column)) {
            $this->setAttribute($column, WideBitMask::from($flags, $this->wideBitMaskDefinition($column)));

            return $this;
        }

        $this->setAttribute($column, BitMask::resolve($flags));

        return $this;
    }

    /**
     * Reset the mask to an empty value.
     */
    public function clearMask(string $column): static
    {
        if ($this->isWideBitMask($column)) {
            $this->setAttribute($column, WideBitMask::none($this->wideBitMaskDefinition($column)));

            return $this;
        }

        $this->setAttribute($column, 0);

        return $this;
    }

    /**
     * Current value of a mask column as a BitMask or WideBitMask.
     */
    protected function maskValue(string $column): BitMask|WideBitMask
    {
        return $this->isWideBitMask($column)
            ? $this->wideBitMask($column)
            : $this->bitMask($column);
    }

    /**
     * Scope: rows where ALL given flags are set. SQL: (column & mask) = mask
     */
    public function scopeWhereMaskHas(Builder $query, string $column, mixed $flags, string $boolean = 'and'): Builder
    {
        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $this->whereWideMask($query, $definition, $flags, 'has', $boolean);
        }

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
        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $this->whereWideMask($query, $definition, $flags, 'any', $boolean);
        }

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
        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $this->whereWideMask($query, $definition, $flags, 'missing', $boolean);
        }

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
        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $this->whereWideMask($query, $definition, $flags, 'equals', $boolean);
        }

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
     * Emit the per-column bitwise predicate(s) for a wide mask, wrapped in a
     * single nested group so it composes cleanly with surrounding conditions.
     */
    protected function whereWideMask(Builder $query, WideMaskDefinition $definition, mixed $flags, string $mode, string $boolean): Builder
    {
        $contributions = $definition->contributions($flags);

        return $query->where(function (Builder $group) use ($contributions, $mode) {
            $touched = false;

            foreach ($contributions as $column => $mask) {
                $sql = $this->bitMaskColumnSql($group, $column);

                switch ($mode) {
                    case 'has':
                        if ($mask !== 0) {
                            $group->whereRaw('(' . $sql . ' & ?) = ?', [$mask, $mask]);
                            $touched = true;
                        }
                        break;
                    case 'any':
                        if ($mask !== 0) {
                            $group->orWhereRaw('(' . $sql . ' & ?) != 0', [$mask]);
                            $touched = true;
                        }
                        break;
                    case 'missing':
                        if ($mask !== 0) {
                            $group->whereRaw('(' . $sql . ' & ?) = 0', [$mask]);
                            $touched = true;
                        }
                        break;
                    case 'equals':
                        $group->where($group->qualifyColumn($column), '=', $mask);
                        $touched = true;
                        break;
                }
            }

            // "hasAny of no flags" matches nothing; keep the group non-empty.
            if (! $touched && $mode === 'any') {
                $group->whereRaw('1 = 0');
            }
        }, null, null, $boolean);
    }

    /**
     * Grammar-quoted, table-qualified column reference for raw bitwise SQL.
     */
    protected function bitMaskColumnSql(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));
    }
}
