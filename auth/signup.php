<?php
session_start();
require __DIR__ . "/../config/database.php";

$error = "";
$success = "";

// Keep whatever the user typed so the form can be re-filled after an error
$oldFullName = "";
$oldUsername = "";
$oldRole     = "";

/**
 * Validates password strength.
 * Requires: at least 8 characters, at least one number,
 * and at least one special character.
 */
function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return "Password must be at least 8 characters long.";
    }
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number.";
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        return "Password must contain at least one special character (e.g. ! @ # $ % &).";
    }
    return "";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $fullName = trim($_POST["full_name"] ?? "");
    $role     = $_POST["role"] ?? "";

    $oldFullName = $fullName;
    $oldUsername = $username;
    $oldRole     = $role;

    $allowedRoles = ["cashier", "manager", "admin"];

    if ($username === "" || $password === "" || $confirmPassword === "" || $fullName === "" || $role === "") {
        $error = "Please complete all fields to create your account.";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters long.";
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = "Invalid role selected.";
    } elseif (($pwError = validatePasswordStrength($password)) !== "") {
        $error = $pwError;
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "That username is already taken.";
        } else {
            $roleStmt = $conn->prepare("SELECT role_id FROM roles WHERE role_name = ?");
            $roleStmt->bind_param("s", $role);
            $roleStmt->execute();
            $roleResult = $roleStmt->get_result();

            if ($roleResult->num_rows === 1) {
                $roleRow = $roleResult->fetch_assoc();
                $roleId = $roleRow["role_id"];

                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $insert = $conn->prepare("INSERT INTO users (username, password, full_name, role_id) VALUES (?, ?, ?, ?)");
                $insert->bind_param("sssi", $username, $hashed, $fullName, $roleId);

                if ($insert->execute()) {
                    // Log the new user in immediately, clear old session state & regenerate ID
                    $_SESSION = [];
                    session_regenerate_id(true);

                    $_SESSION["user_id"]   = $conn->insert_id;
                    $_SESSION["username"]  = $username;
                    $_SESSION["full_name"] = $fullName;
                    $_SESSION["role"]      = $role;

                    if ($role === "cashier") {
                        header("Location: ../pos/index.php");
                    } else {
                        header("Location: ../dashboard/index.php");
                    }
                    exit;
                } else {
                    $error = "Something went wrong while creating your account. Please try again.";
                }
            } else {
                $error = "Invalid role selected.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up | Capuzan Kusina</title>
<style>
    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        height: 100%;
        font-family: 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif;
        overflow: hidden;
        background-color: #F7F0E3;
    }

    body {
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
    }

    .bg-photo {
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background-image: url('https://images.unsplash.com/photo-1555396273-367ea4eb4db5?fm=jpg&q=80&w=1920&auto=format&fit=crop');
        background-size: cover;
        background-position: center;
        z-index: 0;
        filter: brightness(0.65);
        animation: kenBurns 25s ease-in-out infinite alternate;
    }

    @keyframes kenBurns {
        0%   { transform: scale(1) translate(0, 0); }
        100% { transform: scale(1.12) translate(-1%, -1%); }
    }

    .bg-overlay {
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: radial-gradient(circle at center, rgba(74, 41, 28, 0.78), rgba(41, 24, 16, 0.94));
        z-index: 1;
    }

    .login-card {
        position: relative;
        z-index: 2;
        background: #FFFDF8;
        border: 1px solid #E6D9C8;
        width: 100%;
        max-width: 420px;
        padding: 36px 36px;
        border-radius: 24px;
        box-shadow: 0 16px 40px rgba(41, 24, 16, 0.45);
        max-height: 92vh;
        overflow-y: auto;
        scrollbar-width: none;
    }

    .login-card::-webkit-scrollbar {
        display: none;
    }

    .login-header {
        text-align: center;
        margin-bottom: 24px;
    }

    .login-header .logo-circle {
        width: 54px;
        height: 54px;
        margin: 0 auto 10px;
        background: #C96B2C;
        border: 2px solid #D9A441;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #FFFDF8;
        font-size: 20px;
        font-weight: 900;
        letter-spacing: 1px;
        box-shadow: 0 4px 10px rgba(201, 107, 44, 0.35);
    }

    .login-header h1 {
        font-size: 22px;
        font-weight: 800;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin: 0 0 4px;
        color: #4A291C;
    }

    .login-header p {
        font-size: 13px;
        color: #6B574C;
        margin: 0;
    }

    .field {
        margin-bottom: 15px;
    }

    .field label {
        display: block;
        font-size: 12.5px;
        font-weight: 700;
        color: #33261F;
        margin-bottom: 5px;
    }

    .field input,
    .field select {
        width: 100%;
        padding: 11px 16px;
        border: 1px solid #D4C3B2;
        border-radius: 9px;
        font-size: 13.5px;
        outline: none;
        background: #F7F0E3;
        color: #33261F;
        font-family: inherit;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }

    .field select option {
        color: #33261F;
        background: #FFFDF8;
    }

    .field input::placeholder {
        color: #8A7568;
    }

    .field input:focus,
    .field select:focus {
        background: #FFFDF8;
        border-color: #C96B2C;
        box-shadow: 0 0 0 3px rgba(201, 107, 44, 0.2);
    }

    .password-wrapper {
        position: relative;
    }

    .password-wrapper input {
        padding-right: 40px;
    }

    .toggle-eye {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        user-select: none;
        color: #8A7568;
        display: flex;
        align-items: center;
    }

    .toggle-eye:hover {
        color: #C96B2C;
    }

    .toggle-eye svg {
        width: 18px;
        height: 18px;
    }

    .submit-btn {
        width: 100%;
        padding: 13px;
        margin-top: 8px;
        background: #C96B2C;
        color: #FFFDF8;
        border: none;
        border-radius: 9px;
        font-size: 14.5px;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s ease, transform 0.1s ease;
        box-shadow: 0 4px 12px rgba(201, 107, 44, 0.25);
    }

    .submit-btn:hover {
        background: #A94F20;
        transform: translateY(-1px);
    }

    .error-box {
        background: #FCECE9;
        color: #8A2218;
        border: 1px solid #F3C4BE;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 13px;
        margin-bottom: 16px;
        font-weight: 600;
    }

    .login-footer {
        text-align: center;
        margin-top: 18px;
        font-size: 12px;
        color: #8A7568;
    }

    .login-footer a {
        color: #C96B2C;
        font-weight: 700;
        text-decoration: none;
    }

    .login-footer a:hover {
        text-decoration: underline;
    }
</style>
</head>
<body>

    <div class="bg-photo"></div>
    <div class="bg-overlay"></div>

    <div class="login-card">
        <div class="login-header">
            <div class="logo-circle">CK</div>
            <h1>Create account</h1>
            <p>Capuzan Kusina Management System</p>
        </div>

        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div id="js-error-box" class="error-box" style="display:none;"></div>

        <form method="POST" action="signup.php" novalidate onsubmit="return validateForm();">
            <div class="field">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($oldFullName) ?>" required placeholder="e.g. Juan Santos">
            </div>

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($oldUsername) ?>" required minlength="3" placeholder="e.g. jsantos">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required minlength="8" placeholder="At least 8 characters">
                    <span class="toggle-eye" onclick="togglePassword('password', this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </span>
                </div>
                <small style="display:block; margin-top:4px; color:#8A7568; font-size:11px;">
                    Must include a number and special character.
                </small>
                <p id="password-error" style="display:none; color:#B33927; font-size:12px; margin:4px 0 0; font-weight:600;"></p>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter password">
                    <span class="toggle-eye" onclick="togglePassword('confirm_password', this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </span>
                </div>
                <p id="confirm-password-error" style="display:none; color:#B33927; font-size:12px; margin:4px 0 0; font-weight:600;"></p>
            </div>

            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="">Select role</option>
                    <option value="cashier" <?= $oldRole === "cashier" ? "selected" : "" ?>>Cashier</option>
                    <option value="manager" <?= $oldRole === "manager" ? "selected" : "" ?>>Manager</option>
                    <option value="admin" <?= $oldRole === "admin" ? "selected" : "" ?>>Admin</option>
                </select>
            </div>

            <button type="submit" class="submit-btn">Create Account &rarr;</button>
        </form>

        <div class="login-footer">
            Already have an account? <a href="login.php">Log in</a>
        </div>
    </div>

    <script>
        const eyeOpen = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;

        const eyeClosed = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.62 21.62 0 0 1 5.06-6.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.62 21.62 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;

        function togglePassword(fieldId, icon) {
            const input = document.getElementById(fieldId);
            if (input.type === "password") {
                input.type = "text";
                icon.innerHTML = eyeClosed;
            } else {
                input.type = "password";
                icon.innerHTML = eyeOpen;
            }
        }

        function validateForm() {
            const fullName = document.getElementById("full_name").value.trim();
            const username = document.getElementById("username").value.trim();
            const password = document.getElementById("password").value;
            const confirmPassword = document.getElementById("confirm_password").value;
            const role = document.getElementById("role").value;
            const errorEl = document.getElementById("password-error");
            const confirmErrorEl = document.getElementById("confirm-password-error");
            const generalErrorEl = document.getElementById("js-error-box");
            let message = "";

            confirmErrorEl.style.display = "none";
            errorEl.style.display = "none";
            generalErrorEl.style.display = "none";

            if (!fullName || !username || !password || !confirmPassword || !role) {
                generalErrorEl.textContent = "Please complete all fields to create your account.";
                generalErrorEl.style.display = "block";
                return false;
            }

            if (password.length < 8) {
                message = "Password must be at least 8 characters long.";
            } else if (!/[0-9]/.test(password)) {
                message = "Password must contain at least one number.";
            } else if (!/[^a-zA-Z0-9]/.test(password)) {
                message = "Password must contain at least one special character.";
            }

            if (message) {
                errorEl.textContent = message;
                errorEl.style.display = "block";
                return false;
            }
            errorEl.style.display = "none";

            if (password !== confirmPassword) {
                confirmErrorEl.textContent = "Passwords do not match.";
                confirmErrorEl.style.display = "block";
                return false;
            }

            return true;
        }
    </script>

</body>
</html>
