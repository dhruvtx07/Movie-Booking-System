<?php
session_start(); // Start the session at the very beginning
date_default_timezone_set('Asia/Kolkata'); // Set default timezone as in file 1

require_once '../links.php'; // Include links.php (adjusted path for subdir)
require_once '../config.php'; // Keep existing config.php include

// Login check - copied from file 1 (dashboard.php)
// Redirect to login if not authenticated (except for auth pages)
$auth_pages = [basename($login_page), basename($register_page), basename($forgot_pass)]; // Use basename to compare filenames

if (!isset($_SESSION['user_id'])) {
    if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// User ID and username from session (from file 1, though not explicitly used in this file's logic)
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : '';
$adminUserId = $isLoggedIn ? $_SESSION['user_id'] : null;


$pageTitle = "Manage Cities - Catchify"; // Updated page title

// Initialize variables for edit form
$edit_mode = false;
$city_to_edit = ['city_id' => '', 'city_name' => '', 'state_name' => '', 'country' => '', 'is_active' => 'yes'];

// Check if editing a city
if (isset($_GET['edit_id'])) {
    $edit_id = mysqli_real_escape_string($conn, $_GET['edit_id']);
    $sql = "SELECT * FROM cities WHERE city_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($city = mysqli_fetch_assoc($result)) {
        $edit_mode = true;
        $city_to_edit = $city;
    } else {
        $_SESSION['message'] = "City not found.";
        $_SESSION['message_type'] = "danger";
        // Do not redirect, let form show as add, message will be handled by common message display
    }
    mysqli_stmt_close($stmt);
}

