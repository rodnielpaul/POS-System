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

// Handle Form Submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    // 1. Add User Account
    if ($action === "add_user") {
        $fullName = trim($_POST["full_name"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";
        $roleId   = (int)($_POST["role_id"] ?? 0);

        // Role verification (Manager cannot create Admin)
        if ($currentUserRole === "manager" && $roleId === 3) {
            $error = "Managers are not authorized to create Admin accounts.";
        } elseif ($fullName === "" || $username === "" || $password === "" || $roleId <= 0) {
            $error = "Please fill in all required fields.";
        } elseif (strlen($username) < 3) {
            $error = "Username must be at least 3 characters.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($password !== $confirmPassword) {
            $error = "Passwords do not match.";
        } else {
            // Check username uniqueness
            $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $checkStmt->bind_param("s", $username);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $error = "The username '$username' is already taken.";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $positionTitle = trim($_POST["position_title"] ?? "");
                $salaryType = $_POST["salary_type"] ?? "hourly";
                $hourlyRate = (float)($_POST["hourly_rate"] ?? 0);
                $dailyRate  = (float)($_POST["daily_rate"] ?? 0);
                $monthlyRate = (float)($_POST["monthly_rate"] ?? 0);

                $insertStmt = $conn->prepare("INSERT INTO users (full_name, username, password, role_id, status, position_title, salary_type, hourly_rate, daily_rate, monthly_rate) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)");
                $insertStmt->bind_param("sssisssddd", $fullName, $username, $hashedPassword, $roleId, $positionTitle, $salaryType, $hourlyRate, $dailyRate, $monthlyRate);
                if ($insertStmt->execute()) {
                    $newId = $conn->insert_id;
                    $success = "Account '$username' created successfully!";
                    log_activity($conn, $currentUserId, "USER_CREATED", "Created account #$newId ($username) with role ID $roleId");
                } else {
                    $error = "Database error creating account: " . $conn->error;
                }
            }
        }
    }

    // 2. Edit User Account
    elseif ($action === "edit_user") {
        $targetUserId = (int)($_POST["user_id"] ?? 0);
        $fullName     = trim($_POST["full_name"] ?? "");
        $roleId       = (int)($_POST["role_id"] ?? 0);
        $status       = $_POST["status"] ?? "active";
        $newPassword  = $_POST["new_password"] ?? "";
        $positionTitle = trim($_POST["position_title"] ?? "");
        $salaryType   = $_POST["salary_type"] ?? "hourly";
        $hourlyRate   = (float)($_POST["hourly_rate"] ?? 0);
        $dailyRate    = (float)($_POST["daily_rate"] ?? 0);
        $monthlyRate  = (float)($_POST["monthly_rate"] ?? 0);

        // Verify target user exists
        $targetStmt = $conn->prepare("SELECT user_id, username, role_id FROM users WHERE user_id = ?");
        $targetStmt->bind_param("i", $targetUserId);
        $targetStmt->execute();
        $targetUser = $targetStmt->get_result()->fetch_assoc();

        if (!$targetUser) {
            $error = "User not found.";
        } elseif ($currentUserRole === "manager" && ((int)$targetUser["role_id"] === 3 || $roleId === 3)) {
            $error = "Managers cannot modify Admin accounts.";
        } elseif ($targetUserId === $currentUserId && $status !== "active") {
            $error = "You cannot deactivate your own logged-in account.";
        } else {
            if (!empty($newPassword)) {
                if (strlen($newPassword) < 6) {
                    $error = "New password must be at least 6 characters.";
                } else {
                    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                    $upStmt = $conn->prepare("UPDATE users SET full_name = ?, role_id = ?, status = ?, position_title = ?, salary_type = ?, hourly_rate = ?, daily_rate = ?, monthly_rate = ?, password = ? WHERE user_id = ?");
                    $upStmt->bind_param("sisssdddsi", $fullName, $roleId, $status, $positionTitle, $salaryType, $hourlyRate, $dailyRate, $monthlyRate, $hashed, $targetUserId);
                    $upStmt->execute();
                    $success = "User '{$targetUser['username']}' updated successfully with new password.";
                    log_activity($conn, $currentUserId, "USER_UPDATED", "Updated user #$targetUserId with password reset");
                }
            } else {
                $upStmt = $conn->prepare("UPDATE users SET full_name = ?, role_id = ?, status = ?, position_title = ?, salary_type = ?, hourly_rate = ?, daily_rate = ?, monthly_rate = ? WHERE user_id = ?");
                $upStmt->bind_param("sisssdddi", $fullName, $roleId, $status, $positionTitle, $salaryType, $hourlyRate, $dailyRate, $monthlyRate, $targetUserId);
                $upStmt->execute();
                $success = "User '{$targetUser['username']}' updated successfully.";
                log_activity($conn, $currentUserId, "USER_UPDATED", "Updated user #$targetUserId (role: $roleId, status: $status)");
            }
        }
    }

    // 3. Quick Status Toggle
    elseif ($action === "toggle_status") {
        $targetUserId = (int)($_POST["user_id"] ?? 0);
        if ($targetUserId === $currentUserId) {
            $error = "You cannot deactivate your own account.";
        } else {
            $sRes = $conn->query("SELECT user_id, username, status, role_id FROM users WHERE user_id = $targetUserId");
            if ($row = $sRes->fetch_assoc()) {
                if ($currentUserRole === "manager" && (int)$row["role_id"] === 3) {
                    $error = "Managers cannot modify Admin accounts.";
                } else {
                    $newStatus = ($row["status"] === "active") ? "inactive" : "active";
                    $conn->query("UPDATE users SET status = '$newStatus' WHERE user_id = $targetUserId");
                    $success = "User '{$row['username']}' is now $newStatus.";
                    log_activity($conn, $currentUserId, "USER_STATUS_CHANGE", "Changed user #$targetUserId status to $newStatus");
                }
            }
        }
    }

    // 4. Delete Account
    elseif ($action === "delete_user") {
        $targetUserId = (int)($_POST["user_id"] ?? 0);
        if ($targetUserId === $currentUserId) {
            $error = "You cannot delete your own logged-in account.";
        } else {
            $targetStmt = $conn->prepare("SELECT user_id, username, role_id FROM users WHERE user_id = ?");
            $targetStmt->bind_param("i", $targetUserId);
            $targetStmt->execute();
            $targetUser = $targetStmt->get_result()->fetch_assoc();

            if (!$targetUser) {
                $error = "User not found.";
            } elseif ($currentUserRole === "manager" && (int)$targetUser["role_id"] === 3) {
                $error = "Managers cannot delete Admin accounts.";
            } else {
                $conn->begin_transaction();
                try {
                    $conn->query("UPDATE orders SET user_id = NULL WHERE user_id = $targetUserId");
                    $conn->query("UPDATE inventory_logs SET user_id = NULL WHERE user_id = $targetUserId");
                    $conn->query("UPDATE system_logs SET user_id = NULL WHERE user_id = $targetUserId");
                    $conn->query("UPDATE payroll_records SET user_id = NULL WHERE user_id = $targetUserId");
                    $conn->query("DELETE FROM users WHERE user_id = $targetUserId");
                    $conn->commit();
                    $success = "User account '{$targetUser['username']}' was deleted successfully.";
                    log_activity($conn, $currentUserId, "USER_DELETED", "Deleted user #$targetUserId ({$targetUser['username']})");
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Error deleting account: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch all roles for dropdowns
$rolesRes = $conn->query("SELECT * FROM roles ORDER BY role_id ASC");
$allRoles = [];
while ($r = $rolesRes->fetch_assoc()) {
    $allRoles[] = $r;
}

// Fetch user accounts
$usersQuery = "
    SELECT u.user_id, u.username, u.full_name, u.role_id, u.status, u.position_title, u.salary_type, u.hourly_rate, u.daily_rate, u.monthly_rate, r.role_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    ORDER BY u.user_id ASC
";
$usersResult = $conn->query($usersQuery);
$usersList = [];
$totalUsers = 0;
$adminCount = 0;
$managerCount = 0;
$cashierCount = 0;

while ($u = $usersResult->fetch_assoc()) {
    $usersList[] = $u;
    $totalUsers++;
    if ($u["role_name"] === "admin" && $u["status"] === "active") $adminCount++;
    if ($u["role_name"] === "manager" && $u["status"] === "active") $managerCount++;
    if ($u["role_name"] === "cashier" && $u["status"] === "active") $cashierCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Management | Capuzan Kusina</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=<?= time() ?>">
</head>
<body>
    <?php include __DIR__ . "/../includes/navbar.php"; ?>

    <main class="main-wrapper">
        <div class="page-header">
            <div>
                <h1 class="page-title">Account Management</h1>
                <p class="page-subtitle">Manage system users, login credentials, compensation rates, and role permissions.</p>
            </div>
            <div>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <span style="font-size:1.1rem; line-height:1;">+</span> Add New Account
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

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Accounts</span>
                <span class="stat-val"><?= $totalUsers ?></span>
                <span class="stat-sub">Registered system profiles</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active Cashiers</span>
                <span class="stat-val" style="color:var(--success);"><?= $cashierCount ?></span>
                <span class="stat-sub">Front-desk POS operators</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active Managers</span>
                <span class="stat-val" style="color:var(--warning);"><?= $managerCount ?></span>
                <span class="stat-sub">Inventory & Sales overseers</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active Admins</span>
                <span class="stat-val" style="color:var(--danger);"><?= $adminCount ?></span>
                <span class="stat-sub">Full system privilege</span>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">User Accounts Directory</span>
                <input type="text" id="user-search" placeholder="Search by name, position or username..." class="form-control" style="width:260px;" onkeyup="filterUsers()">
            </div>
            <div class="table-responsive">
                <table class="data-table" id="users-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Full Name & Position</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Compensation</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersList as $user): ?>
                            <tr>
                                <td><strong>#<?= $user["user_id"] ?></strong></td>
                                <td>
                                    <strong><?= htmlspecialchars($user["full_name"]) ?></strong>
                                    <?php if (!empty($user["position_title"])): ?>
                                        <div style="font-size:0.8rem; color:var(--gray-600);"><?= htmlspecialchars($user["position_title"]) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($user["username"]) ?></code></td>
                                <td>
                                    <span class="role-pill role-<?= htmlspecialchars($user["role_name"]) ?>">
                                        <?= htmlspecialchars($user["role_name"]) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user["salary_type"] === "monthly" && (float)$user["monthly_rate"] > 0): ?>
                                        <span style="font-weight:600; color:var(--dark-brown);">₱<?= number_format($user["monthly_rate"], 2) ?></span><small style="color:var(--gray-500);">/mo</small>
                                    <?php elseif ($user["salary_type"] === "daily" && (float)$user["daily_rate"] > 0): ?>
                                        <span style="font-weight:600; color:var(--dark-brown);">₱<?= number_format($user["daily_rate"], 2) ?></span><small style="color:var(--gray-500);">/day</small>
                                    <?php elseif ((float)$user["hourly_rate"] > 0): ?>
                                        <span style="font-weight:600; color:var(--dark-brown);">₱<?= number_format($user["hourly_rate"], 2) ?></span><small style="color:var(--gray-500);">/hr</small>
                                    <?php else: ?>
                                        <span style="font-size:0.82rem; color:var(--gray-500);">Not configured</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user["status"] === "active"): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <?php 
                                    // Manager cannot modify Admin accounts
                                    $canModify = !($currentUserRole === "manager" && $user["role_name"] === "admin");
                                    ?>
                                    <?php if ($canModify): ?>
                                        <?php if ($user["status"] === "active"): ?>
                                            <a href="../payroll/index.php?give_to=<?= $user["user_id"] ?>" class="btn btn-sm btn-primary" style="margin-right:4px;" title="Give salary to <?= htmlspecialchars($user["full_name"]) ?>">
                                                💵 Give Salary
                                            </a>
                                        <?php endif; ?>

                                        <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($user) ?>)'>
                                            Edit
                                        </button>
                                        
                                        <?php if ($user["user_id"] != $currentUserId): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= $user["user_id"] ?>">
                                                <?php if ($user["status"] === "active"): ?>
                                                    <button type="button" class="btn btn-sm btn-secondary" onclick="confirmFormSubmit(this.form, 'Deactivate Account', 'Are you sure you want to deactivate account \'<?= htmlspecialchars(addslashes($user['username'])) ?>\'? They will be unable to log in.', 'Deactivate', false)">Deactivate</button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-success" onclick="confirmFormSubmit(this.form, 'Activate Account', 'Are you sure you want to activate account \'<?= htmlspecialchars(addslashes($user['username'])) ?>\'?', 'Activate', false)">Activate</button>
                                                <?php endif; ?>
                                            </form>
                                            <form method="POST" action="index.php" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $user["user_id"] ?>">
                                                <button type="button" class="btn btn-sm btn-danger" style="margin-left:3px;" onclick="confirmFormSubmit(this.form, 'Delete Account', 'Are you sure you want to permanently delete account \'<?= htmlspecialchars(addslashes($user['username'])) ?>\'? All historical sales records will be preserved.', 'Yes, Delete', true)">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="font-size:0.8rem; color:var(--gray-500);">Protected</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: Add New Account -->
    <div class="modal-overlay" id="add-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Create New Account</h3>
                <button class="modal-close" onclick="closeModal('add-modal')">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="add_full_name">Full Name *</label>
                        <input type="text" id="add_full_name" name="full_name" class="form-control" required placeholder="e.g. Juan Santos">
                    </div>
                    <div class="form-group">
                        <label for="add_username">Username *</label>
                        <input type="text" id="add_username" name="username" class="form-control" required placeholder="e.g. jsantos">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_password">Password *</label>
                            <input type="password" id="add_password" name="password" class="form-control" required minlength="6" placeholder="Min 6 chars">
                        </div>
                        <div class="form-group">
                            <label for="add_confirm">Confirm Password *</label>
                            <input type="password" id="add_confirm" name="confirm_password" class="form-control" required minlength="6">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_role">Role Assignment *</label>
                            <select id="add_role" name="role_id" class="form-control" required>
                                <option value="">Select Role</option>
                                <?php foreach ($allRoles as $role): ?>
                                    <?php if ($currentUserRole === "manager" && $role["role_name"] === "admin") continue; ?>
                                    <option value="<?= $role["role_id"] ?>"><?= ucfirst(htmlspecialchars($role["role_name"])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add_position">Position / Job Title</label>
                            <input type="text" id="add_position" name="position_title" class="form-control" placeholder="e.g. Head Cook, Cashier">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_salary_type">Salary Type</label>
                            <select id="add_salary_type" name="salary_type" class="form-control">
                                <option value="hourly">Hourly Rate</option>
                                <option value="daily">Daily Rate</option>
                                <option value="monthly">Monthly Fixed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add_hourly_rate">Hourly Rate (₱)</label>
                            <input type="number" step="0.01" id="add_hourly_rate" name="hourly_rate" class="form-control" value="0.00">
                        </div>
                        <div class="form-group">
                            <label for="add_daily_rate">Daily Rate (₱)</label>
                            <input type="number" step="0.01" id="add_daily_rate" name="daily_rate" class="form-control" value="0.00">
                        </div>
                        <div class="form-group">
                            <label for="add_monthly_rate">Monthly (₱)</label>
                            <input type="number" step="0.01" id="add_monthly_rate" name="monthly_rate" class="form-control" value="0.00">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('add-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Account -->
    <div class="modal-overlay" id="edit-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title">Edit Account</h3>
                <button class="modal-close" onclick="closeModal('edit-modal')">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" id="edit_user_id" name="user_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="edit_username" class="form-control" disabled style="background:#f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label for="edit_full_name">Full Name *</label>
                        <input type="text" id="edit_full_name" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_role">Role</label>
                            <select id="edit_role" name="role_id" class="form-control" required>
                                <?php foreach ($allRoles as $role): ?>
                                    <option value="<?= $role["role_id"] ?>"><?= ucfirst(htmlspecialchars($role["role_name"])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_status">Account Status</label>
                            <select id="edit_status" name="status" class="form-control">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_position">Position / Job Title</label>
                            <input type="text" id="edit_position" name="position_title" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_salary_type">Salary Type</label>
                            <select id="edit_salary_type" name="salary_type" class="form-control">
                                <option value="hourly">Hourly Rate</option>
                                <option value="daily">Daily Rate</option>
                                <option value="monthly">Monthly Fixed</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_hourly_rate">Hourly Rate (₱)</label>
                            <input type="number" step="0.01" id="edit_hourly_rate" name="hourly_rate" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_daily_rate">Daily Rate (₱)</label>
                            <input type="number" step="0.01" id="edit_daily_rate" name="daily_rate" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_monthly_rate">Monthly Rate (₱)</label>
                            <input type="number" step="0.01" id="edit_monthly_rate" name="monthly_rate" class="form-control">
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 12px; border-top: 1px dashed var(--gray-200); padding-top: 12px;">
                        <label for="edit_new_password">Reset Password (leave empty to keep current)</label>
                        <input type="password" id="edit_new_password" name="new_password" class="form-control" placeholder="Enter new password if changing">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('edit-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('add-modal').classList.add('active');
        }

        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.user_id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_full_name').value = user.full_name;
            document.getElementById('edit_role').value = user.role_id;
            document.getElementById('edit_status').value = user.status;
            document.getElementById('edit_position').value = user.position_title || '';
            document.getElementById('edit_salary_type').value = user.salary_type || 'hourly';
            document.getElementById('edit_hourly_rate').value = parseFloat(user.hourly_rate || 0).toFixed(2);
            document.getElementById('edit_daily_rate').value = parseFloat(user.daily_rate || 0).toFixed(2);
            document.getElementById('edit_monthly_rate').value = parseFloat(user.monthly_rate || 0).toFixed(2);
            document.getElementById('edit_new_password').value = "";
            document.getElementById('edit-modal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Live filter table
        function filterUsers() {
            const query = document.getElementById('user-search').value.toLowerCase();
            const rows = document.querySelectorAll('#users-table tbody tr');
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
