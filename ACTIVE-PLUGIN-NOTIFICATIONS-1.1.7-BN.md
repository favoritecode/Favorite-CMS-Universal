# Active Plugin Notifications Fix — 1.1.7

Core ZIP Upload দিয়ে এই package apply করুন। Version 1.1.7 অপরিবর্তিত; আগের dashboard popup/hover handle/2-step/layout রাখা হয়েছে।

Notification settings-এ plugin-এর checkbox শুধু valid installed, active এবং compatible থাকলে আসে। Favorite Digital বন্ধ/না থাকলে তার Products/Purchase confirmations, Multimedia-এর Movies & series এবং App Store-এর Apps & software option লুকানো থাকে। Core security/posts/site updates আগের মতো থাকে।

একই gate preference save, event enqueue, source scan, broadcast expansion ও mail delivery-তেও প্রয়োগ হয়েছে। Forged POST দিয়ে unavailable option চালু করা যায় না। Plugin বন্ধ করলে pending mail transport-এ যায় না। Hidden plugin-এর পুরোনো preference সংরক্ষিত থাকে; plugin পুনরায় active করলে user-এর আগের পছন্দ ফের দেখা যায়।

53টি local security/notification/lifecycle check ও 250টি PHP syntax check পাস। Live hosting SMTP/cron সরাসরি পরীক্ষা করা হয়নি। নতুন migration নেই। Backup রেখে update করে Security & Notifications page reload করুন।
