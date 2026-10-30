<?php
require __DIR__ . "/../middleware/auth_check.php";
$allowedRoles = ["admin", "manager"];
require __DIR__ . "/../middleware/role_check.php";
require __DIR__ . "/../config/database.php";
require __DIR__ . "/../includes/logger.php";

$currentUserId = (int)$_SESSION["user_id"];
$success = "";
$error = "";

// Handle Form Submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    // Helper to process uploaded product image
    function handleProductImageUpload($fileInputName) {
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]["error"] !== UPLOAD_ERR_OK) {
            return null;
        }
        $file = $_FILES[$fileInputName];
        $allowedExts = ["jpg", "jpeg", "png", "webp", "gif"];
        $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            return null;
        }
        $imageInfo = @getimagesize($file["tmp_name"]);
        if ($imageInfo === false) {
            return null;
        }
        $uploadDir = __DIR__ . "/../uploads/products/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $filename = "prod_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($file["tmp_name"], $targetPath)) {
            return "uploads/products/" . $filename;
        }
        return null;
    }

    // 1. Add Product
    if ($action === "add_product") {
        $barcode       = trim($_POST["barcode"] ?? "");
        $itemName      = trim($_POST["item_name"] ?? "");
        $categoryId    = (int)($_POST["category_id"] ?? 0);
        $costPrice     = (float)($_POST["cost_price"] ?? 0.0);
        $price         = (float)($_POST["price"] ?? 0.0);
        $stock         = (int)($_POST["stock"] ?? 0);
        $lowThreshold  = (int)($_POST["low_stock_threshold"] ?? 10);
        $expiration    = !empty($_POST["expiration_date"]) ? $_POST["expiration_date"] : null;
        $supplierId    = !empty($_POST["supplier_id"]) ? (int)$_POST["supplier_id"] : null;
        $imagePath     = handleProductImageUpload("product_image");

        if (empty($itemName) || $categoryId <= 0 || $price < 0) {
            $error = "Please fill in all required product fields correctly.";
        } else {
            // Check barcode uniqueness if provided
            if (!empty($barcode)) {
                $chk = $conn->prepare("SELECT item_id FROM menu_items WHERE barcode = ?");
                $chk->bind_param("s", $barcode);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $error = "The barcode '$barcode' is already assigned to another item.";
                }
            }

            if (empty($error)) {
                $stmt = $conn->prepare("INSERT INTO menu_items (barcode, item_name, category_id, cost_price, price, stock, low_stock_threshold, expiration_date, supplier_id, image_path, is_available) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("ssiddiisis", $barcode, $itemName, $categoryId, $costPrice, $price, $stock, $lowThreshold, $expiration, $supplierId, $imagePath);
                if ($stmt->execute()) {
                    $newItemId = $conn->insert_id;
                    // If initial stock was entered, log it in inventory_logs
                    if ($stock > 0) {
                        $logStmt = $conn->prepare("INSERT INTO inventory_logs (item_id, supplier_id, action_type, quantity_change, cost_per_unit, reference_no, notes, user_id) VALUES (?, ?, 'restock', ?, ?, 'INITIAL-STOCK', 'Initial inventory on creation', ?)");
                        $logStmt->bind_param("iiidi", $newItemId, $supplierId, $stock, $costPrice, $currentUserId);
                        $logStmt->execute();
                    }
                    $success = "Product '$itemName' added successfully!";
                    log_activity($conn, $currentUserId, "PRODUCT_CREATED", "Added item #$newItemId ($itemName, barcode: $barcode)");
                } else {
                    $error = "Error adding product: " . $conn->error;
                }
            }
        }
    }

    // 2. Edit Product
    elseif ($action === "edit_product") {
        $itemId        = (int)($_POST["item_id"] ?? 0);
        $barcode       = trim($_POST["barcode"] ?? "");
        $itemName      = trim($_POST["item_name"] ?? "");
        $categoryId    = (int)($_POST["category_id"] ?? 0);
        $costPrice     = (float)($_POST["cost_price"] ?? 0.0);
        $price         = (float)($_POST["price"] ?? 0.0);
        $lowThreshold  = (int)($_POST["low_stock_threshold"] ?? 10);
        $expiration    = !empty($_POST["expiration_date"]) ? $_POST["expiration_date"] : null;
        $supplierId    = !empty($_POST["supplier_id"]) ? (int)$_POST["supplier_id"] : null;
        $isAvailable   = (int)($_POST["is_available"] ?? 1);
        $uploadedImage = handleProductImageUpload("product_image");

        if ($itemId <= 0 || empty($itemName)) {
            $error = "Invalid product information.";
        } else {
            // Check barcode duplicate
            if (!empty($barcode)) {
                $chk = $conn->prepare("SELECT item_id FROM menu_items WHERE barcode = ? AND item_id != ?");
                $chk->bind_param("si", $barcode, $itemId);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $error = "The barcode '$barcode' is already in use by another item.";
                }
            }

            if (empty($error)) {
                if ($uploadedImage !== null) {
                    $stmt = $conn->prepare("UPDATE menu_items SET barcode = ?, item_name = ?, category_id = ?, cost_price = ?, price = ?, low_stock_threshold = ?, expiration_date = ?, supplier_id = ?, image_path = ?, is_available = ? WHERE item_id = ?");
                    $stmt->bind_param("ssiddisisii", $barcode, $itemName, $categoryId, $costPrice, $price, $lowThreshold, $expiration, $supplierId, $uploadedImage, $isAvailable, $itemId);
                } else {
                    $stmt = $conn->prepare("UPDATE menu_items SET barcode = ?, item_name = ?, category_id = ?, cost_price = ?, price = ?, low_stock_threshold = ?, expiration_date = ?, supplier_id = ?, is_available = ? WHERE item_id = ?");
                    $stmt->bind_param("ssiddisiii", $barcode, $itemName, $categoryId, $costPrice, $price, $lowThreshold, $expiration, $supplierId, $isAvailable, $itemId);
                }
                if ($stmt->execute()) {
                    $success = "Product '$itemName' updated successfully.";
                    log_activity($conn, $currentUserId, "PRODUCT_UPDATED", "Updated item #$itemId ($itemName)");
                } else {
                    $error = "Error updating product: " . $conn->error;
                }
            }
        }
    }

    // 3. Restock Product
    elseif ($action === "restock_product") {
        $itemId        = (int)($_POST["item_id"] ?? 0);
        $supplierId    = !empty($_POST["supplier_id"]) ? (int)$_POST["supplier_id"] : null;
        $qtyToAdd      = (int)($_POST["quantity"] ?? 0);
        $unitCost      = (float)($_POST["cost_price"] ?? 0.0);
        $referenceNo   = trim($_POST["reference_no"] ?? "");
        $notes         = trim($_POST["notes"] ?? "");

        if ($itemId <= 0 || $qtyToAdd <= 0) {
            $error = "Please specify a valid item and quantity greater than zero.";
        } else {
            $conn->begin_transaction();
            try {
                // Update stock in menu_items and optionally cost_price
                $upStmt = $conn->prepare("UPDATE menu_items SET stock = stock + ?, cost_price = IF(? > 0, ?, cost_price) WHERE item_id = ?");
                $upStmt->bind_param("iddi", $qtyToAdd, $unitCost, $unitCost, $itemId);
                $upStmt->execute();

                // Insert into inventory_logs
                $logStmt = $conn->prepare("INSERT INTO inventory_logs (item_id, supplier_id, action_type, quantity_change, cost_per_unit, reference_no, notes, user_id) VALUES (?, ?, 'restock', ?, ?, ?, ?, ?)");
                $logStmt->bind_param("iiidssi", $itemId, $supplierId, $qtyToAdd, $unitCost, $referenceNo, $notes, $currentUserId);
                $logStmt->execute();

                $conn->commit();
                $success = "Successfully restocked $qtyToAdd units!";
                log_activity($conn, $currentUserId, "PRODUCT_RESTOCKED", "Restocked $qtyToAdd units for item #$itemId (Ref: $referenceNo)");
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error during restock operation: " . $e->getMessage();
            }
        }
    }

    // 4. Toggle Item Availability
    elseif ($action === "toggle_availability") {
        $itemId = (int)($_POST["item_id"] ?? 0);
        $iRes = $conn->query("SELECT item_name, is_available FROM menu_items WHERE item_id = $itemId");
        if ($it = $iRes->fetch_assoc()) {
            $newStatus = ($it["is_available"] == 1) ? 0 : 1;
            $conn->query("UPDATE menu_items SET is_available = $newStatus WHERE item_id = $itemId");
            $success = "Status updated for '{$it['item_name']}'.";
            log_activity($conn, $currentUserId, "PRODUCT_STATUS_CHANGE", "Changed item #$itemId availability to $newStatus");
        }
    }

    // 5. Delete Product
    elseif ($action === "delete_product") {
        $itemId = (int)($_POST["item_id"] ?? 0);
        $iRes = $conn->query("SELECT item_name FROM menu_items WHERE item_id = $itemId");
        if ($it = $iRes->fetch_assoc()) {
            $name = $it["item_name"];
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE order_items SET item_id = NULL WHERE item_id = $itemId");
                $conn->query("DELETE FROM inventory_logs WHERE item_id = $itemId");
                $conn->query("DELETE FROM menu_items WHERE item_id = $itemId");
                $conn->commit();
                $success = "Product '$name' was deleted successfully.";
                log_activity($conn, $currentUserId, "PRODUCT_DELETED", "Permanently deleted item #$itemId ($name)");
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error deleting product: " . $e->getMessage();
            }
        } else {
            $error = "Product not found.";
        }
    }
}

