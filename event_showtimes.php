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
// Note: basename($_SERVER['PHP_SELF']) will now be 'event_showtimes.php' for this file.
$auth_pages = [basename($_SERVER['PHP_SELF']), basename($login_page), basename($register_page), basename($forgot_pass)];

if (!isset($_SESSION['user_id'])) {
    // If not logged in, and trying to access a non-auth page, redirect to login
    // If we are on the main page (event_showtimes.php) and not asking for a specific auth form
    // and user is not logged in, redirect to the explicit login page.
    // Check if the current request is for an AJAX endpoint. If so, don't redirect, just proceed (the endpoint can handle auth errors).
    if (!isset($_GET['ajax']) && (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages) ||
        (basename($_SERVER['PHP_SELF']) == 'event_showtimes.php' && !isset($_GET['event_id']) && !isset($_GET['login']) && !isset($_GET['register']) && !isset($_GET['forgot_password']))) ) {
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
// Fetch cities from database (only if logged in or if needed for dropdown by non-logged in users)
// Caching these in session might be better
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

        // --- NEW: Preserve event_id and date in the URL after city selection ---
        $redirectUrl = $_SERVER['PHP_SELF'];
        $queryParams = [];

        // Check GET parameters from the current URL
        if (isset($_GET['event_id'])) {
            $queryParams['event_id'] = $_GET['event_id'];
        }
        if (isset($_GET['date'])) {
            $queryParams['date'] = $_GET['date'];
        }
        
        // Also check POST parameters which might come from hidden inputs added by JS
        if (isset($_POST['event_id']) && !isset($queryParams['event_id'])) {
            $queryParams['event_id'] = $_POST['event_id'];
        }
        if (isset($_POST['date']) && !isset($queryParams['date'])) {
            $queryParams['date'] = $_POST['date'];
        }
        // --- END NEW ---

        if (!empty($queryParams)) {
            $redirectUrl .= '?' . http_build_query($queryParams);
        }

        header("Location: " . $redirectUrl); // Redirect to clear POST data
        exit();
    }
}

// --- AJAX Endpoints ---
// This part handles requests for specific data/HTML snippets without a full page reload.
// It will exit after sending its response.

// AJAX Search Results API (returns JSON)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search' && $isLoggedIn) {
    $searchTerm = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
    $searchResults = [];
    if (!empty($_GET['search'])) {
        try {
            $stmt = $pdo->prepare("SELECT event_id, event_name, event_type FROM event_info 
                                 WHERE event_name LIKE :search 
                                 AND is_active = 'yes'
                                 ORDER BY event_name LIMIT 10");
            $stmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
            $stmt->execute();
            $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Log error, but don't expose sensitive info to client
            error_log("AJAX search failed: " . $e->getMessage());
        }
    }
    header('Content-Type: application/json');
    echo json_encode($searchResults);
    exit(); // IMPORTANT: Exit after sending AJAX response
}

