# Owner control — একই version-এর সংশোধিত সম্পূর্ণ ZIP

CMS 1.2.1; Server & License 1.0.0। নতুন recommended files-এর শেষে owner-control আছে। পুরোনো release assets ইতিহাস হিসেবে আছে।

## কীভাবে চলবে
- শুধু আপনার Server package থাকা installation-এর bound Owner account Central Control পাবে। অন্য super-admin বা customer site এই panel চালাতে পারবে না। Owner password confirmation, CSRF এবং session-bound unlock আগের মতো আছে।
- একই root-এ Server থাকলে Site & connection থেকে বর্তমান super-admin password দিয়ে Confirm local connection দিন। Server নির্বাচন বা বাইরে connect করা লাগবে না। অপরিচিত existing private keys মুছে পুনরায় setup করবেন না।
- অন্য site-এ শুধু CMS install হবে। প্রথম সাধারণ request-এ configured official server-এ HTTPS domain proof দিয়ে registration হবে; login বা manual connect লাগবে না। Daily admin refresh-এ site owner-এর username/email এবং service check-এ version/last seen আসে। সব visitor/customer account পাঠানো হয় না। পুরোনো CMS-এ এই build update না দিলে বা domain/network verification ব্যর্থ হলে তালিকায় আসা নিশ্চিত নয়।
- Central Control → Sites & access থেকে site/owner/status/last seen দেখুন, Inspect site খুলে content যাচাই করুন, reason দিয়ে Warning / Suspend / Ban / Reactivate দিন। এই ব্যবস্থা স্বয়ংক্রিয়ভাবে সব content violation শনাক্ত করে না। Action-এর audit থাকে; data মুছে যায় না। Owner control installation নিজেকে ban/suspend করতে পারে না।
- License purchased, current paid licenses, active installation ও pending payment আলাদা metric। প্রতিটি product details-এ sales/activation হিসাব। Orders-এ customer identity ও Favorite Pay verification-এর link। Manual payment Pay-এর existing verification panel-এ যাচাই করুন; শুধু TrxID বা browser success-এ license হয় না। Automatic gateway-এর verified successful payment event license sync চালায়; retry idempotent।
- Public site suspended/banned হলে 503 info page। স্বাভাবিক policy refresh সর্বোচ্চ প্রায় ৫ মিনিট। আগে যাচাইকৃত active connection সাময়িক outage-এ ২৪ ঘণ্টা চলে; এরপর connection-required info। Credentials বিচ্ছিন্ন হলে public error, admin login ও Pay webhook recovery-এর জন্য খোলা থাকে। এটি PHP source পরিবর্তনের বিরুদ্ধে DRM/tamper-proof guarantee নয়।
- Email শুধু Settings → SMTP থেকে। Continue with Google স্থানীয় configured integrated service বা configured official service নিজে বেছে নেয়। Central Control-এর Google service-এ শুধু Owner Google project/client/callback configure করেন; duplicate SMTP form বা আলাদা Owner mail-connect selector নেই। Existing explicit gateway/token settings silently বদলায় না। Google consent এবং বাস্তব test email প্রয়োজন।

## চলমান site update (আপনার hosting)
1. পুরো files + database + private keys/config backup নিন।
2. Server owner-control ZIP domain root-এ extract করুন, app/bootstrap/public-এর পাশে favorite-server-license folder update হবে। storage/ বা .env মুছবেন না। এই একই-version correction প্রথমবার hosting-এ apply করুন; ভবিষ্যৎ নতুন Server version Owner updater দিয়ে দেওয়া যাবে।
3. CMS owner-control ZIP Admin → Updates দিয়ে upload/validate/install করুন। Configuration, uploads, business plugins/themes এবং database data আগের মতো থাকবে। Version বাড়বে না।
4. Owner login করে Site & connection এবং Central Control যাচাই করুন; Settings → SMTP থেকে mail test দিন। Real Google credentials, payment merchant ও consent ZIP-এ নেই।

## নতুন domain/hosting
HTTPS, PHP 8.1+ (zip, mbstring, sodium, pdo_mysql, pdo_sqlite, curl, openssl) এবং MySQL/MariaDB database তৈরি করুন। একই domain root-এ দুটো ZIP extract, /install শেষ, admin login, Site & connection-এ local confirmation। Server folder public/ এর ভিতরে নয়। Customer-দের private Server ZIP দেবেন না। পুরোনো content/orders/licenses ফেরাতে matching backup লাগে।
