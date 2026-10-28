# IRNoti Service برای وردپرس

پلاگین رسمی اتصال وردپرس/ووکامرس به IRNoti.

| بخش | قابلیت | API |
|---|---|---|
| پیامک | ارسال، الگو (pattern)، پیامک خودکار وضعیت سفارش ووکامرس، اعلان سفارش جدید به مدیر | `POST /sms/send`، `/sms/pattern` |
| ربات | همگام‌سازی محصولات و دسته‌های ووکامرس با ربات فروشگاهی (بله/تلگرام)؛ همگام‌سازی خودکار و دستی | `PUT/DELETE /botbuilder/bots/:id/shop/*` |
| ایمیل | عبور تمام `wp_mail()` از IRNoti (با fallback خودکار) | `POST /email/send` |
| VoIP | سافت‌فون WebRTC (JsSIP) داخل پیشخوان و کلیک‌تو‌کال | `/voip/sip-accounts`، `POST /voip/calls` |

## نصب
1. پوشهٔ `irnoti-service/` را در `wp-content/plugins/` بگذارید (یا zip کنید) و فعال کنید.
2. برای سافت‌فون یک‌بار `bin/vendor-jssip.sh` را اجرا کنید (یا در تب VoIP آدرس JsSIP بدهید).
3. IRNoti ← اتصال: کلید `irn_…` را وارد کنید.

## امنیت
- کلید API با AES‑256‑GCM (کلید مشتق از saltهای وردپرس) در دیتابیس ذخیره می‌شود و هرگز به مرورگر نمی‌رسد؛ همهٔ فراخوانی‌ها از سرور وردپرس انجام می‌شود.
- آدرس API فقط https؛ همهٔ اکشن‌ها `manage_options` + nonce.
- ارسال پیامک سفارش با `Idempotency-Key` (سفارش+وضعیت) تا دوبار شارژ نشود.

## مفروضات که باید با سرور تست شود
- شکل پاسخ `GET /botbuilder/bots` (آرایه یا `items`) و حضور `id` در پاسخ `PUT …/shop/products`.
- نام فیلدهای پاسخ `…/credentials` سافت‌فون (`sipWsUrl|wsUrl`، `sipDomain|domain`، `username`، `secret`).
- قیمت‌ها به ریال: واحد IRT ×۱۰، IRR ×۱.
