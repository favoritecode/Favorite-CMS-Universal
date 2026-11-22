# Dashboard Popup — Core 1.1.7

- Visit site / My account-এর পাশে Customize button। সেখানে ক্লিক করলে dashboard source selection popup খুলবে। Save dashboard আগের মতো account-এর পছন্দ সংরক্ষণ করে।
- Popup-এর close button, Escape ও backdrop click কাজ করে; close হলে focus Customize button-এ ফেরে।
- Panel header-এর মাঝে six-dot reorder handle; ডান পাশে Open / Manage / Create actions। আগের auto-save order রাখা হয়েছে।
- Version 1.1.7 অপরিবর্তিত। আগের Layout Polish, security, notification এবং plugin lifecycle checks রাখা হয়েছে।

Backup নিয়ে Admin → Updates → Core ZIP Upload দিয়ে এই নির্দিষ্ট Dashboard-Popup ZIP apply করুন। আগের ZIP-গুলোর বদলে এটি ব্যবহার করুন। নতুন database migration নেই। Local PHP syntax, source/permission/save-order regression, dark/light mobile/desktop popup, keyboard focus এবং actual mouse drag checks পাস। Live hosting deployment করা হয়নি।
