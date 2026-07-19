# zuko/laravel-bit-masks

*Cũng có bản dịch [English](README.md) cho README này.*

Bộ công cụ Bitmask cho Laravel — trình tạo lớp flag, tích hợp Eloquent, query scopes linh hoạt và các hàm hỗ trợ thao tác bit.

Lưu trữ hàng chục cờ boolean trong một cột integer duy nhất (ví dụ *"email này thuộc những mạng nào?"* trên hơn 500 triệu dòng), và làm việc với chúng thông qua một API được định kiểu rõ ràng thay vì tự viết SQL bitwise thủ công.

- **Trình tạo** — `php artisan make:bitmask` tạo sẵn flag enum dạng int-backed (hoặc lớp constants) với các giá trị lũy thừa của 2.
- **Eloquent trait** — khai báo các cột mask một lần; casting, helpers và query scopes được tự động nối dây.
- **Fluent scopes** — `whereMaskHas`, `whereMaskHasAny`, `whereMaskMissing`, `whereMaskEquals` (+ các biến thể `orWhere*`).
- **Value object** — `BitMask` bất biến với `has / add / remove / toggle / intersect / diff / names ...`.
- **Collections** — các bộ lọc tương tự, xử lý trong bộ nhớ, trên Eloquent collections hoặc plain collections.
- **Wide masks** — một mask logic duy nhất trải rộng trên nhiều cột BIGINT, cho **hơn 63 flags** (ví dụ 126 trên hai cột) mà không cần tự quản lý `networks_1` / `networks_2`.
- **Junction (pivot) masks** — *cùng* API flag nhưng được lưu bằng bảng `(owner, flag_id)` thay vì cột, cho các tập flag **lớn hoặc động** — với EXISTS-based scopes và chỉ mục tra cứu ngược.

## Yêu cầu

- PHP 8.2+
- Laravel 11, 12 hoặc 13

## Cài đặt

```bash
composer require zuko/laravel-bit-masks
```

Service provider được tự động đăng ký.


## Bắt đầu nhanh

**1. Tạo flag enum:**

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

**2. Thêm cột** (migration):

```php
Schema::create('subscribers', function (Blueprint $table) {
    $table->id();
    $table->string('email')->unique();
    $table->bitMask('networks'); // unsignedBigInteger, default 0
});
```

**3. Khai báo trên model:**

```php
use Zuko\BitMasks\Concerns\HasBitMasks;

class Subscriber extends Model
{
    use HasBitMasks;

    protected $bitMasks = [
        'networks' => Network::class, // gắn với flag enum
        // 'toggles',                 // hoặc cột mask thuần
    ];
}
```

**4. Xong — toàn bộ API đã sẵn sàng:**

```php
$s = Subscriber::first();

$s->networks;                                  // BitMask instance
$s->networks->names();                         // ['Gmail', 'Yahoo']
$s->hasMask('networks', Network::Gmail);       // true
$s->addMask('networks', Network::Outlook)->save();

Subscriber::whereMaskHas('networks', Network::Gmail)->count();
Subscriber::all()->whereMaskHasAny('networks', [Network::Yahoo, Network::Outlook]);
```

## Value object `BitMask`

Bất biến — mọi thao tác biến đổi trả về instance mới. Bất cứ nơi nào chấp nhận *flag*, bạn có thể truyền `int`, một case enum int-backed, một `BitMask` khác, hoặc iterable (có thể lồng nhau) của các kiểu đó.

```php
use Zuko\BitMasks\BitMask;

$mask = BitMask::from([Network::Gmail, Network::Yahoo]); // tự phát hiện enum binding
$mask = BitMask::from(0b0101, Network::class);           // binding rõ ràng
$mask = BitMask::none();                                 // 0
$mask = BitMask::all(Network::class);                    // mọi case được set
$mask = bitmask(5, Network::class);                      // global helper
```

| Phương thức | Mô tả |
|---|---|
| `value(): int` | Giá trị integer thô |
| `isEmpty(): bool` | Không có bit nào được set |
| `has(...$flags): bool` | **Tất cả** các flags được truyền có mặt |
| `hasAny(...$flags): bool` | **Ít nhất một** flag có mặt |
| `hasNone(...$flags): bool` | Không có flag nào có mặt |
| `equals($flags): bool` | Khớp chính xác |
| `add(...$flags): self` | Set flags (OR) |
| `remove(...$flags): self` | Bỏ set flags (AND NOT) |
| `toggle(...$flags): self` | Đảo flags (XOR) |
| `clear(): self` | Mask rỗng |
| `intersect($flags): self` | Bits có ở cả hai (AND) |
| `union($flags): self` | Bits có ở một trong hai (OR) |
| `diff($flags): self` | Bits có ở đây nhưng không có ở kia |
| `bits(): array` | Vị trí bit được set, vd `[1, 3]` |
| `values(): array` | Các thành phần lũy thừa 2, vd `[2, 8]` |
| `flags(): array` | Các enum cases (khi bound) hoặc values |
| `names(): array` | Tên các enum case (yêu cầu enum đã bound) |
| `count(): int` | Số bit được set (`Countable`) |
| `toBits(): string` | Chuỗi nhị phân, vd `"1010"` |
| `enum()` / `withEnum($class)` | Đọc / gắn flag enum |

