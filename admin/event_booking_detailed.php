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

if (!isset($_SESSION['user_id'])) {
    if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : '';

// Admin User ID and Username for data filtering - now from session
$adminUserId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$adminUsername = $isLoggedIn ? $_SESSION['username'] : ''; // Corresponding username

// If user_id is null (not logged in), redirect to login as per check above.
// For this particular dashboard, we need a valid adminUserId for queries to work.
if ($adminUserId === null) {
    // This case should ideally not be reached if the header redirect works,
    // but as a safeguard, explicit exit if no user ID after the redirection attempt.
    header("Location: $login_page");
    exit();
}

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
$filter_type = $date_range_result['filter_type_used']; // Update filter_type in case it fell back
$start_datetime = $date_range_result['start'];
$end_datetime = $date_range_result['end'];

// Set input values for custom date range if filter_type is 'custom' and valid, otherwise blank
$start_date_input = ($filter_type === 'custom') ? (new DateTime($start_datetime))->format('Y-m-d') : '';
$end_date_input = ($filter_type === 'custom') ? (new DateTime($end_datetime))->format('Y-m-d') : '';

// Selected filters from GET request for multi-selects
// Capture original GET values for HTML/JS display logic
$selected_events_original_get = $_GET['events'] ?? null; // Initialize as null to distinguish 'not set' from 'empty array'

// Logic to default to 'all' events for admin on initial page load (when 'events' GET param is not set)
// If the user explicitly deselects all events (e.g., ?events=in URL), then $selected_events_original_get will be an empty array
// and 'all' will NOT be added, correctly resulting in no data.
$is_initial_load = empty($_GET) || (!empty($_GET) && !isset($_GET['events']));
if ($selected_events_original_get === null && $is_initial_load) {
    $selected_events_original_get = ['all'];
} elseif (!is_array($selected_events_original_get)) {
    // If 'events' param is present but not an array (e.g., ?events=single_value), treat as empty array
    $selected_events_original_get = [];
}

$selected_cities_original_get = $_GET['cities'] ?? [];
$selected_venues_original_get = $_GET['venues'] ?? [];
$selected_ticket_types_original_get = $_GET['ticket_types'] ?? [];

// NEW FILTERS
$selected_active_bookings = $_GET['active_bookings'] ?? 'all'; // 'all', 'active', 'inactive'
$selected_checked_in_status = $_GET['checked_in_status'] ?? 'all'; // 'all', 'yes', 'no'

// Helper function to prepare multi-select parameters for SQL
function prepareMultiSelectParams(array $selection_array): array
{
    return array_filter($selection_array, function ($value) {
        return $value !== '' && $value !== 'all'; // Filter out empty strings and 'all' dummy value
    });
}

// Process GET parameters for database queries:
// Note: 'all' is explicitly handled in the SQL generation phase later,
// so here we strip it out if present for building actual lists of IDs.
$selected_events_for_query = prepareMultiSelectParams($selected_events_original_get);
$selected_cities_for_query = prepareMultiSelectParams($selected_cities_original_get);
$selected_venues_for_query = prepareMultiSelectParams($selected_venues_original_get);
$selected_ticket_types_for_query = prepareMultiSelectParams($selected_ticket_types_original_get);


// --- Fetching Filter Options for Dropdowns/Multiselects (Dynamically filtered) ---

// All Events for the admin (for the filter dropdown)
// This fetches events created by the logged-in administrator.
$all_events_stmt = $pdo->prepare("SELECT event_id, event_name, event_type, event_category FROM event_info WHERE is_active = 'yes' AND created_by = ? ORDER BY event_name");
$all_events_stmt->execute([$adminUserId]);
$all_events = $all_events_stmt->fetchAll(PDO::FETCH_ASSOC);


// If 'all' event filter was selected (from original GET params), expand $selected_events_for_query to include all admin's event_ids.
// This expansion must happen AFTER all_events data is fetched, as it relies on event IDs.
if (in_array('all', $selected_events_original_get) && !empty($all_events)) {
    $selected_events_for_query = array_column($all_events, 'event_id');
}


// Parameters for event-dependent city/venue fetching (used for populating filter options)
// These filters for options should reflect what the user has currently *chosen* in other filters.
$event_filter_params_for_options = [];
$event_filter_where_clause_for_options = "";

// IMPORTANT: If $selected_events_for_query is empty here (e.g., admin has no events created, or user explicitly unchecked all events),
// we force the SQL to return no options for cities/venues/ticket types, which is correct.
if (!empty($selected_events_for_query)) {
    $event_placeholders_for_options = implode(',', array_fill(0, count($selected_events_for_query), '?'));
    $event_filter_where_clause_for_options = " AND Ei.event_id IN ($event_placeholders_for_options)";
    $event_filter_params_for_options = $selected_events_for_query;
} else {
    $event_filter_where_clause_for_options = " AND 1 = 0"; // Force no results if no events selected/available
}


// Fetch cities - filtered by selected events (if any) and by event creator
$all_cities_sql = "
    SELECT DISTINCT C.city_id, C.city_name
    FROM cities C
    JOIN venues V ON C.city_id = V.city_id AND V.is_active = 'yes'
    JOIN event_schedule Es ON V.venue_id = Es.venue_id AND Es.is_active = 'yes'
    JOIN event_info Ei ON Es.event_id = Ei.event_id AND Ei.is_active = 'yes'
    WHERE C.is_active = 'yes'
    AND Ei.created_by = ?
    {$event_filter_where_clause_for_options}
    ORDER BY C.city_name
";

$stmt_all_cities = $pdo->prepare($all_cities_sql);
$all_cities_params = array_merge([$adminUserId], $event_filter_params_for_options);
$stmt_all_cities->execute($all_cities_params);
$all_cities = $stmt_all_cities->fetchAll(PDO::FETCH_ASSOC);


// Fetch venues - filtered by selected events AND selected cities AND by event creator
$venue_filter_params_for_options = $event_filter_params_for_options; // Start with event params
$venue_filter_where_clause_for_options = $event_filter_where_clause_for_options; // Inherit event filter

if (!empty($selected_cities_for_query)) {
    $city_placeholders_for_options = implode(',', array_fill(0, count($selected_cities_for_query), '?'));
    $venue_filter_where_clause_for_options .= " AND C.city_id IN ($city_placeholders_for_options)";
    $venue_filter_params_for_options = array_merge($venue_filter_params_for_options, $selected_cities_for_query);
}

$all_venues_sql = "
    SELECT DISTINCT V.venue_id, V.venue_name, V.sub_venue_name, C.city_name
    FROM venues V
    JOIN cities C ON V.city_id = C.city_id AND C.is_active = 'yes'
    JOIN event_schedule Es ON V.venue_id = Es.venue_id AND Es.is_active = 'yes'
    JOIN event_info Ei ON Es.event_id = Ei.event_id AND Ei.is_active = 'yes'
    WHERE V.is_active = 'yes'
    AND Ei.created_by = ?
    {$venue_filter_where_clause_for_options}
    ORDER BY V.venue_name, V.sub_venue_name
";
$stmt_all_venues = $pdo->prepare($all_venues_sql);
$all_venues_params = array_merge([$adminUserId], $venue_filter_params_for_options);
$stmt_all_venues->execute($all_venues_params);
$all_venues = $stmt_all_venues->fetchAll(PDO::FETCH_ASSOC);

// Ticket types - filtered by selected events (if any) and by event creator
$ticket_type_filter_params_for_options = [];
$ticket_type_filter_where_clause_for_options = "";

// Use the *expanded* $selected_events_for_query here
if (!empty($selected_events_for_query)) {
    $event_placeholders_tt_for_options = implode(',', array_fill(0, count($selected_events_for_query), '?'));
    $ticket_type_filter_where_clause_for_options = " AND ei.event_id IN ($event_placeholders_tt_for_options)";
    $ticket_type_filter_params_for_options = $selected_events_for_query;
} else {
    // If $selected_events_for_query is empty, force no results for ticket types as well.
    $ticket_type_filter_where_clause_for_options = " AND 1 = 0";
}

$all_ticket_types_sql = "
    SELECT DISTINCT est.ticket_type
    FROM event_schedule_tickets est
    JOIN event_schedule es ON est.event_schedule_id = es.event_schedule_id
    JOIN event_info ei ON es.event_id = ei.event_id
    WHERE ei.created_by = ? AND ei.is_active = 'yes'
    {$ticket_type_filter_where_clause_for_options}
    ORDER BY est.ticket_type
";
$stmt_all_ticket_types = $pdo->prepare($all_ticket_types_sql);
$all_ticket_types_params = array_merge([$adminUserId], $ticket_type_filter_params_for_options);
$stmt_all_ticket_types->execute($all_ticket_types_params);
$all_ticket_types = $stmt_all_ticket_types->fetchAll(PDO::FETCH_ASSOC);


// --- Build Dynamic SQL Queries - Base Components ---

// Initialize parameters and clauses using NAMED parameters
$param_counter = 0; // To generate unique named parameters for IN clauses
$params = [
    ':start_datetime' => $start_datetime,
    ':end_datetime' => $end_datetime,
    ':admin_id' => $adminUserId // Filter all main queries by the admin's owned events
];
// All core queries for bookings will include the admin's ID filter AND the date range.
$main_where_clauses = ["b.booked_at BETWEEN :start_datetime AND :end_datetime", "ei.created_by = :admin_id"];

// This array now explicitly tracks aliases needed for the main WHERE clause filters.
// 'ei' (event_info) is always needed due to the global 'created_by' filter.
$core_query_aliases = ['ei'];


// Add filters to base WHERE clause and parameters (user-selected filters)
// If $selected_events_for_query is empty AFTER it resolved 'all' (meaning no events for admin)
// OR if user explicitly deselected all events and sent an empty array for events, then force 1=0.
// This is crucial for preventing results when data doesn't match the event_id or when user chose no events.
if (empty($selected_events_for_query)) {
    $main_where_clauses[] = "1 = 0"; // Force no results as no events are selected or available for admin
} else {
    $event_placeholders = [];
    foreach ($selected_events_for_query as $event_id) {
        $param_name = ':event_id_' . $param_counter++;
        $event_placeholders[] = $param_name;
        $params[$param_name] = $event_id;
    }
    $main_where_clauses[] = "b.event_id IN (" . implode(',', $event_placeholders) . ")";
}

if (!empty($selected_cities_for_query)) {
    $city_placeholders = [];
    foreach ($selected_cities_for_query as $city_id) {
        $param_name = ':city_id_' . $param_counter++;
        $city_placeholders[] = $param_name;
        $params[$param_name] = $city_id;
    }
    $main_where_clauses[] = "c.city_id IN (" . implode(',', $city_placeholders) . ")";
    $core_query_aliases[] = 'c';
}
if (!empty($selected_venues_for_query)) {
    $venue_placeholders = [];
    foreach ($selected_venues_for_query as $venue_id) {
        $param_name = ':venue_id_' . $param_counter++;
        $venue_placeholders[] = $param_name;
        $params[$param_name] = $venue_id;
    }
    $main_where_clauses[] = "v.venue_id IN (" . implode(',', $venue_placeholders) . ")";
    $core_query_aliases[] = 'v';
}
if (!empty($selected_ticket_types_for_query)) {
    $ticket_type_placeholders = [];
    foreach ($selected_ticket_types_for_query as $ticket_type) {
        $param_name = ':ticket_type_' . $param_counter++;
        $ticket_type_placeholders[] = $param_name;
        $params[$param_name] = $ticket_type;
    }
    $main_where_clauses[] = "est.ticket_type IN (" . implode(',', $ticket_type_placeholders) . ")";
    $core_query_aliases[] = 'est';
}

// NEW FILTERS - active_bookings (vs.slot_starts_at)
if ($selected_active_bookings === 'active') {
    $main_where_clauses[] = "vs.slot_starts_at < NOW()";
    $core_query_aliases[] = 'vs'; // Ensure vs is joined
    $core_query_aliases[] = 'es'; // es needed for vs
} elseif ($selected_active_bookings === 'inactive') {
    $main_where_clauses[] = "vs.slot_starts_at >= NOW()";
    $core_query_aliases[] = 'vs'; // Ensure vs is joined
    $core_query_aliases[] = 'es'; // es needed for vs
}

// NEW FILTERS - checked_in_status (b.checked_in)
if ($selected_checked_in_status === 'yes') {
    $main_where_clauses[] = "b.checked_in = 'yes'";
} elseif ($selected_checked_in_status === 'no') {
    $main_where_clauses[] = "b.checked_in = 'no'";
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
        'est' => ['clause' => "JOIN event_schedule_tickets est ON {$base_table_alias}.ticket_id = est.ticket_id", 'dependencies' => []],
        'es' => ['clause' => "JOIN event_schedule es ON est.event_schedule_id = es.event_schedule_id", 'dependencies' => ['est']],
        'v' => ['clause' => "JOIN venues v ON es.venue_id = v.venue_id", 'dependencies' => ['es']],
        'vs' => ['clause' => "JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id", 'dependencies' => ['es']],
        'c' => ['clause' => "JOIN cities c ON v.city_id = c.city_id", 'dependencies' => ['v']],
        'ei' => ['clause' => "JOIN event_info ei ON {$base_table_alias}.event_id = ei.event_id", 'dependencies' => []],
        'pc' => ['clause' => "LEFT JOIN promo_codes pc ON {$base_table_alias}.code_id = pc.code_id", 'dependencies' => []],
        'u' => ['clause' => "LEFT JOIN users u ON {$base_table_alias}.booked_by = u.userid", 'dependencies' => []],
        'rev_i' => ['clause' => "LEFT JOIN revenue_info rev_i ON {$base_table_alias}.booking_ref = rev_i.booking_ref", 'dependencies' => []],
        'comm_i' => ['clause' => "LEFT JOIN commission_info comm_i ON rev_i.commission_id = comm_i.commission_id", 'dependencies' => ['rev_i']],
    ];

    // Build the list of joins ensuring dependencies are met
    $queue_to_process = array_unique($requested_aliases);
    $ordered_joins = [];

    // Helper to add an alias and its dependencies if not already added
    $add_alias_and_dependencies = function ($alias) use (&$ordered_joins, &$added_aliases_set, $join_definitions, &$add_alias_and_dependencies) {
        if (!isset($join_definitions[$alias]) || isset($added_aliases_set[$alias])) {
            return; // Already added or not a defined alias
        }
        // First add dependencies
        foreach ($join_definitions[$alias]['dependencies'] as $dep) {
            $add_alias_and_dependencies($dep);
        }
        // Then add the current alias if not already in the set
        if (!isset($added_aliases_set[$alias])) {
            $ordered_joins[] = $join_definitions[$alias]['clause'];
            $added_aliases_set[$alias] = true;
        }
    };

    // Process the requested aliases
    foreach ($queue_to_process as $alias) {
        $add_alias_and_dependencies($alias);
    }

    // Sort the join clauses by a predefined order to ensure consistency and correct dependencies
    $final_ordered_aliases_sequence = ['ei', 'est', 'es', 'vs', 'v', 'c', 'pc', 'u', 'rev_i', 'comm_i']; // Ordered for logical flow
    usort($ordered_joins, function ($a, $b) use ($final_ordered_aliases_sequence) {
        // Extract alias from join clause string (e.g., "JOIN table alias ON...")
        preg_match('/\s(\w+)\sON/', $a, $matches_a);
        $alias_a = $matches_a[1] ?? '';
        preg_match('/\s(\w+)\sON/', $b, $matches_b);
        $alias_b = $matches_b[1] ?? '';

        $pos_a = array_search($alias_a, $final_ordered_aliases_sequence);
        $pos_b = array_search($alias_b, $final_ordered_aliases_sequence);

        // Handle cases where aliases might not be in the predefined sequence
        if ($pos_a === false && $pos_b === false) return 0;
        if ($pos_a === false) return 1; // $a is not in sequence, put it later
        if ($pos_b === false) return -1; // $b is not in sequence, put it later

        return $pos_a - $pos_b; // Sort by their position in the defined sequence
    });

    return implode("\n", $ordered_joins);
}


