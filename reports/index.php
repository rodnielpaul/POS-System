<?php
require __DIR__ . "/../middleware/auth_check.php";
$allowedRoles = ["admin", "manager"];
require __DIR__ . "/../middleware/role_check.php";
require __DIR__ . "/../config/database.php";
require __DIR__ . "/../includes/logger.php";

$currentUserId = (int)$_SESSION["user_id"];
$currentUserRole = $_SESSION["role"];

$success = "";
$error = "";

// Handle "+ Log Loss / Spoilage" form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "log_loss") {
    $itemId    = (int)($_POST["item_id"] ?? 0);
    $lossQty   = (int)($_POST["quantity"] ?? 0);
    $reason    = trim($_POST["reason"] ?? "");
    $notes     = trim($_POST["notes"] ?? "");

    if ($itemId <= 0 || $lossQty <= 0) {
        $error = "Please specify a valid product and quantity greater than zero.";
    } else {
        // Fetch item stock and cost
        $itRes = $conn->query("SELECT item_name, stock, cost_price FROM menu_items WHERE item_id = $itemId");
        if ($it = $itRes->fetch_assoc()) {
            if ($it["stock"] < $lossQty) {
                $error = "Cannot report loss greater than current inventory stock ({$it['stock']} units available).";
            } else {
                $unitCost = (float)$it["cost_price"];
                $ref = !empty($reason) ? $reason : "INVENTORY-LOSS";
                $negQty = -$lossQty;

                $conn->begin_transaction();
                try {
                    // 1. Deduct stock from menu_items
                    $conn->query("UPDATE menu_items SET stock = stock - $lossQty WHERE item_id = $itemId");

                    // 2. Insert into inventory_logs
                    $stmt = $conn->prepare("INSERT INTO inventory_logs (item_id, action_type, quantity_change, cost_per_unit, reference_no, notes, user_id) VALUES (?, 'loss/damage', ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iidssi", $itemId, $negQty, $unitCost, $ref, $notes, $currentUserId);
                    $stmt->execute();

                    $conn->commit();
                    $success = "Reported loss of $lossQty unit(s) of '{$it['item_name']}'. Stock deducted.";
                    log_activity($conn, $currentUserId, "LOSS_REPORTED", "Recorded loss of $lossQty units for #$itemId ($ref)");
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to record loss: " . $e->getMessage();
                }
            }
        } else {
            $error = "Product not found.";
        }
    }
}

// -------------------------------------------------------------
// Date Range & Filter Handling
// -------------------------------------------------------------
$preset = $_GET["preset"] ?? "this_month";
$startDate = $_GET["start_date"] ?? "";
$endDate = $_GET["end_date"] ?? "";
$selectedCashier = isset($_GET["cashier_id"]) && $_GET["cashier_id"] !== "all" ? (int)$_GET["cashier_id"] : null;
$activeTab = $_GET["tab"] ?? "sales";

// Calculate dates based on preset
switch ($preset) {
    case "today":
        $startDate = date("Y-m-d");
        $endDate   = date("Y-m-d");
        break;
    case "yesterday":
        $startDate = date("Y-m-d", strtotime("-1 day"));
        $endDate   = date("Y-m-d", strtotime("-1 day"));
        break;
    case "this_week":
        $startDate = date("Y-m-d", strtotime("monday this week"));
        $endDate   = date("Y-m-d", strtotime("sunday this week"));
        break;
    case "last_month":
        $startDate = date("Y-m-01", strtotime("first day of last month"));
        $endDate   = date("Y-m-t", strtotime("last day of last month"));
        break;
    case "this_year":
        $startDate = date("Y-01-01");
        $endDate   = date("Y-12-31");
        break;
    case "all_time":
        $startDate = "2020-01-01";
        $endDate   = date("Y-m-d");
        break;
    case "custom":
        if (empty($startDate)) $startDate = date("Y-m-01");
        if (empty($endDate)) $endDate = date("Y-m-d");
        break;
    case "this_month":
    default:
        $preset = "this_month";
        $startDate = date("Y-m-01");
        $endDate   = date("Y-m-t");
        break;
}

$startDateTime = $startDate . " 00:00:00";
$endDateTime   = $endDate . " 23:59:59";

// Cashier filter clause
$cashierWhere = "";
if ($selectedCashier !== null) {
    $cashierWhere = " AND o.user_id = $selectedCashier";
}

// -------------------------------------------------------------
// 1. Sales Report Queries
// -------------------------------------------------------------
$salesSql = "
    SELECT o.*, u.full_name as cashier_name, u.username
    FROM orders o
    JOIN users u ON o.user_id = u.user_id
    WHERE o.status = 'completed'
      AND o.created_at >= '$startDateTime'
      AND o.created_at <= '$endDateTime'
      $cashierWhere
    ORDER BY o.created_at DESC