`BitMask::resolve(mixed): int` là normalizer nền tảng — dùng nó khi bạn cần giá trị integer thuần.

Serialization: `json_encode($mask)` và `(string) $mask` đều trả về giá trị integer.

## Flag enums — `BitMaskFlags`

Các enum được tạo đi kèm trait này; bất kỳ enum int-backed nào cũng có thể áp dụng:

```php
Network::mask(Network::Gmail, Network::Yahoo); // BitMask(3), bound tới Network
Network::none();                               // BitMask rỗng
Network::all();                                // mọi case được set
Network::fromMask(5);                          // [Network::Gmail, Network::Outlook]
Network::Gmail->in($subscriber->networks);     // kiểm tra membership
Network::Yahoo->notIn(0b0101);                 // true
```

## Tích hợp Eloquent — `HasBitMasks`

Khai báo các cột mask qua `$bitMasks` (tên thuần, hoặc `column => FlagEnum::class`). Trait sau đó:

1. **Cast** mỗi cột thành `BitMask` (qua `AsBitMask`), trừ khi bạn đã khai báo cast riêng.
2. Thêm **instance helpers** (các thay đổi ở trong bộ nhớ; chain `->save()` để persist):

```php
$model->bitMask('networks');                    // BitMask (không bao giờ null)
$model->hasMask('networks', Network::Gmail);    // tất cả flags có mặt?
$model->hasAnyMask('networks', ...$flags);      // bất kỳ flag nào có mặt?
$model->missingMask('networks', ...$flags);     // không có flag nào?
$model->addMask('networks', ...$flags);         // set flags
$model->removeMask('networks', ...$flags);      // bỏ flags
$model->toggleMask('networks', ...$flags);      // đảo flags
$model->setMask('networks', $flags);            // thay thế hoàn toàn
$model->clearMask('networks');                  // reset về 0
```

3. Thêm **query scopes** (tương thích MySQL / PostgreSQL / SQLite):

| Scope | SQL | Khớp các dòng… |
|---|---|---|
| `whereMaskHas($col, $flags)` | `(col & m) = m` | có **tất cả** flags |
| `whereMaskHasAny($col, $flags)` | `(col & m) != 0` | có **bất kỳ** flag |
| `whereMaskMissing($col, $flags)` | `(col & m) = 0` | không có flag nào trong danh sách |
| `whereMaskEquals($col, $flags)` | `col = m` | mask chính xác |

Mỗi scope có phiên bản `orWhere*` song song, và chấp nhận tham số `$boolean` tùy chọn để nhóm thủ công:

```php
Subscriber::whereMaskHas('networks', Network::Gmail)
    ->orWhereMaskHas('networks', [Network::Yahoo, Network::Outlook])
    ->get();
```

Bạn cũng có thể gán masks một cách tự nhiên — cast tự xử lý mọi thứ giống flag:

```php
$subscriber->networks = [Network::Gmail, Network::Hotmail];
$subscriber->save(); // lưu dưới dạng 0b1001
```

### Đặt attributes mà không dùng trait

Cast có thể dùng độc lập:

```php
protected $casts = [
    'networks' => AsBitMask::class,                  // thuần
    'networks' => AsBitMask::using(Network::class),  // enum-bound
];
```

## Collections

Các scopes có phiên bản in-memory song song, được đăng ký như `Collection` macros — chúng hoạt động trên Eloquent collections của các models dùng trait **và** trên plain collections của arrays/objects:

```php
$subscribers->whereMaskHas('networks', Network::Gmail);
$subscribers->whereMaskHasAny('networks', [Network::Yahoo]);
$subscribers->whereMaskMissing('networks', Network::Outlook);
$subscribers->whereMaskEquals('networks', 0);

collect([['mask' => 0b11], ['mask' => 0b01]])->whereMaskHas('mask', 0b10);
```

## Tham chiếu Generator

