<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

// Adjust path to links.php based on location (venues/index.php is nested)
require_once '../links.php';

// Database configuration (from dashboard.php)
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Redirect to login if not authenticated (except for auth pages)
$auth_pages = [$login_page, $register_page, $forgot_pass]; // These vars come from links.php

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

$adminUserId = isset($_SESSION['user_id']); // This might not be directly used in this specific page but is good to have for consistency
$adminUsername = $isLoggedIn ? $_SESSION['username'] : ''; // Corresponding username

// Connect to database (PDO from dashboard.php)
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'"); // Set charset to UTF-8
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Clear message after display (from dashboard.php, adapted for general use)
if (isset($_SESSION['message'])) {
    // Message will be displayed within the HTML below using Bootstrap alerts
}

// Original PHP from venues/index.php, CONVERTED to PDO
$pageTitle = "Manage Venues";

// --- Fetch Cities for dropdowns (Converted to PDO) ---
$cities = [];
$city_sql = "SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name ASC";
$stmt_cities = $pdo->prepare($city_sql);
$stmt_cities->execute();
$cities = $stmt_cities->fetchAll(PDO::FETCH_ASSOC);

// --- Initialize variables for edit form and filters ---
$edit_mode = false;
$venue_to_edit = [
    'venue_id' => '', 'venue_name' => '', 'sub_venue_id' => '', 'sub_venue_name' => '',
    'sub_venue_type' => '', 'capacity' => '', 'address' => '', 'city_id' => '',
    'location_link' => '', 'is_active' => 'yes'
];
$filter_city = $_GET['filter_city'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$search_term = trim($_GET['search'] ?? '');


// --- Handle Edit Request (Converted to PDO) ---
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $sql = "SELECT * FROM venues WHERE venue_id = ?";
    $stmt_edit = $pdo->prepare($sql);
    $stmt_edit->execute([$edit_id]);
    if ($venue = $stmt_edit->fetch(PDO::FETCH_ASSOC)) {
        $edit_mode = true;
        $venue_to_edit = $venue;
    } else {
        $_SESSION['message'] = "Venue not found.";
        $_SESSION['message_type'] = "danger";
    }
}

// --- Fetch Venue Stats (Converted to PDO) ---
$stmt_total = $pdo->prepare("SELECT COUNT(*) as total FROM venues");
$stmt_total->execute();
$total_venues = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt_active = $pdo->prepare("SELECT COUNT(*) as total FROM venues WHERE is_active = 'yes'");
$stmt_active->execute();
$active_venues = $stmt_active->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt_inactive = $pdo->prepare("SELECT COUNT(*) as total FROM venues WHERE is_active = 'no'");
$stmt_inactive->execute();
$inactive_venues = $stmt_inactive->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;


// --- Pagination and Filtering Logic (Converted to PDO) ---
// Define RECORDS_PER_PAGE as it was in config.php.
define('RECORDS_PER_PAGE', 10); 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = RECORDS_PER_PAGE;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$params = [];

