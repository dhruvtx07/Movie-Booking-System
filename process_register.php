<?php
session_start();
require_once 'links.php'; // Assuming 'links.php' defines $register_page and $login_page

// Database configuration
$host = 'localhost';
$db   = 'event_mg';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Log the error message instead of throwing it directly in production
    error_log("Database connection error: " . $e->getMessage());
    $_SESSION['error'] = "A database connection error occurred. Please try again later.";
    header("Location: $register_page");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Collect and trim inputs for name, email, and passwords
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $pwd = trim($_POST['pwd']);
    $confirm_pwd = trim($_POST['confirm_pwd']);
    
    // --- Input Validations ---
    
    // Validate empty fields (basic check, could be more robust)
    if (empty($name) || empty($email) || empty($pwd) || empty($confirm_pwd)) {
        $_SESSION['error'] = "All fields are required.";
        header("Location: $register_page");
        exit;
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
        header("Location: $register_page");
        exit;
    }
    
    // Validate password match
    if ($pwd !== $confirm_pwd) {
        $_SESSION['error'] = "Passwords do not match.";
        header("Location: $register_page");
        exit;
    }
    
    // Check password strength
    if (strlen($pwd) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters long.";
        header("Location: $register_page");
        exit;
    }
    
    // --- Database Operations ---

    // Check if email already exists
    try {
        $stmt = $pdo->prepare("SELECT userid FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = "An account with this email already exists.";
            header("Location: $register_page");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Email check failed: " . $e->getMessage());
        $_SESSION['error'] = "Unable to check email availability. Please try again.";
        header("Location: $register_page");
        exit;
    }
    
    // Hash the password
    $hashed_pwd = password_hash($pwd, PASSWORD_DEFAULT);
    
    // Insert new user
    try {
        $stmt = $pdo->prepare("INSERT INTO users (pwd, name, email, is_host, is_admin) 
                               VALUES (?, ?, ?, NULL, NULL)");
        $stmt->execute([$hashed_pwd, $name, $email]);
        
        // --- THIS IS THE NEW LINE FOR AUTOFULLING EMAIL ---
        $_SESSION['registration_email_for_login'] = $email; 
        // ----------------------------------------------------

        $_SESSION['success'] = true;
        header("Location: $register_page"); // This will show the success popup
        exit;
    } catch (PDOException $e) {
        error_log("User registration failed: " . $e->getMessage()); // Log detailed error
        $_SESSION['error'] = "Registration failed. Please try again. (Database error)";
        header("Location: $register_page");
        exit;
    }
} else {
    // If not a POST request, redirect them back to the register page
    header("Location: $register_page");
    exit;
}