<a href="https://zuko.pro/">
    <img src="https://avatars0.githubusercontent.com/u/6666271?v=3&s=96" alt="Z-Logo"
         title="Halu Universe" align="right" />
</a>
# zuko/laravel-bit-masks


# :performing_arts: Laravel Bitmasks :performing_arts:
*Also have a [Tiếng Việt](README.vi.md) version of this README.*

Bitmask toolkit for Laravel — flag class generator, Eloquent integration, fluent query scopes and bit-level helpers.

Store dozens of boolean flags in a single integer column (e.g. *"which networks is this email listed in?"* across 500M+ rows), and work with them through a clean, typed API instead of hand-rolled bitwise SQL.

- ⚙️ **Generator** — `php artisan make:bitmask` scaffolds int-backed flag enums (or constants classes) with power-of-two values.
- 🧩 **Eloquent trait** — declare your mask columns once; casting, helpers and query scopes are wired automatically.
- 🔍 **Fluent scopes** — `whereMaskHas`, `whereMaskHasAny`, `whereMaskMissing`, `whereMaskEquals` (+ `orWhere*` variants).
- 🛠 **Value object** — immutable `BitMask` with `has / add / remove / toggle / intersect / diff / names ...`.
- 📦 **Collections** — the same filters, in-memory, on Eloquent collections or plain collections.
- 🧵 **Wide masks** — a single logical mask spanning several BIGINT columns, for **more than 63 flags** (e.g. 126 across two columns) without hand-juggling `networks_1` / `networks_2`.
- 🗃 **Junction (pivot) masks** — the *same* flag API backed by a `(owner, flag_id)` table instead of columns, for **large or dynamic** flag sets — with EXISTS-based scopes and a reverse-lookup index.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

```bash
composer require zuko/laravel-bit-masks
```

The service provider is auto-discovered.


## Quick start

**1. Generate a flag enum:**

```bash
php artisan make:bitmask Network --flags="gmail,yahoo,outlook,hotmail"
```

```php
// app/BitMasks/Network.php
enum Network: int
{
    use BitMaskFlags;

    case Gmail = 1 << 0;
    case Yahoo = 1 << 1;
    case Outlook = 1 << 2;
    case Hotmail = 1 << 3;
}
```

**2. Add the column** (migration):

```php
Schema::create('subscribers', function (Blueprint $table) {
    $table->id();
    $table->string('email')->unique();
    $table->bitMask('networks'); // unsignedBigInteger, default 0
});
```

**3. Declare it on the model:**

```php
use Zuko\BitMasks\Concerns\HasBitMasks;

class Subscriber extends Model
{
    use HasBitMasks;

    protected $bitMasks = [
        'networks' => Network::class, // bound to a flag enum
        // 'toggles',                 // or a plain mask column
    ];
}
```

**4. Done — the whole API is live:**

```php
$s = Subscriber::first();

$s->networks;                                  // BitMask instance
$s->networks->names();                         // ['Gmail', 'Yahoo']
$s->hasMask('networks', Network::Gmail);       // true
$s->addMask('networks', Network::Outlook)->save();

Subscriber::whereMaskHas('networks', Network::Gmail)->count();
Subscriber::all()->whereMaskHasAny('networks', [Network::Yahoo, Network::Outlook]);
```

## The `BitMask` value object