";
$salesRes = $conn->query($salesSql);

$ordersList = [];
$totalGrossSales = 0.0;
$totalTaxCollected = 0.0;
$totalDiscountsGiven = 0.0;
$totalNetRevenue = 0.0;
$paymentBreakdown = [
    "cash" => ["count" => 0, "total" => 0.0],
    "gcash" => ["count" => 0, "total" => 0.0],
    "maya" => ["count" => 0, "total" => 0.0],
    "maribank" => ["count" => 0, "total" => 0.0],
    "card" => ["count" => 0, "total" => 0.0]
];

while ($o = $salesRes->fetch_assoc()) {
    $ordersList[] = $o;
    $totalNetRevenue += (float)$o["total_amount"];
    $totalTaxCollected += (float)$o["tax_amount"];
    $totalDiscountsGiven += (float)$o["discount_amount"];
    $totalGrossSales += (float)$o["subtotal"];

    $pm = strtolower($o["payment_method"]);
    if (isset($paymentBreakdown[$pm])) {
        $paymentBreakdown[$pm]["count"]++;
        $paymentBreakdown[$pm]["total"] += (float)$o["total_amount"];
    }
}
$orderCount = count($ordersList);
$avgOrderValue = $orderCount > 0 ? ($totalNetRevenue / $orderCount) : 0.0;

// Fetch item details for these orders
$orderItemsMap = [];
if (!empty($ordersList)) {
    $orderIds = array_map(function($o) { return (int)$o["order_id"]; }, $ordersList);
    $idListStr = implode(",", $orderIds);
    $oiSql = "
        SELECT oi.*, m.item_name, m.cost_price
        FROM order_items oi
        JOIN menu_items m ON oi.item_id = m.item_id
        WHERE oi.order_id IN ($idListStr)
    ";
    $oiRes = $conn->query($oiSql);
    while ($item = $oiRes->fetch_assoc()) {
        $orderItemsMap[$item["order_id"]][] = $item;
    }
}

// Compute Cost of Goods Sold (COGS) for period
$cogsSql = "
    SELECT COALESCE(SUM(oi.quantity * m.cost_price), 0) as total_cogs
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    JOIN menu_items m ON oi.item_id = m.item_id
    WHERE o.status = 'completed'
      AND o.created_at >= '$startDateTime'
      AND o.created_at <= '$endDateTime'
      $cashierWhere
";
$cogsRes = $conn->query($cogsSql);
$totalCOGS = $cogsRes ? (float)$cogsRes->fetch_assoc()["total_cogs"] : 0.0;
$grossProfit = $totalNetRevenue - $totalCOGS;
$grossMarginPct = $totalNetRevenue > 0 ? ($grossProfit / $totalNetRevenue) * 100 : 0.0;

// -------------------------------------------------------------
// 2. Discounted Sales (Senior / PWD) Queries
// -------------------------------------------------------------
$discountedOrders = array_filter($ordersList, function($o) {
    return in_array($o["discount_type"], ["senior", "pwd", "custom"], true) || (float)$o["discount_amount"] > 0;
});
$seniorCount = 0;
$pwdCount = 0;
foreach ($discountedOrders as $d) {
    if ($d["discount_type"] === "senior") $seniorCount++;
    if ($d["discount_type"] === "pwd") $pwdCount++;
}

// -------------------------------------------------------------
// 3. Inventory Losses / Spoilage Queries
// -------------------------------------------------------------
$lossSql = "
    SELECT l.*, m.item_name, m.barcode, u.full_name as logged_by_name, u.username
    FROM inventory_logs l
    JOIN menu_items m ON l.item_id = m.item_id
    JOIN users u ON l.user_id = u.user_id
    WHERE l.action_type = 'loss/damage'
      AND l.created_at >= '$startDateTime'
      AND l.created_at <= '$endDateTime'
    ORDER BY l.created_at DESC
";
$lossRes = $conn->query($lossSql);
$lossesList = [];
$totalLossesCost = 0.0;
$totalLostUnits = 0;

if ($lossRes) {
    while ($lr = $lossRes->fetch_assoc()) {
        $lossesList[] = $lr;
        $qty = abs((int)$lr["quantity_change"]);
        $cost = (float)$lr["cost_per_unit"];
        $totalLostUnits += $qty;
        $totalLossesCost += ($qty * $cost);
    }
}

// -------------------------------------------------------------
// 4. Salary & Payroll Queries
// -------------------------------------------------------------
$payrollSql = "
    SELECT p.*, u.full_name as employee_name, u.username, r.role_name
    FROM payroll_records p
    JOIN users u ON p.user_id = u.user_id
    JOIN roles r ON u.role_id = r.role_id
    WHERE p.payment_date >= '$startDate'
      AND p.payment_date <= '$endDate'
    ORDER BY p.payment_date DESC
