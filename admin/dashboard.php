<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'links.php'; // Include the links.php file for common links and configurations

// Database configuration
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Redirect to login if not authenticated (except for auth pages)
$auth_pages = [$login_page, $register_page, $forgot_pass];

if (!isset($_SESSION['user_id']) || 
    (!isset($_SESSION['is_host']) && !isset($_SESSION['is_admin'])) || 
    ($_SESSION['is_host'] !== 'yes' && $_SESSION['is_admin'] !== 'yes')) {
    
    // Only store redirect if it's not an auth page
    if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : '';

$adminUserId = isset($_SESSION['user_id']); // This is the user ID for which we want to display data
$adminUsername = $isLoggedIn ? $_SESSION['username'] : ''; // Corresponding username, for display purposes if needed.

// Connect to database
try {
 $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
 $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
 $pdo->exec("SET NAMES 'utf8mb4'"); // Set charset to UTF-8
} catch (PDOException $e) {
 die("Database connection failed: " . $e->getMessage());
}

// --- Data Filtering & Preparation ---

// Date Range Filtering
$filter_type = $_GET['date_filter'] ?? 'weekly'; // Default to weekly
$custom_start_date_param = $_GET['custom_start_date'] ?? ''; // Store original GET param for JS
$custom_end_date_param = $_GET['custom_end_date'] ?? ''; // Store original GET param for JS

/**
 * Calculates start and end datetimes based on preferred date filter type.
 *
 * @param string $filter_type The type of date filter (e.g., 'today', 'weekly', 'custom').
 * @param string|null $custom_start Optional custom start date (Y-m-d) for 'custom' filter.
 * @param string|null $custom_end Optional custom end date (Y-m-d) for 'custom' filter.
 * @return array An associative array with 'start' and 'end' datetime strings (Y-m-d H:i:s), and 'filter_type_used'.
 */
function getDateRange(string $filter_type, ?string $custom_start = null, ?string $custom_end = null): array
{
 // Ensure all dates are based on the specified timezone
 $today = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
 $dates = ['start' => '', 'end' => ''];
 $actual_filter_type = $filter_type; // Store the type actually used

 switch ($filter_type) {
  case 'today':
   $dates['start'] = $today->format('Y-m-d 00:00:00');
   $dates['end'] = $today->format('Y-m-d 23:59:59');
   break;
  case 'yesterday':
   $yesterday = new DateTime('yesterday', new DateTimeZone('Asia/Kolkata'));
   $dates['start'] = $yesterday->format('Y-m-d 00:00:00');
   $dates['end'] = $yesterday->format('Y-m-d 23:59:59');
   break;
  case 'weekly':
   // Start of current week (Monday) to end of Sunday
   $startOfWeek = clone $today;
   // Set to Monday of the current week (N=1 for Monday, N=7 for Sunday)
   if ($startOfWeek->format('N') != 1) { // If not Monday
    $startOfWeek->modify('last monday');
   }
   $dates['start'] = $startOfWeek->format('Y-m-d 00:00:00');

   $endOfWeek = clone $startOfWeek;
   $endOfWeek->modify('+6 days'); // Go to Sunday
   $dates['end'] = $endOfWeek->format('Y-m-d 23:59:59');
   break;
  case 'monthly':
   // Start of current month to end of current month
   $dates['start'] = $today->format('Y-m-01 00:00:00');
   $dates['end'] = $today->format('Y-m-t 23:59:59'); // 't' gives number of days in month
   break;
  case 'quarterly':
   $currentMonth = (int)$today->format('m');
   $currentYear = (int)$today->format('Y');
   $quarter = ceil($currentMonth / 3);
   $startMonth = (($quarter - 1) * 3) + 1; // 1, 4, 7, 10

   $startQuarter = new DateTime("$currentYear-$startMonth-01 00:00:00", new DateTimeZone('Asia/Kolkata'));
   $endQuarter = clone $startQuarter;
   $endQuarter->modify('+2 months')->modify('last day of this month');
   $dates['start'] = $startQuarter->format('Y-m-d 00:00:00');
   $dates['end'] = $endQuarter->format('Y-m-d 23:59:59');
   break;
  case 'custom':
   if ($custom_start && $custom_end) {
    // Validate custom dates
    $start = DateTime::createFromFormat('Y-m-d', $custom_start, new DateTimeZone('Asia/Kolkata'));
    $end = DateTime::createFromFormat('Y-m-d', $custom_end, new DateTimeZone('Asia/Kolkata'));
    // Check if dates are valid and start date is not after end date
    if ($start && $end && $start->format('Y-m-d') === $custom_start && $end->format('Y-m-d') === $custom_end && $start <= $end) {
     $dates['start'] = $start->format('Y-m-d 00:00:00');
     $dates['end'] = $end->format('Y-m-d 23:59:59');
    } else {
     // Invalid custom dates, fall back to default
     $actual_filter_type = 'weekly'; // Change filter type to 'weekly' for accurate highlighting
    }
   } else {
    // Custom dates not provided, fall back to default
    $actual_filter_type = 'weekly';
   }
   break;
  default:
   $actual_filter_type = 'weekly'; // Ensure default if an unknown type is passed
   break;
 }

 // If actual_filter_type was changed to 'weekly' due to fallback, recalculate
 if ($actual_filter_type === 'weekly' && empty($dates['start'])) {
  $startOfWeek = clone $today;
  if ($startOfWeek->format('N') != 1) {
   $startOfWeek->modify('last monday');
  }
  $dates['start'] = $startOfWeek->format('Y-m-d 00:00:00');
  $endOfWeek = clone $startOfWeek;
  $endOfWeek->modify('+6 days');
  $dates['end'] = $endOfWeek->format('Y-m-d 23:59:59');
 }

 return ['start' => $dates['start'], 'end' => $dates['end'], 'filter_type_used' => $actual_filter_type];
}

// Pass $_GET values as custom_start/end to getDateRange.
// The $filter_type variable directly reflects the users choice IF valid.
$date_range_result = getDateRange($filter_type, $custom_start_date_param, $custom_end_date_param);
$start_datetime = $date_range_result['start'];
$end_datetime = $date_range_result['end'];
// NOTE: $filter_type here is the actual one chosen by user, not necessarily 'filter_type_used' from function which handles fallback.
// We use $filter_type (user requested) directly in the HTML 'selected' attribute.
// The JS will check $filter_type from PHP and the URL parameters to decide initial visibility.

// Set input values for custom date range if filter_type is 'custom' and valid, otherwise blank
// These specific variables ($start_date_input, $end_date_input) are used for value attributes in HTML.
$start_date_input = ($date_range_result['filter_type_used'] === 'custom') ? (new DateTime($start_datetime))->format('Y-m-d') : '';
$end_date_input = ($date_range_result['filter_type_used'] === 'custom') ? (new DateTime($end_datetime))->format('Y-m-d') : '';


// Selected filters from GET request for multi-selects
$selected_events = $_GET['events'] ?? [];
$selected_cities = $_GET['cities'] ?? [];
$selected_venues = $_GET['venues'] ?? [];
$selected_ticket_types = $_GET['ticket_types'] ?? [];

// Helper function to prepare multi-select parameters for SQL
function prepareMultiSelectParams(array $selection_array): array
{
 return array_filter($selection_array, function ($value) {
  return $value !== '' && $value !== 'all'; // Filter out empty strings and 'all' dummy value
 });
}
$selected_events = prepareMultiSelectParams($selected_events);
$selected_cities = prepareMultiSelectParams($selected_cities);
$selected_venues = prepareMultiSelectParams($selected_venues);
$selected_ticket_types = prepareMultiSelectParams($selected_ticket_types);


// --- Fetching Filter Options for Dropdowns/Multiselects (Dynamically filtered) ---

// All Events (no dynamic filtering for its options based on other filters)
// Fetch all events by active status, then filter by event creator using $adminUserId
$all_events_stmt = $pdo->prepare("SELECT event_id, event_name, event_type, event_category FROM event_info WHERE is_active = 'yes' AND created_by = ? ORDER BY event_name");
$all_events_stmt->execute([$adminUserId]);
$all_events = $all_events_stmt->fetchAll(PDO::FETCH_ASSOC);


// Parameters for event-dependent city/venue fetching
$event_filter_params_for_options = [];
$event_filter_where_clause_for_options = "";

if (!empty($selected_events)) {
 $event_placeholders = implode(',', array_fill(0, count($selected_events), '?'));
 $event_filter_where_clause_for_options = " AND Ei.event_id IN ($event_placeholders)";
 $event_filter_params_for_options = $selected_events;
}

// Fetch cities - now filtered by selected events (if any) and by event creator
$all_cities_sql = "
 SELECT DISTINCT C.city_id, C.city_name
 FROM cities C
 JOIN venues V ON C.city_id = V.city_id AND V.is_active = 'yes'
 JOIN event_schedule Es ON V.venue_id = Es.venue_id AND Es.is_active = 'yes'
 JOIN event_info Ei ON Es.event_id = Ei.event_id AND Ei.is_active = 'yes'
 WHERE C.is_active = 'yes'
 AND Ei.created_by = ? -- Filter by the creator of the event schedule
 {$event_filter_where_clause_for_options}
 ORDER BY C.city_name
";

$stmt_all_cities = $pdo->prepare($all_cities_sql);
// Parameters for all_cities_sql: adminUserId first, then event filter params
$all_cities_params = array_merge([$adminUserId], $event_filter_params_for_options);
$stmt_all_cities->execute($all_cities_params);
$all_cities = $stmt_all_cities->fetchAll(PDO::FETCH_ASSOC);


// Fetch venues - filtered by selected events AND selected cities AND by event creator
$venue_filter_params_for_options = $event_filter_params_for_options; // Start with event filter params
$venue_filter_where_clause_for_options = $event_filter_where_clause_for_options;

if (!empty($selected_cities)) {
 $city_placeholders = implode(',', array_fill(0, count($selected_cities), '?'));
 $venue_filter_where_clause_for_options .= " AND C.city_id IN ($city_placeholders)";
 $venue_filter_params_for_options = array_merge($venue_filter_params_for_options, $selected_cities);
}

$all_venues_sql = "
 SELECT DISTINCT V.venue_id, V.venue_name, V.sub_venue_name, C.city_name
 FROM venues V
 JOIN cities C ON V.city_id = C.city_id AND C.is_active = 'yes'
 JOIN event_schedule Es ON V.venue_id = Es.venue_id AND Es.is_active = 'yes'
 JOIN event_info Ei ON Es.event_id = Ei.event_id AND Ei.is_active = 'yes'
 WHERE V.is_active = 'yes'
 AND Ei.created_by = ? -- Filter by the creator of the event schedule
 {$venue_filter_where_clause_for_options}
 ORDER BY V.venue_name, V.sub_venue_name
";
$stmt_all_venues = $pdo->prepare($all_venues_sql);
// Parameters for all_venues_sql: adminUserId first, then existing venue filter params
$all_venues_params = array_merge([$adminUserId], $venue_filter_params_for_options);
$stmt_all_venues->execute($all_venues_params);
$all_venues = $stmt_all_venues->fetchAll(PDO::FETCH_ASSOC);

// Ticket types (no dynamic filtering for its options based on other filters)
$all_ticket_types_query = $pdo->query("SELECT DISTINCT ticket_type FROM event_schedule_tickets ORDER BY ticket_type");
$all_ticket_types = $all_ticket_types_query->fetchAll(PDO::FETCH_ASSOC);


// --- Build Dynamic SQL Queries - Base Components ---

// Initial parameters common to all queries (date range + admin user ID for event creator filter)
$base_params = [$start_datetime, $end_datetime, $adminUserId];
$main_where_clauses = ["b.booked_at BETWEEN ? AND ?", "ei.created_by = ?"];

// This array now explicitly tracks aliases needed for the main WHERE clause filters.
// It will be passed to get_final_joins_revised.
$core_query_aliases = ['ei']; // 'ei' (event_info) is always needed due to the global filter 'ei.created_by = ?'


// Add filters to base WHERE clause and parameters (user-selected filters)
if (!empty($selected_events)) {
 $event_placeholders = implode(',', array_fill(0, count($selected_events), '?'));
 $main_where_clauses[] = "b.event_id IN ($event_placeholders)";
 $base_params = array_merge($base_params, $selected_events);
 // 'ei' is already in core_query_aliases, no need to add again, as event_id comes directly from bookings table.
}
if (!empty($selected_cities)) {
 $city_ids_placeholders = implode(',', array_fill(0, count($selected_cities), '?'));
 $main_where_clauses[] = "c.city_id IN ($city_ids_placeholders)";
 $base_params = array_merge($base_params, $selected_cities);
 $core_query_aliases[] = 'c'; // Need 'c' alias
}
if (!empty($selected_venues)) {
 $venue_ids_placeholders = implode(',', array_fill(0, count($selected_venues), '?'));
 $main_where_clauses[] = "v.venue_id IN ($venue_ids_placeholders)";
 $base_params = array_merge($base_params, $selected_venues);
 $core_query_aliases[] = 'v'; // Need 'v' alias
}
if (!empty($selected_ticket_types)) {
 $ticket_type_placeholders = implode(',', array_fill(0, count($selected_ticket_types), '?'));
 $main_where_clauses[] = "est.ticket_type IN ($ticket_type_placeholders)";
 $base_params = array_merge($base_params, $selected_ticket_types);
 $core_query_aliases[] = 'est'; // Need 'est' alias
}

// Ensure unique aliases for initial join building
$core_query_aliases = array_unique($core_query_aliases);

$combined_where_sql = "WHERE " . implode(' AND ', $main_where_clauses);

/**
 * Dynamically builds SQL JOIN clauses based on explicitly requested aliases.
 * Handles dependencies automatically, ensuring tables are joined in the correct order.
 *
 * @param string $base_table_alias Always 'b' for bookings.
 * @param array $requested_aliases List of aliases that need to be in the query (for WHERE, SELECT, GROUP BY).
 * @return string Concatenated JOIN clauses.
 */
function get_final_joins_revised(string $base_table_alias, array $requested_aliases): string
{
 $joins = [];
 $added_aliases_set = [$base_table_alias => true];

 // Define all possible joins and their *direct* dependencies
 $join_definitions = [
  'est' => ['clause' => "JOIN event_schedule_tickets est ON b.ticket_id = est.ticket_id", 'dependencies' => []],
  'es' => ['clause' => "JOIN event_schedule es ON est.event_schedule_id = es.event_schedule_id", 'dependencies' => ['est']],
  'v' => ['clause' => "JOIN venues v ON es.venue_id = v.venue_id", 'dependencies' => ['es']],
  'c' => ['clause' => "JOIN cities c ON v.city_id = c.city_id", 'dependencies' => ['v']],
  'ei' => ['clause' => "JOIN event_info ei ON b.event_id = ei.event_id", 'dependencies' => []], // event_info is directly joined on b.event_id
  'pc' => ['clause' => "JOIN promo_codes pc ON b.code_id = pc.code_id", 'dependencies' => []],
  // Note: The original PHP code for `get_final_join_clauses_revised` function had `b.event_id = es.event_id AND b.schedule_id = es.schedule_id`
  // in `event_schedule` (`es`) join. `b.schedule_id` and `b.ticket_id` are not typically direct columns in a `bookings` table
  // if `bookings` directly references `event_schedule_tickets`.
  // I am using the structure `bookings.ticket_id = event_schedule_tickets.ticket_id` and `event_schedule_tickets.event_schedule_id = event_schedule.event_schedule_id`
  // as inferred from the `event_schedule_tickets` name and common database patterns where a booking entry might be linked to a specific type of ticket which in turn is linked to a specific schedule.
  // If your database schema is different, you'll need to adjust the ON clauses accordingly.
 ];

 // Build the list of joins ensuring dependencies are met
 $queue_to_process = array_unique($requested_aliases);
 // Use an array to keep track of already added joins to preserve order and avoid duplicates
 $ordered_joins = [];

 // Helper to add an alias and its dependencies if not already added
 $add_alias_and_dependencies = function ($alias, &$current_joins, &$added_set, $all_definitions, &$recursive_func) {
  if (!isset($all_definitions[$alias]) || isset($added_set[$alias])) {
   return; // Already added or not a defined alias
  }
  // First add dependencies
  foreach ($all_definitions[$alias]['dependencies'] as $dep) {
   $recursive_func($dep, $current_joins, $added_set, $all_definitions, $recursive_func);
  }
  // Then add the current alias if not already in the set
  if (!isset($added_set[$alias])) {
   $current_joins[] = $all_definitions[$alias]['clause'];
   $added_set[$alias] = true;
  }
 };

 // Process the requested aliases
 foreach ($queue_to_process as $alias) {
  $add_alias_and_dependencies($alias, $ordered_joins, $added_aliases_set, $join_definitions, $add_alias_and_dependencies);
 }

 // Sort the join clauses by a predefined order to ensure consistency and correct dependencies
 // This fixed order ensures a predictable structure, e.g., tickets then schedule, then venue, then city.
 $final_ordered_aliases_sequence = ['est', 'es', 'v', 'c', 'ei', 'pc'];
 usort($ordered_joins, function ($a, $b) use ($final_ordered_aliases_sequence) {
  // Extract alias from join clause string (e.g., "JOIN table alias ON...")
  preg_match('/\s(\w+)\sON/', $a, $matches_a);
  $alias_a = $matches_a[1] ?? '';
  preg_match('/\s(\w+)\sON/', $b, $matches_b);
  $alias_b = $matches_b[1] ?? '';

  $pos_a = array_search($alias_a, $final_ordered_aliases_sequence);
  $pos_b = array_search($alias_b, $final_ordered_aliases_sequence);

  if ($pos_a === false && $pos_b === false) return 0; // Both undefined
  if ($pos_a === false) return 1; // $a is undefined, put it after $b
  if ($pos_b === false) return -1; // $b is undefined, put it after $a

  return $pos_a - $pos_b; // Sort by their position in the defined sequence
 });

 return implode("\n", $ordered_joins);
}


// --- Fetch Dashboard Metrics ---

// Base set of aliases needed for all main reports (due to global filters)
// 'ei' is included here because 'ei.created_by = ?' is a global filter.
$base_report_aliases = $core_query_aliases;


// 1. Total Tickets Sold & Total Revenue (Net after promo)
$sql = "
 SELECT
  COUNT(b.ticket_id) AS tickets_sold_count,
  SUM(b.total_amt) AS net_revenue_after_promo,
  SUM(b.booking_amt) AS gross_revenue_before_promo
 FROM bookings b
 " . get_final_joins_revised('b', $base_report_aliases) . "
 $combined_where_sql
";
$stmt = $pdo->prepare($sql);
$stmt->execute($base_params);
$sales_data = $stmt->fetch(PDO::FETCH_ASSOC);

$tickets_sold_count = $sales_data['tickets_sold_count'] ?? 0;
$net_revenue_after_promo = $sales_data['net_revenue_after_promo'] ?? 0;
$gross_revenue_before_promo = $sales_data['gross_revenue_before_promo'] ?? 0;
$total_discount_given = $gross_revenue_before_promo - $net_revenue_after_promo;

// 2. Commission Breakdown & My Platform Revenue
// This query is designed to get the breakdown of commissions for bookings matching the current filters.
// The EXISTS clause ensures that only revenue_info linked to bookings created by the admin user
// and falling within the date range/other filters are counted.
$sql_commission = "
 SELECT
  ci.commission_name,
  SUM(ri.revenue_amt) AS total_commission_amount
 FROM revenue_info ri
 JOIN commission_info ci ON ri.commission_id = ci.commission_id
 WHERE EXISTS (
  SELECT 1
  FROM bookings b
  " . get_final_joins_revised('b', $base_report_aliases) . "
  WHERE ri.booking_ref = b.booking_ref
  AND " . implode(' AND ', $main_where_clauses) . "
 )
 GROUP BY ci.commission_name
";
$stmt = $pdo->prepare($sql_commission);
$stmt->execute($base_params); // Parameters for `booked_at` filtering and other filters inside the subquery
$commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$platform_commission_collected = 0;
$host_commission_collected = 0;
$creator_revenue_amount = 0; // New variable for Creator Revenue
foreach ($commissions as $comm) {
 if ($comm['commission_name'] === 'Platform Fee') {
  $platform_commission_collected = $comm['total_commission_amount'] ?? 0;
 } elseif ($comm['commission_name'] === 'Host Fee') {
  $host_commission_collected = $comm['total_commission_amount'] ?? 0;
 } elseif ($comm['commission_name'] === 'Creator Revenue') {
  $creator_revenue_amount = $comm['total_commission_amount'] ?? 0;
 }
}

// Your total platform revenue (commissions earned) is what the platform keeps
$my_platform_revenue = $platform_commission_collected + $host_commission_collected;

// The amount paid out to hosts/event organizers (now explicitly 'Creator Revenue')
$amount_paid_to_hosts = $creator_revenue_amount; // This direct assignment reflects the new interpretation


// 3. Promo Codes Usage
$sql = "
 SELECT
  pc.code,
  COUNT(b.ticket_id) AS tickets_used,
  SUM(b.booking_amt - b.total_amt) AS total_discount_from_code
 FROM bookings b
 " . get_final_joins_revised('b', array_merge($base_report_aliases, ['pc'])) . "
 $combined_where_sql
 AND b.code_id IS NOT NULL
 GROUP BY pc.code
 ORDER BY tickets_used DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($base_params);
$promo_code_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Ticket-wise breakdown
$sql = "
 SELECT
  est.ticket_type,
  COUNT(b.ticket_id) AS tickets_count,
  SUM(b.total_amt) AS total_revenue
 FROM bookings b
 " . get_final_joins_revised('b', array_merge($base_report_aliases, ['est'])) . "
 $combined_where_sql
 GROUP BY est.ticket_type
 ORDER BY tickets_count DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($base_params);
$ticket_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);


