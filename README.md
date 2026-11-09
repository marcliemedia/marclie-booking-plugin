# Marclie Booking

Plugin WordPress đặt phòng trực tiếp cho website cho thuê ngắn hạn (short-term rentals): kiểm tra phòng trống, giá theo tháng tính theo người, thanh toán đặt cọc qua Stripe, đồng bộ lịch iCal với Airbnb / Booking.com, CRM khách, tài khoản khách và trang quản trị riêng (React).

- **Phiên bản:** 0.1.0
- **Yêu cầu:** WordPress ≥ 6.9, PHP ≥ 8.1 (nên có extension `intl`)
- **Tiền tệ:** EUR (cố định)
- **Ngôn ngữ giao diện khách:** tiếng Croatia (HR, ngôn ngữ gốc của nội dung) + tiếng Anh (EN)

## Tính năng

- **Giá:** bảng giá 12 tháng cho mỗi căn, tính theo người/đêm; mỗi căn có số đêm tối thiểu và số khách tối thiểu phải trả. Trẻ 5–10 tuổi trả nửa giá, trẻ 0–4 tuổi miễn phí. Coupon có giảm theo % hoặc số tiền cố định.
- **Đặt phòng:** giữ ngày trong lúc khách thanh toán, trả cọc bằng Stripe (3-D Secure), lưu thẻ để tự trừ phần còn lại trước ngày đến, gửi link thanh toán, hoàn tiền, ghi nhận dispute và báo cho admin.
- **iCal:** cứ 15 phút nhập lịch bận từ Airbnb / Booking.com và xuất lịch bận của từng căn. Plugin chặn "echo" (lịch của mình bị kênh khác gửi ngược lại) và báo xung đột.
- **Email:** 16 mẫu email HR/EN, sửa được trong admin, có xem trước và gửi thử.
- **Tài khoản khách:** đăng ký, đăng nhập, xem đặt phòng, đổi thẻ, yêu cầu hủy, hạng thành viên kèm coupon.
- **Trang quản trị** (menu **Bookings**): dashboard, lịch timeline, đặt phòng, khách, thanh toán, giá, coupon, mẫu email, cài đặt.
- **Bricks Builder:** element, dynamic tag và query type riêng. Không có Bricks thì dùng shortcode.
- **SEO:** các trang trong luồng đặt phòng và trang tài khoản được đặt noindex; tương thích Rank Math.

## Site cần có những gì

Plugin **không tự tạo** post type, taxonomy hay field. Site phải tạo sẵn (thường bằng ACF Pro):

| Thành phần | Tên | Ghi chú |
|---|---|---|
| Post type căn hộ | `property` | Mỗi bài viết đã xuất bản là một căn, sắp xếp theo `menu_order` |
| Field của căn | `unit_code` | Mã căn, ví dụ `101`. Dùng trong mã đặt phòng và URL iCal |
| | `max_guests`, `max_children` | Sức chứa |
| | `checkin_from`, `checkin_to`, `checkout_until` | `HH:MM`. Để trống thì dùng giá trị trong Settings |
| | `gallery`, `bedrooms`, `bathrooms`, `airbnb_listing_id` | Tùy chọn: ảnh trên thẻ kết quả tìm kiếm, bộ lọc, link Airbnb |
| Taxonomy nhà (nhóm căn) | `kuca` | Term đầu tiên của căn là nhà của căn đó. Admin app nhóm căn theo taxonomy này |
| Taxonomy bộ lọc (tùy chọn) | `za`, `kat`, `vani`, `pogled`, `posebno` | Bộ lọc trên trang tìm kiếm |
| Trang options ACF | `brand_name`, `phone`, `email`, `street`, `postcode`, `city`, `maps_url`, `logo`, `accent_color` | Tên doanh nghiệp, thông tin liên hệ, logo và màu nhấn trong email |

**Tên doanh nghiệp** (`brand_name`, để trống thì lấy tên site) xuất hiện ở thanh bên admin, thẻ thành viên, tên lịch iCal và email.

ACF Pro là phụ thuộc mềm: thiếu ACF thì plugin vẫn chạy, đọc thẳng post meta và hiện cảnh báo trong admin.

