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
use Illuminate\Support\Str;
use Zuko\BitMasks\BitMask;
use Zuko\BitMasks\Casts\AsBitMask;
use Zuko\BitMasks\FlagSet;
use Zuko\BitMasks\PivotDefinition;
use Zuko\BitMasks\WideBitMask;
use Zuko\BitMasks\WideMaskDefinition;

/**
 * Eloquent integration for bitmask columns.
 *
 * Declare the mask columns on the model and the trait wires up everything
 * else (casting, instance helpers and query scopes). One `$bitMasks`
 * declaration selects the storage strategy per attribute:
 *
 *   protected $bitMasks = [
 *       'networks' => Network::class,                            // single BIGINT column
 *       'toggles',                                               // plain single column
 *       'wide'     => ['columns' => 2, 'enum' => Network::class],// several BIGINT columns
 *       'tags'     => ['pivot' => 'email_tags', 'enum' => Tag::class], // junction table
 *   ];
 *
 * Whichever the strategy, the SAME API applies:
 *
 *   $model->networks;                                // BitMask / WideBitMask / FlagSet
 *   $model->hasMask('networks', Network::Gmail);     // bool
 *   $model->addMask('networks', Network::Yahoo)->save();
 *   Model::whereMaskHas('networks', Network::Gmail)->get();
 *
 * Bitmask/wide mutations live on the row and persist with the model's own
 * ->save(). Pivot mutations are buffered and flushed to the junction table on
 * ->save() too, so the "mutate then ->save()" contract holds across strategies
 * (a pivot flush needs the owner key, so save the model at least once first).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasBitMasks
{
    /**
     * Per-class cache of parsed definitions.
     *
     * @var array<class-string, array{single: array<string, class-string<BackedEnum>|null>, wide: array<string, WideMaskDefinition>, pivot: array<string, PivotDefinition>}>
     */
    protected static array $bitMaskDefinitions = [];

    /**
     * Buffered pivot flag sets awaiting flush on the next ->save(), keyed by name.
     *
     * @var array<string, FlagSet>
     */
    protected array $pendingPivotFlags = [];

    /**
     * Per-instance cache of flag ids loaded from junction tables, keyed by name.
     *
     * @var array<string, list<int>>
     */
    protected array $loadedPivotIds = [];

    /**
     * Persist buffered pivot changes right after the row is saved.
     *
     * Overriding save() (rather than hooking the saved event) keeps flushing
     * dispatcher-independent and correct under saveQuietly().
     */
    public function save(array $options = [])
    {
        $saved = parent::save($options);

        if ($saved) {
            $this->flushPendingPivotFlags();
        }

        return $saved;
    }

    /**
     * Purge this model's junction rows when it is deleted.
     */
    public function delete()
    {
        $deleted = parent::delete();

        if ($deleted !== false) {
            $this->purgePivotFlags();
        }

        return $deleted;
    }

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
     * Normalized map of declared pivot masks: ['name' => PivotDefinition].
     *
     * @return array<string, PivotDefinition>
     */
    public function pivotFlagDefinitions(): array
    {
        return $this->parsedBitMaskDefinitions()['pivot'];
    }

    /**
     * Whether the given name refers to a declared wide mask.
     */
    public function isWideBitMask(string $name): bool
    {
        return isset($this->parsedBitMaskDefinitions()['wide'][$name]);
    }

    /**
     * Whether the given name refers to a declared pivot mask.
     */
    public function isPivotFlagMask(string $name): bool
    {
        return isset($this->parsedBitMaskDefinitions()['pivot'][$name]);
    }

    /**
     * The wide-mask definition for the given name, or null.
     */
    public function wideBitMaskDefinition(string $name): ?WideMaskDefinition
    {
        return $this->parsedBitMaskDefinitions()['wide'][$name] ?? null;
    }

    /**
     * The pivot definition for the given name, or null.
     */
    public function pivotFlagDefinition(string $name): ?PivotDefinition
    {
        return $this->parsedBitMaskDefinitions()['pivot'][$name] ?? null;
    }

    /**
     * Parse and cache the model's `$bitMasks` declaration once per class.
     *
     * @return array{single: array<string, class-string<BackedEnum>|null>, wide: array<string, WideMaskDefinition>, pivot: array<string, PivotDefinition>}
     */
    protected function parsedBitMaskDefinitions(): array
    {
        if (isset(static::$bitMaskDefinitions[static::class])) {
            return static::$bitMaskDefinitions[static::class];
        }

        $single = [];
        $wide = [];
        $pivot = [];

        foreach (property_exists($this, 'bitMasks') ? (array) $this->bitMasks : [] as $key => $value) {
            if (is_int($key)) {
                $single[$value] = null;
            } elseif (is_array($value)) {
                if (isset($value['pivot'])) {
                    $pivot[$key] = $this->makePivotDefinition($key, $value);
                } else {
                    $wide[$key] = $this->makeWideDefinition($key, $value);
                }
            } else {
                $single[$key] = $value;
            }
        }

        return static::$bitMaskDefinitions[static::class] = ['single' => $single, 'wide' => $wide, 'pivot' => $pivot];
    }

    /**
     * Build a WideMaskDefinition from a model's array declaration.
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
     * Build a PivotDefinition from a model's array declaration.
     *
     * `pivot` names the junction table; `foreignPivotKey`, `flagKey` and
     * `ownerKey` default to the model's foreign key, `flag_id`, and the model's
     * primary key respectively.
     *
     * @param  array<string, mixed>  $config
     */
    protected function makePivotDefinition(string $name, array $config): PivotDefinition
    {
        $ownerKey = $config['ownerKey'] ?? $this->getKeyName();

        $foreignPivotKey = $config['foreignPivotKey']
            ?? Str::snake(class_basename($this)) . '_' . $ownerKey;

        return new PivotDefinition(
            name: $name,
            table: $config['pivot'],
            foreignPivotKey: $foreignPivotKey,
            flagKey: $config['flagKey'] ?? 'flag_id',
            ownerKey: $ownerKey,
            enum: $config['enum'] ?? null,
        );
    }

    /**
     * The flag enum bound to the given mask (single, wide or pivot), if any.
     *
     * @return class-string<BackedEnum>|null
     */
    public function bitMaskEnum(string $column): ?string
    {
        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $definition->enum;
        }

        if (($pivot = $this->pivotFlagDefinition($column)) !== null) {
            return $pivot->enum;
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
        return WideBitMask::fromColumns($this->getAttributes(), $this->wideBitMaskDefinition($name));
    }

    /**
     * Current value of a pivot mask as a FlagSet (never null).
     *
     * Reflects buffered (unsaved) changes when present, otherwise the flags
     * stored in the junction table.
     */
    public function pivotFlagSet(string $name): FlagSet
    {
        $definition = $this->pivotFlagDefinition($name);

        if (array_key_exists($name, $this->pendingPivotFlags)) {
            return $this->pendingPivotFlags[$name];
        }

        return FlagSet::from($this->loadPivotIds($definition), $definition->enum);
    }

    /**
     * Read wide/pivot masks as value objects; everything else via Eloquent.
     */
    public function getAttribute($key)
    {
        if ($key !== null) {
            if ($this->isWideBitMask($key)) {
                return $this->wideBitMask($key);
            }

            if ($this->isPivotFlagMask($key)) {
                return $this->pivotFlagSet($key);
            }
        }

        return parent::getAttribute($key);
    }

    /**
     * Fan wide masks out to storage columns and buffer pivot masks; delegate otherwise.
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

        if ($this->isPivotFlagMask($key)) {
            $this->pendingPivotFlags[$key] = FlagSet::from($value, $this->pivotFlagDefinition($key)->enum);
            unset($this->loadedPivotIds[$key]);

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

        if ($this->isPivotFlagMask($column)) {
            $this->setAttribute($column, $flags);

            return $this;
        }

        $this->setAttribute($column, BitMask::resolve($flags, $this->bitMaskColumns()[$column] ?? null));

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

        if ($this->isPivotFlagMask($column)) {
            $this->setAttribute($column, []);

            return $this;
        }

        $this->setAttribute($column, 0);

        return $this;
    }

    /**
     * Persist buffered pivot changes to their junction tables.
     */
    public function flushPendingPivotFlags(): void
    {
        if ($this->pendingPivotFlags === []) {
            return;
        }

        foreach ($this->pendingPivotFlags as $name => $set) {
            $this->syncPivotFlags($this->pivotFlagDefinition($name), $set->ids());
        }

        $this->pendingPivotFlags = [];
    }

    /**
     * Delete all junction rows for this model's pivot masks (on model delete).
     */
    public function purgePivotFlags(): void
    {
        foreach ($this->pivotFlagDefinitions() as $definition) {
            $owner = $this->getAttribute($definition->ownerKey);

            if ($owner !== null) {
                $this->newPivotFlagQuery($definition)->where($definition->foreignPivotKey, $owner)->delete();
            }
        }

        $this->pendingPivotFlags = [];
        $this->loadedPivotIds = [];
    }

    /**
     * Current value of a mask column as its value object.
     */
    protected function maskValue(string $column): BitMask|WideBitMask|FlagSet
    {
        if ($this->isWideBitMask($column)) {
            return $this->wideBitMask($column);
        }

        if ($this->isPivotFlagMask($column)) {
            return $this->pivotFlagSet($column);
        }

        return $this->bitMask($column);
    }

    /**
     * Load (and cache) the flag ids stored for a pivot mask.
     *
     * @return list<int>
     */
    protected function loadPivotIds(PivotDefinition $definition): array
    {
        if (array_key_exists($definition->name, $this->loadedPivotIds)) {
            return $this->loadedPivotIds[$definition->name];
        }

        $owner = $this->getAttribute($definition->ownerKey);

        if ($owner === null) {
            return $this->loadedPivotIds[$definition->name] = [];
        }

        $ids = $this->newPivotFlagQuery($definition)
            ->where($definition->foreignPivotKey, $owner)
            ->pluck($definition->flagKey)
            ->map(static fn ($value) => (int) $value)
            ->all();

        sort($ids);

        return $this->loadedPivotIds[$definition->name] = $ids;
    }

    /**
     * Reconcile the junction table for a pivot mask to exactly $desired ids.
     *
     * @param  list<int>  $desired
     */
    protected function syncPivotFlags(PivotDefinition $definition, array $desired): void
    {
        $owner = $this->getAttribute($definition->ownerKey);

        if ($owner === null) {
            return;
        }

        $current = $this->newPivotFlagQuery($definition)
            ->where($definition->foreignPivotKey, $owner)
            ->pluck($definition->flagKey)
            ->map(static fn ($value) => (int) $value)
            ->all();

        $toAdd = array_values(array_diff($desired, $current));
        $toRemove = array_values(array_diff($current, $desired));

        if ($toAdd === [] && $toRemove === []) {
            $this->loadedPivotIds[$definition->name] = $desired;

            return;
        }

        $this->getConnection()->transaction(function () use ($definition, $owner, $toAdd, $toRemove): void {
            if ($toRemove !== []) {
                $this->newPivotFlagQuery($definition)
                    ->where($definition->foreignPivotKey, $owner)
                    ->whereIn($definition->flagKey, $toRemove)
                    ->delete();
            }

            if ($toAdd !== []) {
                $this->newPivotFlagQuery($definition)->insert(array_map(
                    static fn (int $id) => [$definition->foreignPivotKey => $owner, $definition->flagKey => $id],
                    $toAdd
                ));
            }
        });

        $this->loadedPivotIds[$definition->name] = $desired;
    }

    /**
     * A query builder over a pivot mask's junction table on this model's connection.
     */
    protected function newPivotFlagQuery(PivotDefinition $definition): \Illuminate\Database\Query\Builder
    {
        return $this->getConnection()->table($definition->table);
    }

    /**
     * Scope: rows where ALL given flags are set. SQL: (column & mask) = mask
     */
    public function scopeWhereMaskHas(Builder $query, string $column, mixed $flags, string $boolean = 'and'): Builder
    {
        if (($pivot = $this->pivotFlagDefinition($column)) !== null) {
            return $this->whereFlagPivot($query, $pivot, $flags, 'has', $boolean);
        }

        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $this->whereWideMask($query, $definition, $flags, 'has', $boolean);
        }

        $mask = BitMask::resolve($flags, $this->bitMaskColumns()[$column] ?? null);

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
        if (($pivot = $this->pivotFlagDefinition($column)) !== null) {
            return $this->whereFlagPivot($query, $pivot, $flags, 'any', $boolean);
        }

        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $this->whereWideMask($query, $definition, $flags, 'any', $boolean);
        }

        return $query->whereRaw('(' . $this->bitMaskColumnSql($query, $column) . ' & ?) != 0', [BitMask::resolve($flags, $this->bitMaskColumns()[$column] ?? null)], $boolean);
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
        if (($pivot = $this->pivotFlagDefinition($column)) !== null) {
            return $this->whereFlagPivot($query, $pivot, $flags, 'missing', $boolean);
        }

        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $this->whereWideMask($query, $definition, $flags, 'missing', $boolean);
        }

        return $query->whereRaw('(' . $this->bitMaskColumnSql($query, $column) . ' & ?) = 0', [BitMask::resolve($flags, $this->bitMaskColumns()[$column] ?? null)], $boolean);
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
        if (($pivot = $this->pivotFlagDefinition($column)) !== null) {
            return $this->whereFlagPivot($query, $pivot, $flags, 'equals', $boolean);
        }

        if (($definition = $this->wideBitMaskDefinition($column)) !== null) {
            return $this->whereWideMask($query, $definition, $flags, 'equals', $boolean);
        }

        return $query->where($query->qualifyColumn($column), '=', BitMask::resolve($flags, $this->bitMaskColumns()[$column] ?? null), $boolean);
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
     * Emit a correlated EXISTS predicate against a pivot mask's junction table,
     * grouped so it composes with surrounding conditions.
     */
    protected function whereFlagPivot(Builder $query, PivotDefinition $definition, mixed $flags, string $mode, string $boolean): Builder
    {
        $ids = FlagSet::from($flags, $definition->enum)->ids();
        $ownerColumn = $query->qualifyColumn($definition->ownerKey);

        $correlated = function ($sub) use ($definition, $ownerColumn) {
            $sub->from($definition->table)
                ->whereColumn($definition->table . '.' . $definition->foreignPivotKey, $ownerColumn);

            return $sub;
        };

        switch ($mode) {
            case 'any':
                // EXISTS a row whose flag is one of the given ids (empty => matches none).
                return $query->whereExists(function ($sub) use ($correlated, $definition, $ids) {
                    $correlated($sub)->whereIn($definition->table . '.' . $definition->flagKey, $ids);
                }, $boolean);

            case 'missing':
                // No row whose flag is one of the given ids (empty => matches all).
                return $query->whereExists(function ($sub) use ($correlated, $definition, $ids) {
                    $correlated($sub)->whereIn($definition->table . '.' . $definition->flagKey, $ids);
                }, $boolean, true);

            case 'has':
                // Every given flag must have its own row (empty => matches all).
                return $query->where(function (Builder $group) use ($correlated, $definition, $ids) {
                    foreach ($ids as $id) {
                        $group->whereExists(function ($sub) use ($correlated, $definition, $id) {
                            $correlated($sub)->where($definition->table . '.' . $definition->flagKey, $id);
                        });
                    }
                }, null, null, $boolean);

            case 'equals':
            default:
                // Exactly the given set: all present, and none beyond them.
                return $query->where(function (Builder $group) use ($correlated, $definition, $ids) {
                    if ($ids === []) {
                        $group->whereNotExists(function ($sub) use ($correlated) {
                            $correlated($sub);
                        });

                        return;
                    }

                    foreach ($ids as $id) {
                        $group->whereExists(function ($sub) use ($correlated, $definition, $id) {
                            $correlated($sub)->where($definition->table . '.' . $definition->flagKey, $id);
                        });
                    }

                    $group->whereNotExists(function ($sub) use ($correlated, $definition, $ids) {
                        $correlated($sub)->whereNotIn($definition->table . '.' . $definition->flagKey, $ids);
                    });
                }, null, null, $boolean);
        }
    }

    /**
     * Grammar-quoted, table-qualified column reference for raw bitwise SQL.
     */
    protected function bitMaskColumnSql(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));
    }
}
