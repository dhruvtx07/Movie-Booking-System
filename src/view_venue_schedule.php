<?php
session_start();
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

// Search functionality
$searchResults = [];
if ($isLoggedIn && isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = '%' . $_GET['search'] . '%';
    
    try {
        $stmt = $pdo->prepare("SELECT event_id, event_name, event_type FROM event_info 
                              WHERE event_name LIKE :search 
                              AND is_active = 'yes'
                              ORDER BY event_name LIMIT 10");
        $stmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
        $stmt->execute();
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Search failed: " . $e->getMessage());
    }
}

// Get now showing events if city is selected
$nowShowing = [];
if ($isLoggedIn && !empty($_SESSION['selected_city'])) {
    try {
        $stmt = $pdo->prepare("SELECT e.event_id, e.event_name, e.photo, e.event_type, 
                              MIN(s.show_time) as next_show_time
                              FROM event_info e
                              JOIN shows s ON e.event_id = s.event_id
                              JOIN venues v ON s.venue_id = v.venue_id
                              WHERE v.city_id = :city_id
                              AND e.is_active = 'yes'
                              AND s.show_date >= CURDATE()
                              GROUP BY e.event_id
                              ORDER BY e.event_name
                              LIMIT 12");
        $stmt->bindParam(':city_id', $_SESSION['selected_city'], PDO::PARAM_INT);
        $stmt->execute();
        $nowShowing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch now showing events: " . $e->getMessage());
    }
}

// Movie section functionality
$movies = [];
$all_languages = [];
$all_genres = [];
$selected_languages = isset($_GET['language']) ? explode(',', $_GET['language']) : [];
$selected_genres = isset($_GET['genre']) ? explode(',', $_GET['genre']) : [];

if ($isLoggedIn && !empty($_SESSION['selected_city'])) {
    try {
        // Base SQL query for movies
        $sql = "SELECT ei.*
                FROM event_info ei
                JOIN event_schedule es ON ei.event_id = es.event_id
                JOIN venues v ON es.venue_id = v.venue_id
                WHERE ei.event_type LIKE '%movie%'
                  AND ei.is_active = 'yes'
                  AND es.is_active = 'yes'
                  AND v.is_active = 'yes'
                  AND v.city_id = :city_id
                  AND ei.event_start_date <= CURDATE()
                  AND ei.movie_end >= CURDATE()";

        // Apply language filter if any selected
        if (!empty($selected_languages)) {
            $language_conditions = [];
            foreach ($selected_languages as $lang) {
                $language_conditions[] = "ei.event_language = :lang_$lang";
            }
            $sql .= " AND (" . implode(" OR ", $language_conditions) . ")";
        }

        // Apply genre filter if any selected
        if (!empty($selected_genres)) {
            $genre_conditions = [];
            foreach ($selected_genres as $genre) {
                $genre_conditions[] = "ei.genre LIKE :genre_$genre";
            }
            $sql .= " AND (" . implode(" OR ", $genre_conditions) . ")";
        }

        $sql .= " GROUP BY ei.event_id ORDER BY ei.created_on DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':city_id', $_SESSION['selected_city'], PDO::PARAM_INT);

        // Bind language parameters
        foreach ($selected_languages as $lang) {
            $stmt->bindValue(":lang_$lang", $lang);
        }

        // Bind genre parameters
        foreach ($selected_genres as $genre) {
            $stmt->bindValue(":genre_$genre", "%$genre%");
        }

        $stmt->execute();
        $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all available languages and genres for filters
        $filter_sql = "SELECT 
                        GROUP_CONCAT(DISTINCT event_language) as languages,
                        GROUP_CONCAT(DISTINCT genre) as genres
                      FROM event_info
                      WHERE event_type LIKE '%movie%'
                        AND is_active = 'yes'
                        AND event_start_date <= CURDATE()
                        AND movie_end >= CURDATE()";

        $filter_stmt = $pdo->query($filter_sql);
        $filter_data = $filter_stmt->fetch(PDO::FETCH_ASSOC);

        // Process languages
        if (!empty($filter_data['languages'])) {
            $all_languages = array_unique(explode(',', $filter_data['languages']));
            sort($all_languages);
        }

        // Process genres (handle comma-separated values and get unique)
        if (!empty($filter_data['genres'])) {
            $genre_array = explode(',', $filter_data['genres']);
            $temp_genres = [];
            foreach ($genre_array as $genre) {
                $split_genres = array_map('trim', explode(',', $genre));
                $temp_genres = array_merge($temp_genres, $split_genres);
            }
            $all_genres = array_unique($temp_genres);
            sort($all_genres);
        }

    } catch (PDOException $e) {
        error_log("Failed to fetch movies: " . $e->getMessage());
    }
}


date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=event_mg', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("<div class='alert alert-danger'>Database connection failed: " . $e->getMessage() . "</div>");
}

// No event_id needed - we'll show all events
$events = [];
try {
    $eventQuery = $db->query("SELECT * FROM event_info WHERE is_active = 'yes'");
    $events = $eventQuery->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($events)) {
        die("<div class='alert alert-danger'>No active events found</div>");
    }
} catch(PDOException $e) {
    die("<div class='alert alert-danger'>Error fetching events: " . $e->getMessage() . "</div>");
}

