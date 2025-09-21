<?php
// event_assignments.php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once '../links.php'; // Include the links.php file for common links and configurations

// Database configuration taken from dashboard.php for consistency, though Database.php handles actual connection
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Redirect to login if not authenticated (except for auth pages)
$auth_pages = [$login_page, $register_page, $forgot_pass];

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

// Ensure adminUserId is set from session, as used in dashboard.php
// Assuming 'user_id' in session is the admin ID for filtering events
$adminUserId = $_SESSION['user_id'] ?? null; // Null coalesce for safety
if (!$adminUserId) {
    // If user_id is not set even after session check, it's an issue.
    // This typically means the user is not truly authenticated or session data is missing.
    // For production, you might want a more robust error handling or immediate logout.
    // Given the preceding auth check, this should ideally not be reached if the user is not logged out.
    // For now, if $adminUserId is null, subsequent queries might fail or return no data for user-specific content.
}


// Include Database connection class
require_once 'Database.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die("ERROR: Could not connect to database. Please check Database.php configuration.");
}

$pageTitle = "Manage Event Assignments";

// Fetch cities (for event filter dropdown) using PDO
$cities_data = [];
try {
    // City filtering to show only active cities
    $city_sql = "SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name ASC";
    $city_stmt = $conn->prepare($city_sql);
    $city_stmt->execute();
    $cities_data = $city_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("ERROR: Could not fetch cities: " . $e->getMessage());
}

