<?php
session_start();

if (isset($_SESSION["user_id"]) && isset($_SESSION["role"])) {
    header("Location: dashboard/index.php");
} else {
    header("Location: auth/login.php");
}
exit;