// Date handling - calculate next 7 days
$dates = [];
$selectedDate = isset($_GET['date']) ? new DateTime($_GET['date']) : new DateTime();
$today = new DateTime();

// Make sure we're comparing dates without time components
$today->setTime(0, 0, 0);
$selectedDate->setTime(0, 0, 0);

for ($i = 0; $i < 7; $i++) {
    $date = clone $selectedDate;
    $date->modify("+$i days");
    $dates[] = $date;
}

// Fetch venues for the selected city
$city_id = isset($_SESSION['selected_city']) ? $_SESSION['selected_city'] : 2;
$venues = [];
try {
    $venueStmt = $db->prepare("SELECT venue_id, venue_name, sub_venue_name FROM venues WHERE city_id = ? AND is_active = 'yes'");
    $venueStmt->execute([$city_id]);
    $venues = $venueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Failed to fetch venues: " . $e->getMessage());
    $venues = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
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
            --nav-text: #ffffff;
            --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
        }

        body {
            padding-top: 110px; /* 56px (top navbar) + 54px (second navbar) */
            background-color: var(--primary-bg);
            color: var(--text-color);
            transition: all 0.3s ease;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 110px;
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
        
        .top-navbar {
            background-color: var(--nav-dark) !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .top-navbar {
            position: fixed;
            padding-top: 56px;
            left: 0;
            right: 0;
            z-index: 1030;
        }

        .second-navbar {
            background-color: var(--nav-dark) !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
.second-navbar {
    top: 54px; /* Height of the first navbar */
    left: 0;
    right: 0;
    z-index: 1020;
    white-space: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    margin-top: 0; /* Ensure no margin */
    border-top: none; /* Remove any border */
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

        .second-navbar .navbar-toggler {
            display: none !important;
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
        
        /* Add this to your existing CSS */
.fade-out {
    animation: fadeOut 3s ease-out forwards;
}

@keyframes fadeOut {
    0% { opacity: 1; }
    100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }
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

        /* Add these styles to your existing CSS */

.movie-section {
    padding: 10px 0;
    background: var(--bg-color);
    max-width: 100%;
    overflow: hidden;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding: 0 5px;
}

.section-header h2 {
    color: var(--text-color);
    font-weight: 700;
    margin: 0;
    position: relative;
    padding-bottom: 5px;
    font-size: 1rem;
}

.section-header h2::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 30px;
    height: 2px;
    background: var(--gradient);
    border-radius: 2px;
}

.see-all-btn {
    color: var(--primary-yellow);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 3px;
    font-size: 0.7rem;
}

.see-all-btn:hover {
    color: var(--primary-orange);
    transform: translateX(3px);
}

.carousel-container {
    position: relative;
    width: 100%;
    overflow: hidden;
    padding: 0 5px;
}

.carousel-track {
    display: flex;
    transition: transform 0.5s ease;
    gap: 8px;
    padding: 5px 0;
}

.movie-card {
    background: var(--card-bg);
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
    transition: all 0.3s ease;
    flex: 0 0 calc(25% - 7px);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    position: relative;
    border: 1px solid var(--border-color);
    min-width: 0;
}

.movie-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 10px rgba(229, 9, 20, 0.3);
    border-color: var(--primary-red);
}

.movie-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--gradient);
}

.thumbnail-container {
    width: 100%;
    height: 0;
    padding-bottom: 133.33%;
    overflow: hidden;
    position: relative;
    background: var(--filter-bg);
}

.movie-thumbnail {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}

.movie-card:hover .movie-thumbnail {
    transform: scale(1.03);
}

.movie-info {
    padding: 6px;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.movie-title {
    font-weight: bold;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 0.7rem;
    color: var(--text-color);
}

.movie-meta {
    display: flex;
    justify-content: flex-start;
    font-size: 0.6rem;
    margin-bottom: 5px;
    gap: 3px;
    flex-wrap: wrap;
}

.movie-meta span {
    background: rgba(255, 107, 53, 0.2);
    color: var(--primary-orange);
    padding: 1px 4px;
    border-radius: 2px;
    font-weight: 500;
    white-space: nowrap;
}

.rating-container {
    display: flex;
    align-items: center;
    margin-bottom: 5px;
    gap: 2px;
}

.stars {
    color: var(--primary-yellow);
    font-size: 0.6rem;
}

.rating-value {
    font-size: 0.55rem;
    color: #aaa;
}

.book-now-btn {
    background: var(--gradient);
    color: white;
    border: none;
    padding: 3px 6px;
    border-radius: 3px;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 100%;
    margin-top: auto;
    font-size: 0.6rem;
}

.book-now-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(229, 9, 20, 0.3);
}

.no-movies {
    text-align: center;
    padding: 15px;
    color: #aaa;
    font-size: 0.8rem;
}

.placeholder-icon {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: #444;
    font-size: 1.2rem;
}

.carousel-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(3px);
    border: none;
    border-radius: 50%;
    color: white;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 10;
    transition: all 0.3s ease;
}

