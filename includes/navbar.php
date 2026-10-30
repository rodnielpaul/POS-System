<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserRole = $_SESSION["role"] ?? "cashier";
$currentUserName = $_SESSION["full_name"] ?? "User";
$currentScript = $_SERVER['REQUEST_URI'] ?? '';

// Determine active state
function isNavActive($uriSegment, $currentScript) {
    return strpos($currentScript, $uriSegment) !== false ? 'active' : '';
}
?>
<nav class="app-navbar">
    <div style="display: flex; align-items: center; gap: 28px;">
        <a href="/POS-System/dashboard/index.php" class="nav-brand">
            <span class="brand-logo-badge">CK</span>
            <span>Capuzan Kusina</span>
            <span class="brand-badge"><?= strtoupper($currentUserRole) ?></span>
        </a>

        <ul class="nav-links">
            <li><a href="/POS-System/dashboard/index.php" class="<?= isNavActive('/dashboard/', $currentScript) ?>">Dashboard</a></li>
            <li><a href="/POS-System/pos/index.php" class="<?= isNavActive('/pos/', $currentScript) ?>">Point of Sale</a></li>
            
            <?php if (in_array($currentUserRole, ['admin', 'manager'], true)): ?>
                <li><a href="/POS-System/inventory/index.php" class="<?= isNavActive('/inventory/', $currentScript) ?>">Inventory</a></li>
                <li><a href="/POS-System/suppliers/index.php" class="<?= isNavActive('/suppliers/', $currentScript) ?>">Suppliers</a></li>
                <li><a href="/POS-System/employees/index.php" class="<?= isNavActive('/employees/', $currentScript) ?>">Accounts</a></li>
                <li><a href="/POS-System/payroll/index.php" class="<?= isNavActive('/payroll/', $currentScript) ?>">Payroll</a></li>
                <li><a href="/POS-System/reports/index.php" class="<?= isNavActive('/reports/', $currentScript) ?>">Reports</a></li>
            <?php endif; ?>

            <?php if ($currentUserRole === 'admin'): ?>
                <li><a href="/POS-System/logs/index.php" class="<?= isNavActive('/logs/', $currentScript) ?>">System Logs</a></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="nav-user">
        <div class="user-tag">
            <span><?= htmlspecialchars($currentUserName) ?></span>
            <span class="role-pill role-<?= htmlspecialchars($currentUserRole) ?>">
                <?= htmlspecialchars($currentUserRole) ?>
            </span>
        </div>
        <a href="javascript:void(0)" onclick="openLogoutModal()" class="btn-logout">Logout</a>
    </div>
</nav>

<!-- Logout Confirmation Notification Modal -->
<div id="logoutConfirmModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(51,38,31,0.65); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(3px);">
    <div style="background:#FFFDF8; border-radius:16px; max-width:400px; width:90%; padding:26px 24px; box-shadow:0 16px 36px rgba(74,41,28,0.3); border:1px solid #E6D9C8; text-align:center;">
        <div style="width:52px; height:52px; border-radius:50%; background:#FEF8E8; color:#D9A441; display:flex; align-items:center; justify-content:center; font-size:26px; margin:0 auto 14px; border:1px solid #D9A441;">
            🚪
        </div>
        <h3 style="color:#4A291C; margin-bottom:8px; font-size:1.25rem; font-weight:800;">Log Out of Capuzan Kusina?</h3>
        <p style="color:#6B574C; font-size:13.5px; line-height:1.5; margin-bottom:20px;">
            Are you sure you want to end your current session, <strong><?= htmlspecialchars($currentUserName) ?></strong>? Any unsaved changes or active transaction carts will be cleared.
        </p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button type="button" onclick="closeLogoutModal()" style="flex:1; padding:10px 16px; border-radius:8px; font-weight:700; font-size:14px; background:#FFFDF8; color:#4A291C; border:1px solid #E6D9C8; cursor:pointer; transition:all 0.15s ease;" onmouseover="this.style.background='#F7F0E3'" onmouseout="this.style.background='#FFFDF8'">
                Cancel
            </button>
            <a href="/POS-System/auth/logout.php" style="flex:1; padding:10px 16px; border-radius:8px; font-weight:700; font-size:14px; background:#C96B2C; color:#FFFDF8; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition:background 0.15s ease;" onmouseover="this.style.background='#A94F20'" onmouseout="this.style.background='#C96B2C'">
                Yes, Log Out
            </a>
        </div>
    </div>
</div>

<script>
    function openLogoutModal() {
        var m = document.getElementById('logoutConfirmModal');
        if (m) m.style.display = 'flex';
    }
    function closeLogoutModal() {
        var m = document.getElementById('logoutConfirmModal');
        if (m) m.style.display = 'none';
    }
    window.addEventListener('click', function(e) {
        var m = document.getElementById('logoutConfirmModal');
        if (m && e.target === m) {
            closeLogoutModal();
        }
    });
    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLogoutModal();
        }
    });
</script>
<script src="/POS-System/assets/js/notifications.js?v=<?= time() ?>"></script>
