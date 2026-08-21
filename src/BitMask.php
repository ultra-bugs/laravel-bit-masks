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
 * Immutable value object representing a bitmask.
 *
 * Every mutation method returns a new instance. Flags passed to any method can
 * be ints, int-backed enum cases, other BitMask instances, or (nested)
 * iterables of those — everything is normalized through {@see BitMask::resolve()}.
 *
 * Optionally an int-backed enum class can be bound to the mask so set bits can
 * be resolved back to named flags via {@see BitMask::flags()} / {@see BitMask::names()}.
 */
final class BitMask implements \Countable, \JsonSerializable, \Stringable
{
    /**
     * Highest usable bit position (bit 63 is the sign bit of a signed BIGINT).
     */
    public const MAX_BIT = 62;

    private function __construct(
        private readonly int $value,
        private readonly ?string $enum = null,
    ) {
    }

    /**
     * Create a mask from any flag-ish value.
     *
     * When $enum is omitted and the source flags are enum cases (or a BitMask
     * already bound to an enum), the enum class is inherited automatically.
     *
     * @param  int|\BackedEnum|self|iterable|null  $flags
     * @param  class-string<\BackedEnum>|null  $enum
     */
    public static function from(mixed $flags = 0, ?string $enum = null): self
    {
        $enum ??= self::detectEnum($flags);

        if ($enum !== null) {
            self::assertFlagEnum($enum);
        }

        return new self(self::resolve($flags, $enum), $enum);
    }

    /**
     * Create an empty mask.
     *
     * @param  class-string<\BackedEnum>|null  $enum
     */
    public static function none(?string $enum = null): self
    {
        return self::from(0, $enum);
    }

    /**
     * Create a mask with every case of the given enum set.
     *
     * @param  class-string<\BackedEnum>  $enum
     */
    public static function all(string $enum): self
    {
        self::assertFlagEnum($enum);

        return self::from($enum::cases(), $enum);
    }

    /**
     * Normalize any flag-ish value into a plain integer mask.
     *
     * Accepts ints, numeric strings (as returned by some DB drivers),
     * int-backed enum cases, BitMask instances, null (=> 0) and iterables of
     * any of those (OR-combined). When a flag enum is given, flag NAMES
     * ('gmail', 'YAHOO_MAIL', 'yahoo mail') resolve too — see {@see FlagName}.
     *
     * @param  class-string<\BackedEnum>|null  $enum
     */
    public static function resolve(mixed $flags, ?string $enum = null): int
    {
        if ($flags === null) {
            return 0;
        }

        if ($flags instanceof self) {
            return $flags->value;
        }

        if ($flags instanceof \BackedEnum) {
            if (! is_int($flags->value)) {
                throw new \InvalidArgumentException(sprintf('[%s] is a string-backed enum. Bitmask flags must be int-backed.', $flags::class));
            }

            return self::assertNonNegative($flags->value);
        }

        if (is_int($flags)) {
            return self::assertNonNegative($flags);
        }

        if (is_string($flags)) {
            if (ctype_digit($flags)) {
                return (int) $flags;
            }

            if ($enum !== null) {
                return self::resolve(FlagName::resolve($enum, $flags));
            }

            throw new \InvalidArgumentException(sprintf('Cannot resolve flag name [%s]: no flag enum is bound or provided.', $flags));
        }

        if (is_iterable($flags)) {
            $mask = 0;

            foreach ($flags as $flag) {
                $mask |= self::resolve($flag, $enum);
            }

            return $mask;
        }

        throw new \InvalidArgumentException(sprintf('Cannot resolve [%s] into a bitmask value.', get_debug_type($flags)));
    }

    /**
     * The raw integer value of the mask.
     */
    public function value(): int
    {
        return $this->value;
    }

    /**
     * The enum class bound to this mask, if any.
     *
     * @return class-string<\BackedEnum>|null
     */
    public function enum(): ?string
    {
        return $this->enum;
    }

    /**
     * Return a copy of this mask bound to the given flag enum.
     *
     * @param  class-string<\BackedEnum>|null  $enum
     */
    public function withEnum(?string $enum): self
    {
        if ($enum !== null) {
            self::assertFlagEnum($enum);
        }

        return new self($this->value, $enum);
    }

    /**
     * Whether no bit is set.
     */
    public function isEmpty(): bool
    {
        return $this->value === 0;
    }

    /**
     * Whether ALL of the given flags are present.
     */
    public function has(mixed ...$flags): bool
    {
        $mask = self::resolve($flags, $this->enum);

        return ($this->value & $mask) === $mask;
    }