if (!empty($filter_city)) {
    $where_clauses[] = "v.city_id = ?";
    $params[] = $filter_city;
}
if (!empty($filter_status)) {
    $where_clauses[] = "v.is_active = ?";
    $params[] = $filter_status;
}
if (!empty($search_term)) {
    $where_clauses[] = "(v.venue_name LIKE ? OR v.sub_venue_name LIKE ? OR v.address LIKE ?)";
    $searchTermLike = "%" . $search_term . "%";
    $params[] = $searchTermLike;
    $params[] = $searchTermLike;
    $params[] = $searchTermLike;
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

// Get total records for pagination after filtering
$count_sql = "SELECT COUNT(*) as total_count FROM venues v" . $where_sql;
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_records = $stmt_count->fetch(PDO::FETCH_ASSOC)['total_count'] ?? 0;
$total_pages = ceil($total_records / $records_per_page);

// Fetch venues for the current page with filters (FIX APPLIED HERE)
$venues_sql = "SELECT v.*, c.city_name
                FROM venues v
                LEFT JOIN cities c ON v.city_id = c.city_id
                $where_sql
                ORDER BY v.venue_name, v.sub_venue_name
                LIMIT " . (int)$records_per_page . " OFFSET " . (int)$offset; // LIMIT and OFFSET handled directly

$stmt_venues = $pdo->prepare($venues_sql);
$stmt_venues->execute($params); // Only pass parameters for the WHERE clause
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Catchify</title>
    <!-- Bootstrap CSS and FontAwesome from dashboard.php -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

            /* Responsive Font Sizes (from dashboard.php) */
            --section-title-font: 2rem;
            --section-subtitle-font: 1.5rem; /* Not directly used but good for consistency */
            --metric-card-display-4-font: 2.5rem; /* Not directly used but affects stat-box */
            --metric-card-h5-font: 1.1rem; /* Not directly used but affects stat-box */
        }

        /* WebKit Scrollbar (Chrome, Safari, Edge) (from dashboard.php) */
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

        /* Firefox Scrollbar (from dashboard.php) */
        * {
            scrollbar-width: thin;
            scrollbar-color: var(--accent-red) var(--secondary-bg);
        }

        /* For scrollable filter groups (from dashboard.php, not used in this specific content but kept for common theme) */
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

        /* Sub-menu styling (from dashboard.php) */
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

        /* When sidebar expands (has .is-open class), push content wrapper (from dashboard.php) */
        body.sidebar-is-open .content-wrapper { /* Class added to body by JS */
            margin-left: var(--sidebar-width-expanded);
        }

        /* Main Content Area (from dashboard.php) */
        .main-content {
            flex-grow: 1; /* Take up remaining space AFTER padding */
            padding: 20px;
        }

        /* General Styles (from dashboard.php) */
        .container-fluid { /* Changed from .container to .container-fluid */
            max-width: 1200px;
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
        .section-subtitle { /* from dashboard.php, not directly used in index.php but great to keep for common h3 style */
            font-size: var(--section-subtitle-font);
            margin-top: 30px;
            margin-bottom: 15px;
            color: var(--accent-peach);
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 5px;
        }

        /* Alert styles (from dashboard.php) */
        .alert {
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            color: white; /* Ensure text is visible on colored alerts */
        }
        .alert-success { background: linear-gradient(90deg, #28a745, #218838); border: none; }
        .alert-danger { background: var(--gradient-primary); border: none; }
        .alert-warning { background: linear-gradient(90deg, var(--accent-yellow), #e0a800); border: none; }
        .alert-info { background: linear-gradient(90deg, var(--accent-orange), var(--accent-peach)); border: none; }

        /* Form elements (from dashboard.php) */
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
        .form-check-label {
            color: var(--text-color);
        }
        /* Custom styling for form-check-input (general checkbox) - from dashboard.php */
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

        /* Styles for filter checkbox groups (from dashboard.php, not directly used in index.php but kept for common theme) */
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

        /* Buttons (from dashboard.php) */
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
            background: var(--gradient-primary);
            border: none;
            transition: all 0.3s ease;
            color: white;
        }
        .btn-danger:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-info { /* Original button for filters, now adapted to theme */
            background: linear-gradient(135deg, #17a2b8, #138496); /* default Bootstrap info-like gradient */
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-info:hover {
            background: linear-gradient(135deg, #138496, #17a2b8); 
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(23, 162, 184, 0.4);
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
        .btn-outline-secondary { /* Style from dashboard.php, not used here but kept */
            color: var(--light-text-color);
            border-color: var(--card-border);
            background-color: transparent;
        }
        .btn-outline-secondary:hover {
            color: var(--text-color);
            background-color: rgba(255, 255, 255, 0.08); /* Subtle hover */
            border-color: var(--light-text-color);
        }

        /* General card style to unify with dashboard vibe */
        .card { 
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .card-header {
            background-color: transparent; /* match general dashboard vibe */
            border-bottom: 1px solid var(--card-border);
            color: var(--accent-orange); /* To match theme */
            padding-bottom: 15px; /* increase padding */
        }
        .card-header h3 {
            color: var(--accent-orange); /* Ensure card header text color matches */
        }
        .card-body {
            color: var(--text-color);
        }
        .card-title {
            color: var(--accent-peach);
        }

        /* Stats Box specific styling (adapted from dashboard.php's metric-card) */
        .stat-box {
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-align: center;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .stat-box h3 {
            color: var(--light-text-color);
            font-size: var(--metric-card-h5-font); /* Match dashboard h5 */
            margin-bottom: 10px;
            font-weight: 600;
        }
        .stat-box p {
            color: var(--accent-orange); /* Consistent accent color */
            font-size: var(--metric-card-display-4-font); /* Match dashboard display-4 */
            font-weight: bold;
        }

        /* Venue Card specific styling */
        .venue-card {
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .venue-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .venue-card .card-subtitle {
            color: var(--light-text-color) !important; /* Fixing original missing "text-muted" or hex */
        }
        .venue-card .card-text {
            color: var(--light-text-color);
        }
        .venue-card .card-text i {
            margin-right: 5px;
        }
        /* Fixing direct color assignments to match theme */
        .venue-card .card-text .fa-theater-masks {
            color: var(--accent-peach) !important; /* Replaced #peach */
        }
        .venue-card .card-text .fa-users {
            color: var(--accent-yellow) !important; /* Replaced #FFC107 */
        }
        .venue-card .card-text .fa-map-marker-alt {
            color: var(--accent-red) !important; /* Replaced text-danger */
        }

        .venue-card .badge {
            font-size: 0.85em; /* Match badge size */
            padding: 0.4em 0.8em; /* Adjusted padding */
            margin-right: 5px;
            color: white; /* Ensure text is visible on all badges */
        }
        .venue-card .badge.bg-success { background-color: #28a745 !important; }
        .venue-card .badge.bg-danger { background-color: var(--accent-red) !important; }
        .venue-card .badge.bg-info { /* Re-style info badge for map link */
            background: var(--gradient-secondary) !important; /* Use gradient for visual appeal */
            color: white !important;
        }
        .venue-card .actions .btn {
            margin-right: 5px;
            margin-top: 10px; /* Space from badges */
        }

        /* Filter form background */
        .card .card-body form.row {
            background-color: var(--primary-bg) !important; /* Darker than secondary for contrast with card body */
            border-radius: 8px;
            padding: 15px;
            margin: 0; /* Remove default row margins */
        }
        .card .card-body form.row label {
            color: var(--text-color); /* Ensure label color is visible */
        }
        .modal-content { /* Styling for modals to fit theme */
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            color: var(--text-color);
        }
        .modal-header {
            border-bottom: 1px solid var(--card-border);
        }
        .modal-title {
            color: var(--accent-orange) !important;
        }
        .modal-footer {
            border-top: 1px solid var(--card-border);
        }


        /* Pagination Styling (from dashboard.php general styles) */
        .pagination .page-item .page-link {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
            transition: all 0.3s ease;
        }
        .pagination .page-item.active .page-link,
        .pagination .page-item .page-link:hover {
            background: var(--gradient-primary);
            color: white;
            border-color: var(--accent-red);
        }
        .pagination .page-item.disabled .page-link {
            background-color: var(--primary-bg);
            color: var(--light-text-color);
            border-color: var(--card-border);
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

        /* Responsive Adjustments (from dashboard.php, plus new for components) */
        @media (max-width: 768px) {
            :root {
                --section-title-font: 1.8rem;
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
            /* .filter-group-spacing: not applicable as separate CSS, but column classes handle stack */
            .stat-box h3 {
                font-size: 0.95rem;
            }
            .stat-box p {
                font-size: 2rem;
            }
            .venue-card .actions .btn {
                width: auto; /* Allow buttons to size naturally */
                font-size: 0.85rem; /* Smaller font for mobile buttons */
                padding: 0.25rem 0.5rem; /* Smaller padding for mobile buttons */
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
            .card .card-body form.row {
                 margin-left: -5px; /* Adjust to stay inside padding on smaller screens */
                 margin-right: -5px;
             }
        }
        @media (min-width: 769px) { /* Desktop */
            .sidebar-toggle-btn {
                display: none; /* Hide on larger screens */
            }
        }
    </style>
</head>
<body>
<!-- Sidebar Toggle Button for Small Screens (from dashboard.php) -->
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


<!-- New wrapper for Main Content and Footer (from dashboard.php) -->
<div class="content-wrapper" id="contentWrapper">
    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid py-4"> <!-- Using container-fluid from dashboard.php for full width -->
            <h2 class="section-title"><?= $pageTitle ?></h2>

            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type']) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
            <?php endif; ?>

            <!-- Stats Row (Original content starts here) -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-box">
                        <h3>Total Venues</h3>
                        <p><?php echo $total_venues; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <h3>Active Venues</h3>
                        <p><?php echo $active_venues; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <h3>Inactive Venues</h3>
                        <p><?php echo $inactive_venues; ?></p>
                    </div>
                </div>
            </div>


            <!-- Add/Edit Venue Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="mb-0"><?php echo $edit_mode ? 'Edit Venue' : 'Add New Venue'; ?></h3>
                </div>
                <div class="card-body">
                    <form action="venue_handler.php" method="POST">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="venue_id_pk" value="<?php echo htmlspecialchars($venue_to_edit['venue_id']); ?>">
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="venue_name" class="form-label">Venue Name (e.g., Mall Name, Complex)</label>
                                <input type="text" class="form-control" id="venue_name" name="venue_name" value="<?php echo htmlspecialchars($venue_to_edit['venue_name']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="sub_venue_name" class="form-label">Sub Venue Name (e.g., Audi 1, Hall A)</label>
                                <input type="text" class="form-control" id="sub_venue_name" name="sub_venue_name" value="<?php echo htmlspecialchars($venue_to_edit['sub_venue_name']); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="sub_venue_id_text" class="form-label">Sub Venue ID (Text Identifier, e.g., A1, H1)</label>
                                <input type="text" class="form-control" id="sub_venue_id_text" name="sub_venue_id_text" value="<?php echo htmlspecialchars($venue_to_edit['sub_venue_id']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="sub_venue_type" class="form-label">Sub Venue Type (e.g., Auditorium, Screen)</label>
                                <input type="text" class="form-control" id="sub_venue_type" name="sub_venue_type" value="<?php echo htmlspecialchars($venue_to_edit['sub_venue_type']); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="capacity" class="form-label">Capacity</label>
                                <input type="number" class="form-control" id="capacity" name="capacity" value="<?php echo htmlspecialchars($venue_to_edit['capacity']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="city_id" class="form-label">City</label>
                                <select class="form-select" id="city_id" name="city_id" required>
                                    <option value="">Select City</option>
                                    <?php foreach ($cities as $c): ?>
                                        <option value="<?php echo $c['city_id']; ?>" <?php echo ($venue_to_edit['city_id'] == $c['city_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['city_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Full Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2" required><?php echo htmlspecialchars($venue_to_edit['address']); ?></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="location_link" class="form-label">Location Link (Google Maps URL)</label>
                                <input type="url" class="form-control" id="location_link" name="location_link" value="<?php echo htmlspecialchars($venue_to_edit['location_link']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="is_active" class="form-label">Status</label>
                                <select class="form-select" id="is_active" name="is_active" required>
                                    <option value="yes" <?php echo ($venue_to_edit['is_active'] == 'yes') ? 'selected' : ''; ?>>Active</option>
                                    <option value="no" <?php echo ($venue_to_edit['is_active'] == 'no') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <?php if ($edit_mode): ?>
                            <button type="submit" name="update_venue" class="btn btn-primary">Update Venue</button>
                            <a href="index.php" class="btn btn-secondary">Cancel Edit</a>
                        <?php else: ?>
                            <button type="submit" name="save_venue" class="btn btn-primary">Save Venue</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>


            <!-- Filters and Venues List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0 d-inline">Venues List</h3>
                    <small class="ms-2 text-muted">(Showing <?php echo $stmt_venues->rowCount(); ?> of <?php echo $total_records; ?> venues)</small>
                </div>
                <div class="card-body">
                    <!-- Filter Form -->
                    <form method="GET" action="index.php" class="row gx-3 gy-2 align-items-center mb-4 p-3 rounded">
                        <div class="col-sm-3">
                            <label class="visually-hidden" for="search">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Search venue, sub-venue, address..." value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="col-sm-3">
                            <label class="visually-hidden" for="filter_city">City</label>
                            <select class="form-select" id="filter_city" name="filter_city">
                                <option value="">All Cities</option>
                                <?php foreach ($cities as $c): ?>
                                    <option value="<?php echo $c['city_id']; ?>" <?php echo ($filter_city == $c['city_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['city_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="visually-hidden" for="filter_status">Status</label>
                            <select class="form-select" id="filter_status" name="filter_status">
                                <option value="">All Statuses</option>
                                <option value="yes" <?php echo ($filter_status == 'yes') ? 'selected' : ''; ?>>Active</option>
                                <option value="no" <?php echo ($filter_status == 'no') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-sm-auto">
                            <button type="submit" class="btn btn-info w-100"><i class="fas fa-filter"></i> Filter</button>
                        </div>
                        <div class="col-sm-auto">
                            <a href="index.php" class="btn btn-secondary w-100"><i class="fas fa-times-circle"></i> Clear</a>
                        </div>
                    </form>

                    <!-- Venue Cards -->
                    <div class="row">
                        <?php
                        if ($stmt_venues->rowCount() > 0):
                            while ($venue = $stmt_venues->fetch(PDO::FETCH_ASSOC)):
                        ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card venue-card h-100">
                                <div class="card-body d-flex flex-column">
                                    <div>
                                        <h5 class="card-title"><?php echo htmlspecialchars($venue['venue_name']); ?></h5>
                                        <h6 class="card-subtitle mb-2">
                                            <?php echo htmlspecialchars($venue['sub_venue_name']); ?>
                                            (ID: <?php echo htmlspecialchars($venue['sub_venue_id']); ?>)</h6>
                                        <p class="card-text mb-1">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($venue['address']); ?>, <?php echo htmlspecialchars($venue['city_name'] ?? 'N/A'); ?>
                                        </p>
                                        <?php if($venue['capacity']): ?>
                                        <p class="card-text mb-1"><i class="fas fa-users"></i> Capacity: <?php echo htmlspecialchars($venue['capacity']); ?></p>
                                        <?php endif; ?>
                                        <?php if($venue['sub_venue_type']): ?>
                                        <p class="card-text mb-1"><i class="fas fa-theater-masks"></i> Type: <?php echo htmlspecialchars($venue['sub_venue_type']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-auto">
                                        <?php if ($venue['is_active'] == 'yes'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                        <?php if ($venue['location_link']): ?>
                                            <a href="<?php echo htmlspecialchars($venue['location_link']); ?>" target="_blank" class="badge bg-info"><i class="fas fa-link"></i> Map</a>
                                        <?php endif; ?>

                                        <div class="actions mt-2">
                                            <a href="index.php?edit_id=<?php echo $venue['venue_id']; ?>" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteVenueModal_<?php echo $venue['venue_id']; ?>">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Delete Confirmation Modal for Venue -->
                        <div class="modal fade" id="deleteVenueModal_<?php echo $venue['venue_id']; ?>" tabindex="-1" aria-labelledby="deleteVenueModalLabel_<?php echo $venue['venue_id']; ?>" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="deleteVenueModalLabel_<?php echo $venue['venue_id']; ?>">Confirm Deletion</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        Are you sure you want to delete the venue "<?php echo htmlspecialchars($venue['venue_name'] . ' - ' . $venue['sub_venue_name']); ?>"?
                                        <br><small class="text-warning">This action cannot be undone.</small>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <form action="venue_handler.php" method="POST" style="display: inline;">
                                            <input type="hidden" name="venue_id_to_delete" value="<?php echo $venue['venue_id']; ?>">
                                            <button type="submit" name="delete_venue" class="btn btn-danger">Delete Venue</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php
                            endwhile;
                        else:
                        ?>
                        <div class="col-12">
                            <p class="text-center p-4">No venues found matching your criteria.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php
                            // Build query string for pagination links to preserve filters
                            $query_params = [];
                            if (!empty($filter_city)) $query_params['filter_city'] = $filter_city;
                            if (!empty($filter_status)) $query_params['filter_status'] = $filter_status;
                            if (!empty($search_term)) $query_params['search'] = $search_term;
                            $query_string = http_build_query($query_params);
                            $base_page_url = "index.php?" . $query_string . (empty($query_string) ? "" : "&") . "page=";
                            ?>

                            <!-- Previous Page Link -->
                            <li class="page-item <?php if($page <= 1){ echo 'disabled'; } ?>">
                                <a class="page-link" href="<?php if($page > 1){ echo $base_page_url . ($page - 1); } else { echo '#'; } ?>">Previous</a>
                            </li>

                            <!-- Page Number Links -->
                            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php if($page == $i) {echo 'active'; } ?>">
                                <a class="page-link" href="<?php echo $base_page_url . $i; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>

                            <!-- Next Page Link -->
                            <li class="page-item <?php if($page >= $total_pages) { echo 'disabled'; } ?>">
                                <a class="page-link" href="<?php if($page < $total_pages) { echo $base_page_url . ($page + 1); } else { echo '#'; } ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </main>

    <!-- Footer (from dashboard.php) -->
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

<!-- Bootstrap JS Bundle and Custom JS (from dashboard.php) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Sidebar Toggle (from dashboard.php)
        const sidebar = document.getElementById('sidebar');
        const contentWrapper = document.getElementById('contentWrapper');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');

        const setSidebarOpen = (isOpen) => {
            if (isOpen) {
                sidebar.classList.add('is-open');
                document.body.classList.add('sidebar-is-open');
                if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-times"></i>';
            } else {
                sidebar.classList.remove('is-open');
                document.body.classList.remove('sidebar-is-open');
                if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
                // When collapsing the main sidebar, also collapse any open submenus
                document.querySelectorAll('.sidebar-nav .collapse.show').forEach(collapseElement => {
                    const bsCollapse = bootstrap.Collapse.getInstance(collapseElement) || new bootstrap.Collapse(collapseElement, { toggle: false });
                    bsCollapse.hide();
                });
            }
        };

        if (sidebar && contentWrapper) {
            // Desktop hover behavior
            sidebar.addEventListener('mouseenter', () => {
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

        // --- Active Link and Submenu Management --- (from dashboard.php, adapted for nested pages)
        const currentPath = window.location.pathname;
        const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

        navLinks.forEach(link => {
            const linkHref = link.getAttribute('href');
            if (!linkHref || linkHref.startsWith('#')) return;

            // Normalize link path to be relative to the root for comparison
            const normalizedLinkPath = new URL(linkHref, window.location.origin).pathname; 
            
            // Check if the current URL path starts with the normalized link path
            if (currentPath.startsWith(normalizedLinkPath) && normalizedLinkPath !== '/') { // Exclude '/' matching everything
                link.classList.add('active');

                // If this is a child link, expand its parent collapse menu
                const parentCollapseDiv = link.closest('.collapse');
                if (parentCollapseDiv) {
                    const bsCollapse = bootstrap.Collapse.getInstance(parentCollapseDiv) || new bootstrap.Collapse(parentCollapseDiv, { toggle: false });
                    bsCollapse.show(); 

                    // Highlight the parent toggle link as well
                    const parentToggleLink = document.querySelector(`a[data-bs-target="#${parentCollapseDiv.id}"]`);
                    if (parentToggleLink) {
                        parentToggleLink.classList.remove('collapsed');
                        parentToggleLink.setAttribute('aria-expanded', 'true');
                        parentToggleLink.classList.add('active'); 
                    }
                }
            } else if (currentPath === '/dashboard.php' && normalizedLinkPath === '/dashboard.php') {
                // Special case for dashboard root link
                 link.classList.add('active');
            }
        });


        // --- Caret Icon Rotation on Collapse Events --- (from dashboard.php)
        document.querySelectorAll('.sidebar-nav .collapse').forEach(collapseElement => {
            collapseElement.addEventListener('show.bs.collapse', function () {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(180deg)';
                    toggleLink.classList.add('active'); 
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
    });
</script>
</body>
</html>