// Fetch commission structure for calculations (not directly used for revenue calculation anymore, informational)
$commission_rates = [];
$stmt_commissions = $pdo->query("SELECT commission_id, commission_name, commission_percent FROM commission_info");
while ($row = $stmt_commissions->fetch(PDO::FETCH_ASSOC)) {
    $commission_rates[$row['commission_id']] = [
        'name' => $row['commission_name'],
        'percent' => $row['commission_percent']
    ];
}


// --- Aggregate Data for Event-wise Breakdown (Accordion) ---

// Get total defined tickets capacity for each event (pre-filter)
$event_total_tickets_capacity = [];
$capacity_event_filter = "";
$capacity_param_counter_for_capacity = 0;
$capacity_params_for_query = [':admin_id_cap' => $adminUserId]; // Dedicated params for capacity query

// Use the *expanded* $selected_events_for_query here
// Similar to the main query, if no events are selected for query (e.g., user explicitly unchecked all),
// force no capacity results.
if (!empty($selected_events_for_query)) {
    $capacity_placeholders_events = []; // Use named placeholders for capacity query
    foreach ($selected_events_for_query as $event_id) {
        $param_name = ':event_id_cap_' . $capacity_param_counter_for_capacity++;
        $capacity_placeholders_events[] = $param_name;
        $capacity_params_for_query[$param_name] = $event_id;
    }
    $capacity_event_filter = " AND ei.event_id IN (" . implode(',', $capacity_placeholders_events) . ")";
} else {
    $capacity_event_filter = " AND 1 = 0"; // Return no capacity if no events selected/available
}

