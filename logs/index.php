<?php
require __DIR__ . "/../middleware/auth_check.php";
$allowedRoles = ["admin"];
require __DIR__ . "/../middleware/role_check.php";
require __DIR__ . "/../config/database.php";

// Fetch system logs
$sql = "
    SELECT l.*, u.username, u.full_name, r.role_name
    FROM system_logs l
    LEFT JOIN users u ON l.user_id = u.user_id
    LEFT JOIN roles r ON u.role_id = r.role_id
    ORDER BY l.created_at DESC
    LIMIT 100
";
$res = $conn->query($sql);
$logs = [];
$totalLogs = 0;
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $logs[] = $r;
        $totalLogs++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs | Capuzan Kusina</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
    <?php include __DIR__ . "/../includes/navbar.php"; ?>

    <main class="main-wrapper">
        <div class="page-header">
            <div>
                <h1 class="page-title">System Audit Logs</h1>
                <p class="page-subtitle">Security audit trail recording user logins, product edits, inventory restocking, and sales.</p>
            </div>
            <div>
                <input type="text" id="log-search" placeholder="Search logs..." class="form-control" style="width:260px;" onkeyup="filterLogs()">
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Activity Logs (<?= $totalLogs ?>)</span>
                <span style="font-size:0.85rem; color:var(--gray-500);">Admin-Only View</span>
            </div>
            <div class="table-responsive">
                <table class="data-table" id="logs-table">
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>Timestamp</th>
                            <th>Action Type</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Activity Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:20px;">No system logs recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>#<?= $log["log_id"] ?></td>
                                    <td><small><?= $log["created_at"] ?></small></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?= htmlspecialchars($log["action"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($log["full_name"] ?: ($log["username"] ?: "System / Guest")) ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($log["role_name"])): ?>
                                            <span class="role-pill role-<?= htmlspecialchars($log["role_name"]) ?>">
                                                <?= htmlspecialchars($log["role_name"]) ?>
                                            </span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($log["details"] ?: "—") ?></td>
                                    <td><code><?= htmlspecialchars($log["ip_address"] ?: "—") ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        function filterLogs() {
            const query = document.getElementById('log-search').value.toLowerCase();
            const rows = document.querySelectorAll('#logs-table tbody tr');
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
