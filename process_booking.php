<?php
session_start();
// Use the shared PDO connection from config/db_config.php
require_once 'config/db_config.php';
require_once 'links.php'; // Assuming this defines $book_event and $home_page

// Enable error reporting (good for development, disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Fallback for location if $book_event is not defined dynamically
$fallbackRedirectPage = $book_event ?? $home_page; // Use $book_event for better context

// Retrieve booking data from session (set by bookevent_tickets.php)
$bookingData = $_SESSION['booking_data'] ?? null;

// --- Debugging point 1: Check raw SESSION data ---
// error_log("Booking Processor - Raw SESSION Data: " . print_r($_SESSION, true));
// exit();

if (!$bookingData || !isset($bookingData['event_id'], $bookingData['schedule_id'], $bookingData['selected_tickets'], $bookingData['held_ticket_ids'])) {
    error_log("Booking Processor - Missing critical session data. Session: " . print_r($_SESSION, true));
    $_SESSION['error_message'] = "Booking process interrupted. Critical data missing. Please restart booking. (Code: BP_SESSION_UNSET)";
    header("Location: {$events_page}"); // Redirect to a safe initial page
    exit();
}

// Extract data from bookingData
$eventId = $bookingData['event_id'];
$scheduleId = $bookingData['schedule_id'];
$selectedTickets = $bookingData['selected_tickets'];
$heldTicketIds = $bookingData['held_ticket_ids'];

// Ensure user is logged in and session user matches booking data user
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] != $bookingData['user_id']) {
    $_SESSION['error_message'] = "User session mismatch or missing. Please log in to complete your booking.";
    header("Location: {$login_page}"); // Redirect to login
    exit();
}
$userId = $_SESSION['user_id'];

// If selectedTickets is empty after being passed from bookevent_tickets.php, it's an issue
if (empty($selectedTickets) || !is_array($selectedTickets)) {
    error_log("Booking Processor - Invalid or empty selected tickets data. Data: " . print_r($selectedTickets, true));
    $_SESSION['error_message'] = "Invalid ticket selection data received. Please re-select your tickets. (Code: BP_INVALID_TICKETS)";
    header("Location: " . $book_event . "?event_id=" . ($eventId ?? '') . "&schedule_id=" . ($scheduleId ?? '')); // Redirect back to ticket map
    exit();
}

// If no held tickets were set by the previous step, it's also an error
if (empty($heldTicketIds) || !is_array($heldTicketIds)) {
    error_log("Booking Processor - No held tickets passed from bookevent_tickets.php. Held IDs: " . print_r($heldTicketIds, true));
    $_SESSION['error_message'] = "Temporary hold for tickets failed. Please re-select your tickets. (Code: BP_NO_HELD_IDS)";
    header("Location: " . $book_event . "?event_id=" . ($eventId ?? '') . "&schedule_id=" . ($scheduleId ?? '')); // Redirect back to ticket map
    exit();
}

