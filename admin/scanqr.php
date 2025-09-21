<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'links.php';

// Database configuration
$host = 'localhost';
$dbname = 'event_mg'; // Your database name
$dbusername = 'root';
$password = '';

// Redirect to login if not authenticated
$auth_pages = [$login_page, $register_page, $forgot_pass];

if (!isset($_SESSION['user_id'])) {
    $current_page_basename = basename($_SERVER['PHP_SELF']);
    // If the current page requires login and user is not logged in, redirect.
    // 'scanqr.php' is generally a page requiring authentication.
    if (!in_array($current_page_basename, $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// Check if user is logged in (for display purposes, e.g., username)
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : '';
$adminUserId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// Connect to database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'"); // Set charset to UTF-8
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$qr_scan_result_status = null; // Stores status of QR scan (e.g., 'already_checked_in' etc. for custom UI states)
$booking_data = null; // Stores fetched booking details for display
$message = null;    // For general success/error messages displayed as Bootstrap alerts

// Handle POST requests for QR scan or check-in
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine action to perform: scan_qr_data or check_in
    $action = $_POST['action'] ?? '';

    // --- QR Code Data Processing ---
    if ($action === 'scan_qr_data') {
        $qr_data_string = $_POST['qr_code_data'] ?? '';

        if (empty($qr_data_string)) {
            $message = ['type' => 'danger', 'text' => 'No QR code data received or decoded.'];
        } else {
            // Parse the QR data string (e.g., "booking_ref=BK-XXXX&event_id=Y&schedule_id=A")
            $params = [];
            parse_str($qr_data_string, $params);

            $booking_ref = $params['booking_ref'] ?? null;
            $event_id = $params['event_id'] ?? null;
            $schedule_id = $params['schedule_id'] ?? null;

            // Step 1: Validate essential QR data and fetch venue_id from event_schedule table
            if ($booking_ref && $event_id && $schedule_id) {
                // Query event_schedule to get the venue_id associated with this schedule and event
                $stmt_get_venue_id = $pdo->prepare("
                    SELECT venue_id
                    FROM event_schedule
                    WHERE event_id = :event_id AND event_schedule_id = :schedule_id
                    LIMIT 1
                ");
                $stmt_get_venue_id->execute([
                    ':event_id' => $event_id,
                    ':schedule_id' => $schedule_id
                ]);
                $event_schedule_info = $stmt_get_venue_id->fetch(PDO::FETCH_ASSOC);

                if ($event_schedule_info) {
                    $venue_id = $event_schedule_info['venue_id'];

                    // Step 2: Fetch all booking details using the inferred venue_id
                    // This query fetches all tickets under one booking_ref and joins
                    // all necessary tables to display comprehensive booking information.
                    $stmt = $pdo->prepare("
                        SELECT
                            b.booking_ref, b.user_id, b.total_amt, b.booking_amt, b.checked_in, b.booked_at,
                            ui.name AS customer_name, ui.email AS customer_email,
                            ei.event_name, ei.event_type, ei.event_category,
                            v.venue_name, v.sub_venue_name,
                            c.city_name, c.state_name,
                            vs.slot_starts_at, vs.slot_ends_at,
                            est.ticket_id, est.ticket_location, est.ticket_row, est.ticket_column, est.ticket_type, est.ticket_price
                        FROM bookings b
                        LEFT JOIN users ui ON b.booked_by = ui.userid
                        LEFT JOIN event_schedule_tickets est ON b.ticket_id = est.ticket_id
                        LEFT JOIN event_schedule es ON est.event_schedule_id = es.event_schedule_id
                        LEFT JOIN event_info ei ON es.event_id = ei.event_id
                        LEFT JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
                        LEFT JOIN venues v ON vs.venue_id = v.venue_id
                        LEFT JOIN cities c ON v.city_id = c.city_id
                        WHERE b.booking_ref = :booking_ref
                        AND es.event_id = :event_id
                        AND v.venue_id = :venue_id -- Match venue_id (now inferred from event_schedule)
                        AND es.event_schedule_id = :schedule_id
                    ");
                    $stmt->execute([
                        ':booking_ref' => $booking_ref,
                        ':event_id' => $event_id,
                        ':venue_id' => $venue_id,
                        ':schedule_id' => $schedule_id
                    ]);
                    $raw_booking_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($raw_booking_tickets)) {
                        $booking_ref_status = $raw_booking_tickets[0]['checked_in'];
                        if ($booking_ref_status === 'yes') {
                            $message = ['type' => 'warning', 'text' => 'This booking (Ref: ' . htmlspecialchars($booking_ref) . ') has already been checked in.'];
                            $qr_scan_result_status = 'already_checked_in';
                        } else {
                            // Aggregate ticket details and summary for display
                            $ticket_details = [];
                            $total_tickets_count = 0;
                            $ticket_types_summary = [];

                            foreach ($raw_booking_tickets as $ticket) {
                                $ticket_details[] = [
                                    'ticket_id' => $ticket['ticket_id'],
                                    'ticket_location' => $ticket['ticket_location'],
                                    'ticket_row' => $ticket['ticket_row'],
                                    'ticket_column' => $ticket['ticket_column'],
                                    'ticket_type' => $ticket['ticket_type'],
                                    'ticket_price' => $ticket['ticket_price'],
                                ];
                                $total_tickets_count++;
                                $ticket_types_summary[$ticket['ticket_type']] = ($ticket_types_summary[$ticket['ticket_type']] ?? 0) + 1;
                            }

                            // Prepare booking_data for display in the form
                            $booking_data = [
                                'booking_ref' => $raw_booking_tickets[0]['booking_ref'],
                                'customer_name' => $raw_booking_tickets[0]['customer_name'],
                                'customer_email' => $raw_booking_tickets[0]['customer_email'],
                                'event_name' => $raw_booking_tickets[0]['event_name'],
                                'event_type' => $raw_booking_tickets[0]['event_type'],
                                'event_category' => $raw_booking_tickets[0]['event_category'],
                                'venue_name' => $raw_booking_tickets[0]['venue_name'],
                                'sub_venue_name' => $raw_booking_tickets[0]['sub_venue_name'],
                                'city_name' => $raw_booking_tickets[0]['city_name'],
                                'state_name' => $raw_booking_tickets[0]['state_name'],
                                'slot_starts_at' => $raw_booking_tickets[0]['slot_starts_at'],
                                'slot_ends_at' => $raw_booking_tickets[0]['slot_ends_at'],
                                'booking_amt' => $raw_booking_tickets[0]['booking_amt'],
                                'total_amt' => $raw_booking_tickets[0]['total_amt'],
                                'checked_in' => $raw_booking_tickets[0]['checked_in'],
                                'booked_at' => $raw_booking_tickets[0]['booked_at'],
                                'total_tickets_count' => $total_tickets_count,
                                'ticket_types_summary' => $ticket_types_summary,
                                'ticket_details' => $ticket_details,
                                'original_qr_data' => $qr_data_string // Store original QR data for resubmission
                            ];
                            $message = ['type' => 'success', 'text' => 'QR code scanned successfully. Booking details loaded.'];
                        }
                    } else {
                        $message = ['type' => 'danger', 'text' => 'Booking not found or invalid QR data combination.'];
                    }
                } else {
                    $message = ['type' => 'danger', 'text' => 'Event schedule not found for the given event ID and schedule ID.'];
                }
            } else {
                $message = ['type' => 'danger', 'text' => 'Incomplete QR data. Missing booking_ref, event_id, or schedule_id.'];
            }
        }
    }
    // --- Check-in Logic ---
    elseif ($action === 'check_in') {
        $booking_ref_to_check = $_POST['booking_ref_to_check'] ?? null;
        $customer_email_input = $_POST['customer_email_input'] ?? null;
        $original_qr_data_string = $_POST['qr_code_original_data'] ?? ''; // Re-passed from form to re-scan after update

        if ($booking_ref_to_check && $customer_email_input) {
            // First, retrieve the actual user's email associated with *any* ticket in this booking_ref
            // Since all tickets under a booking_ref belong to the same user.
            $stmt_get_email = $pdo->prepare("
                SELECT DISTINCT ui.email
                FROM bookings b
                JOIN users ui ON b.user_id = ui.userid
                WHERE b.booking_ref = :booking_ref
                LIMIT 1
            ");
            $stmt_get_email->execute([':booking_ref' => $booking_ref_to_check]);
            $actual_customer_email_row = $stmt_get_email->fetch(PDO::FETCH_ASSOC);

            if ($actual_customer_email_row && $actual_customer_email_row['email'] === $customer_email_input) {
                // Email matches, proceed to update checked_in for ALL tickets under this booking_ref
                $stmt_update = $pdo->prepare("
                    UPDATE bookings
                    SET checked_in = 'yes'
                    WHERE booking_ref = :booking_ref AND checked_in = 'no'
                ");
                $stmt_update->execute([':booking_ref' => $booking_ref_to_check]);

                if ($stmt_update->rowCount() > 0) {
                    $message = ['type' => 'success', 'text' => 'Tickets for Booking Ref ' . htmlspecialchars($booking_ref_to_check) . ' successfully checked in!'];
                } else {
                    $message = ['type' => 'warning', 'text' => 'Booking Ref ' . htmlspecialchars($booking_ref_to_check) . ' was already checked in, or no matching active booking found.'];
                }
            } else {
                $message = ['type' => 'danger', 'text' => 'Customer email does not match for this booking reference.'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Missing booking reference or customer email for check-in.'];
        }

        // After attempting check-in, reload the booking data to reflect the new status
        // Pass the original QR data back to simulate a fresh scan immediately after
        $_POST['qr_code_data'] = $original_qr_data_string;
        $_POST['action'] = 'scan_qr_data'; // Re-set action to re-fetch booking data and update UI
        // The script will now fall through and re-process the 'scan_qr_data' part with the updated status.
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan QR & Check-in - Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- jsQR library for client-side QR decoding -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.1.0/dist/jsQR.min.js"></script>
    <style>
        /* Custom Color Variables - Dark Theme Default (copied from dashboard.php) */
        :root {
            --primary-bg: #1A1A1A; /* Dark background */
            --secondary-bg: #2B2B2B; /* Lighter dark for cards/elements */
            --text-color: #F0F0F0; /* Light text */
            --light-text-color: #B0B0B0; /* Muted text */
            --accent-black: #000000; /* Pure Black */
            --accent-red: #E50914; /* Netflix Red */
            --accent-orange: #FF6B35; /* Vibrant Orange */
            --accent-golden: #FFD700; /* Golden Yellow */
            --accent-peach: #FF9E7D; /* Soft Peach */
            --nav-dark: var(--accent-black); /* Pure black for specific elements like sidebar overlay */
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

        /* New wrapper for Main Content and Footer */
        .content-wrapper {
            display: flex;
            flex-direction: column; /* Stack main content and footer vertically */
            flex-grow: 1; /* Allows it to take up the remaining horizontal space */
            margin-left: var(--sidebar-width-collapsed); /* Initial margin to offset collapsed sidebar */
            transition: margin-left var(--sidebar-transition-duration) ease-in-out;
            min-height: 100vh; /* Ensures the wrapper fills at least the viewport height */
        }

        /* Sidebar Styling (copied from dashboard.php) */
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

        /* General Styles - Copied from dashboard.php */
        .container {
            max-width: 1200px;
            padding-left: 15px;
            padding-right: 15px;
        }
        @media (max-width: 768px) {
            .container {
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
        .alert-warning { background: linear-gradient(90deg, var(--accent-golden), var(--accent-orange)); border: none; }
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
        .btn-success { /* For Add New User / Check-in success */
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

        /* Dashboard Cards - Reused for general displays */
        .metric-card {
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%; /* Ensure cards in a row have equal height */
            display: flex;
            flex-direction: column;
        }
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .list-group-item {
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
            color: var(--text-color);
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            font-size: 0.95rem; /* Slightly larger text */
        }
        
        /* QR Scanner specific styles */
        #drop-area {
            border: 2px dashed var(--accent-orange);
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            background-color: var(--secondary-bg);
            color: var(--light-text-color);
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        #drop-area.highlight {
            background-color: var(--accent-red);
            border-color: var(--accent-peach);
            color: white;
            box-shadow: 0 0 15px var(--accent-red);
        }
        #drop-area i {
            font-size: 3rem;
            color: var(--accent-orange);
            margin-bottom: 10px;
            transition: color 0.3s ease;
        }
        #drop-area.highlight i {
            color: white;
        }
        #qr-image-display {
            max-width: 100%;
            max-height: 300px;
            object-fit: contain; /* Ensure image scales properly */
            display: block;
            margin: 20px auto;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--card-border);
        }
        #qr-canvas { /* Canvas for decoding */
            display: none; 
        }
        #qr-scan-btn {
            display: none; /* Hidden by default until image is selected */
            margin-top: 15px;
        }
        
        .qr-card-title {
            color: var(--accent-peach);
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        .qr-card-value {
            color: var(--text-color);
            font-size: 1rem;
            margin-bottom: 15px;
            display: block;
            word-wrap: break-word; /* For long booking refs */
        }
        /* Specific styling for the 'already_checked_in' state */
        .already-checked-in-banner {
            background: linear-gradient(135deg, var(--accent-golden) 0%, var(--accent-orange) 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 20px;
        }

        /* Footer Styling (copied from dashboard.php) */
        .footer {
            background-color: var(--secondary-bg);
            color: var(--light-text-color);
            padding: 20px;
            border-top: 1px solid var(--card-border);
            flex-shrink: 0; /* Prevents the footer from shrinking */
            width: 100%; /* Ensures it spans the full width of its parent (.content-wrapper) */
        }
        .footer a {
            color: var(--accent-orange);
            text-decoration: none;
        }
        .footer a:hover {
            color: var(--accent-red);
            text-decoration: underline;
        }

        /* Responsive Adjustments (copied from dashboard.php) */
        @media (max-width: 768px) {
            :root {
                --section-title-font: 1.8rem;
                --section-subtitle-font: 1.25rem;
            }

            .sidebar {
                width: 0;
                padding-top: 60px;
                box-shadow: none;
            }
            .sidebar.is-open {
                width: var(--sidebar-width-expanded);
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.5);
            }

            .content-wrapper {
                margin-left: 0;
                padding-top: 15px;
            }
            /* Add a button to toggle sidebar on small screens */
            .sidebar-toggle-btn {
                display: block;
                position: fixed;
                top: 10px;
                left: 10px;
                z-index: 1030;
                background-color: var(--nav-dark);
                color: var(--nav-text);
                border: none;
                padding: 10px 15px;
                border-radius: 5px;
                cursor: pointer;
                box-shadow: 0 2px 5px rgba(0,0,0,0.3);
                transition: transform 0.2s ease;
            }
            
            .metric-card {
                padding: 15px;
            }
            .list-group-item {
                padding: 8px 12px;
                font-size: 0.9rem;
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
        @media (min-width: 769px) {
            .sidebar-toggle-btn {
                display: none;
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
    <a href="dashboard.php" title="Catchify Dashboard">
      <img src="images/logo.png" alt="Catchify Logo" class="logo-img">
      <h3>Catchify Admin</h3>
    </a>
  </div>
  <nav class="sidebar-nav">
    <ul class="nav flex-column">
      <li class="nav-item">
        <a class="nav-link" href="dashboard.php" title="Dashboard">
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
              <a class="nav-link" href="event_handler.php" title="Manage Events">
                <i class="fas fa-edit"></i>
                <span>Manage Events</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="./event_assignment/event_assignment.php" title="Event Schedules">
                <i class="fas fa-clock"></i>
                <span>Event Schedules</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="event_schedule_tickets/event_schedule_tickets.php" title="Event Ticket Types">
                <i class="fas fa-ticket-alt"></i>
                <span>Event Ticket Types</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="event_dashboard.php" title="Event Dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Event Dashboard</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="event_booking_detailed.php" title="Event Reports">
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
                            <a class="nav-link" href="cities/index.php" title="Manage Venues">
                                <i class="fas fa-warehouse"></i>
                                <span>Manage Cities</span>
                            </a>
                        </li>
            <li class="nav-item">
              <a class="nav-link" href="venues/index.php" title="Manage Venues">
                <i class="fas fa-warehouse"></i>
                <span>Manage Venues</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="venue_schedules/venue_schedules.php" title="Venue Schedules">
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
              <a class="nav-link" href="venue_dashboard.php" title="Venue Dashboard">
                <i class="fas fa-chart-pie"></i>
                <span>Venue Dashboard</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="venue_booking_detailed.php" title="Venue Reports">
                <i class="fas fa-clipboard-list"></i>
                <span>Venue Reports</span>
              </a>
            </li>
          </ul>
        </div>
      </li>
       
      <li class="nav-item">
        <a class="nav-link" href="manage_promos.php" title="Promo Codes">
          <i class="fas fa-tag"></i>
          <span>Promo Codes</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link" href="scanqr.php" title="Promo Codes">
          <i class="fas fa-qrcode"></i>
          <span>Scan Ticket QR</span>
        </a>
      </li>

      <!-- Manage Users (from file 2) - Marked Active -->
                <li class="nav-item">
                    <a class="nav-link" href="manage_users.php" title="Manage Users">
                        <i class="fas fa-users-cog"></i>
                        <span>Manage Users</span>
                    </a>
                </li>

      <li class="nav-item">
        <a class="nav-link" href="logout.php" title="Logout">
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
        <div class="container py-4">
            <h2 class="section-title text-center">Scan QR Code for Check-in</h2>

            <?php if ($message): ?>
                <div class="alert alert-<?= $message['type'] ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message['text']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row justify-content-center">
                <div class="col-lg-8 col-md-10">
                    <div class="metric-card p-4">
                        <h4 class="text-center mb-4">Upload QR Code Image</h4>
                        <div id="drop-area">
                            <i class="fas fa-upload"></i>
                            <p>Drag & drop a QR image here, or click to select</p>
                            <input type="file" id="fileElem" accept="image/*" class="d-none">
                        </div>
                        <!-- Canvas for QR decoding (hidden) -->
                        <canvas id="qr-canvas"></canvas>
                        <img id="qr-image-display" src="#" alt="QR Code Image" class="d-none">
                        <button type="button" id="qr-scan-btn" class="btn btn-primary w-100"><i class="fas fa-qrcode me-2"></i>Decode & Scan QR</button>
                    </div>
                </div>
            </div>

            <?php if ($booking_data): /* Display booking details if data is available */ ?>
                <h3 class="section-subtitle mt-5">Booking Details</h3>
                <div class="row justify-content-center" id="booking-details-section">
                    <div class="col-lg-8 col-md-10">
                        <div class="metric-card p-4">
                            <?php if ($booking_data['checked_in'] === 'yes'): ?>
                                <div class="already-checked-in-banner">
                                    <i class="fas fa-check-circle me-2"></i> This booking has already been checked in.
                                </div>
                            <?php endif; ?>

                            <form id="check-in-form" method="POST">
                                <input type="hidden" name="action" value="check_in">
                                <input type="hidden" name="booking_ref_to_check" value="<?= htmlspecialchars($booking_data['booking_ref']) ?>">
                                <input type="hidden" name="qr_code_original_data" id="qr-code-original-data-hidden" value="<?= htmlspecialchars($booking_data['original_qr_data']) ?>">

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Booking Reference:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($booking_data['booking_ref']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Checked In Status:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars(ucfirst($booking_data['checked_in'])) ?>" readonly>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Customer Name:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($booking_data['customer_name'] ?? 'N/A') ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Booked At:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars((new DateTime($booking_data['booked_at']))->format('M d, Y H:i:s')) ?>" readonly>
                                    </div>
                                </div>

                                <hr class="my-4" style="border-color: rgba(255, 255, 255, 0.1);">

                                <h5 class="section-subtitle" style="margin-top: 0; margin-bottom: 15px;">Event Details</h5>
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <label class="qr-card-title">Event Name:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($booking_data['event_name'] ?? 'N/A') ?>" readonly>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Event Type:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($booking_data['event_type'] ?? 'N/A') ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Event Category:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($booking_data['event_category'] ?? 'N/A') ?>" readonly>
                                    </div>
                                </div>

                                <hr class="my-4" style="border-color: rgba(255, 255, 255, 0.1);">

                                <h5 class="section-subtitle" style="margin-top: 0; margin-bottom: 15px;">Venue & Schedule</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Venue:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars(($booking_data['venue_name'] && $booking_data['sub_venue_name']) ? $booking_data['venue_name'] . ' - ' . $booking_data['sub_venue_name'] : 'N/A') ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Location:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars(($booking_data['city_name'] && $booking_data['state_name']) ? $booking_data['city_name'] . ', ' . $booking_data['state_name'] : 'N/A') ?>" readonly>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Schedule Start:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars((new DateTime($booking_data['slot_starts_at']))->format('M d, Y H:i')) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Schedule End:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars((new DateTime($booking_data['slot_ends_at']))->format('M d, Y H:i')) ?>" readonly>
                                    </div>
                                </div>

                                <hr class="my-4" style="border-color: rgba(255, 255, 255, 0.1);">

                                <h5 class="section-subtitle" style="margin-top: 0; margin-bottom: 15px;">Ticket & Pricing</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Total Tickets Booked:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($booking_data['total_tickets_count']) ?> Tickets" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Ticket Types:</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars(implode(', ', array_map(function($type, $count){ return "$type ($count)"; }, array_keys($booking_data['ticket_types_summary']), $booking_data['ticket_types_summary']))) ?>" readonly>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Original Booking Amount:</label>
                                        <input type="text" class="form-control" value="INR <?= number_format($booking_data['booking_amt'], 2) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="qr-card-title">Total Amount Paid (after discounts):</label>
                                        <input type="text" class="form-control" value="INR <?= number_format($booking_data['total_amt'], 2) ?>" readonly>
                                    </div>
                                </div>

                                <div class="my-3">
                                    <label class="qr-card-title">Ticket Locations:</label>
                                    <ul class="list-group list-group-flush">
                                        <?php if (!empty($booking_data['ticket_details'])): ?>
                                            <?php foreach($booking_data['ticket_details'] as $ticket): ?>
                                                <li class="list-group-item d-flex align-items-center mb-1">
                                                    <span class="flex-grow-1"><strong><?= htmlspecialchars($ticket['ticket_type']) ?></strong>: <?= htmlspecialchars($ticket['ticket_location']) ?> (Row: <?= htmlspecialchars($ticket['ticket_row']) ?>, Col: <?= htmlspecialchars($ticket['ticket_column']) ?>)</span>
                                                    <span class="badge text-white" style="background-color: var(--accent-red);">INR <?= number_format($ticket['ticket_price'], 2) ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <li class="list-group-item">No specific ticket locations found.</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>

                                <hr class="my-4" style="border-color: rgba(255, 255, 255, 0.1);">

                                <div class="col-md-12 mb-3">
                                    <label for="customer_email_input" class="form-label text-white">Enter Customer Email ID to Confirm Check-in:</label>
                                    <input type="email" class="form-control" id="customer_email_input" name="customer_email_input" placeholder="e.g., <?= htmlspecialchars($booking_data['customer_email']) ?>" required>
                                </div>
                                
                                <div class="text-center mt-4">
                                    <button type="submit" class="btn btn-success btn-lg px-5" <?= $booking_data['checked_in'] === 'yes' ? 'disabled' : '' ?>>
                                        <i class="fas fa-user-check me-2"></i> Check-in Booking
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php elseif ($qr_scan_result_status === 'already_checked_in'): /* Specific message if booking was already checked in */ ?>
                <h3 class="section-subtitle mt-5">Booking Status</h3>
                <div class="row justify-content-center" id="already-checked-in-section">
                    <div class="col-lg-8 col-md-10">
                        <div class="metric-card p-4">
                            <div class="already-checked-in-banner">
                                <i class="fas fa-check-circle me-2"></i> This booking has already been checked in.
                            </div>
                            <p class="text-center text-muted">Please scan another QR code.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer (copied from dashboard.php) -->
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
        // --- Sidebar Toggle and Functionality (copied directly from dashboard.php) ---
        const sidebar = document.getElementById('sidebar');
        const contentWrapper = document.getElementById('contentWrapper');
        let sidebarToggleBtn = document.getElementById('sidebarToggleBtn'); // Use `let` so it can be re-assigned

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

        // --- Active Link and Submenu Management (copied from dashboard.php) ---
        // Moved to reinitializePageElements as it needs to run on initial load AND after AJAX
        const applyActiveLinkStyles = () => {
             const currentPath = window.location.pathname;
             const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

             navLinks.forEach(link => {
                 // Remove 'active' class from all links first to ensure only one is active
                 link.classList.remove('active');

                 const linkHref = link.getAttribute('href');
                 if (!linkHref || linkHref.startsWith('#')) return;

                 const currentFilename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
                 const linkFilename = linkHref.substring(linkHref.lastIndexOf('/') + 1);

                 if (linkFilename === currentFilename) {
                     link.classList.add('active'); // Mark the specific item as active
                     const parentCollapseDiv = link.closest('.collapse');
                     if (parentCollapseDiv) {
                         const bsCollapse = bootstrap.Collapse.getInstance(parentCollapseDiv) || new bootstrap.Collapse(parentCollapseDiv, { toggle: false });
                         bsCollapse.show(); // Show the collapse
                         const parentToggleLink = document.querySelector(`a[data-bs-target="#${parentCollapseDiv.id}"]`);
                         if (parentToggleLink) {
                             parentToggleLink.classList.remove('collapsed'); // Remove collapsed class
                             parentToggleLink.setAttribute('aria-expanded', 'true'); // Set expanded state for accessibility
                         }
                     }
                 }
             });
        }


        // Global references to main QR elements for re-attachment
        let dropArea = document.getElementById('drop-area');
        let fileElem = document.getElementById('fileElem'); // Hidden file input
        let qrImageDisplay = document.getElementById('qr-image-display');
        let qrScanBtn = document.getElementById('qr-scan-btn');
        const qrCanvas = document.getElementById('qr-canvas');
        const canvasContext = qrCanvas.getContext('2d');

        let decodedQrData = null; // Stores the actual decoded string from the QR code

        // --- General event handlers for QR drop area ---
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        function highlight() {
            this.classList.add('highlight');
        }

        function unhighlight() {
            this.classList.remove('highlight');
        }

        // Handle dropped files
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }

        // Handle file selection via hidden input
        function handleFileElemChange(e) {
            if (e.target.files && e.target.files.length > 0) {
                handleFiles(e.target.files);
            } else {
                // If the file dialog is cancelled, reset to initial state
                resetQRSection();
            }
        }

        function handleFiles(files) {
            console.log("handleFiles called with 'files' object:", files); // Log the *original* files object
            console.log("Type of 'files':", typeof files, "Is instance of FileList?", files instanceof FileList);
            console.log("Length of 'files':", files ? files.length : "null/undefined");

            // Essential check for 'files' object itself
            if (!files) {
                console.error("handleFiles: 'files' object is null or undefined at the start.");
                alert('An unexpected error occurred: No file information was received.');
                return;
            }

            // Immediately create a static array from the FileList.
            // This can mitigate issues if the live FileList object is somehow becoming invalid.
            const fileArray = Array.from(files);

            console.log("Converted 'files' to 'fileArray':", fileArray);
            console.log("Length of 'fileArray':", fileArray.length);

            // Now, check the length of this static array.
            if (fileArray.length === 0) {
                console.warn("handleFiles: Converted files list is empty.");
                // This might happen if the file selection was cancelled implicitly, or a very unusual state.
                resetQRSection(); // Clear any partial state
                return;
            }

            resetQRSection(); // Clears qrImageDisplay, qrScanBtn, and fileElem.value

            const file = fileArray[0]; // Access the first file from the static array

            // This is the check that was triggered before (line 834 or close)
            // If this is still hit, it's highly unusual and suggests a deeper browser/system issue.
            if (!file) {
                console.error("handleFiles: ERROR - fileArray[0] is undefined, even though fileArray.length was > 0. This is highly unusual and suggests a browser/system level quirk.");
                alert('An unusual error occurred while processing the image file. Please try selecting the image again or use a different browser.');
                // Attempt to explicitly clear the input to allow a fresh start
                if (fileElem) fileElem.value = '';
                return;
            }

            // Normal file type validation
            if (!file.type.startsWith('image/')) {
                alert('Please upload an image file (PNG, JPG, GIF, etc.).');
                resetQRSection();
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                qrImageDisplay.src = e.target.result;
                qrImageDisplay.onload = () => { // Ensure image is loaded before drawing on canvas
                    qrImageDisplay.classList.remove('d-none'); // Show the image
                    qrScanBtn.classList.remove('d-none'); // Show the decode button
                    decodedQrData = null; // Reset decoded data for new image
                    decodeImage(qrImageDisplay); // Attempt to decode immediately
                };
            };
            reader.readAsDataURL(file);
        }


        function decodeImage(img) {
            try {
                // Set canvas dimensions to match image
                qrCanvas.width = img.naturalWidth;
                qrCanvas.height = img.naturalHeight;
                // Draw the image onto the canvas
                canvasContext.drawImage(img, 0, 0, img.naturalWidth, img.naturalHeight);

                // Get image data from canvas
                const imageData = canvasContext.getImageData(0, 0, img.naturalWidth, img.naturalHeight);

                // Decode QR code using jsQR
                const code = jsQR(imageData.data, imageData.width, imageData.height, {
                    inversionAttempts: "dontInvert"
                });

                if (code) {
                    decodedQrData = code.data;
                    console.log("QR Code decoded:", decodedQrData);
                    triggerScanAction(decodedQrData); // Automatically trigger scan after decoding
                } else {
                    decodedQrData = null; // Clear if not found
                    qrScanBtn.classList.add('d-none'); // Hide scan button if no QR
                    // Since AJAX fetch refreshes the whole page, we don't need a client-side alert here
                    // The POST request for scan_qr_data will handle it.
                }
            } catch (err) {
                console.error("Error decoding image:", err);
                decodedQrData = null; // Clear if error
                qrScanBtn.classList.add('d-none'); // Hide scan button on error
                // Simulate an error message from server by sending empty data
                triggerScanAction('');
            }
        }

        // Resets the QR scanning interface and clears displayed data
        function resetQRSection() {
            qrImageDisplay.src = '#';
            qrImageDisplay.classList.add('d-none');
            qrScanBtn.classList.add('d-none');
            // Reset the value of the file input to allow re-uploading the same file
            // if the user cancels and re-selects it.
            if (fileElem) fileElem.value = ''; 
            decodedQrData = null; // Clear decoded data
        }

        // Sends decoded QR data to the server via AJAX
        function triggerScanAction(data) {
            const formData = new FormData();
            formData.append('action', 'scan_qr_data');
            formData.append('qr_code_data', data);

            fetch(window.location.href, { // Send to the same PHP page
                method: 'POST',
                body: formData
            })
            .then(response => response.text()) // Get raw HTML response from PHP
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Get the updated content and sidebar HTML chunks from the parsed document
                const newContentWrapper = doc.getElementById('contentWrapper');
                const newSidebar = doc.getElementById('sidebar');

                if (newContentWrapper) {
                    document.getElementById('contentWrapper').innerHTML = newContentWrapper.innerHTML;
                }
                if (newSidebar && sidebar) { // Only update sidebar if current sidebar exists
                    sidebar.innerHTML = newSidebar.innerHTML;
                }

                // IMPORTANT: Reinitialize all dynamic elements as innerHTML replacement destroys listeners
                reinitializePageElements();
            })
            .catch(error => {
                console.error('Error during QR scan:', error);
                // Fallback client-side alert if connection fails
                alert('An error occurred during QR scan. Please try again.');
            });
        }


        // --- Check-in Form Functionality (Ajax submission) ---
        function handleCheckInForm(e) {
            e.preventDefault(); // Prevent default form submission
            const form = e.target;
            const formData = new FormData(form);
            
            fetch(window.location.href, { // Send to the same PHP page
                method: 'POST',
                body: formData
            })
            .then(response => response.text()) // Get raw HTML response from PHP
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                const newContentWrapper = doc.getElementById('contentWrapper');
                const newSidebar = doc.getElementById('sidebar');

                if (newContentWrapper) {
                    document.getElementById('contentWrapper').innerHTML = newContentWrapper.innerHTML;
                }
                if (newSidebar && sidebar) {
                    sidebar.innerHTML = newSidebar.innerHTML;
                }

                // IMPORTANT: Reinitialize Bootstrap components and re-attach event listeners.
                reinitializePageElements();
            })
            .catch(error => {
                console.error('Error during check-in:', error);
                alert('An error occurred during check-in. Please try again.');
            });
        }

        // Function to reinitialize all interactive elements after AJAX content load
        function reinitializePageElements() {
            // Update global element references
            sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            dropArea = document.getElementById('drop-area');
            fileElem = document.getElementById('fileElem');
            qrImageDisplay = document.getElementById('qr-image-display');
            qrScanBtn = document.getElementById('qr-scan-btn');
            
            // Re-attach sidebar toggle listener for mobile
            if (sidebarToggleBtn) {
                // Remove existing listener to prevent duplicates
                sidebarToggleBtn.removeEventListener('click', function(e){}); 
                sidebarToggleBtn.addEventListener('click', function () {
                    setSidebarOpen(!sidebar.classList.contains('is-open'));
                });
            }

            // Restore desktop hover behavior for sidebar
            if (sidebar && contentWrapper) {
                // These listener functions are anonymous, so we need to remove all existing ones then re-add
                // A more robust solution might use named functions or custom data attributes to track listeners
                // For simplicity, re-adding here after removal is common for small applications.
                sidebar.removeEventListener('mouseenter', function(){});
                sidebar.removeEventListener('mouseleave', function(){});
                
                sidebar.addEventListener('mouseenter', () => {
                    if (window.innerWidth > 768) { // Only on desktop
                        setSidebarOpen(true);
                    }
                });
                sidebar.addEventListener('mouseleave', () => {
                    if (window.innerWidth > 768) { // Only on desktop
                        setSidebarOpen(false);
                    }
                });
            }

            // Re-initialize Bootstrap collapses and their caret rotation
            document.querySelectorAll('.sidebar-nav .collapse').forEach(collapseElement => {
                // Ensure collapse is initialized if it's new content
                bootstrap.Collapse.getOrCreateInstance(collapseElement, { toggle: false });

                // Remove existing listeners for caret rotation to avoid duplicates
                collapseElement.removeEventListener('show.bs.collapse', handleShowCollapse);
                collapseElement.removeEventListener('hide.bs.collapse', handleHideCollapse);
                
                // Re-add event listeners for caret rotation
                collapseElement.addEventListener('show.bs.collapse', handleShowCollapse);
                collapseElement.addEventListener('hide.bs.collapse', handleHideCollapse);
            });
            
            // Defined as named functions for easier removal/re-attachment
            function handleShowCollapse() {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(180deg)';
                }
            }

            function handleHideCollapse() {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(0deg)';
                    const hasActiveChild = this.querySelector('.nav-link.active');
                    if (!hasActiveChild) {
                        toggleLink.classList.remove('active');
                    }
                }
            }


            // Re-attach submit listener for the new check-in form, if it exists
            const newCheckInForm = document.getElementById('check-in-form');
            if (newCheckInForm) {
                newCheckInForm.removeEventListener('submit', handleCheckInForm); // Remove old to prevent duplicates
                newCheckInForm.addEventListener('submit', handleCheckInForm);
            }
            
            // Re-attach QR scanner UI elements listeners
            if (dropArea && fileElem && qrImageDisplay && qrScanBtn) {
                // Remove existing to prevent duplicates
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropArea.removeEventListener(eventName, preventDefaults);
                });
                ['dragenter', 'dragover'].forEach(eventName => {
                    dropArea.removeEventListener(eventName, highlight);
                });
                ['dragleave', 'drop'].forEach(eventName => {
                    dropArea.removeEventListener(eventName, unhighlight);
                });
                dropArea.removeEventListener('drop', handleDrop);
                dropArea.removeEventListener('click', function(){}); // Anonymous, so direct removal is tricky; needs re-adding

                fileElem.removeEventListener('change', handleFileElemChange);
                qrScanBtn.removeEventListener('click', function(){}); // Anonymous, so direct removal is tricky; needs re-adding

                // Re-add listeners
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, preventDefaults, false);
                });
                ['dragenter', 'dragover'].forEach(eventName => {
                    dropArea.addEventListener(eventName, highlight, false);
                });
                ['dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, unhighlight, false);
                });
                dropArea.addEventListener('drop', handleDrop, false);

                // For click on drop area to open file dialog
                dropArea.addEventListener('click', () => { fileElem.click(); });

                // For handling file selection after dialog
                fileElem.addEventListener('change', handleFileElemChange);
                
                // For manual decode button click
                qrScanBtn.addEventListener('click', () => {
                    if (decodedQrData) {
                        triggerScanAction(decodedQrData);
                    } else {
                        // If no data, indicates jsQR failed or user needs to upload image
                        alert('No QR code data decoded yet. Please upload an image with a clear QR code.');
                    }
                });

                // Set initial display of image and button if data was already loaded on page reload
                // (e.g., if PHP rendered a booking_data, the image might be part of an initial form state)
                // This logic might need refinement if you want to store and re-display the actual QR image.
                // For now, it just ensures the decode button is hidden initially unless an image was loaded.
                if (qrImageDisplay.src && qrImageDisplay.src !== window.location.href + '#') { // Check if src is valid and not empty
                    qrImageDisplay.classList.remove('d-none');
                    qrScanBtn.classList.remove('d-none');
                    // If page is reloading after checkin, and booking_data was set, the original_qr_data will be available
                    // Use that to set decodedQrData for potential re-scans.
                    const originalQrDataHidden = document.getElementById('qr-code-original-data-hidden');
                    if(originalQrDataHidden) {
                       decodedQrData = originalQrDataHidden.value;
                    }
                } else {
                    qrImageDisplay.classList.add('d-none');
                    qrScanBtn.classList.add('d-none');
                }
            }

            // Apply active link styles to sidebar (after new content is loaded)
            applyActiveLinkStyles();
        }

        // Initial setup on DOMContentLoaded
        reinitializePageElements();
    });
</script>
</body>
</html>