
<?php
session_start();
date_default_timezone_set('Asia/Kolkata');



require_once 'config/db_config.php';

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

// Database configuration
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? $_SESSION['user_id'] : null;
$username = $isLoggedIn ? $_SESSION['username'] : 'Guest';

// Pagination settings
$reviewsPerPage = 10;
$bookingsPerPage = 10;

// Get current page for reviews
$reviewPage = isset($_GET['review_page']) ? max(1, intval($_GET['review_page'])) : 1;
$reviewOffset = ($reviewPage - 1) * $reviewsPerPage;

// Get current page for bookings
$bookingPage = isset($_GET['booking_page']) ? max(1, intval($_GET['booking_page'])) : 1;
$bookingOffset = ($bookingPage - 1) * $bookingsPerPage;

// Connect to database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'submit_rating':
                handleSubmitRating($pdo, $userId);
                break;
            case 'update_rating':
                handleUpdateRating($pdo, $userId);
                break;
            case 'delete_rating':
                handleDeleteRating($pdo, $userId);
                break;
        }
    }
    // Redirect to prevent form resubmission
    header("Location: ".$_SERVER['REQUEST_URI']);
    exit();
}

// Function to handle rating submission
function handleSubmitRating($pdo, $userId) {
    if (!$userId || !isset($_POST['event_id']) || !isset($_POST['rating']) || !isset($_POST['rating_desc'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid rating data'];
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO event_ratings (
                event_id, user_gave, max_rating, rating_desc, hastag, created_by, created_on, is_active
            ) VALUES (?, ?, 5, ?, ?, ?, NOW(), 'yes')
        ");
        $stmt->execute([
            $_POST['event_id'],
            $_POST['rating'],
            $_POST['rating_desc'],
            $_POST['hashtag'] ?? null,
            $userId
        ]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Rating submitted successfully!'];
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to submit rating: ' . $e->getMessage()];
    }
}

// Function to fetch user bookings with pagination
function getUserBookings($pdo, $userId, $offset = 0, $limit = 10) {
    try {
        $stmt = $pdo->prepare("
            WITH schedule_dedup AS (
                SELECT 
                    es.event_id,
                    vs.slot_starts_at,
                    vs.venue_schedule_id,
                    v.venue_name,
                    c.city_name AS city,
                    c.state_name AS state,
                    c.country AS country,
                    ROW_NUMBER() OVER (PARTITION BY es.event_id ORDER BY vs.slot_starts_at ASC) AS rn
                FROM event_schedule es
                JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
                JOIN venues v ON vs.venue_id = v.venue_id
                JOIN cities c ON v.city_id = c.city_id
            )
            SELECT 
                agg.booking_ref,
                agg.event_id,
                agg.total_booking_amt,
                agg.grand_total_amt,
                agg.payment_method,
                agg.checked_in,
                agg.booked_at,
                e.event_name,
                e.event_type,
                sd.venue_name,
                sd.city,
                sd.state,
                sd.country,
                sd.slot_starts_at,
                agg.ticket_count
            FROM (
                SELECT * FROM (
                    SELECT 
                        b.booking_ref,
                        b.event_id,
                        SUM(b.booking_amt) AS total_booking_amt,
                        SUM(b.total_amt) AS grand_total_amt,
                        MAX(b.payment_method) AS payment_method,
                        MAX(b.checked_in) AS checked_in,
                        MAX(b.booked_at) AS booked_at,
                        COUNT(*) AS ticket_count
                    FROM bookings b
                    WHERE b.booked_by = ?
                    GROUP BY b.booking_ref, b.event_id
                    ORDER BY MAX(b.booked_at) DESC
                    LIMIT ? OFFSET ?
                ) AS limited
            ) agg
            JOIN event_info e ON agg.event_id = e.event_id
            JOIN schedule_dedup sd ON agg.event_id = sd.event_id AND sd.rn = 1;
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Function to count total user bookings
function countUserBookings($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT booking_ref) AS total
            FROM bookings
            WHERE booked_by = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['total'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

// Function to fetch user ratings with pagination
function getUserRatings($pdo, $userId, $offset = 0, $limit = 10) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                e.event_name,
                e.event_id
            FROM 
                event_ratings r
            JOIN event_info e ON r.event_id = e.event_id
            WHERE r.created_by = ?
            ORDER BY r.created_on DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Function to count total user ratings
function countUserRatings($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM event_ratings
            WHERE created_by = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['total'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

// Function to get user booking statistics
function getUserBookingStats($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT booking_ref) AS total_bookings,
                SUM(total_amt) AS total_spent,
                SUM(booking_amt - total_amt) AS total_discount
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
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [
            'total_bookings' => 0,
            'total_spent' => 0,
            'total_discount' => 0
        ];
    }
}

// Fetch data for the current user with pagination
$totalBookings = $isLoggedIn ? countUserBookings($pdo, $userId) : 0;
$bookings = $isLoggedIn ? getUserBookings($pdo, $userId, $bookingOffset, $bookingsPerPage) : [];

$totalRatings = $isLoggedIn ? countUserRatings($pdo, $userId) : 0;
$ratings = $isLoggedIn ? getUserRatings($pdo, $userId, $reviewOffset, $reviewsPerPage) : [];

// Get booking statistics
$bookingStats = $isLoggedIn ? getUserBookingStats($pdo, $userId) : [
    'total_bookings' => 0,
    'total_spent' => 0,
    'total_discount' => 0
];

// Check if viewing all reviews
$viewingAllReviews = isset($_GET['view']) && $_GET['view'] === 'reviews';
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
                                   placeholder="Search for movies..." aria-label="Search"
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
                                <li><a class="dropdown-item active"<?= basename($_SERVER['PHP_SELF']) == $movies_page ? 'active' : '' ?>" href="<?= $movies_page ?>"><i class="fas fa-film"></i> Movies</a></li>
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
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message']['type'] ?> alert-dismissible fade show">
                <?= $_SESSION['message']['text'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <?php if (!$isLoggedIn): ?>
            <div class="alert alert-warning">
                Please <a href="<?$login_page?>" class="alert-link">login</a> to view your bookings.
            </div>

        <?php elseif ($viewingAllReviews): ?>
            <h2 class="mb-4">My Reviews</h2>
            
            <?php if (empty($ratings)): ?>
                <div class="alert alert-info">You haven't posted any reviews yet.</div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($ratings as $rating): ?>
                        <div class="col-md-6 mb-4">
                            <div class="card review-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title"><?= htmlspecialchars($rating['event_name']) ?></h5>
                                    <div class="mb-2">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?= $i <= $rating['user_gave'] ? 'star-filled' : 'star-empty' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="card-text"><?= htmlspecialchars($rating['rating_desc']) ?></p>
                                    <?php if ($rating['hastag']): ?>
                                        <div class="text-muted">Tags: <?= htmlspecialchars($rating['hastag']) ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted mt-2">
                                        Posted on <?= date('M j, Y g:i A', strtotime($rating['created_on'])) ?>
                                        <?php if ($rating['updated_on']): ?>
                                            (updated <?= date('M j, Y g:i A', strtotime($rating['updated_on'])) ?>)
                                        <?php endif; ?>
                                    </div>
                                    <div class="review-actions mt-3">
                                        <a href="<?=$edit_review?>?rating_id=<?= $rating['rating_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                        <form method="post" action="" class="d-inline">
                                            <input type="hidden" name="action" value="delete_rating">
                                            <input type="hidden" name="rating_id" value="<?= $rating['rating_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this review?')">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalRatings > $reviewsPerPage): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <nav aria-label="Reviews pagination">
                            <ul class="pagination">
                                <?php if ($reviewPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?view=reviews&review_page=1" aria-label="First">
                                            <span aria-hidden="true">&laquo;&laquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?view=reviews&review_page=<?= $reviewPage - 1 ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php 
                                $totalPages = ceil($totalRatings / $reviewsPerPage);
                                $startPage = max(1, $reviewPage - 2);
                                $endPage = min($totalPages, $reviewPage + 2);
                                
                                for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?= $i == $reviewPage ? 'active' : '' ?>">
                                        <a class="page-link" href="?view=reviews&review_page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($reviewPage < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?view=reviews&review_page=<?= $reviewPage + 1 ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?view=reviews&review_page=<?= $totalPages ?>" aria-label="Last">
                                            <span aria-hidden="true">&raquo;&raquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <a href="?" class="btn btn-primary">Back to Bookings</a>

        <?php else: ?>
            <h2 class="mb-4">My Bookings</h2>
            
            <!-- Booking Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card stats-card">
                        <div class="card-body text-center">
                            <div class="stats-value"><?= $bookingStats['total_bookings'] ?></div>
                            <div class="stats-label">Total Bookings</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card">
                        <div class="card-body text-center">
                            <div class="stats-value">₹<?= number_format($bookingStats['total_spent'], 2) ?></div>
                            <div class="stats-label">Total Spent</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card">
                        <div class="card-body text-center">
                            <div class="stats-value">₹<?= number_format($bookingStats['total_discount'], 2) ?></div>
                            <div class="stats-label">Total Savings</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (empty($bookings)): ?>
                <div class="alert alert-info">You have no bookings yet.</div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($bookings as $booking): ?>
                        <div class="col-12 mb-4">
                            <div class="card booking-card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <span><?= htmlspecialchars($booking['event_name']) ?></span>
                                    <span class="badge badge-checked-in">
    <?= htmlspecialchars($booking['checked_in']) ?>
</span>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="detail-item">
                                                <span class="detail-label">Event Type:</span>
                                                <span class="detail-value"><?= htmlspecialchars($booking['event_type']) ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Venue:</span>
                                                <span class="detail-value"><?= htmlspecialchars($booking['venue_name']) ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Location:</span>
                                                <span class="detail-value">
                                                    <?= htmlspecialchars($booking['city']) ?>, <?= htmlspecialchars($booking['state']) ?>
                                                </span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Date & Time:</span>
                                                <span class="detail-value"><?= date('M j, Y g:i A', strtotime($booking['slot_starts_at'])) ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Tickets:</span>
                                                <span class="detail-value"><?= htmlspecialchars($booking['ticket_count']) ?></span>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-md-end">
                                            <div class="detail-item">
                                                <span class="detail-label">Booking Ref:</span>
                                                <span class="detail-value"><?= htmlspecialchars($booking['booking_ref']) ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Total Amount:</span>
                                                <span class="detail-value">₹<?= number_format($booking['grand_total_amt'], 2) ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Booked On:</span>
                                                <span class="detail-value"><?= date('M j, Y g:i A', strtotime($booking['booked_at'])) ?></span>
                                            </div>
                                            <a href="<?=$booking_summary?>?booking_ref=<?= urlencode($booking['booking_ref']) ?>" class="btn btn-gradient-primary btn-sm mt-2">View Details</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalBookings > $bookingsPerPage): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <nav aria-label="Bookings pagination">
                            <ul class="pagination">
                                <?php if ($bookingPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?booking_page=1" aria-label="First">
                                            <span aria-hidden="true">&laquo;&laquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?booking_page=<?= $bookingPage - 1 ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php 
                                $totalPages = ceil($totalBookings / $bookingsPerPage);
                                $startPage = max(1, $bookingPage - 2);
                                $endPage = min($totalPages, $bookingPage + 2);
                                
                                for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?= $i == $bookingPage ? 'active' : '' ?>">
                                        <a class="page-link" href="?booking_page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($bookingPage < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?booking_page=<?= $bookingPage + 1 ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?booking_page=<?= $totalPages ?>" aria-label="Last">
                                            <span aria-hidden="true">&raquo;&raquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($totalRatings > 0): ?>
                <div class="mt-4" style="text-align: center;">
                    <a href="<?=$my_reviews?>" class="btn btn-gradient-secondary">View All My Reviews (<?= $totalRatings ?>)</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Event Booking System. All rights reserved.</p>
        </div>
    </footer>

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
        fetch(`<?$search?>?search=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                if (data.length > 0) {
                    let html = '';
                    data.forEach(item => {
                        html += `
                            <a href="<?$event_info_page?>?id=${item.event_id}" class="search-result-item">
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