";
$payrollRes = $conn->query($payrollSql);
$payrollList = [];
$totalGrossPayroll = 0.0;
$totalPayrollDeductions = 0.0;
$totalNetPayroll = 0.0;

if ($payrollRes) {
    while ($pr = $payrollRes->fetch_assoc()) {
        $payrollList[] = $pr;
        $totalGrossPayroll += (float)$pr["gross_pay"];
        $totalPayrollDeductions += (float)$pr["deductions"];
        $totalNetPayroll += (float)$pr["net_pay"];
    }
}

// -------------------------------------------------------------
// 5. Income Statement Calculations
// -------------------------------------------------------------
// Revenue:
//   Gross Sales: $totalGrossSales
//   Less: Discounts: $totalDiscountsGiven
//   Net Sales Revenue: $totalNetRevenue
// Cost of Goods Sold (COGS): $totalCOGS
// Gross Profit: $grossProfit
// Operating Expenses:
//   Salaries Expense: $totalGrossPayroll
//   Inventory Losses & Spoilage: $totalLossesCost
$totalOperatingExpenses = $totalGrossPayroll + $totalLossesCost;
$netOperatingIncome = $grossProfit - $totalOperatingExpenses;
$netMarginPct = $totalNetRevenue > 0 ? ($netOperatingIncome / $totalNetRevenue) * 100 : 0.0;

// Fetch cashiers for filter dropdown
$cashiersRes = $conn->query("SELECT user_id, full_name, username FROM users ORDER BY full_name ASC");
$allStaff = [];
while ($st = $cashiersRes->fetch_assoc()) {
    $allStaff[] = $st;
}

