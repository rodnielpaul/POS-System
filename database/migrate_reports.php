<?php
// Migration for Reports module: payroll_records and sample reporting seeds
require_once __DIR__ . "/../config/database.php";

echo "--- Starting Reports Module Migration ---\n";

// 1. Create payroll_records table
$sql = "
CREATE TABLE IF NOT EXISTS payroll_records (
    payroll_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    pay_period_start DATE NOT NULL,
    pay_period_end DATE NOT NULL,
    hours_worked DECIMAL(6,2) DEFAULT 0.00,
    hourly_rate DECIMAL(8,2) DEFAULT 0.00,
    gross_pay DECIMAL(10,2) NOT NULL,
    deductions DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_pay DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    status ENUM('paid', 'pending') NOT NULL DEFAULT 'paid',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

if ($conn->query($sql)) {
    echo "[OK] `payroll_records` table verified/created.\n";
} else {
    echo "[ERR] Failed creating `payroll_records`: " . $conn->error . "\n";
}

// 2. Seed realistic payroll records if empty
$chkPayroll = $conn->query("SELECT COUNT(*) as c FROM payroll_records");
$pCount = $chkPayroll ? (int)$chkPayroll->fetch_assoc()["c"] : 0;

if ($pCount === 0) {
    // Fetch users
    $uRes = $conn->query("SELECT user_id, full_name, role_id FROM users WHERE status = 'active' LIMIT 4");
    $users = [];
    while ($u = $uRes->fetch_assoc()) {
        $users[] = $u;
    }

    $samplePayroll = [
        // [hours, rate, gross, deductions, net, start, end, pay_date, notes]
        [80.00, 100.00, 8000.00, 450.00, 7550.00, date('Y-m-01'), date('Y-m-15'), date('Y-m-15'), 'Semi-monthly regular cutoff'],
        [88.00, 100.00, 8800.00, 520.00, 8280.00, date('Y-m-01'), date('Y-m-15'), date('Y-m-15'), 'Regular cutoff + 8 hrs OT'],
        [80.00, 150.00, 12000.00, 850.00, 11150.00, date('Y-m-01'), date('Y-m-15'), date('Y-m-15'), 'Manager cutoff salary'],
    ];

    $pStmt = $conn->prepare("INSERT INTO payroll_records (user_id, pay_period_start, pay_period_end, hours_worked, hourly_rate, gross_pay, deductions, net_pay, payment_date, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?)");

    for ($i = 0; $i < count($users) && $i < count($samplePayroll); $i++) {
        $u = $users[$i];
        $sp = $samplePayroll[$i];
        $pStmt->bind_param("issdddddss", $u["user_id"], $sp[5], $sp[6], $sp[0], $sp[1], $sp[2], $sp[3], $sp[4], $sp[7], $sp[8]);
        $pStmt->execute();
    }
    echo "[OK] Seeded sample payroll records.\n";
}

// 3. Seed realistic losses / spoilage if none exist
$chkLosses = $conn->query("SELECT COUNT(*) as c FROM inventory_logs WHERE action_type = 'loss/damage'");
$lossCount = $chkLosses ? (int)$chkLosses->fetch_assoc()["c"] : 0;

if ($lossCount === 0) {
    $firstUserRes = $conn->query("SELECT user_id FROM users WHERE role_id = 3 LIMIT 1");
    $adminUserId = $firstUserRes ? (int)$firstUserRes->fetch_assoc()["user_id"] : 1;

    $losses = [
        [3, 2, 96.00, 'KITCHEN-SPOIL', 'Pork Sisig portion expired in chiller', date('Y-m-d', strtotime('-3 days'))],
        [7, 3, 36.00, 'EXPIRED-DESSERT', 'Leche Flan past shelf life expiration', date('Y-m-d', strtotime('-1 days'))],
        [4, 4, 24.00, 'DAMAGE-SPILL', 'Iced Tea concentrate jug spilled in storage', date('Y-m-d')]
    ];

    $lStmt = $conn->prepare("INSERT INTO inventory_logs (item_id, action_type, quantity_change, cost_per_unit, reference_no, notes, user_id, created_at) VALUES (?, 'loss/damage', ?, ?, ?, ?, ?, ?)");
    foreach ($losses as $l) {
        $negQty = -$l[1];
        $lStmt->bind_param("iidssis", $l[0], $negQty, $l[2], $l[3], $l[4], $adminUserId, $l[5]);
        $lStmt->execute();
    }
    echo "[OK] Seeded sample inventory losses/spoilage entries.\n";
}

// 4. Seed realistic varied orders for the current month if order count is low
$orderCountRes = $conn->query("SELECT COUNT(*) as c FROM orders");
$orderCount = $orderCountRes ? (int)$orderCountRes->fetch_assoc()["c"] : 0;

    $firstCashier = $conn->query("SELECT user_id FROM users WHERE role_id = 1 LIMIT 1")->fetch_assoc();
    $cashierId = $firstCashier ? (int)$firstCashier["user_id"] : 2;

    $sampleOrders = [
        // [subtotal, tax, disc_type, disc_id, disc_amt, total, method, ref, date]
        [450.00, 54.00, 'none', '', 0.00, 504.00, 'cash', '', date('Y-m-d H:i:s', strtotime('-4 days'))],
        [320.00, 38.40, 'none', '', 0.00, 358.40, 'gcash', 'GCASH-98214', date('Y-m-d H:i:s', strtotime('-2 days'))],
        [600.00, 0.00, 'senior', 'OSCA-48192', 120.00, 480.00, 'cash', '', date('Y-m-d H:i:s', strtotime('-1 days'))],
        [350.00, 0.00, 'pwd', 'PWD-88319', 70.00, 280.00, 'maya', 'MAYA-44910', date('Y-m-d H:i:s')],
        [280.00, 33.60, 'none', '', 0.00, 313.60, 'maribank', 'MARI-11029', date('Y-m-d H:i:s')],
    ];

    $oStmt = $conn->prepare("INSERT INTO orders (user_id, subtotal, total_amount, tax_rate, tax_amount, discount_type, discount_id_no, discount_amount, payment_method, amount_paid, change_amount, reference_no, status, created_at) VALUES (?, ?, ?, 12.00, ?, ?, ?, ?, ?, ?, 0.00, ?, 'completed', ?)");
    $oiStmt = $conn->prepare("INSERT INTO order_items (order_id, item_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");

    foreach ($sampleOrders as $so) {
        $oStmt->bind_param("idddssdsdss", $cashierId, $so[0], $so[5], $so[1], $so[2], $so[3], $so[4], $so[6], $so[5], $so[7], $so[8]);
        $oStmt->execute();
        $oid = $conn->insert_id;

        // Add items per order
        $p1 = 150.00;
        $qty2 = 2;
        $sub300 = 300.00;
        $mId = (int)$conn->query("SELECT item_id FROM menu_items LIMIT 1")->fetch_assoc()["item_id"];
        $oiStmt->bind_param("iiidd", $oid, $mId, $qty2, $p1, $sub300);
        $oiStmt->execute();
    }
    echo "[OK] Seeded sample orders with varied payment methods and senior/pwd discounts.\n";

echo "--- Reports Migration Completed Successfully ---\n";
?>
