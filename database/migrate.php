<?php
require_once __DIR__ . "/../config/database.php";

echo "--- Starting POS System Migration ---\n";

// Helper to check if column exists in table
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($result && $result->num_rows > 0);
}

// 1. Create tables
$sql = file_get_contents(__DIR__ . "/schema_migration.sql");
if ($conn->multi_query($sql)) {
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());
}
echo "[OK] Created / verified auxiliary tables.\n";

// 2. Add columns to menu_items
$menuItemCols = [
    "barcode" => "ALTER TABLE menu_items ADD COLUMN barcode VARCHAR(50) NULL UNIQUE AFTER item_name",
    "cost_price" => "ALTER TABLE menu_items ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price",
    "stock" => "ALTER TABLE menu_items ADD COLUMN stock INT NOT NULL DEFAULT 0 AFTER cost_price",
    "low_stock_threshold" => "ALTER TABLE menu_items ADD COLUMN low_stock_threshold INT NOT NULL DEFAULT 10 AFTER stock",
    "expiration_date" => "ALTER TABLE menu_items ADD COLUMN expiration_date DATE NULL AFTER low_stock_threshold",
    "supplier_id" => "ALTER TABLE menu_items ADD COLUMN supplier_id INT NULL AFTER expiration_date",
    "image_path" => "ALTER TABLE menu_items ADD COLUMN image_path VARCHAR(255) NULL AFTER supplier_id"
];

foreach ($menuItemCols as $col => $query) {
    if (!columnExists($conn, "menu_items", $col)) {
        if ($conn->query($query)) {
            echo "[OK] Added column `$col` to `menu_items`.\n";
        } else {
            echo "[ERR] Failed adding `$col` to `menu_items`: " . $conn->error . "\n";
        }
    } else {
        echo "[INFO] Column `$col` already exists in `menu_items`.\n";
    }
}

// 3. Add columns to orders
$orderCols = [
    "subtotal" => "ALTER TABLE orders ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER user_id",
    "tax_rate" => "ALTER TABLE orders ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00 AFTER total_amount",
    "tax_amount" => "ALTER TABLE orders ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_rate",
    "discount_type" => "ALTER TABLE orders ADD COLUMN discount_type VARCHAR(20) NOT NULL DEFAULT 'none' AFTER tax_amount",
    "discount_id_no" => "ALTER TABLE orders ADD COLUMN discount_id_no VARCHAR(100) NULL AFTER discount_type",
    "discount_amount" => "ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_id_no",
    "amount_paid" => "ALTER TABLE orders ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount",
    "change_amount" => "ALTER TABLE orders ADD COLUMN change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER amount_paid",
    "reference_no" => "ALTER TABLE orders ADD COLUMN reference_no VARCHAR(100) NULL AFTER change_amount"
];

// Relax payment_method column from ENUM('cash','card') to VARCHAR(50) if needed
$conn->query("ALTER TABLE orders MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'cash'");

foreach ($orderCols as $col => $query) {
    if (!columnExists($conn, "orders", $col)) {
        if ($conn->query($query)) {
            echo "[OK] Added column `$col` to `orders`.\n";
        } else {
            echo "[ERR] Failed adding `$col` to `orders`: " . $conn->error . "\n";
        }
    } else {
        echo "[INFO] Column `$col` already exists in `orders`.\n";
    }
}

// 4. Add price column to order_items
if (!columnExists($conn, "order_items", "price")) {
    $conn->query("ALTER TABLE order_items ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER quantity");
    echo "[OK] Added column `price` to `order_items`.\n";
}

// 5. Seed default suppliers
$chkSuppliers = $conn->query("SELECT COUNT(*) as c FROM suppliers");
$suppCount = $chkSuppliers ? (int)$chkSuppliers->fetch_assoc()["c"] : 0;

