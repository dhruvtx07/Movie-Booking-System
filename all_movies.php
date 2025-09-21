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
                              LIMIT 10");
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

// Pagination variables
$moviesPerPage = 12;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $moviesPerPage;

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

        $sql .= " GROUP BY ei.event_id ORDER BY ei.created_on DESC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':city_id', $_SESSION['selected_city'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $moviesPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

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

        // Get count for pagination
        $countSql = "SELECT COUNT(DISTINCT ei.event_id) as total
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

        if (!empty($selected_languages)) {
            $countSql .= " AND (" . implode(" OR ", $language_conditions) . ")";
        }

        if (!empty($selected_genres)) {
            $countSql .= " AND (" . implode(" OR ", $genre_conditions) . ")";
        }

        $countStmt = $pdo->prepare($countSql);
        $countStmt->bindParam(':city_id', $_SESSION['selected_city'], PDO::PARAM_INT);

        foreach ($selected_languages as $lang) {
            $countStmt->bindValue(":lang_$lang", $lang);
        }

        foreach ($selected_genres as $genre) {
            $countStmt->bindValue(":genre_$genre", "%$genre%");
        }

        $countStmt->execute();
        $totalMovies = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        $totalPages = ceil($totalMovies / $moviesPerPage);

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
    --border-color: #e0e0e0;
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
    --border-color: #333;
}

body {
    padding-top: 110px;
    background-color: var(--primary-bg);
    color: var(--text-color);
    transition: all 0.3s ease;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1030;
}

.second-navbar {
    background-color: var(--nav-dark) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    top: 54px;
    left: 0;
    right: 0;
    z-index: 1020;
    white-space: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    margin-top: 0;
    border-top: none;
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

.fade-out {
    animation: fadeOut 3s ease-out forwards;
}

@keyframes fadeOut {
    0% { opacity: 1; }
    100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }
}

/* Movie Grid Styles */
.movie-section {
    padding: 10px 0;
    background: var(--primary-bg);
    max-width: 100%;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 0 5px;
}

.section-header h2 {
    color: var(--text-color);
    font-weight: 700;
    margin: 0;
    position: relative;
    padding-bottom: 5px;
    font-size: 1.5rem;
}

.section-header h2::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 50px;
    height: 3px;
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
    gap: 5px;
    font-size: 1rem;
}

.see-all-btn:hover {
    color: var(--accent-red);
    transform: translateX(3px);
}

.movie-card {
    background: var(--card-bg);
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    position: relative;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
    height: 100%;
}

.movie-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(229, 9, 20, 0.2);
    border-color: var(--accent-red);
}

.movie-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--gradient-primary);
}

.thumbnail-container {
    width: 100%;
    height: 0;
    padding-bottom: 150%;
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
    transform: scale(1.05);
}

.movie-info {
    padding: 12px;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.movie-title {
    font-weight: bold;
    margin-bottom: 8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 1rem;
    color: var(--text-color);
}

.movie-meta {
    display: flex;
    justify-content: flex-start;
    font-size: 0.8rem;
    margin-bottom: 10px;
    gap: 5px;
    flex-wrap: wrap;
}

.movie-meta span {
    background: rgba(255, 107, 53, 0.2);
    color: var(--accent-orange);
    padding: 3px 8px;
    border-radius: 4px;
    font-weight: 500;
    white-space: nowrap;
}

.book-now-btn {
    background: var(--gradient-primary);
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 4px;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 100%;
    margin-top: 10px;
    font-size: 0.9rem;
}

.book-now-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
}

.no-movies {
    text-align: center;
    padding: 30px;
    color: #aaa;
    font-size: 1.2rem;
}

.placeholder-icon {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: #444;
    font-size: 2rem;
}

.rating-overlay {
    position: absolute;
    top: 8px;
    left: 8px;
    background: rgba(0, 0, 0, 0.7);
    padding: 3px 8px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 3px;
    z-index: 2;
}

.rating-overlay .stars {
    color: var(--accent-orange);
    font-size: 0.8rem;
}

.rating-overlay .rating-value {
    color: white;
    font-size: 0.8rem;
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
    margin-bottom: 20px;
    padding: 12px;
    background: var(--secondary-bg);
    border-radius: 8px;
    max-width: 100%;
    transition: all 0.3s ease;
}

.filter-group {
    margin-bottom: 10px;
}

.filter-group h4 {
    color: var(--text-color);
    font-size: 0.9rem;
    margin-bottom: 8px;
    font-weight: 600;
}

