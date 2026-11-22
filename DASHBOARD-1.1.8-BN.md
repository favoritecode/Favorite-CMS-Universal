> Historical development notes. The consolidated release is v1.1.7. Final dashboard behavior uses a Customize popup, centered 3×2 hover drag handles and right-side actions. See CHANGELOG.md for the current release.

# Favorite CMS Universal 1.1.8 — Custom Dashboard

## ব্যবহার
Core ZIP দিয়ে update করার পরে main Dashboard → Customize dashboard খুলুন। CMS-এর Posts, Pages, Comments, Users, Media এবং My account থেকে প্রয়োজনমতো tick দিন। Install করা supported plugin-এর option-ও সেখানে থাকবে। এক বা একাধিক source select করে Save dashboard চাপুন।

উদাহরণ: movie site-এ Favorite Multimedia tick দিয়ে Posts untick করলে main dashboard-এ Multimedia summary আসবে, post counts/recent posts/quick draft আসবে না। Multimedia ও Shop একসঙ্গে select করাও যাবে।

পছন্দ account অনুযায়ী সংরক্ষিত হয়। অন্য admin/user-এর dashboard নিজে থেকে বদলায় না। Restore default dashboard শুধু বর্তমান account-এর selection reset করে। সব uncheck রাখলে পরিষ্কার empty dashboard থাকবে; জোর করে Posts ফেরত আসবে না।

## Supported sources
- CMS: permission অনুযায়ী posts/pages/comments/users/media/account। Author-এর posts, drafts ও media নিজের account-এ সীমিত।
- Favorite Multimedia: movies, series, episodes, songs, auto-post queue ও recent movies।
- Favorite Digital: products, orders/paid count, memberships ও recent orders।
- Favorite Shop: products, orders/paid count, categories, coupons, low/out-of-stock alerts ও recent orders।
- Favorite App Store: apps/software summary, auto-post sources ও recent apps।

Digital 1.0.14, Shop 1.0.4 ও Multimedia 1.1.2-এর দেওয়া ZIP-এর manifest/schema/admin routes মিলিয়ে adapters করা হয়েছে। App Store-এর আগের project manifest-ও যাচাই করা হয়েছে। Shop 1.0.4-এর raw PDO table এবং Core-এর prefixed table-এর পার্থক্য read-only adapter সামলায়; plugin বা table বদলায় না।

## Plugin lifecycle ও permission
Plugin-এর folder-এ valid manifest/entry point না থাকলে dashboard-এর option/data আসবে না। Database-এ পুরোনো table বা saved selection থেকে গেলেও plugin installed হিসেবে ধরা হয় না।

Install করা কিন্তু inactive/incompatible plugin-এর option-এ Inactive দেখা যায়। Select থাকলেও তার SQL data query হয় না; activate/requirements ঠিক করার বার্তা আসে। Uninstall/remove হলে option ও panel লুকিয়ে যায়। অন্য selected sources চলতে থাকে।

Built-in plugin global summaries কেবল manage_options permission থাকা account পায়। CMS source নিজের permission মেনে চলে। Source selection-এ CSRF ও server-side validation আছে; body দিয়ে অন্য account-এর preference বদলানো যায় না।

কোনো table/column ready না থাকলে শুধু সংশ্লিষ্ট metric/panel বাদ যায় বা unavailable message আসে। Raw SQL error public dashboard-এ দেখানো হয় না।

## Update
Version 1.1.8 করা হয়েছে, যাতে installed 1.1.7 থেকেও Core Update ZIP গ্রহণ করে। আগে files ও database backup নিন; Admin → Updates থেকে Favorite-CMS-Universal-v1.1.8.zip দিন। নতুন migration নেই; আগের 1.1.7-এর security/notification সুবিধাগুলো package-এ রয়েছে। Plugins, uploaded files ও existing content এই update পরিবর্তন করে না। Plugin ZIP-গুলো reference হিসেবে পড়া হয়েছে, Core ZIP-এর ভেতরে plugin ঢোকানো হয়নি।

## অন্য plugin-এর জন্য provider
একটি installed plugin server-side provider register করতে পারে:

```php
\FavoriteCMS\Core\Hook::addFilter('dashboard_sources', function(array $sources, $user): array {
    $sources[] = [
        'id' => 'my-plugin-overview',
        'plugin' => 'my-plugin', // installed directory ID
        'title' => 'My Plugin',
        'description' => 'My plugin summary',
        'allowed' => fn($actor) => $actor->hasPermission('manage_options'),
        'load' => function($actor, $db): array {
            // নিজের plugin-এর permission এবং tenant/owner scope মেনে read-only query করুন।
            return [
                'metrics' => [['label' => 'Records', 'value' => 0]],
                'actions' => [['label' => 'Open', 'url' => '/admin/page/my-plugin']],
            ];
        },
    ];
    return $sources;
}, 10, 2);
```

Provider-এর plugin installed না থাকলে option আসে না। Inactive হলে loader চলে না। Loader কেবল selected হলে চলে। Text escaped হয় এবং dashboard shortcut local site path-এ সীমিত।

## Verification
Local prefixed SQLite fixtures-এ 27টি behavior check: actual manifests, multi-selection, unchecked source query suppression, stale preferences, plugin removed/inactive, role isolation, author ownership, Shop raw tables, stock count, paid orders, missing tables, CSRF, account preference isolation, reset ও provider extension। Dark/light 390px, 768px ও 1440px-এ picker/panel overflow ও native details toggle পরীক্ষা করা হয়েছে। Core package validator-এ 1.1.7 → 1.1.8 upgrade যাচাই হয়েছে। Live hosting data/PHP-MySQL deployment সরাসরি চালানো হয়নি।