.carousel-btn:hover {
    background: var(--primary-red);
    transform: translateY(-50%) scale(1.1);
}

.carousel-btn.prev {
    left: 0;
}

.carousel-btn.next {
    right: 0;
}

.rating-overlay {
    position: absolute;
    top: 4px;
    left: 4px;
    background: rgba(0, 0, 0, 0.7);
    padding: 2px 5px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 2px;
    z-index: 2;
}

.rating-overlay .stars {
    color: var(--primary-yellow);
    font-size: 0.55rem;
}

.rating-overlay .rating-value {
    color: white;
    font-size: 0.55rem;
    font-weight: bold;
}

/* Compact Filters section */
.filters-section {
    margin-bottom: 8px;
    padding: 6px;
    background: var(--filter-bg);
    border-radius: 4px;
    max-width: 100%;
    transition: all 0.3s ease;
}

.filter-group {
    margin-bottom: 5px;
}

.filter-group h4 {
    color: var(--text-color);
    font-size: 0.65rem;
    margin-bottom: 4px;
    font-weight: 600;
}

.filter-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
}

.filter-tag {
    padding: 2px 6px;
    background: var(--filter-bg);
    color: var(--filter-text);
    border-radius: 10px;
    font-size: 0.6rem;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid var(--border-color);
}

.filter-tag:hover {
    background: rgba(255, 107, 53, 0.2);
    border-color: var(--primary-orange);
}

.filter-tag.active {
    background: var(--filter-active-bg);
    color: var(--filter-active-text);
    border-color: var(--filter-active-bg);
}

.reset-filters {
    display: inline-block;
    padding: 3px 8px;
    background: transparent;
    color: var(--primary-orange);
    border: 1px solid var(--primary-orange);
    border-radius: 10px;
    font-size: 0.6rem;
    cursor: pointer;
    transition: all 0.2s ease;
    margin-top: 4px;
}

.reset-filters:hover {
    background: rgba(255, 107, 53, 0.2);
}

.active-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
    margin-bottom: 5px;
}

.active-filter-tag {
    padding: 2px 6px;
    background: var(--filter-active-bg);
    color: var(--filter-active-text);
    border-radius: 10px;
    font-size: 0.6rem;
    display: flex;
    align-items: center;
    gap: 3px;
}

.active-filter-tag .remove-filter {
    cursor: pointer;
    font-size: 0.55rem;
}

/* Make the entire card clickable */
.movie-card-link {
    text-decoration: none;
    color: inherit;
    display: block;
    height: 100%;
}

/* Movie Card Styling */
.movie-section {
    padding: 10px 0;
    background: var(--primary-bg);
    max-width: 100%;
    overflow: hidden;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding: 0 5px;
}

.section-header h2 {
    color: var(--text-color);
    font-weight: 700;
    margin: 0;
    position: relative;
    padding-bottom: 5px;
    font-size: 1rem;
}

.section-header h2::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 30px;
    height: 2px;
    background: var(--gradient-primary);
    border-radius: 2px;
}

.see-all-btn {
    color: var(--accent-orange);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 3px;
    font-size: 0.7rem;
}

.see-all-btn:hover {
    color: var(--accent-red);
    transform: translateX(3px);
}

.carousel-container {
    position: relative;
    width: 100%;
    overflow: hidden;
    padding: 0 5px;
}

.carousel-track {
    display: flex;
    transition: transform 0.5s ease;
    gap: 8px;
    padding: 5px 0;
}

.movie-card {
    background: var(--card-bg);
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
    transition: all 0.3s ease;
    flex: 0 0 calc(25% - 7px);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    position: relative;
    border: 1px solid var(--border-color);
    min-width: 0;
}

.movie-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 10px rgba(229, 9, 20, 0.3);
    border-color: var(--accent-red);
}

.movie-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--gradient-primary);
}

.thumbnail-container {
    width: 100%;
    height: 0;
    padding-bottom: 133.33%;
    overflow: hidden;
    position: relative;
    background: var(--secondary-bg);
}

.movie-thumbnail {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}

.movie-card:hover .movie-thumbnail {
    transform: scale(1.03);
}

.movie-info {
    padding: 6px;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.movie-title {
    font-weight: bold;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 0.7rem;
    color: var(--text-color);
}

.movie-meta {
    display: flex;
    justify-content: flex-start;
    font-size: 0.6rem;
    margin-bottom: 5px;
    gap: 3px;
    flex-wrap: wrap;
}

.movie-meta span {
    background: rgba(255, 107, 53, 0.2);
    color: var(--accent-orange);
    padding: 1px 4px;
    border-radius: 2px;
    font-weight: 500;
    white-space: nowrap;
}

.book-now-btn {
    background: var(--gradient-primary);
    color: white;
    border: none;
    padding: 3px 6px;
    border-radius: 3px;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 100%;
    margin-top: auto;
    font-size: 0.6rem;
}

.book-now-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(229, 9, 20, 0.3);
}