## Cài đặt trên một site mới

### 1. Lấy mã nguồn

Thư mục plugin **phải tên là `marclie-booking`** (text domain và đường dẫn file dịch phụ thuộc vào tên này):

```sh
cd wp-content/plugins
git clone https://github.com/marcliemedia/marclie-booking-plugin.git marclie-booking
cd marclie-booking
composer install --no-dev --optimize-autoloader
```

Repo đã kèm admin app build sẵn (`build/admin`) và file dịch, nên site **không cần Node**. Nếu thiếu `vendor/`, plugin sẽ không chạy và admin hiện thông báo "Composer dependencies are missing".

Kích hoạt plugin. Khi kích hoạt, plugin tạo các bảng `mbk_*`, quyền `mbk_manage_bookings`, `mbk_manage_rates`, `mbk_manage_settings`, role `booking_manager` và lịch cron.

### 2. Múi giờ

Settings › General › Timezone phải chọn **tên thành phố** (ví dụ `Europe/Zagreb`), không chọn kiểu `UTC+1`. Cron hằng ngày chạy lúc 09:00 giờ site và ngày lưu trú tính theo múi giờ này.

### 3. Stripe

Key **chỉ đặt trong `wp-config.php`**, không bao giờ lưu vào database:

```php
define( 'MBK_STRIPE_PUBLISHABLE_KEY', 'pk_live_…' );
define( 'MBK_STRIPE_SECRET_KEY', 'sk_live_…' );      // hoặc restricted key rk_live_…
define( 'MBK_STRIPE_WEBHOOK_SECRET', 'whsec_…' );
```

Trong Stripe Dashboard, tạo webhook tới `https://<domain>/wp-json/marclie-booking/v1/stripe/webhook` với các event:
`payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`, `charge.dispute.created`, `refund.created`, `refund.updated`, `refund.failed`.

Nhiều site dùng chung một tài khoản Stripe được: mỗi PaymentIntent mang metadata `site`, nên site này không xử lý giao dịch của site khác. Kiểm tra kết nối tại Bookings › Settings › Stripe.

### 4. Trang của luồng đặt phòng

```sh
wp mbk pages --create
```

Lệnh này tạo trang và gán vào page map (Bookings › Settings › Pages): đặt phòng, cảm ơn, thanh toán phần còn lại, tìm kiếm, điều khoản, đăng nhập, đăng ký, tài khoản, quên mật khẩu. Trang có sẵn được dùng lại; nội dung đã sửa không bị ghi đè. Slug mặc định bằng tiếng Croatia (`/rezervacija/`, `/pretraga/`…); muốn slug khác thì tự tạo trang rồi chọn trong page map.

### 5. Cấu hình trong admin (menu Bookings)

- **Settings › General:**
  - % đặt cọc, số ngày trước khi trừ phần còn lại, hạn hủy miễn phí, thời gian giữ chỗ;
  - coupon mặc định: mặc định là `DIRECT5`, xóa đi nếu không dùng hoặc chạy `wp mbk seed` để tạo coupon này;
  - giờ nhận / trả phòng;
  - **tiền tố mã đặt phòng:** 1–6 chữ hoặc số, mặc định `BK`, tạo mã dạng `BK-27-0712-101`.
- **Settings › Notifications:** email nhận thông báo admin, tên và địa chỉ người gửi.
- **Settings › iCal:** URL xuất cho Airbnb / Booking.com và nguồn nhập. Xem [docs/ical-setup.md](docs/ical-setup.md).
- **Prices:** nhập giá cho từng căn. Tháng để trống là tháng đóng, không nhận đặt.
- **Emails:** sửa câu chữ từng mẫu theo HR/EN. Mẫu mặc định viết chung chung, nên thêm chi tiết riêng của site (bãi đỗ xe, hướng dẫn đường đi…).

### 6. Kiểm tra

```sh
wp mbk doctor
```

Lệnh kiểm tra bảng dữ liệu, phiên bản schema, cron, múi giờ, key Stripe, page map, REST, bản build admin và `intl`. Có mục lỗi thì lệnh thoát với mã khác 0.

