<?php
session_start();
require_once 'config/db_config.php';

require_once 'links.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $baseUrl = dirname($_SERVER['SCRIPT_NAME'], 2); // Goes up 2 levels (from auth/login.php to /)
    header("Location: $home_page");
    exit;


}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_or_username = trim($_POST['email_or_username']);
    $password = $_POST['pwd'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email_or_username, $email_or_username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['pwd'])) {
            $_SESSION['user_id'] = $user['userid'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['is_host'] = $user['is_host'] === 'yes';
            $_SESSION['is_admin'] = $user['is_admin'] === 'yes';
            
            // Regenerate session ID for security
            session_regenerate_id(true);
            
            // Redirect to original page or home
            // Redirect to original page or home
            $baseUrl = dirname($_SERVER['SCRIPT_NAME'], 2); // Goes up 2 levels (from auth/login.php to /)
            header("Location: $home_page");
            exit();
            
        } else {
            $_SESSION['login_error'] = "Invalid credentials";
            header("Location: $home_page");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        $_SESSION['login_error'] = "Database error";
        header("Location: $home_page");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bms-black: #1a1a1a;
            --bms-dark: #2a2a2a;
            --bms-red: #e63946;
            --bms-peach: #ff9a8b;
            --bms-orange: #ff7e33;
            --bms-light: #f8f9fa;
            --bms-gradient: linear-gradient(135deg, var(--bms-red) 0%, var(--bms-orange) 100%);
            
            /* Light mode variables */
            --bg-color: var(--bms-black);
            --container-bg: var(--bms-dark);
            --text-color: white;
            --form-bg: rgba(255, 255, 255, 0.05);
            --form-border: rgba(255, 255, 255, 0.1);
            --placeholder-color: rgba(255, 255, 255, 0.4);
            --divider-color: rgba(255, 255, 255, 0.1);
            --link-color: var(--bms-peach);
            --link-hover: white;
        }
        
        /* Light mode overrides */
        .light-mode {
            --bg-color: #f5f5f5;
            --container-bg: white;
            --text-color: #333;
            --form-bg: rgba(0, 0, 0, 0.05);
            --form-border: rgba(0, 0, 0, 0.1);
            --placeholder-color: rgba(0, 0, 0, 0.4);
            --divider-color: rgba(0, 0, 0, 0.1);
            --link-color: var(--bms-red);
            --link-hover: var(--bms-orange);
        }
        
        body {
            background-color: var(--bg-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            transition: background-color 0.3s ease, color 0.3s ease;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        
        .auth-container {
            max-width: 420px;
            width: 100%;
            margin: 0 auto;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            background: var(--container-bg);
            border: 1px solid var(--form-border);
            transition: all 0.3s ease;
        }
        
        .auth-header {
            background: var(--bms-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
            margin-bottom: 30px;
            text-align: center;
            font-size: 32px;
            letter-spacing: -0.5px;
        }
        
        .form-control {
            padding: 14px 18px;
            border-radius: 6px;
            border: 1px solid var(--form-border);
            margin-bottom: 20px;
            background-color: var(--form-bg);
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        
        .form-control::placeholder {
            color: var(--placeholder-color);
        }
        
        .form-control:focus {
            border-color: var(--bms-peach);
            box-shadow: 0 0 0 0.25rem rgba(230, 57, 70, 0.25);
            background-color: var(--form-bg);
            color: var(--text-color);
        }
        
        .btn-bms {
            background: var(--bms-gradient);
            border: none;
            padding: 14px;
            font-weight: 700;
            border-radius: 6px;
            width: 100%;
            margin-top: 15px;
            color: white;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
        }
        
        .btn-bms:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(230, 57, 70, 0.4);
            color: white;
        }
        
        .auth-links {
            text-align: center;
            margin-top: 25px;
            color: var(--placeholder-color);
            font-size: 14px;
        }
        
        .auth-links a {
            color: var(--link-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .auth-links a:hover {
            color: var(--link-hover);
            text-decoration: none;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 25px 0;
            color: var(--placeholder-color);
        }
        
        .divider::before, .divider::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid var(--divider-color);
        }
        
        .divider::before {
            margin-right: 15px;
        }
        
        .divider::after {
            margin-left: 15px;
        }
        
        .alert {
            border-radius: 6px;
            background-color: rgba(230, 57, 70, 0.2);
            border: 1px solid var(--bms-red);
            color: var(--text-color);
        }
        
        /* Logo styles */
        .logo-container {
            text-align: center;
            margin-bottom: 25px;
            position: relative;
            height: 80px; /* Fixed height */
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .logo {
            max-height: 100%;
            max-width: 100%;
            object-fit: contain;
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .auth-container {
            animation: fadeIn 0.6s ease-out forwards;
        }
        
        /* Theme toggle button */
        .theme-toggle {
            position: absolute;
            top: 0;
            right: 0;
            background: transparent;
            border: none;
            color: var(--text-color);
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .theme-toggle:hover {
            transform: scale(1.1);
        }
        
        .theme-toggle:focus {
            outline: none;
        }
        
        /* Container adjustments */
        .main-container {
            width: 100%;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="auth-container">
            <!-- Logo with theme toggle -->
            <div class="logo-container">
                <img src="images/logo.png" alt="CatchIfy Logo" class="logo">
                <button class="theme-toggle" id="themeToggle">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
            </div>
            
            <h2 class="auth-header">Login to CatchIfy</h2>
            
            <?php if (isset($_SESSION['login_error'])): ?>
                <div class="alert alert-danger"><?= $_SESSION['login_error'] ?></div>
                <?php unset($_SESSION['login_error']); ?>
            <?php endif; ?>
            
            <form method="POST" action="<?= $login_page; ?>">
                <div class="mb-3">
                    <input type="text" class="form-control" id="email_or_username" name="email_or_username" placeholder="Email or Username" required>
                </div>
                <div class="mb-3">
                    <input type="password" class="form-control" id="pwd" name="pwd" placeholder="Password" required>
                </div>

                
                
                <button type="submit" class="btn btn-bms">
                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>
                
                <div class="auth-links mt-4">
                    <div class="mb-2"><a href="<?= $forgot_pass; ?>">Forgot Password?</a></div>
                    <div>New to CatchIfy? <a href="<?= $register_page; ?>">Create Account</a></div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to set theme and store preference
        function setTheme(theme) {
            document.documentElement.className = theme;
            localStorage.setItem('theme', theme);
            
            // Update icon
            const themeIcon = document.getElementById('themeIcon');
            if (theme === 'light-mode') {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
        }
        
        // Function to toggle between themes
        function toggleTheme() {
            if (document.documentElement.classList.contains('light-mode')) {
                setTheme('');
            } else {
                setTheme('light-mode');
            }
        }
        
        // Check for saved theme preference or use system preference
        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            if (savedTheme) {
                setTheme(savedTheme);
            } else if (!prefersDark) {
                setTheme('light-mode');
            }
            
            // Add event listener to theme toggle button
            document.getElementById('themeToggle').addEventListener('click', toggleTheme);
            
            // Watch for system theme changes
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                if (!localStorage.getItem('theme')) {
                    setTheme(e.matches ? '' : 'light-mode');
                }
            });
        });
    </script>
</body>
</html>