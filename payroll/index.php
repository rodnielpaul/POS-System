<?php
require __DIR__ . "/../middleware/auth_check.php";

$allowedRoles = ["admin", "manager"];
require __DIR__ . "/../middleware/role_check.php";
require __DIR__ . "/../config/database.php";
require __DIR__ . "/../includes/logger.php";

$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$currentUserRole = $_SESSION["role"] ?? "admin";
$currentUserName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Manager";

$success = "";
$error = "";

// -------------------------------------------------------------------------
// POST HANDLERS: Manager gives salary, edits salary, marks paid, deletes, or updates rates
// -------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    // 1. MANAGER GIVES SALARY / DISBURSES PAYROLL
    if ($action === "give_salary") {
        $targetUserId = (int)($_POST["user_id"] ?? 0);
        $payPeriodStart = trim($_POST["pay_period_start"] ?? "");
        $payPeriodEnd   = trim($_POST["pay_period_end"] ?? "");
        $salaryType     = $_POST["salary_type"] ?? "hourly";
        $paymentDate    = trim($_POST["payment_date"] ?? date("Y-m-d"));
        $paymentMethod  = $_POST["payment_method"] ?? "cash";
        $status         = $_POST["status"] ?? "paid";
        $notes          = trim($_POST["notes"] ?? "");

        $hoursWorked    = max(0.0, (float)($_POST["hours_worked"] ?? 0));
        $daysWorked     = max(0.0, (float)($_POST["days_worked"] ?? 0));
        $hourlyRate     = max(0.0, (float)($_POST["hourly_rate"] ?? 0));
        $baseSalary     = max(0.0, (float)($_POST["base_salary"] ?? 0));

        $overtimeHours  = max(0.0, (float)($_POST["overtime_hours"] ?? 0));
        $overtimeRate   = max(0.0, (float)($_POST["overtime_rate"] ?? 0));
        $overtimePay    = max(0.0, (float)($_POST["overtime_pay"] ?? 0));
        $allowances     = max(0.0, (float)($_POST["allowances"] ?? 0));

        // Deductions
        $sssDeduction        = max(0.0, (float)($_POST["sss_deduction"] ?? 0));
        $philhealthDeduction = max(0.0, (float)($_POST["philhealth_deduction"] ?? 0));
        $pagibigDeduction    = max(0.0, (float)($_POST["pagibig_deduction"] ?? 0));
        $taxDeduction        = max(0.0, (float)($_POST["tax_deduction"] ?? 0));
        $otherDeductions     = max(0.0, (float)($_POST["other_deductions"] ?? 0));

        // Recompute if overtime_pay was not explicitly given
        if ($overtimePay == 0 && $overtimeHours > 0 && $overtimeRate > 0) {
            $overtimePay = round($overtimeHours * $overtimeRate, 2);
        }

        // Gross Pay
        $computedGross = $baseSalary + $overtimePay + $allowances;
        $grossPay = max(0.0, (float)($_POST["gross_pay"] ?? $computedGross));
        if ($grossPay <= 0 && $computedGross > 0) {
            $grossPay = $computedGross;
        }

        // Total Deductions
        $computedDeductions = $sssDeduction + $philhealthDeduction + $pagibigDeduction + $taxDeduction + $otherDeductions;
        $totalDeductions = max(0.0, (float)($_POST["deductions"] ?? $computedDeductions));
        if ($totalDeductions <= 0 && $computedDeductions > 0) {
            $totalDeductions = $computedDeductions;
        }

        // Net Take-Home Pay
        $netPay = max(0.0, $grossPay - $totalDeductions);

        // Reference number
        $referenceNo = trim($_POST["reference_no"] ?? "");
        if (empty($referenceNo)) {
            $randSeq = strtoupper(substr(uniqid(), -4));
            $referenceNo = "PAY-" . date("Ym", strtotime($paymentDate)) . "-" . $randSeq;
        }

        // Validate employee exists
        $empCheck = $conn->prepare("SELECT user_id, full_name, username, position_title FROM users WHERE user_id = ?");
        $empCheck->bind_param("i", $targetUserId);
        $empCheck->execute();
        $empResult = $empCheck->get_result()->fetch_assoc();

        if (!$empResult) {
            $error = "Please select a valid employee.";
        } elseif (empty($payPeriodStart) || empty($payPeriodEnd)) {
            $error = "Please specify both the start and end dates of the pay period.";
        } elseif ($grossPay <= 0) {
            $error = "Gross salary must be greater than zero.";
        } else {
            $insertQuery = "INSERT INTO payroll_records (
                reference_no, user_id, processed_by, pay_period_start, pay_period_end,
                salary_type, base_salary, hours_worked, days_worked, hourly_rate,
                overtime_hours, overtime_rate, overtime_pay, allowances, gross_pay,
                sss_deduction, philhealth_deduction, pagibig_deduction, tax_deduction,
                other_deductions, deductions, net_pay, payment_date, payment_method,
                status, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param(
                "siisssddddddddddddddddssss",
                $referenceNo,
                $targetUserId,
                $currentUserId,
                $payPeriodStart,
                $payPeriodEnd,
                $salaryType,
                $baseSalary,
                $hoursWorked,
                $daysWorked,
                $hourlyRate,
                $overtimeHours,
                $overtimeRate,
                $overtimePay,
                $allowances,
                $grossPay,
                $sssDeduction,
                $philhealthDeduction,
                $pagibigDeduction,
                $taxDeduction,
                $otherDeductions,
                $totalDeductions,
                $netPay,
                $paymentDate,
                $paymentMethod,
                $status,
                $notes
            );

            if ($stmt->execute()) {
                $newPayrollId = $conn->insert_id;

                // Optionally update employee baseline rates if requested
                if (!empty($_POST["save_as_default_rate"])) {
                    $upRateStmt = $conn->prepare("UPDATE users SET salary_type = ?, hourly_rate = ?, daily_rate = ? WHERE user_id = ?");
                    $upRateStmt->bind_param("sddi", $salaryType, $hourlyRate, $baseSalary, $targetUserId);
                    $upRateStmt->execute();
                }

                $statusText = ($status === 'paid') ? 'Disbursed' : 'Queued as Pending';
                $success = "Salary of ₱" . number_format($netPay, 2) . " successfully issued to " . htmlspecialchars($empResult["full_name"]) . "! [Ref: $referenceNo] ($statusText)";
                log_activity($conn, $currentUserId, "SALARY_DISBURSED", "Manager '$currentUserName' gave salary of ₱" . number_format($netPay, 2) . " ($paymentMethod) to employee {$empResult['full_name']} [Ref: $referenceNo, ID: #$newPayrollId]");
            } else {
                $error = "Database error issuing salary: " . $conn->error;
            }
        }
    }

    // 2. EDIT EXISTING SALARY RECORD
    elseif ($action === "edit_salary") {
        $payrollId      = (int)($_POST["payroll_id"] ?? 0);

        // Security / Business rule check: Prevent editing if already paid
        $checkStmt = $conn->prepare("SELECT status FROM payroll_records WHERE payroll_id = ?");
        $checkStmt->bind_param("i", $payrollId);
        $checkStmt->execute();
        $existingRecord = $checkStmt->get_result()->fetch_assoc();

        if ($existingRecord && $existingRecord["status"] === "paid") {
            $error = "Paid salary disbursement vouchers cannot be edited. Only pending vouchers may be modified.";
        } else {
            $payPeriodStart = trim($_POST["pay_period_start"] ?? "");
            $payPeriodEnd   = trim($_POST["pay_period_end"] ?? "");
            $salaryType     = $_POST["salary_type"] ?? "hourly";
            $paymentDate    = trim($_POST["payment_date"] ?? date("Y-m-d"));
            $paymentMethod  = $_POST["payment_method"] ?? "cash";
            $status         = $_POST["status"] ?? "paid";
            $notes          = trim($_POST["notes"] ?? "");
            $referenceNo    = trim($_POST["reference_no"] ?? "");

            $hoursWorked    = max(0.0, (float)($_POST["hours_worked"] ?? 0));
            $daysWorked     = max(0.0, (float)($_POST["days_worked"] ?? 0));
            $hourlyRate     = max(0.0, (float)($_POST["hourly_rate"] ?? 0));
            $baseSalary     = max(0.0, (float)($_POST["base_salary"] ?? 0));

            $overtimeHours  = max(0.0, (float)($_POST["overtime_hours"] ?? 0));
            $overtimeRate   = max(0.0, (float)($_POST["overtime_rate"] ?? 0));
            $overtimePay    = max(0.0, (float)($_POST["overtime_pay"] ?? 0));
            $allowances     = max(0.0, (float)($_POST["allowances"] ?? 0));

            $sssDeduction        = max(0.0, (float)($_POST["sss_deduction"] ?? 0));
            $philhealthDeduction = max(0.0, (float)($_POST["philhealth_deduction"] ?? 0));
            $pagibigDeduction    = max(0.0, (float)($_POST["pagibig_deduction"] ?? 0));
            $taxDeduction        = max(0.0, (float)($_POST["tax_deduction"] ?? 0));
            $otherDeductions     = max(0.0, (float)($_POST["other_deductions"] ?? 0));

            $grossPay        = max(0.0, (float)($_POST["gross_pay"] ?? ($baseSalary + $overtimePay + $allowances)));
            $totalDeductions = max(0.0, (float)($_POST["deductions"] ?? ($sssDeduction + $philhealthDeduction + $pagibigDeduction + $taxDeduction + $otherDeductions)));
            $netPay          = max(0.0, $grossPay - $totalDeductions);

            $upQuery = "UPDATE payroll_records SET
                reference_no = ?, pay_period_start = ?, pay_period_end = ?, salary_type = ?,
                base_salary = ?, hours_worked = ?, days_worked = ?, hourly_rate = ?,
                overtime_hours = ?, overtime_rate = ?, overtime_pay = ?, allowances = ?,
                gross_pay = ?, sss_deduction = ?, philhealth_deduction = ?, pagibig_deduction = ?,
                tax_deduction = ?, other_deductions = ?, deductions = ?, net_pay = ?,
                payment_date = ?, payment_method = ?, status = ?, notes = ?, processed_by = ?
                WHERE payroll_id = ?";

            $upStmt = $conn->prepare($upQuery);
            $upStmt->bind_param(
                "ssssddddddddddddddddssssii",
                $referenceNo,
                $payPeriodStart,
                $payPeriodEnd,
                $salaryType,
                $baseSalary,
                $hoursWorked,
                $daysWorked,
                $hourlyRate,
                $overtimeHours,
                $overtimeRate,
                $overtimePay,
                $allowances,
                $grossPay,
                $sssDeduction,
                $philhealthDeduction,
                $pagibigDeduction,
                $taxDeduction,
                $otherDeductions,
                $totalDeductions,
                $netPay,
                $paymentDate,
                $paymentMethod,
                $status,
                $notes,
                $currentUserId,
                $payrollId
            );

            if ($upStmt->execute()) {
                $success = "Payroll record #$payrollId ($referenceNo) was updated successfully.";
                log_activity($conn, $currentUserId, "SALARY_UPDATED", "Updated salary record #$payrollId ($referenceNo, Net: ₱" . number_format($netPay, 2) . ")");
            } else {
                $error = "Error updating payroll record: " . $conn->error;
            }
        }
    }

    // 3. MARK AS PAID
    elseif ($action === "mark_paid") {
        $payrollId = (int)($_POST["payroll_id"] ?? 0);
        $payDate   = date("Y-m-d");

        $markStmt = $conn->prepare("UPDATE payroll_records SET status = 'paid', payment_date = ?, processed_by = ? WHERE payroll_id = ?");
        $markStmt->bind_param("sii", $payDate, $currentUserId, $payrollId);
        if ($markStmt->execute()) {
            $success = "Salary record #$payrollId marked as Paid.";
            log_activity($conn, $currentUserId, "SALARY_PAID", "Marked salary record #$payrollId as paid");
        } else {
            $error = "Error updating status: " . $conn->error;
        }
    }

    // 4. DELETE / VOID SALARY RECORD
    elseif ($action === "delete_salary") {
        $payrollId = (int)($_POST["payroll_id"] ?? 0);
        if ($payrollId <= 0) {
            $error = "Invalid payroll record ID.";
        } else {
            $delStmt = $conn->prepare("DELETE FROM payroll_records WHERE payroll_id = ?");
            $delStmt->bind_param("i", $payrollId);
            if ($delStmt->execute()) {
                if ($delStmt->affected_rows > 0) {
                    $success = "Salary record #$payrollId was voided and removed.";
                    log_activity($conn, $currentUserId, "SALARY_DELETED", "Deleted payroll record #$payrollId");
                } else {
                    $error = "Salary record #$payrollId was not found or has already been removed.";
                }
            } else {
                $error = "Error deleting payroll record: " . $conn->error;
            }
            $delStmt->close();
        }
    }

    // 5. UPDATE EMPLOYEE COMPENSATION RATES
    elseif ($action === "update_employee_rate") {
        $targetUserId  = (int)($_POST["user_id"] ?? 0);
        $positionTitle = trim($_POST["position_title"] ?? "");
        $salaryType    = $_POST["salary_type"] ?? "hourly";
        $hourlyRate    = max(0.0, (float)($_POST["hourly_rate"] ?? 0));
        $dailyRate     = max(0.0, (float)($_POST["daily_rate"] ?? 0));
        $monthlyRate   = max(0.0, (float)($_POST["monthly_rate"] ?? 0));

        $uStmt = $conn->prepare("UPDATE users SET position_title = ?, salary_type = ?, hourly_rate = ?, daily_rate = ?, monthly_rate = ? WHERE user_id = ?");
        $uStmt->bind_param("ssdddi", $positionTitle, $salaryType, $hourlyRate, $dailyRate, $monthlyRate, $targetUserId);
        if ($uStmt->execute()) {
            $success = "Compensation rates updated for user #$targetUserId.";
            log_activity($conn, $currentUserId, "SALARY_RATE_UPDATED", "Updated salary rate for user #$targetUserId ($positionTitle, $salaryType)");
        } else {
            $error = "Error updating rates: " . $conn->error;
        }
    }
}