.filter-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.filter-tag {
    padding: 5px 10px;
    background: var(--secondary-bg);
    color: var(--text-color);
    border-radius: 15px;
    font-size: 0.8rem;
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
    padding: 5px 12px;
    background: transparent;
    color: var(--accent-orange);
    border: 1px solid var(--accent-orange);
    border-radius: 15px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s ease;
    margin-top: 8px;
}

.reset-filters:hover {
    background: rgba(255, 107, 53, 0.2);
}

.active-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 10px;
}

.active-filter-tag {
    padding: 5px 10px;
    background: var(--accent-red);
    color: white;
    border-radius: 15px;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 5px;
}

.active-filter-tag .remove-filter {
    cursor: pointer;
    font-size: 0.7rem;
}

.load-more-container {
    text-align: center;
    margin-top: 30px;
    margin-bottom: 30px;
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
        margin-top: 40px;
    }
    
    .container.my-4 {
        margin-top: 1rem !important;
        padding-top: 0.5rem;
    }
    
    .movie-card {
        margin-bottom: 15px;
    }
    
    .movie-title {
        font-size: 0.9rem;
    }
    
    .movie-meta span {
        font-size: 0.7rem;
        padding: 2px 6px;
    }
    
    .book-now-btn {
        padding: 6px 10px;
        font-size: 0.8rem;
    }
    
    .section-header h2 {
        font-size: 1.2rem;
    }
    
    .see-all-btn {
        font-size: 0.9rem;
    }
}

