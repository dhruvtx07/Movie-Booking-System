<?php
// schedule_handler.php
session_start();
header('Content-Type: application/json'); // Crucial: tell the browser to expect JSON

require_once 'Database.php'; // Include your database connection class

$response = array('success' => false, 'message' => 'Invalid request.');

// Initialize database connection
$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    echo json_encode(array('success' => false, 'message' => 'Database connection failed.'));
    exit();
}

// Helper function to check for schedule overlaps (for single and bulk create/edit)
function checkOverlap($conn, $venue_id, $new_start_datetime, $new_end_datetime, $exclude_schedule_id = null) {
    try {
        $query = "SELECT COUNT(*) FROM venue_schedule
                  WHERE venue_id = :venue_id
                  AND is_active = 'yes' -- Only check active schedules for overlap
                  AND (
                      (:new_start_datetime < slot_ends_at AND :new_end_datetime > slot_starts_at)
                  )";

        if ($exclude_schedule_id !== null) {
            $query .= " AND venue_schedule_id != :exclude_schedule_id";
        }

        $stmt = $conn->prepare($query);
        $stmt->bindParam(":venue_id", $venue_id);
        $stmt->bindParam(":new_start_datetime", $new_start_datetime);
        $stmt->bindParam(":new_end_datetime", $new_end_datetime);
        if ($exclude_schedule_id !== null) {
            $stmt->bindParam(":exclude_schedule_id", $exclude_schedule_id);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();

        return $count > 0;
    } catch (PDOException $e) {
        error_log("Overlap check error: " . $e->getMessage());
        return true; // Assume overlap on error for safety
    }
}

// Determine if the request is GET or POST and the action
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

switch ($action) {
    case 'get_venues_with_schedule_counts':
        try {
            // Sanitize and validate inputs
            $search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
            $city_id = filter_input(INPUT_GET, 'city_id', FILTER_VALIDATE_INT);
            $is_active_filter = filter_input(INPUT_GET, 'is_active', FILTER_SANITIZE_STRING);
            $start_date_filter = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_STRING);
            $end_date_filter = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_STRING);
            $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
            $limit = 12; // Number of venues per page

            $offset = ($page - 1) * $limit;

            $where_clauses = [];
            $params = [];

            if ($search) {
                $where_clauses[] = "(v.venue_name LIKE :search OR v.sub_venue_name LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            if ($city_id) {
                $where_clauses[] = "v.city_id = :city_id";
                $params[':city_id'] = $city_id;
            }
            if ($is_active_filter && ($is_active_filter == 'yes' || $is_active_filter == 'no')) {
                $where_clauses[] = "v.is_active = :is_active_filter";
                $params[':is_active_filter'] = $is_active_filter;
            }

            $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

            // Query for venues (paged) and their cities
            $query = "
                SELECT
                    v.venue_id,
                    v.venue_name,
                    v.sub_venue_name,
                    c.city_name,
                    v.is_active,
                    (
                        SELECT COUNT(vs.venue_schedule_id)
                        FROM venue_schedule vs
                        WHERE vs.venue_id = v.venue_id
                        AND vs.is_active = 'yes'
                        " . ($start_date_filter ? "AND DATE(vs.slot_starts_at) >= :start_date_filter" : "") . "
                        " . ($end_date_filter ? "AND DATE(vs.slot_ends_at) <= :end_date_filter" : "") . "
                    ) as schedules_in_selected_range_count
                FROM venues v
                LEFT JOIN cities c ON v.city_id = c.city_id
                $where_sql
                ORDER BY v.venue_name ASC
                LIMIT :limit OFFSET :offset";

            $stmt = $conn->prepare($query);
            foreach ($params as $key => &$val) {
                $stmt->bindParam($key, $val, PDO::PARAM_STR);
            }
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

            // Bind date filters for the subquery if they exist
            if ($start_date_filter) {
                $stmt->bindParam(':start_date_filter', $start_date_filter, PDO::PARAM_STR);
            }
            if ($end_date_filter) {
                $stmt->bindParam(':end_date_filter', $end_date_filter, PDO::PARAM_STR);
            }

            $stmt->execute();
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get total venues count for pagination
            $total_venues_query = "SELECT COUNT(*) FROM venues v LEFT JOIN cities c ON v.city_id = c.city_id $where_sql";
            $total_stmt = $conn->prepare($total_venues_query);
            foreach ($params as $key => &$val) {
                $total_stmt->bindParam($key, $val, PDO::PARAM_STR);
            }
            $total_stmt->execute();
            $total_venues = $total_stmt->fetchColumn();
            $total_pages_venues = ceil($total_venues / $limit);

            // Calculate overall stats for the dashboard
            $total_schedules_in_range_count = 0;
            $active_schedules_in_range_count = 0;
            $upcoming_schedules_7_days_count = 0;

            $stats_sql = "SELECT COUNT(*) AS total, SUM(CASE WHEN vs.is_active = 'yes' THEN 1 ELSE 0 END) AS active
                          FROM venue_schedule vs
                          WHERE 1=1 ";

            $stats_params = [];

            if ($start_date_filter) {
                $stats_sql .= " AND DATE(vs.slot_starts_at) >= :stats_start_date";
                $stats_params[':stats_start_date'] = $start_date_filter;
            }
            if ($end_date_filter) {
                $stats_sql .= " AND DATE(vs.slot_ends_at) <= :stats_end_date";
                $stats_params[':stats_end_date'] = $end_date_filter;
            }

            $stmt_stats = $conn->prepare($stats_sql);
            foreach ($stats_params as $key => &$val) {
                $stmt_stats->bindParam($key, $val, PDO::PARAM_STR);
            }
            $stmt_stats->execute();
            $range_stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
            $total_schedules_in_range_count = $range_stats['total'] ?? 0;
            $active_schedules_in_range_count = $range_stats['active'] ?? 0;

            // Upcoming schedules (next 7 days)
            $today = date('Y-m-d');
            $seven_days_later = date('Y-m-d', strtotime('+6 days')); // Today + 6 days = 7-day range

            $stmt_upcoming = $conn->prepare("SELECT COUNT(*) FROM venue_schedule WHERE is_active = 'yes' AND DATE(slot_starts_at) BETWEEN :today AND :seven_days_later");
            $stmt_upcoming->bindParam(':today', $today);
            $stmt_upcoming->bindParam(':seven_days_later', $seven_days_later);
            $stmt_upcoming->execute();
            $upcoming_schedules_7_days_count = $stmt_upcoming->fetchColumn();

            $response = array(
                'success' => true,
                'venues' => $venues,
                'total_venues' => $total_venues,
                'total_pages_venues' => $total_pages_venues,
                'current_page' => $page,
                'stats' => [
                    'total_schedules_in_range' => $total_schedules_in_range_count,
                    'active_schedules_in_range' => $active_schedules_in_range_count,
                    'upcoming_schedules_7_days' => $upcoming_schedules_7_days_count
                ]
            );

        } catch (PDOException $e) {
            error_log("Error fetching venues: " . $e->getMessage());
            $response = array('success' => false, 'message' => "Database error fetching venues: " . $e->getMessage());
        }
        break;

    case 'bulk_update_venue_status':
        try {
            $venue_ids_raw = filter_input(INPUT_POST, 'venue_ids', FILTER_UNSAFE_RAW);
            $new_status = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);

            if (!in_array($new_status, ['yes', 'no']) || empty($venue_ids_raw)) {
                $response = array('success' => false, 'message' => 'Invalid status or venue IDs provided.');
                break;
            }

            $venue_ids = json_decode($venue_ids_raw, true);
            if (!is_array($venue_ids) || count($venue_ids) === 0) {
                $response = array('success' => false, 'message' => 'No venue IDs provided.');
                break;
            }

            // Create a comma-separated string of placeholders for the IN clause
            $placeholders = implode(',', array_fill(0, count($venue_ids), '?'));
            $query = "UPDATE venues SET is_active = ? WHERE venue_id IN ($placeholders)";
            $stmt = $conn->prepare($query);

            // Bind values: first param is new_status, then venue_ids
            $bind_values = array_merge([$new_status], $venue_ids);
            $stmt->execute($bind_values);

            $response = array('success' => true, 'message' => 'Venue status updated successfully.');

        } catch (PDOException $e) {
            error_log("Error bulk updating venue status: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during bulk venue status update.');
        }
        break;

    case 'get_detailed_schedule_info':
        try {
            $venue_id = filter_input(INPUT_GET, 'venue_id', FILTER_VALIDATE_INT);
            $time_range_type = filter_input(INPUT_GET, 'time_range_type', FILTER_SANITIZE_STRING);
            $status_filter = filter_input(INPUT_GET, 'status_filter', FILTER_SANITIZE_STRING);
            $date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING); // For daily view
            $single_schedule_id = filter_input(INPUT_GET, 'schedule_id', FILTER_VALIDATE_INT); // For editing a single schedule

            if (!$venue_id) {
                $response = array('success' => false, 'message' => 'Venue ID is required for schedule details.');
                break;
            }

            $schedules = [];
            $stats = ['total' => 0, 'active' => 0]; // Initialize stats for the modal

            if ($single_schedule_id) { // Fetching a single schedule for edit modal
                $query = "SELECT
                            venue_schedule_id,
                            venue_id,
                            DATE_FORMAT(slot_starts_at, '%Y-%m-%d') as slot_date,
                            DATE_FORMAT(slot_starts_at, '%H:%i') as slot_start_time,
                            DATE_FORMAT(slot_ends_at, '%H:%i') as slot_end_time,
                            is_active
                          FROM venue_schedule
                          WHERE venue_schedule_id = :schedule_id AND venue_id = :venue_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':schedule_id', $single_schedule_id, PDO::PARAM_INT);
                $stmt->bindParam(':venue_id', $venue_id, PDO::PARAM_INT);
                $stmt->execute();
                $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($schedule) {
                    $response = array('success' => true, 'schedule' => $schedule);
                } else {
                    $response = array('success' => false, 'message' => 'Schedule not found or not associated with this venue.');
                }
                break; // Exit after handling single schedule fetch
            }

            // Otherwise, fetch multiple schedules based on time_range_type
            $where_clauses = ["venue_id = :venue_id"];
            $query_params = [':venue_id' => $venue_id];

            if ($status_filter && $status_filter != 'all') { // Check for 'all' explicitly, otherwise filter
                $where_clauses[] = "is_active = :status_filter";
                $query_params[':status_filter'] = $status_filter;
            }

            switch ($time_range_type) {
                case 'daily':
                    if (!$date) {
                        $response = array('success' => false, 'message' => 'Date is required for daily view.');
                        break 2; // Break out of switch and outer switch
                    }
                    $where_clauses[] = "DATE(slot_starts_at) = :selected_date";
                    $query_params[':selected_date'] = $date;
                    break;
                case 'weekly':
                    $today_date = date('Y-m-d');
                    $seven_days_later = date('Y-m-d', strtotime('+6 days'));
                    $where_clauses[] = "DATE(slot_starts_at) BETWEEN :today_date AND :seven_days_later";
                    $query_params[':today_date'] = $today_date;
                    $query_params[':seven_days_later'] = $seven_days_later;
                    break;
                case 'monthly': // Next 30 days
                    $today_date = date('Y-m-d');
                    $thirty_days_later = date('Y-m-d', strtotime('+29 days'));
                    $where_clauses[] = "DATE(slot_starts_at) BETWEEN :today_date AND :thirty_days_later";
                    $query_params[':today_date'] = $today_date;
                    $query_params[':thirty_days_later'] = $thirty_days_later;
                    break;
                default:
                    $response = array('success' => false, 'message' => 'Invalid time range type.');
                    break 2;
            }

            $query = "SELECT venue_schedule_id, slot_starts_at, slot_ends_at, is_active
                      FROM venue_schedule
                      WHERE " . implode(" AND ", $where_clauses) . "
                      ORDER BY slot_starts_at ASC";

            $stmt = $conn->prepare($query);
            $stmt->execute($query_params);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate stats for the current view
            $stats['total'] = count($schedules);
            $stats['active'] = array_reduce($schedules, function($carry, $item) {
                return $carry + ($item['is_active'] === 'yes' ? 1 : 0);
            }, 0);

            $response = array(
                'success' => true,
                'schedules' => $schedules,
                'stats' => $stats
            );

        } catch (PDOException $e) {
            error_log("Error fetching detailed schedule info: " . $e->getMessage());
            $response = array('success' => false, 'message' => "Database error fetching schedules: " . $e->getMessage());
        }
        break;

    case 'create_single_schedule':
        try {
            $venue_id = filter_input(INPUT_POST, 'venue_id', FILTER_VALIDATE_INT);
            $slot_date = filter_input(INPUT_POST, 'slot_date', FILTER_SANITIZE_STRING);
            $slot_start_time = filter_input(INPUT_POST, 'slot_start_time', FILTER_SANITIZE_STRING);
            $slot_end_time = filter_input(INPUT_POST, 'slot_end_time', FILTER_SANITIZE_STRING);
            $is_active_schedule = filter_input(INPUT_POST, 'is_active_schedule', FILTER_SANITIZE_STRING);
            $created_by = $_SESSION['user_id'] ?? 1; // Fallback to 1 if user ID not in session

            if (!$venue_id || !$slot_date || !$slot_start_time || !$slot_end_time || !in_array($is_active_schedule, ['yes', 'no'])) {
                $response = array('success' => false, 'message' => 'Missing or invalid input for schedule slot.');
                break;
            }

            $slot_starts_at = "$slot_date $slot_start_time:00";
            $slot_ends_at = "$slot_date $slot_end_time:00";

            if (strtotime($slot_starts_at) >= strtotime($slot_ends_at)) {
                $response = array('success' => false, 'message' => 'End time must be after start time.');
                break;
            }

            // Only check overlap if the new schedule is 'active'
            if ($is_active_schedule === 'yes' && checkOverlap($conn, $venue_id, $slot_starts_at, $slot_ends_at)) {
                $response = array('success' => false, 'message' => 'Schedule slot overlaps with an existing active schedule. Please choose a different time or date.');
                break;
            }
            
            $query = "INSERT INTO venue_schedule (venue_id, slot_starts_at, slot_ends_at, created_by, created_on, is_active, is_vacant)
                      VALUES (:venue_id, :slot_starts_at, :slot_ends_at, :created_by, NOW(), :is_active, 'yes')";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':venue_id', $venue_id, PDO::PARAM_INT);
            $stmt->bindParam(':slot_starts_at', $slot_starts_at);
            $stmt->bindParam(':slot_ends_at', $slot_ends_at);
            $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $is_active_schedule);

            $stmt->execute();
            $response = array('success' => true, 'message' => 'Schedule slot added successfully.');

        } catch (PDOException $e) {
            error_log("Error creating single schedule: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error adding schedule slot.');
        }
        break;

    case 'update_schedule':
        try {
            $venue_schedule_id = filter_input(INPUT_POST, 'venue_schedule_id', FILTER_VALIDATE_INT);
            $venue_id = filter_input(INPUT_POST, 'venue_id', FILTER_VALIDATE_INT); // Required for ownership check, can also be from session
            $slot_date = filter_input(INPUT_POST, 'slot_date', FILTER_SANITIZE_STRING);
            $slot_start_time = filter_input(INPUT_POST, 'slot_start_time', FILTER_SANITIZE_STRING);
            $slot_end_time = filter_input(INPUT_POST, 'slot_end_time', FILTER_SANITIZE_STRING);
            $is_active_schedule = filter_input(INPUT_POST, 'is_active_schedule', FILTER_SANITIZE_STRING);

            if (!$venue_schedule_id || !$venue_id || !$slot_date || !$slot_start_time || !$slot_end_time || !in_array($is_active_schedule, ['yes', 'no'])) {
                $response = array('success' => false, 'message' => 'Missing or invalid input for schedule update.');
                break;
            }

            $slot_starts_at = "$slot_date $slot_start_time:00";
            $slot_ends_at = "$slot_date $slot_end_time:00";

            if (strtotime($slot_starts_at) >= strtotime($slot_ends_at)) {
                $response = array('success' => false, 'message' => 'End time must be after start time.');
                break;
            }

            // Only check overlap if the new schedule is 'active'
            if ($is_active_schedule === 'yes' && checkOverlap($conn, $venue_id, $slot_starts_at, $slot_ends_at, $venue_schedule_id)) {
                $response = array('success' => false, 'message' => 'Updated schedule slot overlaps with an existing active schedule. Please choose a different time or date.');
                break;
            }

            $query = "UPDATE venue_schedule SET
                        slot_starts_at = :slot_starts_at,
                        slot_ends_at = :slot_ends_at,
                        is_active = :is_active_schedule
                      WHERE venue_schedule_id = :venue_schedule_id AND venue_id = :venue_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':slot_starts_at', $slot_starts_at);
            $stmt->bindParam(':slot_ends_at', $slot_ends_at);
            $stmt->bindParam(':is_active_schedule', $is_active_schedule);
            $stmt->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
            $stmt->bindParam(':venue_id', $venue_id, PDO::PARAM_INT);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $response = array('success' => true, 'message' => 'Schedule slot updated successfully.');
            } else {
                $response = array('success' => false, 'message' => 'No changes made or schedule not found.');
            }

        } catch (PDOException $e) {
            error_log("Error updating schedule: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error updating schedule slot.');
        }
        break;

    case 'bulk_update_schedule_status':
        try {
            $schedule_ids_raw = filter_input(INPUT_POST, 'schedule_ids', FILTER_UNSAFE_RAW);
            $new_status = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);

            if (!in_array($new_status, ['yes', 'no']) || empty($schedule_ids_raw)) {
                $response = array('success' => false, 'message' => 'Invalid status or schedule IDs provided.');
                break;
            }

            $schedule_ids = json_decode($schedule_ids_raw, true);
            if (!is_array($schedule_ids) || count($schedule_ids) === 0) {
                $response = array('success' => false, 'message' => 'No schedule IDs provided.');
                break;
            }

            $placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));
            $query = "UPDATE venue_schedule SET is_active = ? WHERE venue_schedule_id IN ($placeholders)";
            $stmt = $conn->prepare($query);

            $bind_values = array_merge([$new_status], $schedule_ids);
            $stmt->execute($bind_values);

            $response = array('success' => true, 'message' => 'Selected schedule slots status updated successfully.');

        } catch (PDOException $e) {
            error_log("Error bulk updating schedule status: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during bulk schedule status update.');
        }
        break;

    case 'delete_schedules_hard': // New action for hard deletion
        try {
            $schedule_ids_raw = filter_input(INPUT_POST, 'schedule_ids', FILTER_UNSAFE_RAW);

            if (empty($schedule_ids_raw)) {
                $response = array('success' => false, 'message' => 'No schedule IDs provided for deletion.');
                break;
            }

            $schedule_ids = json_decode($schedule_ids_raw, true);
            if (!is_array($schedule_ids) || count($schedule_ids) === 0) {
                $response = array('success' => false, 'message' => 'Invalid schedule IDs format.');
                break;
            }

            $placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));
            $query = "DELETE FROM venue_schedule WHERE venue_schedule_id IN ($placeholders)";
            $stmt = $conn->prepare($query);

            $stmt->execute($schedule_ids);
            $deleted_count = $stmt->rowCount();

            if ($deleted_count > 0) {
                $response = array('success' => true, 'message' => "Successfully deleted {$deleted_count} schedule slot(s).");
            } else {
                $response = array('success' => false, 'message' => 'No schedule slots found matching the provided IDs for deletion.');
            }

        } catch (PDOException $e) {
            error_log("Error hard deleting schedules: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during hard deletion of schedules.');
        }
        break;

    case 'create_bulk_schedules':
        try {
            $venue_id = filter_input(INPUT_POST, 'venue_id', FILTER_VALIDATE_INT);
            $slot_date = filter_input(INPUT_POST, 'slot_date', FILTER_SANITIZE_STRING);
            $first_start_time = filter_input(INPUT_POST, 'first_start_time', FILTER_SANITIZE_STRING);
            $last_end_time = filter_input(INPUT_POST, 'last_end_time', FILTER_SANITIZE_STRING);
            $slot_duration_minutes = filter_input(INPUT_POST, 'slot_duration_minutes', FILTER_VALIDATE_INT);
            $break_duration_minutes = filter_input(INPUT_POST, 'break_duration_minutes', FILTER_VALIDATE_INT);
            $created_by = $_SESSION['user_id'] ?? 1; // Fallback to 1

            if (!$venue_id || !$slot_date || !$first_start_time || !$last_end_time || $slot_duration_minutes === false || $break_duration_minutes === false) {
                $response = array('success' => false, 'message' => 'Missing or invalid input for bulk schedule generation.');
                break;
            }
            if ($slot_duration_minutes <= 0) {
                $response = array('success' => false, 'message' => 'Slot duration must be greater than 0.');
                break;
            }
            if ($break_duration_minutes < 0) {
                $response = array('success' => false, 'message' => 'Break duration cannot be negative.');
                break;
            }

            $current_slot_start_time = strtotime("$slot_date $first_start_time");
            $day_end_time_boundary = strtotime("$slot_date $last_end_time");

            if ($current_slot_start_time >= $day_end_time_boundary) {
                $response = array('success' => false, 'message' => 'Day end time must be after day start time.');
                break;
            }

            $generated_slots = 0;
            $skipped_slots = 0; // Renamed for clarity (not 'failed' but 'skipped' due to overlap/boundaries)
            $conn->beginTransaction(); // Start a transaction

            $current_start_timestamp = $current_slot_start_time;

            while (true) {
                $slot_end_timestamp = $current_start_timestamp + ($slot_duration_minutes * 60); // Convert minutes to seconds

                if ($slot_end_timestamp > $day_end_time_boundary) {
                    // This slot goes beyond the day's end time, or there's not enough room for a full slot
                    break;
                }

                $slot_starts_at_db = date('Y-m-d H:i:s', $current_start_timestamp);
                $slot_ends_at_db = date('Y-m-d H:i:s', $slot_end_timestamp);

                // Check for overlap before inserting
                if (checkOverlap($conn, $venue_id, $slot_starts_at_db, $slot_ends_at_db)) {
                    $skipped_slots++; // Count as skipped due to overlap
                } else {
                    $insert_query = "INSERT INTO venue_schedule (venue_id, slot_starts_at, slot_ends_at, created_by, created_on, is_active, is_vacant)
                                     VALUES (:venue_id, :slot_starts_at, :slot_ends_at, :created_by, NOW(), 'yes', 'yes')";
                    $stmt_insert = $conn->prepare($insert_query);
                    $stmt_insert->bindParam(':venue_id', $venue_id, PDO::PARAM_INT);
                    $stmt_insert->bindParam(':slot_starts_at', $slot_starts_at_db);
                    $stmt_insert->bindParam(':slot_ends_at', $slot_ends_at_db);
                    $stmt_insert->bindParam(':created_by', $created_by, PDO::PARAM_INT);
                    $stmt_insert->execute();
                    $generated_slots++;
                }

                // Prepare for next slot
                $next_slot_start_timestamp = $slot_end_timestamp + ($break_duration_minutes * 60);

                // If the next slot starts after or exactly at the day ends, stop
                if ($next_slot_start_timestamp >= $day_end_time_boundary) {
                    break;
                }
                $current_start_timestamp = $next_slot_start_timestamp;
            }
            $conn->commit(); // Commit transaction

            // Provide more detailed message about skipped vs generated
            $message = "Successfully generated {$generated_slots} schedule slot(s).";
            if ($skipped_slots > 0) {
                 $message .= " {$skipped_slots} slot(s) skipped due to overlaps or not fitting within the time frame.";
            }

            $response = array(
                'success' => true,
                'message' => $message
            );

        } catch (PDOException $e) {
            $conn->rollBack(); // Rollback on error
            error_log("Error creating bulk schedules: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during bulk schedule generation.');
        }
        break;

    default:
        $response = array('success' => false, 'message' => 'Unknown action or method.');
        break;
}

echo json_encode($response);
exit();
?>
