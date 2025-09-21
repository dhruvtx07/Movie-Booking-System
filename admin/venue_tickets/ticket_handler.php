<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config/db_config.php';
 // Ensure this path is correct and $pdo is defined

date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');

$current_user_id = 1; // Hardcoded user ID for demonstration. Replace with $_SESSION['user_id'] in a real app.
$action = $_REQUEST['action'] ?? ''; // Use $_REQUEST to handle both GET and POST for action

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

function get_venue_ticket_stats($pdo, $venue_id) {
    $stats = [
        'total_tickets' => 0,
        'available_tickets' => 0,
        'by_type' => [],
        'by_price' => [],
    ];

    // Total and available
    $stmt = $pdo->prepare("SELECT 
                                COUNT(*) as total, 
                                SUM(CASE WHEN is_vacant = 'yes' AND is_active = 'yes' THEN 1 ELSE 0 END) as available 
                                FROM venue_tickets 
                                WHERE venue_id = :venue_id AND is_active = 'yes'"); // Count only active tickets for stats
    $stmt->execute(['venue_id' => $venue_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stats['total_tickets'] = (int)$row['total'];
        $stats['available_tickets'] = (int)$row['available'];
    }

    // By type
    $stmt = $pdo->prepare("SELECT ticket_type, COUNT(*) as count 
                                FROM venue_tickets 
                                WHERE venue_id = :venue_id AND is_active = 'yes'
                                GROUP BY ticket_type");
    $stmt->execute(['venue_id' => $venue_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_type'][$row['ticket_type']] = (int)$row['count'];
    }

    // By price
    $stmt = $pdo->prepare("SELECT ticket_price, COUNT(*) as count 
                                FROM venue_tickets 
                                WHERE venue_id = :venue_id AND is_active = 'yes'
                                GROUP BY ticket_price");
    $stmt->execute(['venue_id' => $venue_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_price'][(string)$row['ticket_price']] = (int)$row['count'];
    }
    return $stats;
}


switch ($action) {
    case 'get_venues':
        try {
            $search_term = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_SPECIAL_CHARS); // Use filter_var or htmlspecialchars for older PHP
            $city_id = filter_input(INPUT_GET, 'city_id', FILTER_VALIDATE_INT);
            $is_active_filter = filter_input(INPUT_GET, 'is_active', FILTER_SANITIZE_SPECIAL_CHARS); // yes, no, or ""

            $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
            $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 8, 'min_range' => 1]]);
            $offset = ($page - 1) * $limit;

            $base_sql = "FROM venues v JOIN cities c ON v.city_id = c.city_id";
            $stats_join_sql = "LEFT JOIN (
                                SELECT
                                    venue_id,
                                    COUNT(ticket_id) AS total_tickets,
                                    SUM(CASE WHEN is_vacant = 'yes' AND is_active = 'yes' THEN 1 ELSE 0 END) AS available_tickets
                                FROM
                                    venue_tickets
                                WHERE is_active = 'yes'
                                GROUP BY
                                    venue_id
                            ) vt_stats ON v.venue_id = vt_stats.venue_id";
            
            $where_clauses = [];
            $params = [];

            if (!empty($search_term)) {
                $where_clauses[] = "(v.venue_name LIKE :search OR v.sub_venue_name LIKE :search)";
                $params[':search'] = "%" . $search_term . "%";
            }
            if (!empty($city_id)) {
                $where_clauses[] = "v.city_id = :city_id";
                $params[':city_id'] = $city_id;
            }
            if ($is_active_filter === 'yes' || $is_active_filter === 'no') {
                $where_clauses[] = "v.is_active = :is_active_filter";
                $params[':is_active_filter'] = $is_active_filter;
            }
            
            $where_sql = "";
            if (count($where_clauses) > 0) {
                $where_sql = " WHERE " . implode(" AND ", $where_clauses);
            }

            // Count total venues for pagination
            $count_stmt = $pdo->prepare("SELECT COUNT(v.venue_id) " . $base_sql . $where_sql);
            $count_stmt->execute($params);
            $total_venues = (int)$count_stmt->fetchColumn();
            $total_pages = ceil($total_venues / $limit);

            // Fetch venues for the current page
            $sql = "SELECT v.venue_id, v.venue_name, v.sub_venue_name, v.capacity, v.is_active, c.city_name,
                           COALESCE(vt_stats.total_tickets, 0) AS total_tickets,
                           COALESCE(vt_stats.available_tickets, 0) AS available_tickets
                    " . $base_sql . " " . $stats_join_sql . $where_sql . "
                    ORDER BY v.venue_name, v.sub_venue_name 
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            // Bind common params
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true, 
                'venues' => $venues, 
                'total_venues' => $total_venues, 
                'total_pages' => $total_pages,
                'current_page' => $page
            ]);

        } catch (PDOException $e) {
            error_log("Error in get_venues: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error fetching venues. ' . $e->getMessage()]);
        }
        break;

    case 'get_venue_ticket_data':
        $venue_id = filter_input(INPUT_GET, 'venue_id', FILTER_VALIDATE_INT);
        if (!$venue_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid Venue ID.']);
            exit;
        }
        try {
            // ***** FIX: Added ticket_id to the SELECT statement *****
            $stmt = $pdo->prepare("SELECT ticket_id, ticket_location, ticket_row, ticket_column, ticket_type, ticket_price, is_vacant 
                                    FROM venue_tickets 
                                    WHERE venue_id = :venue_id AND is_active = 'yes'
                                    ORDER BY ticket_row, LPAD(ticket_column, 3, '0')"); // LPAD for better numerical sort on column
            $stmt->execute(['venue_id' => $venue_id]);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stats = get_venue_ticket_stats($pdo, $venue_id);

            echo json_encode(['success' => true, 'tickets' => $tickets, 'stats' => $stats]);
        } catch (PDOException $e) {
            error_log("Error in get_venue_ticket_data: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error fetching ticket data.']);
        }
        break;

    case 'generate_single_ticket':
        $venue_id = filter_input(INPUT_POST, 'venue_id', FILTER_VALIDATE_INT);
        $ticket_row = strtoupper(trim($_POST['ticket_row'] ?? ''));
        $ticket_column = filter_input(INPUT_POST, 'ticket_column', FILTER_VALIDATE_INT);
        $ticket_type = trim($_POST['ticket_type'] ?? '');
        $ticket_price = filter_input(INPUT_POST, 'ticket_price', FILTER_VALIDATE_FLOAT);

        if (!$venue_id || empty($ticket_row) || !$ticket_column || empty($ticket_type) || $ticket_price === false || $ticket_price < 0) {
            echo json_encode(['success' => false, 'message' => 'Missing or invalid ticket data.']);
            exit;
        }
        
        if (!preg_match('/^[A-Z]{1,3}$/', $ticket_row)) {
            echo json_encode(['success' => false, 'message' => 'Invalid ticket row format. Use 1-3 uppercase letters.']);
            exit;
        }

        $ticket_location = $ticket_row . $ticket_column;

        try {
            // Check for duplicate active ticket
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM venue_tickets WHERE venue_id = :venue_id AND ticket_location = :ticket_location AND is_active = 'yes'");
            $stmt->execute(['venue_id' => $venue_id, 'ticket_location' => $ticket_location]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => "Active ticket '$ticket_location' already exists for this venue."]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO venue_tickets 
                                    (venue_id, ticket_location, ticket_row, ticket_column, ticket_type, ticket_price, is_vacant, created_by, created_at, is_active) 
                                    VALUES 
                                    (:venue_id, :ticket_location, :ticket_row, :ticket_column, :ticket_type, :ticket_price, 'yes', :created_by, NOW(), 'yes')");
            $stmt->execute([
                'venue_id' => $venue_id,
                'ticket_location' => $ticket_location,
                'ticket_row' => $ticket_row,
                'ticket_column' => $ticket_column,
                'ticket_type' => $ticket_type,
                'ticket_price' => $ticket_price,
                'created_by' => $current_user_id
            ]);

            $new_stats = get_venue_ticket_stats($pdo, $venue_id);
            echo json_encode(['success' => true, 'message' => "Ticket '$ticket_location' generated successfully.", 'new_stats' => $new_stats]);

        } catch (PDOException $e) {
            error_log("Error in generate_single_ticket: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    case 'generate_bulk_tickets':
        $venue_id = filter_input(INPUT_POST, 'venue_id', FILTER_VALIDATE_INT);
        $row_start_char = strtoupper(trim($_POST['row_start'] ?? ''));
        $row_end_char = strtoupper(trim($_POST['row_end'] ?? ''));
        $col_start = filter_input(INPUT_POST, 'col_start', FILTER_VALIDATE_INT);
        $col_end = filter_input(INPUT_POST, 'col_end', FILTER_VALIDATE_INT);
        $ticket_type = trim($_POST['ticket_type'] ?? '');
        $ticket_price = filter_input(INPUT_POST, 'ticket_price', FILTER_VALIDATE_FLOAT);

        if (!$venue_id || empty($row_start_char) || empty($row_end_char) ||
            !$col_start || !$col_end || empty($ticket_type) || $ticket_price === false || $ticket_price < 0) {
            echo json_encode(['success' => false, 'message' => 'Missing or invalid bulk ticket data.']);
            exit;
        }
        
        if (strlen($row_start_char) !== 1 || strlen($row_end_char) !== 1 ||
            !ctype_alpha($row_start_char) || !ctype_alpha($row_end_char) ||
            ord($row_start_char) > ord($row_end_char)) { // Corrected: ord() for comparison
            echo json_encode(['success' => false, 'message' => 'Invalid row range. Rows must be single letters (A-Z) and start <= end.']);
            exit;
        }
        if ($col_start > $col_end) {
            echo json_encode(['success' => false, 'message' => 'Invalid column range. Start column must be <= end column.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $insert_stmt = $pdo->prepare("INSERT INTO venue_tickets 
                                (venue_id, ticket_location, ticket_row, ticket_column, ticket_type, ticket_price, is_vacant, created_by, created_at, is_active) 
                                VALUES 
                                (:venue_id, :ticket_location, :ticket_row, :ticket_column, :ticket_type, :ticket_price, 'yes', :created_by, NOW(), 'yes')");
            
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM venue_tickets WHERE venue_id = :venue_id AND ticket_location = :ticket_location AND is_active = 'yes'");

            $generated_count = 0;
            $skipped_count = 0;

            for ($r_char_code = ord($row_start_char); $r_char_code <= ord($row_end_char); $r_char_code++) {
                $current_row = chr($r_char_code);
                for ($current_col = $col_start; $current_col <= $col_end; $current_col++) {
                    $ticket_location = $current_row . $current_col;

                    $check_stmt->execute(['venue_id' => $venue_id, 'ticket_location' => $ticket_location]);
                    if ($check_stmt->fetchColumn() > 0) {
                        $skipped_count++;
                        continue; 
                    }
                    
                    $insert_stmt->execute([
                        'venue_id' => $venue_id,
                        'ticket_location' => $ticket_location,
                        'ticket_row' => $current_row,
                        'ticket_column' => $current_col,
                        'ticket_type' => $ticket_type,
                        'ticket_price' => $ticket_price,
                        'created_by' => $current_user_id
                    ]);
                    if ($insert_stmt->rowCount() > 0) {
                        $generated_count++;
                    }
                }
            }
            $pdo->commit();
            
            $message = "$generated_count tickets generated successfully.";
            if ($skipped_count > 0) {
                $message .= " $skipped_count tickets were skipped as they already existed (active).";
            }
            $new_stats = get_venue_ticket_stats($pdo, $venue_id);
            echo json_encode(['success' => true, 'message' => $message, 'generated_count' => $generated_count, 'skipped_count' => $skipped_count, 'new_stats' => $new_stats]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error in generate_bulk_tickets: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error during bulk generation: ' . $e->getMessage()]);
        }
        break;
    
  case 'update_tickets':
    $venue_id = filter_input(INPUT_POST, 'venue_id', FILTER_VALIDATE_INT);
    $ticket_ids_json = $_POST['ticket_ids'] ?? '[]'; // Ensure this is how it's sent from frontend
    $ticket_ids = json_decode($ticket_ids_json, true);

    // Validate essential inputs
    if (!$venue_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid Venue ID.']);
        exit;
    }
    if (!is_array($ticket_ids) || empty($ticket_ids)) {
        echo json_encode(['success' => false, 'message' => 'No ticket IDs provided or invalid format.']);
        exit;
    }

    // Sanitize ticket_ids to ensure they are integers
    $sanitized_ticket_ids = [];
    foreach ($ticket_ids as $tid) {
        $validated_tid = filter_var($tid, FILTER_VALIDATE_INT);
        if ($validated_tid !== false) {
            $sanitized_ticket_ids[] = $validated_tid;
        }
        // Optionally, else log/error if a non-integer ID is found
    }

    if (empty($sanitized_ticket_ids)) {
        echo json_encode(['success' => false, 'message' => 'No valid ticket IDs provided after sanitization.']);
        exit;
    }
    $ticket_ids = $sanitized_ticket_ids; // Use the sanitized list

    $new_type = trim($_POST['ticket_type'] ?? '');
    $new_price_str = trim($_POST['ticket_price'] ?? ''); // Price as string to check if it was sent
    $new_is_vacant = trim($_POST['is_vacant'] ?? '');

    // Check if any actual update values are provided
    if (empty($new_type) && $new_price_str === '' && empty($new_is_vacant)) {
        echo json_encode(['success' => false, 'message' => 'No changes specified for update.']);
        exit;
    }
    
    $update_sql_parts = []; // To build "column = ?" parts for the SET clause
    $update_values = [];    // To store parameter values for the SET clause placeholders

    if (!empty($new_type)) {
        $update_sql_parts[] = "ticket_type = ?";
        $update_values[] = $new_type;
    }

    if ($new_price_str !== '') { // Check if price field was submitted (even if '0')
        $new_price = filter_var($new_price_str, FILTER_VALIDATE_FLOAT);
        // DB `ticket_price` is INT. PHP is validating as FLOAT. MySQL will typically truncate.
        // Consider if this behavior is acceptable (e.g. 150.75 becomes 150).
        if ($new_price === false || $new_price < 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ticket price. Price must be a non-negative number.']);
            exit;
        }
        $update_sql_parts[] = "ticket_price = ?";
        $update_values[] = $new_price;
    }

    if (!empty($new_is_vacant)) {
        if ($new_is_vacant === 'yes' || $new_is_vacant === 'no') {
            $update_sql_parts[] = "is_vacant = ?";
            $update_values[] = $new_is_vacant;
        } else {
            // An invalid value for is_vacant was provided.
            // You might want to return an error or simply ignore this field.
            // For now, it's ignored if not 'yes' or 'no'.
        }
    }

    // If after processing, no valid fields are set to be updated
    if (empty($update_sql_parts)) {
        echo json_encode(['success' => false, 'message' => 'No valid changes to apply.']);
        exit;
    }
    
    // Add audit fields (assuming these are for "last modified by/at")
    $update_sql_parts[] = "created_by = ?"; // Should ideally be named "updated_by"
    $update_values[] = $current_user_id;
    $update_sql_parts[] = "created_at = NOW()"; // No placeholder for NOW(); Should be "updated_at"

    // Construct the SET part of the SQL query
    $sql_set_clause = implode(', ', $update_sql_parts);

    // Create positional placeholders for the IN clause (e.g., "?,?,?")
    $in_clause_placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
    
    // Construct the full SQL query using only positional placeholders
    $sql = "UPDATE venue_tickets SET " . $sql_set_clause . 
           " WHERE ticket_id IN (" . $in_clause_placeholders . ") AND venue_id = ?";

    // Assemble all parameters for PDO's execute() method. Order is crucial.
    $execute_params = $update_values;          // 1. Values for the SET clause
    foreach ($ticket_ids as $tid_val) {        // 2. Values for the IN clause (ticket_id IN (?,?))
        $execute_params[] = $tid_val;
    }
    $execute_params[] = $venue_id;             // 3. Value for venue_id = ?

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        
        // For debugging purposes:
        // error_log("Update SQL: " . $sql);
        // error_log("Update Params: " . json_encode($execute_params));

        $stmt->execute($execute_params);
        $affected_rows = $stmt->rowCount();
        $pdo->commit();

        $new_stats = get_venue_ticket_stats($pdo, $venue_id);
        echo json_encode([
            'success' => true, 
            'message' => "$affected_rows ticket(s) updated successfully.", 
            'affected_rows' => $affected_rows, 
            'new_stats' => $new_stats
        ]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error in update_tickets: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($execute_params));
        echo json_encode(['success' => false, 'message' => 'Database error updating tickets: ' . $e->getMessage()]);
    }
    break;

    case 'delete_tickets': // This will mark tickets as inactive
        $venue_id = filter_input(INPUT_POST, 'venue_id', FILTER_VALIDATE_INT);
        $ticket_ids_json = $_POST['ticket_ids'] ?? '[]';
        $ticket_ids = json_decode($ticket_ids_json, true);

        if (!$venue_id || !is_array($ticket_ids) || empty($ticket_ids)) {
            echo json_encode(['success' => false, 'message' => 'Invalid venue ID or no ticket IDs provided for deletion.']);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
        $sql = "UPDATE venue_tickets SET is_active = 'no', created_by = ?, created_at = NOW() 
                WHERE ticket_id IN ($placeholders) AND venue_id = ?";
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            
            $execute_params = [$current_user_id]; // For updated_by
            foreach ($ticket_ids as $id) {
                $execute_params[] = $id;
            }
            $execute_params[] = $venue_id; // For venue_id

            $stmt->execute($execute_params);
            $affected_rows = $stmt->rowCount();
            $pdo->commit();

            $new_stats = get_venue_ticket_stats($pdo, $venue_id); // Recalculate stats based on active tickets
            echo json_encode(['success' => true, 'message' => "$affected_rows ticket(s) marked as inactive.", 'affected_rows' => $affected_rows, 'new_stats' => $new_stats]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error in delete_tickets: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error deleting tickets: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        break;
}
?>