```bash
php artisan make:bitmask {name}
    {--flags=}        # tên flags phân cách bằng dấu phẩy: --flags="gmail,yahoo mail,out-look"
    {--from-file=}    # file với mỗi dòng một tên flag
    {--type=enum}     # "enum" (mặc định) hoặc "constants"
    {--namespace=}    # mặc định: App\BitMasks
    {--path=}         # mặc định: app/BitMasks
    {--start=0}       # vị trí bit của flag đầu tiên
    {--force}         # ghi đè file hiện có
```

Tên được chuẩn hóa thành identifiers (`yahoo mail` → `YahooMail` / `YAHOO_MAIL`); các bản trùng và tràn >63-bit bị từ chối. `--start` cho phép bạn thêm flags mới vào chuỗi hiện có mà không cần đánh số lại (tạo class thứ hai, hoặc tạo lại với danh sách đầy đủ).

`--type=constants` tạo ra class thuần cho các codebase ưa thích constants:

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
$table->wideBitMask('networks', 2);     // networks_1, networks_2 — hai BIGINTs, default 0 (xem Wide masks)
$table->flagPivot('email', 'network_id'); // junction table: cột flag_id + composite PK + reverse index (xem Junction masks)
```

## Wide masks — hơn 63 flags

Một BIGINT chứa 63 flags khả dụng. Khi bạn cần nhiều hơn (package này được xây dựng cho
*"email này thuộc trong 100+ networks nào?"*), **wide mask** trình bày một
mask logic duy nhất được hỗ trợ bởi nhiều cột BIGINT — mỗi cột 63 flags — vì vậy hai
cột cho bạn 126 flags, ba cho 189, và tiếp tục.

Sự khác biệt nằm ở cách flags được định địa chỉ. Flag enum một cột dùng
**giá trị bit** (`1 << n`); flag enum wide-mask dùng **chỉ số toàn cục** (`0`,
`1`, … `125`), vì mask vượt quá bit 62 không thể chứa trong một PHP integer. Chỉ số `n`
định tuyến tới cột `n / 63`, bit `n % 63`.

**1. Flag enum dựa trên chỉ số toàn cục:**

```php
enum Network: int
{
    case Gmail = 0;    // cột 1, bit 0
    case Yahoo = 1;
    // …
    case Proton = 63;  // cột 2, bit 0  (flag thứ 64)
    case Icloud = 125; // cột 2, bit 62 (flag thứ 126)
}
```

**2. Các cột** — macro blueprint `wideBitMask` tạo `networks_1`, `networks_2`:

```php
Schema::create('emails', function (Blueprint $table) {
    $table->string('email')->primary();
    $table->wideBitMask('networks', 2); // networks_1, networks_2 (BIGINT default 0)
});
```

**3. Khai báo trên model** với giá trị mảng (so với enum đơn cho một cột):

```php
class Email extends Model
{
    use HasBitMasks;

    protected $bitMasks = [
        // 'columns' có thể là số (tự suy ra networks_1..N) hoặc danh sách rõ ràng.
        'networks' => ['columns' => 2, 'enum' => Network::class],
    ];
}
```

**4. Cùng API — giá trị giờ là `WideBitMask`:**

```php
$email->networks = [Network::Gmail, Network::Proton]; // phân phát tới networks_1 & networks_2
$email->networks;                                     // WideBitMask instance
$email->networks->names();                            // ['Gmail', 'Proton']
$email->hasMask('networks', Network::Icloud);         // false
$email->addMask('networks', Network::Icloud)->save();

Email::whereMaskHas('networks', Network::Icloud)->get();          // khớp đúng cột
Email::whereMaskHasAny('networks', [Network::Gmail, Network::Proton])->get(); // OR giữa các cột
```

Các scopes tự động phát ra các predicates bitwise theo từng cột (`AND` giữa các cột
cho `has` / `missing` / `equals`, `OR` cho `hasAny`), tất cả được bọc trong một
clause nhóm duy nhất để chúng kết hợp với `orWhere*` và các điều kiện khác của bạn.

`WideBitMask` phản chiếu `BitMask` (`has / hasAny / hasNone / equals / add / remove /
toggle / clear / bits / flags / names / count`); `->columns()` trả về các
integer thô theo từng cột, và `bits()` trả về các chỉ số toàn cục.

## Junction (pivot) masks — tập flags lớn hoặc động

Khi flags quá nhiều ngay cả cho wide mask, hoặc bạn muốn mỗi membership là
một dòng riêng (dễ bulk load, tra cứu ngược theo từng flag, `flag_id` không cần
là vị trí bit liền kề), lưu chúng trong **bảng junction** mỏng —
`(owner_key, flag_id)`, một dòng cho mỗi flag được set — thay vì cột mask. 
`flag_id` là *giá trị* của case enum int-backed (bất kỳ integer không âm nào, không phải
vị trí bit), nên số lượng flag thực tế không giới hạn.

Đó là **cùng cách khai báo và cùng API** — chỉ storage khác.

**1. Flag enum** (giá trị là flag ids tùy ý):

```php
enum Network: int
{
    case Gmail = 1;
    case Proton = 100;
    case Icloud = 250;
}
```

**2. Junction table** — macro `flagPivot` thêm cột `flag_id`, 
primary key tổ hợp, và reverse index (`flag_id, owner`) cho
"những owners nào có flag X?":

```php
Schema::create('email_networks', function (Blueprint $table) {
    $table->string('email');                    // cột owner (kiểu do bạn)
    $table->flagPivot('email', 'network_id');   // + network_id, PK & reverse index
});
```

**3. Khai báo trên model** với key `pivot`. `foreignPivotKey`, `flagKey`
và `ownerKey` là tùy chọn — chúng mặc định là foreign key của model, `flag_id`,
và primary key của model:

```php
class Email extends Model
{
    use HasBitMasks;

