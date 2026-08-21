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

use Zuko\BitMasks\Support\FlagName;

/**
 * Immutable set of flag ids — the value object behind the junction-table
 * ("pivot") storage strategy (see {@see PivotDefinition}).
 *
 * Where {@see BitMask} packs flags into one integer's bits and {@see WideBitMask}
 * spreads them across columns, a FlagSet is just a set of ids stored one row per
 * flag in a `(owner_key, flag_id)` table — so the flag count is effectively
 * unbounded and each `flag_id` is the int-backed enum case's value (not a bit
 * position or a power of two).
 *
 * The read API mirrors BitMask (has/hasAny/hasNone/equals + add/remove/toggle/
 * clear returning fresh instances), so a model can switch storage strategy
 * without callers changing.
 *
 * @implements \Countable
 */
final class FlagSet implements \Countable, \JsonSerializable
{
    /**
     * @param  list<int>  $ids  sorted, unique flag ids
     * @param  class-string<\BackedEnum>|null  $enum
     */
    private function __construct(
        private readonly array $ids,
        private readonly ?string $enum = null,
    ) {
    }

    /**
     * Build a set from any flag-ish value (ids, int-backed enum cases, other
     * FlagSet instances, or iterables of those). null yields an empty set.
     *
     * @param  class-string<\BackedEnum>|null  $enum
     */
    public static function from(mixed $flags, ?string $enum = null): self
    {
        $ids = [];

        self::accumulate($flags, $ids, $enum);

        ksort($ids);

        return new self(array_values(array_map('intval', array_keys($ids))), $enum);
    }

    /**
     * An empty set, optionally bound to a flag enum.
     *
     * @param  class-string<\BackedEnum>|null  $enum
     */
    public static function none(?string $enum = null): self
    {
        return new self([], $enum);
    }

    /**
     * Resolve any flag-ish value to a single flag id. Flag NAMES resolve too
     * when a flag enum is given.
     *
     * @param  class-string<\BackedEnum>|null  $enum
     */
    public static function resolveId(mixed $flag, ?string $enum = null): int
    {
        if ($flag instanceof \BackedEnum) {
            if (! is_int($flag->value)) {
                throw new \InvalidArgumentException(sprintf('[%s] is a string-backed enum. Flag ids must be int-backed.', $flag::class));
            }

            return $flag->value;
        }

        if (is_int($flag)) {
            if ($flag < 0) {
                throw new \InvalidArgumentException('Flag ids must be non-negative integers.');
            }

            return $flag;
        }

        if (is_string($flag)) {
            if (ctype_digit($flag)) {
                return (int) $flag;
            }

            if ($enum !== null) {
                return self::resolveId(FlagName::resolve($enum, $flag));
            }

            throw new \InvalidArgumentException(sprintf('Cannot resolve flag name [%s]: no flag enum is bound or provided.', $flag));
        }

        throw new \InvalidArgumentException(sprintf('Cannot resolve [%s] into a flag id.', get_debug_type($flag)));
    }

    /**
     * The flag ids in the set, ascending.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    /**
     * The bound flag enum, if any.
     *
     * @return class-string<\BackedEnum>|null
     */
    public function enum(): ?string
    {
        return $this->enum;
    }

    /**
     * Whether the set is empty.
     */
    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    /**
     * Whether ALL of the given flags are present.
     */
    public function has(mixed ...$flags): bool
    {
        foreach (self::from($flags, $this->enum)->ids as $id) {
            if (! in_array($id, $this->ids, true)) {
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
        foreach (self::from($flags, $this->enum)->ids as $id) {
            if (in_array($id, $this->ids, true)) {
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
     * Whether this set equals the given flags exactly.
     */
    public function equals(mixed $flags): bool
    {
        return $this->ids === self::from($flags, $this->enum)->ids;
    }

    /**
     * Return a new set with the given flags added.
     */
    public function add(mixed ...$flags): self
    {
        return new self($this->merged(array_merge($this->ids, self::from($flags, $this->enum)->ids)), $this->enum);
    }

    /**
     * Return a new set with the given flags removed.
     */
    public function remove(mixed ...$flags): self
    {
        $remove = self::from($flags, $this->enum)->ids;

        return new self(array_values(array_filter($this->ids, static fn (int $id) => ! in_array($id, $remove, true))), $this->enum);
    }

    /**
     * Return a new set with the given flags toggled.
     */
    public function toggle(mixed ...$flags): self
    {
        $ids = $this->ids;

        foreach (self::from($flags, $this->enum)->ids as $id) {
            $key = array_search($id, $ids, true);

            if ($key === false) {
                $ids[] = $id;
            } else {
                unset($ids[$key]);
            }
        }

        return new self($this->merged($ids), $this->enum);
    }

    /**
     * Return a new, empty set (enum binding preserved).
     */
    public function clear(): self
    {
        return new self([], $this->enum);
    }

    /**
     * Enum cases in the set when an enum is bound, otherwise the raw ids.
     *
     * @return list<\BackedEnum>|list<int>
     */
    public function flags(): array
    {
        if ($this->enum === null) {
            return $this->ids;
        }

        $cases = [];

        foreach (($this->enum)::cases() as $case) {
            if (in_array($case->value, $this->ids, true)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * Names of the enum cases in the set.
     *
     * @return list<string>
     *
     * @throws \LogicException when no flag enum is bound
     */
    public function names(): array
    {
        if ($this->enum === null) {
            throw new \LogicException('Cannot resolve flag names: no flag enum is bound to this FlagSet.');
        }

        return array_map(static fn (\BackedEnum $case) => $case->name, $this->flags());
    }

    /**
     * Number of flags in the set.
     */
    public function count(): int
    {
        return count($this->ids);
    }

    /**
     * The set as an array — enum cases when bound, ids otherwise.
     *
     * @return list<\BackedEnum>|list<int>
     */
    public function toArray(): array
    {
        return $this->flags();
    }

    /**
     * @return list<int>
     */
    public function jsonSerialize(): array
    {
        return $this->ids;
    }

    /**
     * @param  array<array-key, int>  $ids
     * @return list<int>
     */
    private function merged(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<int, bool>  $ids  used as a set: id => true
     */
    private static function accumulate(mixed $flags, array &$ids, ?string $enum = null): void
    {
        if ($flags === null) {
            return;
        }

        if ($flags instanceof self) {
            foreach ($flags->ids as $id) {
                $ids[$id] = true;
            }

            return;
        }

        if ($flags instanceof \BackedEnum || is_int($flags) || is_string($flags)) {
            $ids[self::resolveId($flags, $enum)] = true;

            return;
        }

        if (is_iterable($flags)) {
            foreach ($flags as $flag) {
                self::accumulate($flag, $ids, $enum);
            }

            return;
        }

        throw new \InvalidArgumentException(sprintf('Cannot resolve [%s] into flag ids.', get_debug_type($flags)));
    }
}
