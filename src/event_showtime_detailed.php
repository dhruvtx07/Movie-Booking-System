<?php



date_default_timezone_set('Asia/Kolkata');
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

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



// Database connection with error handling
try {
    $db = new PDO('mysql:host=localhost;dbname=event_mg', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("<div class='alert alert-danger'>Database connection failed: " . $e->getMessage() . "</div>");
}

// Get and validate event ID
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT) ?: 10;

// Fetch event details
try {
    $eventQuery = $db->prepare("SELECT * FROM event_info WHERE event_id = ? AND is_active = 'yes'");
    $eventQuery->execute([$eventId]);
    $event = $eventQuery->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        echo "<script>window.history.back();</script>";
        exit();

        die("<div class='alert alert-danger'>Event not found or inactive</div>");
    }
} catch(PDOException $e) {
    die("<div class='alert alert-danger'>Error fetching event: " . $e->getMessage() . "</div>");
}

// Date handling - calculate next 7 days
$dates = [];
$selectedDate = isset($_GET['date']) ? new DateTime($_GET['date']) : new DateTime();
$today = new DateTime(); // For highlighting today's date
for ($i = 0; $i < 7; $i++) {
    $date = clone $selectedDate;
    $date->modify("+$i days");
    $dates[] = $date;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($event['event_name']) ?> - Showtimes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        
        :root {
    --primary-black: #1a1a1a;
    --primary-red: #d62828;
    --primary-orange: #f77f00;
    --primary-yellow: #fcbf49;
    --primary-peach: #ffd8a6;
    --dark-red: #9e2a2b;
    --light-peach: #fff3e6;
    
    /* Dark mode variables */
    --dm-bg: #121212;
    --dm-card-bg: #1e1e1e;
    --dm-text: #e0e0e0;
    --dm-text-muted: #a0a0a0;
    --dm-border: #333;
    --dm-shadow: rgba(0, 0, 0, 0.3);
    --dm-header-bg: #1a1a1a;
    --dm-badge-bg: #333;
    --dm-movie-title: #ffffff;
    --dm-movie-subtitle: #bbbbbb;
    --dm-movie-meta: #fcbf49;
}

body {
    background-color: var(--light-peach);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: var(--primary-black);
    transition: background-color 0.3s ease, color 0.3s ease;
    font-size: 0.9rem; /* Reduced base font size */
}

/* Dark mode styles */
body.dark-mode {
    background-color: var(--dm-bg);
    color: var(--dm-text);
}

/* Movie Header - Enhanced Dark Mode */
.dark-mode .movie-header {
    background: linear-gradient(135deg, var(--dm-header-bg) 0%, #2c2c2c 100%);
    color: var(--dm-text);
    border-left: 4px solid var(--primary-orange); /* Reduced border */
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3); /* Reduced shadow */
}

.light-mode .movie-header {
    background: white;
    color: white;
    border-left: 4px solid var(--primary-red); /* Reduced border */
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15); /* Reduced shadow */
}

.light-mode .movie-title{
    color: var(--primary-black) !important;
}

.light-mode .section-title{
    color: var(--primary-orange)
}

.dark-mode .movie-title {
    color: var(--dm-movie-title) !important;
}

.dark-mode .movie-title small {
    color: var(--dm-movie-subtitle) !important;
}

.light-mode .movie-title small {
    color: var(--primary-peach) !important;
}

.dark-mode .movie-title:after {
    background: var(--primary-orange);
}

.dark-mode .movie-header .text {
    color: var(--dm-movie-subtitle) !important;
}

.light-mode .movie-header .text {
    color: var(--primary-peach) !important;
}

.dark-mode .movie-meta {
    color: var(--dm-movie-meta) !important;
}

.light-mode .movie-meta {
    color: var(--primary-peach) !important;
}

.dark-mode .badge.bg-dark {
    background-color: var(--dm-badge-bg) !important;
    color: white !important;
}

.dark-mode .badge.bg-danger {
    background-color: var(--primary-red) !important;
    color: white !important;
}

