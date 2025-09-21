<?php
// CRITICAL: No whitespace, newlines, or BOM characters BEFORE this <?php tag

// Set content type header FIRST to ensure consistent JSON response in case of early errors/warnings.
// This must be sent before ANY other output.
header('Content-Type: application/json; charset=utf-8');

session_start(); // Start session for user ID if needed

// Error reporting settings - keep these ON during development for debugging, turn OFF for production.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure db_config.php provides PDO connection
// This file itself MUST NOT output anything other than its intended JSON error from its catch block.
require_once 'config/db_config.php';

// After including db_config.php, verify PDO connection
// db_config.php's catch block for PDOException should already send JSON error and exit.
// This check is a fallback for unexpected scenarios where $pdo might be null but script continues.
if ($pdo === null) {
    // If db_config.php didn't exit on DB error (which it should), send error from here.
    // The header is already set at the top.
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Ensure db_config.php is correctly set up.']);
    exit;
}

date_default_timezone_set('Asia/Kolkata');

// Hardcoded user ID for demonstration. Replace with $_SESSION['user_id'] in a real app.
// DEFAULT_USER_ID is defined in db_config.php. The previous warning about it
// could be due to a deeper PHP error preventing db_config.php from being fully parsed,
// or a strict interpretation of constants.
$current_user_id = DEFAULT_USER_ID;

// --- Helper functions for row increments (like Excel columns) ---
// These functions are already good, just keeping them in context
function row_to_int($row_str) {
    $row_str = strtoupper($row_str);
    $length = strlen($row_str);
    $value = 0;
    for ($i = 0; $i < $length; $i++) {
        $char = $row_str[$i];
        if ($char >= 'A' && $char <= 'Z') {
            $value = $value * 26 + (ord($char) - ord('A') + 1);
        } else {
            return -1;
        }
    }
    return $value;
}

function int_to_row($value) {
    if ($value <= 0) {
        return '';
    }
    $result = '';
    while ($value > 0) {
        $value--;
        $remainder = $value % 26;
        $result = chr(ord('A') + $remainder) . $result;
        $value = floor($value / 26);
    }
    return $result;
}
// --- End Helper functions for row increments ---

