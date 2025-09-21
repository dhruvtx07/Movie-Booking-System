<?php
session_start();
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

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=event_mg', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die('<div class="alert alert-danger">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

// Handle city selection
if (isset($_POST['city'])) {
    $_SESSION['selected_city'] = (int)$_POST['city'];
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Set default city if not selected
$current_date = date('Y-m-d');
$current_time = date('H:i:s');
$city_id = isset($_SESSION['selected_city']) ? (int)$_SESSION['selected_city'] : 2;

// Fetch cities for dropdown
try {
    $cityStmt = $db->query("SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name");
    $cities = $cityStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $cities = [
        ['city_id' => 1, 'city_name' => 'Mumbai'],
        ['city_id' => 2, 'city_name' => 'Delhi'],
        ['city_id' => 3, 'city_name' => 'Bangalore'],
        ['city_id' => 4, 'city_name' => 'Hyderabad'],
        ['city_id' => 5, 'city_name' => 'Chennai'],
        ['city_id' => 6, 'city_name' => 'Kolkata'],
        ['city_id' => 7, 'city_name' => 'Pune']
    ];
}

function getCityNameById($cities, $id) {
    foreach ($cities as $city) {
        if ($city['city_id'] == $id) {
            return $city['city_name'];
        }
    }
    return 'Unknown City';
}

// Get and validate parameters
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT) ?: 1;
$date = filter_input(INPUT_GET, 'date') ?: date('Y-m-d');

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
    $stmt = $db->prepare($query);
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
        if ($date != $current_date || $venue['end_time'] > $current_time) {
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
            
            $ticketStmt = $db->prepare($ticketQuery);
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Showtimes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    :root {
        --black: #1a1a1a;
        --dark-black: #0d0d0d;
        --red: #e63946;
        --orange: #f77f00;
        --yellow: #fcbf49;
        --peach: #ffcdb2;
        --light-peach: #fff5f0;
        
        /* Light mode variables */
        --lm-bg: #f8f9fa;
        --lm-card-bg: #ffffff;
        --lm-text: #212529;
        --lm-text-muted: #6c757d;
        --lm-border: #dee2e6;
        --lm-shadow: rgba(0, 0, 0, 0.1);
        --lm-header-bg: #343a40;
        --lm-badge-bg: #6c757d;
        --lm-movie-title: #ffffff;
        --lm-movie-subtitle: #e9ecef;
        --lm-movie-meta: #fcbf49;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--black);
        color: #f0f0f0;
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    
    /* Light mode styles */
    body.light-mode {
        background-color: var(--lm-bg);
        color: var(--lm-text);
    }
    
    .light-mode .venue-row {
        background: white;
        border-color: var(--lm-border);
        box-shadow: 0 4px 12px var(--lm-shadow);
    }
    
    .light-mode .venue-name {
        color: var(--orange);
    }
    
    .light-mode .venue-subname {
        color: var(--lm-text-muted);
    }
    
    .light-mode .time-slot {
        background: var(--lm-bg);
        border-color: var(--lm-border);
    }
    
    .light-mode .time-slot:hover {
        background: var(--red);
        color: white;
    }
    
    .light-mode .ticket-type {
        background: rgba(230, 57, 70, 0.1);
        border-color: rgba(230, 57, 70, 0.2);
    }
    
    .light-mode .no-shows {
        background: white;
        border-color: var(--lm-border);
    }
    
    .light-mode .price-range {
        color: var(--lm-text-muted);
    }
    
    .light-mode .filter-container {
        background-color: white;
        border-color: var(--lm-border);
    }
    
    .light-mode .filter-tag {
        background-color: var(--lm-bg);
        border-color: var(--lm-border);
        color: var(--lm-text);
    }
    
    .light-mode .filter-tag.active {
        background-color: var(--orange);
        color: white;
    }
    
    .light-mode .reset-filters {
        color: var(--orange);
    }
    
    .filter-container {
        background-color: var(--dark-black);
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        border: 1px solid #333;
    }
    
    .filter-section {
        margin-bottom: 15px;
    }
    
    .filter-section:last-child {
        margin-bottom: 0;
    }
    
    .filter-title {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 10px;
        color: var(--peach);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .filter-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .filter-tag {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        background-color: var(--black);
        border: 1px solid #444;
        color: white;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .filter-tag:hover {
        background-color: var(--orange);
        color: var(--dark-black);
    }
    
    .filter-tag.active {
        background-color: var(--orange);
        color: var(--dark-black);
        font-weight: 600;
    }
    
    .filter-tag i {
        font-size: 12px;
    }
    
    .reset-filters {
        font-size: 14px;
        color: var(--peach);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 10px;
        transition: all 0.2s;
    }
    
    .reset-filters:hover {
        color: var(--orange);
    }
    
    .venues-container {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    @media (max-width: 768px) {
        .venues-container {
            gap: 10px;
        }
        
        .filter-tags {
            gap: 6px;
        }
        
        .filter-tag {
            padding: 5px 10px;
            font-size: 13px;
        }
    }
    
    .venue-row {
        background: linear-gradient(145deg, var(--dark-black), #2a2a2a);
        border-radius: 8px;
        padding: 15px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        border: 1px solid #333;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        position: relative;
        overflow: hidden;
    }
    
    .venue-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .venue-name {
        font-size: 18px;
        font-weight: 700;
        color: var(--peach);
    }
    
    .venue-name a {
        color: inherit;
        text-decoration: none;
    }
    
    .venue-name:hover {
        color: var(--orange);
    }
    
    .venue-subname {
        font-size: 14px;
        color: #aaa;
        margin-top: 4px;
    }
    
    .venue-capacity {
        font-size: 13px;
        color: #ccc;
        background: rgba(255,255,255,0.1);
        padding: 3px 8px;
        border-radius: 4px;
    }
    
    .price-range {
        font-size: 13px;
        color: #ccc;
        background: rgba(255,255,255,0.1);
        padding: 3px 8px;
        border-radius: 4px;
    }
    
    .time-slots-container {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 10px;
    }
    
    .time-slot {
        padding: 10px;
        background: var(--black);
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        border: 1px solid #444;
        transition: all 0.2s ease;
        line-height: 1.2;
        cursor: pointer;
        position: relative;
        text-decoration: none;
        display: block;
        color: inherit;
        height: 100%;
        box-sizing: border-box;
    }
    
    .time-slot:hover {
        background: var(--red);
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        border-color: var(--red);
    }
    
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
        margin-bottom: 8px;
    }
    
    .slot-time {
        font-weight: bold;
    }
    
    .slot-availability {
        font-size: 12px;
        background: rgba(255,255,255,0.2);
        padding: 2px 6px;
        border-radius: 3px;
    }
    
    .slot-availability.few-left {
        color: var(--orange);
    }
    
    .slot-availability.sold-out {
        color: var(--red);
    }
    
    .slot-availability.available {
        color: #4CAF50;
    }
    
    .ticket-info {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .ticket-type {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        padding: 4px 6px;
        background: rgba(230, 57, 70, 0.15);
        border-radius: 3px;
        border: 1px solid rgba(230, 57, 70, 0.3);
    }
    
    .ticket-name {
        flex: 1;
    }
    
    .ticket-price {
        font-weight: bold;
        color: var(--yellow);
        margin-left: 5px;
    }
    
    .ticket-availability {
        font-size: 11px;
        margin-left: 5px;
        font-weight: 500;
    }
    
    .ticket-availability.few-left {
        color: var(--orange);
        font-weight: 600;
    }
    
    .ticket-availability.sold-out {
        color: var(--red);
        font-weight: 600;
    }
    
    .ticket-availability.available {
        color: #4CAF50;
    }
    
    .no-shows {
        text-align: center;
        padding: 30px;
        background: var(--dark-black);
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        border: 1px dashed #444;
        font-size: 14px;
    }
    
    .no-shows::before {
        content: "🎬";
        font-size: 28px;
        display: block;
        margin-bottom: 10px;
    }
    
    /* Hidden class for filtering */
    .hidden {
        display: none !important;
    }
    
    /* Filter indicators */
    .filter-indicator {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-left: 5px;
        background-color: var(--orange);
    }
    
    .filter-indicator.active {
        background-color: var(--red);
    }
</style>
</head>
<body class="dark-mode">

    <!-- Filters Section -->
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
                            <a href="<?=$venue_wise_showtimes?>?event_id=<?= $eventId ?>&venue_id=<?= $venue['venue_id'] ?>&city_id=<?= $city_id ?>">
                                <?= htmlspecialchars($venue['venue_name']) ?>
                            </a>
                        </div>
                        <?php if (!empty($venue['sub_venue_name'])): ?>
                            <div class="venue-subname"><?= htmlspecialchars($venue['sub_venue_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="price-range">
                        ₹<?= htmlspecialchars($venue['min_price']) ?> - ₹<?= htmlspecialchars($venue['max_price']) ?>
                    </div>
                </div>
                
                <div class="time-slots-container">
                    <?php foreach ($venue['time_slots'] as $slot): 
                        $availabilityStatus = '';
                        if ($slot['available_seats'] <= 0) {
                            $availabilityStatus = 'sold-out';
                        } elseif ($slot['available_seats'] < 5) {
                            $availabilityStatus = 'few-left';
                        } else {
                            $availabilityStatus = 'available';
                        }
                    ?>
                        <a href="<?=$ticket_map?>?event_id=<?= $eventId ?>&venue_id=<?= $venue['venue_id'] ?>&schedule_id=<?= $slot['schedule_id'] ?>" 
                           class="time-slot <?= $slot['is_late'] ? 'late' : '' ?>"
                           data-time-category="<?= $slot['time_category'] ?>"
                           data-price-min="<?= $slot['tickets'][0]['ticket_price'] ?>">
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
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($groupedVenues)): ?>
            <div class="no-shows">
                No upcoming showtimes available in <?= htmlspecialchars(getCityNameById($cities, $city_id)) ?>.
            </div>
        <?php else: ?>
            <div class="no-shows hidden" id="noShowsMessage">
                No upcoming showtimes available for the selected filters in <?= htmlspecialchars(getCityNameById($cities, $city_id)) ?>.
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const priceFilters = document.querySelectorAll('#priceFilters .filter-tag');
            const timeFilters = document.querySelectorAll('#timeFilters .filter-tag');
            const resetFiltersBtn = document.getElementById('resetFilters');
            const venuesContainer = document.getElementById('venuesContainer');
            const venueRows = document.querySelectorAll('.venue-row');
            const noShowsMessage = document.getElementById('noShowsMessage');
            
            let activePriceFilters = [];
            let activeTimeFilters = [];
            
            // Initialize filters from URL if present
            const urlParams = new URLSearchParams(window.location.search);
            const priceParam = urlParams.get('price');
            const timeParam = urlParams.get('time');
            
            if (priceParam) {
                activePriceFilters = priceParam.split(',');
                activePriceFilters.forEach(filter => {
                    const filterTag = document.querySelector(`#priceFilters .filter-tag[data-value="${filter}"]`);
                    if (filterTag) {
                        filterTag.classList.add('active');
                        filterTag.querySelector('.filter-indicator').classList.add('active');
                    }
                });
            }
            
            if (timeParam) {
                activeTimeFilters = timeParam.split(',');
                activeTimeFilters.forEach(filter => {
                    const filterTag = document.querySelector(`#timeFilters .filter-tag[data-value="${filter}"]`);
                    if (filterTag) {
                        filterTag.classList.add('active');
                        filterTag.querySelector('.filter-indicator').classList.add('active');
                    }
                });
            }
            
            // Apply initial filters
            applyFilters();
            
            // Price filter click handler
            priceFilters.forEach(filter => {
                filter.addEventListener('click', function() {
                    const value = this.getAttribute('data-value');
                    const index = activePriceFilters.indexOf(value);
                    
                    if (index > -1) {
                        activePriceFilters.splice(index, 1);
                        this.classList.remove('active');
                        this.querySelector('.filter-indicator').classList.remove('active');
                    } else {
                        activePriceFilters.push(value);
                        this.classList.add('active');
                        this.querySelector('.filter-indicator').classList.add('active');
                    }
                    
                    applyFilters();
                    updateUrl();
                });
            });
            
            // Time filter click handler
            timeFilters.forEach(filter => {
                filter.addEventListener('click', function() {
                    const value = this.getAttribute('data-value');
                    const index = activeTimeFilters.indexOf(value);
                    
                    if (index > -1) {
                        activeTimeFilters.splice(index, 1);
                        this.classList.remove('active');
                        this.querySelector('.filter-indicator').classList.remove('active');
                    } else {
                        activeTimeFilters.push(value);
                        this.classList.add('active');
                        this.querySelector('.filter-indicator').classList.add('active');
                    }
                    
                    applyFilters();
                    updateUrl();
                });
            });
            
            // Reset filters button
            resetFiltersBtn.addEventListener('click', function() {
                activePriceFilters = [];
                activeTimeFilters = [];
                
                priceFilters.forEach(filter => {
                    filter.classList.remove('active');
                    filter.querySelector('.filter-indicator').classList.remove('active');
                });
                
                timeFilters.forEach(filter => {
                    filter.classList.remove('active');
                    filter.querySelector('.filter-indicator').classList.remove('active');
                });
                
                applyFilters();
                updateUrl(true);
            });
            
            // Apply filters function
            function applyFilters() {
                let visibleVenues = 0;
                
                venueRows.forEach(venue => {
                    const venuePriceRanges = venue.getAttribute('data-price-ranges').split(',');
                    const venueTimeCategories = venue.getAttribute('data-time-categories').split(',');
                    
                    // Check price filters
                    let priceMatch = activePriceFilters.length === 0;
                    if (!priceMatch) {
                        for (const filter of activePriceFilters) {
                            if (venuePriceRanges.includes(filter)) {
                                priceMatch = true;
                                break;
                            }
                        }
                    }
                    
                    // Check time filters
                    let timeMatch = activeTimeFilters.length === 0;
                    if (!timeMatch) {
                        for (const filter of activeTimeFilters) {
                            if (venueTimeCategories.includes(filter)) {
                                timeMatch = true;
                                break;
                            }
                        }
                    }
                    
                    // Show/hide venue based on filters
                    if (priceMatch && timeMatch) {
                        venue.classList.remove('hidden');
                        visibleVenues++;
                        
                        // Filter time slots within the venue
                        const timeSlots = venue.querySelectorAll('.time-slot');
                        timeSlots.forEach(slot => {
                            const timeCategory = slot.getAttribute('data-time-category');
                            const minPrice = parseInt(slot.getAttribute('data-price-min'));
                            
                            // Check if time slot matches time filters
                            const slotTimeMatch = activeTimeFilters.length === 0 || 
                                                activeTimeFilters.includes(timeCategory);
                            
                            // Check if time slot matches price filters
                            let slotPriceMatch = activePriceFilters.length === 0;
                            if (!slotPriceMatch) {
                                for (const filter of activePriceFilters) {
                                    if ((filter === 'under-200' && minPrice < 200) ||
                                        (filter === '200-500' && minPrice >= 200 && minPrice < 500) ||
                                        (filter === '500-1000' && minPrice >= 500 && minPrice < 1000) ||
                                        (filter === 'over-1000' && minPrice >= 1000)) {
                                        slotPriceMatch = true;
                                        break;
                                    }
                                }
                            }
                            
                            if (slotTimeMatch && slotPriceMatch) {
                                slot.classList.remove('hidden');
                            } else {
                                slot.classList.add('hidden');
                            }
                        });
                    } else {
                        venue.classList.add('hidden');
                    }
                });
                
                // Show no shows message if no venues match filters
                if (visibleVenues === 0) {
                    if (noShowsMessage) noShowsMessage.classList.remove('hidden');
                } else {
                    if (noShowsMessage) noShowsMessage.classList.add('hidden');
                }
            }
            
            // Update URL without reloading
            function updateUrl(reset = false) {
                const url = new URL(window.location);
                
                if (reset) {
                    url.searchParams.delete('price');
                    url.searchParams.delete('time');
                } else {
                    if (activePriceFilters.length > 0) {
                        url.searchParams.set('price', activePriceFilters.join(','));
                    } else {
                        url.searchParams.delete('price');
                    }
                    
                    if (activeTimeFilters.length > 0) {
                        url.searchParams.set('time', activeTimeFilters.join(','));
                    } else {
                        url.searchParams.delete('time');
                    }
                }
                
                window.history.pushState({}, '', url);
            }
            // Add to file3's scripts
// Send height to parent for proper iframe sizing
window.addEventListener('load', function() {
    const height = document.body.scrollHeight;
    window.parent.postMessage({
        height: height
    }, '*');
});

// Also send on resize
window.addEventListener('resize', function() {
    const height = document.body.scrollHeight;
    window.parent.postMessage({
        height: height
    }, '*');
});
        });
    </script>
</body>
</html>
<?php
} catch(PDOException $e) {
    echo '<div class="alert alert-danger">Error loading showtimes: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?> 