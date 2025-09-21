<?php
// venue_schedules.php
// Start session at the very top of the script
session_start();
date_default_timezone_set('Asia/Kolkata'); // Ensure timezone consistency

require_once '../links.php'; // Include the links.php file for common links and configurations

// Database configuration (from links.php or defined here if not in links.php)
// Assuming these are defined in links.php based on dashboard.php's structure.
// If not, you'd need to define them here:
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Redirect to login if not authenticated (except for auth pages)
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

$adminUserId = isset($_SESSION['user_id']); // This is the user ID for which we want to display data
$adminUsername = $isLoggedIn ? $_SESSION['username'] : ''; // Corresponding username, for display purposes if needed.


// Connect to database
try {
    $dsn = "mysql:host={$host};dbname={$dbname}";
    $pdo = new PDO($dsn, $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'"); // Set charset to UTF-8
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$pageTitle = "Manage Venue Schedules";

// Fetch cities (for filter dropdown) using PDO
$cities_data = [];
try {
    // Note: This fetches all active cities, not necessarily filtered by created_by,
    // assuming venue management is a general admin function.
    $city_sql = "SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name ASC";
    $city_stmt = $pdo->prepare($city_sql);
    $city_stmt->execute();
    $cities_data = $city_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("ERROR: Could not fetch cities: " . $e->getMessage());
}

// Get filter parameters from GET for initial display
$filter_city = isset($_GET['filter_city']) ? $_GET['filter_city'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$search_term = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search'])) : '';
$start_date_filter = isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : '';
$end_date_filter = isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : '';
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;

// Clear message after display if any
if (isset($_SESSION['message'])) {
    unset($_SESSION['message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>

    <!-- Bootstrap CSS (via CDN) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CSS (via CDN) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Custom CSS (Merged from dashboard.php) -->
    <style>
        /* Custom Color Variables - Dark Theme Default (from dashboard.php) */
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

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-color);
            margin: 0;
            display: flex; /* Flexbox for sidebar and content wrapper */
            transition: background-color 0.3s ease;
        }

        /* New wrapper for Main Content and Footer (from dashboard.php) */
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

        /* Main Content Area (from dashboard.php) */
        .main-content {
            flex-grow: 1; /* Take up remaining space AFTER padding */
            padding: 20px;
        }

        /* General Styles (from dashboard.php) */
        .container-fluid { /* Adjusted from .container in dashboard.php */
            max-width: 1200px; /* Max-width might need adjustment for wider tables/cards */
            padding-left: 15px;
            padding-right: 15px;
        }
        @media (max-width: 768px) {
            .container-fluid {
                padding-left: 20px;
                padding-right: 20px;
            }
        }

        .section-title {
            color: var(--accent-orange);
            font-size: var(--section-title-font);
            margin-bottom: 20px;
            border-bottom: 2px solid var(--accent-red);
            padding-bottom: 10px;
        }
        .section-subtitle {
            font-size: var(--section-subtitle-font);
            margin-top: 30px;
            margin-bottom: 15px;
            color: var(--accent-peach);
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 5px;
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
        .form-control, .form-select, .input-group-text { /* input-group-text added for venue_schedules.php */
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
        .form-check-label {
            color: var(--text-color);
        }
        /* Custom styling for form-check-input (general checkbox) */
        input[type="checkbox"].form-check-input {
            width: 1.25em;
            height: 1.25em;
            vertical-align: top;
            background-color: var(--primary-bg); /* Use primary-bg as background for non-selected */
            border: 1px solid var(--accent-orange);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            border-radius: 0.25rem;
            transition: background-color .15s ease-in-out, border-color .15s ease-in-out, box-shadow .15s ease-in-out, background-image .15s ease-in-out;
            cursor: pointer;
            flex-shrink: 0; /* Important for alignment in flex containers */
        }
        input[type="checkbox"].form-check-input:checked {
            background-color: var(--accent-red);
            border-color: var(--accent-red);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M6 10l3 3l6-6'/%3e%3csvg%3e");
            background-size: 100% 100%;
        }
        input[type="checkbox"].form-check-input:focus {
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
        }

        /* Styles for new filter checkbox groups (from dashboard.php) */
        .filter-checkbox-group {
            max-height: 150px; /* Adjust as needed */
            overflow-y: auto;
            border: 1px solid var(--card-border); /* Match form-control border */
            border-radius: 5px; /* Match form-control border-radius */
            padding: 10px; /* Add some padding internally */
            background-color: var(--secondary-bg); /* Match form-control background */
            margin-bottom: 0px; /* To align with other form fields */
            color: var(--text-color); /* Ensure text color is set */
        }
        .filter-checkbox-group .form-check {
            margin-bottom: 8px; /* Spacing between checkboxes */
            display: flex; /* Make it a flex container for better alignment */
            align-items: flex-start; /* Vertically align checkbox and label */
        }
        .filter-checkbox-group .form-check:last-child {
            margin-bottom: 0; /* No margin after last checkbox */
        }
        .filter-checkbox-group .form-check-label {
            margin-left: 10px; /* Spacing between checkbox and label */
            cursor: pointer;
            line-height: 1.2; /* For better vertical alignment */
        }

        /* Buttons */
        .btn-primary {
            background: var(--gradient-primary);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: var(--gradient-secondary); /* Change gradient on hover */
            transform: translateY(-2px); /* Lift effect */
            box-shadow: 0 4px 10px rgba(229, 9, 20, 0.4); /* Stronger shadow */
            color: white;
        }
        .btn-danger {
            background: var(--gradient-primary); /* Reusing primary gradient for danger */
            border: none;
            transition: all 0.3s ease;
            color: white;
        }
        .btn-danger:hover {
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
        .btn-secondary {
            background-color: #6c757d; /* Default secondary color */
            border-color: #6c757d;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
            transform: translateY(-1px);
        }
        .btn-outline-info { /* Added for venue_schedules.php */
            color: var(--accent-orange);
            border-color: var(--accent-orange);
            background-color: transparent;
        }
        .btn-outline-info:hover {
            background-color: var(--accent-orange);
            color: var(--primary-bg);
        }
        .btn-info { /* Added for venue_schedules.php, based on dashboard orange-peach */
            background: linear-gradient(90deg, var(--accent-orange), var(--accent-peach)); /* Info as orange-peach */
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-info:hover {
            background: linear-gradient(90deg, var(--accent-peach), var(--accent-orange));
            transform: translateY(-1px);
        }
        .btn-warning { /* Re-used from dashboard.php but check specific colors */
             background-color: var(--accent-yellow);
             border-color: var(--accent-yellow);
             color: var(--primary-bg); /* Dark text on yellow */
             transition: all 0.3s ease;
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #e0a800;
            transform: translateY(-1px);
        }
        .btn-outline-light { /* Specific for menu toggle button */
            color: var(--text-color);
            border-color: var(--text-color);
        }
        .btn-outline-light:hover {
            background-color: var(--text-color);
            color: var(--primary-bg);
        }

        /* Dashboard Cards (from dashboard.php) */
        .card { /* Overrides file2 existing card */
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease; /* For overall styling */
        }
        .card-header { /* Overrides file2 existing card-header */
            background-color: transparent; /* No strong background for header */
            border-bottom: 1px solid var(--card-border);
            color: var(--accent-orange); /* Card header title color */
            border-top-left-radius: 8px; /* Maintain rounded corners */
            border-top-right-radius: 8px;
        }
        .card-title { /* Overrides file2 existing card-title */
            color: var(--text-color); /* Default text color for titles */
            font-size: 1.25em;
        }
        .card-subtitle { /* Overrides file2 existing card-subtitle */
            color: var(--light-text-color) !important; /* Muted subtitle text */
            font-size: 0.9em;
        }
        /* IMPROVEMENT FOR CARD-TEXT VISIBILITY */
        .card-text {
            color: var(--text-color); /* Ensure general card text is visible */
        }

        /* Stat Boxes - adapted from venue_schedules.php but using dashboard.php colors */
        .stat-box { 
            background-color: var(--secondary-bg); 
            color: var(--text-color); 
            padding: 20px; 
            border-radius: 8px; 
            text-align: center; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3); /* Similar to card shadow */
            height: 100%; /* For row alignment */
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-box h3 { 
            color: var(--light-text-color); 
            font-size: 1.1rem; /* Smaller font for h3 in stat box */
            font-weight: 600;
            margin-bottom: 10px;
        }
        .stat-box p { 
            font-size: 2.5rem; /* Larger number */
            font-weight: bold; 
            color: var(--accent-orange); 
            margin-bottom: 0;
        }
        /* Adjusted for modal stats */
        #scheduleDetailsModal .stat-box { 
            padding: 10px; 
            margin-bottom: 15px; 
            max-height: unset; /* Override potential fixed height */
        }
        #scheduleDetailsModal .stat-box h3 { font-size: 1rem; }
        #scheduleDetailsModal .stat-box p { font-size: 1.5rem; }

        /* Badges */
        .badge { /* Overrides file2 existing badge */
            font-size: 0.8em; 
            font-weight: normal; 
            padding: 0.3em 0.6em; /* Adjusted padding */
            border-radius: 3px; 
        }
        .badge.bg-success { background-color: #28a745 !important; color: white !important; }
        .badge.bg-danger { background-color: var(--accent-red) !important; color: white !important; }
        .badge.bg-info { background-color: var(--accent-peach) !important; color: var(--primary-bg) !important; } /* Peach for info */

        /* Filters section (from venue_schedules.php) */
        form.row.gx-3.gy-2 { 
            background-color: var(--secondary-bg) !important; 
            border-radius: 8px; 
            padding: 15px !important; 
            border: 1px solid var(--card-border);
        }
        /* form-check-input styling is inherited from dashboard.php, fine. */

        /* Icons */
        i.fas, i.far { color: var(--accent-orange); } /* Default icon color */
        i.fa-building { color: var(--accent-peach); } /* This is for the specific icon, not the text */

        /* Contextual text colors (IMPROVEMENT FOR VISIBILITY) */
        .text-danger { color: var(--accent-red) !important; }
        .text-warning { color: var(--accent-yellow) !important; }
        .text-muted { color: var(--light-text-color) !important; }
        .text-info { color: var(--accent-peach) !important; } /* Ensure info text is light */
        .text-primary { color: var(--accent-orange) !important; } /* Ensure primary text is light */


        /* Pagination (from dashboard.php) */
        .pagination .page-item .page-link { 
            background-color: var(--secondary-bg); 
            color: var(--text-color); 
            border: 1px solid var(--card-border); 
            border-radius: 5px; 
            margin: 0 2px; 
            transition: all 0.3s ease;
        }
        .pagination .page-item .page-link:hover { 
            background-color: var(--accent-red); 
            color: var(--text-color); 
            border-color: var(--accent-red);
        }
        .pagination .page-item.active .page-link { 
            background: var(--gradient-primary) !important; 
            border-color: var(--accent-red) !important; 
            color: white !important; 
        }
        .pagination .page-item.disabled .page-link { 
            background-color: var(--secondary-bg); 
            border-color: var(--card-border); 
            color: var(--light-text-color); 
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* Custom Switch for Status Toggler (from venue_schedules.php, adapted colors) */
        .custom-switch { 
            position: relative; display: inline-block; width: 40px; height: 24px; vertical-align: middle; 
            margin-top: 0; /* Adjust vertical-align, not margin-top */
        }
        .custom-switch input { opacity: 0; width: 0; height: 0; }
        .custom-switch .slider { 
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; 
            background-color: var(--accent-red); /* Off state */
            transition: .4s; border-radius: 24px; 
        }
        .custom-switch .slider:before { 
            position: absolute; content: ""; height: 16px; width: 16px; left: 4px; bottom: 4px; 
            background-color: white; transition: .4s; border-radius: 50%; 
        }
        .custom-switch input:checked + .slider { background-color: #28a745; } /* On state */
        .custom-switch input:checked + .slider:before { transform: translateX(16px); }

        /* Venue Card Specifics (from venue_schedules.php, preserving min-height) */
        .venue-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            min-height: 250px; /* Adjusted fixed height for consistency */
            display: flex;
            flex-direction: column;
            justify-content: space-between; /* Distribute content */
            align-items: flex-start; /* Align text to start */
             padding-bottom: 15px; /* Add some padding at the bottom for content to grow */
        }
        .venue-card:hover { transform: translateY(-5px); box-shadow: 0 8px 16px rgba(0, 0, 0, 0.6); }

        /* Modals (from dashboard.php, adapted if necessary) */
        .modal-content { 
            background-color: var(--secondary-bg); 
            color: var(--text-color); 
            border: 1px solid var(--card-border); 
        }
        .modal-header { border-bottom: 1px solid var(--card-border); }
        .modal-footer { border-top: 1px solid var(--card-border); }
        .btn-close { filter: invert(1); padding: 0.5rem; margin: 0.5rem;} /* Adjusted for visibility on dark theme */
        .modal-title { color: var(--accent-yellow) !important; }

        /* Specific for Schedule Details Modal (no scrollbar, tabbing - from venue_schedules.php) */
        #scheduleDetailsModal .modal-body {
            max-height: 70vh; /* Limit height to prevent modal overflowing screen */
            overflow-y: auto; /* Enable scrolling within body if content is tall */
            padding-right: var(--bs-gutter-x); /* Account for scrollbar width if present from Bootstrap variable */
        }
        #scheduleDetailsModal .nav-tabs .nav-link {
            color: var(--light-text-color);
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
        }
        #scheduleDetailsModal .nav-tabs .nav-link.active {
            color: var(--primary-bg); /* Dark text */
            background-color: var(--accent-orange); /* Orange active tab */
            border-color: var(--accent-orange);
        }
        #scheduleDetailsModal .nav-tabs .nav-link:hover {
            border-color: var(--accent-peach);
        }
        #scheduleDetailsModal .tab-content {
            border: 1px solid var(--card-border);
            border-top: none;
            padding: 15px;
            background-color: var(--secondary-bg);
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }
        #scheduleDetailsModal .schedule-list-item {
            background-color: var(--secondary-bg); /* Lighter dark */
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--card-border);
        }
        #scheduleDetailsModal .schedule-list-item .actions {
            white-space: nowrap; /* Prevent buttons from wrapping */
        }
        /* Make form-check-label visible for select-all */
        .form-check-label.visible-label {
            display: inline-block !important; /* Override visually-hidden */
            color: var(--text-color);
            margin-left: 8px; /* Space between checkbox and text */
        }

        /* Footer Styling (from dashboard.php) */
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

        /* Toast Notifications (from venue_schedules.php, adapted colors) */
        .toast-container { z-index: 1070; right: 0; top: 0; }
        .toast { /* Inherits from alert styles now */ }

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
                /* padding-top: 15px; */ /* Adjusted as main-content has padding */
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
             /* Adjust column widths for filters on small screens */
            .col-sm-3.col-md-2, .col-sm-6.col-md-2 {
                flex: 0 0 100%;
                max-width: 100%;
            }
            .d-flex.justify-content-start.align-items-center.mb-3 {
                flex-wrap: wrap; /* Allow wrapping for buttons in bulk actions */
            }
            .d-flex.justify-content-start.align-items-center.mb-3 > .btn-sm,
            .d-flex.justify-content-start.align-items-center.mb-3 > .form-check {
                margin-bottom: 10px; /* Add spacing between wrapped actions */
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
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3"></div>

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
              <a class="nav-link" href="../event_assignment/event_assignment.php" title="Event Schedules">
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
              <a class="nav-link" href="venue_schedules.php" title="Venue Schedules">
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

    <!-- New wrapper for Main Content and Footer -->
    <div class="content-wrapper" id="contentWrapper">
        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="container-fluid">
                <!-- Combined Menu (Mobile) - Re-aligned for new layout -->
                <div class="d-flex justify-content-between align-items-center mb-3 d-md-none">
                     <!-- Button for mobile only, covered by sidebar-toggle-btn now -->
                    <h1 class="h3 mb-0 text-white"><?php echo $pageTitle; ?></h1>
                </div>
                <h2 class="section-title"><?php echo $pageTitle; ?></h2>

                <!-- Stats Row -->
                <div class="row mb-4" id="statsRow">
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Total Schedules <br> (Selected Range)</h3>
                            <p id="totalSchedulesRange">0</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Active Schedules <br> (Selected Range)</h3>
                            <p id="activeSchedulesRange">0</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Upcoming Schedules <br> (Next 7 Days)</h3>
                            <p id="upcomingSchedules7Days">0</p>
                        </div>
                    </div>
                </div>

                <!-- Venue Filters and List -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0 d-inline">Venue List</h3>
                        <small class="ms-2 text-muted">(Displaying <span id="venuesCount">0</span> of <span id="totalVenuesCount">0</span> venues)</small>
                    </div>
                    <div class="card-body">
                        <!-- Venue Filter Form -->
                        <form id="venueFilterForm" class="row gx-3 gy-2 align-items-end mb-4">
                            <div class="col-sm-3 col-md-2 filter-group-spacing">
                                <label class="form-label" for="search">Search Venue</label>
                                <input type="text" class="form-control" id="search" name="search" placeholder="Name or Sub-Venue" value="<?php echo $search_term; ?>">
                            </div>
                            <div class="col-sm-3 col-md-2 filter-group-spacing">
                                <label class="form-label" for="filter_city">City</label>
                                <select class="form-select" id="filter_city" name="filter_city">
                                    <option value="">All Cities</option>
                                    <?php foreach ($cities_data as $city): ?>
                                        <option value="<?php echo htmlspecialchars($city['city_id']); ?>" <?php echo ((string)$filter_city === (string)$city['city_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($city['city_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-3 col-md-2 filter-group-spacing">
                                <label class="form-label" for="filter_status">Venue Status</label>
                                <select class="form-select" id="filter_status" name="filter_status">
                                    <option value="">All Statuses</option>
                                    <option value="yes" <?php echo ($filter_status == 'yes') ? 'selected' : ''; ?>>Active</option>
                                    <option value="no" <?php echo ($filter_status == 'no') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-sm-3 col-md-2 filter-group-spacing">
                                <label class="form-label" for="start_date">Schedule Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date_filter; ?>">
                            </div>
                            <div class="col-sm-3 col-md-2 filter-group-spacing">
                                <label class="form-label" for="end_date">Schedule End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date_filter; ?>">
                            </div>
                            <div class="col-sm-6 col-md-2 d-grid gap-2 filter-group-spacing">
                                <button type="submit" class="btn btn-info"><i class="fas fa-filter"></i> Apply Filters</button>
                                <button type="button" class="btn btn-secondary" id="clearVenueFilters"><i class="fas fa-times-circle"></i> Clear Filters</button>
                            </div>
                            <div class="col-12 mt-2">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-info btn-sm date-preset-btn" data-range="today">Today</button>
                                    <button type="button" class="btn btn-outline-info btn-sm date-preset-btn" data-range="tomorrow">Tomorrow</button>
                                    <button type="button" class="btn btn-outline-info btn-sm date-preset-btn" data-range="next7days">Next 7 Days</button>
                                    <button type="button" class="btn btn-outline-info btn-sm date-preset-btn" data-range="thismonth">This Month</button>
                                </div>
                            </div>
                        </form>

                        <hr style="border-top: 1px solid var(--card-border); margin: 1rem 0;">

                        <!-- Bulk Actions for Venues -->
                        <div class="d-flex flex-wrap align-items-center mb-3">
                            <div class="form-check me-3">
                                <input class="form-check-input bulk-checkbox" type="checkbox" id="selectAllVenuesCheckbox">
                                <label class="form-check-label visible-label" for="selectAllVenuesCheckbox">Select All</label>
                            </div>
                            <span class="me-2 text-warning">Bulk Actions (Venues):</span>
                            <button type="button" class="btn btn-sm btn-success me-2 mb-2 mb-md-0" id="bulkActivateVenuesBtn">
                                <i class="fas fa-toggle-on"></i> Activate Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-warning mb-2 mb-md-0" id="bulkDeactivateVenuesBtn">
                                <i class="fas fa-toggle-off"></i> Deactivate Selected
                            </button>
                        </div>

                        <!-- Venue Cards -->
                        <div class="row" id="venueCardsContainer">
                            <!-- Venue Cards will be loaded here by JavaScript -->
                            <div class="col-12 text-center p-4" id="loadingSpinner">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="text-white mt-2">Loading venues...</p>
                            </div>
                            <div class="col-12 text-center p-4 d-none" id="noVenuesFound">
                                <p>No venues found matching your criteria.</p>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <nav aria-label="Page navigation" class="mt-4">
                            <ul class="pagination justify-content-center" id="venuePagination">
                                <!-- Pagination will be loaded here by JavaScript -->
                            </ul>
                        </nav>

                    </div>
                </div>
            </div>
        </main>

        <!-- Footer (from dashboard.php) -->
        <footer class="footer">
            <div class="container-fluid py-3">
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

    <!-- Schedule Details Modal -->
    <div class="modal fade" id="scheduleDetailsModal" tabindex="-1" aria-labelledby="scheduleDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="scheduleDetailsModalLabel">Schedules for <span id="modalVenueName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="modalVenueId">

                    <!-- Stats Row for Schedules in Modal -->
                    <div class="row mb-3 mt-2" id="modalStatsRow">
                        <div class="col-md-6 mb-3">
                            <div class="stat-box">
                                <h3>Total Schedules <br> (Current View)</h3>
                                <p id="modalTotalSchedules">0</p>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="stat-box">
                                <h3>Active Schedules <br> (Current View)</h3>
                                <p id="modalActiveSchedules">0</p>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <!-- Filters for Schedules Tab -->
                        <div class="col-md-4">
                            <label for="scheduleStatusFilter" class="form-label">Show Status</label>
                            <select class="form-select mb-2" id="scheduleStatusFilter">
                                <option value="all">All</option>
                                <option value="yes">Active</option>
                                <option value="no">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="dailyDatePickerContainer">
                            <label for="modalScheduleDate" class="form-label">Select Date</label>
                            <input type="date" class="form-control mb-2" id="modalScheduleDate">
                        </div>
                        <div class="col-md-4 d-flex align-items-end justify-content-end gap-2">
                            <button type="button" class="btn btn-warning mt-2" id="createSingleSlotBtn"><i class="fas fa-plus"></i> Add Single Slot</button>
                            <button type="button" class="btn btn-success mt-2" id="bulkGenerateSlotsBtn"><i class="fas fa-splotch"></i> Bulk Generate</button>
                        </div>
                    </div>

                    <!-- Bulk Actions for Schedules -->
                    <div class="d-flex flex-wrap align-items-center mb-3">
                        <div class="form-check me-3">
                            <input class="form-check-input bulk-checkbox" type="checkbox" id="selectAllSchedulesCheckbox">
                            <label class="form-check-label visible-label" for="selectAllSchedulesCheckbox">Select All</label>
                        </div>
                        <span class="me-2 text-warning">Bulk Actions (Slots):</span>
                        <button type="button" class="btn btn-sm btn-danger me-2 mb-2 mb-md-0" id="bulkDeleteSchedulesBtn">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <button type="button" class="btn btn-sm btn-success me-2 mb-2 mb-md-0" id="bulkActivateSchedulesBtn">
                            <i class="fas fa-toggle-on"></i> Activate Selected
                        </button>
                        <button type="button" class="btn btn-sm btn-warning mb-2 mb-md-0" id="bulkDeactivateSchedulesBtn">
                            <i class="fas fa-toggle-off"></i> Deactivate Selected
                        </button>
                    </div>

                    <!-- Tabbing for Different Schedule Views -->
                    <ul class="nav nav-tabs" id="scheduleTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="daily-tab" data-bs-toggle="tab" data-bs-target="#daily-schedule" type="button" role="tab" aria-controls="daily-schedule" aria-selected="true" data-time-range="daily">Daily View</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="weekly-tab" data-bs-toggle="tab" data-bs-target="#weekly-schedule" type="button" role="tab" aria-controls="weekly-schedule" aria-selected="false" data-time-range="weekly">Upcoming Week</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="monthly-tab" data-bs-toggle="tab" data-bs-target="#monthly-schedule" type="button" role="tab" aria-controls="monthly-schedule" aria-selected="false" data-time-range="monthly">Upcoming Month</button>
                        </li>
                    </ul>
                    <div class="tab-content" id="myTabContent">
                        <div class="tab-pane fade show active" id="daily-schedule" role="tabpanel" aria-labelledby="daily-tab">
                            <h6 class="text-center mt-3">Daily Schedules (<span id="currentDailyDate"></span>)</h6>
                            <small class="d-block text-end mb-2 text-muted">Total: <span id="dailyTotalSchedules">0</span> | Active: <span id="dailyActiveSchedules">0</span></small>
                            <div id="dailyScheduleList" class="schedule-list">
                                <!-- Schedules loaded via AJAX -->
                            </div>
                        </div>
                        <div class="tab-pane fade" id="weekly-schedule" role="tabpanel" aria-labelledby="weekly-tab">
                            <h6 class="text-center mt-3">Upcoming Schedules (Next 7 Days)</h6>
                            <small class="d-block text-end mb-2 text-muted">Total: <span id="weeklyTotalSchedules">0</span> | Active: <span id="weeklyActiveSchedules">0</span></small>
                            <div id="weeklyScheduleList" class="schedule-list">
                                <!-- Schedules loaded via AJAX -->
                            </div>
                        </div>
                        <div class="tab-pane fade" id="monthly-schedule" role="tabpanel" aria-labelledby="monthly-tab">
                            <h6 class="text-center mt-3">Upcoming Schedules (Next 30 Days)</h6>
                            <small class="d-block text-end mb-2 text-muted">Total: <span id="monthlyTotalSchedules">0</span> | Active: <span id="monthlyActiveSchedules">0</span></small>
                            <div id="monthlyScheduleList" class="schedule-list">
                                <!-- Schedules loaded via AJAX -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create/Edit Schedule Slot Modal -->
    <div class="modal fade" id="slotModal" tabindex="-1" aria-labelledby="slotModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="slotModalLabel">Add/Edit Schedule Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="slotForm">
                        <input type="hidden" id="slotVenueId" name="venue_id">
                        <input type="hidden" id="slotScheduleId" name="venue_schedule_id">
                        <div class="mb-3">
                            <label for="slotDate" class="form-label">Date</label>
                            <input type="date" class="form-control" id="slotDate" name="slot_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="slotStartTime" class="form-label">Start Time</label>
                            <input type="time" class="form-control" id="slotStartTime" name="slot_start_time" required>
                        </div>
                        <div class="mb-3">
                            <label for="slotEndTime" class="form-label">End Time</label>
                            <input type="time" class="form-control" id="slotEndTime" name="slot_end_time" required>
                            <div class="text-danger mt-1" id="slotTimeFeedback" style="display:none;">
                                End time must be after start time.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="is_active_schedule" class="form-label">Status</label>
                            <select class="form-select" id="is_active_schedule" name="is_active_schedule" required>
                                <option value="yes">Active</option>
                                <option value="no">Inactive</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" id="saveSlotBtn">Save Slot</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Generate Slots Modal -->
    <div class="modal fade" id="bulkGenerateModal" tabindex="-1" aria-labelledby="bulkGenerateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkGenerateModalLabel">Bulk Generate Schedule Slots</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="bulkGenerateForm">
                        <input type="hidden" id="bulkVenueId" name="venue_id">
                        <div class="mb-3">
                            <label for="bulkSlotDate" class="form-label">Date</label>
                            <input type="date" class="form-control" id="bulkSlotDate" name="slot_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="bulkFirstStartTime" class="form-label">Day Starts At</label>
                            <input type="time" class="form-control" id="bulkFirstStartTime" name="first_start_time" required>
                        </div>
                        <div class="mb-3">
                            <label for="bulkLastEndTime" class="form-label">Day Ends At</label>
                            <input type="time" class="form-control" id="bulkLastEndTime" name="last_end_time" required>
                            <div class="text-danger mt-1" id="bulkTimeFeedback" style="display:none;">
                                End time must be after start time.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="bulkSlotDuration" class="form-label">Slot Duration (minutes)</label>
                            <input type="number" class="form-control" id="bulkSlotDuration" name="slot_duration_minutes" min="1" value="60" required>
                        </div>
                        <div class="mb-3">
                            <label for="bulkBreakDuration" class="form-label">Break Duration (minutes between slots)</label>
                            <input type="number" class="form-control" id="bulkBreakDuration" name="break_duration_minutes" min="0" value="0" required>
                        </div>
                        <div class="mb-3 alert alert-secondary" id="possibleSlotsInfo">
                            <i class="fas fa-info-circle"></i> Estimated slots: <span id="estimatedSlotsCount">0</span>
                        </div>
                        <button type="submit" class="btn btn-primary">Generate Slots</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS (via CDN - placed at end of body for performance) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JavaScript (Embedded, merged from dashboard.php and venue_schedules.php) -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const URL_HANDLER = 'schedule_handler.php'; // Path to your PHP handler

            // Sidebar elements (from dashboard.php)
            const sidebar = document.getElementById('sidebar');
            const contentWrapper = document.getElementById('contentWrapper');
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            const body = document.body; // Reference to the body element

            // Function to set sidebar state (open/closed)
            const setSidebarOpen = (isOpen) => {
                if (isOpen) {
                    sidebar.classList.add('is-open');
                    // Apply class to body to trigger content-wrapper margin adjustment
                    body.classList.add('sidebar-is-open');
                    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-times"></i>';
                } else {
                    sidebar.classList.remove('is-open');
                    // Remove class from body to revert content-wrapper margin adjustment
                    body.classList.remove('sidebar-is-open');
                    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
                    // When collapsing the main sidebar, also collapse any open submenus
                    document.querySelectorAll('.sidebar-nav .collapse.show').forEach(collapseElement => {
                        const bsCollapse = bootstrap.Collapse.getInstance(collapseElement) || new bootstrap.Collapse(collapseElement, { toggle: false });
                        bsCollapse.hide();
                    });
                }
            };

            // Sidebar interactivity for desktop (hover)
            if (sidebar && contentWrapper) {
                sidebar.addEventListener('mouseenter', () => {
                    // Only trigger hover on desktop (larger screens)
                    if (window.innerWidth > 768) {
                        setSidebarOpen(true);
                    }
                });
                sidebar.addEventListener('mouseleave', () => {
                    if (window.innerWidth > 768) {
                        setSidebarOpen(false);
                    }
                });
            }

            // Sidebar interactivity for mobile (click toggle)
            if (sidebarToggleBtn) {
                sidebarToggleBtn.addEventListener('click', function () {
                    // Toggle the state of the sidebar
                    setSidebarOpen(!sidebar.classList.contains('is-open'));
                });

                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function (event) {
                    // Only for mobile (<= 768px) and if sidebar is open
                    if (window.innerWidth <= 768 && sidebar.classList.contains('is-open')) {
                        // Check if the click occurred outside the sidebar and the toggle button itself
                        if (!sidebar.contains(event.target) && !sidebarToggleBtn.contains(event.target)) {
                            setSidebarOpen(false);
                        }
                    }
                });
            }
            // --- Active Link and Submenu Management (from dashboard.php) ---
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

            navLinks.forEach(link => {
                // Get the link's href and extract just the filename (e.g., "dashboard.php")
                const linkHref = link.getAttribute('href');
                if (!linkHref || linkHref.startsWith('#')) return; // Skip links that are just collapse toggles

                const currentFilename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
                const linkFilename = linkHref.substring(linkHref.lastIndexOf('/') + 1);

                // Check if the link's filename matches the current page's filename
                if (linkFilename === currentFilename) {
                    link.classList.add('active'); // Mark the specific item as active

                    // If this active link is inside a submenu, expand its parent
                    const parentCollapseDiv = link.closest('.collapse');
                    if (parentCollapseDiv) {
                        // Ensure the Bootstrap collapse instance is created
                        const bsCollapse = bootstrap.Collapse.getInstance(parentCollapseDiv) || new bootstrap.Collapse(parentCollapseDiv, { toggle: false });
                        bsCollapse.show(); // Show the collapse

                        // Find the parent toggle link and update its state
                        const parentToggleLink = document.querySelector(`a[data-bs-target="#${parentCollapseDiv.id}"]`);
                        if (parentToggleLink) {
                            parentToggleLink.classList.remove('collapsed'); // Remove collapsed class
                            parentToggleLink.setAttribute('aria-expanded', 'true'); // Set expanded state for accessibility
                        }
                    }
                    // If the current page is "dashboard.php", ensure its link is 'active'
                    // and no parent collapse is incorrectly triggered.
                    // This specific check isn't strictly needed if href matching handles it,
                    // but it's harmless if a dashboard link exists.
                    if (currentFilename === 'dashboard.php') {
                        document.querySelector('a[href="dashboard.php"]')?.classList.add('active');
                    }
                }
            });

            // --- Caret Icon Rotation on Collapse Events (from dashboard.php) ---
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


            // Toast Utility Function (adapted from venue_schedules.php and dashboard.php)
            function showToast(message, type) {
                const toastContainer = document.querySelector('.toast-container');
                if (!toastContainer) {
                    console.error('Toast container not found!');
                    return;
                }

                const toastElement = document.createElement('div');
                toastElement.classList.add('toast', 'align-items-center', 'border-0', 'text-white');
                
                let bsTypeClass;
                if (type === 'success') {
                    bsTypeClass = 'alert-success'; // Uses gradient from dashboard.php
                } else if (type === 'danger') {
                    bsTypeClass = 'alert-danger'; // Uses gradient from dashboard.php
                } else { // Default to info/primary theme for other types
                    bsTypeClass = 'alert-info'; // Uses gradient from dashboard.php
                }
                toastElement.classList.add(bsTypeClass);

                toastElement.setAttribute('role', 'alert');
                toastElement.setAttribute('aria-live', 'assertive');
                toastElement.setAttribute('aria-atomic', 'true');
                toastElement.setAttribute('data-bs-delay', '2500'); // Display for 2.5 seconds

                toastElement.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message}
                        </div>
                        <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                `;
                toastContainer.appendChild(toastElement);

                // Initialize and show the toast
                const toast = new bootstrap.Toast(toastElement);
                toast.show();

                // Clean up DOM after toast hides
                toastElement.addEventListener('hidden.bs.toast', () => {
                    if (toastElement.parentNode) toastElement.parentNode.removeChild(toastElement);
                });
            }

            // Venue filter form elements
            const venueFilterForm = document.getElementById('venueFilterForm');
            const searchInput = document.getElementById('search');
            const filterCitySelect = document.getElementById('filter_city');
            const filterStatusSelect = document.getElementById('filter_status');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const clearVenueFiltersBtn = document.getElementById('clearVenueFilters');
            const datePresetBtns = document.querySelectorAll('.date-preset-btn');

            // Venue list elements
            const venueCardsContainer = document.getElementById('venueCardsContainer');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const noVenuesFound = document.getElementById('noVenuesFound');
            const venuePagination = document.getElementById('venuePagination');
            const venuesCountSpan = document.getElementById('venuesCount');
            const totalVenuesCountSpan = document.getElementById('totalVenuesCount');

            // Stats row elements (main page)
            const totalSchedulesRange = document.getElementById('totalSchedulesRange');
            const activeSchedulesRange = document.getElementById('activeSchedulesRange');
            const upcomingSchedules7Days = document.getElementById('upcomingSchedules7Days');

            // Bulk venue action buttons
            const selectAllVenuesCheckbox = document.getElementById('selectAllVenuesCheckbox');
            const bulkActivateVenuesBtn = document.getElementById('bulkActivateVenuesBtn');
            const bulkDeactivateVenuesBtn = document.getElementById('bulkDeactivateVenuesBtn');

            // Schedule Details Modal elements
            const scheduleDetailsModal = new bootstrap.Modal(document.getElementById('scheduleDetailsModal'));
            const modalVenueName = document.getElementById('modalVenueName');
            const modalVenueId = document.getElementById('modalVenueId');
            const scheduleStatusFilter = document.getElementById('scheduleStatusFilter');
            const modalScheduleDate = document.getElementById('modalScheduleDate');
            const createSingleSlotBtn = document.getElementById('createSingleSlotBtn');
            const bulkGenerateSlotsBtn = document.getElementById('bulkGenerateSlotsBtn');

            // Modal Stats elements
            const modalTotalSchedules = document.getElementById('modalTotalSchedules');
            const modalActiveSchedules = document.getElementById('modalActiveSchedules');

            // Bulk schedule action buttons (+ select all for modal)
            const selectAllSchedulesCheckbox = document.getElementById('selectAllSchedulesCheckbox');
            const bulkDeleteSchedulesBtn = document.getElementById('bulkDeleteSchedulesBtn'); // This now performs hard delete
            const bulkActivateSchedulesBtn = document.getElementById('bulkActivateSchedulesBtn');
            const bulkDeactivateSchedulesBtn = document.getElementById('bulkDeactivateSchedulesBtn');

            // Schedule Tabbing content
            const scheduleTabs = document.getElementById('scheduleTabs');
            const dailyScheduleList = document.getElementById('dailyScheduleList');
            const weeklyScheduleList = document.getElementById('weeklyScheduleList');
            const monthlyScheduleList = document.getElementById('monthlyScheduleList');
            // Mini-stats for each tab (within the small text)
            const dailyTotalSchedules = document.getElementById('dailyTotalSchedules');
            const dailyActiveSchedules = document.getElementById('dailyActiveSchedules');
            const weeklyTotalSchedules = document.getElementById('weeklyTotalSchedules');
            const weeklyActiveSchedules = document.getElementById('weeklyActiveSchedules');
            const monthlyTotalSchedules = document.getElementById('monthlyTotalSchedules');
            const monthlyActiveSchedules = document.getElementById('monthlyActiveSchedules');
            const currentDailyDateSpan = document.getElementById('currentDailyDate');
            const dailyDatePickerContainer = document.getElementById('dailyDatePickerContainer');


            // Slot Add/Edit Modal elements
            const slotModal = new bootstrap.Modal(document.getElementById('slotModal'));
            const slotForm = document.getElementById('slotForm');
            const slotModalLabel = document.getElementById('slotModalLabel');
            const slotVenueIdInput = document.getElementById('slotVenueId');
            const slotScheduleIdInput = document.getElementById('slotScheduleId');
            const slotDateInput = document.getElementById('slotDate');
            const slotStartTimeInput = document.getElementById('slotStartTime');
            const slotEndTimeInput = document.getElementById('slotEndTime');
            const slotTimeFeedback = document.getElementById('slotTimeFeedback');
            const is_active_scheduleSelect = document.getElementById('is_active_schedule');
            const saveSlotBtn = document.getElementById('saveSlotBtn');

            // Bulk Generate Slot Modal elements
            const bulkGenerateModal = new bootstrap.Modal(document.getElementById('bulkGenerateModal'));
            const bulkGenerateForm = document.getElementById('bulkGenerateForm');
            const bulkVenueIdInput = document.getElementById('bulkVenueId');
            const bulkSlotDateInput = document.getElementById('bulkSlotDate');
            const bulkFirstStartTimeInput = document.getElementById('bulkFirstStartTime');
            const bulkLastEndTimeInput = document.getElementById('bulkLastEndTime');
            const bulkTimeFeedback = document.getElementById('bulkTimeFeedback');
            const bulkSlotDurationInput = document.getElementById('bulkSlotDuration');
            const bulkBreakDurationInput = document.getElementById('bulkBreakDuration');
            const estimatedSlotsCount = document.getElementById('estimatedSlotsCount');


            let currentPage = <?php echo $current_page; ?>;

            // Function to set default date ranges
            function setDatePreset(range) {
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');

                let sDate = '';
                let eDate = '';

                switch (range) {
                    case 'today':
                        sDate = `${year}-${month}-${day}`;
                        eDate = sDate;
                        break;
                    case 'tomorrow':
                        const tomorrow = new Date(today);
                        tomorrow.setDate(today.getDate() + 1);
                        sDate = tomorrow.toISOString().split('T')[0];
                        eDate = sDate;
                        break;
                    case 'next7days':
                        sDate = `${year}-${month}-${day}`;
                        const sevenDaysLater = new Date(today);
                        sevenDaysLater.setDate(today.getDate() + 6); // +6 days from today makes it a 7-day range
                        eDate = sevenDaysLater.toISOString().split('T')[0];
                        break;
                    case 'thismonth':
                        sDate = `${year}-${month}-01`;
                        const lastDayOfMonth = new Date(year, today.getMonth() + 1, 0);
                        eDate = lastDayOfMonth.toISOString().split('T')[0];
                        break;
                    default:
                        sDate = '';
                        eDate = '';
                        break;
                }

                startDateInput.value = sDate;
                endDateInput.value = eDate;
                fetchVenues(); // Re-fetch venues with new date range
            }

            // --- Main Venue Fetching Function ---
            function fetchVenues(page = currentPage) {
                loadingSpinner.classList.remove('d-none');
                venueCardsContainer.innerHTML = ''; // Clear existing cards
                noVenuesFound.classList.add('d-none');
                selectAllVenuesCheckbox.checked = false; // Reset select all on new fetch

                const params = new URLSearchParams({
                    action: 'get_venues_with_schedule_counts',
                    page: page,
                    search: searchInput.value,
                    city_id: filterCitySelect.value,
                    is_active: filterStatusSelect.value,
                    start_date: startDateInput.value,
                    end_date: endDateInput.value
                });

                fetch(`${URL_HANDLER}?${params.toString()}`) // Correct URL to handler
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        loadingSpinner.classList.add('d-none');
                        if (data.success) {
                            displayVenues(data.venues);
                            updateVenuePagination(data.total_pages_venues, data.current_page);
                            updateMainStats(data.stats); // Use updateMainStats for clarity
                            totalVenuesCountSpan.textContent = data.total_venues;
                            venuesCountSpan.textContent = data.venues.length;

                            if (data.venues.length === 0) {
                                noVenuesFound.classList.remove('d-none');
                            }
                        } else {
                            showToast(data.message || 'Failed to fetch venues.', 'danger');
                            noVenuesFound.classList.remove('d-none');
                            console.error('API Error:', data.message);
                        }
                    })
                    .catch(error => {
                        loadingSpinner.classList.add('d-none');
                        showToast('Error fetching venues: ' + error.message, 'danger');
                        console.error('Fetch error:', error);
                    });
            }

            function displayVenues(venues) {
                venueCardsContainer.innerHTML = '';
                if (venues.length === 0) {
                    noVenuesFound.classList.remove('d-none');
                    return;
                }
                venues.forEach(venue => {
                    const card = document.createElement('div');
                    card.className = 'col-md-6 col-lg-4 col-xl-3 mb-3';

                    const statusClass = venue.is_active === 'yes' ? 'bg-success' : 'bg-danger';
                    const statusText = venue.is_active === 'yes' ? 'Active' : 'Inactive';

                    card.innerHTML = `
                        <div class="card venue-card h-100 position-relative">
                            <div class="form-check position-absolute" style="top: 10px; right: 10px;">
                                <input class="form-check-input bulk-venue-checkbox" type="checkbox" value="${venue.venue_id}" id="venue_${venue.venue_id}">
                                <label class="form-check-label visually-hidden" for="venue_${venue.venue_id}">Select Venue</label>
                            </div>
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title">${escapeHTML(venue.venue_name)}</h5>
                                <h6 class="card-subtitle mb-2 text-muted">${escapeHTML(venue.sub_venue_name)}</h6>
                                <p class="card-text mb-1"><i class="fas fa-city text-info"></i> City: ${escapeHTML(venue.city_name)}</p>
                                <p class="card-text mb-1"><i class="fas fa-calendar-alt text-primary"></i> Schedules (Filtered Range): ${venue.schedules_in_selected_range_count}</p>
                                <div class="mt-auto pt-2 d-flex justify-content-between align-items-end">
                                    <div class="d-flex align-items-center">
                                        <label class="custom-switch me-2">
                                            <input type="checkbox" class="venue-status-toggle" data-venue-id="${venue.venue_id}" ${venue.is_active === 'yes' ? 'checked' : ''}>
                                            <span class="slider round"></span>
                                        </label>
                                        <span class="badge ${statusClass}" id="venue_status_${venue.venue_id}">${statusText}</span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-info view-schedules-btn" data-venue-id="${venue.venue_id}" data-venue-name="${escapeHTML(venue.venue_name)}">
                                        <i class="fas fa-calendar-check"></i> View Schedules
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    venueCardsContainer.appendChild(card);
                });
                addVenueEventListeners();
            }

            function updateMainStats(stats) {
                totalSchedulesRange.textContent = stats.total_schedules_in_range;
                activeSchedulesRange.textContent = stats.active_schedules_in_range;
                upcomingSchedules7Days.textContent = stats.upcoming_schedules_7_days;
            }

            // Function to update stats in the modal
            function updateModalStats(total, active) {
                modalTotalSchedules.textContent = total;
                modalActiveSchedules.textContent = active;
            }

            function updateVenuePagination(totalPages, currentPage) {
                venuePagination.innerHTML = '';
                if (totalPages <= 1) return;

                const queryParams = new URL(location.href).searchParams; 

                function createPageItem(page, text, isDisabled = false, isActive = false) {
                    const li = document.createElement('li');
                    li.className = `page-item ${isDisabled ? 'disabled' : ''} ${isActive ? 'active' : ''}`;
                    const a = document.createElement('a');
                    a.className = 'page-link';
                    
                    // Don't modify the queryParams directly here, create a new instance for each link
                    const linkQueryParams = new URLSearchParams(queryParams.toString());
                    linkQueryParams.set('page', page); 
                    a.href = `?${linkQueryParams.toString()}`; 
                    
                    a.textContent = text;
                    if (!isDisabled) {
                        a.addEventListener('click', (e) => {
                            e.preventDefault();
                            setNewPageAndFetch(page);
                        });
                    }
                    li.appendChild(a);
                    return li;
                }

                // Helper to update current_page and fetch
                function setNewPageAndFetch(newPage) {
                    currentPage = newPage;
                    fetchVenues(currentPage);
                    // Update URL without reloading entire page for better UX
                    const newUrl = new URL(window.location.href);
                    newUrl.searchParams.set('page', newPage);
                    window.history.pushState({ path: newUrl.href }, '', newUrl.href);
                }


                venuePagination.appendChild(createPageItem(currentPage - 1, 'Previous', currentPage <= 1));

                // Show a limited number of page links
                const pageDisplayLimit = 5;
                let startPage = Math.max(1, currentPage - Math.floor(pageDisplayLimit / 2));
                let endPage = Math.min(totalPages, startPage + pageDisplayLimit - 1);

                if (endPage - startPage + 1 < pageDisplayLimit) {
                    startPage = Math.max(1, endPage - pageDisplayLimit + 1);
                }

                if (startPage > 1) {
                    venuePagination.appendChild(createPageItem(1, '1'));
                    if (startPage > 2) {
                        const ellipsis = document.createElement('li');
                        ellipsis.className = 'page-item disabled';
                        ellipsis.innerHTML = '<span class="page-link">...</span>';
                        venuePagination.appendChild(ellipsis);
                    }
                }

                for (let i = startPage; i <= endPage; i++) {
                    venuePagination.appendChild(createPageItem(i, i, false, i === currentPage));
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) {
                        const ellipsis = document.createElement('li');
                        ellipsis.className = 'page-item disabled';
                        ellipsis.innerHTML = '<span class="page-link">...</span>';
                        venuePagination.appendChild(ellipsis);
                    }
                    venuePagination.appendChild(createPageItem(totalPages, totalPages));
                }

                venuePagination.appendChild(createPageItem(currentPage + 1, 'Next', currentPage >= totalPages));
            }


            function addVenueEventListeners() {
                // Event listener for individual venue status toggles
                document.querySelectorAll('.venue-status-toggle').forEach(toggle => {
                    toggle.addEventListener('change', function() {
                        const venueId = this.dataset.venueId;
                        const newStatus = this.checked ? 'yes' : 'no';
                        const currentToggle = this;
                        const originalStatus = this.checked ? 'no' : 'yes'; // For reverting on error
                        const statusBadge = document.getElementById(`venue_status_${venueId}`);

                        const formData = new FormData();
                        formData.append('action', 'bulk_update_venue_status'); // Reuse bulk action for single update
                        formData.append('venue_ids', JSON.stringify([venueId]));
                        formData.append('new_status', newStatus);

                        fetch(URL_HANDLER, { method: 'POST', body: formData })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showToast(data.message, 'success');
                                    if (statusBadge) {
                                        statusBadge.classList.toggle('bg-success', newStatus === 'yes');
                                        statusBadge.classList.toggle('bg-danger', newStatus === 'no');
                                        statusBadge.textContent = newStatus === 'yes' ? 'Active' : 'Inactive';
                                    }
                                } else {
                                    showToast(data.message || 'Could not update venue status.', 'danger');
                                    currentToggle.checked = (originalStatus === 'yes'); // Revert toggle on error
                                }
                            })
                            .catch(error => {
                                console.error('AJAX Error:', error);
                                showToast('An error occurred while updating venue status.', 'danger');
                                currentToggle.checked = (originalStatus === 'yes'); // Revert toggle on network error
                            });
                    });
                });

                // Event listener for "View Schedules" button
                document.querySelectorAll('.view-schedules-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const venueId = this.dataset.venueId;
                        const venueName = this.dataset.venueName;
                        modalVenueId.value = venueId;
                        modalVenueName.textContent = venueName;
                        // Default to daily view for today's date when modal opens
                        const today = new Date().toISOString().split('T')[0];
                        modalScheduleDate.value = today;
                        currentDailyDateSpan.textContent = today;
                        scheduleStatusFilter.value = 'all'; // Reset status filter
                        selectAllSchedulesCheckbox.checked = false; // Reset inner select all

                        // Activate an initial tab and fetch schedules
                        const dailyTab = document.getElementById('daily-tab');
                        const tabTrigger = new bootstrap.Tab(dailyTab);
                        tabTrigger.show(); // This triggers 'shown.bs.tab' which calls fetchSchedulesForModal()

                        // Ensure daily date picker is visible
                        dailyDatePickerContainer.classList.remove('d-none');

                        // Show the modal
                        scheduleDetailsModal.show();
                    });
                });
            }

            // --- Schedule Details Modal Functions ---

            // Function to fetch schedules for the active tab and filters
            function fetchSchedulesForModal() {
                const venueId = modalVenueId.value;
                if (!venueId) return;

                const activeTab = document.querySelector('#scheduleTabs .nav-link.active');
                const timeRangeType = activeTab.dataset.timeRange;
                const statusFilter = scheduleStatusFilter.value;

                let dateParam = '';
                if (timeRangeType === 'daily') {
                    dateParam = modalScheduleDate.value;
                    dailyDatePickerContainer.classList.remove('d-none');
                    currentDailyDateSpan.textContent = dateParam;
                } else {
                    dailyDatePickerContainer.classList.add('d-none');
                }

                const params = new URLSearchParams({
                    action: 'get_detailed_schedule_info',
                    venue_id: venueId,
                    time_range_type: timeRangeType,
                    status_filter: statusFilter,
                    date: dateParam // Only relevant for daily view
                });

                // Clear current tab list and show loading
                const activePaneId = activeTab.dataset.bsTarget;
                const activeScheduleListDiv = document.querySelector(`${activePaneId} .schedule-list`);
                activeScheduleListDiv.innerHTML = `
                    <div class="text-center p-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-white mt-2">Loading schedules...</p>
                    </div>
                `;
                // Reset select all checkbox for schedules before loading
                selectAllSchedulesCheckbox.checked = false;

                fetch(`${URL_HANDLER}?${params.toString()}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            displaySchedules(data.schedules, timeRangeType, data.stats);
                            updateModalStats(data.stats.total, data.stats.active); // Update modal's main stats
                        } else {
                            showToast(data.message || 'Failed to fetch schedules.', 'danger');
                            activeScheduleListDiv.innerHTML = `<p class="text-center text-danger mt-3">Error: ${data.message || 'Failed to load schedules.'}</p>`;
                            updateScheduleTabStats(timeRangeType, 0, 0); // Update mini-stats to 0
                            updateModalStats(0, 0); // Update modal's main stats to 0
                            console.error('API Error:', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        showToast('Error fetching schedules: ' + error.message, 'danger');
                        activeScheduleListDiv.innerHTML = `<p class="text-center text-danger mt-3">Error fetching schedules.</p>`;
                        updateScheduleTabStats(timeRangeType, 0, 0); // Update mini-stats to 0
                        updateModalStats(0, 0); // Update modal's main stats to 0
                    });
            }

            function displaySchedules(schedules, timeRangeType, stats) {
                let targetListDiv;
                let totalSpan, activeSpan;

                if (timeRangeType === 'daily') {
                    targetListDiv = dailyScheduleList;
                    totalSpan = dailyTotalSchedules;
                    activeSpan = dailyActiveSchedules;
                } else if (timeRangeType === 'weekly') {
                    targetListDiv = weeklyScheduleList;
                    totalSpan = weeklyTotalSchedules;
                    activeSpan = weeklyActiveSchedules;
                } else if (timeRangeType === 'monthly') {
                    targetListDiv = monthlyScheduleList;
                    totalSpan = monthlyTotalSchedules;
                    activeSpan = monthlyActiveSchedules;
                } else {
                    return; // Should not happen
                }

                targetListDiv.innerHTML = '';
                if (schedules.length === 0) {
                    targetListDiv.innerHTML = '<p class="text-center mt-3">No schedules found for this view.</p>';
                } else {
                    schedules.forEach(schedule => {
                        const start = new Date(schedule.slot_starts_at);
                        const end = new Date(schedule.slot_ends_at);
                        const displayDate = start.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                        const startTime = start.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
                        const endTime = end.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

                        const statusClass = schedule.is_active === 'yes' ? 'bg-success' : 'bg-danger';
                        const statusText = schedule.is_active === 'yes' ? 'Active' : 'Inactive';

                        const scheduleItem = document.createElement('div');
                        scheduleItem.className = 'schedule-list-item';
                        scheduleItem.innerHTML = `
                            <div>
                                <h6 class="mb-0 text-white">${displayDate} - ${startTime} to ${endTime}</h6>
                                <span class="badge ${statusClass}" id="schedule_status_${schedule.venue_schedule_id}">${statusText}</span>
                            </div>
                            <div class="actions">
                                <div class="form-check form-check-inline mx-2">
                                    <input class="form-check-input bulk-schedule-checkbox" type="checkbox" value="${schedule.venue_schedule_id}" id="schedule_bulk_${schedule.venue_schedule_id}">
                                    <label class="form-check-label visually-hidden" for="schedule_bulk_${schedule.venue_schedule_id}">Select</label>
                                </div>
                                <label class="custom-switch me-2">
                                    <input type="checkbox" class="schedule-status-toggle" data-schedule-id="${schedule.venue_schedule_id}" ${schedule.is_active === 'yes' ? 'checked' : ''}>
                                    <span class="slider round"></span>
                                </label>
                                <button type="button" class="btn btn-sm btn-secondary me-1 edit-schedule-btn" data-schedule-id="${schedule.venue_schedule_id}"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-sm btn-danger delete-schedule-btn" data-schedule-id="${schedule.venue_schedule_id}"><i class="fas fa-trash"></i></button>
                            </div>
                        `;
                        targetListDiv.appendChild(scheduleItem);
                    });
                }
                updateScheduleTabStats(timeRangeType, stats.total, stats.active);
                addScheduleEventListeners(); // Re-attach event listeners for newly added elements
            }

            function updateScheduleTabStats(timeRangeType, total, active) {
                let totalSpan, activeSpan;
                if (timeRangeType === 'daily') {
                    totalSpan = dailyTotalSchedules;
                    activeSpan = dailyActiveSchedules;
                } else if (timeRangeType === 'weekly') {
                    totalSpan = weeklyTotalSchedules;
                    activeSpan = weeklyActiveSchedules;
                } else if (timeRangeType === 'monthly') {
                    totalSpan = monthlyTotalSchedules;
                    activeSpan = monthlyActiveSchedules;
                } else {
                    return;
                }
                totalSpan.textContent = total;
                activeSpan.textContent = active;
            }

            function addScheduleEventListeners() {
                // Event listener FOR INDIVIDUAL SCHEDULE STATUS TOGGLES
                document.querySelectorAll('.schedule-status-toggle').forEach(toggle => {
                    toggle.addEventListener('change', function() {
                        const scheduleId = this.dataset.scheduleId;
                        const newStatus = this.checked ? 'yes' : 'no';
                        const currentToggle = this;
                        const originalStatus = this.checked ? 'no' : 'yes';
                        const statusBadge = document.getElementById(`schedule_status_${scheduleId}`);

                        const formData = new FormData();
                        formData.append('action', 'bulk_update_schedule_status'); // Use bulk update action
                        formData.append('schedule_ids', JSON.stringify([scheduleId]));
                        formData.append('new_status', newStatus);

                        fetch(URL_HANDLER, { method: 'POST', body: formData })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showToast(data.message, 'success');
                                    if (statusBadge) {
                                        statusBadge.classList.toggle('bg-success', newStatus === 'yes');
                                        statusBadge.classList.toggle('bg-danger', newStatus === 'no');
                                        statusBadge.textContent = newStatus === 'yes' ? 'Active' : 'Inactive';
                                    }
                                    fetchSchedulesForModal(); // Refresh schedules in current tab to update counts
                                } else {
                                    showToast(data.message || 'Could not update schedule status.', 'danger');
                                    currentToggle.checked = (originalStatus === 'yes');
                                }
                            })
                            .catch(error => {
                                console.error('AJAX Error:', error);
                                showToast('An error occurred while updating schedule status.', 'danger');
                                currentToggle.checked = (originalStatus === 'yes');
                            });
                    });
                });

                // Event listener FOR SINGLE SCHEDULE DELETE BUTTONS (now hard delete if confirmed)
                document.querySelectorAll('.delete-schedule-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const scheduleId = this.dataset.scheduleId;
                        if (confirm('Are you sure you want to PERMANENTLY DELETE this schedule slot? This action cannot be undone.')) {
                            const formData = new FormData();
                            formData.append('action', 'delete_schedules_hard'); // New action for hard delete
                            formData.append('schedule_ids', JSON.stringify([scheduleId]));

                            fetch(URL_HANDLER, { method: 'POST', body: formData })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        showToast(data.message, 'success');
                                        fetchSchedulesForModal(); // Refresh schedules
                                    } else {
                                        showToast(data.message || 'Failed to delete schedule.', 'danger');
                                    }
                                })
                                .catch(error => {
                                    console.error('AJAX Error:', error);
                                    showToast('An error occurred while deleting schedule.', 'danger');
                                });
                        }
                    });
                });

                // Event listener FOR EDIT SCHEDULE BUTTONS
                document.querySelectorAll('.edit-schedule-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const scheduleId = this.dataset.scheduleId;
                        slotModalLabel.textContent = 'Edit Schedule Slot';
                        slotVenueIdInput.value = modalVenueId.value;
                        slotScheduleIdInput.value = scheduleId;
                        saveSlotBtn.textContent = 'Update Slot';
                        slotForm.dataset.action = 'update_schedule';

                        // Fetch schedule details for editing
                        const params = new URLSearchParams({
                            action: 'get_detailed_schedule_info',
                            venue_id: modalVenueId.value,
                            schedule_id: scheduleId
                        });

                        fetch(`${URL_HANDLER}?${params.toString()}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.success && data.schedule) {
                                    slotDateInput.value = data.schedule.slot_date;
                                    slotStartTimeInput.value = data.schedule.slot_start_time;
                                    slotEndTimeInput.value = data.schedule.slot_end_time;
                                    is_active_scheduleSelect.value = data.schedule.is_active;
                                    slotTimeFeedback.style.display = 'none'; // Clear previous validation
                                    slotEndTimeInput.classList.remove('is-invalid');
                                    slotModal.show();
                                } else {
                                    showToast(data.message || 'Failed to load schedule details.', 'danger');
                                }
                            })
                            .catch(error => {
                                console.error('Fetch error:', error);
                                showToast('Error fetching schedule details: ' + error.message, 'danger');
                            });
                    });
                });
            }

            // Helper for HTML escaping
            function escapeHTML(str) {
                if (typeof str !== 'string') return ''; // Ensure it's a string
                const div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
            }

            // --- Event Listeners ---

            // Venue filter form submission
            venueFilterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                currentPage = 1; // Reset to first page on new filter
                fetchVenues();
            });

            // Clear venue filters
            clearVenueFiltersBtn.addEventListener('click', function() {
                searchInput.value = '';
                filterCitySelect.value = '';
                filterStatusSelect.value = '';
                startDateInput.value = '';
                endDateInput.value = '';
                currentPage = 1;
                fetchVenues();
            });

            // Date preset buttons
            datePresetBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    setDatePreset(this.dataset.range);
                });
            });

            // Select All Venues Checkbox
            selectAllVenuesCheckbox.addEventListener('change', function() {
                document.querySelectorAll('.bulk-venue-checkbox').forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });

            // Bulk actions for Venues
            bulkActivateVenuesBtn.addEventListener('click', function() {
                const selectedIds = Array.from(document.querySelectorAll('.bulk-venue-checkbox:checked')).map(cb => cb.value);
                if (selectedIds.length === 0) {
                    showToast('Please select at least one venue.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to activate ${selectedIds.length} selected venues?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_update_venue_status');
                    formData.append('venue_ids', JSON.stringify(selectedIds));
                    formData.append('new_status', 'yes');
                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchVenues(); // Refresh list on success
                        })
                        .catch(error => {
                            console.error('Bulk activate venues error:', error);
                            showToast('An error occurred during bulk activation.', 'danger');
                        });
                }
            });

            bulkDeactivateVenuesBtn.addEventListener('click', function() {
                const selectedIds = Array.from(document.querySelectorAll('.bulk-venue-checkbox:checked')).map(cb => cb.value);
                if (selectedIds.length === 0) {
                    showToast('Please select at least one venue.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to deactivate ${selectedIds.length} selected venues?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_update_venue_status');
                    formData.append('venue_ids', JSON.stringify(selectedIds));
                    formData.append('new_status', 'no');
                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchVenues();
                        })
                        .catch(error => {
                            console.error('Bulk deactivate venues error:', error);
                            showToast('An error occurred during bulk deactivation.', 'danger');
                        });
                }
            });

            // Schedule Details Modal Events
            scheduleDetailsModal._element.addEventListener('shown.bs.modal', fetchSchedulesForModal);

            // Tab changes within schedule modal
            scheduleTabs.addEventListener('shown.bs.tab', function(event) {
                fetchSchedulesForModal();
            });

            // Date change in daily view in modal
            modalScheduleDate.addEventListener('change', fetchSchedulesForModal);
            // Status filter change in modal
            scheduleStatusFilter.addEventListener('change', fetchSchedulesForModal);

            // Add Single Slot button
            createSingleSlotBtn.addEventListener('click', function() {
                // Pre-fill date with current daily view date, or today
                const defaultDate = modalScheduleDate.value || new Date().toISOString().split('T')[0];
                slotModalLabel.textContent = 'Add New Schedule Slot';
                slotVenueIdInput.value = modalVenueId.value;
                slotScheduleIdInput.value = ''; // Clear for new slot
                slotDateInput.value = defaultDate;
                slotStartTimeInput.value = '09:00'; // Default times
                slotEndTimeInput.value = '10:00';
                is_active_scheduleSelect.value = 'yes';
                saveSlotBtn.textContent = 'Save Slot';
                slotForm.dataset.action = 'create_single_schedule';
                slotTimeFeedback.style.display = 'none';
                slotEndTimeInput.classList.remove('is-invalid');
                slotModal.show();
            });

            // Bulk Generate Slots button
            bulkGenerateSlotsBtn.addEventListener('click', function() {
                const defaultDate = modalScheduleDate.value || new Date().toISOString().split('T')[0];
                bulkVenueIdInput.value = modalVenueId.value;
                bulkSlotDateInput.value = defaultDate;
                bulkFirstStartTimeInput.value = '09:00'; // Default times
                bulkLastEndTimeInput.value = '17:00';
                bulkSlotDurationInput.value = 60; // Default 60 min
                bulkBreakDurationInput.value = 0; // Default 0 min break
                bulkTimeFeedback.style.display = 'none';
                bulkLastEndTimeInput.classList.remove('is-invalid');
                calculatePossibleSlots(); // Calculate initial slots
                bulkGenerateModal.show();
            });

            // Live calculation for bulk generate slots
            [bulkFirstStartTimeInput, bulkLastEndTimeInput, bulkSlotDurationInput, bulkBreakDurationInput].forEach(input => {
                input.addEventListener('input', calculatePossibleSlots);
            });

            function calculatePossibleSlots() {
                const dayStartsStr = bulkFirstStartTimeInput.value;
                const dayEndsStr = bulkLastEndTimeInput.value;
                const slotDuration = parseInt(bulkSlotDurationInput.value);
                const breakDuration = parseInt(bulkBreakDurationInput.value);

                if (!dayStartsStr || !dayEndsStr || isNaN(slotDuration) || isNaN(breakDuration) || slotDuration <= 0 || breakDuration < 0) {
                    estimatedSlotsCount.textContent = '0';
                    return;
                }

                // Temporary parse for calculation, full validation on submit
                const parseTime = (timeStr) => {
                    const parts = timeStr.split(':');
                    return parseInt(parts[0]) * 60 + parseInt(parts[1]);
                };

                const dayStartsMinutes = parseTime(dayStartsStr);
                const dayEndsMinutes = parseTime(dayEndsStr);


                if (dayStartsMinutes >= dayEndsMinutes) {
                    estimatedSlotsCount.textContent = '0';
                    return;
                } else {
                    bulkLastEndTimeInput.classList.remove('is-invalid');
                    bulkTimeFeedback.style.display = 'none';
                }

                let currentStartMinutes = dayStartsMinutes;
                let count = 0;

                while (true) {
                    let currentEndMinutes = currentStartMinutes + slotDuration;
                    if (currentEndMinutes > dayEndsMinutes) {
                        break; // Slot goes beyond end time
                    }
                    count++;
                    currentStartMinutes = currentEndMinutes + breakDuration;
                }
                estimatedSlotsCount.textContent = count;
            }


            // Slot Form (Add/Edit) Submission
            slotForm.addEventListener('submit', function(e) {
                e.preventDefault();
                // Frontend validation
                const startTime = slotStartTimeInput.value;
                const endTime = slotEndTimeInput.value;

                if (startTime >= endTime) {
                    slotEndTimeInput.classList.add('is-invalid');
                    slotTimeFeedback.style.display = 'block';
                    return;
                } else {
                    slotEndTimeInput.classList.remove('is-invalid');
                    slotTimeFeedback.style.display = 'none';
                }

                const formData = new FormData(slotForm);
                formData.append('action', slotForm.dataset.action);

                fetch(URL_HANDLER, { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast(data.message, 'success');
                            slotModal.hide();
                            fetchSchedulesForModal(); // Refresh schedules in the modal
                        } else {
                            showToast(data.message || 'Operation failed.', 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Form submission error:', error);
                        showToast('An error occurred during form submission.', 'danger');
                    });
            });

            // Bulk Generate Form Submission
            bulkGenerateForm.addEventListener('submit', function(e) {
                e.preventDefault();
                // Frontend validation for bulk form
                const firstStartTime = bulkFirstStartTimeInput.value;
                const lastEndTime = bulkLastEndTimeInput.value;

                if (firstStartTime >= lastEndTime) {
                    bulkLastEndTimeInput.classList.add('is-invalid');
                    bulkTimeFeedback.style.display = 'block';
                    return;
                } else {
                    bulkLastEndTimeInput.classList.remove('is-invalid');
                    bulkTimeFeedback.style.display = 'none';
                }

                const formData = new FormData(bulkGenerateForm);
                formData.append('action', 'create_bulk_schedules');

                fetch(URL_HANDLER, { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast(data.message, 'success');
                            bulkGenerateModal.hide();
                            fetchSchedulesForModal(); // Refresh schedules in the modal
                        } else {
                            showToast(data.message || 'Bulk generation failed.', 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Bulk generation error:', error);
                        showToast('An error occurred during bulk generation.', 'danger');
                    });
            });

            // Select All Schedules Checkbox
            selectAllSchedulesCheckbox.addEventListener('change', function() {
                // Determine which tab is active to select checkboxes in that tab only
                const activeTab = document.querySelector('#scheduleTabs .nav-link.active');
                const activePaneId = activeTab.dataset.bs.target; // Corrected to data-bs.target for Bootstrap 5
                const checkboxesInActiveTab = document.querySelectorAll(`${activePaneId} .bulk-schedule-checkbox`);

                checkboxesInActiveTab.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });


            // Bulk actions for schedules within the modal
            function getSelectedScheduleIds() {
                // Get selected IDs from the currently active schedule tab
                const activeTab = document.querySelector('#scheduleTabs .nav-link.active');
                const activePaneId = activeTab.dataset.bs.target; // Corrected to data-bs.target for Bootstrap 5
                return Array.from(document.querySelectorAll(`${activePaneId} .bulk-schedule-checkbox:checked`)).map(cb => cb.value);
            }

            bulkDeleteSchedulesBtn.addEventListener('click', function() {
                const selectedIds = getSelectedScheduleIds();
                if (selectedIds.length === 0) {
                    showToast('Please select at least one schedule slot.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to PERMANENTLY DELETE ${selectedIds.length} selected schedule slot(s)? This action cannot be undone.`)) {
                    // This now calls the hard delete action
                    const formData = new FormData();
                    formData.append('action', 'delete_schedules_hard'); // New action for hard delete
                    formData.append('schedule_ids', JSON.stringify(selectedIds));

                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchSchedulesForModal(); // Refresh schedules
                        })
                        .catch(error => {
                            console.error('Bulk delete schedules error:', error);
                            showToast('An error occurred during bulk deletion.', 'danger');
                        });
                }
            });

            bulkActivateSchedulesBtn.addEventListener('click', function() {
                const selectedIds = getSelectedScheduleIds();
                if (selectedIds.length === 0) {
                    showToast('Please select at least one schedule slot.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to activate ${selectedIds.length} selected schedule slots?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_update_schedule_status');
                    formData.append('schedule_ids', JSON.stringify(selectedIds));
                    formData.append('new_status', 'yes');
                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchSchedulesForModal();
                        })
                        .catch(error => {
                            console.error('Bulk activate schedules error:', error);
                            showToast('An error occurred during bulk activation.', 'danger');
                        });
                }
            });

            bulkDeactivateSchedulesBtn.addEventListener('click', function() {
                const selectedIds = getSelectedScheduleIds();
                if (selectedIds.length === 0) {
                    showToast('Please select at least one schedule slot.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to deactivate ${selectedIds.length} selected schedule slots?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_update_schedule_status');
                    formData.append('schedule_ids', JSON.stringify(selectedIds));
                    formData.append('new_status', 'no');
                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchSchedulesForModal();
                        })
                        .catch(error => {
                            console.error('Bulk deactivate schedules error:', error);
                            showToast('An error occurred during bulk deactivation.', 'danger');
                        });
                }
            });


            // Initial fetch on page load
            fetchVenues();
        });
    </script>
</body>
</html>