// -------------------------------------------------------------------------
// FETCH ALL ACTIVE EMPLOYEES FOR DROPDOWNS & RATES DIRECTORY
// -------------------------------------------------------------------------
$employeesQuery = "
    SELECT u.user_id, u.username, u.full_name, u.role_id, u.status,
           u.position_title, u.contact_number, u.salary_type,
           u.hourly_rate, u.daily_rate, u.monthly_rate,
           r.role_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE u.status = 'active'
    ORDER BY u.role_id ASC, u.full_name ASC
";
$employeesRes = $conn->query($employeesQuery);
$activeEmployees = [];
$employeesById = [];
while ($emp = $employeesRes->fetch_assoc()) {
    $activeEmployees[] = $emp;
    $employeesById[$emp["user_id"]] = $emp;
}

// -------------------------------------------------------------------------
// FILTER PARAMETERS
// -------------------------------------------------------------------------
$periodFilter = $_GET["period"] ?? "this_month";
$employeeFilter = (int)($_GET["employee_id"] ?? 0);
$statusFilter = $_GET["status"] ?? "all";
$methodFilter = $_GET["payment_method"] ?? "all";
$tab = $_GET["tab"] ?? "disbursements";
$autoOpenUser = (int)($_GET["give_to"] ?? 0);

// Date ranges
$today = date("Y-m-d");
$startDate = "";
$endDate = "";

switch ($periodFilter) {
    case "this_month":
        $startDate = date("Y-m-01");
        $endDate   = date("Y-m-t");
        break;
    case "last_month":
        $startDate = date("Y-m-01", strtotime("first day of last month"));
        $endDate   = date("Y-m-t", strtotime("last day of last month"));
        break;
    case "last_30_days":
        $startDate = date("Y-m-d", strtotime("-30 days"));
        $endDate   = $today;
        break;
    case "this_year":
        $startDate = date("Y-01-01");
        $endDate   = date("Y-12-31");
        break;
    case "custom":
        $startDate = !empty($_GET["start_date"]) ? $_GET["start_date"] : date("Y-m-01");
        $endDate   = !empty($_GET["end_date"]) ? $_GET["end_date"] : $today;
        break;
    case "all":
    default:
        $startDate = "2020-01-01";
        $endDate   = "2099-12-31";
        break;
}

// -------------------------------------------------------------------------
// QUERY PAYROLL RECORDS WITH FILTERS
// -------------------------------------------------------------------------
$whereClauses = ["1=1"];

if ($periodFilter !== "all") {
    $safeStart = $conn->real_escape_string($startDate);
    $safeEnd   = $conn->real_escape_string($endDate);
    $whereClauses[] = "(p.payment_date >= '$safeStart' AND p.payment_date <= '$safeEnd')";
}

if ($employeeFilter > 0) {
    $whereClauses[] = "p.user_id = $employeeFilter";
}

if ($statusFilter !== "all" && in_array($statusFilter, ["paid", "pending"], true)) {
    $whereClauses[] = "p.status = '$statusFilter'";
}

if ($methodFilter !== "all" && in_array($methodFilter, ["cash", "gcash", "maya", "bank_transfer", "check"], true)) {
    $whereClauses[] = "p.payment_method = '$methodFilter'";
}

$whereSql = implode(" AND ", $whereClauses);

$payrollQuery = "
    SELECT p.*,
           u.full_name as employee_name, u.username as employee_username,
           u.position_title as employee_position, r.role_name as employee_role,
           m.full_name as manager_name, m.username as manager_username
    FROM payroll_records p
    LEFT JOIN users u ON p.user_id = u.user_id
    LEFT JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN users m ON p.processed_by = m.user_id
    WHERE $whereSql
    ORDER BY p.payment_date DESC, p.payroll_id DESC
";

$payrollRes = $conn->query($payrollQuery);
$payrollList = [];

$totalGrossDisbursed = 0.0;
$totalDeductionsDisbursed = 0.0;
$totalNetDisbursed = 0.0;
$pendingAmount = 0.0;
$pendingCount = 0;
$distinctEmployees = [];

