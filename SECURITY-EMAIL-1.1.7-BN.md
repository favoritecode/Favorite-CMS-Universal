# Favorite CMS Universal 1.1.7 — Security, Email & Default Theme

## আপডেট
প্রথমে site files ও database backup নিন। Admin → Updates-এর Core ZIP upload দিয়ে Favorite-CMS-Universal-v1.1.7.zip দিন। Core updater migration 021 চালাবে। Plugin, uploaded media ও অন্য theme এই package-এ নেই। Default theme-এর bundled files আপডেট হবে; database-এর theme settings অপরিবর্তিত থাকবে। Original 1.1.6 ZIP অপরিবর্তিত রাখা হয়েছে।

Manual file replacement করলে existing .env, installed.lock, uploads, plugins ও storage রাখুন এবং CLI-তে `php migrate.php` চালান। `--fresh` দেবেন না। নতুন migration additive; user/post/product/movie মুছে বা বদলে দেয় না।

## User security
Profile → Security & email preferences, অথবা `/admin/account/security`। Public account dropdown-এও Security & email পাওয়া যাবে।

- Authenticator app: current password দিন, setup key দিয়ে Google/Microsoft Authenticator বা অন্য TOTP app-এ account যোগ করুন। একই ফোনে “Open authenticator” link ব্যবহার করা যায়। App-এর code দিয়ে confirm করলেই চালু হবে। External QR service-এ secret পাঠানো হয় না; desktop-এ manual setup key ব্যবহার করুন।
- Email: current password দিয়ে method বেছে নিন; account email-এর code confirm করলে চালু হবে। এরপর প্রতিবার password-এর পর email code লাগবে।
- দুই method-এর সঙ্গেই আটটি একবার ব্যবহারযোগ্য recovery code পাওয়া যায়। এগুলো তখনই সংরক্ষণ করুন; পুনরায় দেখানো হয় না।
- Disable বা recovery code বদলাতে current password ও বর্তমান factor/recovery code দরকার। Method বদলাতে আগে বর্তমান method disable করুন।
- Enable/disable করলে অন্য পুরোনো sessions invalidate হবে। Login password reset করলেও 2-step বন্ধ হয় না।
- Authenticator code একবার গ্রহণের পর একই time-step-এর code দ্বিতীয়বার চলে না। পরের code-এর জন্য app-এর timer শেষ হওয়া পর্যন্ত অপেক্ষা করুন। Server ও phone-এর clock ঠিক থাকতে হবে।
- Authenticator secret AES-256-GCM দিয়ে APP_KEY-এর সাহায্যে encrypted থাকে। Existing .env-এর APP_KEY পাল্টাবেন না; backup-এ রাখুন। PHP OpenSSL প্রয়োজন।

TOTP algorithm ও vector reference: [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238).

## Email settings ও cron
আগের Settings → Email-এর SMTP/Google/native mail configuration-ই ব্যবহার হয়। আগে Test email দিয়ে নিজের hosting থেকে delivery পরীক্ষা করুন। Inbox delivery email provider-এর ওপরও নির্ভর করে।

Visitor না থাকলেও mail queue পাঠাতে hosting cron-এ প্রতি মিনিটে দিন। নিজের path ও PHP executable অনুযায়ী বদলাতে হবে:

```sh
* * * * * /usr/bin/php /home/ACCOUNT/public_html/app/Console/notifications.php
```

PHP-FPM থাকলে visitor response-এর পরে ছোট fallback worker আছে; cron-এর বিকল্প হিসেবে নিশ্চিত সময় দেয় না। Worker concurrent run lock করে, একবারে সীমিত email পাঠায় এবং failure-এ delay দিয়ে পাঁচবার পর্যন্ত চেষ্টা করে। SMTP accepted হওয়ার পর process crash হলে পুনরায় delivery-এর সম্ভাবনা পুরোপুরি দূর করা যায় না।

Admin-এর Security & email page-এ queue counts, Process queued emails now, Retry failed emails ও subscriber announcement form আছে। User নিজের শেষ দশটি delivery status দেখতে পাবে। `sent` মানে configured transport গ্রহণ করেছে, inbox-এ পৌঁছেছে এমন delivery receipt নয়।

## Notification পছন্দ
Security alerts ও purchase confirmations default on; user বন্ধ করতে পারবে। Email 2-step code ও password recovery-এর প্রয়োজনীয় email এই newsletter switch দিয়ে বন্ধ হয় না।

Posts/pages, products, movies/series, apps এবং site updates default off। User opt in করলে পরবর্তী নতুন publication-এর email যাবে। একই content edit বা worker rerun-এ একই publication বারবার ঘোষণা হয় না। Draft/private publication ঘোষণা হয় না। Upgrade-এর আগের সব content একসঙ্গে email করা হয় না।

Built-in adapters:
- Core posts ও pages
- Active Favorite Digital-এর নতুন published products এবং paid order confirmations (order number, total ও currency)
- Active Favorite Multimedia-এর published movies ও series
- Active Favorite App Store-এর published apps/software
- Admin-এর subscriber-only site announcements

Plugin deactivated থাকলে তার রেখে যাওয়া table থেকে announcement যাবে না। নতুন বা ভিন্ন plugin schema-এর জন্য নিচের integration যোগ করা যায়; সব third-party plugin নিজে থেকে compatible ধরে নেওয়া হয়নি।

## Plugin integration
Trusted plugin-এর server code থেকে `cms_notification` action পাঠানো যায়:

```php
\FavoriteCMS\Core\Hook::doAction('cms_notification', [
    'key' => 'my-plugin:paid-order:123', // একই event-এর জন্য স্থায়ী unique key
    'category' => 'purchases',
    'subject' => 'Purchase confirmed',
    'body' => 'Order #123 has been paid.',
    'user_id' => 42, // targeted account; broadcast হলে বাদ দিন
]);
```

Categories: security, purchases, posts, products, movies, apps, updates। User notification preference ও delivery-time opt-out মানা হয়। `cms_notification_sources` filter দিয়ে schema-compatible publication adapter যোগ করা যায়।

## Default theme
Responsive default-icon branding-এ site name, পরিমিত gradient hero, উন্নত empty state, subtle card hover ও mobile spacing যুক্ত হয়েছে। Custom logo-এর aspect ratio বা active অন্য theme পরিবর্তন করা হয়নি। Reduced-motion preference মানা হয়।

## যাচাই ও সীমা
Local isolated prefixed SQLite database-এ login, optional two-step, RFC vectors, encrypted secret tamper, replay prevention, recovery single-use, expiry, password/session invalidation, email resend/failure, CSRF, unauthorized announcement, user cleanup, opt-in/out, retry, native/plugin publication এবং paid-only receipt পরীক্ষা হয়েছে। পুরোনো loaded Database class দিয়ে migration-এর prefix compatibility-ও পরীক্ষা হয়েছে। 249 first-party PHP file syntax check এবং dark/light mobile/desktop layout checks পাস।

Real hosting SMTP, PHP-FPM এবং production MySQL deployment সরাসরি পরীক্ষা করা হয়নি। Hosting-এ update-এর পরে Test email, নিজের একটি 2-step setup/login ও cron run পরীক্ষা করুন। Existing account-এর 2-step default off; নিজের verified method ও recovery codes প্রস্তুত হলে তবেই চালু করুন।