.no-movies {
    text-align: center;
    padding: 15px;
    color: #aaa;
    font-size: 0.8rem;
}

.placeholder-icon {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: #444;
    font-size: 1.2rem;
}

.carousel-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(3px);
    border: none;
    border-radius: 50%;
    color: white;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 10;
    transition: all 0.3s ease;
}

.carousel-btn:hover {
    background: var(--accent-red);
    transform: translateY(-50%) scale(1.1);
}

.carousel-btn.prev {
    left: 0;
}

.carousel-btn.next {
    right: 0;
}

.rating-overlay {
    position: absolute;
    top: 4px;
    left: 4px;
    background: rgba(0, 0, 0, 0.7);
    padding: 2px 5px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 2px;
    z-index: 2;
}

.rating-overlay .stars {
    color: var(--accent-orange);
    font-size: 0.55rem;
}

.rating-overlay .rating-value {
    color: white;
    font-size: 0.55rem;
    font-weight: bold;
}

.movie-card-link {
    text-decoration: none;
    color: inherit;
    display: block;
    height: 100%;
}

/* Filters Section */
.filters-section {
    margin-bottom: 8px;
    padding: 6px;
    background: var(--secondary-bg);
    border-radius: 4px;
    max-width: 100%;
    transition: all 0.3s ease;
}

.filter-group {
    margin-bottom: 5px;
}

.filter-group h4 {
    color: var(--text-color);
    font-size: 0.65rem;
    margin-bottom: 4px;
    font-weight: 600;
}

.filter-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
}

.filter-tag {
    padding: 2px 6px;
    background: var(--secondary-bg);
    color: var(--text-color);
    border-radius: 10px;
    font-size: 0.6rem;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid var(--border-color);
}

.filter-tag:hover {
    background: rgba(255, 107, 53, 0.2);
    border-color: var(--accent-orange);
}

.filter-tag.active {
    background: var(--accent-red);
    color: white;
    border-color: var(--accent-red);
}

.reset-filters {
    display: inline-block;
    padding: 3px 8px;
    background: transparent;
    color: var(--accent-orange);
    border: 1px solid var(--accent-orange);
    border-radius: 10px;
    font-size: 0.6rem;
    cursor: pointer;
    transition: all 0.2s ease;
    margin-top: 4px;
}

.reset-filters:hover {
    background: rgba(255, 107, 53, 0.2);
}

.active-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
    margin-bottom: 5px;
}

.active-filter-tag {
    padding: 2px 6px;
    background: var(--accent-red);
    color: white;
    border-radius: 10px;
    font-size: 0.6rem;
    display: flex;
    align-items: center;
    gap: 3px;
}