// 5. Top 5 Events by Revenue
$sql = "
 SELECT
  ei.event_name,
  ei.event_category,
  SUM(b.total_amt) AS event_revenue
 FROM bookings b
 " . get_final_joins_revised('b', array_merge($base_report_aliases, ['ei'])) . "
 $combined_where_sql
 GROUP BY ei.event_id, ei.event_name, ei.event_category
 ORDER BY event_revenue DESC
 LIMIT 5
";
$stmt = $pdo->prepare($sql);
$stmt->execute($base_params);
$top_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. Top 5 Cities by Revenue
$sql = "
 SELECT
  c.city_name,
  SUM(b.total_amt) AS city_revenue
 FROM bookings b
 " . get_final_joins_revised('b', array_merge($base_report_aliases, ['c'])) . "
 $combined_where_sql
 GROUP BY c.city_id, c.city_name
 ORDER BY city_revenue DESC
 LIMIT 5
";
$stmt = $pdo->prepare($sql);
$stmt->execute($base_params);
$top_cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 7. Top 5 Venues by Revenue
$sql = "
 SELECT
  v.venue_name, v.sub_venue_name,
  SUM(b.total_amt) AS venue_revenue
 FROM bookings b
 " . get_final_joins_revised('b', array_merge($base_report_aliases, ['v'])) . "
 $combined_where_sql
 GROUP BY v.venue_id, v.venue_name, v.sub_venue_name
 ORDER BY venue_revenue DESC
 LIMIT 5
