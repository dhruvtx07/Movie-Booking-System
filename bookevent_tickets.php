<?php
session_start();
// Use the shared PDO connection from config/db_config.php
require_once 'config/db_config.php';
date_default_timezone_set('Asia/Kolkata');

require_once 'links.php';

// Enable error reporting (good for development, disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 1. CORE AUTHENTICATION & REDIRECTION CHECKS (HIGHEST PRIORITY) ---

// Define authentication-exempt pages
$auth_pages = [basename($login_page), basename($register_page), basename($forgot_pass)];

// If user is not logged in AND this is not an auth page, redirect to login
if (!isset($_SESSION['user_id'])) {
    if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
        $_SESSION['error_message'] = "User is not logged in or session expired. Please log in.";
        header("Location: {$login_page}");
        exit();
    }
}

// If we reached here, user is either on an auth page, or logged in.
// Now, handle cases for logged-in users specifically.
// This check is slightly redundant but confirms the integrity of $_SESSION['user_id']
// Only apply this part if user is supposed to be logged in (i.e. not on an auth page)
if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['error_message'] = "Your account is currently inactive or does not exist. Please contact support.";
        header("Location: {$logout_page}");
        exit();
    }
}


$userId = $_SESSION['user_id'] ?? null; // Get user ID from session for subsequent checks

// --- 2. USER ACCOUNT STATUS CHECK (FROM DB) ---
// This is critical. If the user account is inactive or not found, log them out.
if ($userId !== null) { // Only do this check if user is nominally logged in.
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM users WHERE userid = ?");
        $stmt->execute([$userId]);
        $userStatus = $stmt->fetchColumn(); // 'yes', 'no', or FALSE if user_id not found

        // If user_id not found or is_active is not 'yes' (e.g., 'no', NULL, FALSE)
        if ($userStatus !== 'yes') {
            $_SESSION['error_message'] = "Your account is currently inactive or does not exist. Please contact support.";
            header("Location: {$logout_page}");
            exit();
        }
    } catch (PDOException $e) {
        // Log sensitive error details, but show generic message to user
        error_log("Database error during user status check (ID: $userId): " . $e->getMessage());
        $_SESSION['error_message'] = "A database error occurred during account verification. Please try again.";
        header("Location: {$logout_page}");
        exit();
    }
}

$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$username = $_SESSION['username'] ?? 'User';

$_SESSION['selected_city'] = $_SESSION['selected_city'] ?? '';