.active-filter-tag .remove-filter {
    cursor: pointer;
    font-size: 0.55rem;
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

:root {
    --primary-black: #1a1a1a;
    --primary-red: #d62828;
    --primary-orange: #f77f00;
    --primary-yellow: #fcbf49;
    --primary-peach: #ffd8a6;
    --dark-red: #9e2a2b;
    --light-peach: #fff3e6;
    
    /* Dark mode variables */
    --dm-bg: #121212;
    --dm-card-bg: #1e1e1e;
    --dm-text: #e0e0e0;
    --dm-text-muted: #a0a0a0;
    --dm-border: #333;
    --dm-shadow: rgba(0, 0, 0, 0.3);
    --dm-header-bg: #1a1a1a;
    --dm-badge-bg: #333;
    --dm-movie-title: #ffffff;
    --dm-movie-subtitle: #bbbbbb;
    --dm-movie-meta: #fcbf49;
}

body {
    background-color: var(--light-peach);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: var(--primary-black);
    transition: background-color 0.3s ease, color 0.3s ease;
    font-size: 0.9rem;
}

/* Dark mode styles */
body.dark-mode {
    background-color: var(--dm-bg);
    color: var(--dm-text);
}

/* Movie Header - Enhanced Dark Mode */
.dark-mode .movie-header {
    background: linear-gradient(135deg, var(--dm-header-bg) 0%, #2c2c2c 100%);
    color: var(--dm-text);
    border-left: 4px solid var(--primary-orange);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
}

.light-mode .movie-header {
    background: white;
    color: white;
    border-left: 4px solid var(--primary-red);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
}

.light-mode .movie-title{
    color: var(--primary-black) !important;
}

.light-mode .section-title{
    color: var(--primary-orange)
}

.dark-mode .movie-title {
    color: var(--dm-movie-title) !important;
}

.dark-mode .movie-title small {
    color: var(--dm-movie-subtitle) !important;
}

.light-mode .movie-title small {
    color: var(--primary-peach) !important;
}

.dark-mode .movie-title:after {
    background: var(--primary-orange);
}

.dark-mode .movie-header .text {
    color: var(--dm-movie-subtitle) !important;
}

.light-mode .movie-header .text {
    color: var(--primary-peach) !important;
}

.dark-mode .movie-meta {
    color: var(--dm-movie-meta) !important;
}

.light-mode .movie-meta {
    color: var(--primary-peach) !important;
}

.dark-mode .badge.bg-dark {
    background-color: var(--dm-badge-bg) !important;
    color: white !important;
}

.dark-mode .badge.bg-danger {
    background-color: var(--primary-red) !important;
    color: white !important;
}

.dark-mode .badge.bg-warning {
    background-color: var(--primary-yellow) !important;
    color: var(--primary-black) !important;
}

/* Rest of the dark mode styles */
.dark-mode .date-header,
.dark-mode .showtime-slot,
.dark-mode .no-shows {
    background-color: var(--dm-card-bg);
    border-color: var(--dm-border);
    color: var(--dm-text);
    box-shadow: 0 2px 8px var(--dm-shadow);
}

.dark-mode .date-day {
    color: var(--dm-text-muted);
}

.dark-mode .date-date {
    color: var(--dm-text);
}

.dark-mode .selected-date {
    background-color: rgba(247, 127, 0, 0.2);
}

.dark-mode .today-date {
    background-color: rgba(252, 191, 73, 0.1);
}

.dark-mode .venue-name,
.dark-mode .availability-info {
    color: var(--dm-text-muted);
}

.dark-mode .time-slot {
    color: var(--dm-text) !important;
}

.dark-mode .section-title {
    color: var(--dm-text);
}

.dark-mode .section-title:after {
    background: linear-gradient(90deg, var(--primary-orange), var(--primary-yellow));
}

.dark-mode .price-amount {
    background: rgba(214, 40, 40, 0.2);
    color: var(--primary-peach) !important;
}

.dark-mode .today-badge {
    background-color: var(--primary-orange);
    color: var(--primary-black);
}

.dark-mode .spinner-border {
    border-color: var(--dm-text-muted);
    border-right-color: transparent;
}

.container {
    max-width: 1200px;
    padding: 1rem;
    position: relative;
    z-index: 1;
}

/* Movie Header - Base Styles */
.movie-header {
    margin-top: 100px;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
}

.movie-header:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.movie-title {
    font-weight: 700;
    margin-bottom: 0.25rem;
    position: relative;
    display: inline-block;
    transition: color 0.3s ease;
    font-size: 1.5rem;
}

.movie-title:after {
    content: '';
    position: absolute;
    bottom: -4px;
    left: 0;
    width: 40px;
    height: 2px;
    background: var(--primary-orange);
    transition: background 0.3s ease;
}

/* Rest of the base styles */
.date-header {
    background-color: white;
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e0e0e0;
}

.date-cell {
    text-align: center;
    padding: 0.5rem 0.25rem;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    border-radius: 6px;
    position: relative;
    margin: 0 1px;
}

.date-cell:hover {
    background-color: var(--primary-peach);
    transform: translateY(-1px);
}

.date-day {
    font-size: 0.8rem;
    color: #666;
    font-weight: 500;
}

.date-date {
    font-weight: 600;
    font-size: 1rem;
    color: var(--primary-black);
}

.selected-date {
    background-color: var(--primary-peach);
    border-bottom: 2px solid var(--primary-orange);
    box-shadow: 0 2px 4px rgba(247, 127, 0, 0.2);
}

.today-date {
    background-color: rgba(252, 191, 73, 0.2);
}

.today-badge {
    position: absolute;
    top: -6px;
    right: 6px;
    font-size: 0.55rem;
    background: var(--primary-orange);
    color: white;
    padding: 1px 4px;
    border-radius: 8px;
    font-weight: 600;
}

.venue-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 1rem;
}

.showtime-slot {
    background: white;
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 0;
    height: 140px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    border: 1px solid #eee;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    position: relative;
    overflow: hidden;
}

.showtime-slot:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: linear-gradient(90deg, var(--primary-red), var(--primary-orange));
}

.showtime-slot:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-color: var(--primary-yellow);
}

.showtime-header {
    display: flex;
    flex-direction: column;
    margin-bottom: 0.25rem;
}

.time-slot {
    font-weight: 600;
    font-size: 1rem;
    color: var(--primary-black);
    margin-bottom: 0.25rem;
    text-align: center;
}

.venue-name {
    font-size: 0.75rem;
    color: #666;
    text-align: center;
    margin-bottom: 0.25rem;
}

.price-info {
    text-align: center;
    margin: 0.25rem 0;
}

.price-amount {
    font-weight: 600;
    color: var(--primary-red);
    font-size: 0.9rem;
    background: rgba(214, 40, 40, 0.1);
    padding: 0.2rem 0.4rem;
    border-radius: 3px;
    display: inline-block;
}

.availability-info {
    margin-top: auto;
    text-align: center;
    font-size: 0.75rem;
    color: #666;
}

.book-btn {
    width: 100%;
    padding: 0.4rem;
    font-size: 0.8rem;
    font-weight: 600;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-yellow));
    border: none;
    color: var(--primary-black);
    border-radius: 5px;
    transition: all 0.3s ease;
    margin-top: 0.25rem;
    box-shadow: 0 1px 3px rgba(247, 127, 0, 0.3);
}

.book-btn:hover {
    background: linear-gradient(135deg, var(--primary-red), var(--primary-orange));
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(214, 40, 40, 0.3);
}

