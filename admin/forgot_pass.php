<?php
session_start();

// Database configuration and connection
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'event_mg');

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn === false) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = '';
$success = '';
$step = isset($_SESSION['reset_step']) ? $_SESSION['reset_step'] : 1; // 1 = email, 2 = OTP verification, 3 = password reset

// Handle flash messages from redirects (e.g., from a resend OTP request)
if (isset($_SESSION['forgot_pass_error'])) {
    $error = $_SESSION['forgot_pass_error'];
    unset($_SESSION['forgot_pass_error']);
}
if (isset($_SESSION['forgot_pass_success'])) {
    $success = $_SESSION['forgot_pass_success'];
    unset($_SESSION['forgot_pass_success']);
}

// Function to generate OTP
function generateOTP() {
    return rand(100000, 999999);
}

// Function to send OTP email
function sendOTPEmail($email, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();                                       
        $mail->Host       = 'smtp.gmail.com';             
        $mail->SMTPAuth   = true;                              
        $mail->Username   = 'catchifyevents@gmail.com';       // Your Gmail address
        $mail->Password   = 'evem orsj qviu nphz';           // Use App Password here (YOUR APP PASSWORD)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        $mail->Timeout = 10;
        
        // Disable debugging for production
        $mail->SMTPDebug = 0;
        
        // Recipients
        $mail->addAddress($email);
        $mail->setFrom('tdhruv425@gmail.com', 'Catchify: Your online movie booking platform');
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Password Reset OTP';
        $mail->Body    = "
            <h2>Password Reset Request</h2>
            <p>Your OTP for password reset is: <strong>$otp</strong></p>
            <p>This OTP is valid for 10 minutes.</p>
            <p>If you didn't request this, please ignore this email.</p>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error for debugging purposes
        error_log("Mailer Error: " . $mail->ErrorInfo); 
        return false;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['email']) && $step === 1) {
        // Step 1: Email verification
        $email = trim($_POST['email']);
        
        // Validate email
        if (empty($email)) {
            $error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check if email exists in database
            $sql = "SELECT userid FROM users WHERE email = ?";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("s", $email);
                
                if ($stmt->execute()) {
                    $stmt->store_result();
                    
                    if ($stmt->num_rows === 1) {
                        // Generate and store OTP
                        $otp = generateOTP();
                        $_SESSION['reset_otp'] = $otp;
                        $_SESSION['reset_email'] = $email;
                        $_SESSION['otp_expiry'] = time() + 600; // OTP valid for 10 minutes
                        $_SESSION['reset_step'] = 2; // Move to OTP verification step
                        
                        // Send OTP email
                        if (sendOTPEmail($email, $otp)) {
                            $_SESSION['otp_last_sent_time'] = time(); // Record when OTP was successfully sent
                            // Redirect to prevent form resubmission
                            header("Location: forgot_pass.php");
                            exit();
                        } else {
                            $error = "Failed to send OTP. Please try again.";
                            // On email send failure, revert the step
                            unset($_SESSION['reset_step']); 
                        }
                    } else {
                        $error = 'No account found with that email address.';
                    }
                } else {
                    $error = "Oops! Something went wrong. Please try again later.";
                }
                $stmt->close();
            } else {
                $error = "Database error. Please try again later.";
            }
        }
    } elseif (isset($_POST['otp']) && $step === 2) {
        // Step 2: OTP Verification
        $userOTP = trim($_POST['otp']);
        $storedOTP = $_SESSION['reset_otp'] ?? '';
        $expiry = $_SESSION['otp_expiry'] ?? 0;
        
        if (empty($userOTP)) {
            $error = 'Please enter the OTP.';
        } elseif (time() > $expiry) {
            $error = 'OTP has expired. Please request a new one.';
            unset($_SESSION['reset_otp']);
            unset($_SESSION['otp_expiry']);
            unset($_SESSION['otp_last_sent_time']); // Clear sent time so resend is active immediately
            $_SESSION['reset_step'] = 1; // Back to email step
        } elseif ($userOTP != $storedOTP) {
            $error = 'Invalid OTP. Please try again.';
        } else {
            // OTP verified, proceed to password reset
            $_SESSION['reset_step'] = 3;
            unset($_SESSION['reset_otp']);
            unset($_SESSION['otp_expiry']);
            unset($_SESSION['otp_last_sent_time']); // Clear sent time after verification
            
            // Redirect to prevent form resubmission
            header("Location: forgot_pass.php");
            exit();
        }
    } elseif (isset($_POST['new_password']) && isset($_POST['confirm_password']) && $step === 3) {
        // Step 3: Password reset
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        // Validate passwords
        if (empty($newPassword) || empty($confirmPassword)) {
            $error = 'Please enter and confirm your new password.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } else {
            // Update password in database
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET pwd = ? WHERE email = ?";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ss", $hashedPassword, $_SESSION['reset_email']);
                
                if ($stmt->execute()) {
                    // Password reset successful. Prepare for redirect to login.
                    $_SESSION['password_reset_email'] = $_SESSION['reset_email']; // Email to prefill login form
                    $_SESSION['login_success_message'] = 'Your password has been reset successfully! Please login with your new password.'; // Success message for login page
                    
                    // Clear all password reset session variables
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_step']);
                    unset($_SESSION['reset_otp']);
                    unset($_SESSION['otp_expiry']);
                    unset($_SESSION['otp_last_sent_time']);
                    
                    // Redirect to login page
                    header("Location: login.php");
                    exit();
                } else {
                    $error = 'Error updating password. Please try again.';
                }
                $stmt->close();
            } else {
                $error = "Database error. Please try again later.";
            }
        }
    }
}

