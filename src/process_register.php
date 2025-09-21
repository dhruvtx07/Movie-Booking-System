<?php
session_start();
require_once 'links.php';

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
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate inputs
    $username = trim($_POST['username']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $pwd = trim($_POST['pwd']);
    $confirm_pwd = trim($_POST['confirm_pwd']);
    
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
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
        header("Location: $register_page");
        exit;
    }
    
    // Validate phone number (basic validation)
    if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
        $_SESSION['error'] = "Invalid phone number format.";
        header("Location: $register_page");
        exit;
    }
    
    // Check if username or email already exists
    $stmt = $pdo->prepare("SELECT userid FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->rowCount() > 0) {
        $_SESSION['error'] = "Username or email already exists.";
        header("Location: $register_page");
        exit;
    }
    
    // Hash the password
    $hashed_pwd = password_hash($pwd, PASSWORD_DEFAULT);
    
    // Insert new user
    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, pwd, name, email, phone, is_host, is_admin) 
                              VALUES (?, ?, ?, ?, ?, NULL, NULL)");
        $stmt->execute([$username, $hashed_pwd, $name, $email, $phone]);
        
        $_SESSION['success'] = true;
        header("Location: $register_page");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Registration failed. Please try again.";
        header("Location: $register_page");
        exit;
    }
} else {
    header("Location: $register_page");
    exit;
}