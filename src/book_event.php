<?php
session_start();
require_once 'config/db_config.php';

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
        $stmt = $pdo->prepare("SELECT e.event_id, e.event_name, e.event_image, e.event_type, 
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BookMyShow Clone</title>
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
    
    /* Light mode search results */
    --search-result-bg: #ffffff;
    --search-result-text: #141414;
    --search-result-hover: #f8f9fa;
    --search-result-border: #dee2e6;
    
    /* Dark mode variables */
    --dm-bg: #121212;
    --dm-card-bg: #1e1e1e;
    --dm-text: #e0e0e0;
    --dm-border: #333;
    --dm-header-bg: #1a1a1a;
}



        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--primary-black);
            transition: background-color 0.3s ease, color 0.3s ease;
            font-size: 0.9rem;
            
        }
body.dark-mode {
    background-color: var(--dm-bg);
    color: var(--dm-text);
    --search-result-bg: var(--dm-card-bg);
    --search-result-text: var(--dm-text);
    --search-result-hover: rgba(255,255,255,0.05);
    --search-result-border: var(--dm-border);
}

        /* Navbar styles */
        .top-navbar {
            background-color: var(--nav-dark) !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .top-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            
        }

        .second-navbar {
            margin-top: 80px;
            background-color: var(--nav-dark) !important;
            position: fixed;
            top: 100px;
            left: 0;
            right: 0;
            display: block !important;
            white-space: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            margin-bottom: 40px;
            z-index: 100;
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

        /* Second Navbar Link Styles */
.second-navbar .nav-link {
    color: var(--nav-text);
    position: relative;
    padding: 0.5rem 1rem;
    white-space: nowrap;
    transition: all 0.3s ease;
}

.second-navbar .nav-link::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    width: 0;
    height: 2px;
    background: var(--gradient-primary);
    transition: all 0.3s ease;
    transform: translateX(-50%);
    opacity: 0;
}

.second-navbar .nav-link:hover::after,
.second-navbar .nav-link.active-nav:hover::after {
    width: 100%;
    opacity: 1;
}

.second-navbar .nav-link:hover {
    transform: translateY(-2px);
}

.second-navbar .nav-link.active-nav {
    color: var(--nav-text) !important;
    font-weight: bold;
}

/* Remove default underline for active item */
.second-navbar .nav-link.active-nav::after {
    width: 0;
    opacity: 0;
}

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
        }

        .search-form {
            width: 100%;
            max-width: 500px;
            position: relative;
        }

.search-results {
        z-index: 1500 !important; /* Higher than navbar z-index */
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background-color: white;
        border: 1px solid #dee2e6;
        border-radius: 0 0 8px 8px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        max-height: 400px;
        overflow-y: auto;
        display: none;
    }

.search-result-item {
    padding: 10px 15px;
    border-bottom: 1px solid var(--search-result-border);
    transition: all 0.2s;
    color: var(--search-result-text);
    text-decoration: none;
    display: block;
    z-index: 1500 !important;
}

.search-result-item:hover {
    background-color: var(--search-result-hover);
    color: var(--search-result-text);
}

