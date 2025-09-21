<?php
// Start session for all pages
session_start();

// Set default timezone
date_default_timezone_set('Asia/Kolkata');

// --- Temporarily enable error reporting for debugging (REMOVE IN PRODUCTION) ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting ---

// Include configuration files and links definitions
require_once 'config/db_config.php'; // Contains $pdo object for database connection
require_once 'links.php'; // Defines page URLs like $login_page, $home_page, etc.

// --- Global Authentication Logic (applied to all requests) ---
// Define pages that don't require authentication (e.g., login, register)
// Note: basename($_SERVER['PHP_SELF']) will be 'venue_wise_showtimes.php' for this file.
$auth_pages = [basename($_SERVER['PHP_SELF']), basename($login_page), basename($register_page), basename($forgot_pass)];

if (!isset($_SESSION['user_id'])) {
    if (!isset($_GET['ajax']) && (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages) ||
        (basename($_SERVER['PHP_SELF']) == 'venue_wise_showtimes.php' && !isset($_GET['venue_id']) && !isset($_GET['login']) && !isset($_GET['register']) && !isset($_GET['forgot_password']))) ) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// --- Initialize Variables and Shared Functions ---
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : '';

// Function to get city name by ID
function getCityNameById($cities_array, $cityId) {
    foreach ($cities_array as $city) {
        if ($city['city_id'] == $cityId) {
            return $city['city_name'];
        }
    }
    return 'Unknown City';
}

$cities = [];
// Fetch cities from database
try {
    $stmt = $pdo->query("SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name");
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch cities: " . $e->getMessage());
    // Fallback for development/testing
    $cities = [
        ['city_id' => 1, 'city_name' => 'Mumbai'],
        ['city_id' => 2, 'city_name' => 'Delhi'],
        ['city_id' => 3, 'city_name' => 'Bangalore']
    ];
}

// --- Handle City Selection (post request) ---
if (isset($_SESSION['user_id'])) {
    $_SESSION['selected_city'] = $_SESSION['selected_city'] ?? ''; // Initialize if not set

    if (isset($_POST['city'])) {
        $_SESSION['selected_city'] = (int)$_POST['city'];

        // Preserve current page parameters (venue_id and date) when city is changed
        $redirectUrl = $_SERVER['PHP_SELF'];
        $queryParams = [];
        
        if (isset($_GET['venue_id'])) {
            $queryParams['venue_id'] = $_GET['venue_id'];
        }
        if (isset($_GET['date'])) {
            $queryParams['date'] = $_GET['date'];
        }

        if (!empty($queryParams)) {
            $redirectUrl .= '?' . http_build_query($queryParams);
        }

        header("Location: " . $redirectUrl); // Redirect to clear POST data
        exit();
    }
}

// --- Global Variable for selected city (if not set, try to default) ---
if ($isLoggedIn && empty($_SESSION['selected_city'])) {
    // If user is logged in but no city is selected, try to default to the first available city
    if (!empty($cities)) {
        $_SESSION['selected_city'] = $cities[0]['city_id'];
    } else {
        $_SESSION['selected_city'] = null; 
    }
}
$selected_city_id = $_SESSION['selected_city'] ?? null;

// --- AJAX Endpoints for venue_wise_showtimes.php ---

// AJAX for fetching initial venues list for the horizontal scroller
// This endpoint already correctly uses $selected_city_id from session.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'venues_in_city' && $isLoggedIn && $selected_city_id) {
    $venuesList = [];
    try {
        // Fetch venues that have at least one active event schedule in the selected city
        $stmt = $pdo->prepare("SELECT DISTINCT v.venue_id, v.venue_name, v.sub_venue_name 
                               FROM venues v
                               JOIN event_schedule es ON v.venue_id = es.venue_id
                               WHERE v.city_id = :city_id AND v.is_active = 'yes' AND es.is_active = 'yes'
                               ORDER BY v.venue_name");
        $stmt->bindParam(':city_id', $selected_city_id, PDO::PARAM_INT);
        $stmt->execute();
        $venuesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch venues for AJAX: " . $e->getMessage());
    }
    header('Content-Type: application/json');
    echo json_encode($venuesList);
    exit();
}

// AJAX for fetching event showtimes for a specific venue and date
if (isset($_GET['ajax']) && $_GET['ajax'] === 'venue_event_showtimes' && $isLoggedIn && isset($_GET['venue_id']) && isset($_GET['date'])) {
    $venueId = filter_input(INPUT_GET, 'venue_id', FILTER_VALIDATE_INT);
    $date = filter_input(INPUT_GET, 'date');

    if (!$venueId || !$date) {
        echo '<div class="no-shows">Invalid venue ID or date provided.</div>';
        exit();
    }

    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');
    
    // --- IMPORTANT CHANGE HERE: Prioritize city_id from GET if it exists ---
    $incoming_city_id = filter_input(INPUT_GET, 'city_id', FILTER_VALIDATE_INT);
    $city_id_for_query = $incoming_city_id ?: $selected_city_id; // Use incoming, fallback to session

    if (!$city_id_for_query) { // Essential check
        echo '<div class="no-shows">Please select a city to view showtimes.</div>';
        exit();
    }

    // Prepare price ranges for filters
    $priceRanges = [
        'under-200' => 'Under ₹200',
        '200-500' => '₹200 - ₹500',
        '500-1000' => '₹500 - ₹1000',
        'over-1000' => 'Over ₹1000'
    ];

    // Prepare time categories for filters
    $timeCategories = [
        'morning' => 'Morning (6AM-12PM)',
        'afternoon' => 'Afternoon (12PM-5PM)',
        'evening' => 'Evening (5PM-10PM)',
        'night' => 'Night (10PM-6AM)'
    ];

    try {
        // Query to get events and their showtimes for the selected venue and date
        $query = "
        SELECT
            ei.event_id,
            ei.event_name,
            ei.event_language,
            ei.event_type,
            ei.genre,
            ei.event_duration,
            es.event_schedule_id,
            MIN(est.ticket_price) as min_price,
            MAX(est.ticket_price) as max_price,
            DATE(vs.slot_starts_at) as slot_date,
            TIME_FORMAT(vs.slot_starts_at, '%h:%i %p') as formatted_time,
            TIME(vs.slot_starts_at) as time_value,
            TIME(vs.slot_ends_at) as end_time,
            SUM(CASE WHEN est.is_vacant = 'yes' THEN 1 ELSE 0 END) as available_seats,
            COUNT(est.ticket_id) as total_seats
        FROM event_schedule es
        JOIN event_info ei ON es.event_id = ei.event_id
        JOIN venues v ON es.venue_id = v.venue_id
        JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
        JOIN event_schedule_tickets est ON es.event_schedule_id = est.event_schedule_id
        WHERE
            v.venue_id = :venue_id
            AND DATE(vs.slot_starts_at) = :selected_date
            AND es.is_active = 'yes'
            AND ei.is_active = 'yes'
            AND v.city_id = :city_id_param /* Using the determined city_id for query */
        GROUP BY
            ei.event_id, ei.event_name, ei.event_language, ei.event_type, ei.genre, ei.event_duration,
            es.event_schedule_id, vs.slot_starts_at, vs.slot_ends_at
        ORDER BY
            ei.event_name, vs.slot_starts_at;
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':venue_id' => $venueId,
            ':selected_date' => $date,
            ':city_id_param' => $city_id_for_query // Using the determined city_id
        ]);
        $eventShowtimes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process data to group by event
        $groupedEvents = [];
        foreach ($eventShowtimes as $showtime) {
            $eventId = $showtime['event_id'];
            if (!isset($groupedEvents[$eventId])) {
                $groupedEvents[$eventId] = [
                    'event_id' => $showtime['event_id'],
                    'event_name' => $showtime['event_name'],
                    'event_language' => $showtime['event_language'],
                    'event_type' => $showtime['event_type'],
                    'genre' => $showtime['genre'],
                    'event_duration' => $showtime['event_duration'],
                    'showtimes' => [],
                    'min_event_price' => PHP_INT_MAX, // Overall min price for this event at this venue on this date
                    'max_event_price' => 0, // Overall max price for this event at this venue on this date
                    'price_ranges_available' => [], // For filters related to this event
                    'time_categories_available' => [] // For filters related to this event
                ];
            }

            $hour = (int)substr($showtime['time_value'], 0, 2);
            $isLate = $hour >= 22 || ($hour >= 0 && $hour < 6); // Late show if between 10pm-6am
                
            $timeCategory = '';
            if ($hour >= 6 && $hour < 12) { $timeCategory = 'morning'; }
            elseif ($hour >= 12 && $hour < 17) { $timeCategory = 'afternoon'; }
            elseif ($hour >= 17 && $hour < 22) { $timeCategory = 'evening'; }
            else { $timeCategory = 'night'; }
            
            // Only include time slots that haven't ended yet (for current date)
            if ($date > $current_date || ($date == $current_date && $showtime['end_time'] > $current_time)) {
                // Get ticket types and availability for this particular showtime
                $ticketQuery = "
                SELECT
                    ticket_type,
                    ticket_price,
                    COUNT(*) AS total_seats,
                    SUM(CASE WHEN is_vacant = 'yes' THEN 1 ELSE 0 END) AS available_seats
                FROM event_schedule_tickets
                WHERE event_schedule_id = :schedule_id
                AND is_active = 'yes'
                GROUP BY ticket_type, ticket_price
                ORDER BY ticket_price ASC;
                ";
                    
                $ticketStmt = $pdo->prepare($ticketQuery);
                $ticketStmt->execute([':schedule_id' => $showtime['event_schedule_id']]);
                $tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

                $groupedEvents[$eventId]['showtimes'][] = [
                    'schedule_id' => $showtime['event_schedule_id'],
                    'formatted_time' => $showtime['formatted_time'],
                    'time_value' => $showtime['time_value'],
                    'end_time' => $showtime['end_time'],
                    'is_late' => $isLate,
                    'time_category' => $timeCategory,
                    'total_seats' => $showtime['total_seats'],
                    'available_seats' => $showtime['available_seats'],
                    'min_slot_price' => $showtime['min_price'], // Price range for this specific slot
                    'max_slot_price' => $showtime['max_price'],
                    'tickets' => $tickets
                ];
                
                // Update overall min/max prices and categories for the event
                if ($showtime['min_price'] < $groupedEvents[$eventId]['min_event_price']) {
                    $groupedEvents[$eventId]['min_event_price'] = $showtime['min_price'];
                }
                if ($showtime['max_price'] > $groupedEvents[$eventId]['max_event_price']) {
                    $groupedEvents[$eventId]['max_event_price'] = $showtime['max_price'];
                }
                $groupedEvents[$eventId]['time_categories_available'][$timeCategory] = true;

                foreach ($tickets as $ticket) {
                    $price = (int)$ticket['ticket_price'];
                    if ($price < 200) { $groupedEvents[$eventId]['price_ranges_available']['under-200'] = true; }
                    elseif ($price >= 200 && $price < 500) { $groupedEvents[$eventId]['price_ranges_available']['200-500'] = true; }
                    elseif ($price >= 500 && $price < 1000) { $groupedEvents[$eventId]['price_ranges_available']['500-1000'] = true; }
                    else { $groupedEvents[$eventId]['price_ranges_available']['over-1000'] = true; }
                }
            }
        }