// Handle city selection (if form submitted by user)
if (isset($_POST['city'])) {
    $_SESSION['selected_city'] = $_POST['city'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch active cities from database for dropdown (always fetch if logged in)
$cities = [];
try {
    $stmt = $pdo->query("SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name");
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch cities from DB: " . $e->getMessage());
    $_SESSION['error_message'] = "Could not load city list. Please try again later.";
    $cities = [
        ['city_id' => 1, 'city_name' => 'Mumbai'],
        ['city_id' => 2, 'city_name' => 'Delhi'],
        ['city_id' => 3, 'city_name' => 'Bangalore']
    ];
}

// Helper function to get city name by ID
function getCityNameById($cities, $cityId)
{
    foreach ($cities as $city) {
        if ($city['city_id'] == $cityId) {
            return $city['city_name'];
        }
    }
    return 'Select City';
}

// Search functionality (if a search term is present in GET params)
$searchResults = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = '%' . $_GET['search'] . '%';
    try {
        $stmt = $pdo->prepare("SELECT event_id, event_name, event_image, event_type FROM event_info
          WHERE event_name LIKE :search AND is_active = 'yes'
          ORDER BY event_name LIMIT 10");
        $stmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
        $stmt->execute();
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Search functionality failed: " . $e->getMessage());
        $_SESSION['error_message'] = "Error during search. Please try again.";
        $searchResults = [];
    }
}

// --- 4. TICKET BOOKING SPECIFIC VALIDATION AND DATA FETCHING ---

// Get parameters for this specific page (bookevent_tickets.php)
$scheduleId = filter_input(INPUT_GET, 'schedule_id', FILTER_VALIDATE_INT);
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

// Fallback direct URL for booking pages (if events_page is not suitable)
$fallbackBookEvent = $book_event ?? $home_page;

// Validate that necessary schedule and event IDs are present in URL
if (!$scheduleId || !$eventId) {
    $_SESSION['error_message'] = "Invalid event or showtime selected. Please choose an event and showtime to book tickets.";
    header("Location: $events_page");
    exit();
}

// --- 5. FETCH ALL SCHEDULE/EVENT/VENUE/CITY DETAILS IN ONE QUERY ---
try {
    $stmt = $pdo->prepare("
        SELECT
            es.event_schedule_id, es.venue_id, es.event_schedule_id,
            es.is_active AS event_schedule_active,
            vs.slot_starts_at, vs.is_active AS venue_schedule_active,
            ei.event_name, ei.event_language, ei.genre,
            ei.event_duration AS duration,
            ei.is_active AS event_active,
            v.venue_name, v.sub_venue_name, v.is_active AS venue_active,
            c.city_id, c.city_name, c.is_active AS city_active
        FROM event_schedule es
        JOIN venues v ON es.venue_id = v.venue_id
        JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
        JOIN event_info ei ON es.event_id = ei.event_id
        JOIN cities c ON v.city_id = c.city_id
        WHERE es.event_schedule_id = ? AND es.event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$scheduleId, $eventId]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- 6. VALIDATE FETCHED SCHEDULE DATA & ITS ACTIVE STATUSES ---

    // Check if a schedule was found at all for the given IDs
    if (!$schedule) {
        $_SESSION['error_message'] = "The selected event showtime could not be found or has been removed.";
        header("Location: $events_page");
        exit();
    }

    // Validate active statuses AFTER confirming the schedule exists
    if ($schedule['event_active'] !== 'yes') {
        $_SESSION['error_message'] = "The selected event is no longer active or available for booking. Please choose another event.";
        header("Location: {$events_page}");
        exit();
    }
    if ($schedule['venue_active'] !== 'yes') {
        $_SESSION['error_message'] = "The venue for this event is currently inactive. Please select another event or venue.";
        header("Location: {$events_page}");
        exit();
    }
    if ($schedule['city_active'] !== 'yes') {
        $_SESSION['error_message'] = "The city (" . (isset($schedule['city_name']) ? $schedule['city_name'] : 'unknown') . ") where this event is held is currently inactive.";
        header("Location: {$home_page}"); // Changed to home_page as city affects all events
        exit();
    }
    if ($schedule['event_schedule_active'] !== 'yes' || $schedule['venue_schedule_active'] !== 'yes') {
        $_SESSION['error_message'] = "The selected showtime is no longer available or active. Please check the timings and try again.";
        header("Location: {$events_page}");
        // Changed to events_page to prevent infinite redirect if $book_event is same as $events_page
        exit();
    }

    // --- 7. FETCH ALL SEATS/TICKETS FOR THIS SCHEDULE AND ORGANIZE FOR UNIFIED MAP ---
    $ticketsStmt = $pdo->prepare("
        SELECT ticket_id, ticket_type, ticket_row, ticket_column, ticket_location, ticket_price, is_vacant, is_held, held_by_user_id, held_until
        FROM event_schedule_tickets
        WHERE event_schedule_id = ? AND is_active = 'yes'
        ORDER BY ticket_row, ticket_column
    ");
    $ticketsStmt->execute([$scheduleId]);
    $allTickets = $ticketsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allTickets)) {
        $_SESSION['error_message'] = "No seats available for this showtime. It might be sold out or not configured.";
        header("Location: " . $book_event . "?event_id=" . ($eventId ?? '') . "&date=" . date('Y-m-d'));
        exit();
    }

    // NEW LOGIC FOR UNIFIED SEAT MAP
    $unifiedSeatMap = [
        'rows' => [],
        'columns' => [],
        'allSeatsData' => [], // Stores full seat data including availability
        'ticketPricesByType' => [], // Stores prices per ticket type for legend
        'ticketLocationsByType' => [] // Stores locations per ticket type for legend (optional)
    ];

    foreach ($allTickets as $ticket) {
        $row = $ticket['ticket_row'];
        $column = (int)$ticket['ticket_column']; // Ensure column is integer for sorting
        $type = $ticket['ticket_type'];
        $price = $ticket['ticket_price'];

        // Determine seat availability considering 'is_vacant' and 'is_held' state
        $isVacant = $ticket['is_vacant'] === 'yes';
        $isHeldByCurrentUser = ($ticket['is_held'] === 'yes' && $ticket['held_by_user_id'] == $userId);
        // Check if held by others and the hold has NOT expired
        // FIX APPLIED HERE: Added check for null before strtotime()
        $isHoldExpired = ($ticket['held_until'] !== null && strtotime($ticket['held_until']) < time());
        $isHeldByOthers = ($ticket['is_held'] === 'yes' && $ticket['held_by_user_id'] != $userId && !$isHoldExpired);

        // Store seat data in a structure indexed by row/column
        if (!isset($unifiedSeatMap['allSeatsData'][$row])) {
            $unifiedSeatMap['allSeatsData'][$row] = [];
        }
        $unifiedSeatMap['allSeatsData'][$row][$column] = [
            'data' => $ticket,
            // A seat is available for selection if it's vacant AND not held by another user.
            'available_for_selection' => $isVacant && !$isHeldByOthers,
            'held_by_current_user' => $isHeldByCurrentUser, // To initially mark as selected if held by current user
            'held_by_others' => $isHeldByOthers // To mark as held by others visually
        ];

        // Collect unique rows and columns
        if (!in_array($row, $unifiedSeatMap['rows'])) {
            $unifiedSeatMap['rows'][] = $row;
        }
        if (!in_array($column, $unifiedSeatMap['columns'])) {
            $unifiedSeatMap['columns'][] = $column;
        }

        // Collect prices for each ticket type (for the legend)
        if (!isset($unifiedSeatMap['ticketPricesByType'][$type])) {
            $unifiedSeatMap['ticketPricesByType'][$type] = $price;
        }
        // Collect locations for each ticket type (optional, for legend)
        if (!isset($unifiedSeatMap['ticketLocationsByType'][$type])) {
            $unifiedSeatMap['ticketLocationsByType'][$type] = [];
        }
        if (!in_array($ticket['ticket_location'], $unifiedSeatMap['ticketLocationsByType'][$type])) {
            $unifiedSeatMap['ticketLocationsByType'][$type][] = $ticket['ticket_location'];
        }
    }

    // Sort rows (alphabetically) and columns (numerically)
    sort($unifiedSeatMap['rows']);
    sort($unifiedSeatMap['columns'], SORT_NUMERIC);

    // This order is also used in the legend
    $orderedTypes = ['Regular', 'Recliner', 'Premium', 'VIP'];

} catch (PDOException $e) {
    error_log("Error fetching schedule or ticket mapping data: " . $e->getMessage());
    $_SESSION['error_message'] = "A system error occurred while preparing the seat map. Please try again later. (Code: TMAP_LOAD_DB_ERR)";
    header("Location: $events_page");
    exit();
}

// --- 8. HANDLE FORM SUBMISSION FOR TICKET SELECTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_tickets'])) {
    $selectedTicketsData = json_decode($_POST['selected_tickets'], true);

    if (empty($selectedTicketsData)) {
        $_SESSION['error_message'] = "No tickets were selected. Please select at least one ticket to proceed.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?schedule_id=$scheduleId&event_id=$eventId");
        exit();
    }

    $unavailableTickets = [];
    $ticketsToHold = []; // Renamed from ticketsToUpdate, as we are now holding, not finalizing

    try {
        // Collect all ticket IDs from the submitted data
        $submittedTicketIds = array_keys($selectedTicketsData);

        // Start a transaction for the validation AND hold operation
        $pdo->beginTransaction();

        // Prepare query to fetch current status of all submitted tickets FOR UPDATE (lock rows)
        // This is crucial for preventing race conditions.
        $placeholders = implode(',', array_fill(0, count($submittedTicketIds), '?'));
        $stmt = $pdo->prepare("SELECT ticket_id, is_vacant, is_held, held_by_user_id, held_until FROM event_schedule_tickets WHERE ticket_id IN ($placeholders) AND event_schedule_id = ? AND is_active = 'yes' FOR UPDATE");
        $params = array_merge($submittedTicketIds, [$scheduleId]);
        $stmt->execute($params);
        $verifiedTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Index verified tickets by ID for quick lookup
        $verifiedTicketStatus = [];
        foreach ($verifiedTickets as $ticket) {
            $verifiedTicketStatus[$ticket['ticket_id']] = $ticket;
        }

        foreach ($submittedTicketIds as $ticketId) {
            $ticketAttrs = $selectedTicketsData[$ticketId]; // Get data from submitted JSON

            if (!isset($verifiedTicketStatus[$ticketId])) {
                // Ticket not found or inactive (e.g., removed by admin or was not for this schedule), cannot proceed.
                $unavailableTickets[] = ($ticketAttrs['seatId'] ?? $ticketId) . ' (Not Found/Inactive)';
                continue;
            }

            $currentTicketState = $verifiedTicketStatus[$ticketId];

            // Check if it's vacant AND NOT held by someone else (or if held by current user, the hold is already there or expired).
            // FIX APPLIED HERE: Added check for null before strtotime() to prevent deprecation warning.
            $isTheirHoldExpired = ($currentTicketState['held_until'] !== null && strtotime($currentTicketState['held_until']) < time());
            $isHeldByOthers = ($currentTicketState['is_held'] === 'yes' && $currentTicketState['held_by_user_id'] != $userId && !$isTheirHoldExpired);

            if ($currentTicketState['is_vacant'] !== 'yes' || $isHeldByOthers) {
                // This ticket is either already booked (is_vacant='no') OR held by someone else AND their hold is still valid
                $unavailableTickets[] = $ticketAttrs['seatId'] ?? $ticketId;
            } else {
                // This ticket is truly available or held by the current user (and potentially expired), mark it for holding
                $ticketsToHold[] = $ticketId;
            }
        }

        if (!empty($unavailableTickets)) {
            $pdo->rollBack(); // Rollback any locking performed by FOR UPDATE
            $_SESSION['error_message'] = "Oops! Some selected seats are no longer available or active: " . implode(', ', $unavailableTickets) . ". Please choose again.";
            $_SESSION['selected_tickets_json'] = $_POST['selected_tickets']; // Preserve user's selection for repopulation
            header("Location: " . $_SERVER['PHP_SELF'] . "?schedule_id=$scheduleId&event_id=$eventId");
            exit();
        }

        // If all selected tickets are valid, proceed to hold them
        if (!empty($ticketsToHold)) {
            $holdPlaceholders = implode(',', array_fill(0, count($ticketsToHold), '?'));
            // Set is_held to 'yes', held_by_user_id, and held_until for 5 minutes from now.
            // is_vacant remains 'yes' because it's only a hold, not a final booking.
            $holdStmt = $pdo->prepare("UPDATE event_schedule_tickets SET is_held = 'yes', held_by_user_id = ?, held_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE ticket_id IN ($holdPlaceholders)");
            // The first parameter is $userId, followed by the ticket IDs for the IN clause.
            $holdStmt->execute(array_merge([$userId], $ticketsToHold));
        } else {
            // This should theoretically not happen if $selectedTicketsData was not empty
            // (Unless user selects, then re-selects same tickets, and validation above already marked them available but they are cleared in ticketsToHold somehow)
            $pdo->rollBack();
            $_SESSION['error_message'] = "No valid tickets found to process from your selection. Please select again.";
            header("Location: " . $_SERVER['PHP_SELF'] . "?schedule_id=$scheduleId&event_id=$eventId");
            exit();
        }

        // --- Store booking data in session for processing by booking_processor ---
        // This is crucial as the booking_processor needs details of selected items.
        $_SESSION['booking_data'] = [
            'user_id' => $userId,
            'event_id' => $eventId,
            'schedule_id' => $scheduleId,
            'venue_id' => $schedule['venue_id'],
            'selected_tickets' => $selectedTicketsData, // Use the submitted data which contains original details
            'event_name' => $schedule['event_name'],
            'duration' => $schedule['duration'],
            'genre' => $schedule['genre'],
            'language' => $schedule['event_language'],
            'venue_name' => $schedule['venue_name'],
            'sub_venue_name' => $schedule['sub_venue_name'],
            'slot_time' => $schedule['slot_starts_at'],
            // Add the actual ticket IDs that were successfully held so booking_processor and payment can use them
            'held_ticket_ids' => $ticketsToHold
        ];

        $pdo->commit(); // Commit the transaction if all updates were successful
        unset($_SESSION['selected_tickets_json']); // Clear the session variable used for re-populating on error
        header("Location: $booking_processor");
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack(); // Rollback in case of any database error during validation or update
        error_log("Error during ticket selection and holding (ID: $userId): " . $e->getMessage());
        $_SESSION['error_message'] = "A system error occurred during seat verification. Please try again. (Code: TMAP_SUBMIT_DB_ERR)";
        header("Location: " . $_SERVER['PHP_SELF'] . "?schedule_id=$scheduleId&event_id=$eventId");
        exit();
    }
}

