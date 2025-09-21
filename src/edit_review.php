<?php
require_once 'config/db_config.php';
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'links.php';

// Redirect to login if not authenticated (except for auth pages)
$auth_pages = [$login_page, $register_page, $forgot_pass];

if (!isset($_SESSION['user_id'])) {
    if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// Initialize selected city
$_SESSION['selected_city'] = $_SESSION['selected_city'] ?? '';

// Handle city selection (auto-submit)
if (isset($_POST['city'])) {
    $_SESSION['selected_city'] = $_POST['city'];
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : '';

// Fetch cities from database (only if logged in)
$cities = [];
if ($isLoggedIn) {
    try {
        $stmt = $pdo->query("SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name");
        $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch cities: " . $e->getMessage());
        $cities = [
            ['city_id' => 1, 'city_name' => 'Mumbai'],
            ['city_id' => 2, 'city_name' => 'Delhi'],
            ['city_id' => 3, 'city_name' => 'Bangalore']
        ];
    }
}

function getCityNameById($cities, $cityId) {
    foreach ($cities as $city) {
        if ($city['city_id'] == $cityId) {
            return $city['city_name'];
        }
    }
    return 'Unknown City';
}

// Database connection for event rating
$servername = "localhost";
$username_db = "root";
$password_db = "";
$dbname = "event_mg";

try {
    $conn = new mysqli($servername, $username_db, $password_db, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Check if we're editing an existing review
$editingReview = isset($_GET['rating_id']);
$existingReview = null;
$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

if ($editingReview) {
    $rating_id = intval($_GET['rating_id']);
    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    
    $sql = "SELECT * FROM event_ratings WHERE rating_id = $rating_id AND created_by = $user_id";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $existingReview = $result->fetch_assoc();
        $event_id = $existingReview['event_id'];
        
        // Fetch event info again to ensure we have it
        $event_sql = "SELECT * FROM event_info WHERE event_id = $event_id AND is_active = 'yes'";
        $event_result = $conn->query($event_sql);
        
        if ($event_result->num_rows === 0) {
            die("Event not found or no longer available.");
        }
        
        $event = $event_result->fetch_assoc();
    } else {
        die("Review not found or you don't have permission to edit it.");
    }
} else {
    // For new reviews, fetch the event info
    if ($event_id > 0) {
        $event_sql = "SELECT * FROM event_info WHERE event_id = $event_id AND is_active = 'yes'";
        $event_result = $conn->query($event_sql);
        
        if ($event_result->num_rows === 0) {
            die("Event not found or no longer available.");
        }
        
        $event = $event_result->fetch_assoc();
    } else {
        die("Event ID not specified.");
    }
}

$success_message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
    $description = isset($_POST['description']) ? trim($conn->real_escape_string($_POST['description'])) : '';
    $hashtag = isset($_POST['hashtag']) ? trim($conn->real_escape_string($_POST['hashtag'])) : '';
    
    $created_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    
    if ($created_by === 0) {
        $error = "You must be logged in to submit a rating.";
    } else if ($rating < 1 || $rating > 5) {
        $error = "Please select a rating between 1 and 5 stars.";
    } else {
        if ($editingReview && $existingReview) {
            // Update existing review
            $update_sql = "UPDATE event_ratings 
                          SET user_gave = $rating, 
                              rating_desc = " . ($description === '' ? "NULL" : "'$description'") . ", 
                              hastag = " . ($hashtag === '' ? "NULL" : "'$hashtag'") . ", 
                              created_on = NOW()
                          WHERE rating_id = " . $existingReview['rating_id'] . " AND created_by = $created_by";
            
            if ($conn->query($update_sql)) {
                $success_message = "Your review has been updated successfully!";
            } else {
                $error = "Error updating review: " . $conn->error;
            }
        } else {
            // Create new review - only if not editing
            // First check if user already has a review for this event
            $check_sql = "SELECT rating_id FROM event_ratings WHERE event_id = $event_id AND created_by = $created_by";
            $check_result = $conn->query($check_sql);
            
            if ($check_result->num_rows > 0) {
                $error = "You have already submitted a review for this event. You can edit your existing review.";
            } else {
                $insert_sql = "INSERT INTO event_ratings (user_gave, max_rating, rating_desc, hastag, event_id, created_by, created_on, is_active) 
                              VALUES ($rating, 5, " . ($description === '' ? "NULL" : "'$description'") . ", " . ($hashtag === '' ? "NULL" : "'$hashtag'") . ", $event_id, $created_by, NOW(), 'yes')";
                
                if ($conn->query($insert_sql)) {
                    $success_message = "Thank you for your rating!";
                } else {
                    $error = "Error submitting rating: " . $conn->error;
                }
            }
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate <?php echo htmlspecialchars($event['event_name']); ?> - Event Rating</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary-bg: #ffffff;
        --secondary-bg: #f8f9fa;
        --text-color: #141414;
        --accent-red: #e50914;
        --accent-orange: #ff6b35;
        --accent-peach: #ff9e7d;
        --accent-yellow: #f7c548;
        --accent-black: #141414;
        --card-bg: #ffffff;
        --nav-dark: #141414;
        --nav-text: #ffffff;
        --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
        --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
        --border-color: #e0e0e0;
        --input-bg: #ffffff;
        --input-border: #ced4da;
        --success-bg: #d4edda;
        --success-text: #155724;
        --error-bg: #f8d7da;
        --error-text: #721c24;
    }

    [data-bs-theme="dark"] {
        --primary-bg: #121212;
        --secondary-bg: #1e1e1e;
        --text-color: #f8f9fa;
        --accent-red: #e50914;
        --accent-orange: #ff6b35;
        --accent-peach: #ff9e7d;
        --accent-yellow: #f7c548;
        --accent-black: #f8f9fa;
        --card-bg: #1e1e1e;
        --nav-dark: #000000;
        --nav-text: #ffffff;
        --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
        --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
        --border-color: #333;
        --input-bg: #2a2a2a;
        --input-border: #444;
        --success-bg: #1e3a1e;
        --success-text: #a3d9a3;
        --error-bg: #3a1e1e;
        --error-text: #d9a3a3;
    }

    body {
        background-color: var(--primary-bg);
        color: var(--text-color);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding-top: 110px;
        transition: all 0.3s ease;
    }

    /* Top Navigation Bar Styles */
    .top-navbar {
        background-color: var(--nav-dark) !important;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1030;
        transition: all 0.3s ease;
    }

    .profile-pic {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 8px;
    }
    
    .dropdown-toggle::after {
        vertical-align: middle;
    }
    
    .welcome-text {
        font-size: 0.9rem;
        margin-right: 5px;
        color: var(--nav-text);
    }
    
    .logo-img {
        height: 30px;
        width: auto;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
    }
    
    .nav-content-wrapper {
        display: flex;
        width: 100%;
        align-items: center;
    }
    
    .brand-section {
        flex: 0 0 auto;
    }
    
    .search-section {
        flex: 1 1 auto;
        min-width: 0;
        padding: 0 15px;
    }
    
    .right-section {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .city-selector {
        min-width: 150px;
    }
    
    .dropdown-menu {
        z-index: 1050;
        background-color: var(--nav-dark);
        border: 1px solid rgba(255,255,255,0.1);
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    }
    
    .city-dropdown-menu {
        max-height: 400px;
        overflow-y: auto;
        width: 300px;
        padding: 0;
    }
    
    .city-search-container {
        padding: 0.5rem 1rem;
        position: sticky;
        top: 0;
        background-color: var(--nav-dark);
        z-index: 1;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .city-search-input {
        background-color: rgba(255,255,255,0.1);
        color: white;
        border: none;
        border-radius: 4px;
        padding: 0.25rem 0.5rem;
        width: 100%;
    }
    
    .city-search-input:focus {
        outline: none;
        box-shadow: 0 0 0 2px rgba(255,107,53,0.5);
    }
    
    .city-item {
        padding: 0.5rem 1rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .city-item:hover {
        background-color: rgba(255,255,255,0.1);
    }
    
    .dropdown-item {
        color: var(--nav-text);
        transition: all 0.2s ease;
    }
    
    .dropdown-item:hover {
        background: var(--gradient-secondary);
        color: white;
        transform: translateX(5px);
    }
    
    .btn-danger {
        background: var(--gradient-primary);
        border: none;
        box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
        transition: all 0.3s ease;
    }
    
    .btn-danger:hover {
        background: var(--gradient-primary);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(229, 9, 20, 0.4);
    }
    
    .btn-outline-light {
        color: var(--nav-text);
        border-color: var(--nav-text);
        background: transparent;
        transition: all 0.3s ease;
    }
    
    .nav-link {
        color: var(--nav-text);
        transition: all 0.2s;
        position: relative;
        padding: 0.5rem 1rem;
        white-space: nowrap;
    }
    
    .nav-link::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        width: 0;
        height: 2px;
        background: var(--gradient-primary);
        transition: all 0.3s ease;
        transform: translateX(-50%);
    }
    
    .nav-link:hover {
        color: var(--nav-text);
    }
    
    .nav-link:hover::after {
        width: 100%;
    }
    
    .active-nav {
        color: var(--nav-text) !important;
        font-weight: bold;
    }
    
    .active-nav::after {
        width: 100%;
        background: var(--gradient-primary);
    }
    
    .theme-toggle {
        background: none;
        border: none;
        font-size: 1.2rem;
        color: var(--nav-text);
        cursor: pointer;
        transition: all 0.3s ease;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }
    
    .theme-toggle:hover {
        transform: rotate(30deg);
        background: rgba(255,255,255,0.1);
    }

    /* Second Navigation Bar Styles */
    .second-navbar {
        background-color: var(--nav-dark) !important;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        position: fixed;
        top: 54px;
        left: 0;
        right: 0;
        z-index: 1020;
        transition: all 0.3s ease;
    }

    .second-navbar {
        white-space: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }

    .second-navbar::-webkit-scrollbar {
        display: none;
    }

    .second-navbar .navbar-nav {
        display: inline-flex;
        flex-wrap: nowrap;
        flex-direction: row;
    }

    .second-navbar .nav-item {
        flex-shrink: 0;
    }

    .second-navbar .nav-link {
        padding: 0.5rem 0.75rem;
        font-size: 0.9rem;
    }

    .second-navbar .navbar-collapse {
        display: flex !important;
        flex-basis: auto !important;
    }

    /* Search Form Styles */
    .search-form {
        width: 100%;
        max-width: 500px;
        position: relative;
    }
    
    .search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background-color: var(--card-bg);
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 0 0 8px 8px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        z-index: 1000;
        max-height: 400px;
        overflow-y: auto;
        display: none;
    }
    
    .search-result-item {
        padding: 10px 15px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: all 0.2s;
        color: var(--text-color);
        text-decoration: none;
        display: block;
    }
    
    .search-result-item:hover {
        background-color: var(--secondary-bg);
        transform: translateX(5px);
        color: var(--text-color);
    }

    .search-result-item:last-child {
        border-bottom: none;
    }
    
    .search-result-type {
        font-size: 0.8rem;
        color: var(--accent-orange);
        text-transform: capitalize;
        font-weight: 500;
    }
    
    .search-result-name {
        font-weight: 500;
        transition: all 0.2s;
    }
    
    .search-result-item:hover .search-result-name {
        color: var(--accent-orange);
    }

    /* Mobile menu dropdown */
    .mobile-menu-dropdown {
        display: none;
    }
    
    .mobile-menu-toggle {
        display: none;
        background: none;
        border: none;
        color: var(--nav-text);
        font-size: 1.2rem;
        padding: 0.5rem;
        margin-left: 0.5rem;
    }
    
    .mobile-menu-dropdown .dropdown-menu {
        width: 100%;
        background-color: var(--nav-dark);
    }
    
    .mobile-menu-dropdown .dropdown-item {
        padding: 0.75rem 1.5rem;
    }
    
    .mobile-menu-dropdown .dropdown-item i {
        margin-right: 10px;
        width: 20px;
        text-align: center;
    }
    
    .mobile-menu-dropdown .dropdown-divider {
        border-color: rgba(255,255,255,0.1);
    }

    /* Rating Page Specific Styles */
    .rating-container {
        max-width: 800px;
        margin: 20px auto;
        background: var(--card-bg);
        border-radius: 10px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        padding: 30px;
        border: 1px solid var(--border-color);
    }

    .event-info-card {
        background: var(--secondary-bg);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
        border: 1px solid var(--border-color);
    }

    .form-control, .form-select {
        background-color: var(--input-bg);
        color: var(--text-color);
        border: 1px solid var(--input-border);
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--accent-orange);
        box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
    }

    .btn-submit {
        background: var(--accent-red);
        color: white;
        border: none;
        padding: 10px 25px;
        font-size: 1rem;
        font-weight: bold;
        border-radius: 5px;
        transition: all 0.3s ease;
    }

    .btn-submit:hover {
        background: var(--accent-orange);
        transform: translateY(-2px);
        box-shadow: 0 5px 10px rgba(229, 9, 20, 0.3);
    }

    .success-message {
        background: var(--success-bg);
        color: var(--success-text);
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
        border: 1px solid rgba(0,0,0,0.1);
    }

    .alert-danger {
        background: var(--error-bg);
        color: var(--error-text);
        border-color: rgba(0,0,0,0.1);
        margin-bottom: 15px;
    }

    /* Star Rating */
    .star-rating {
        direction: rtl;
        display: inline-flex;
        justify-content: flex-start;
    }

    .star-rating input {
        display: none;
    }

    .star-rating label {
        font-size: 1.8rem;
        color: var(--border-color);
        cursor: pointer;
        transition: all 0.2s;
        margin: 0 5px;
    }

    .star-rating label:before {
        content: '\f005';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
    }

    .star-rating input:checked ~ label {
        color: var(--accent-yellow);
    }

    .star-rating label:hover,
    .star-rating label:hover ~ label {
        color: var(--accent-peach);
    }

    .star-rating input:checked ~ label:hover,
    .star-rating input:checked ~ label:hover ~ label {
        color: var(--accent-orange);
    }

    /* Button container */
    .button-container {
        display: flex;
        gap: 15px;
        margin-top: 25px;
    }

    /* Responsive adjustments */
    @media (max-width: 992px) {
        body {
            padding-top: 56px;
        }
        
        .second-navbar {
            display: none;
        }
        
        .mobile-menu-toggle {
            display: block;
        }
        
        .mobile-menu-dropdown {
            display: block;
        }
        
        .nav-content-wrapper {
            flex-wrap: wrap;
        }
        
        .search-section {
            order: 3;
            flex: 0 0 100%;
            padding: 10px 0;
            margin-top: 10px;
        }
        
        .right-section {
            order: 2;
            margin-left: auto;
        }
        
        .city-selector {
            min-width: 120px;
        }
        
        .rating-container {
            padding: 20px;
        }
    }

    @media (max-width: 768px) {
        .rating-container {
            padding: 15px;
        }
        
        .star-rating label {
            font-size: 1.5rem;
        }
        
        .button-container {
            flex-direction: column;
            gap: 10px;
        }
        
        .btn-submit, .btn-outline-secondary {
            width: 100%;
        }
    }

    @media (max-width: 576px) {
        body {
            padding-top: 56px;
        }
        
        .rating-container {
            margin: 10px auto;
            padding: 15px;
        }
        
        .event-info-card {
            padding: 15px;
        }
        
        h2 {
            font-size: 1.5rem;
        }
        
        h4 {
            font-size: 1.2rem;
        }
    }
</style>
</head>
<body>
    
    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark top-navbar py-2 fixed-top">
        <div class="container">
            <div class="nav-content-wrapper">
                <!-- Logo/Brand -->
                <div class="brand-section">
                    <a class="navbar-brand" href="<?= $home_page ?>">
                        <img src="images/logo.png" alt="Catchify Logo" class="logo-img">
                        <b>Catchify</b>
                    </a>
                </div>

                <!-- Search Bar - Centered (only shown when logged in) -->
                <?php if ($isLoggedIn): ?>
                <div class="search-section">
                    <form class="search-form" method="GET" action="<?= $home_page ?>">
                        <div class="input-group">
                            <input class="form-control" type="search" name="search" id="searchInput" 
                                   placeholder="Search for movies, events, plays..." aria-label="Search"
                                   value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                            <button class="btn btn-danger" type="submit"><i class="fas fa-search"></i></button>
                        </div>
                        <div class="search-results" id="searchResults">
                            <!-- Search results will be populated here via JavaScript -->
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Right Section -->
                <div class="right-section">
                    <!-- Theme Toggle Button -->
                    <button class="theme-toggle" id="themeToggle">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <?php if ($isLoggedIn): ?>
                        <!-- Mobile Menu Dropdown Toggle -->
                        <div class="mobile-menu-dropdown">
                            <button class="mobile-menu-toggle dropdown-toggle" type="button" id="mobileMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-bars"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="mobileMenuDropdown">
                                <li><h6 class="dropdown-header">Menu</h6></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == $movies_page ? 'active' : '' ?>" href="<?= $movies_page ?>"><i class="fas fa-film"></i> Movies</a></li>
                                <li><a class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == $events_page ? 'active' : '' ?>" href="<?= $events_page ?>"><i class="fas fa-calendar-alt"></i> Events</a></li>
                                <li><a class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == $plays_page ? 'active' : '' ?>" href="<?= $plays_page ?>"><i class="fas fa-theater-masks"></i> Plays</a></li>
                                <li><a class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == $sports_page ? 'active' : '' ?>" href="<?= $sports_page ?>"><i class="fas fa-running"></i> Sports</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $list_your_show ?>"><i class="fa fa-plus-square"></i> List Your Show</a></li>
                                <li><a class="dropdown-item" href="<?= $view_promos ?>"><i class="fa fa-ticket"></i> Offers</a></li>
                            </ul>
                        </div>
                        
                        <!-- City Dropdown with Search -->
                        <div class="dropdown city-selector">
                            <button class="btn btn-outline-light dropdown-toggle" type="button" id="cityDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-map-marker-alt me-1"></i>
                                <?= !empty($_SESSION['selected_city']) ? htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) : 'Select City' ?>
                            </button>
                            <ul class="dropdown-menu city-dropdown-menu" aria-labelledby="cityDropdown">
                                <li class="city-search-container">
                                    <input type="text" class="city-search-input" placeholder="Search cities..." id="citySearch">
                                </li>
                                <form method="post" id="cityForm">
                                    <?php foreach ($cities as $city): ?>
                                        <li class="city-item" data-value="<?= $city['city_id'] ?>">
                                            <div class="dropdown-item">
                                                <?= htmlspecialchars($city['city_name']) ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                    <input type="hidden" name="city" id="selectedCity">
                                </form>
                            </ul>
                        </div>
                        
                        <!-- User Profile Dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-outline-light dropdown-toggle d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <?php if (isset($_SESSION['profile_pic']) && !empty($_SESSION['profile_pic'])): ?>
                                    <img src="<?= htmlspecialchars($_SESSION['profile_pic']) ?>" class="profile-pic" alt="Profile">
                                <?php else: ?>
                                    <i class="fas fa-user-circle me-1"></i>
                                <?php endif; ?>
                                <span class="welcome-text">Hi, <?= htmlspecialchars($username) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><h6 class="dropdown-header">Welcome, <?= htmlspecialchars($username) ?></h6></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $my_profile ?>"><i class="fas fa-user me-2"></i> My Profile</a></li>
                                <li><a class="dropdown-item" href="<?= $my_bookings ?>"><i class="fas fa-ticket-alt me-2"></i> My Bookings</a></li>
                                <li><a class="dropdown-item" href="<?= $my_reviews ?>"><i class="fas fa-heart me-2"></i> My Reviews</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $logout_page ?>"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    
    <?php if ($isLoggedIn): ?>
    <!-- Second Navigation Bar - Hidden on mobile -->
    <nav class="navbar navbar-expand-lg navbar-dark second-navbar py-1 fixed-top d-none d-lg-block">
        <div class="container">
            <div class="collapse navbar-collapse" id="secondNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == $movies_page ? 'active-nav' : '' ?>" href="<?= $movies_page ?>">
                            <i class="fas fa-film me-1"></i>
                            <span class="nav-text">Movies</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == $events_page ? 'active-nav' : '' ?>" href="<?= $events_page ?>">
                            <i class="fas fa-calendar-alt me-1"></i>
                            <span class="nav-text">Events</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == $plays_page ? 'active-nav' : '' ?>" href="<?= $plays_page ?>">
                            <i class="fas fa-theater-masks me-1"></i>
                            <span class="nav-text">Plays</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == $sports_page ? 'active-nav' : '' ?>" href="<?= $sports_page ?>">
                            <i class="fas fa-running me-1"></i>
                            <span class="nav-text">Sports</span>
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <a href="<?= $list_your_show  ?>" class="nav-link">
                        <i class="fa fa-plus-square me-1"></i>
                        <span class="nav-text">List Your Show</span>
                    </a>
                    <a href="<?= $view_promos  ?>" class="nav-link">
                        <i class="fa fa-ticket me-1"></i>
                        <span class="nav-text">Promo Codes</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <?php endif; ?>

 
    <!-- Main Content -->
    <div class="container" style="margin-top: 30px;">
        <?php if (!empty($success_message)): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($success_message); ?>
                <div class="mt-2">
                    
                    <a href="javascript:history.go(-2)" class="btn btn-primary btn-sm">Back to Previous Page</a>

                </div>
            </div>
        <?php else: ?>
            <div class="rating-container">
                <h2 class="mb-3">Rate: <?php echo htmlspecialchars($event['event_name']); ?></h2>
                
                <div class="event-info-card mb-3">
                    <div class="row align-items-center">
                        <div class="col-4 col-md-3">
                            <?php if (!empty($event['photo'])): ?>
                                <img src="<?php echo htmlspecialchars($event['photo']); ?>" alt="<?php echo htmlspecialchars($event['event_name']); ?>" class="img-fluid rounded" style="border: 1px solid var(--border-color);">
                            <?php else: ?>
                                <div class="bg-light d-flex justify-content-center align-items-center" style="height: 80px; width: 80px; border: 1px solid var(--border-color); border-radius: 5px;">
                                    <i class="fas fa-film fa-lg text-secondary"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-8 col-md-9">
                            <h4 class="mb-1"><?php echo htmlspecialchars($event['event_name']); ?></h4>
                            <p class="mb-1 small"><strong>Genre:</strong> <?php echo !empty($event['genre']) ? htmlspecialchars($event['genre']) : 'N/A'; ?></p>
                            <p class="mb-1 small"><strong>Language:</strong> <?php echo !empty($event['event_language']) ? htmlspecialchars($event['event_language']) : 'N/A'; ?></p>
                            <p class="mb-0 small"><strong>Duration:</strong> <?php echo !empty($event['duration']) ? htmlspecialchars($event['duration']) : 'N/A'; ?></p>
                        </div>
                    </div>
                </div>
                
                <?php if (isset($error) && $error): ?>
                    <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <!-- Modify the form section to pre-populate data when editing -->
<form method="POST" action="edit_review.php?event_id=<?php echo $event_id; ?><?php echo $editingReview ? '&rating_id='.$existingReview['rating_id'] : ''; ?>">
    <div class="form-group mb-3">
        <label class="form-label"><strong>Your Rating</strong> <span class="text-danger">*</span></label>
        <div class="star-rating">
            <input type="radio" id="star5" name="rating" value="5" <?php echo ($editingReview && $existingReview['user_gave'] == 5) ? 'checked' : ''; ?>><label for="star5" title="5 stars"></label>
            <input type="radio" id="star4" name="rating" value="4" <?php echo ($editingReview && $existingReview['user_gave'] == 4) ? 'checked' : ''; ?>><label for="star4" title="4 stars"></label>
            <input type="radio" id="star3" name="rating" value="3" <?php echo ($editingReview && $existingReview['user_gave'] == 3) ? 'checked' : ''; ?>><label for="star3" title="3 stars"></label>
            <input type="radio" id="star2" name="rating" value="2" <?php echo ($editingReview && $existingReview['user_gave'] == 2) ? 'checked' : ''; ?>><label for="star2" title="2 stars"></label>
            <input type="radio" id="star1" name="rating" value="1" <?php echo ($editingReview && $existingReview['user_gave'] == 1) ? 'checked' : ''; ?> required><label for="star1" title="1 star"></label>
        </div>
        <small class="text-muted">Click a star to select (1-5 stars)</small>
    </div>
    
    <div class="form-group mb-3">
        <label for="description" class="form-label"><strong>Your Review</strong></label>
        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Share your thoughts..."><?php echo $editingReview ? htmlspecialchars($existingReview['rating_desc']) : ''; ?></textarea>
    </div>
    
    <div class="form-group mb-3">
        <label for="hashtag" class="form-label"><strong>Hashtag (optional)</strong></label>
        <input type="text" class="form-control" id="hashtag" name="hashtag" placeholder="#awesome #mustwatch" value="<?php echo $editingReview ? htmlspecialchars($existingReview['hastag']) : ''; ?>">
    </div>
    
    <div class="button-container">
        <button type="submit" class="btn btn-submit"><?php echo $editingReview ? 'Update Review' : 'Submit Rating'; ?></button>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">Cancel</a>
<?php if ($editingReview): ?>
            <a href="<?=$delete_review?>?rating_id=<?php echo $existingReview['rating_id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this review?')">Delete Review</a>
        <?php endif; ?>
    </div>
</form>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
    // Theme toggle functionality
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const htmlElement = document.documentElement;
    
    // Check for saved theme preference or use system preference
    const savedTheme = localStorage.getItem('theme');
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    if (savedTheme) {
        htmlElement.setAttribute('data-bs-theme', savedTheme);
        updateThemeIcon(savedTheme);
    } else if (systemPrefersDark) {
        htmlElement.setAttribute('data-bs-theme', 'dark');
        updateThemeIcon('dark');
    }
    
    themeToggle.addEventListener('click', () => {
        const currentTheme = htmlElement.getAttribute('data-bs-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        htmlElement.setAttribute('data-bs-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeIcon(newTheme);
    });
    
    function updateThemeIcon(theme) {
        if (theme === 'dark') {
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        } else {
            themeIcon.classList.remove('fa-sun');
            themeIcon.classList.add('fa-moon');
        }
    }
    
    // City search functionality
    const citySearch = document.getElementById('citySearch');
    const cityItems = document.querySelectorAll('.city-item');
    
    if (citySearch) {
        citySearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            
            cityItems.forEach(item => {
                const cityName = item.textContent.toLowerCase();
                item.style.display = cityName.includes(searchTerm) ? 'block' : 'none';
            });
        });
    }
    
    // City selection
    cityItems.forEach(item => {
        item.addEventListener('click', function() {
            const cityId = this.getAttribute('data-value');
            document.getElementById('selectedCity').value = cityId;
            document.getElementById('cityForm').submit();
        });
    });
    
    // Search functionality with AJAX
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            if (searchTerm.length >= 2) {
                fetchSearchResults(searchTerm);
            } else {
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
            }
        });
        
        // Hide results when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });
    }
    
    function fetchSearchResults(searchTerm) {
        fetch(`<?=$search?>?search=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                if (data.length > 0) {
                    let html = '';
                    data.forEach(item => {
                        html += `
                            <a href="<?=$event_info_page?>?id=${item.event_id}" class="search-result-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="search-result-name">${item.event_name}</div>
                                    <div class="search-result-type">${item.event_type}</div>
                                </div>
                            </a>
                        `;
                    });
                    searchResults.innerHTML = html;
                    searchResults.style.display = 'block';
                } else {
                    searchResults.innerHTML = '<div class="search-result-item">No results found</div>';
                    searchResults.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error fetching search results:', error);
                searchResults.innerHTML = '<div class="search-result-item">Error loading results</div>';
                searchResults.style.display = 'block';
            });
    }
});
    </script>
</body>
</html>

<?php
$conn->close();
?>