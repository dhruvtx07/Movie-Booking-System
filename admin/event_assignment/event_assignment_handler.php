<?php
// event_assignment_handler.php
session_start();
header('Content-Type: application/json'); // Crucial: tell the browser to expect JSON

require_once 'Database.php'; // Include your database connection class

$response = array('success' => false, 'message' => 'Invalid request.');

// Initialize database connection
$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    // CORRECTED: Output JSON on DB connection failure for AJAX handlers
    echo json_encode(array('success' => false, 'message' => 'Database connection failed.'));
    exit();
}

// Helper function to check if a schedule slot is already assigned to ANY event
function isSlotAssigned($conn, $venue_schedule_id) {
    try {
        // Updated to specifically check active assignments to avoid issues with soft-deleted or inactive assignments
        $query = "SELECT COUNT(*) FROM event_schedule WHERE venue_schedule_id = :venue_schedule_id AND is_active = 'yes'";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(":venue_schedule_id", $venue_schedule_id);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking slot assignment: " . $e->getMessage());
        return true; // Assume assigned on error for safety
    }
}

// Determine if the request is GET or POST and the action
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

switch ($action) {
    case 'get_events_with_assignment_counts':
        try {
            // Sanitize and validate inputs
            // Using FILTER_UNSAFE_RAW instead of FILTER_SANITIZE_STRING for search, as prepared statements handle escaping.
            $search = filter_input(INPUT_GET, 'search', FILTER_UNSAFE_RAW);
            $city_id = filter_input(INPUT_GET, 'city_id', FILTER_VALIDATE_INT);
            $is_active_filter = filter_input(INPUT_GET, 'is_active', FILTER_UNSAFE_RAW);
            $start_date_filter = filter_input(INPUT_GET, 'start_date', FILTER_UNSAFE_RAW);
            $end_date_filter = filter_input(INPUT_GET, 'end_date', FILTER_UNSAFE_RAW);
            $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
            $limit = 12; // Number of events per page

            $offset = ($page - 1) * $limit;

            $where_clauses = [];
            $params = [];

            // Base queries with joins
            $from_clause = "FROM event_info ei";
            $join_clause = "";

            if ($search) {
                $where_clauses[] = "(ei.event_name LIKE :search OR ei.event_desc LIKE :search OR ei.genre LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            if ($city_id) {
                // To filter events by city, we need to join through venue_schedule and venues
                $join_clause .= " INNER JOIN event_schedule es ON ei.event_id = es.event_id
                                  INNER JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
                                  INNER JOIN venues v ON vs.venue_id = v.venue_id";
                // Add a DISTINCT here because an event can have schedules in multiple venues in the same city.
                // Or simply filter on the city ID from the venues table.
                $where_clauses[] = "v.city_id = :city_id";
                $params[':city_id'] = $city_id;
            }
            if ($is_active_filter && ($is_active_filter == 'yes' || $is_active_filter == 'no')) {
                $where_clauses[] = "ei.is_active = :is_active_filter";
                $params[':is_active_filter'] = $is_active_filter;
            }
            if ($start_date_filter) {
                $where_clauses[] = "ei.event_start_date >= :start_date_filter";
                $params[':start_date_filter'] = $start_date_filter;
            }
            if ($end_date_filter) {
                // Assuming 'event_end_date' is the correct column name, not 'movie_end'
                $where_clauses[] = "ei.event_end_date <= :end_date_filter";
                $params[':end_date_filter'] = $end_date_filter;
            }

            $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

            // --- Total events filtered count ---
            $total_events_query = "SELECT COUNT(DISTINCT ei.event_id) $from_clause $join_clause $where_sql";
            $total_stmt = $conn->prepare($total_events_query);
            foreach ($params as $key => &$val) {
                $total_stmt->bindParam($key, $val, PDO::PARAM_STR);
            }
            $total_stmt->execute();
            $total_events_filtered = $total_stmt->fetchColumn();
            $total_pages_events = ceil($total_events_filtered / $limit);

            // --- Fetch events with assignment counts ---
            $query = "
                SELECT
                    ei.event_id,
                    ei.event_name,
                    ei.event_type,
                    ei.event_language,
                    ei.event_duration,
                    ei.genre,
                    ei.is_active,
                    (
                        SELECT COUNT(es.event_schedule_id)
                        FROM event_schedule es
                        WHERE es.event_id = ei.event_id AND es.is_active = 'yes'
                    ) as assigned_schedules_count
                $from_clause
                $join_clause
                $where_sql
                GROUP BY ei.event_id
                ORDER BY ei.event_name ASC
                LIMIT :limit OFFSET :offset";

            $stmt = $conn->prepare($query);
            foreach ($params as $key => &$val) {
                $stmt->bindParam($key, $val, PDO::PARAM_STR);
            }
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // --- Calculate overall stats for the dashboard ---
            // Total Events (unfiltered for dashboard stat)
            $total_events_q = $conn->query("SELECT COUNT(*) FROM event_info");
            $total_events = $total_events_q->fetchColumn();

            // Active Events (unfiltered for dashboard stat)
            $active_events_q = $conn->query("SELECT COUNT(*) FROM event_info WHERE is_active = 'yes'");
            $active_events = $active_events_q->fetchColumn();

            // Total Vacant Schedules (across all venues, active)
            $vacant_schedules_q = $conn->query("SELECT COUNT(*) FROM venue_schedule WHERE is_vacant = 'yes' AND is_active = 'yes' AND slot_starts_at > NOW()");
            $vacant_schedules = $vacant_schedules_q->fetchColumn();


            $response = array(
                'success' => true,
                'events' => $events,
                'total_events_filtered' => $total_events_filtered,
                'total_pages_events' => $total_pages_events,
                'current_page' => $page,
                'stats' => [
                    'total_events' => $total_events,
                    'active_events' => $active_events,
                    'vacant_schedules' => $vacant_schedules,
                ]
            );

        } catch (PDOException $e) {
            error_log("Error fetching events: " . $e->getMessage());
            $response = array('success' => false, 'message' => "Database error fetching events: " . $e->getMessage());
        }
        break;

    case 'bulk_update_event_status':
        try {
            $event_ids_raw = filter_input(INPUT_POST, 'event_ids', FILTER_UNSAFE_RAW);
            $new_status = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);

            if (!in_array($new_status, ['yes', 'no']) || empty($event_ids_raw)) {
                $response = array('success' => false, 'message' => 'Invalid status or event IDs provided.');
                break;
            }

            $event_ids = json_decode($event_ids_raw, true);
            if (!is_array($event_ids) || count($event_ids) === 0) {
                $response = array('success' => false, 'message' => 'No event IDs provided.');
                break;
            }

            $placeholders = implode(',', array_fill(0, count($event_ids), '?'));
            $query = "UPDATE event_info SET is_active = ? WHERE event_id IN ($placeholders)";
            $stmt = $conn->prepare($query);

            $bind_values = array_merge([$new_status], $event_ids);
            $stmt->execute($bind_values);

            $response = array('success' => true, 'message' => 'Event status updated successfully.');

        } catch (PDOException $e) {
            error_log("Error bulk updating event status: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during bulk event status update.');
        }
        break;

    case 'get_schedules_for_assignment_modal':
        try {
            $event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
            $time_range_type = filter_input(INPUT_GET, 'time_range_type', FILTER_SANITIZE_STRING);
            $status_filter = filter_input(INPUT_GET, 'status_filter', FILTER_SANITIZE_STRING); // is_active of venue_schedule
            $assignment_type = filter_input(INPUT_GET, 'assignment_type', FILTER_SANITIZE_STRING); // 'vacant' or 'assigned_to_event'
            $date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING); // For daily view
            $venue_id_filter = filter_input(INPUT_GET, 'venue_id_filter', FILTER_VALIDATE_INT);

            if (!$event_id) {
                $response = array('success' => false, 'message' => 'Event ID is required.');
                break;
            }

            $current_datetime = date('Y-m-d H:i:s');
            $schedules = [];
            $stats = ['total' => 0, 'active' => 0, 'mapped_to_event' => 0, 'vacant' => 0];
            $venues_in_schedules = [];

            // Build base query
            $base_query = "
                SELECT
                    vs.venue_schedule_id,
                    vs.venue_id,
                    v.venue_name,
                    v.sub_venue_name,
                    vs.slot_starts_at,
                    vs.slot_ends_at,
                    vs.is_active,
                    vs.is_vacant,
                    es.event_schedule_id,
                    es.event_id AS assigned_event_id
                FROM venue_schedule vs
                JOIN venues v ON vs.venue_id = v.venue_id
                LEFT JOIN event_schedule es ON vs.venue_schedule_id = es.venue_schedule_id AND es.event_id = :event_id_for_join
                WHERE vs.slot_ends_at > :current_datetime "; // Only show future/ongoing schedules

            $query_params = [
                ':event_id_for_join' => $event_id,
                ':current_datetime' => $current_datetime
            ];
            $where_clauses = [];

            // Apply time range filters
            switch ($time_range_type) {
                case 'daily':
                    if (!$date) {
                        $response = array('success' => false, 'message' => 'Date is required for daily view.');
                        break 2;
                    }
                    $where_clauses[] = "DATE(vs.slot_starts_at) = :selected_date";
                    $query_params[':selected_date'] = $date;
                    break;
                case 'weekly':
                    $today_date = date('Y-m-d');
                    $seven_days_later = date('Y-m-d', strtotime('+6 days'));
                    $where_clauses[] = "DATE(vs.slot_starts_at) BETWEEN :today_date AND :seven_days_later";
                    $query_params[':today_date'] = $today_date;
                    $query_params[':seven_days_later'] = $seven_days_later;
                    break;
                case 'monthly': // Next 30 days
                    $today_date = date('Y-m-d');
                    $thirty_days_later = date('Y-m-d', strtotime('+29 days'));
                    $where_clauses[] = "DATE(vs.slot_starts_at) BETWEEN :today_date AND :thirty_days_later";
                    $query_params[':today_date'] = $today_date;
                    $query_params[':thirty_days_later'] = $thirty_days_later;
                    break;
                default:
                    $response = array('success' => false, 'message' => 'Invalid time range type.');
                    break 2;
            }

            // Apply slot status filter
            if ($status_filter && $status_filter != 'all') {
                $where_clauses[] = "vs.is_active = :status_filter";
                $query_params[':status_filter'] = $status_filter;
            }

            // Apply assignment type filter
            if ($assignment_type === 'vacant') {
                $where_clauses[] = "vs.is_vacant = 'yes'";
            } elseif ($assignment_type === 'assigned_to_event') {
                $where_clauses[] = "es.event_schedule_id IS NOT NULL AND es.event_id = :event_id_assigned";
                $query_params[':event_id_assigned'] = $event_id;
            }

            // Apply venue filter
            if ($venue_id_filter) {
                $where_clauses[] = "vs.venue_id = :venue_id_filter";
                $query_params[':venue_id_filter'] = $venue_id_filter;
            }

            $final_query = $base_query . (count($where_clauses) > 0 ? " AND " . implode(" AND ", $where_clauses) : "") . " ORDER BY vs.slot_starts_at ASC";

            $stmt = $conn->prepare($final_query);
             foreach ($query_params as $key => &$val) {
                 $stmt->bindParam($key, $val);
             }
            $stmt->execute();
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate stats and collect venues
            foreach ($schedules as $schedule) {
                $stats['total']++;
                if ($schedule['is_active'] === 'yes') {
                    $stats['active']++;
                }
                if ($schedule['event_schedule_id']) {
                    $stats['mapped_to_event']++;
                }
                if ($schedule['is_vacant'] === 'yes') {
                    $stats['vacant']++;
                }
                // Only unique venue_id, venue_name, sub_venue_name pairs
                $venues_in_schedules[$schedule['venue_id']] = ['venue_id' => $schedule['venue_id'], 'venue_name' => $schedule['venue_name'], 'sub_venue_name' => $schedule['sub_venue_name']];
            }
            $venues_in_schedules = array_values($venues_in_schedules); // Convert to simple array

            $response = array(
                'success' => true,
                'schedules' => $schedules,
                'stats' => $stats,
                'venues_in_schedules' => $venues_in_schedules // Return venues found in these schedules
            );

        } catch (PDOException $e) {
            error_log("Error fetching schedules for assignment modal: " . $e->getMessage());
            $response = array('success' => false, 'message' => "Database error fetching schedules for modal: " . $e->getMessage());
        }
        break;

    case 'assign_schedule_to_event':
        try {
            $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
            $venue_schedule_id = filter_input(INPUT_POST, 'venue_schedule_id', FILTER_VALIDATE_INT);
            $created_by = $_SESSION['user_id'] ?? 1; // Fallback to 1

            if (!$event_id || !$venue_schedule_id) {
                $response = array('success' => false, 'message' => 'Missing event_id or venue_schedule_id.');
                break;
            }

            // Check if venue_schedule exists, is active, is vacant and in future/today
            $schedule_check_query = "SELECT venue_id, is_active, is_vacant, slot_starts_at FROM venue_schedule WHERE venue_schedule_id = :venue_schedule_id";
            $stmt_check = $conn->prepare($schedule_check_query);
            $stmt_check->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
            $stmt_check->execute();
            $schedule_details = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if (!$schedule_details) {
                $response = array('success' => false, 'message' => 'Schedule slot not found.');
                break;
            }
            if ($schedule_details['is_active'] === 'no') {
                $response = array('success' => false, 'message' => 'Cannot assign an inactive schedule slot. Please activate it first.');
                break;
            }
            if ($schedule_details['is_vacant'] === 'no') {
                // Check if it's actually vacant for *another* event. `is_vacant` implies no assignment.
                // If it's `is_vacant='no'`, it means *some* event is assigned.
                if (isSlotAssigned($conn, $venue_schedule_id)) {
                     $response = array('success' => false, 'message' => 'Schedule slot is already assigned to another event.'); // CORRECTED: Closing quote
                     break;
                }
            }
            if (strtotime($schedule_details['slot_starts_at']) < time()) {
                 $response = array('success' => false, 'message' => 'Cannot assign a schedule slot that has already started or passed.');
                 break;
            }

            // Check if already assigned to *this* event via event_schedule (Though UI should prevent this)
            $existing_assignment_query = "SELECT COUNT(*) FROM event_schedule WHERE event_id = :event_id AND venue_schedule_id = :venue_schedule_id";
            $stmt_existing = $conn->prepare($existing_assignment_query);
            $stmt_existing->bindParam(':event_id', $event_id, PDO::PARAM_INT);
            $stmt_existing->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
            $stmt_existing->execute();
            if ($stmt_existing->fetchColumn() > 0) {
                $response = array('success' => false, 'message' => 'This schedule slot is already assigned to this event.');
                break;
            }

            $conn->beginTransaction();

            // 1. Update venue_schedule.is_vacant
            $update_venue_schedule_query = "UPDATE venue_schedule SET is_vacant = 'no' WHERE venue_schedule_id = :venue_schedule_id";
            $stmt_update_vs = $conn->prepare($update_venue_schedule_query);
            $stmt_update_vs->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
            $stmt_update_vs->execute();

            // 2. Insert into event_schedule
            $insert_event_schedule_query = "INSERT INTO event_schedule (venue_id, venue_schedule_id, event_id, created_by, created_on, is_active)
                                             VALUES (:venue_id, :venue_schedule_id, :event_id, :created_by, NOW(), 'yes')";
            $stmt_insert_es = $conn->prepare($insert_event_schedule_query);
            $stmt_insert_es->bindParam(':venue_id', $schedule_details['venue_id'], PDO::PARAM_INT);
            $stmt_insert_es->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
            $stmt_insert_es->bindParam(':event_id', $event_id, PDO::PARAM_INT);
            $stmt_insert_es->bindParam(':created_by', $created_by, PDO::PARAM_INT);
            $stmt_insert_es->execute();

            $conn->commit();
            $response = array('success' => true, 'message' => 'Schedule slot assigned to event successfully.');

        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error assigning schedule to event: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during assignment: ' . $e->getMessage());
        }
        break;

    case 'unassign_schedule_from_event':
        try {
            $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
            $venue_schedule_id = filter_input(INPUT_POST, 'venue_schedule_id', FILTER_VALIDATE_INT);
            $event_schedule_id = filter_input(INPUT_POST, 'event_schedule_id', FILTER_VALIDATE_INT); // Explicit ID for safety

            if (!$event_id || !$venue_schedule_id || !$event_schedule_id) {
                $response = array('success' => false, 'message' => 'Missing event_id, venue_schedule_id, or event_schedule_id.');
                break;
            }

            // Check if the assignment exists and is valid for THIS event
            $check_query = "SELECT COUNT(*) FROM event_schedule WHERE event_schedule_id = :event_schedule_id AND event_id = :event_id AND venue_schedule_id = :venue_schedule_id";
            $stmt_check = $conn->prepare($check_query);
            $stmt_check->bindParam(':event_schedule_id', $event_schedule_id, PDO::PARAM_INT);
            $stmt_check->bindParam(':event_id', $event_id, PDO::PARAM_INT);
            $stmt_check->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
            $stmt_check->execute();
            if ($stmt_check->fetchColumn() == 0) {
                $response = array('success' => false, 'message' => 'Assignment not found or does not match event.');
                break;
            }

            // Check if the venue_schedule slot has already ended (cannot unassign past slots)
            $schedule_slot_query = "SELECT slot_ends_at FROM venue_schedule WHERE venue_schedule_id = :venue_schedule_id";
            $stmt_slot = $conn->prepare($schedule_slot_query);
            $stmt_slot->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
            $stmt_slot->execute();
            $slot_end_time = $stmt_slot->fetchColumn();

            if ($slot_end_time && strtotime($slot_end_time) < time()) {
                $response = array('success' => false, 'message' => 'Cannot unassign a schedule slot that has already ended.');
                break;
            }

            $conn->beginTransaction();

            // 1. Delete from event_schedule
            $delete_event_schedule_query = "DELETE FROM event_schedule WHERE event_schedule_id = :event_schedule_id AND event_id = :event_id";
            $stmt_delete_es = $conn->prepare($delete_event_schedule_query);
            $stmt_delete_es->bindParam(':event_schedule_id', $event_schedule_id, PDO::PARAM_INT);
            $stmt_delete_es->bindParam(':event_id', $event_id, PDO::PARAM_INT);
            $stmt_delete_es->execute();

            // 2. Update venue_schedule.is_vacant (Only if no other event is using this slot)
            $count_other_assignments_query = "SELECT COUNT(*) FROM event_schedule WHERE venue_schedule_id = :venue_schedule_id AND is_active = 'yes'";
            $stmt_other_assignments = $conn->prepare($count_other_assignments_query);
            $stmt_other_assignments->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
            $stmt_other_assignments->execute();
            $other_active_assignments = $stmt_other_assignments->fetchColumn();

            if ($other_active_assignments == 0) { // Only set to vacant if NO active assignments remaining
                $update_venue_schedule_query = "UPDATE venue_schedule SET is_vacant = 'yes' WHERE venue_schedule_id = :venue_schedule_id";
                $stmt_update_vs = $conn->prepare($update_venue_schedule_query);
                $stmt_update_vs->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                $stmt_update_vs->execute();
            }

            $conn->commit();
            $response = array('success' => true, 'message' => 'Schedule slot unassigned from event successfully.');

        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error unassigning schedule from event: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during unassignment: ' . $e->getMessage());
        }
        break;

    case 'bulk_assign_schedules_to_event':
        try {
            $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
            $venue_schedule_ids_raw = filter_input(INPUT_POST, 'venue_schedule_ids', FILTER_UNSAFE_RAW);
            $created_by = $_SESSION['user_id'] ?? 1;

            if (!$event_id || empty($venue_schedule_ids_raw)) {
                $response = array('success' => false, 'message' => 'Missing event_id or venue_schedule_ids.');
                break;
            }

            $venue_schedule_ids = json_decode($venue_schedule_ids_raw, true);
            if (!is_array($venue_schedule_ids) || count($venue_schedule_ids) === 0) {
                $response = array('success' => false, 'message' => 'No schedule IDs provided for bulk assignment.');
                break;
            }

            $success_count = 0;
            $fail_count = 0;
            $messages = [];

            $conn->beginTransaction();

            foreach ($venue_schedule_ids as $venue_schedule_id) {
                // Check if venue_schedule exists, is active, is vacant and in future/today
                $schedule_check_query = "SELECT venue_id, is_active, is_vacant, slot_starts_at FROM venue_schedule WHERE venue_schedule_id = :venue_schedule_id";
                $stmt_check = $conn->prepare($schedule_check_query);
                $stmt_check->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                $stmt_check->execute();
                $schedule_details = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if (!$schedule_details) {
                    $messages[] = "Schedule ID {$venue_schedule_id} not found.";
                    $fail_count++;
                    continue;
                }
                if ($schedule_details['is_active'] === 'no') {
                    $messages[] = "Schedule ID {$venue_schedule_id} is inactive and cannot be assigned.";
                    $fail_count++;
                    continue;
                }
                // Check if this specific slot is already assigned to ANY active event
                if (isSlotAssigned($conn, $venue_schedule_id)) {
                    $messages[] = "Schedule ID {$venue_schedule_id} is already assigned to another (active) event.";
                    $fail_count++;
                    continue;
                }
                if (strtotime($schedule_details['slot_starts_at']) < time()) {
                    $messages[] = "Schedule ID {$venue_schedule_id} has already started or passed and cannot be assigned.";
                    $fail_count++;
                    continue;
                }

                // Check if already assigned to *this* event.
                $existing_assignment_query = "SELECT COUNT(*) FROM event_schedule WHERE event_id = :event_id AND venue_schedule_id = :venue_schedule_id";
                $stmt_existing = $conn->prepare($existing_assignment_query);
                $stmt_existing->bindParam(':event_id', $event_id, PDO::PARAM_INT);
                $stmt_existing->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                $stmt_existing->execute();
                if ($stmt_existing->fetchColumn() > 0) {
                    $messages[] = "Schedule ID {$venue_schedule_id} is already assigned to this event.";
                    $fail_count++;
                    continue;
                }

                // Update venue_schedule.is_vacant to 'no' as it's now assigned
                $update_venue_schedule_query = "UPDATE venue_schedule SET is_vacant = 'no' WHERE venue_schedule_id = :venue_schedule_id";
                $stmt_update_vs = $conn->prepare($update_venue_schedule_query);
                $stmt_update_vs->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                $stmt_update_vs->execute();

                // Insert into event_schedule
                $insert_event_schedule_query = "INSERT INTO event_schedule (venue_id, venue_schedule_id, event_id, created_by, created_on, is_active)
                                                 VALUES (:venue_id, :venue_schedule_id, :event_id, :created_by, NOW(), 'yes')";
                $stmt_insert_es = $conn->prepare($insert_event_schedule_query);
                $stmt_insert_es->bindParam(':venue_id', $schedule_details['venue_id'], PDO::PARAM_INT);
                $stmt_insert_es->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                $stmt_insert_es->bindParam(':event_id', $event_id, PDO::PARAM_INT);
                $stmt_insert_es->bindParam(':created_by', $created_by, PDO::PARAM_INT);
                $stmt_insert_es->execute();

                $success_count++;
            }

            $conn->commit();
            $message = "Successfully assigned {$success_count} schedule slot(s).";
            if ($fail_count > 0) {
                // Return detailed messages for failed assignments
                $message .= " Failed to assign {$fail_count} slot(s): " . implode("; ", $messages);
            }
            $response = array('success' => true, 'message' => $message);

        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error bulk assigning schedules: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during bulk assignment: ' . $e->getMessage());
        }
        break;

    case 'bulk_unassign_schedules_from_event':
        try {
            $event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
            $venue_schedule_ids_raw = filter_input(INPUT_POST, 'venue_schedule_ids', FILTER_UNSAFE_RAW);

            if (!$event_id || empty($venue_schedule_ids_raw)) {
                $response = array('success' => false, 'message' => 'Missing event_id or venue_schedule_ids.');
                break;
            }

            $venue_schedule_ids = json_decode($venue_schedule_ids_raw, true);
            if (!is_array($venue_schedule_ids) || count($venue_schedule_ids) === 0) {
                $response = array('success' => false, 'message' => 'No schedule IDs provided for bulk unassignment.');
                break;
            }

            $success_count = 0;
            $fail_count = 0;
            $messages = [];

            $conn->beginTransaction();

            foreach ($venue_schedule_ids as $venue_schedule_id) {
                // Get the specific event_schedule_id for this event and venue_schedule
                $assignment_query = "SELECT event_schedule_id FROM event_schedule WHERE event_id = :event_id AND venue_schedule_id = :venue_schedule_id";
                $stmt_assignment = $conn->prepare($assignment_query);
                $stmt_assignment->bindParam(':event_id', $event_id, PDO::PARAM_INT);
                $stmt_assignment->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                $stmt_assignment->execute();
                $event_schedule_id = $stmt_assignment->fetchColumn();

                if (!$event_schedule_id) {
                    $messages[] = "Assignment for schedule ID {$venue_schedule_id} not found for this event.";
                    $fail_count++;
                    continue;
                }

                // Check if the venue_schedule slot has already ended
                $schedule_slot_query = "SELECT slot_ends_at FROM venue_schedule WHERE venue_schedule_id = :venue_schedule_id";
                $stmt_slot = $conn->prepare($schedule_slot_query);
                $stmt_slot->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                $stmt_slot->execute();
                $slot_end_time = $stmt_slot->fetchColumn();

                if ($slot_end_time && strtotime($slot_end_time) < time()) {
                    $messages[] = "Schedule ID {$venue_schedule_id} has already ended and cannot be unassigned.";
                    $fail_count++;
                    continue;
                }

                // Delete from event_schedule
                $delete_event_schedule_query = "DELETE FROM event_schedule WHERE event_schedule_id = :event_schedule_id";
                $stmt_delete_es = $conn->prepare($delete_event_schedule_query);
                $stmt_delete_es->bindParam(':event_schedule_id', $event_schedule_id, PDO::PARAM_INT); // Use the fetched event_schedule_id
                $stmt_delete_es->execute();

                // Update venue_schedule.is_vacant (only if no other remaining active assignments for this venue_schedule)
                $remaining_active_assignments_query = "SELECT COUNT(*) FROM event_schedule WHERE venue_schedule_id = :venue_schedule_id AND is_active = 'yes'";
                $stmt_remaining = $conn->prepare($remaining_active_assignments_query);
                $stmt_remaining->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                $stmt_remaining->execute();
                if ($stmt_remaining->fetchColumn() == 0) { // If no active assignments remain for this venue_schedule
                    $update_venue_schedule_query = "UPDATE venue_schedule SET is_vacant = 'yes' WHERE venue_schedule_id = :venue_schedule_id";
                    $stmt_update_vs = $conn->prepare($update_venue_schedule_query);
                    $stmt_update_vs->bindParam(':venue_schedule_id', $venue_schedule_id, PDO::PARAM_INT);
                    $stmt_update_vs->execute();
                }
                $success_count++;
            }

            $conn->commit();
            $message = "Successfully unassigned {$success_count} schedule slot(s).";
            if ($fail_count > 0) {
                $message .= " Failed to unassign {$fail_count} slot(s): " . implode("; ", $messages);
            }
            $response = array('success' => true, 'message' => $message);

        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error bulk unassigning schedules: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during bulk unassignment: ' . $e->getMessage());
        }
        break;

    case 'bulk_update_schedule_status': // Reusing this action for venue_schedule.is_active from schedule_handler.php
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

            foreach($schedule_ids as $schedule_id) {
                // If setting to 'no' (inactive), ensure it's unassigned from events first
                if ($new_status === 'no') {
                    // Check if there are active assignments for this venue_schedule_id
                    $assigned_event_count_query = "SELECT COUNT(*) FROM event_schedule WHERE venue_schedule_id = :venue_schedule_id AND is_active = 'yes'";
                    $stmt_assigned = $conn->prepare($assigned_event_count_query);
                    $stmt_assigned->bindParam(':venue_schedule_id', $schedule_id, PDO::PARAM_INT);
                    $stmt_assigned->execute();
                    if ($stmt_assigned->fetchColumn() > 0) {
                        $response = array('success' => false, 'message' => 'Cannot deactivate schedule ID ' . $schedule_id . ' as it is still assigned to active events. Unassign first.');
                        // To avoid partial updates in bulk, you might skip this one or roll back.
                        // For this example, we'll return an error and expect the user to unassign first.
                        $conn->rollBack(); // If you had a transaction, roll back here
                        echo json_encode($response);
                        exit();
                    }
                }
            }


            $placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));
            $query = "UPDATE venue_schedule SET is_active = ? WHERE venue_schedule_id IN ($placeholders)";
            $stmt = $conn->prepare($query);

            $bind_values = array_merge([$new_status], $schedule_ids);
            $stmt->execute($bind_values);

            // Also, if a slot becomes active, ensure it's marked as vacant if no event is assigned.
            // If it becomes inactive and already had no assignments, it stays inactive.
            // If it becomes inactive and had assignments (and we let it pass the check), it stays inactive.
            // The is_vacant logic is mostly handled by assign/unassign.
            // You might want to explicitly set is_vacant to 'yes' if activating and was 'no' with no active event assignments.
            if ($new_status === 'yes') {
                $update_vacant_query = "UPDATE venue_schedule vs SET is_vacant = 'yes' WHERE vs.venue_schedule_id IN ($placeholders) AND NOT EXISTS (SELECT 1 FROM event_schedule es WHERE es.venue_schedule_id = vs.venue_schedule_id AND es.is_active = 'yes')";
                $stmt_update_vacant = $conn->prepare($update_vacant_query);
                $stmt_update_vacant->execute($schedule_ids); // Bind just the IDs for the IN clause
            }


            $response = array('success' => true, 'message' => 'Selected venue schedule slots status updated successfully.');

        } catch (PDOException $e) {
            error_log("Error bulk updating venue schedule status: " . $e->getMessage());
            $response = array('success' => false, 'message' => 'Database error during bulk venue schedule status update.');
        }
        break;


    default:
        $response = array('success' => false, 'message' => 'Unknown action or method.');
        break;
}

echo json_encode($response);
exit();
?>
