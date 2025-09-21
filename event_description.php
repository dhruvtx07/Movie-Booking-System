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

// Database connection for event details
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



// Check if event_id is provided in URL
if (isset($_GET['id'])) {
    $event_id = intval($_GET['id']);
    $_SESSION['event_id'] = $event_id; // Save it in session
} elseif (isset($_SESSION['event_id'])) {
    $event_id = $_SESSION['event_id']; // Reuse from session if not in URL
} else {
    header("Location: $home");
    exit();
}

// Query to get event details
$sql = "SELECT * FROM event_info WHERE event_id = $event_id AND is_active = 'yes'";
$result = $conn->query($sql);

if ($result->num_rows === 0) {
    die("Event not found or no longer available.");
}

$event = $result->fetch_assoc();

// Calculate average rating and count
$rating_sql = "SELECT AVG(user_gave) as avg_rating, COUNT(*) as rating_count 
               FROM event_ratings 
               WHERE event_id = $event_id AND is_active = 'yes'";
$rating_result = $conn->query($rating_sql);
$rating_data = $rating_result->fetch_assoc();
$avg_rating = $rating_data['avg_rating'] ?? 0;
$rating_count = $rating_data['rating_count'] ?? 0;

// Get cast information
$cast_sql = "SELECT * FROM event_starcast WHERE event_id = $event_id AND is_active = 'yes'";
$cast_result = $conn->query($cast_sql);
$cast_members = [];
if ($cast_result->num_rows > 0) {
    while ($row = $cast_result->fetch_assoc()) {
        $cast_members[] = $row;
    }
}

// Get all unique hashtags for filtering
$hashtags_sql = "SELECT DISTINCT hastag FROM event_ratings 
                 WHERE event_id = $event_id AND is_active = 'yes' AND hastag IS NOT NULL";
$hashtags_result = $conn->query($hashtags_sql);
$hashtags = [];
if ($hashtags_result->num_rows > 0) {
    while ($row = $hashtags_result->fetch_assoc()) {
        $hashtags[] = $row['hastag'];
    }
}

// Get reviews with pagination
$reviews_per_page = 8;
$page = isset($_GET['review_page']) ? max(1, intval($_GET['review_page'])) : 1;
$offset = ($page - 1) * $reviews_per_page;

// Check for hashtag filter
$selected_hashtag = isset($_GET['hashtag']) ? $_GET['hashtag'] : null;
$hashtag_filter = $selected_hashtag ? "AND hastag = '" . $conn->real_escape_string($selected_hashtag) . "'" : "";

$reviews_sql = "SELECT r.*, u.name as user_name 
                FROM event_ratings r
                JOIN users u ON r.created_by = u.userid
                WHERE r.event_id = $event_id AND r.is_active = 'yes' $hashtag_filter
                ORDER BY r.created_on DESC
                LIMIT $offset, $reviews_per_page";
$reviews_result = $conn->query($reviews_sql);
$reviews = [];
if ($reviews_result->num_rows > 0) {
    while ($row = $reviews_result->fetch_assoc()) {
        $reviews[] = $row;
    }
}

// Get total reviews count for pagination
$total_reviews_sql = "SELECT COUNT(*) as total FROM event_ratings 
                      WHERE event_id = $event_id AND is_active = 'yes' $hashtag_filter";
$total_reviews_result = $conn->query($total_reviews_sql);
$total_reviews = $total_reviews_result->fetch_assoc()['total'];
$total_pages = ceil($total_reviews / $reviews_per_page);

