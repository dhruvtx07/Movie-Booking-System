<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('Asia/Kolkata');

require_once '../links.php'; // Contains paths ($login_page etc.)
require_once 'config/db_config.php'; // Contains $pdo db connection

// Login check from dashboard.php
$auth_pages = [$login_page, $register_page, $forgot_pass]; 
$current_page = basename($_SERVER['PHP_SELF']);

if (!isset($_SESSION['user_id'])) {
    if (!in_array($current_page, $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// User ID from session (matching dashboard.php's structure)
$adminUserId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// --- Configuration ---
$items_per_page = 8; // Number of venues per page

// --- Fetch Initial Data for Filters ---
function getCities($pdo) {
    try {
        $stmt = $pdo->query("SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error
        // In a production environment, you might log this to a file and not display it.
        // For development, displaying it can be helpful.
        error_log("Database error fetching cities: " . $e->getMessage());
        return [];
    }
}
$cities = getCities($pdo);

// Clear message after display if any (from dashboard.php pattern)
if (isset($_SESSION['message'])) {
    unset($_SESSION['message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venue Ticket Management - Catchify</title>
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
            --section-subtitle-font: 1.5rem;
            --metric-card-display-4-font: 2.5rem; /* Not directly used but kept for theme consistency */
            --metric-card-h5-font: 1.1rem; /* Not directly used but kept for theme consistency */
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

        /* Main Content Area */
        .main-content {
            flex-grow: 1; /* Take up remaining space AFTER padding */
            padding: 20px; /* Adjusted from original index.php's 25px */
        }

        /* General Styles */
        .container-fluid { /* Used container-fluid as it's common in this layout, but restrict max-width */
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--accent-red); /* Changed from --color-secondary to accent-red */
        }
        .page-title {
            color: var(--accent-orange); /* Changed from --color-accent to accent-orange */
            margin: 0;
            font-size: var(--section-title-font); /* Consistent with dashboard.php */
        }

        /* Forms, Buttons, Alerts (from dashboard.php general styles) */
        .form-control, .form-select {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
            border-radius: 5px;
        }
        .form-control::placeholder {
            color: var(--light-text-color);
            opacity: 0.7;
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border-color: var(--accent-orange);
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
        }
        .form-label { 
            color: var(--light-text-color); 
            font-size: 0.9em; /* Smaller label font consistent with original index.php style */
        }

        /* Buttons styles, adapted for 'index.php' custom classes */
        .btn-filter, .btn-primary-custom { /* Combined from original index.php and dashboard.php */
            background: var(--gradient-primary);
            color: white;
            border: none;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-filter:hover, .btn-primary-custom:hover {
            background: var(--gradient-secondary); /* Changes on hover */
            color: white;
            opacity: 1; 
            transform: translateY(-2px); /* Lift effect */
            box-shadow: 0 4px 10px rgba(229, 9, 20, 0.4); /* Stronger shadow */
        }
        .btn-secondary-custom { /* From original index.php, adapted to dashboard theme */
            background: var(--gradient-secondary);
            color: var(--primary-bg); /* Text color for contrast on gradient */
            border: none;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-secondary-custom:hover {
            opacity: 0.9; 
            background: var(--gradient-primary); /* Reverse gradient on hover */
            color: var(--primary-bg);
        }
        .btn-danger-custom { /* Adapted to dashboard theme */
            background-color: var(--accent-red);
            color: white;
            border: none;
            transition: all 0.3s ease;
        }
        .btn-danger-custom:hover {
            opacity: 0.9; 
            background-color: #b71c1c; /* Slightly darker red */
        }
        .btn-sm-custom { 
            font-size: 0.8rem; 
            padding: 0.2rem 0.5rem; 
        }

        /* Filters Bar (from original index.php, restyled) */
        .filters-bar {
            background-color: var(--secondary-bg); /* Changed from --color-surface-light */
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        /* Venue Cards (from original index.php, restyled) */
        .venue-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .venue-card {
            background-color: var(--secondary-bg); /* Changed from --color-surface */
            border: 1px solid var(--card-border); /* Changed from --color-border */
            border-left: 4px solid var(--accent-orange); /* Changed from --color-secondary to accent-orange */
            border-radius: 6px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.4);
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-left-color 0.25s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
        }
        .venue-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.5);
            border-left-color: var(--accent-red); /* Changed from --color-primary to accent-red */
        }
        .venue-card-header h6 {
            color: var(--accent-peach); /* Changed from --color-peach */
            margin-bottom: 3px;
            font-size: 1.1em;
        }
        .venue-card-header p {
            color: var(--light-text-color); /* Changed from --color-text-secondary */
            font-size: 0.8em;
            margin-bottom: 10px;
        }
        .venue-stats {
            font-size: 0.75em;
            color: var(--light-text-color); /* Changed from --color-text-secondary */
            margin-bottom: 10px;
            flex-grow: 1;
        }
        .venue-stats .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            border-bottom: 1px dashed var(--card-border); /* Adjusted to card-border */
        }
        .venue-stats .stat-value { 
            color: var(--text-color); 
            font-weight: 600; 
        }
        .venue-card .active-status {
            font-size: 0.7em;
            padding: 2px 6px;
            border-radius: 4px;
            float: right;
        }
        .active-status.yes { background-color: #28a745; color: white; } /* Specific green */
        .active-status.no { background-color: var(--accent-red); color: white; } /* Uses accent-red */

        /* Pagination (from dashboard.php, restyled) */
        .pagination .page-item .page-link {
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
            color: var(--accent-peach);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--accent-orange);
            border-color: var(--accent-orange);
            color: var(--primary-bg); /* Changed text color for contrast */
        }
        .pagination .page-item.disabled .page-link {
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
            color: var(--light-text-color);
        }
        .pagination .page-item .page-link:hover {
            background-color: var(--accent-orange);
            color: var(--primary-bg);
            opacity:0.8;
        }

        /* Ticket Management Modal (from original index.php, restyled) */
        .ticket-management-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.85); /* Darker overlay */
            z-index: 1100;
            overflow-y: auto;
            padding: 20px;
            align-items: center;
            justify-content: center;
        }
        .ticket-management-content {
            background-color: var(--secondary-bg); /* Changed from --color-surface */
            padding: 20px;
            border-radius: 10px;
            width: 95%;
            max-width: 1300px;
            box-shadow: 0 0 40px rgba(0,0,0,0.6);
            border-top: 5px solid var(--accent-red); /* Changed from --color-primary */
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .tm-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--accent-yellow); /* Changed from --color-accent to accent-yellow */
            border-bottom: 1px solid var(--card-border); /* Changed from --color-border */
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .tm-header h4 { margin: 0; font-size: 1.5rem; }
        .close-tm-modal {
            font-size: 2rem;
            color: var(--light-text-color); /* Changed from --color-text-secondary */
            cursor: pointer;
            transition: color 0.2s ease, transform 0.2s ease;
            background:none; border:none; padding:0 10px;
        }
        .close-tm-modal:hover { 
            color: var(--accent-red); /* Uses accent-red */
            transform: rotate(90deg); 
        }

        .tm-body {
            overflow-y: auto;
            flex-grow: 1;
        }
        .tm-controls-column {
            background-color: var(--secondary-bg); /* Changed from --color-surface-light */
            padding: 15px;
            border-radius: 8px;
            height: 100%;
            max-height: calc(90vh - 150px);
            overflow-y: auto;
        }
        
        .tm-section-title {
            color: var(--accent-peach); /* Changed from --color-peach */
            font-size: 1.1rem;
            margin-top: 15px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--card-border); /* Changed from --color-border */
        }
        .tm-section-title:first-child { margin-top: 0;}

        /* Ticket Map Visualizer (from original index.php, restyled) */
        .ticket-map-container {
            background-color: var(--primary-bg); /* Changed from --color-bg */
            padding: 10px;
            border-radius: 6px;
            border: 1px solid var(--card-border); /* Changed from --color-border */
            margin-bottom: 15px;
            overflow: auto;
            min-height: 300px;
        }
        .ticket-map-grid {
            display: grid;
            gap: 4px;
            align-items: center;
            justify-content: flex-start;
        }
        .map-seat, .map-row-label, .map-col-label {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 3px;
            font-size: 0.65em;
            font-weight: bold;
            transition: background-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
        }
        .map-row-label, .map-col-label {
            background-color: transparent;
            color: var(--light-text-color); /* Changed from --color-text-secondary */
            width: 25px;
        }
        .map-col-label { height: 25px; }
        .map-placeholder { width: 25px; height: 25px; }

        .map-seat {
            background-color: var(--secondary-bg); /* Changed from --color-surface-light */
            border: 1px solid var(--card-border); /* Changed from --color-border */
            color: var(--light-text-color); /* Changed from --color-text-secondary */
            cursor: pointer;
        }
        /* Seat Type Specific Colors (from original index.php, adapted to dashboard theme) */
        .map-seat.generated.type-regular { background-color: #555; color:var(--text-color); border-color: #666; }
        .map-seat.generated.type-premium { background-color: var(--accent-peach); color:var(--primary-bg); border-color: #ffc099;}
        .map-seat.generated.type-vip { background-color: var(--accent-yellow); color:var(--primary-bg); border-color: #e6c300;}
        .map-seat.generated.type-recliner { background-color: var(--accent-orange); color:var(--primary-bg); border-color: #cc7000;}
        .map-seat.generated.type-box { background-color: var(--accent-red); color:var(--text-color); border-color: #b80710;}
            
        .map-seat.selected, .map-seat.filtered-highlight {
            transform: scale(1.1);
            box-shadow: 0 0 12px var(--accent-yellow); /* Changed from --color-glow to accent-yellow */
            border: 1px solid var(--accent-yellow); /* Changed from --color-accent to accent-yellow */
            z-index: 10;
        }
        /* For seats that are just filtered, not actively selected */
        .map-seat.filtered-highlight:not(.selected) {
            box-shadow: 0 0 8px rgba(255, 107, 53, 0.6); /* Uses accent-orange with opacity */
            border: 1px solid var(--accent-orange); /* Uses accent-orange */
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

        /* Contextual CRUD Forms for Tickets (from original index.php, restyled) */
        #ticketActionButtons button { margin-right: 10px; margin-bottom: 10px; }

        #updateTicketFormContainer {
            background-color: var(--secondary-bg); /* Changed from --color-surface */
            padding:15px; border-radius: 6px; margin-top:15px;
            border: 1px solid var(--accent-orange); /* Changed from --color-secondary to accent-orange */
        }
        
        /* Small Stats Display in Modal (from original index.php, restyled) */
        .ticket-stats-display-modal {
            background-color: var(--secondary-bg); /* Changed from --color-surface-light */
            padding: 10px;
            border-radius: 6px;
            font-size: 0.8em;
            border:1px solid var(--card-border); /* Changed from --color-border */
        }
        .ticket-stats-display-modal h6 { 
            color: var(--accent-peach); /* Changed from --color-peach */
            font-size: 1.1em; 
        }
        .ticket-stats-display-modal p { margin-bottom: 3px; }
        .ticket-stats-display-modal strong { 
            color: var(--accent-yellow); /* Changed from --color-accent to accent-yellow */
        }

        /* Toast Notifications (from original index.php, restyled) */
        .toast-container { z-index: 1200; }
        .toast {
            background-color: var(--secondary-bg) !important; /* Changed from --color-surface-light */
            color: var(--text-color) !important; /* Changed from --color-text */
            border: 1px solid var(--card-border) !important; /* Changed from --color-border */
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .toast-header {
            background-color: var(--primary-bg) !important; /* Changed from --color-surface */
            color: var(--accent-peach) !important; /* Changed from --color-peach */
            border-bottom: 1px solid var(--card-border) !important; /* Changed from --color-border */
        }
        .toast .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }

        /* Loading Spinner (from original index.php, restyled) */
        .spinner-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 150px;
        }
        .spinner-border-custom {
            color: var(--accent-orange); /* Changed from --color-secondary */
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
            /* .filter-group-spacing etc. from dashboard.php not directly applicable here but illustrate theme */
            .metric-card { /* Not used in this particular index.php content, but part of theme */
                padding: 15px; 
            }
            .list-group-item { /* Not used in this particular index.php content, but part of theme */
                padding: 8px 12px; 
                font-size: 0.9rem;
            }
            th, td { /* Not used in this particular index.php content, but part of theme */
                padding: 10px 12px; 
                font-size: 0.8rem;
            }
            
            .footer {
                padding: 15px; 
                text-align: center; 
            }
            .footer .col-md-6 {
                text-align: center !important; 
            }
            .footer .row {
                flex-direction: column; 
            }
            .footer .col-md-6:first-child {
                margin-bottom: 10px; 
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
              <a class="nav-link" href="index.php" title="Venue Ticket Types">
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
    <!-- Main Content (original content from index.php) -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-cogs"></i> Venue & Ticket Management</h1>
            </div>

            <!-- Filters Bar -->
            <div class="filters-bar">
                <form id="venueFiltersForm" class="row gx-2 gy-2 align-items-end">
                    <div class="col-md-4">
                        <label for="searchTerm" class="form-label">Search Venue</label>
                        <input type="text" class="form-control form-control-sm" id="searchTerm" name="search" placeholder="Venue name or sub-venue...">
                    </div>
                    <div class="col-md-3">
                        <label for="cityFilter" class="form-label">City</label>
                        <select class="form-select form-select-sm" id="cityFilter" name="city_id">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?= htmlspecialchars($city['city_id']) ?>"><?= htmlspecialchars($city['city_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="activeFilter" class="form-label">Status</label>
                        <select class="form-select form-select-sm" id="activeFilter" name="is_active">
                            <option value="">All</option>
                            <option value="yes" selected>Active</option>
                            <option value="no">Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-filter btn-sm w-100"><i class="fas fa-filter"></i> Apply</button>
                    </div>
                </form>
            </div>

            <!-- Venue Grid - Populated by JS -->
            <div id="venueGridContainer" class="venue-grid">
                <!-- Venues will be loaded here -->
            </div>
            <div id="venueGridPlaceholder" class="spinner-container" style="display:none;">
                <div class="spinner-border spinner-border-custom" role="status"><span class="visually-hidden">Loading venues...</span></div>
            </div>


            <!-- Pagination - Populated by JS -->
            <nav aria-label="Venue Pagination">
                <ul class="pagination justify-content-center" id="venuePagination">
                    <!-- Pagination links will be loaded here -->
                </ul>
            </nav>
        </div>
    </main>

    <!-- Ticket Management Modal (original content from index.php) -->
    <div class="ticket-management-modal" id="ticketManagementModal">
        <div class="ticket-management-content">
            <div class="tm-header">
                <h4 id="tmVenueNameModal">Venue Ticket Management</h4>
                <button class="close-tm-modal" id="closeTicketManagementModal" aria-label="Close">&times;</button>
            </div>

            <div class="tm-body">
                <div class="row g-3">
                    <!-- Left Column: Map and Map Filters -->
                    <div class="col-lg-8">
                        <div class="ticket-map-filters mb-3 p-2 rounded" style="background-color: var(--secondary-bg);">
                            <form id="ticketMapFiltersForm" class="row gx-2 gy-2 align-items-center">
                                <div class="col-md-3">
                                    <select class="form-select form-select-sm" name="map_ticket_type">
                                        <option value="">All Types</option>
                                        <option value="Regular">Regular</option>
                                        <option value="Premium">Premium</option>
                                        <option value="VIP">VIP</option>
                                        <option value="Recliner">Recliner</option>
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
                                    <button type="submit" class="btn btn-secondary-custom btn-sm w-100">Filter Map</button>
                                </div>
                            </form>
                        </div>
                        
                        <div class="legend mb-2">
                            <div class="legend-item"><span class="legend-color" style="background-color: #555;"></span>Regular</div>
                            <div class="legend-item"><span class="legend-color" style="background-color: var(--accent-peach);"></span>Premium</div>
                            <div class="legend-item"><span class="legend-color" style="background-color: var(--accent-yellow);"></span>VIP</div>
                            <div class="legend-item"><span class="legend-color" style="background-color: var(--accent-orange);"></span>Recliner</div>
                            <div class="legend-item"><span class="legend-color" style="background-color: var(--accent-red);"></span>Box</div>
                            <div class="legend-item"><span class="map-seat selected" style="width:15px; height:15px;font-size:0.5em;line-height:1;"></span>Selected</div>
                            <div class="legend-item"><span class="map-seat not-vacant" style="width:15px; height:15px; font-size:0.5em;line-height:1; opacity:0.6;"></span>N/A</div>
                        </div>

                        <div class="ticket-map-container" id="ticketMapContainer">
                            <div class="spinner-container">
                                <div class="spinner-border spinner-border-custom" role="status"><span class="visually-hidden">Loading map...</span></div>
                            </div>
                        </div>
                        <div id="ticketActionButtons" class="mt-2">
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
                                </div>
                                <button type="submit" class="btn btn-primary-custom btn-sm mt-2">Apply Changes</button>
                                <button type="button" id="cancelUpdateTicket" class="btn btn-outline-secondary btn-sm mt-2">Cancel</button>
                            </form>
                        </div>

                    </div>

                    <!-- Right Column: Stats and Forms -->
                    <div class="col-lg-4">
                        <div class="tm-controls-column">
                            <div class="ticket-stats-display-modal mb-3" id="venueTicketStatsModal">
                                <h6><i class="fas fa-chart-pie"></i> Ticket Statistics</h6>
                                <p>Total: <strong id="statModalTotal">0</strong> | Available: <strong id="statModalAvailable">0</strong></p>
                                <div id="statModalByType"></div>
                                <div id="statModalByPrice"></div>
                            </div>
                            
                            <h6 class="tm-section-title mt-3"><i class="fas fa-plus-circle"></i> Generate Single Ticket</h6>
                            <form id="generateSingleTicketFormModal">
                                <input type="hidden" id="single_venue_id_modal" name="venue_id">
                                <div class="mb-2">
                                    <label for="single_ticket_row_modal" class="form-label">Row (A-Z)</label>
                                    <input type="text" class="form-control form-control-sm" id="single_ticket_row_modal" name="ticket_row" maxlength="3" required>
                                </div>
                                <div class="mb-2">
                                    <label for="single_ticket_column_modal" class="form-label">Column (1-99)</label>
                                    <input type="number" class="form-control form-control-sm" id="single_ticket_column_modal" name="ticket_column" min="1" required>
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

                            <h6 class="tm-section-title mt-3"><i class="fas fa-layer-group"></i> Generate Bulk Tickets</h6>
                            <form id="generateBulkTicketFormModal">
                                <input type="hidden" id="bulk_venue_id_modal" name="venue_id">
                                <div class="row gx-2 mb-1">
                                    <div class="col"><label for="bulk_row_start_modal" class="form-label">Row Start</label><input type="text" class="form-control form-control-sm" id="bulk_row_start_modal" name="row_start" maxlength="1" required placeholder="A"></div>
                                    <div class="col"><label for="bulk_row_end_modal" class="form-label">Row End</label><input type="text" class="form-control form-control-sm" id="bulk_row_end_modal" name="row_end" maxlength="1" required placeholder="J"></div>
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
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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

<!-- Toast Container for Notifications -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <!-- Toasts will be appended here -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log("DEBUG: DOMContentLoaded event fired. Script starting.");

    // --- Global Variables & Elements ---
    const venueFiltersForm = document.getElementById('venueFiltersForm');
    const venueGridContainer = document.getElementById('venueGridContainer');
    const venueGridPlaceholder = document.getElementById('venueGridPlaceholder');
    const venuePagination = document.getElementById('venuePagination');
        
    const ticketManagementModal = document.getElementById('ticketManagementModal');
    const closeTicketManagementModal = document.getElementById('closeTicketManagementModal');
    const tmVenueNameModal = document.getElementById('tmVenueNameModal');
    const ticketMapContainer = document.getElementById('ticketMapContainer');
        
    const singleVenueIdModalInput = document.getElementById('single_venue_id_modal');
    const bulkVenueIdModalInput = document.getElementById('bulk_venue_id_modal');
    const generateSingleTicketFormModal = document.getElementById('generateSingleTicketFormModal');
    const generateBulkTicketFormModal = document.getElementById('generateBulkTicketFormModal');
    const bulkPreviewModalDiv = document.getElementById('bulkPreviewModal');
        
    const ticketMapFiltersForm = document.getElementById('ticketMapFiltersForm');
    const btnUpdateSelectedTickets = document.getElementById('btnUpdateSelectedTickets');
    const btnDeleteSelectedTickets = document.getElementById('btnDeleteSelectedTickets');
    const updateTicketFormContainer = document.getElementById('updateTicketFormContainer');
    const updateTicketForm = document.getElementById('updateTicketForm');
    const cancelUpdateTicketBtn = document.getElementById('cancelUpdateTicket');


    let currentVenueIdForModal = null;
    let currentMapTickets = [];
    let selectedTicketIds = new Set();
    let venueMapMaxRow = 'J', venueMapMaxCol = 20; // Initial (default) values

    const itemsPerPage = <?php echo $items_per_page; ?>;
    let currentPage = 1;

    // --- Toast Notification Function ---
    let toastCounter = 0;
    function showToast(message, type = 'info') {
        const toastId = 'toast-' + toastCounter++;
        const toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            console.error("DEBUG: Toast container not found!");
            return;
        }
        let headerClass = 'text-info';
        let iconClass = 'fa-info-circle';
        if (type === 'success') { headerClass = 'text-success'; iconClass = 'fa-check-circle'; }
        else if (type === 'error') { headerClass = 'text-danger'; iconClass = 'fa-exclamation-triangle'; }

        const toastHTML = `
            <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">
                <div class="toast-header">
                    <i class="fas ${iconClass} me-2"></i>
                    <strong class="me-auto ${headerClass}">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">${message}</div>
            </div>`;
        toastContainer.insertAdjacentHTML('beforeend', toastHTML);
        const toastElement = document.getElementById(toastId);
        if (toastElement) {
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            toastElement.addEventListener('hidden.bs.toast', () => toastElement.remove());
        } else {
            console.error(`DEBUG: Toast element with ID ${toastId} not found after insertion.`);
        }
    }

    // --- Sidebar Toggle (from dashboard.php) ---
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

    // --- Active Link and Submenu Management ---
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

    navLinks.forEach(link => {
        const linkHref = link.getAttribute('href');
        if (!linkHref || linkHref.startsWith('#')) return; 

        const currentFilename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
        const linkFilename = linkHref.substring(linkHref.lastIndexOf('/') + 1);

        if (linkFilename === currentFilename) {
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
            // If the current page is "dashboard.php", ensure its link is 'active' (redundant if already handled, but safe)
            if (currentFilename === 'dashboard.php' && linkFilename === 'dashboard.php') {
                document.querySelector('a[href="dashboard.php"]').classList.add('active');
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
    // --- End Sidebar Code ---

    async function fetchVenues(page = 1) {
        console.log("DEBUG: fetchVenues called for page:", page);
        venueGridPlaceholder.style.display = 'flex';
        venueGridContainer.innerHTML = '';  
        venuePagination.innerHTML = '';

        const formData = new FormData(venueFiltersForm);
        const params = new URLSearchParams(formData);
        params.append('action', 'get_venues');
        params.append('page', page);
        params.append('limit', itemsPerPage);

        try {
            const response = await fetch(`ticket_handler.php?${params.toString()}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();

            venueGridPlaceholder.style.display = 'none';
            if (data.success) {
                renderVenueCards(data.venues);
                renderPagination(data.total_venues, page, data.total_pages);
                if(!data.venues || data.venues.length === 0){
                    venueGridContainer.innerHTML = '<p class="text-center text-muted w-100">No venues found matching your criteria.</p>';
                }
            } else {
                showToast(data.message || 'Failed to load venues.', 'error');
                venueGridContainer.innerHTML = `<p class="text-center text-danger w-100">Error loading venues: ${data.message || 'Unknown error'}</p>`;
            }
        } catch (error) {
            venueGridPlaceholder.style.display = 'none';
            console.error('DEBUG: Error fetching venues:', error);
            showToast('Client-side error fetching venues: ' + error.message, 'error');
            venueGridContainer.innerHTML = '<p class="text-center text-danger w-100">Client-side error loading venues. Check console.</p>';
        }
    }

    function renderVenueCards(venues) {
        console.log("DEBUG: renderVenueCards called with venues:", venues);
        if (!venues || venues.length === 0) {
            console.warn("DEBUG: No venues data to render or venues array is empty.");
            return;
        }
        venues.forEach(venue => {
            const activeStatus = venue.is_active === 'yes' 
                ? '<span class="active-status yes">Active</span>' 
                : '<span class="active-status no">Inactive</span>';
            const cardHTML = `
                <div class="venue-card" data-venue-id="${htmlspecialchars(venue.venue_id)}" data-venue-name="${htmlspecialchars(venue.venue_name || '')} - ${htmlspecialchars(venue.sub_venue_name || '')}" data-capacity="${htmlspecialchars(venue.capacity || 0)}">
                    ${activeStatus}
                    <div class="venue-card-header">
                        <h6>${htmlspecialchars(venue.venue_name || 'N/A')} <small class="text-muted">(${htmlspecialchars(venue.sub_venue_name || 'N/A')})</small></h6>
                        <p><i class="fas fa-map-marker-alt fa-xs"></i> ${htmlspecialchars(venue.city_name || 'N/A')}</p>
                    </div>
                    <div class="venue-stats">
                        <div class="stat-item"><span>Capacity:</span> <span class="stat-value">${htmlspecialchars(venue.capacity || 'N/A')}</span></div>
                        <div class="stat-item"><span>Tickets Total:</span> <span class="stat-value">${htmlspecialchars(venue.total_tickets || 0)}</span></div>
                        <div class="stat-item"><span>Tickets Avail:</span> <span class="stat-value">${htmlspecialchars(venue.available_tickets || 0)}</span></div>
                    </div>
                </div>`;
            venueGridContainer.insertAdjacentHTML('beforeend', cardHTML);
        });
            
        const cards = venueGridContainer.querySelectorAll('.venue-card');
        console.log(`DEBUG: Attaching click listeners to ${cards.length} venue cards.`);
        cards.forEach(card => {
            card.addEventListener('click', handleVenueCardClick);
        });
    }

    function renderPagination(totalItems, currentPage, totalPages) {
        if (totalPages <= 1) {
            venuePagination.innerHTML = '';
            return;
        }
        let paginationHTML = '';
        paginationHTML += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" data-page="${currentPage - 1}" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a></li>`;
            
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

        paginationHTML += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
            <a class="page-link" href="#" data-page="${currentPage + 1}" aria-label="Next"><span aria-hidden="true">&raquo;</span></a></li>`;
            
        venuePagination.innerHTML = paginationHTML;
    }

    if (venueFiltersForm) {
        venueFiltersForm.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log("DEBUG: Venue filters form submitted.");
            currentPage = 1;
            fetchVenues(currentPage);
        });
    } else {
        console.error("DEBUG: venueFiltersForm not found!");
    }

    if (venuePagination) {
        venuePagination.addEventListener('click', function(e) {
            e.preventDefault();
            const link = e.target.closest('.page-link');
            if (link && link.dataset.page) {
                const page = parseInt(link.dataset.page);
                if (!isNaN(page) && page !== currentPage && page > 0 && !link.closest('.page-item.disabled')) {
                    console.log("DEBUG: Pagination link clicked for page:", page);
                    currentPage = page;
                    fetchVenues(currentPage);
                }
            }
        });
    } else {
        console.error("DEBUG: venuePagination element not found!");
    }
        
    function htmlspecialchars(str) {
        if (typeof str !== 'string') return String(str);
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return str.replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    function handleVenueCardClick() {
        console.log("DEBUG: handleVenueCardClick triggered for venue ID:", this.dataset.venueId, "- Name:", this.dataset.venueName);
        if (!this.dataset.venueId) {
            console.error("DEBUG: Clicked venue card is missing data-venue-id attribute!");
            showToast("Error: Venue information is missing. Cannot open details.", "error");
            return;
        }
        currentVenueIdForModal = this.dataset.venueId;
        const venueName = this.dataset.venueName;

        tmVenueNameModal.textContent = venueName;
        if(singleVenueIdModalInput) singleVenueIdModalInput.value = currentVenueIdForModal;
        if(bulkVenueIdModalInput) bulkVenueIdModalInput.value = currentVenueIdForModal;
            
        if(generateSingleTicketFormModal) generateSingleTicketFormModal.reset();
        if(generateBulkTicketFormModal) generateBulkTicketFormModal.reset();
        if(bulkPreviewModalDiv) {
            bulkPreviewModalDiv.style.display = 'none';
            bulkPreviewModalDiv.innerHTML = '';
        }
        if(ticketMapFiltersForm) ticketMapFiltersForm.reset();
            
        selectedTicketIds.clear(); // Clear selections FIRST
        hideUpdateTicketForm();     // Then hide the form (it won't call updateActionButtonsVisibility anymore)
        updateActionButtonsVisibility(); // Then update button visibility based on the (now empty) selection

        ticketManagementModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';  
        loadVenueTicketDataForModal(currentVenueIdForModal);
    }

    if (closeTicketManagementModal) {
        closeTicketManagementModal.addEventListener('click', function() {
            console.log("DEBUG: Close ticket management modal clicked.");
            ticketManagementModal.style.display = 'none';
            document.body.style.overflow = 'auto';
            currentVenueIdForModal = null;
            currentMapTickets = [];
            selectedTicketIds.clear();
            ticketMapContainer.innerHTML = '<div class="spinner-container"><div class="spinner-border spinner-border-custom" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            const statsModal = document.getElementById('venueTicketStatsModal');
            if(statsModal) statsModal.innerHTML = '<h6><i class="fas fa-chart-pie"></i> Ticket Statistics</h6><p>Total: <strong id="statModalTotal">0</strong> | Available: <strong id="statModalAvailable">0</strong></p><div id="statModalByType"></div><div id="statModalByPrice"></div>';
            hideUpdateTicketForm(); 
            updateActionButtonsVisibility(); // Ensure buttons are reset
        });
    } else {
        console.error("DEBUG: closeTicketManagementModal button not found!");
    }


    async function loadVenueTicketDataForModal(venueId) {
        console.log("DEBUG: loadVenueTicketDataForModal called for venueId:", venueId);
        ticketMapContainer.innerHTML = '<div class="spinner-container"><div class="spinner-border spinner-border-custom" role="status"><span class="visually-hidden">Loading map...</span></div></div>';
        selectedTicketIds.clear();
        updateActionButtonsVisibility(); // Update visibility based on cleared selection

        try {
            const response = await fetch(`ticket_handler.php?action=get_venue_ticket_data&venue_id=${venueId}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();

            if (data.success && data.tickets) {
                currentMapTickets = data.tickets;  
                    
                let maxR = 'A', maxC = 1;  
                if (currentMapTickets.length > 0) {
                    let maxRowCharCode = 0;
                    let maxColNum = 0;
                    currentMapTickets.forEach(t => {
                        if (t.ticket_row && t.ticket_row.length > 0) {
                            const charCode = t.ticket_row.charCodeAt(0);
                            if (charCode > maxRowCharCode) {
                                maxRowCharCode = charCode;
                            }
                        }
                        if (t.ticket_column && parseInt(t.ticket_column) > maxColNum) {
                            maxColNum = parseInt(t.ticket_column);
                        }
                    });
                    maxR = maxRowCharCode > 0 ? String.fromCharCode(maxRowCharCode) : 'J';  
                    maxC = maxColNum > 0 ? maxColNum : 20;  
                } else {  
                    maxR = 'J';  
                    maxC = 20;  
                }
                venueMapMaxRow = maxR;  
                venueMapMaxCol = maxC;

                renderTicketMap(currentMapTickets, venueMapMaxRow, venueMapMaxCol);
                renderVenueStatsInModal(data.stats);
            } else {
                showToast('Error loading venue ticket data: ' + (data.message || 'Tickets array missing or success false'), 'error');
                ticketMapContainer.innerHTML = `<p class="text-center p-3 text-danger">Error loading map data: ${data.message || 'Unknown error'}</p>`;
            }
        } catch (error) {
            console.error('DEBUG: Error in loadVenueTicketDataForModal:', error);
            showToast('Client-side error fetching ticket data for modal. ' + error.message, 'error');
            ticketMapContainer.innerHTML = '<p class="text-center p-3 text-danger">Client-side error loading map data. Check console.</p>';
        }
    }
        
    function renderTicketMap(ticketsToRender, maxRow, maxCol) {
        console.log(`DEBUG: renderTicketMap called. MaxRow: ${maxRow}, MaxCol: ${maxCol}. Tickets to render:`, ticketsToRender.length);
        ticketMapContainer.innerHTML = '';  
        const grid = document.createElement('div');
        grid.className = 'ticket-map-grid';
            
        const containerWidth = ticketMapContainer.clientWidth > 0 ? ticketMapContainer.clientWidth - 25 : 300 - 25;  
        const seatOuterWidth = 30 + 4; 
        const numColsToDisplay = Math.max(1, Math.min(maxCol, Math.floor(containerWidth / seatOuterWidth)));  
            
        grid.style.gridTemplateColumns = `25px repeat(${numColsToDisplay}, 30px)`;

        const placeholder = document.createElement('div'); placeholder.className = 'map-placeholder'; grid.appendChild(placeholder);
        for (let c = 1; c <= numColsToDisplay; c++) {
            const colLabel = document.createElement('div'); colLabel.className = 'map-col-label'; colLabel.textContent = c; grid.appendChild(colLabel);
        }

        for (let rCharCode = 'A'.charCodeAt(0); rCharCode <= maxRow.charCodeAt(0); rCharCode++) {
            const rowStr = String.fromCharCode(rCharCode);
            const rowLabel = document.createElement('div'); rowLabel.className = 'map-row-label'; rowLabel.textContent = rowStr; grid.appendChild(rowLabel);

            for (let c = 1; c <= numColsToDisplay; c++) {
                const seatDiv = document.createElement('div');
                seatDiv.className = 'map-seat';
                seatDiv.dataset.row = rowStr;
                seatDiv.dataset.col = c;
                    
                const ticket = ticketsToRender.find(t => t.ticket_row === rowStr && parseInt(t.ticket_column) === c);
                if (ticket) {
                    seatDiv.classList.add('generated');
                    seatDiv.classList.add(`type-${String(ticket.ticket_type).toLowerCase().replace(/\s+/g, '-')}`);
                    seatDiv.textContent = ticket.ticket_column;  
                    seatDiv.title = `Seat: ${htmlspecialchars(ticket.ticket_location)}\nType: ${htmlspecialchars(ticket.ticket_type)}\nPrice: ₹${htmlspecialchars(ticket.ticket_price)}\nStatus: ${ticket.is_vacant === 'yes' ? 'Vacant' : 'Booked/Held'}`;
                    if(!ticket.ticket_id) console.error("DEBUG: Ticket object missing ticket_id!", ticket);
                    seatDiv.dataset.ticketId = ticket.ticket_id;  
                    if (ticket.is_vacant !== 'yes') seatDiv.classList.add('not-vacant');
                    if (selectedTicketIds.has(String(ticket.ticket_id))) seatDiv.classList.add('selected');
                }
                seatDiv.addEventListener('click', handleSeatClick);
                grid.appendChild(seatDiv);
            }
        }
        ticketMapContainer.appendChild(grid);
    }
        
    function handleSeatClick(e) {
        const seatDiv = e.currentTarget;
        const ticketId = seatDiv.dataset.ticketId;

        if (!ticketId) {  
            if(document.getElementById('single_ticket_row_modal')) document.getElementById('single_ticket_row_modal').value = seatDiv.dataset.row;
            if(document.getElementById('single_ticket_column_modal')) document.getElementById('single_ticket_column_modal').value = seatDiv.dataset.col;
            showToast(`Empty slot ${seatDiv.dataset.row}${seatDiv.dataset.col} selected. Fill details in "Generate Single Ticket" form.`, 'info');
            return;
        }

        if (selectedTicketIds.has(ticketId)) {
            selectedTicketIds.delete(ticketId);
            seatDiv.classList.remove('selected');
        } else {
            selectedTicketIds.add(ticketId);
            seatDiv.classList.add('selected');
        }
        updateActionButtonsVisibility();
        if (selectedTicketIds.size === 0) {  
            // No action needed here as hideUpdateTicketForm() will be called by updateActionButtonsVisibility
        } else if (selectedTicketIds.size === 1 && updateTicketFormContainer.style.display === 'block') {
            prefillUpdateFormForSingleSelection();
        }
    }

    // *** MODIFIED FUNCTION (FROM ORIGINAL) ***
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
            }
        } else {
            if(updateTicketForm) updateTicketForm.reset();  
        }
    }

    function renderVenueStatsInModal(stats) {
        const totalEl = document.getElementById('statModalTotal');
        const availEl = document.getElementById('statModalAvailable');
        const typeEl = document.getElementById('statModalByType');
        const priceEl = document.getElementById('statModalByPrice');
            
        if(typeEl) typeEl.innerHTML = '';  
        if(priceEl) priceEl.innerHTML = '';  

        if (stats) {
            if(totalEl) totalEl.textContent = stats.total_tickets || 0;
            if(availEl) availEl.textContent = stats.available_tickets || 0;

            if (stats.by_type && Object.keys(stats.by_type).length > 0 && typeEl) {
                let typeHtml = `<small>By Type: `;
                for (const type in stats.by_type) { typeHtml += `${htmlspecialchars(type)}: ${htmlspecialchars(stats.by_type[type])} | `; }  
                typeHtml = typeHtml.slice(0, -3) + `</small>`;  
                typeEl.innerHTML = typeHtml;
            }
            if (stats.by_price && Object.keys(stats.by_price).length > 0 && priceEl) {
                let priceHtml = `<small>By Price: `;
                for (const price in stats.by_price) {priceHtml += `₹${htmlspecialchars(price)}: ${htmlspecialchars(stats.by_price[price])} | `; }  
                priceHtml = priceHtml.slice(0, -3) + `</small>`;
                priceEl.innerHTML = priceHtml;
            }
        }
    }
        
    if (ticketMapFiltersForm) {
        ticketMapFiltersForm.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log("DEBUG: Ticket map filters submitted.");
            applyMapFilters();
        });
    } else {
        console.error("DEBUG: ticketMapFiltersForm not found!");
    }


    function applyMapFilters() {
        const formData = new FormData(ticketMapFiltersForm);
        const typeFilter = formData.get('map_ticket_type');
        const priceFilterStr = formData.get('map_ticket_price');
        const priceFilter = priceFilterStr ? parseFloat(priceFilterStr) : null;
        const vacantFilter = formData.get('map_is_vacant');

        let foundMatch = false;
        document.querySelectorAll('.map-seat.generated').forEach(seat => {
            seat.classList.remove('filtered-highlight');  
            const ticketId = seat.dataset.ticketId;
            const ticket = currentMapTickets.find(t => String(t.ticket_id) === ticketId);
            if (!ticket) return;

            let matches = true;
            if (typeFilter && ticket.ticket_type !== typeFilter) matches = false;
            if (priceFilter !== null && parseFloat(ticket.ticket_price) !== priceFilter) matches = false;
            if (vacantFilter && ticket.is_vacant !== vacantFilter) matches = false;
                
            if (matches) {
                seat.classList.add('filtered-highlight');
                foundMatch = true;
            }
        });
        if (!foundMatch && (typeFilter || priceFilterStr || vacantFilter)) {
            showToast('No tickets match map filters.', 'info');
        } else if (!typeFilter && !priceFilterStr && !vacantFilter) {  
            document.querySelectorAll('.map-seat.generated.filtered-highlight').forEach(seat => seat.classList.remove('filtered-highlight'));
        }
    }

    if(generateSingleTicketFormModal) {
        generateSingleTicketFormModal.addEventListener('submit', async function(e) {
            e.preventDefault();
            console.log("DEBUG: Generate single ticket form submitted.");
            const formData = new FormData(this);
            formData.append('action', 'generate_single_ticket');
                
            const ticketRow = formData.get('ticket_row').toUpperCase();
            if (!/^[A-Z]{1,3}$/.test(ticketRow)) {
                showToast('Invalid Row: Use 1-3 uppercase letters (A-Z).', 'error'); return;
            }
            formData.set('ticket_row', ticketRow);

            try {
                const response = await fetch('ticket_handler.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message || 'Ticket generated!', 'success');
                    this.reset();  
                    loadVenueTicketDataForModal(currentVenueIdForModal);  
                    fetchVenues(currentPage);
                } else { showToast(result.message || 'Failed to generate ticket.', 'error'); }
            } catch (error) {  
                console.error("DEBUG: Single Gen Error:", error);
                showToast('Client-side error (single gen): ' + error.message, 'error');  
            }
        });
    } else {
        console.error("DEBUG: generateSingleTicketFormModal not found!");
    }


    ['bulk_row_start_modal', 'bulk_row_end_modal', 'bulk_col_start_modal', 'bulk_col_end_modal', 'bulk_ticket_price_modal'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', () => {
                const rowStartInput = document.getElementById('bulk_row_start_modal');
                const rowEndInput = document.getElementById('bulk_row_end_modal');
                const rowStart = rowStartInput.value.toUpperCase();
                const rowEnd = rowEndInput.value.toUpperCase();
                rowStartInput.value = rowStart;
                rowEndInput.value = rowEnd;    

                const colStart = parseInt(document.getElementById('bulk_col_start_modal').value);
                const colEnd = parseInt(document.getElementById('bulk_col_end_modal').value);
                const priceStr = document.getElementById('bulk_ticket_price_modal').value;
                const price = priceStr ? parseFloat(priceStr) : NaN;

                if (rowStart && rowEnd && /^[A-Z]$/.test(rowStart) && /^[A-Z]$/.test(rowEnd) && rowStart.charCodeAt(0) <= rowEnd.charCodeAt(0) &&  
                    !isNaN(colStart) && !isNaN(colEnd) && colStart > 0 && colEnd > 0 && colStart <= colEnd) {
                    const numRows = rowEnd.charCodeAt(0) - rowStart.charCodeAt(0) + 1;
                    const numCols = colEnd - colStart + 1;
                    const totalTickets = numRows * numCols;
                        
                    if(bulkPreviewModalDiv) {
                        bulkPreviewModalDiv.style.display = 'block';
                        let previewText = `Generating <strong>${totalTickets}</strong> tickets.`;  
                        if (!isNaN(price) && price >= 0) {
                            previewText += ` Total Value: <strong>₹${(totalTickets * price).toFixed(2)}</strong>.`;
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
            console.log("DEBUG: Generate bulk ticket form submitted.");
            const formData = new FormData(this);
            formData.append('action', 'generate_bulk_tickets');
                
            const rowStart = formData.get('row_start').toUpperCase();
            const rowEnd = formData.get('row_end').toUpperCase();
            if (!/^[A-Z]$/.test(rowStart) || !/^[A-Z]$/.test(rowEnd) || rowStart.charCodeAt(0) > rowEnd.charCodeAt(0)) {
                showToast('Invalid Row Range: Use single uppercase letters (A-Z), Start <= End.', 'error'); return;
            }
            formData.set('row_start', rowStart);
            formData.set('row_end', rowEnd);

            const colStart = parseInt(formData.get('col_start'));
            const colEnd = parseInt(formData.get('col_end'));
            if (isNaN(colStart) || isNaN(colEnd) || colStart <= 0 || colEnd <= 0 || colStart > colEnd) {
                showToast('Invalid Column Range: Must be positive numbers, Start <= End.', 'error'); return;
            }

            try {
                const response = await fetch('ticket_handler.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message || 'Bulk tickets generated!', 'success');
                    this.reset();  
                    if(bulkPreviewModalDiv) {
                        bulkPreviewModalDiv.style.display = 'none';
                        bulkPreviewModalDiv.innerHTML = '';
                    }
                    loadVenueTicketDataForModal(currentVenueIdForModal);  
                    fetchVenues(currentPage);
                } else { showToast(result.message || 'Failed to generate bulk tickets.', 'error'); }
            } catch (error) {  
                console.error("DEBUG: Bulk Gen Error:", error);
                showToast('Client-side error (bulk gen): ' + error.message, 'error');  
            }
        });
    } else {
        console.error("DEBUG: generateBulkTicketFormModal not found!");
    }

    if(btnUpdateSelectedTickets) {
        btnUpdateSelectedTickets.addEventListener('click', function() {
            console.log("DEBUG: Update selected tickets button clicked. Selected count:", selectedTicketIds.size);
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
    } else {
        console.error("DEBUG: btnUpdateSelectedTickets not found!");
    }
        
    if(cancelUpdateTicketBtn) {
        cancelUpdateTicketBtn.addEventListener('click', function() {
            console.log("DEBUG: Cancel update ticket button clicked.");
            hideUpdateTicketForm();
            updateActionButtonsVisibility(); 
        });
    } else {
        console.error("DEBUG: cancelUpdateTicketBtn not found!");
    }

    function hideUpdateTicketForm() {
        if(updateTicketFormContainer) updateTicketFormContainer.style.display = 'none';
        if(updateTicketForm) updateTicketForm.reset();
    }

    if(updateTicketForm) {
        updateTicketForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            console.log("DEBUG: Update ticket form submitted. Selected count:", selectedTicketIds.size);
            if (selectedTicketIds.size === 0) {
                showToast('No tickets selected.', 'error'); return;
            }
                
            const currentFormData = new FormData(this);  
            const newType = currentFormData.get('ticket_type');
            const newPriceStr = currentFormData.get('ticket_price');
            const newVacant = currentFormData.get('is_vacant');

            if (!newType && !newPriceStr && !newVacant) {
                showToast('No changes specified for update.', 'info');
                return;
            }

            const payload = new FormData();  
            payload.append('action', 'update_tickets');
            payload.append('ticket_ids', JSON.stringify(Array.from(selectedTicketIds)));
            payload.append('venue_id', currentVenueIdForModal);

            if (newType) payload.append('ticket_type', newType);
            if (newPriceStr) {
                const priceVal = parseFloat(newPriceStr);
                if (isNaN(priceVal) || priceVal < 0) {
                    showToast('Invalid price. Must be a non-negative number.', 'error'); return;
                }
                payload.append('ticket_price', priceVal);
            }
            if (newVacant) payload.append('is_vacant', newVacant);
                
            try {
                const response = await fetch('ticket_handler.php', { method: 'POST', body: payload });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message || 'Tickets updated!', 'success');
                    loadVenueTicketDataForModal(currentVenueIdForModal);  
                    fetchVenues(currentPage);
                } else { showToast(result.message || 'Failed to update tickets.', 'error'); }
            } catch (error) {  
                console.error("DEBUG: Update Tickets Error:", error);
                showToast('Client-side error (update): ' + error.message, 'error');  
            }
        });
    } else {
        console.error("DEBUG: updateTicketForm not found!");
    }

    if(btnDeleteSelectedTickets) {
        btnDeleteSelectedTickets.addEventListener('click', async function() {
            console.log("DEBUG: Delete selected tickets button clicked. Selected count:", selectedTicketIds.size);
            if (selectedTicketIds.size === 0) {
                showToast('No tickets selected to delete.', 'info'); return;
            }
            if (!confirm(`Are you sure you want to delete (mark inactive) ${selectedTicketIds.size} selected ticket(s)?`)) {
                return;
            }
                
            const formData = new FormData();
            formData.append('action', 'delete_tickets');
            formData.append('ticket_ids', JSON.stringify(Array.from(selectedTicketIds)));
            formData.append('venue_id', currentVenueIdForModal);

            try {
                const response = await fetch('ticket_handler.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message || 'Tickets deleted!', 'success');
                    loadVenueTicketDataForModal(currentVenueIdForModal);
                    fetchVenues(currentPage);
                } else { showToast(result.message || 'Failed to delete tickets.', 'error'); }
            } catch (error) {  
                console.error("DEBUG: Delete Tickets Error:", error);
                showToast('Client-side error (delete): ' + error.message, 'error');
            }
        });
    } else {
        console.error("DEBUG: btnDeleteSelectedTickets not found!");
    }

    // Initial load of venues when the page is ready
    console.log("DEBUG: Initializing page, calling fetchVenues...");
    fetchVenues(currentPage);
    console.log("DEBUG: Script execution finished.");
});
</script>

</body>
</html>