$capacity_sql = "
    SELECT
        ei.event_id,
        COUNT(est.ticket_id) AS total_capacity
    FROM event_info ei
    JOIN event_schedule es ON ei.event_id = es.event_id AND es.is_active = 'yes'
    JOIN event_schedule_tickets est ON es.event_schedule_id = est.event_schedule_id AND est.is_active = 'yes'
    WHERE ei.created_by = :admin_id_cap
    AND ei.is_active = 'yes'
    {$capacity_event_filter}
    GROUP BY ei.event_id
";
$stmt_capacity = $pdo->prepare($capacity_sql);
$stmt_capacity->execute($capacity_params_for_query);
while ($row = $stmt_capacity->fetch(PDO::FETCH_ASSOC)) {
    $event_total_tickets_capacity[$row['event_id']] = $row['total_capacity'];
}

// Initialize event_wise_data structure
$event_wise_data = [];

// Query 1: Overall Event Summaries (Total Gross, Net, Discount, Tickets Sold, Unique Bookings)
$event_summary_sql = "
SELECT
    ei.event_id,
    ei.event_name,
    SUM(b.booking_amt) AS total_gross_revenue,
    SUM(b.total_amt) AS total_net_revenue,
    (SUM(b.booking_amt) - SUM(b.total_amt)) AS total_discount,
    COUNT(b.ticket_id) AS total_tickets_sold,
    COUNT(DISTINCT b.booking_ref) AS unique_bookings_count
FROM bookings b
" . get_final_joins_revised('b', array_merge($core_query_aliases)) . "
$combined_where_sql
GROUP BY ei.event_id, ei.event_name
ORDER BY ei.event_name;
";
$stmt_event_summary = $pdo->prepare($event_summary_sql);
$stmt_event_summary->execute($params);
$event_summaries = $stmt_event_summary->fetchAll(PDO::FETCH_ASSOC);

foreach ($event_summaries as $summary) {
    $eventId = $summary['event_id'];
    $event_wise_data[$eventId] = [
        'event_name' => $summary['event_name'],
        'total_gross_revenue' => $summary['total_gross_revenue'],
        'total_net_revenue' => $summary['total_net_revenue'],
        'total_discount' => $summary['total_discount'],
        'unique_bookings' => $summary['unique_bookings_count'],
        'total_tickets_sold' => $summary['total_tickets_sold'],
        'total_capacity' => $event_total_tickets_capacity[$eventId] ?? 'N/A',
        'platform_fee' => 0, // Will be filled from separate query
        'host_commission' => 0, // Will be filled from separate query
        'creator_revenue' => 0, // Will be filled from separate query
        'ticket_type_breakdown' => [],
        'promo_code_breakdown' => [],
        'payment_method_breakdown' => []
    ];
}

// Query 2: Revenue Breakdown per Event (Platform Fee, Host Fee, Creator Revenue)
// This query is optimized to accurately sum revenue_amt from revenue_info
// by first filtering the relevant booking_refs using a subquery based on all applied filters.
$revenue_breakdown_sql = "
SELECT
    fb.event_id,
    comm_i.commission_name,
    SUM(rev_i.revenue_amt) AS revenue_amount
FROM revenue_info rev_i
JOIN commission_info comm_i ON rev_i.commission_id = comm_i.commission_id
JOIN (
    -- Subquery to get all unique booking_refs and their corresponding event_id
    -- that meet the overall filtering criteria (date, event, city, etc.)
    SELECT DISTINCT b_filtered.booking_ref, ei.event_id
    FROM bookings b_filtered
    " . get_final_joins_revised('b_filtered', $core_query_aliases) . "
    " . str_replace('b.', 'b_filtered.', $combined_where_sql) . "
) AS fb ON rev_i.booking_ref = fb.booking_ref -- Directly join revenue_info to the filtered booking_refs
GROUP BY fb.event_id, comm_i.commission_name;
";
$stmt_revenue_breakdown = $pdo->prepare($revenue_breakdown_sql);
$stmt_revenue_breakdown->execute($params);
$revenue_breakdowns = $stmt_revenue_breakdown->fetchAll(PDO::FETCH_ASSOC);

foreach ($revenue_breakdowns as $row) {
    $eventId = $row['event_id'];
    if (isset($event_wise_data[$eventId])) {
        switch ($row['commission_name']) {
            case 'Platform Fee':
                $event_wise_data[$eventId]['platform_fee'] = $row['revenue_amount'];
                break;
            case 'Host Fee':
                $event_wise_data[$eventId]['host_commission'] = $row['revenue_amount'];
                break;
            case 'Creator Revenue':
                $event_wise_data[$eventId]['creator_revenue'] = $row['revenue_amount'];
                break;
        }
    }
}

// Query 3: Ticket Type Breakdown per Event
$ticket_type_breakdown_sql = "
SELECT
    ei.event_id,
    est.ticket_type,
    COUNT(b.ticket_id) AS tickets_sold,
    SUM(b.total_amt) AS net_revenue,
    SUM(b.booking_amt) AS gross_revenue
FROM bookings b
" . get_final_joins_revised('b', array_merge($core_query_aliases, ['est'])) . "
$combined_where_sql
GROUP BY ei.event_id, est.ticket_type
ORDER BY ei.event_id, est.ticket_type;
";
$stmt_ticket_type_breakdown = $pdo->prepare($ticket_type_breakdown_sql);
$stmt_ticket_type_breakdown->execute($params);
$ticket_type_breakdowns = $stmt_ticket_type_breakdown->fetchAll(PDO::FETCH_ASSOC);