.dark-mode .badge.bg-warning {
    background-color: var(--primary-yellow) !important;
    color: var(--primary-black) !important;
}

/* Rest of the dark mode styles */
.dark-mode .date-header,
.dark-mode .showtime-slot,
.dark-mode .no-shows {
    background-color: var(--dm-card-bg);
    border-color: var(--dm-border);
    color: var(--dm-text);
    box-shadow: 0 2px 8px var(--dm-shadow); /* Reduced shadow */
}

.dark-mode .date-day {
    color: var(--dm-text-muted);
}

.dark-mode .date-date {
    color: var(--dm-text);
}

.dark-mode .selected-date {
    background-color: rgba(247, 127, 0, 0.2);
}

.dark-mode .today-date {
    background-color: rgba(252, 191, 73, 0.1);
}

.dark-mode .venue-name,
.dark-mode .availability-info {
    color: var(--dm-text-muted);
}

.dark-mode .time-slot {
    color: var(--dm-text) !important;
}

.dark-mode .section-title {
    color: var(--dm-text);
}

.dark-mode .section-title:after {
    background: linear-gradient(90deg, var(--primary-orange), var(--primary-yellow));
}

.dark-mode .price-amount {
    background: rgba(214, 40, 40, 0.2);
    color: var(--primary-peach) !important;
}

.dark-mode .today-badge {
    background-color: var(--primary-orange);
    color: var(--primary-black);
}

.dark-mode .spinner-border {
    border-color: var(--dm-text-muted);
    border-right-color: transparent;
}

.container {
    max-width: 1200px;
    padding: 1rem; /* Reduced padding */
}

/* Movie Header - Base Styles */
.movie-header {
    margin-top: 100px;
    border-radius: 8px; /* Reduced radius */
    padding: 1.5rem; /* Reduced padding */
    margin-bottom: 1.5rem; /* Reduced margin */
    transition: all 0.3s ease;
}

.movie-header:hover {
    transform: translateY(-2px); /* Reduced movement */
    box-shadow: 0 4px 12px rgba(0,0,0,0.15); /* Reduced shadow */
}

.movie-title {
    font-weight: 700; /* Reduced weight */
    margin-bottom: 0.25rem; /* Reduced margin */
    position: relative;
    display: inline-block;
    transition: color 0.3s ease;
    font-size: 1.5rem; /* Reduced size */
}

.movie-title:after {
    content: '';
    position: absolute;
    bottom: -4px; /* Reduced position */
    left: 0;
    width: 40px; /* Reduced width */
    height: 2px; /* Reduced height */
    background: var(--primary-orange);
    transition: background 0.3s ease;
}

/* Rest of the base styles */
.date-header {
    background-color: white;
    border-radius: 8px; /* Reduced radius */
    padding: 0.75rem; /* Reduced padding */
    margin-bottom: 1.5rem; /* Reduced margin */
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); /* Reduced shadow */
    border: 1px solid #e0e0e0;
}

.date-cell {
    text-align: center;
    padding: 0.5rem 0.25rem; /* Reduced padding */
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    border-radius: 6px; /* Reduced radius */
    position: relative;
    margin: 0 1px; /* Reduced margin */
}

.date-cell:hover {
    background-color: var(--primary-peach);
    transform: translateY(-1px); /* Reduced movement */
}

.date-day {
    font-size: 0.8rem; /* Reduced size */
    color: #666;
    font-weight: 500;
}

.date-date {
    font-weight: 600; /* Reduced weight */
    font-size: 1rem; /* Reduced size */
    color: var(--primary-black);
}

.selected-date {
    background-color: var(--primary-peach);
    border-bottom: 2px solid var(--primary-orange); /* Reduced border */
    box-shadow: 0 2px 4px rgba(247, 127, 0, 0.2); /* Reduced shadow */
}

.today-date {
    background-color: rgba(252, 191, 73, 0.2);
}