// Fetch all active items for the Log Loss modal dropdown
$itemsRes = $conn->query("SELECT item_id, item_name, barcode, stock, cost_price FROM menu_items WHERE stock > 0 ORDER BY item_name ASC");
$stockItems = [];
while ($si = $itemsRes->fetch_assoc()) {
    $stockItems[] = $si;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Financial Analytics | Capuzan Kusina</title>
    <link rel="stylesheet" href="../assets/css/app.css">
    <style>
        .filter-panel {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .tab-nav {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid var(--gray-200);
            margin-bottom: 24px;
            overflow-x: auto;
        }

        .tab-nav a {
            padding: 12px 18px;
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--gray-600);
            text-decoration: none;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            transition: all 0.15s ease;
        }

        .tab-nav a:hover {
            color: var(--primary);
        }

        .tab-nav a.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(37, 99, 235, 0.04);
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }

        /* Statement Sheet */
        .statement-sheet {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 32px 40px;
            max-width: 820px;
            margin: 0 auto;
            box-shadow: var(--shadow-md);
        }

        .stmt-header {
            text-align: center;
            border-bottom: 2px solid var(--gray-800);
            padding-bottom: 16px;
            margin-bottom: 24px;
        }

        .stmt-line {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 0.95rem;
        }

        .stmt-indent {
            padding-left: 24px;
            color: var(--gray-600);
        }

        .stmt-subtotal {
            font-weight: 700;
            border-top: 1px solid var(--gray-300);
            padding-top: 6px;
            margin-top: 4px;
        }

        .stmt-grandtotal {
            font-weight: 800;
            font-size: 1.15rem;
            border-top: 2px solid var(--gray-800);
            border-bottom: 3px double var(--gray-800);
            padding: 12px 0;
            margin-top: 12px;
        }

        @media print {
            .app-navbar, .filter-panel, .tab-nav, .no-print {
                display: none !important;
            }
            body {
                background: #fff;
            }
            .statement-sheet, .card {
                border: none;
                box-shadow: none;
                padding: 0;
                width: 100%;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/../includes/navbar.php"; ?>

    <main class="main-wrapper">
        <div class="page-header">
            <div>
                <h1 class="page-title">Reports & Business Analytics</h1>
                <p class="page-subtitle">Track sales performance, senior/PWD discounts, inventory losses, payroll, and income statements.</p>
            </div>
            <div class="no-print">
                <button class="btn btn-secondary" onclick="window.print()">
                    🖨️ Print Report
                </button>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success no-print">
                <span>✓ <?= htmlspecialchars($success) ?></span>
                <button onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-weight:bold;">✕</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger no-print">
                <span>⚠ <?= htmlspecialchars($error) ?></span>
                <button onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-weight:bold;">✕</button>
            </div>
        <?php endif; ?>

        <!-- Global Filter Panel -->
        <form method="GET" action="index.php" class="filter-panel no-print">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">

            <div class="filter-group">
                <label style="font-size:0.85rem; font-weight:700;">Date Range:</label>
                <select name="preset" id="preset-select" class="form-control" style="width:160px;" onchange="toggleCustomDates()">
                    <option value="today" <?= $preset === "today" ? "selected" : "" ?>>Today</option>
                    <option value="yesterday" <?= $preset === "yesterday" ? "selected" : "" ?>>Yesterday</option>
                    <option value="this_week" <?= $preset === "this_week" ? "selected" : "" ?>>This Week</option>
                    <option value="this_month" <?= $preset === "this_month" ? "selected" : "" ?>>This Month</option>
                    <option value="last_month" <?= $preset === "last_month" ? "selected" : "" ?>>Last Month</option>
                    <option value="this_year" <?= $preset === "this_year" ? "selected" : "" ?>>This Year</option>
                    <option value="all_time" <?= $preset === "all_time" ? "selected" : "" ?>>All Time</option>
                    <option value="custom" <?= $preset === "custom" ? "selected" : "" ?>>Custom Range</option>
                </select>

                <div id="custom-dates" style="display:<?= $preset === 'custom' ? 'flex' : 'none' ?>; gap:8px; align-items:center;">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-control" style="width:145px;">
                    <span style="font-size:0.85rem; color:var(--gray-500);">to</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-control" style="width:145px;">
                </div>
            </div>

            <div class="filter-group">
                <label style="font-size:0.85rem; font-weight:700;">Cashier / Staff:</label>
                <select name="cashier_id" class="form-control" style="width:180px;">
                    <option value="all">All Cashiers</option>
                    <?php foreach ($allStaff as $staff): ?>
                        <option value="<?= $staff["user_id"] ?>" <?= $selectedCashier === (int)$staff["user_id"] ? "selected" : "" ?>>
                            <?= htmlspecialchars($staff["full_name"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-primary btn-sm" style="padding:9px 16px;">
                    Filter
                </button>
            </div>
        </form>

        <!-- Navigation Tabs -->
        <nav class="tab-nav no-print">
            <a href="?tab=sales&preset=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&cashier_id=<?= urlencode($selectedCashier ?? 'all') ?>" 
               class="<?= $activeTab === 'sales' ? 'active' : '' ?>">
               📈 Sales Report
            </a>
            <a href="?tab=discounts&preset=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&cashier_id=<?= urlencode($selectedCashier ?? 'all') ?>" 
               class="<?= $activeTab === 'discounts' ? 'active' : '' ?>">
               🎖️ Discounted Sales (Senior / PWD)
            </a>
            <a href="?tab=losses&preset=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&cashier_id=<?= urlencode($selectedCashier ?? 'all') ?>" 
               class="<?= $activeTab === 'losses' ? 'active' : '' ?>">
               ⚠️ Losses & Spoilage
            </a>
            <a href="?tab=salary&preset=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&cashier_id=<?= urlencode($selectedCashier ?? 'all') ?>" 
               class="<?= $activeTab === 'salary' ? 'active' : '' ?>">
               👥 Salary & Payroll
            </a>
            <a href="?tab=income_statement&preset=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&cashier_id=<?= urlencode($selectedCashier ?? 'all') ?>" 
               class="<?= $activeTab === 'income_statement' ? 'active' : '' ?>">
               📑 Income Statement
            </a>
        </nav>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 1: SALES REPORT -->
        <!-- ------------------------------------------------------------- -->
        <?php if ($activeTab === "sales"): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Net Sales Revenue</span>
                    <span class="stat-val" style="color:var(--success);">₱<?= number_format($totalNetRevenue, 2) ?></span>
                    <span class="stat-sub">From <?= $orderCount ?> completed transaction(s)</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Total Transactions</span>
                    <span class="stat-val"><?= $orderCount ?></span>
                    <span class="stat-sub">Avg Order Value: ₱<?= number_format($avgOrderValue, 2) ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Tax Collected</span>
                    <span class="stat-val" style="color:var(--primary);">₱<?= number_format($totalTaxCollected, 2) ?></span>
                    <span class="stat-sub">Recorded sales VAT</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Discounts Deducted</span>
                    <span class="stat-val" style="color:var(--warning);">₱<?= number_format($totalDiscountsGiven, 2) ?></span>
                    <span class="stat-sub">Senior Citizen & PWD discounts</span>
                </div>
            </div>

            <!-- Payment Methods Pill Breakdown -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <span class="card-title">Payment Channels Breakdown</span>
                    <small style="color:var(--gray-500);"><?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></small>
                </div>
                <div class="card-body" style="display:flex; gap:16px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:140px; background:#f8fafc; padding:12px 16px; border-radius:8px; border-left:4px solid var(--gray-700);">
                        <div style="font-size:0.8rem; color:var(--gray-500);">💵 Cash</div>
                        <div style="font-size:1.15rem; font-weight:700;">₱<?= number_format($paymentBreakdown["cash"]["total"], 2) ?></div>
                        <small style="color:var(--gray-500);"><?= $paymentBreakdown["cash"]["count"] ?> orders</small>
                    </div>
                    <div style="flex:1; min-width:140px; background:#f8fafc; padding:12px 16px; border-radius:8px; border-left:4px solid #007dfe;">
                        <div style="font-size:0.8rem; color:#007dfe; font-weight:600;">📱 GCash</div>
                        <div style="font-size:1.15rem; font-weight:700;">₱<?= number_format($paymentBreakdown["gcash"]["total"], 2) ?></div>
                        <small style="color:var(--gray-500);"><?= $paymentBreakdown["gcash"]["count"] ?> orders</small>
                    </div>
                    <div style="flex:1; min-width:140px; background:#f8fafc; padding:12px 16px; border-radius:8px; border-left:4px solid #00d664;">
                        <div style="font-size:0.8rem; color:#00d664; font-weight:600;">📱 Maya</div>
                        <div style="font-size:1.15rem; font-weight:700;">₱<?= number_format($paymentBreakdown["maya"]["total"], 2) ?></div>
                        <small style="color:var(--gray-500);"><?= $paymentBreakdown["maya"]["count"] ?> orders</small>
                    </div>
                    <div style="flex:1; min-width:140px; background:#f8fafc; padding:12px 16px; border-radius:8px; border-left:4px solid #ff6000;">
                        <div style="font-size:0.8rem; color:#ff6000; font-weight:600;">🏦 MariBank</div>
                        <div style="font-size:1.15rem; font-weight:700;">₱<?= number_format($paymentBreakdown["maribank"]["total"], 2) ?></div>
                        <small style="color:var(--gray-500);"><?= $paymentBreakdown["maribank"]["count"] ?> orders</small>
                    </div>
                    <div style="flex:1; min-width:140px; background:#f8fafc; padding:12px 16px; border-radius:8px; border-left:4px solid #8b5cf6;">
                        <div style="font-size:0.8rem; color:#8b5cf6; font-weight:600;">💳 Card</div>
                        <div style="font-size:1.15rem; font-weight:700;">₱<?= number_format($paymentBreakdown["card"]["total"], 2) ?></div>
                        <small style="color:var(--gray-500);"><?= $paymentBreakdown["card"]["count"] ?> orders</small>
                    </div>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Completed Sales Transactions</span>
                    <input type="text" id="sales-search" placeholder="Search order # or cashier..." class="form-control" style="width:240px;" onkeyup="filterTable('sales-table', this.value)">
                </div>
                <div class="table-responsive">
                    <table class="data-table" id="sales-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date & Time</th>
                                <th>Cashier</th>
                                <th>Payment Method</th>
                                <th>Subtotal</th>
                                <th>Tax</th>
                                <th>Discount</th>
                                <th>Total Paid</th>
                                <th>Purchased Items</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ordersList)): ?>
                                <tr><td colspan="9" style="text-align:center; padding:24px;">No sales transactions found for the selected period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($ordersList as $ord): ?>
                                    <tr>
                                        <td><strong>#<?= $ord["order_id"] ?></strong></td>
                                        <td><small><?= $ord["created_at"] ?></small></td>
                                        <td><?= htmlspecialchars($ord["cashier_name"] ?: $ord["username"]) ?></td>
                                        <td>
                                            <span class="badge badge-info" style="text-transform:uppercase;">
                                                <?= htmlspecialchars($ord["payment_method"]) ?>
                                            </span>
                                            <?php if (!empty($ord["reference_no"])): ?>
                                                <div><small style="font-family:monospace;"><?= htmlspecialchars($ord["reference_no"]) ?></small></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>₱<?= number_format($ord["subtotal"], 2) ?></td>
                                        <td>₱<?= number_format($ord["tax_amount"], 2) ?></td>
                                        <td>
                                            <?php if ($ord["discount_amount"] > 0): ?>
                                                <span style="color:var(--warning); font-weight:600;">-₱<?= number_format($ord["discount_amount"], 2) ?></span>
                                            <?php else: ?>
                                                ₱0.00
                                            <?php endif; ?>
                                        </td>
                                        <td><strong style="color:var(--success);">₱<?= number_format($ord["total_amount"], 2) ?></strong></td>
                                        <td>
                                            <?php 
                                            $items = $orderItemsMap[$ord["order_id"]] ?? [];
                                            foreach ($items as $it): ?>
                                                <span style="display:inline-block; background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:0.75rem; margin:1px;">
                                                    <?= htmlspecialchars($it["item_name"]) ?> x<?= $it["quantity"] ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 2: DISCOUNTED SALES (SENIOR / PWD) REPORT -->
        <!-- ------------------------------------------------------------- -->
        <?php if ($activeTab === "discounts"): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Total Discount Granted</span>
                    <span class="stat-val" style="color:var(--warning);">₱<?= number_format($totalDiscountsGiven, 2) ?></span>
                    <span class="stat-sub">Statutory 20% deductions</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Senior Citizen Orders</span>
                    <span class="stat-val" style="color:var(--primary);"><?= $seniorCount ?></span>
                    <span class="stat-sub">OSCA verified sales</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">PWD Orders</span>
                    <span class="stat-val" style="color:var(--primary);"><?= $pwdCount ?></span>
                    <span class="stat-sub">Persons with Disability sales</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Net Sales from Discounted</span>
                    <span class="stat-val" style="color:var(--success);">
                        ₱<?= number_format(array_sum(array_column($discountedOrders, 'total_amount')), 2) ?>
                    </span>
                    <span class="stat-sub">VAT-exempt revenue</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Senior Citizen & PWD Discount Compliance Audit</span>
                    <span style="font-size:0.85rem; color:var(--gray-500);"><?= count($discountedOrders) ?> qualifying orders</span>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date & Time</th>
                                <th>Cashier</th>
                                <th>Classification</th>
                                <th>ID Number (OSCA / PWD)</th>
                                <th>Gross Subtotal</th>
                                <th>Discount (20%)</th>
                                <th>Net Amount Paid</th>
                                <th>Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($discountedOrders)): ?>
                                <tr><td colspan="9" style="text-align:center; padding:24px;">No discounted transactions recorded for this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($discountedOrders as $dOrd): ?>
                                    <tr>
                                        <td><strong>#<?= $dOrd["order_id"] ?></strong></td>
                                        <td><small><?= $dOrd["created_at"] ?></small></td>
                                        <td><?= htmlspecialchars($dOrd["cashier_name"] ?: $dOrd["username"]) ?></td>
                                        <td>
                                            <?php if ($dOrd["discount_type"] === "senior"): ?>
                                                <span class="badge badge-purple">Senior Citizen</span>
                                            <?php elseif ($dOrd["discount_type"] === "pwd"): ?>
                                                <span class="badge badge-info">PWD</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning"><?= htmlspecialchars($dOrd["discount_type"]) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code><?= htmlspecialchars($dOrd["discount_id_no"] ?: "—") ?></code>
                                        </td>
                                        <td>₱<?= number_format($dOrd["subtotal"], 2) ?></td>
                                        <td><strong style="color:var(--warning);">-₱<?= number_format($dOrd["discount_amount"], 2) ?></strong></td>
                                        <td><strong style="color:var(--success);">₱<?= number_format($dOrd["total_amount"], 2) ?></strong></td>
                                        <td><span class="badge badge-secondary"><?= strtoupper($dOrd["payment_method"]) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 3: INVENTORY LOSSES & SPOILAGE REPORT -->
        <!-- ------------------------------------------------------------- -->
        <?php if ($activeTab === "losses"): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Total Inventory Loss</span>
                    <span class="stat-val" style="color:var(--danger);">₱<?= number_format($totalLossesCost, 2) ?></span>
                    <span class="stat-sub">Financial waste cost</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Damaged / Spoiled Units</span>
                    <span class="stat-val"><?= $totalLostUnits ?></span>
                    <span class="stat-sub">Units discarded from stock</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Loss Incidents</span>
                    <span class="stat-val"><?= count($lossesList) ?></span>
                    <span class="stat-sub">Recorded damage reports</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Action</span>
                    <button class="btn btn-danger btn-sm" onclick="openLossModal()" style="margin-top:10px;">
                        + Log Loss / Spoilage
                    </button>
                    <span class="stat-sub">Record damaged/expired item</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Inventory Losses & Spoilage Log</span>
                    <button class="btn btn-danger btn-sm no-print" onclick="openLossModal()">+ Report Damaged Item</button>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Incident Date</th>
                                <th>Product / Item</th>
                                <th>Barcode</th>
                                <th>Quantity Lost</th>
                                <th>Unit Cost</th>
                                <th>Total Loss Value</th>
                                <th>Reason / Reference</th>
                                <th>Logged By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lossesList)): ?>
                                <tr><td colspan="8" style="text-align:center; padding:24px;">No inventory losses or spoilage recorded in this timeframe.</td></tr>
                            <?php else: ?>
                                <?php foreach ($lossesList as $loss): ?>
                                    <?php 
                                        $lossQty = abs($loss["quantity_change"]); 
                                        $lineCost = $lossQty * $loss["cost_per_unit"];
                                    ?>
                                    <tr>
                                        <td><small><?= $loss["created_at"] ?></small></td>
                                        <td><strong><?= htmlspecialchars($loss["item_name"]) ?></strong></td>
                                        <td><code><?= htmlspecialchars($loss["barcode"] ?: "—") ?></code></td>
                                        <td><span class="badge badge-danger"><?= $lossQty ?> units</span></td>
                                        <td>₱<?= number_format($loss["cost_per_unit"], 2) ?></td>
                                        <td><strong style="color:var(--danger);">₱<?= number_format($lineCost, 2) ?></strong></td>
                                        <td>
                                            <div><strong><?= htmlspecialchars($loss["reference_no"] ?: "Wastage") ?></strong></div>
                                            <small style="color:var(--gray-500);"><?= htmlspecialchars($loss["notes"] ?: "") ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($loss["logged_by_name"] ?: $loss["username"]) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 4: SALARY & PAYROLL REPORT -->
        <!-- ------------------------------------------------------------- -->
        <?php if ($activeTab === "salary"): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Total Gross Payroll</span>
                    <span class="stat-val">₱<?= number_format($totalGrossPayroll, 2) ?></span>
                    <span class="stat-sub">Before deductions</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Total Deductions</span>
                    <span class="stat-val" style="color:var(--warning);">₱<?= number_format($totalPayrollDeductions, 2) ?></span>
                    <span class="stat-sub">SSS, PhilHealth, Pag-IBIG</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Net Salaries Disbursed</span>
                    <span class="stat-val" style="color:var(--success);">₱<?= number_format($totalNetPayroll, 2) ?></span>
                    <span class="stat-sub">Take-home pay disbursed</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Payroll Records</span>
                    <span class="stat-val"><?= count($payrollList) ?></span>
                    <span class="stat-sub">Employees paid in period</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Employee Salary Disbursements</span>
                    <small style="color:var(--gray-500);">Paid between <?= htmlspecialchars($startDate) ?> and <?= htmlspecialchars($endDate) ?></small>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Pay Date</th>
                                <th>Employee</th>
                                <th>Role</th>
                                <th>Pay Period</th>
                                <th>Hours / Rate</th>
                                <th>Gross Pay</th>
                                <th>Deductions</th>
                                <th>Net Pay</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payrollList)): ?>
                                <tr><td colspan="9" style="text-align:center; padding:24px;">No payroll salary entries found for this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payrollList as $pr): ?>
                                    <tr>
                                        <td><strong><?= $pr["payment_date"] ?></strong></td>
                                        <td><?= htmlspecialchars($pr["employee_name"] ?: $pr["username"]) ?></td>
                                        <td><span class="role-pill role-<?= htmlspecialchars($pr["role_name"]) ?>"><?= htmlspecialchars($pr["role_name"]) ?></span></td>
                                        <td><small><?= $pr["pay_period_start"] ?> to <?= $pr["pay_period_end"] ?></small></td>
                                        <td><?= $pr["hours_worked"] ?> hrs @ ₱<?= number_format($pr["hourly_rate"], 2) ?></td>
                                        <td>₱<?= number_format($pr["gross_pay"], 2) ?></td>
                                        <td><span style="color:var(--danger);">-₱<?= number_format($pr["deductions"], 2) ?></span></td>
                                        <td><strong style="color:var(--success);">₱<?= number_format($pr["net_pay"], 2) ?></strong></td>
                                        <td><span class="badge badge-success">Paid</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 5: INCOME STATEMENT (FINANCIAL SUMMARY) -->
        <!-- ------------------------------------------------------------- -->
        <?php if ($activeTab === "income_statement"): ?>
            <div class="statement-sheet">
                <div class="stmt-header">
                    <h2 style="font-size:1.6rem; font-weight:800; text-transform:uppercase; margin-bottom:4px; color:var(--deep-brown);">Capuzan Kusina</h2>
                    <h3 style="font-size:1.15rem; font-weight:700; color:var(--gray-700); margin-bottom:4px;">INCOME STATEMENT</h3>
                    <p style="font-size:0.85rem; color:var(--gray-500); margin:0;">
                        For the Period: <strong><?= date("F d, Y", strtotime($startDate)) ?></strong> through <strong><?= date("F d, Y", strtotime($endDate)) ?></strong>
                    </p>
                </div>

                <!-- Revenues -->
                <div style="margin-bottom:16px;">
                    <div style="font-weight:700; text-transform:uppercase; font-size:0.9rem; color:var(--primary); margin-bottom:6px;">
                        I. OPERATING REVENUES
                    </div>
                    <div class="stmt-line stmt-indent">
                        <span>Gross Sales</span>
                        <span>₱<?= number_format($totalGrossSales, 2) ?></span>
                    </div>
                    <div class="stmt-line stmt-indent">
                        <span>Less: Sales Discounts (Senior Citizen & PWD)</span>
                        <span style="color:var(--danger);">(₱<?= number_format($totalDiscountsGiven, 2) ?>)</span>
                    </div>
                    <div class="stmt-line stmt-subtotal">
                        <span>NET SALES REVENUE</span>
                        <span style="color:var(--success);">₱<?= number_format($totalNetRevenue, 2) ?></span>
                    </div>
                </div>

                <!-- Cost of Goods Sold -->
                <div style="margin-bottom:16px;">
                    <div style="font-weight:700; text-transform:uppercase; font-size:0.9rem; color:var(--primary); margin-bottom:6px;">
                        II. COST OF GOODS SOLD (COGS)
                    </div>
                    <div class="stmt-line stmt-indent">
                        <span>Food, Beverage & Packaging Wholesale Cost</span>
                        <span style="color:var(--danger);">(₱<?= number_format($totalCOGS, 2) ?>)</span>
                    </div>
                    <div class="stmt-line stmt-subtotal">
                        <span>GROSS PROFIT</span>
                        <span style="color:<?= $grossProfit >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                            ₱<?= number_format($grossProfit, 2) ?> 
                            <small style="font-weight:normal; font-size:0.8rem;">(<?= number_format($grossMarginPct, 1) ?>% margin)</small>
                        </span>
                    </div>
                </div>

                <!-- Operating Expenses -->
                <div style="margin-bottom:16px;">
                    <div style="font-weight:700; text-transform:uppercase; font-size:0.9rem; color:var(--primary); margin-bottom:6px;">
                        III. OPERATING EXPENSES
                    </div>
                    <div class="stmt-line stmt-indent">
                        <span>Employee Salaries & Wages</span>
                        <span>₱<?= number_format($totalGrossPayroll, 2) ?></span>
                    </div>
                    <div class="stmt-line stmt-indent">
                        <span>Inventory Spoilage, Damage & Losses</span>
                        <span>₱<?= number_format($totalLossesCost, 2) ?></span>
                    </div>
                    <div class="stmt-line stmt-subtotal">
                        <span>TOTAL OPERATING EXPENSES</span>
                        <span style="color:var(--danger);">(₱<?= number_format($totalOperatingExpenses, 2) ?>)</span>
                    </div>
                </div>

                <!-- Net Income -->
                <div class="stmt-grandtotal stmt-line">
                    <div>
                        <div>NET OPERATING INCOME / (LOSS)</div>
                        <small style="font-size:0.8rem; font-weight:normal; color:var(--gray-500);">Net Profit Margin: <?= number_format($netMarginPct, 1) ?>%</small>
                    </div>
                    <div style="color:<?= $netOperatingIncome >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                        ₱<?= number_format($netOperatingIncome, 2) ?>
                    </div>
                </div>

                <div style="margin-top:28px; font-size:0.8rem; color:var(--gray-500); text-align:center;">
                    Generated on <?= date("Y-m-d H:i:s") ?> by <?= htmlspecialchars($_SESSION["full_name"]) ?> (<?= strtoupper($currentUserRole) ?>)
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- Modal: Log Loss / Spoilage -->
    <div class="modal-overlay" id="loss-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Record Inventory Loss / Spoilage</h3>
                <button class="modal-close" onclick="closeModal('loss-modal')">&times;</button>
            </div>
            <form method="POST" action="index.php?tab=losses&preset=<?= urlencode($preset) ?>">
                <input type="hidden" name="action" value="log_loss">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="loss_item_id">Select Product with Damage/Loss *</label>
                        <select id="loss_item_id" name="item_id" class="form-control" required>
                            <option value="">-- Choose Item --</option>
                            <?php foreach ($stockItems as $it): ?>
                                <option value="<?= $it["item_id"] ?>">
                                    <?= htmlspecialchars($it["item_name"]) ?> (Stock: <?= $it["stock"] ?>, Cost: ₱<?= number_format($it["cost_price"], 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="loss_qty">Quantity Damaged / Lost *</label>
                            <input type="number" id="loss_qty" name="quantity" class="form-control" required min="1" placeholder="e.g. 2">
                        </div>
                        <div class="form-group">
                            <label for="loss_reason">Reason Category *</label>
                            <select id="loss_reason" name="reason" class="form-control" required>
                                <option value="SPOILAGE">Kitchen Spoilage</option>
                                <option value="EXPIRED">Expired Past Shelf Life</option>
                                <option value="DAMAGED">Dropped / Physical Damage</option>
                                <option value="INVENTORY-ADJUST">Stock Discrepancy</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="loss_notes">Specific Notes / Incident Details</label>
                        <input type="text" id="loss_notes" name="notes" class="form-control" placeholder="e.g. Broken packaging upon delivery inspection">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('loss-modal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm & Deduct Stock</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleCustomDates() {
            const preset = document.getElementById('preset-select').value;
            const customDatesBox = document.getElementById('custom-dates');
            customDatesBox.style.display = (preset === 'custom') ? 'flex' : 'none';
        }

        function openLossModal() {
            document.getElementById('loss-modal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function filterTable(tableId, query) {
            query = query.toLowerCase();
            const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
