<?php
require_once __DIR__ . "/../config/database.php";

echo "--- Starting Payroll Module Migration ---\n";

function colExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($result && $result->num_rows > 0);
}

// 1. Ensure payroll_records table exists
$sqlPayrollTable = "
CREATE TABLE IF NOT EXISTS payroll_records (
    payroll_id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(50) NULL,
    user_id INT NULL,
    processed_by INT NULL,
    pay_period_start DATE NOT NULL,
    pay_period_end DATE NOT NULL,
    salary_type ENUM('hourly', 'daily', 'monthly', 'fixed') NOT NULL DEFAULT 'hourly',
    base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    hours_worked DECIMAL(6,2) DEFAULT 0.00,
    days_worked DECIMAL(5,2) DEFAULT 0.00,
    hourly_rate DECIMAL(8,2) DEFAULT 0.00,
    overtime_hours DECIMAL(6,2) DEFAULT 0.00,
    overtime_rate DECIMAL(8,2) DEFAULT 0.00,
    overtime_pay DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    allowances DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gross_pay DECIMAL(10,2) NOT NULL,
    sss_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    philhealth_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pagibig_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    other_deductions DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    deductions DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_pay DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'gcash', 'maya', 'bank_transfer', 'check') NOT NULL DEFAULT 'cash',
    status ENUM('paid', 'pending') NOT NULL DEFAULT 'paid',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($sqlPayrollTable);
echo "[OK] `payroll_records` base table ensured.\n";

// 2. Add extended columns to payroll_records if not exist
$payrollCols = [
    "reference_no" => "ALTER TABLE payroll_records ADD COLUMN reference_no VARCHAR(50) NULL AFTER payroll_id",
    "processed_by" => "ALTER TABLE payroll_records ADD COLUMN processed_by INT NULL AFTER user_id",
    "salary_type" => "ALTER TABLE payroll_records ADD COLUMN salary_type ENUM('hourly', 'daily', 'monthly', 'fixed') NOT NULL DEFAULT 'hourly' AFTER pay_period_end",
    "base_salary" => "ALTER TABLE payroll_records ADD COLUMN base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER salary_type",
    "days_worked" => "ALTER TABLE payroll_records ADD COLUMN days_worked DECIMAL(5,2) DEFAULT 0.00 AFTER hours_worked",
    "overtime_hours" => "ALTER TABLE payroll_records ADD COLUMN overtime_hours DECIMAL(6,2) DEFAULT 0.00 AFTER hourly_rate",
    "overtime_rate" => "ALTER TABLE payroll_records ADD COLUMN overtime_rate DECIMAL(8,2) DEFAULT 0.00 AFTER overtime_hours",
    "overtime_pay" => "ALTER TABLE payroll_records ADD COLUMN overtime_pay DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER overtime_rate",
    "allowances" => "ALTER TABLE payroll_records ADD COLUMN allowances DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER overtime_pay",
    "sss_deduction" => "ALTER TABLE payroll_records ADD COLUMN sss_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER gross_pay",
    "philhealth_deduction" => "ALTER TABLE payroll_records ADD COLUMN philhealth_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER sss_deduction",
    "pagibig_deduction" => "ALTER TABLE payroll_records ADD COLUMN pagibig_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER philhealth_deduction",
    "tax_deduction" => "ALTER TABLE payroll_records ADD COLUMN tax_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER pagibig_deduction",
    "other_deductions" => "ALTER TABLE payroll_records ADD COLUMN other_deductions DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_deduction",
    "payment_method" => "ALTER TABLE payroll_records ADD COLUMN payment_method ENUM('cash', 'gcash', 'maya', 'bank_transfer', 'check') NOT NULL DEFAULT 'cash' AFTER payment_date"
];

foreach ($payrollCols as $col => $query) {
    if (!colExists($conn, "payroll_records", $col)) {
        $conn->query($query);
    }
}

// 3. Add compensation columns to users table
$userCols = [
    "position_title" => "ALTER TABLE users ADD COLUMN position_title VARCHAR(100) NULL AFTER full_name",
    "contact_number" => "ALTER TABLE users ADD COLUMN contact_number VARCHAR(50) NULL AFTER position_title",
    "salary_type" => "ALTER TABLE users ADD COLUMN salary_type ENUM('hourly', 'daily', 'monthly') NOT NULL DEFAULT 'hourly' AFTER contact_number",
    "hourly_rate" => "ALTER TABLE users ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER salary_type",
    "daily_rate" => "ALTER TABLE users ADD COLUMN daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER hourly_rate",
    "monthly_rate" => "ALTER TABLE users ADD COLUMN monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER daily_rate"
];

foreach ($userCols as $col => $query) {
    if (!colExists($conn, "users", $col)) {
        $conn->query($query);
    }
}

// 4. Clean orphan records where user_id IS NULL
$conn->query("DELETE FROM payroll_records WHERE user_id IS NULL");

// 5. Check if manager exists
$mgrRes = $conn->query("SELECT user_id FROM users WHERE role_id = 2 LIMIT 1");
$mgrId = $mgrRes && $mgrRes->num_rows > 0 ? (int)$mgrRes->fetch_assoc()["user_id"] : null;

