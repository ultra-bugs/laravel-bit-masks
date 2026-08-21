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
 * Immutable value object for a logical bitmask that spans multiple BIGINT
 * columns (see {@see WideMaskDefinition}).
 *
 * Mirrors the {@see BitMask} API — has/hasAny/hasNone/equals plus the
 * add/remove/toggle/clear mutators returning fresh instances — but flags are
 * addressed by GLOBAL INDEX, and the value is kept decomposed across columns
 * so it can exceed a single 64-bit integer.
 *
 * @implements \Countable
 */
final class WideBitMask implements \Countable, \JsonSerializable
{
    /**
     * @param  array<string, int>  $columns  per-column values, keyed by column name in definition order
     */
    private function __construct(
        private readonly array $columns,
        private readonly WideMaskDefinition $definition,
    ) {
    }

    /**
     * Build a mask from any flag-ish value (index ints, int-backed enum cases,
     * WideBitMask instances, or iterables of those). null yields an empty mask.
     */
    public static function from(mixed $flags, WideMaskDefinition $definition): self
    {
        return new self($definition->contributions($flags), $definition);
    }

    /**
     * Build a mask directly from raw per-column integer values, as stored in the
     * database. Missing columns default to 0; unknown keys are ignored.
     *
     * @param  array<string, int|string|null>  $raw
     */
    public static function fromColumns(array $raw, WideMaskDefinition $definition): self
    {
        $columns = [];

        foreach ($definition->columns() as $column) {
            $columns[$column] = (int) ($raw[$column] ?? 0);
        }

        return new self($columns, $definition);
    }

    /**
     * An empty mask for the given definition.
     */
    public static function none(WideMaskDefinition $definition): self
    {
        return new self(array_fill_keys($definition->columns(), 0), $definition);
    }

    /**
     * Per-column integer values, keyed by column name in definition order.
     * This is what gets persisted to the storage columns.
     *
     * @return array<string, int>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * The definition backing this mask.
     */
    public function definition(): WideMaskDefinition
    {
        return $this->definition;
    }

    /**
     * Whether no bit is set in any column.
     */
    public function isEmpty(): bool
    {
        foreach ($this->columns as $value) {
            if ($value !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether ALL of the given flags are present.
     */
    public function has(mixed ...$flags): bool
    {
        foreach ($this->definition->contributions($flags) as $column => $mask) {
            if (($this->columns[$column] & $mask) !== $mask) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether AT LEAST ONE of the given flags is present.
     */
    public function hasAny(mixed ...$flags): bool
    {
        foreach ($this->definition->contributions($flags) as $column => $mask) {
            if (($this->columns[$column] & $mask) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether NONE of the given flags are present.
     */
    public function hasNone(mixed ...$flags): bool
    {
        return ! $this->hasAny(...$flags);
    }

    /**
     * Whether this mask equals the given flags exactly (every column).
     */
    public function equals(mixed $flags): bool
    {
        return $this->columns === $this->definition->contributions($flags);
    }

    /**
     * Return a new mask with the given flags added.
     */
    public function add(mixed ...$flags): self
    {
        return $this->combine($flags, static fn (int $current, int $mask): int => $current | $mask);
    }

    /**
     * Return a new mask with the given flags removed.
     */
    public function remove(mixed ...$flags): self
    {
        return $this->combine($flags, static fn (int $current, int $mask): int => $current & ~$mask);
    }

    /**
     * Return a new mask with the given flags toggled.
     */
    public function toggle(mixed ...$flags): self
    {
        return $this->combine($flags, static fn (int $current, int $mask): int => $current ^ $mask);
    }

    /**
     * Return a new, empty mask.
     */
    public function clear(): self
    {
        return self::none($this->definition);
    }

    /**
     * Global indices of the set bits, ascending.
     *
     * @return list<int>
     */
    public function bits(): array
    {
        $bits = [];
        $columnIndex = 0;

        foreach ($this->columns as $value) {
            $base = $columnIndex * $this->definition->bitsPerColumn;

            for ($bit = 0; $bit < $this->definition->bitsPerColumn; $bit++) {
                if (($value >> $bit) & 1) {
                    $bits[] = $base + $bit;
                }
            }

            $columnIndex++;
        }

        return $bits;
    }

    /**
     * Enum cases contained in this mask when an enum is bound, otherwise the
     * raw global indices.
     *
     * @return list<\BackedEnum>|list<int>
     */
    public function flags(): array
    {
        if ($this->definition->enum === null) {
            return $this->bits();
        }

        $cases = [];

        foreach (($this->definition->enum)::cases() as $case) {
            if ($this->has($case)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * Names of the enum cases contained in this mask.
     *
     * @return list<string>
     *
     * @throws \LogicException when no flag enum is bound
     */
    public function names(): array
    {
        if ($this->definition->enum === null) {
            throw new \LogicException('Cannot resolve flag names: no flag enum is bound to this wide mask.');
        }

        return array_map(static fn (\BackedEnum $case) => $case->name, $this->flags());
    }

    /**
     * Number of set bits across all columns.
     */
    public function count(): int
    {
        $count = 0;

        foreach ($this->columns as $value) {
            $count += substr_count(decbin($value), '1');
        }

        return $count;
    }

    /**
     * The set flags as an array — enum cases when bound, indices otherwise.
     *
     * @return list<\BackedEnum>|list<int>
     */
    public function toArray(): array
    {
        return $this->flags();
    }

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return $this->columns;
    }

    /**
     * @param  callable(int, int): int  $op
     */
    private function combine(mixed $flags, callable $op): self
    {
        $columns = $this->columns;

        foreach ($this->definition->contributions($flags) as $column => $mask) {
            $columns[$column] = $op($columns[$column], $mask);
        }

        return new self($columns, $this->definition);
    }
}