?>
    <div class="filter-container">
        <div class="filter-section">
            <div class="filter-title">
                <i class="fas fa-tags"></i>
                <span>Price Range</span>
            </div>
            <div class="filter-tags" id="priceFilters">
                <?php foreach ($priceRanges as $key => $label): ?>
                    <div class="filter-tag" 
                        data-filter="price" 
                        data-value="<?= $key ?>">
                        <?= $label ?>
                        <span class="filter-indicator"></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="filter-section">
            <div class="filter-title">
                <i class="fas fa-clock"></i>
                <span>Time Slot</span>
            </div>
            <div class="filter-tags" id="timeFilters">
                <?php foreach ($timeCategories as $key => $label): ?>
                    <div class="filter-tag" 
                        data-filter="time" 
                        data-value="<?= $key ?>">
                        <?= $label ?>
                        <span class="filter-indicator"></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="reset-filters" id="resetFilters">
            <i class="fas fa-times"></i>
            <span>Reset All Filters</span>
        </div>
    </div>

    <div class="events-in-venue-container" id="eventsInVenueContainer">
        <?php foreach ($groupedEvents as $event): ?>
            <div class="event-showtimes-card" 
                data-event-id="<?= $event['event_id'] ?>"
                data-price-ranges="<?= implode(',', array_keys($event['price_ranges_available'])) ?>"
                data-time-categories="<?= implode(',', array_keys($event['time_categories_available'])) ?>">
                <div class="event-header">
                    <div>
                        <div class="event-name">
                             <a href="<?= $event_info_page ?>?event_id=<?= $event['event_id'] ?>" class="text-white text-decoration-none">
                                <?= htmlspecialchars($event['event_name']) ?>
                            </a>
                            <small class="text-muted">(<?= htmlspecialchars($event['event_language']) ?>)</small>
                        </div>
                        <div class="event-meta">
                            <span class="badge bg-dark"><?= htmlspecialchars($event['event_type']) ?></span>
                            <span class="badge bg-danger"><?= htmlspecialchars($event['genre']) ?></span>
                            <span class="badge bg-warning text-dark"><?= htmlspecialchars($event['event_duration']) ?></span>
                        </div>
                    </div>
                    <?php if (PHP_INT_MAX == $event['min_event_price'] && 0 == $event['max_event_price']): ?>
                        <div class="price-range">N/A</div>
                    <?php else: ?>
                        <div class="price-range">
                            ₹<?= htmlspecialchars($event['min_event_price']) ?> - ₹<?= htmlspecialchars($event['max_event_price']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="time-slots-container">
                    <?php
                    $hasVisibleSlots = false;
                    foreach ($event['showtimes'] as $slot):
                        $availabilityStatus = '';
                        if ($slot['available_seats'] <= 0) { $availabilityStatus = 'sold-out'; }
                        elseif ($slot['available_seats'] < 5) { $availabilityStatus = 'few-left'; }
                        else { $availabilityStatus = 'available'; }
                    ?>
                        <a href="<?= $ticket_map ?>?event_id=<?= $event['event_id'] ?>&venue_id=<?= $venueId ?>&schedule_id=<?= $slot['schedule_id'] ?>" 
                            class="time-slot <?= $slot['is_late'] ? 'late' : '' ?>"
                            data-time-category="<?= $slot['time_category'] ?>"
                            data-price-min="<?= htmlspecialchars($slot['min_slot_price'] ?? 0) ?>">
                            <div class="slot-header">
                                <div class="slot-time"><?= $slot['formatted_time'] ?></div>
                                <div class="slot-availability <?= $availabilityStatus ?>">
                                    <?php
                                    if ($slot['available_seats'] <= 0) { echo 'Sold out'; }
                                    elseif ($slot['available_seats'] < 5) { echo 'Few left'; }
                                    else { echo $slot['available_seats'] . '/' . $slot['total_seats']; }
                                    ?>
                                </div>
                            </div>
                            <div class="ticket-info">
                                <?php foreach ($slot['tickets'] as $ticket):
                                    $ticketAvailability = '';
                                    if ($ticket['available_seats'] <= 0) { $ticketAvailability = 'sold-out'; }
                                    elseif ($ticket['available_seats'] < 5) { $ticketAvailability = 'few-left'; }
                                    else { $ticketAvailability = 'available'; }
                                ?>
                                    <div class="ticket-type">
                                        <span class="ticket-name"><?= htmlspecialchars($ticket['ticket_type']) ?></span>
                                        <span class="ticket-price">₹<?= htmlspecialchars($ticket['ticket_price']) ?></span>
                                        <span class="ticket-availability <?= $ticketAvailability ?>">
                                            <?php
                                            if ($ticket['available_seats'] <= 0) { echo 'Sold out'; }
                                            elseif ($ticket['available_seats'] < 5) { echo 'Few left'; }
                                            else { echo $ticket['available_seats'] . '/' . $ticket['total_seats']; }
                                            ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </a>
                    <?php
                    $hasVisibleSlots = true;
                    endforeach; ?>
                    <?php if (!$hasVisibleSlots): ?>
                        <div class="no-shows-small">No valid showtimes found for this event at this venue on the selected date.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($groupedEvents)): ?>
            <div class="no-shows">
                <i class="fas fa-frown fa-2x mb-3"></i><br>
                No events found for this venue on <strong><?= htmlspecialchars($date) ?></strong> in <strong><?= htmlspecialchars(getCityNameById($cities, $city_id_for_query)) /* Use city_id_for_query here */ ?></strong>.
            </div>
        <?php else: ?>
            <div class="no-shows hidden" id="noShowsMessage">
                <i class="fas fa-filter fa-2x mb-3"></i><br>
                No events match the selected filters for this venue.
            </div>
        <?php endif; ?>
