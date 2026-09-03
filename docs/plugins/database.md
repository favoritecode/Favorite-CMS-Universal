# Database Access for Plugins

Plugins have full, secure access to the Core PDO database abstraction layer.

---

## 1. Creating Custom Database Tables

If your plugin requires custom relational tables, create them on the `plugin.activated` hook:

```php
add_action('plugin.activated', function(string $pluginId) {
    if ($pluginId !== 'my-shop') {
        return;
    }

    $db = app(\FavoriteCMS\Core\Database::class);
    $db->execute('
        CREATE TABLE IF NOT EXISTS `shop_orders` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `order_number` VARCHAR(64) NOT NULL UNIQUE,
            `total_amount` DECIMAL(10,2) NOT NULL,
            `customer_email` VARCHAR(191) NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT "pending",
            `created_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
});
```

---

## 2. Querying Custom Tables

Always use prepared parameters:

```php
$db = app(\FavoriteCMS\Core\Database::class);

// Inserting an order
$orderId = $db->insert('shop_orders', [
    'order_number'   => 'ORD-' . strtoupper(bin2hex(random_bytes(4))),
    'total_amount'   => 49.99,
    'customer_email' => 'client@example.com',
    'status'         => 'paid',
    'created_at'     => date('Y-m-d H:i:s'),
]);

// Fetching an order
$order = $db->selectOne("SELECT * FROM shop_orders WHERE order_number = ?", [$orderNumber]);
```
