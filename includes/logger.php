<?php
/**
 * Activity & System Logger
 */
function log_activity($conn, $userId, $action, $details = "") {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $uid = (!empty($userId) && (int)$userId > 0) ? (int)$userId : null;
        
        $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isss", $uid, $action, $details, $ip);
            $stmt->execute();
            $stmt->close();
        }
    } catch (\Throwable $e) {
        // Logging failure must never break core transaction
        error_log("log_activity notice: " . $e->getMessage());
    }
}