// Fetch categories
$catsRes = $conn->query("SELECT * FROM categories ORDER BY category_name ASC");
$categories = [];
while ($c = $catsRes->fetch_assoc()) {
    $categories[] = $c;
}

// Fetch suppliers
$suppRes = $conn->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name ASC");
$suppliers = [];
while ($s = $suppRes->fetch_assoc()) {
    $suppliers[] = $s;
}

// Fetch menu items with alerts
$sql = "
    SELECT m.*, c.category_name, s.supplier_name,
           DATEDIFF(m.expiration_date, CURDATE()) AS days_to_expire
    FROM menu_items m
    LEFT JOIN categories c ON m.category_id = c.category_id
    LEFT JOIN suppliers s ON m.supplier_id = s.supplier_id
    ORDER BY m.stock ASC, m.item_name ASC
";
$itemsResult = $conn->query($sql);
$items = [];
$totalItems = 0;
$lowStockCount = 0;
$expiringCount = 0;
$inventoryValuation = 0;

while ($row = $itemsResult->fetch_assoc()) {
    $items[] = $row;
    $totalItems++;
    if ((int)$row["stock"] <= (int)$row["low_stock_threshold"]) {
        $lowStockCount++;
    }
    if (!empty($row["expiration_date"])) {
        if ((int)$row["days_to_expire"] <= 30) {
            $expiringCount++;
        }
    }
    $inventoryValuation += ((float)$row["cost_price"] * (int)$row["stock"]);
}

