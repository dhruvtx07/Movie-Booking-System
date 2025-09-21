<?php
require_once 'config/db_config.php';
session_start();

require_once 'links.php';
require_once 'functions.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("Location: $login_page");
    exit();
}

// Check if rating_id is provided
if (!isset($_GET['rating_id'])) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'No review specified for deletion'];
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

$rating_id = intval($_GET['rating_id']);
$user_id = $_SESSION['user_id'];

// Database connection
$servername = "localhost";
$username_db = "root";
$password_db = "";
$dbname = "event_mg";

try {
    $conn = new mysqli($servername, $username_db, $password_db, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // First get the event_id for redirect
    $event_sql = "SELECT event_id FROM event_ratings WHERE rating_id = ? AND created_by = ?";
    $stmt = $conn->prepare($event_sql);
    $stmt->bind_param("ii", $rating_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Review not found or you don\'t have permission to delete it'];
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    }
    
    $review = $result->fetch_assoc();
    $event_id = $review['event_id'];
    
    // Delete the review
    $delete_sql = "DELETE FROM event_ratings WHERE rating_id = ? AND created_by = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("ii", $rating_id, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Review deleted successfully'];
    } else {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Error deleting review: ' . $conn->error];
    }
    
    $stmt->close();
    $conn->close();
    
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$history = $_SESSION['history'] ?? [];

// Get one and two pages back (defensive)
$one_back = $history[count($history) - 2] ?? '';
$two_back = $history[count($history) - 3] ?? '';

if (strpos($referer, $booking_summary) !== false) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
} elseif (strpos($referer, $edit_review) !== false && !empty($two_back)) {
    header("Location: " . $two_back);
} else {
    header("Location: $my_reviews"); // fallback
}
    exit();
    
} catch (Exception $e) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $e->getMessage()];
    header("Location: $my_bookings");
    exit();
}
?>