.today-badge {
    position: absolute;
    top: -6px; /* Reduced position */
    right: 6px; /* Reduced position */
    font-size: 0.55rem; /* Reduced size */
    background: var(--primary-orange);
    color: white;
    padding: 1px 4px; /* Reduced padding */
    border-radius: 8px; /* Reduced radius */
    font-weight: 600;
}

.venue-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); /* Reduced min width */
    gap: 1rem; /* Reduced gap */
}

.showtime-slot {
    background: white;
    border-radius: 8px; /* Reduced radius */
    padding: 0.75rem; /* Reduced padding */
    margin-bottom: 0;
    height: 140px; /* Reduced height */
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1); /* Reduced shadow */
    border: 1px solid #eee;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    position: relative;
    overflow: hidden;
}

.showtime-slot:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px; /* Reduced height */
    background: linear-gradient(90deg, var(--primary-red), var(--primary-orange));
}

.showtime-slot:hover {
    transform: translateY(-3px) scale(1.02); /* Reduced movement */
    box-shadow: 0 4px 12px rgba(0,0,0,0.15); /* Reduced shadow */
    border-color: var(--primary-yellow);
}

.showtime-header {
    display: flex;
    flex-direction: column;
    margin-bottom: 0.25rem; /* Reduced margin */
}

.time-slot {
    font-weight: 600; /* Reduced weight */
    font-size: 1rem; /* Reduced size */
    color: var(--primary-black);
    margin-bottom: 0.25rem; /* Reduced margin */
    text-align: center;
}

.venue-name {
    font-size: 0.75rem; /* Reduced size */
    color: #666;
    text-align: center;
    margin-bottom: 0.25rem; /* Reduced margin */
}

.price-info {
    text-align: center;
    margin: 0.25rem 0; /* Reduced margin */
}

.price-amount {
    font-weight: 600; /* Reduced weight */
    color: var(--primary-red);
    font-size: 0.9rem; /* Reduced size */
    background: rgba(214, 40, 40, 0.1);
    padding: 0.2rem 0.4rem; /* Reduced padding */
    border-radius: 3px; /* Reduced radius */
    display: inline-block;
}

.availability-info {
    margin-top: auto;
    text-align: center;
    font-size: 0.75rem; /* Reduced size */
    color: #666;
}

.book-btn {
    width: 100%;
    padding: 0.4rem; /* Reduced padding */
    font-size: 0.8rem; /* Reduced size */
    font-weight: 600;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-yellow));
    border: none;
    color: var(--primary-black);
    border-radius: 5px; /* Reduced radius */
    transition: all 0.3s ease;
    margin-top: 0.25rem; /* Reduced margin */
    box-shadow: 0 1px 3px rgba(247, 127, 0, 0.3); /* Reduced shadow */
}

.book-btn:hover {
    background: linear-gradient(135deg, var(--primary-red), var(--primary-orange));
    color: white;
    transform: translateY(-1px); /* Reduced movement */
    box-shadow: 0 2px 6px rgba(214, 40, 40, 0.3); /* Reduced shadow */
}

.book-btn:active {
    transform: translateY(0);
}

.loading-spinner {
    width: 2.5rem; /* Reduced size */
    height: 2.5rem; /* Reduced size */
    border: 0.3em solid var(--primary-orange); /* Reduced border */
    border-right-color: transparent;
}

.no-shows {
    grid-column: 1 / -1;
    text-align: center;
    padding: 2rem; /* Reduced padding */
    background-color: white;
    border-radius: 8px; /* Reduced radius */
    box-shadow: 0 2px 8px rgba(0,0,0,0.1); /* Reduced shadow */
    border-left: 3px solid var(--primary-red); /* Reduced border */
}

.no-shows-title {
    color: var(--primary-red);
    font-weight: 600; /* Reduced weight */
    margin-bottom: 0.75rem; /* Reduced margin */
    font-size: 1.1rem; /* Reduced size */
}