    protected $bitMasks = [
        'networks' => [
            'pivot' => 'email_networks',
            'enum'  => Network::class,
            'foreignPivotKey' => 'email',   // cột owner trong junction table
            'flagKey'         => 'network_id',
            'ownerKey'        => 'email',    // local key mà nó tham chiếu
        ],
    ];
}
```

**4. Cùng API — giá trị giờ là `FlagSet`:**

```php
$email->networks = [Network::Gmail, Network::Proton]; // được buffer…
$email->save();                                       // …flush vào junction table

$email->networks;                              // FlagSet instance
$email->networks->names();                     // ['Gmail', 'Proton']
$email->hasMask('networks', Network::Icloud);  // false
$email->addMask('networks', Network::Icloud)->save();

Email::whereMaskHas('networks', Network::Gmail)->get();
Email::whereMaskHasAny('networks', [Network::Proton, Network::Icloud])->get();
```

Ghi chú:

- **Các thay đổi flush khi `->save()`** (cùng hợp đồng như các chiến lược cột) — các
  dòng pivot được đồng bộ với tập đã buffer. Flush cần owner key, nên
  save model ít nhất một lần. Xóa model sẽ xóa các dòng junction của nó.
- **Scopes** phát ra các subqueries `EXISTS` / `NOT EXISTS` tương quan: `whereMaskHas` là
  một `EXISTS` cho mỗi flag, `whereMaskHasAny` là `EXISTS … flag_id IN (…)`,
  `whereMaskMissing` là `NOT EXISTS` của nó, và `whereMaskEquals` là khớp chính xác. Cả
  bốn (và các biến thể `orWhere*`) kết hợp với các điều kiện khác của bạn.
- `FlagSet` phản chiếu `BitMask` (`has / hasAny / hasNone / equals / add / remove /
  toggle / clear / flags / names / count`); `->ids()` trả về các flag ids thô.

## Chọn chiến lược storage

| Flags | Chiến lược | Khai báo |
|---|---|---|
| ≤ 63 | cột đơn | `'networks' => Network::class` |
| 64 – vài trăm | wide mask | `'networks' => ['columns' => N, 'enum' => …]` |
| nhiều / động / bulk-loaded | junction table | `'networks' => ['pivot' => 'table', 'enum' => …]` |

Cột bitmask giữ mọi flag trong dòng (point lookups một dòng, không cần joins);
junction tables đánh đổi điều đó lấy flags không giới hạn, có thể index riêng lẻ.

## Giới hạn & truy vấn ở quy mô lớn

- Một cột chứa **63 flags khả dụng** (bit 63 là sign bit trên các engines BIGINT có dấu). Generator thực thi điều này; cần nhiều hơn? Hãy dùng [**wide mask**](#wide-masks--hơn-63-flags) (nhiều cột BIGINT đằng sau một tên logic), hoặc — vượt quá vài trăm flags *động* — một junction table `(flag_id, row_id)` với composite index thường truy vấn tốt hơn.
- Các predicates kiểu `whereMaskHas` không thể dùng B-tree index thông thường; trên các bảng khổng lồ (hàng trăm triệu dòng) **partial indexes** của PostgreSQL giữ các truy vấn hot-flag nhanh:

```sql
CREATE INDEX idx_subscribers_net_5 ON subscribers (email) WHERE (networks & 32) != 0;
```

- Point lookups (`WHERE email = ?`) không bị ảnh hưởng — mask đi cùng trong dòng.

## Testing

```bash
composer install
composer test
```

## License

MIT © [Zuko](mailto:tansautn@gmail.com)