.book-btn:active {
    transform: translateY(0);
}

.loading-spinner {
    width: 2.5rem;
    height: 2.5rem;
    border: 0.3em solid var(--primary-orange);
    border-right-color: transparent;
}

.no-shows {
    grid-column: 1 / -1;
    text-align: center;
    padding: 2rem;
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-left: 3px solid var(--primary-red);
}

.no-shows-title {
    color: var(--primary-red);
    font-weight: 600;
    margin-bottom: 0.75rem;
    font-size: 1.1rem;
}

.section-title {
    font-weight: 600;
    color: var(--primary-black);
    margin-bottom: 1rem;
    position: relative;
    padding-bottom: 0.4rem;
    font-size: 1.2rem;
}

.section-title:after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 50px;
    height: 2px;
    background: linear-gradient(90deg, var(--primary-red), var(--primary-orange));
}

/* Search results styling */
:root {
    /* Light mode search results */
    --search-result-bg: #ffffff;
    --search-result-text: #141414;
    --search-result-hover: #f8f9fa;
    --search-result-border: #dee2e6;
    
    /* Dark mode search results */
    --dm-search-result-bg: #1e1e1e;
    --dm-search-result-text: #e0e0e0;
    --dm-search-result-hover: rgba(255,255,255,0.05);
    --dm-search-result-border: #333;
}

.dark-mode .search-results {
    background-color: var(--dm-search-result-bg);
    color: var(--dm-search-result-text);
    border-color: var(--dm-search-result-border);
}

.dark-mode .search-result-item {
    color: var(--dm-search-result-text);
}

.dark-mode .search-result-item:hover {
    background-color: var(--dm-search-result-hover);
}

@media (max-width: 768px) {
    .venue-container {
        grid-template-columns: 1fr;
    }
    
    .date-header {
        overflow-x: auto;
        white-space: nowrap;
        display: flex;
        padding-bottom: 0.5rem;
    }
    
    .date-cell {
        min-width: 70px;
        display: inline-block;
        margin-right: 0.4rem;
        flex-shrink: 0;
    }
    
    .movie-header {
        padding: 1rem;
    }
    
    .movie-title {
        font-size: 1.3rem;
    }
    
    .section-title {
        font-size: 1.1rem;
    }
    
    .showtime-slot {
        height: 130px;
    }
}

@media (max-width: 576px) {
    .container {
        padding: 0.75rem;
    }
    
    .movie-header {
        padding: 1rem 0.75rem;
    }
    
    .movie-title {
        font-size: 1.2rem;
    }
    
    .date-cell {
        min-width: 60px;
        padding: 0.4rem 0.2rem;
    }
    
    .date-day {
        font-size: 0.7rem;
    }
    
    .date-date {
        font-size: 0.9rem;
    }
    
    .section-title {
        font-size: 1rem;
    }
    
    .showtime-slot {
        height: 120px;
        padding: 0.6rem;
    }
    
    .time-slot {
        font-size: 0.9rem;
    }
    
    .book-btn {
        font-size: 0.75rem;
    }
}

/* Animation for loading */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

.showtime-slot {
    animation: fadeIn 0.3s ease forwards;
    opacity: 0.1;
}

/* Delay animations for each item */
.showtime-slot:nth-child(1) { animation-delay: 0.1s; }
.showtime-slot:nth-child(2) { animation-delay: 0.2s; }
.showtime-slot:nth-child(3) { animation-delay: 0.3s; }
.showtime-slot:nth-child(4) { animation-delay: 0.4s; }
.showtime-slot:nth-child(5) { animation-delay: 0.5s; }
.showtime-slot:nth-child(6) { animation-delay: 0.6s; }
.showtime-slot:nth-child(7) { animation-delay: 0.7s; }
.showtime-slot:nth-child(8) { animation-delay: 0.8s; }



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
<div class="container my-4">
        <?php if (!$isLoggedIn): ?>
            <div class="text-center py-5">
                <h2>Welcome to Catchify </h2>
                <p class="lead">Please <a href="<?= $login_page  ?>">login</a> to access all features</p>
            </div>
            
        <?php elseif (!empty($_SESSION['selected_city'])): ?>
            
            <?php if (isset($_POST['city'])): ?>
                <div class="alert alert-info mb-4" id="cityAlert">
                Showing content for: <strong><?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?></strong>
                </div>  
            <?php endif; ?>
            
            <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                <!-- Search Results Section -->
                <!-- Your existing search results code remains the same -->
                
            <?php else: ?>
                <!-- Now Showing Section -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <!-- <h3 class="mb-4">Now Showing in <?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?></h3>
                         -->
                        <?php if (!empty($nowShowing)): ?>
                            <!-- Your existing now showing content -->
                             
                        <?php else: ?>
                            <!-- <div class="alert alert-warning">
                                No events currently showing in <?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?>
                            </div> -->

                                            <!-- Movie Section -->
                
                            
    <!-- Date Navigation -->
    <div class="date-header">
        <h5 class="section-title mb-3">Select Date</h5>
        <div class="row g-0">
            <?php foreach ($dates as $index => $date): 
    $date->setTime(0, 0, 0); // Ensure time is set to midnight for comparison
    $isSelected = $date->format('Y-m-d') === $selectedDate->format('Y-m-d');
    $isToday = $date->format('Y-m-d') === $today->format('Y-m-d');
    $dateParam = $date->format('Y-m-d');
    $dayName = $date->format('D');
    $dateNum = $date->format('d');
    $monthName = $date->format('M');
