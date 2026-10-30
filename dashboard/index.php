<?php
require __DIR__ . "/../middleware/auth_check.php";
require __DIR__ . "/../config/database.php";

$role = $_SESSION["role"];
$fullName = $_SESSION["full_name"];
$userId = (int)$_SESSION["user_id"];

// Quick summary metrics
$todaySales = 0.00;
$todayOrdersCount = 0;
$lowStockCount = 0;
$totalProducts = 0;
$totalSuppliers = 0;

// Sales today
$salesQuery = "SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(created_at) = CURDATE() AND status = 'completed'";
if ($role === 'cashier') {
    $salesQuery .= " AND user_id = $userId";
}
$sRes = $conn->query($salesQuery);
if ($sRow = $sRes->fetch_assoc()) {
    $todayOrdersCount = (int)$sRow["cnt"];
    $todaySales = (float)$sRow["total"];
}

// Products & Low stock
$pRes = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN stock <= low_stock_threshold THEN 1 ELSE 0 END) as low_count FROM menu_items WHERE is_available = 1");
if ($pRow = $pRes->fetch_assoc()) {
    $totalProducts = (int)$pRow["total"];
    $lowStockCount = (int)$pRow["low_count"];
}

// Active suppliers
$suppRes = $conn->query("SELECT COUNT(*) as cnt FROM suppliers WHERE status = 'active'");
if ($suppRow = $suppRes->fetch_assoc()) {
    $totalSuppliers = (int)$suppRow["cnt"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Capuzan Kusina</title>
    <link rel="stylesheet" href="../assets/css/app.css">
    <style>
        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .module-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 24px;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        .module-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .module-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 16px;
        }

        .module-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 6px;
        }

        .module-desc {
            font-size: 0.85rem;
            color: var(--gray-500);
            line-height: 1.4;
            flex: 1;
        }

        .module-badge {
            align-self: flex-start;
            margin-top: 14px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary);
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/../includes/navbar.php"; ?>

    <main class="main-wrapper">
        <div class="page-header">
            <div>
                <h1 class="page-title">Welcome back, <?= htmlspecialchars($fullName) ?></h1>
                <p class="page-subtitle">Overview of today's sales, catalog health, and system modules.</p>
            </div>
            <div>
                <a href="../pos/index.php" class="btn btn-primary" style="padding:10px 20px; font-size:1rem;">
                    Launch Point of Sale &rarr;
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Today's Sales</span>
                <span class="stat-val" style="color:var(--success);">₱<?= number_format($todaySales, 2) ?></span>
                <span class="stat-sub"><?= $todayOrdersCount ?> transaction(s) completed today</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Catalog Products</span>
                <span class="stat-val"><?= $totalProducts ?></span>
                <span class="stat-sub">Active items available for sale</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Low Stock Alerts</span>
                <span class="stat-val" style="color:<?= $lowStockCount > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                    <?= $lowStockCount ?>
                </span>
                <span class="stat-sub"><?= $lowStockCount > 0 ? 'Items need restocking' : 'All stock levels healthy' ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active Suppliers</span>
                <span class="stat-val" style="color:var(--primary);"><?= $totalSuppliers ?></span>
                <span class="stat-sub">Vendor partnerships</span>
            </div>
        </div>

        <!-- Module Navigation Cards -->
        <h2 style="font-size:1.25rem; font-weight:700; color:var(--gray-800); margin-top:12px;">System Modules</h2>
        
        <div class="module-grid">
            <!-- POS Card (Available to all) -->
            <a href="../pos/index.php" class="module-card">
                <div class="module-icon" style="background:#eff6ff; color:#2563eb;">🛒</div>
                <div class="module-title">Point of Sale (POS)</div>
                <div class="module-desc">Process cashier sales with barcode entry, mouseless keyboard shortcuts, cashless QR display, and instant receipt generation.</div>
                <div class="module-badge">Open Terminal &rarr;</div>
            </a>

            <?php if (in_array($role, ["admin", "manager"], true)): ?>
                <!-- Inventory Card -->
                <a href="../inventory/index.php" class="module-card">
                    <div class="module-icon" style="background:#fef3c7; color:#d97706;">📦</div>
                    <div class="module-title">Inventory Management</div>
                    <div class="module-desc">Manage product catalog, monitor low stock and expiration date alerts, and perform audited restocking.</div>
                    <div class="module-badge">Manage Stock &rarr;</div>
                </a>

                <!-- Suppliers Card -->
                <a href="../suppliers/index.php" class="module-card">
                    <div class="module-icon" style="background:#f0fdf4; color:#16a34a;">🏭</div>
                    <div class="module-title">Supplier Management</div>
                    <div class="module-desc">Maintain vendor records, link suppliers to inventory products, and trace restocking deliveries.</div>
                    <div class="module-badge">View Suppliers &rarr;</div>
                </a>

                <!-- Accounts / Employees Card -->
                <a href="../employees/index.php" class="module-card">
                    <div class="module-icon" style="background:#f3e8ff; color:#7e22ce;">👥</div>
                    <div class="module-title">Account Management</div>
                    <div class="module-desc">Create and manage user accounts, assign roles (Admin, Manager, Cashier), and toggle active statuses.</div>
                    <div class="module-badge">Manage Accounts &rarr;</div>
                </a>

                <!-- Payroll Card -->
                <a href="../payroll/index.php" class="module-card">
                    <div class="module-icon" style="background:#fef2f2; color:#b91c1c;">💵</div>
                    <div class="module-title">Payroll & Salaries</div>
                    <div class="module-desc">Disburse employee salaries, track statutory deductions, calculate overtime, and generate official payslips.</div>
                    <div class="module-badge">Manage Payroll &rarr;</div>
                </a>

                <!-- Reports Card -->
                <a href="../reports/index.php" class="module-card">
                    <div class="module-icon" style="background:#ecfdf5; color:#059669;">📊</div>
                    <div class="module-title">Reports & Analytics</div>
                    <div class="module-desc">Review sales transactions, item performance, revenue breakdown, and order history.</div>
                    <div class="module-badge">View Reports &rarr;</div>
                </a>
            <?php endif; ?>

            <?php if ($role === "admin"): ?>
                <!-- System Logs Card -->
                <a href="../logs/index.php" class="module-card">
                    <div class="module-icon" style="background:#fee2e2; color:#dc2626;">🛡️</div>
                    <div class="module-title">System Audit Logs</div>
                    <div class="module-desc">Track system activity, user logins, inventory stock changes, and sales transactions with timestamps.</div>
                    <div class="module-badge">Inspect Logs &rarr;</div>
                </a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
