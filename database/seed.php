<?php
// One-off dev script: creates test accounts for each role.
// Run this once (e.g. via CLI: php seed.php) after importing pos_system.sql.
require __DIR__ . "/../config/database.php";

$users = [
    ["cashier1", "cashier123", "Juan Dela Cruz", 1], // 1 = cashier
    ["manager1", "manager123", "Maria Santos",   2], // 2 = manager
    ["admin1",   "admin123",   "System Admin",   3], // 3 = admin
];

foreach ($users as $u) {
    $hashed = password_hash($u[1], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $u[0], $hashed, $u[2], $u[3]);
    $stmt->execute();
}

echo "Test users created!";
$conn->close();