if (!$mgrId) {
    $hashed = password_hash("manager123", PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, password, full_name, role_id, status, position_title, salary_type, monthly_rate) VALUES ('manager', '$hashed', 'Maria Santos', 2, 'active', 'Restaurant Manager', 'monthly', 28000.00)");
    $mgrId = $conn->insert_id;
    echo "[OK] Seeded manager account 'manager' / 'manager123'.\n";
}

// 6. Check staff accounts
$cashiers = $conn->query("SELECT user_id, full_name, position_title, salary_type, hourly_rate, daily_rate FROM users WHERE role_id = 1 ORDER BY user_id ASC");
$staff = [];
while ($c = $cashiers->fetch_assoc()) {
    $staff[] = $c;
}

// 7. Seed sample payroll records if count is 0
$pCountRes = $conn->query("SELECT COUNT(*) as c FROM payroll_records");
$pCount = $pCountRes ? (int)$pCountRes->fetch_assoc()["c"] : 0;

if ($pCount === 0 && !empty($staff)) {
    $today = date('Y-m-d');
    $pStart = date('Y-m-01');
    $pEnd = date('Y-m-15');

    $sampleRecords = [
        [
            "ref" => "PAY-" . date('Ym') . "-001",
            "emp_idx" => 0,
            "start" => $pStart,
            "end" => $pEnd,
            "type" => "hourly",
            "hours" => 80.00,
            "days" => 10.00,
            "rate" => 75.00,
            "base" => 6000.00,
            "ot_hours" => 4.00,
            "ot_rate" => 93.75,
            "ot_pay" => 375.00,
            "allowances" => 500.00,
            "gross" => 6875.00,
            "sss" => 275.00,
            "philhealth" => 137.50,
            "pagibig" => 100.00,
            "tax" => 0.00,
            "other" => 0.00,
            "deductions" => 512.50,
            "net" => 6362.50,
            "date" => $pEnd,
            "method" => "cash",
            "status" => "paid",
            "notes" => "1st Half Cutoff regular salary - Paid in cash by Manager"
        ],
        [
            "ref" => "PAY-" . date('Ym') . "-002",
            "emp_idx" => 1,
            "start" => $pStart,
            "end" => $pEnd,
            "type" => "daily",
            "hours" => 96.00,
            "days" => 12.00,
            "rate" => 90.00,
            "base" => 9000.00,
            "ot_hours" => 6.00,
            "ot_rate" => 112.50,
            "ot_pay" => 675.00,
            "allowances" => 800.00,
            "gross" => 10475.00,
            "sss" => 419.00,
            "philhealth" => 209.50,
            "pagibig" => 100.00,
            "tax" => 150.00,
            "other" => 200.00,
            "deductions" => 1078.50,
            "net" => 9396.50,
            "date" => $pEnd,
            "method" => "gcash",
            "status" => "paid",
            "notes" => "Kitchen lead 1st cutoff salary via GCash - Disbursed by Manager"
        ],
        [
            "ref" => "PAY-" . date('Ym') . "-003",
            "emp_idx" => 2,
            "start" => $pStart,
            "end" => $pEnd,
            "type" => "hourly",
            "hours" => 75.00,
            "days" => 9.50,
            "rate" => 70.00,
            "base" => 5250.00,
            "ot_hours" => 2.00,
            "ot_rate" => 87.50,
            "ot_pay" => 175.00,
            "allowances" => 400.00,
            "gross" => 5825.00,
            "sss" => 233.00,
            "philhealth" => 116.50,
            "pagibig" => 100.00,
            "tax" => 0.00,
            "other" => 150.00,
            "deductions" => 599.50,
            "net" => 5225.50,
            "date" => $pEnd,
            "method" => "cash",
            "status" => "paid",
            "notes" => "Service crew 1st cutoff salary - Paid by Manager"
        ]
    ];

    $insStmt = $conn->prepare("INSERT INTO payroll_records (
        reference_no, user_id, processed_by, pay_period_start, pay_period_end,
        salary_type, base_salary, hours_worked, days_worked, hourly_rate, overtime_hours,
        overtime_rate, overtime_pay, allowances, gross_pay, sss_deduction,
        philhealth_deduction, pagibig_deduction, tax_deduction, other_deductions,
        deductions, net_pay, payment_date, payment_method, status, notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($sampleRecords as $r) {
        $emp = $staff[$r["emp_idx"] % count($staff)];
        $insStmt->bind_param(
            "siisssddddddddddddddddssss",
            $r["ref"],
            $emp["user_id"],
            $mgrId,
            $r["start"],
            $r["end"],
            $r["type"],
            $r["base"],
            $r["hours"],
            $r["days"],
            $r["rate"],
            $r["ot_hours"],
            $r["ot_rate"],
            $r["ot_pay"],
            $r["allowances"],
            $r["gross"],
            $r["sss"],
            $r["philhealth"],
            $r["pagibig"],
            $r["tax"],
            $r["other"],
            $r["deductions"],
            $r["net"],
            $r["date"],
            $r["method"],
            $r["status"],
            $r["notes"]
        );
        $insStmt->execute();
    }
    echo "[OK] Seeded 3 sample payroll disbursements given by Manager.\n";
}

// Log migration in system_logs
$conn->query("INSERT INTO system_logs (action, details) VALUES ('PAYROLL_MIGRATION', 'Payroll schema migration executed successfully.')");

echo "--- Payroll Module Migration Finished ---\n";