if ($suppCount === 0) {
    $suppliers = [
        ["San Miguel Foods & Bev", "Carlos Tan", "+63 917 123 4567", "carlos@smfb.example.com", "Mandaluyong City, Metro Manila"],
        ["Universal Robina Wholesale", "Grace Lim", "+63 918 234 5678", "gracelim@urc.example.com", "Pasig City, Metro Manila"],
        ["Fresh Farm Produce Co.", "Eduardo Santos", "+63 919 345 6789", "sales@freshfarm.example.com", "Benguet / Quezon City"],
        ["Metro Fresh Meats", "Ramon Bautista", "+63 920 456 7890", "ramon@metromeats.example.com", "Marikina City, Metro Manila"]
    ];

    $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, status) VALUES (?, ?, ?, ?, ?, 'active')");
    foreach ($suppliers as $s) {
        $stmt->bind_param("sssss", $s[0], $s[1], $s[2], $s[3], $s[4]);
        $stmt->execute();
    }
    echo "[OK] Seeded 4 initial suppliers.\n";
}

// 6. Update existing menu_items with barcodes, stock levels, cost prices, suppliers, expiration
$itemsRes = $conn->query("SELECT item_id, item_name, price, barcode, stock FROM menu_items ORDER BY item_id ASC");
$barcodeNum = 1001;

while ($row = $itemsRes->fetch_assoc()) {
    $id = (int)$row["item_id"];
    $currentBarcode = $row["barcode"];
    $currentStock = (int)$row["stock"];
    
    // Assign barcode if null or empty
    if (empty($currentBarcode)) {
        $newBarcode = strval($barcodeNum);
        // Cost is approximately 60% of retail price
        $cost = round($row["price"] * 0.60, 2);
        // Set realistic stock: some healthy (25-50), item #3 low stock (4), item #8 nearing expiration
        $stock = ($id === 3) ? 4 : 35;
        $lowThreshold = 10;
        
        // Expiration date: item #7 (Dessert) nearing expiration in 5 days; others 3-6 months
        $expDate = ($id === 7) ? date('Y-m-d', strtotime('+5 days')) : date('Y-m-d', strtotime('+90 days'));
        
        // Link to a supplier (1 to 4)
        $supplierId = (($id - 1) % 4) + 1;
        
        $uStmt = $conn->prepare("UPDATE menu_items SET barcode = ?, cost_price = ?, stock = ?, low_stock_threshold = ?, expiration_date = ?, supplier_id = ? WHERE item_id = ?");
        $uStmt->bind_param("sdiisii", $newBarcode, $cost, $stock, $lowThreshold, $expDate, $supplierId, $id);
        $uStmt->execute();
    }
    $barcodeNum++;
}
echo "[OK] Updated menu items with barcodes, stock levels, cost prices, and suppliers.\n";

// 7. Seed initial inventory log if empty
$chkLogs = $conn->query("SELECT COUNT(*) as c FROM inventory_logs");
$logCount = $chkLogs ? (int)$chkLogs->fetch_assoc()["c"] : 0;
if ($logCount === 0) {
    // Log initial restock for the items
    $firstUserRes = $conn->query("SELECT user_id FROM users ORDER BY user_id ASC LIMIT 1");
    $firstUserId = $firstUserRes ? (int)$firstUserRes->fetch_assoc()["user_id"] : 1;

    $allItems = $conn->query("SELECT item_id, supplier_id, stock, cost_price FROM menu_items");
    $logStmt = $conn->prepare("INSERT INTO inventory_logs (item_id, supplier_id, action_type, quantity_change, cost_per_unit, reference_no, notes, user_id) VALUES (?, ?, 'restock', ?, ?, 'INIT-STOCK', 'Initial system inventory batch', ?)");
    while ($it = $allItems->fetch_assoc()) {
        $logStmt->bind_param("iiidi", $it["item_id"], $it["supplier_id"], $it["stock"], $it["cost_price"], $firstUserId);
        $logStmt->execute();
    }
    echo "[OK] Seeded initial inventory logs.\n";
}

// 8. Seed system log entry
$conn->query("INSERT INTO system_logs (action, details) VALUES ('SYSTEM_MIGRATION', 'Database migration for Account, Inventory, Suppliers, and POS completed successfully.')");

echo "--- Migration Completed Successfully! ---\n";
?>