foreach ($ticket_type_breakdowns as $row) {
    $eventId = $row['event_id'];
    if (isset($event_wise_data[$eventId])) {
        $event_wise_data[$eventId]['ticket_type_breakdown'][$row['ticket_type']] = [
            'tickets_sold' => $row['tickets_sold'],
            'net_revenue' => $row['net_revenue'],
            'gross_revenue' => $row['gross_revenue']
        ];
    }
}

// Query 4: Promo Code Breakdown per Event
$promo_code_breakdown_sql = "
SELECT
    ei.event_id,
    COALESCE(pc.code, 'N/A') AS promo_code,
    COUNT(b.ticket_id) AS tickets_used,
    SUM(b.booking_amt - b.total_amt) AS discount_amount
FROM bookings b
" . get_final_joins_revised('b', array_merge($core_query_aliases, ['pc'])) . "
$combined_where_sql
GROUP BY ei.event_id, promo_code -- Group by COALESCE(pc.code, 'N/A') or just promo_code as alias
ORDER BY ei.event_id, promo_code;
";
$stmt_promo_code_breakdown = $pdo->prepare($promo_code_breakdown_sql);
$stmt_promo_code_breakdown->execute($params);
$promo_code_breakdowns = $stmt_promo_code_breakdown->fetchAll(PDO::FETCH_ASSOC);

foreach ($promo_code_breakdowns as $row) {
    $eventId = $row['event_id'];
    if (isset($event_wise_data[$eventId])) {
        $event_wise_data[$eventId]['promo_code_breakdown'][$row['promo_code']] = [
            'tickets_used' => $row['tickets_used'],
            'discount_amount' => $row['discount_amount']
        ];
    }
}

// Query 5: Payment Method Breakdown per Event
$payment_method_breakdown_sql = "
SELECT
    ei.event_id,
    b.payment_method,
    COUNT(b.ticket_id) AS tickets_count,
    SUM(b.total_amt) AS net_revenue_sum
FROM bookings b
" . get_final_joins_revised('b', array_merge($core_query_aliases)) . "
$combined_where_sql
GROUP BY ei.event_id, b.payment_method
ORDER BY ei.event_id, b.payment_method;
";
$stmt_payment_method_breakdown = $pdo->prepare($payment_method_breakdown_sql);
$stmt_payment_method_breakdown->execute($params);
$payment_method_breakdowns = $stmt_payment_method_breakdown->fetchAll(PDO::FETCH_ASSOC);

foreach ($payment_method_breakdowns as $row) {
    $eventId = $row['event_id'];
    if (isset($event_wise_data[$eventId])) {
        $event_wise_data[$eventId]['payment_method_breakdown'][$row['payment_method']] = [
            'tickets_count' => $row['tickets_count'],
            'net_revenue_sum' => $row['net_revenue_sum']
        ];
    }
}

// Order events by name for display (optional)
if (!empty($event_wise_data)) {
    uasort($event_wise_data, function($a, $b) { // uasort preserves keys for proper structure
        return strcmp($a['event_name'], $b['event_name']);
    });
}


// --- Detailed Booking Records (Paginated Table) ---
$records_per_page = 15;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
// Ensure current_page is at least 1
if ($current_page < 1) {
    $current_page = 1;
}
$offset = ($current_page - 1) * $records_per_page;

// Build params for count query (uses named parameters)
// Use the exact same logic as for the main $params array to ensure consistency.
$count_param_counter_for_count = 0;
$count_params = [
    ':count_start_datetime' => $start_datetime,
    ':count_end_datetime' => $end_datetime,
    ':count_admin_id' => $adminUserId
];
$count_where_clauses = ["b.booked_at BETWEEN :count_start_datetime AND :count_end_datetime", "ei.created_by = :count_admin_id"];
$count_core_query_aliases = ['ei'];

// Use the same logic for events filtering as main query
if (empty($selected_events_for_query)) {
    $count_where_clauses[] = "1 = 0"; // Force no results
} else {
    $event_placeholders_count = [];
    foreach ($selected_events_for_query as $event_id) {
        $param_name = ':count_event_id_' . $count_param_counter_for_count++;
        $event_placeholders_count[] = $param_name;
        $count_params[$param_name] = $event_id;
    }
    $count_where_clauses[] = "b.event_id IN (" . implode(',', $event_placeholders_count) . ")";
}

if (!empty($selected_cities_for_query)) {
    $city_placeholders_count = [];
    foreach ($selected_cities_for_query as $city_id) {
        $param_name = ':count_city_id_' . $count_param_counter_for_count++;
        $city_placeholders_count[] = $param_name;
        $count_params[$param_name] = $city_id;
    }
    $count_where_clauses[] = "c.city_id IN (" . implode(',', $city_placeholders_count) . ")";
    $count_core_query_aliases[] = 'c';
}
if (!empty($selected_venues_for_query)) {
    $venue_placeholders_count = [];
    foreach ($selected_venues_for_query as $venue_id) {
        $param_name = ':count_venue_id_' . $count_param_counter_for_count++;
        $venue_placeholders_count[] = $param_name;
        $count_params[$param_name] = $venue_id;
    }
    $count_where_clauses[] = "v.venue_id IN (" . implode(',', $venue_placeholders_count) . ")";
    $count_core_query_aliases[] = 'v';
}
if (!empty($selected_ticket_types_for_query)) {
    $ticket_type_placeholders_count = [];
    foreach ($selected_ticket_types_for_query as $ticket_type) {
        $param_name = ':count_ticket_type_' . $count_param_counter_for_count++;
        $ticket_type_placeholders_count[] = $param_name;
        $count_params[$param_name] = $ticket_type;
    }
    $count_where_clauses[] = "est.ticket_type IN (" . implode(',', $ticket_type_placeholders_count) . ")";
    $count_core_query_aliases[] = 'est';
}

// NEW FILTERS - active_bookings (vs.slot_starts_at) for count query
if ($selected_active_bookings === 'active') {
    $count_where_clauses[] = "vs.slot_starts_at < NOW()";
    $count_core_query_aliases[] = 'vs';
    $count_core_query_aliases[] = 'es';
} elseif ($selected_active_bookings === 'inactive') {
    $count_where_clauses[] = "vs.slot_starts_at >= NOW()";
    $count_core_query_aliases[] = 'vs';
    $count_core_query_aliases[] = 'es';
}

// NEW FILTERS - checked_in_status (b.checked_in) for count query
if ($selected_checked_in_status === 'yes') {
    $count_where_clauses[] = "b.checked_in = 'yes'";
} elseif ($selected_checked_in_status === 'no') {
    $count_where_clauses[] = "b.checked_in = 'no'";
}

$count_combined_where_sql = "WHERE " . implode(' AND ', $count_where_clauses);


// Query to get total count for pagination
$count_sql = "
    SELECT COUNT(DISTINCT b.booking_ref) as total_bookings
    FROM bookings b
    " . get_final_joins_revised('b', array_unique($count_core_query_aliases)) . "
    $count_combined_where_sql
";
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($count_params); // Pass named parameters to count query
$total_bookings_count = $stmt_count->fetchColumn();


$total_pages = ceil($total_bookings_count / $records_per_page);
// Adjust current_page if it's too high for the actual total_pages
if ($total_pages > 0 && $current_page > $total_pages ) { // Check total_pages > 0 to avoid current_page becoming 0 if total_pages is 0
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $records_per_page; // Recalculate offset for adjusted page
} else if ($total_pages === 0) {
    $current_page = 1;
    $offset = 0;
}

// Detailed Booking Records SQL Query
$detailed_records_sql = "
SELECT
    main_agg.booking_ref,
    main_agg.booking_gross_sum_for_ref,
    main_agg.booking_net_sum_for_ref,
    main_agg.total_discount_for_ref,
    main_agg.booking_time,
    main_agg.payment_method,
    main_agg.checked_in,
    main_agg.event_name,
    main_agg.event_id,
    main_agg.venue_name,
    main_agg.sub_venue_name,
    main_agg.slot_starts_at,
    main_agg.slot_ends_at,
    main_agg.user_username,
    ticket_counts.ticket_types_and_counts,
    COALESCE(revenue_summary.platform_revenue, 0) AS platform_revenue,
    COALESCE(revenue_summary.host_revenue, 0) AS host_revenue,
    COALESCE(revenue_summary.creator_revenue, 0) AS creator_revenue