?>
                <div class="col date-cell <?= $isSelected ? 'selected-date' : '' ?> <?= $isToday ? 'today-date' : '' ?>" 
     data-date="<?= $dateParam ?>"
     onclick="loadShowtimes('<?= $dateParam ?>')">
    <div class="date-day"><?= $dayName ?></div>
    <div class="date-date"><?= $dateNum ?> <?= $monthName ?></div>
    <?= $isToday ? '<div class="today-badge">Today</div>' : '' ?>
</div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Venue Selection -->
    <div class="venue-selection mb-4">
        <h5 class="section-title">Select Venue</h5>
        <div class="row g-2">
            <?php foreach ($venues as $venue): ?>
                <div class="col-md-3 col-6">
                    <button class="btn btn-outline-secondary w-100 venue-btn" 
                            data-venue-id="<?= $venue['venue_id'] ?>"
                            onclick="selectVenue(<?= $venue['venue_id'] ?>)">
                        <?= htmlspecialchars($venue['venue_name']) ?>
                        <?= htmlspecialchars($venue['sub_venue_name']) ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Showtimes Container -->
    <h5 class="section-title">Available Showtimes</h5>
    <div id="showtimesContainer-<?= $event['event_id'] ?>">
        
    <?php
$url = $venue_showtimes . '?date=' . urlencode($selectedDate->format('Y-m-d')) .
       '&venue_id=' . (isset($_GET['venue_id']) ? $_GET['venue_id'] : $venues[0]['venue_id']);

include $url;
?>