// Helper function to get ticket statistics for a given event_schedule
function get_event_schedule_ticket_stats($pdo, $event_schedule_id) {
    $stats = [
        'total_active_tickets' => 0, 
        'available_tickets' => 0,
        'inactive_tickets' => 0, 
        'by_type' => [],
        'by_price' => [],
    ];

    // Count for active tickets
    $stmt = $pdo->prepare("SELECT
                                COUNT(ticket_id) as total_active,
                                SUM(CASE WHEN is_vacant = 'yes' THEN 1 ELSE 0 END) as available
                            FROM event_schedule_tickets
                            WHERE event_schedule_id = :event_schedule_id AND is_active = 'yes'");
    $stmt->execute(['event_schedule_id' => $event_schedule_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stats['total_active_tickets'] = (int)$row['total_active'];
        $stats['available_tickets'] = (int)$row['available'];
    }

    // Count for inactive tickets
    $stmt = $pdo->prepare("SELECT COUNT(ticket_id) as total_inactive
                            FROM event_schedule_tickets
                            WHERE event_schedule_id = :event_schedule_id AND is_active = 'no'");
    $stmt->execute(['event_schedule_id' => $event_schedule_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stats['inactive_tickets'] = (int)$row['total_inactive'];
    }


    // Stats by type (only for active tickets)
    $stmt = $pdo->prepare("SELECT ticket_type, COUNT(*) as count
                            FROM event_schedule_tickets
                            WHERE event_schedule_id = :event_schedule_id AND is_active = 'yes'
                            GROUP BY ticket_type");
    $stmt->execute(['event_schedule_id' => $event_schedule_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_type'][$row['ticket_type']] = (int)$row['count'];
    }

    // Stats by price (only for active tickets)
    $stmt = $pdo->prepare("SELECT ticket_price, COUNT(*) as count
                            FROM event_schedule_tickets
                            WHERE event_schedule_id = :event_schedule_id AND is_active = 'yes'
                            GROUP BY ticket_price");
    $stmt->execute(['event_schedule_id' => $event_schedule_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_price'] = array_merge($stats['by_price'], [(string)$row['ticket_price'] => (int)$row['count']]);
    }
    
    // Sort by_price keys numerically
    ksort($stats['by_price'], SORT_NUMERIC);

    return $stats;
}

// Global stats for the main page (unchanged - these are overall system totals)
function get_overall_event_schedule_stats($pdo) {
    $overall_stats = [
        'total_event_schedules' => 0,
        'active_event_schedules' => 0,
        'total_tickets_mapped' => 0, // This now reflects total active tickets
    ];

    $stmt = $pdo->query("SELECT
                                COUNT(*) as total,
                                SUM(CASE WHEN is_active = 'yes' THEN 1 ELSE 0 END) as active
                            FROM event_schedule");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $overall_stats['total_event_schedules'] = (int)$result['total'];
    $overall_stats['active_event_schedules'] = (int)$result['active'];

    // This now counts all *active* tickets
    $stmt = $pdo->query("SELECT COUNT(*) FROM event_schedule_tickets WHERE is_active = 'yes'");
    $overall_stats['total_tickets_mapped'] = (int)$stmt->fetchColumn();

    return $overall_stats;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'get_event_schedules_with_ticket_counts':
        try {
            // Use FILTER_SANITIZE_STRING (now deprecated, use FILTER_UNSAFE_RAW with htmlspecialchars on output)
            // or FILTER_SANITIZE_FULL_SPECIAL_CHARS for general text inputs where special chars need encoding.
            $search_term = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $city_id = filter_input(INPUT_GET, 'city_id', FILTER_VALIDATE_INT);
            $genre = filter_input(INPUT_GET, 'genre', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $event_type = filter_input(INPUT_GET, 'event_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            
            // For date inputs, use FILTER_UNSAFE_RAW as we just need the string (validation happens with date functions)
            $schedule_date_start = filter_input(INPUT_GET, 'schedule_date_start', FILTER_UNSAFE_RAW);
            $schedule_date_end = filter_input(INPUT_GET, 'schedule_date_end', FILTER_UNSAFE_RAW); 
            
            $is_schedule_active = filter_input(INPUT_GET, 'is_schedule_active', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            
            $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
            $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => RECORDS_PER_PAGE, 'min_range' => 1]]);
            $offset = ($page - 1) * $limit;

            $base_sql = "FROM event_schedule es
                         JOIN event_info ei ON es.event_id = ei.event_id
                         JOIN venues v ON es.venue_id = v.venue_id
                         JOIN cities c ON v.city_id = c.city_id
                         JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id";
            
            $where_clauses = [];
            $params = [];

            if (!empty($search_term)) {
                $where_clauses[] = "(ei.event_name LIKE :search OR v.venue_name LIKE :search OR v.sub_venue_name LIKE :search)";
                $params[':search'] = "%" . $search_term . "%";
            }
            if (!empty($city_id)) {
                $where_clauses[] = "v.city_id = :city_id";
                $params[':city_id'] = $city_id;
            }
            if (!empty($genre)) {
                $where_clauses[] = "ei.genre = :genre";
                $params[':genre'] = $genre;
            }
            if (!empty($event_type)) {
                $where_clauses[] = "ei.event_type = :event_type";
                $params[':event_type'] = $event_type;
            }
            // Reference vs.slot_starts_at and vs.slot_ends_at for dates
            if (!empty($schedule_date_start)) {
                $where_clauses[] = "DATE(vs.slot_starts_at) >= :schedule_date_start";
                $params[':schedule_date_start'] = $schedule_date_start;
            }
            if (!empty($schedule_date_end)) {
                $where_clauses[] = "DATE(vs.slot_ends_at) <= :schedule_date_end"; 
                $params[':schedule_date_end'] = $schedule_date_end;
            }
            if ($is_schedule_active === 'yes' || $is_schedule_active === 'no') {
                $where_clauses[] = "es.is_active = :is_schedule_active";
                $params[':is_schedule_active'] = $is_schedule_active;
            }

            $where_sql = "";
            if (count($where_clauses) > 0) {
                $where_sql = " WHERE " . implode(" AND ", $where_clauses);
            }

            // TOTAL COUNT for pagination of the FILTERED set
            $count_stmt = $pdo->prepare("SELECT COUNT(es.event_schedule_id) " . $base_sql . $where_sql);
            $count_stmt->execute($params);
            $total_event_schedules_filtered = (int)$count_stmt->fetchColumn(); // Renamed for clarity
            $total_pages = ceil($total_event_schedules_filtered / $limit);

            $sql = "SELECT
                                es.event_schedule_id,
                                ei.event_name,
                                ei.event_type,
                                ei.genre,
                                v.venue_name,
                                v.sub_venue_name,
                                v.capacity,
                                c.city_name,
                                vs.slot_starts_at,
                                vs.slot_ends_at, 
                                es.is_active AS is_schedule_active,
                                (SELECT COUNT(est.ticket_id) FROM event_schedule_tickets est WHERE est.event_schedule_id = es.event_schedule_id AND est.is_active = 'yes') AS total_tickets,
                                (SELECT SUM(CASE WHEN est.is_vacant = 'yes' AND est.is_active = 'yes' THEN 1 ELSE 0 END) FROM event_schedule_tickets est WHERE est.event_schedule_id = es.event_schedule_id) AS available_tickets
                            " . $base_sql . $where_sql . "
                            ORDER BY vs.slot_starts_at DESC
                            LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $event_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate additional filtered view stats manually from the fetched array
            $filtered_active_schedules_count = 0;
            $filtered_total_tickets_mapped_count = 0;
            foreach ($event_schedules as $schedule) {
                if ($schedule['is_schedule_active'] === 'yes') {
                    $filtered_active_schedules_count++;
                }
                $filtered_total_tickets_mapped_count += (int)$schedule['total_tickets'];
            }

            echo json_encode([
                'success' => true,
                'event_schedules' => $event_schedules,
                'total_event_schedules' => $total_event_schedules_filtered, // This is count of FILTERED schedules for pagination
                'total_pages' => $total_pages,
                'current_page' => $page,
                'overall_stats' => get_overall_event_schedule_stats($pdo), // This remains GLOBAL system stats
                'filtered_view_stats' => [ // NEW: Stats relevant to the CURRENTLY FILTERED VIEW
                    'total_filtered_schedules' => $total_event_schedules_filtered,
                    'active_filtered_schedules' => $filtered_active_schedules_count,
                    'total_mapped_tickets_in_filtered_schedules' => $filtered_total_tickets_mapped_count,
                ]
            ]);

        } catch (PDOException $e) {
            error_log("Error in get_event_schedules_with_ticket_counts: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error fetching event schedules. ' . $e->getMessage()]);
        }
        break;

    case 'get_event_schedule_ticket_data':
        $event_schedule_id = filter_input(INPUT_GET, 'event_schedule_id', FILTER_VALIDATE_INT);
        if (!$event_schedule_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid Event Schedule ID.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT
                                            ticket_id,
                                            ticket_location,
                                            ticket_row,
                                            ticket_column,
                                            ticket_type,
                                            ticket_price,
                                            is_vacant,
                                            is_active -- Fetch is_active
                                            FROM event_schedule_tickets
                                            WHERE event_schedule_id = :event_schedule_id
                                            ORDER BY ticket_row, LPAD(ticket_column, 3, '0')");
            $stmt->execute(['event_schedule_id' => $event_schedule_id]);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get venue capacity for this event_schedule
            $stmt_capacity = $pdo->prepare("SELECT v.capacity FROM event_schedule es JOIN venues v ON es.venue_id = v.venue_id WHERE es.event_schedule_id = :event_schedule_id");
            $stmt_capacity->execute(['event_schedule_id' => $event_schedule_id]);
            $venue_capacity = $stmt_capacity->fetchColumn();

            $stats = get_event_schedule_ticket_stats($pdo, $event_schedule_id);

            echo json_encode(['success' => true, 'tickets' => $tickets, 'stats' => $stats, 'venue_capacity' => (int)$venue_capacity]); // Cast to int

        } catch (PDOException $e) {
            error_log("Error in get_event_schedule_ticket_data: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error fetching ticket data. ' . $e->getMessage()]);
        }
        break;

    case 'generate_single_ticket':
        $event_schedule_id = filter_input(INPUT_POST, 'event_schedule_id', FILTER_VALIDATE_INT);
        $ticket_row = strtoupper(trim($_POST['ticket_row'] ?? ''));
        $ticket_column = filter_input(INPUT_POST, 'ticket_column', FILTER_VALIDATE_INT);
        $ticket_type = trim($_POST['ticket_type'] ?? '');
        $ticket_price = filter_input(INPUT_POST, 'ticket_price', FILTER_VALIDATE_FLOAT);

        if (!$event_schedule_id || empty($ticket_row) || !$ticket_column || empty($ticket_type) || $ticket_price === false || $ticket_price < 0) {
            echo json_encode(['success' => false, 'message' => 'Missing or invalid ticket data provided for single generation.']);
            exit;
        }
        
        if (!preg_match('/^[A-Z]{1,3}$/', $ticket_row)) {
            echo json_encode(['success' => false, 'message' => 'Invalid Row: Only uppercase letters (A-Z) allowed (max 3 chars).']);
            exit;
        }

        $ticket_location = $ticket_row . $ticket_column;

        try {
            $stmt_capacity = $pdo->prepare("SELECT v.capacity FROM event_schedule es JOIN venues v ON es.venue_id = v.venue_id WHERE es.event_schedule_id = :event_schedule_id");
            $stmt_capacity->execute(['event_schedule_id' => $event_schedule_id]);
            $venue_capacity = (int)$stmt_capacity->fetchColumn();

            // Count all ACTIVE tickets for capacity check
            $stmt_current_tickets = $pdo->prepare("SELECT COUNT(*) FROM event_schedule_tickets WHERE event_schedule_id = :event_schedule_id AND is_active = 'yes'");
            $stmt_current_tickets->execute(['event_schedule_id' => $event_schedule_id]);
            $current_active_tickets = (int)$stmt_current_tickets->fetchColumn();

            // Check for existence of any ticket (active or inactive) at this location
            $stmt_duplicate = $pdo->prepare("SELECT is_active FROM event_schedule_tickets WHERE event_schedule_id = :event_schedule_id AND ticket_location = :ticket_location");
            $stmt_duplicate->execute(['event_schedule_id' => $event_schedule_id, 'ticket_location' => $ticket_location]);
            $existing_ticket_status = $stmt_duplicate->fetchColumn();

            if ($existing_ticket_status) {
                if ($existing_ticket_status === 'yes') {
                    echo json_encode(['success' => false, 'message' => "Ticket '$ticket_location' already exists and is active for this event schedule. "]);
                } else {
                    echo json_encode(['success' => false, 'message' => "Ticket '$ticket_location' exists but is inactive. Cannot create a new one at this location. Consider updating the existing ticket (setting is_active to 'yes')."]); // More helpful message
                }
                exit;
            }

            if ($current_active_tickets >= $venue_capacity) {
                echo json_encode(['success' => false, 'message' => "Cannot add ticket: Venue capacity ($venue_capacity) reached for active tickets for this event schedule."]);
                exit;
            }
            
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO event_schedule_tickets
                                         (event_schedule_id, ticket_location, ticket_row, ticket_column, ticket_type, ticket_price, is_vacant, is_active, created_by, created_at)
                                         VALUES
                                         (:event_schedule_id, :ticket_location, :ticket_row, :ticket_column, :ticket_type, :ticket_price, 'yes', 'yes', :created_by, NOW())");
            $stmt->execute([
                'event_schedule_id' => $event_schedule_id,
                'ticket_location' => $ticket_location,
                'ticket_row' => $ticket_row,
                'ticket_column' => $ticket_column,
                'ticket_type' => $ticket_type,
                'ticket_price' => $ticket_price,
                'created_by' => $current_user_id,
            ]);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => "Ticket '$ticket_location' generated successfully."]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error generating single ticket: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error generating single ticket: ' . $e->getMessage()]);
        }
        break;

    case 'generate_bulk_tickets':
        $event_schedule_id = filter_input(INPUT_POST, 'event_schedule_id', FILTER_VALIDATE_INT);
        $row_start = strtoupper(trim($_POST['row_start'] ?? ''));
        $row_end = strtoupper(trim($_POST['row_end'] ?? ''));
        $col_start = filter_input(INPUT_POST, 'col_start', FILTER_VALIDATE_INT);
        $col_end = filter_input(INPUT_POST, 'col_end', FILTER_VALIDATE_INT);
        $ticket_type = trim($_POST['ticket_type'] ?? '');
        $ticket_price = filter_input(INPUT_POST, 'ticket_price', FILTER_VALIDATE_FLOAT);

        if (!$event_schedule_id || empty($row_start) || empty($row_end) ||
            !$col_start || !$col_end || empty($ticket_type) || $ticket_price === false || $ticket_price < 0) {
            echo json_encode(['success' => false, 'message' => 'Missing or invalid bulk ticket data provided.']);
            exit;
        }

        if (!preg_match('/^[A-Z]{1,3}$/', $row_start) || !preg_match('/^[A-Z]{1,3}$/', $row_end)) {
            echo json_encode(['success' => false, 'message' => 'Invalid Row format. Use 1-3 uppercase letters (A-Z).']);
            exit;
        }

        $row_start_int = row_to_int($row_start);
        $row_end_int = row_to_int($row_end);

        if ($row_start_int === -1 || $row_end_int === -1 || $row_start_int > $row_end_int) {
            echo json_encode(['success' => false, 'message' => 'Invalid Row Range: Start > End or invalid row format.']);
            exit;
        }
        if ($col_start > $col_end || $col_start <=0 ) {
            echo json_encode(['success' => false, 'message' => 'Invalid Column Range: Must be positive numbers, Start <= End.']);
            exit;
        }
        
        try {
            $stmt_capacity = $pdo->prepare("SELECT v.capacity FROM event_schedule es JOIN venues v ON es.venue_id = v.venue_id WHERE es.event_schedule_id = :event_schedule_id");
            $stmt_capacity->execute(['event_schedule_id' => $event_schedule_id]);
            $venue_capacity = (int)$stmt_capacity->fetchColumn();

            // Count all ACTIVE tickets for capacity check
            $stmt_current_tickets = $pdo->prepare("SELECT COUNT(*) FROM event_schedule_tickets WHERE event_schedule_id = :event_schedule_id AND is_active = 'yes'");
            $stmt_current_tickets->execute(['event_schedule_id' => $event_schedule_id]);
            $current_active_tickets = (int)$stmt_current_tickets->fetchColumn();

            $pdo->beginTransaction();

            $insert_sql = "INSERT INTO event_schedule_tickets
                                         (event_schedule_id, ticket_location, ticket_row, ticket_column, ticket_type, ticket_price, is_vacant, is_active, created_by, created_at)
                                         VALUES
                                         (:event_schedule_id, :ticket_location, :ticket_row, :ticket_column, :ticket_type, :ticket_price, 'yes', 'yes', :created_by, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            
            // Check for existence of any ticket (active or inactive) at this location
            $check_duplicate_sql = "SELECT is_active FROM event_schedule_tickets WHERE event_schedule_id = :event_schedule_id AND ticket_location = :ticket_location";
            $check_duplicate_stmt = $pdo->prepare($check_duplicate_sql);


            $generated_count = 0;
            $skipped_count = 0;
            
            for ($current_row_int = $row_start_int; $current_row_int <= $row_end_int; $current_row_int++) {
                $current_row_str = int_to_row($current_row_int);

                for ($current_col = $col_start; $current_col <= $col_end; $current_col++) {
                    $ticket_location = $current_row_str . $current_col;

                    $check_duplicate_stmt->execute(['event_schedule_id' => $event_schedule_id, 'ticket_location' => $ticket_location]);
                    $existing_ticket_status = $check_duplicate_stmt->fetchColumn();

                    if ($existing_ticket_status) {
                        $skipped_count++;
                        // If it's active, log or indicate why it's skipped
                        // If it's inactive, we still don't create a new one, consider it "taken"
                        continue;
                    }

                    // Capacity check against active tickets + newly generated active tickets
                    if (($current_active_tickets + $generated_count) >= $venue_capacity) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => "Capacity Limit Reached! Generated $generated_count out of possible tickets. Skipped $skipped_count existing/over-capacity tickets. Remaining tickets not generated.", 'generated_count' => $generated_count, 'skipped_count' => $skipped_count]);
                        exit;
                    }
                    
                    $insert_stmt->execute([
                        'event_schedule_id' => $event_schedule_id,
                        'ticket_location' => $ticket_location,
                        'ticket_row' => $current_row_str,
                        'ticket_column' => $current_col,
                        'ticket_type' => $ticket_type,
                        'ticket_price' => $ticket_price,
                        'created_by' => $current_user_id,
                    ]);
                    $generated_count++;
                }
            }
            $pdo->commit();
            
            $message = "Successfully generated {$generated_count} ticket(s).";
            if ($skipped_count > 0) {
                $message .= " {$skipped_count} ticket(s) were skipped as they already existed or were inactive.";
            }
            echo json_encode(['success' => true, 'message' => $message, 'generated_count' => $generated_count, 'skipped_count' => $skipped_count]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error generating bulk tickets: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error during bulk generation: ' . $e->getMessage()]);
        }
        break;

    case 'update_tickets':
        $event_schedule_id = filter_input(INPUT_POST, 'event_schedule_id', FILTER_VALIDATE_INT);
        $ticket_ids_json = $_POST['ticket_ids'] ?? '[]';
        $ticket_ids = json_decode($ticket_ids_json, true);

        if (!$event_schedule_id || !is_array($ticket_ids) || empty($ticket_ids)) {
            echo json_encode(['success' => false, 'message' => 'Invalid Event Schedule ID or no ticket IDs provided for update.']);
            exit;
        }

        $sanitized_ticket_ids = [];
        foreach ($ticket_ids as $tid) {
            $validated_tid = filter_var($tid, FILTER_VALIDATE_INT);
            if ($validated_tid !== false) {
                $sanitized_ticket_ids[] = $validated_tid;
            }
        }
        if (empty($sanitized_ticket_ids)) {
            echo json_encode(['success' => false, 'message' => 'No valid ticket IDs provided after sanitization.']);
            exit;
        }
        $ticket_ids = $sanitized_ticket_ids;

        $new_type = trim($_POST['ticket_type'] ?? '');
        $new_price_str = trim($_POST['ticket_price'] ?? '');
        $new_is_vacant = trim($_POST['is_vacant'] ?? '');
        $new_is_active = trim($_POST['is_active'] ?? ''); // Get new is_active status

        if (empty($new_type) && $new_price_str === '' && empty($new_is_vacant) && empty($new_is_active)) {
            echo json_encode(['success' => false, 'message' => 'No changes specified for update.']);
            exit;
        }
        
        $update_sql_parts = [];
        $update_values = [];

        if (!empty($new_type)) {
            $update_sql_parts[] = "ticket_type = :ticket_type";
            $update_values[':ticket_type'] = $new_type;
        }
        if ($new_price_str !== '') {
            $new_price = filter_var($new_price_str, FILTER_VALIDATE_FLOAT);
            if ($new_price === false || $new_price < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ticket price. Must be a non-negative number.']);
                exit;
            }
            $update_sql_parts[] = "ticket_price = :ticket_price";
            $update_values[':ticket_price'] = $new_price;
        }
        
        // Handle is_active status change first
        if (($new_is_active === 'yes' || $new_is_active === 'no')) {
            $update_sql_parts[] = "is_active = :is_active";
            $update_values[':is_active'] = $new_is_active;

            // If changing to inactive, also make it not vacant and clear booking details
            if ($new_is_active === 'no') {
                $update_sql_parts[] = "is_vacant = 'no'";        // Force to not vacant
                $update_sql_parts[] = "booked_by = NULL";        // Clear booking
                $update_sql_parts[] = "booked_at = NULL";        // Clear booking
            }
        }

        // Handle is_vacant status change (only if not already forced by is_active)
        // Check `new_is_active` to ensure we don't overwrite `is_vacant = 'no'` if `is_active` changed to 'no'
        if (($new_is_vacant === 'yes' || $new_is_vacant === 'no') && !isset($update_values[':is_vacant'])) {
            $update_sql_parts[] = "is_vacant = :is_vacant";
            $update_values[':is_vacant'] = $new_is_vacant;
            // Clear booked_by/at only if setting to vacant 'yes' 
            if ($new_is_vacant === 'yes') {
                $update_sql_parts[] = "booked_by = NULL";
                $update_sql_parts[] = "booked_at = NULL";
            }
        }
        

        if (empty($update_sql_parts)) {
            echo json_encode(['success' => false, 'message' => 'No valid changes to apply. This should not be reached.']); // Fallback
            exit;
        }
        
        $sql_set_clause = implode(', ', $update_sql_parts);

        // Create named placeholders for the IN clause
        $ticket_id_placeholders = [];
        $ticket_id_bind_values = [];
        foreach ($ticket_ids as $index => $id) {
            $placeholder_name = ":ticket_id_" . $index;
            $ticket_id_placeholders[] = $placeholder_name;
            $ticket_id_bind_values[$placeholder_name] = $id;
        }
        $ticket_ids_in_clause = implode(',', $ticket_id_placeholders);

        $sql = "UPDATE event_schedule_tickets SET " . $sql_set_clause .
                     ", created_at = NOW(), created_by = :updated_by " . // This line sets created_at/by as updated_at/by. Consider separate columns for modified_at/by in schema.
                     " WHERE event_schedule_id = :event_schedule_id AND ticket_id IN ($ticket_ids_in_clause)";

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            
            // Bind values for SET clause
            foreach ($update_values as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            // Bind common parameters
            $stmt->bindValue(':event_schedule_id', $event_schedule_id, PDO::PARAM_INT);
            $stmt->bindValue(':updated_by', $current_user_id, PDO::PARAM_INT);
            
            // Bind values for IN clause
            foreach ($ticket_id_bind_values as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            }

            $stmt->execute();
            $affected_rows = $stmt->rowCount();
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "$affected_rows ticket(s) updated successfully.",
                'affected_rows' => $affected_rows,
            ]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error updating tickets: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error updating tickets: ' . $e->getMessage()]);
        }
        break;

    case 'delete_tickets': // Permanently delete tickets
        $event_schedule_id = filter_input(INPUT_POST, 'event_schedule_id', FILTER_VALIDATE_INT);
        $ticket_ids_json = $_POST['ticket_ids'] ?? '[]';
        $ticket_ids = json_decode($ticket_ids_json, true);

        if (!$event_schedule_id || !is_array($ticket_ids) || empty($ticket_ids)) {
            echo json_encode(['success' => false, 'message' => 'Invalid Event Schedule ID or no ticket IDs provided for deletion.']);
            exit;
        }

        $sanitized_ticket_ids = [];
        foreach ($ticket_ids as $tid) {
            $validated_tid = filter_var($tid, FILTER_VALIDATE_INT);
            if ($validated_tid !== false) {
                $sanitized_ticket_ids[] = $validated_tid;
            }
        }
        if (empty($sanitized_ticket_ids)) {
            echo json_encode(['success' => false, 'message' => 'No valid ticket IDs provided after sanitization.']);
            exit;
        }
        $ticket_ids = $sanitized_ticket_ids;

        // Create named placeholders for the IN clause
        $ticket_id_placeholders = [];
        $ticket_id_bind_values = [];
        foreach ($ticket_ids as $index => $id) {
            $placeholder_name = ":ticket_id_" . $index;
            $ticket_id_placeholders[] = $placeholder_name;
            $ticket_id_bind_values[$placeholder_name] = $id;
        }
        $ticket_ids_in_clause = implode(',', $ticket_id_placeholders);

        // *** MODIFICATION HERE: Changed from UPDATE to DELETE ***
        $sql = "DELETE FROM event_schedule_tickets
                WHERE event_schedule_id = :event_schedule_id AND ticket_id IN ($ticket_ids_in_clause)";
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            
            $stmt->bindValue(':event_schedule_id', $event_schedule_id, PDO::PARAM_INT);
            
            // Bind values for IN clause
            foreach ($ticket_id_bind_values as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            }

            $stmt->execute();
            $affected_rows = $stmt->rowCount();
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => "$affected_rows ticket(s) permanently deleted.", 'affected_rows' => $affected_rows]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error deleting tickets: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error deleting tickets: ' . $e->getMessage()]);
        }
        break;

    default:
        // If an invalid action is requested, return an error.
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        break;
}
