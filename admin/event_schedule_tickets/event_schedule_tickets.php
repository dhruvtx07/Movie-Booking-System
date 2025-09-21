<?php
session_start();
date_default_timezone_set('Asia/Kolkata'); // Set timezone as in dashboard.php

// Error reporting setup (can be removed if links.php or server config handles it)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include links.php for common links, configurations, and potentially auth_pages
require_once '../links.php';

// Include db_config.php for constants like RECORDS_PER_PAGE and DEFAULT_USER_ID if they are not in links.php
// If links.php already includes db_config.php or defines these, this line might be redundant.
// I'm keeping it for now, assuming it defines constants uniquely.
require_once 'config/db_config.php';

// Database configuration (Copied from dashboard.php)
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Redirect to login if not authenticated (except for auth pages) - Copied from dashboard.php
$auth_pages = [$login_page, $register_page, $forgot_pass]; // Assuming these are defined in links.php

if (!isset($_SESSION['user_id'])) {
    if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : '';

// $adminUserId and $adminUsername for filtering data by the logged-in user
// If a user is not logged in, consider a default behavior or redirect.
$adminUserId = $isLoggedIn ? $_SESSION['user_id'] : (defined('DEFAULT_USER_ID') ? DEFAULT_USER_ID : 1); // Use SESSION user_id or a default if not logged in
$adminUsername = $isLoggedIn ? $_SESSION['username'] : 'Guest'; // Corresponding username

// Connect to database (Copied from dashboard.php)
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'"); // Set charset to UTF-8
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch Cities for filter dropdowns
$cities = [];
try {
    $city_sql = "SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name ASC";
    $city_stmt = $pdo->query($city_sql);
    $cities = $city_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching cities: " . $e->getMessage());
    // Fallback or display error to user
}

// Fetch Event Genres for filter dropdowns
$genres = [];
try {
    // Assuming genres are distinct values in event_info 'genre' column or a separate table
    $genre_sql = "SELECT DISTINCT genre FROM event_info WHERE is_active = 'yes' AND genre IS NOT NULL AND genre != '' ORDER BY genre ASC";
    $genre_stmt = $pdo->query($genre_sql);
    $genres = $genre_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching genres: " . $e->getMessage());
}

// Fetch event types for filter dropdown
$event_types = [];
try {
    $event_type_sql = "SELECT DISTINCT event_type FROM event_info WHERE is_active = 'yes' AND event_type IS NOT NULL AND event_type != '' ORDER BY event_type ASC";
    $event_type_stmt = $pdo->query($event_type_sql);
    $event_types = $event_type_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching event types: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Schedule Ticket Management - Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom Color Variables - Dark Theme Default (Copied from dashboard.php) */
        :root {
            --primary-bg: #1A1A1A; /* Dark background */
            --secondary-bg: #2B2B2B; /* Lighter dark for cards/elements */
            --text-color: #F0F0F0; /* Light text */
            --light-text-color: #B0B0B0; /* Muted text */
            --accent-red: #E50914; /* Netflix Red */
            --accent-orange: #FF6B35; /* Vibrant Orange */
            --accent-yellow: #FFC107; /* Golden Yellow */
            --accent-peach: #FF9E7D; /* Soft Peach */
            --nav-dark: #000000; /* Pure black for specific elements like sidebar overlay */
            --nav-text: #ffffff; /* White for nav text */
            --card-border: rgba(255, 255, 255, 0.1); /* Subtle border for cards */
            --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
            --sidebar-width-collapsed: 70px;
            --sidebar-width-expanded: 220px;
            --sidebar-transition-duration: 0.3s;

            /* Responsive Font Sizes */
            --section-title-font: 2rem;
            --section-subtitle-font: 1.5rem;
            --metric-card-display-4-font: 2.5rem;
            --metric-card-h5-font: 1.1rem;
        }

        /* WebKit Scrollbar (Chrome, Safari, Edge) */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--secondary-bg);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--accent-red);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--accent-orange);
        }

        /* Firefox Scrollbar */
        * {
            scrollbar-width: thin;
            scrollbar-color: var(--accent-red) var(--secondary-bg);
        }

        /* For scrollable filter groups */
        .filter-checkbox-group::-webkit-scrollbar {
            width: 8px;
        }
        .filter-checkbox-group::-webkit-scrollbar-track {
            background: var(--primary-bg);
        }
        .filter-checkbox-group::-webkit-scrollbar-thumb {
            background: var(--accent-orange);
            border-radius: 4px;
        }
        .filter-checkbox-group::-webkit-scrollbar-thumb:hover {
            background: var(--accent-red);
        }
        .filter-checkbox-group {
            scrollbar-width: thin;
            scrollbar-color: var(--accent-orange) var(--primary-bg);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-color);
            margin: 0;
            display: flex; /* Flexbox for sidebar and content wrapper */
            transition: background-color 0.3s ease;
            min-height: 100vh; /* Ensures the wrapper fills at least the viewport height */
        }

        /* New wrapper for Main Content and Footer */
        .content-wrapper {
            display: flex;
            flex-direction: column; /* Stack main content and footer vertically */
            flex-grow: 1; /* Allows it to take up the remaining horizontal space */
            margin-left: var(--sidebar-width-collapsed); /* Initial margin to offset collapsed sidebar */
            transition: margin-left var(--sidebar-transition-duration) ease-in-out;
            min-height: 100vh; /* Ensures the wrapper fills at least the viewport height */
        }

        /* Sidebar Styling (from dashboard.php) */
        .sidebar {
            width: var(--sidebar-width-collapsed);
            background-color: var(--nav-dark);
            color: var(--nav-text);
            position: fixed; /* Fixed position */
            top: 0;
            left: 0;
            height: 100vh; /* Use full height for fixed sidebar */
            overflow-x: hidden; /* Hide horizontal scrollbar when collapsed */
            overflow-y: auto; /* Enable vertical scrolling */
            transition: width var(--sidebar-transition-duration) ease-in-out;
            padding-top: 20px;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.5);
            z-index: 1000; /* Ensure it stays on top */
        }

        .sidebar.is-open { /* New class for expanded state */
            width: var(--sidebar-width-expanded);
        }

        .sidebar-header {
            text-align: center;
            margin-bottom: 30px;
            opacity: 0; /* Hidden by default when collapsed */
            transition: opacity var(--sidebar-transition-duration) ease-in-out 0.1s;
        }
        .sidebar.is-open .sidebar-header { /* Use .is-open for header visibility */
            opacity: 1;
        }

        .sidebar-header a {
            text-decoration: none;
            color: var(--nav-text);
        }

        .sidebar-header .logo-img {
            height: 40px;
            filter: drop-shadow(0 0 5px var(--accent-red));
        }
        .sidebar-header h3 {
            font-size: 1.5rem;
            margin-top: 10px;
            color: var(--nav-text);
        }

        .sidebar-nav .nav-link {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: var(--nav-text);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
            position: relative;
        }

        .sidebar-nav .nav-link:hover {
            background-color: #333; /* Slightly lighter dark */
            color: var(--accent-orange);
            transform: translateX(5px);
        }

        .sidebar-nav .nav-link.active {
            background: var(--gradient-primary); /* Red-orange gradient */
            color: white;
            padding-left: 25px; /* Visual indicator for active */
        }
        .sidebar-nav .nav-link.active i {
            color: white; /* Ensure icon is white in active state */
        }

        .sidebar-nav .nav-link i {
            font-size: 1.3rem;
            margin-right: 15px; /* Default margin for icon */
            color: var(--accent-peach);
            transition: color 0.2s ease, margin-right var(--sidebar-transition-duration) ease-in-out;
            flex-shrink: 0; /* Prevent icon from shrinking */
        }

        /* Caret icon specific styling */
        .sidebar-nav .nav-link .caret-icon {
            font-size: 0.9rem; /* Smaller caret */
            transition: transform 0.3s ease-in-out;
            color: var(--light-text-color); /* Muted color for caret */
            margin-left: auto; /* Push to the right */
            margin-right: 0; /* Override default margin from general icon rule */
        }

        /* Rotate caret when menu is expanded */
        .sidebar-nav .nav-link[aria-expanded="true"] .caret-icon {
            transform: rotate(180deg);
        }

        .sidebar-nav .nav-link span {
            white-space: nowrap; /* Prevent text wrapping */
            opacity: 0; /* Hidden by default when collapsed */
            flex-grow: 1;
            visibility: hidden; /* Start hidden for better accessibility */
            transition: opacity var(--sidebar-transition-duration) ease-in-out 0.1s, 
                        visibility var(--sidebar-transition-duration) ease-in-out 0.1s; /* Transition both */
        }

        .sidebar.is-open .sidebar-nav .nav-link span {
            opacity: 1; /* Fully visible when sidebar is open */
            visibility: visible; /* Make visible when sidebar is open */
        }

        /* Sub-menu styling */
        .sidebar-nav .sub-menu {
            border-left: 3px solid rgba(255, 107, 53, 0.4); /* Subtle line to indicate sub-menu */
            margin-left: 20px; /* Indent sub-menu slightly */
            padding-left: 0; /* Remove default padding for ul */
        }

        .sidebar-nav .sub-menu .nav-item {
            margin-bottom: 0; /* Adjust spacing between sub-menu items */
        }

        .sidebar-nav .sub-menu .nav-link {
            padding: 10px 20px 10px 30px; /* Further indent sub-menu links */
            font-size: 0.9rem; /* Slightly smaller font for sub-items */
            background-color: rgba(0, 0, 0, 0.2); /* Slightly transparent background for sub-items */
            border-radius: 0; /* No explicit border-radius for sub-items */
            color: var(--light-text-color); /* Muted text color for sub-items */
        }

        .sidebar-nav .sub-menu .nav-link:hover {
            background-color: rgba(51, 51, 51, 0.5); /* Hover for sub-items */
            color: var(--accent-peach);
            transform: translateX(3px); /* Smaller hover effect */
        }

        .sidebar-nav .sub-menu .nav-link.active {
            background: var(--gradient-secondary); /* Different gradient for active sub-menu */
            color: white;
            padding-left: 35px; /* Adjust padding for active sub-menu */
        }

        /* When sidebar expands (has .is-open class), push content wrapper */
        body.sidebar-is-open .content-wrapper { /* Class added to body by JS */
            margin-left: var(--sidebar-width-expanded);
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1; /* Take up remaining space AFTER padding */
            padding: 20px;
        }

        /* General Styles */
        .container-fluid { /* Changed from .container to .container-fluid */
            max-width: 1400px; /* Increased max-width for container-fluid */
            padding-left: 15px;
            padding-right: 15px;
        }
        @media (max-width: 768px) {
            .container-fluid {
                padding-left: 20px;
                padding-right: 20px;
            }
        }

        /* Page-specific styling overrides/additions, adapted to new theme */
        .page-title { /* Renamed from original .page-header h1 to .page-title and styled with new color */
            color: var(--accent-orange);
            font-size: var(--section-title-font);
            margin-bottom: 20px;
            border-bottom: 2px solid var(--accent-red);
            padding-bottom: 10px;
        }
        .page-header { /* Retained as a parent div, but styling adjusted */
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--card-border); /* Use new border color */
        }


        /* Alert styles */
        .alert {
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            color: white; /* Ensure text is visible on colored alerts */
        }
        .alert-success { background: linear-gradient(90deg, #28a745, #218838); border: none; }
        .alert-danger { background: var(--gradient-primary); border: none; }
        .alert-warning { background: linear-gradient(90deg, var(--accent-yellow), #e0a800); border: none; }
        .alert-info { background: linear-gradient(90deg, var(--accent-orange), var(--accent-peach)); border: none; }


        /* Form elements */
        .form-control, .form-select {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
            border-radius: 5px;
        }
        .form-control::placeholder {
            color: var(--light-text-color); /* Placeholder color */
            opacity: 0.7;
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--secondary-bg); /* Keep background same on focus */
            color: var(--text-color);
            border-color: var(--accent-orange); /* Highlight border with accent color */
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25); /* Glow effect */
        }
        .form-label {
             color: var(--light-text-color); /* Muted form labels */
             font-size: 0.9em;
        }

        /* Buttons */
        .btn-primary, .btn-primary-custom { /* Merged custom with primary */
            background: var(--gradient-primary);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-primary:hover, .btn-primary-custom:hover {
            background: var(--gradient-secondary); /* Change gradient on hover */
            transform: translateY(-2px); /* Lift effect */
            box-shadow: 0 4px 10px rgba(229, 9, 20, 0.4); /* Stronger shadow */
            color: white;
        }
        .btn-danger, .btn-danger-custom { /* Merged custom with danger */
            background: var(--gradient-primary); /* Reusing primary gradient for danger */
            border: none;
            transition: all 0.3s ease;
            color: white;
        }
        .btn-danger:hover, .btn-danger-custom:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-success { /* For Add New User */
            background: linear-gradient(135deg, #28a745, #20c997); /* Green colors for success */
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-success:hover {
            background: linear-gradient(135deg, #20c997, #28a745);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.4);
        }
        .btn-secondary, .btn-secondary-custom { /* Merged custom with secondary */
            background-color: #6c757d; /* Default secondary color */
            border-color: #6c757d;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover, .btn-secondary-custom:hover {
            background-color: #5a6268;
            border-color: #545b62;
            transform: translateY(-1px);
        }
        
        .btn-outline-secondary { /* Style for new select buttons (no longer used for multiselect) */
            color: var(--light-text-color);
            border-color: var(--card-border);
            background-color: transparent;
        }
        .btn-outline-secondary:hover {
            color: var(--text-color);
            background-color: rgba(255, 255, 255, 0.08); /* Subtle hover */
            border-color: var(--light-text-color);
        }

        /* Dashboard Cards / Stat Boxes */
        .metric-card { /* Renamed from original .stat-box */
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-align: center;
            height: 100%; /* Ensure cards in a row have equal height */
            display: flex;
            flex-direction: column;
        }
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .metric-card h5, .metric-card h3 { /* Using h5 and h3 for consistency */
            color: var(--light-text-color);
            font-size: var(--metric-card-h5-font); /* Uses dashboard h5 font size */
            font-weight: 600; /* Made slightly bolder */
            margin-bottom: 10px;
        }
        .metric-card .display-4, .metric-card p { /* Using display-4 from dashboard */
            color: var(--accent-orange); /* Default color for numbers */
            font-size: var(--metric-card-display-4-font);
            font-weight: bold;
            margin-bottom: 10px;
        }
        /* Specific colors for metric-card types */
        .metric-card.green .display-4, .metric-card.green p { color: #28a745; }
        .metric-card.red .display-4, .metric-card.red p { color: var(--accent-red); }
        .metric-card.blue .display-4, .metric-card.blue p { color: #007bff; }
        .metric-card.purple .display-4, .metric-card.purple p { color: #8F5EEB; }
        .metric-card.cyan .display-4, .metric-card.cyan p { color: #17a2b8; }
        .metric-card.gold .display-4, .metric-card.gold p { color: #FFD700; }

        /* Filters Bar (matches dashboard's filter card style) */
        .filters-bar {
            background-color: var(--secondary-bg);
            padding: 15px; /* Adjust padding slightly */
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            border: 1px solid var(--card-border); /* Added consistent border */
        }
        .filters-bar .btn-filter { /* Specific style for filter button */
            background: var(--gradient-primary);
            color: white;
            border: none;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .filters-bar .btn-filter:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(229, 9, 20, 0.4);
        }

        /* Event Schedule Cards */
        .event-schedule-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .event-schedule-card {
            background-color: var(--secondary-bg); /* Use secondary-bg */
            border: 1px solid var(--card-border); /* Use card-border */
            border-left: 4px solid var(--accent-red); /* Use accent-red */
            border-radius: 6px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.4);
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-left-color 0.25s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
        }
        .event-schedule-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.5);
            border-left-color: var(--accent-orange); /* Use accent-orange on hover */
        }
        .event-schedule-card-header h6 {
            color: var(--accent-peach); /* Use accent-peach */
            margin-bottom: 3px;
            font-size: 1.1em;
        }
        .event-schedule-card-header p {
            color: var(--light-text-color); /* Use light-text-color */
            font-size: 0.8em;
            margin-bottom: 10px;
        }
        .event-schedule-stats {
            font-size: 0.75em; /* Smaller font for stats */
            color: var(--light-text-color); /* Use light-text-color */
            margin-bottom: 10px;
            flex-grow: 1; /* Pushes action section to bottom */
        }
        .event-schedule-stats .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.1); /* Subtle dashed border */
        }
        .event-schedule-stats .stat-item:last-child { border-bottom: none; }
        .event-schedule-stats .stat-value { color: var(--text-color); font-weight: 600; }
        .event-schedule-card .active-status {
            font-size: 0.7em;
            padding: 2px 6px;
            border-radius: 4px;
            float: right;
        }
        .active-status.yes { background-color: #28a745; color: white; } /* Bootstrap success color */
        .active-status.no { background-color: var(--accent-red); color: white; } /* Accent red for inactive */

        /* Pagination (from dashboard.php) */
        .pagination .page-item .page-link {
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
            color: var(--accent-peach);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--accent-orange); /* Active background */
            border-color: var(--accent-orange);
            color: var(--nav-dark); /* Dark text on active */
        }
        .pagination .page-item.disabled .page-link {
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
            color: var(--light-text-color);
        }
        .pagination .page-item .page-link:hover {
            background-color: var(--accent-orange);
            color: var(--nav-dark);
            opacity:0.8;
        }

        /* Ticket Management Modal */
        .ticket-management-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.85); /* Slightly less transparent */
            z-index: 1100;
            overflow-y: auto;
            padding: 20px;
            align-items: center;
            justify-content: center;
        }
        .ticket-management-content {
            background-color: var(--primary-bg); /* Use primary-bg for modal content */
            padding: 20px;
            border-radius: 10px;
            width: 95%;
            max-width: 1400px;
            box-shadow: 0 0 40px rgba(0,0,0,0.6);
            border-top: 5px solid var(--accent-red); /* Use accent-red */
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .tm-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--accent-yellow); /* Use accent-yellow */
            border-bottom: 1px solid var(--card-border); /* Use card-border */
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .tm-header h4 { margin: 0; font-size: 1.5rem; }
        .close-tm-modal {
            font-size: 2rem;
            color: var(--light-text-color); /* Use light-text-color */
            cursor: pointer;
            background:none; border:none; padding:0 10px;
            transition: color 0.2s ease, transform 0.2s ease;
        }
        .close-tm-modal:hover { color: var(--accent-red); transform: rotate(90deg); }

        .tm-body {
            overflow-y: auto;
            flex-grow: 1;
        }
        .tm-controls-column {
            background-color: var(--secondary-bg); /* Use secondary-bg */
            padding: 15px;
            border-radius: 8px;
            height: 100%;
            max-height: calc(90vh - 150px);
            overflow-y: auto;
            overflow-x: hidden;
        }
            
        .tm-section-title {
            color: var(--accent-peach); /* Use accent-peach */
            font-size: 1.1rem;
            margin-top: 15px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--card-border); /* Use card-border */
        }
        .tm-section-title:first-child { margin-top: 0;}

        /* Ticket Map Visualizer */
        .ticket-map-container {
            background-color: var(--primary-bg); /* Use primary-bg */
            padding: 10px;
            border-radius: 6px;
            border: 1px solid var(--card-border); /* Use card-border */
            margin-bottom: 15px;
            overflow: auto;
            min-height: 300px;
        }
        /* Match ticket map filters form background and border */
        .ticket-map-filters {
            background-color: var(--secondary-bg) !important;
            border: 1px solid var(--card-border);
        }

        .ticket-map-grid {
            display: grid;
            gap: 4px;
            align-items: center;
            justify-content: flex-start;
        }
        .map-seat { /* Common properties for seats */
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 3px;
            font-size: 0.6em;
            font-weight: bold;
            transition: background-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
            cursor: pointer;
            background-color: var(--secondary-bg); /* Use secondary-bg */
            border: 1px solid var(--card-border); /* Use card-border */
            color: var(--light-text-color); /* Use light-text-color */
        }
            
        .map-row-label, .map-col-label {
            background-color: transparent;
            color: var(--light-text-color); /* Use light-text-color */
            width: 25px;
        }
        .map-col-label { height: 25px; }
        .map-placeholder { width: 25px; height: 25px; }

        /* Seat colors based on type (adapted to new theme colors) */
        .map-seat.type-regular { background-color: #555; color:var(--text-color); border-color: #666; }
        .map-seat.type-premium { background-color: var(--accent-peach); color:var(--primary-bg); border-color: #ffc099;}
        .map-seat.type-vip { background-color: var(--accent-yellow); color:var(--primary-bg); border-color: #e6c300;}
        .map-seat.type-recliner { background-color: var(--accent-orange); color:var(--primary-bg); border-color: #cc7000;}
        .map-seat.type-box { background-color: var(--accent-red); color:var(--text-color); border-color: #b80710;}
            
        /* Selected tickets */
        .map-seat.selected {
            transform: scale(1.1);
            box-shadow: 0 0 12px var(--accent-yellow); /* Strong golden glow */
            border: 1px solid var(--accent-yellow);
            z-index: 10;
        }
            
        /* Filtered Highlighting - less intrusive */
        .map-seat.filtered-highlight {
            box-shadow: 0 0 8px rgba(255, 107, 53, 0.6); /* Orange glow */
            border: 1px solid var(--accent-orange); /* Orange border */
        }
            
        /* When a seat is both selected and filtered, the selected glow is dominant */
        .map-seat.selected.filtered-highlight {
            box-shadow: 0 0 12px var(--accent-yellow); /* Prioritize strong selected glow */
            border: 1px solid var(--accent-yellow);
        }

        .map-seat.not-vacant {
            opacity: 0.6;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 0.8em;
        }
        .legend-item { display: flex; align-items: center; }
        .legend-color { width: 15px; height: 15px; border-radius:3px; margin-right: 5px; border: 1px solid var(--card-border); }

        /* Contextual CRUD Forms for Tickets */
        #ticketActionButtons button { margin-right: 10px; margin-bottom: 10px; }

        #updateTicketFormContainer {
            background-color: var(--secondary-bg); /* Use secondary-bg */
            padding:15px; border-radius: 6px; margin-top:15px;
            border: 1px solid var(--card-border); /* Use card-border */
        }
        .btn-sm-custom { font-size: 0.8rem; padding: 0.2rem 0.8rem; } /* Adjusted padding */

        /* Small Stats Display in Modal */
        .ticket-stats-display-modal {
            background-color: var(--secondary-bg); /* Use secondary-bg */
            padding: 10px;
            border-radius: 6px;
            font-size: 0.8em;
            border:1px solid var(--card-border); /* Use card-border */
        }
        .ticket-stats-display-modal h6 { color: var(--accent-peach); font-size: 1.1em; }
        .ticket-stats-display-modal p { margin-bottom: 3px; }
        .ticket-stats-display-modal strong { color: var(--accent-yellow); } /* Use accent-yellow */

        /* Toast Notifications (Adapted from dashboard.php alerts and current toast) */
        .toast-container { z-index: 1200; }
        .toast {
            background-color: var(--secondary-bg) !important;
            color: var(--text-color) !important;
            border: 1px solid var(--card-border) !important;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .toast-header {
            background-color: var(--primary-bg) !important; /* Lighter background for header */
            color: var(--accent-peach) !important; /* Accent text for header */
            border-bottom: 1px solid var(--card-border) !important;
        }
        .toast .btn-close { filter: invert(1) grayscale(100%) brightness(200%); } /* Ensure close button visibility */

        /* Loading Spinner */
        .spinner-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 150px;
            flex-direction: column;
        }
        .spinner-border-custom {
            color: var(--accent-orange); /* Use accent-orange for spinner */
        }

        /* Footer Styling (Copied from dashboard.php) */
        .footer {
            background-color: var(--secondary-bg);
            color: var(--light-text-color);
            padding: 20px;
            border-top: 1px solid var(--card-border);
            flex-shrink: 0; /* Prevents the footer from shrinking */
            width: 100%; /* Ensures it spans the full width of its parent (.content-wrapper) */
        }
        .footer a {
            color: var(--accent-orange); /* Highlight links */
            text-decoration: none;
        }
        .footer a:hover {
            color: var(--accent-red);
            text-decoration: underline;
        }

        /* Responsive Adjustments (from dashboard.php) */
        @media (max-width: 768px) {
            :root {
                --section-title-font: 1.8rem;
                --section-subtitle-font: 1.25rem;
                --metric-card-display-4-font: 2rem;
                --metric-card-h5-font: 1rem;
            }

            .sidebar {
                width: 0; /* Fully collapse sidebar by default on smaller screens */
                padding-top: 60px; /* Space for the fixed toggle button area */
                box-shadow: none; /* Remove shadow when fully collapsed */
            }
            .sidebar.is-open { /* Class added by JS when toggle button is clicked */
                width: var(--sidebar-width-expanded);
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.5);
            }

            .content-wrapper {
                margin-left: 0; /* Main content takes full width on small screens, no offset */
                padding-top: 15px; /* Adjust top padding for content */
            }
            /* When sidebar is active, main content doesn't shift, it gets overlaid */
            /* body.sidebar-is-open .content-wrapper rule is overridden by this media query */

            /* Add a button to toggle sidebar on small screens */
            .sidebar-toggle-btn {
                display: block; /* Show on small screens */
                position: fixed;
                top: 10px;
                left: 10px;
                z-index: 1030; /* Higher than sidebar */
                background-color: var(--nav-dark);
                color: var(--nav-text);
                border: none;
                padding: 10px 15px;
                border-radius: 5px;
                cursor: pointer;
                box-shadow: 0 2px 5px rgba(0,0,0,0.3);
                transition: transform 0.2s ease;
            }
            .filter-group-spacing { /* Add spacing between filter groups if they stack */
                margin-bottom: 15px;
            }

            .metric-card {
                padding: 15px; /* Reduce padding on cards for mobile */
            }
            .list-group-item {
                padding: 8px 12px; /* Reduce list item padding */
                font-size: 0.9rem;
            }
            th, td {
                padding: 10px 12px; /* Reduce table padding */
                font-size: 0.8rem;
            }
            .filter-checkbox-group {
                max-height: 120px; /* Tighter layout for mobile */
            }

            .footer {
                padding: 15px; /* Less padding */
                text-align: center; /* Center text on small screens */
            }
            .footer .col-md-6 {
                text-align: center !important; /* Force center for both columns */
            }
            .footer .row {
                flex-direction: column; /* Stack columns */
            }
            .footer .col-md-6:first-child {
                margin-bottom: 10px; /* Space between stacked columns */
            }
        }
        @media (min-width: 769px) { /* Desktop */
            .sidebar-toggle-btn {
                display: none; /* Hide on larger screens */
            }
            .filter-group-spacing {
                margin-bottom: 0; /* No extra spacing on desktop */
            }
        }
        /* Specific adjustments for modal on smaller screens */
        @media (max-width: 576px) {
            .event-schedule-grid {
                grid-template-columns: 1fr; /* Single column on small screens */
            }
            .ticket-management-content {
                padding: 10px;
                max-width: 100%;
                margin: 0;
            }
            /* Adjust map seat size for very small screens if necessary */
            .map-seat {
                width: 25px;
                height: 25px;
                font-size: 0.5em; /* Smaller font for smaller seats */
            }
            .map-row-label, .map-col-label {
                width: 20px;
                height: 20px;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar Toggle Button for Small Screens -->
<button class="sidebar-toggle-btn d-md-none" id="sidebarToggleBtn">
 <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <a href="../dashboard.php" title="Catchify Dashboard">
      <img src="../images/logo.png" alt="Catchify Logo" class="logo-img">
      <h3>Catchify Admin</h3>
    </a>
  </div>
  <nav class="sidebar-nav">
    <ul class="nav flex-column">
      <li class="nav-item">
        <a class="nav-link" href="../dashboard.php" title="Dashboard">
          <i class="fas fa-tachometer-alt"></i>
          <span>Dashboard</span>
        </a>
      </li>

      <!-- Events Group -->
      <li class="nav-item">
        <a class="nav-link collapsed" href="#eventsSubMenu" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="eventsSubMenu" title="Events">
          <i class="fas fa-calendar-alt"></i>
          <span>Events</span>
          <i class="fas fa-chevron-down ms-auto caret-icon"></i>
        </a>
        <div class="collapse" id="eventsSubMenu">
          <ul class="nav flex-column sub-menu">
            <li class="nav-item">
              <a class="nav-link" href="../event_handler.php" title="Manage Events">
                <i class="fas fa-edit"></i>
                <span>Manage Events</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="event_assignment.php" title="Event Schedules">
                <i class="fas fa-clock"></i>
                <span>Event Schedules</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../event_schedule_tickets/event_schedule_tickets.php" title="Event Ticket Types">
                <i class="fas fa-ticket-alt"></i>
                <span>Event Ticket Types</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../event_dashboard.php" title="Event Dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Event Dashboard</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../event_booking_detailed.php" title="Event Reports">
                <i class="fas fa-file-invoice"></i>
                <span>Event Reports</span>
              </a>
            </li>
          </ul>
        </div>
      </li>

      <!-- Venues Group -->
      <li class="nav-item">
        <a class="nav-link collapsed" href="#venuesSubMenu" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="venuesSubMenu" title="Venues">
          <i class="fas fa-map-marker-alt"></i>
          <span>Venues</span>
          <i class="fas fa-chevron-down ms-auto caret-icon"></i>
        </a>
        <div class="collapse" id="venuesSubMenu">
          <ul class="nav flex-column sub-menu">
            <li class="nav-item">
                            <a class="nav-link" href="../cities/index.php" title="Manage Venues">
                                <i class="fas fa-warehouse"></i>
                                <span>Manage Cities</span>
                            </a>
                        </li>
            <li class="nav-item">
              <a class="nav-link" href="../venues/index.php" title="Manage Venues">
                <i class="fas fa-warehouse"></i>
                <span>Manage Venues</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../venue_schedules/venue_schedules.php" title="Venue Schedules">
                <i class="fas fa-calendar-check"></i>
                <span>Venue Schedules</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../venue_tickets/index.php" title="Venue Ticket Types">
                <i class="fas fa-ticket-alt"></i>
                <span>Venue Ticket Types</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../venue_dashboard.php" title="Venue Dashboard">
                <i class="fas fa-chart-pie"></i>
                <span>Venue Dashboard</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../venue_booking_detailed.php" title="Venue Reports">
                <i class="fas fa-clipboard-list"></i>
                <span>Venue Reports</span>
              </a>
            </li>
          </ul>
        </div>
      </li>
       
      <li class="nav-item">
        <a class="nav-link" href="../manage_promos.php" title="Promo Codes">
          <i class="fas fa-tag"></i>
          <span>Promo Codes</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link" href="../scanqr.php" title="Promo Codes">
          <i class="fas fa-qrcode"></i>
          <span>Scan Ticket QR</span>
        </a>
      </li>

      <!-- Manage Users (from file 2) - Marked Active -->
                <li class="nav-item">
                    <a class="nav-link" href="../manage_users.php" title="Manage Users">
                        <i class="fas fa-users-cog"></i>
                        <span>Manage Users</span>
                    </a>
                </li>

      <li class="nav-item">
        <a class="nav-link" href="../logout.php" title="Logout">
          <i class="fas fa-sign-out-alt"></i>
          <span>Logout</span>
        </a>
      </li>
    </ul>
  </nav>
</aside>


<!-- New wrapper for Main Content and Footer (Copied from dashboard.php) -->
<div class="content-wrapper" id="contentWrapper">
    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-ticket-alt"></i> Event Schedule Ticket Management</h1>
            </div>

            <!-- Global Stats Row (metric-card adapted from stat-box) -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="metric-card blue">
                        <h5>Total Event Schedules (Filtered)</h5>
                        <p id="statTotalEventSchedules">0</p>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="metric-card green">
                        <h5>Active Schedules (Filtered)</h5>
                        <p id="statActiveSchedules">0</p>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="metric-card purple">
                        <h5>Total Active Tickets (Filtered)</h5>
                        <p id="statTotalTicketsMapped">0</p>
                    </div>
                </div>
            </div>

            <!-- Filters Bar (adapted to dashboard.php card style) -->
            <div class="filters-bar">
                <form id="eventScheduleFiltersForm" class="row gx-2 gy-2 align-items-end">
                    <div class="col-md-4">
                        <label for="searchEventSchedule" class="form-label">Search Event/Venue</label>
                        <input type="text" class="form-control form-control-sm" id="searchEventSchedule" name="search" placeholder="Event name, venue name...">
                    </div>
                    <div class="col-md-3">
                        <label for="cityFilter" class="form-label">Venue City</label>
                        <select class="form-select form-select-sm" id="cityFilter" name="city_id">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?= htmlspecialchars($city['city_id']) ?>"><?= htmlspecialchars($city['city_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="eventGenreFilter" class="form-label">Event Genre</label>
                        <select class="form-select form-select-sm" id="eventGenreFilter" name="genre">
                            <option value="">All Genres</option>
                            <?php foreach ($genres as $genre): ?>
                                <option value="<?= htmlspecialchars($genre['genre']) ?>"><?= htmlspecialchars($genre['genre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="eventTypeFilter" class="form-label">Event Type</label>
                        <select class="form-select form-select-sm" id="eventTypeFilter" name="event_type">
                            <option value="">All Types</option>
                            <?php foreach ($event_types as $type): ?>
                                <option value="<?= htmlspecialchars($type['event_type']) ?>"><?= htmlspecialchars($type['event_type']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="scheduleDateStart" class="form-label">Schedule From Date</label>
                        <input type="date" class="form-control form-control-sm" id="scheduleDateStart" name="schedule_date_start">
                    </div>
                    <div class="col-md-3">
                        <label for="scheduleDateEnd" class="form-label">Schedule To Date</label>
                        <input type="date" class="form-control form-control-sm" id="scheduleDateEnd" name="schedule_date_end">
                    </div>
                    <div class="col-md-3">
                        <label for="scheduleStatusFilter" class="form-label">Schedule Status</label>
                        <select class="form-select form-select-sm" id="scheduleStatusFilter" name="is_schedule_active">
                            <option value="">All Status</option>
                            <option value="yes">Active</option>
                            <option value="no">Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-filter btn-sm w-100 mt-md-3"><i class="fas fa-filter"></i> Apply Filters</button>
                    </div>
                </form>
            </div>

            <!-- Event Schedule Grid - Populated by JS -->
            <div id="eventScheduleGridContainer" class="event-schedule-grid">
                <!-- Event schedules will be loaded here -->
            </div>
            <div id="eventScheduleGridPlaceholder" class="spinner-container">
                <div class="spinner-border spinner-border-custom" role="status"><span class="visually-hidden">Loading event schedules...</span></div>
                <p class="text-white-50 mt-2">Loading event schedules...</p>
            </div>


            <!-- Pagination - Populated by JS -->
            <nav aria-label="Event Schedule Pagination">
                <ul class="pagination justify-content-center" id="eventSchedulePagination">
                    <!-- Pagination links will be loaded here -->
                </ul>
            </nav>
        </div>
    </main>

    <!-- Footer (Copied from dashboard.php) -->
    <footer class="footer">
        <div class="container py-3">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start">
                    &copy; <?php echo date('Y'); ?> Catchify Admin Dashboard. All rights reserved.
                </div>
                <div class="col-md-6 text-center text-md-end">
                    Version 1.0
                </div>
            </div>
        </div>
    </footer>
</div> <!-- Close content-wrapper -->

<!-- Ticket Management Modal -->
<div class="ticket-management-modal" id="ticketManagementModal">
    <div class="ticket-management-content">
        <div class="tm-header">
            <h4 id="tmEventScheduleDetails">Event Tickets</h4>
            <button class="close-tm-modal" id="closeTicketManagementModal" aria-label="Close">&times;</button>
        </div>

        <div class="tm-body">
            <div class="row g-3">
                <!-- Left Column: Map and Map Filters -->
                <div class="col-lg-8">
                    <div class="ticket-map-filters mb-3 p-2 rounded"> <!-- Removed hardcoded style here as it's now in CSS -->
                        <form id="ticketMapFiltersForm" class="row gx-2 gy-2 align-items-center">
                            <div class="col-md-3">
                                <select class="form-select form-select-sm" name="map_ticket_type">
                                    <option value="">All Types</option>
                                    <option value="Regular">Regular</option><option value="Premium">Premium</option>
                                    <option value="VIP">VIP</option><option value="Recliner">Recliner</option>
                                    <option value="Box">Box</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control form-control-sm" name="map_ticket_price" placeholder="Price (e.g. 250)">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select form-select-sm" name="map_is_vacant">
                                    <option value="">All Status</option>
                                    <option value="yes">Vacant</option>
                                    <option value="no">Booked/Held</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select form-select-sm" name="map_is_active">
                                    <option value="">All Active Status</option>
                                    <option value="yes">Active</option>
                                    <option value="no">Inactive</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-secondary-custom btn-sm"><i class="fas fa-filter"></i> Filter Map</button>
                            </div>
                            <div class="col-auto">
                                <button type="reset" class="btn btn-outline-secondary btn-sm" id="resetTicketMapFilters"><i class="fas fa-xmark"></i> Clear Filters</button>
                            </div>
                        </form>
                    </div>
                
                    <div class="legend mb-2">
                        <div class="legend-item"><span class="legend-color map-seat type-regular" style="width:15px; height:15px; font-size:0.5em;line-height:1;"></span>Regular</div>
                        <div class="legend-item"><span class="legend-color map-seat type-premium" style="width:15px; height:15px; font-size:0.5em;line-height:1;"></span>Premium</div>
                        <div class="legend-item"><span class="legend-color map-seat type-vip" style="width:15px; height:15px; font-size:0.5em;line-height:1;"></span>VIP</div>
                        <div class="legend-item"><span class="legend-color map-seat type-recliner" style="width:15px; height:15px; font-size:0.5em;line-height:1;"></span>Recliner</div>
                        <div class="legend-item"><span class="legend-color map-seat type-box" style="width:15px; height:15px; font-size:0.5em;line-height:1;"></span>Box</div>
                        <div class="legend-item"><span class="map-seat selected" style="width:15px; height:15px;font-size:0.5em;line-height:1;"></span>Selected</div>
                        <div class="legend-item"><span class="map-seat not-vacant" style="width:15px; height:15px; font-size:0.5em;line-height:1; opacity:0.6;"></span>Taken/Inactive</div>
                    </div>

                    <div class="ticket-map-container" id="ticketMapContainer">
                        <div class="spinner-container">
                            <div class="spinner-border spinner-border-custom" role="status"><span class="visually-hidden">Loading map...</span></div>
                            <p class="text-white-50 mt-2">Loading ticket map...</p>
                        </div>
                    </div>
                    <div id="ticketActionButtons" class="mt-2">
                        <!-- Buttons appear contextually -->
                        <button id="btnUpdateSelectedTickets" class="btn btn-secondary-custom btn-sm" style="display:none;"><i class="fas fa-edit"></i> Edit Selected</button>
                        <button id="btnDeleteSelectedTickets" class="btn btn-danger-custom btn-sm" style="display:none;"><i class="fas fa-trash"></i> Delete Selected</button>
                    </div>
                    <div id="updateTicketFormContainer" style="display:none;" class="mt-2">
                        <h6 class="tm-section-title">Update Selected Tickets</h6>
                        <form id="updateTicketForm">
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label for="update_ticket_type" class="form-label">New Ticket Type</label>
                                    <select class="form-select form-select-sm" id="update_ticket_type" name="ticket_type">
                                        <option value="">-- No Change --</option>
                                        <option value="Regular">Regular</option><option value="Premium">Premium</option>
                                        <option value="VIP">VIP</option><option value="Recliner">Recliner</option>
                                        <option value="Box">Box</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="update_ticket_price" class="form-label">New Ticket Price (₹)</label>
                                    <input type="number" class="form-control form-control-sm" id="update_ticket_price" name="ticket_price" min="0" step="10" placeholder="No Change">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="update_is_vacant" class="form-label">Availability</label>
                                    <select class="form-select form-select-sm" id="update_is_vacant" name="is_vacant">
                                        <option value="">-- No Change --</option>
                                        <option value="yes">Vacant</option>
                                        <option value="no">Not Vacant</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="update_is_active" class="form-label">Active Status</label>
                                    <select class="form-select form-select-sm" id="update_is_active" name="is_active">
                                        <option value="">-- No Change --</option>
                                        <option value="yes">Active</option>
                                        <option value="no">Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary-custom btn-sm mt-2"><i class="fas fa-save"></i> Apply Changes</button>
                            <button type="button" id="cancelUpdateTicket" class="btn btn-outline-secondary btn-sm mt-2"><i class="fas fa-xmark"></i> Cancel</button>
                        </form>
                    </div>

                </div>

                <!-- Right Column: Stats and Forms -->
                <div class="col-lg-4">
                    <div class="tm-controls-column">
                        <div class="ticket-stats-display-modal mb-3" id="modalVenueTicketStats">
                            <!-- Modal Stats will be rendered here -->
                            <h6><i class="fas fa-chart-pie"></i> Ticket Statistics</h6>
                            <p>Total Active: <strong id="statModalTotalTickets">0</strong> | Available: <strong id="statModalAvailableTickets">0</strong></p>
                            <p>Inactive: <strong id="statModalInactiveTickets">0</strong></p>
                            <p>Venue Capacity: <strong id="statModalVenueCapacity">0</strong></p>
                            <div id="statModalByType"></div>
                            <div id="statModalByPrice"></div>
                        </div>
                            
                        <h6 class="tm-section-title"><i class="fas fa-plus-circle"></i> Generate Single Ticket</h6>
                        <form id="generateSingleTicketFormModal" style="display:none;">
                            <input type="hidden" id="single_event_schedule_id_modal" name="event_schedule_id">
                            <div class="mb-2">
                                <label for="single_ticket_row_modal" class="form-label">Row (A-Z, or multi-char like AA)</label>
                                <input type="text" class="form-control form-control-sm" id="single_ticket_row_modal" name="ticket_row" maxlength="3" required pattern="^[A-Z]+$" title="Only uppercase letters (A-Z)">
                            </div>
                            <div class="mb-2">
                                <label for="single_ticket_column_modal" class="form-label">Column (1-999)</label>
                                <input type="number" class="form-control form-control-sm" id="single_ticket_column_modal" name="ticket_column" min="1" max="999" required>
                            </div>
                            <div class="mb-2">
                                <label for="single_ticket_type_modal" class="form-label">Type</label>
                                <select class="form-select form-select-sm" id="single_ticket_type_modal" name="ticket_type" required>
                                    <option value="Regular">Regular</option><option value="Premium">Premium</option>
                                    <option value="VIP">VIP</option><option value="Recliner">Recliner</option><option value="Box">Box</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label for="single_ticket_price_modal" class="form-label">Price (₹)</label>
                                <input type="number" class="form-control form-control-sm" id="single_ticket_price_modal" name="ticket_price" min="0" step="10" required>
                            </div>
                            <button type="submit" class="btn btn-primary-custom btn-sm w-100"><i class="fas fa-plus"></i> Add Ticket</button>
                        </form>
                        <button class="btn btn-outline-secondary btn-sm w-100 mt-2" id="showSingleTicketFormBtn">Show Single Ticket Form</button>

                        <h6 class="tm-section-title mt-3"><i class="fas fa-layer-group"></i> Generate Bulk Tickets</h6>
                        <form id="generateBulkTicketFormModal" style="display:none;">
                            <input type="hidden" id="bulk_event_schedule_id_modal" name="event_schedule_id">
                            <div class="row gx-2 mb-1">
                                <div class="col"><label for="bulk_row_start_modal" class="form-label">Row Start</label><input type="text" class="form-control form-control-sm" id="bulk_row_start_modal" name="row_start" maxlength="3" required placeholder="A" pattern="^[A-Z]+$" title="Only uppercase letters (A-Z)"></div>
                                <div class="col"><label for="bulk_row_end_modal" class="form-label">Row End</label><input type="text" class="form-control form-control-sm" id="bulk_row_end_modal" name="row_end" maxlength="3" required placeholder="J" pattern="^[A-Z]+$" title="Only uppercase letters (A-Z)"></div>
                            </div>
                            <div class="row gx-2 mb-1">
                                <div class="col"><label for="bulk_col_start_modal" class="form-label">Col Start</label><input type="number" class="form-control form-control-sm" id="bulk_col_start_modal" name="col_start" min="1" required placeholder="1"></div>
                                <div class="col"><label for="bulk_col_end_modal" class="form-label">Col End</label><input type="number" class="form-control form-control-sm" id="bulk_col_end_modal" name="col_end" min="1" required placeholder="10"></div>
                            </div>
                            <div class="mb-1">
                                <label for="bulk_ticket_type_modal" class="form-label">Type</label>
                                <select class="form-select form-select-sm" id="bulk_ticket_type_modal" name="ticket_type" required>
                                    <option value="Regular">Regular</option><option value="Premium">Premium</option>
                                    <option value="VIP">VIP</option><option value="Recliner">Recliner</option><option value="Box">Box</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label for="bulk_ticket_price_modal" class="form-label">Price (₹)</label>
                                <input type="number" class="form-control form-control-sm" id="bulk_ticket_price_modal" name="ticket_price" min="0" step="10" required>
                            </div>
                            <div id="bulkPreviewModal" class="alert alert-secondary p-1" style="font-size:0.8em; display:none;"></div>
                            <button type="submit" class="btn btn-primary-custom btn-sm w-100"><i class="fas fa-magic"></i> Generate Bulk</button>
                        </form>
                        <button class="btn btn-outline-secondary btn-sm w-100 mt-2" id="showBulkTicketFormBtn">Show Bulk Ticket Form</button>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container for Notifications (Copied from dashboard.php but adapted) -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <!-- Toasts will be appended here -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // DEBUG: Global debug toggle
        const DEBUG_MODE = true;   
        function debugLog(...args) {
            if (DEBUG_MODE) {
                console.log("[DEBUG]", ...args);
            }
        }
        debugLog("DOMContentLoaded event fired. Script starting.");

        // --- Global Variables & Elements ---
        const eventScheduleFiltersForm = document.getElementById('eventScheduleFiltersForm');
        const eventScheduleGridContainer = document.getElementById('eventScheduleGridContainer');
        const eventScheduleGridPlaceholder = document.getElementById('eventScheduleGridPlaceholder');
        const eventSchedulePagination = document.getElementById('eventSchedulePagination');

        // These will now show FILTERED counts
        const statTotalEventSchedules = document.getElementById('statTotalEventSchedules');
        const statActiveSchedules = document.getElementById('statActiveSchedules');
        const statTotalTicketsMapped = document.getElementById('statTotalTicketsMapped');   

        const ticketManagementModal = document.getElementById('ticketManagementModal');
        const closeTicketManagementModal = document.getElementById('closeTicketManagementModal');
        const tmEventScheduleDetails = document.getElementById('tmEventScheduleDetails');
        const ticketMapContainer = document.getElementById('ticketMapContainer');

        const modalStatTotalTickets = document.getElementById('statModalTotalTickets'); // Total Active
        const modalStatAvailableTickets = document.getElementById('statModalAvailableTickets');
        const modalStatInactiveTickets = document.getElementById('statModalInactiveTickets'); // New
        const modalStatVenueCapacity = document.getElementById('statModalVenueCapacity');
        const modalStatByType = document.getElementById('statModalByType');
        const modalStatByPrice = document.getElementById('statModalByPrice');

        const singleEventScheduleIdModalInput = document.getElementById('single_event_schedule_id_modal');
        const bulkEventScheduleIdModalInput = document.getElementById('bulk_event_schedule_id_modal');
        const generateSingleTicketFormModal = document.getElementById('generateSingleTicketFormModal');
        const showSingleTicketFormBtn = document.getElementById('showSingleTicketFormBtn');
        const SIngleTicketrowInput = document.getElementById('single_ticket_row_modal');
        const SIngleTicketColInput = document.getElementById('single_ticket_column_modal');

        const generateBulkTicketFormModal = document.getElementById('generateBulkTicketFormModal');
        const showBulkTicketFormBtn = document.getElementById('showBulkTicketFormBtn');
        const bulkPreviewModalDiv = document.getElementById('bulkPreviewModal');
            
        const ticketMapFiltersForm = document.getElementById('ticketMapFiltersForm');
        const btnUpdateSelectedTickets = document.getElementById('btnUpdateSelectedTickets');
        const btnDeleteSelectedTickets = document.getElementById('btnDeleteSelectedTickets');
        const updateTicketFormContainer = document.getElementById('updateTicketFormContainer');
        const updateTicketForm = document.getElementById('updateTicketForm');
        const cancelUpdateTicketBtn = document.getElementById('cancelUpdateTicket');
        const resetTicketMapFiltersBtn = document.getElementById('resetTicketMapFilters');

        if (!eventScheduleGridContainer) debugLog("Error: eventScheduleGridContainer is NULL!");
        if (!ticketManagementModal) debugLog("Error: ticketManagementModal is NULL!");

        let currentEventScheduleIdForModal = null;
        let currentMapTickets = [];
        let selectedTicketIds = new Set();
        let venueCapacityForCurrentSchedule = 0; // Fetched with ticket data

        const itemsPerPage = <?php echo RECORDS_PER_PAGE; ?>;
        let currentPage = 1;

        // --- Toast Notification Function ---
        let toastCounter = 0;
        function showToast(message, type = 'info') {
            const toastId = 'toast-' + toastCounter++;
            const iconClass = {
                'success': 'fa-check-circle',
                'error': 'fa-exclamation-triangle',
                'warning': 'fa-triangle-exclamation',
                'info': 'fa-info-circle'
            }[type] || 'fa-info-circle';

            const toastHtml = `
                <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">
                    <div class="toast-header">
                        <i class="fas ${iconClass} me-2"></i>
                        <strong class="me-auto text-capitalize text-white">${type}</strong>
                        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                    <div class="toast-body">${message}</div>
                </div>
            `;
            const toastContainer = document.querySelector('.toast-container');
            if (!toastContainer) {
                console.error("Toast container not found!");
                return;
            }
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.getElementById(toastId);
            if (toastElement) {
                const toast = new bootstrap.Toast(toastElement);
                toast.show();
                toastElement.addEventListener('hidden.bs.toast', () => toastElement.remove());
            } else {
                console.error(`Toast element with ID ${toastId} not found after insertion.`);
            }
        }


        // --- Main Event Schedule Fetching and Rendering ---
        async function fetchEventSchedules(page = 1) {
            debugLog("fetchEventSchedules called for page:", page);
            if(eventScheduleGridPlaceholder) eventScheduleGridPlaceholder.style.display = 'flex';
            if(eventScheduleGridContainer) eventScheduleGridContainer.innerHTML = '';
            if(eventSchedulePagination) eventSchedulePagination.innerHTML = '';

            const formData = new FormData(eventScheduleFiltersForm);
            const params = new URLSearchParams(formData);
            params.append('action', 'get_event_schedules_with_ticket_counts');
            params.append('page', page);
            params.append('limit', itemsPerPage);
            debugLog("Fetching event schedules with params:", params.toString());

            try {
                const response = await fetch(`event_schedule_tickets_handler.php?${params.toString()}`);
                if (!response.ok) {
                    // Attempt to parse non-JSON response in case of PHP errors / HTML output
                    const errorText = await response.text();   
                    debugLog("Non-OK response text:", errorText);
                    throw new Error(`HTTP error! status: ${response.status}. Response: ${errorText.substring(0, 500)}...`); // Show a snippet
                }
                const data = await response.json(); // THIS IS WHERE THE ERROR 'Unexpected token <' happens if PHP outputs HTML
                debugLog("fetchEventSchedules data received:", data);

                if(eventScheduleGridPlaceholder) eventScheduleGridPlaceholder.style.display = 'none';
                if (data.success) {
                    renderEventScheduleCards(data.event_schedules);
                    renderPagination(data.total_event_schedules, page, data.total_pages); // total_event_schedules is already filtered total
                    updateMainStats(data.overall_stats, data.filtered_view_stats); // Pass both sets of stats

                    if (eventScheduleGridContainer && (!data.event_schedules || data.event_schedules.length === 0)) {
                        eventScheduleGridContainer.innerHTML = '<p class="text-center text-muted w-100">No event schedules found matching your criteria.</p>';
                    }
                } else {
                    showToast(data.message || 'Failed to load event schedules.', 'error');
                    if(eventScheduleGridContainer) eventScheduleGridContainer.innerHTML = `<p class="text-center text-danger w-100">Error loading event schedules: ${htmlspecialchars(data.message || 'Unknown error')}</p>`;
                }
            } catch (error) {
                if(eventScheduleGridPlaceholder) eventScheduleGridPlaceholder.style.display = 'none';
                console.error('Error fetching event schedules:', error);
                showToast('Client-side error fetching event schedules: ' + error.message, 'error');
                if(eventScheduleGridContainer) eventScheduleGridContainer.innerHTML = '<p class="text-center text-danger w-100">Client-side error loading event schedules. Check console.</p>';
            }
        }

        function renderEventScheduleCards(schedules) {
            debugLog("renderEventScheduleCards called with schedules:", schedules);
            if(eventScheduleGridContainer) eventScheduleGridContainer.innerHTML = ''; // Clear previous content
            if (!schedules || schedules.length === 0) {
                return;
            }
            schedules.forEach(schedule => {
                const activeStatus = schedule.is_schedule_active === 'yes'
                    ? '<span class="active-status yes">Active</span>'
                    : '<span class="active-status no">Inactive</span>';

                const cardHTML = `
                    <div class="event-schedule-card" data-event-schedule-id="${htmlspecialchars(schedule.event_schedule_id)}"
                        data-event-name="${htmlspecialchars(schedule.event_name)}"
                        data-venue-name="${htmlspecialchars(schedule.venue_name)} - ${htmlspecialchars(schedule.sub_venue_name)}"
                    >
                        <div class="event-schedule-card-header">
                            ${activeStatus}
                            <h6>${htmlspecialchars(schedule.event_name || 'N/A')}</h6>
                            <p class="mb-1"><i class="fas fa-map-marker-alt fa-xs"></i> <strong>Venue:</strong> ${htmlspecialchars(schedule.venue_name || 'N/A')} - ${htmlspecialchars(schedule.sub_venue_name || 'N/A')}</p>
                            <p class="mb-1"><i class="fas fa-city fa-xs"></i> <strong>City:</strong> ${htmlspecialchars(schedule.city_name || 'N/A')}</p>
                            <p class="mb-1"><i class="fas fa-calendar-alt fa-xs"></i> <strong>Schedule:</strong> <small>${formatDateTime(schedule.slot_starts_at)} to ${formatTime(schedule.slot_ends_at)}</small></p>
                            <p class="mb-1"><i class="fas fa-info-circle fa-xs"></i> <strong>Type:</strong> ${htmlspecialchars(schedule.event_type || 'N/A')}, <strong>Genre:</strong> ${htmlspecialchars(schedule.genre || 'N/A')}</p>
                        </div>
                        <div class="event-schedule-stats">
                            <div class="stat-item"><span>Total Active Tickets:</span> <span class="stat-value">${htmlspecialchars(schedule.total_tickets || 0)}</span></div>
                            <div class="stat-item"><span>Available Tickets:</span> <span class="stat-value">${htmlspecialchars(schedule.available_tickets || 0)}</span></div>
                            <div class="stat-item"><span>Venue Capacity:</span> <span class="stat-value">${htmlspecialchars(schedule.capacity || 'N/A')}</span></div>
                        </div>
                    </div>`;
                if(eventScheduleGridContainer) eventScheduleGridContainer.insertAdjacentHTML('beforeend', cardHTML);
            });
            
            // Add click listeners to newly rendered cards
            if(eventScheduleGridContainer) {
                eventScheduleGridContainer.querySelectorAll('.event-schedule-card').forEach(card => {
                    card.addEventListener('click', handleEventScheduleCardClick);
                });
            }
        }

        function updateMainStats(overallStats, filteredViewStats) { // Now accepts both global and filtered stats
            debugLog("Updating main stats with overall:", overallStats, "and filtered view stats:", filteredViewStats);

            // Update the main page stat cards to reflect the currently filtered data
            if (statTotalEventSchedules) statTotalEventSchedules.textContent = filteredViewStats.total_filtered_schedules || 0;
            if (statActiveSchedules) statActiveSchedules.textContent = filteredViewStats.active_filtered_schedules || 0;
            if (statTotalTicketsMapped) statTotalTicketsMapped.textContent = filteredViewStats.total_mapped_tickets_in_filtered_schedules || 0;
        }

        function renderPagination(totalItems, currentPage, totalPages) {
            if (totalPages <= 1) {
                if(eventSchedulePagination) eventSchedulePagination.innerHTML = '';
                return;
            }
            let paginationHTML = '';
            // Previous Button
            paginationHTML += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${currentPage - 1}" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a></li>`;
                
            // Page Numbers
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);

            if (startPage > 1) paginationHTML += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
            if (startPage > 2) paginationHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;

            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
            }
                
            if (endPage < totalPages - 1) paginationHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            if (endPage < totalPages) paginationHTML += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;

            // Next Button
            paginationHTML += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${currentPage + 1}" aria-label="Next"><span aria-hidden="true">&raquo;</span></a></li>`;
                
            if(eventSchedulePagination) eventSchedulePagination.innerHTML = paginationHTML;
        }

        // --- Event Listeners for Main View ---
        if (eventScheduleFiltersForm) {
            eventScheduleFiltersForm.addEventListener('submit', function(e) {
                e.preventDefault();
                debugLog("Event schedule filters form submitted.");
                currentPage = 1;
                fetchEventSchedules(currentPage);
            });
        }

        if (eventSchedulePagination) {
            eventSchedulePagination.addEventListener('click', function(e) {
                e.preventDefault();
                const link = e.target.closest('.page-link');
                if (link && link.dataset.page) {
                    const page = parseInt(link.dataset.page);
                    // Ensure the pagination has current total pages set for accurate bounds checking
                    const totalPagesAttr = eventSchedulePagination.querySelector('.page-item.active .page-link');
                    const currentTotalPages = totalPagesAttr ? parseInt(totalPagesAttr.dataset.page) : 1000000; // Fallback for safety
                    
                    if (!isNaN(page) && page !== currentPage && page > 0 && page <= currentTotalPages && !link.closest('.page-item.disabled')) {
                        debugLog("Pagination link clicked for page:", page);
                        currentPage = page;
                        fetchEventSchedules(currentPage);
                    }
                }
            });
        }

        // --- HTML Escaping for Display ---
        function htmlspecialchars(str) {
            if (typeof str === 'undefined' || str === null) return ''; // Handle undefined or null
            if (typeof str !== 'string') str = String(str);
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return str.replace(/[&<>"']/g, function (m) { return map[m]; });
        }
        function formatDateTime(datetimeString) {
            if (!datetimeString) return 'N/A';
            const date = new Date(datetimeString);
            return date.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) + ' ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
        }
        function formatTime(datetimeString) {
            if (!datetimeString) return 'N/A';
            const date = new Date(datetimeString);
            return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
        }


        // --- Ticket Management Modal Logic ---
        async function handleEventScheduleCardClick() {
            const eventScheduleId = this.dataset.eventScheduleId;
            const eventName = this.dataset.eventName;
            const venueName = this.dataset.venueName; // Combines venue and sub-venue
            debugLog("handleEventScheduleCardClick triggered for event schedule ID:", eventScheduleId);

            if (!eventScheduleId) {
                showToast("Error: Event schedule information is missing. Cannot open details.", "error");
                return;
            }
            currentEventScheduleIdForModal = eventScheduleId;
            
            if(tmEventScheduleDetails) tmEventScheduleDetails.textContent = `${htmlspecialchars(eventName)} at ${htmlspecialchars(venueName)}`;
            if(singleEventScheduleIdModalInput) singleEventScheduleIdModalInput.value = currentEventScheduleIdForModal;
            if(bulkEventScheduleIdModalInput) bulkEventScheduleIdModalInput.value = currentEventScheduleIdForModal;
            
            // Reset forms and selections
            if(generateSingleTicketFormModal) generateSingleTicketFormModal.reset();
            if(generateBulkTicketFormModal) generateBulkTicketFormModal.reset();
            if(bulkPreviewModalDiv) {
                bulkPreviewModalDiv.style.display = 'none';
                bulkPreviewModalDiv.innerHTML = '';
            }
            if(ticketMapFiltersForm) {
                ticketMapFiltersForm.reset();
                document.querySelectorAll('.map-seat.filtered-highlight').forEach(seat => seat.classList.remove('filtered-highlight'));
            }
            selectedTicketIds.clear();
            hideUpdateTicketForm(); // Ensure edit/delete/update forms are hidden
            if(generateSingleTicketFormModal) generateSingleTicketFormModal.style.display = 'none';
            if(generateBulkTicketFormModal) generateBulkTicketFormModal.style.display = 'none';
            if(showSingleTicketFormBtn) showSingleTicketFormBtn.style.display = 'block';
            if(showBulkTicketFormBtn) showBulkTicketFormBtn.style.display = 'block';


            if(ticketManagementModal) ticketManagementModal.style.display = 'flex';
            document.body.style.overflow = 'hidden'; // Prevent body scrolling
            loadEventScheduleTicketDataForModal(currentEventScheduleIdForModal);
        }

        if (closeTicketManagementModal) {
            closeTicketManagementModal.addEventListener('click', function() {
                debugLog("Close ticket management modal clicked.");
                if(ticketManagementModal) ticketManagementModal.style.display = 'none';
                document.body.style.overflow = 'auto'; // Re-enable body scrolling
                currentEventScheduleIdForModal = null;
                currentMapTickets = [];
                selectedTicketIds.clear();
                
                // Reset map container and stats display to initial loading state
                if(ticketMapContainer) ticketMapContainer.innerHTML = '<div class="spinner-container"><div class="spinner-border spinner-border-custom" role="status"><span class="visually-hidden">Loading...</span></div><p class="text-white-50 mt-2">Loading ticket map...</p></div>';
                if(modalStatTotalTickets) modalStatTotalTickets.textContent = '0';
                if(modalStatAvailableTickets) modalStatAvailableTickets.textContent = '0';
                if(modalStatInactiveTickets) modalStatInactiveTickets.textContent = '0'; // Reset inactive count
                if(modalStatVenueCapacity) modalStatVenueCapacity.textContent = '0';
                if(modalStatByType) modalStatByType.innerHTML = '';
                if(modalStatByPrice) modalStatByPrice.innerHTML = '';
            });
        }

        async function loadEventScheduleTicketDataForModal(eventScheduleId) {
            debugLog("loadEventScheduleTicketDataForModal called for eventScheduleId:", eventScheduleId);
            if(ticketMapContainer) ticketMapContainer.innerHTML = '<div class="spinner-container"><div class="spinner-border spinner-border-custom" role="status"><span class="visually-hidden">Loading map...</span></div><p class="text-white-50 mt-2">Loading ticket map...</p></div>';
            selectedTicketIds.clear(); // Clear selections for new schedule
            updateActionButtonsVisibility(); // Update button visibility based on cleared selection

            try {
                const params = new URLSearchParams({
                    action: 'get_event_schedule_ticket_data',
                    event_schedule_id: eventScheduleId
                });
                const response = await fetch(`event_schedule_tickets_handler.php?${params.toString()}`);
                if (!response.ok) {
                    // Attempt to parse non-JSON response in case of PHP errors / HTML output
                    const errorText = await response.text();   
                    debugLog("Non-OK response text for modal data:", errorText);
                    throw new Error(`HTTP error! status: ${response.status}. Response: ${errorText.substring(0, 500)}...`);   
                }
                const data = await response.json();
                debugLog("loadEventScheduleTicketDataForModal data received:", data);

                if (data.success && data.tickets) {
                    currentMapTickets = data.tickets;
                    venueCapacityForCurrentSchedule = data.venue_capacity || 0;

                    // Determine max row/col for map display based on existing tickets
                    let maxRowChar = 'A', maxColNum = 1;
                    if (currentMapTickets.length > 0) {
                        // Find the lexicographically largest row string and largest column number
                        currentMapTickets.forEach(t => {
                            // For 'A', 'B', ..., 'Z', 'AA', 'AB' like sorting
                            if (t.ticket_row && t.ticket_row.localeCompare(maxRowChar) > 0) {
                                maxRowChar = t.ticket_row;
                            }
                            if (t.ticket_column && parseInt(t.ticket_column) > maxColNum) {
                                maxColNum = parseInt(t.ticket_column);
                            }
                        });
                        // Ensure minimum map size for visibility
                        // If current maxRowChar is 'A' and there are tickets, ensure at least 'J' rows are displayed
                        // to make the grid usable for new ticket generation.
                        if (maxRowChar.length === 1 && maxRowChar.charCodeAt(0) < 'J'.charCodeAt(0)) {
                            maxRowChar = 'J';   
                        }
                        if (maxColNum < 20) { // Ensure enough columns for visual grid, even if tickets are few
                            maxColNum = 20;   
                        }
                    } else {
                        maxRowChar = 'J'; // Default for empty maps
                        maxColNum = 20; // Default for empty maps
                    }
                    
                    renderTicketMap(currentMapTickets, maxRowChar, maxColNum);
                    renderModalTicketStats(data.stats, data.venue_capacity);
                } else {
                    showToast('Error loading event tickets: ' + (data.message || 'Tickets data missing'), 'error');
                    if(ticketMapContainer) ticketMapContainer.innerHTML = `<p class="text-center p-3 text-danger">Error loading map data: ${htmlspecialchars(data.message || 'Unknown error')}</p>`;
                }
            } catch (error) {
                console.error('Error in loadEventScheduleTicketDataForModal:', error);
                showToast('Client-side error fetching ticket data for modal. ' + error.message, 'error');
                if(ticketMapContainer) ticketMapContainer.innerHTML = '<p class="text-center p-3 text-danger">Client-side error loading map data. Check console.</p>';
            }
        }
            
        function renderTicketMap(ticketsToRender, maxRow, maxCol) {
            debugLog(`renderTicketMap: MaxRow: ${maxRow}, MaxCol: ${maxCol}. Tickets to render:`, ticketsToRender.length);
            if(ticketMapContainer) ticketMapContainer.innerHTML = '';
            const grid = document.createElement('div');
            grid.className = 'ticket-map-grid';

            // Calculate the number of rows to display (A up to maxRow)
            let rowsToDisplay = [];
            let currentRow = 'A';
            // This iteration logic ensures that 'A' to 'Z', then 'AA' to 'AZ' (and so on) are covered.
            // It's a simplified version of Excel-like column increments.
            while (true) {
                rowsToDisplay.push(currentRow);
                if (currentRow.localeCompare(maxRow) >= 0 && currentRow.length >= maxRow.length) break; // Stop when current row is beyond or equal to maxRow

                let nextRowCharCodes = currentRow.split('').map(char => char.charCodeAt(0));
                let i = nextRowCharCodes.length - 1;
                let carry = true;
                while(i >= 0 && carry) {
                    if (nextRowCharCodes[i] < 'Z'.charCodeAt(0)) {
                        nextRowCharCodes[i]++;
                        carry = false;
                    } else {
                        nextRowCharCodes[i] = 'A'.charCodeAt(0);
                        i--;
                    }
                }
                if (carry) { // If all characters were 'Z', e.g., 'Z' to 'AA', 'ZZ' to 'AAA'
                    currentRow = 'A' + nextRowCharCodes.map(code => String.fromCharCode(code)).join('');
                } else {
                    currentRow = nextRowCharCodes.map(code => String.fromCharCode(code)).join('');
                }
                if (rowsToDisplay.length > 200) { debugLog("RenderTicketMap: Too many rows generated, stopping to prevent infinite loop."); break; } // Safety break
            }
            
            // Adjust grid template columns based on max row name length for labels (e.g. for "AA")
            const firstColWidth = Math.max(25, (maxRow.length + 0.5) * 10); // Adjust for longer row names
            grid.style.gridTemplateColumns = `${firstColWidth}px repeat(${maxCol}, 30px)`; // Corrected interpolation

            const placeholders = document.createElement('div');
            placeholders.className = 'map-placeholder';
            grid.appendChild(placeholders); // Top-left empty corner

            // Column labels
            for (let c = 1; c <= maxCol; c++) {
                const colLabel = document.createElement('div');
                colLabel.className = 'map-col-label';
                colLabel.textContent = c;
                grid.appendChild(colLabel);
            }

            // Row labels and seats
            rowsToDisplay.forEach(rowStr => {
                const rowLabel = document.createElement('div');
                rowLabel.className = 'map-row-label';
                rowLabel.textContent = rowStr;
                grid.appendChild(rowLabel);

                for (let c = 1; c <= maxCol; c++) {
                    const seatDiv = document.createElement('div');
                    seatDiv.className = 'map-seat';
                    seatDiv.dataset.row = rowStr;
                    seatDiv.dataset.col = c;
                    
                    // Find the ticket for this row/column combination
                    const ticket = ticketsToRender.find(
                        t => t.ticket_row === rowStr && parseInt(t.ticket_column) === c
                    );

                    if (ticket) {
                        seatDiv.classList.add(`type-${String(ticket.ticket_type).toLowerCase().replace(/\s+/g, '-')}`);
                        seatDiv.textContent = htmlspecialchars(ticket.ticket_location); // Display ticket_location
                        seatDiv.title = `Seat: ${htmlspecialchars(ticket.ticket_location)}\nType: ${htmlspecialchars(ticket.ticket_type)}\nPrice: ₹${htmlspecialchars(ticket.ticket_price)}\nStatus: ${ticket.is_vacant === 'yes' ? 'Vacant' : 'Booked/Held'}\nActive: ${ticket.is_active === 'yes' ? 'Yes' : 'No'}`;
                        seatDiv.dataset.ticketId = htmlspecialchars(ticket.ticket_id);
                        seatDiv.dataset.ticketType = htmlspecialchars(ticket.ticket_type);
                        seatDiv.dataset.ticketPrice = htmlspecialchars(ticket.ticket_price);
                        seatDiv.dataset.isVacant = htmlspecialchars(ticket.is_vacant);
                        seatDiv.dataset.isActive = htmlspecialchars(ticket.is_active); // Store is_active

                        // Mark inactive/unavailable tickets visually
                        if (ticket.is_vacant !== 'yes' || ticket.is_active !== 'yes') { 
                            seatDiv.classList.add('not-vacant');
                        }
                        if (selectedTicketIds.has(String(ticket.ticket_id))) seatDiv.classList.add('selected');
                    } else {
                        // If no ticket, hint for adding new ones
                        seatDiv.title = `Empty: ${rowStr}${c}\nClick to add new ticket.`; // Use template literal
                    }
                    seatDiv.addEventListener('click', handleSeatClick);
                    grid.appendChild(seatDiv);
                }
            });
            if(ticketMapContainer) ticketMapContainer.appendChild(grid);
        }
            
        function handleSeatClick(e) {
            const seatDiv = e.currentTarget;
            const ticketId = seatDiv.dataset.ticketId ? String(seatDiv.dataset.ticketId) : null;
            const row = seatDiv.dataset.row;
            const col = seatDiv.dataset.col;
            debugLog(`handleSeatClick: Seat clicked. Row: ${row}, Col: ${col}, Ticket ID: ${ticketId}`);

            // If an empty slot (no existing ticketId)
            if (!ticketId) {
                if (showSingleTicketFormBtn) showSingleTicketFormBtn.click(); // Show single ticket form
                if(SIngleTicketrowInput) SIngleTicketrowInput.value = row;
                if(SIngleTicketColInput) SIngleTicketColInput.value = col;
                showToast(`Empty slot ${row}${col} selected. Use the "Generate Single Ticket" form.`, 'info');
                
                // Deselect any currently selected tickets on the map
                Array.from(selectedTicketIds).forEach(id => {
                    const deselectedSeat = ticketMapContainer.querySelector(`[data-ticket-id="${id}"]`);
                    if (deselectedSeat) deselectedSeat.classList.remove('selected');
                });
                selectedTicketIds.clear();
                updateActionButtonsVisibility();
                return; // Stop here, no other actions for empty seats
            }

            // If an existing ticket is clicked
            // Allow multiple selection (toggle) for modification/deletion
            if (selectedTicketIds.has(ticketId)) {
                selectedTicketIds.delete(ticketId);
                seatDiv.classList.remove('selected');
            } else {
                selectedTicketIds.add(ticketId);
                seatDiv.classList.add('selected');
            }
            debugLog("Current selected Ticket IDs:", Array.from(selectedTicketIds));
            updateActionButtonsVisibility();

            if (selectedTicketIds.size === 1 && updateTicketFormContainer && updateTicketFormContainer.style.display !== 'none') {
                prefillUpdateFormForSingleSelection();
            } else if (selectedTicketIds.size === 0) {
                hideUpdateTicketForm();
            }
        }


        function updateActionButtonsVisibility() {
            const hasSelection = selectedTicketIds.size > 0;
            if(btnUpdateSelectedTickets) btnUpdateSelectedTickets.style.display = hasSelection ? 'inline-block' : 'none';
            if(btnDeleteSelectedTickets) btnDeleteSelectedTickets.style.display = hasSelection ? 'inline-block' : 'none';

            if (!hasSelection) {
                if (updateTicketFormContainer && updateTicketFormContainer.style.display !== 'none') {
                    hideUpdateTicketForm();
                }
            }
        }
            
        function prefillUpdateFormForSingleSelection() {
            if (selectedTicketIds.size === 1) {
                const ticketId = selectedTicketIds.values().next().value;
                const ticket = currentMapTickets.find(t => String(t.ticket_id) === ticketId);
                if (ticket) {
                    if(document.getElementById('update_ticket_type')) document.getElementById('update_ticket_type').value = ticket.ticket_type;
                    if(document.getElementById('update_ticket_price')) document.getElementById('update_ticket_price').value = ticket.ticket_price;
                    if(document.getElementById('update_is_vacant')) document.getElementById('update_is_vacant').value = ticket.is_vacant;
                    if(document.getElementById('update_is_active')) document.getElementById('update_is_active').value = ticket.is_active; // New
                }
            } else {
                if(updateTicketForm) updateTicketForm.reset();
            }
        }

        function renderModalTicketStats(stats, venueCapacity) {
            debugLog("renderModalTicketStats called with stats:", stats, "Capacity:", venueCapacity);
            if(modalStatTotalTickets) modalStatTotalTickets.textContent = htmlspecialchars(stats.total_active_tickets || 0); // Use total_active_tickets
            if(modalStatAvailableTickets) modalStatAvailableTickets.textContent = htmlspecialchars(stats.available_tickets || 0);
            if(modalStatInactiveTickets) modalStatInactiveTickets.textContent = htmlspecialchars(stats.inactive_tickets || 0); // New
            if(modalStatVenueCapacity) modalStatVenueCapacity.textContent = htmlspecialchars(venueCapacity || 0);

            if(modalStatByType) modalStatByType.innerHTML = '';
            if (stats.by_type && Object.keys(stats.by_type).length > 0 && modalStatByType) {
                let typeHtml = `<small>By Type: `;
                for (const type in stats.by_type) { typeHtml += `${htmlspecialchars(type)}: ${htmlspecialchars(stats.by_type[type])} | `; }
                modalStatByType.innerHTML = typeHtml.slice(0, -3) + `</small>`;
            }
            if(modalStatByPrice) modalStatByPrice.innerHTML = ''
            if (stats.by_price && Object.keys(stats.by_price).length > 0 && modalStatByPrice) {
                let priceHtml = `<small>By Price: `;
                for (const price in stats.by_price) {priceHtml += `₹${htmlspecialchars(price)}: ${htmlspecialchars(stats.by_price[price])} | `; }
                modalStatByPrice.innerHTML = priceHtml.slice(0, -3) + `</small>`;
            }
        }
            
        if (ticketMapFiltersForm) {
            ticketMapFiltersForm.addEventListener('submit', function(e) {
                e.preventDefault();
                debugLog("Ticket map filters submitted.");
                applyMapFilters();
            });
        }

        if (resetTicketMapFiltersBtn) {
            resetTicketMapFiltersBtn.addEventListener('click', function() {
                ticketMapFiltersForm.reset();
                // Remove all highlights
                document.querySelectorAll('.map-seat.filtered-highlight').forEach(seat => seat.classList.remove('filtered-highlight'));
                showToast('Map filters cleared.', 'info');
            });
        }

        function applyMapFilters() {
            const formData = new FormData(ticketMapFiltersForm);
            const typeFilter = formData.get('map_ticket_type');
            const priceFilterStr = formData.get('map_ticket_price');
            const priceFilter = priceFilterStr ? parseFloat(priceFilterStr) : null;
            const vacantFilter = formData.get('map_is_vacant');
            const activeFilter = formData.get('map_is_active'); // New
            debugLog(`Applying map filters - Type: ${typeFilter}, Price: ${priceFilter}, Vacant: ${vacantFilter}, Active: ${activeFilter}`);

            let foundMatch = false;
            document.querySelectorAll('.map-seat').forEach(seat => {
                seat.classList.remove('filtered-highlight'); // Clear previous highlight
                const ticketId = seat.dataset.ticketId;
                if (!ticketId) return; // Skip empty seats

                const ticket = currentMapTickets.find(t => String(t.ticket_id) === ticketId);
                if (!ticket) return;

                let matches = true;
                if (typeFilter && ticket.ticket_type !== typeFilter) matches = false;
                if (priceFilter !== null && parseFloat(ticket.ticket_price) !== priceFilter) matches = false;
                if (vacantFilter && ticket.is_vacant !== vacantFilter) matches = false;
                if (activeFilter && ticket.is_active !== activeFilter) matches = false; // New condition
                    
                if (matches) {
                    seat.classList.add('filtered-highlight');
                    foundMatch = true;
                }
            });
            if (!foundMatch && (typeFilter || priceFilterStr || vacantFilter || activeFilter)) { // Check all filters
                showToast('No tickets match map filters.', 'info');
            } else if (!typeFilter && !priceFilterStr && !vacantFilter && !activeFilter) { // If all fields empty
                document.querySelectorAll('.map-seat.filtered-highlight').forEach(seat => seat.classList.remove('filtered-highlight'));
            }
        }

        // --- Ticket Generation / CRUD (Modal Forms) ---

        // Toggle visibility for single ticket form
        if(showSingleTicketFormBtn) {
            showSingleTicketFormBtn.addEventListener('click', function() {
                if(generateSingleTicketFormModal) generateSingleTicketFormModal.style.display = 'block';
                if(showSingleTicketFormBtn) showSingleTicketFormBtn.style.display = 'none';
                // Hide bulk form if active
                if(generateBulkTicketFormModal) generateBulkTicketFormModal.style.display = 'none';
                if(showBulkTicketFormBtn) showBulkTicketFormBtn.style.display = 'block';
            });
        }

        // Toggle visibility for bulk ticket form
        if(showBulkTicketFormBtn) {
            showBulkTicketFormBtn.addEventListener('click', function() {
                if(generateBulkTicketFormModal) generateBulkTicketFormModal.style.display = 'block';
                if(showBulkTicketFormBtn) showBulkTicketFormBtn.style.display = 'none';
                // Hide single form if active
                if(generateSingleTicketFormModal) generateSingleTicketFormModal.style.display = 'none';
                if(showSingleTicketFormBtn) showSingleTicketFormBtn.style.display = 'block';
            });
        }

        if(generateSingleTicketFormModal) {
            generateSingleTicketFormModal.addEventListener('submit', async function(e) {
                e.preventDefault();
                debugLog("Generate single ticket form submitted.");
                const formData = new FormData(this);
                formData.append('action', 'generate_single_ticket');
                
                const ticketRow = formData.get('ticket_row').toUpperCase();
                if (!/^[A-Z]+$/.test(ticketRow)) { // Validates only uppercase letters (A-Z)
                    showToast('Invalid Row: Only uppercase letters (A-Z) allowed.', 'error'); return;
                }
                formData.set('ticket_row', ticketRow);

                // Check capacity constraints
                // Use modalStatTotalTickets which is total active tickets
                const currentTotalTickets = parseInt(modalStatTotalTickets.textContent);  
                if (currentTotalTickets >= venueCapacityForCurrentSchedule) {
                    showToast(`Cannot add ticket: Venue capacity (${venueCapacityForCurrentSchedule}) reached for active tickets.`, 'warning');
                    return;
                }

                try {
                    const response = await fetch('event_schedule_tickets_handler.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    debugLog("Single ticket gen result:", result);
                    if (result.success) {
                        showToast(result.message || 'Ticket generated!', 'success');
                        this.reset(); // Clear the form
                        if(generateSingleTicketFormModal) generateSingleTicketFormModal.style.display = 'none';
                        if(showSingleTicketFormBtn) showSingleTicketFormBtn.style.display = 'block';
                        loadEventScheduleTicketDataForModal(currentEventScheduleIdForModal); // Refresh map and stats
                        fetchEventSchedules(currentPage); // Refresh main list stats
                    } else { showToast(result.message || 'Failed to generate ticket.', 'error'); }
                } catch (error) {
                    console.error("Single Gen Error:", error);
                    showToast('Client-side error (single gen): ' + error.message, 'error');
                }
            });
        }

        // Logic for calculating bulk ticket preview
        // Helper function to convert row string to integer (for A-Z, AA-AZ, etc.)
        function getRowInt(rowStr) {
            rowStr = rowStr.toUpperCase();
            let value = 0;
            for (let i = 0; i < rowStr.length; i++) {
                const charCode = rowStr.charCodeAt(i);
                if (charCode >= 'A'.charCodeAt(0) && charCode <= 'Z'.charCodeAt(0)) {
                    value = value * 26 + (charCode - 'A'.charCodeAt(0) + 1);
                } else {
                    return NaN; // Indicate invalid format for this helper
                }
            }
            return value;
        }

        // Helper function to convert integer to row string (for A-Z, AA-AZ, etc.)
        function intToRow(value) {
            if (value <= 0) return '';
            let result = '';
            while (value > 0) {
                value--; // Adjust for 0-indexed alphabet (A=0, B=1, ...)
                const remainder = value % 26;
                result = String.fromCharCode('A'.charCodeAt(0) + remainder) + result;
                value = Math.floor(value / 26);
            }
            return result;
        }


        ['bulk_row_start_modal', 'bulk_row_end_modal', 'bulk_col_start_modal', 'bulk_col_end_modal', 'bulk_ticket_price_modal'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('input', () => {
                    const rowStartInput = document.getElementById('bulk_row_start_modal');
                    const rowEndInput = document.getElementById('bulk_row_end_modal');
                    
                    // Force uppercase and basic cleanup for row inputs
                    if(rowStartInput) rowStartInput.value = rowStartInput.value.toUpperCase().replace(/[^A-Z]/g, ''); // Only A-Z
                    if(rowEndInput) rowEndInput.value = rowEndInput.value.toUpperCase().replace(/[^A-Z]/g, ''); // Only A-Z

                    const rowStart = rowStartInput ? rowStartInput.value : '';
                    const rowEnd = rowEndInput ? rowEndInput.value : '';
                    const colStartInput = document.getElementById('bulk_col_start_modal');
                    const colEndInput = document.getElementById('bulk_col_end_modal');
                    const priceInput = document.getElementById('bulk_ticket_price_modal');

                    const colStart = colStartInput ? parseInt(colStartInput.value) : NaN;
                    const colEnd = colEndInput ? parseInt(colEndInput.value) : NaN;
                    const priceStr = priceInput ? priceInput.value : '';
                    const price = priceStr ? parseFloat(priceStr) : NaN;

                    const rowStartInt = getRowInt(rowStart);
                    const rowEndInt = getRowInt(rowEnd);

                    if (rowStart && rowEnd && !isNaN(rowStartInt) && !isNaN(rowEndInt) && rowStartInt <= rowEndInt &&
                        !isNaN(colStart) && !isNaN(colEnd) && colStart > 0 && colEnd > 0 && colStart <= colEnd) {
                            
                        const numRows = rowEndInt - rowStartInt + 1;
                        const numCols = colEnd - colStart + 1;
                        const totalTickets = numRows * numCols;
                            
                        if(bulkPreviewModalDiv) {
                            bulkPreviewModalDiv.style.display = 'block';
                            let previewText = `Estimated: <strong>${totalTickets}</strong> tickets.`;
                            if (!isNaN(price) && price >= 0) {
                                previewText += ` Total Value: <strong>₹${(totalTickets * price).toFixed(2)}</strong>.`;
                            }
                            // Check for capacity issues
                            const currentTotalActiveTickets = parseInt(modalStatTotalTickets.textContent); // Use active tickets
                            if ((currentTotalActiveTickets + totalTickets) > venueCapacityForCurrentSchedule) {
                                previewText += ` <span class="text-danger">Warning: This batch (${totalTickets}) combined with existing active tickets (${currentTotalActiveTickets}) will exceed venue capacity (${venueCapacityForCurrentSchedule})!</span>`;
                            } else if (totalTickets > 1000) { // arbitrary warning for large batches
                                previewText += ` <span>Consider smaller batches for very large counts.</span>`;
                            }
                            bulkPreviewModalDiv.innerHTML = previewText;
                        }
                    } else if (bulkPreviewModalDiv) {
                        bulkPreviewModalDiv.style.display = 'none';
                        bulkPreviewModalDiv.innerHTML = '';
                    }
                });
            }
        });

        if (generateBulkTicketFormModal) {
            generateBulkTicketFormModal.addEventListener('submit', async function(e) {
                e.preventDefault();
                debugLog("Generate bulk ticket form submitted.");
                const formData = new FormData(this);
                formData.append('action', 'generate_bulk_tickets');
                
                // Force uppercase and basic cleanup for row inputs
                const rowStartInput = document.getElementById('bulk_row_start_modal');
                const rowEndInput = document.getElementById('bulk_row_end_modal');
                const rowStart = rowStartInput? rowStartInput.value.toUpperCase().replace(/[^A-Z]/g, ''): '';
                const rowEnd = rowEndInput? rowEndInput.value.toUpperCase().replace(/[^A-Z]/g, ''): '';
                formData.set('row_start', rowStart);
                formData.set('row_end', rowEnd);

                const rowStartInt = getRowInt(rowStart);
                const rowEndInt = getRowInt(rowEnd);

                if (!rowStart || !rowEnd || isNaN(rowStartInt) || isNaN(rowEndInt) || rowStartInt > rowEndInt) {
                    showToast('Invalid Row Range: Use uppercase letters (A-Z), Start <= End.', 'error');
                    return;
                }

                const colStart = parseInt(formData.get('col_start'));
                const colEnd = parseInt(formData.get('col_end'));
                if (isNaN(colStart) || isNaN(colEnd) || colStart <= 0 || colEnd <= 0 || colStart > colEnd) {
                    showToast('Invalid Column Range: Must be positive numbers, Start <= End.', 'error');
                    return;
                }

                try {
                    const response = await fetch('event_schedule_tickets_handler.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    debugLog("Bulk ticket gen result:", result);
                    if (result.success) {
                        showToast(result.message || 'Bulk tickets generated!', 'success');
                        this.reset();
                        if(bulkPreviewModalDiv) {
                            bulkPreviewModalDiv.style.display = 'none';
                            bulkPreviewModalDiv.innerHTML = '';
                        }
                        loadEventScheduleTicketDataForModal(currentEventScheduleIdForModal); // Refresh map and stats
                        fetchEventSchedules(currentPage); // Refresh main list stats
                    } else { showToast(result.message || 'Failed to generate bulk tickets.', 'error'); }
                } catch (error) {
                    console.error("Bulk Gen Error:", error);
                    showToast('Client-side error (bulk gen): ' + error.message, 'error');
                }
            });
        }

        if(btnUpdateSelectedTickets) {
            btnUpdateSelectedTickets.addEventListener('click', function() {
                debugLog("Update selected tickets button clicked. Selected count:", selectedTicketIds.size);
                if (selectedTicketIds.size === 0) {
                    showToast('No tickets selected to update.', 'info');
                    return;
                }
                if(updateTicketFormContainer) updateTicketFormContainer.style.display = 'block';
                if(updateTicketForm) updateTicketForm.reset();
                prefillUpdateFormForSingleSelection();
                
                this.style.display = 'none';
                if(btnDeleteSelectedTickets) btnDeleteSelectedTickets.style.display = 'none';
            });
        }

        if(cancelUpdateTicketBtn) {
            cancelUpdateTicketBtn.addEventListener('click', function() {
                debugLog("Cancel update ticket button clicked.");
                hideUpdateTicketForm();
                updateActionButtonsVisibility(); // Re-show the original buttons
            });
        }

        function hideUpdateTicketForm() {
            if(updateTicketFormContainer) updateTicketFormContainer.style.display = 'none';
            if(updateTicketForm) updateTicketForm.reset();
        }

        if(updateTicketForm) {
            updateTicketForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                debugLog("Update ticket form submitted. Selected count:", selectedTicketIds.size);
                if (selectedTicketIds.size === 0) {
                    showToast('No tickets selected.', 'error'); return;
                }
                
                const currentFormData = new FormData(this);
                const newType = currentFormData.get('ticket_type');
                const newPriceStr = currentFormData.get('ticket_price');
                const newVacant = currentFormData.get('is_vacant');
                const newActive = currentFormData.get('is_active'); // New

                if (!newType && !newPriceStr && !newVacant && !newActive) { // Include newActive
                    showToast('No changes specified for update.', 'info');
                    return;
                }

                const payload = new FormData();
                payload.append('action', 'update_tickets');
                payload.append('ticket_ids', JSON.stringify(Array.from(selectedTicketIds)));
                payload.append('event_schedule_id', currentEventScheduleIdForModal);

                // Append only the fields that were actually changed/provided
                if (newType) payload.append('ticket_type', newType);
                if (newPriceStr) {
                    const priceVal = parseFloat(newPriceStr);
                    if (isNaN(priceVal) || priceVal < 0) {
                        showToast('Invalid price. Must be a non-negative number.', 'error'); return;
                    }
                    payload.append('ticket_price', priceVal);
                }
                if (newVacant) payload.append('is_vacant', newVacant);
                if (newActive) payload.append('is_active', newActive); // New

                try {
                    const response = await fetch('event_schedule_tickets_handler.php', { method: 'POST', body: payload });
                    const result = await response.json();
                    debugLog("Update tickets result:", result);
                    if (result.success) {
                        showToast(result.message || 'Tickets updated!', 'success');
                        loadEventScheduleTicketDataForModal(currentEventScheduleIdForModal); // Refresh map and stats
                        fetchEventSchedules(currentPage); // Refresh main list stats
                    } else { showToast(result.message || 'Failed to update tickets.', 'error'); }
                } catch (error) {
                    console.error("Update Tickets Error:", error);
                    showToast('Client-side error (update): ' + error.message, 'error');
                }
            });
        }

        if(btnDeleteSelectedTickets) {
            btnDeleteSelectedTickets.addEventListener('click', async function() {
                debugLog("Delete selected tickets button clicked. Selected count:", selectedTicketIds.size);
                if (selectedTicketIds.size === 0) {
                    showToast('No tickets selected to delete.', 'info'); return;
                }
                if (!confirm(`WARNING: This action will PERMANENTLY DELETE ${selectedTicketIds.size} selected ticket(s) from the database. This action cannot be undone. Are you absolutely sure?`)) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'delete_tickets'); // Permanent delete
                formData.append('ticket_ids', JSON.stringify(Array.from(selectedTicketIds)));
                formData.append('event_schedule_id', currentEventScheduleIdForModal);

                try {
                    const response = await fetch('event_schedule_tickets_handler.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    debugLog("Delete tickets result:", result);
                    if (result.success) {
                        showToast(result.message || 'Tickets permanently deleted!', 'success');
                        loadEventScheduleTicketDataForModal(currentEventScheduleIdForModal); // Refresh map and stats
                        fetchEventSchedules(currentPage); // Refresh main list stats
                    } else { showToast(result.message || 'Failed to permanently delete tickets.', 'error'); }
                } catch (error) {
                    console.error("Delete Tickets Error:", error);
                    showToast('Client-side error (delete): ' + error.message, 'error');
                }
            });
        }

        // --- Sidebar Toggle for mobile/responsiveness (Copied from dashboard.php) ---
        const sidebar = document.getElementById('sidebar');
        const contentWrapper = document.getElementById('contentWrapper'); // Changed from mainContent
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');

        const setSidebarOpen = (isOpen) => {
            if (isOpen) {
                sidebar.classList.add('is-open');
                // Apply class to body to trigger content-wrapper margin adjustment
                document.body.classList.add('sidebar-is-open');
                if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-times"></i>';
            } else {
                sidebar.classList.remove('is-open');
                // Remove class from body to revert content-wrapper margin adjustment
                document.body.classList.remove('sidebar-is-open');
                if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
                // When collapsing the main sidebar, also collapse any open submenus
                document.querySelectorAll('.sidebar-nav .collapse.show').forEach(collapseElement => {
                    const bsCollapse = bootstrap.Collapse.getInstance(collapseElement) || new bootstrap.Collapse(collapseElement, { toggle: false });
                    bsCollapse.hide();
                });
            }
        };

        if (sidebar && contentWrapper  && window.innerWidth >= 768) { // Only apply hover on desktop
            // Desktop hover behavior
            sidebar.addEventListener('mouseenter', () => {
                setSidebarOpen(true);
            });
            sidebar.addEventListener('mouseleave', () => {
                setSidebarOpen(false);
            });
        }

        if (sidebarToggleBtn) {
            // Mobile click toggle behavior
            sidebarToggleBtn.addEventListener('click', function () {
                setSidebarOpen(!sidebar.classList.contains('is-open'));
            });

            // Click outside to close sidebar on mobile
            document.addEventListener('click', function (event) {
                if (window.innerWidth <= 768 && sidebar.classList.contains('is-open')) {
                    if (!sidebar.contains(event.target) && !sidebarToggleBtn.contains(event.target)) {
                        setSidebarOpen(false);
                    }
                }
            });
        }

        // --- Active Link and Submenu Management ---
        const currentPath = window.location.pathname;
        const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

        navLinks.forEach(link => {
            const linkHref = link.getAttribute('href');
            if (!linkHref || linkHref.startsWith('#')) return;

            const currentFilename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
            const linkFilename = linkHref.substring(linkHref.lastIndexOf('/') + 1);

            if (linkFilename === currentFilename) {
                link.classList.add('active'); // Mark the specific item as active

                const parentCollapseDiv = link.closest('.collapse');
                if (parentCollapseDiv) {
                    const bsCollapse = bootstrap.Collapse.getInstance(parentCollapseDiv) || new bootstrap.Collapse(parentCollapseDiv, { toggle: false });
                    bsCollapse.show(); 

                    const parentToggleLink = document.querySelector(`a[data-bs-target="#${parentCollapseDiv.id}"]`);
                    if (parentToggleLink) {
                        parentToggleLink.classList.remove('collapsed'); 
                        parentToggleLink.setAttribute('aria-expanded', 'true');
                    }
                }
            }
        });

        // --- Caret Icon Rotation on Collapse Events ---
        document.querySelectorAll('.sidebar-nav .collapse').forEach(collapseElement => {
            collapseElement.addEventListener('show.bs.collapse', function () {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(180deg)';
                    toggleLink.classList.add('active'); // Optionally activate parent link on expand
                }
            });

            collapseElement.addEventListener('hide.bs.collapse', function () {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(0deg)';
                    
                    // Only deactivate parent link if none of its *current* sub-items are active
                    const hasActiveChild = this.querySelector('.nav-link.active');
                    if (!hasActiveChild) {
                        toggleLink.classList.remove('active');
                    }
                }
            });
        });
            
        // Initial call to fetch event schedules when the page loads
        fetchEventSchedules(currentPage);
        debugLog("Script execution finished.");
    });
</script>

</body>
</html>