";
$stmt = $pdo->prepare($sql);
$stmt->execute($base_params);
$top_venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Clear message after display
if (isset($_SESSION['message'])) {
 unset($_SESSION['message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Analytics Dashboard - Catchify</title>
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
 <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
 <style>
  /* Custom Color Variables - Dark Theme Default */
  :root {
   --primary-bg: #1A1A1A; /* Dark background */
   --secondary-bg: #2B2B2B; /* Lighter dark for cards/elements */
   --text-color: #F0F0F0; /* Light text */
   --light-text-color: #B0B0B0; /* Muted text */
   --accent-red: #E50914; /* Netflix Red */
   --accent-orange: #FF6B35; /* Vibrant Orange */
   --accent-yellow: #FFC107; /* Golden Yellow */
   --accent-peach: #FF9E7D; /* Soft Peach */
   --nav-dark: #000000; /* Pure black for specific elements like sidebar overlay */
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

  /* For scrollable filter groups */
  .filter-checkbox-group::-webkit-scrollbar {
   width: 8px;
  }
  .filter-checkbox-group::-webkit-scrollbar-track {
   background: var(--primary-bg);
  }
  .filter-checkbox-group::-webkit-scrollbar-thumb {
   background: var(--accent-orange);
   border-radius: 4px;
  }
  .filter-checkbox-group::-webkit-scrollbar-thumb:hover {
   background: var(--accent-red);
  }
  .filter-checkbox-group {
   scrollbar-width: thin;
   scrollbar-color: var(--accent-orange) var(--primary-bg);
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

  /* Sidebar Styling (from manage_users.php) */
  .sidebar {
   width: var(--sidebar-width-collapsed);
   background-color: var(--nav-dark);
   color: var(--nav-text);
   /* position: fixed; and top/left remain for overlay effect on smaller screens */
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
   /* Remove margin-left and width as they are handled by .content-wrapper now */
  }

  /* General Styles */
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
  .alert-warning { background: linear-gradient(90deg, var(--accent-yellow), #e0a800); border: none; }
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
  /* Custom styling for form-check-input (general checkbox) */
  input[type="checkbox"].form-check-input {
   width: 1.25em;
   height: 1.25em;
   vertical-align: top;
   background-color: var(--primary-bg); /* Use primary-bg as background for non-selected */
   border: 1px solid var(--accent-orange);
   -webkit-appearance: none;
   -moz-appearance: none;
   appearance: none;
   border-radius: 0.25rem;
   transition: background-color .15s ease-in-out, border-color .15s ease-in-out, box-shadow .15s ease-in-out, background-image .15s ease-in-out;
   cursor: pointer;
   flex-shrink: 0; /* Important for alignment in flex containers */
  }
  input[type="checkbox"].form-check-input:checked {
   background-color: var(--accent-red);
   border-color: var(--accent-red);
   background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M6 10l3 3l6-6'/%3e%3csvg%3e");
   background-size: 100% 100%;
  }
  input[type="checkbox"].form-check-input:focus {
   box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
  }

  /* Styles for new filter checkbox groups */
  .filter-checkbox-group {
   max-height: 150px; /* Adjust as needed */
   overflow-y: auto;
   border: 1px solid var(--card-border); /* Match form-control border */
   border-radius: 5px; /* Match form-control border-radius */
   padding: 10px; /* Add some padding internally */
   background-color: var(--secondary-bg); /* Match form-control background */
   margin-bottom: 0px; /* To align with other form fields */
   color: var(--text-color); /* Ensure text color is set */
  }
  .filter-checkbox-group .form-check {
   margin-bottom: 8px; /* Spacing between checkboxes */
   display: flex; /* Make it a flex container for better alignment */
   align-items: flex-start; /* Vertically align checkbox and label */
  }
  .filter-checkbox-group .form-check:last-child {
   margin-bottom: 0; /* No margin after last checkbox */
  }
  .filter-checkbox-group .form-check-label {
   margin-left: 10px; /* Spacing between checkbox and label */
   cursor: pointer;
   line-height: 1.2; /* For better vertical alignment */
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
  .btn-success { /* For Add New User */
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

  /* Dashboard Cards */
  .metric-card {
   background-color: var(--secondary-bg);
   border: 1px solid var(--card-border);
   border-radius: 8px;
   padding: 20px;
   box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
   transition: transform 0.3s ease, box-shadow 0.3s ease;
   text-align: center;
   height: 100%; /* Ensure cards in a row have equal height */
   display: flex;
   flex-direction: column;
  }
  .metric-card:hover {
   transform: translateY(-5px);
   box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
  }
  .metric-card h5 {
   color: var(--light-text-color);
   font-size: var(--metric-card-h5-font);
   font-weight: 600; /* Made slightly bolder */
   margin-bottom: 10px;
  }
  .metric-card .display-4 {
   color: var(--accent-orange);
   font-size: var(--metric-card-display-4-font);
   font-weight: bold;
   margin-bottom: 10px;
  }

  .metric-card p.text-muted {
   color: var(--text-color) !important;
   font-size: 0.85rem; /* Slightly smaller text */
   margin-top: auto; /* Push text to the bottom */
   margin-bottom: 0;
   line-height: 1.3;
  }

  .metric-card.green .display-4 { color: #28a745; } /* Green for positive revenue */
  .metric-card.red .display-4 { color: var(--accent-red); } /* Red for discounts */
  .metric-card.blue .display-4 { color: #007bff; }
  .metric-card.purple .display-4 { color: #8F5EEB; } /* For custom new card (original has #6f42c1) */
  .metric-card.cyan .display-4 { color: #17a2b8; } /* For Host Payouts */
  .metric-card.gold .display-4 { color: #FFD700; } /* Gold for Creator Revenue */


  .list-group-item {
   background-color: var(--secondary-bg);
   border-color: var(--card-border);
   color: var(--text-color);
   display: flex;
   justify-content: space-between;
   align-items: center;
   padding: 10px 15px;
   font-size: 0.95rem; /* Slightly larger text */
  }
  .list-group-item:first-child { border-top-left-radius: 0; border-top-right-radius: 0; }
  .list-group-item:last-child { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
  .list-group-item strong {
   color: var(--accent-peach);
   white-space: nowrap;
   overflow: hidden;
   text-overflow: ellipsis;
   padding-right: 10px;
  }
  .list-group-item span.badge {
   background-color: var(--accent-red); /* Consistent badge color */
   color: white;
   font-size: 0.8em; /* Adjusted relative to new item font-size */
   padding: 0.3em 0.6em; /* Adjusted padding */
   flex-shrink: 0; /* Prevent badge from shrinking */
  }
  table {
   color: var(--text-color);
  }
  thead {
   background-color: var(--secondary-bg);
  }
  th, td {
   border-color: var(--card-border) !important;
   padding: 12px 18px; /* Increased padding */
  }
  th {
   font-size: 0.9rem;
   text-transform: uppercase;
   letter-spacing: 0.05em;
   font-weight: 600; /* Made bolder */
  }
  tbody tr:hover {
   background-color: rgba(255, 255, 255, 0.03); /* Subtle hover effect */
  }

  /* Footer Styling */
  .footer {
   background-color: var(--secondary-bg);
   color: var(--light-text-color);
   padding: 20px;
   border-top: 1px solid var(--card-border);
   flex-shrink: 0; /* Prevents the footer from shrinking */
   width: 100%; /* Ensures it spans the full width of its parent (.content-wrapper) */
  }
  .footer a {
   color: var(--accent-orange); /* Highlight links */
   text-decoration: none;
  }
  .footer a:hover {
   color: var(--accent-red);
   text-decoration: underline;
  }

  /* Responsive Adjustments (from manage_users.php, plus new for footer) */
  @media (max-width: 768px) {
   :root {
    --section-title-font: 1.8rem;
    --section-subtitle-font: 1.25rem;
    --metric-card-display-4-font: 2rem;
    --metric-card-h5-font: 1rem;
   }

   .sidebar {
    width: 0; /* Fully collapse sidebar by default on smaller screens */
    padding-top: 60px; /* Space for the fixed toggle button area */
    box-shadow: none; /* Remove shadow when fully collapsed */
   }
   .sidebar.is-open { /* Class added by JS when toggle button is clicked */
    width: var(--sidebar-width-expanded);
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.5);
   }

   .content-wrapper {
    margin-left: 0; /* Main content takes full width on small screens, no offset */
    padding-top: 15px; /* Adjust top padding for content */
   }
   /* When sidebar is active, main content doesn't shift, it gets overlaid */
   /* body.sidebar-is-open .content-wrapper rule is overridden by this media query */

   /* Add a button to toggle sidebar on small screens */
   .sidebar-toggle-btn {
    display: block; /* Show on small screens */
    position: fixed;
    top: 10px;
    left: 10px;
    z-index: 1030; /* Higher than sidebar */
    background-color: var(--nav-dark);
    color: var(--nav-text);
    border: none;
    padding: 10px 15px;
    border-radius: 5px;
    cursor: pointer;
    box-shadow: 0 2px 5px rgba(0,0,0,0.3);
    transition: transform 0.2s ease;
   }
   .filter-group-spacing { /* Add spacing between filter groups if they stack */
    margin-bottom: 15px;
   }

   .metric-card {
    padding: 15px; /* Reduce padding on cards for mobile */
   }
   .list-group-item {
    padding: 8px 12px; /* Reduce list item padding */
    font-size: 0.9rem;
   }
   th, td {
    padding: 10px 12px; /* Reduce table padding */
    font-size: 0.8rem;
   }
   .filter-checkbox-group {
    max-height: 120px; /* Tighter layout for mobile */
   }

   .footer {
    padding: 15px; /* Less padding */
    text-align: center; /* Center text on small screens */
   }
   .footer .col-md-6 {
    text-align: center !important; /* Force center for both columns */
   }
   .footer .row {
    flex-direction: column; /* Stack columns */
   }
   .footer .col-md-6:first-child {
    margin-bottom: 10px; /* Space between stacked columns */
   }
  }
  @media (min-width: 769px) { /* Desktop */
   .sidebar-toggle-btn {
    display: none; /* Hide on larger screens */
   }
   .filter-group-spacing {
    margin-bottom: 0; /* No extra spacing on desktop */
   }
  }
 </style>
</head>
<body>
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
    <h2 class="section-title">Analytics Dashboard</h2>
    <div class="d-flex justify-content-end mb-4">
     <button class="btn btn-outline-secondary" id="toggleFiltersBtn">
      <i class="fas fa-filter me-2"></i> Show Filters
     </button>
    </div>

    <!-- Filter Form -->
    <div class="card p-4 mb-4 d-none" id="filterCard" style="background-color: var(--secondary-bg); border: 1px solid var(--card-border);">
     <h4 class="mb-3 text-white">Apply Filters</h4>
     <form action="" method="GET" id="filterForm">
      <div class="row g-3">
       <div class="col-md-6 col-lg-3 filter-group-spacing">
        <label for="date_filter" class="form-label text-white">Date Range:</label>
        <select class="form-select" id="date_filter" name="date_filter">
         <option value="today" <?= $filter_type == 'today' ? 'selected' : '' ?>>Today</option>
         <option value="yesterday" <?= $filter_type == 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
         <option value="weekly" <?= $filter_type == 'weekly' ? 'selected' : '' ?>>This Week</option>
         <option value="monthly" <?= $filter_type == 'monthly' ? 'selected' : '' ?>>This Month</option>
         <option value="quarterly" <?= $filter_type == 'quarterly' ? 'selected' : '' ?>>This Quarter</option>
         <option value="custom" <?= $filter_type == 'custom' ? 'selected' : '' ?>>Custom Range</option>
        </select>
       </div>
       <div class="col-md-6 col-lg-3 custom-date-range <?= $filter_type == 'custom' ? '' : 'd-none' ?> filter-group-spacing">
        <label for="custom_start_date" class="form-label text-white">Start Date:</label>
        <input type="date" class="form-control" id="custom_start_date" name="custom_start_date" value="<?= htmlspecialchars($start_date_input) ?>">
       </div>
       <div class="col-md-6 col-lg-3 custom-date-range <?= $filter_type == 'custom' ? '' : 'd-none' ?> filter-group-spacing">
        <label for="custom_end_date" class="form-label text-white">End Date:</label>
        <input type="date" class="form-control" id="custom_end_date" name="custom_end_date" value="<?= htmlspecialchars($end_date_input) ?>">
       </div>

       <div class="col-md-6 col-lg-3 filter-group-spacing">
        <label class="form-label text-white">Events:</label>
        <input type="text" class="form-control filter-search-input mb-2" placeholder="Search events..." data-target-group="#events-group">
        <div class="filter-checkbox-group" id="events-group">
         <div class="form-check">
          <input class="form-check-input filter-type-all" type="checkbox" id="all_events" value="all"
           <?= (empty($selected_events)) ? 'checked' : '' ?>>
          <label class="form-check-label" for="all_events">All Events</label>
         </div>
         <?php foreach ($all_events as $event): ?>
          <div class="form-check">
           <input class="form-check-input filter-type-item" type="checkbox" id="event_<?= htmlspecialchars($event['event_id']) ?>" name="events[]" value="<?= htmlspecialchars($event['event_id']) ?>"
            <?= in_array($event['event_id'], $selected_events) ? 'checked' : '' ?>>
           <label class="form-check-label" for="event_<?= htmlspecialchars($event['event_id']) ?>">
            <?= htmlspecialchars($event['event_name']) ?> (<?= htmlspecialchars($event['event_category']) ?>)
           </label>
          </div>
         <?php endforeach; ?>
        </div>
       </div>

       <div class="col-md-6 col-lg-3 filter-group-spacing">
        <label class="form-label text-white">Cities:</label>
        <input type="text" class="form-control filter-search-input mb-2" placeholder="Search cities..." data-target-group="#cities-group">
        <div class="filter-checkbox-group" id="cities-group">
         <div class="form-check">
          <input class="form-check-input filter-type-all" type="checkbox" id="all_cities" value="all"
           <?= (empty($selected_cities)) ? 'checked' : '' ?>>
          <label class="form-check-label" for="all_cities">All Cities</label>
         </div>
         <?php foreach ($all_cities as $city): ?>
          <div class="form-check">
           <input class="form-check-input filter-type-item" type="checkbox" id="city_<?= htmlspecialchars($city['city_id']) ?>" name="cities[]" value="<?= htmlspecialchars($city['city_id']) ?>"
            <?= in_array($city['city_id'], $selected_cities) ? 'checked' : '' ?>>
           <label class="form-check-label" for="city_<?= htmlspecialchars($city['city_id']) ?>">
            <?= htmlspecialchars($city['city_name']) ?>
           </label>
          </div>
         <?php endforeach; ?>
        </div>
       </div>

       <div class="col-md-6 col-lg-3 filter-group-spacing">
        <label class="form-label text-white">Venues:</label>
        <input type="text" class="form-control filter-search-input mb-2" placeholder="Search venues..." data-target-group="#venues-group">
        <div class="filter-checkbox-group" id="venues-group">
         <div class="form-check">
          <input class="form-check-input filter-type-all" type="checkbox" id="all_venues" value="all"
           <?= (empty($selected_venues)) ? 'checked' : '' ?>>
          <label class="form-check-label" for="all_venues">All Venues</label>
         </div>
         <?php foreach ($all_venues as $venue): ?>
          <div class="form-check">
           <input class="form-check-input filter-type-item" type="checkbox" id="venue_<?= htmlspecialchars($venue['venue_id']) ?>" name="venues[]" value="<?= htmlspecialchars($venue['venue_id']) ?>"
            <?= in_array($venue['venue_id'], $selected_venues) ? 'checked' : '' ?>>
           <label class="form-check-label" for="venue_<?= htmlspecialchars($venue['venue_id']) ?>">
            <?= htmlspecialchars($venue['venue_name']) ?> - <?= htmlspecialchars($venue['sub_venue_name']) ?> (<?= htmlspecialchars($venue['city_name']) ?>)
           </label>
          </div>
         <?php endforeach; ?>
        </div>
       </div>

       <div class="col-md-6 col-lg-3 filter-group-spacing">
        <label class="form-label text-white">Ticket Types:</label>
        <input type="text" class="form-control filter-search-input mb-2" placeholder="Search ticket types..." data-target-group="#ticket_types-group">
        <div class="filter-checkbox-group" id="ticket_types-group">
         <div class="form-check">
          <input class="form-check-input filter-type-all" type="checkbox" id="all_ticket_types" value="all"
           <?= (empty($selected_ticket_types)) ? 'checked' : '' ?>>
          <label class="form-check-label" for="all_ticket_types">All Ticket Types</label>
         </div>
         <?php foreach ($all_ticket_types as $type): ?>
          <div class="form-check">
           <input class="form-check-input filter-type-item" type="checkbox" id="ticket_type_<?= htmlspecialchars($type['ticket_type']) ?>" name="ticket_types[]" value="<?= htmlspecialchars($type['ticket_type']) ?>"
            <?= in_array($type['ticket_type'], $selected_ticket_types) ? 'checked' : '' ?>>
           <label class="form-check-label" for="ticket_type_<?= htmlspecialchars($type['ticket_type']) ?>">
            <?= htmlspecialchars($type['ticket_type']) ?>
           </label>
          </div>
         <?php endforeach; ?>
        </div>
       </div>

       <div class="col-12 text-end">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply Filters</button>
        <a href="dashboard.php" class="btn btn-secondary ms-2"><i class="fas fa-sync-alt me-2"></i>Reset Filters</a>
       </div>
      </div>
     </form>
    </div>

    <h3 class="section-subtitle">Overall Performance</h3>
    <div class="row mb-4">
     <div class="col-md-12 mb-3"> <!-- First sub-row for tickets and breakdown -->
      <div class="row">
       <div class="col-lg-8 mb-3"> <!-- Tickets Sold (wide) -->
        <div class="metric-card">
         <h5>Tickets Sold</h5>
         <div class="display-4"><i class="fas fa-ticket-alt"></i> <?= number_format($tickets_sold_count) ?></div>
         <p class="text-muted">Total number of tickets booked between<br><?= (new DateTime($start_datetime))->format('M d, Y') ?> - <?= (new DateTime($end_datetime))->format('M d, Y') ?></p>
        </div>
       </div>
       <div class="col-lg-4 mb-3"> <!-- Ticket Breakdown by Type -->
        <div class="metric-card">
         <h5>Ticket Breakdown by Type</h5>
         <ul class="list-group list-group-flush">
          <?php if (empty($ticket_breakdown)): ?>
           <li class="list-group-item">No tickets sold for the selected filters.</li>
          <?php else: ?>
           <?php foreach ($ticket_breakdown as $type): ?>
            <li class="list-group-item">
             <span><strong><?= htmlspecialchars($type['ticket_type']) ?></strong> (<?= number_format($type['tickets_count']) ?>)</span>
             <span class="badge"><i class="fas fa-indian-rupee-sign"></i> <?= number_format($type['total_revenue'], 2) ?></span>
            </li>
           <?php endforeach; ?>
          <?php endif; ?>
         </ul>
        </div>
       </div>
      </div>
     </div>
     <div class="col-md-12 mb-3"> <!-- Second sub-row for financial metrics -->
      <div class="row">
       <div class="col-md-4 mb-3">
        <div class="metric-card blue">
         <h5>Gross Booking Value</h5>
         <div class="display-4"><i class="fas fa-sack-dollar"></i> <?= number_format($gross_revenue_before_promo, 2) ?></div>
         <p class="text-muted">Sum of original ticket prices before any discounts.</p>
        </div>
       </div>
       <div class="col-md-4 mb-3">
        <div class="metric-card red">
         <h5>Total Discounts Given</h5>
         <div class="display-4"><i class="fas fa-tag"></i> <?= number_format($total_discount_given, 2) ?></div>
         <p class="text-muted">Total value of promo codes and other discounts applied.</p>
        </div>
       </div>
       <div class="col-md-4 mb-3">
        <div class="metric-card green">
         <h5>Net Revenue (Customer Paid)</h5>
         <div class="display-4"><i class="fas fa-indian-rupee-sign"></i> <?= number_format($net_revenue_after_promo, 2) ?></div>
         <p class="text-muted">Total amount paid by customers after all discounts.</p>
        </div>
       </div>
      </div>
     </div>
    </div>

    <h3 class="section-subtitle">Revenue Breakdown (Collected by Platform)</h3>
    <div class="row mb-4">
     <div class="col-md-12 mb-3">
      <div class="row">
       <div class="col-md-4 mb-3">
        <div class="metric-card purple">
         <h5>Platform Charges</h5>
         <div class="display-4"><i class="fas fa-city"></i> <?= number_format($platform_commission_collected, 2) ?></div>
         <p class="text-muted">Charges for the platform's service.</p>
        </div>
       </div>
       <div class="col-md-4 mb-3">
        <div class="metric-card cyan">
         <h5>Host Revenue </h5>
         <div class="display-4"><i class="fas fa-user-tie"></i> <?= number_format($host_commission_collected, 2) ?></div>
         <p class="text-muted">Revenue to event hosts by the platform.</p>
        </div>
       </div>
       <div class="col-md-4 mb-3">
        <div class="metric-card gold">
         <h5>Creator Revenue</h5>
         <div class="display-4"><i class="fas fa-indian-rupee-sign"></i> <?= number_format($creator_revenue_amount, 2) ?></div>
         <p class="text-muted">Amount allocated to event creators/organizers.</p>
        </div>
       </div>
      </div>
     </div>
     <!-- Removed "Total Commission Revenue" and "Amount Paid to Host" in favor of the new structure -->
    </div>

    <h3 class="section-subtitle">Promo Code Performance</h3>
    <?php if (empty($promo_code_stats)): ?>
     <div class="alert alert-info">No promo codes used within the selected filters.</div>
    <?php else: ?>
     <div class="table-responsive">
      <table class="table table-dark table-striped">
       <thead>
      <tr>
        <th>Promo Code</th>
        <th>Tickets Used</th>
        <th>Total Discount Value</th>
      </tr>
       </thead>
       <tbody>
       <?php foreach ($promo_code_stats as $promo): ?>
        <tr>
         <td><?= htmlspecialchars($promo['code']) ?></td>
         <td><?= number_format($promo['tickets_used']) ?></td>
         <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($promo['total_discount_from_code'], 2) ?></td>
        </tr>
       <?php endforeach; ?>
       </tbody>
      </table>
     </div>
    <?php endif; ?>

    <h3 class="section-subtitle">Top Analytics</h3>
    <div class="row">
     <div class="col-md-4 mb-4">
      <div class="metric-card">
       <h5>Top 5 Events by Revenue</h5>
       <ul class="list-group list-group-flush">
        <?php if (empty($top_events)): ?>
         <li class="list-group-item">No events found for the selected filters.</li>
        <?php else: ?>
         <?php foreach ($top_events as $event): ?>
          <li class="list-group-item">
           <span><strong><?= htmlspecialchars($event['event_name']) ?></strong> (<?= htmlspecialchars($event['event_category']) ?>)</span>
           <span class="badge"><i class="fas fa-indian-rupee-sign"></i> <?= number_format($event['event_revenue'], 2) ?></span>
          </li>
         <?php endforeach; ?>
        <?php endif; ?>
       </ul>
      </div>
     </div>
     <div class="col-md-4 mb-4">
      <div class="metric-card">
       <h5>Top 5 Cities by Revenue</h5>
       <ul class="list-group list-group-flush">
        <?php if (empty($top_cities)): ?>
         <li class="list-group-item">No cities found for the selected filters.</li>
        <?php else: ?>
         <?php foreach ($top_cities as $city): ?>
          <li class="list-group-item">
           <span><strong><?= htmlspecialchars($city['city_name']) ?></strong></span>
           <span class="badge"><i class="fas fa-indian-rupee-sign"></i> <?= number_format($city['city_revenue'], 2) ?></span>
          </li>
         <?php endforeach; ?>
        <?php endif; ?>
       </ul>
      </div>
     </div>
     <div class="col-md-4 mb-4">
      <div class="metric-card">
       <h5>Top 5 Venues by Revenue</h5>
       <ul class="list-group list-group-flush">
        <?php if (empty($top_venues)): ?>
         <li class="list-group-item">No venues found for the selected filters.</li>
        <?php else: ?>
         <?php foreach ($top_venues as $venue): ?>
          <li class="list-group-item">
           <span><strong><?= htmlspecialchars($venue['venue_name']) ?></strong> - <?= htmlspecialchars($venue['sub_venue_name']) ?></span>
           <span class="badge"><i class="fas fa-indian-rupee-sign"></i> <?= number_format($venue['venue_revenue'], 2) ?></span>
          </li>
         <?php endforeach; ?>
        <?php endif; ?>
       </ul>
      </div>
     </div>
    </div>

    <!-- TODO: Add links to detailed reports for each card/section -->

  </div>
  </main>

  <!-- Footer -->
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
  // Sidebar Toggle (from manage_users.php)
  const sidebar = document.getElementById('sidebar');
  const contentWrapper = document.getElementById('contentWrapper'); // Changed from mainContent
  const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');

  const setSidebarOpen = (isOpen) => {
   if (isOpen) {
    sidebar.classList.add('is-open');
    // Apply class to body to trigger content-wrapper margin adjustment
    document.body.classList.add('sidebar-is-open');
    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-times"></i>';
   } else {
    sidebar.classList.remove('is-open');
    // Remove class from body to revert content-wrapper margin adjustment
    document.body.classList.remove('sidebar-is-open');
    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
    // When collapsing the main sidebar, also collapse any open submenus
    document.querySelectorAll('.sidebar-nav .collapse.show').forEach(collapseElement => {
      const bsCollapse = bootstrap.Collapse.getInstance(collapseElement) || new bootstrap.Collapse(collapseElement, { toggle: false });
      bsCollapse.hide();
    });
   }
  };

  if (sidebar && contentWrapper) {
   // Desktop hover behavior
   sidebar.addEventListener('mouseenter', () => {
    if (window.innerWidth > 768) {
     setSidebarOpen(true);
    }
   });
   sidebar.addEventListener('mouseleave', () => {
    if (window.innerWidth > 768) {
     setSidebarOpen(false);
    }
   });
  }

  if (sidebarToggleBtn) {
   // Mobile click toggle behavior
   sidebarToggleBtn.addEventListener('click', function () {
    setSidebarOpen(!sidebar.classList.contains('is-open'));
   });

   // Click outside to close sidebar on mobile
   document.addEventListener('click', function (event) {
    if (window.innerWidth <= 768 && sidebar.classList.contains('is-open')) {
     if (!sidebar.contains(event.target) && !sidebarToggleBtn.contains(event.target)) {
      setSidebarOpen(false);
     }
    }
   });
  }

  // --- Active Link and Submenu Management ---
  const currentPath = window.location.pathname;
  const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

  navLinks.forEach(link => {
    // Get the link's href and extract just the filename (e.g., "dashboard.php")
    const linkHref = link.getAttribute('href');
    if (!linkHref || linkHref.startsWith('#')) return; // Skip links that are just collapse toggles

    const currentFilename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
    const linkFilename = linkHref.substring(linkHref.lastIndexOf('/') + 1);

    // Check if the link's filename matches the current page's filename
    if (linkFilename === currentFilename) {
      link.classList.add('active'); // Mark the specific item as active

      // If this active link is inside a submenu, expand its parent
      const parentCollapseDiv = link.closest('.collapse');
      if (parentCollapseDiv) {
        // Ensure the Bootstrap collapse instance is created
        const bsCollapse = bootstrap.Collapse.getInstance(parentCollapseDiv) || new bootstrap.Collapse(parentCollapseDiv, { toggle: false });
        bsCollapse.show(); // Show the collapse

        // Find the parent toggle link and update its state
        const parentToggleLink = document.querySelector(`a[data-bs-target="#${parentCollapseDiv.id}"]`);
        if (parentToggleLink) {
          parentToggleLink.classList.remove('collapsed'); // Remove collapsed class
          parentToggleLink.setAttribute('aria-expanded', 'true'); // Set expanded state for accessibility
        }
      }
      // If the current page is "dashboard.php", ensure its link is 'active'
      // and no parent collapse is incorrectly triggered.
      if (currentFilename === 'dashboard.php') {
        document.querySelector('a[href="dashboard.php"]').classList.add('active');
      }
    }
  });

  // --- Caret Icon Rotation on Collapse Events ---
  document.querySelectorAll('.sidebar-nav .collapse').forEach(collapseElement => {
    collapseElement.addEventListener('show.bs.collapse', function () {
      const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
      if (toggleLink) {
        const caretIcon = toggleLink.querySelector('.caret-icon');
        if (caretIcon) caretIcon.style.transform = 'rotate(180deg)';
        toggleLink.classList.add('active'); // Optionally activate parent link on expand
      }
    });

    collapseElement.addEventListener('hide.bs.collapse', function () {
      const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
      if (toggleLink) {
        const caretIcon = toggleLink.querySelector('.caret-icon');
        if (caretIcon) caretIcon.style.transform = 'rotate(0deg)';
         
        // Only deactivate parent link if none of its *current* sub-items are active
        const hasActiveChild = this.querySelector('.nav-link.active');
        if (!hasActiveChild) {
          toggleLink.classList.remove('active');
        }
      }
    });
  });


  // Date Filter Logic
  const dateFilterSelect = document.getElementById('date_filter');
  const customDateRangeInputs = document.querySelectorAll('.custom-date-range');
  const startDateInput = document.getElementById('custom_start_date');
  const endDateInput = document.getElementById('custom_end_date');

  function toggleCustomDateInputs() {
   if (dateFilterSelect.value === 'custom') {
    customDateRangeInputs.forEach(inputDiv => inputDiv.classList.remove('d-none'));
   } else {
    customDateRangeInputs.forEach(inputDiv => inputDiv.classList.add('d-none'));
   }
  }

  if (dateFilterSelect) {
   dateFilterSelect.addEventListener('change', toggleCustomDateInputs);
   toggleCustomDateInputs(); // Initial call to set visibility based on initial load filter
  }

  // Filter Toggle Logic (New) - MODIFIED: ALWAYS HIDDEN BY DEFAULT ON LOAD
  const filterCard = document.getElementById('filterCard');
  const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');

  if (filterCard && toggleFiltersBtn) {
   // Check if any filters are applied from URL parameters (excluding date_filter, custom_start/end).
   // This determines if the filter card should be open on load.
   const urlParams = new URLSearchParams(window.location.search);
   let filtersApplied = false;
   for (const [key, value] of urlParams.entries()) {
    if (key !== 'date_filter' && key !== 'custom_start_date' && key !== 'custom_end_date' && value) {
     // Check if it's an array parameter and has selected values other than 'all' or empty
     if (Array.isArray(urlParams.getAll(key))) { // Correctly check for array parameters
      if (urlParams.getAll(key).some(v => v !== '' && v !== 'all')) {
        filtersApplied = true;
        break;
      }
     } else if (value !== '' && value !== 'all') { // Single value parameter
       filtersApplied = true;
       break;
     }
    }
   }

   // Also check if the date filter is 'custom' and dates are provided.
   if (dateFilterSelect.value === 'custom' && startDateInput.value && endDateInput.value) {
    filtersApplied = true;
   }

   if (filtersApplied) {
    filterCard.classList.remove('d-none');
    toggleFiltersBtn.innerHTML = '<i class="fas fa-times me-2"></i> Hide Filters';
   } else {
    filterCard.classList.add('d-none');
    toggleFiltersBtn.innerHTML = '<i class="fas fa-filter me-2"></i> Show Filters';
   }

   // Add the click event listener for the toggle button
   toggleFiltersBtn.addEventListener('click', function () {
    filterCard.classList.toggle('d-none'); // Toggles visibility
    if (filterCard.classList.contains('d-none')) {
     toggleFiltersBtn.innerHTML = '<i class="fas fa-filter me-2"></i> Show Filters'; // Set to Show Filters
    } else {
     toggleFiltersBtn.innerHTML = '<i class="fas fa-times me-2"></i> Hide Filters'; // Set to Hide Filters
    }
   });
  }


  // --- Multi-Select Checkboxes Logic ---
  // Function to manage 'All' checkbox and individual checkboxes within a group
  function setupFilterGroup(groupId) {
   const group = document.getElementById(groupId);
   if (!group) return;

   const allCheckbox = group.querySelector('.filter-type-all');
   const itemCheckboxes = group.querySelectorAll('.filter-type-item');

   // Add a data attribute to the group div to store the base name (e.g., 'events', 'cities')
   group.dataset.filterName = allCheckbox.id.replace('all_', ''); // 'all_events' -> 'events'

   // Function to update the state of individual checkboxes (disabled/enabled)
   // and their 'name' attribute for submission.
   function updateItemCheckboxesNameAndDisableState() {
    itemCheckboxes.forEach(cb => {
     // Always re-add the name attribute based on the group's filterName
     cb.setAttribute('name', group.dataset.filterName + '[]');
      
     if (allCheckbox.checked) {
      cb.checked = false; // Uncheck all selected items when 'All' is selected
      cb.disabled = true; // Disable them
      // Remove name if 'all' is checked. This ensures 'all' is the effective selection.
      cb.removeAttribute('name');
     } else {
      cb.disabled = false; // Enable them
      // Name attribute already set above if 'all' is not checked.
     }
    });
   }

   // Listen for changes on 'All' checkbox
   allCheckbox.addEventListener('change', function () {
    updateItemCheckboxesNameAndDisableState();
   });

   // Listen for changes on individual item checkboxes
   itemCheckboxes.forEach(itemCheckbox => {
    itemCheckbox.addEventListener('change', function () {
     if (this.checked) {
      // If an item is checked, uncheck 'All' if it was checked
      allCheckbox.checked = false;
     } else {
      // If an item is unchecked, check if any other item is still checked.
      // If no other item is checked, then auto-check 'All'.
      const anyItemStillChecked = Array.from(itemCheckboxes).some(cb => cb.checked);
      if (!anyItemStillChecked) {
       allCheckbox.checked = true;
      }
     }
     // After any individual item change, update the disable state of *all* items based on 'All' checkbox
     updateItemCheckboxesNameAndDisableState();
    });
   });

   // Initial setup on page load: ensure correct state based on PHP pre-selection
   // If `selected_events` etc. are empty, `allCheckbox` checked by PHP.
   // If they are NOT empty, `allCheckbox` is NOT checked by PHP.
   // Then, if `allCheckbox` *is* checked on load, run update to disable items.
   // If `allCheckbox` is *not* checked on load, items are already enabled.
   // This correctly initializes the `name` attributes and disabled states.
   updateItemCheckboxesNameAndDisableState();
  }

  // Call setup for each filter group that uses checkboxes
  setupFilterGroup('events-group');
  setupFilterGroup('cities-group');
  setupFilterGroup('venues-group');
  setupFilterGroup('ticket_types-group');

  // --- Client-side search for filter checkboxes ---
  document.querySelectorAll('.filter-search-input').forEach(searchInput => {
   searchInput.addEventListener('keyup', function () {
    const searchTerm = this.value.toLowerCase();
    const targetGroupId = this.dataset.targetGroup;
    const checkboxGroup = document.querySelector(targetGroupId);

    if (checkboxGroup) {
     // Select all `.form-check` elements within the group
     const items = checkboxGroup.querySelectorAll('.form-check');
     items.forEach(item => {
      // Get the text content of the label (the visible text)
      const label = item.querySelector('.form-check-label');
      // Get the checkbox itself
      const checkbox = item.querySelector('.form-check-input');

      // Special handling for the "All" checkbox: always show it, and disable search for it
      if (checkbox && checkbox.classList.contains('filter-type-all')) {
       item.style.display = 'flex'; // Always show "All"
       return; // Skip search logic for "All"
      }

      if (label) {
       const labelText = label.textContent.toLowerCase();
       if (labelText.includes(searchTerm)) {
        item.style.display = 'flex'; // Show it
       } else {
        item.style.display = 'none'; // Hide it
       }
      }
     });
    }
   });
  });
 });
</script>
</body>
</html>