</div>
    </div>

                            
                        <?php endif; ?>
                    </div>
                </div>
                


                
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-warning">
                Please select a city to see local events and movies
            </div>
        <?php endif; ?>
    </div>


    <!-- Bootstrap JS Bundle with Popper -->
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
                fetch(`<?=$search;?>?search=${encodeURIComponent(searchTerm)}`)
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
            // Movie Carousel functionality
        const track = document.getElementById('carouselTrack');
        const cards = document.querySelectorAll('.movie-card');
        const prevBtn = document.querySelector('.carousel-btn.prev');
        const nextBtn = document.querySelector('.carousel-btn.next');
        
        if (track && cards.length) {
            const cardsPerView = 4; // Always show 4 cards
            let currentIndex = 0;
            let cardWidth = cards[0].offsetWidth + 8; // width + gap
            
            function updateCarousel() {
                // Recalculate card width on each update
                const containerWidth = track.parentElement.offsetWidth;
                const gap = parseFloat(getComputedStyle(track).gap.replace('px', '')) || 8;
                const newCardWidth = (containerWidth / cardsPerView) - gap;
                
                // Apply new width to all cards
                cards.forEach(card => {
                    card.style.flex = `0 0 ${newCardWidth}px`;
                });
                
                // Recalculate card width after resizing
                cardWidth = newCardWidth + gap;
                
                // If we have less than 4 cards, don't center them - keep them left-aligned
                if (cards.length < cardsPerView) {
                    track.style.transform = 'translateX(0)';
                    prevBtn.style.display = 'none';
                    nextBtn.style.display = 'none';
                    return;
                }
                
                const newPosition = -currentIndex * cardWidth * cardsPerView;
                track.style.transform = `translateX(${newPosition}px)`;
                
                // Disable/enable buttons based on position
                prevBtn.disabled = currentIndex === 0;
                nextBtn.disabled = currentIndex >= Math.ceil(cards.length / cardsPerView) - 1;
                
                // Special handling for last group - ensure we don't show partial cards
                const maxIndex = Math.ceil(cards.length / cardsPerView) - 1;
                if (currentIndex === maxIndex && (cards.length % cardsPerView !== 0)) {
                    // If we're at the last index and it's not a full set of cards
                    // Adjust the position to show the last cards aligned to the end
                    const visibleCards = cards.length % cardsPerView;
                    const adjustment = (cardsPerView - visibleCards) * cardWidth;
                    track.style.transform = `translateX(calc(${newPosition}px + ${adjustment}px))`;
                }
            }
            
            prevBtn.addEventListener('click', function() {
                if (currentIndex > 0) {
                    currentIndex--;
                    updateCarousel();
                }
            });
            
            nextBtn.addEventListener('click', function() {
                const maxIndex = Math.ceil(cards.length / cardsPerView) - 1;
                if (currentIndex < maxIndex) {
                    currentIndex++;
                    updateCarousel();
                }
            });
            
            // Handle responsive changes
            function handleResize() {
                updateCarousel();
            }
            
            window.addEventListener('resize', handleResize);
            
            // Initialize
            updateCarousel();
        }
        
        // Movie Filter functionality
        const filterTags = document.querySelectorAll('.filter-tag');
        const removeFilters = document.querySelectorAll('.remove-filter');
        const resetFiltersBtn = document.getElementById('resetFilters');
        
        function updateFilters() {
            const urlParams = new URLSearchParams(window.location.search);
            const languages = urlParams.get('language') ? urlParams.get('language').split(',') : [];
            const genres = urlParams.get('genre') ? urlParams.get('genre').split(',') : [];
            
            // Update active state of filter tags
            filterTags.forEach(tag => {
                const type = tag.dataset.type;
                const value = tag.dataset.value;
                
                if ((type === 'language' && languages.includes(value)) || 
                    (type === 'genre' && genres.includes(value))) {
                    tag.classList.add('active');
                } else {
                    tag.classList.remove('active');
                }
            });
        }
        
        function toggleFilter(type, value) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentFilters = urlParams.get(type) ? urlParams.get(type).split(',') : [];
            
            const filterIndex = currentFilters.indexOf(value);
            if (filterIndex === -1) {
                // Add filter
                currentFilters.push(value);
            } else {
                // Remove filter
                currentFilters.splice(filterIndex, 1);
            }
            
            if (currentFilters.length > 0) {
                urlParams.set(type, currentFilters.join(','));
            } else {
                urlParams.delete(type);
            }
            
            window.location.search = urlParams.toString();
        }
        
        // Add click event to filter tags
        filterTags.forEach(tag => {
            tag.addEventListener('click', function() {
                const type = this.dataset.type;
                const value = this.dataset.value;
                toggleFilter(type, value);
            });
        });
        
        // Add click event to remove filter buttons
        removeFilters.forEach(btn => {
            btn.addEventListener('click', function() {
                const type = this.dataset.type;
                const value = this.dataset.value;
                toggleFilter(type, value);
            });
        });
        
        // Add click event to reset all filters button
        if (resetFiltersBtn) {
            resetFiltersBtn.addEventListener('click', function() {
                window.location.search = '';
            });
        }
        
        

        });

        function loadShowtimes(dateStr, venueId) {
    // Ensure we have a venue ID
    venueId = venueId || <?= !empty($venues) ? $venues[0]['venue_id'] : 0 ?>;
    
    // Update URL without reloading
    const newUrl = new URL(window.location.href);
    newUrl.searchParams.set('date', dateStr);
    if (venueId) {
        newUrl.searchParams.set('venue_id', venueId);
    }
    window.history.pushState({}, '', newUrl);
    
    // Update active date in the UI
    document.querySelectorAll('.date-cell').forEach(cell => {
        cell.classList.remove('selected-date');
        if (cell.getAttribute('data-date') === dateStr) {
            cell.classList.add('selected-date');
        }
    });
    
    // Load showtimes for each event
    document.querySelectorAll('[id^="showtimesContainer-"]').forEach(container => {
        const eventId = container.id.split('-')[1];
        fetch(`<?=$venue_showtimes;?>?date=${dateStr}&venue_id=${venueId}`)
            .then(response => response.text())
            .then(data => {
                container.innerHTML = data;
            })
            .catch(error => {
                container.innerHTML = `
                    <div class="no-shows">
                        <h5 class="no-shows-title">Error loading showtimes</h5>
                        <p>${error.message}</p>
                    </div>
                `;
            });
    });
}

        function selectVenue(venueId) {
            const urlParams = new URLSearchParams(window.location.search);
            const dateParam = urlParams.get('date') || new Date().toISOString().split('T')[0];
            loadShowtimes(dateParam, venueId);
            
            document.querySelectorAll('.venue-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.getAttribute('data-venue-id') == venueId) {
                    btn.classList.add('active');
                }
            });
        }

        // Load initial showtimes
        document.addEventListener('DOMContentLoaded', function() {
            
            const urlParams = new URLSearchParams(window.location.search);
    const dateParam = urlParams.get('date') || new Date().toISOString().split('T')[0];
    const venueParam = urlParams.get('venue_id') || <?= !empty($venues) ? $venues[0]['venue_id'] : 0 ?>;
    
    // Set the active date in the UI
    document.querySelectorAll('.date-cell').forEach(cell => {
        if (cell.getAttribute('data-date') === dateParam) {
            cell.classList.add('selected-date');
        }
    });
    
    loadShowtimes(dateParam, venueParam);
            
        });

            
        document.addEventListener('DOMContentLoaded', function() {

            // Initialize tabs
    const tabElms = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabElms.forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', function(event) {
            // Refresh iframe when tab is shown
            const target = event.target.getAttribute('data-bs-target');
            const iframe = document.querySelector(`${target} iframe`);
            if(iframe) {
                iframe.src = iframe.src; // Refresh
            }
        });
    });
    
    // Handle iframe resizing
    window.addEventListener('message', function(e) {
        const iframes = document.querySelectorAll('.tab-content iframe');
        iframes.forEach(iframe => {
            if(e.source === iframe.contentWindow) {
                iframe.style.height = e.data.height + 'px';
            }
        });
    }, false);
});

        

    </script>
</body>
</html>