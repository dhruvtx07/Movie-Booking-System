<?php
// Database configuration
define('DB_SERVER', 'localhost'); // Or your DB host
define('DB_USERNAME', 'root'); // <<---- ⚠️ UPDATE THIS
define('DB_PASSWORD', ''); // <<---- ⚠️ UPDATE THIS
define('DB_NAME', 'event_mg');       // <<---- ⚠️ UPDATE THIS

// Attempt to connect to MySQL database
$conn = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if($conn === false){
    die("ERROR: Could not connect. " . mysqli_connect_error());
}

// Set charset to utf8mb4 for full emoji and special character support
mysqli_set_charset($conn, "utf8mb4");

// Define base URL for easier linking (optional but good practice)
// Adjust if your app is in a subdirectory
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$script_name = explode('/', $_SERVER['SCRIPT_NAME']);
// Remove the script file name, and if in a subdirectory, keep it.
// This logic might need adjustment based on your exact server setup.
// For a root install like /venue_management_app/, it might be:
array_pop($script_name); // remove the last part (e.g. index.php)
if (end($script_name) === "cities" || end($script_name) === "venues") {
    array_pop($script_name); // remove cities or venues if inside those folders
}
$base_path = implode('/', $script_name);
define('BASE_URL', $protocol . $host . $base_path . '/');


// For pagination
define('RECORDS_PER_PAGE', 5); // Number of venues per page

// For created_by (since no user system, use a default)
define('DEFAULT_USER_ID', 1);
?>