// Fetch recent 20 inventory logs for History tab
$logsSql = "
    SELECT l.*, m.item_name, m.barcode, s.supplier_name, u.username, u.full_name
    FROM inventory_logs l
    JOIN menu_items m ON l.item_id = m.item_id
    LEFT JOIN suppliers s ON l.supplier_id = s.supplier_id
    JOIN users u ON l.user_id = u.user_id
    ORDER BY l.created_at DESC
    LIMIT 25
";
$logsResult = $conn->query($logsSql);
$inventoryLogs = [];
if ($logsResult) {
    while ($lr = $logsResult->fetch_assoc()) {
        $inventoryLogs[] = $lr;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management | Capuzan Kusina</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=<?= time() ?>">
    <style>
        .row-low-stock { background-color: #fffbeb !important; }
        .row-expired { background-color: #fef2f2 !important; }
    </style>
</head>
<body>
    <?php include __DIR__ . "/../includes/navbar.php"; ?>

    <main class="main-wrapper">
        <div class="page-header">
            <div>
                <h1 class="page-title">Inventory Management</h1>
                <p class="page-subtitle">Catalog items, barcode tracking, restocking audits, and low-stock/expiration alerts.</p>
            </div>
            <div style="display:flex; gap:10px;">
                <button class="btn btn-secondary" onclick="openRestockModal()">
                    <span style="font-weight:bold;">📦</span> Restock Items
                </button>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <span style="font-size:1.1rem; line-height:1;">+</span> Add Product
                </button>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <span>✓ <?= htmlspecialchars($success) ?></span>
                <button onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-weight:bold;">✕</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <span>⚠ <?= htmlspecialchars($error) ?></span>
                <button onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-weight:bold;">✕</button>
            </div>
        <?php endif; ?>

        <!-- System Alerts -->
        <?php if ($lowStockCount > 0): ?>
            <div class="alert alert-warning">
                <span>⚠️ <strong>Low Stock Alert:</strong> There are <?= $lowStockCount ?> product(s) at or below their reorder threshold!</span>
                <button class="btn btn-sm btn-secondary" onclick="setFilter('low_stock')">View Low Stock</button>
            </div>
        <?php endif; ?>

        <?php if ($expiringCount > 0): ?>
            <div class="alert alert-danger">
                <span>⏰ <strong>Expiration Alert:</strong> <?= $expiringCount ?> product(s) are expired or expiring within 30 days!</span>
                <button class="btn btn-sm btn-secondary" onclick="setFilter('expiring')">View Expiring Items</button>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Catalog Items</span>
                <span class="stat-val"><?= $totalItems ?></span>
                <span class="stat-sub">Active & available products</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Low Stock Items</span>
                <span class="stat-val" style="color:var(--warning);"><?= $lowStockCount ?></span>
                <span class="stat-sub">Needs replenishment</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Expiring / Expired</span>
                <span class="stat-val" style="color:var(--danger);"><?= $expiringCount ?></span>
                <span class="stat-sub">Within next 30 days</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Total Stock Valuation</span>
                <span class="stat-val" style="color:var(--primary);">₱<?= number_format($inventoryValuation, 2) ?></span>
                <span class="stat-sub">Total inventory asset cost</span>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="tabs">
            <button class="tab-btn active" id="tab-all" onclick="setTab('all')">All Products (<?= $totalItems ?>)</button>
            <button class="tab-btn" id="tab-low_stock" onclick="setTab('low_stock')">Low Stock Alerts (<?= $lowStockCount ?>)</button>
            <button class="tab-btn" id="tab-expiring" onclick="setTab('expiring')">Expiration Alerts (<?= $expiringCount ?>)</button>
            <button class="tab-btn" id="tab-history" onclick="setTab('history')">Restock History</button>
        </div>

        <!-- Products Table Card -->
        <div class="card" id="catalog-card">
            <div class="card-header">
                <span class="card-title">Product Catalog & Stock Levels</span>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <select id="cat-filter" class="form-control" style="width:180px;" onchange="filterProducts()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat["category_name"]) ?>"><?= htmlspecialchars($cat["category_name"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="prod-search" placeholder="Search barcode or name..." class="form-control" style="width:240px;" onkeyup="filterProducts()">
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table" id="products-table">
                    <thead>
                        <tr>
                            <th>Barcode</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Cost</th>
                            <th>Price</th>
                            <th>Current Stock</th>
                            <th>Threshold</th>
                            <th>Expiration</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php 
                                $isLow = ($item["stock"] <= $item["low_stock_threshold"]);
                                $isExp = (!empty($item["expiration_date"]) && $item["days_to_expire"] <= 30);
                                $isPast = (!empty($item["expiration_date"]) && $item["days_to_expire"] < 0);
                                $rowClass = $isPast ? 'row-expired' : ($isLow ? 'row-low-stock' : '');
                            ?>
                            <tr class="<?= $rowClass ?>" 
                                data-low="<?= $isLow ? '1' : '0' ?>" 
                                data-exp="<?= $isExp ? '1' : '0' ?>"
                                data-category="<?= htmlspecialchars($item["category_name"] ?? "") ?>">
                                <td>
                                    <code><?= htmlspecialchars($item["barcode"] ?: "—") ?></code>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <?php if (!empty($item["image_path"]) && file_exists(__DIR__ . "/../" . $item["image_path"])): ?>
                                            <img src="../<?= htmlspecialchars($item["image_path"]) ?>" style="width:42px; height:42px; border-radius:8px; object-fit:cover; border:1px solid #E6D9C8; flex-shrink:0;">
                                        <?php else: ?>
                                            <div style="width:42px; height:42px; border-radius:8px; background:#F4EADA; display:flex; align-items:center; justify-content:center; font-size:20px; border:1px solid #E6D9C8; flex-shrink:0;">
                                                <?php
                                                    $catL = strtolower($item["category_name"] ?? "");
                                                    if (strpos($catL, "drink") !== false) echo "🥤";
                                                    elseif (strpos($catL, "dessert") !== false) echo "🍰";
                                                    elseif (strpos($catL, "appetizer") !== false) echo "🥟";
                                                    else echo "🍲";
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= htmlspecialchars($item["item_name"]) ?></strong>
                                            <div style="font-size:0.75rem; color:var(--gray-500);">ID #<?= $item["item_id"] ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($item["category_name"] ?: "General") ?></td>
                                <td>₱<?= number_format($item["cost_price"], 2) ?></td>
                                <td><strong>₱<?= number_format($item["price"], 2) ?></strong></td>
                                <td>
                                    <?php if ($item["stock"] <= 0): ?>
                                        <span class="badge badge-danger">Out of Stock (0)</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge badge-warning">Low: <?= $item["stock"] ?> units</span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><?= $item["stock"] ?> units</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $item["low_stock_threshold"] ?></td>
                                <td>
                                    <?php if (empty($item["expiration_date"])): ?>
                                        <span style="color:var(--gray-500);">N/A</span>
                                    <?php elseif ($isPast): ?>
                                        <span class="badge badge-danger">Expired (<?= $item["expiration_date"] ?>)</span>
                                    <?php elseif ($isExp): ?>
                                        <span class="badge badge-warning"><?= $item["days_to_expire"] ?>d left (<?= $item["expiration_date"] ?>)</span>
                                    <?php else: ?>
                                        <small><?= $item["expiration_date"] ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($item["supplier_name"] ?: "Unlinked") ?></td>
                                <td>
                                    <?php if ($item["is_available"] == 1): ?>
                                        <span class="badge badge-success">Available</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <button class="btn btn-secondary btn-sm" onclick='openRestockModalForItem(<?= json_encode($item) ?>)'>
                                        Restock
                                    </button>
                                    <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($item) ?>)'>
                                        Edit
                                    </button>
                                    <form method="POST" action="index.php" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="item_id" value="<?= $item["item_id"] ?>">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="confirmFormSubmit(this.form, 'Delete Product', 'Are you sure you want to permanently delete \'<?= htmlspecialchars(addslashes($item['item_name'])) ?>\'? Past sales records will retain item totals.', 'Yes, Delete', true)">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- History Card -->
        <div class="card" id="history-card" style="display:none;">
            <div class="card-header">
                <span class="card-title">Restocking & Stock Movement History</span>
                <span style="font-size:0.85rem; color:var(--gray-500);">Last 25 entries</span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Action</th>
                            <th>Product</th>
                            <th>Qty Change</th>
                            <th>Unit Cost</th>
                            <th>Supplier</th>
                            <th>Ref # / Notes</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventoryLogs)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:20px;">No inventory logs found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($inventoryLogs as $log): ?>
                                <tr>
                                    <td><small><?= $log["created_at"] ?></small></td>
                                    <td>
                                        <?php if ($log["action_type"] === "restock"): ?>
                                            <span class="badge badge-success">Restock</span>
                                        <?php elseif ($log["action_type"] === "sale"): ?>
                                            <span class="badge badge-info">Sale</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><?= htmlspecialchars($log["action_type"]) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($log["item_name"]) ?></strong>
                                        <div><code><?= htmlspecialchars($log["barcode"] ?: "") ?></code></div>
                                    </td>
                                    <td>
                                        <strong style="color:<?= $log["quantity_change"] > 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                            <?= $log["quantity_change"] > 0 ? "+" . $log["quantity_change"] : $log["quantity_change"] ?>
                                        </strong>
                                    </td>
                                    <td>₱<?= number_format($log["cost_per_unit"], 2) ?></td>
                                    <td><?= htmlspecialchars($log["supplier_name"] ?: "—") ?></td>
                                    <td>
                                        <div><strong><?= htmlspecialchars($log["reference_no"] ?: "—") ?></strong></div>
                                        <small style="color:var(--gray-500);"><?= htmlspecialchars($log["notes"] ?: "") ?></small>
                                    </td>
                                    <td><small><?= htmlspecialchars($log["full_name"] ?: $log["username"]) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: Add Product -->
    <div class="modal-overlay" id="add-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Add New Product</h3>
                <button class="modal-close" onclick="closeModal('add-modal')">&times;</button>
            </div>
            <form method="POST" action="index.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_product">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_barcode">Barcode (Manual or Generated)</label>
                            <div style="display:flex; gap:6px;">
                                <input type="text" id="add_barcode" name="barcode" class="form-control" placeholder="e.g. 1012">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="generateBarcode()">Gen</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="add_category_id">Category *</label>
                            <select id="add_category_id" name="category_id" class="form-control" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat["category_id"] ?>"><?= htmlspecialchars($cat["category_name"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add_item_name">Product / Item Name *</label>
                        <input type="text" id="add_item_name" name="item_name" class="form-control" required placeholder="e.g. Garlic Fried Rice">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_cost_price">Cost Price (₱) *</label>
                            <input type="number" step="0.01" id="add_cost_price" name="cost_price" class="form-control" required placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="add_price">Selling Price (₱) *</label>
                            <input type="number" step="0.01" id="add_price" name="price" class="form-control" required placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_stock">Initial Stock Quantity</label>
                            <input type="number" id="add_stock" name="stock" class="form-control" value="0">
                        </div>
                        <div class="form-group">
                            <label for="add_threshold">Low Stock Threshold</label>
                            <input type="number" id="add_threshold" name="low_stock_threshold" class="form-control" value="10">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_expiration">Expiration Date (optional)</label>
                            <input type="date" id="add_expiration" name="expiration_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="add_supplier_id">Linked Supplier</label>
                            <select id="add_supplier_id" name="supplier_id" class="form-control">
                                <option value="">No Supplier Linked</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s["supplier_id"] ?>"><?= htmlspecialchars($s["supplier_name"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:10px;">
                        <label>Product Photo (Optional)</label>
                        <div style="display:flex; gap:14px; align-items:center;">
                            <div id="add-img-preview-box" style="width:68px; height:68px; border-radius:10px; border:2px dashed #E6D9C8; background:#FFFDF8; display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:26px; flex-shrink:0;">
                                📸
                            </div>
                            <div style="flex:1;">
                                <input type="file" name="product_image" id="add_product_image" class="form-control" accept="image/*" onchange="previewProductImage(this, 'add-img-preview-box')">
                                <small style="color:var(--gray-500); display:block; margin-top:4px;">Upload photo (JPG, PNG, WEBP). Recommended: square food photo.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('add-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Product -->
    <div class="modal-overlay" id="edit-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Edit Product</h3>
                <button class="modal-close" onclick="closeModal('edit-modal')">&times;</button>
            </div>
            <form method="POST" action="index.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" id="edit_item_id" name="item_id" value="">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_barcode">Barcode</label>
                            <input type="text" id="edit_barcode" name="barcode" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_category_id">Category *</label>
                            <select id="edit_category_id" name="category_id" class="form-control" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat["category_id"] ?>"><?= htmlspecialchars($cat["category_name"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_item_name">Product / Item Name *</label>
                        <input type="text" id="edit_item_name" name="item_name" class="form-control" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_cost_price">Cost Price (₱) *</label>
                            <input type="number" step="0.01" id="edit_cost_price" name="cost_price" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_price">Selling Price (₱) *</label>
                            <input type="number" step="0.01" id="edit_price" name="price" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_threshold">Low Stock Threshold</label>
                            <input type="number" id="edit_threshold" name="low_stock_threshold" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_status">Availability in POS</label>
                            <select id="edit_is_available" name="is_available" class="form-control">
                                <option value="1">Available</option>
                                <option value="0">Hidden / Unavailable</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_expiration">Expiration Date</label>
                            <input type="date" id="edit_expiration" name="expiration_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_supplier_id">Linked Supplier</label>
                            <select id="edit_supplier_id" name="supplier_id" class="form-control">
                                <option value="">No Supplier Linked</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s["supplier_id"] ?>"><?= htmlspecialchars($s["supplier_name"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:10px;">
                        <label>Product Photo</label>
                        <div style="display:flex; gap:14px; align-items:center;">
                            <div id="edit-img-preview-box" style="width:68px; height:68px; border-radius:10px; border:2px dashed #E6D9C8; background:#FFFDF8; display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:26px; flex-shrink:0;">
                                📸
                            </div>
                            <div style="flex:1;">
                                <input type="file" name="product_image" id="edit_product_image" class="form-control" accept="image/*" onchange="previewProductImage(this, 'edit-img-preview-box')">
                                <small style="color:var(--gray-500); display:block; margin-top:4px;">Upload new photo to replace current image, or leave blank to keep current photo.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('edit-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Restock Product -->
    <div class="modal-overlay" id="restock-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Restock Product Inventory</h3>
                <button class="modal-close" onclick="closeModal('restock-modal')">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="restock_product">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="restock_item_id">Select Product to Restock *</label>
                        <select id="restock_item_id" name="item_id" class="form-control" required onchange="onRestockItemChange()">
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($items as $it): ?>
                                <option value="<?= $it["item_id"] ?>" 
                                        data-cost="<?= $it["cost_price"] ?>"
                                        data-stock="<?= $it["stock"] ?>"
                                        data-supplier="<?= $it["supplier_id"] ?>">
                                    <?= htmlspecialchars($it["item_name"]) ?> (Current Stock: <?= $it["stock"] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="restock_qty">Quantity to Add *</label>
                            <input type="number" id="restock_qty" name="quantity" class="form-control" required min="1" placeholder="e.g. 50">
                        </div>
                        <div class="form-group">
                            <label for="restock_cost">Purchase Unit Cost (₱)</label>
                            <input type="number" step="0.01" id="restock_cost" name="cost_price" class="form-control" placeholder="Unit cost">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="restock_supplier">Supplier / Vendor</label>
                            <select id="restock_supplier" name="supplier_id" class="form-control">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s["supplier_id"] ?>"><?= htmlspecialchars($s["supplier_name"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="restock_ref">Delivery / Invoice Ref #</label>
                            <input type="text" id="restock_ref" name="reference_no" class="form-control" placeholder="e.g. INV-2026-0901">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="restock_notes">Notes / Remarks</label>
                        <input type="text" id="restock_notes" name="notes" class="form-control" placeholder="Delivery notes, batch details, etc.">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('restock-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Restock</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentFilterMode = 'all';

        function previewProductImage(input, previewBoxId) {
            const box = document.getElementById(previewBoxId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    box.innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover; border-radius:8px;">`;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function openAddModal() {
            document.getElementById('add-img-preview-box').innerHTML = '📸';
            document.getElementById('add_product_image').value = '';
            document.getElementById('add-modal').classList.add('active');
        }

        function generateBarcode() {
            // Generates random 6-digit barcode
            const code = Math.floor(100000 + Math.random() * 900000);
            document.getElementById('add_barcode').value = code;
        }

        function openEditModal(item) {
            document.getElementById('edit_item_id').value = item.item_id;
            document.getElementById('edit_barcode').value = item.barcode || '';
            document.getElementById('edit_item_name').value = item.item_name;
            document.getElementById('edit_category_id').value = item.category_id;
            document.getElementById('edit_cost_price').value = item.cost_price;
            document.getElementById('edit_price').value = item.price;
            document.getElementById('edit_threshold').value = item.low_stock_threshold;
            document.getElementById('edit_expiration').value = item.expiration_date || '';
            document.getElementById('edit_supplier_id').value = item.supplier_id || '';
            document.getElementById('edit_is_available').value = item.is_available;

            const editPreview = document.getElementById('edit-img-preview-box');
            if (item.image_path) {
                editPreview.innerHTML = `<img src="../${item.image_path}" style="width:100%; height:100%; object-fit:cover; border-radius:8px;">`;
            } else {
                editPreview.innerHTML = '📸';
            }
            document.getElementById('edit_product_image').value = '';

            document.getElementById('edit-modal').classList.add('active');
        }

        function openRestockModal() {
            document.getElementById('restock_item_id').value = "";
            document.getElementById('restock_qty').value = "";
            document.getElementById('restock_cost').value = "";
            document.getElementById('restock_supplier').value = "";
            document.getElementById('restock_ref').value = "";
            document.getElementById('restock_notes').value = "";
            document.getElementById('restock-modal').classList.add('active');
        }

        function openRestockModalForItem(item) {
            openRestockModal();
            const select = document.getElementById('restock_item_id');
            select.value = item.item_id;
            document.getElementById('restock_cost').value = item.cost_price;
            document.getElementById('restock_supplier').value = item.supplier_id || '';
        }

        function onRestockItemChange() {
            const select = document.getElementById('restock_item_id');
            const selectedOpt = select.options[select.selectedIndex];
            if (selectedOpt && selectedOpt.value) {
                document.getElementById('restock_cost').value = selectedOpt.getAttribute('data-cost') || '';
                document.getElementById('restock_supplier').value = selectedOpt.getAttribute('data-supplier') || '';
            }
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function setTab(tab) {
            currentFilterMode = tab;
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            const activeBtn = document.getElementById('tab-' + tab);
            if (activeBtn) activeBtn.classList.add('active');

            if (tab === 'history') {
                document.getElementById('catalog-card').style.display = 'none';
                document.getElementById('history-card').style.display = 'block';
            } else {
                document.getElementById('catalog-card').style.display = 'block';
                document.getElementById('history-card').style.display = 'none';
                filterProducts();
            }
        }

        function setFilter(type) {
            setTab(type);
        }

        function filterProducts() {
            const query = document.getElementById('prod-search').value.toLowerCase();
            const selectedCat = document.getElementById('cat-filter').value.toLowerCase();
            const rows = document.querySelectorAll('#products-table tbody tr');

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                const cat = (row.getAttribute('data-category') || '').toLowerCase();
                const isLow = row.getAttribute('data-low') === '1';
                const isExp = row.getAttribute('data-exp') === '1';

                let matchesTab = true;
                if (currentFilterMode === 'low_stock' && !isLow) matchesTab = false;
                if (currentFilterMode === 'expiring' && !isExp) matchesTab = false;

                const matchesCat = (selectedCat === '' || cat === selectedCat);
                const matchesQuery = (query === '' || text.includes(query));

                row.style.display = (matchesTab && matchesCat && matchesQuery) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
