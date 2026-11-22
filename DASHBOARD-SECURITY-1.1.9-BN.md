> Historical development notes. The consolidated release is v1.1.7. Final dashboard behavior uses a Customize popup, centered 3×2 hover drag handles and right-side actions. See CHANGELOG.md for the current release.

# Favorite CMS 1.1.9 — Dashboard Order & Visible Account Security

Backup নিয়ে Admin → Updates থেকে এই Core ZIP update করুন। 1.1.8 থেকেও update করা যাবে। নতুন migration নেই; 1.1.7-এর security migration আগের মতো package-এ আছে।

- নতুন/default dashboard-এ কেবল permitted CMS sources selected থাকে। Installed plugin-এর checkbox নিজে চালু করে Save dashboard দিলে তার panel আসে। Existing saved choices সম্মান করা হয়। CMS default-এ ফিরতে Customize dashboard → Restore default dashboard ব্যবহার করুন।
- প্রতিটি panel-এর বাঁ দিকের ছয়-dot handle ধরে drag করুন। Order স্বয়ংক্রিয়ভাবে save হয়, refresh-এর পরেও থাকে। Keyboard-এ handle focus করে Arrow Up/Down দিয়েও সরানো যায়। নতুন checkbox চালু করলে আগের drag order থাকে; নতুন panel শেষে যোগ হয়।
- Users → Security & Notifications-এ 2-step setup ও email preferences আছে। Profile-এর Security & Password অংশেও “Set up two-step verification” ও “Email notification preferences” button আছে।
- সরাসরি: /admin/account/security#two-step এবং /admin/account/security#email-notifications।
- Plugin removed হলে option/data লুকানো ও inactive হলে data query না করার ব্যবস্থা অপরিবর্তিত। Reorder endpoint কেবল বর্তমান account-এর selected panels-এর order বদলায়; unchecked plugin চালু করতে পারে না।

Local verification: default CMS-only selection, explicit plugin opt-in, order save/reload, checkbox/order coexistence, invalid reorder rejection, CSRF, source lifecycle/permissions, real mouse drag, keyboard reorder ও mobile profile links পরীক্ষা পাস। Hosting-এ সরাসরি install করা হয়নি।