Immutable — every mutation returns a new instance. Anywhere a *flag* is accepted, you may pass an `int`, an int-backed enum case, another `BitMask`, an (nested) iterable of those — or, when a flag enum is bound, a flag **name** as a string (see [Flag names as strings](#flag-names-as-strings)).

```php
use Zuko\BitMasks\BitMask;

$mask = BitMask::from([Network::Gmail, Network::Yahoo]); // enum binding auto-detected
$mask = BitMask::from(0b0101, Network::class);           // explicit binding
$mask = BitMask::none();                                 // 0
$mask = BitMask::all(Network::class);                    // every case set
$mask = bitmask(5, Network::class);                      // global helper
```

| Method | Description |
|---|---|
| `value(): int` | Raw integer value |
| `isEmpty(): bool` | No bit set |
| `has(...$flags): bool` | **All** given flags present |
| `hasAny(...$flags): bool` | **At least one** flag present |
| `hasNone(...$flags): bool` | None of the flags present |
| `equals($flags): bool` | Exact match |
| `add(...$flags): self` | Set flags (OR) |
| `remove(...$flags): self` | Unset flags (AND NOT) |
| `toggle(...$flags): self` | Flip flags (XOR) |
| `clear(): self` | Empty mask |
| `intersect($flags): self` | Bits in both (AND) |
| `union($flags): self` | Bits in either (OR) |
| `diff($flags): self` | Bits here but not there |
| `bits(): array` | Set bit positions, e.g. `[1, 3]` |
| `values(): array` | Power-of-two components, e.g. `[2, 8]` |
| `flags(): array` | Enum cases (when bound) or values |
| `names(): array` | Enum case names (requires bound enum) |
| `count(): int` | Number of set bits (`Countable`) |
| `toBits(): string` | Binary string, e.g. `"1010"` |
| `enum()` / `withEnum($class)` | Read / bind the flag enum |

`BitMask::resolve(mixed, ?enum): int` is the underlying normalizer — use it whenever you need a plain integer. Passing the optional enum class also resolves flag names.

Serialization: `json_encode($mask)` and `(string) $mask` both yield the integer value.

## Flag enums — `BitMaskFlags`

Generated enums ship with this trait; any int-backed enum can adopt it:

```php
Network::mask(Network::Gmail, Network::Yahoo); // BitMask(3), bound to Network
Network::none();                               // empty BitMask
Network::all();                                // every case set
Network::fromMask(5);                          // [Network::Gmail, Network::Outlook]
Network::Gmail->in($subscriber->networks);     // membership check
Network::Yahoo->notIn(0b0101);                 // true

Network::fromName('gmail');                    // Network::Gmail (forgiving match)
Network::tryFromName('telegram');              // null
Network::valueOf('gmail');                     // 1 — name straight to int
```

## Flag names as strings

Whenever a flag enum is bound (or passed explicitly), plain string **names** are
accepted anywhere flags are — value objects, model helpers, query scopes and
collection macros. Matching is forgiving: case-insensitive, separators ignored —
`'gmail'`, `'Gmail'`, `'YAHOO_MAIL'` and `'yahoo mail'` all resolve.

```php
$subscriber->addMask('networks', 'outlook')->save();
$subscriber->hasMask('networks', 'gmail');
$subscriber->networks = ['gmail', 'hotmail'];

Subscriber::whereMaskHas('networks', 'gmail')->count();
Subscriber::all()->whereMaskHasAny('networks', ['yahoo', 'outlook']);

Network::mask('gmail', 'yahoo');       // BitMask(3)
bitmask('gmail', Network::class);      // BitMask(1)
```

For raw data work (imports, queues, APIs) the direct string-to-int bridges:

```php
Network::valueOf('gmail');                        // 1
bitmask_value('gmail', Network::class);           // 1
bitmask_value(['gmail', 'yahoo'], Network::class); // 3
bitmask_value('yahoo mail', NetworkConstants::class); // constants classes too
```

The resolver behind all of this is `Zuko\BitMasks\Support\FlagName`
(`resolve` / `tryResolve` / `value` / `tryValue`), usable standalone and aware of
both flag enums and `--type=constants` classes. Numeric strings (`'5'`) keep
resolving as plain integer values, never as names.

## Eloquent integration — `HasBitMasks`

Declare mask columns via `$bitMasks` (plain names, or `column => FlagEnum::class`). The trait then:

1. **Casts** each column to a `BitMask` (via `AsBitMask`), unless you declared your own cast.
2. Adds **instance helpers** (mutations are in-memory; chain `->save()` to persist):

```php
$model->bitMask('networks');                    // BitMask (never null)
$model->hasMask('networks', Network::Gmail);    // all flags present?
$model->hasAnyMask('networks', ...$flags);      // any flag present?
$model->missingMask('networks', ...$flags);     // none present?
$model->addMask('networks', ...$flags);         // set flags
$model->removeMask('networks', ...$flags);      // unset flags
$model->toggleMask('networks', ...$flags);      // flip flags
$model->setMask('networks', $flags);            // replace entirely
$model->clearMask('networks');                  // reset to 0
```

3. Adds **query scopes** (portable across MySQL / PostgreSQL / SQLite):

| Scope | SQL | Matches rows… |
|---|---|---|
| `whereMaskHas($col, $flags)` | `(col & m) = m` | with **all** flags |
| `whereMaskHasAny($col, $flags)` | `(col & m) != 0` | with **any** flag |
| `whereMaskMissing($col, $flags)` | `(col & m) = 0` | with **none** of the flags |
| `whereMaskEquals($col, $flags)` | `col = m` | exact mask |

Each has an `orWhere*` twin, and accepts an optional `$boolean` argument for manual grouping:

```php
Subscriber::whereMaskHas('networks', Network::Gmail)
    ->orWhereMaskHas('networks', [Network::Yahoo, Network::Outlook])
    ->get();
```

You can also assign masks naturally — the cast resolves anything flag-ish:

```php
$subscriber->networks = [Network::Gmail, Network::Hotmail];
$subscriber->save(); // stored as 0b1001
```

### Setting attributes without the trait

The cast is usable standalone:

```php
protected $casts = [
    'networks' => AsBitMask::class,                  // plain
    'networks' => AsBitMask::using(Network::class),  // enum-bound
];
```

## Collections

The scopes have in-memory twins, registered as `Collection` macros — they work on Eloquent collections of trait-using models **and** on plain collections of arrays/objects:

```php
$subscribers->whereMaskHas('networks', Network::Gmail);
$subscribers->whereMaskHasAny('networks', [Network::Yahoo]);
$subscribers->whereMaskMissing('networks', Network::Outlook);
$subscribers->whereMaskEquals('networks', 0);

collect([['mask' => 0b11], ['mask' => 0b01]])->whereMaskHas('mask', 0b10);
```

## Generator reference

```bash
php artisan make:bitmask {name}
    {--flags=}        # comma-separated flag names: --flags="gmail,yahoo mail,out-look"
    {--from-file=}    # file with one flag name per line
    {--type=enum}     # "enum" (default) or "constants"
    {--namespace=}    # default: App\BitMasks
    {--path=}         # default: app/BitMasks
    {--start=0}       # bit position of the first flag
    {--force}         # overwrite existing file
```

Names are normalized into identifiers (`yahoo mail` → `YahooMail` / `YAHOO_MAIL`); duplicates and >63-bit overflows are rejected. `--start` lets you append new flags to an existing sequence without renumbering (generate a second class, or regenerate with the full list).

`--namespace`, `--path` and `--type` fall back to the published config (see
[Configuration](#configuration)) before the built-in defaults.

`--type=constants` produces a plain class for codebases that prefer constants:

```php
final class Network
{
    public const GMAIL = 1 << 0;
    public const YAHOO = 1 << 1;
}
```

## Schema helper

```php
$table->bitMask('networks');            // = $table->unsignedBigInteger('networks')->default(0)
$table->wideBitMask('networks', 2);     // networks_1, networks_2 — two BIGINTs, default 0 (see Wide masks)
$table->flagPivot('email', 'network_id'); // junction table: flag_id column + composite PK + reverse index (see Junction masks)
```

## Wide masks — more than 63 flags

One BIGINT holds 63 usable flags. When you need more (this package was built for
*"which of 100+ networks is this email listed in?"*), a **wide mask** presents a
single logical mask backed by several BIGINT columns — 63 flags each — so two
columns give you 126 flags, three give 189, and so on.

The distinction is in how flags are addressed. A single-column flag enum uses the
**bit value** (`1 << n`); a wide-mask flag enum uses the **global index** (`0`,
`1`, … `125`), because a mask past bit 62 can't fit in one PHP integer. Index `n`
routes to column `n / 63`, bit `n % 63`.

**1. Flag enum backed by global index:**

```php
enum Network: int
{
    case Gmail = 0;    // column 1, bit 0
    case Yahoo = 1;
    // …
    case Proton = 63;  // column 2, bit 0  (the 64th flag)
    case Icloud = 125; // column 2, bit 62 (the 126th flag)
}
```

**2. Columns** — the `wideBitMask` blueprint macro creates `networks_1`, `networks_2`:

```php
Schema::create('emails', function (Blueprint $table) {
    $table->string('email')->primary();
    $table->wideBitMask('networks', 2); // networks_1, networks_2 (BIGINT default 0)
});
```

**3. Declare it on the model** with an array value (vs. a bare enum for a single column):

```php
class Email extends Model
{
    use HasBitMasks;

    protected $bitMasks = [
        // 'columns' may be a count (derives networks_1..N) or an explicit list.
        'networks' => ['columns' => 2, 'enum' => Network::class],
    ];
}
```

**4. Same API — the value is now a `WideBitMask`:**

```php
$email->networks = [Network::Gmail, Network::Proton]; // fans out to networks_1 & networks_2
$email->networks;                                     // WideBitMask instance
$email->networks->names();                            // ['Gmail', 'Proton']
$email->hasMask('networks', Network::Icloud);         // false
$email->addMask('networks', Network::Icloud)->save();

Email::whereMaskHas('networks', Network::Icloud)->get();          // matched in the right column
Email::whereMaskHasAny('networks', [Network::Gmail, Network::Proton])->get(); // OR across columns
```

The scopes emit per-column bitwise predicates automatically (`AND` across columns
for `has` / `missing` / `equals`, `OR` for `hasAny`), all wrapped in a single
grouped clause so they compose with `orWhere*` and your other conditions.

`WideBitMask` mirrors `BitMask` (`has / hasAny / hasNone / equals / add / remove /
toggle / clear / bits / flags / names / count`); `->columns()` returns the raw
per-column integers, and `bits()` returns global indices.

## Junction (pivot) masks — large or dynamic flag sets

When flags are too many even for a wide mask, or you want each membership as its
own row (easy bulk load, per-flag reverse lookups, `flag_id`s that aren't
contiguous bit positions), store them in a thin **junction table** —
`(owner_key, flag_id)`, one row per set flag — instead of mask columns. The
`flag_id` is the int-backed enum case's *value* (any non-negative integer, not a
bit position), so the flag count is effectively unbounded.

It's the **same declaration and the same API** — only the storage differs.

**1. Flag enum** (values are arbitrary flag ids):

```php
enum Network: int
{
    case Gmail = 1;
    case Proton = 100;
    case Icloud = 250;
}
```

**2. Junction table** — the `flagPivot` macro adds the `flag_id` column, the
composite primary key, and a reverse index (`flag_id, owner`) for
"which owners carry flag X?":

```php
Schema::create('email_networks', function (Blueprint $table) {
    $table->string('email');                    // owner column (its type is yours)
    $table->flagPivot('email', 'network_id');   // + network_id, PK & reverse index
});
```

**3. Declare it on the model** with a `pivot` key. `foreignPivotKey`, `flagKey`
and `ownerKey` are optional — they default to the model's foreign key, `flag_id`,
and the model's primary key:

```php
class Email extends Model
{
    use HasBitMasks;

    protected $bitMasks = [
        'networks' => [
            'pivot' => 'email_networks',
            'enum'  => Network::class,
            'foreignPivotKey' => 'email',   // owner column in the junction table
            'flagKey'         => 'network_id',
            'ownerKey'        => 'email',    // local key it references
        ],
    ];
}
```

**4. Same API — the value is now a `FlagSet`:**

```php
$email->networks = [Network::Gmail, Network::Proton]; // buffered…
$email->save();                                       // …flushed to the junction table

$email->networks;                              // FlagSet instance
$email->networks->names();                     // ['Gmail', 'Proton']
$email->hasMask('networks', Network::Icloud);  // false
$email->addMask('networks', Network::Icloud)->save();

Email::whereMaskHas('networks', Network::Gmail)->get();
Email::whereMaskHasAny('networks', [Network::Proton, Network::Icloud])->get();
```

Notes:

- **Mutations flush on `->save()`** (same contract as the column strategies) — the
  pivot rows are reconciled to the buffered set. A flush needs the owner key, so
  save the model at least once. Deleting the model purges its junction rows.
- **Scopes** emit correlated `EXISTS` / `NOT EXISTS` subqueries: `whereMaskHas` is
  an `EXISTS` per flag, `whereMaskHasAny` an `EXISTS … flag_id IN (…)`,
  `whereMaskMissing` its `NOT EXISTS`, and `whereMaskEquals` an exact match. All
  four (and their `orWhere*` twins) compose with your other conditions.
- `FlagSet` mirrors `BitMask` (`has / hasAny / hasNone / equals / add / remove /
  toggle / clear / flags / names / count`); `->ids()` returns the raw flag ids.

## Choosing a storage strategy

| Flags | Strategy | Declaration |
|---|---|---|
| ≤ 63 | single column | `'networks' => Network::class` |
| 64 – a few hundred | wide mask | `'networks' => ['columns' => N, 'enum' => …]` |
| many / dynamic / bulk-loaded | junction table | `'networks' => ['pivot' => 'table', 'enum' => …]` |

Bitmask columns keep every flag in the row (one-row point lookups, no joins);
junction tables trade that for unbounded, individually-indexable flags.

## Limits & querying at scale

- One column holds **63 usable flags** (bit 63 is the sign bit on signed BIGINT engines). The generator enforces this; need more? Reach for a [**wide mask**](#wide-masks--more-than-63-flags) (several BIGINT columns behind one logical name), or — beyond a few hundred *dynamic* flags — a `(flag_id, row_id)` junction table with a composite index usually queries better.
- `whereMaskHas`-style predicates can't use a plain B-tree index; on huge tables (hundreds of millions of rows) PostgreSQL **partial indexes** keep hot-flag queries fast:

```sql
CREATE INDEX idx_subscribers_net_5 ON subscribers (email) WHERE (networks & 32) != 0;
```

- Point lookups (`WHERE email = ?`) are unaffected — the mask rides along in the row.

## Configuration

```bash
php artisan vendor:publish --tag=bit-masks-config
```

`config/bit-masks.php` holds the generator defaults; each `make:bitmask` option
still overrides its config value per invocation:

```php
'generator' => [
    'namespace' => 'App\\BitMasks', // --namespace
    'path'      => 'app/BitMasks',  // --path (relative to base_path())
    'type'      => 'enum',          // --type: 'enum' or 'constants'
],
```

## Testing

```bash
composer install
composer test
```

## License

MIT © [Zuko](mailto:tansautn@gmail.com)
