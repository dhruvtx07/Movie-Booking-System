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
 
// Start session and check login

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=event_mg', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get parameters
$scheduleId = filter_input(INPUT_GET, 'schedule_id', FILTER_VALIDATE_INT);
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$scheduleId || !$eventId) {
            echo "<script>window.history.back();</script>";
echo "<script>window.location.href = '$book_event';</script>";
exit();

    die("Invalid parameters");
}

// Initialize variables
$schedule = null;
$organizedTickets = [];
$tickets = [];
$bookedTickets = [];

// Fetch schedule and event details
try {
    $stmt = $db->prepare("
        SELECT 
            es.*,
            v.venue_name, 
            v.sub_venue_name,
            vs.slot_starts_at,
            ei.event_name,
            ei.event_language
        FROM event_schedule es
        JOIN venues v ON es.venue_id = v.venue_id
        JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
        JOIN event_info ei ON es.event_id = ei.event_id
        WHERE es.event_schedule_id = ? AND es.event_id = ?
    ");
    $stmt->execute([$scheduleId, $eventId]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$schedule) {
        die("Schedule not found");
    }
    
    // Fetch all tickets (both available and booked)
    $ticketsStmt = $db->prepare("
        SELECT * FROM event_schedule_tickets 
        WHERE event_schedule_id = ? AND is_active = 'yes'
        ORDER BY ticket_type, ticket_row, ticket_column
    ");
    $ticketsStmt->execute([$scheduleId]);
    $allTickets = $ticketsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Separate available and booked tickets
    foreach ($allTickets as $ticket) {
        if ($ticket['is_vacant'] === 'yes') {
            $tickets[] = $ticket;
        } else {
            $bookedTickets[] = $ticket;
        }
    }
    
    // Organize tickets by type
    foreach ($allTickets as $ticket) {
        $type = $ticket['ticket_type'];
        $row = $ticket['ticket_row'];
        $column = $ticket['ticket_column'];
        $location = $ticket['ticket_location'];
        
        if (!isset($organizedTickets[$type])) {
            $organizedTickets[$type] = [
                'rows' => [],
                'columns' => [],
                'locations' => [],
                'price' => $ticket['ticket_price'],
                'seats' => []
            ];
        }
        
        if (!in_array($row, $organizedTickets[$type]['rows'])) {
            $organizedTickets[$type]['rows'][] = $row;
        }
        if (!in_array($column, $organizedTickets[$type]['columns'])) {
            $organizedTickets[$type]['columns'][] = $column;
        }
        if (!in_array($location, $organizedTickets[$type]['locations'])) {
            $organizedTickets[$type]['locations'][] = $location;
        }
        
        // Mark if the seat is available or booked
        $organizedTickets[$type]['seats'][$row][$column] = [
            'data' => $ticket,
            'available' => $ticket['is_vacant'] === 'yes'
        ];
    }
    
    // Sort rows and columns
    foreach ($organizedTickets as $type => $data) {
        sort($organizedTickets[$type]['rows']);
        sort($organizedTickets[$type]['columns'], SORT_NUMERIC);
    }

} catch(PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}

// Check if form was submitted to verify seat availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_tickets'])) {
    $selectedTickets = json_decode($_POST['selected_tickets'], true);
    $unavailableTickets = [];
    
    try {
        // Check each selected ticket for availability
        foreach ($selectedTickets as $ticketId => $ticket) {
            $stmt = $db->prepare("SELECT is_vacant FROM event_schedule_tickets WHERE ticket_id = ?");
            $stmt->execute([$ticketId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result || $result['is_vacant'] !== 'yes') {
                $unavailableTickets[] = $ticket['seatId'];
            }
        }
        
        if (!empty($unavailableTickets)) {
            $_SESSION['error_message'] = "Some selected seats are no longer available: " . implode(', ', $unavailableTickets);
            $_SESSION['selected_tickets'] = $_POST['selected_tickets'];
            header("Location: ".$_SERVER['PHP_SELF']."?schedule_id=$scheduleId&event_id=$eventId");
            exit();
        }
        
        // If all tickets are available, store in session and redirect to booking page
        $_SESSION['booking_data'] = [
            'event_id' => $eventId,
            'schedule_id' => $scheduleId,
            'selected_tickets' => $selectedTickets,
            'event_name' => $schedule['event_name'],
            'event_date' => $schedule['slot_starts_at'],
            'venue_name' => $schedule['venue_name'],
            'sub_venue_name' => $schedule['sub_venue_name'],
            'total_amount' => array_reduce($selectedTickets, function($sum, $ticket) {
                return $sum + $ticket['price'];
            }, 0)
        ];
        
        header("Location: $booking_processor");
        exit();
        
    } catch(PDOException $e) {
        die("Error verifying ticket availability: " . $e->getMessage());
    }
}

// Restore selected tickets from session if available
$selectedTicketsJson = $_SESSION['selected_tickets'] ?? '[]';
$selectedTicketsFromSession = json_decode($selectedTicketsJson, true);
unset($_SESSION['error_message']);
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
    /* Your existing CSS styles remain unchanged */
    :root {
        --bms-black: #1a1a1a;
        --bms-dark: #2a2a2a;
        --bms-red: #e63946;
        --bms-peach: #ff9a8b;
        --bms-orange: #ff7e33;
        --bms-light: #f8f9fa;
        --bms-gradient: linear-gradient(135deg, var(--bms-red) 0%, var(--bms-orange) 100%);
        
        --primary-color: var(--bms-black);
        --secondary-color: var(--bms-red);
        --accent-color: var(--bms-orange);
        --highlight-color: #FFFF00;
        --light-color: #FFDAB9;
        
        --bg-color: var(--bms-black);
        --container-bg: var(--bms-dark);
        --text-color: white;
        --form-bg: rgba(255, 255, 255, 0.05);
        --form-border: rgba(255, 255, 255, 0.1);
        --placeholder-color: rgba(255, 255, 255, 0.4);
        --divider-color: rgba(255, 255, 255, 0.1);
        --link-color: var(--bms-peach);
        --link-hover: white;
    }
    
    .light-mode {
        --bg-color: #f5f5f5;
        --container-bg: white;
        --text-color: #333;
        --form-bg: rgba(0, 0, 0, 0.05);
        --form-border: rgba(0, 0, 0, 0.1);
        --placeholder-color: rgba(0, 0, 0, 0.4);
        --divider-color: rgba(0, 0, 0, 0.1);
        --link-color: var(--bms-red);
        --link-hover: var(--bms-orange);
    }

    body {
        background-color: var(--bg-color);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: var(--text-color);
        transition: background-color 0.3s ease, color 0.3s ease;
        font-size: 0.9rem;
    }
    
    .container {
        max-width: 1200px;
        padding-top: 1rem;
        padding-bottom: 2rem;
    }
    
    .card {
        background-color: var(--container-bg);
        border: 1px solid var(--form-border);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        
    }
    
    .card-header {
        background: var(--bms-gradient) !important;
        color: white;
        font-weight: 700;
        border-bottom: none;
    }
    
    .venue-map {
        background-color: var(--container-bg);
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
        background: var(--bms-gradient);
        color: white;
        border-radius: 8px;
        font-size: 1rem;
        letter-spacing: 0.5px;
        box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
    }
    
    .legend {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
        padding: 12px;
        background-color: var(--form-bg);
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
    
    .seat-map-container {
        display: flex;
        flex-direction: column;
        gap: 30px;
        align-items: center;
        margin-top: 20px;
    }
    
    .seat-type-container {
        background-color: var(--container-bg);
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        width: 100%;
        max-width: 800px;
        margin: 0 auto;
        transition: all 0.3s ease;
        border: 1px solid var(--form-border);
    }
    
    .seat-type-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--divider-color);
    }
    
    .seat-type-title {
        font-weight: 700;
        font-size: 1.1rem;
        letter-spacing: 0.5px;
    }
    
    .seat-type-price {
        font-weight: 700;
        font-size: 1rem;
        color: var(--secondary-color);
    }
    
    .seat-type-location {
        color: var(--placeholder-color);
        margin-bottom: 12px;
        text-align: center;
        font-size: 0.85rem;
    }
    
    .seat-grid {
        display: inline-block;
        border: 1px solid var(--form-border);
        border-radius: 8px;
        overflow: hidden;
        background-color: var(--container-bg);
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
        background-color: var(--form-bg);
        font-weight: 600;
        border: none;
        color: var(--text-color);
        font-size: 0.75rem;
    }
    
    .seat-cell.row-header {
        background-color: var(--form-bg);
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
    
    .regular .seat { background-color: var(--bms-peach); }
    .recliner .seat { background-color: var(--bms-red); }
    .premium .seat { background-color: var(--accent-color); }
    .vip .seat { background-color: var(--light-color); color: #000; }
    
    .disabled .seat {
        background-color: var(--form-bg);
        color: var(--placeholder-color);
        cursor: not-allowed;
        position: relative;
    }
    
    .light-mode .disabled .seat {
        background-color: #e9ecef;
        color: #adb5bd;
    }
    
    .disabled .seat i {
        font-size: 0.8rem;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }
    
    .seat-label {
        position: absolute;
        bottom: -16px;
        font-size: 0.6rem;
        color: var(--placeholder-color);
        width: 100%;
        text-align: center;
    }
    
    .ticket-info {
        background-color: var(--container-bg);
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        position: sticky;
        top: 20px;
        transition: all 0.3s ease;
        border: 1px solid var(--form-border);
    }
    
    .selected-ticket-type {
        background-color: var(--form-bg);
        padding: 12px;
        margin-bottom: 12px;
        border-radius: 8px;
        font-size: 0.85rem;
        transition: all 0.3s ease;
        border: 1px solid var(--form-border);
    }
    
    .light-mode .selected-ticket-type {
        background-color: #f8f9fa;
    }
    
    .total-amount {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 15px 0;
        padding-top: 12px;
        border-top: 1px solid var(--divider-color);
        color: var(--secondary-color);
    }
    
    .selected-seats-list {
        margin-top: 8px;
        font-size: 0.85rem;
        color: var(--placeholder-color);
    }
    
    .btn-bms {
        background: var(--bms-gradient);
        border: none;
        padding: 12px;
        font-weight: 700;
        border-radius: 8px;
        width: 100%;
        color: white;
        letter-spacing: 0.5px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
        text-transform: uppercase;
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
    
    .alert {
        border-radius: 8px;
        background-color: rgba(230, 57, 70, 0.2);
        border: 1px solid var(--bms-red);
        color: var(--text-color);
    }
    
    @media (max-width: 768px) {
        .seat-cell {
            width: 20px;
            height: 20px;
        }
        
        .seat-type-title {
            font-size: 1rem;
        }
        
        .container {
            padding-left: 15px;
            padding-right: 15px;
        }
        
        .seat-label {
            bottom: -14px;
            font-size: 0.55rem;
        }
        
        .seat-type-container {
            padding: 15px;
        }
        
        .venue-map {
            padding: 15px;
        }
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .venue-map {
        animation: fadeIn 0.6s ease-out forwards;
    }
    
    .seat-type-container {
        animation: fadeIn 0.6s ease-out forwards;
        animation-delay: 0.1s;
    }
    
    .ticket-info {
        animation: fadeIn 0.6s ease-out forwards;
        animation-delay: 0.2s;
    }

    /* Navbar styles from file 2 */
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
        padding-top: 100px;
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
    .top-navbar, .second-navbar {
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
        position: fixed;
        margin-top: 70px;
        left: 0;
        right: 0;
        z-index: 1020;
        display: block !important;
        white-space: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
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
        .card{
            margin-top: 10px;
        }
        .card-header{
            margin-top: 10px;
        }
    }

    @media (max-width: 768px) {
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

    @media (max-width: 576px) {
        .card{
            margin-top: 40px;
        }
        .card-header{
            margin-top: 10px;
        }
        body {
            padding-top: 60px;
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
                                                <a href="<?=$event_info_page?>?id=<?= $result['event_id'] ?>" class="btn btn-sm btn-danger mt-2">View Details</a>
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
                        <i class="bi bi-exclamation-triangle me-2"></i> No tickets available for this showtime.
                    </div>
                    <a href="<?=$book_event?>?event_id=<?php echo isset($eventId) ? htmlspecialchars($eventId) : ''; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left me-2"></i> Back to Showtimes
                    </a>

                <?php else: ?>
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="venue-map">
                                <div class="stage-indicator">
                                    <i class="bi bi-cast me-2"></i> STAGE AREA
                                </div>
                                
                                <div class="legend">
                                    <div class="legend-item">
                                        <div class="legend-color regular"  style="background-color: var(--bms-peach);"></div>
                                        <span>Regular</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color recliner" style="background-color: var(--bms-red);"></div>
                                        <span>Recliner</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color premium" style="background-color: var(--bms-orange);"></div>
                                        <span>Premium</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color vip" style="background-color: var(--bms-dark);"></div>
                                        <span>VIP</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color" style="background-color: var(--highlight-color);"></div>
                                        <span>Selected</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color disabled" style="background-color: grey;"></div>
                                        <span>Booked</span>
                                    </div>
                                </div>
                                
                                <div class="seat-map-container">
                                    <?php 
                                    $orderedTypes = ['Regular', 'Recliner', 'Premium', 'VIP'];
                                    foreach ($orderedTypes as $type): 
                                        if (isset($organizedTickets[$type])):
                                            $data = $organizedTickets[$type];
                                    ?>
                                        <div class="seat-type-container <?= strtolower($type) ?>">
                                            <div class="seat-type-header">
                                                <div class="seat-type-title"><?= htmlspecialchars($type) ?></div>
                                                <div class="seat-type-price">₹<?= htmlspecialchars($data['price']) ?></div>
                                            </div>
                                            
                                            <p class="seat-type-location">
                                                <i class="bi bi-pin-map me-1"></i> 
                                                <?= implode(', ', array_map('htmlspecialchars', $data['locations'])) ?>
                                            </p>
                                            
                                            <div class="d-flex justify-content-center">
                                                <div class="seat-grid">
                                                    <!-- Column headers -->
                                                    <div class="seat-row">
                                                        <div class="seat-cell row-header"></div>
                                                        <?php foreach ($data['columns'] as $col): ?>
                                                            <div class="seat-cell header"><?= $col ?></div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    
                                                    <!-- Seat rows -->
                                                    <?php foreach ($data['rows'] as $row): ?>
                                                        <div class="seat-row">
                                                            <div class="seat-cell row-header"><?= $row ?></div>
                                                            <?php foreach ($data['columns'] as $col): ?>
                                                                <div class="seat-cell">
                                                                    <?php if (isset($data['seats'][$row][$col])): ?>
                                                                        <?php 
                                                                        $seatData = $data['seats'][$row][$col]['data'];
                                                                        $isAvailable = $data['seats'][$row][$col]['available'];
                                                                        ?>
                                                                        <?php if ($isAvailable): ?>
                                                                            <div class="seat"
                                                                                 data-ticket-id="<?= $seatData['ticket_id'] ?>"
                                                                                 data-ticket-type="<?= htmlspecialchars($seatData['ticket_type']) ?>"
                                                                                 data-ticket-price="<?= $seatData['ticket_price'] ?>"
                                                                                 data-seat-id="<?= htmlspecialchars($row . $col) ?>"
                                                                                 data-seat-location="<?= htmlspecialchars($seatData['ticket_location']) ?>"
                                                                                 onclick="selectSeat(this)">
                                                                                <?= $seatData['ticket_location'] ?>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <div class="seat disabled" title="Already booked">
                                                                                <i class="bi bi-x-lg"></i>
                                                                                <div class="seat-label"><?= htmlspecialchars($seatData['ticket_location']) ?></div>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        <div class="seat disabled"><?= $col ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="ticket-info">
                                <h5 class="mb-3"><i class="bi bi-cart-check me-2"></i> Your Selection</h5>
                                
                                <div id="selectedTicketsContainer">
                                    <p class="text-muted small">No tickets selected yet</p>
                                </div>
                                
                                <div class="total-amount">
                                    Total: ₹<span id="totalAmount">0</span>
                                </div>

                                <form id="bookingForm" action="<?=$booking_processor?>" method="post">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
                                    <input type="hidden" name="selected_tickets" id="selectedTicketsInput" value="<?= htmlspecialchars($selectedTicketsJson) ?>">
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" id="proceedToPayment" class="btn btn-bms" disabled>
                                            <i class="bi bi-credit-card me-2"></i> Proceed to Payment
                                        </button>
                                        <a href="<?=$book_event?>?event_id=<?php echo isset($eventId) ? htmlspecialchars($eventId) : ''; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left me-2"></i> Back to Showtimes
                    </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>              

                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
        <?php endif; ?>
    </div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
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
            fetch(`<?=$search?>?search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        let html = '';
                        data.forEach(item => {
                            html += `
                                <a href="<?=$event_info_page?>?id=${item.event_id}" class="search-result-item">
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

        // Seat selection functionality
        let selectedTickets = {};
        
        // Initialize with any previously selected tickets from session
        <?php if (!empty($selectedTicketsFromSession)): ?>
            selectedTickets = <?= json_encode($selectedTicketsFromSession) ?>;
            
            // Mark the seats as selected in the UI
            for (const ticketId in selectedTickets) {
                const seatElement = document.querySelector(`.seat[data-ticket-id="${ticketId}"]`);
                if (seatElement) {
                    seatElement.classList.add('selected');
                }
            }
        <?php endif; ?>
        
        // Add click event listeners to all seat elements
        document.querySelectorAll('.seat').forEach(seat => {
            if (!seat.classList.contains('disabled')) {
                seat.addEventListener('click', function() {
                    selectSeat(this);
                });
            }
        });

        function selectSeat(seatElement) {
            const ticketId = seatElement.getAttribute('data-ticket-id');
            
            if (seatElement.classList.contains('selected')) {
                // Deselect the seat
                seatElement.classList.remove('selected');
                delete selectedTickets[ticketId];
            } else {
                // Select the seat
                seatElement.classList.add('selected');
                selectedTickets[ticketId] = {
                    id: ticketId,
                    type: seatElement.getAttribute('data-ticket-type'),
                    price: parseFloat(seatElement.getAttribute('data-ticket-price')),
                    seatId: seatElement.getAttribute('data-seat-id'),
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
            
            if (Object.keys(selectedTickets).length === 0) {
                container.innerHTML = '<p class="text-muted small">No tickets selected yet</p>';
                proceedButton.disabled = true;
                totalAmountSpan.textContent = '0';
                selectedTicketsInput.value = '[]';
                return;
            }
            
            // Group tickets by type
            const ticketsByType = {};
            let totalAmount = 0;
            
            for (const ticket of Object.values(selectedTickets)) {
                if (!ticketsByType[ticket.type]) {
                    ticketsByType[ticket.type] = [];
                }
                ticketsByType[ticket.type].push(ticket);
                totalAmount += ticket.price;
            }
            
            // Generate HTML for selected tickets
            let ticketsHtml = '';
            
            for (const [type, tickets] of Object.entries(ticketsByType)) {
                const typeTotal = tickets.reduce((sum, ticket) => sum + ticket.price, 0);
                const seatIds = tickets.map(t => `${t.location}`).join(', ');
                const price = tickets[0].price;
                
                ticketsHtml += `
                    <div class="selected-ticket-type">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-1">${type}</h6>
                            <span class="badge bg-danger">₹${price} × ${tickets.length}</span>
                        </div>
                        <div class="selected-seats-list">
                            ${seatIds}
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">${tickets.length} seat${tickets.length > 1 ? 's' : ''}</small>
                            <strong>₹${typeTotal.toFixed(2)}</strong>
                        </div>
                    </div>
                `;
            }
            
            container.innerHTML = ticketsHtml;
            totalAmountSpan.textContent = totalAmount.toFixed(2);
            proceedButton.disabled = false;
            selectedTicketsInput.value = JSON.stringify(selectedTickets);
        }

        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (Object.keys(selectedTickets).length === 0) {
                alert('Please select at least one ticket');
                return;
            }

            const loadingOverlay = document.getElementById('loadingOverlay');
            const proceedButton = document.getElementById('proceedToPayment');

            // Show loading overlay
            loadingOverlay.style.display = 'flex';
            proceedButton.disabled = true;
            proceedButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

            // Submit the form
            this.submit();
        });

        // Auto-hide city alert after 5 seconds
        const cityAlert = document.getElementById('cityAlert');
        if (cityAlert) {
            setTimeout(() => {
                cityAlert.classList.add('fade-out');
                setTimeout(() => {
                    cityAlert.remove();
                }, 1000);
            }, 5000);
        }
    });
    </script>

</body>
</html>
