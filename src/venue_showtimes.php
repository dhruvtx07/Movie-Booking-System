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

// Add these after your database connection
$priceRanges = [
    'under-200' => 'Under ₹200',
    '200-500' => '₹200-500',
    '500-1000' => '₹500-1000',
    'over-1000' => 'Over ₹1000'
];

$timeCategories = [
    'morning' => 'Morning (6AM-12PM)',
    'afternoon' => 'Afternoon (12PM-5PM)',
    'evening' => 'Evening (5PM-9PM)',
    'night' => 'Night (9PM-12AM)',
    'late-night' => 'Late Night (12AM-6AM)'
];
// Get parameters
$date = filter_input(INPUT_GET, 'date') ?: date('Y-m-d');
$venueId = filter_input(INPUT_GET, 'venue_id', FILTER_VALIDATE_INT);

if (!$venueId) {
    die('<div class="alert alert-danger">Please select a venue first</div>');
}

// Fetch venue details
try {
    $venueStmt = $db->prepare("SELECT * FROM venues WHERE venue_id = ? AND is_active = 'yes'");
    $venueStmt->execute([$venueId]);
    $venue = $venueStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$venue) {
        die('<div class="alert alert-danger">Venue not found</div>');
    }
} catch(PDOException $e) {
    die('<div class="alert alert-danger">Error fetching venue: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

// Fetch all active events
try {
    $eventStmt = $db->query("SELECT * FROM event_info WHERE is_active = 'yes'");
    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die('<div class="alert alert-danger">Error fetching events: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

// Main query to get showtimes for all events at this venue
try {
    $query = "
SELECT 
    e.event_id,
    e.event_name,
    e.event_type,
    e.genre,
    e.event_duration,
    es.event_schedule_id,
    DATE(vs.slot_starts_at) as slot_date,
    TIME_FORMAT(vs.slot_starts_at, '%h:%i %p') as formatted_time,
    TIME(vs.slot_starts_at) as time_value,
    TIME(vs.slot_ends_at) as end_time,
    MIN(est.ticket_price) as min_price,
    MAX(est.ticket_price) as max_price,
    COUNT(DISTINCT est.ticket_id) as total_seats,
    SUM(CASE WHEN est.is_vacant = 'yes' THEN 1 ELSE 0 END) as available_seats
FROM event_info e
JOIN event_schedule es ON e.event_id = es.event_id
JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
JOIN event_schedule_tickets est ON es.event_schedule_id = est.event_schedule_id
WHERE 
    es.venue_id = :venue_id
    AND e.is_active = 'yes'
    AND es.is_active = 'yes'
    AND DATE(vs.slot_starts_at) = :date
GROUP BY 
    e.event_id, e.event_name, e.event_type, e.genre, e.event_duration,
    es.event_schedule_id, DATE(vs.slot_starts_at), 
    TIME_FORMAT(vs.slot_starts_at, '%h:%i %p'), TIME(vs.slot_starts_at), TIME(vs.slot_ends_at)
ORDER BY e.event_name, vs.slot_starts_at;
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':venue_id' => $venueId,
        ':date' => $date
    ]);
    $showtimes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group showtimes by event
    $groupedShowtimes = [];
    foreach ($showtimes as $showtime) {
        $eventId = $showtime['event_id'];
        if (!isset($groupedShowtimes[$eventId])) {
            $groupedShowtimes[$eventId] = [
                'event' => [
                    'event_id' => $showtime['event_id'],
                    'event_name' => $showtime['event_name'],
                    'event_type' => $showtime['event_type'],
                    'genre' => $showtime['genre'],
                    'event_duration' => $showtime['event_duration']
                ],
                'showtimes' => []
            ];
        }
        
        // Get ticket types for this showtime
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
        $ticketStmt->execute([':schedule_id' => $showtime['event_schedule_id']]);
        $tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $showtime['tickets'] = $tickets;
        $groupedShowtimes[$eventId]['showtimes'][] = $showtime;
    }

    // Output showtimes grouped by event
    foreach ($groupedShowtimes as $eventGroup): 
        $event = $eventGroup['event'];
        $eventShowtimes = $eventGroup['showtimes'];
    ?>
    <div class="event-showtimes mb-4">
        <h4><?= htmlspecialchars($event['event_name']) ?></h4>
        <div class="time-slots-container">
            <?php foreach ($eventShowtimes as $showtime): 
                $availabilityStatus = $showtime['available_seats'] <= 0 ? 'sold-out' : 
                                    ($showtime['available_seats'] < 5 ? 'few-left' : 'available');
            ?>
                <a href="<?=$ticket_map;?>?schedule_id=<?= $showtime['event_schedule_id'] ?>" 
                   class="time-slot">
                    <div class="slot-header">
                        <div class="slot-time"><?= $showtime['formatted_time'] ?></div>
                        <div class="slot-availability <?= $availabilityStatus ?>">
                            <?= $showtime['available_seats'] <= 0 ? 'Sold out' : 
                               ($showtime['available_seats'] < 5 ? 'Few left' : 
                                $showtime['available_seats'] . '/' . $showtime['total_seats']) ?>
                        </div>
                    </div>
                    <div class="ticket-info">
                        <?php foreach ($showtime['tickets'] as $ticket): 
                            $ticketAvailability = $ticket['available_seats'] <= 0 ? 'sold-out' : 
                                               ($ticket['available_seats'] < 5 ? 'few-left' : 'available');
                        ?>
                            <div class="ticket-type">
                                <span class="ticket-name"><?= htmlspecialchars($ticket['ticket_type']) ?></span>
                                <span class="ticket-price">₹<?= htmlspecialchars($ticket['ticket_price']) ?></span>
                                <span class="ticket-availability <?= $ticketAvailability ?>">
                                    <?= $ticket['available_seats'] <= 0 ? 'Sold out' : 
                                       ($ticket['available_seats'] < 5 ? 'Few left' : 
                                        $ticket['available_seats'] . '/' . $ticket['total_seats']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach;
    
    if (empty($groupedShowtimes)) {
        echo '<div class="no-shows">No showtimes available for the selected date and venue.</div>';
    }

} catch(PDOException $e) {
    echo '<div class="alert alert-danger">Error loading showtimes: ' . htmlspecialchars($e->getMessage()) . '</div>';
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
}
body .dark-mode {
    background-color: var(--primary-bg);
    color: var(--text-color);
    /* rest of your body styles */
}

    
    body .light-mode {
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

    :root {
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
    
    /* Dark mode search results */
    --dm-search-result-bg: #1e1e1e;
    --dm-search-result-text: #e0e0e0;
    --dm-search-result-hover: rgba(255,255,255,0.05);
    --dm-search-result-border: #333;
}

.dark-mode .search-results {
    background-color: var(--dm-search-result-bg);
    color: var(--dm-search-result-text);
    border-color: var(--dm-search-result-border);
}

.dark-mode .search-result-item {
    color: var(--dm-search-result-text);
}

.dark-mode .search-result-item:hover {
    background-color: var(--dm-search-result-hover);
}

</style>
</head>
<body class="dark-mode">

   

 


    <!-- Showtimes Container -->
    <div class="showtimes-container" id="showtimesContainer">
        <?php if (!empty($groupedShowtimes)): ?>
            <div class="time-slots-container">
                <?php foreach ($groupedShowtimes as $showtime):   
                ?>
            
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            
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
    
    
            
        document.addEventListener('DOMContentLoaded', function() {

            // Initialize tabs
    const tabElms = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabElms.forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', function(event) {
            // Refresh iframe when tab is shown
            const target = event.target.getAttribute('data-bs-target');
            const iframe = document.querySelector(`${target} iframe`);
            if(iframe) {
                iframe.src = iframe.src; // Refresh
            }
        });
    });
    
    // Handle iframe resizing
    window.addEventListener('message', function(e) {
        const iframes = document.querySelectorAll('.tab-content iframe');
        iframes.forEach(iframe => {
            if(e.source === iframe.contentWindow) {
                iframe.style.height = e.data.height + 'px';
            }
        });
    }, false);
    
    // Theme toggle functionality
    // Request theme from parent
window.parent.postMessage({ type: 'THEME_REQUEST' }, '*');

// Listen for theme changes
window.addEventListener('message', function(event) {
    if (event.data.type === 'SET_THEME') {
        document.documentElement.setAttribute('data-bs-theme', event.data.theme);
    }
});
        });
        </script>

</body>
</html>
