<?php
session_start();
require_once 'config/db_config.php';
require_once 'links.php';



$login_message = ''; $message_type = ''; $prefill_email = '';

if (isset($_SESSION['login_success_message'])) {
    $login_message = $_SESSION['login_success_message']; $message_type = 'success';
    unset($_SESSION['login_success_message']);
} elseif (isset($_SESSION['login_error'])) {
    $login_message = $_SESSION['login_error']; $message_type = 'danger';
    unset($_SESSION['login_error']);
}

if (isset($_SESSION['prefill_email_for_login'])) {
    $prefill_email = htmlspecialchars($_SESSION['prefill_email_for_login']);
    unset($_SESSION['prefill_email_for_login']);
} elseif (isset($_SESSION['registration_email_for_login'])) {
    $prefill_email = htmlspecialchars($_SESSION['registration_email_for_login']);
    unset($_SESSION['registration_email_for_login']);
} elseif (isset($_SESSION['password_reset_email'])) {
    $prefill_email = htmlspecialchars($_SESSION['password_reset_email']);
    unset($_SESSION['password_reset_email']);
}

if (isset($_SESSION['user_id'])) {
    header("Location: " . $home_page);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']); $password = $_POST['pwd'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?"); $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (password_verify($password, $user['pwd'])) {
                if ($user['is_host'] === 'yes') {
                    $_SESSION['user_id'] = $user['userid']; $_SESSION['username'] = $user['name']; $_SESSION['email'] = $user['email'];
                    $_SESSION['is_host'] = $user['is_host'] === 'yes'; $_SESSION['is_admin'] = $user['is_admin'] === 'yes';
                    $_SESSION['is_active'] = $user['is_active'] === 'yes';
                    session_regenerate_id(true);
                    header("Location: " . $home_page);
                    exit();
                } else {
                    $_SESSION['login_error'] = "Access denied. Your account is not authorized as a host yet.";
                    $_SESSION['prefill_email_for_login'] = $email;
                    header("Location: " . $login_page . "?error=not_host");
                    exit();
                }
            } else {
                $_SESSION['login_error'] = "Invalid credentials. Please check your password.";
                $_SESSION['prefill_email_for_login'] = $email;
                header("Location: " . $login_page . "?error=invalid");
                exit();
            }
        } else {
            $_SESSION['login_error'] = "Email not found. Please create an account first.";
            header("Location: " . $login_page . "?error=email_not_found");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        $_SESSION['login_error'] = "A database error occurred. Please try again later.";
        $_SESSION['prefill_email_for_login'] = $email;
        header("Location: " . $login_page . "?error=db_error");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root{--bms-black:#1a1a1a;--bms-dark:#2a2a2a;--bms-red:#e63946;--bms-peach:#ff9a8b;--bms-orange:#ff7e33;--bms-light:#f8f9fa;--bms-gradient:linear-gradient(135deg,var(--bms-red) 0%,var(--bms-orange) 100%);--bg-color:var(--bms-black);--container-bg:var(--bms-dark);--text-color:white;--form-bg:rgba(255,255,255,.05);--form-border:rgba(255,255,255,.1);--placeholder-color:rgba(255,255,255,.4);--divider-color:rgba(255,255,255,.1);--link-color:var(--bms-peach);--link-hover:white;}
        .light-mode{--bg-color:#f5f5f5;--container-bg:white;--text-color:#333;--form-bg:rgba(0,0,0,.05);--form-border:rgba(0,0,0,.1);--placeholder-color:rgba(0,0,0,.4);--divider-color:rgba(0,0,0,.1);--link-color:var(--bms-red);--link-hover:var(--bms-orange);}
        body{background-color:var(--bg-color);font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;color:var(--text-color);transition:background-color .3s ease,color .3s ease;min-height:100vh;display:flex;align-items:center;}
        .auth-container{max-width:420px;width:100%;margin:0 auto;padding:40px;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.1);background:var(--container-bg);border:1px solid var(--form-border);transition:all .3s ease;}
        .auth-header{background:var(--bms-gradient);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;font-weight:800;margin-bottom:30px;text-align:center;font-size:32px;letter-spacing:-.5px;}
        .form-control{padding:14px 18px;border-radius:6px;border:1px solid var(--form-border);margin-bottom:20px;background-color:var(--form-bg);color:var(--text-color);transition:all .3s ease;caret-color:var(--text-color);}
        .form-control::placeholder{color:var(--placeholder-color);}
        .form-control:focus{border-color:var(--bms-peach);box-shadow:0 0 0 .25rem rgba(230,57,70,.25);background-color:var(--form-bg);color:var(--text-color);}
        .form-control:-webkit-autofill,.form-control:-webkit-autofill:hover,.form-control:-webkit-autofill:focus,.form-control:-webkit-autofill:active{-webkit-box-shadow:0 0 0px 1000px var(--container-bg) inset!important;box-shadow:0 0 0px 1000px var(--container-bg) inset!important;-webkit-text-fill-color:var(--text-color)!important;color:var(--text-color)!important;}
        .btn-bms{background:var(--bms-gradient);border:none;padding:14px;font-weight:700;border-radius:6px;width:100%;margin-top:15px;color:white;letter-spacing:.5px;text-transform:uppercase;transition:all .3s ease;box-shadow:0 4px 15px rgba(230,57,70,.3);}
        .btn-bms:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(230,57,70,.4);color:white;}
        .auth-links{text-align:center;margin-top:25px;color:var(--placeholder-color);font-size:14px;}
        .auth-links a{color:var(--link-color);text-decoration:none;font-weight:500;transition:color .2s ease;}
        .auth-links a:hover{color:var(--link-hover);text-decoration:none;}
        .divider{display:flex;align-items:center;margin:25px 0;color:var(--placeholder-color);}
        .divider::before,.divider::after{content:"";flex:1;border-bottom:1px solid var(--divider-color);}
        .divider::before{margin-right:15px;}
        .divider::after{margin-left:15px;}
        .alert{border-radius:6px;text-align:center;border:1px solid transparent;}
        .alert-danger{background-color:rgba(230,57,70,.2);border-color:var(--bms-red);color:var(--text-color);}
        .alert-success{background-color:rgba(40,167,69,.2);border-color:#28a745;color:var(--text-color);}
        .logo-container{text-align:center;margin-bottom:25px;position:relative;height:80px;display:flex;justify-content:center;align-items:center;}
        .logo{max-height:100%;max-width:100%;object-fit:contain;}
        @keyframes fadeIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
        .auth-container{animation:fadeIn .6s ease-out forwards;}
        .theme-toggle{position:absolute;top:0;right:0;background:transparent;border:none;color:var(--text-color);font-size:1.2rem;cursor:pointer;transition:all .3s ease;outline:none;box-shadow:none;}
        .theme-toggle:hover{transform:scale(1.1);}
        .theme-toggle:focus{outline:none;box-shadow:none;}
        .main-container{width:100%;padding:20px;}
    </style>
</head>
<body>
    <div class="main-container">
        <div class="auth-container">
            <div class="logo-container">
                <img src="images/logo.png" alt="CatchIfy Logo" class="logo">
                <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon" id="themeIcon"></i></button>
            </div>
            <h2 class="auth-header">Login to CatchIfy Admin</h2>
            <?php if (!empty($login_message)): ?>
                <div class="alert alert-<?php echo $message_type; ?> mb-4"><?php echo htmlspecialchars($login_message); ?></div>
            <?php endif; ?>
            <form method="POST" action="<?= $login_page; ?>">
                <div class="mb-3"><input type="email" class="form-control" id="email" name="email" placeholder="Email Address" required value="<?= $prefill_email ?>"></div>
                <div class="mb-3"><input type="password" class="form-control" id="pwd" name="pwd" placeholder="Password" required></div>
                <button type="submit" class="btn btn-bms"><i class="fas fa-sign-in-alt me-2"></i>Sign In</button>
                <div class="auth-links mt-4">
                    <div class="mb-2"><a href="<?= $forgot_pass; ?>">Forgot Password?</a></div>
                    <div>New to CatchIfy? <a href="<?= $register_page; ?>">Create Account</a></div>
                </div>
            </form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setTheme(t){document.documentElement.className=t;localStorage.setItem('theme',t);const e=document.getElementById('themeIcon');t==='light-mode'?(e.classList.remove('fa-moon'),e.classList.add('fa-sun')):(e.classList.remove('fa-sun'),e.classList.add('fa-moon'))}
        function toggleTheme(){document.documentElement.classList.contains('light-mode')?setTheme(''):setTheme('light-mode')}
        document.addEventListener('DOMContentLoaded',()=>{const t=localStorage.getItem('theme'),e=window.matchMedia('(prefers-color-scheme: dark)').matches;t?setTheme(t):e?setTheme(''):setTheme('light-mode');document.getElementById('themeToggle').addEventListener('click',toggleTheme);window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',t=>{localStorage.getItem('theme')||setTheme(t.matches?'':'light-mode')})});
    </script>
</body>
</html>