if ($payrollRes) {
    while ($row = $payrollRes->fetch_assoc()) {
        $payrollList[] = $row;
        if ($row["status"] === "paid") {
            $totalGrossDisbursed      += (float)$row["gross_pay"];
            $totalDeductionsDisbursed += (float)$row["deductions"];
            $totalNetDisbursed        += (float)$row["net_pay"];
            if (!empty($row["user_id"])) {
                $distinctEmployees[$row["user_id"]] = true;
            }
        } else {
            $pendingAmount += (float)$row["net_pay"];
            $pendingCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll & Salaries | Capuzan Kusina</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=<?= time() ?>">
    <style>
        .payroll-tabs {
            display: flex;
            gap: 12px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 24px;
        }

        .payroll-tab-btn {
            background: none;
            border: none;
            padding: 12px 20px;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--gray-600);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .payroll-tab-btn:hover {
            color: var(--primary);
        }

        .payroll-tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .filter-panel {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
            box-shadow: var(--shadow-sm);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-group label {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--gray-600);
        }

        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .method-cash { background: #EBF0DE; color: #51632B; border: 1px solid #C4D2B2; }
        .method-gcash { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
        .method-maya { background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; }
        .method-bank_transfer { background: #EDE9FE; color: #6D28D9; border: 1px solid #DDD6FE; }
        .method-check { background: #F3EAE0; color: #6B574C; border: 1px solid #E6D9C8; }

        .calc-preview-box {
            background: #FFFDF8;
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-top: 16px;
        }

        .calc-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            font-size: 0.92rem;
            border-bottom: 1px dashed var(--border-color);
        }

        .calc-summary-row.total-row {
            border-bottom: none;
            border-top: 2px solid var(--primary);
            margin-top: 8px;
            padding-top: 10px;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--deep-brown);
        }

        .preset-pill-btn {
            background: #F3EAE0;
            border: 1px solid var(--border-color);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .preset-pill-btn:hover {
            background: var(--primary);
            color: #FFFDF8;
            border-color: var(--primary);
        }

        /* Printable Payslip Styling */
        .payslip-container {
            background: #FFFFFF;
            color: #2D231C;
            max-width: 680px;
            margin: 0 auto;
            padding: 32px 36px;
            border: 2px solid #E6D9C8;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(51, 38, 31, 0.12);
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .payslip-header {
            text-align: center;
            border-bottom: 2px solid #4A291C;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }

        .payslip-brand-badge {
            display: inline-block;
            background: #C96B2C;
            color: #FFFDF8;
            font-weight: 900;
            font-size: 1.1rem;
            padding: 4px 14px;
            border-radius: 8px;
            margin-bottom: 6px;
            letter-spacing: 0.05em;
        }

        .payslip-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 20px;
        }

        .payslip-meta-table, .payslip-breakdown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }

        .payslip-meta-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #F3EAE0;
        }

        .payslip-breakdown-table th {
            background: #F7F0E3;
            color: #4A291C;
            font-weight: 700;
            padding: 8px;
            text-align: left;
            border-bottom: 1.5px solid #E6D9C8;
        }

        .payslip-breakdown-table td {
            padding: 7px 8px;
            border-bottom: 1px solid #F3EAE0;
        }

        .payslip-net-box {
            background: #FEF8E8;
            border: 2px solid #D9A441;
            border-radius: 10px;
            padding: 16px 20px;
            text-align: center;
            margin: 20px 0;
        }

        .payslip-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            margin-top: 36px;
            padding-top: 16px;
        }

        .sig-line {
            border-top: 1.5px solid #4A291C;
            text-align: center;
            padding-top: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #6B574C;
        }

        /* Print Media Styles */
        @media print {
            body * {
                visibility: hidden;
            }
            #printablePayslipModal, #printablePayslipModal * {
                visibility: visible;
            }
            #printablePayslipModal {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .modal-card {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .modal-header, .modal-footer, .no-print {
                display: none !important;
            }
            .payslip-container {
                border: 1px solid #33261F !important;
                box-shadow: none !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 20px !important;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/../includes/navbar.php"; ?>

    <main class="main-wrapper">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Payroll & Salary Disbursement</h1>
                <p class="page-subtitle">Logged in as <strong><?= htmlspecialchars($currentUserName) ?></strong> (<?= ucfirst($currentUserRole) ?>) &bull; Compute hours, apply statutory deductions, and disburse staff salaries.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button class="btn btn-primary" onclick="openGiveSalaryModal()">
                    <span style="font-size:1.15rem; line-height:1;">💵</span> Give Salary / Issue Pay
                </button>
                <button class="btn btn-secondary" onclick="window.print()">
                    🖨️ Print Report
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

        <!-- Stats Overview Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Net Salaries Disbursed</span>
                <span class="stat-val" style="color:var(--success);">₱<?= number_format($totalNetDisbursed, 2) ?></span>
                <span class="stat-sub">Actual take-home pay released</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Total Gross Payroll</span>
                <span class="stat-val">₱<?= number_format($totalGrossDisbursed, 2) ?></span>
                <span class="stat-sub">Basic pay + Overtime + Allowances</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Total Deductions</span>
                <span class="stat-val" style="color:var(--warning);">₱<?= number_format($totalDeductionsDisbursed, 2) ?></span>
                <span class="stat-sub">SSS, PhilHealth, Pag-IBIG, Tax</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Employees Paid</span>
                <span class="stat-val"><?= count($distinctEmployees) ?></span>
                <span class="stat-sub">Out of <?= count($activeEmployees) ?> active employees</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Pending Disbursements</span>
                <span class="stat-val" style="color:<?= $pendingCount > 0 ? 'var(--danger)' : 'var(--gray-600)' ?>;">
                    <?= $pendingCount ?> <small style="font-size:0.85rem; font-weight:normal;">(₱<?= number_format($pendingAmount, 2) ?>)</small>
                </span>
                <span class="stat-sub"><?= $pendingCount > 0 ? 'Requires manager payout' : 'All payroll vouchers cleared' ?></span>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="payroll-tabs">
            <a href="?tab=disbursements" class="payroll-tab-btn <?= $tab === 'disbursements' ? 'active' : '' ?>">
                📋 Salary Disbursements & Records (<?= count($payrollList) ?>)
            </a>
            <a href="?tab=rates" class="payroll-tab-btn <?= $tab === 'rates' ? 'active' : '' ?>">
                ⚙️ Employee Salary Rates Directory (<?= count($activeEmployees) ?>)
            </a>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 1: SALARY DISBURSEMENTS & HISTORY -->
        <!-- ------------------------------------------------------------- -->
        <?php if ($tab === 'disbursements'): ?>
            <!-- Filter Toolbar -->
            <form method="GET" action="index.php" class="filter-panel" id="filterForm">
                <input type="hidden" name="tab" value="disbursements">

                <div class="filter-group">
                    <label for="filter_period">Period</label>
                    <select id="filter_period" name="period" class="form-control" onchange="toggleCustomDates(this.value)">
                        <option value="this_month" <?= $periodFilter === 'this_month' ? 'selected' : '' ?>>This Month (<?= date('M Y') ?>)</option>
                        <option value="last_month" <?= $periodFilter === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                        <option value="last_30_days" <?= $periodFilter === 'last_30_days' ? 'selected' : '' ?>>Last 30 Days</option>
                        <option value="this_year" <?= $periodFilter === 'this_year' ? 'selected' : '' ?>>This Year (<?= date('Y') ?>)</option>
                        <option value="all" <?= $periodFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                        <option value="custom" <?= $periodFilter === 'custom' ? 'selected' : '' ?>>Custom Range...</option>
                    </select>
                </div>

                <div class="filter-group custom-date-grp" style="display:<?= $periodFilter === 'custom' ? 'flex' : 'none' ?>;">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-control">
                </div>

                <div class="filter-group custom-date-grp" style="display:<?= $periodFilter === 'custom' ? 'flex' : 'none' ?>;">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-control">
                </div>

                <div class="filter-group">
                    <label for="filter_emp">Employee</label>
                    <select id="filter_emp" name="employee_id" class="form-control" style="min-width:180px;">
                        <option value="0">All Employees</option>
                        <?php foreach ($activeEmployees as $emp): ?>
                            <option value="<?= $emp["user_id"] ?>" <?= $employeeFilter === (int)$emp["user_id"] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp["full_name"]) ?> (<?= ucfirst($emp["role_name"]) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_status">Status</label>
                    <select id="filter_status" name="status" class="form-control">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_method">Method</label>
                    <select id="filter_method" name="payment_method" class="form-control">
                        <option value="all" <?= $methodFilter === 'all' ? 'selected' : '' ?>>All Methods</option>
                        <option value="cash" <?= $methodFilter === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="gcash" <?= $methodFilter === 'gcash' ? 'selected' : '' ?>>GCash</option>
                        <option value="maya" <?= $methodFilter === 'maya' ? 'selected' : '' ?>>Maya</option>
                        <option value="bank_transfer" <?= $methodFilter === 'bank_transfer' ? 'selected' : '' ?>>Bank</option>
                        <option value="check" <?= $methodFilter === 'check' ? 'selected' : '' ?>>Check</option>
                    </select>
                </div>

                <div class="filter-group">
                    <button type="submit" class="btn btn-primary" style="padding:8px 16px;">Filter</button>
                </div>

                <div class="filter-group" style="margin-left:auto;">
                    <label>Live Search</label>
                    <input type="text" id="payrollSearch" placeholder="Search employee, ref, method..." class="form-control" style="width:230px;" onkeyup="filterPayrollTable()">
                </div>
            </form>

            <!-- Payroll Table -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Salary Disbursement Vouchers</span>
                    <small style="color:var(--gray-600);">Showing <?= count($payrollList) ?> transaction(s)</small>
                </div>
                <div class="table-responsive">
                    <table class="data-table" id="payrollTable">
                        <thead>
                            <tr>
                                <th>Ref & Date</th>
                                <th>Employee & Position</th>
                                <th>Pay Period</th>
                                <th>Earnings Breakdown</th>
                                <th>Gross Pay</th>
                                <th>Deductions</th>
                                <th>Net Salary</th>
                                <th>Payment</th>
                                <th>Given / Approved By</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payrollList)): ?>
                                <tr>
                                    <td colspan="11" style="text-align:center; padding:36px; color:var(--gray-600);">
                                        <div style="font-size:1.8rem; margin-bottom:8px;">💳</div>
                                        <strong>No salary disbursement records found for this criteria.</strong>
                                        <div style="margin-top:8px;">
                                            <button class="btn btn-primary btn-sm" onclick="openGiveSalaryModal()">Issue First Salary Now</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payrollList as $p): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($p["reference_no"] ?: 'PAY-' . $p["payroll_id"]) ?></strong>
                                            <div style="font-size:0.78rem; color:var(--gray-600);"><?= date("M d, Y", strtotime($p["payment_date"])) ?></div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($p["employee_name"] ?: ($p["employee_username"] ?? 'Deleted User')) ?></strong>
                                            <div style="font-size:0.8rem; color:var(--gray-600);">
                                                <?= htmlspecialchars($p["employee_position"] ?: ucfirst($p["employee_role"] ?? 'Staff')) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-size:0.85rem; white-space:nowrap;">
                                                <?= date("M d", strtotime($p["pay_period_start"])) ?> - <?= date("M d, Y", strtotime($p["pay_period_end"])) ?>
                                            </span>
                                            <div style="font-size:0.75rem; text-transform:uppercase; color:var(--gray-500); font-weight:700;">
                                                <?= htmlspecialchars($p["salary_type"]) ?>
                                            </div>
                                        </td>
                                        <td style="font-size:0.85rem;">
                                            <?php if ($p["salary_type"] === "hourly" && (float)$p["hours_worked"] > 0): ?>
                                                <div><?= number_format($p["hours_worked"], 1) ?> hrs @ ₱<?= number_format($p["hourly_rate"], 2) ?></div>
                                            <?php elseif ($p["salary_type"] === "daily" && (float)$p["days_worked"] > 0): ?>
                                                <div><?= number_format($p["days_worked"], 1) ?> days @ ₱<?= number_format($p["base_salary"] / max(1, $p["days_worked"]), 2) ?></div>
                                            <?php else: ?>
                                                <div>Base: ₱<?= number_format($p["base_salary"], 2) ?></div>
                                            <?php endif; ?>

                                            <?php if ((float)$p["overtime_hours"] > 0): ?>
                                                <div style="color:var(--primary); font-size:0.78rem;">
                                                    +<?= number_format($p["overtime_hours"], 1) ?>h OT (₱<?= number_format($p["overtime_pay"], 2) ?>)
                                                </div>
                                            <?php endif; ?>
                                            <?php if ((float)$p["allowances"] > 0): ?>
                                                <div style="color:var(--success); font-size:0.78rem;">
                                                    +Allowance: ₱<?= number_format($p["allowances"], 2) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong>₱<?= number_format($p["gross_pay"], 2) ?></strong>
                                        </td>
                                        <td>
                                            <span style="color:var(--danger); font-weight:600;">
                                                -₱<?= number_format($p["deductions"], 2) ?>
                                            </span>
                                            <?php if ((float)$p["deductions"] > 0): ?>
                                                <div style="font-size:0.72rem; color:var(--gray-500);">
                                                    SSS: <?= number_format($p["sss_deduction"], 0) ?> | PH: <?= number_format($p["philhealth_deduction"], 0) ?> | PI: <?= number_format($p["pagibig_deduction"], 0) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="font-size:1.05rem; color:var(--success);">
                                                ₱<?= number_format($p["net_pay"], 2) ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <span class="method-badge method-<?= htmlspecialchars($p["payment_method"]) ?>">
                                                <?= strtoupper(str_replace('_', ' ', $p["payment_method"])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($p["manager_name"])): ?>
                                                <strong><?= htmlspecialchars($p["manager_name"]) ?></strong>
                                                <div style="font-size:0.75rem; color:var(--gray-500);">Manager</div>
                                            <?php else: ?>
                                                <span style="color:var(--gray-500); font-size:0.8rem;">System / Admin</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($p["status"] === "paid"): ?>
                                                <span class="badge badge-success">PAID</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">PENDING</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <button class="btn btn-sm btn-secondary" onclick='openPayslipModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="View & Print Payslip">
                                                🖨️ Payslip
                                            </button>

                                            <?php if ($p["status"] === "pending"): ?>
                                                <form method="POST" action="index.php" style="display:inline;">
                                                    <input type="hidden" name="action" value="mark_paid">
                                                    <input type="hidden" name="payroll_id" value="<?= (int)$p["payroll_id"] ?>">
                                                    <button type="submit" class="btn btn-sm btn-success" title="Mark Disbursed">
                                                        ✓ Pay
                                                    </button>
                                                </form>

                                                <button class="btn btn-sm btn-secondary" onclick='openEditSalaryModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit Voucher">
                                                    Edit
                                                </button>
                                            <?php endif; ?>

                                            <form method="POST" action="index.php" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_salary">
                                                <input type="hidden" name="payroll_id" value="<?= (int)$p["payroll_id"] ?>">
                                                <button type="button" 
                                                        class="btn btn-sm btn-danger" 
                                                        title="Void / Delete Voucher"
                                                        data-payroll-id="<?= (int)$p["payroll_id"] ?>"
                                                        data-ref="<?= htmlspecialchars($p['reference_no'] ?: ('PAY-' . $p['payroll_id']), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-net="<?= number_format((float)($p['net_pay'] ?? 0), 2) ?>"
                                                        data-emp="<?= htmlspecialchars($p['employee_name'] ?: ($p['employee_username'] ?? 'Deleted User'), ENT_QUOTES, 'UTF-8') ?>"
                                                        onclick="confirmDeletePayroll(this)">
                                                    🗑️
                                                </button>
                                            </form>
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
        <!-- TAB 2: EMPLOYEE SALARY RATES DIRECTORY -->
        <!-- ------------------------------------------------------------- -->
        <?php if ($tab === 'rates'): ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <span class="card-title">Employee Compensation Directory</span>
                        <p style="font-size:0.85rem; color:var(--gray-600); margin-top:2px;">
                            Configure standard pay rates and job titles so giving salary automatically pre-fills rates.
                        </p>
                    </div>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="openGiveSalaryModal()">
                            + Give Salary
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Role</th>
                                <th>Position / Title</th>
                                <th>Pay Basis</th>
                                <th>Hourly Rate</th>
                                <th>Daily Rate</th>
                                <th>Monthly Fixed</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeEmployees as $emp): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($emp["full_name"]) ?></strong>
                                        <div style="font-size:0.8rem; color:var(--gray-600);"><code><?= htmlspecialchars($emp["username"]) ?></code></div>
                                    </td>
                                    <td>
                                        <span class="role-pill role-<?= htmlspecialchars($emp["role_name"]) ?>">
                                            <?= htmlspecialchars($emp["role_name"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($emp["position_title"] ?: "Unassigned Position") ?></strong>
                                    </td>
                                    <td>
                                        <span style="font-weight:700; text-transform:uppercase; font-size:0.8rem; color:var(--primary);">
                                            <?= htmlspecialchars($emp["salary_type"] ?: 'hourly') ?>
                                        </span>
                                    </td>
                                    <td>
                                        ₱<?= number_format($emp["hourly_rate"], 2) ?>/hr
                                    </td>
                                    <td>
                                        ₱<?= number_format($emp["daily_rate"], 2) ?>/day
                                    </td>
                                    <td>
                                        ₱<?= number_format($emp["monthly_rate"], 2) ?>/mo
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <button class="btn btn-sm btn-primary" onclick="openGiveSalaryForUser(<?= $emp['user_id'] ?>)">
                                            💵 Give Salary
                                        </button>
                                        <button class="btn btn-sm btn-secondary" onclick='openEditRateModal(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            ⚙️ Set Rates
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- ============================================================= -->
    <!-- MODAL 1: GIVE SALARY / ISSUE PAYROLL (MANAGER) -->
    <!-- ============================================================= -->
    <div class="modal-overlay" id="giveSalaryModal">
        <div class="modal-card" style="max-width:760px; max-height:92vh; overflow-y:auto;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">💵 Issue Salary Disbursement</h3>
                    <p style="font-size:0.85rem; color:var(--gray-600); margin:2px 0 0 0;">
                        Logged in Manager: <strong><?= htmlspecialchars($currentUserName) ?></strong> &bull; Complete earnings and statutory deductions.
                    </p>
                </div>
                <button class="modal-close" onclick="closeModal('giveSalaryModal')">&times;</button>
            </div>
            <form method="POST" action="index.php" id="giveSalaryForm">
                <input type="hidden" name="action" value="give_salary">

                <div class="modal-body" style="display:flex; flex-direction:column; gap:16px;">
                    <!-- Section 1: Employee & Period -->
                    <div style="background:#F7F0E3; padding:14px 16px; border-radius:10px; border:1px solid #E6D9C8;">
                        <h4 style="font-size:0.9rem; font-weight:800; color:var(--deep-brown); margin-bottom:10px; text-transform:uppercase;">
                            1. Employee & Cutoff Period
                        </h4>

                        <div class="form-row">
                            <div class="form-group" style="flex:2;">
                                <label for="give_user_id">Select Employee *</label>
                                <select id="give_user_id" name="user_id" class="form-control" required onchange="onEmployeeSelected(this.value)">
                                    <option value="">-- Choose Employee --</option>
                                    <?php foreach ($activeEmployees as $emp): ?>
                                        <option value="<?= $emp["user_id"] ?>">
                                            <?= htmlspecialchars($emp["full_name"]) ?> &bull; <?= htmlspecialchars($emp["position_title"] ?: ucfirst($emp["role_name"])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label for="give_salary_type">Salary Basis</label>
                                <select id="give_salary_type" name="salary_type" class="form-control" onchange="onSalaryTypeChanged()">
                                    <option value="hourly">Hourly Rate</option>
                                    <option value="daily">Daily Rate</option>
                                    <option value="monthly">Monthly Fixed</option>
                                </select>
                            </div>
                        </div>

                        <!-- Quick Cutoff Presets -->
                        <div style="margin-top:10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span style="font-size:0.75rem; font-weight:700; color:var(--gray-600); text-transform:uppercase;">Quick Cutoff:</span>
                            <button type="button" class="preset-pill-btn" onclick="setCutoffPreset('1st_half')">1st Half (1-15)</button>
                            <button type="button" class="preset-pill-btn" onclick="setCutoffPreset('2nd_half')">2nd Half (16-End)</button>
                            <button type="button" class="preset-pill-btn" onclick="setCutoffPreset('full_month')">Full Month</button>
                            <button type="button" class="preset-pill-btn" onclick="setCutoffPreset('last_7_days')">Last 7 Days</button>
                        </div>

                        <div class="form-row" style="margin-top:10px;">
                            <div class="form-group">
                                <label for="give_start_date">Cutoff Start Date *</label>
                                <input type="date" id="give_start_date" name="pay_period_start" class="form-control" required value="<?= date('Y-m-01') ?>">
                            </div>
                            <div class="form-group">
                                <label for="give_end_date">Cutoff End Date *</label>
                                <input type="date" id="give_end_date" name="pay_period_end" class="form-control" required value="<?= date('Y-m-15') ?>">
                            </div>
                            <div class="form-group">
                                <label for="give_payment_date">Payment Date *</label>
                                <input type="date" id="give_payment_date" name="payment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Earnings & Overtime -->
                    <div style="background:#FFFDF8; padding:14px 16px; border-radius:10px; border:1px solid #E6D9C8;">
                        <h4 style="font-size:0.9rem; font-weight:800; color:var(--deep-brown); margin-bottom:10px; text-transform:uppercase;">
                            2. Earnings & Overtime Calculation
                        </h4>

                        <div class="form-row">
                            <div class="form-group" id="grp_hourly_rate">
                                <label for="give_hourly_rate">Hourly Rate (₱)</label>
                                <input type="number" step="0.01" id="give_hourly_rate" name="hourly_rate" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group" id="grp_hours_worked">
                                <label for="give_hours_worked">Hours Worked (hrs)</label>
                                <input type="number" step="0.5" id="give_hours_worked" name="hours_worked" class="form-control" value="80.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group" id="grp_days_worked" style="display:none;">
                                <label for="give_days_worked">Days Worked (days)</label>
                                <input type="number" step="0.5" id="give_days_worked" name="days_worked" class="form-control" value="10.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group">
                                <label for="give_base_salary">Base Pay (₱)</label>
                                <input type="number" step="0.01" id="give_base_salary" name="base_salary" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                        </div>

                        <div class="form-row" style="margin-top:8px;">
                            <div class="form-group">
                                <label for="give_ot_hours">Overtime Hours (hrs)</label>
                                <input type="number" step="0.5" id="give_ot_hours" name="overtime_hours" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group">
                                <label for="give_ot_rate">OT Rate / hr (1.25x) (₱)</label>
                                <input type="number" step="0.01" id="give_ot_rate" name="overtime_rate" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group">
                                <label for="give_ot_pay">OT Total Pay (₱)</label>
                                <input type="number" step="0.01" id="give_ot_pay" name="overtime_pay" class="form-control" value="0.00" readonly style="background:#f1f5f9;">
                            </div>
                            <div class="form-group">
                                <label for="give_allowances">Allowances & Tips (₱)</label>
                                <input type="number" step="0.01" id="give_allowances" name="allowances" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                        </div>

                        <div style="text-align:right; margin-top:8px; font-weight:700; color:var(--deep-brown);">
                            Total Gross Pay: <span id="display_gross_pay" style="color:var(--primary); font-size:1.15rem;">₱0.00</span>
                            <input type="hidden" id="give_gross_pay" name="gross_pay" value="0.00">
                        </div>
                    </div>

                    <!-- Section 3: Statutory & Company Deductions -->
                    <div style="background:#FFFDF8; padding:14px 16px; border-radius:10px; border:1px solid #E6D9C8;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <h4 style="font-size:0.9rem; font-weight:800; color:var(--deep-brown); text-transform:uppercase; margin:0;">
                                3. Deductions Breakdown
                            </h4>
                            <button type="button" class="preset-pill-btn" onclick="autoCalculateDeductions()">
                                ⚡ Auto-fill Standard SSS / PH / PI
                            </button>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="give_sss">SSS Contribution (₱)</label>
                                <input type="number" step="0.01" id="give_sss" name="sss_deduction" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group">
                                <label for="give_ph">PhilHealth (₱)</label>
                                <input type="number" step="0.01" id="give_ph" name="philhealth_deduction" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group">
                                <label for="give_pi">Pag-IBIG (₱)</label>
                                <input type="number" step="0.01" id="give_pi" name="pagibig_deduction" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group">
                                <label for="give_tax">Withholding Tax (₱)</label>
                                <input type="number" step="0.01" id="give_tax" name="tax_deduction" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                            <div class="form-group">
                                <label for="give_other_ded">Vale / Cash Adv / Other (₱)</label>
                                <input type="number" step="0.01" id="give_other_ded" name="other_deductions" class="form-control" value="0.00" oninput="calculatePayroll()">
                            </div>
                        </div>

                        <div style="text-align:right; margin-top:8px; font-weight:700; color:var(--danger);">
                            Total Deductions: <span id="display_deductions" style="font-size:1.15rem;">-₱0.00</span>
                            <input type="hidden" id="give_deductions" name="deductions" value="0.00">
                        </div>
                    </div>

                    <!-- Section 4: Disbursement & Take-Home Pay -->
                    <div class="calc-preview-box">
                        <div class="calc-summary-row">
                            <span>Computed Gross Earnings:</span>
                            <strong id="summary_gross">₱0.00</strong>
                        </div>
                        <div class="calc-summary-row">
                            <span>Total Statutory & Vale Deductions:</span>
                            <strong id="summary_deductions" style="color:var(--danger);">-₱0.00</strong>
                        </div>
                        <div class="calc-summary-row total-row">
                            <span>NET SALARY TAKE-HOME PAY:</span>
                            <span id="summary_net" style="color:var(--success); font-size:1.4rem;">₱0.00</span>
                            <input type="hidden" id="give_net_pay" name="net_pay" value="0.00">
                        </div>

                        <div class="form-row" style="margin-top:16px;">
                            <div class="form-group">
                                <label for="give_payment_method">Payment Disbursement Method *</label>
                                <select id="give_payment_method" name="payment_method" class="form-control" required>
                                    <option value="cash">💵 Cash (Physical Handout)</option>
                                    <option value="gcash">📱 GCash E-Wallet</option>
                                    <option value="maya">💳 Maya E-Wallet</option>
                                    <option value="bank_transfer">🏦 Bank Direct Deposit</option>
                                    <option value="check">📜 Bank Check</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="give_status">Voucher Status *</label>
                                <select id="give_status" name="status" class="form-control" required>
                                    <option value="paid">PAID (Disbursed by Manager Now)</option>
                                    <option value="pending">PENDING (Queued for Disbursal)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="give_reference_no">Reference / Slip # (Optional)</label>
                                <input type="text" id="give_reference_no" name="reference_no" class="form-control" placeholder="Auto-generated if empty">
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:10px;">
                            <label for="give_notes">Notes / Special Remarks</label>
                            <input type="text" id="give_notes" name="notes" class="form-control" placeholder="e.g. 1st cutoff salary, includes perfect attendance bonus">
                        </div>

                        <div style="margin-top:12px;">
                            <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer;">
                                <input type="checkbox" name="save_as_default_rate" value="1">
                                <span>Save entered hourly/daily rates as default compensation for this employee</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('giveSalaryModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="font-weight:800;">
                        ✓ Disburse Salary Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL 2: EDIT SALARY RECORD -->
    <!-- ============================================================= -->
    <div class="modal-overlay" id="editSalaryModal">
        <div class="modal-card" style="max-width:720px; max-height:92vh; overflow-y:auto;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Edit Salary Voucher</h3>
                    <p style="font-size:0.85rem; color:var(--gray-600); margin:0;" id="editModalSub">Modify hours, deductions, or payment status.</p>
                </div>
                <button class="modal-close" onclick="closeModal('editSalaryModal')">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="edit_salary">
                <input type="hidden" id="edit_payroll_id" name="payroll_id" value="">

                <div class="modal-body" style="display:flex; flex-direction:column; gap:14px;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Employee</label>
                            <input type="text" id="edit_employee_name" class="form-control" disabled style="background:#f1f5f9;">
                        </div>
                        <div class="form-group">
                            <label for="edit_ref_no">Reference Slip #</label>
                            <input type="text" id="edit_ref_no" name="reference_no" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_status">Status</label>
                            <select id="edit_status" name="status" class="form-control">
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_period_start">Period Start</label>
                            <input type="date" id="edit_period_start" name="pay_period_start" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_period_end">Period End</label>
                            <input type="date" id="edit_period_end" name="pay_period_end" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_payment_date">Payment Date</label>
                            <input type="date" id="edit_payment_date" name="payment_date" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_salary_type">Salary Basis</label>
                            <select id="edit_salary_type" name="salary_type" class="form-control">
                                <option value="hourly">Hourly</option>
                                <option value="daily">Daily</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_hours">Hours Worked</label>
                            <input type="number" step="0.5" id="edit_hours" name="hours_worked" class="form-control" oninput="recalcEditSalary()">
                        </div>
                        <div class="form-group">
                            <label for="edit_rate">Hourly / Base Rate (₱)</label>
                            <input type="number" step="0.01" id="edit_rate" name="hourly_rate" class="form-control" oninput="recalcEditSalary()">
                        </div>
                        <div class="form-group">
                            <label for="edit_base">Base Salary (₱)</label>
                            <input type="number" step="0.01" id="edit_base" name="base_salary" class="form-control" oninput="recalcEditSalary()">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_ot_hours">Overtime Hours</label>
                            <input type="number" step="0.5" id="edit_ot_hours" name="overtime_hours" class="form-control" oninput="recalcEditSalary()">
                        </div>
                        <div class="form-group">
                            <label for="edit_ot_pay">Overtime Pay (₱)</label>
                            <input type="number" step="0.01" id="edit_ot_pay" name="overtime_pay" class="form-control" oninput="recalcEditSalary()">
                        </div>
                        <div class="form-group">
                            <label for="edit_allowances">Allowances (₱)</label>
                            <input type="number" step="0.01" id="edit_allowances" name="allowances" class="form-control" oninput="recalcEditSalary()">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_sss">SSS (₱)</label>
                            <input type="number" step="0.01" id="edit_sss" name="sss_deduction" class="form-control" oninput="recalcEditSalary()">
                        </div>
                        <div class="form-group">
                            <label for="edit_ph">PhilHealth (₱)</label>
                            <input type="number" step="0.01" id="edit_ph" name="philhealth_deduction" class="form-control" oninput="recalcEditSalary()">
                        </div>
                        <div class="form-group">
                            <label for="edit_pi">Pag-IBIG (₱)</label>
                            <input type="number" step="0.01" id="edit_pi" name="pagibig_deduction" class="form-control" oninput="recalcEditSalary()">
                        </div>
                        <div class="form-group">
                            <label for="edit_tax">Tax (₱)</label>
                            <input type="number" step="0.01" id="edit_tax" name="tax_deduction" class="form-control" oninput="recalcEditSalary()">
                        </div>
                        <div class="form-group">
                            <label for="edit_other">Other Deductions (₱)</label>
                            <input type="number" step="0.01" id="edit_other" name="other_deductions" class="form-control" oninput="recalcEditSalary()">
                        </div>
                    </div>

                    <div style="background:#FFFDF8; border:1px solid #E6D9C8; border-radius:8px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            Gross: <strong id="edit_disp_gross">₱0.00</strong> |
                            Deductions: <strong id="edit_disp_ded" style="color:var(--danger);">-₱0.00</strong>
                        </div>
                        <div>
                            Net Salary: <strong id="edit_disp_net" style="color:var(--success); font-size:1.25rem;">₱0.00</strong>
                        </div>
                        <input type="hidden" id="edit_gross_pay" name="gross_pay">
                        <input type="hidden" id="edit_deductions" name="deductions">
                        <input type="hidden" id="edit_net_pay" name="net_pay">
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex:1;">
                            <label for="edit_method">Disbursement Method</label>
                            <select id="edit_method" name="payment_method" class="form-control">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                                <option value="maya">Maya</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="check">Check</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:2;">
                            <label for="edit_notes">Notes / Memo</label>
                            <input type="text" id="edit_notes" name="notes" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteFromEditModal()" style="display:inline-flex; align-items:center; gap:6px;">
                        🗑️ Void Voucher
                    </button>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editSalaryModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL 3: OFFICIAL PRINTABLE PAYSLIP VOUCHER -->
    <!-- ============================================================= -->
    <div class="modal-overlay" id="printablePayslipModal">
        <div class="modal-card" style="max-width:760px; max-height:92vh; overflow-y:auto; background:#FFFDF8;">
            <div class="modal-header no-print">
                <h3 class="modal-title">Employee Payslip Voucher</h3>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-primary btn-sm" onclick="window.print()">
                        🖨️ Print Payslip
                    </button>
                    <button class="modal-close" onclick="closeModal('printablePayslipModal')">&times;</button>
                </div>
            </div>

            <div class="modal-body" style="padding:16px;">
                <div class="payslip-container" id="payslipContent">
                    <div class="payslip-header">
                        <div class="payslip-brand-badge">CAPUZAN KUSINA</div>
                        <h2 style="font-size:1.35rem; font-weight:800; color:#4A291C; margin:4px 0;">OFFICIAL PAYSLIP VOUCHER</h2>
                        <p style="font-size:0.82rem; color:#6B574C; margin:0;">
                            123 Main Street, Metro Manila &bull; Tel: +63 912 345 6789
                        </p>
                    </div>

                    <!-- Meta Table -->
                    <div class="payslip-grid">
                        <table class="payslip-meta-table">
                            <tr>
                                <td style="font-weight:700; width:40%;">Employee Name:</td>
                                <td id="slip_emp_name">-</td>
                            </tr>
                            <tr>
                                <td style="font-weight:700;">Position / Role:</td>
                                <td id="slip_emp_role">-</td>
                            </tr>
                            <tr>
                                <td style="font-weight:700;">Employee ID:</td>
                                <td id="slip_emp_id">-</td>
                            </tr>
                        </table>
                        <table class="payslip-meta-table">
                            <tr>
                                <td style="font-weight:700; width:40%;">Voucher Ref #:</td>
                                <td><strong id="slip_ref_no">-</strong></td>
                            </tr>
                            <tr>
                                <td style="font-weight:700;">Cutoff Period:</td>
                                <td id="slip_period">-</td>
                            </tr>
                            <tr>
                                <td style="font-weight:700;">Payment Date:</td>
                                <td id="slip_pay_date">-</td>
                            </tr>
                        </table>
                    </div>

                    <!-- Side by Side Earnings and Deductions -->
                    <div class="payslip-grid">
                        <!-- Earnings Column -->
                        <div>
                            <table class="payslip-breakdown-table">
                                <thead>
                                    <tr>
                                        <th>Earnings</th>
                                        <th style="text-align:right;">Amount (₱)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Basic Pay (<span id="slip_base_desc">-</span>)</td>
                                        <td style="text-align:right;" id="slip_base_amt">0.00</td>
                                    </tr>
                                    <tr>
                                        <td>Overtime Pay (<span id="slip_ot_hrs">0.0</span> hrs)</td>
                                        <td style="text-align:right;" id="slip_ot_amt">0.00</td>
                                    </tr>
                                    <tr>
                                        <td>Allowances & Incentives</td>
                                        <td style="text-align:right;" id="slip_allowances">0.00</td>
                                    </tr>
                                    <tr style="font-weight:800; background:#F7F0E3;">
                                        <td>TOTAL GROSS EARNINGS</td>
                                        <td style="text-align:right; color:#4A291C;" id="slip_gross">0.00</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Deductions Column -->
                        <div>
                            <table class="payslip-breakdown-table">
                                <thead>
                                    <tr>
                                        <th>Deductions</th>
                                        <th style="text-align:right;">Amount (₱)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>SSS Contribution</td>
                                        <td style="text-align:right;" id="slip_sss">0.00</td>
                                    </tr>
                                    <tr>
                                        <td>PhilHealth Contribution</td>
                                        <td style="text-align:right;" id="slip_ph">0.00</td>
                                    </tr>
                                    <tr>
                                        <td>Pag-IBIG / HDMF</td>
                                        <td style="text-align:right;" id="slip_pi">0.00</td>
                                    </tr>
                                    <tr>
                                        <td>Withholding Tax</td>
                                        <td style="text-align:right;" id="slip_tax">0.00</td>
                                    </tr>
                                    <tr>
                                        <td>Vale / Cash Advance / Others</td>
                                        <td style="text-align:right;" id="slip_other_ded">0.00</td>
                                    </tr>
                                    <tr style="font-weight:800; background:#FCECE9;">
                                        <td>TOTAL DEDUCTIONS</td>
                                        <td style="text-align:right; color:#B33927;" id="slip_total_ded">0.00</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Net Pay Highlight Box -->
                    <div class="payslip-net-box">
                        <span style="font-size:0.85rem; font-weight:700; text-transform:uppercase; color:#6B574C; letter-spacing:0.04em;">
                            NET TAKE-HOME SALARY
                        </span>
                        <div style="font-size:2rem; font-weight:900; color:#4A291C; margin:4px 0;" id="slip_net">
                            ₱0.00
                        </div>
                        <div style="font-size:0.82rem; color:#6B574C;">
                            Disbursed via: <strong id="slip_method">-</strong> &bull; Status: <strong id="slip_status">-</strong>
                        </div>
                    </div>

                    <div style="font-size:0.8rem; color:#6B574C; margin-top:8px;" id="slip_notes_box">
                        <strong>Remarks / Memo:</strong> <span id="slip_notes">None</span>
                    </div>

                    <!-- Signature Acknowledgement Lines -->
                    <div class="payslip-signatures">
                        <div class="sig-line">
                            <span id="slip_manager_sig">Maria Santos (Manager)</span><br>
                            Prepared & Approved by (Manager / Admin)
                        </div>
                        <div class="sig-line">
                            <span id="slip_receiver_sig">Employee Signature</span><br>
                            Received by (Employee Signature & Date)
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer no-print">
                <button type="button" class="btn btn-secondary" onclick="closeModal('printablePayslipModal')">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">🖨️ Print Payslip</button>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL 4: SET EMPLOYEE RATES DIRECTORY -->
    <!-- ============================================================= -->
    <div class="modal-overlay" id="editRateModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Configure Employee Pay Rates</h3>
                <button class="modal-close" onclick="closeModal('editRateModal')">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="update_employee_rate">
                <input type="hidden" id="rate_user_id" name="user_id" value="">

                <div class="modal-body">
                    <div class="form-group">
                        <label>Employee Name</label>
                        <input type="text" id="rate_full_name" class="form-control" disabled style="background:#f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label for="rate_position">Position / Job Title</label>
                        <input type="text" id="rate_position" name="position_title" class="form-control" placeholder="e.g. Line Cook, Barista, Cashier">
                    </div>
                    <div class="form-group">
                        <label for="rate_salary_type">Salary Type</label>
                        <select id="rate_salary_type" name="salary_type" class="form-control">
                            <option value="hourly">Hourly Rate</option>
                            <option value="daily">Daily Rate</option>
                            <option value="monthly">Monthly Fixed</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="rate_hourly">Hourly Rate (₱)</label>
                            <input type="number" step="0.01" id="rate_hourly" name="hourly_rate" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="rate_daily">Daily Rate (₱)</label>
                            <input type="number" step="0.01" id="rate_daily" name="daily_rate" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="rate_monthly">Monthly Rate (₱)</label>
                            <input type="number" step="0.01" id="rate_monthly" name="monthly_rate" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editRateModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Rates</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- JAVASCRIPT LOGIC & REAL-TIME CALCULATIONS -->
    <!-- ============================================================= -->
    <script>
        // Employee database mapping for dynamic auto-fill
        const employeesMap = <?= json_encode($employeesById) ?>;

        function closeModal(modalId) {
            const m = document.getElementById(modalId);
            if (m) m.classList.remove('active');
        }

        function openGiveSalaryModal() {
            document.getElementById('giveSalaryModal').classList.add('active');
            calculatePayroll();
        }

        function openGiveSalaryForUser(userId) {
            document.getElementById('give_user_id').value = userId;
            onEmployeeSelected(userId);
            document.getElementById('giveSalaryModal').classList.add('active');
        }

        // When manager selects an employee from the dropdown
        function onEmployeeSelected(userId) {
            const emp = employeesMap[userId];
            if (!emp) return;

            const salaryType = emp.salary_type || 'hourly';
            document.getElementById('give_salary_type').value = salaryType;

            const hourly = parseFloat(emp.hourly_rate || 0);
            const daily = parseFloat(emp.daily_rate || 0);
            const monthly = parseFloat(emp.monthly_rate || 0);

            document.getElementById('give_hourly_rate').value = hourly.toFixed(2);
            document.getElementById('give_ot_rate').value = (hourly * 1.25).toFixed(2);

            if (salaryType === 'hourly') {
                document.getElementById('give_hours_worked').value = 80;
                document.getElementById('give_base_salary').value = (hourly * 80).toFixed(2);
            } else if (salaryType === 'daily') {
                document.getElementById('give_days_worked').value = 10;
                document.getElementById('give_base_salary').value = (daily * 10).toFixed(2);
            } else {
                document.getElementById('give_base_salary').value = (monthly / 2).toFixed(2); // half month cutoff
            }

            onSalaryTypeChanged();
            autoCalculateDeductions();
            calculatePayroll();
        }

        function onSalaryTypeChanged() {
            const type = document.getElementById('give_salary_type').value;
            const hrGrp = document.getElementById('grp_hourly_rate');
            const hrsWorkedGrp = document.getElementById('grp_hours_worked');
            const daysWorkedGrp = document.getElementById('grp_days_worked');

            if (type === 'hourly') {
                hrGrp.style.display = 'flex';
                hrsWorkedGrp.style.display = 'flex';
                daysWorkedGrp.style.display = 'none';
            } else if (type === 'daily') {
                hrGrp.style.display = 'none';
                hrsWorkedGrp.style.display = 'none';
                daysWorkedGrp.style.display = 'flex';
            } else { // monthly
                hrGrp.style.display = 'none';
                hrsWorkedGrp.style.display = 'none';
                daysWorkedGrp.style.display = 'none';
            }
            calculatePayroll();
        }

        // Real-time payroll math calculator
        function calculatePayroll() {
            const type = document.getElementById('give_salary_type').value;
            const hourlyRate = parseFloat(document.getElementById('give_hourly_rate').value) || 0;
            const hoursWorked = parseFloat(document.getElementById('give_hours_worked').value) || 0;
            const daysWorked = parseFloat(document.getElementById('give_days_worked').value) || 0;

            let baseSalary = parseFloat(document.getElementById('give_base_salary').value) || 0;

            if (type === 'hourly' && hourlyRate > 0 && hoursWorked > 0) {
                baseSalary = Math.round(hourlyRate * hoursWorked * 100) / 100;
                document.getElementById('give_base_salary').value = baseSalary.toFixed(2);
            }

            // Overtime
            const otHours = parseFloat(document.getElementById('give_ot_hours').value) || 0;
            let otRate = parseFloat(document.getElementById('give_ot_rate').value) || 0;
            if (otRate <= 0 && hourlyRate > 0) {
                otRate = Math.round(hourlyRate * 1.25 * 100) / 100;
                document.getElementById('give_ot_rate').value = otRate.toFixed(2);
            }
            const otPay = Math.round(otHours * otRate * 100) / 100;
            document.getElementById('give_ot_pay').value = otPay.toFixed(2);

            // Allowances
            const allowances = parseFloat(document.getElementById('give_allowances').value) || 0;

            // Gross Pay
            const grossPay = Math.round((baseSalary + otPay + allowances) * 100) / 100;
            document.getElementById('give_gross_pay').value = grossPay.toFixed(2);
            document.getElementById('display_gross_pay').innerText = '₱' + grossPay.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('summary_gross').innerText = '₱' + grossPay.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            // Deductions
            const sss = parseFloat(document.getElementById('give_sss').value) || 0;
            const ph = parseFloat(document.getElementById('give_ph').value) || 0;
            const pi = parseFloat(document.getElementById('give_pi').value) || 0;
            const tax = parseFloat(document.getElementById('give_tax').value) || 0;
            const other = parseFloat(document.getElementById('give_other_ded').value) || 0;

            const totalDeductions = Math.round((sss + ph + pi + tax + other) * 100) / 100;
            document.getElementById('give_deductions').value = totalDeductions.toFixed(2);
            document.getElementById('display_deductions').innerText = '-₱' + totalDeductions.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('summary_deductions').innerText = '-₱' + totalDeductions.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            // Net Take-Home Pay
            const netPay = Math.max(0, Math.round((grossPay - totalDeductions) * 100) / 100);
            document.getElementById('give_net_pay').value = netPay.toFixed(2);
            document.getElementById('summary_net').innerText = '₱' + netPay.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        // Auto calculate statutory contributions based on standard PH bracket approximation
        function autoCalculateDeductions() {
            const gross = parseFloat(document.getElementById('give_gross_pay').value) || 0;
            if (gross <= 0) return;

            // SSS approx 4.5% of gross
            const sss = Math.round(gross * 0.045 * 100) / 100;
            // PhilHealth approx 2.0% of gross
            const ph = Math.round(gross * 0.02 * 100) / 100;
            // Pag-IBIG flat ₱100.00
            const pi = 100.00;

            document.getElementById('give_sss').value = sss.toFixed(2);
            document.getElementById('give_ph').value = ph.toFixed(2);
            document.getElementById('give_pi').value = pi.toFixed(2);

            calculatePayroll();
        }

        // Quick Cutoff Presets
        function setCutoffPreset(preset) {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const lastDay = new Date(year, now.getMonth() + 1, 0).getDate();

            let start = '', end = '';

            if (preset === '1st_half') {
                start = `${year}-${month}-01`;
                end = `${year}-${month}-15`;
            } else if (preset === '2nd_half') {
                start = `${year}-${month}-16`;
                end = `${year}-${month}-${lastDay}`;
            } else if (preset === 'full_month') {
                start = `${year}-${month}-01`;
                end = `${year}-${month}-${lastDay}`;
            } else if (preset === 'last_7_days') {
                const prev = new Date(now.getTime() - 6 * 24 * 60 * 60 * 1000);
                start = prev.toISOString().split('T')[0];
                end = now.toISOString().split('T')[0];
            }

            document.getElementById('give_start_date').value = start;
            document.getElementById('give_end_date').value = end;
        }

        // Edit Salary Modal Population
        function openEditSalaryModal(p) {
            if (p.status === 'paid') {
                posAlert("This voucher is already marked as PAID and cannot be modified.", "Action Not Allowed", "warning");
                return;
            }
            document.getElementById('edit_payroll_id').value = p.payroll_id;
            document.getElementById('edit_employee_name').value = p.employee_name || p.employee_username;
            document.getElementById('edit_ref_no').value = p.reference_no || ('PAY-' + p.payroll_id);
            document.getElementById('edit_status').value = p.status;
            document.getElementById('edit_period_start').value = p.pay_period_start;
            document.getElementById('edit_period_end').value = p.pay_period_end;
            document.getElementById('edit_payment_date').value = p.payment_date;
            document.getElementById('edit_salary_type').value = p.salary_type || 'hourly';
            document.getElementById('edit_hours').value = parseFloat(p.hours_worked || 0).toFixed(1);
            document.getElementById('edit_rate').value = parseFloat(p.hourly_rate || 0).toFixed(2);
            document.getElementById('edit_base').value = parseFloat(p.base_salary || 0).toFixed(2);
            document.getElementById('edit_ot_hours').value = parseFloat(p.overtime_hours || 0).toFixed(1);
            document.getElementById('edit_ot_pay').value = parseFloat(p.overtime_pay || 0).toFixed(2);
            document.getElementById('edit_allowances').value = parseFloat(p.allowances || 0).toFixed(2);
            document.getElementById('edit_sss').value = parseFloat(p.sss_deduction || 0).toFixed(2);
            document.getElementById('edit_ph').value = parseFloat(p.philhealth_deduction || 0).toFixed(2);
            document.getElementById('edit_pi').value = parseFloat(p.pagibig_deduction || 0).toFixed(2);
            document.getElementById('edit_tax').value = parseFloat(p.tax_deduction || 0).toFixed(2);
            document.getElementById('edit_other').value = parseFloat(p.other_deductions || 0).toFixed(2);
            document.getElementById('edit_method').value = p.payment_method || 'cash';
            document.getElementById('edit_notes').value = p.notes || '';

            recalcEditSalary();
            document.getElementById('editSalaryModal').classList.add('active');
        }

        function recalcEditSalary() {
            const base = parseFloat(document.getElementById('edit_base').value) || 0;
            const ot = parseFloat(document.getElementById('edit_ot_pay').value) || 0;
            const allowances = parseFloat(document.getElementById('edit_allowances').value) || 0;
            const gross = base + ot + allowances;

            const sss = parseFloat(document.getElementById('edit_sss').value) || 0;
            const ph = parseFloat(document.getElementById('edit_ph').value) || 0;
            const pi = parseFloat(document.getElementById('edit_pi').value) || 0;
            const tax = parseFloat(document.getElementById('edit_tax').value) || 0;
            const other = parseFloat(document.getElementById('edit_other').value) || 0;
            const deductions = sss + ph + pi + tax + other;

            const net = Math.max(0, gross - deductions);

            document.getElementById('edit_gross_pay').value = gross.toFixed(2);
            document.getElementById('edit_deductions').value = deductions.toFixed(2);
            document.getElementById('edit_net_pay').value = net.toFixed(2);

            document.getElementById('edit_disp_gross').innerText = '₱' + gross.toFixed(2);
            document.getElementById('edit_disp_ded').innerText = '-₱' + deductions.toFixed(2);
            document.getElementById('edit_disp_net').innerText = '₱' + net.toFixed(2);
        }

        // Official Payslip Modal Population
        function openPayslipModal(p) {
            document.getElementById('slip_emp_name').innerText = p.employee_name || p.employee_username;
            document.getElementById('slip_emp_role').innerText = p.employee_position || (p.employee_role ? (p.employee_role.charAt(0).toUpperCase() + p.employee_role.slice(1)) : 'Staff');
            document.getElementById('slip_emp_id').innerText = '#' + (p.user_id || 'N/A');
            document.getElementById('slip_ref_no').innerText = p.reference_no || ('PAY-' + p.payroll_id);
            document.getElementById('slip_period').innerText = p.pay_period_start + ' to ' + p.pay_period_end;
            document.getElementById('slip_pay_date').innerText = p.payment_date;

            let baseDesc = 'Fixed';
            if (p.salary_type === 'hourly') {
                baseDesc = `${p.hours_worked} hrs @ ₱${parseFloat(p.hourly_rate).toFixed(2)}`;
            } else if (p.salary_type === 'daily') {
                baseDesc = `${p.days_worked} days`;
            }
            document.getElementById('slip_base_desc').innerText = baseDesc;
            document.getElementById('slip_base_amt').innerText = parseFloat(p.base_salary).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_ot_hrs').innerText = parseFloat(p.overtime_hours || 0).toFixed(1);
            document.getElementById('slip_ot_amt').innerText = parseFloat(p.overtime_pay || 0).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_allowances').innerText = parseFloat(p.allowances || 0).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_gross').innerText = '₱' + parseFloat(p.gross_pay).toLocaleString('en-US', {minimumFractionDigits:2});

            document.getElementById('slip_sss').innerText = parseFloat(p.sss_deduction || 0).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_ph').innerText = parseFloat(p.philhealth_deduction || 0).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_pi').innerText = parseFloat(p.pagibig_deduction || 0).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_tax').innerText = parseFloat(p.tax_deduction || 0).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_other_ded').innerText = parseFloat(p.other_deductions || 0).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_total_ded').innerText = '-₱' + parseFloat(p.deductions).toLocaleString('en-US', {minimumFractionDigits:2});

            document.getElementById('slip_net').innerText = '₱' + parseFloat(p.net_pay).toLocaleString('en-US', {minimumFractionDigits:2});
            document.getElementById('slip_method').innerText = (p.payment_method || 'cash').toUpperCase();
            document.getElementById('slip_status').innerText = (p.status || 'paid').toUpperCase();
            document.getElementById('slip_notes').innerText = p.notes || 'None';

            const managerName = p.manager_name || <?= json_encode($currentUserName) ?>;
            document.getElementById('slip_manager_sig').innerText = managerName + ' (Manager)';
            document.getElementById('slip_receiver_sig').innerText = p.employee_name || p.employee_username;

            document.getElementById('printablePayslipModal').classList.add('active');
        }

        // Edit Rate Modal
        function openEditRateModal(emp) {
            document.getElementById('rate_user_id').value = emp.user_id;
            document.getElementById('rate_full_name').value = emp.full_name + ' (' + emp.username + ')';
            document.getElementById('rate_position').value = emp.position_title || '';
            document.getElementById('rate_salary_type').value = emp.salary_type || 'hourly';
            document.getElementById('rate_hourly').value = parseFloat(emp.hourly_rate || 0).toFixed(2);
            document.getElementById('rate_daily').value = parseFloat(emp.daily_rate || 0).toFixed(2);
            document.getElementById('rate_monthly').value = parseFloat(emp.monthly_rate || 0).toFixed(2);
            document.getElementById('editRateModal').classList.add('active');
        }

        // Toggle custom date range inputs
        function toggleCustomDates(val) {
            const grps = document.querySelectorAll('.custom-date-grp');
            grps.forEach(g => {
                g.style.display = (val === 'custom') ? 'flex' : 'none';
            });
            if (val !== 'custom') {
                document.getElementById('filterForm').submit();
            }
        }

        // Live table filter
        function filterPayrollTable() {
            const q = document.getElementById('payrollSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#payrollTable tbody tr');
            rows.forEach(r => {
                const txt = r.innerText.toLowerCase();
                r.style.display = txt.includes(q) ? '' : 'none';
            });
        }

        // Confirm and submit deletion of a payroll record
        function confirmDeletePayroll(btn) {
            const form = btn.closest('form');
            if (!form) return;
            const ref = btn.getAttribute('data-ref') || 'this voucher';
            const net = btn.getAttribute('data-net') || '0.00';
            const emp = btn.getAttribute('data-emp') || 'Staff';
            const title = 'Void Salary Voucher';
            const message = `Are you sure you want to void voucher '${ref}' of ₱${net} for ${emp}?\nAll historical records for this voucher will be permanently removed.`;

            if (typeof window.confirmFormSubmit === 'function') {
                window.confirmFormSubmit(form, title, message, 'Yes, Void Voucher', true);
            } else if (typeof window.posConfirm === 'function') {
                window.posConfirm(title, message, function() {
                    form.submit();
                }, {
                    confirmText: 'Yes, Void Voucher',
                    isDanger: true
                });
            } else if (confirm(`Are you sure you want to void voucher '${ref}' of ₱${net} for ${emp}?`)) {
                form.submit();
            }
        }

        // Void voucher directly from within Edit Modal
        function deleteFromEditModal() {
            const payrollId = document.getElementById('edit_payroll_id').value;
            const ref = document.getElementById('edit_ref_no').value || ('PAY-' + payrollId);
            const emp = document.getElementById('edit_employee_name').value || 'Staff';
            if (!payrollId) return;

            const title = 'Void Salary Voucher';
            const message = `Are you sure you want to void voucher '${ref}' for ${emp}?\nAll historical records for this voucher will be permanently removed.`;

            const executeDelete = function() {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'index.php';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_salary">
                    <input type="hidden" name="payroll_id" value="${payrollId}">
                `;
                document.body.appendChild(form);
                form.submit();
            };

            closeModal('editSalaryModal');

            if (typeof window.posConfirm === 'function') {
                window.posConfirm(title, message, executeDelete, {
                    confirmText: 'Yes, Void Voucher',
                    isDanger: true
                });
            } else if (confirm(`Are you sure you want to void voucher '${ref}' for ${emp}?`)) {
                executeDelete();
            }
        }

        // Auto-open modal if give_to parameter was present in URL
        <?php if ($autoOpenUser > 0): ?>
            window.addEventListener('DOMContentLoaded', function() {
                openGiveSalaryForUser(<?= $autoOpenUser ?>);
            });
        <?php endif; ?>
    </script>
</body>
</html>