FROM (
    SELECT
        b.booking_ref,
        SUM(b.booking_amt) AS booking_gross_sum_for_ref,
        SUM(b.total_amt) AS booking_net_sum_for_ref,
        (SUM(b.booking_amt) - SUM(b.total_amt)) AS total_discount_for_ref,
        MIN(b.booked_at) AS booking_time,
        MIN(b.payment_method) AS payment_method,
        MIN(b.checked_in) AS checked_in,
        MIN(ei.event_name) AS event_name,
        MIN(ei.event_id) AS event_id,
        MIN(v.venue_name) AS venue_name,
        MIN(v.sub_venue_name) AS sub_venue_name,
        MIN(vs.slot_starts_at) AS slot_starts_at,
        MIN(vs.slot_ends_at) AS slot_ends_at,
        MIN(u.name) AS user_username
    FROM bookings b
    " . get_final_joins_revised('b', array_merge($core_query_aliases, ['u', 'vs', 'v'])) . "
    $combined_where_sql
    GROUP BY b.booking_ref
) AS main_agg

INNER JOIN ( /* Aggregate ticket types by count for each booking_ref */
    SELECT
        agg_tt.booking_ref,
        GROUP_CONCAT(
            CONCAT(agg_tt.ticket_type, ' (', agg_tt.ticket_count, ')')
            ORDER BY agg_tt.ticket_type ASC
            SEPARATOR '; '
        ) AS ticket_types_and_counts
    FROM (
        -- Inner subquery to count tickets per booking_ref and ticket_type
        SELECT
            b_inner.booking_ref,
            est_inner.ticket_type,
            COUNT(b_inner.ticket_id) AS ticket_count
        FROM bookings b_inner
        INNER JOIN event_schedule_tickets est_inner ON b_inner.ticket_id = est_inner.ticket_id AND est_inner.is_active = 'yes'
        " . get_final_joins_revised('b_inner', $core_query_aliases) . " -- Use custom joins for b_inner to apply filters
        " . str_replace('b.', 'b_inner.', $combined_where_sql) . " -- Apply the same filters, adjusting aliases to b_inner
        GROUP BY b_inner.booking_ref, est_inner.ticket_type
    ) AS agg_tt
    GROUP BY agg_tt.booking_ref
) AS ticket_counts ON main_agg.booking_ref = ticket_counts.booking_ref

-- Aggregate all revenue types per booking_ref (corrected for accurate sum)
LEFT JOIN (
    SELECT
        rev_i.booking_ref,
        SUM(CASE WHEN comm_i.commission_name = 'Platform Fee' THEN rev_i.revenue_amt ELSE 0 END) AS platform_revenue,
        SUM(CASE WHEN comm_i.commission_name = 'Host Fee' THEN rev_i.revenue_amt ELSE 0 END) AS host_revenue,
        SUM(CASE WHEN comm_i.commission_name = 'Creator Revenue' THEN rev_i.revenue_amt ELSE 0 END) AS creator_revenue
    FROM revenue_info rev_i
    JOIN commission_info comm_i ON rev_i.commission_id = comm_i.commission_id
    GROUP BY rev_i.booking_ref
) AS revenue_summary ON main_agg.booking_ref = revenue_summary.booking_ref

ORDER BY main_agg.booking_time DESC
LIMIT " . (int)$records_per_page . " OFFSET " . (int)$offset . "
";

$stmt_detailed_records = $pdo->prepare($detailed_records_sql);
$execution_params_for_detailed = $params; // $params already contains all the filter named params
$stmt_detailed_records->execute($execution_params_for_detailed);
$paginated_detailed_bookings = $stmt_detailed_records->fetchAll(PDO::FETCH_ASSOC);


