<?php
session_start();
require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/logger.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $conn->prepare("
            SELECT u.user_id, u.username, u.password, u.full_name, u.status, r.role_name
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.username = ?
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user["status"] !== "active") {
                $error = "This account is inactive. Contact an administrator.";
                log_activity($conn, $user["user_id"], "LOGIN_BLOCKED", "Inactive user attempt: " . $username);
            } elseif (password_verify($password, $user["password"])) {
                // Clear any previous session variables & regenerate ID to prevent session fixation/pollution
                $_SESSION = [];
                session_regenerate_id(true);

                $_SESSION["user_id"]   = $user["user_id"];
                $_SESSION["username"]  = $user["username"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["role"]      = $user["role_name"];

                log_activity($conn, $user["user_id"], "USER_LOGIN", "Successful login as " . $user["role_name"]);

                // Role-based routing
                if ($user["role_name"] === "cashier") {
                    header("Location: ../pos/index.php");
                } else {
                    header("Location: ../dashboard/index.php");
                }
                exit;
            } else {
                $error = "Incorrect username or password.";
                log_activity($conn, $user["user_id"], "LOGIN_FAILED", "Incorrect password for user: " . $username);
            }
        } else {
            $error = "Incorrect username or password.";
            log_activity($conn, null, "LOGIN_FAILED", "Unknown username: " . $username);
        }
    }
}

