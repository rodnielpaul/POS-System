<?php
// Include this AFTER auth_check.php on any page restricted to certain roles.
// Set $allowedRoles = ["admin", "manager"]; before requiring this file.
//
// Usage:
//   require __DIR__ . "/../middleware/auth_check.php";
//   $allowedRoles = ["admin"];
//   require __DIR__ . "/../middleware/role_check.php";

if (!isset($allowedRoles) || !is_array($allowedRoles)) {
    $allowedRoles = [];
}

if (!in_array($_SESSION["role"], $allowedRoles, true)) {
    http_response_code(403);
    echo "<h2>403 - You don't have permission to view this page.</h2>";
    echo '<p><a href="/POS-System/dashboard/index.php">Back to dashboard</a></p>';
    exit;
}