## Hiển thị trên website

### Shortcode

| Shortcode | Chức năng |
|---|---|
| `[mbk_bookbox]` | Hộp đặt phòng của căn: chọn ngày, giá, nút đặt |
| `[mbk_calendar]` | Lịch phòng trống |
| `[mbk_bookbar]` | Thanh đặt phòng dính đáy màn hình (mobile) |
| `[mbk_search_form]`, `[mbk_search_results]` | Form tìm kiếm và kết quả có bộ lọc |
| `[mbk_checkout]`, `[mbk_confirmation]`, `[mbk_pay_balance]` | Thanh toán, xác nhận, trả phần còn lại |
| `[mbk_login]`, `[mbk_register]`, `[mbk_forgot_password]`, `[mbk_account]` | Tài khoản khách |
| `[mbk_lang_switch]` | Chuyển HR / EN |

Với Bricks Builder, mỗi shortcode có element tương ứng (`mbk-booking-box`, `mbk-checkout`, `mbk-search-results`…), cùng các dynamic tag `{mbk_*}` (giá từ, chính sách, ngày nhận/trả…). Danh sách tag nằm ở đầu `src/Bricks/DynamicTags.php`.

### Giao diện

Template PHP nằm trong `templates/` và in markup theo class BEM tiền tố `w-` (`w-checkout`, `w-field`, `w-button-primary`…). Plugin chỉ kèm CSS nền (`assets/css/mbk-base.css`); phần style chính do theme đảm nhận. Có hai cách tùy chỉnh trên site mới:

1. Style các class `w-*` trong theme, hoặc
2. Ghi đè template: copy `templates/<tên>.php` vào `wp-content/themes/<child-theme>/marclie-booking/<tên>.php`. Email ghi đè trong `marclie-booking/emails/`. Filter `mbk_template_path` cho phép đổi đường dẫn cuối cùng.

### Ngôn ngữ

Chuỗi gốc trong code là tiếng Anh; bản tiếng Croatia nằm trong `languages/marclie-booking-hr.po`. Có hai driver ngôn ngữ:
- **Native:** tiếng Anh qua `?lang=en` hoặc cookie `mbk_lang`.
- **WPML:** dùng tự động khi WPML được bật.

## Lệnh WP-CLI

| Lệnh | Chức năng |
|---|---|
| `wp mbk doctor` | Kiểm tra cài đặt |
| `wp mbk pages [--create]` | Liệt kê / tạo các trang của luồng đặt phòng |
| `wp mbk seed [--force]` | Tạo coupon `DIRECT5` (5 %) |
| `wp mbk charge-balances [--date=Y-m-d]` | Trừ phần còn lại đến hạn (việc cron làm lúc 09:00) |
| `wp mbk ical sync [--unit=<code>] [--feed=<id>]` | Đồng bộ iCal ngay |
| `wp mbk ical urls [--unit=<code>] [--format=csv]` | Liệt kê URL xuất iCal |

## Cron

| Hook | Tần suất | Việc |
|---|---|---|
| `mbk_daily` | 09:00 giờ site | Trừ phần còn lại, email nhắc và email trước ngày đến, cập nhật hạng thành viên, dọn nhật ký |
| `mbk_ical_sync` | 15 phút | Nhập lịch Airbnb / Booking.com |
| `mbk_reap_holds`, `mbk_hourly` | 5 phút / 1 giờ | Giải phóng chỗ giữ hết hạn, cảnh báo |

Trên production nên tắt WP-Cron (`DISABLE_WP_CRON`) và gọi `wp-cron.php` bằng cron của hệ thống, nếu không lịch chỉ chạy khi có người truy cập site.

## Hook cho developer

Một số filter / action hay dùng (danh sách đầy đủ: `git grep -E "apply_filters|do_action" -- src`):

