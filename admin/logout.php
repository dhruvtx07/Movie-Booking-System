<?php
require 'config/db_config.php';

require_once 'links.php';


// Start the session if it's not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_unset();
session_destroy();

// Redirect to login page
header("Location: $login_page");
exit();
?>