// Ensure $selectedTicketsFromSession is initialized even if not from $_SESSION
$selectedTicketsJson = $_SESSION['selected_tickets_json'] ?? '[]';
unset($_SESSION['selected_tickets_json']); // Important to unset after decoding to prevent stale data
// --- HTML BEGINS ---
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Tickets - <?= htmlspecialchars($schedule['event_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* --- CSS variables from home.php --- */
        :root { /* Default/Light mode */
            --primary-bg: #ffffff; /* Body background */
            --secondary-bg: #f8f9fa; /* Card background, filter background */
            --text-color: #141414; /* Main text */
            --accent-red: #e50914; /* Strong red accent */
            --accent-orange: #ff6b35; /* Orange accent */
            --accent-peach: #ff9e7d; /* Lighter orange accent */
            --accent-black: #141414; /* Logo text/dark text */
            --card-bg: #ffffff; /* Specific card background */
            --nav-dark: #141414; /* Navbar background */
            --nav-text: #ffffff; /* Navbar text */
            --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
            --border-color: rgba(0,0,0,0.1); /* General border */
            --form-border: rgba(0,0,0,0.1); /* For search/city dropdown border */
            --input-bg: rgba(0,0,0,0.03); /* For search input background */
            --placeholder-color: rgba(0,0,0,0.4); /* For placeholder text */
            --divider-color: rgba(0,0,0,0.1); /* For HR/dividers */
            --link-color: var(--accent-red);
            --link-hover: var(--accent-orange);
            --highlight-color: #FFFF00; /* Used for selected seats (unique to this file) */
            --light-color: #FFDAB9; /* Used for VIP seats (unique to this file) */
            --search-result-bg: var(--card-bg);
            --search-result-text: var(--text-color);
            --search-result-hover: var(--secondary-bg);
            --search-result-border: rgba(0,0,0,0.1);
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
            --border-color: rgba(255,255,255,0.1);
            --form-border: rgba(255,255,255,0.1);
            --input-bg: rgba(255,255,255,0.1);
            --placeholder-color: rgba(255,255,255,0.4);
            --divider-color: rgba(255,255,255,0.1);
            --link-color: var(--accent-red);
            --link-hover: var(--accent-orange);
            --highlight-color: #FFFF00; /* Used for selected seats */
            --light-color: #FFDAB9; /* Used for VIP seats */
            --search-result-bg: var(--secondary-bg);
            --search-result-text: var(--text-color);
            --search-result-hover: rgba(255,255,255,0.05);
            --search-result-border: var(--form-border);
        }

        body {
            padding-top: 110px; /* Default: Accounts for both navbars on large screens */
            background-color: var(--primary-bg);
            color: var(--text-color);
            transition: all 0.3s ease;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 0.9rem;
        }

        /* --- Navbar Styles from home.php --- */
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
            position: fixed; /* Added fixed position */
            top: 80px; /* Height of the first navbar */
            left: 0;
            right: 0;
            z-index: 1020;
            white-space: nowrap;
            overflow-x: auto;
            overflow-y: hidden; /* Changed from hidden in home.php to prevent scrollbar */
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            margin-top: 0; /* Ensure no margin */
            border-top: none; /* Remove any border */
            display: block !important; /* ADDED/CONFIRMED: Ensure it's block by default for wider screens, will be overridden by media query */
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
            color: var(--nav-text); /* Ensure color from variable */
            position: relative; /* Ensure relative pos for ::after */
            transition: all 0.3s ease; /* Ensure transition */
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
            opacity: 0; /* Hidden by default */
        }

        .second-navbar .nav-link:hover::after {
            width: 100%;
            opacity: 1;
        }

        .second-navbar .nav-link:hover {
            transform: translateY(-2px);
            color: var(--nav-text); /* Keep text color on hover */
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
            background-color: var(--search-result-bg);
            border: 1px solid var(--search-result-border);
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            z-index: 1000;
            max-height: 400px;
            overflow-y: auto;
            display: none;
            color: var(--search-result-text);
        }

        .search-result-item {
            padding: 10px 15px;
            border-bottom: 1px solid var(--search-result-border);
            transition: all 0.2s;
            color: var(--search-result-text);
            text-decoration: none;
            display: block;
        }

        .search-result-item:hover {
            background-color: var(--search-result-hover);
            color: var(--search-result-text);
        }

        .search-result-item:last-child { /* Added from home.php */
            border-bottom: none;
        }

        .search-result-type {
            font-size: 0.8rem;
            color: var(--accent-orange);
            text-transform: capitalize;
            font-weight: 500;
        }

        .search-result-name { /* Added from home.php */
            font-weight: 500;
            transition: all 0.2s;
        }

        .search-result-item:hover .search-result-name { /* Added from home.php */
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
            margin-right: 10px; /* Adjusted to keep spacing */
        }

        .dropdown-menu {
            z-index: 1050;
            background-color: var(--nav-dark);
            border: 1px solid var(--form-border);
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
            border-bottom: 1px solid var(--form-border);
        }

        .city-search-input {
            background-color: var(--input-bg);
            color: var(--text-color);
            border: none;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            width: 100%;
        }

        .city-search-input:focus { /* Copied from home.php */
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
            color: white; /* Text color on hover */
            transform: translateX(5px); /* Added from home.php */
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
            opacity: 0; /* Hidden by default */
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
            cursor: pointer;
            color: white;
            background: transparent;
            border: none;
            font-size: 1.2rem;
        }

        /* Mobile menu dropdown - hidden by default on large screens */
        .mobile-menu-dropdown {
            display: none; /* ADDED/CONFIRMED */
        }

        .mobile-menu-toggle {
            display: none; /* ADDED/CONFIRMED */
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

        /* General Alerts (Consistent with home.php) */
        .alert {
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            color: var(--text-color); /* Ensure text color from variable */
            background-color: var(--secondary-bg); /* Default background color */
        }
        .alert-info {
            background: var(--gradient-secondary);
            color: white;
            border: none;
        }
        .alert-warning { /* Copied from home.php */
            background: var(--gradient-primary);
            color: white;
            border: none;
        }
        .alert-danger { /* Specific style for error messages */
            background-color: var(--accent-red);
            color: white;
            border: none;
        }


        /* Global Body & Container Styles (Original, adapted to new variables) */
        .container {
            max-width: 1200px;
            padding-top: 1rem;
            padding-bottom: 2rem;
        }

        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--form-border);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .card-header {
            background: var(--gradient-primary) !important;
            color: white;
            font-weight: 700;
            border-bottom: none;
        }

        .venue-map {
            background-color: var(--secondary-bg); /* Mapped from --container-bg */
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border: 1px solid var(--form-border);
        }

        .stage-indicator {
            text-align: center;
            font-weight: bold;
            margin: 15px 0;
            padding: 12px;
            background: var(--gradient-primary); /* Mapped from --bms-gradient */
            color: white;
            border-radius: 8px;
            font-size: 1rem;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3); /* Kept original shadow color, it's defined inside */
        }

        .legend {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            padding: 12px;
            background-color: var(--input-bg); /* Mapped from --form-bg */
            border-radius: 8px;
            border: 1px solid var(--form-border);
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin-right: 12px;
            font-size: 0.8rem;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            margin-right: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* --- NEW CSS FOR LEGEND COLORS - using new vars --- */
        .legend-color.regular { background-color: var(--accent-peach); }
        .legend-color.recliner { background-color: var(--accent-red); }
        .legend-color.premium { background-color: var(--accent-orange); } /* Mapped from --accent-color */
        .legend-color.vip { background-color: var(--light-color); }
        .legend-color.disabled { /* For the 'Booked' status in legend */
            background-color: var(--form-border) !important; /* Mapped from --form-bg, which is input-bg for active elements */
        }

        .seat-map-container {
            display: flex;
            flex-direction: column;
            gap: 30px;
            align-items: center;
            margin-top: 20px;
        }

        .seat-grid {
            display: inline-block;
            border: 1px solid var(--form-border);
            border-radius: 8px;
            overflow: hidden;
            background-color: var(--secondary-bg); /* Mapped from --container-bg */
            margin: 0 auto;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .seat-row {
            display: flex;
            justify-content: center;
        }

        .seat-cell {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--form-border);
            position: relative;
            margin: 2px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .seat-cell.header {
            background-color: var(--input-bg); /* Mapped from --form-bg */
            font-weight: 600;
            border: none;
            color: var(--text-color);
            font-size: 0.75rem;
        }

        .seat-cell.row-header {
            background-color: var(--input-bg); /* Mapped from --form-bg */
            font-weight: 600;
            min-width: 28px;
            border: none;
            color: var(--text-color);
            font-size: 0.75rem;
            margin-right: 8px;
        }

        .seat {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            border-radius: 4px;
            color: white;
            font-weight: 600;
            font-size: 0.65rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .seat:hover {
            transform: scale(1.15);
            box-shadow: 0 0 8px rgba(255,255,255,0.3);
        }

        .seat.selected {
            transform: scale(1.15);
            box-shadow: 0 0 12px rgba(255,255,0,0.8);
            background-color: var(--highlight-color) !important;
            color: #000;
            z-index: 1;
        }

        /* Dynamic seat colors based on type - using new vars */
        .seat.regular { background-color: var(--accent-peach); }
        .seat.recliner { background-color: var(--accent-red); }
        .seat.premium { background-color: var(--accent-orange); } /* Mapped from --accent-color */
        .seat.vip { background-color: var(--light-color); color: #000; }

        .disabled.seat {
            background-color: var(--form-border); /* Mapped from --form-bg */
            color: var(--placeholder-color);
            cursor: not-allowed;
            position: relative; /* Keep this if the icon needs relative positioning */
        }

        .disabled.seat i {
            font-size: 0.8rem;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        /* The .seat-label CSS is no longer needed for disabled seats, but kept for future use if design changes */
        .seat-label {
            position: absolute; /* This might be problematic if not carefully used */
            bottom: -16px;
            font-size: 0.6rem;
            color: var(--placeholder-color);
            width: 100%;
            text-align: center;
        }

        .ticket-info {
            background-color: var(--secondary-bg); /* Mapped from --container-bg */
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            position: sticky;
            top: 120px; /* Default for large screens (two navbars) */
            transition: all 0.3s ease;
            border: 1px solid var(--form-border);
            width: 100%; /* Ensure it takes full column width */
            max-height: calc(100vh - 140px); /* Limit height for stickiness */
            overflow-y: auto; /* Allow scrolling if content is too long */
        }

        .selected-ticket-type {
            background-color: var(--input-bg); /* Mapped from --form-bg */
            padding: 12px;
            margin-bottom: 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            border: 1px solid var(--form-border);
        }

        .total-amount {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 15px 0;
            padding-top: 12px;
            border-top: 1px solid var(--divider-color);
            color: var(--accent-red); /* Mapped from --secondary-color */
        }

        .selected-seats-list {
            margin-top: 8px;
            font-size: 0.85rem;
            color: var(--placeholder-color);
        }

        .btn-bms {
            background: var(--gradient-primary); /* Mapped from --bms-gradient */
            border: none;
            padding: 12px;
            font-weight: 700;
            border-radius: 8px;
            width: 100%;
            color: white;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
        }

        .btn-bms:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(230, 57, 70, 0.4);
            color: white;
        }

        .btn-bms:disabled {
            opacity: 0.7;
            transform: none;
            box-shadow: none;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
        }

        .theme-toggle {
            cursor: pointer;
            color: white;
            background: transparent;
            border: none;
            font-size: 1.2rem;
        }

        /* Responsive */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .venue-map {
            animation: fadeIn 0.6s ease-out forwards;
        }
        .ticket-info {
            animation: fadeIn 0.6s ease-out forwards;
            animation-delay: 0.2s;
        }

        /* Add this to your existing CSS */
        .fade-out {
            animation: fadeOut 3s ease-out forwards;
        }

        @keyframes fadeOut {
            0% { opacity: 1; }
            100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }
        }

        /*
        * ADJUSTED MEDIA QUERIES FOR NAV BARS
        * The second navbar will now hide below 768px (Bootstrap's 'md' breakpoint)
        * This allows it to be visible on most laptops and desktops.
        */

        /* Devices smaller than 768px (e.g., tablets in portrait, phones) */
        @media (max-width: 767.98px) { /* Changed from 992px */
            body {
                padding-top: 54px; /* Only top navbar visible */
            }
            .second-navbar {
                display: none !important; /* Explicitly hide second navbar */
            }
            .mobile-menu-toggle {
                display: block; /* Show mobile toggle */
            }
            .mobile-menu-dropdown {
                display: block; /* Show mobile dropdown wrapper */
            }
            /* Adjusted sticky top for when only one navbar is present (54px topbar + ~20px margin) */
            .ticket-info {
                top: 74px;
            }
            /* Fix for mobile menu dropdown positioning */
            .mobile-menu-dropdown .dropdown-menu {
                left: 0 !important;
                right: auto !important;
                width: calc(100% - 30px);
                margin-left: 15px;
                margin-right: 15px;
                box-sizing: border-box;
            }
            /* General mobile layout adjustments */
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
            .card{
                margin-top: 10px;
            }
            .card-header{
                margin-top: 10px;
            }
        }

        /* Devices 768px and wider (e.g., most laptops and desktops) */
        @media (min-width: 768px) { /* Changed from 993px */
            .second-navbar {
                display: block !important; /* Ensure second navbar is visible */
            }
            /* Also ensure mobile menu is hidden on these larger screens */
            .mobile-menu-toggle,
            .mobile-menu-dropdown {
                display: none !important;
            }
        }

        /* Existing media query for very small mobile screens (e.g., phones) */
        @media (max-width: 576px) {
            .card{
                margin-top: 40px;
            }
            .card-header{
                margin-top: 10px;
            }
            body {
                padding-top: 60px; /* Specific padding for very small screens */
            }

            .container {
                padding: 0.75rem;
            }
            /* Adjusted sticky top for very small screens (60px topbar + ~20px margin) */
            .ticket-info {
                top: 80px;
            }
        }

        /* Other responsive styles (original, kept) */
        @media (max-width: 768px) { /* This media query is now redundant in terms of breakpoint 768px, but its rules are still applied */
            .seat-cell {
                width: 20px;
                height: 20px;
            }
            .container {
                padding-left: 15px;
                padding-right: 15px;
            }
            .seat-label {
                bottom: -16px;
                font-size: 0.55rem;
            }
            .venue-map {
                padding: 15px;
            }
        }

        @media (max-width: 768px) { /* This media query is also largely redundant with the new 767.98px one in terms of breakpoint, but its rules remain */
            .card{
                margin-top: 10px;
            }
            .card-header{
                margin-top: 10px;
            }
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
                <p class="lead">Please <a href="<?= $login_page ?>">login</a> to access all features</p>
            </div>
        <?php elseif (empty($_SESSION['selected_city'])): ?>
            <div class="text-center py-5">
                <h2>Please Select a City</h2>
                <p class="lead">To view events and book tickets, please select your city from the dropdown in the navigation bar.</p>
            </div>
        <?php elseif (isset($_GET['search']) && !empty($_GET['search'])): ?>
            <?php if (isset($_POST['city'])): ?>
                <div class="alert alert-info mb-4" id="cityAlert">
                    Showing content for: <strong><?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?></strong>
                </div>
            <?php endif; ?>
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
                                            <a href="<?= $event_info_page ?>?id=<?= $result['event_id'] ?>" class="btn btn-sm btn-danger mt-2">View Details</a>
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
        <?php else: // THIS IS THE MAIN 'ELSE' BLOCK FOR THE BOOKING UI. Start of line 245 in the original. ?>
            <?php if (isset($_POST['city'])): ?>
                <div class="alert alert-info mb-4" id="cityAlert">
                    You are viewing events for: <strong><?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?></strong>
                </div>
            <?php endif; ?>

            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner-border text-warning loading-spinner" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <div class="container">
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= $_SESSION['error_message'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
            </div>

            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card mb-4 border-0">
                        <div class="card-header py-3">
                            <h3 class="mb-0"><i class="fas fa-ticket-alt me-2"></i>Book Tickets - <?= htmlspecialchars($schedule['event_name']) ?></h3>
                        </div>
                        <div class="card-body p-3">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p class="mb-2"><i class="bi bi-calendar-event me-2"></i>
                                        <?= (new DateTime($schedule['slot_starts_at']))->format('l, F j, Y \a\t h:i A') ?>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><i class="bi bi-geo-alt me-2"></i>
                                        <?= htmlspecialchars($schedule['venue_name']) ?> -
                                        <?= htmlspecialchars($schedule['sub_venue_name']) ?>
                                    </p>
                                </div>
                            </div>

                            <?php if (empty($allTickets)): ?>
                                <div class="alert alert-danger py-2">
                                    <i class="bi bi-exclamation-triangle me-2"></i> No tickets available for this showtime. Please return to showtimes.
                                </div>
                                <a href="<?= $book_event ?>?event_id=<?php echo isset($eventId) ? htmlspecialchars($eventId) : ''; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary btn-sm">
                                    <i class="bi bi-arrow-left me-2"></i> Back to Showtimes
                                </a>

                            <?php else: ?>
                                <div class="row"> <!-- This row wraps the venue map and ticket info columns -->
                                    <div class="col-lg-8">
                                        <div class="venue-map">
                                            <div class="stage-indicator">
                                                <i class="bi bi-cast me-2"></i> STAGE AREA
                                            </div>

                                            <div class="legend">
                                                <?php foreach ($orderedTypes as $type): ?>
                                                    <?php if (isset($unifiedSeatMap['ticketPricesByType'][$type])): ?>
                                                        <div class="legend-item">
                                                            <div class="legend-color <?= strtolower($type) ?>"></div>
                                                            <span><?= htmlspecialchars($type) ?> (₹<?= htmlspecialchars($unifiedSeatMap['ticketPricesByType'][$type]) ?>)</span>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <div class="legend-item">
                                                    <div class="legend-color" style="background-color: var(--highlight-color);"></div>
                                                    <span>Selected</span>
                                                </div>
                                                <div class="legend-item">
                                                    <div class="legend-color disabled"></div>
                                                    <span>Booked</span>
                                                </div>
                                            </div>

                                            <div class="seat-map-container">
                                                <h5 class="mb-3 text-center">Select Your Seats</h5>
                                                <div class="d-flex justify-content-center">
                                                    <div class="seat-grid">
                                                        <!-- Column headers -->
                                                        <div class="seat-row">
                                                            <div class="seat-cell row-header"></div>
                                                            <?php foreach ($unifiedSeatMap['columns'] as $col): ?>
                                                                <div class="seat-cell header"><?= $col ?></div>
                                                            <?php endforeach; ?>
                                                        </div>

                                                        <!-- Seat rows -->
                                                        <?php foreach ($unifiedSeatMap['rows'] as $row): ?>
                                                            <div class="seat-row">
                                                                <div class="seat-cell row-header"><?= $row ?></div>
                                                                <?php foreach ($unifiedSeatMap['columns'] as $col): ?>
                                                                    <div class="seat-cell">
                                                                        <?php
                                                                        $seatData = $unifiedSeatMap['allSeatsData'][$row][$col]['data'] ?? null;
                                                                        // FIX APPLIED HERE: Use 'available_for_selection' from the unifiedSeatMap
                                                                        $isAvailable = $unifiedSeatMap['allSeatsData'][$row][$col]['available_for_selection'] ?? false;
                                                                        ?>
                                                                        <?php if ($seatData): ?>
                                                                            <?php if ($isAvailable): ?>
                                                                                <div class="seat <?= strtolower($seatData['ticket_type']) ?>"
                                                                                    data-ticket-id="<?= $seatData['ticket_id'] ?>"
                                                                                    data-ticket-type="<?= htmlspecialchars($seatData['ticket_type']) ?>"
                                                                                    data-ticket-price="<?= $seatData['ticket_price'] ?>"
                                                                                    data-seat-id="<?= htmlspecialchars($seatData['ticket_location']) ?>"
                                                                                    data-seat-location="<?= htmlspecialchars($seatData['ticket_location']) ?>"
                                                                                    onclick="selectSeat(this)">
                                                                                    <?= htmlspecialchars($seatData['ticket_location']) ?>
                                                                                </div>
                                                                            <?php else: ?>
                                                                                <div class="seat disabled" title="Already booked">
                                                                                    <i class="bi bi-x-lg"></i>
                                                                                    <!-- Removed seat-label div here to prevent visual overlap -->
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div> <!-- Corrected closing div for col-lg-8 -->

                                    <div class="col-lg-4"> <!-- This col-lg-4 is now a sibling, not nested -->
                                        <div class="ticket-info">
                                            <h5 class="mb-3"><i class="bi bi-cart-check me-2"></i> Your Selection</h5>

                                            <div id="selectedTicketsContainer">
                                                <p class="text-muted small">No tickets selected yet</p>
                                            </div>

                                            <div class="total-amount">
                                                Total: ₹<span id="totalAmount">0</span>
                                            </div>

                                            <form id="bookingForm" action="" method="post">
                                                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                                <input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
                                                <input type="hidden" name="selected_tickets" id="selectedTicketsInput" value="<?= htmlspecialchars($selectedTicketsJson) ?>">

                                                <div class="d-grid gap-2">
                                                    <button type="submit" id="proceedToPayment" class="btn btn-bms" disabled>
                                                        <i class="bi bi-credit-card me-2"></i> Proceed to Payment
                                                    </button>
                                                    <a href="<?= $book_event ?>?event_id=<?php echo isset($eventId) ? htmlspecialchars($eventId) : ''; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary btn-sm">
                                                        <i class="bi bi-arrow-left me-2"></i> Back to Showtimes
                                                    </a>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; // Closes empty($allTickets) block ?>
                        </div> <!-- Closing div for .card-body -->
                    </div> <!-- Closing div for .card -->
                </div> <!-- Closing div for .col-md-12 -->
            </div> <!-- Closing div for .row.mt-4 -->
        <?php endif; // Closes the main 'else' block for booking UI ?>
    </div> <!-- Closing div for .container.my-4 -->

    <footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Catchify. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Theme toggle functionality (Copied from home.php) ---
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

            // --- City search functionality (Copied from home.php) ---
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

            // --- Search functionality with AJAX (Copied from home.php) ---
            const searchInput = document.getElementById('searchInput');
            const searchResults = document.getElementById('searchResults');

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.trim();

                    if (searchTerm.length >= 2) {
                        fetch(`<?= $search; ?>?search=${encodeURIComponent(searchTerm)}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.length > 0) {
                                    let html = '';
                                    data.forEach(item => {
                                        html += `
                                        <a href="<?= $event_info_page ?>?id=${item.event_id}" class="search-result-item">
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


            // --- Seat selection functionality (Original, adapted) ---
            let selectedTickets = {};

            <?php
            // PHP code to re-populate JS selectedTickets from session IF an error occurred
            // and selected_tickets_json was intentionally preserved.
            // THIS IS CRITICAL FOR USER EXPERIENCE AFTER AN ERROR REDIRECT.
            if (isset($_SESSION['selected_tickets_json'])): ?>
            try {
                const preSelectedJson = '<?= $_SESSION['selected_tickets_json'] ?>';
                const preSelectedData = JSON.parse(preSelectedJson);
                if (typeof preSelectedData === 'object' && preSelectedData !== null) {
                    selectedTickets = preSelectedData;
                    // Loop through the selected tickets from session and apply 'selected' class
                    // Use a slight delay to ensure all seats are rendered before trying to select them
                    setTimeout(() => {
                        Object.values(selectedTickets).forEach(ticket => {
                            const seatElement = document.querySelector(`.seat[data-ticket-id="${ticket.id}"]`);
                            if (seatElement) {
                                seatElement.classList.add('selected');
                            }
                        });
                        updateSelectionDisplay(); // Also update display once initial selection is done
                    }, 100); // Small delay to allow DOM to render fully
                }
            } catch (e) {
                console.error('Error parsing pre-selected tickets JSON:', e);
                selectedTickets = {}; // Reset if parsing fails
            }
            <?php endif; ?>


            // Attach click listeners to all available seats
            document.querySelectorAll('.seat').forEach(seat => {
                // Only attach if it's not a disabled/booked seat
                if (!seat.classList.contains('disabled')) {
                    seat.addEventListener('click', function() {
                        selectSeat(this);
                    });
                }
            });

            function selectSeat(seatElement) {
                const ticketId = seatElement.getAttribute('data-ticket-id');

                // Disable interaction if seat is currently held by someone else or already booked
                // This check is duplicated with PHP-side logic but provides immediate UI feedback
                if (seatElement.classList.contains('disabled')) {
                    return;
                }

                if (seatElement.classList.contains('selected')) {
                    seatElement.classList.remove('selected');
                    delete selectedTickets[ticketId];
                } else {
                    // Optional: Limit number of tickets a user can select (e.g., max 10)
                    // if (Object.keys(selectedTickets).length >= 10) {
                    //    alert("You can select a maximum of 10 tickets.");
                    //    return;
                    // }
                    seatElement.classList.add('selected');
                    selectedTickets[ticketId] = {
                        id: ticketId,
                        type: seatElement.getAttribute('data-ticket-type'),
                        price: parseFloat(seatElement.getAttribute('data-ticket-price')),
                        seatId: seatElement.getAttribute('data-seat-id'), // This is ticket_location (e.g., A1, B2)
                        location: seatElement.getAttribute('data-seat-location')
                    };
                }

                updateSelectionDisplay();
            }

            function updateSelectionDisplay() {
                const container = document.getElementById('selectedTicketsContainer');
                const proceedButton = document.getElementById('proceedToPayment');
                const totalAmountSpan = document.getElementById('totalAmount');
                const selectedTicketsInput = document.getElementById('selectedTicketsInput');
                const bookingForm = document.getElementById('bookingForm');


                if (Object.keys(selectedTickets).length === 0) {
                    container.innerHTML = '<p class="text-muted small">No tickets selected yet</p>';
                    proceedButton.disabled = true;
                    totalAmountSpan.textContent = '0';
                    selectedTicketsInput.value = '[]';
                    return;
                }

                const ticketsByType = {};
                let totalAmount = 0;

                for (const ticket of Object.values(selectedTickets)) {
                    if (!ticketsByType[ticket.type]) {
                        ticketsByType[ticket.type] = [];
                    }
                    ticketsByType[ticket.type].push(ticket);
                    totalAmount += ticket.price;
                }

                let ticketsHtml = '';

                // Sort ticket types to ensure consistent display, e.g., 'Regular', 'Recliner', etc.
                const orderedTypesForDisplay = ['Regular', 'Recliner', 'Premium', 'VIP']; // Define your preferred order
                const sortedTypes = Object.keys(ticketsByType).sort((a, b) => {
                    return orderedTypesForDisplay.indexOf(a) - orderedTypesForDisplay.indexOf(b) || a.localeCompare(b);
                });

                for (const type of sortedTypes) {
                    if (ticketsByType.hasOwnProperty(type)) { // Ensure the type exists in ticketsByType
                        const tickets = ticketsByType[type];
                        const typeTotal = tickets.reduce((sum, ticket) => sum + ticket.price, 0);
                        // Using seatId (e.g., A1, B2) as the display text for seats
                        const seatIds = tickets.map(t => `${t.seatId}`).join(', '); 

                        ticketsHtml += `
                            <div class="selected-ticket-type">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-1">${tickets.length} ${type} Ticket${tickets.length > 1 ? 's' : ''}</h6>
                                    <span class="badge bg-danger">₹${tickets[0].price}</span>
                                </div>
                                <div class="selected-seats-list">
                                    Seats: ${seatIds}
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <small class="text-muted">Sub-Total:</small>
                                    <strong>₹${typeTotal.toFixed(2)}</strong>
                                </div>
                            </div>
                        `;
                    }
                }

                container.innerHTML = ticketsHtml;
                totalAmountSpan.textContent = totalAmount.toFixed(2);
                proceedButton.disabled = false;
                selectedTicketsInput.value = JSON.stringify(selectedTickets);
                bookingForm.action = "<?php echo $_SERVER['PHP_SELF'] . '?schedule_id=' . $scheduleId . '&event_id=' . $eventId; ?>"; // Ensure form submits to itself for processing
            }

            document.getElementById('bookingForm').addEventListener('submit', function(e) {
                e.preventDefault();

                if (Object.keys(selectedTickets).length === 0) {
                    alert('Please select at least one ticket');
                    return;
                }

                const loadingOverlay = document.getElementById('loadingOverlay');
                const proceedButton = document.getElementById('proceedToPayment');

                loadingOverlay.style.display = 'flex';
                proceedButton.disabled = true;
                proceedButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                // Submit the form containing the selected_tickets JSON
                this.submit();
            });

            const cityAlert = document.getElementById('cityAlert');
            if (cityAlert) {
                setTimeout(() => {
                    cityAlert.classList.add('fade-out');
                    setTimeout(() => {
                        cityAlert.remove();
                    }, 1000);
                }, 5000);
            }

            // Call updateSelectionDisplay initially in case there are no pre-selected tickets (e.g., on page load)
            // This ensures the button is disabled and total is 0.
            updateSelectionDisplay();
        });
    </script>

</body>
</html>