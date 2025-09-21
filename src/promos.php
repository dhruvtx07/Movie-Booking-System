<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

// Database configuration
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Connect to database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Pagination variables
$promosPerPage = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $promosPerPage;

// Get total number of promo codes
$totalPromosStmt = $pdo->prepare("SELECT COUNT(*) FROM promo_codes WHERE is_active = 'yes'");
$totalPromosStmt->execute();
$totalPromos = $totalPromosStmt->fetchColumn();

// Get paginated promo codes
$promosStmt = $pdo->prepare("
    SELECT 
        *
    FROM 
        promo_codes
    WHERE is_active = 'yes'
    ORDER BY created_on DESC
    LIMIT ? OFFSET ?
");
$promosStmt->bindValue(1, $promosPerPage, PDO::PARAM_INT);
$promosStmt->bindValue(2, $offset, PDO::PARAM_INT);
$promosStmt->execute();

$promos = $promosStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch cities from database
$cities = [];
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

function getCityNameById($cities, $cityId) {
    foreach ($cities as $city) {
        if ($city['city_id'] == $cityId) {
            return $city['city_name'];
        }
    }
    return 'Unknown City';
}
?>






<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reviews - Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-bg: #ffffff;
            --secondary-bg: #f8f9fa;
            --text-color: #141414;
            --accent-red: #e50914;
            --accent-orange: #ff6b35;
            --accent-yellow: #ffc107;
            --accent-peach: #ff9e7d;
            --accent-black: #141414;
            --card-bg: #ffffff;
            --nav-dark: #141414;
            --nav-text: #ffffff;
            --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
            --border-color: rgba(0,0,0,0.1);
        }

        [data-bs-theme="dark"] {
            --primary-bg: #121212;
            --secondary-bg: #1e1e1e;
            --text-color: #f8f9fa;
            --accent-red: #e50914;
            --accent-orange: #ff6b35;
            --accent-yellow: #ffc107;
            --accent-peach: #ff9e7d;
            --accent-black: #f8f9fa;
            --card-bg: #1e1e1e;
            --nav-dark: #000000;
            --nav-text: #ffffff;
            --border-color: rgba(255,255,255,0.1);
        }

        body {
            padding-top: 56px;
            background-color: var(--primary-bg);
            color: var(--text-color);
            transition: all 0.3s ease;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .promo-card {
            transition: all 0.3s ease;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            height: 100%;
            background-color: var(--card-bg);
        }

        .promo-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .badge-active {
            background-color: #28a745;
        }

        .badge-inactive {
            background-color: #dc3545;
        }

        @media (max-width: 768px) {
            body {
                padding-top: 110px;
            }
        }


        :root {
    --primary-bg: #ffffff;
    --secondary-bg: #f8f9fa;
    --text-color: #141414;
    --accent-red: #e50914;
    --accent-orange: #ff6b35;
    --accent-yellow: #ffc107;
    --accent-peach: #ff9e7d;
    --accent-black: #141414;
    --card-bg: #ffffff;
    --nav-dark: #141414;
    --nav-text: #ffffff;
    --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
    --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
    --border-color: rgba(0,0,0,0.1);
}

[data-bs-theme="dark"] {
    --primary-bg: #121212;
    --secondary-bg: #1e1e1e;
    --text-color: #f8f9fa;
    --accent-red: #e50914;
    --accent-orange: #ff6b35;
    --accent-yellow: #ffc107;
    --accent-peach: #ff9e7d;
    --accent-black: #f8f9fa;
    --card-bg: #1e1e1e;
    --nav-dark: #000000;
    --nav-text: #ffffff;
    --border-color: rgba(255,255,255,0.1);
}

body {
    padding-top: 56px;
    background-color: var(--primary-bg);
    color: var(--text-color);
    transition: all 0.3s ease;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* Navbar styles */
.top-navbar {
    background-color: var(--nav-dark) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1030;
    height: 56px;
}

.second-navbar {
    background-color: var(--nav-dark) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    position: fixed;
    top: 56px;
    left: 0;
    right: 0;
    z-index: 1020;
    white-space: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    height: 54px;
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

/* Profile and dropdown styles */
.profile-pic {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 8px;
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

.dropdown-menu {
    z-index: 1050;
    background-color: var(--nav-dark);
    border: 1px solid var(--border-color);
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
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

/* Button styles */
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

.btn-outline-light:hover {
    color: var(--nav-dark);
    background-color: var(--nav-text);
}

/* Nav link styles */
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

/* Search styles */
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
    border: 1px solid var(--border-color);
    border-radius: 0 0 8px 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    z-index: 1000;
    max-height: 400px;
    overflow-y: auto;
    display: none;
}

.search-result-item {
    padding: 10px 15px;
    border-bottom: 1px solid var(--border-color);
    transition: all 0.2s;
    color: var(--text-color);
    text-decoration: none;
    display: block;
}

.search-result-item:hover {
    background-color: var(--secondary-bg);
    transform: translateX(5px);
}

.search-result-type {
    font-size: 0.8rem;
    color: var(--accent-orange);
    text-transform: capitalize;
    font-weight: 500;
}

/* Nav content wrapper */
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

/* Theme toggle */
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

/* Mobile menu */
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

/* Alert styles */
.alert {
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.alert-info {
    background: var(--gradient-secondary);
    color: white;
    border: none;
}

.alert-warning {
    background: var(--gradient-primary);
    color: white;
    border: none;
}

/* Review cards */
.review-card {
    transition: all 0.3s ease;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: 1px solid var(--border-color);
    height: 100%;
    background-color: var(--card-bg);
}

.review-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.card-body {
    padding: 20px;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.star-filled {
    color: var(--accent-orange);
}

.star-empty {
    color: #ddd;
}

.review-actions {
    margin-top: auto;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.section-title {
    font-size: 1.2rem;
    color: var(--accent-orange);
    margin-bottom: 15px;
    padding-bottom: 5px;
    border-bottom: 2px solid var(--border-color);
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.rating-stars {
    color: var(--accent-orange);
    font-size: 24px;
    cursor: pointer;
}

.rating-stars i {
    margin-right: 5px;
}

.total-reviews {
    font-size: 1rem;
    color: var(--text-color);
    background-color: var(--secondary-bg);
    padding: 5px 10px;
    border-radius: 20px;
}

.edit-review-container {
    max-width: 800px;
    margin: 0 auto;
}

.review-container {
    background-color: var(--card-bg);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.review-container:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border-color: var(--accent-red);
}

/* Gradient buttons */
.btn-gradient-primary {
    background: var(--gradient-primary);
    border: none;
    color: white;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
}

.btn-gradient-primary:hover {
    background: var(--gradient-primary);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(229, 9, 20, 0.4);
    color: white;
}

.btn-gradient-secondary {
    background: var(--gradient-secondary);
    border: none;
    color: white;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(255, 107, 53, 0.3);
}

.btn-gradient-secondary:hover {
    background: var(--gradient-secondary);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(255, 107, 53, 0.4);
    color: white;
}

/* City dropdown */
.city-selector .dropdown-menu {
    max-height: 300px;
    overflow-y: auto;
}

.city-search-container {
    padding: 8px 12px;
}

.city-search-input {
    width: 100%;
    padding: 4px 8px;
    border-radius: 4px;
    border: 1px solid var(--border-color);
    background-color: var(--secondary-bg);
    color: var(--text-color);
}

.city-item {
    cursor: pointer;
}

.city-item:hover {
    background-color: var(--secondary-bg);
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
    
    .review-actions {
        flex-direction: column;
        gap: 5px;
    }
    
    .review-actions .btn {
        width: 100%;
    }
    
    .col-md-6 {
        margin-bottom: 15px;
    }
}

@media (max-width: 768px) {
    body {
        padding-top: 56px;
    }
    
    .review-container, .review-card {
        padding: 15px;
    }
    
    .section-title {
        font-size: 1.1rem;
    }
}

@media (max-width: 576px) {
    body {
        padding-top: 56px;
    }
    
    .review-container, .review-card {
        padding: 12px;
    }
    
    .rating-stars {
        font-size: 20px;
    }
}

/* Animation for alerts */
.fade-out {
    animation: fadeOut 1s ease-out forwards;
}

@keyframes fadeOut {
    0% { opacity: 1; }
    100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }
}

@media (max-width: 992px) {
    .top-navbar {
        height: auto;
        padding-bottom: 0;
    }
    
    .second-navbar {
        background-color: var(--nav-dark) !important;
        position: relative;
        top: 0;
        display: block !important;
        height: auto;
        overflow: visible;
    }
    
    .second-navbar .navbar-nav {
        display: flex !important;
        flex-wrap: wrap;
        padding: 0.5rem 0;
    }
    
    .second-navbar .nav-item {
        flex: 1 0 auto;
        text-align: center;
    }
    .second-navbar{
        display: none;
    }
    
    body {
        padding-top: 70px;
    }
    
    .container.py-5.mt-5 {
        margin-top: 1rem !important;
        padding-top: 1rem;
    }
}

@media (max-width: 768px) {
    .top-navbar {
        height: auto;
        padding-bottom: 0;
    }
    
    .second-navbar {
        background-color: var(--nav-dark) !important;
        position: relative;
        top: 0;
        display: block !important;
        height: auto;
        overflow: visible;
    }
    
    .second-navbar .navbar-nav {
        display: flex !important;
        flex-wrap: wrap;
        padding: 0.5rem 0;
    }
    
    .second-navbar .nav-item {
        flex: 1 0 auto;
        text-align: center;
    }
    .second-navbar{
        display: none;
    }
    
    body {
        padding-top: 40px;
    }
    
    .container.py-5.mt-5 {
        margin-top: 1rem !important;
        padding-top: 1rem;
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
                    <a class="navbar-brand" href="home2.php">
                        <img src="images/logo.png" alt="Catchify Logo" class="logo-img">
                        <b>Catchify</b>
                    </a>
                </div>

                <!-- Search Bar - Centered -->
                <div class="search-section">
                    <form class="search-form" method="GET" action="home2.php">
                        <div class="input-group">
                            <input class="form-control" type="search" name="search" id="searchInput" 
                                   placeholder="Search for movies, events, plays..." aria-label="Search">
                            <button class="btn btn-danger" type="submit"><i class="fas fa-search"></i></button>
                        </div>
                        <div class="search-results" id="searchResults">
                            <!-- Search results will be populated here via JavaScript -->
                        </div>
                    </form>
                </div>
                
                <!-- Right Section -->
                <div class="right-section">
                    <!-- Theme Toggle Button -->
                    <button class="theme-toggle" id="themeToggle">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <!-- Mobile Menu Dropdown Toggle -->
                    <div class="mobile-menu-dropdown">
                        <button class="mobile-menu-toggle dropdown-toggle" type="button" id="mobileMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bars"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="mobileMenuDropdown">
                            <li><h6 class="dropdown-header">Menu</h6></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="index.php"><i class="fas fa-film me-2"></i> Movies</a></li>
                            <li><a class="dropdown-item" href="events.php"><i class="fas fa-calendar-alt me-2"></i> Events</a></li>
                            <li><a class="dropdown-item" href="plays.php"><i class="fas fa-theater-masks me-2"></i> Plays</a></li>
                            <li><a class="dropdown-item" href="sports.php"><i class="fas fa-running me-2"></i> Sports</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="help.php"><i class="fa fa-plus-square me-2"></i> List Your Show</a></li>
                            <li><a class="dropdown-item" href="help.php"><i class="fa fa-ticket me-2"></i> Offers</a></li>
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
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="bookings.php"><i class="fas fa-ticket-alt me-2"></i> My Bookings</a></li>
                            <li><a class="dropdown-item" href="wishlist.php"><i class="fas fa-heart me-2"></i> My Wishlist</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Second Navigation Bar - Hidden on mobile -->
    <nav class="navbar navbar-expand-lg navbar-dark second-navbar py-1 fixed-top d-none d-lg-block">
        <div class="container">
            <div class="collapse navbar-collapse" id="secondNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-film me-1"></i>
                            <span class="nav-text">Movies</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="events.php">
                            <i class="fas fa-calendar-alt me-1"></i>
                            <span class="nav-text">Events</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="plays.php">
                            <i class="fas fa-theater-masks me-1"></i>
                            <span class="nav-text">Plays</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sports.php">
                            <i class="fas fa-running me-1"></i>
                            <span class="nav-text">Sports</span>
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <a href="help.php" class="nav-link">
                        <i class="fa fa-plus-square me-1"></i>
                        <span class="nav-text">List Your Show</span>
                    </a>
                    <a href="help.php" class="nav-link">
                        <i class="fa fa-ticket me-1"></i>
                        <span class="nav-text">Offers</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-5 mt-5">
        <div class="promos-page">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Available Promo Codes</h2>
                <div class="total-promos">Total: <?= $totalPromos ?> active promo codes</div>
            </div>
            
            <?php if (empty($promos)): ?>
                <div class="alert alert-info">There are no active promo codes available at the moment.</div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($promos as $promo): ?>
                        <div class="col-md-6 mb-4">
                            <div class="card promo-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <h5 class="card-title"><?= htmlspecialchars($promo['code']) ?></h5>
                                        <span class="badge bg-success">Active</span>
                                    </div>
                                    <p class="card-text">
                                        <strong>Discount Value:</strong> ₹<?= htmlspecialchars($promo['code_value']) ?>
                                    </p>
                                    
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <div class="d-flex justify-content-between mt-4">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="btn btn-outline-primary">Previous</a>
                    <?php else: ?>
                        <span class="btn btn-outline-secondary disabled">Previous</span>
                    <?php endif; ?>
                    
                    <?php if (($page * $promosPerPage) < $totalPromos): ?>
                        <a href="?page=<?= $page + 1 ?>" class="btn btn-outline-primary">Next</a>
                    <?php else: ?>
                        <span class="btn btn-outline-secondary disabled">Next</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
    // Theme toggle functionality
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const htmlElement = document.documentElement;
    
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

    // Rating stars interaction
    document.querySelectorAll('.rating-stars .fa-star').forEach(star => {
        star.addEventListener('click', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            const container = this.parentElement;
            
            // Update visual stars
            container.querySelectorAll('.fa-star').forEach((s, i) => {
                if (i < rating) {
                    s.classList.remove('star-empty');
                    s.classList.add('star-filled');
                } else {
                    s.classList.remove('star-filled');
                    s.classList.add('star-empty');
                }
            });
            
            // Update hidden input value
            document.getElementById('rating-value').value = rating;
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

    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.classList.add('fade-out');
            setTimeout(() => {
                alert.remove();
            }, 1000);
        }, 2500);
    });

    // Confirm before deleting review
    const deleteForms = document.querySelectorAll('form[action*="delete_rating"]');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to delete this review? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

    function fetchSearchResults(searchTerm) {
        fetch(`search_ajax.php?search=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                if (data.length > 0) {
                    let html = '';
                    data.forEach(item => {
                        html += `
                            <a href="event.php?id=${item.event_id}" class="search-result-item">
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

// Fade out animation for alerts
const fadeOut = [
    { opacity: 1 },
    { opacity: 0, height: 0, padding: 0, margin: 0, overflow: 'hidden' }
];

const fadeTiming = {
    duration: 1000,
    easing: 'ease-out',
    fill: 'forwards'
};

document.querySelectorAll('.fade-out').forEach(element => {
    element.animate(fadeOut, fadeTiming);
});
    </script>
</body>
</html>