// Handle OTP resend request (GET request)
if (isset($_GET['resend_otp']) && $step === 2 && isset($_SESSION['reset_email'])) {
    // Implement a cooldown for resend button in PHP for security
    $cooldown_period = 60; // 60 seconds
    $last_sent_time = $_SESSION['otp_last_sent_time'] ?? 0;
    
    if (time() - $last_sent_time < $cooldown_period) {
        $_SESSION['forgot_pass_error'] = 'Please wait before requesting another OTP.';
    } else {
        $otp = generateOTP();
        $_SESSION['reset_otp'] = $otp;
        $_SESSION['otp_expiry'] = time() + 600; // New OTP valid for 10 minutes
        
        if (sendOTPEmail($_SESSION['reset_email'], $otp)) {
            $_SESSION['otp_last_sent_time'] = time(); // Update last sent time
            $_SESSION['forgot_pass_success'] = 'A new OTP has been sent to your email.';
        } else {
            $_SESSION['forgot_pass_error'] = "Failed to resend OTP. Please try again.";
        }
    }
    // Redirect to prevent duplicate requests and clear GET parameter
    header("Location: forgot_pass.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset | CatchIfy Admin </title>
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
            --body-bg-light: #f8f9fa;
            --container-bg-light: #ffffff;
            --text-color-light: #333333;
            --input-bg-light: #ffffff;
            --input-border-light: #ced4da;
            --input-text-light: #495057;
            --placeholder-light: #6c757d;
            --card-shadow-light: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        /* Dark mode variables (default) */
        body.dark-mode {
            --bg-color: var(--bms-black);
            --container-bg: var(--bms-dark);
            --text-color: white;
            --input-bg: rgba(255, 255, 255, 0.05); /* Slightly transparent white for input */
            --input-border: rgba(255, 255, 255, 0.1);
            --input-text: white;
            --placeholder: rgba(255, 255, 255, 0.4);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            --back-link-color: var(--bms-peach);
            --form-border: rgba(255, 255, 255, 0.1);
            --divider-color: rgba(255, 255, 255, 0.1);
            --loader-color: var(--bms-peach);
        }
        
        /* Light mode variables */
        body.light-mode {
            --bg-color: var(--body-bg-light);
            --container-bg: var(--container-bg-light);
            --text-color: var(--text-color-light);
            --input-bg: var(--input-bg-light);
            --input-border: var(--input-border-light);
            --input-text: var(--input-text-light);
            --placeholder: var(--placeholder-light);
            --card-shadow: var(--card-shadow-light);
            --back-link-color: var(--bms-red);
            --form-border: rgba(0, 0, 0, 0.1);
            --divider-color: rgba(0, 0, 0, 0.1);
            --loader-color: var(--bms-red);
        }
        
        body {
            background-color: var(--bg-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .password-container {
            max-width: 500px;
            width: 100%;
            margin: 0 auto;
            padding: 40px;
            background: var(--container-bg);
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--form-border);
            animation: fadeIn 0.6s ease-out forwards;
            transition: all 0.3s ease;
            position: relative;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .logo-img {
            max-width: 120px; /* Use max-width for better responsiveness */
            height: auto; /* Maintain aspect ratio */
            object-fit: contain;
            margin-bottom: 25px;
        }
        
        .btn-bms {
            background: var(--bms-gradient);
            color: white;
            border: none;
            padding: 12px;
            font-weight: 700;
            border-radius: 6px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
        }
        
        .btn-bms:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(230, 57, 70, 0.4);
            color: white; /* Keep text white on hover */
        }
        
        .form-control {
            padding: 14px 18px;
            border-radius: 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--input-text);
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--bms-peach);
            box-shadow: 0 0 0 0.25rem rgba(230, 57, 70, 0.25);
            background-color: var(--input-bg); /* Maintain background on focus */
            color: var(--input-text); /* Maintain text color on focus */
        }
        
        .form-control::placeholder {
            color: var(--placeholder);
        }

        /* ------------------------------------------- */
        /* FIX FOR WEBKIT AUTOFILL BACKGROUND CHANGE */
        /* ------------------------------------------- */
        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus, 
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0px 1000px var(--input-bg) inset !important;
            -webkit-text-fill-color: var(--input-text) !important;
            box-shadow: 0 0 0px 1000px var(--input-bg) inset !important; /* Non-webkit browsers */
            transition: background-color 5000s ease-in-out 0s; /* Prevents visual flicker */
        }
        /* ------------------------------------------- */
        
        .input-group-text {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--bms-peach);
            transition: all 0.3s ease;
        }
        
        .back-to-login {
            color: var(--back-link-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .back-to-login:hover {
            color: var(--bms-red);
            text-decoration: none;
        }
        
        .alert {
            border-radius: 6px;
            border: none;
            transition: all 0.3s ease;
            text-align: center; /* Center align alerts too */
        }
        
        .alert-danger {
            background-color: rgba(230, 57, 70, 0.2);
            border: 1px solid var(--bms-red);
            color: var(--text-color); /* Maintain theme text color */
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.2);
            border: 1px solid #28a745;
            color: var(--text-color); /* Maintain theme text color */
        }
        
        h2 {
            background: var(--bms-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
            margin-bottom: 25px;
            text-align: center;
        }
        
        .toggle-password {
            color: var(--bms-peach);
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            transition: all 0.2s ease;
            cursor: pointer; /* Indicate it's clickable */
        }
        
        .toggle-password:hover {
            background-color: var(--input-bg); /* Keep transparent on hover */
            color: var(--bms-red);
        }
        
        .success-icon {
            font-size: 3rem;
            /* Ensure the icon itself is colored by the gradient, not just the text */
            background: var(--bms-gradient); 
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 15px;
        }
        
        .theme-toggle {
            position: absolute;
            top: 20px;
            right: 20px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            outline: none; /* remove outline on focus */
        }
        
        .theme-toggle:hover {
            background-color: var(--input-bg); /* Keep background on hover */
            transform: scale(1.1);
        }
        
        .theme-toggle i {
            color: var(--bms-peach);
            font-size: 1.2rem;
            transition: color 0.3s ease; /* smooth color transition on theme change */
        }
        
        .text-muted {
            color: var(--placeholder) !important;
        }
        
        /* Logo container */
        .logo-container {
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }
        
        /* Form highlight */
        .password-container {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            border: 1px solid var(--form-border);
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: var(--placeholder);
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
        
        .otp-input {
            letter-spacing: 1.5rem; /* Increased letter spacing for better visual */
            font-size: 1.5rem;
            text-align: center;
            font-weight: bold;
        }
        
        .resend-otp {
            cursor: pointer;
            color: var(--bms-peach);
            text-decoration: underline;
            transition: color 0.2s ease;
        }
        
        .resend-otp:hover {
            color: var(--bms-red);
        }
        
        /* Loader styles */
        .loader-container {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            text-align: center;
        }
        
        .loader-container.active {
            opacity: 1;
            visibility: visible;
        }
        
        .loader {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--loader-color);
            animation: spin 1s ease-in-out infinite;
            margin: 0 auto; /* Center the loader itself */
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loader-text {
            color: white;
            margin-top: 15px;
            font-weight: 500;
            font-size: 1.1rem;
        }
        
        /* Disabled state for buttons */
        .btn-disabled {
            opacity: 0.7;
            pointer-events: none;
        }
        
        /* Countdown timer */
        .countdown-text {
            color: var(--bms-peach);
            font-weight: bold;
        }
        
        /* Responsive adjustments */
        @media (max-width: 576px) {
            .password-container {
                padding: 25px;
                margin: 20px;
            }
            .logo-img {
                margin-bottom: 20px;
            }
            h2 {
                font-size: 1.7rem;
                margin-bottom: 20px;
            }
            .btn-bms {
                padding: 10px;
                font-size: 0.9rem;
            }
            .form-control {
                padding: 12px 15px;
            }
            .otp-input {
                letter-spacing: 1rem;
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body class="dark-mode">
    <!-- Loader container -->
    <div class="loader-container" id="loader">
        <div class="text-center">
            <div class="loader"></div>
            <div class="loader-text">Processing your request...</div> <!-- Default text, will be updated by JS -->
        </div>
    </div>
    
    <div class="container">
        <div class="password-container">
            <!-- Theme toggle inside the form -->
            <button class="theme-toggle" id="themeToggle" title="Toggle theme">
                <i class="fas fa-moon" id="themeIcon"></i>
            </button>
            
            <div class="logo-container">
                <img src="images/logo.png" alt="CatchIfy Logo" class="logo-img">
            </div>
            
            <?php // Only show generic title for steps where forms are actively used ?>
            <?php if ($step !== 4): ?> 
                <h2>Catchify Password Reset</h2>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php // This block is for general success messages (e.g., successful OTP resend)
                  // The final password reset success is now handled by redirecting to login.php.
            ?>
            <?php if (!empty($success)): ?>
                <div class="alert alert-success mt-3 mb-4">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($step === 1): ?>
                <!-- Step 1: Email Verification -->
                <form method="POST" action="forgot_pass.php" id="emailForm">
                    <div class="mb-4">
                        <label for="email" class="form-label mb-2">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" placeholder="Enter your registered email" required>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 mb-4">
                        <button type="submit" class="btn btn-bms btn-lg" id="submitBtn">
                            <i class="fas fa-arrow-right me-2"></i>Continue
                        </button>
                    </div>
                </form>
                
            <?php elseif ($step === 2): ?>
                <!-- Step 2: OTP Verification -->
                <form method="POST" action="forgot_pass.php" id="otpForm">
                    <div class="mb-4 text-center">
                        <p>We've sent a 6-digit OTP to your email <strong><?php echo htmlspecialchars($_SESSION['reset_email'] ?? 'your email'); ?></strong></p>
                        <p>Please check your inbox and enter the OTP below.</p>
                    </div>
                    
                    <div class="mb-4">
                        <label for="otp" class="form-label mb-2">Enter OTP</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-shield-alt"></i></span>
                            <input type="text" class="form-control otp-input" id="otp" name="otp" placeholder="XXXXXX" maxlength="6" required pattern="\d{6}" title="Please enter a 6-digit OTP">
                        </div>
                        <div class="text-end mt-2">
                            <span class="text-muted">Didn't receive OTP? </span>
                            <a href="forgot_pass.php?resend_otp=1" class="resend-otp" id="resendOtp">Resend OTP</a>
                            <span id="resendCountdown" class="countdown-text"></span>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 mb-4">
                        <button type="submit" class="btn btn-bms btn-lg" id="verifyBtn">
                            <i class="fas fa-check-circle me-2"></i>Verify OTP
                        </button>
                    </div>
                </form>
                
            <?php elseif ($step === 3): ?>
                <!-- Step 3: Password Reset -->
                <form method="POST" action="forgot_pass.php" id="passwordForm">
                    <div class="mb-3">
                        <label for="new_password" class="form-label mb-2">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password (min 8 characters)" required minlength="8">
                            <button class="btn btn-outline-secondary toggle-password" type="button" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label mb-2">Confirm Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required minlength="8">
                            <button class="btn btn-outline-secondary toggle-password" type="button" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 mb-4">
                        <button type="submit" class="btn btn-bms btn-lg" id="resetBtn">
                            <i class="fas fa-sync-alt me-2"></i>Reset Password
                        </button>
                    </div>
                </form>
            <?php endif; ?>
            
            <?php // Only show back to login if not on the final success screen (which redirects) ?>
            <?php if ($step !== 4): ?>
                <div class="text-center mt-3">
                    <a href="login.php" class="back-to-login">
                        <i class="fas fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to set theme
            function setTheme(theme) {
                document.body.className = theme;
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
            
            // Check for saved theme preference or use system preference
            const savedTheme = localStorage.getItem('theme');
            const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            if (savedTheme) {
                setTheme(savedTheme);
            } else if (!systemPrefersDark) {
                setTheme('light-mode');
            } else {
                setTheme('dark-mode'); // Explicitly set dark mode if no preference and system prefers dark
            }
            
            // Toggle password visibility
            document.querySelectorAll('.toggle-password').forEach(button => {
                button.addEventListener('click', function() {
                    const input = this.parentElement.querySelector('input');
                    const icon = this.querySelector('i');
                    
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });
            
            // Theme toggle button
            document.getElementById('themeToggle').addEventListener('click', function() {
                const currentTheme = document.body.classList.contains('dark-mode') ? 'dark-mode' : 'light-mode';
                const newTheme = currentTheme === 'dark-mode' ? 'light-mode' : 'dark-mode';
                setTheme(newTheme);
            });
            
            // Listen for system theme changes (if no preference is set by user)
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                // Only react to system changes if user hasn't explicitly set a theme
                if (!localStorage.getItem('theme')) {
                    setTheme(e.matches ? 'dark-mode' : 'light-mode');
                }
            });
            
            // OTP input formatting (enforce numbers only)
            const otpInput = document.getElementById('otp');
            if (otpInput) {
                otpInput.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
            
            // Countdown timer for OTP resend
            const resendOtpBtn = document.getElementById('resendOtp');
            const resendCountdown = document.getElementById('resendCountdown');
            const RESEND_COOLDOWN_SECONDS = 60; // 60 seconds for resend cooldown

            if (resendOtpBtn && resendCountdown) {
                let timerInterval;

                // Function to start or resume the countdown
                function startResendCountdown(initialSeconds) {
                    let secondsLeft = initialSeconds;
                    resendOtpBtn.classList.add('btn-disabled'); // Disable resend button
                    resendOtpBtn.style.pointerEvents = 'none'; // Further disable pointer events
                    resendCountdown.textContent = `(${secondsLeft}s)`;

                    clearInterval(timerInterval); // Clear any existing timer
                    timerInterval = setInterval(() => {
                        secondsLeft--;
                        if (secondsLeft > 0) {
                            resendCountdown.textContent = `(${secondsLeft}s)`;
                        } else {
                            clearInterval(timerInterval);
                            resendCountdown.textContent = '';
                            resendOtpBtn.classList.remove('btn-disabled'); // Enable resend button
                            resendOtpBtn.style.pointerEvents = 'auto'; // Re-enable pointer events
                        }
                    }, 1000);
                }

                // Get last sent time from PHP for initial load
                const otpLastSentTime = <?php echo json_encode($_SESSION['otp_last_sent_time'] ?? 0); ?>;
                const currentTime = Math.floor(Date.now() / 1000);

                // If OTP was sent and we are in step 2 (OTP verification step)
                if (<?php echo $step; ?> === 2 && otpLastSentTime > 0) {
                    const timeElapsed = currentTime - otpLastSentTime;
                    const remainingCooldown = RESEND_COOLDOWN_SECONDS - timeElapsed;

                    if (remainingCooldown > 0) {
                        startResendCountdown(remainingCooldown);
                    } else {
                        // Cooldown has passed, enable resend button
                        resendOtpBtn.classList.remove('btn-disabled');
                        resendOtpBtn.style.pointerEvents = 'auto';
                    }
                } else {
                    // Not in step 2 or no OTP sent yet, enable resend button
                    resendOtpBtn.classList.remove('btn-disabled');
                    resendOtpBtn.style.pointerEvents = 'auto';
                }
                
                // Show loader when resending OTP
                resendOtpBtn.addEventListener('click', function(event) {
                    // Only prevent default and show loader if button is not disabled by cooldown
                    if (!this.classList.contains('btn-disabled')) {
                        event.preventDefault(); 
                        document.getElementById('loader').classList.add('active');
                        document.querySelector('#loader .loader-text').textContent = 'Sending new OTP...';
                        window.location.href = this.href; // Proceed with the GET request
                    }
                });
            }
            
            // Form submission handlers to show loader
            const forms = ['emailForm', 'otpForm', 'passwordForm'];
            forms.forEach(formId => {
                const form = document.getElementById(formId);
                if (form) {
                    form.addEventListener('submit', function() {
                        const loader = document.getElementById('loader');
                        if (loader) {
                            loader.classList.add('active');
                            
                            // Update loader text based on which form is submitting
                            const loaderText = loader.querySelector('.loader-text');
                            if (formId === 'emailForm') {
                                loaderText.textContent = 'Checking email and sending OTP...';
                            } else if (formId === 'otpForm') {
                                loaderText.textContent = 'Verifying OTP...';
                            } else if (formId === 'passwordForm') {
                                loaderText.textContent = 'Updating password...';
                            }
                        }
                    });
                }
            });
            
            // Hide loader when page finishes loading (in case it was left active)
            window.addEventListener('load', function() {
                const loader = document.getElementById('loader');
                if (loader) {
                    loader.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>