.search-result-type {
    font-size: 0.8rem;
    color: var(--accent-orange);
    text-transform: capitalize;
    font-weight: 500;
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
            margin-right: 10px;
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
        }

        .btn-danger {
            background: var(--gradient-primary);
            border: none;
            box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
        }

        .btn-outline-light {
            color: var(--nav-text);
            border-color: var(--nav-text);
            background: transparent;
        }

        .nav-link {
            color: var(--nav-text);
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
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin-left: 0;
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

        /* Responsive styles */
        @media (max-width: 992px) {
            body {
                padding-top: 54px;
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
                margin-right: 0;
            }
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
        }

        @media (max-width: 576px) {
            body {
                padding-top: 54px;
            }
            
            .container {
                padding: 0.75rem;
            }
        }

        /* Dark mode specific fixes */

        .dark-mode .dropdown-menu {
            background-color: var(--dm-header-bg);
        }
        
        .dark-mode .city-search-container {
            background-color: var(--dm-header-bg);
        }
        
        .dark-mode .city-search-input {
            background-color: rgba(255,255,255,0.1);
            color: var(--dm-text);
        }
/* Add this to your existing styles in file1 */
.dropdown-menu {
    z-index: 1050 !important;
}

.city-dropdown-menu {
    z-index: 1051 !important;
}

.search-results {
    z-index: 1052 !important;
}

/* Ensure the navbar stays on top */
.top-navbar {
    z-index: 1040 !important;
}

.second-navbar {
    z-index: 1039 !important;
}

.dropdown-menu, .city-dropdown-menu {
        background-color: #1a1a1a !important;
        border: 1px solid #333 !important;
    }
    
    .dropdown-item {
        color: #e0e0e0 !important;
    }
    
    .dropdown-item:hover {
        background-color: #333 !important;
        color: white !important;
    }
    
    .city-search-container {
        background-color: #1a1a1a !important;
    }
    
    .city-search-input {
        background-color: rgba(255,255,255,0.1) !important;
        color: white !important;
    }

    /* Add to file1's styles */
.tab-content {
    padding: 20px 0;
}

.tab-content iframe {
    width: 100%;
    min-height: 600px;
    background: white;
}

/* Dark mode adjustments */
.dark-mode .tab-content {
    background-color: var(--dm-bg);
}

.dark-mode .nav-tabs {
    border-bottom-color: var(--dm-border);
}

.dark-mode .nav-link {
    color: var(--dm-text);
}

.dark-mode .nav-link.active {
    background-color: var(--dm-card-bg);
    border-color: var(--dm-border);
    color: var(--dm-text);
    border-bottom-color: var(--dm-card-bg);
}

/* Mobile adjustments */
@media (max-width: 768px) {
    .tab-content iframe {
        min-height: 400px;
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
                <div class="row mt-4">
                    <div class="col-md-12">
                        <h3 class="mb-4">Search Results for "<?= htmlspecialchars($_GET['search']) ?>"</h3>
                        
                        <?php if (!empty($searchResults)): ?>
                            <div class="row">
                                <?php foreach ($searchResults as $result): ?>
                                    <div class="col-md-3 mb-4">
                                        <div class="card event-card">
                                            <div class="position-relative">
                                                <img src="<?= !empty($result['event_image']) ? htmlspecialchars($result['event_image']) : 'placeholder.jpg' ?>" 
                                                     class="card-img-top event-card-img" alt="<?= htmlspecialchars($result['event_name']) ?>">
                                                <span class="event-type-badge"><?= htmlspecialchars($result['event_type']) ?></span>
                                            </div>
                                            <div class="card-body event-card-body">
                                                <h5 class="card-title event-card-title"><?= htmlspecialchars($result['event_name']) ?></h5>
                                                <a href="<?=$event_info_page;?>?id=<?= $result['event_id'] ?>" class="btn btn-sm btn-danger mt-2">View Details</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                No results found for "<?= htmlspecialchars($_GET['search']) ?>"
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>



                <?php
$event_id = 10;
$date = '2025-05-15';
include $showtime_info;
?>

                <div class="row mt-4">
                    <div class="col-md-12">
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
        <?php endif; ?>
    </div>



    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
    
    // Theme toggle functionality
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const bodyElement = document.body;
    
    // Check for saved theme preference or use system preference
    const savedTheme = localStorage.getItem('theme');
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    // Apply the saved theme or system preference
    if (savedTheme === 'dark' || (!savedTheme && systemPrefersDark)) {
        bodyElement.classList.add('dark-mode');
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        updateThemeIcon('dark');
    } else {
        bodyElement.classList.remove('dark-mode');
        document.documentElement.setAttribute('data-bs-theme', 'light');
        updateThemeIcon('light');
    }
    
    themeToggle.addEventListener('click', () => {
        const isDarkMode = bodyElement.classList.contains('dark-mode');
        
        if (isDarkMode) {
            bodyElement.classList.remove('dark-mode');
            document.documentElement.setAttribute('data-bs-theme', 'light');
            localStorage.setItem('theme', 'light');
            updateThemeIcon('light');
        } else {
            bodyElement.classList.add('dark-mode');
            document.documentElement.setAttribute('data-bs-theme', 'dark');
            localStorage.setItem('theme', 'dark');
            updateThemeIcon('dark');
        }
    });
    
    // Change from Bootstrap Icons to Font Awesome
function updateIcon(theme) {
    if (theme === 'dark') {
        themeIcon.classList.remove('fa-moon');
        themeIcon.classList.add('fa-sun');
    } else {
        themeIcon.classList.remove('fa-sun');
        themeIcon.classList.add('fa-moon');
    }
}
// Initialize Bootstrap dropdowns
document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
    toggle.addEventListener('click', function(e) {
        e.preventDefault();
        const dropdown = new bootstrap.Dropdown(this);
        dropdown.toggle();
    });
});

document.addEventListener('DOMContentLoaded', function() {
        // Initialize city search functionality
        const citySearch = document.getElementById('citySearch');
        if (citySearch) {
            citySearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const cityItems = document.querySelectorAll('.city-item');
                
                cityItems.forEach(item => {
                    const cityName = item.textContent.toLowerCase();
                    item.style.display = cityName.includes(searchTerm) ? 'block' : 'none';
                });
            });
        }
        
        // Initialize dropdowns
        function initializeDropdowns() {
            // Initialize Bootstrap dropdowns
            document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdown = new bootstrap.Dropdown(this);
                    dropdown.toggle();
                });
            });
            
            // City selection
            document.querySelectorAll('.city-item').forEach(item => {
                item.addEventListener('click', function() {
                    const cityId = this.getAttribute('data-value');
                    document.getElementById('selectedCity').value = cityId;
                    document.getElementById('cityForm').submit();
                });
            });
        }
        
        // Call this function when the page loads
        initializeDropdowns();
        
        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const searchResults = document.getElementById('searchResults');
        
        if (searchInput && searchResults) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.trim();
                
                if (searchTerm.length >= 2) {
                    fetch(`<?= $event_info_page;  ?>?search=${encodeURIComponent(searchTerm)}`)
                        .then(response => response.text())
                        .then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const results = doc.querySelector('#searchResults').innerHTML;
                            
                            searchResults.innerHTML = results;
                            searchResults.style.display = results ? 'block' : 'none';
                        });
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
    });

