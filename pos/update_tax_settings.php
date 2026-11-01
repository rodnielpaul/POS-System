<?php
session_start();
require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/logger.php";
header("Content-Type: application/json");

$allowedRoles = ["admin", "manager", "cashier"];
if (!isset($_SESSION["role"]) || !in_array($_SESSION["role"], $allowedRoles, true)) {
    echo json_encode(["success" => false, "message" => "Not authorized."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$taxRate = isset($data["taxRate"]) ? (float)$data["taxRate"] : null;

if ($taxRate === null || $taxRate < 0 || $taxRate > 100) {
    echo json_encode(["success" => false, "message" => "Please enter a valid tax rate between 0% and 100%."]);
    exit;
}

$formattedRate = number_format($taxRate, 2, '.', '');

// Insert or update tax_rate in settings table
$stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('tax_rate', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
$stmt->bind_param("ss", $formattedRate, $formattedRate);

if ($stmt->execute()) {
    $userId = (int)($_SESSION["user_id"] ?? 0);
    log_activity($conn, $userId, "TAX_RATE_UPDATED", "Updated system default tax rate to {$formattedRate}%");
    echo json_encode([
        "success" => true,
        "taxRate" => $formattedRate,
        "message" => "Store default tax rate updated to {$formattedRate}%."
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to update tax rate: " . $conn->error]);
}
