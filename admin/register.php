<?php
session_start();
require_once 'links.php'; // Assuming 'links.php' defines $register_page_reditrect and $login_page

// Ensure $register_page_reditrect and $login_page are defined for the frontend to work
// These are placeholders, ensure your links.php defines them correctly, e.g.:
// If links.php doesn't exist or doesn't define them, add these for testing:
if (!isset($register_page_reditrect)) {
    $register_page_reditrect = 'process_register.php';
}
if (!isset($login_page)) {
    $login_page = 'login.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | BookMyShow</title>
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
            
            --bg-color: var(--bms-black);
            --container-bg: var(--bms-dark);
            --text-color: white;
            --form-bg: rgba(255, 255, 255, 0.05); /* Lighter background for inputs in dark mode */
            --form-border: rgba(255, 255, 255, 0.1);
            --placeholder-color: rgba(255, 255, 255, 0.4);
            --divider-color: rgba(255, 255, 255, 0.1);
            --link-color: var(--bms-peach);
            --link-hover: white;
        }
        .light-mode {
            --bg-color: #f5f5f5;
            --container-bg: white;
            --text-color: #333;
            --form-bg: rgba(0, 0, 0, 0.05); /* Lighter background for inputs in light mode */
            --form-border: rgba(0, 0, 0, 0.1); /* Lighter border in light mode */
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
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .auth-container {
            width: 100%;
            max-width: 320px;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            background: var(--container-bg);
            border: 1px solid var(--form-border);
            animation: fadeIn 0.6s ease-out forwards;
            position: fixed; /* Keep fixed to center it initially */
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .auth-header {
            background: var(--bms-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
            margin-bottom: 20px;
            text-align: center;
            font-size: 24px;
        }

        .form-control {
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid var(--form-border);
            margin-bottom: 14px;
            background-color: var(--form-bg); /* Use this for general background */
            color: var(--text-color);
            font-size: 13px;
            width: 100%;
            box-sizing: border-box;
            height: 38px;
            caret-color: var(--text-color); /* Controls the blinking cursor color */
        }

        .form-control::placeholder {
            color: var(--placeholder-color);
        }

        .form-control:focus {
            border-color: var(--bms-peach);
            box-shadow: 0 0 0 0.2rem rgba(230, 57, 70, 0.25);
            /* Ensure background and text color are retained on focus */
            background-color: var(--form-bg); 
            color: var(--text-color);
        }

        /* Fix for Webkit autofill background and text color */
        .form-control:-webkit-autofill,
        .form-control:-webkit-autofill:hover, 
        .form-control:-webkit-autofill:focus, 
        .form-control:-webkit-autofill:active {
            /* This inset shadow covers the browser's default autofill background color */
            -webkit-box-shadow: 0 0 0px 1000px var(--container-bg) inset !important; /* Changed to container-bg */
            box-shadow: 0 0 0px 1000px var(--container-bg) inset !important; /* Changed to container-bg */
            -webkit-text-fill-color: var(--text-color) !important;
            color: var(--text-color) !important;
        }

        .btn-bms {
            background: var(--bms-gradient);
            border: none;
            padding: 10px;
            font-weight: 700;
            border-radius: 6px;
            width: 100%;
            color: white;
            font-size: 13px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 40px;
        }

        .btn-bms:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 57, 70, 0.4);
        }

        .auth-links {
            text-align: center;
            margin-top: 14px;
            font-size: 12px;
            color: var(--placeholder-color);
        }

        .auth-links a {
            color: var(--link-color);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .auth-links a:hover {
            color: var(--link-hover);
        }

        .logo-container {
            text-align: center;
            margin-bottom: 20px;
            position: relative;
            width: 100px;
            height: 60px;
            margin-left: auto;
            margin-right: auto;
        }

        .logo {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
        }

        .theme-toggle {
            position: absolute;
            top: -10px;
            right: -40px;
            background: transparent;
            border: none;
            color: var(--text-color);
            font-size: 1.1rem;
            cursor: pointer;
            padding: 5px;
            /* Added to remove outline on focus/click */
            outline: none; 
            box-shadow: none;
        }

        .theme-toggle:focus { /* Explicitly remove outline */
            outline: none;
            box-shadow: none;
        }

        .popup-overlay {
            display: none;
            position: fixed;
            top: 0; 
            left: 0;
            width: 100%; 
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .popup {
            background: var(--container-bg);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            max-width: 300px;
            width: 90%;
            border: 1px solid var(--form-border);
        }

        .popup h3 {
            background: var(--bms-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
            font-size: 20px;
            margin-bottom: 10px;
        }

        .popup p {
            color: var(--text-color);
            font-size: 13px;
            margin-bottom: 18px;
        }

        .popup-btn {
            background: var(--bms-gradient);
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            color: white;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s ease;
            font-size: 13px;
        }

        .popup-btn:hover {
            transform: translateY(-1px);
        }

        .alert {
            border-radius: 6px;
            background-color: rgba(230, 57, 70, 0.2);
            border: 1px solid var(--bms-red);
            color: var(--text-color); /* Keep text color consistent with theme */
            font-size: 13px;
            padding: 8px;
            margin-bottom: 14px;
            text-align: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translate(-50%, -40%); }
            to { opacity: 1; transform: translate(-50%, -50%); }
        }

        .form-group {
            margin-bottom: 14px;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="logo-container">
            <img src="images/logo.png" alt="Logo" class="logo">
            <button class="theme-toggle" id="themeToggle">
                <i class="fas fa-moon" id="themeIcon"></i>
            </button>
        </div>

        <h2 class="auth-header">Create Account</h2>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <form action="<?= $register_page_reditrect; ?>" method="post">
            <div class="form-group">
                <input type="text" class="form-control" name="name" placeholder="Full Name" required>
            </div>
            <div class="form-group">
                <input type="email" class="form-control" name="email" placeholder="Email" required>
            </div>
            <div class="form-group">
                <input type="password" class="form-control" name="pwd" placeholder="Password" required>
            </div>
            <div class="form-group">
                <input type="password" class="form-control" name="confirm_pwd" placeholder="Confirm Password" required>
            </div>

            <button type="submit" class="btn btn-bms">
                <i class="fas fa-user-plus me-2"></i> Register on Catchify Admin
            </button>

            <div class="auth-links mt-3">
                Already have an account? <a href="<?= $login_page; ?>">Login</a>
            </div>
        </form>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="popup-overlay" id="successPopup" style="display: flex;">
        <div class="popup">
            <h3>Registration Successful!</h3>
            <p>You have successfully registered. Please login to continue.</p>
            <button class="popup-btn" onclick="window.location.href='<?= $login_page; ?>'">Login Now</button>
        </div>
    </div>
    <script>
        // Redirect after 5 seconds
        setTimeout(() => {
            window.location.href = '<?= $login_page; ?>';
        }, 5000);
    </script>
    <?php unset($_SESSION['success']); endif; ?>

    <script>
        function setTheme(theme) {
            document.documentElement.className = theme;
            localStorage.setItem('theme', theme);
            const themeIcon = document.getElementById('themeIcon');
            themeIcon.className = theme === 'light-mode' ? 'fas fa-sun' : 'fas fa-moon';
        }

        function toggleTheme() {
            if (document.documentElement.classList.contains('light-mode')) {
                setTheme(''); // Back to dark mode
            } else {
                setTheme('light-mode');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme');
            // Check for system preference only if no theme is saved
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            if (savedTheme) {
                setTheme(savedTheme);
            } else if (prefersDark) {
                setTheme(''); // Default to dark mode if system prefers dark
            } else {
                setTheme('light-mode'); // Default to light mode if system prefers light or no preference
            }

            document.getElementById('themeToggle').addEventListener('click', toggleTheme);
        });
    </script>
</body>
</html>