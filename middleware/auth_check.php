<?php
// Include this at the top of any page that requires a logged-in user.
// Usage: require __DIR__ . "/../middleware/auth_check.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user_id"]) || !isset($_SESSION["role"])) {
    header("Location: /POS-System/auth/login.php");
    exit;
}