<?php
    } catch(PDOException $e) {
        error_log("Error loading venue event showtimes (AJAX): " . $e->getMessage());
        echo '<div class="no-shows">Error loading showtimes: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    exit(); // IMPORTANT: Exit after sending AJAX response
}

// --- Main Page Logic and HTML Structure for venue_wise_showtimes.php ---

$displayVenueInfo = false;
$selectedVenue = null; // Contains details of the currently selected venue
$current_page_selected_venue_id = null; // The venue_id selected via URL or click

// Date handling - calculate next 7 days
// Default to today if no date in URL
$selectedDate = new DateTime();
if (isset($_GET['date']) && isValidDateFormat($_GET['date'], 'Y-m-d')) { // Using a helper function for date validation
    $selectedDate = new DateTime($_GET['date']);
}
   
$today = new DateTime(); // For highlighting today's date
$dates = [];
for ($i = 0; $i < 7; $i++) {
    $date_iterator = clone $today; // Start from today
    $date_iterator->modify("+$i days");
    $dates[] = $date_iterator;
}

// Helper function for date validation
function isValidDateFormat($dateString, $format) {
    $d = DateTime::createFromFormat($format, $dateString);
    return $d && $d->format($format) === $dateString;
}

// Logic to get initial venue info if selected via URL
if ($isLoggedIn && isset($_GET['venue_id']) && $selected_city_id) {
    $current_page_selected_venue_id = filter_input(INPUT_GET, 'venue_id', FILTER_VALIDATE_INT);
    if ($current_page_selected_venue_id) {
        try {
            $stmt = $pdo->prepare("SELECT venue_id, venue_name, sub_venue_name FROM venues WHERE venue_id = :venue_id AND city_id = :city_id AND is_active = 'yes'");
            $stmt->bindParam(':venue_id', $current_page_selected_venue_id, PDO::PARAM_INT);
            $stmt->bindParam(':city_id', $selected_city_id, PDO::PARAM_INT);
            $stmt->execute();
            $selectedVenue = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($selectedVenue) {
                $displayVenueInfo = true;
            } else {
                $current_page_selected_venue_id = null; // Invalidate if not found
            }
        } catch (PDOException $e) {
            error_log("Failed to fetch selected venue details: " . $e->getMessage());
            $current_page_selected_venue_id = null;
        }
    }
}

