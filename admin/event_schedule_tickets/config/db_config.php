<?php
// CRITICAL: No whitespace, newlines, or BOM characters BEFORE this <?php tag

// config/db_config.php

// Define a default user ID for demonstration/testing
// In a real application, this would come from a session or authentication system
if (!defined('DEFAULT_USER_ID')) {
    define('DEFAULT_USER_ID', 1); // Or any other default ID
}

// Define records per page for pagination
if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 9); // Matches default in JS, adjust as needed
}

// Database connection details
$db_host = 'localhost';          // Your DB host
$db_name = 'event_mg'; // !! IMPORTANT: REPLACE WITH YOUR ACTUAL DATABASE NAME !!
$db_user = 'root';     // !! IMPORTANT: REPLACE WITH YOUR ACTUAL DATABASE USERNAME !!
$db_pass = '';         // !! IMPORTANT: REPLACE WITH YOUR ACTUAL DATABASE PASSWORD !!

$pdo = null; // Initialize PDO variable

try {
    // Attempt to connect to the database using PDO
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    // Set PDO error mode to exception for better error handling
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // IMPORTANT: DO NOT ECHO ANYTHING HERE IN A LIVE APPLICATION!
    // This file is required by AJAX handlers, and any output will break JSON responses.
    // Uncommenting the line below for testing ONLY, then remove.
    // echo "Database connection successful!"; // MAKE SURE THIS IS COMMENTED OUT!

} catch (PDOException $e) {
    // Log the error message (e.g., to your server's error log)
    error_log("Database Connection Error in db_config.php: " . $e->getMessage());

    // For AJAX requests, return a JSON error to the client.
    // This is crucial to prevent the "Unexpected token <" error on the frontend if the DB connection fails.
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please try again later. (' . $e->getMessage() . ')' // Temporarily show message for debugging
    ]);
    exit; // Stop script execution on database connection failure
}