@media (max-width: 576px) {
    .thumbnail-container {
        padding-bottom: 133.33%;
    }
    
    .movie-info {
        padding: 8px;
    }
    
    .movie-title {
        font-size: 0.8rem;
    }
    
    .movie-meta span {
        font-size: 0.65rem;
        padding: 1px 4px;
    }
    
    .book-now-btn {
        padding: 4px 8px;
        font-size: 0.7rem;
    }
    
    .section-header h2 {
        font-size: 1rem;
    }
    
    .see-all-btn {
        font-size: 0.8rem;
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
                        <h3 class="mb-4">Now Showing in <?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?></h3>
                        
                        <?php if (!empty($nowShowing)): ?>
                            <!-- Your existing now showing content -->
                             
                        <?php else: ?>
                            <!-- Movie Section -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="movie-section">
                                        <div class="section-header">
                                            <h2>Ongoing Movies</h2>
                                            <?php if ($totalPages > 1 && $currentPage < $totalPages): ?>
                                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $currentPage + 1])) ?>" class="see-all-btn">See More Movies <i class="fas fa-chevron-right"></i></a>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Compact Filters Section -->
                                        <div class="filters-section">
                                            <?php if (!empty($selected_languages) || !empty($selected_genres)): ?>
                                                <div class="active-filters">
                                                    <?php foreach ($selected_languages as $lang): ?>
                                                        <div class="active-filter-tag">
                                                            <?php echo htmlspecialchars($lang); ?>
                                                            <span class="remove-filter" data-type="language" data-value="<?php echo htmlspecialchars($lang); ?>">
                                                                <i class="fas fa-times"></i>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($selected_genres as $genre): ?>
                                                        <div class="active-filter-tag">
                                                            <?php echo htmlspecialchars($genre); ?>
                                                            <span class="remove-filter" data-type="genre" data-value="<?php echo htmlspecialchars($genre); ?>">
                                                                <i class="fas fa-times"></i>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="filter-group">
                                                <h4>Languages</h4>
                                                <div class="filter-tags" id="languageFilters">
                                                    <?php foreach ($all_languages as $language): ?>
                                                        <div class="filter-tag <?php echo in_array($language, $selected_languages) ? 'active' : ''; ?>" 
                                                            data-type="language" 
                                                            data-value="<?php echo htmlspecialchars($language); ?>">
                                                            <?php echo htmlspecialchars($language); ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <div class="filter-group">
                                                <h4>Genres</h4>
                                                <div class="filter-tags" id="genreFilters">
                                                    <?php foreach ($all_genres as $genre): ?>
                                                        <div class="filter-tag <?php echo in_array($genre, $selected_genres) ? 'active' : ''; ?>" 
                                                            data-type="genre" 
                                                            data-value="<?php echo htmlspecialchars($genre); ?>">
                                                            <?php echo htmlspecialchars($genre); ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($selected_languages) || !empty($selected_genres)): ?>
                                                <button class="reset-filters" id="resetFilters">Reset All Filters</button>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="row">
                                            <?php if (!empty($movies)): ?>
                                                <?php foreach ($movies as $row): 
                                                    $event_id = $row['event_id'];
                                                    // Get average rating for the event
                                                    $rating_sql = "SELECT AVG(user_gave) as avg_rating, COUNT(*) as rating_count 
                                                                FROM event_ratings 
                                                                WHERE event_id = $event_id AND is_active = 'yes'";
                                                    $rating_stmt = $pdo->query($rating_sql);
                                                    $rating_data = $rating_stmt->fetch(PDO::FETCH_ASSOC);
                                                    $avg_rating = $rating_data['avg_rating'] ?? 0;
                                                    $rating_count = $rating_data['rating_count'] ?? 0;
                                                    
                                                    // Calculate star rating
                                                    $full_stars = floor($avg_rating);
                                                    $has_half_star = ($avg_rating - $full_stars) >= 0.5;
                                                    $empty_stars = 5 - $full_stars - ($has_half_star ? 1 : 0);
                                                ?>
                                                    <div class="col-md-3 col-sm-6 mb-4">
                                                        <div class="movie-card">
                                                            <a href="<?= $event_info_page  ?>?id=<?= $row['event_id'] ?>" class="movie-card-link">
                                                                <div class="thumbnail-container">
                                                                    <?php if (!empty($row['photo'])): ?>
                                                                        <img src="<?= $row['photo'] ?>" alt="<?= htmlspecialchars($row['event_name']) ?>" class="movie-thumbnail" onerror="this.onerror=null;this.src='data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 200 300\"%3E%3Crect width=\"200\" height=\"300\" fill=\"%232a2a2a\"/%3E%3Ctext x=\"100\" y=\"150\" font-family=\"Arial\" font-size=\"16\" fill=\"%23666\" text-anchor=\"middle\"%3ENo Image%3C/text%3E%3C/svg%3E'">
                                                                    <?php else: ?>
                                                                        <div class="placeholder-icon">
                                                                            <i class="fas fa-film"></i>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    
                                                                    <?php if ($avg_rating > 0): ?>
                                                                        <div class="rating-overlay">
                                                                            <div class="stars">
                                                                                <?php 
                                                                                    // Full stars
                                                                                    for ($i = 0; $i < $full_stars; $i++) {
                                                                                        echo '<i class="fas fa-star"></i>';
                                                                                    }
                                                                                    
                                                                                    // Half star
                                                                                    if ($has_half_star) {
                                                                                        echo '<i class="fas fa-star-half-alt"></i>';
                                                                                    }
                                                                                    
                                                                                    // Empty stars
                                                                                    for ($i = 0; $i < $empty_stars; $i++) {
                                                                                        echo '<i class="far fa-star"></i>';
                                                                                    }
                                                                                ?>
                                                                            </div>
                                                                            <div class="rating-value"><?= number_format($avg_rating, 1) ?></div>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="movie-info">
                                                                    <div>
                                                                        <div class="movie-title" title="<?= htmlspecialchars($row['event_name']) ?>"><?= htmlspecialchars($row['event_name']) ?></div>
                                                                        <div class="movie-meta">
                                                                            <?php if (!empty($row['genre'])): ?>
                                                                                <span><?= htmlspecialchars($row['genre']) ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($row['event_language'])): ?>
                                                                                <span><?= htmlspecialchars($row['event_language']) ?></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <button class="book-now-btn">
                                                                        Book Now
                                                                    </button>
                                                                </div>
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="col-12">
                                                    <div class="no-movies">No movies found matching your filters.</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($totalPages > 1 && $currentPage < $totalPages): ?>
                                            <div class="text-center mt-4">
                                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $currentPage + 1])) ?>" class="btn btn-danger">
                                                    See More Movies <i class="fas fa-chevron-down ms-2"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
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

    <footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Catchify. All rights reserved.</p>
        </div>
    </footer>


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
        fetch(`search_ajax.php?search=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                if (data.length > 0) {
                    let html = '';
                    data.forEach(item => {
                        html += `
                            <a href="eventdex.php?id=${item.event_id}" class="search-result-item">
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
        
        // Reset to page 1 when filters change
        urlParams.delete('page');
        
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
    
    // Fade out city alert after 3 seconds
    const cityAlert = document.getElementById('cityAlert');
    if (cityAlert) {
        setTimeout(() => {
            cityAlert.classList.add('fade-out');
        }, 3000);
    }
});
    </script>
</body>
</html>