.section-title {
    font-weight: 600; /* Reduced weight */
    color: var(--primary-black);
    margin-bottom: 1rem; /* Reduced margin */
    position: relative;
    padding-bottom: 0.4rem; /* Reduced padding */
    font-size: 1.2rem; /* Reduced size */
}

.section-title:after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 50px; /* Reduced width */
    height: 2px; /* Reduced height */
    background: linear-gradient(90deg, var(--primary-red), var(--primary-orange));
}





@media (max-width: 768px) {
    .venue-container {
        grid-template-columns: 1fr;
    }
    
    .date-header {
        overflow-x: auto;
        white-space: nowrap;
        display: flex;
        padding-bottom: 0.5rem; /* Reduced padding */
    }
    
    .date-cell {
        min-width: 70px; /* Reduced width */
        display: inline-block;
        margin-right: 0.4rem; /* Reduced margin */
        flex-shrink: 0;
    }
    
    .movie-header {
        padding: 1rem; /* Reduced padding */
    }
    
    .movie-title {
        font-size: 1.3rem; /* Reduced size */
    }
    
 
    
    .section-title {
        font-size: 1.1rem; /* Reduced size */
    }
    
    .showtime-slot {
        height: 130px; /* Reduced height */
    }
}

@media (max-width: 576px) {
    .container {
        padding: 0.75rem; /* Reduced padding */
    }
    
    .movie-header {
        padding: 1rem 0.75rem; /* Reduced padding */
    }
    
    .movie-title {
        font-size: 1.2rem; /* Reduced size */
    }
    
    .date-cell {
        min-width: 60px; /* Reduced width */
        padding: 0.4rem 0.2rem; /* Reduced padding */
    }
    
    .date-day {
        font-size: 0.7rem; /* Reduced size */
    }
    
    .date-date {
        font-size: 0.9rem; /* Reduced size */
    }
    
    .section-title {
        font-size: 1rem; /* Reduced size */
    }
    
    .showtime-slot {
        height: 120px; /* Reduced height */
        padding: 0.6rem; /* Reduced padding */
    }
    
    .time-slot {
        font-size: 0.9rem; /* Reduced size */
    }
    
    .book-btn {
        font-size: 0.75rem; /* Reduced size */
    }
}

/* Animation for loading */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); } /* Reduced movement */
    to { opacity: 1; transform: translateY(0); }
}

.showtime-slot {
    animation: fadeIn 0.3s ease forwards; /* Reduced duration */
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
/* Add to file2 and file3 styles */
.container {
    position: relative;
    z-index: 1; /* Lower than dropdown z-index */
}

    </style>
</head>
<body class="light-mode">

    <div class="container py-4">
        <!-- Event Header -->
        <div class="movie-header">
            <h2 class="movie-title"><?= htmlspecialchars($event['event_name']) ?> 
                <small class="text">(<?= htmlspecialchars($event['event_language']) ?>)</small>
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
                <?php foreach ($dates as $index => $date): 
                    $isSelected = $date->format('Y-m-d') === $selectedDate->format('Y-m-d');
                    $isToday = $date->format('Y-m-d') === $today->format('Y-m-d');
                    $dateParam = $date->format('Y-m-d');
                    $dayName = $date->format('D');
                    $dateNum = $date->format('d');
                    $monthName = $date->format('M');
                ?>
                    <div class="col date-cell <?= $isSelected ? 'selected-date' : '' ?> <?= $isToday ? 'today-date' : '' ?>" 
                         onclick="loadShowtimes('<?= $dateParam ?>')">
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

});
        // Theme management
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const body = document.body;
        
        // Check for saved theme preference or use system preference
        function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            if (savedTheme) {
                // Use saved preference
                body.classList.remove('light-mode', 'dark-mode');
                body.classList.add(savedTheme === 'dark' ? 'dark-mode' : 'light-mode');
                updateIcon(savedTheme);
            } else {
                // Use system preference if no saved preference
                body.classList.remove('light-mode', 'dark-mode');
                body.classList.add(systemPrefersDark ? 'dark-mode' : 'light-mode');
                updateIcon(systemPrefersDark ? 'dark' : 'light');
            }
        }
        