    /**
     * Whether AT LEAST ONE of the given flags is present.
     */
    public function hasAny(mixed ...$flags): bool
    {
        return ($this->value & self::resolve($flags, $this->enum)) !== 0;
    }

    /**
     * Whether NONE of the given flags are present.
     */
    public function hasNone(mixed ...$flags): bool
    {
        return ! $this->hasAny(...$flags);
    }

    /**
     * Whether this mask is exactly equal to the given flags.
     */
    public function equals(mixed $flags): bool
    {
        return $this->value === self::resolve($flags, $this->enum);
    }

    /**
     * Return a new mask with the given flags added.
     */
    public function add(mixed ...$flags): self
    {
        return new self($this->value | self::resolve($flags, $this->enum), $this->enum);
    }

    /**
     * Return a new mask with the given flags removed.
     */
    public function remove(mixed ...$flags): self
    {
        return new self($this->value & ~self::resolve($flags, $this->enum), $this->enum);
    }

    /**
     * Return a new mask with the given flags toggled.
     */
    public function toggle(mixed ...$flags): self
    {
        return new self($this->value ^ self::resolve($flags, $this->enum), $this->enum);
    }

    /**
     * Return a new, empty mask (enum binding preserved).
     */
    public function clear(): self
    {
        return new self(0, $this->enum);
    }

    /**
     * Return a new mask containing bits present in both masks (AND).
     */
    public function intersect(mixed $flags): self
    {
        return new self($this->value & self::resolve($flags, $this->enum), $this->enum);
    }

    /**
     * Return a new mask combining bits of both masks (OR). Alias of add().
     */
    public function union(mixed $flags): self
    {
        return $this->add($flags);
    }

    /**
     * Return a new mask with bits present here but NOT in the given mask.
     */
    public function diff(mixed $flags): self
    {
        return new self($this->value & ~self::resolve($flags, $this->enum), $this->enum);
    }

    /**
     * Positions of the set bits, ascending. E.g. value 0b1010 => [1, 3].
     *
     * @return list<int>
     */
    public function bits(): array
    {
        $bits = [];

        for ($bit = 0; $bit <= self::MAX_BIT; $bit++) {
            if (($this->value >> $bit) & 1) {
                $bits[] = $bit;
            }
        }

        return $bits;
    }

    /**
     * Power-of-two values of the set bits. E.g. value 0b1010 => [2, 8].
     *
     * @return list<int>
     */
    public function values(): array
    {
        return array_map(static fn (int $bit) => 1 << $bit, $this->bits());
    }

    /**
     * Enum cases contained in this mask when an enum is bound,
     * otherwise the raw power-of-two values.
     *
     * @return list<\BackedEnum>|list<int>
     */
    public function flags(): array
    {
        if ($this->enum === null) {
            return $this->values();
        }

        $cases = [];

        foreach (($this->enum)::cases() as $case) {
            if ($case->value !== 0 && ($this->value & $case->value) === $case->value) {
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
        if ($this->enum === null) {
            throw new \LogicException('Cannot resolve flag names: no flag enum is bound to this BitMask.');
        }

        return array_map(static fn (\BackedEnum $case) => $case->name, $this->flags());
    }

    /**
     * Number of set bits.
     */
    public function count(): int
    {
        return substr_count(decbin($this->value), '1');
    }

    /**
     * The set flags as an array — enum cases when bound, ints otherwise.
     *
     * @return list<\BackedEnum>|list<int>
     */
    public function toArray(): array
    {
        return $this->flags();
    }

    /**
     * Binary representation of the mask, e.g. "1010".
     */
    public function toBits(): string
    {
        return decbin($this->value);
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    /**
     * Detect an enum class from the given flags source, if possible.
     */
    private static function detectEnum(mixed $flags): ?string
    {
        if ($flags instanceof self) {
            return $flags->enum;
        }

        if ($flags instanceof \BackedEnum) {
            return $flags::class;
        }

        if (is_iterable($flags)) {
            foreach ($flags as $flag) {
                if (($enum = self::detectEnum($flag)) !== null) {
                    return $enum;
                }
            }
        }

        return null;
    }

    /**
     * @param  class-string  $enum
     */
    private static function assertFlagEnum(string $enum): void
    {
        if (! is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException(sprintf('[%s] is not a backed enum and cannot be used as a flag enum.', $enum));
        }

        $case = $enum::cases()[0] ?? null;

        if ($case !== null && ! is_int($case->value)) {
            throw new \InvalidArgumentException(sprintf('[%s] must be an int-backed enum to be used as a flag enum.', $enum));
        }
    }

    private static function assertNonNegative(int $value): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Bitmask values must be non-negative integers.');
        }

        return $value;
    }
}