// Clear message after display if it exists (from file 1 logic)
if (isset($_SESSION['message'])) {
    // You should probably display the message here if you want it to appear
    // Example: echo '<div class="alert alert-' . $_SESSION['message_type'] . '">' . $_SESSION['message'] . '</div>';
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom Color Variables - Dark Theme Default */
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
            --metric-card-display-4-font: 2.5rem; /* Unused in this file, but kept for consistency */
            --metric-card-h5-font: 1.1rem; /* Unused in this file, but kept for consistency */
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
        .container-fluid { /* Changed from .container to .container-fluid as used in file 2 */
            max-width: 1200px; /* Max width to prevent content from stretching too wide */
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
        .section-subtitle { /* Not directly used in file 2, but kept for consistency */
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
        /* Custom styling for form-check-input (general checkbox) - Not used in this file */
        /* It's good to keep it if other forms use it. */
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
        .btn-success { /* For Add New User / Success messages */
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

        /* Dashboard Cards - Not used in this file, but general card styling */
        .card {
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            color: var(--text-color); /* Ensure text is visible within cards */
        }
        .card-header {
            background-color: var(--secondary-bg); /* Use same as card body for seamless look */
            border-bottom: 1px solid var(--card-border);
            color: var(--accent-orange); /* Highlight header text */
            font-weight: bold;
        }
        .card-body {
            padding: 20px;
        }

        .list-group-item { /* Not used in this file, but keep general list group style */
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
            color: var(--text-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            font-size: 0.95rem;
        }
        
        /* Table Styling */
        table {
            color: var(--text-color);
        }
        thead {
            background-color: var(--secondary-bg);
        }
        th, td {
            border-color: var(--card-border) !important;
            padding: 12px 18px; /* Increased padding */
        }
        th {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600; /* Made bolder */
        }
        tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.03); /* Subtle hover effect */
        }

        /* Specific for City Management Page Layout */
        .col-md-4_or_5 {
            width: 100%; /* Default to full width on small screens */
        }
        .col-md-7_or_8 {
            width: 100%; /* Default to full width on small screens */
        }

        @media (min-width: 768px) {
            .col-md-4_or_5 {
                flex: 0 0 auto;
                width: 33.333333%; /* Approx col-md-4 */
            }
            .col-md-7_or_8 {
                flex: 0 0 auto;
                width: 66.666667%; /* Approx col-md-8 */
            }
        }
        @media (min-width: 992px) { /* Adjust for larger screens if needed, as in dashboard.php */
             .col-lg-3 { width: 25%; }
             .col-lg-4 { width: 33.333333%; }
             .col-lg-8 { width: 66.666667%; }
        }


        /* Footer Styling */
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

        /* Responsive Adjustments (from dashboard.php, plus new for footer) */
        @media (max-width: 768px) {
            :root {
                --section-title-font: 1.8rem;
                --section-subtitle-font: 1.25rem;
                --metric-card-display-4-font: 2rem; /* Unused in this file */
                --metric-card-h5-font: 1rem; /* Unused in this file */
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
            .filter-group-spacing { /* Not used in this file, but kept for consistency */
                margin-bottom: 15px;
            }

            /* Adjust cards and tables for smaller screens */
            .metric-card { /* Not used in this file */
                padding: 15px;
            }
            .list-group-item { /* Not used in this file */
                padding: 8px 12px;
                font-size: 0.9rem;
            }
            th, td {
                padding: 10px 12px; /* Reduce table padding */
                font-size: 0.8rem;
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
            .filter-group-spacing { /* Not used in this file */
                margin-bottom: 0;
            }
        }
    </style>
</head>
<body>
<!-- Sidebar Toggle Button for Small Screens -->
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
                            <a class="nav-link" href="index.php" title="Manage Venues">
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
              <a class="nav-link" href="venue_tickets/index.php" title="Venue Ticket Types">
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
        <div class="container-fluid py-4">
            <h2 class="section-title">Manage Cities</h2>

            <?php if (isset($_SESSION['message']) && $_SESSION['message']): ?>
                 <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                     <?php echo $_SESSION['message']; ?>
                     <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                 </div>
                 <?php unset($_SESSION['message']); unset($_SESSION['message_type']); // Clear message after display ?>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-4_or_5"> <!-- Form column -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="mb-0"><?php echo $edit_mode ? 'Edit City' : 'Add New City'; ?></h3>
                        </div>
                        <div class="card-body">
                            <form action="city_handler.php" method="POST">
                                <?php if ($edit_mode): ?>
                                    <input type="hidden" name="city_id" value="<?php echo htmlspecialchars($city_to_edit['city_id']); ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label for="city_name" class="form-label">City Name</label>
                                    <input type="text" class="form-control" id="city_name" name="city_name" value="<?php echo htmlspecialchars($city_to_edit['city_name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="state_name" class="form-label">State Name</label>
                                    <input type="text" class="form-control" id="state_name" name="state_name" value="<?php echo htmlspecialchars($city_to_edit['state_name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="country" name="country" value="<?php echo htmlspecialchars($city_to_edit['country']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="is_active" class="form-label">Status</label>
                                    <select class="form-select" id="is_active" name="is_active" required>
                                        <option value="yes" <?php echo ($city_to_edit['is_active'] == 'yes') ? 'selected' : ''; ?>>Active</option>
                                        <option value="no" <?php echo ($city_to_edit['is_active'] == 'no') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <?php if ($edit_mode): ?>
                                    <button type="submit" name="update_city" class="btn btn-primary">Update City</button>
                                    <a href="index.php" class="btn btn-secondary">Cancel Edit</a>
                                <?php else: ?>
                                    <button type="submit" name="save_city" class="btn btn-primary">Save City</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-7_or_8"> <!-- Table column -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="mb-0">Cities List</h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-dark">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>City Name</th>
                                            <th>State</th>
                                            <th>Country</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Fetch all cities for display
                                        $sql = "SELECT * FROM cities ORDER BY city_name ASC";
                                        $result = mysqli_query($conn, $sql);
                                        if (mysqli_num_rows($result) > 0):
                                            while ($row = mysqli_fetch_assoc($result)):
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['city_id']); ?></td>
                                            <td><?php echo htmlspecialchars($row['city_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['state_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['country']); ?></td>
                                            <td>
                                                <?php if ($row['is_active'] == 'yes'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="index.php?edit_id=<?php echo $row['city_id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteCityModal_<?php echo $row['city_id']; ?>">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>

                                                <!-- Delete Confirmation Modal -->
                                                <div class="modal fade" id="deleteCityModal_<?php echo $row['city_id']; ?>" tabindex="-1" aria-labelledby="deleteCityModalLabel_<?php echo $row['city_id']; ?>" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="deleteCityModalLabel_<?php echo $row['city_id']; ?>" style="color: var(--accent-yellow);">Confirm Deletion</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                Are you sure you want to delete the city "<?php echo htmlspecialchars($row['city_name']); ?>"?
                                                                <br><small class="text-warning">This action cannot be undone. The city will only be deleted if no venues are associated with it.</small>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <form action="city_handler.php" method="POST" style="display: inline;">
                                                                    <input type="hidden" name="city_id_to_delete" value="<?php echo $row['city_id']; ?>">
                                                                    <button type="submit" name="delete_city" class="btn btn-danger">Delete City</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                            endwhile;
                                        else:
                                        ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No cities found.</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
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
            // Get the link's href and extract just the filename (e.g., "index.php" or "dashboard.php")
            const linkHref = link.getAttribute('href');
            if (!linkHref || linkHref.startsWith('#')) return; // Skip links that are just collapse toggles

            // Use the full path for comparison, adjusted for relative paths
            const normalizedLinkHref = new URL(linkHref, window.location.href).pathname;
            const normalizedCurrentPath = window.location.pathname;

            // Check if the link's normalized path matches the current page's normalized path
            if (normalizedLinkHref === normalizedCurrentPath) {
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
            }
        });

        // --- Caret Icon Rotation on Collapse Events ---
        document.querySelectorAll('.sidebar-nav .collapse').forEach(collapseElement => {
            collapseElement.addEventListener('show.bs.collapse', function () {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(180deg)';
                    // Do NOT add 'active' to parent link here, as it may interfere with actual active status
                    // toggleLink.classList.add('active'); 
                }
            });

            collapseElement.addEventListener('hide.bs.collapse', function () {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(0deg)';
                    
                    // Only deactivate parent link if none of its *current* sub-items are active
                    // and the parent link itself is not the current active page
                    const hasActiveChild = this.querySelector('.nav-link.active');
                    const isParentLinkActive = toggleLink.classList.contains('active');
                    
                    if (!hasActiveChild && !isParentLinkActive) {
                        toggleLink.classList.remove('active');
                    }
                }
            });
        });
    });
</script>
</body>
</html>