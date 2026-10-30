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

    // 1. Add Supplier
    if ($action === "add_supplier") {
        $supplierName  = trim($_POST["supplier_name"] ?? "");
        $contactPerson = trim($_POST["contact_person"] ?? "");
        $phone         = trim($_POST["phone"] ?? "");
        $email         = trim($_POST["email"] ?? "");
        $address       = trim($_POST["address"] ?? "");

        if (empty($supplierName)) {
            $error = "Supplier name is required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param("sssss", $supplierName, $contactPerson, $phone, $email, $address);
            if ($stmt->execute()) {
                $newId = $conn->insert_id;
                $success = "Supplier '$supplierName' added successfully.";
                log_activity($conn, $currentUserId, "SUPPLIER_CREATED", "Added supplier #$newId ($supplierName)");
            } else {
                $error = "Error adding supplier: " . $conn->error;
            }
        }
    }

    // 2. Edit Supplier
    elseif ($action === "edit_supplier") {
        $supplierId    = (int)($_POST["supplier_id"] ?? 0);
        $supplierName  = trim($_POST["supplier_name"] ?? "");
        $contactPerson = trim($_POST["contact_person"] ?? "");
        $phone         = trim($_POST["phone"] ?? "");
        $email         = trim($_POST["email"] ?? "");
        $address       = trim($_POST["address"] ?? "");
        $status        = $_POST["status"] ?? "active";

        if ($supplierId <= 0 || empty($supplierName)) {
            $error = "Invalid supplier details.";
        } else {
            $stmt = $conn->prepare("UPDATE suppliers SET supplier_name = ?, contact_person = ?, phone = ?, email = ?, address = ?, status = ? WHERE supplier_id = ?");
            $stmt->bind_param("ssssssi", $supplierName, $contactPerson, $phone, $email, $address, $status, $supplierId);
            if ($stmt->execute()) {
                $success = "Supplier '$supplierName' updated successfully.";
                log_activity($conn, $currentUserId, "SUPPLIER_UPDATED", "Updated supplier #$supplierId ($supplierName)");
            } else {
                $error = "Error updating supplier: " . $conn->error;
            }
        }
    }

    // 3. Toggle Status
    elseif ($action === "toggle_status") {
        $supplierId = (int)($_POST["supplier_id"] ?? 0);
        $sRes = $conn->query("SELECT supplier_name, status FROM suppliers WHERE supplier_id = $supplierId");
        if ($s = $sRes->fetch_assoc()) {
            $newStatus = ($s["status"] === "active") ? "inactive" : "active";
            $conn->query("UPDATE suppliers SET status = '$newStatus' WHERE supplier_id = $supplierId");
            $success = "Supplier '{$s['supplier_name']}' marked as $newStatus.";
            log_activity($conn, $currentUserId, "SUPPLIER_STATUS_CHANGE", "Changed supplier #$supplierId status to $newStatus");
        }
    }

    // 4. Delete Supplier
    elseif ($action === "delete_supplier") {
        $supplierId = (int)($_POST["supplier_id"] ?? 0);
        $sRes = $conn->query("SELECT supplier_name FROM suppliers WHERE supplier_id = $supplierId");
        if ($s = $sRes->fetch_assoc()) {
            $name = $s["supplier_name"];
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE menu_items SET supplier_id = NULL WHERE supplier_id = $supplierId");
                $conn->query("UPDATE inventory_logs SET supplier_id = NULL WHERE supplier_id = $supplierId");
                $conn->query("DELETE FROM suppliers WHERE supplier_id = $supplierId");
                $conn->commit();
                $success = "Supplier '$name' was deleted successfully.";
                log_activity($conn, $currentUserId, "SUPPLIER_DELETED", "Deleted supplier #$supplierId ($name)");
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error deleting supplier: " . $e->getMessage();
            }
        } else {
            $error = "Supplier not found.";
        }
    }
}

// Fetch suppliers with linked product count
$sql = "
    SELECT s.*, 
           COUNT(m.item_id) AS product_count
    FROM suppliers s
    LEFT JOIN menu_items m ON s.supplier_id = m.supplier_id
    GROUP BY s.supplier_id
    ORDER BY s.status ASC, s.supplier_name ASC
";
$result = $conn->query($sql);
$suppliers = [];
$totalCount = 0;
$activeCount = 0;
$inactiveCount = 0;
$totalSuppliedItems = 0;

