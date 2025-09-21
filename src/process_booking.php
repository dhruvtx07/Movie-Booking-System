<?php
session_start();


require_once 'links.php';


// Database connection (adjust these parameters as needed)
$db = new mysqli('localhost', 'root', '', 'event_mg');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Validate input
$eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
$scheduleId = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT);
$selectedTicketsJson = filter_input(INPUT_POST, 'selected_tickets');

if (!$eventId || !$scheduleId || !$selectedTicketsJson) {
    die("Invalid request");
}

// Decode the selected tickets
$selectedTickets = json_decode($selectedTicketsJson, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($selectedTickets)) {
    die("Invalid ticket data");
}

// Fetch event details from database
$query = "SELECT 
            ei.event_id, ei.event_name, ei.event_duration as duration, ei.genre, ei.event_language as language,
            es.event_schedule_id, es.venue_id,
            v.venue_name, v.sub_venue_name,
            vs.slot_starts_at as slot_time
        FROM event_info ei
        JOIN event_schedule es ON ei.event_id = es.event_id
        JOIN venues v ON es.venue_id = v.venue_id
        JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
        WHERE ei.event_id = ? AND es.event_schedule_id = ?
        ";

$stmt = $db->prepare($query);
$stmt->bind_param("ii", $eventId, $scheduleId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Event or schedule not found");
}

$eventDetails = $result->fetch_assoc();

// Calculate total amount
$totalAmount = array_sum(array_column($selectedTickets, 'price'));

// Store all data in session
$_SESSION['booking_data'] = [
    'event_id' => $eventId,
    'schedule_id' => $scheduleId,
    'selected_tickets' => $selectedTickets,
    'total_amount' => $totalAmount,
    'event_name' => $eventDetails['event_name'],
    'duration' => $eventDetails['duration'],
    'genre' => $eventDetails['genre'],
    'language' => $eventDetails['language'],
    'venue_name' => $eventDetails['venue_name'],
    'sub_venue_name' => $eventDetails['sub_venue_name'],
    'slot_time' => $eventDetails['slot_time']
];

// Validate session data
if(empty($_SESSION['booking_data']['event_id']) || empty($_SESSION['booking_data']['schedule_id'])) {
    die("Missing event/schedule data in session");
}

// Store flat data for redirect
$_SESSION['flat_booking_data'] = [
    'eid' => $_SESSION['booking_data']['event_id'],
    'sid' => $_SESSION['booking_data']['schedule_id']
];

// Redirect to payment page
header("Location: $payment?event_id=".$_SESSION['booking_data']['event_id']."&schedule_id=".$_SESSION['booking_data']['schedule_id']);
exit();