// If no venue selected or found, try to select the first active venue in the city
if ($isLoggedIn && !$current_page_selected_venue_id && $selected_city_id) {
    try {
        $stmt = $pdo->prepare("SELECT venue_id, venue_name, sub_venue_name FROM venues WHERE city_id = :city_id AND is_active = 'yes' ORDER BY venue_name LIMIT 1");
        $stmt->bindParam(':city_id', $selected_city_id, PDO::PARAM_INT);
        $stmt->execute();
        $firstVenue = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($firstVenue) {
            $current_page_selected_venue_id = $firstVenue['venue_id'];
            $selectedVenue = $firstVenue; // Set selected venue for display
            $displayVenueInfo = true;
        }
    } catch (PDOException $e) {
        error_log("Failed to fetch first venue for default: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catchify - <?php echo $displayVenueInfo ? htmlspecialchars($selectedVenue['venue_name']) . ' Showtimes' : 'Browse Venues'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* --- Root Variables & Theme Colors --- */
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
            --dm-text-muted: #a0a0a0;
            --dm-shadow: rgba(0, 0, 0, 0.3);
            --dm-movie-title: #ffffff; /* Re-using this for event name */
            --dm-movie-subtitle: #bbbbbb;

            /* Showtime specific colors (from file2/file3) */
            --black: #1a1a1a;
            --dark-black: #0d0d0d;
            --red: #e63946;
            --orange: #f77f00; /* Overrides --accent-orange for showtimes context */
            --yellow: #fcbf49; /* Badge text color from original index.php for 'warning' */
            --peach: #ffcdb2;
            --light-peach: #fff5f0;

            /* NEW: Light Mode specific variables for showtimes elements*/
            --lm-border: #e0e0e0;
            --lm-shadow: rgba(0, 0, 0, 0.1);
            --lm-text-muted: #666;
        }

        /* --- General Body and Theme Switching --- */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color); /* General light mode text */
            transition: background-color 0.3s ease, color 0.3s ease;
            font-size: 0.9rem;
            padding-top: 150px; /* Adjust padding for fixed navbars */
            background-color: var(--primary-bg); /* Default light mode background */
        }
        body.dark-mode {
            background-color: var(--dm-bg);
            color: var(--dm-text);
            --search-result-bg: var(--dm-card-bg);
            --search-result-text: var(--dm-text);
            --search-result-hover: rgba(255,255,255,0.05);
            --search-result-border: var(--dm-border);

            /* Dark mode specific for showtimes (override existing non-dm values) */
            --dark-black: #1a1a1a; /* Darker background for components */
            --black: #2a2a2a; /* Slightly lighter background for cards */
            --peach: var(--dm-text); /* Use dm-text for main accents */
            --orange: var(--accent-orange); /* Keep accent orange for specific elements */
            --red: var(--accent-red); /* Keep accent red */
            --yellow: var(--dm-movie-meta); /* Use theme appropriate yellow */
        }

        /* Add the new CSS for form-control here */
    body.dark-mode .form-control {
        background-color: var(--dm-card-bg) !important;
        color: var(--dm-text) !important;
        border-color: var(--dm-border) !important;
    }
    body.dark-mode .form-control::placeholder {
        color: var(--dm-text-muted) !important;
    }
    body.dark-mode .search-form .btn-danger {
        box-shadow: 0 4px 8px rgba(229, 9, 20, 0.5) !important;
    }

        /* --- Navbar Styles (Same as index.php) --- */
        .top-navbar, .second-navbar {
            background-color: var(--nav-dark) !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            left: 0;
            right: 0;
            z-index: 1030; /* Ensure navbars are on top */
        }

        .top-navbar {
            top: 0;
            height: 90px; /* Set a fixed height for consistency */
            display: flex; /* Use flexbox to align content vertically */
            align-items: center; /* Center content vertically */
            padding: 0; /* Remove default padding for better control */
        }

        .second-navbar {
            top: 90px; /* Position directly below the top navbar */
            display: block !important;
            white-space: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            z-index: 1029; /* Slightly lower than top-navbar */
            padding-bottom: 0.5rem; /* For scrollbar on mobile */
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

        /* Navbar Links */
        .nav-link {
            color: var(--nav-text);
            position: relative;
            padding: 0.5rem 1rem;
            white-space: nowrap;
            transition: all 0.3s ease;
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
            opacity: 0;
        }
        .nav-link:hover::after,
        .nav-link.active-nav::after {
            width: 100%;
            opacity: 1;
        }
        .nav-link:hover {
            transform: translateY(-2px);
        }
        .active-nav {
            font-weight: bold;
        }
        .active-nav::after {
            background: var(--gradient-primary); /* Ensure active keeps color */
        }

        /* User Info & Dropdowns */
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
        .dropdown-menu {
            z-index: 1050; /* Ensure dropdowns are on top */
            background-color: var(--nav-dark);
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .dropdown-item {
            color: var(--nav-text);
            transition: all 0.2s ease;
        }
        .dropdown-item:hover {
            background: var(--gradient-secondary);
            color: white;
        }

        /* City Selector */
        .city-selector {
            min-width: 150px;
            margin-right: 10px;
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

        /* Buttons */
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

        /* Search Bar & Results */
        .search-form {
            width: 100%;
            max-width: 500px;
            position: relative;
        }
        .search-results {
            z-index: 1052; /* Highest z-index for search results */
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: var(--search-result-bg);
            border: 1px solid var(--search-result-border);
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            max-height: 400px;
            overflow-y: auto;
            display: none;
        }
        .search-result-item {
            padding: 10px 15px;
            border-bottom: 1px solid var(--search-result-border);
            transition: all 0.2s;
            color: var(--search-result-text);
            text-decoration: none;
            display: block;
        }
        .search-result-item:last-child {
            border-bottom: none;
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

        /* Layout for Navbars */
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

        /* Theme Toggle */
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

        /* Mobile Menu (hamburger for second navbar items) */
        .mobile-menu-dropdown {
            display: none; /* Hidden by default, shown in media query */
        }
        .mobile-menu-toggle {
            display: none; /* Hidden by default, shown in media query */
            background: none;
            border: none;
            color: var(--nav-text);
            font-size: 1.2rem;
            padding: 0.5rem;
            margin-left: 0.5rem;
        }

        /* --- Venue Details Page Specific Styles --- */
        /* Venue Header (similar to movie-header in index.php) */
        .venue-page-header {
            margin-top: 20px;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        body:not(.dark-mode) .venue-page-header {
            background: white;
            color: var(--text-color);
            border-left: 4px solid var(--accent-red);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        }
        body.dark-mode .venue-page-header {
            background: linear-gradient(135deg, var(--dm-header-bg) 0%, #2c2c2c 100%);
            color: var(--dm-text);
            border-left: 4px solid var(--accent-orange);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        .venue-page-title {
            font-weight: 700;
            margin-bottom: 0.25rem;
            display: inline-block;
            transition: color 0.3s ease;
            font-size: 1.8rem;
        }
        body:not(.dark-mode) .venue-page-title { color: var(--text-color) !important; }
        body.dark-mode .venue-page-title { color: var(--dm-movie-title) !important; }
        .venue-page-title small { font-size: 0.7em; }
        body:not(.dark-mode) .venue-page-title small { color: var(--accent-peach) !important; }
        body.dark-mode .venue-page-title small { color: var(--dm-movie-subtitle) !important; }

        /* Date Navigation (same as index.php) */
        .date-header {
            background-color: white;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
        }
        body.dark-mode .date-header {
            background-color: var(--dm-card-bg);
            border-color: var(--dm-border);
            box-shadow: 0 2px 8px var(--dm-shadow);
        }
        .date-cell {
            text-align: center;
            padding: 0.5rem 0.25rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            border-radius: 6px;
            position: relative;
            margin: 0 1px;
            flex-grow: 1;
            flex-basis: 0;
            text-decoration: none; /* Make links look like cells */
            color: inherit;
        }
        .date-cell:hover {
            background-color: var(--accent-peach);
            transform: translateY(-1px);
        }
        body.dark-mode .date-cell:hover { background-color: rgba(255,255,255,0.08); }
        .date-day {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }
        body.dark-mode .date-day { color: var(--dm-text-muted); }
        .date-date {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-color);
        }
        body.dark-mode .date-date { color: var(--dm-text); }
        .selected-date {
            background-color: var(--accent-peach);
            border-bottom: 2px solid var(--accent-orange);
            box-shadow: 0 2px 4px rgba(247, 127, 0, 0.2);
        }
        body.dark-mode .selected-date { background-color: rgba(247, 127, 0, 0.2); }
        .today-date {
            background-color: rgba(252, 191, 73, 0.2);
        }
        body.dark-mode .today-date { background-color: rgba(252, 191, 73, 0.1); }
        .today-badge {
            position: absolute;
            top: -6px;
            right: 6px;
            font-size: 0.55rem;
            background: var(--accent-orange);
            color: white;
            padding: 1px 4px;
            border-radius: 8px;
            font-weight: 600;
        }
        body.dark-mode .today-badge { 
            background-color: var(--accent-orange); 
            color: var(--accent-black); /* Text should be dark on light badge */
        }
        .loading-spinner {
            width: 2.5rem;
            height: 2.5rem;
            border: 0.3em solid var(--accent-orange);
            border-right-color: transparent;
        }
        body.dark-mode .loading-spinner {
            border-color: var(--dm-text-muted);
            border-right-color: transparent;
        }

        /* NEW: Venue Selector Styles (similar to date selector) */
        .venue-selector-header {
            background-color: white;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
            overflow-x: auto; /* Enable horizontal scrolling */
            white-space: nowrap; /* Prevent wrappingVENUE */
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .venue-selector-header::-webkit-scrollbar {
            display: none; /* Hide scrollbar for Webkit browsers */
        }
        body.dark-mode .venue-selector-header {
            background-color: var(--dm-card-bg);
            border-color: var(--dm-border);
            box-shadow: 0 2px 8px var(--dm-shadow);
        }
        .venue-selection-list {
            display: flex; /* Use flexbox for horizontal layout */
            gap: 8px; /* Space between venue cards */
            justify-content: flex-start; /* Align items to the start */
        }
        .venue-cell {
            flex-shrink: 0; /* Prevent venues from shrinking */
            min-width: 140px; /* Minimum width for each venue card */
            text-align: center;
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            border-radius: 6px;
            position: relative;
            border: 1px solid rgba(0,0,0,0.05); /* Light border */
            background-color: var(--secondary-bg); /* Light background */
            color: var(--text-color);
            text-decoration: none; /* Make links look like cells */
        }
        body.dark-mode .venue-cell {
            background-color: var(--black);
            border-color: #333;
            color: var(--dm-text);
        }
        .venue-cell:hover {
            background-color: var(--accent-peach);
            transform: translateY(-2px);
        }
        body.dark-mode .venue-cell:hover { background-color: rgba(255,255,255,0.08); }

        .venue-cell.selected-venue {
            background-color: var(--accent-orange);
            color: white;
            border-bottom: 2px solid var(--accent-red);
            box-shadow: 0 2px 4px rgba(247, 127, 0, 0.2);
        }
        body.dark-mode .venue-cell.selected-venue {
            background-color: var(--accent-orange);
            color: white; /* text should be white on orange background */
            border-color: var(--accent-red);
        }
        .venue-cell-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0px; /* Adjust spacing */
        }
        .venue-cell-subname {
            font-size: 0.75rem;
            color: var(--lm-text-muted); /* Muted text for sub-venue */
            margin-top: 0px;
        }
        body.dark-mode .venue-cell-subname { color: var(--dm-text-muted); }
        .venue-cell.selected-venue .venue-cell-subname {
            color: rgba(255,255,255,0.8); /* Lighter muted for selected */
        }


        /* --- Showtimes Filters & Display (COMPACT UI & Refined Theming) --- */
        /* These styles are mostly identical to index.php (event info page) but apply to events within a venue */
        .filter-container {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            border: 1px solid #333;
            background-color: var(--dark-black);
        }
        body:not(.dark-mode) .filter-container {
            background-color: var(--primary-bg);
            border-color: var(--lm-border);
            box-shadow: 0 4px 6px var(--lm-shadow);
        }
        .filter-section { margin-bottom: 10px; }
        .filter-section:last-child { margin-bottom: 0; }
        .filter-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--peach);
        }
        body:not(.dark-mode) .filter-title { color: var(--orange); }
        .filter-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .filter-tag {
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            background-color: var(--black);
            border: 1px solid #444;
            color: white;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        body:not(.dark-mode) .filter-tag {
            background-color: var(--secondary-bg);
            border-color: var(--lm-border);
            color: var(--text-color);
        }
        .filter-tag:hover {
            background-color: var(--orange);
            color: var(--dark-black);
        }
        body:not(.dark-mode) .filter-tag:hover { background-color: var(--orange); color: white; }
        .filter-tag.active {
            background-color: var(--orange);
            color: var(--dark-black);
            font-weight: 600;
        }
        body:not(.dark-mode) .filter-tag.active { background-color: var(--orange); color: white; }
        .filter-tag i { font-size: 11px; }
        .reset-filters {
            font-size: 13px;
            color: var(--peach);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 10px;
            transition: all 0.2s;
        }
        body:not(.dark-mode) .reset-filters { color: var(--orange); }
        .reset-filters:hover { color: var(--orange); }
        .filter-indicator {
            display: none;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            margin-left: 3px;
            background-color: var(--orange);
        }
        .filter-tag.active .filter-indicator { display: inline-block; background-color: white; }
        body:not(.dark-mode) .filter-tag.active .filter-indicator { background-color: white; }


        .events-in-venue-container { /* Renamed from .venues-container */
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .event-showtimes-card { /* New wrapper for each event's showtimes within a venue */
            background: linear-gradient(145deg, var(--dark-black), #2a2a2a);
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            border: 1px solid #333;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            overflow: hidden;
            margin-bottom: 20px; /* Space between events */
        }
        body:not(.dark-mode) .event-showtimes-card {
            background: white;
            border-color: var(--lm-border);
            box-shadow: 0 4px 12px var(--lm-shadow);
        }
        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            border-bottom: 1px solid #444; /* Separator for event header */
            padding-bottom: 10px;
        }
        body:not(.dark-mode) .event-header { border-bottom: 1px solid #e0e0e0; }

        .event-name {
            font-size: 1.3rem; /* Larger for event name */
            font-weight: 700;
            color: var(--peach);
        }
        body:not(.dark-mode) .event-name { color: var(--accent-red); }
        .event-name a { color: inherit; text-decoration: none; }
        .event-name a:hover { color: var(--orange); }
        .event-name small {
            font-size: 0.7em;
            color: #aaa;
        }
        body:not(.dark-mode) .event-name small { color: var(--lm-text-muted); }

        .event-meta {
            font-size: 0.85rem;
            margin-top: 5px;
        }
        .event-meta .badge {
            font-size: 0.7em;
            padding: 0.3em 0.6em;
        }
        body.dark-mode .event-meta .badge.bg-dark { background-color: var(--dm-badge-bg) !important; color: white !important; }
        body.dark-mode .event-meta .badge.bg-danger { background-color: var(--red) !important; color: white !important; }
        body.dark-mode .event-meta .badge.bg-warning { background-color: var(--yellow) !important; color: var(--text-color) !important; }

        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 8px;
        }
        .time-slot {
            padding: 8px;
            background: var(--black);
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid #444;
            transition: all 0.2s ease;
            line-height: 1.1;
            cursor: pointer;
            position: relative;
            text-decoration: none;
            display: block;
            color: inherit;
            height: 100%;
            box-sizing: border-box;
            min-height: 80px;
        }
        body:not(.dark-mode) .time-slot {
            background: var(--secondary-bg);
            border-color: var(--lm-border);
        }
        .time-slot:hover {
            background: var(--red);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            border-color: var(--red);
        }
        body:not(.dark-mode) .time-slot:hover { background: var(--red); color: white; }
        .time-slot.late {
            background: var(--orange);
            color: var(--dark-black);
            font-weight: 600;
        }
        .time-slot.late:hover {
            background: var(--red);
            color: white;
        }
        .slot-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        .slot-time { font-weight: bold; }
        .slot-availability {
            font-size: 10px;
            background: rgba(255,255,255,0.2);
            padding: 2px 6px;
            border-radius: 3px;
        }
        body:not(.dark-mode) .slot-availability { background: rgba(0,0,0,0.05); }
        .slot-availability.few-left { color: var(--orange); }
        .slot-availability.sold-out { color: var(--red); }
        .slot-availability.available { color: #4CAF50; }
        .ticket-info { display: flex; flex-direction: column; gap: 3px; }
        .ticket-type {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            padding: 3px 6px;
            background: rgba(230, 57, 70, 0.15);
            border-radius: 3px;
            border: 1px solid rgba(230, 57, 70, 0.3);
        }
        body:not(.dark-mode) .ticket-type {
            background: rgba(230, 57, 70, 0.05);
            border-color: rgba(230, 57, 70, 0.1);
        }
        .ticket-name { flex: 1; }
        .ticket-price { font-weight: bold; color: var(--yellow); margin-left: 5px; }
        .ticket-availability { font-size: 10px; margin-left: 5px; font-weight: 500; }
        .ticket-availability.few-left { color: var(--orange); font-weight: 600; }
        .ticket-availability.sold-out { color: var(--red); font-weight: 600; }
        .ticket-availability.available { color: #4CAF50; }
        .no-shows {
            text-align: center;
            padding: 30px;
            background: var(--dark-black);
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            border: 1px dashed #444;
            font-size: 14px;
            color: #eee;
        }
        body:not(.dark-mode) .no-shows {
            background: white;
            border-color: var(--lm-border);
            box-shadow: 0 2px 8px var(--lm-shadow);
            color: var(--text-color);
        }
        .no-shows-small {
            text-align: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
            font-size: 0.8em;
            color: #ccc;
            margin: 5px;
            border: 1px dashed rgba(255, 255, 255, 0.1);
        }
        body:not(.dark-mode) .no-shows-small {
            background: rgba(0, 0, 0, 0.03);
            border: 1px dashed rgba(0, 0, 0, 0.08);
            color: #666;
        }

        .no-shows::before { content: ""; font-size: 28px; display: block; margin-bottom: 10px; }
        .hidden { display: none !important; }

        /* Animation for loading showtimes */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .showtime-slot { /* Not used directly for these elements but for general animation principle */
            animation: fadeIn 0.3s ease forwards;
            opacity: 0.1;
        }
        /* Delay animations for each item */
        .showtime-slot:nth-child(1) { animation-delay: 0.1s; }
        .showtime-slot:nth-child(2) { animation-delay: 0.2s; }
        .showtime-slot:nth-child(3) { animation-delay: 0.3s; }
        .showtime-slot:nth-child(4) { animation-delay: 0.4s; }
        .showtime-slot:nth-child(5) { animation-delay: 0.5s; }
        .showtime-slot:nth-child(6) { animation-delay: 0.6s; }
        .showtime-slot:nth-child(7) { animation-delay: 0.7s; }
        .showtime-slot:nth-child(8) { animation-delay: 0.8s; }

        /* --- Responsive Styles (Same as index.php, adapted) --- */
        @media (max-width: 992px) {
            body { padding-top: 140px; }
            .second-navbar { display: none !important; }
            .mobile-menu-toggle { display: block; }
            .mobile-menu-dropdown { display: block; }
            .nav-content-wrapper { flex-wrap: wrap; }
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
            .top-navbar .container {
                flex-wrap: wrap;
                padding-left: 10px;
                padding-right: 10px;
            }
            .brand-section {
                flex-grow: 1;
            }
            .right-section {
                flex-shrink: 0;
            }
             .top-navbar{
                height: 120px;
            }
        }

        @media (max-width: 768px) {
            .date-header, .venue-selector-header {
                overflow-x: auto;
                white-space: nowrap;
                display: flex;
                padding-bottom: 0.5rem;
            }
            .date-cell, .venue-cell {
                min-width: 70px;
                display: inline-block;
                margin-right: 0.4rem;
                flex-shrink: 0;
            }
            .venue-cell { min-width: 120px; } /* Adjust for smaller screens */
            .venue-page-header { padding: 1rem; }
            .venue-page-title { font-size: 1.5rem; }
            .section-title { font-size: 1.1rem; }
            .filter-tags { gap: 6px; }
            .filter-tag { padding: 5px 10px; font-size: 13px; }
            .event-showtimes-card { padding: 10px; margin-bottom: 15px; } /* More compact */
            .event-name { font-size: 1.2rem; }

             .top-navbar{
                height: 160px;
            }
        }

        @media (max-width: 576px) {
            body { padding-top: 140px; }
            .container { padding: 0.75rem; }
            .venue-page-header { padding: 1rem 0.75rem; }
            .venue-page-title { font-size: 1.2rem; }
            .date-cell { min-width: 60px; padding: 0.4rem 0.2rem; }
            .date-day { font-size: 0.7rem; }
            .date-date { font-size: 0.9rem; }
            .section-title { font-size: 1rem; }
            .time-slot { font-size: 0.85rem; }
            .ticket-type { font-size: 0.9rem; }
            .ticket-price { font-size: 0.9rem; }
            .ticket-availability { font-size: 0.8rem; }
            .venue-cell { min-width: 100px; } /* Even smaller for mobile */
            .venue-cell-name { font-size: 0.9rem; }
            .no-shows-small { font-size: 0.7em; }
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

    <!-- Main Content Area -->
    <div class="container my-4">
        <?php if (!$isLoggedIn): ?>
            <div class="text-center py-5">
                <h2>Welcome to Catchify </h2>
                <p class="lead">Please <a href="<?= $login_page ?>">login</a> to access all features</p>
            </div>
        <?php elseif (!$selected_city_id): ?>
            <div class="alert alert-info text-center py-5">
                <h2>No City Selected</h2>
                <p class="lead">Please select a city from the dropdown in the navigation bar to browse venues and events.</p>
            </div>
        <?php else: // Logged in and city selected ?>
            <?php if ($displayVenueInfo): ?>
                <div class="venue-page-header">
                    <h2 class="venue-page-title">
                        <?= htmlspecialchars($selectedVenue['venue_name']) ?>
                        <?php if (!empty($selectedVenue['sub_venue_name'])): ?>
                            <small>(<?= htmlspecialchars($selectedVenue['sub_venue_name']) ?>)</small>
                        <?php endif; ?>
                    </h2>
                </div>
            <?php endif; ?>

            <!-- NEW: Venue Selector Header -->
            <div class="venue-selector-header">
                <h5 class="section-title mb-3">Select Venue in <?= htmlspecialchars(getCityNameById($cities, $selected_city_id)) ?></h5>
                <div class="venue-selection-list" id="venueSelectionList">
                    <div class="d-flex justify-content-center p-3">
                        <div class="spinner-border loading-spinner" role="status">
                            <span class="visually-hidden">Loading Venues...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Date Navigation -->
            <div class="date-header">
                <h5 class="section-title mb-3">Select Date</h5>
                <div class="row g-0">
                    <?php foreach ($dates as $index => $date_obj): 
                        $isSelected = $date_obj->format('Y-m-d') === $selectedDate->format('Y-m-d');
                        $isToday = $date_obj->format('Y-m-d') === (new DateTime())->format('Y-m-d');
                        $dateParam = $date_obj->format('Y-m-d');
                        $dayName = $date_obj->format('D');
                        $dateNum = $date_obj->format('d');
                        $monthName = $date_obj->format('M');
                    ?>
                        <a href="#" class="col date-cell <?= $isSelected ? 'selected-date' : '' ?> <?= $isToday ? 'today-date' : '' ?>" 
                            data-date="<?= $dateParam ?>">
                            <div class="date-day"><?= $dayName ?></div>
                            <div class="date-date"><?= $dateNum ?> <?= $monthName ?></div>
                            <?= $isToday ? '<div class="today-badge">Today</div>' : '' ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Events/Showtimes Container (loaded via AJAX) -->
            <h5 class="section-title">Available Event Showtimes at This Venue</h5>
            <div id="venueEventShowtimesContainer">
                <div class="d-flex justify-content-center my-5 py-5">
                    <div class="spinner-border loading-spinner" role="status">
                        <span class="visually-hidden">Loading Showtimes...</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>


    <footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Catchify. All rights reserved.</p>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <!-- Bootstrap JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
    // --- Theme Toggle Functionality ---
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const bodyElement = document.body;
    
    function updateThemeIcon(theme) {
        if (theme === 'dark') {
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        } else {
            themeIcon.classList.remove('fa-sun');
            themeIcon.classList.add('fa-moon');
        }
    }

    // Check for saved theme preference or use system preference
    const savedTheme = localStorage.getItem('theme');
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    // Apply the saved theme or system preference on load
    if (savedTheme === 'dark' || (!savedTheme && systemPrefersDark)) {
        bodyElement.classList.add('dark-mode');
        updateThemeIcon('dark');
    } else {
        bodyElement.classList.remove('dark-mode');
        updateThemeIcon('light');
    }
    
    // Event listener for theme toggle button
    themeToggle.addEventListener('click', () => {
        const isDarkMode = bodyElement.classList.contains('dark-mode');
        if (isDarkMode) {
            bodyElement.classList.remove('dark-mode');
            localStorage.setItem('theme', 'light');
            updateThemeIcon('light');
        } else {
            bodyElement.classList.add('dark-mode');
            localStorage.setItem('theme', 'dark');
            updateThemeIcon('dark');
        }
    });

    // --- City Dropdown Specific Initialization ---
    // Explicitly initialize the city dropdown button.
    // This can sometimes fix issues if Bootstrap's auto-init fails for specific elements.
    const cityDropdownButton = document.getElementById('cityDropdown');
    if (cityDropdownButton) {
        new bootstrap.Dropdown(cityDropdownButton);
    }
    // No other general dropdown initialization is needed.

    // --- City Search and Selection ---
    const citySearch = document.getElementById('citySearch');
    const cityListItemsDiv = document.getElementById('cityListItems');
    // Ensure cityItems is populated only if cityListItemsDiv exists, which it should.
    let cityItems = cityListItemsDiv ? cityListItemsDiv.querySelectorAll('.city-item') : [];

    // Pass PHP $selected_city_id to JS
    const currentSelectedCityId = "<?= ($selected_city_id !== null) ? $selected_city_id : '' ?>";

    if (citySearch) {
        citySearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            cityItems.forEach(item => {
                const cityName = item.querySelector('a').textContent.toLowerCase();
                item.style.display = cityName.includes(searchTerm) ? 'block' : 'none';
            });
        });
    }
    
    // City selection (auto-submit form) - Modified for URL Preservation
    cityItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent <a> default behavior
            const cityId = this.getAttribute('data-value');
            document.getElementById('selectedCity').value = cityId;
            
            const cityForm = document.getElementById('cityForm');
            // Hidden input to signify city was selected by POST
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'city_submitted';
            hiddenInput.value = '1';
            cityForm.appendChild(hiddenInput);

            // Preserve venue_id and date if they exist in the current URL
            const currentUrlParams = new URL(window.location.href).searchParams;
            const venueId = currentUrlParams.get('venue_id');
            const date = currentUrlParams.get('date');

            if (venueId) {
                const venueIdInput = document.createElement('input');
                venueIdInput.type = 'hidden';
                venueIdInput.name = 'venue_id';
                venueIdInput.value = venueId;
                cityForm.appendChild(venueIdInput);
            }
            if (date) {
                const dateInput = document.createElement('input');
                dateInput.type = 'hidden';
                dateInput.name = 'date';
                dateInput.value = date;
                cityForm.appendChild(dateInput);
            }
            cityForm.submit();
        });
    });

    // --- Search functionality with AJAX ---
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    const searchForm = document.querySelector('.search-form');

    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value.trim();
            if (searchTerm.length >= 2) {
                searchTimeout = setTimeout(() => {
                    fetchSearchResults(searchTerm);
                }, 300); // Debounce
            } else {
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
            }
        });

        // Prevent default form submission on enter for AJAX search
        if (searchForm) {
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const searchTerm = searchInput.value.trim();
                if (searchTerm.length >= 2) {
                    window.location.href = `index.php?search=${encodeURIComponent(searchTerm)}`;
                }
            });
        }
        
        // Hide results when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });
    }
    
    function fetchSearchResults(searchTerm) {
        fetch(`index.php?ajax=search&search=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                if (data.length > 0) {
                    let html = '';
                    data.forEach(item => {
                        html += `
                            <a href="event_info.php?event_id=${item.event_id}" class="search-result-item">
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
    
    // --- Venue Specific Showtimes Loading & Filtering ---
    const venueSelectionList = document.getElementById('venueSelectionList');
    const venueEventShowtimesContainer = document.getElementById('venueEventShowtimesContainer');
    const urlParams = new URL(window.location.href).searchParams;

    // Get initial venue and date from URL or set defaults
    let currentSelectedVenueId_JS = urlParams.get('venue_id') ? parseInt(urlParams.get('venue_id')) : null;
    let currentSelectedDate_JS = urlParams.get('date') || new Date().toISOString().split('T')[0];

    // These variables persist filters even if showtimes are reloaded
    let currentActivePriceFilters = [];
    let currentActiveTimeFilters = [];

    // Helper function to toggle filter state (add/remove from array, update class)
    function toggleFilter(tagElement, value, filtersArray) {
        const index = filtersArray.indexOf(value);
        if (index > -1) {
            filtersArray.splice(index, 1);
            tagElement.classList.remove('active');
            if (tagElement.querySelector('.filter-indicator')) tagElement.querySelector('.filter-indicator').classList.remove('active');
        } else {
            filtersArray.push(value);
            tagElement.classList.add('active');
            if (tagElement.querySelector('.filter-indicator')) tagElement.querySelector('.filter-indicator').classList.add('active');
        }
    }
    
    // This function applies the current filters to the visible showtime events
    function applyShowtimeFilters() {
        const eventCards = document.querySelectorAll('.event-showtimes-card');
        let eventsVisibleCount = 0;
        eventCards.forEach(card => {
            // Ensure data attributes are parsed correctly
            const priceRangesAvailable = card.dataset.priceRanges ? card.dataset.priceRanges.split(',') : [];
            const timeCategoriesAvailable = card.dataset.timeCategories ? card.dataset.timeCategories.split(',') : [];

            let priceMatch = currentActivePriceFilters.length === 0;
            if (!priceMatch) {
                // Check if any active price filter is present in the card's available price ranges
                priceMatch = currentActivePriceFilters.some(filter => priceRangesAvailable.includes(filter));
            }

            let timeMatch = currentActiveTimeFilters.length === 0;
            if (!timeMatch) {
                // Check if any active time filter is present in the card's available time categories
                timeMatch = currentActiveTimeFilters.some(filter => timeCategoriesAvailable.includes(filter));
            }

            if (priceMatch && timeMatch) {
                card.style.display = 'block';
                eventsVisibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        // Show/hide "No events match" message
        const noShowsMessage = document.getElementById('noShowsMessage');
        if (noShowsMessage) {
            if (eventsVisibleCount === 0) {
                noShowsMessage.classList.remove('hidden');
            } else {
                noShowsMessage.classList.add('hidden');
            }
        }
    }


    // This function loads active filters from URL params and applies them visually and logically
    function loadActiveFiltersFromURL() {
        const urlSearchParams = new URLSearchParams(window.location.search);
        const priceParam = urlSearchParams.get('price');
        const timeParam = urlSearchParams.get('time');

        // Clear previously stored filters
        currentActivePriceFilters = []; // Reset on every load
        currentActiveTimeFilters = [];  // Reset on every load

        // Reset active classes on all filter tags before re-applying based on URL
        document.querySelectorAll('.filter-tag').forEach(tag => {
            tag.classList.remove('active');
            if (tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.remove('active');
        });

        if (priceParam) {
            priceParam.split(',').forEach(filterValue => {
                // Corrected: Use actual filterValue to select the tag
                const tag = document.querySelector(`#priceFilters .filter-tag[data-value="${filterValue}"]`);
                if (tag) {
                    tag.classList.add('active');
                    if (tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.add('active');
                    currentActivePriceFilters.push(filterValue);
                }
            });
        }

        if (timeParam) {
            timeParam.split(',').forEach(filterValue => {
                // Corrected: Use actual filterValue to select the tag
                const tag = document.querySelector(`#timeFilters .filter-tag[data-value="${filterValue}"]`);
                if (tag) {
                    tag.classList.add('active');
                    if (tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.add('active');
                    currentActiveTimeFilters.push(filterValue);
                }
            });
        }
        applyShowtimeFilters(); // Apply loaded filters to the visible showtimes
    }

    // Attaches event listeners for filter tags using delegation
    function attachFilterEventListeners() {
        const filterContainer = document.querySelector('.filter-container'); // Parent for delegation
        if (!filterContainer) return;

        // Only attach listeners once to the parent container
        if (!filterContainer.dataset.filterListenersAttached) {
            filterContainer.addEventListener('click', function(e) {
                const target = e.target.closest('.filter-tag');
                if (target) {
                    const filterType = target.dataset.filter;
                    const filterValue = target.dataset.value;

                    if (filterType === 'price') {
                        toggleFilter(target, filterValue, currentActivePriceFilters);
                    } else if (filterType === 'time') {
                        toggleFilter(target, filterValue, currentActiveTimeFilters);
                    }
                    applyShowtimeFilters();
                    updateVenueShowtimeUrl(); // Update URL after filter change
                }
            });

            const resetFiltersBtn = document.getElementById('resetFilters');
            if (resetFiltersBtn) {
                resetFiltersBtn.addEventListener('click', function() {
                    currentActivePriceFilters = []; // Clear filter arrays
                    currentActiveTimeFilters = [];
                    // Visually deactivate all filter tags
                    document.querySelectorAll('.filter-tag').forEach(tag => {
                        tag.classList.remove('active');
                        if(tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.remove('active');
                    });
                    applyShowtimeFilters();
                    updateVenueShowtimeUrl(true); // Signal reset to URL update
                });
            }
            filterContainer.dataset.filterListenersAttached = true; // Mark as attached
        }
    }


    // Function to load venues for the horizontal selector
    async function loadVenues(cityId) {
        if (!cityId) {
            venueSelectionList.innerHTML = '<div class="no-shows-small">Please select a city.</div>';
            venueEventShowtimesContainer.innerHTML = '<div class="no-shows my-4">No showtimes available as no city is selected.</div>';
            return;
        }
        venueSelectionList.innerHTML = `
            <div class="d-flex justify-content-center p-3 w-100">
                <div class="spinner-border loading-spinner" role="status">
                    <span class="visually-hidden">Loading Venues...</span>
                </div>
            </div>
        `;
        try {
            // Corrected template literal syntax for city_id
            const response = await fetch(`venue_wise_showtimes.php?ajax=venues_in_city&city_id=${cityId}`);
            if (!response.ok) throw new Error('Failed to fetch venues for the city.');
            const venues = await response.json();

            if (venues.length > 0) {
                let venuesHtml = '<div class="venue-selection-list ps-2">'; // Opening wrapper for flex
                // If no venue is specified in URL or previous selection, use the first one available
                if (!currentSelectedVenueId_JS || !venues.some(v => v.venue_id === currentSelectedVenueId_JS)) {
                    currentSelectedVenueId_JS = venues[0].venue_id;
                }
                venues.forEach(venue => {
                    const isSelected = venue.venue_id === currentSelectedVenueId_JS;
                    venuesHtml += `
                        <a href="#" class="venue-cell ${isSelected ? 'selected-venue' : ''}" data-venue-id="${venue.venue_id}">
                            <p class="venue-cell-name">${venue.venue_name}</p>
                            ${venue.sub_venue_name ? `<p class="venue-cell-subname">${venue.sub_venue_name}</p>` : ''}
                        </a>
                    `;
                });
                venuesHtml += '</div>'; // Closing wrapper
                venueSelectionList.innerHTML = venuesHtml;
                
                // Attach click listeners to new venue cells
                document.querySelectorAll('.venue-cell').forEach(cell => {
                    cell.addEventListener('click', function(e) {
                        e.preventDefault(); // Prevent default link behavior
                        currentSelectedVenueId_JS = parseInt(this.dataset.venueId);
                        updateVenueUI(currentSelectedVenueId_JS); // Update UI for venue selection
                        loadVenueEventShowtimes(currentSelectedVenueId_JS, currentSelectedDate_JS, currentSelectedCityId);
                        updateVenueShowtimeUrl();
                    });
                });
                
                // After loading venues, load showtimes for the initially selected/default venue
                if (currentSelectedVenueId_JS) {
                    loadVenueEventShowtimes(currentSelectedVenueId_JS, currentSelectedDate_JS, currentSelectedCityId);
                }
            } else {
                venueSelectionList.innerHTML = '<div class="no-shows-small w-100 text-center py-2">No venues with active shows available in this city.</div>';
                venueEventShowtimesContainer.innerHTML = '<div class="no-shows my-4">No showtimes available for any venue in this city.</div>';
            }
        } catch (error) {
            console.error('Error loading venues:', error);
            venueSelectionList.innerHTML = '<div class="no-shows-small w-100 text-center py-2">Error loading venues. Please try again.</div>';
        }
    }

    // Function to update Venue UI (highlight selection)
    function updateVenueUI(selectedVenueId) {
        document.querySelectorAll('.venue-cell').forEach(cell => {
            if (parseInt(cell.dataset.venueId) === selectedVenueId) {
                cell.classList.add('selected-venue');
            } else {
                cell.classList.remove('selected-venue');
            }
        });
    }

    // Function to load event showtimes for a selected venue, date, and city
    window.loadVenueEventShowtimes = async function(venueId, dateStr, cityId) {
        if (!venueId || !dateStr || !cityId) {
            venueEventShowtimesContainer.innerHTML = '<div class="no-shows">Incomplete selection: venue, date, or city missing.</div>';
            return;
        }

        currentSelectedVenueId_JS = venueId; // Ensure JS global var is updated
        currentSelectedDate_JS = dateStr; // Update global
        
        updateVenueUI(venueId); // Ensure venue is highlighted
        updateDateUI(dateStr); // Ensure date is highlighted

        // Update URL params (filters, venue, date)
        updateVenueShowtimeUrl();

        // Show loading spinner for showtimes
        venueEventShowtimesContainer.innerHTML = `
            <div class="d-flex justify-content-center my-5 py-5">
                <div class="spinner-border loading-spinner" role="status">
                    <span class="visually-hidden">Loading Showtimes...</span>
                </div>
            </div>
        `;
        
        try {
            // Corrected template literal syntax for venueId, dateStr, and cityId
            const response = await fetch(`venue_wise_showtimes.php?ajax=venue_event_showtimes&venue_id=${venueId}&date=${dateStr}&city_id=${cityId}`);
            if (!response.ok) throw new Error('Failed to fetch event showtimes for venue.');
            const html = await response.text();
            venueEventShowtimesContainer.innerHTML = html;
            attachFilterEventListeners(); // Attach filter listeners for new content
            loadActiveFiltersFromURL(); // Re-apply filters from URL to the new content
        } catch (error) {
            console.error('Error loading venue event showtimes:', error);
            venueEventShowtimesContainer.innerHTML = `
                <div class="no-shows">
                    <h5 class="no-shows-title"><i class="fas fa-exclamation-triangle"></i> Error Loading Showtimes</h5>
                    <p>Failed to load showtimes for this venue and date. Please try again later.</p>
                    <small class="text-muted">${error.message}</small>
                </div>
            `;
        }
    };

    // Function to update the selected date cell and its URL parameter
    function updateDateUI(dateStr) {
        document.querySelectorAll('.date-cell').forEach(cell => {
            cell.classList.remove('selected-date');
            if (cell.dataset.date === dateStr) {
                cell.classList.add('selected-date');
                // Add pulse animation on date selection for visual feedback
                cell.style.animation = 'pulse 0.5s ease';
                setTimeout(() => { cell.style.animation = ''; }, 500);
            }
        });
    }

    // Attach event listeners to date cells
    const dateCells = document.querySelectorAll('.date-cell');
    dateCells.forEach(cell => {
        cell.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent default link behavior
            const dateParam = this.dataset.date;
            loadVenueEventShowtimes(currentSelectedVenueId_JS, dateParam, currentSelectedCityId);
        });
    });

    // Update URL parameters without reloading for venues and showtimes
    function updateVenueShowtimeUrl(resetFilters = false) {
        const url = new URL(window.location);
        url.searchParams.set('venue_id', currentSelectedVenueId_JS);
        url.searchParams.set('date', currentSelectedDate_JS);
        url.searchParams.set('city_id', currentSelectedCityId); // Ensure city_id is always in URL
        
        if (resetFilters) {
            url.searchParams.delete('price');
            url.searchParams.delete('time');
        } else {
            if (currentActivePriceFilters.length > 0) {
                url.searchParams.set('price', currentActivePriceFilters.join(','));
            } else {
                url.searchParams.delete('price');
            }
            
            if (currentActiveTimeFilters.length > 0) {
                url.searchParams.set('time', currentActiveTimeFilters.join(','));
            } else {
                url.searchParams.delete('time');
            }
        }
        window.history.pushState({}, '', url); // Update URL without full page reload
    }

    // Handle browser back/forward buttons for showtimes/venues
    window.addEventListener('popstate', function() {
        const urlSearchParams = new URLSearchParams(window.location.search);
        const venueIdParam = urlSearchParams.get('venue_id');
        const dateParam = urlSearchParams.get('date');
        const cityIdParam = urlSearchParams.get('city_id');

        if (venueIdParam || dateParam) {
            currentSelectedVenueId_JS = venueIdParam ? parseInt(venueIdParam) : currentSelectedVenueId_JS;
            currentSelectedDate_JS = dateParam || new Date().toISOString().split('T')[0];
            // Reload venues (and showtimes)
            loadVenues(currentSelectedCityId);
        } else {
            // If no venue/date selected, re-initialize based on selected city.
            loadVenues(currentSelectedCityId);
        }

        loadActiveFiltersFromURL(); // Load filters on popstate as well
    });

    // Initial load of venues and showtimes if logged in and city selected
    if (currentSelectedCityId && currentSelectedCityId !== '0') {
      loadVenues(currentSelectedCityId);
      updateDateUI(currentSelectedDate_JS); // Also update the date cell highlight on initial page load
    }

    // Add pulse animation for selected date (CSS keyframes defined inline)
    const style = document.createElement('style');
    style.textContent = `
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }
    `;
    document.head.appendChild(style);
}); // End DOMContentLoaded
</script>

</body>
</html>