// Check if there are more reviews to load
$has_more_reviews = ($page * $reviews_per_page) < $total_reviews;

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($event['event_name']); ?> - Event Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        /* Navbar Variables */
        --primary-bg: #ffffff;
        --secondary-bg: #f8f9fa;
        --text-color: #141414;
        --accent-red: #e50914;
        --accent-orange: #ff6b35;
        --accent-peach: #ff9e7d;
        --accent-black: #141414;
        --card-bg: #ffffff;
        --nav-dark: #141414;
        --nav-text: #ffffff;
        --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
        --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
        
        /* Event Details Variables */
        --primary-black: #121212;
        --primary-yellow: #f7c548;
        --border-color: #e0e0e0;
        --filter-bg: #f0f0f0;
        --filter-text: #333333;
        --filter-active-bg: #e50914;
        --filter-active-text: #ffffff;
        --shadow: 0 0 20px rgba(0,0,0,0.1);
        --border-radius: 8px;
        --transition: all 0.3s ease;
    }

    [data-bs-theme="dark"] {
        --primary-bg: #121212;
        --secondary-bg: #1e1e1e;
        --text-color: #f8f9fa;
        --accent-red: #e50914;
        --accent-orange: #ff6b35;
        --accent-peach: #ff9e7d;
        --accent-black: #f8f9fa;
        --card-bg: #1e1e1e;
        --nav-dark: #000000;
        --border-color: #333;
        --filter-bg: #2a2a2a;
        --filter-text: #ffffff;
        --shadow: 0 0 20px rgba(0,0,0,0.3);
    }

    /* Base Styles */
    body {
        background-color: var(--primary-bg);
        color: var(--text-color);
        transition: var(--transition);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding-top: 110px;
    }

    /* Navbar Styles */
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

    .top-navbar, .second-navbar {
        background-color: var(--nav-dark) !important;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        transition: var(--transition);
    }

    .top-navbar {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1030;
    }

    .second-navbar {
        position: fixed;
        top: 54px;
        left: 0;
        right: 0;
        z-index: 1020;
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
        box-shadow: var(--shadow);
        z-index: 1000;
        max-height: 400px;
        overflow-y: auto;
        display: none;
    }

    .search-result-item {
        padding: 10px 15px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: var(--transition);
        color: var(--text-color);
        text-decoration: none;
        display: block;
    }

    .search-result-item:hover {
        background-color: var(--secondary-bg);
        transform: translateX(5px);
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
        transition: var(--transition);
    }

    .search-result-item:hover .search-result-name {
        color: var(--accent-orange);
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
        transition: var(--transition);
    }

    .city-item:hover {
        background-color: rgba(255,255,255,0.1);
    }

    .dropdown-item {
        color: var(--nav-text);
        transition: var(--transition);
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
        transition: var(--transition);
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
        transition: var(--transition);
    }

    .nav-link {
        color: var(--nav-text);
        transition: var(--transition);
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
        transition: var(--transition);
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
        transition: var(--transition);
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

    /* Event Details Styles */
    .event-details-container {
        max-width: 1200px;
        margin: 120px auto 40px;
        padding: 0 20px;
    }

    .event-header {
        background: var(--card-bg);
        padding: 30px;
        border-radius: var(--border-radius);
        margin-bottom: 30px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .event-title {
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0;
        color: var(--text-color);
    }

    .event-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: center;
    }

    .rating-container {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .star-rating {
        color: var(--primary-yellow);
        font-size: 1.2rem;
    }

    .action-btn {
        padding: 10px 20px;
        border-radius: 5px;
        font-weight: 600;
        text-decoration: none;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .rate-now-btn {
        background: transparent;
        border: 1px solid var(--accent-orange);
        color: var(--accent-orange);
    }

    .rate-now-btn:hover {
        background: rgba(255,107,53,0.1);
    }

    .book-btn {
        background: var(--accent-red);
        color: white;
        border: none;
    }

    .book-btn:hover {
        background: var(--accent-red);
        opacity: 0.9;
        transform: translateY(-2px);
    }

    .trailer-btn {
        background: var(--accent-orange);
        color: white;
        border: none;
    }

    .trailer-btn:hover {
        background: var(--accent-orange);
        opacity: 0.9;
        transform: translateY(-2px);
    }

    .event-body {
        display: flex;
        gap: 30px;
        margin-bottom: 40px;
        align-items: flex-start;
    }

    .event-poster {
        flex: 0 0 300px;
        width: 100%;
        max-width: 300px;
        height: auto;
        aspect-ratio: 2/3;
        border-radius: var(--border-radius);
        overflow: hidden;
        box-shadow: var(--shadow);
        background: var(--card-bg);
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 0 auto;
    }

    .event-poster img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
    }

    .event-info {
        flex: 1;
        background: var(--card-bg);
        padding: 30px;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }

    .info-item {
        margin-bottom: 15px;
    }

    .info-label {
        font-weight: 600;
        color: var(--accent-orange);
        margin-bottom: 5px;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 1rem;
        color: var(--text-color);
    }

    /* Reviews Section */
    .reviews-section {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        padding: 30px;
        box-shadow: var(--shadow);
        margin-bottom: 40px;
    }

    .section-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 20px;
        position: relative;
        padding-bottom: 10px;
        color: var(--text-color);
    }

    .section-title::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 50px;
        height: 3px;
        background: var(--gradient-primary);
        border-radius: 3px;
    }

    .reviews-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .reviews-summary {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .reviews-count {
        font-size: 1rem;
        color: var(--accent-orange);
    }

    .hashtags-filter {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 20px;
    }

    .hashtag-btn {
        padding: 5px 15px;
        border-radius: 20px;
        background: var(--filter-bg);
        color: var(--filter-text);
        border: none;
        font-size: 0.8rem;
        transition: var(--transition);
        cursor: pointer;
        border: 1px solid var(--border-color);
    }

    .hashtag-btn:hover {
        transform: translateY(-2px);
    }

    .hashtag-btn.active {
        background: var(--filter-active-bg);
        color: var(--filter-active-text);
        border-color: var(--filter-active-bg);
    }

    .reset-filter-btn {
        padding: 5px 15px;
        border-radius: 20px;
        background: transparent;
        color: var(--accent-orange);
        border: 1px solid var(--accent-orange);
        font-size: 0.8rem;
        transition: var(--transition);
        cursor: pointer;
    }

    .reset-filter-btn:hover {
        background: var(--accent-orange);
        color: white;
    }

    .reviews-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }

    .review-card {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        padding: 20px;
        box-shadow: var(--shadow);
        transition: var(--transition);
        border: 1px solid var(--border-color);
    }

    .review-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }

    .review-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }

    .review-user {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--accent-peach);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
    }

    .review-rating {
        color: var(--primary-yellow);
        font-size: 0.9rem;
    }

    .review-content {
        color: var(--text-color);
        line-height: 1.6;
    }

    .review-hashtag {
        display: inline-block;
        margin-top: 10px;
        padding: 3px 10px;
        background: var(--accent-peach);
        color: white;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 500;
    }

    .load-more-btn {
        display: block;
        width: 100%;
        padding: 10px;
        background: var(--card-bg);
        color: var(--accent-orange);
        border: 1px solid var(--accent-orange);
        border-radius: var(--border-radius);
        font-weight: 600;
        transition: var(--transition);
        text-align: center;
    }

    .load-more-btn:hover {
        background: var(--accent-orange);
        color: white;
    }

    .load-more-btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    /* Cast Section */
    .cast-section {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        padding: 30px;
        box-shadow: var(--shadow);
        margin-bottom: 40px;
    }

    .cast-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 20px;
    }

    .cast-member {
        text-align: center;
    }

    .cast-photo-container {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        overflow: hidden;
        margin: 0 auto 10px;
        background: var(--filter-bg);
        display: flex;
        justify-content: center;
        align-items: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }

    .cast-photo {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .cast-name {
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: 5px;
    }

    .cast-role {
        font-size: 0.8rem;
        color: var(--accent-orange);
    }

    /* Sticky Header */
    .sticky-header {
        position: fixed;
        top: -100px;
        left: 0;
        right: 0;
        background: var(--card-bg);
        padding: 10px 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        z-index: 999;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: top 0.3s ease;
        border-bottom: 1px solid var(--border-color);
    }

    .sticky-header.show {
        top: 0;
    }

    .sticky-title {
        font-size: 1.2rem;
        font-weight: 600;
        margin: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 60%;
        color: var(--text-color);
    }

    .sticky-book-btn {
        padding: 8px 15px;
        font-size: 0.9rem;
    }

    /* Responsive adjustments */
    @media (max-width: 992px) {
        body {
            margin-top: 30px;
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

        .event-header {
            padding: 20px;
        }
        
        .event-title {
            font-size: 2rem;
        }
        
        .event-body {
            flex-direction: column;
            align-items: center;
        }
        
        .event-info {
            width: 100%;
            padding: 20px;
        }
        
        .info-grid {
            grid-template-columns: 1fr;
        }
        
        .event-poster {
            max-width: 250px;
        }
        
        .reviews-grid {
            grid-template-columns: 1fr;
        }
        
        .cast-grid {
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        }
        
        .cast-photo-container {
            width: 80px;
            height: 80px;
        }
    }

    @media (max-width: 768px) {
        body {
            margin-top: 55px;
        }
        
        .sticky-header {
            padding: 8px 15px;
        }
        
        .sticky-title {
            font-size: 1rem;
            max-width: 50%;
        }
        
        .event-title {
            font-size: 1.8rem;
        }
        
        .event-actions {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .event-poster {
            max-width: 200px;
        }
    }

    @media (max-width: 576px) {
        body {
            margin-top: 70px;
        }
        
        .event-title {
            font-size: 1.5rem;
        }
        
        .action-btn {
            padding: 8px 15px;
            font-size: 0.9rem;
        }
        
        .event-poster {
            max-width: 200px;
        }
        
        .cast-grid {
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
        }
        
        .cast-photo-container {
            width: 60px;
            height: 60px;
        }
    }

    /* Animation for alerts */
    .fade-out {
        animation: fadeOut 3s ease-out forwards;
    }

    @keyframes fadeOut {
        0% { opacity: 1; }
        100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }
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

    <!-- Main Content - Event Details -->
    <div class="event-details-container" style="margin-top: 20px;">
        <!-- Sticky Header (initially hidden) -->
        <div class="sticky-header" id="stickyHeader">
            <h2 class="sticky-title"><?php echo htmlspecialchars($event['event_name']); ?></h2>
            <a href="<?= $book_event ?>?event_id=<?php echo isset($event['event_id']) ? htmlspecialchars($event['event_id']) : ''; ?>" class="action-btn book-btn sticky-book-btn">
                <i class="fas fa-ticket-alt"></i> Book Now
            </a>
        </div>
        


        <!-- Event Content -->
        <div class="event-header">
            <h1 class="event-title"><?php echo htmlspecialchars($event['event_name']); ?></h1>
            
            <div class="event-actions">
                <div class="rating-container">
                    <div class="star-rating">
                        <?php
                        $rating = floatval($avg_rating);
                        $fullStars = floor($rating);
                        $halfStar = ($rating - $fullStars) >= 0.5 ? 1 : 0;
                        $emptyStars = 5 - $fullStars - $halfStar;
                        
                        for ($i = 0; $i < $fullStars; $i++) {
                            echo '<i class="fas fa-star"></i>';
                        }
                        if ($halfStar) {
                            echo '<i class="fas fa-star-half-alt"></i>';
                        }
                        for ($i = 0; $i < $emptyStars; $i++) {
                            echo '<i class="far fa-star"></i>';
                        }
                        ?>
                        <span style="font-size: 1rem; margin-left: 5px;">(<?php echo number_format($rating, 1); ?> from <?php echo $rating_count; ?> ratings)</span>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="<?=$rate_event?>?event_id=<?php echo $event_id; ?>" class="action-btn rate-now-btn">
                        <i class="fas fa-pen"></i> Rate Now
                    </a>
                    
                    <a href="<?= $book_event ?>?event_id=<?php echo isset($event['event_id']) ? htmlspecialchars($event['event_id']) : ''; ?>&date=<?php echo date('Y-m-d'); ?>" class="book-btn action-btn" style="display: inline-block; margin-top: 20px;">
    <i class="fas fa-ticket-alt"></i> Book Tickets
</a>

                    
                    <?php if (!empty($event['event_trailer_link'])): ?>
                        <button class="action-btn trailer-btn" onclick="window.open('<?php echo htmlspecialchars($event['event_trailer_link']); ?>', '_blank')">
                            <i class="fas fa-play"></i> Watch Trailer
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="event-body">
            <div class="event-poster">
                <?php if (!empty($event['photo'])): ?>
                    <img src="<?php echo htmlspecialchars($event['photo']); ?>" alt="<?php echo htmlspecialchars($event['event_name']); ?>" onerror="this.onerror=null;this.src='data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 300 450\"%3E%3Crect width=\"300\" height=\"450\" fill=\"%232a2a2a\"/%3E%3Ctext x=\"150\" y=\"225\" font-family=\"Arial\" font-size=\"16\" fill=\"%23666\" text-anchor=\"middle\"%3ENo Image%3C/text%3E%3C/svg%3E'">
                <?php else: ?>
                    <i class="fas fa-film" style="font-size: 3rem; color: var(--border-color);"></i>
                <?php endif; ?>
            </div>
            
            <div class="event-info">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Language</div>
                        <div class="info-value"><?php echo !empty($event['event_language']) ? htmlspecialchars($event['event_language']) : 'N/A'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Duration</div>
                        <div class="info-value"><?php echo !empty($event['event_duration']) ? htmlspecialchars($event['event_duration']) : 'N/A'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Genre</div>
                        <div class="info-value"><?php echo !empty($event['genre']) ? htmlspecialchars($event['genre']) : 'N/A'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Release Date</div>
                        <div class="info-value"><?php echo !empty($event['event_start_date']) ? date('F j, Y', strtotime($event['event_start_date'])) : 'N/A'; ?></div>
                    </div>
                </div>
                
                <?php if (!empty($event['event_desc'])): ?>
                    <div class="info-item">
                        <div class="info-label">About the movie</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($event['event_desc'])); ?></div>
                    </div>
                <?php endif; ?>
                
                <a href="<?= $book_event ?>?event_id=<?php echo isset($event['event_id']) ? htmlspecialchars($event['event_id']) : ''; ?>&date=<?php echo date('Y-m-d'); ?>" class="book-btn action-btn" style="display: inline-block; margin-top: 20px;">
    <i class="fas fa-ticket-alt"></i> Book Tickets
</a>

            </div>
        </div>
        
        <!-- Cast Section -->
        <?php if (!empty($cast_members)): ?>
        <div class="cast-section">
            <h2 class="section-title">Cast</h2>
            <div class="cast-grid">
                <?php foreach ($cast_members as $member): ?>
                    <div class="cast-member">
                        <div class="cast-photo-container">
                            <?php if (!empty($member['photo'])): ?>
                                <img src="<?php echo htmlspecialchars($member['photo']); ?>" alt="<?php echo htmlspecialchars($member['starcast_name']); ?>" class="cast-photo" onerror="this.onerror=null;this.src='data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"%3E%3Ccircle cx=\"50\" cy=\"50\" r=\"50\" fill=\"%232a2a2a\"/%3E%3Ctext x=\"50\" y=\"55\" font-family=\"Arial\" font-size=\"16\" fill=\"%23666\" text-anchor=\"middle\"%3ENo Image%3C/text%3E%3C/svg%3E'">
                            <?php else: ?>
                                <i class="fas fa-user" style="font-size: 2rem; color: var(--border-color);"></i>
                            <?php endif; ?>
                        </div>
                        <div class="cast-name"><?php echo htmlspecialchars($member['starcast_name']); ?></div>
                        <div class="cast-role"><?php echo htmlspecialchars($member['designation']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Reviews Section -->
        <div class="reviews-section">
            <div class="reviews-header">
                <h2 class="section-title">User Reviews</h2>
                <div class="reviews-summary">
                    <div class="star-rating">
                        <?php
                        $rating = floatval($avg_rating);
                        $fullStars = floor($rating);
                        $halfStar = ($rating - $fullStars) >= 0.5 ? 1 : 0;
                        
                        for ($i = 0; $i < $fullStars; $i++) {
                            echo '<i class="fas fa-star"></i>';
                        }
                        if ($halfStar) {
                            echo '<i class="fas fa-star-half-alt"></i>';
                        }
                        ?>
                    </div>
                    <div class="reviews-count"><?php echo $rating_count; ?> reviews</div>
                </div>
            </div>
            
            <!-- Hashtag Filters -->
            <?php if (!empty($hashtags)): ?>
            <div class="hashtags-filter">
                <?php foreach ($hashtags as $tag): ?>
                    <button class="hashtag-btn <?php echo ($selected_hashtag === $tag) ? 'active' : ''; ?>" 
                            onclick="window.location.href='?id=<?php echo $event_id; ?>&hashtag=<?php echo urlencode($tag); ?>'">
                        #<?php echo htmlspecialchars($tag); ?>
                    </button>
                <?php endforeach; ?>
                <?php if ($selected_hashtag): ?>
                    <button class="reset-filter-btn" onclick="window.location.href='?id=<?php echo $event_id; ?>'">
                        Reset Filter
                    </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Reviews Grid -->
            <div class="reviews-grid">
                <?php if (!empty($reviews)): ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="review-user">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($review['user_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="user-name"><?php echo htmlspecialchars($review['user_name']); ?></div>
                                        <div class="review-date"><?php echo date('M j, Y', strtotime($review['created_on'])); ?></div>
                                    </div>
                                </div>
                                <div class="review-rating">
                                    <?php
                                    $user_rating = intval($review['user_gave']);
                                    for ($i = 0; $i < 5; $i++) {
                                        if ($i < $user_rating) {
                                            echo '<i class="fas fa-star"></i>';
                                        } else {
                                            echo '<i class="far fa-star"></i>';
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="review-content">
                                <?php echo nl2br(htmlspecialchars($review['rating_desc'])); ?>
                            </div>
                            <?php if (!empty($review['hastag'])): ?>
                                <div class="review-hashtag">#<?php echo htmlspecialchars($review['hastag']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <p>No reviews found. Be the first to review this event!</p>
                    </div>
                <?php endif; ?>
            </div>
            
<!-- Load More Button -->
            <?php if ($has_more_reviews): ?>
                <button class="load-more-btn" id="loadMoreBtn" 
                        onclick="loadMoreReviews(<?php echo $page + 1; ?>, '<?php echo $selected_hashtag ? htmlspecialchars($selected_hashtag, ENT_QUOTES) : ''; ?>')">
                    Load More Reviews
                </button>
            <?php endif; ?>
        </div>
    </div>

    <footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Catchify. All rights reserved.</p>
        </div>
    </footer>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {  // Fixed: removed extra closing parenthesis
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
    
    if (themeToggle && themeIcon) {  // Added null checks
        themeToggle.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            htmlElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeIcon(newTheme);
        });
    }
    
    function updateThemeIcon(theme) {
        if (!themeIcon) return;  // Added null check
        
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
            const selectedCityInput = document.getElementById('selectedCity');
            const cityForm = document.getElementById('cityForm');
            
            if (selectedCityInput && cityForm) {
                selectedCityInput.value = cityId;
                cityForm.submit();
            }
        });
    });
    
    // Search functionality with AJAX
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput && searchResults) {
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
        fetch(`<?= $search; ?>?search=${encodeURIComponent(searchTerm)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (!searchResults) return;
                
                if (data.length > 0) {
                    let html = '';
                    data.forEach(item => {
                        html += `
                            <a href="<?= $book_event ?>?id=${item.event_id}" class="search-result-item">
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
                if (searchResults) {
                    searchResults.innerHTML = '<div class="search-result-item">Error loading results</div>';
                    searchResults.style.display = 'block';
                }
            });
    }
    
    // Sticky Header Functionality
    const stickyHeader = document.getElementById('stickyHeader');
    
    if (stickyHeader) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 200) {
                stickyHeader.classList.add('show');
            } else {
                stickyHeader.classList.remove('show');
            }
        });
    }
    


});

// Load More Reviews Function
        function loadMoreReviews(page, hashtag) {
            const loadMoreBtn = document.getElementById('loadMoreBtn');
            loadMoreBtn.disabled = true;
            loadMoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            
            const url = new URL(window.location.href);
            url.searchParams.set('review_page', page);
            if (hashtag) {
                url.searchParams.set('hashtag', hashtag);
            }
            
            fetch(url.toString())
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newReviews = doc.querySelectorAll('.review-card');
                    const reviewsGrid = document.querySelector('.reviews-grid');
                    
                    newReviews.forEach(review => {
                        reviewsGrid.appendChild(review);
                    });
                    
                    // Update load more button
                    const newLoadMoreBtn = doc.getElementById('loadMoreBtn');
                    if (newLoadMoreBtn) {
                        loadMoreBtn.outerHTML = newLoadMoreBtn.outerHTML;
                    } else {
                        loadMoreBtn.remove();
                    }
                })
                .catch(error => {
                    console.error('Error loading more reviews:', error);
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.innerHTML = 'Load More Reviews';
                    alert('Failed to load more reviews. Please try again.');
                });
        }
    
    </script>

</body>
</html>