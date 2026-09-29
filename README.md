# Cafe Menu Manager 0.1.0

پنل مدیریت فرانت‌اند برای یک کافه با WordPress + ACF + Elementor.

## مدل داده

### Product CPT
- `product`
- Taxonomy: `product_category`
- Product fields: `price`, `old_price`, `description`, `product_image`, `available`, `visible`, `featured`, `sort_order`

Plugin اگر CPT/Taxonomy از قبل وجود داشته باشند دوباره آن‌ها را ثبت نمی‌کند؛ اگر وجود نداشته باشند fallback register می‌کند.

## Drag & Drop ordering

در پنل محصولات Drag & Drop در دو حالت فعال است: (۱) وقتی هیچ فیلتر/جستجویی فعال نیست، (۲) وقتی فقط فیلتر «دسته‌بندی» فعال است. با فیلتر موجودی، فیلتر نمایش یا جستجو غیرفعال می‌ماند. در حالت دسته‌بندی، محصولات همان دسته فقط بین جایگاه‌هایی که از قبل داشتند جابه‌جا می‌شوند و ترتیب محصولات بقیهٔ دسته‌ها دست‌نخورده می‌ماند (همیشه لیست کامل IDها به endpoint ارسال می‌شود). بعد از drop، endpoint زیر آرایه IDها را می‌گیرد:

`POST /wp-json/cmm/v1/products/reorder`

نمونه payload:

```json
{"ids":[24,17,31,9]}
```

Plugin برای آن‌ها `sort_order` را به شکل `10,20,30,40...` ذخیره می‌کند و همان مقدار را در `menu_order` داخلی WordPress هم mirror می‌کند. منوی عمومی Elementor بر اساس `menu_order` مرتب می‌شود؛ بنابراین ترتیب پنل و ترتیب خروجی دقیقاً یکی است و برای آیتم‌های بدون meta هم خطر حذف شدن از Query وجود ندارد.

### نمایش در Elementor

در Elementor یک Loop Grid/Loop Item بساز و در Query ID مقدار زیر را وارد کن:

`cmm_products`

Plugin برای این Query:
- post type را `product` می‌کند
- فقط `publish` را می‌خواند
- محصولات `visible = 1` را نمایش می‌دهد
- در صورت نبود meta برای visible، محصول را visible فرض می‌کند
- با `sort_order` صعودی مرتب می‌کند و title را fallback می‌گذارد

دسته‌بندی را می‌توان از کنترل‌های Taxonomy خود Elementor روی همان Query اعمال کرد.

## مسیرها

- `/manage` — پنل
- `/manage/login` — ورود
- `/menu` — منوی عمومی پایدار برای QR

## کاربر مدیریت

در activation role زیر ساخته می‌شود:

`cafe_manager`

Capability:

`manage_cafe_menu`

برای این نقش، `upload_files` هم فعال است تا آپلود تصویر از پنل فرانت‌اند امکان‌پذیر باشد.

پس از ساخت/اختصاص این role، کاربر کافه وارد `/manage` می‌شود و به wp-admin هدایت نمی‌شود.

## نسخه 0.1.0 شامل

- login فرانت‌اند
- dashboard
- products CRUD
- inline price edit
- availability / visibility / featured toggles
- duplicate product
- product drag & drop ordering
- categories CRUD
- category visibility
- category drag & drop ordering
- settings
- image upload
- Elementor query integration
- native REST API + WP nonce
- responsive dashboard

## نسخه 0.2.0

### تگ‌ها
- Taxonomy جدید `menu_tag` (بدون نیاز به ACF). صفحهٔ «تگ‌ها» در پنل: ساخت، ویرایش، حذف و انتخاب رنگ (خاکستری، هلویی، مشکی، سبز مریم‌گلی).
- در فرم محصول تا **۲ تگ** انتخاب می‌شود (بیشتر از دو تا منو را شلوغ می‌کند). می‌توان از داخل همان فرم تگ جدید ساخت.
- تگ‌ها کنار نام محصول در لیست پنل نمایش داده می‌شوند و در کپی محصول کپی می‌شوند.
- REST: `GET/POST /wp-json/cmm/v1/tags` و `POST/DELETE /wp-json/cmm/v1/tags/{id}`؛ فیلد `tags` (آرایهٔ ID) در ساخت/ویرایش محصول. اگر `tags` ارسال نشود، تگ‌های محصول دست‌نخورده می‌مانند.

### قیمت
- ورودی قیمت و قیمت قبلی با ارقام فارسی و ویرگول هر سه رقم نمایش داده می‌شود (هنگام تایپ). ارقام فارسی/عربی و جداکننده‌ها در سرور هم نرمال می‌شوند.
- ذخیرهٔ فیلدهای True/False بدون ACF از این پس `1`/`0` است.

## نسخه 0.2.1

- **موجود/نمایش**: مقدار خاموش (`false`) دیگر به‌صورت رشتهٔ خالی ذخیره نمی‌شود؛ همیشه `1`/`0` ذخیره می‌شود (با و بدون ACF). ردیفی که قبلاً خالی ذخیره شده بود «خاموش» خوانده می‌شود. کپی محصول هم وضعیت موجود/نمایش/ویژه را درست کپی می‌کند.
- **Drag & Drop**: علاوه بر حالت بدون فیلتر، وقتی فقط فیلتر دسته‌بندی فعال است هم ترتیب محصولات قابل تغییر است.


## نسخه 0.2.2

- **Taxonomy مشترک با CafeFlo/ACF**: پنل از taxonomy انتخاب‌شده در گزینهٔ `cafeflo_product_taxonomy` استفاده می‌کند. `product_category` fallback است و پنل taxonomy موازی ایجاد نمی‌کند وقتی taxonomy اصلی از قبل ثبت شده باشد.


## نسخه 0.2.3

- اتصال taxonomy در شروع و پایان مرحلهٔ `init` دوباره resolve می‌شود تا اگر ACF/CafeFlo taxonomy را کمی دیرتر ثبت کند، پنل همچنان به همان taxonomy مشترک متصل بماند.