document.querySelectorAll('.city-item').forEach(item => {
    item.addEventListener('click', function() {
        const cityId = this.getAttribute('data-value');
        document.getElementById('selectedCity').value = cityId;
        document.getElementById('cityForm').submit();
    });
});
            
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
            
            // Search functionality
const searchInput = document.getElementById('searchInput');
const searchResults = document.getElementById('searchResults');

if (searchInput && searchResults) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.trim();
        
        if (searchTerm.length >= 2) {
            // Use the existing PHP search logic from your file
            fetch(`<?= $event_info_page;  ?>?search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.text())
                .then(html => {
                    // Extract just the search results part from the response
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const results = doc.querySelector('#searchResults').innerHTML;
                    
                    searchResults.innerHTML = results;
                    searchResults.style.display = results ? 'block' : 'none';
                });
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
                fetch(`<?= $search;  ?>?search=${encodeURIComponent(searchTerm)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.length > 0) {
                            let html = '';
                            data.forEach(item => {
                                html += `
                                    <a href="<?= $event_info_page;  ?>?id=${item.event_id}" class="search-result-item">
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
        // Add this to your existing JavaScript in file1
function initializeDropdowns() {
    // Initialize Bootstrap dropdowns
    document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const dropdown = new bootstrap.Dropdown(this);
            dropdown.toggle();
        });
    });
    
    // City selection
    document.querySelectorAll('.city-item').forEach(item => {
        item.addEventListener('click', function() {
            const cityId = this.getAttribute('data-value');
            document.getElementById('selectedCity').value = cityId;
            document.getElementById('cityForm').submit();
        });
    });
    
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput && searchResults) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            if (searchTerm.length >= 2) {
                fetch(`<?= $event_info_page;  ?>?search=${encodeURIComponent(searchTerm)}`)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const results = doc.querySelector('#searchResults').innerHTML;
                        
                        searchResults.innerHTML = results;
                        searchResults.style.display = results ? 'block' : 'none';
                    });
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
}

// Call this function when the page loads and after any dynamic content loads
document.addEventListener('DOMContentLoaded', initializeDropdowns);
    </script>
</body>
</html>