// Get filter parameters from GET for initial display
$filter_city = isset($_GET['filter_city']) ? (int)$_GET['filter_city'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$search_term = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search'])) : '';
$start_date_filter = isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : '';
$end_date_filter = isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : '';
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;

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

    <!-- Custom CSS (Embedded) - Integrates styles from dashboard.php -->
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

        /* When sidebar expands (has .is-open class), push content wrapper (from dashboard.php) */
        body.sidebar-is-open .content-wrapper { /* Class added to body by JS */
            margin-left: var(--sidebar-width-expanded);
        }

        /* Main Content Area (from dashboard.php) */
        .main-content {
            flex-grow: 1; /* Take up remaining space AFTER padding */
            padding: 20px;
            /* Remove margin-left and width as they are handled by .content-wrapper now */
        }

        /* General Theme & Component Styling (adapted to dashboard.php's theme) */
        .container-fluid {
            background-color: var(--primary-bg); /* Use theme color */
        }
        .section-title { /* Added since it's common in dashboard.php */
            color: var(--accent-orange);
            font-size: var(--section-title-font);
            margin-bottom: 20px;
            border-bottom: 2px solid var(--accent-red);
            padding-bottom: 10px;
        }

        .card {
            background-color: var(--secondary-bg); /* Use secondary-bg */
            border: 1px solid var(--card-border); /* Use card-border */
            color: var(--text-color); /* Use text-color */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
            border-radius: 8px;
        }
        .card-header {
            background-color: var(--secondary-bg); /* Lighter than body for header */
            border-bottom: 1px solid var(--card-border);
            color: var(--accent-orange); /* Use accent-orange */
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }
        .card-title {
            color: var(--accent-yellow); /* Use accent-yellow */
            font-size: 1.25em;
        }
        .card-subtitle {
            color: var(--accent-peach) !important; /* Use accent-peach */
            font-size: 0.9em;
        }
        .form-label {
            color: var(--text-color); /* Use text-color */
        }
        .form-control, .form-select, .input-group-text {
            background-color: var(--secondary-bg); /* Use secondary-bg */
            color: var(--text-color); /* Use text-color */
            border: 1px solid var(--card-border); /* Use card-border */
            border-radius: 5px;
        }
        .form-control::placeholder {
            color: var(--light-text-color); /* Placeholder color */
            opacity: 0.7;
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--secondary-bg); /* Keep background same on focus */
            color: var(--text-color); /* Keep text color same on focus */
            border-color: var(--accent-orange); /* Highlight border with accent color */
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25); /* Glow effect */
        }
        textarea.form-control { resize: vertical; }

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
        .btn-info {
            background-color: var(--accent-orange); /* Use accent-orange for info */
            border-color: var(--accent-orange);
            color: var(--nav-text); /* White text on orange */
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .btn-info:hover {
            background-color: var(--accent-peach); /* Lighter orange on hover */
            border-color: var(--accent-peach);
            color: var(--nav-text);
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
        .btn-warning {
            background-color: var(--accent-yellow); /* Use accent-yellow for warning */
            border-color: var(--accent-yellow);
            color: var(--primary-bg); /* Dark text on yellow */
            transition: all 0.3s ease;
        }
        .btn-warning:hover {
            background-color: #d1b000; /* Darker yellow on hover */
            border-color: #d1b000;
            color: var(--primary-bg);
        }
        .btn-outline-light {
            border-color: var(--light-text-color);
            color: var(--light-text-color);
            background-color: transparent;
        }
        .btn-outline-light:hover {
            background-color: rgba(255, 255, 255, 0.08); /* Subtle hover from dashboard.php's btn-outline-secondary */
            color: var(--text-color);
            border-color: var(--text-color);
        }

        /* Badges (adapted to dashboard.php colors) */
        .badge {
            font-size: 0.8em;
            font-weight: normal;
            padding: 0.4em 0.6em;
            border-radius: 3px;
        }
        .badge.bg-success {
            background-color: #28a745 !important;
            color: white !important;
        }
        .badge.bg-danger {
            background-color: var(--accent-red) !important;
            color: white !important;
        }
        .badge.bg-info {
            background-color: var(--accent-orange) !important;
            color: white !important;
        }
        .badge.bg-secondary {
            background-color: #6c757d !important;
            color: white !important;
        }

        /* Stat Boxes (adapted to dashboard.php's metric-card styling) */
        .stat-box {
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
            justify-content: center; /* Center content vertically */
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .stat-box h3 {
            color: var(--light-text-color); /* Use light-text-color */
            font-size: var(--metric-card-h5-font); /* Use defined font size for consistency */
            font-weight: 600;
            margin-bottom: 10px;
        }
        .stat-box p {
            font-size: var(--metric-card-display-4-font); /* Use defined font size for consistency */
            font-weight: bold;
            color: var(--accent-orange); /* Use accent-orange for numbers */
            margin-bottom: 0;
        }
        /* Adjusted for modal stats */
        #assignmentModal .stat-box {
            padding: 10px;
            margin-bottom: 15px;
        }
        #assignmentModal .stat-box h3 {
            font-size: 1rem;
        }
        #assignmentModal .stat-box p {
            font-size: 1.5rem;
        }


        /* Filters section (from dashboard.php concept) */
        form.row.gx-3.gy-2 {
            background-color: var(--secondary-bg) !important; /* Use secondary-bg */
            border-radius: 8px;
            padding: 15px !important;
            border: 1px solid var(--card-border);
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
        .form-check-label.visible-label { /* Re-enabled and styled for dark theme */
            display: inline-block !important; /* Override visually-hidden */
            color: var(--text-color);
            margin-left: 8px; /* Space between checkbox and text */
        }


        /* Icons */
        .text-danger { color: var(--accent-red) !important; } /* Use accent-red */
        .text-warning { color: var(--accent-yellow) !important; } /* Use accent-yellow */
        .text-muted { color: var(--light-text-color) !important; } /* Use light-text-color */

        i.fas, i.far { color: var(--accent-orange); } /* General icon color from dashboard.php */
        i.fa-building { color: var(--accent-peach); } /* Peach for venues */
        i.fa-calendar-day { color: #9B59B6; } /* Purple for schedules - kept original for now */
        i.fa-ticket-alt { color: #2ECC71; } /* Green for events - kept original for now */
        i.text-primary { color: var(--accent-orange) !important; } /* Specific for assigned schedules icon, use accent-orange */


        /* Pagination (from dashboard.php) */
        .pagination .page-item .page-link {
            background-color: var(--secondary-bg);
            color: var(--accent-orange);
            border: 1px solid var(--card-border);
            border-radius: 5px;
            margin: 0 2px;
        }
        .pagination .page-item .page-link:hover {
            background-color: rgba(255, 255, 255, 0.08); /* Subtle hover, like btn-outline-secondary */
            color: var(--accent-peach);
            border-color: var(--accent-orange);
        }
        .pagination .page-item.active .page-link {
            background: var(--gradient-primary); /* Use gradient for active */
            border-color: var(--accent-red);
            color: white;
        }
        .pagination .page-item.disabled .page-link {
            background-color: var(--primary-bg); /* Lighter disabled background */
            border-color: var(--card-border);
            color: var(--light-text-color);
            pointer-events: none; /* Disable click */
        }
        .pagination .page-item.disabled .page-link:hover { /* Override hover for disabled */
            background-color: var(--primary-bg);
            color: var(--light-text-color);
            border-color: var(--card-border);
        }

        /* Custom Switch for Status Toggler (colors adapted) */
        .custom-switch { position: relative; display: inline-block; width: 40px; height: 24px; vertical-align: top; margin-top: 5px; }
        .custom-switch input { opacity: 0; width: 0; height: 0; }
        .custom-switch .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: var(--accent-red); /* Inactive is accent-red */
            transition: .4s; border-radius: 24px;
        }
        .custom-switch .slider:before {
            position: absolute; content: ""; height: 16px; width: 16px; left: 4px; bottom: 4px;
            background-color: white; /* Thumb is white */
            transition: .4s; border-radius: 50%;
        }
        .custom-switch input:checked + .slider { background-color: #28a745; } /* Active is green */
        .custom-switch input:checked + .slider:before { transform: translateX(16px); }

        /* Event Card Specifics (adapted to dashboard.php colors) */
        .event-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            min-height: 250px; /* Adjusted fixed height for consistency */
            display: flex;
            flex-direction: column;
        }
        .event-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.6);
        }
        .event-card .card-text i {
            color: var(--accent-peach); /* Icons within card text */
        }

        /* Modals (adapted from dashboard.php colors) */
        .modal-content {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
        }
        .modal-header {
            border-bottom: 1px solid var(--card-border);
        }
        .modal-footer {
            border-top: 1px solid var(--card-border);
        }
        .btn-close { /* Filter in place of color for dark backgrounds */
            filter: invert(1);
            padding: 0.5rem;
            margin: 0.5rem;
        }
        .modal-title {
            color: var(--accent-yellow) !important; /* Use accent-yellow */
        }

        /* Specific for Assignment Modal (no scrollbar, tabbing) */
        #assignmentModal .modal-body {
            max-height: 70vh;
            overflow-y: auto;
            padding-right: var(--bs-gutter-x, 0.75rem);
        }
        #assignmentModal .nav-tabs .nav-link {
            color: var(--light-text-color);
            background-color: var(--primary-bg); /* Darker background for tabs */
            border-color: var(--card-border);
        }
        #assignmentModal .nav-tabs .nav-link.active {
            color: var(--primary-bg); /* Dark text on active */
            background-color: var(--accent-orange); /* Active tab is accent orange */
            border-color: var(--accent-orange);
        }
        #assignmentModal .nav-tabs .nav-link:hover {
            border-color: var(--accent-peach); /* Hover color */
        }
        #assignmentModal .tab-content {
            border: 1px solid var(--card-border);
            border-top: none;
            padding: 15px;
            background-color: var(--secondary-bg);
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }
        #assignmentModal .schedule-list-item {
            background-color: var(--primary-bg); /* Darker background for list items */
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #assignmentModal .schedule-list-item .actions {
            white-space: nowrap; /* Prevent buttons from wrapping */
        }

        /* Footer (from dashboard.php) */
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
                left: 0; /* Ensures it starts from left */
                transition: width var(--sidebar-transition-duration) ease-in-out, left var(--sidebar-transition-duration) ease-in-out; /* Add left transition */
                z-index: 1020; /* Lower than toggle button, higher than backdrop */
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
            /* Overlay for mobile sidebar (from event_assignment.php original) */
            #sidebarOverlay {
                position: fixed; width: 100%; height: 100%; top: 0; left: 0;
                background-color: rgba(0,0,0,0.5); z-index: 1010; /* Between sidebar and content */
                display: none;
            }
            body.sidebar-is-open #sidebarOverlay {
                display: block;
            }


            .stat-box {
                padding: 15px; /* Reduce padding on cards for mobile */
            }
            /* Adjust column widths for filters and content on mobile */
            .col-sm-3.col-md-3, .col-sm-3.col-md-2, .col-sm-6.col-md-1, .col-md-3.col-sm-6 { /* Added col-sm-6 for modal filters */
                flex: 0 0 100%;
                max-width: 100%;
                margin-bottom: 10px; /* Add some spacing between stacked filter inputs */
            }
            .col-sm-6.col-md-1.d-grid {
                margin-top: 10px; /* Add margin above buttons if stacking */
            }

            .event-card { /* Ensure event cards stack nicely */
                max-width: 100%;
            }
            .event-card .card-body {
                padding-bottom: 10px !important; /* Adjust if content overflows from fixed min-height */
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
            .section-title { /* Adjust main title size for mobile */
                font-size: 1.75rem;
            }
            h3.mb-0.d-inline { /* Adjust section title size for mobile */
                font-size: 1.5rem;
            }
            small.ms-2.text-muted { /* Adjust small text for mobile */
                 font-size: 0.75rem;
            }
            /* Bulk action buttons for events and schedules wrap better */
            .d-flex.flex-wrap .btn-sm, .d-flex.flex-wrap .badge {
                margin-bottom: 8px !important; /* Spacing between wrapped buttons */
            }
        }
        @media (min-width: 769px) { /* Desktop */
            .sidebar-toggle-btn {
                display: none; /* Hide on larger screens */
            }
            /* Make sure filter columns go back to normal on desktop */
            .col-sm-3.col-md-3 { flex: 0 0 25%; max-width: 25%; }
            .col-sm-3.col-md-2 { flex: 0 0 16.66666667%; max-width: 16.66666667%; }
            .col-sm-6.col-md-1 { flex: 0 0 8.33333333%; max-width: 8.33333333%; }
            .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
            .col-md-3.col-sm-6 { /* For modal filters, revert to 25% */
                flex: 0 0 25%;
                max-width: 25%;
            }

            /* Specific rule to fix event cards on wider mobile/tablet (between 576px and 768px)*/
            @media (min-width: 577px) and (max-width: 768px) {
                .col-sm-6.col-md-4.col-lg-3 {
                    flex: 0 0 50%; /* Two columns */
                    max-width: 50%;
                }
            }
        }

        /* Toast Notifications (from dashboard.php) */
        .toast-container { z-index: 1070; right: 0; top: 0; }
        .toast {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
        }
        .toast .btn-close { filter: invert(1); } /* Ensure close button visibility */
        .toast .toast-body { word-break: break-word; } /* Prevent long messages from overflowing */
        .toast.bg-success { background-color: #28a745 !important; }
        .toast.bg-danger { background-color: var(--accent-red) !important; }
        .toast.bg-info { background-color: var(--accent-orange) !important; }

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

    <!-- New wrapper for Main Content and Footer -->
    <div class="content-wrapper" id="contentWrapper">
        <!-- Toast Container (from original event_assignment.php) -->
        <div class="toast-container position-fixed top-0 end-0 p-3"></div>

        <!-- Main Content (from dashboard.php, name changed to mainContent for consistency) -->
        <main class="main-content" id="mainContent">
            <!-- Overlay for click-outside-to-close on mobile (Original event_assignment.php) -->
            <div id="sidebarOverlay" class="d-md-none"></div>

            <div class="container-fluid">
                <!-- Combined Menu (Mobile) - replaced with sidebar toggle btn and title -->
                <h1 class="section-title mb-0 d-inline"><?php echo $pageTitle; ?></h1>

                <!-- Stats Row -->
                <div class="row mb-4 mt-3" id="statsRow">
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Total Events</h3>
                            <p id="totalEventsCount">0</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Active Events</h3>
                            <p id="activeEventsCount">0</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Vacant Schedules</h3>
                            <p id="vacantSchedulesCount">0</p>
                        </div>
                    </div>
                </div>

                <!-- Event Filters and List -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0 d-inline">Event List</h3>
                        <small class="ms-2 text-muted">(Displaying <span id="displayedEventsCount">0</span> of <span id="totalEventsFilteredCount">0</span> events)</small>
                    </div>
                    <div class="card-body">
                        <!-- Event Filter Form -->
                        <form id="eventFilterForm" class="row gx-3 gy-2 align-items-end mb-4">
                            <div class="col-sm-3 col-md-3">
                                <label class="form-label" for="search">Search Event</label>
                                <input type="text" class="form-control" id="search" name="search" placeholder="Event Name, Genre..." value="<?php echo $search_term; ?>">
                            </div>
                            <div class="col-sm-3 col-md-2">
                                <label class="form-label" for="filter_city">City (Venue)</label>
                                <select class="form-select" id="filter_city" name="filter_city">
                                    <option value="">All Cities</option>
                                    <?php foreach ($cities_data as $city): ?>
                                        <option value="<?php echo htmlspecialchars($city['city_id']); ?>" <?php echo ((string)$filter_city === (string)$city['city_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($city['city_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-3 col-md-2">
                                <label class="form-label" for="filter_status">Event Status</label>
                                <select class="form-select" id="filter_status" name="filter_status">
                                    <option value="">All Statuses</option>
                                    <option value="yes" <?php echo ($filter_status == 'yes') ? 'selected' : ''; ?>>Active</option>
                                    <option value="no" <?php echo ($filter_status == 'no') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-sm-3 col-md-2">
                                <label class="form-label" for="start_date">Event Starts After</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date_filter; ?>">
                            </div>
                            <div class="col-sm-3 col-md-2">
                                <label class="form-label" for="end_date">Event Ends Before</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date_filter; ?>">
                            </div>
                            <div class="col-sm-6 col-md-1 d-grid">
                                <button type="submit" class="btn btn-info"><i class="fas fa-filter"></i></button>
                            </div>
                            <div class="col-sm-6 col-md-1 d-grid">
                                <button type="button" class="btn btn-secondary" id="clearEventFilters"><i class="fas fa-times-circle"></i></button>
                            </div>
                        </form>

                        <hr style="border-top: 1px solid var(--card-border); margin: 1rem 0;">

                        <!-- Bulk Actions for Events -->
                        <div class="d-flex justify-content-start align-items-center mb-3 flex-wrap"> <!-- Added flex-wrap for mobile -->
                            <div class="form-check me-3 mb-2"> <!-- Added mb-2 for mobile spacing -->
                                <input class="form-check-input bulk-checkbox" type="checkbox" id="selectAllEventsCheckbox">
                                <label class="form-check-label visible-label" for="selectAllEventsCheckbox">Select All</label>
                            </div>
                            <span class="me-2 text-warning mb-2">Bulk Actions (Events):</span>
                            <button type="button" class="btn btn-sm btn-success me-2 mb-2" id="bulkActivateEventsBtn">
                                <i class="fas fa-toggle-on"></i> Activate Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-warning mb-2" id="bulkDeactivateEventsBtn">
                                <i class="fas fa-toggle-off"></i> Deactivate Selected
                            </button>
                        </div>

                        <!-- Event Cards -->
                        <div class="row" id="eventCardsContainer">
                            <!-- Event Cards will be loaded here by JavaScript -->
                            <div class="col-12 text-center p-4" id="loadingSpinner">
                                <div class="spinner-border text-warning" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="text-white mt-2">Loading events...</p>
                            </div>
                            <div class="col-12 text-center p-4 d-none" id="noEventsFound">
                                <p>No events found matching your criteria.</p>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <nav aria-label="Page navigation" class="mt-4">
                            <ul class="pagination justify-content-center" id="eventPagination">
                                <!-- Pagination will be loaded here by JavaScript -->
                            </ul>
                        </nav>

                    </div>
                </div>
            </div>
        </main>

        <!-- Assignment Modal -->
        <div class="modal fade" id="assignmentModal" tabindex="-1" aria-labelledby="assignmentModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="assignmentModalLabel">Manage Assignments for <span id="modalEventName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="modalEventId">

                        <!-- Stats Row for Schedules in Modal -->
                        <div class="row mb-3 mt-2" id="modalStatsRow">
                            <div class="col-md-6 mb-3">
                                <div class="stat-box">
                                    <h3>Mapped Schedules <br> (This Event)</h3>
                                    <p id="modalMappedSchedules">0</p>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="stat-box">
                                    <h3>Vacant Schedules <br> (Matching Filters)</h3>
                                    <p id="modalVacantSchedules">0</p>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3 align-items-center">
                            <!-- Filters for Schedules Tab -->
                            <div class="col-md-3 col-sm-6 mb-3">
                                <label for="scheduleStatusFilter" class="form-label">Slot Status</label>
                                <select class="form-select" id="scheduleStatusFilter">
                                    <option value="all">All</option>
                                    <option value="yes">Active</option>
                                    <option value="no">Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-3" id="dailyDatePickerContainer">
                                <label for="modalScheduleDate" class="form-label">Filter by Date</label>
                                <input type="date" class="form-control" id="modalScheduleDate">
                            </div>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <label for="modalAssignmentTypeFilter" class="form-label">Show Schedules</label>
                                <select class="form-select" id="modalAssignmentTypeFilter">
                                    <option value="assigned_to_event">Assigned to this Event</option>
                                    <option value="vacant">Vacant Slots</option>
                                </select>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <label for="modalVenueFilter" class="form-label">Filter by Venue</label>
                                <select class="form-select" id="modalVenueFilter">
                                    <option value="">All Venues</option>
                                    <!-- Venues associated with schedules will be loaded here -->
                                </select>
                            </div>
                        </div>

                        <!-- Bulk Actions for Schedules -->
                        <div class="d-flex justify-content-start align-items-center mb-3 flex-wrap">
                            <div class="form-check me-3 mb-2">
                                <input class="form-check-input bulk-checkbox" type="checkbox" id="selectAllModalSchedulesCheckbox">
                                <label class="form-check-label visible-label" for="selectAllModalSchedulesCheckbox">Select All</label>
                            </div>
                            <span class="me-2 text-warning mb-2">Bulk Actions (Schedules):</span>
                            <button type="button" class="btn btn-sm btn-success me-2 mb-2" id="bulkAssignSchedulesBtn">
                                <i class="fas fa-plus"></i> Bulk Assign
                            </button>
                            <button type="button" class="btn btn-sm btn-danger me-2 mb-2" id="bulkUnassignSchedulesBtn">
                                <i class="fas fa-minus"></i> Bulk Unassign
                            </button>
                            <button type="button" class="btn btn-sm btn-info me-2 mb-2" id="bulkActivateScheduleSlotBtn">
                                <i class="fas fa-toggle-on"></i> Activate Slot
                            </button>
                            <button type="button" class="btn btn-sm btn-warning mb-2" id="bulkDeactivateScheduleSlotBtn">
                                <i class="fas fa-toggle-off"></i> Deactivate Slot
                            </button>
                        </div>

                        <!-- Tabbing for Different Schedule Views (Daily/Weekly/Monthly) -->
                        <ul class="nav nav-tabs" id="assignmentTabs" role="tablist">
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
                        <div class="tab-content" id="assignmentTabContent">
                            <div class="tab-pane fade show active" id="daily-schedule" role="tabpanel" aria-labelledby="daily-tab">
                                <h6 class="text-center mt-3">Schedules for <span id="currentDailyDate"></span></h6>
                                <small class="d-block text-end mb-2 text-muted">Total: <span id="dailyTotalSchedules">0</span> | Active: <span id="dailyActiveSchedules">0</span> | Assigned: <span id="dailyAssignedSchedules">0</span></small>
                                <div id="dailyScheduleList" class="schedule-list">
                                    <!-- Schedules loaded via AJAX -->
                                </div>
                            </div>
                            <div class="tab-pane fade" id="weekly-schedule" role="tabpanel" aria-labelledby="weekly-tab">
                                <h6 class="text-center mt-3">Upcoming Schedules (Next 7 Days)</h6>
                                <small class="d-block text-end mb-2 text-muted">Total: <span id="weeklyTotalSchedules">0</span> | Active: <span id="weeklyActiveSchedules">0</span> | Assigned: <span id="weeklyAssignedSchedules">0</span></small>
                                <div id="weeklyScheduleList" class="schedule-list">
                                    <!-- Schedules loaded via AJAX -->
                                </div>
                            </div>
                            <div class="tab-pane fade" id="monthly-schedule" role="tabpanel" aria-labelledby="monthly-tab">
                                <h6 class="text-center mt-3">Upcoming Schedules (Next 30 Days)</h6>
                                <small class="d-block text-end mb-2 text-muted">Total: <span id="monthlyTotalSchedules">0</span> | Active: <span id="monthlyActiveSchedules">0</span> | Assigned: <span id="monthlyAssignedSchedules">0</span></small>
                                <div id="monthlyScheduleList" class="schedule-list">
                                    <!-- Schedules loaded via AJAX -->
                                </div>
                            </div>
                        </div>
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

    <!-- Bootstrap JS (via CDN - placed at end of body for performance) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JavaScript (Embedded) -->

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const URL_HANDLER = 'event_assignment_handler.php'; // Path to your new handler

            // Sidebar elements
            const sidebar = document.getElementById('sidebar'); // Changed from querySelector
            const contentWrapper = document.getElementById('contentWrapper'); // New
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn'); // New

            // Original mobile overlay, now also used on small screens
            const sidebarOverlay = document.getElementById('sidebarOverlay');

            // --- Sidebar Toggle Logic (from dashboard.php) ---
            const setSidebarOpen = (isOpen) => {
                if (isOpen) {
                    sidebar.classList.add('is-open');
                    document.body.classList.add('sidebar-is-open');
                    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-times"></i>';
                    if (window.innerWidth <= 768) { // For mobile, show overlay
                        sidebarOverlay.style.display = 'block';
                    }
                } else {
                    sidebar.classList.remove('is-open');
                    document.body.classList.remove('sidebar-is-open');
                    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
                    document.querySelectorAll('.sidebar-nav .collapse.show').forEach(collapseElement => {
                        const bsCollapse = bootstrap.Collapse.getInstance(collapseElement) || new bootstrap.Collapse(collapseElement, { toggle: false });
                        bsCollapse.hide();
                    });
                    if (window.innerWidth <= 768) { // For mobile, hide overlay
                        sidebarOverlay.style.display = 'none';
                    }
                }
            };

            // Sidebar hover behavior for desktop (from dashboard.php)
            if (sidebar && contentWrapper) {
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

            // Mobile click toggle behavior (from dashboard.php)
            if (sidebarToggleBtn) {
                sidebarToggleBtn.addEventListener('click', function () {
                    setSidebarOpen(!sidebar.classList.contains('is-open'));
                });

                // Click outside to close sidebar on mobile (from dashboard.php)
                document.addEventListener('click', function (event) {
                    if (window.innerWidth <= 768 && sidebar.classList.contains('is-open')) {
                        if (!sidebar.contains(event.target) && !sidebarToggleBtn.contains(event.target)) {
                            setSidebarOpen(false);
                        }
                    }
                });
            }
            // Also listen to overlay click for mobile sidebar close
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    setSidebarOpen(false);
                });
            }


            // --- Active Link and Submenu Management (from dashboard.php) ---
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

            navLinks.forEach(link => {
                const linkHref = link.getAttribute('href');
                if (!linkHref || linkHref.startsWith('#')) return;

                // Adjust path for comparison if needed, e.g., ../event_assignment/event_assignment.php
                // Or simply compare full paths.
                // Assuming "event_assignment.php" is the current page, and the link href is "../event_assignment/event_assignment.php"
                // This means using the full path is better for comparison.
                const fullLinkPath = new URL(linkHref, window.location.origin + window.location.pathname).pathname;
                const currentFullPath = window.location.pathname;

                if (fullLinkPath === currentFullPath) {
                    link.classList.add('active');

                    const parentCollapseDiv = link.closest('.collapse');
                    if (parentCollapseDiv) {
                        const bsCollapse = bootstrap.Collapse.getInstance(parentCollapseDiv) || new bootstrap.Collapse(parentCollapseDiv, { toggle: false });
                        bsCollapse.show(); // Show the collapse

                        const parentToggleLink = document.querySelector(`a[data-bs-target="#${parentCollapseDiv.id}"]`);
                        if (parentToggleLink) {
                            parentToggleLink.classList.remove('collapsed');
                            parentToggleLink.setAttribute('aria-expanded', 'true');
                        }
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
                        // Optionally activate parent link on expand, but original dashboard.php doesn't do this for parents unless child is active
                        // toggleLink.classList.add('active'); 
                    }
                });

                collapseElement.addEventListener('hide.bs.collapse', function () {
                    const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                    if (toggleLink) {
                        const caretIcon = toggleLink.querySelector('.caret-icon');
                        if (caretIcon) caretIcon.style.transform = 'rotate(0deg)';
                        
                        // Only deactivate parent link if none of its *current* sub-items are active
                        // This prevents deactivating parent if another child is active
                        const hasActiveChild = this.querySelector('.nav-link.active');
                        if (!hasActiveChild) {
                            toggleLink.classList.remove('active');
                        }
                    }
                });
            });


            // Event filter form elements
            const eventFilterForm = document.getElementById('eventFilterForm');
            const searchInput = document.getElementById('search');
            const filterCitySelect = document.getElementById('filter_city');
            const filterStatusSelect = document.getElementById('filter_status');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const clearEventFiltersBtn = document.getElementById('clearEventFilters');

            // Event list elements
            const eventCardsContainer = document.getElementById('eventCardsContainer');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const noEventsFound = document.getElementById('noEventsFound');
            const eventPagination = document.getElementById('eventPagination');
            const displayedEventsCountSpan = document.getElementById('displayedEventsCount');
            const totalEventsFilteredCountSpan = document.getElementById('totalEventsFilteredCount');

            // Stats row elements (main page)
            const totalEventsCount = document.getElementById('totalEventsCount');
            const activeEventsCount = document.getElementById('activeEventsCount');
            const vacantSchedulesCount = document.getElementById('vacantSchedulesCount');

            // Bulk event action buttons
            const selectAllEventsCheckbox = document.getElementById('selectAllEventsCheckbox');
            const bulkActivateEventsBtn = document.getElementById('bulkActivateEventsBtn');
            const bulkDeactivateEventsBtn = document.getElementById('bulkDeactivateEventsBtn');

            // Assignment Modal elements
            const assignmentModal = new bootstrap.Modal(document.getElementById('assignmentModal'));
            const modalEventName = document.getElementById('modalEventName');
            const modalEventId = document.getElementById('modalEventId');
            const scheduleStatusFilter = document.getElementById('scheduleStatusFilter');
            const modalScheduleDate = document.getElementById('modalScheduleDate');
            const modalAssignmentTypeFilter = document.getElementById('modalAssignmentTypeFilter');
            const modalVenueFilter = document.getElementById('modalVenueFilter');


            // Modal Stats elements
            const modalMappedSchedules = document.getElementById('modalMappedSchedules');
            const modalVacantSchedules = document.getElementById('modalVacantSchedules');

            // Bulk schedule action buttons (+ select all for modal)
            const selectAllModalSchedulesCheckbox = document.getElementById('selectAllModalSchedulesCheckbox');
            const bulkAssignSchedulesBtn = document.getElementById('bulkAssignSchedulesBtn');
            const bulkUnassignSchedulesBtn = document.getElementById('bulkUnassignSchedulesBtn');
            const bulkActivateScheduleSlotBtn = document.getElementById('bulkActivateScheduleSlotBtn');
            const bulkDeactivateScheduleSlotBtn = document.getElementById('bulkDeactivateScheduleSlotBtn');

            // Schedule Tabbing content
            const assignmentTabs = document.getElementById('assignmentTabs');
            const dailyScheduleList = document.getElementById('dailyScheduleList');
            const weeklyScheduleList = document.getElementById('weeklyScheduleList');
            const monthlyScheduleList = document.getElementById('monthlyScheduleList');
            const currentDailyDateSpan = document.getElementById('currentDailyDate');
            const dailyDatePickerContainer = document.getElementById('dailyDatePickerContainer');

            // Mini-stats for each tab (within the small text)
            const dailyTotalSchedules = document.getElementById('dailyTotalSchedules');
            const dailyActiveSchedules = document.getElementById('dailyActiveSchedules');
            const dailyAssignedSchedules = document.getElementById('dailyAssignedSchedules');
            const weeklyTotalSchedules = document.getElementById('weeklyTotalSchedules');
            const weeklyActiveSchedules = document.getElementById('weeklyActiveSchedules');
            const weeklyAssignedSchedules = document.getElementById('weeklyAssignedSchedules');
            const monthlyTotalSchedules = document.getElementById('monthlyTotalSchedules');
            const monthlyActiveSchedules = document.getElementById('monthlyActiveSchedules');
            const monthlyAssignedSchedules = document.getElementById('monthlyAssignedSchedules');


            let currentPage = <?php echo $current_page; ?>;

            // --- Utility Functions ---
            function showToast(message, type) {
                const toastContainer = document.querySelector('.toast-container');
                const toastElement = document.createElement('div');
                toastElement.classList.add('toast', 'align-items-center', 'border-0', 'text-white');
                const bsTypeClass = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-info');
                toastElement.classList.add(bsTypeClass);
                toastElement.setAttribute('role', 'alert');
                toastElement.setAttribute('aria-live', 'assertive');
                toastElement.setAttribute('aria-atomic', 'true');
                toastElement.setAttribute('data-bs-delay', '2500');
                toastElement.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                `;
                toastContainer.appendChild(toastElement);
                const toast = new bootstrap.Toast(toastElement);
                toast.show();
                toastElement.addEventListener('hidden.bs.toast', () => {
                    if (toastElement.parentNode) toastElement.parentNode.removeChild(toastElement);
                });
            }

            // Helper for HTML escaping
            function escapeHTML(str) {
                if (typeof str !== 'string' && typeof str !== 'number') return ''; // Ensure it's a string or number
                const div = document.createElement('div');
                div.appendChild(document.createTextNode(String(str)));
                return div.innerHTML;
            }

            // --- Main Event Fetching Function ---
            function fetchEvents(page = currentPage) {
                loadingSpinner.classList.remove('d-none');
                eventCardsContainer.innerHTML = ''; // Clear existing cards
                noEventsFound.classList.add('d-none');
                selectAllEventsCheckbox.checked = false; // Reset select all on new fetch

                const params = new URLSearchParams({
                    action: 'get_events_with_assignment_counts',
                    page: page,
                    search: searchInput.value,
                    city_id: filterCitySelect.value,
                    is_active: filterStatusSelect.value,
                    start_date: startDateInput.value,
                    end_date: endDateInput.value,
                    admin_user_id: '<?php echo $adminUserId; ?>' // Pass adminUserId to filter events for current user
                });

                fetch(`${URL_HANDLER}?${params.toString()}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        loadingSpinner.classList.add('d-none');
                        if (data.success) {
                            displayEvents(data.events);
                            updateEventPagination(data.total_pages_events, data.current_page);
                            updateMainStats(data.stats);
                            totalEventsFilteredCountSpan.textContent = data.total_events_filtered;
                            displayedEventsCountSpan.textContent = data.events.length;

                            if (data.events.length === 0) {
                                noEventsFound.classList.remove('d-none');
                            }
                        } else {
                            showToast(data.message || 'Failed to fetch events.', 'danger');
                            noEventsFound.classList.remove('d-none');
                            console.error('API Error:', data.message);
                        }
                    })
                    .catch(error => {
                        loadingSpinner.classList.add('d-none');
                        showToast('Error fetching events: ' + error.message, 'danger');
                        console.error('Fetch error:', error);
                    });
            }

            function displayEvents(events) {
                eventCardsContainer.innerHTML = '';
                if (events.length === 0) {
                    noEventsFound.classList.remove('d-none');
                    return;
                }
                events.forEach(event => {
                    const card = document.createElement('div');
                    card.className = 'col-sm-6 col-md-4 col-lg-3 mb-3';

                    const statusClass = event.is_active === 'yes' ? 'bg-success' : 'bg-danger';
                    const statusText = event.is_active === 'yes' ? 'Active' : 'Inactive';

                    card.innerHTML = `
                        <div class="card event-card h-100 position-relative">
                            <div class="form-check position-absolute" style="top: 10px; right: 10px;">
                                <input class="form-check-input bulk-event-checkbox" type="checkbox" value="${event.event_id}" id="event_${event.event_id}">
                                <label class="form-check-label visually-hidden" for="event_${event.event_id}">Select Event</label>
                            </div>
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title">${escapeHTML(event.event_name)}</h5>
                                <h6 class="card-subtitle mb-2 text-muted">${escapeHTML(event.event_type)} - ${escapeHTML(event.genre)}</h6>
                                <p class="card-text mb-1"><i class="fas fa-language"></i> Language: ${escapeHTML(event.event_language)}</p>
                                <p class="card-text mb-1"><i class="fas fa-calendar-alt"></i> Duration: ${escapeHTML(event.event_duration)}</p>
                                <p class="card-text mb-1"><i class="fas fa-calendar-check text-primary"></i> Assigned Schedules: ${event.assigned_schedules_count}</p>
                                <div class="mt-auto pt-2 d-flex justify-content-between align-items-end">
                                    <div class="d-flex align-items-center">
                                        <label class="custom-switch me-2">
                                            <input type="checkbox" class="event-status-toggle" data-event-id="${event.event_id}" ${event.is_active === 'yes' ? 'checked' : ''}>
                                            <span class="slider round"></span>
                                        </label>
                                        <span class="badge ${statusClass}" id="event_status_${event.event_id}">${statusText}</span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-info manage-assignments-btn" data-event-id="${event.event_id}" data-event-name="${escapeHTML(event.event_name)}">
                                        <i class="fas fa-ticket-alt"></i> Manage Assignments
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    eventCardsContainer.appendChild(card);
                });
                addEventEventListeners();
            }

            function updateMainStats(stats) {
                totalEventsCount.textContent = stats.total_events || 0;
                activeEventsCount.textContent = stats.active_events || 0;
                vacantSchedulesCount.textContent = stats.vacant_schedules || 0;
            }

            function updateEventPagination(totalPages, currentPage) {
                eventPagination.innerHTML = '';
                if (totalPages <= 1) return;

                const queryParams = new URL(location.href).searchParams;

                function createPageItem(page, text, isDisabled = false, isActive = false) {
                    const li = document.createElement('li');
                    li.className = `page-item ${isDisabled ? 'disabled' : ''} ${isActive ? 'active' : ''}`;
                    const a = document.createElement('a');
                    a.className = 'page-link';
                    queryParams.set('page', page);
                    a.href = `?${queryParams.toString()}`;
                    a.textContent = text;
                    if (!isDisabled && !isActive) {
                        a.addEventListener('click', (e) => {
                            e.preventDefault();
                            currentPage = page;
                            fetchEvents(currentPage);
                        });
                    }
                    li.appendChild(a);
                    return li;
                }

                eventPagination.appendChild(createPageItem(currentPage - 1, 'Previous', currentPage <= 1));

                const pageDisplayLimit = 5;
                let startPage = Math.max(1, currentPage - Math.floor(pageDisplayLimit / 2));
                let endPage = Math.min(totalPages, startPage + pageDisplayLimit - 1);

                if (endPage - startPage + 1 < pageDisplayLimit) {
                    startPage = Math.max(1, endPage - pageDisplayLimit + 1);
                }

                if (startPage > 1) {
                    eventPagination.appendChild(createPageItem(1, '1'));
                    if (startPage > 2) {
                        const ellipsis = document.createElement('li');
                        ellipsis.className = 'page-item disabled';
                        ellipsis.innerHTML = '<span class="page-link">...</span>';
                        eventPagination.appendChild(ellipsis);
                    }
                }

                for (let i = startPage; i <= endPage; i++) {
                    eventPagination.appendChild(createPageItem(i, i, false, i === currentPage));
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) {
                        const ellipsis = document.createElement('li');
                        ellipsis.className = 'page-item disabled';
                        ellipsis.innerHTML = '<span class="page-link">...</span>';
                        eventPagination.appendChild(ellipsis);
                    }
                    eventPagination.appendChild(createPageItem(totalPages, totalPages));
                }

                eventPagination.appendChild(createPageItem(currentPage + 1, 'Next', currentPage >= totalPages));
            }

            function addEventEventListeners() {
                // Event listener for individual event status toggles
                document.querySelectorAll('.event-status-toggle').forEach(toggle => {
                    toggle.addEventListener('change', function() {
                        const eventId = this.dataset.eventId;
                        const newStatus = this.checked ? 'yes' : 'no';
                        const currentToggle = this;
                        const originalStatus = this.checked ? 'no' : 'yes'; // For reverting on error
                        const statusBadge = document.getElementById(`event_status_${eventId}`); // CORRECTED: Template literal for ID

                        const formData = new FormData();
                        formData.append('action', 'bulk_update_event_status'); // Reuse bulk action for single update
                        formData.append('event_ids', JSON.stringify([eventId]));
                        formData.append('new_status', newStatus);
                        formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id

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
                                    showToast(data.message || 'Could not update event status.', 'danger');
                                    currentToggle.checked = (originalStatus === 'yes'); // Revert toggle on error
                                }
                            })
                            .catch(error => {
                                console.error('AJAX Error:', error);
                                showToast('An error occurred while updating event status.', 'danger');
                                currentToggle.checked = (originalStatus === 'yes'); // Revert toggle on network error
                            });
                    });
                });

                // Event listener for "Manage Assignments" button
                document.querySelectorAll('.manage-assignments-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const eventId = this.dataset.eventId;
                        const eventName = this.dataset.eventName;
                        modalEventId.value = eventId;
                        modalEventName.textContent = eventName;

                        // Default to daily view for today's date when modal opens
                        const today = new Date();
                        const todayFormatted = today.toISOString().split('T')[0];
                        modalScheduleDate.value = todayFormatted;
                        currentDailyDateSpan.textContent = today.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); // Formatted date for display
                        scheduleStatusFilter.value = 'all'; // Reset slot status filter
                        modalAssignmentTypeFilter.value = 'assigned_to_event'; // Default to assigned
                        modalVenueFilter.value = ''; // Reset venue filter
                        selectAllModalSchedulesCheckbox.checked = false; // Reset inner select all

                        // Activate daily tab and fetch schedules
                        const dailyTab = document.getElementById('daily-tab');
                        const tabTrigger = new bootstrap.Tab(dailyTab);
                        tabTrigger.show();

                        // Show the modal
                        assignmentModal.show();
                    });
                });
            }

            // --- Schedule Assignment Modal Functions ---

            // Function to fetch schedules for the active tab and filters within the modal
            function fetchSchedulesForAssignmentModal() {
                const eventId = modalEventId.value;
                if (!eventId) return;

                const activeTab = document.querySelector('#assignmentTabs .nav-link.active');
                const timeRangeType = activeTab.dataset.timeRange;
                const statusFilter = scheduleStatusFilter.value;
                const assignmentType = modalAssignmentTypeFilter.value;
                const venueFilter = modalVenueFilter.value;

                let dateParam = '';
                if (timeRangeType === 'daily') {
                    dateParam = modalScheduleDate.value;
                    dailyDatePickerContainer.classList.remove('d-none');
                    // Update currentDailyDateSpan with user selected date or default
                    const selectedDate = new Date(modalScheduleDate.value);
                    currentDailyDateSpan.textContent = selectedDate.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                } else {
                    dailyDatePickerContainer.classList.add('d-none');
                }

                const params = new URLSearchParams({
                    action: 'get_schedules_for_assignment_modal',
                    event_id: eventId,
                    time_range_type: timeRangeType,
                    status_filter: statusFilter,
                    assignment_type: assignmentType,
                    date: dateParam, // Only relevant for daily view
                    venue_id_filter: venueFilter,
                    // Pass adminUserId for filtering schedules specific to the current admin's associated events
                    // This is important if an admin only manages their own events/venue schedules
                    admin_user_id: '<?php echo $adminUserId; ?>'
                });

                // Clear current tab list and show loading
                const activePaneId = activeTab.dataset.bsTarget;
                const activeScheduleListDiv = document.querySelector(`${activePaneId} .schedule-list`); // CORRECTED: Template literal for selector
                activeScheduleListDiv.innerHTML = `
                    <div class="text-center p-4">
                        <div class="spinner-border text-warning" role="status"></div>
                        <p class="text-white mt-2">Loading schedules...</p>
                    </div>
                `;
                selectAllModalSchedulesCheckbox.checked = false; // Reset select all checkbox for schedules

                fetch(`${URL_HANDLER}?${params.toString()}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            displaySchedulesForAssignmentModal(data.schedules, timeRangeType, data.stats);
                            // Only update modal's main stats if the current modal view is 'assigned_to_event' for mapped schedules
                            // and 'vacant' for vacant schedules.
                            // The modal's main stats reflect the count across ALL criteria for the event overall.
                            // This part might need further refinement based on how the main stats are calculated server-side.
                            // For now, let's assume `data.overall_mapped_schedules` and `data.overall_vacant_schedules` reflect the total for the event, not just the filtered view.
                            updateModalStats(data.overall_mapped_schedules || 0, data.overall_vacant_schedules || 0); // Assuming these are sent from server

                            // Only populate venue filter if not already showing selected venues:
                            // This logic is tricky. If populateModalVenueFilter is called on every change,
                            // selecting a venue will re-populate it with only schedules matching that venue.
                            // Better to populate it once when the modal opens with all possible venues for the event.
                            // Or, if it should dynamically show venues present in the *filtered* schedules, then this is fine.
                            // Let's keep it here for now as original code had it.
                            populateModalVenueFilter(data.venues_in_schedules);
                        } else {
                            showToast(data.message || 'Failed to fetch schedules.', 'danger');
                            activeScheduleListDiv.innerHTML = `<p class="text-center text-danger mt-3">Error: ${data.message || 'Failed to load schedules.'}</p>`;
                            updateScheduleTabStats(timeRangeType, 0, 0, 0); // Update mini-stats to 0
                            updateModalStats(data.overall_mapped_schedules || 0, data.overall_vacant_schedules || 0); // Still update overall stats if available
                            console.error('API Error:', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        showToast('Error fetching schedules: ' + error.message, 'danger');
                        activeScheduleListDiv.innerHTML = `<p class="text-center text-danger mt-3">Error fetching schedules.</p>`;
                        updateScheduleTabStats(timeRangeType, 0, 0, 0); // Update mini-stats to 0
                        // Cannot update overall modal stats on fetch error as we don't have them reliably
                    });
            }

            function displaySchedulesForAssignmentModal(schedules, timeRangeType, stats) {
                let targetListDiv;
                let totalSpan, activeSpan, assignedSpan;

                if (timeRangeType === 'daily') {
                    targetListDiv = dailyScheduleList;
                    totalSpan = dailyTotalSchedules;
                    activeSpan = dailyActiveSchedules;
                    assignedSpan = dailyAssignedSchedules;
                } else if (timeRangeType === 'weekly') {
                    targetListDiv = weeklyScheduleList;
                    totalSpan = weeklyTotalSchedules;
                    activeSpan = weeklyActiveSchedules;
                    assignedSpan = weeklyAssignedSchedules;
                } else if (timeRangeType === 'monthly') {
                    targetListDiv = monthlyScheduleList;
                    totalSpan = monthlyTotalSchedules;
                    activeSpan = monthlyActiveSchedules;
                    assignedSpan = monthlyAssignedSchedules;
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

                        const slotStatusClass = schedule.is_active === 'yes' ? 'bg-success' : 'bg-danger';
                        const slotStatusText = schedule.is_active === 'yes' ? 'Active' : 'Inactive';

                        const assignedStatusClass = schedule.event_schedule_id ? 'bg-info' : 'bg-secondary';
                        const assignedStatusText = schedule.event_schedule_id ? 'Assigned' : 'Vacant';

                        const assignmentButton = schedule.event_schedule_id ?
                            `<button type="button" class="btn btn-sm btn-danger unassign-schedule-btn" data-schedule-id="${schedule.venue_schedule_id}" data-event-schedule-id="${schedule.event_schedule_id}"><i class="fas fa-minus"></i> Unassign</button>` :
                            `<button type="button" class="btn btn-sm btn-success assign-schedule-btn" data-schedule-id="${schedule.venue_schedule_id}"><i class="fas fa-plus"></i> Assign</button>`;

                        const scheduleItem = document.createElement('div');
                        scheduleItem.className = 'schedule-list-item';
                        scheduleItem.innerHTML = `
                            <div>
                                <h6 class="mb-0 text-white">${escapeHTML(schedule.venue_name)} (${escapeHTML(schedule.sub_venue_name || 'N/A')})<br>${displayDate} ${startTime} to ${endTime}</h6>
                                <span class="badge ${slotStatusClass}" id="slot_status_${schedule.venue_schedule_id}">${slotStatusText}</span>
                                <span class="badge ${assignedStatusClass}" id="assignment_status_${schedule.venue_schedule_id}">${assignedStatusText}</span>
                            </div>
                            <div class="actions">
                                <div class="form-check form-check-inline mx-2">
                                    <input class="form-check-input bulk-schedule-checkbox" type="checkbox" value="${schedule.venue_schedule_id}" id="schedule_modal_bulk_${schedule.venue_schedule_id}">
                                    <label class="form-check-label visually-hidden" for="schedule_modal_bulk_${schedule.venue_schedule_id}">Select</label>
                                </div>
                                <label class="custom-switch me-2">
                                    <input type="checkbox" class="schedule-slot-status-toggle" data-schedule-id="${schedule.venue_schedule_id}" ${schedule.is_active === 'yes' ? 'checked' : ''}>
                                    <span class="slider round"></span>
                                </label>
                                ${assignmentButton}
                            </div>
                        `;
                        targetListDiv.appendChild(scheduleItem);
                    });
                }
                updateScheduleTabStats(timeRangeType, stats.total, stats.active, stats.mapped_to_event);
                addScheduleAssignmentEventListeners(); // Re-attach event listeners for newly added elements
            }

            function updateScheduleTabStats(timeRangeType, total, active, assigned) {
                let totalSpan, activeSpan, assignedSpan;
                if (timeRangeType === 'daily') {
                    totalSpan = dailyTotalSchedules;
                    activeSpan = dailyActiveSchedules;
                    assignedSpan = dailyAssignedSchedules;
                } else if (timeRangeType === 'weekly') {
                    totalSpan = weeklyTotalSchedules;
                    activeSpan = weeklyActiveSchedules;
                    assignedSpan = weeklyAssignedSchedules;
                } else if (timeRangeType === 'monthly') {
                    totalSpan = monthlyTotalSchedules;
                    activeSpan = monthlyActiveSchedules;
                    assignedSpan = monthlyAssignedSchedules;
                } else {
                    return;
                }
                totalSpan.textContent = total;
                activeSpan.textContent = active;
                assignedSpan.textContent = assigned;
            }

            // Function to update stats in the modal header
            function updateModalStats(mapped, vacant) {
                modalMappedSchedules.textContent = mapped;
                modalVacantSchedules.textContent = vacant;
            }

            function populateModalVenueFilter(venues) {
                modalVenueFilter.innerHTML = '<option value="">All Venues</option>';
                venues.forEach(venue => {
                    const option = document.createElement('option');
                    option.value = venue.venue_id;
                    option.textContent = `${venue.venue_name} (${venue.sub_venue_name || 'N/A'})`; // Handle sub_venue_name potentially being null
                    modalVenueFilter.appendChild(option);
                });
            }


            function addScheduleAssignmentEventListeners() {
                const currentEventId = modalEventId.value;

                // Event listener FOR INDIVIDUAL VENUE_SCHEDULE STATUS TOGGLES
                // This updates the is_active status of the venue_schedule slot itself.
                document.querySelectorAll('.schedule-slot-status-toggle').forEach(toggle => {
                    toggle.addEventListener('change', function() {
                        const scheduleId = this.dataset.scheduleId;
                        const newStatus = this.checked ? 'yes' : 'no';
                        const currentToggle = this;
                        const originalStatus = this.checked ? 'no' : 'yes';
                        const statusBadge = document.getElementById(`slot_status_${scheduleId}`); // CORRECTED: Template literal for ID

                        const formData = new FormData();
                        formData.append('action', 'bulk_update_schedule_status'); // This action could exist in schedule_handler.php or be duplicated if specific logic is needed. Assuming it's in this handler for simplicity.
                        formData.append('schedule_ids', JSON.stringify([scheduleId]));
                        formData.append('new_status', newStatus);
                        formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id to the handler

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
                                    fetchSchedulesForAssignmentModal(); // Refresh schedules in current tab to update counts
                                } else {
                                    showToast(data.message || 'Could not update schedule slot status.', 'danger');
                                    currentToggle.checked = (originalStatus === 'yes');
                                }
                            })
                            .catch(error => {
                                console.error('AJAX Error:', error);
                                showToast('An error occurred while updating schedule slot status.', 'danger');
                                currentToggle.checked = (originalStatus === 'yes');
                            });
                    });
                });


                // Event listener for Assign button
                document.querySelectorAll('.assign-schedule-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const scheduleId = this.dataset.scheduleId;
                        if (confirm('Are you sure you want to assign this schedule slot?')) {
                            const formData = new FormData();
                            formData.append('action', 'assign_schedule_to_event');
                            formData.append('event_id', currentEventId);
                            formData.append('venue_schedule_id', scheduleId);
                            formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id to the handler

                            fetch(URL_HANDLER, { method: 'POST', body: formData })
                                .then(response => response.json())
                                .then(data => {
                                    showToast(data.message, data.success ? 'success' : 'danger');
                                    if (data.success) fetchSchedulesForAssignmentModal();
                                })
                                .catch(error => {
                                    console.error('Assign schedule error:', error);
                                    showToast('An error occurred during assignment.', 'danger');
                                });
                        }
                    });
                });

                // Event listener for Unassign button
                document.querySelectorAll('.unassign-schedule-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const scheduleId = this.dataset.scheduleId;
                        const eventScheduleId = this.dataset.eventScheduleId;
                        if (confirm('Are you sure you want to unassign this schedule slot?')) {
                            const formData = new FormData();
                            formData.append('action', 'unassign_schedule_from_event');
                            formData.append('event_id', currentEventId);
                            formData.append('venue_schedule_id', scheduleId);
                            formData.append('event_schedule_id', eventScheduleId); // Pass the explicit event_schedule_id
                            formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id to the handler

                            fetch(URL_HANDLER, { method: 'POST', body: formData })
                                .then(response => response.json())
                                .then(data => {
                                    showToast(data.message, data.success ? 'success' : 'danger');
                                    if (data.success) fetchSchedulesForAssignmentModal();
                                })
                                .catch(error => {
                                    console.error('Unassign schedule error:', error);
                                    showToast('An error occurred during unassignment.', 'danger');
                                });
                        }
                    });
                });
            }

            // --- Event Listeners and Initial Load ---

            // Event filter form submission
            eventFilterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                currentPage = 1; // Reset to first page on new filter
                fetchEvents();
            });

            // Clear event filters
            clearEventFiltersBtn.addEventListener('click', function() {
                searchInput.value = '';
                filterCitySelect.value = '';
                filterStatusSelect.value = '';
                startDateInput.value = '';
                endDateInput.value = '';
                currentPage = 1;
                fetchEvents();
            });

            // Select All Events Checkbox
            selectAllEventsCheckbox.addEventListener('change', function() {
                document.querySelectorAll('.bulk-event-checkbox').forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });

            // Bulk actions for Events
            bulkActivateEventsBtn.addEventListener('click', function() {
                const selectedIds = Array.from(document.querySelectorAll('.bulk-event-checkbox:checked')).map(cb => cb.value);
                if (selectedIds.length === 0) {
                    showToast('Please select at least one event.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to activate ${selectedIds.length} selected events?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_update_event_status');
                    formData.append('event_ids', JSON.stringify(selectedIds));
                    formData.append('new_status', 'yes');
                    formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id

                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchEvents(); // Refresh list on success
                        })
                        .catch(error => {
                            console.error('Bulk activate events error:', error);
                            showToast('An error occurred during bulk activation.', 'danger');
                        });
                }
            });

            bulkDeactivateEventsBtn.addEventListener('click', function() {
                const selectedIds = Array.from(document.querySelectorAll('.bulk-event-checkbox:checked')).map(cb => cb.value);
                if (selectedIds.length === 0) {
                    showToast('Please select at least one event.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to deactivate ${selectedIds.length} selected events?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_update_event_status');
                    formData.append('event_ids', JSON.stringify(selectedIds));
                    formData.append('new_status', 'no');
                    formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id

                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchEvents();
                        })
                        .catch(error => {
                            console.error('Bulk deactivate events error:', error);
                            showToast('An error occurred during bulk deactivation.', 'danger');
                        });
                }
            });

            // Assignment Modal Events
            assignmentModal._element.addEventListener('shown.bs.modal', fetchSchedulesForAssignmentModal);

            // Tab changes within schedule modal
            assignmentTabs.addEventListener('shown.bs.tab', function(event) {
                fetchSchedulesForAssignmentModal();
            });

            // Date change in daily view in modal
            modalScheduleDate.addEventListener('change', fetchSchedulesForAssignmentModal);
            // Status filter change in modal
            scheduleStatusFilter.addEventListener('change', fetchSchedulesForAssignmentModal);
            // Assignment Type filter change in modal
            modalAssignmentTypeFilter.addEventListener('change', fetchSchedulesForAssignmentModal);
            // Venue filter change in modal
            modalVenueFilter.addEventListener('change', fetchSchedulesForAssignmentModal);


            // Select All Schedules Checkbox in modal
            selectAllModalSchedulesCheckbox.addEventListener('change', function() {
                const activeTab = document.querySelector('#assignmentTabs .nav-link.active');
                const activePaneId = activeTab.dataset.bsTarget;
                document.querySelectorAll(`${activePaneId} .bulk-schedule-checkbox`).forEach(checkbox => { // CORRECTED: Template literal for selector
                    checkbox.checked = this.checked;
                });
            });

            // Bulk actions for schedules within the modal
            function getSelectedAssignmentScheduleIds() {
                const activeTab = document.querySelector('#assignmentTabs .nav-link.active');
                const activePaneId = activeTab.dataset.bsTarget;
                return Array.from(document.querySelectorAll(`${activePaneId} .bulk-schedule-checkbox:checked`)).map(cb => cb.value); // CORRECTED: Template literal for selector
            }

            bulkAssignSchedulesBtn.addEventListener('click', function() {
                const selectedIds = getSelectedAssignmentScheduleIds();
                const currentEventId = modalEventId.value;
                if (selectedIds.length === 0) {
                    showToast('Please select at least one schedule slot.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to assign ${selectedIds.length} selected schedule slots to this event?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_assign_schedules_to_event');
                    formData.append('event_id', currentEventId);
                    formData.append('venue_schedule_ids', JSON.stringify(selectedIds));
                    formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id

                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchSchedulesForAssignmentModal();
                        })
                        .catch(error => {
                            console.error('Bulk assign schedules error:', error);
                            showToast('An error occurred during bulk assignment.', 'danger');
                        });
                }
            });

            bulkUnassignSchedulesBtn.addEventListener('click', function() {
                const selectedIds = getSelectedAssignmentScheduleIds();
                const currentEventId = modalEventId.value;
                if (selectedIds.length === 0) {
                    showToast('Please select at least one schedule slot.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to unassign ${selectedIds.length} selected schedule slots from this event?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_unassign_schedules_from_event');
                    formData.append('event_id', currentEventId);
                    formData.append('venue_schedule_ids', JSON.stringify(selectedIds));
                    formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id

                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchSchedulesForAssignmentModal();
                        })
                        .catch(error => {
                            console.error('Bulk unassign schedules error:', error);
                            showToast('An error occurred during bulk unassignment.', 'danger');
                        });
                }
            });

            bulkActivateScheduleSlotBtn.addEventListener('click', function() {
                const selectedIds = getSelectedAssignmentScheduleIds();
                if (selectedIds.length === 0) {
                    showToast('Please select at least one schedule slot.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to activate ${selectedIds.length} selected schedule slots?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_update_schedule_status');
                    formData.append('schedule_ids', JSON.stringify(selectedIds));
                    formData.append('new_status', 'yes');
                    formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id

                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchSchedulesForAssignmentModal();
                        })
                        .catch(error => {
                            console.error('Bulk activate schedule slots error:', error);
                            showToast('An error occurred during bulk activation of slots.', 'danger');
                        });
                }
            });

            bulkDeactivateScheduleSlotBtn.addEventListener('click', function() {
                const selectedIds = getSelectedAssignmentScheduleIds();
                if (selectedIds.length === 0) {
                    showToast('Please select at least one schedule slot.', 'info');
                    return;
                }
                if (confirm(`Are you sure you want to deactivate ${selectedIds.length} selected schedule slots?`)) {
                    const formData = new FormData();
                    formData.append('action', 'bulk_update_schedule_status');
                    formData.append('schedule_ids', JSON.stringify(selectedIds));
                    formData.append('new_status', 'no');
                    formData.append('admin_user_id', '<?php echo $adminUserId; ?>'); // Pass admin_user_id

                    fetch(URL_HANDLER, { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            showToast(data.message, data.success ? 'success' : 'danger');
                            if (data.success) fetchSchedulesForAssignmentModal();
                        })
                        .catch(error => {
                            console.error('Bulk deactivate schedule slots error:', error);
                            showToast('An error occurred during bulk deactivation of slots.', 'danger');
                        });
                }
            });


            // Initial fetch on page load
            fetchEvents();
        });
    </script>
</body>
</html>