// Clear message after display (not typically used in this context but good practice)
if (isset($_SESSION['message'])) {
    unset($_SESSION['message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Event Bookings - Catchify</title>
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

        /* Sidebar Styling */
        .sidebar {
            width: var(--sidebar-width-collapsed);
            background-color: var(--nav-dark);
            color: var(--nav-text);
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

        /* Accordion (Event Breakdown) Styling */
        .accordion-item {
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            color: var(--text-color);
        }
        .accordion-header .accordion-button {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            padding: 15px 20px;
            font-weight: bold;
            border-radius: 8px; /* Slightly increase radius for a softer look */
        }
        .accordion-header .accordion-button:not(.collapsed) {
            background-color: var(--primary-bg); /* Darker when open */
            color: var(--accent-orange);
            box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid var(--card-border);
        }
        .accordion-header .accordion-button:focus {
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
            border-color: var(--accent-orange);
        }
        .accordion-body {
            background-color: var(--primary-bg); /* Deeper dark for body */
            color: var(--text-color);
            padding: 20px;
        }
        .accordion-flush .accordion-header .accordion-button {
            border-bottom: 0; /* Remove border between header and body if flush type is applied somehow */
        }
        .accordion-flush .accordion-item {
            border-right: 0;
            border-left: 0;
            border-radius: 0;
        }
        .accordion-flush .accordion-item:first-child {
            border-top: 0;
        }
        .accordion-flush .accordion-item:last-child {
            border-bottom: 0;
        }

        /* nested lists in accordion */
        .accordion-body ul {
            list-style: none; /* Remove default list bullets */
            padding-left: 0;
            margin-bottom: 15px;
        }
        .accordion-body ul li {
            padding: 5px 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.08); /* Subtle separator */
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .accordion-body ul li:last-child {
            border-bottom: none;
        }
        .accordion-body ul li span:first-child {
            font-weight: normal;
            color: var(--light-text-color);
        }
        .accordion-body ul li span:nth-child(2) {
            font-weight: bold;
            color: var(--text-color);
        }
        .accordion-body strong {
            color: var(--accent-peach); /* Consistent for strong elements */
        }

        /* Pagination styles */
        .pagination .page-item .page-link {
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
            color: var(--text-color);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--accent-red);
            border-color: var(--accent-red);
            color: white;
        }
        .pagination .page-item .page-link:hover {
            background-color: rgba(255, 107, 53, 0.25);
            color: var(--text-color);
        }
        .pagination .page-item.disabled .page-link {
            background-color: var(--primary-bg);
            border-color: var(--card-border);
            color: var(--light-text-color);
        }

        /* Responsive Adjustments (from dashboard.php) */
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
            <h2 class="section-title">My Event Bookings Breakdown</h2>
            <div class="d-flex justify-content-end mb-4">
                <button class="btn btn-outline-secondary" id="toggleFiltersBtn">
                    <i class="fas fa-filter me-2"></i> Show Filters
                </button>
            </div>

            <!-- Filter Form -->
            <div class="card p-4 mb-4 d-none" id="filterCard" style="background-color: var(--secondary-bg); border: 1px solid var(--card-border);">
                <h4 class="mb-3 text-white">Apply Filters for My Events</h4>
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
                            <div class="filter-checkbox-group" id="events-group" data-filter-name="events">
                                <div class="form-check">
                                    <input class="form-check-input filter-type-all" type="checkbox" id="all_events" value="all"
                                    <?= (in_array('all', $selected_events_original_get)) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="all_events">All My Events</label>
                                </div>
                                <?php foreach ($all_events as $event): ?>
                                    <div class="form-check">
                                        <input class="form-check-input filter-type-item" type="checkbox" id="event_<?= htmlspecialchars($event['event_id']) ?>" name="events[]" value="<?= htmlspecialchars($event['event_id']) ?>"
                                        <?= in_array($event['event_id'], $selected_events_original_get) ? 'checked' : '' ?>>
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
                            <div class="filter-checkbox-group" id="cities-group" data-filter-name="cities">
                                <div class="form-check">
                                    <input class="form-check-input filter-type-all" type="checkbox" id="all_cities" value="all"
                                    <?= (empty($selected_cities_original_get) || in_array('all', $selected_cities_original_get)) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="all_cities">All Cities for My Events</label>
                                </div>
                                <?php foreach ($all_cities as $city): ?>
                                    <div class="form-check">
                                        <input class="form-check-input filter-type-item" type="checkbox" id="city_<?= htmlspecialchars($city['city_id']) ?>" name="cities[]" value="<?= htmlspecialchars($city['city_id']) ?>"
                                        <?= in_array($city['city_id'], $selected_cities_original_get) ? 'checked' : '' ?>>
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
                            <div class="filter-checkbox-group" id="venues-group" data-filter-name="venues">
                                <div class="form-check">
                                    <input class="form-check-input filter-type-all" type="checkbox" id="all_venues" value="all"
                                    <?= (empty($selected_venues_original_get) || in_array('all', $selected_venues_original_get)) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="all_venues">All Venues for My Events</label>
                                </div>
                                <?php foreach ($all_venues as $venue): ?>
                                    <div class="form-check">
                                        <input class="form-check-input filter-type-item" type="checkbox" id="venue_<?= htmlspecialchars($venue['venue_id']) ?>" name="venues[]" value="<?= htmlspecialchars($venue['venue_id']) ?>"
                                        <?= in_array($venue['venue_id'], $selected_venues_original_get) ? 'checked' : '' ?>>
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
                            <div class="filter-checkbox-group" id="ticket_types-group" data-filter-name="ticket_types">
                                <div class="form-check">
                                    <input class="form-check-input filter-type-all" type="checkbox" id="all_ticket_types" value="all"
                                    <?= (empty($selected_ticket_types_original_get) || in_array('all', $selected_ticket_types_original_get)) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="all_ticket_types">All Ticket Types for My Events</label>
                                </div>
                                <?php foreach ($all_ticket_types as $type): ?>
                                    <div class="form-check">
                                        <input class="form-check-input filter-type-item" type="checkbox" id="ticket_type_<?= htmlspecialchars($type['ticket_type']) ?>" name="ticket_types[]" value="<?= htmlspecialchars($type['ticket_type']) ?>"
                                        <?= in_array($type['ticket_type'], $selected_ticket_types_original_get) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="ticket_type_<?= htmlspecialchars($type['ticket_type']) ?>">
                                            <?= htmlspecialchars($type['ticket_type']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- New Filters: Active Bookings and Checked In Status -->
                        <div class="col-md-6 col-lg-3 filter-group-spacing">
                            <label for="active_bookings" class="form-label text-white">Event Slot Status:</label>
                            <select class="form-select" id="active_bookings" name="active_bookings">
                                <option value="all" <?= $selected_active_bookings == 'all' ? 'selected' : '' ?>>All Slots</option>
                                <option value="active" <?= $selected_active_bookings == 'active' ? 'selected' : '' ?>>Current/Past Slots</option>
                                <option value="inactive" <?= $selected_active_bookings == 'inactive' ? 'selected' : '' ?>>Future Slots</option>
                            </select>
                        </div>

                        <div class="col-md-6 col-lg-3 filter-group-spacing">
                            <label for="checked_in_status" class="form-label text-white">Checked In Status:</label>
                            <select class="form-select" id="checked_in_status" name="checked_in_status">
                                <option value="all" <?= $selected_checked_in_status == 'all' ? 'selected' : '' ?>>All Bookings</option>
                                <option value="yes" <?= $selected_checked_in_status == 'yes' ? 'selected' : '' ?>>Checked In</option>
                                <option value="no" <?= $selected_checked_in_status == 'no' ? 'selected' : '' ?>>Not Checked In</option>
                            </select>
                        </div>

                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply Filters</button>
                            <a href="event_booking_detailed.php" class="btn btn-secondary ms-2"><i class="fas fa-sync-alt me-2"></i>Reset Filters</a>
                        </div>
                    </div>
                </form>
            </div>

            <h3 class="section-subtitle">Event-Wise Booking & Revenue Breakdown (My Events)</h3>
            <?php if (empty($event_wise_data)): ?>
                <div class="alert alert-info">No booking data found for your events matching the current filters. Please verify your filter selections.<br>
                    <small><strong>Tip:</strong> This dashboard displays data only for events created by you (User ID `<?= htmlspecialchars($adminUserId) ?>`). If your sample data has bookings for events not created by this user, they will not appear here. Consider creating new demo bookings for events owned by User ID `<?= htmlspecialchars($adminUserId) ?>` if you expect to see data.</small></div>
            <?php else: ?>
                <div class="accordion accordion-flush" id="eventsBreakdownAccordion">
                    <?php $accordionId = 0; ?>
                    <?php foreach ($event_wise_data as $eventId => $data): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading<?= $accordionId ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $accordionId ?>" aria-expanded="false" aria-controls="collapse<?= $accordionId ?>">
                                    <div class="d-flex w-100 align-items-center">
                                        <span class="flex-shrink-0 me-3 text-start"><?= htmlspecialchars($data['event_name']) ?></span>
                                        <div class="d-flex flex-wrap flex-fill justify-content-end align-items-center gap-3">
                                            <span class="text-nowrap">Tickets Sold: <strong><?= number_format($data['total_tickets_sold']) ?></strong> / Allocated: <?= $data['total_capacity'] != 'N/A' ? number_format($data['total_capacity']) : 'N/A' ?></span>
                                            <span class="text-nowrap">Net Revenue: <strong><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['total_net_revenue'], 0) ?></strong></span>
                                            <span class="text-nowrap">Plat Fee: <strong><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['platform_fee'], 0) ?></strong></span>
                                            <span class="text-nowrap">Host Comm: <strong><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['host_commission'], 0) ?></strong></span>
                                            <span class="text-nowrap">Creator Rev: <strong><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['creator_revenue'], 0) ?></strong></span>
                                        </div>
                                    </div>
                                </button>
                            </h2>
                            <div id="collapse<?= $accordionId ?>" class="accordion-collapse collapse" aria-labelledby="heading<?= $accordionId ?>" data-bs-parent="#eventsBreakdownAccordion">
                                <div class="accordion-body">
                                    <p><strong>Overall Event Summary:</strong></p>
                                    <ul>
                                        <li>Total Unique Bookings: <span><?= number_format($data['unique_bookings']) ?></span></li>
                                        <li>Tickets Sold: <span><?= number_format($data['total_tickets_sold']) ?></span></li>
                                        <li>Total Gross Revenue: <span><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['total_gross_revenue'], 2) ?></span></li>
                                        <li>Total Net Revenue (After Discounts): <span><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['total_net_revenue'], 2) ?></span></li>
                                        <li>Total Discount Given: <span><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['total_discount'], 2) ?></span></li>
                                        <li>Platform Fee: <span><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['platform_fee'], 2) ?></span></li>
                                        <li>Host Commission: <span><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['host_commission'], 2) ?></span></li>
                                        <li>Creator Revenue: <span><i class="fas fa-indian-rupee-sign"></i> <?= number_format($data['creator_revenue'], 2) ?></span></li>
                                    </ul>

                                    <p class="mt-4"><strong>Ticket Type Breakdown:</strong></p>
                                    <?php if (empty($data['ticket_type_breakdown'])): ?>
                                        <p class="text-muted">No tickets sold by type for this event.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-dark table-striped table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Ticket Type</th>
                                                        <th>Tickets Sold</th>
                                                        <th>Gross Revenue</th>
                                                        <th>Net Revenue</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($data['ticket_type_breakdown'] as $ticketType => $ttData): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($ticketType) ?></td>
                                                            <td><?= number_format($ttData['tickets_sold']) ?></td>
                                                            <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($ttData['gross_revenue'], 2) ?></td>
                                                            <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($ttData['net_revenue'], 2) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>

                                    <p class="mt-4"><strong>Promo Code Usage for this Event:</strong></p>
                                    <?php
                                    // Filter out 'N/A' promo code if its discount and tickets are 0 (meaning no actual promo applied)
                                    $filtered_promo_codes = array_filter($data['promo_code_breakdown'], function($p_code_data, $promo_key) {
                                        return $promo_key !== 'N/A' || $p_code_data['tickets_used'] > 0 || $p_code_data['discount_amount'] > 0;
                                    }, ARRAY_FILTER_USE_BOTH);

                                    if (empty($filtered_promo_codes)): ?>
                                        <p class="text-muted">No promo codes used for this event.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-dark table-striped table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Promo Code</th>
                                                        <th>Tickets Used</th>
                                                        <th>Total Discount</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($filtered_promo_codes as $promoCode => $pcData): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($promoCode) ?></td>
                                                            <td><?= number_format($pcData['tickets_used']) ?></td>
                                                            <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($pcData['discount_amount'], 2) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>

                                    <!-- NEW: Payment Method Breakdown -->
                                    <p class="mt-4"><strong>Payment Method Breakdown:</strong></p>
                                    <?php if (empty($data['payment_method_breakdown'])): ?>
                                        <p class="text-muted">No payment methods recorded for this event.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-dark table-striped table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Payment Method</th>
                                                        <th>Bookings Count</th>
                                                        <th>Net Revenue</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($data['payment_method_breakdown'] as $method => $pmData): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($method) ?></td>
                                                            <td><?= number_format($pmData['tickets_count']) ?></td>
                                                            <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($pmData['net_revenue_sum'], 2) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                    <!-- END NEW: Payment Method Breakdown -->

                                </div>
                            </div>
                        </div>
                        <?php $accordionId++; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 class="section-subtitle">Detailed Booking Records</h3>
            <p class="mb-3 text-white">Total Booking References: <strong><?= number_format($total_bookings_count) ?></strong></p>
            <?php if (empty($paginated_detailed_bookings)): ?>
                <div class="alert alert-info">No detailed booking records found for the current filters. <br>
                    <small><strong>Tip:</strong> The detailed records also respect the "My Events" filter. If no bookings are shown here, it's likely due to the same data issues as above, or the combination of the new 'Event Slot Status' and 'Checked In Status' filters. Please adjust your filters or ensure relevant bookings exist for your events.</small></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Event Name</th>
                                <th>Tickets Info</th>
                                <th>Gross Val</th>
                                <th>Net Val</th>
                                <th>Discount</th>
                                <th>Payment Method</th>
                                <th>Platform Revenue</th>
                                <th>Host Revenue</th>
                                <th>Creator Revenue</th>
                                <th>Venue</th>
                                <th>Schedule</th>
                                <th>Booked At</th>
                                <th>Booked By</th>
                                <th>Checked In</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paginated_detailed_bookings as $booking): ?>
                                <tr>
                                    <td><?= htmlspecialchars($booking['booking_ref']) ?></td>
                                    <td><?= htmlspecialchars($booking['event_name']) ?></td>
                                    <td><?= htmlspecialchars($booking['ticket_types_and_counts']) ?></td>
                                    <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($booking['booking_gross_sum_for_ref'], 2) ?></td>
                                    <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($booking['booking_net_sum_for_ref'], 2) ?></td>
                                    <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($booking['total_discount_for_ref'], 2) ?></td>
                                    <td><?= htmlspecialchars($booking['payment_method']) ?></td>
                                    <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($booking['platform_revenue'], 2) ?></td>
                                    <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($booking['host_revenue'], 2) ?></td>
                                    <td><i class="fas fa-indian-rupee-sign"></i> <?= number_format($booking['creator_revenue'], 2) ?></td>
                                    <td><?= htmlspecialchars($booking['venue_name']) ?> (<?= htmlspecialchars($booking['sub_venue_name']) ?>)</td>
                                    <td><?= (new DateTime($booking['slot_starts_at']))->format('M d, Y H:i A') ?> - <?= (new DateTime($booking['slot_ends_at']))->format('H:i A') ?></td>
                                    <td><?= (new DateTime($booking['booking_time']))->format('M d, Y H:i A') ?></td>
                                    <td><?= htmlspecialchars($booking['user_username']) ?></td>
                                    <td><?= htmlspecialchars($booking['checked_in']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination for Detailed Booking Records -->
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" aria-label="First">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>" aria-label="Previous">
                                <span aria-hidden="true">&lsaquo;</span>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($current_page == $i) ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>" aria-label="Next">
                                <span aria-hidden="true">&rsaquo;</span>
                            </a>
                        </li>
                        <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>" aria-label="Last">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

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
    document.addEventListener('DOMContentLoaded', function() {
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const contentWrapper = document.getElementById('contentWrapper');
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
            sidebarToggleBtn.addEventListener('click', function() {
                setSidebarOpen(!sidebar.classList.contains('is-open'));
            });

            // Click outside to close sidebar on mobile
            document.addEventListener('click', function(event) {
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
            }
        });

        // --- Caret Icon Rotation on Collapse Events ---
        document.querySelectorAll('.sidebar-nav .collapse').forEach(collapseElement => {
            collapseElement.addEventListener('show.bs.collapse', function() {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(180deg)';
                    // Optionally activate parent link on expand, but usually only if it's currently selected.
                    // For submenus, the actual page link sets active state, the parent link only shows it.
                }
            });

            collapseElement.addEventListener('hide.bs.collapse', function() {
                const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                if (toggleLink) {
                    const caretIcon = toggleLink.querySelector('.caret-icon');
                    if (caretIcon) caretIcon.style.transform = 'rotate(0deg)';
                    
                    // Only deactivate parent link if none of its *current* sub-items are active
                    const hasActiveChild = this.querySelector('.nav-link.active');
                    if (!hasActiveChild) {
                       // toggleLink.classList.remove('active'); // Keep active if page is active, otherwise remove
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
        const currentUrlParams = new URLSearchParams(window.location.search);

        // Function to check if any non-default filters are applied
        const areFiltersApplied = () => {
            const tempParams = new URLSearchParams(currentUrlParams);

            // Special handling for 'events' parameter on this page.
            // If it's the very first load or 'events' isn't explicitly set, it defaults to 'all'.
            // For other filters, 'all' is explicitly *not* applied.
            const eventsParam = tempParams.getAll('events');
            const isInitialLoadForEvents = !window.location.search.includes('events%5B%5D') && !window.location.search.includes('events=');
            
            if (eventsParam.length === 0 && isInitialLoadForEvents) {
                // If events parameter is absent on initial load (or not set), PHP defaults to 'all', so it's not a filter
                tempParams.delete('events');
            } else if (eventsParam.length === 1 && eventsParam[0] === 'all') {
                // If it's explicitly 'all', and it was not from initial load (meaning user selected 'all')
                // For this page, 'all' for events means "my events", so it's still default behaviour.
                tempParams.delete('events');
            } else if (eventsParam.length === 0 && window.location.search.includes('events%5B%5D')) {
                // If it's an empty array (?events=) from the GET, it means user actively unchecked all events.
                // This IS an applied filter from the user's perspective, so don't delete.
            }

            // Default date_filter is 'weekly'.
            const dateFilterParam = tempParams.get('date_filter');
            if (dateFilterParam === 'weekly') {
                tempParams.delete('date_filter');
                if (tempParams.has('custom_start_date')) tempParams.delete('custom_start_date');
                if (tempParams.has('custom_end_date')) tempParams.delete('custom_end_date');
            } else if (dateFilterParam === 'custom') {
                const customStart = tempParams.get('custom_start_date');
                const customEnd = tempParams.get('custom_end_date');
                if(!customStart || !customEnd) {
                    tempParams.delete('custom_start_date');
                    tempParams.delete('custom_end_date');
                    tempParams.delete('date_filter');
                }
            }


            // Default for non-events filters: if empty or contain 'all', they are not 'applied'
            const checkAndClearDefault = (paramName, defaultValue = 'all') => {
                const paramValue = tempParams.getAll(paramName);
                if (paramValue.length === 0 || (paramValue.length === 1 && paramValue[0] === defaultValue)) {
                    tempParams.delete(paramName);
                } else if (paramValue.length > 1 && paramValue.includes(defaultValue)) {
                   // This should not happen if multi-select logic is correct ("all" is exclusive)
                   // But if it does, consider it as a specific filter.
                }
            };
            checkAndClearDefault('cities');
            checkAndClearDefault('venues');
            checkAndClearDefault('ticket_types');
            checkAndClearDefault('active_bookings');
            checkAndClearDefault('checked_in_status');

            // Remove 'page' parameter as it's for pagination, not filtering.
            tempParams.delete('page');

            return Array.from(tempParams.keys()).length > 0;
        };


        if (filterCard && toggleFiltersBtn) {
            const filtersActiveInitially = areFiltersApplied();
            // Initial state of filter card: visible if filters are active, hidden otherwise.
            if (filtersActiveInitially) {
                filterCard.classList.remove('d-none');
                toggleFiltersBtn.innerHTML = '<i class="fas fa-times me-2"></i> Hide Filters';
            } else {
                filterCard.classList.add('d-none');
                toggleFiltersBtn.innerHTML = '<i class="fas fa-filter me-2"></i> Show Filters';
            }

            // Add the click event listener for the toggle button
            toggleFiltersBtn.addEventListener('click', function() {
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

            // Get the original filter name from the data attribute (e.g., 'events', 'cities')
            const filterName = group.dataset.filterName;

            function updateItemCheckboxesReadinessForSubmission() {
                if (!allCheckbox) { // Should not happen if HTML is consistent
                    itemCheckboxes.forEach(cb => {
                        if (!cb.hasAttribute('name')) {
                            cb.setAttribute('name', filterName + '[]');
                        }
                    });
                    return;
                }

                if (allCheckbox.checked) {
                    // If 'All' is checked, disable and remove name from individual items
                    itemCheckboxes.forEach(cb => {
                        cb.checked = false; // Visually uncheck individual items
                        cb.disabled = true; // Disable individual items
                        cb.removeAttribute('name'); // Remove name so they are not sent in form
                    });
                    // Ensure 'All' checkbox has its name attribute so 'all' is sent
                    allCheckbox.setAttribute('name', filterName + '[]');
                } else {
                    // If 'All' is not checked, enable and ensure name for *checked* individual items
                    allCheckbox.removeAttribute('name'); // Remove 'All' name
                    itemCheckboxes.forEach(cb => {
                        cb.disabled = false; // Enable individual items
                        if (cb.checked) {
                            cb.setAttribute('name', filterName + '[]');
                        } else {
                            cb.removeAttribute('name');
                        }
                    });
                }
            }

            if (allCheckbox) {
                allCheckbox.addEventListener('change', function() {
                    updateItemCheckboxesReadinessForSubmission();
                });
            }

            itemCheckboxes.forEach(itemCheckbox => {
                itemCheckbox.addEventListener('change', function() {
                    if (allCheckbox) {
                        if (this.checked) {
                            allCheckbox.checked = false; // If any individual is checked, 'All' is implicitly false
                        } else {
                            // If an individual item is unchecked, check if any other item is still checked.
                            // If no other item, "All" should be checked (or not checked if user chose no events)
                            const anyItemStillChecked = Array.from(itemCheckboxes).some(cb => cb.checked);
                            if (!anyItemStillChecked) {
                                // Special case for 'events' filter on this page:
                                // If the user explicitly deselects ALL events (empty array sent),
                                // it means they want no events. So 'All My Events' checkbox should also be unchecked.
                                if (filterName === 'events') {
                                    allCheckbox.checked = false; // Keep it unchecked if no specific events selected
                                } else {
                                    allCheckbox.checked = true; // For other filters, default back to 'all' if none selected
                                }
                            }
                        }
                    }
                    updateItemCheckboxesReadinessForSubmission(); // Re-evaluate names and disabled states
                });
            });

            // Initial setup on page load: ensure correct state based on PHP pre-selection
            // PHP passes original_get values directly.
            let originalSelectedItems = [];
             <?php
            // Use json_encode to pass PHP arrays to JavaScript
            echo "originalSelectedItems = ";
            if ($filterName === 'events') {
                echo json_encode($selected_events_original_get);
            } else if ($filterName === 'cities') {
                echo json_encode($selected_cities_original_get);
            } else if ($filterName === 'venues') {
                echo json_encode($selected_venues_original_get);
            } else if ($filterName === 'ticket_types') {
                echo json_encode($selected_ticket_types_original_get);
            } else {
                echo "[]"; // Default empty array
            }
            echo ";\n";
            ?>
            

            const isAllSelectedInPHP = originalSelectedItems.includes('all');
            const noSpecificItemsSelectedInPHP = originalSelectedItems.length === 0;

            if (allCheckbox) { // Ensure 'All' checkbox HTML element exists
                if (isAllSelectedInPHP) {
                    allCheckbox.checked = true;
                } else if (noSpecificItemsSelectedInPHP) {
                     // If no events specified in GET (e.g. ?events=) for 'events' filter
                    if (filterName === 'events') {
                        allCheckbox.checked = false; // No specific events selected == uncheck 'All'
                    } else { // For other filters, empty array means 'all' by default
                        allCheckbox.checked = true;
                    }
                } else { // Specific items were selected
                    allCheckbox.checked = false;
                }
            }

            // Also check individual checkboxes based on initialSelectedItems
            itemCheckboxes.forEach(cb => {
                if (originalSelectedItems.includes(cb.value)) {
                    cb.checked = true;
                } else {
                    cb.checked = false;
                }
            });

            // This ensures the current state is reflected in the `name` attributes and disabled states.
            updateItemCheckboxesReadinessForSubmission();
        }

        // Call setup for each filter group that uses checkboxes
        setupFilterGroup('events-group');
        setupFilterGroup('cities-group');
        setupFilterGroup('venues-group');
        setupFilterGroup('ticket_types-group');

        // --- Client-side search for filter checkboxes ---
        document.querySelectorAll('.filter-search-input').forEach(searchInput => {
            searchInput.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const targetGroupId = this.dataset.targetGroup;
                const checkboxGroup = document.querySelector(targetGroupId);

                if (checkboxGroup) {
                    const items = checkboxGroup.querySelectorAll('.form-check');
                    items.forEach(item => {
                        const label = item.querySelector('.form-check-label');
                        const input = item.querySelector('.form-check-input');
                        // Only hide/show if it's not the "All" checkbox
                        if (input && !input.classList.contains('filter-type-all')) {
                            if (label) {
                                const labelText = label.textContent.toLowerCase();
                                if (labelText.includes(searchTerm)) {
                                    item.style.display = 'flex';
                                } else {
                                    item.style.display = 'none';
                                }
                            }
                        }
                    });
                    // Hide "All" checkbox if search term is active
                    const allOptionElement = checkboxGroup.querySelector('.form-check .filter-type-all');
                    if (allOptionElement && allOptionElement.closest('.form-check')) {
                        if (searchTerm.length > 0) {
                            allOptionElement.closest('.form-check').style.display = 'none';
                        } else {
                            allOptionElement.closest('.form-check').style.display = 'flex';
                        }
                    }
                }
            });
        });
    });
</script>
</body>
</html>