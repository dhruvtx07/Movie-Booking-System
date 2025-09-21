

<?php
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





require_once 'config/db_config.php';



// Initialize selected city if not set (only for logged-in users)
if (isset($_SESSION['user_id'])) {
    $_SESSION['selected_city'] = $_SESSION['selected_city'] ?? '';
    
    // Handle city selection (auto-submit)
    if (isset($_POST['city'])) {
        $_SESSION['selected_city'] = $_POST['city'];
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }
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

$userId = $isLoggedIn ? $_SESSION['user_id'] : '';

// Fetch user details
$userDetails = [];
if ($isLoggedIn) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE userid = ?");
        $stmt->execute([$userId]);
        $userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch user details: " . $e->getMessage());
    }
}

// Get booking statistics
function getUserBookingStats($pdo, $userId) {
    $stats = [
        'total_bookings' => 0,
        'total_spent' => 0,
        'total_discount' => 0,
        'avg_order_value' => 0,
        'max_order_value' => 0,
        'min_order_value' => 0
    ];
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT booking_ref) AS total_bookings,
                SUM(total_amt) AS total_spent,
                SUM(booking_amt - total_amt) AS total_discount,
                AVG(total_amt) AS avg_order_value,
                MAX(total_amt) AS max_order_value,
                MIN(total_amt) AS min_order_value
            FROM (
                SELECT 
                    booking_ref,
                    SUM(booking_amt) AS booking_amt,
                    SUM(total_amt) AS total_amt
                FROM bookings
                WHERE booked_by = ?
                GROUP BY booking_ref
            ) AS booking_totals
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $stats = array_merge($stats, $result);
        }
    } catch (PDOException $e) {
        error_log("Failed to fetch booking stats: " . $e->getMessage());
    }
    
    return $stats;
}

// Get top 3 most booked movies
function getTopBookedMovies($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
    e.event_name, 
    COUNT(DISTINCT b.booking_ref) AS booking_count
FROM bookings b
JOIN event_info e ON b.event_id = e.event_id
WHERE b.booked_by = ? 
GROUP BY e.event_name
ORDER BY booking_count DESC
LIMIT 3;
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch top movies: " . $e->getMessage());
        return [];
    }
}

$bookingStats = $isLoggedIn ? getUserBookingStats($pdo, $userId) : [];
$topMovies = $isLoggedIn ? getTopBookedMovies($pdo, $userId) : [];


?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings</title>
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
    padding-top: 40px;
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

/* Booking cards */
.booking-card, .review-card {
    transition: all 0.3s ease;
    margin-bottom: 20px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: 1px solid var(--border-color);
    background-color: var(--card-bg);
}

.booking-card:hover, .review-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border-color: var(--accent-red);
}

.card-header {
    background-color: var(--accent-red);
    color: white;
    font-weight: bold;
    padding: 15px 20px;
}

.card-body {
    padding: 20px;
}

.detail-item {
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
}

.detail-label {
    font-weight: 600;
    color: var(--text-color);
    flex: 1;
}

.detail-value {
    color: var(--text-color);
    flex: 2;
    text-align: right;
}

/* Rating stars */
.rating-stars {
    color: var(--accent-orange);
    font-size: 24px;
    cursor: pointer;
}

.star-filled {
    color: var(--accent-orange);
}

.star-empty {
    color: #ddd;
}

.badge-checked-in {
    font-size: 0.9rem;
    padding: 5px 10px;
}

/* Stats cards */
.stats-card {
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
}

.stats-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--accent-red);
}

.stats-label {
    font-size: 0.9rem;
    color: var(--text-color);
}

/* Review cards */
.review-card {
    height: 100%;
    display: flex;
    flex-direction: column;
}

.review-card .card-body {
    flex-grow: 1;
    display: flex;
    flex-direction: column;
}

.review-card .card-text {
    flex-grow: 1;
}

.review-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 15px;
}

/* Pagination */
.pagination {
    margin-bottom: 0;
}

.page-item.active .page-link {
    background-color: var(--accent-red);
    border-color: var(--accent-red);
}

.page-link {
    color: var(--accent-red);
}

.page-link:hover {
    color: var(--accent-orange);
}