| Hook | Loại | Dùng để |
|---|---|---|
| `mbk_booking_ref` | filter | Đổi định dạng mã đặt phòng |
| `mbk_unit_capacity` | filter | Sức chứa của căn |
| `mbk_quote`, `mbk_policy` | filter | Điều chỉnh báo giá / chính sách |
| `mbk_template_path` | filter | Đường dẫn template |
| `mbk_email_enabled` | filter | Bật / tắt từng email |
| `mbk_stay_card_badges` | filter | Huy hiệu trên thẻ kết quả tìm kiếm |
| `mbk_trusted_proxies` | filter | IP proxy tin cậy (rate limit) |
| `mbk_booking_created`, `mbk_booking_status_changed` | action | Sau khi tạo đặt phòng / đổi trạng thái |
| `mbk_payment_succeeded`, `mbk_payment_failed`, `mbk_refund_created` | action | Sự kiện thanh toán |

## Phát triển

```
marclie-booking/
├── marclie-booking.php   bootstrap, hằng số MBK_*
├── src/                  PHP (PSR-4 Marclie\Booking\): Domain, Service, Repository, Rest, Payment, Ical, Email, Frontend, Bricks, Cli
├── templates/            template giao diện khách + email (emails/defaults/*.json = mẫu email mặc định)
├── assets/               JS/CSS giao diện khách (không cần build)
├── admin-app/            mã nguồn React + TypeScript của trang quản trị
├── build/admin/          admin app đã build (có commit vào repo)
├── languages/            .pot, .po/.mo tiếng Croatia, JSON dịch cho JS
├── docs/                 hướng dẫn admin, cài đặt iCal
└── tests/                phpunit/, integration/, e2e/ (Playwright)
```

### Admin app

```sh
cd admin-app
npm ci
npm run typecheck
npm run build        # ghi vào build/admin (tên chunk có hash)
```

Sau **mỗi lần build**, phải tạo lại file JSON dịch, vì tên file JSON dựa trên đường dẫn chunk JS. Toàn bộ quy trình dịch:

```sh
# chạy từ thư mục gốc WordPress
WP_CLI="wp" node wp-content/plugins/marclie-booking/admin-app/scripts/i18n-pot.js
wp i18n update-po wp-content/plugins/marclie-booking/languages/marclie-booking.pot wp-content/plugins/marclie-booking/languages/marclie-booking-hr.po
# dịch các msgstr còn trống trong marclie-booking-hr.po, rồi:
rm wp-content/plugins/marclie-booking/languages/marclie-booking-hr-*.json
wp i18n make-mo wp-content/plugins/marclie-booking/languages/marclie-booking-hr.po
wp i18n make-json wp-content/plugins/marclie-booking/languages/marclie-booking-hr.po --no-purge
```

### Test

```sh
composer install                 # kèm dev dependencies (PHPUnit)
vendor/bin/phpunit               # unit test, không cần WordPress
wp eval-file wp-content/plugins/marclie-booking/tests/integration/run.php   # chạy trên site local, tự dọn dữ liệu đã tạo
```

Test E2E (Playwright, Stripe test mode): xem [tests/e2e/README.md](tests/e2e/README.md).

### Đóng gói bản phát hành

```sh
cd admin-app && npm run plugin-zip
```

Lệnh tạo `marclie-booking.zip` ở thư mục gốc plugin, loại trừ những gì có trong `.distignore` (`admin-app/`, `tests/`, file cấu hình, `*.md`). Bản zip **chỉ chứa `vendor/` nếu đã chạy `composer install --no-dev` trước khi đóng gói**. File zip đã nằm trong `.gitignore`; muốn phát hành thì đính kèm vào GitHub Release.

## Cập nhật plugin trên site

```sh
cd wp-content/plugins/marclie-booking
git pull
composer install --no-dev --optimize-autoloader
wp mbk doctor
```

Migration của database tự chạy theo `mbk_db_version`.

## Gỡ cài đặt

Gỡ plugin chỉ xóa cron. Bảng dữ liệu, quyền và cài đặt **chỉ bị xóa** khi bật Settings › Advanced › "Delete all data when the plugin is uninstalled".

## Bảo mật

Repo này public: **không commit** key Stripe, file `wp-config.php`, dump database hay dữ liệu khách. Key chỉ nằm trong `wp-config.php` của từng site.

## License

Proprietary — © Marclie Agency.
