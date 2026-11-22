> Historical development notes. The consolidated release is v1.1.7. Final dashboard behavior uses a Customize popup, centered 3×2 hover drag handles and right-side actions. See CHANGELOG.md for the current release.

# Favorite CMS 1.1.7 — Layout Polish

তোমার নির্দেশ অনুযায়ী package/Core version 1.1.7 রাখা হয়েছে। সর্বশেষ dashboard selection, order save, notification ও 2-step সুবিধাগুলো রাখা আছে; এটি পুরোনো 1.1.7-এর আগের feature set-এ ফেরত যায়নি।

পরিবর্তন:
- Security page-এর বাইরের max-width cap সরিয়ে admin content area অনুসরণ করা হয়েছে।
- বামে two-step setup ও email delivery history; ডানে compact notification option cards।
- Site email administration একই outer layout-এর নিচে রাখা হয়েছে।
- Mobile-এ cards এক column-এ আসে; dark/light উভয় mode পরীক্ষা হয়েছে।
- Dashboard-এর six-dot drag handle panel header-এর ডান পাশে। আগের pointer/keyboard reorder ও automatic save অপরিবর্তিত।

Backup নিয়ে Admin → Updates → Core ZIP Upload দিয়ে এই নির্দিষ্ট Layout-Polish ZIP upload/apply করুন। আগের 1.1.8/1.1.9 থাকলেও upload package-এর প্রদর্শিত version এখন 1.1.7 হবে—এটি ইচ্ছাকৃত version naming। GitHub “Check updates” বোতাম এই local build নিজে থেকে পাবে না। আগে পাঠানো original 1.1.7 ZIP-এর বদলে এই Layout-Polish ZIP ব্যবহার করুন।

নতুন migration নেই। Existing plugin/user data, 2-step secrets বা notification preference পরিবর্তন করা হয়নি। Local security/login/email regression, dashboard selection/order regression, real mouse/keyboard drag এবং dark/light 390/1440/1920px layout পরীক্ষায় পাস। Hosting-এ সরাসরি deployment করা হয়নি।