/* Responsive adjustments */
@media (max-width: 992px) {
    body {
        padding-top: 56px;
    }
    
    .second-navbar {
        display: shown;
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
    
    .detail-item {
        flex-direction: column;
    }
    
    .detail-label, .detail-value {
        flex: auto;
        text-align: left;
    }
    
    .detail-value {
        margin-top: 5px;
    }
    
    .col-md-4.text-md-end {
        text-align: left !important;
        margin-top: 15px;
    }
}

@media (max-width: 768px) {
    .stats-card {
        margin-bottom: 15px;
    }
    
    .booking-card, .review-card {
        margin-bottom: 15px;
    }
    
    .card-header {
        padding: 10px 15px;
    }
    
    .card-body {
        padding: 15px;
    }
}

@media (max-width: 576px) {
    .stats-card .card-body {
        padding: 15px 10px;
    }
    
    .stats-value {
        font-size: 1.2rem;
    }
    
    .stats-label {
        font-size: 0.8rem;
    }
    
    .review-actions {
        flex-direction: column;
        gap: 5px;
    }
    
    .review-actions .btn {
        width: 100%;
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

/* Add these to your CSS */
.btn-gradient-primary {
    background: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
    border: none;
    color: white;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
}

.btn-gradient-primary:hover {
    background: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(255, 107, 53, 0.4);
}

.btn-gradient-secondary {
    background: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
    border: none;
    color: white;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(255, 107, 53, 0.3);
}

.btn-gradient-secondary:hover {
    background: linear-gradient(135deg, var(--accent-peach) 0%, var(--accent-yellow) 100%);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(255, 158, 125, 0.4);
}

/* Update the badge styles */
.badge-checked-in {
    font-size: 0.9rem;
    padding: 5px 10px;
    background: linear-gradient(135deg, var(--accent-yellow) 0%, var(--accent-peach) 100%);
    color: var(--accent-black);
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

        @media (max-width: 992px) {
            body {
                margin-top: 12px;
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
        }
        
        @media (max-width: 768px) {
            body {
            margin-top: 40px; /* Slightly more padding for very small screens */
    }
    
    .container.my-4 {
        margin-top: 1rem !important; /* Reduce top margin on small screens */
        padding-top: 0.5rem; /* Add some padding to ensure content is visible */
    }
            
        }
        @media (max-width: 768px) {
    .movie-card {
        flex: 0 0 calc(25% - 6px);
    }
    
    .carousel-track {
        gap: 6px;
    }
    
    .movie-title {
        font-size: 0.65rem;
    }
    
    .movie-meta span {
        padding: 1px 3px;
        font-size: 0.55rem;
    }
    
    .book-now-btn {
        padding: 2px 4px;
        font-size: 0.55rem;
    }
}
@media (max-width: 992px) {
    .top-navbar {
        height: auto; /* Remove fixed height */
        padding-bottom: 0; /* Remove extra padding */
    }
    
    .second-navbar {
        background-color: var(--nav-dark) !important; /* Ensure same background */
        position: relative; /* Change from fixed to relative */
        top: 0; /* Reset position */
        display: block !important; /* Force show on mobile */
        height: auto; /* Auto height */
        overflow: visible; /* Allow content to flow */
    }
    
    .second-navbar .navbar-nav {
        display: flex !important; /* Keep flex layout */
        flex-wrap: wrap; /* Allow items to wrap */
        padding: 0.5rem 0; /* Add some padding */
    }
    
    .second-navbar .nav-item {
        flex: 1 0 auto; /* Allow items to grow */
        text-align: center; /* Center align items */
    }
    .second-navbar{
        display: none;
    }
    
    body {
        padding-top: 70px; /* Adjust for single navbar height */
    }
    
    /* Adjust container spacing */
    .container.py-5.mt-5 {
        margin-top: 1rem !important;
        padding-top: 1rem;
    }
}

@media (max-width: 768px) {
    .top-navbar {
        height: auto; /* Remove fixed height */
        padding-bottom: 0; /* Remove extra padding */
    }
    
    .second-navbar {
        background-color: var(--nav-dark) !important; /* Ensure same background */
        position: relative; /* Change from fixed to relative */
        top: 0; /* Reset position */
        display: block !important; /* Force show on mobile */
        height: auto; /* Auto height */
        overflow: visible; /* Allow content to flow */
    }
    
    .second-navbar .navbar-nav {
        display: flex !important; /* Keep flex layout */
        flex-wrap: wrap; /* Allow items to wrap */
        padding: 0.5rem 0; /* Add some padding */
    }
    
    .second-navbar .nav-item {
        flex: 1 0 auto; /* Allow items to grow */
        text-align: center; /* Center align items */
    }
    .second-navbar{
        display: none;
    }
    
    body {
        padding-top: 40px; /* Adjust for single navbar height */
    }
    
    /* Adjust container spacing */
    .container.py-5.mt-5 {
        margin-top: 1rem !important;
        padding-top: 1rem;
    }
}

:root {
    --primary-bg: #ffffff;
    --secondary-bg: #f8f9fa;
    --text-color: #141414;
    --accent-red: #e50914;
    --accent-orange: #ff6b35;
    --card-bg: #ffffff;
    --nav-dark: #141414;
}

[data-bs-theme="dark"] {
    --primary-bg: #121212;
    --secondary-bg: #1e1e1e;
    --text-color: #f8f9fa;
    --card-bg: #1e1e1e;
    --nav-dark: #000000;
}

body {
    background-color: var(--primary-bg);
    color: var(--text-color);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.card {
    background-color: var(--card-bg);
    border: 1px solid rgba(0,0,0,0.1);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

.card-header {
    background-color: var(--accent-red);
    color: white;
}

.text-muted {
    color: var(--text-color);
    opacity: 0.7;
}

@media (max-width: 768px) {
    .container {
        padding-top: 1rem !important;
    }
}


/* Enhanced Profile Page Styling */
.profile-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.profile-header {
    display: flex;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}

.profile-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 25px;
    border: 3px solid var(--accent-red);
    box-shadow: 0 4px 12px rgba(229, 9, 20, 0.2);
}

.profile-info h2 {
    margin-bottom: 5px;
    font-weight: 700;
    color: var(--text-color);
}

.profile-info p {
    margin-bottom: 10px;
    color: var(--text-color);
    opacity: 0.8;
}

.profile-stats {
    display: flex;
    gap: 15px;
    margin-top: 10px;
}

.stat-item {
    text-align: center;
    padding: 10px 15px;
    background: var(--secondary-bg);
    border-radius: 8px;
    min-width: 80px;
}

.stat-value {
    font-weight: 700;
    font-size: 1.2rem;
    color: var(--accent-red);
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.8rem;
    color: var(--text-color);
    opacity: 0.7;
}

/* Profile Sections */
.profile-section {
    margin-bottom: 40px;
}

.section-title {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--accent-red);
    display: inline-block;
    color: var(--text-color);
}

/* Personal Info Card */
.personal-info-card {
    background: var(--card-bg);
    border-radius: 12px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.08);
    overflow: hidden;
    border: none;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.personal-info-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.12);
}

.personal-info-header {
    background: var(--gradient-primary);
    color: white;
    padding: 15px 20px;
    font-weight: 600;
}

.personal-info-body {
    padding: 25px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    margin-bottom: 15px;
}

.info-label {
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 5px;
    font-size: 0.9rem;
    opacity: 0.9;
}

.info-value {
    color: var(--text-color);
    padding: 8px 12px;
    background: var(--secondary-bg);
    border-radius: 6px;
    font-size: 0.95rem;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border: 1px solid var(--border-color);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    border-color: var(--accent-red);
}

.stat-card-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 5px;
    background: var(--gradient-primary);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.stat-card-label {
    font-size: 0.9rem;
    color: var(--text-color);
    opacity: 0.8;
}

/* Top Movies Section */
.top-movies-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.movie-card {
    background: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border: 1px solid var(--border-color);
}

.movie-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    border-color: var(--accent-red);
}

.movie-header {
    background: var(--gradient-secondary);
    color: white;
    padding: 15px;
    font-weight: 600;
}

.movie-body {
    padding: 20px;
    text-align: center;
}

.movie-count {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--accent-orange);
    margin-bottom: 10px;
}

.movie-name {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 5px;
    color: var(--text-color);
}

.movie-times {
    font-size: 0.9rem;
    color: var(--text-color);
    opacity: 0.8;
}

/* Edit Profile Button */
.edit-profile-btn {
    background: var(--gradient-primary);
    border: none;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
    margin-top: 20px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.edit-profile-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(229, 9, 20, 0.4);
    color: white;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
    
    .profile-avatar {
        margin-right: 0;
        margin-bottom: 20px;
    }
    
    .profile-stats {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 576px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .top-movies-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-card-value {
        font-size: 1.5rem;
    }
}

/* Animation for stats cards */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.stat-card {
    animation: fadeInUp 0.6s ease forwards;
}

/* Delay animations for each card */
.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }
.stat-card:nth-child(4) { animation-delay: 0.4s; }
.stat-card:nth-child(5) { animation-delay: 0.5s; }
.stat-card:nth-child(6) { animation-delay: 0.6s; }

/* Loading skeleton for async content */
.skeleton {
    background: linear-gradient(90deg, var(--secondary-bg) 25%, rgba(255,255,255,0.1) 50%, var(--secondary-bg) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: 4px;
    height: 20px;
    margin-bottom: 10px;
}

@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Badge styles */
.badge-pill {
    padding: 6px 12px;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.badge-premium {
    background: linear-gradient(135deg, #ffd700 0%, #ff9500 100%);
    color: #141414;
}

.badge-member {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
}

/* Social links */
.social-links {
    display: flex;
    gap: 15px;
    margin-top: 20px;
}

.social-link {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    transition: all 0.3s ease;
    font-size: 1.1rem;
}

.social-link.facebook {
    background: #3b5998;
}

.social-link.twitter {
    background: #1da1f2;
}

.social-link.instagram {
    background: linear-gradient(45deg, #405de6, #5851db, #833ab4, #c13584, #e1306c, #fd1d1d);
}

.social-link.linkedin {
    background: #0077b5;
}

.social-link:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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



<div class="container py-5 mt-5">
    <?php if (!$isLoggedIn): ?>
        <div class="alert alert-warning">Please <a href="<?=$login_page?>" class="alert-link">login</a> to view your profile.</div>
    <?php else: ?>
        <!-- Profile Header -->
        <div class="profile-header">
            <?php if (isset($_SESSION['profile_pic']) && !empty($_SESSION['profile_pic'])): ?>
                <img src="<?= htmlspecialchars($_SESSION['profile_pic']) ?>" class="profile-avatar" alt="Profile Picture">
            <?php else: ?>
                <div class="profile-avatar bg-secondary d-flex align-items-center justify-content-center">
                    <i class="fas fa-user fa-3x text-light"></i>
                </div>
            <?php endif; ?>
            
            <div class="profile-info">
                <h2><?= htmlspecialchars($userDetails['name'] ?? 'User') ?></h2>
                <p class="text-muted">@<?= htmlspecialchars($userDetails['username'] ?? 'username') ?></p>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-value" onclick="window.location.href='<?=$my_bookings?>'"><?= $bookingStats['total_bookings'] ?? 0 ?></div>
                        <div class="stat-label">Bookings</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">₹<?= number_format($bookingStats['total_spent'] ?? 0, 0) ?></div>
                        <div class="stat-label">Spent</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">₹<?= number_format($bookingStats['total_discount'] ?? 0, 0) ?></div>
                        <div class="stat-label">Saved</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Personal Information Section -->
        <div class="profile-section">
            <h3 class="section-title">Personal Information</h3>
            
            <div class="personal-info-card">
                <div class="personal-info-header">
                    <i class="fas fa-user-circle me-2"></i> About Me
                </div>
                <div class="personal-info-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?= htmlspecialchars($userDetails['name'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Username</div>
                            <div class="info-value">@<?= htmlspecialchars($userDetails['username'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?= htmlspecialchars($userDetails['email'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?= htmlspecialchars($userDetails['phone'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Member Since</div>
                            <div class="info-value"><?= date('M Y', strtotime($userDetails['created_at'] ?? 'now')) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Account Status</div>
                            <div class="info-value">
                                <span class="badge badge-pill badge-success">Active</span>
                            </div>
                        </div>
                    </div>
                    
                    
                </div>
            </div>
        </div>
        
        <!-- Booking Statistics Section -->
        <div class="profile-section">
            <h3 class="section-title">Booking Statistics</h3>
            
            <div class="stats-grid">
                <div class="stat-card" onclick="window.location.href='<?=$my_bookings?>'">
                    <div class="stat-card-value"><?= $bookingStats['total_bookings'] ?? 0 ?></div>
                    <div class="stat-card-label">Total Bookings</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-value">₹<?= number_format($bookingStats['total_spent'] ?? 0, 0) ?></div>
                    <div class="stat-card-label">Total Spent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-value">₹<?= number_format($bookingStats['total_discount'] ?? 0, 0) ?></div>
                    <div class="stat-card-label">Total Savings</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-value">₹<?= number_format($bookingStats['avg_order_value'] ?? 0, 0) ?></div>
                    <div class="stat-card-label">Avg. Order</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-value">₹<?= number_format($bookingStats['max_order_value'] ?? 0, 0) ?></div>
                    <div class="stat-card-label">Max. Order</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-value">₹<?= number_format($bookingStats['min_order_value'] ?? 0, 0) ?></div>
                    <div class="stat-card-label">Min. Order</div>
                </div>
            </div>
        </div>
        
        <!-- Top Movies Section -->
        <?php if (!empty($topMovies)): ?>
        <div class="profile-section">
            <h3 class="section-title">Your Favorite Events</h3>
            
            <div class="top-movies-grid">
                <?php foreach ($topMovies as $movie): ?>
                <div class="movie-card">
                    <div class="movie-header">
                        <i class="fas fa-film me-2"></i> <?= htmlspecialchars($movie['event_name']) ?>
                    </div>
                    <div class="movie-body">
                        <div class="movie-count"><?= $movie['booking_count'] ?></div>
                        <div class="movie-times">times booked</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        



    <?php endif; ?>
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
            
            // Update hidden input value if exists
            const ratingInput = container.parentElement.querySelector('#rating-value');
            if (ratingInput) {
                ratingInput.value = rating;
            }
        });
    });

    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
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
            }
        
        );
    
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