// Re-fetch comprehensive event details from database using PDO
// This acts as a secondary validation and ensures up-to-date data for payment page
try {
    $query = "SELECT
                ei.event_id, ei.event_name, ei.event_duration as duration, ei.genre, ei.event_language as language,
                es.venue_id, es.event_schedule_id, es.is_active as event_schedule_active,
                v.venue_name, v.sub_venue_name, v.is_active as venue_active,
                vs.slot_starts_at as slot_time, vs.is_active as venue_schedule_active,
                ei.is_active as event_active,
                c.is_active as city_active, c.city_name
              FROM event_info ei
              JOIN event_schedule es ON ei.event_id = es.event_id
              JOIN venues v ON es.venue_id = v.venue_id
              JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
              JOIN cities c ON v.city_id = c.city_id
              WHERE ei.event_id = ? AND es.event_schedule_id = ?";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$eventId, $scheduleId]);
    $eventDetails = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- Debugging point 2: Check fetched event details from DB ---
    // error_log("Booking Processor - Fetched Event Details: " . print_r($eventDetails, true));
    // exit();

    // If event details are not found, or any part is inactive/invalid
    if (!$eventDetails) {
        error_log("Booking Processor - Event details not found for event_id: {$eventId}, schedule_id: {$scheduleId}.");
        $_SESSION['error_message'] = "The selected event or showtime could not be found or has been removed. Please choose another event. (Code: BP_DETAIL_NOT_FOUND)";
        header("Location: " . $book_event . "?event_id=" . ($eventId ?? '')); // Redirect back to general event view
        exit();
    }

    // Cross-check active statuses
    if ($eventDetails['event_active'] !== 'yes' ||
        $eventDetails['venue_active'] !== 'yes' ||
        $eventDetails['city_active'] !== 'yes' ||
        $eventDetails['event_schedule_active'] !== 'yes' ||
        $eventDetails['venue_schedule_active'] !== 'yes') {

        error_log("Booking Processor - Inactive entity detected for event_id: {$eventId}, schedule_id: {$scheduleId}. Status: " . print_r($eventDetails, true));
        $_SESSION['error_message'] = "The selected event, showtime, venue, or city is no longer active or available. Please choose another. (Code: BP_INACTIVE_ENTITY)";
        header("Location: " . $book_event . "?event_id=" . ($eventId ?? '')); // Redirect back to general event view
        exit();
    }

} catch (PDOException $e) {
    error_log("Booking Processor - Database query error during details re-fetch: " . $e->getMessage());
    $_SESSION['error_message'] = "A critical database error occurred while preparing your booking details. Please try again later. (Code: BP_DB_ERR_FETCH)";
    header("Location: " . $fallbackRedirectPage); // Fallback to a safe page (home/events)
    exit();
}

// Calculate total amount (re-calculate on server-side for security)
// This will be the *subtotal* before promo codes, convenience fees, or GST on the payment page.
$totalAmount = 0;
foreach ($selectedTickets as $ticket) {
    if (isset($ticket['price'])) {
        $totalAmount += (float)$ticket['price'];
    } else {
        error_log("Booking Processor - Ticket price missing for ticket: " . print_r($ticket, true));
        $_SESSION['error_message'] = "Corrupted ticket price data. Please re-select your tickets. (Code: BP_TICKET_PRICE_MISSING)";
        header("Location: " . $book_event . "?event_id=" . ($eventId ?? '') . "&schedule_id=" . ($scheduleId ?? ''));
        exit();
    }
}
if ($totalAmount <= 0) {
    error_log("Booking Processor - Total amount calculated as zero or negative. Total: " . $totalAmount);
    $_SESSION['error_message'] = "Invalid ticket prices. Please re-select your tickets. (Code: BP_ZERO_TOTAL_AMOUNT)";
    header("Location: " . $book_event . "?event_id=" . ($eventId ?? '') . "&schedule_id=" . ($scheduleId ?? ''));
    exit();
}


// Store all validated and comprehensive data in session for payment.php
// This overwrites previous $_SESSION['booking_data'] with refined, validated data.
$_SESSION['booking_data'] = [
    'user_id' => $userId,
    'event_id' => $eventId,
    'schedule_id' => $scheduleId, // This is event_schedule_id
    'venue_id' => $eventDetails['venue_id'],
    'selected_tickets' => $selectedTickets,
    'held_ticket_ids' => $heldTicketIds, // IMPORTANT: Pass held ticket IDs to payment.php
    'pre_discount_total_amount' => $totalAmount, // New key for total before promo, fees, GST
    'event_name' => $eventDetails['event_name'],
    'duration' => $eventDetails['duration'],
    'genre' => $eventDetails['genre'],
    'language' => $eventDetails['language'],
    'venue_name' => $eventDetails['venue_name'],
    'sub_venue_name' => $eventDetails['sub_venue_name'],
    'slot_time' => $eventDetails['slot_time']
];

// Redirect to payment page
header("Location: {$payment}");
exit();
?>