// Change from Bootstrap Icons to Font Awesome
function updateIcon(theme) {
    if (theme === 'dark') {
        themeIcon.classList.remove('fa-moon');
        themeIcon.classList.add('fa-sun');
    } else {
        themeIcon.classList.remove('fa-sun');
        themeIcon.classList.add('fa-moon');
    }
}
        
        // Toggle theme
        function toggleTheme() {
            const isDark = body.classList.contains('dark-mode');
            body.classList.remove(isDark ? 'dark-mode' : 'light-mode');
            body.classList.add(isDark ? 'light-mode' : 'dark-mode');
            const theme = isDark ? 'light' : 'dark';
            localStorage.setItem('theme', theme);
            updateIcon(theme);
        }
        
        // Event listener for theme toggle
        themeToggle.addEventListener('click', toggleTheme);
        
        // Initialize theme on load
        document.addEventListener('DOMContentLoaded', initTheme);
        
        // Watch for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            // Only apply system preference if no user preference is set
            if (!localStorage.getItem('theme')) {
                body.classList.remove(e.matches ? 'light-mode' : 'dark-mode');
                body.classList.add(e.matches ? 'dark-mode' : 'light-mode');
                updateIcon(e.matches ? 'dark' : 'light');
            }
        });
        
        // Load showtimes when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const dateParam = urlParams.get('date') || new Date().toISOString().split('T')[0];
            loadShowtimes(dateParam);
        });

        function loadShowtimes(dateStr) {
            const container = document.getElementById('showtimesContainer');
            container.innerHTML = `
                <div class="d-flex justify-content-center my-5 py-5">
                    <div class="spinner-border loading-spinner" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            // Update URL without reloading
            const url = new URL(window.location);
            url.searchParams.set('date', dateStr);
            window.history.pushState({}, '', url);
            
            // Highlight selected date
            document.querySelectorAll('.date-cell').forEach(cell => {
                cell.classList.remove('selected-date');
                if (cell.getAttribute('onclick').includes(dateStr)) {
                    cell.classList.add('selected-date');
                    // Add pulse animation to selected date
                    cell.style.animation = 'pulse 0.5s ease';
                    setTimeout(() => {
                        cell.style.animation = '';
                    }, 500);
                }
            });
            
            // Get current event ID
            const urlParams = new URLSearchParams(window.location.search);
            const eventId = urlParams.get('event_id') || <?= $eventId ?>;
            
            // Fetch showtimes
            fetch(`<?=$showtimes?>?event_id=${eventId}&date=${dateStr}`)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.text();
                })
                .then(data => {
                    container.innerHTML = data;
                    // Attach event listeners to new elements
                    attachEventListeners();
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = `
                        <div class="no-shows">
                            <h5 class="no-shows-title"><i class="bi bi-exclamation-triangle-fill"></i> No Showtimes Available</h5>
                            <p>We couldn't find any showtimes for this date. Please try another date.</p>
                            <small class="text-muted">${error.message}</small>
                        </div>
                    `;
                });
        }
        
        function attachEventListeners() {
            // Attach click handlers to book buttons
            document.querySelectorAll('.book-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const scheduleId = this.getAttribute('data-schedule-id');
                    
                    // Add click feedback
                    this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Redirecting...';
                    this.classList.add('disabled');
                    
                    setTimeout(() => {
                        window.location.href = `<?=$ticket_map?>?schedule_id=${scheduleId}&event_id=<?= $eventId ?>`;
                    }, 500);
                });
                
                // Add hover effects
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                });
            });
        }
        
        // Handle back/forward navigation
        window.addEventListener('popstate', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const dateParam = urlParams.get('date');
            if (dateParam) {
                loadShowtimes(dateParam);
            }
        });
        
        // Add pulse animation for selected date
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
        `;
        document.head.appendChild(style);
        // After loading new content
initializeDropdowns();
    </script>
</body>
</html>