$alreadyLoggedIn = !empty($_SESSION["user_id"]) && !empty($_SESSION["role"]);
$activeUserFullName = $_SESSION["full_name"] ?? "User";
$activeUserRole = $_SESSION["role"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | Capuzan Kusina</title>
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
        max-width: 400px;
        padding: 40px 36px;
        border-radius: 24px;
        box-shadow: 0 16px 40px rgba(41, 24, 16, 0.45);
    }

    .login-header {
        text-align: center;
        margin-bottom: 28px;
    }

    .login-header .logo-circle {
        width: 58px;
        height: 58px;
        margin: 0 auto 12px;
        background: #C96B2C;
        border: 2px solid #D9A441;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #FFFDF8;
        font-size: 22px;
        font-weight: 900;
        letter-spacing: 1px;
        box-shadow: 0 4px 10px rgba(201, 107, 44, 0.35);
    }

    .login-header h1 {
        font-size: 24px;
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
        margin-bottom: 18px;
    }

    .field label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: #33261F;
        margin-bottom: 6px;
    }

    .field input {
        width: 100%;
        padding: 13px 18px;
        border: 1px solid #D4C3B2;
        border-radius: 10px;
        font-size: 14px;
        outline: none;
        background: #F7F0E3;
        color: #33261F;
        font-family: inherit;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }

    .field input::placeholder {
        color: #8A7568;
    }

    .field input:focus {
        background: #FFFDF8;
        border-color: #C96B2C;
        box-shadow: 0 0 0 3px rgba(201, 107, 44, 0.2);
    }

    .password-wrapper {
        position: relative;
    }

    .password-wrapper input {
        padding-right: 42px;
    }

    .toggle-eye {
        position: absolute;
        right: 14px;
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
        padding: 14px;
        margin-top: 8px;
        background: #C96B2C;
        color: #FFFDF8;
        border: none;
        border-radius: 10px;
        font-size: 15px;
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
        padding: 11px 14px;
        border-radius: 8px;
        font-size: 13px;
        margin-bottom: 18px;
        font-weight: 600;
    }

    .login-footer {
        text-align: center;
        margin-top: 22px;
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
            <h1>Capuzan Kusina</h1>
            <p>Filipino Cuisine & Restaurant POS</p>
        </div>

        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($alreadyLoggedIn && !$error): ?>
            <div style="background:#FFF9E6; border:1px solid #E6C587; border-left:4px solid #D9A441; border-radius:10px; padding:12px 14px; margin-bottom:18px; font-size:13px; color:#4A291C;">
                <div style="font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span>⚠️ Active Session Detected</span>
                </div>
                <div style="line-height:1.4; color:#6B574C; margin-bottom:10px;">
                    You are currently logged in as <strong><?= htmlspecialchars($activeUserFullName) ?></strong> (<span style="text-transform:capitalize; font-weight:700; color:#C96B2C;"><?= htmlspecialchars($activeUserRole) ?></span>).
                </div>
                <div style="display:flex; gap:8px; margin-bottom:8px;">
                    <a href="<?= $activeUserRole === 'cashier' ? '../pos/index.php' : '../dashboard/index.php' ?>" style="display:inline-block; padding:7px 12px; background:#C96B2C; color:#FFFDF8; text-decoration:none; border-radius:6px; font-size:12px; font-weight:700;">
                        Go to <?= $activeUserRole === 'cashier' ? 'POS' : 'Dashboard' ?> &rarr;
                    </a>
                    <a href="logout.php" style="display:inline-block; padding:7px 12px; background:#FFFDF8; color:#8A2218; border:1px solid #F3C4BE; text-decoration:none; border-radius:6px; font-size:12px; font-weight:700;">
                        Log Out First
                    </a>
                </div>
                <div style="font-size:11.5px; color:#8A7568; line-height:1.35; border-top:1px dashed #E6D9C8; padding-top:6px; margin-top:6px;">
                    💡 <em>Note: Browsers share cookies across tabs. Logging in here replaces the user in all open tabs. To test multiple roles at the same time, use an <strong>Incognito window</strong> or a <strong>different browser</strong>.</em>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" novalidate>
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= isset($username) ? htmlspecialchars($username) : "" ?>" required autofocus placeholder="Enter your username">
            </div>

            <div class="field">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label for="password" style="margin-bottom:0;">Password</label>
                    <a href="javascript:void(0)" onclick="openForgotModal()" style="font-size:12px; color:#C96B2C; text-decoration:none; font-weight:600;">Forgot Password?</a>
                </div>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required placeholder="Enter your password">
                    <span class="toggle-eye" onclick="togglePassword('password', this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </span>
                </div>
            </div>

            <button type="submit" class="submit-btn">Log In &rarr;</button>
        </form>

        <p style="text-align:center; margin-top:18px; font-size:13px; color:#6B574C;">
            Don't have an account? <a href="signup.php" style="color:#C96B2C; font-weight:700;">Sign up</a>
        </p>

        <div class="login-footer">Capuzan Kusina &copy; <?= date("Y") ?></div>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(51,38,31,0.65); z-index:999; align-items:center; justify-content:center; backdrop-filter:blur(3px);">
        <div style="background:#FFFDF8; border-radius:16px; max-width:380px; width:90%; padding:26px 24px; box-shadow:0 16px 36px rgba(74,41,28,0.3); border:1px solid #E6D9C8; text-align:center;">
            <div style="width:50px; height:50px; border-radius:50%; background:#FEF8E8; color:#D9A441; display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 14px; border:1px solid #D9A441;">🔑</div>
            <h3 style="color:#4A291C; margin-bottom:8px; font-size:1.2rem; font-weight:800;">Password Assistance</h3>
            <p style="color:#6B574C; font-size:13.5px; line-height:1.5; margin-bottom:16px;">
                For restaurant POS security, staff credentials cannot be reset self-service without authorization.
            </p>
            <div style="font-size:13px; color:#4A291C; background:#F7F0E3; padding:12px 14px; border-radius:10px; margin-bottom:20px; text-align:left; border-left:3px solid #C96B2C;">
                <div style="font-weight:700; margin-bottom:4px; font-size:12.5px;">How to reset:</div>
                <ol style="margin:0; padding-left:18px; color:#6B574C; font-size:12px; line-height:1.5;">
                    <li>Contact your on-duty <strong>Store Manager</strong> or <strong>Admin</strong>.</li>
                    <li>They will open <strong>Account Management</strong> and issue a new password for your account.</li>
                </ol>
            </div>
            <button type="button" onclick="closeForgotModal()" style="background:#C96B2C; color:#FFFDF8; border:none; padding:11px 24px; border-radius:8px; font-weight:700; cursor:pointer; font-size:14px; width:100%; transition:background 0.15s ease;">Got It</button>
        </div>
    </div>

    <script>
        const eyeOpen = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;

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

        function openForgotModal() {
            document.getElementById('forgotModal').style.display = 'flex';
        }

        function closeForgotModal() {
            document.getElementById('forgotModal').style.display = 'none';
        }

        // Close when clicking outside the dialog card
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('forgotModal');
            if (e.target === modal) {
                closeForgotModal();
            }
        });
    </script>

</body>
</html>