// AJAX Showtime Grid for event (returns HTML snippet)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'showtimes' && $isLoggedIn && isset($_GET['event_id']) && isset($_GET['date'])) {
    // This essentially contains the logic from file3.php (excluding global setup)
    
    // Get and validate parameters
    $eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
    $date = filter_input(INPUT_GET, 'date');

    if (!$eventId || !$date) {
        // Return a basic error HTML (or JSON) if parameters are missing
        echo '<div class="no-shows">Invalid event ID or date provided.</div>';
        exit();
    }

    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');
    $city_id = isset($_SESSION['selected_city']) ? (int)$_SESSION['selected_city'] : null;

    // --- NEW: Display message if no city selected ---
    if (!$city_id) {
        echo '<div class="no-shows">Please select a city to view showtimes for this event.</div>';
        exit();
    }
    // --- END NEW ---

    // Prepare price ranges for filters (these would ideally be dynamically built from DB)
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
        // Query to get venues with their showtimes and schedule IDs
        $query = "
        SELECT 
            v.venue_id,
            v.venue_name, 
            v.sub_venue_name,
            v.capacity,
            es.event_schedule_id,
            MIN(est.ticket_price) as min_price,
            MAX(est.ticket_price) as max_price,
            DATE(vs.slot_starts_at) as slot_date,
            TIME_FORMAT(vs.slot_starts_at, '%h:%i %p') as formatted_time,
            TIME(vs.slot_starts_at) as time_value,
            TIME(vs.slot_ends_at) as end_time,
            COUNT(DISTINCT est.ticket_id) as total_seats,
            SUM(CASE WHEN est.is_vacant = 'yes' THEN 1 ELSE 0 END) as available_seats
        FROM event_schedule es
        JOIN venues v ON es.venue_id = v.venue_id
        JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
        JOIN event_schedule_tickets est ON es.event_schedule_id = est.event_schedule_id
        WHERE 
            es.event_id = :event_id
            AND es.is_active = 'yes'
            AND v.city_id = :city_id
            AND DATE(vs.slot_starts_at) = :date
        GROUP BY 
            v.venue_id, v.venue_name, v.sub_venue_name, v.capacity, 
            es.event_schedule_id, DATE(vs.slot_starts_at), 
            TIME_FORMAT(vs.slot_starts_at, '%h:%i %p'), TIME(vs.slot_starts_at), TIME(vs.slot_ends_at)
        ORDER BY 
            v.venue_name, v.sub_venue_name, vs.slot_starts_at;
        ";
        
        // Prepare and execute main query
        $stmt = $pdo->prepare($query); // Use the global $pdo from db_config.php
        $stmt->execute([
            ':event_id' => $eventId, 
            ':date' => $date,
            ':city_id' => $city_id
        ]);
        $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process venues data
        $groupedVenues = [];
        
        foreach ($venues as $venue) {
            $hour = (int)substr($venue['time_value'], 0, 2);
            $isLate = $hour >= 22 || ($hour >= 0 && $hour < 6); // Late show if between 10pm-6am
            
            // Determine time category
            $timeCategory = '';
            if ($hour >= 6 && $hour < 12) {
                $timeCategory = 'morning';
            } elseif ($hour >= 12 && $hour < 17) {
                $timeCategory = 'afternoon';
            } elseif ($hour >= 17 && $hour < 22) {
                $timeCategory = 'evening';
            } else {
                $timeCategory = 'night';
            }
            
            // Only include time slots that haven't ended yet (for current date)
            if ($date > $current_date || ($date == $current_date && $venue['end_time'] > $current_time)) {
                // Get ticket types and availability for this venue and time slot
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
                $ticketStmt->execute([':schedule_id' => $venue['event_schedule_id']]);
                $tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Create venue key
                $venueKey = $venue['venue_id'];
                
                if (!isset($groupedVenues[$venueKey])) {
                    $groupedVenues[$venueKey] = [
                        'venue_id' => $venue['venue_id'],
                        'venue_name' => $venue['venue_name'],
                        'sub_venue_name' => $venue['sub_venue_name'],
                        'capacity' => $venue['capacity'],
                        'time_slots' => [],
                        'min_price' => PHP_INT_MAX,
                        'max_price' => 0,
                        'price_ranges' => [],
                        'time_categories' => []
                    ];
                }
                
                // Add time slot
                $timeSlot = [
                    'schedule_id' => $venue['event_schedule_id'],
                    'formatted_time' => $venue['formatted_time'],
                    'time_value' => $venue['time_value'],
                    'end_time' => $venue['end_time'],
                    'is_late' => $isLate,
                    'time_category' => $timeCategory,
                    'total_seats' => $venue['total_seats'],
                    'available_seats' => $venue['available_seats'],
                    'tickets' => $tickets
                ];
                
                $groupedVenues[$venueKey]['time_slots'][] = $timeSlot;
                $groupedVenues[$venueKey]['time_categories'][$timeCategory] = true;
                
                // Update min/max prices for the venue
                if ($venue['min_price'] < $groupedVenues[$venueKey]['min_price']) {
                    $groupedVenues[$venueKey]['min_price'] = $venue['min_price'];
                }
                if ($venue['max_price'] > $groupedVenues[$venueKey]['max_price']) {
                    $groupedVenues[$venueKey]['max_price'] = $venue['max_price'];
                }
                
                // Add price ranges for this time slot
                foreach ($tickets as $ticket) {
                    $price = (int)$ticket['ticket_price'];
                    if ($price < 200) {
                        $groupedVenues[$venueKey]['price_ranges']['under-200'] = true;
                    } elseif ($price >= 200 && $price < 500) {
                        $groupedVenues[$venueKey]['price_ranges']['200-500'] = true;
                    } elseif ($price >= 500 && $price < 1000) {
                        $groupedVenues[$venueKey]['price_ranges']['500-1000'] = true;
                    } else {
                        $groupedVenues[$venueKey]['price_ranges']['over-1000'] = true;
                    }
                }
            }
        }

        // Output HTML for the showtimes section (from file3.php)
        // This is sent back to the client and inserted into the DOM.
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

    <div class="venues-container" id="venuesContainer">
        <?php foreach ($groupedVenues as $venue): ?>
            <div class="venue-row" 
                data-venue-id="<?= $venue['venue_id'] ?>"
                data-price-ranges="<?= implode(',', array_keys($venue['price_ranges'])) ?>"
                data-time-categories="<?= implode(',', array_keys($venue['time_categories'])) ?>">
                <div class="venue-header">
                    <div>
                        <div class="venue-name">
                            <a href="<?= $venue_wise_showtimes ?>?event_id=<?= $eventId ?>&venue_id=<?= $venue['venue_id'] ?>&city_id=<?= $city_id ?>">
                                <?= htmlspecialchars($venue['venue_name']) ?>
                            </a>
                        </div>
                        <?php if (!empty($venue['sub_venue_name'])): ?>
                            <div class="venue-subname"><?= htmlspecialchars($venue['sub_venue_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if (PHP_INT_MAX == $venue['min_price'] && 0 == $venue['max_price']): // No valid time slots found for this venue ?>
                        <div class="price-range">N/A</div>
                    <?php else: ?>
                        <div class="price-range">
                            ₹<?= htmlspecialchars($venue['min_price']) ?> - ₹<?= htmlspecialchars($venue['max_price']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="time-slots-container">
                    <?php 
                    $hasVisibleSlots = false; // This is for PHP, not JS filter
                    foreach ($venue['time_slots'] as $slot): 
                        // Determine overall availability status for the slot card
                        $availabilityStatus = '';
                        if ($slot['available_seats'] <= 0) {
                            $availabilityStatus = 'sold-out';
                        } elseif ($slot['available_seats'] < 5) {
                            $availabilityStatus = 'few-left';
                        } else {
                            $availabilityStatus = 'available';
                        }
                    ?>
                        <a href="<?= $ticket_map ?>?event_id=<?= $eventId ?>&venue_id=<?= $venue['venue_id'] ?>&schedule_id=<?= $slot['schedule_id'] ?>" 
                            class="time-slot <?= $slot['is_late'] ? 'late' : '' ?>"
                            data-time-category="<?= $slot['time_category'] ?>"
                            data-price-min="<?= htmlspecialchars($slot['tickets'][0]['ticket_price'] ?? 0) ?>">
                            <div class="slot-header">
                                <div class="slot-time"><?= $slot['formatted_time'] ?></div>
                                <div class="slot-availability <?= $availabilityStatus ?>">
                                    <?php 
                                        if ($slot['available_seats'] <= 0) {
                                            echo 'Sold out';
                                        } elseif ($slot['available_seats'] < 5) {
                                            echo 'Few left';
                                        } else {
                                            echo $slot['available_seats'] . '/' . $slot['total_seats'];
                                        }
                                    ?>
                                </div>
                            </div>
                            <div class="ticket-info">
                                <?php foreach ($slot['tickets'] as $ticket): 
                                    $ticketAvailability = '';
                                    if ($ticket['available_seats'] <= 0) {
                                        $ticketAvailability = 'sold-out';
                                    } elseif ($ticket['available_seats'] < 5) {
                                        $ticketAvailability = 'few-left';
                                    } else {
                                        $ticketAvailability = 'available';
                                    }
                                ?>
                                    <div class="ticket-type">
                                        <span class="ticket-name"><?= htmlspecialchars($ticket['ticket_type']) ?></span>
                                        <span class="ticket-price">₹<?= htmlspecialchars($ticket['ticket_price']) ?></span>
                                        <span class="ticket-availability <?= $ticketAvailability ?>">
                                            <?php 
                                                if ($ticket['available_seats'] <= 0) {
                                                    echo 'Sold out';
                                                } elseif ($ticket['available_seats'] < 5) {
                                                    echo 'Few left';
                                                } else {
                                                    echo $ticket['available_seats'] . '/' . $ticket['total_seats'];
                                                }
                                            ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </a>
                    <?php 
                    $hasVisibleSlots = true; // Mark that at least one slot was processed (even if it ends up filtered by JS)
                    endforeach; ?>
                    <?php if (!$hasVisibleSlots): // If 'hasVisibleSlots' is still false, it means no valid future slots were found in PHP level ?>
                        <div class="no-shows-small">No valid showtimes available for this venue on the selected date.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($groupedVenues)): ?>
            <div class="no-shows">
                <i class="fas fa-frown fa-2x mb-3"></i><br>
                No upcoming showtimes available in <strong><?= htmlspecialchars(getCityNameById($cities, $city_id)) ?></strong> for this event on <strong><?= htmlspecialchars($date) ?></strong>.
            </div>
        <?php else: ?>
            <div class="no-shows hidden" id="noShowsMessage">
                <i class="fas fa-filter fa-2x mb-3"></i><br>
                No upcoming showtimes available for the selected filters in <strong><?= htmlspecialchars(getCityNameById($cities, $city_id)) ?></strong>.
            </div>
        <?php endif; ?>
<?php
    } catch(PDOException $e) {
        error_log("Error loading showtimes (AJAX): " . $e->getMessage());
        echo '<div class="no-shows">Error loading showtimes: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    exit(); // IMPORTANT: Exit after sending AJAX response
}

// --- Main Page Logic and HTML Structure ---

// Check if we are on an event detail page (from file2.php's original logic)
$displayEventInfo = false;
$event = null;
$dates = [];
$selectedDate = new DateTime(); // Default to today

if ($isLoggedIn && isset($_GET['event_id'])) {
    $displayEventInfo = true;
    $eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

    if ($eventId) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM event_info WHERE event_id = :event_id AND is_active = 'yes'");
            $stmt->bindParam(':event_id', $eventId, PDO::PARAM_INT);
            $stmt->execute();
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$event) {
                // Event not found or inactive, redirect to home page
                header("Location: $home_page");
                exit();
            }
        } catch (PDOException $e) {
            error_log("Failed to fetch event details: " . $e->getMessage());
            // Fallback to home page or show error
            header("Location: $home_page");
            exit();
        }
    } else {
        // Invalid event ID, redirect to home page
        header("Location: $home_page");
        exit();
    }

    // Date handling - calculate next 7 days (from file2.php)
    if (isset($_GET['date']) && DateTime::createFromFormat('Y-m-d', $_GET['date'])) {
        $selectedDate = new DateTime($_GET['date']);
    } else {
        $selectedDate = new DateTime(); // Default to today
    }
    
    $today = new DateTime(); // For highlighting today's date
    for ($i = 0; $i < 7; $i++) {
        $date_iterator = clone $today; // Start from today, not $selectedDate
        $date_iterator->modify("+$i days");
        $dates[] = $date_iterator;
    }
} else {
    // Logic for home page (from file1.php)
    $nowShowing = [];
    if ($isLoggedIn && !empty($_SESSION['selected_city'])) {
        try {
            // Note: The previous logic might fetch events without shows in the city
            // if s.show_time is NULL. Refined query to only fetch if shows exist
            // in the selected city.
            $stmt = $pdo->prepare("SELECT e.event_id, e.event_name, e.event_image, e.event_type
                                FROM event_info e
                                WHERE e.is_active = 'yes'
                                AND EXISTS (
                                    SELECT 1 FROM event_schedule es
                                    JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
                                    JOIN venues v ON es.venue_id = v.venue_id
                                    WHERE es.event_id = e.event_id
                                    AND v.city_id = :city_id
                                    AND DATE(vs.slot_starts_at) >= CURDATE()
                                    LIMIT 1
                                )
                                ORDER BY e.event_name
                                LIMIT 12");
            $stmt->bindParam(':city_id', $_SESSION['selected_city'], PDO::PARAM_INT);
            $stmt->execute();
            $nowShowing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Failed to fetch now showing events: " . $e->getMessage());
            // Optionally, define a hardcoded list for errors
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catchify - <?= $displayEventInfo ? htmlspecialchars($event['event_name']) : 'Your Ultimate Event Destination' ?></title>
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
            --dm-badge-bg: #333;
            --dm-movie-title: #ffffff;
            --dm-movie-subtitle: #bbbbbb;
            --dm-movie-meta: #fcbf49;

            /* Showtime specific colors (from file2/file3) */
            --black: #1a1a1a;
            --dark-black: #0d0d0d;
            --red: #e63946;
            --orange: #f77f00; /* Overrides --accent-orange for showtimes context */
            --yellow: #fcbf49;
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

        /* --- Navbar Styles (No changes here, copied from original) --- */
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

        /* --- Home Page Specific Styles (No changes here, copied from original) --- */
        .event-card {
            border: none;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        .event-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        }
        .event-card-img {
            max-height: 200px;
            object-fit: cover;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }
        .event-type-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background-color: var(--accent-orange);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8em;
            text-transform: capitalize;
        }
        .event-card-body {
            padding: 15px;
        }
        .event-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .dark-mode .event-card {
            background-color: var(--dm-card-bg);
            color: var(--dm-text);
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }

        /* --- Event Details (Showtimes) Page Specific Styles --- */
        /* Movie Header */
        .movie-header {
            margin-top: 20px; /* Reduced margin */
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        body:not(.dark-mode) .movie-header { /* Use :not(.dark-mode) for specific light mode styles */
            background: white;
            color: var(--text-color);
            border-left: 4px solid var(--accent-red);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        }
        body.dark-mode .movie-header {
            background: linear-gradient(135deg, var(--dm-header-bg) 0%, #2c2c2c 100%);
            color: var(--dm-text);
            border-left: 4px solid var(--accent-orange);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        .movie-title {
            font-weight: 700;
            margin-bottom: 0.25rem;
            position: relative;
            display: inline-block;
            transition: color 0.3s ease;
            font-size: 1.5rem;
        }
        body:not(.dark-mode) .movie-title { color: var(--text-color) !important; }
        body.dark-mode .movie-title { color: var(--dm-movie-title) !important; }
        .movie-title small { font-size: 0.8em; }
        body:not(.dark-mode) .movie-title small { color: var(--accent-peach) !important; }
        body.dark-mode .movie-title small { color: var(--dm-movie-subtitle) !important; }
        .movie-title:after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 40px;
            height: 2px;
            background: var(--accent-orange);
            transition: background 0.3s ease;
        }
        .movie-meta { font-size: 0.85rem; }
        body:not(.dark-mode) .movie-meta { color: var(--accent-peach) !important; }
        body.dark-mode .movie-meta { color: var(--dm-movie-meta) !important; }
        body.dark-mode .badge.bg-dark { background-color: var(--dm-badge-bg) !important; color: white !important; }
        body.dark-mode .badge.bg-danger { background-color: var(--red) !important; color: white !important; }
        body.dark-mode .badge.bg-warning { background-color: var(--yellow) !important; color: var(--text-color) !important; }

        /* Date Navigation */
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
            flex-grow: 1; /* Make cells take equal width */
            flex-basis: 0; /* Important for flex-grow */
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

        /* --- Showtimes Filters & Display (COMPACT UI & Refined Theming) --- */
        .filter-container {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            border: 1px solid #333;
            background-color: var(--dark-black); /* Default for dark mode */
        }
        body:not(.dark-mode) .filter-container {
            background-color: var(--primary-bg); /* Use primary-bg for light mode */
            border-color: var(--lm-border);
            box-shadow: 0 4px 6px var(--lm-shadow);
        }
        .filter-section { margin-bottom: 10px; } /* Slightly more compact */
        .filter-section:last-child { margin-bottom: 0; }
        .filter-title {
            font-size: 15px; /* Slightly smaller */
            font-weight: 600;
            margin-bottom: 8px; /* Slightly more compact */
            display: flex;
            align-items: center;
            gap: 6px; /* Slightly more compact */
            color: var(--peach); /* Default for dark mode */
        }
        body:not(.dark-mode) .filter-title { color: var(--orange); }
        .filter-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px; /* More compact */
        }
        .filter-tag {
            padding: 4px 10px; /* More compact filter tags */
            border-radius: 16px; /* More circular */
            font-size: 13px; /* Slightly smaller font */
            cursor: pointer;
            transition: all 0.2s;
            background-color: var(--black); /* Default for dark mode */
            border: 1px solid #444;
            color: white;
            display: flex;
            align-items: center;
            gap: 4px; /* More compact */
        }
        body:not(.dark-mode) .filter-tag {
            background-color: var(--secondary-bg); /* Use secondary-bg for light mode */
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
        .filter-tag i { font-size: 11px; } /* Smaller icon */
        .reset-filters {
            font-size: 13px; /* Slightly smaller */
            color: var(--peach); /* Default for dark mode */
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
            display: none; /* Hide by default */
            width: 7px; /* Smaller indicator */
            height: 7px; /* Smaller indicator */
            border-radius: 50%;
            margin-left: 3px; /* More compact */
            background-color: var(--orange);
        }
        .filter-tag.active .filter-indicator { display: inline-block; background-color: white; } /* Show when active */
        body:not(.dark-mode) .filter-tag.active .filter-indicator { background-color: white; }

        .venues-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .venue-row {
            background: linear-gradient(145deg, var(--dark-black), #2a2a2a); /* Default for dark mode */
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            border: 1px solid #333;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            overflow: hidden;
        }
        body:not(.dark-mode) .venue-row {
            background: white;
            border-color: var(--lm-border);
            box-shadow: 0 4px 12px var(--lm-shadow);
        }
        .venue-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px; /* Slightly more compact */
        }
        .venue-name {
            font-size: 17px; /* Slightly smaller */
            font-weight: 700;
            color: var(--peach); /* Default for dark mode */
        }
        body:not(.dark-mode) .venue-name { color: var(--orange); }
        .venue-name a { color: inherit; text-decoration: none; }
        .venue-name:hover { color: var(--orange); }
        .venue-subname {
            font-size: 13px; /* Slightly smaller */
            color: #aaa; /* Default for dark mode */
            margin-top: 3px; /* More compact */
        }
        body:not(.dark-mode) .venue-subname { color: var(--lm-text-muted); }
        .price-range {
            font-size: 12px; /* Slightly smaller */
            color: #ccc; /* Default for dark mode */
            background: rgba(255,255,255,0.1);
            padding: 2px 7px; /* More compact */
            border-radius: 4px;
        }
        body:not(.dark-mode) .price-range { background: rgba(0,0,0,0.05); color: var(--text-color); }

        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); /* Min width reduced */
            gap: 8px; /* More compact */
        }
        .time-slot {
            padding: 8px; /* More compact */
            background: var(--black); /* Default for dark mode */
            border-radius: 6px;
            font-size: 12px; /* Smaller base font size */
            font-weight: 500;
            border: 1px solid #444;
            transition: all 0.2s ease;
            line-height: 1.1; /* Tighter line height */
            cursor: pointer;
            position: relative;
            text-decoration: none;
            display: block;
            color: inherit;
            height: 100%; /* Ensure consistent height within flexbox */
            box-sizing: border-box;
            min-height: 80px; /* Minimum height to prevent content collapse */
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
            margin-bottom: 5px; /* More compact */
        }
        .slot-time { font-weight: bold; }
        .slot-availability {
            font-size: 10px; /* Smaller */
            background: rgba(255,255,255,0.2);
            padding: 2px 6px;
            border-radius: 3px;
        }
        body:not(.dark-mode) .slot-availability { background: rgba(0,0,0,0.05); }
        .slot-availability.few-left { color: var(--orange); }
        .slot-availability.sold-out { color: var(--red); }
        .slot-availability.available { color: #4CAF50; }
        .ticket-info { display: flex; flex-direction: column; gap: 3px; /* More compact */ }
        .ticket-type {
            display: flex;
            justify-content: space-between;
            font-size: 11px; /* Smaller */
            padding: 3px 6px; /* More compact */
            background: rgba(230, 57, 70, 0.15); /* Primary red tint */
            border-radius: 3px;
            border: 1px solid rgba(230, 57, 70, 0.3);
        }
        body:not(.dark-mode) .ticket-type {
            background: rgba(230, 57, 70, 0.05);
            border-color: rgba(230, 57, 70, 0.1);
        }
        .ticket-name { flex: 1; }
        .ticket-price { font-weight: bold; color: var(--yellow); margin-left: 5px; }
        .ticket-availability { font-size: 10px; /* Smaller */ margin-left: 5px; font-weight: 500; }
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
        /* New: for small no showtimes messages within venues */
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

        .no-shows::before { content: ""; font-size: 28px; display: block; margin-bottom: 10px; } /* Removed default sad face, using font-awesome icon */
        .hidden { display: none !important; }

        /* Animation for loading showtimes */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .showtime-slot {
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

        /* --- Responsive Styles (No major changes here, copied from original) --- */
        @media (max-width: 992px) {
            body { padding-top: 140px; } /* Adjust for smaller screens */
            .second-navbar { display: none !important; } /* Hide second navbar on mobile */
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
            .top-navbar .container { /* Changed from .container to .container */
                flex-wrap: wrap; /* Allow container content to wrap */
                padding-left: 10px; /* Add some padding to prevent content hugging edges */
                padding-right: 10px;
            }
            .brand-section {
                flex-grow: 1; /* Allow brand section to take available space */
            }
            .right-section {
                flex-shrink: 0; /* Prevent shrinking too much */
            }
            .top-navbar{
                height: 120px;
            }
        }

        @media (max-width: 768px) {
            .venue-container { grid-template-columns: 1fr; }
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
            .movie-header { padding: 1rem; }
            .movie-title { font-size: 1.3rem; }
            .section-title { font-size: 1.1rem; }
            /* .showtime-slot { height: 130px; } Removed fixed height, using min-height now */
            .filter-tags { gap: 6px; }
            .filter-tag { padding: 5px 10px; font-size: 13px; }

             .top-navbar{
                height: 160px;
            }

        }

        @media (max-width: 576px) {
            body { padding-top: 140px; }
            .container { padding: 0.75rem; }
            .movie-header { padding: 1rem 0.75rem; }
            .movie-title { font-size: 1.2rem; }
            .date-cell { min-width: 60px; padding: 0.4rem 0.2rem; }
            .date-day { font-size: 0.7rem; }
            .date-date { font-size: 0.9rem; }
            .section-title { font-size: 1rem; }
            /* .showtime-slot { height: 120px; padding: 0.6rem; } Removed fixed height, using min-height now */
            .time-slot { font-size: 0.85rem; } /* Even smaller for mobile */
            .ticket-type { font-size: 0.9rem; } /* Even smaller for mobile */
            .ticket-price { font-size: 0.9rem; } /* Even smaller for mobile */
            .ticket-availability { font-size: 0.8rem; } /* Even smaller for mobile */
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
                <!-- Optionally show some public content here -->
            </div>
        <?php elseif ($displayEventInfo): // Content from file2.php ?>
            <div class="movie-header">
                <h2 class="movie-title"><?= htmlspecialchars($event['event_name']) ?> 
                    <small>(<?= htmlspecialchars($event['event_language']) ?>)</small>
                </h2>
                <p class="movie-meta mb-0">
                    <span class="badge bg-dark me-1"><?= htmlspecialchars($event['event_type']) ?></span>
                    <span class="badge bg-danger me-1"><?= htmlspecialchars($event['genre']) ?></span>
                    <span class="badge bg-warning text-dark"><?= htmlspecialchars($event['event_duration']) ?></span>
                </p>
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
                        <div class="col date-cell <?= $isSelected ? 'selected-date' : '' ?> <?= $isToday ? 'today-date' : '' ?>" 
                            data-date="<?= $dateParam ?>">
                            <div class="date-day"><?= $dayName ?></div>
                            <div class="date-date"><?= $dateNum ?> <?= $monthName ?></div>
                            <?= $isToday ? '<div class="today-badge">Today</div>' : '' ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Showtimes Container (loaded via AJAX) -->
            <h5 class="section-title">Available Showtimes</h5>
            <div id="showtimesContainer">
                <div class="d-flex justify-content-center my-5 py-5">
                    <div class="spinner-border loading-spinner" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        <?php elseif (!empty($_SESSION['selected_city'])): // Homepage with Now Showing (from file1.php) ?>
            <?php if (isset($_POST['city_submitted'])): // Only show alert on fresh page loads after POST ?>
                <div class="alert alert-info mb-4" id="cityAlert">
                    Showing content for: <strong><?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?></strong>
                </div> 
            <?php endif; ?>

            <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                <!-- Search Results Section -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <h3 class="mb-4">Search Results for "<?= htmlspecialchars($_GET['search']) ?>"</h3>
                        
                        <?php if (!empty($nowShowing)): // Re-using nowShowing which would be populated by search on home page ?>
                            <div class="row">
                                <?php foreach ($nowShowing as $event): ?>
                                    <div class="col-6 col-md-4 col-lg-3 mb-4">
                                        <div class="card event-card">
                                            <div class="position-relative">
                                                <img src="<?= !empty($event['event_image']) ? htmlspecialchars($event['event_image']) : 'images/placeholder.jpg' ?>" 
                                                    class="card-img-top event-card-img" alt="<?= htmlspecialchars($event['event_name']) ?>">
                                                <span class="event-type-badge"><?= htmlspecialchars($event['event_type']) ?></span>
                                            </div>
                                            <div class="card-body event-card-body">
                                                <h5 class="card-title event-card-title"><?= htmlspecialchars($event['event_name']) ?></h5>
                                                <a href="<?= $event_info_page ?>?event_id=<?= $event['event_id'] ?>" class="btn btn-sm btn-danger mt-2">View Details</a>
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
                <!-- Now Showing / Home Page Content -->
                <h3 class="mt-4 mb-4">Now Showing in <?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?></h3>
                <?php if (!empty($nowShowing)): ?>
                    <div class="row">
                        <?php foreach ($nowShowing as $event): ?>
                            <div class="col-6 col-md-4 col-lg-3 mb-4">
                                <div class="card event-card">
                                    <div class="position-relative">
                                        <img src="<?= !empty($event['event_image']) ? htmlspecialchars($event['event_image']) : 'images/placeholder.jpg' ?>" 
                                            class="card-img-top event-card-img" alt="<?= htmlspecialchars($event['event_name']) ?>">
                                        <span class="event-type-badge"><?= htmlspecialchars($event['event_type']) ?></span>
                                    </div>
                                    <div class="card-body event-card-body">
                                        <h5 class="card-title event-card-title"><?= htmlspecialchars($event['event_name']) ?></h5>
                                        <a href="<?= $event_info_page ?>?event_id=<?= $event['event_id'] ?>" class="btn btn-sm btn-danger mt-2">Book Now</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        No events found for <?= htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) ?>. Please select another city or check back later.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <h2>Welcome to Catchify </h2>
                <p class="lead">Please login to experience all features. Once logged in, select a city to see events.</p>
            </div>
        <?php endif; ?>
    </div>

    <footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Catchify. All rights reserved.</p>
        </div>
    </footer>

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

            // --- Bootstrap Dropdowns Initialization ---
            document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                new bootstrap.Dropdown(toggle);
            });

            // --- City Search and Selection (NEW: URL Preservation) ---
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
            
            // City selection (auto-submit form) - Modified for URL Preservation
            cityItems.forEach(item => {
                item.addEventListener('click', function() {
                    const cityId = this.getAttribute('data-value');
                    document.getElementById('selectedCity').value = cityId;
                    
                    const cityForm = document.getElementById('cityForm');
                    // Add a hidden input to signify that city was selected by POST
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'city_submitted';
                    hiddenInput.value = '1';
                    cityForm.appendChild(hiddenInput);

                    // --- NEW: Preserve event_id and date if they exist in the current URL ---
                    const currentUrlParams = new URL(window.location.href).searchParams;
                    const eventId = currentUrlParams.get('event_id');
                    const date = currentUrlParams.get('date');

                    if (eventId) {
                        const eventIdInput = document.createElement('input');
                        eventIdInput.type = 'hidden';
                        eventIdInput.name = 'event_id';
                        eventIdInput.value = eventId;
                        cityForm.appendChild(eventIdInput);
                    }
                    if (date) {
                        const dateInput = document.createElement('input');
                        dateInput.type = 'hidden';
                        dateInput.name = 'date';
                        dateInput.value = date;
                        cityForm.appendChild(dateInput);
                    }
                    // --- END NEW ---

                    cityForm.submit();
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
                fetch(`event_showtimes.php?ajax=search&search=${encodeURIComponent(searchTerm)}`)
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
            
            // --- Showtime Loading & Filtering (for event_id page) ---
            const showtimesContainer = document.getElementById('showtimesContainer');
            const urlParams = new URL(window.location.href).searchParams;
            const initialEventId = urlParams.get('event_id');

            if (showtimesContainer && initialEventId) { // Only run if on an event details page
                // Function to load showtimes via AJAX
                window.loadShowtimes = function(dateStr) {
                    // Update URL without reloading the full page
                    const url = new URL(window.location);
                    url.searchParams.set('date', dateStr);
                    window.history.pushState({}, '', url);

                    // Show loading spinner
                    showtimesContainer.innerHTML = `
                        <div class="d-flex justify-content-center my-5 py-5">
                            <div class="spinner-border loading-spinner" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    `;
                    
                    // Update highlighted date cell
                    document.querySelectorAll('.date-cell').forEach(cell => {
                        cell.classList.remove('selected-date');
                        const cellDate = cell.dataset.date;
                        if (cellDate === dateStr) {
                            cell.classList.add('selected-date');
                            // Add pulse animation for visual feedback
                            cell.style.animation = 'pulse 0.5s ease';
                            setTimeout(() => { cell.style.animation = ''; }, 500);
                        }
                    });

                    // Fetch showtimes HTML from our AJAX endpoint
                    fetch(`event_showtimes.php?ajax=showtimes&event_id=${initialEventId}&date=${dateStr}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok: ' + response.statusText);
                            }
                            return response.text();
                        })
                        .then(html => {
                            showtimesContainer.innerHTML = html;
                            attachFilterEventListeners(); // NEW: Attach delegated listeners for new content
                            loadActiveFiltersFromURL(); // Re-apply filters from URL to the new content
                            attachShowtimeEventListeners(); // Re-attach listeners for time-slot links (optional visual feedback)
                        })
                        .catch(error => {
                            console.error('Error loading showtimes:', error);
                            showtimesContainer.innerHTML = `
                                <div class="no-shows">
                                    <h5 class="no-shows-title"><i class="fas fa-exclamation-triangle"></i> Error Loading Showtimes</h5>
                                    <p>Failed to load showtimes for this date. Please try again later.</p>
                                    <small class="text-muted">${error.message}</small>
                                </div>
                            `;
                        });
                }; // End of window.loadShowtimes function

                // Attach event listeners to date cells AFTER loadShowtimes is defined
                const dateCells = document.querySelectorAll('.date-cell');
                dateCells.forEach(cell => {
                    cell.addEventListener('click', function() {
                        const dateParam = this.dataset.date;
                        window.loadShowtimes(dateParam);
                    });
                });

                // Get the initial date from URL or default to today and trigger the first load
                const initialDateParam = urlParams.get('date') || new Date().toISOString().split('T')[0];
                window.loadShowtimes(initialDateParam);


                // Attach event listeners to newly loaded showtime elements (e.g., book buttons)
                function attachShowtimeEventListeners() {
                    document.querySelectorAll('.time-slot').forEach(slotLink => {
                        slotLink.addEventListener('click', function(e) {
                            if (slotLink.href) {
                                // Optional visual feedback before navigating
                                slotLink.style.opacity = '0.7';
                                slotLink.style.transition = 'opacity 0.2s';
                                // Let default link navigation happen
                            }
                        });
                    });
                }

                // Showtime Filter Logic (NEW: Using event delegation for robustness)
                let currentActivePriceFilters = []; // Persist across date changes
                let currentActiveTimeFilters = []; // Persist across date changes

                // NEW function using event delegation
                function attachFilterEventListeners() {
                    const priceFiltersDiv = document.getElementById('priceFilters');
                    const timeFiltersDiv = document.getElementById('timeFilters');
                    const resetFiltersBtn = document.getElementById('resetFilters');

                    // Helper to update filter state
                    function updateFilterState(tag, value, filtersArray) {
                        const index = filtersArray.indexOf(value);
                        if (index > -1) {
                            filtersArray.splice(index, 1);
                            tag.classList.remove('active');
                            if (tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.remove('active');
                        } else {
                            filtersArray.push(value);
                            tag.classList.add('active');
                            if (tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.add('active');
                        }
                    }

                    // Attach delegated listener for Price Filters (if not already attached)
                    if (priceFiltersDiv && !priceFiltersDiv.dataset.hasClickListener) {
                        priceFiltersDiv.addEventListener('click', function(e) {
                            const target = e.target.closest('.filter-tag');
                            if (target && target.dataset.filter === 'price') {
                                updateFilterState(target, target.dataset.value, currentActivePriceFilters);
                                applyShowtimeFilters();
                                updateShowtimeUrl();
                            }
                        });
                        priceFiltersDiv.dataset.hasClickListener = true; // Mark as having listener
                    }

                    // Attach delegated listener for Time Filters (if not already attached)
                    if (timeFiltersDiv && !timeFiltersDiv.dataset.hasClickListener) {
                        timeFiltersDiv.addEventListener('click', function(e) {
                            const target = e.target.closest('.filter-tag');
                            if (target && target.dataset.filter === 'time') {
                                updateFilterState(target, target.dataset.value, currentActiveTimeFilters);
                                applyShowtimeFilters();
                                updateShowtimeUrl();
                            }
                        });
                        timeFiltersDiv.dataset.hasClickListener = true; // Mark as having listener
                    }

                    // Reset Filters button (if not already attached)
                    if (resetFiltersBtn && !resetFiltersBtn.dataset.hasClickListener) {
                        resetFiltersBtn.addEventListener('click', function() {
                            currentActivePriceFilters = [];
                            currentActiveTimeFilters = [];

                            // Deactivate all filter tags visually
                            document.querySelectorAll('#priceFilters .filter-tag').forEach(tag => {
                                tag.classList.remove('active');
                                if(tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.remove('active');
                            });
                            document.querySelectorAll('#timeFilters .filter-tag').forEach(tag => {
                                tag.classList.remove('active');
                                if(tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.remove('active');
                            });
                            
                            applyShowtimeFilters();
                            updateShowtimeUrl(true); // Signal reset to URL update
                        });
                        resetFiltersBtn.dataset.hasClickListener = true; // Mark as having listener
                    }
                } // End of attachFilterEventListeners

                // This function loads active filters from URL params and applies them
                function loadActiveFiltersFromURL() {
                    const urlSearchParams = new URLSearchParams(window.location.search);
                    const priceParam = urlSearchParams.get('price');
                    const timeParam = urlSearchParams.get('time');

                    // Reset filters before applying from URL
                    currentActivePriceFilters = [];
                    currentActiveTimeFilters = [];

                    // Clear active classes from all tags first (in case DOM recreated without full page reload)
                    document.querySelectorAll('.filter-tag').forEach(tag => {
                        tag.classList.remove('active');
                        if (tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.remove('active');
                    });

                    // Apply price filters from URL
                    if (priceParam) {
                        priceParam.split(',').forEach(filterValue => {
                            const tag = document.querySelector(`#priceFilters .filter-tag[data-value="${filterValue}"]`);
                            if (tag) {
                                tag.classList.add('active');
                                if (tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.add('active');
                                currentActivePriceFilters.push(filterValue);
                            }
                        });
                    }

                    // Apply time filters from URL
                    if (timeParam) {
                        timeParam.split(',').forEach(filterValue => {
                            const tag = document.querySelector(`#timeFilters .filter-tag[data-value="${filterValue}"]`);
                            if (tag) {
                                tag.classList.add('active');
                                if (tag.querySelector('.filter-indicator')) tag.querySelector('.filter-indicator').classList.add('active');
                                currentActiveTimeFilters.push(filterValue);
                            }
                        });
                    }
                    applyShowtimeFilters(); // Apply loaded filters
                }


                function applyShowtimeFilters() {
                    const venueRows = document.querySelectorAll('#venuesContainer .venue-row');
                    const noShowsMessage = document.getElementById('noShowsMessage');
                    let anyVenueVisible = false;

                    venueRows.forEach(venueRow => {
                        let venueHasVisibleTimeSlots = false;
                        const timeSlots = venueRow.querySelectorAll('.time-slot');
                        
                        timeSlots.forEach(slot => {
                            // Reset visibility for this slot before applying filters
                            slot.classList.remove('hidden'); 
                            const timeCategory = slot.dataset.timeCategory;
                            const minPrice = parseInt(slot.dataset.priceMin);

                            // Check time category filter
                            const timeMatch = currentActiveTimeFilters.length === 0 || 
                                    currentActiveTimeFilters.includes(timeCategory);

                            // Check price range filter
                            let priceMatch = currentActivePriceFilters.length === 0;
                            if (!priceMatch) {
                                for (const filter of currentActivePriceFilters) {
                                    if ((filter === 'under-200' && minPrice < 200) ||
                                        (filter === '200-500' && minPrice >= 200 && minPrice < 500) ||
                                        (filter === '500-1000' && minPrice >= 500 && minPrice < 1000) ||
                                        (filter === 'over-1000' && minPrice >= 1000)) {
                                        priceMatch = true;
                                        break;
                                    }
                                }
                            }

                            if (timeMatch && priceMatch) {
                                slot.classList.remove('hidden');
                                venueHasVisibleTimeSlots = true;
                            } else {
                                slot.classList.add('hidden');
                            }
                        });

                        // Hide/show the venue row based on its time slots
                        if (venueHasVisibleTimeSlots) {
                            venueRow.classList.remove('hidden');
                            anyVenueVisible = true;
                        } else {
                            venueRow.classList.add('hidden');
                        }
                    });

                    // Show/hide general "no shows" message based on current filters
                    if (noShowsMessage) {
                        if (anyVenueVisible) {
                            noShowsMessage.classList.add('hidden');
                        } else {
                            noShowsMessage.classList.remove('hidden');
                        }
                    }
                }

                // Update URL parameters without reloading for showtimes
                function updateShowtimeUrl(reset = false) {
                    const url = new URL(window.location);
                    
                    if (reset) {
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
                    window.history.pushState({}, '', url);
                }

                // Handle browser back/forward buttons for showtimes
                window.addEventListener('popstate', function() {
                    const urlSearchParams = new URLSearchParams(window.location.search);
                    const dateParam = urlSearchParams.get('date');
                    if (dateParam) {
                        loadShowtimes(dateParam);
                    }
                    // Load filters on popstate as well
                    loadActiveFiltersFromURL();
                });

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
            } // End of if (showtimesContainer)
        }); // End DOMContentLoaded
    </script>
</body>
</html>

