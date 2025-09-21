<?php
// config/db_config.php
$host = 'localhost';
$db   = 'event_mg'; // Your database name
$user = 'root';      // Your database username
$pass = '';          // Your database password
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
    // Log the error (do not display in production)
    error_log("Database connection failed: " . $e->getMessage());
    $pdo = null; // Ensure $pdo is null on failure
    // It's checked in schedule_handler.php, so no die() here is fine
}
?>