while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
    $totalCount++;
    if ($row["status"] === "active") $activeCount++;
    else $inactiveCount++;
    $totalSuppliedItems += (int)$row["product_count"];
}

// Fetch all products grouped by supplier for the modal view
$prodRes = $conn->query("SELECT m.item_id, m.item_name, m.barcode, m.price, m.cost_price, m.stock, m.supplier_id, c.category_name 
                         FROM menu_items m 
                         LEFT JOIN categories c ON m.category_id = c.category_id 
                         WHERE m.supplier_id IS NOT NULL");
$productsBySupplier = [];
while ($p = $prodRes->fetch_assoc()) {
    $productsBySupplier[$p["supplier_id"]][] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management | Capuzan Kusina</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=<?= time() ?>">
</head>
<body>
    <?php include __DIR__ . "/../includes/navbar.php"; ?>

    <main class="main-wrapper">
        <div class="page-header">
            <div>
                <h1 class="page-title">Supplier Management</h1>
                <p class="page-subtitle">Track vendors, contacts, linked catalog products, and replenishment tracing.</p>
            </div>
            <div>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <span style="font-size:1.1rem; line-height:1;">+</span> Add Supplier
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

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Suppliers</span>
                <span class="stat-val"><?= $totalCount ?></span>
                <span class="stat-sub">Vendor partnerships</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active Vendors</span>
                <span class="stat-val" style="color:var(--success);"><?= $activeCount ?></span>
                <span class="stat-sub">Currently in good standing</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Inactive Vendors</span>
                <span class="stat-val" style="color:var(--danger);"><?= $inactiveCount ?></span>
                <span class="stat-sub">Deactivated / Archived</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Supplied Products</span>
                <span class="stat-val" style="color:var(--primary);"><?= $totalSuppliedItems ?></span>
                <span class="stat-sub">Catalog items linked to vendors</span>
            </div>
        </div>

        <!-- Suppliers Card -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Registered Suppliers Directory</span>
                <input type="text" id="supplier-search" placeholder="Search suppliers or contacts..." class="form-control" style="width:260px;" onkeyup="filterSuppliers()">
            </div>
            <div class="table-responsive">
                <table class="data-table" id="suppliers-table">
                    <thead>
                        <tr>
                            <th>Supplier Name</th>
                            <th>Contact Person</th>
                            <th>Phone / Email</th>
                            <th>Address</th>
                            <th>Linked Items</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $s): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($s["supplier_name"]) ?></strong>
                                    <div style="font-size:0.75rem; color:var(--gray-500);">ID #<?= $s["supplier_id"] ?></div>
                                </td>
                                <td><?= htmlspecialchars($s["contact_person"] ?: "—") ?></td>
                                <td>
                                    <div><?= htmlspecialchars($s["phone"] ?: "—") ?></div>
                                    <small style="color:var(--gray-500);"><?= htmlspecialchars($s["email"] ?: "") ?></small>
                                </td>
                                <td><small><?= htmlspecialchars($s["address"] ?: "—") ?></small></td>
                                <td>
                                    <button class="btn btn-secondary btn-sm" onclick='viewLinkedProducts(<?= $s["supplier_id"] ?>, "<?= htmlspecialchars(addslashes($s["supplier_name"])) ?>")'>
                                        <?= (int)$s["product_count"] ?> Item(s) &rarr;
                                    </button>
                                </td>
                                <td>
                                    <?php if ($s["status"] === "active"): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($s) ?>)'>
                                        Edit
                                    </button>

                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="supplier_id" value="<?= $s["supplier_id"] ?>">
                                        <?php if ($s["status"] === "active"): ?>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="confirmFormSubmit(this.form, 'Deactivate Supplier', 'Are you sure you want to deactivate supplier \'<?= htmlspecialchars(addslashes($s['supplier_name'])) ?>\'?', 'Deactivate', false)">Deactivate</button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-success" onclick="confirmFormSubmit(this.form, 'Activate Supplier', 'Are you sure you want to activate supplier \'<?= htmlspecialchars(addslashes($s['supplier_name'])) ?>\'?', 'Activate', false)">Activate</button>
                                        <?php endif; ?>
                                    </form>

                                    <form method="POST" action="index.php" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_supplier">
                                        <input type="hidden" name="supplier_id" value="<?= $s["supplier_id"] ?>">
                                        <button type="button" class="btn btn-sm btn-danger" style="margin-left:3px;" onclick="confirmFormSubmit(this.form, 'Delete Supplier', 'Are you sure you want to permanently delete supplier \'<?= htmlspecialchars(addslashes($s['supplier_name'])) ?>\'? Linked products will have their vendor unlinked.', 'Yes, Delete', true)">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: Add Supplier -->
    <div class="modal-overlay" id="add-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Add New Supplier</h3>
                <button class="modal-close" onclick="closeModal('add-modal')">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="add_supplier">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="add_supplier_name">Supplier / Company Name *</label>
                        <input type="text" id="add_supplier_name" name="supplier_name" class="form-control" required placeholder="e.g. San Miguel Foods">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_contact_person">Contact Person</label>
                            <input type="text" id="add_contact_person" name="contact_person" class="form-control" placeholder="e.g. Maria Santos">
                        </div>
                        <div class="form-group">
                            <label for="add_phone">Phone Number</label>
                            <input type="text" id="add_phone" name="phone" class="form-control" placeholder="e.g. +63 912 345 6789">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="add_email">Email Address</label>
                        <input type="email" id="add_email" name="email" class="form-control" placeholder="e.g. supplier@example.com">
                    </div>
                    <div class="form-group">
                        <label for="add_address">Office / Warehouse Address</label>
                        <textarea id="add_address" name="address" class="form-control" rows="2" placeholder="Full address"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('add-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Supplier -->
    <div class="modal-overlay" id="edit-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Edit Supplier</h3>
                <button class="modal-close" onclick="closeModal('edit-modal')">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="edit_supplier">
                <input type="hidden" id="edit_supplier_id" name="supplier_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_supplier_name">Supplier / Company Name *</label>
                        <input type="text" id="edit_supplier_name" name="supplier_name" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_contact_person">Contact Person</label>
                            <input type="text" id="edit_contact_person" name="contact_person" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_phone">Phone Number</label>
                            <input type="text" id="edit_phone" name="phone" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit_email">Email Address</label>
                        <input type="email" id="edit_email" name="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="edit_address">Office / Warehouse Address</label>
                        <textarea id="edit_address" name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('edit-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Supplier</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: View Linked Products -->
    <div class="modal-overlay" id="products-modal">
        <div class="modal-card" style="max-width: 680px;">
            <div class="modal-header">
                <h3 class="modal-title" id="products-modal-title">Products Supplied</h3>
                <button class="modal-close" onclick="closeModal('products-modal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="products-list-container"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('products-modal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        const productsMap = <?= json_encode($productsBySupplier) ?>;

        function openAddModal() {
            document.getElementById('add-modal').classList.add('active');
        }

        function openEditModal(supplier) {
            document.getElementById('edit_supplier_id').value = supplier.supplier_id;
            document.getElementById('edit_supplier_name').value = supplier.supplier_name;
            document.getElementById('edit_contact_person').value = supplier.contact_person || '';
            document.getElementById('edit_phone').value = supplier.phone || '';
            document.getElementById('edit_email').value = supplier.email || '';
            document.getElementById('edit_address').value = supplier.address || '';
            document.getElementById('edit_status').value = supplier.status;
            document.getElementById('edit-modal').classList.add('active');
        }

        function viewLinkedProducts(supplierId, supplierName) {
            document.getElementById('products-modal-title').textContent = "Products supplied by: " + supplierName;
            const container = document.getElementById('products-list-container');
            const prods = productsMap[supplierId] || [];

            if (prods.length === 0) {
                container.innerHTML = "<p style='color:var(--gray-500); text-align:center; padding:20px;'>No products are currently linked to this supplier. You can link products in the Inventory module.</p>";
            } else {
                let html = `
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Barcode</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                                <th>Current Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                prods.forEach(p => {
                    html += `
                        <tr>
                            <td><code>${p.barcode || '—'}</code></td>
                            <td><strong>${p.item_name}</strong></td>
                            <td>${p.category_name || 'General'}</td>
                            <td>₱${parseFloat(p.cost_price).toFixed(2)}</td>
                            <td>₱${parseFloat(p.price).toFixed(2)}</td>
                            <td><span class="badge ${p.stock <= 10 ? 'badge-warning' : 'badge-success'}">${p.stock} units</span></td>
                        </tr>
                    `;
                });
                html += `</tbody></table>`;
                container.innerHTML = html;
            }
            document.getElementById('products-modal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function filterSuppliers() {
            const query = document.getElementById('supplier-search').value.toLowerCase();
            const rows = document.querySelectorAll('#suppliers-table tbody tr');
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
