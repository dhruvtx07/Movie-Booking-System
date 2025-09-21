<?php
session_start(); // Start session at the very top of the script

// --- Database Configuration ---
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); // <<< IMPORTANT: REPLACE WITH YOUR DB USERNAME
define('DB_PASSWORD', ''); // <<< IMPORTANT: REPLACE WITH YOUR DB PASSWORD
define('DB_NAME', 'event_mg'); // <<< IMPORTANT: REPLACE WITH YOUR DATABASE NAME

// Establish database connection
$conn = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn === false) {
    die("ERROR: Could not connect to database. " . mysqli_connect_error());
}

// Ensure error reporting is robust but not visible on production
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// --- Global Constants ---
define('RECORDS_PER_PAGE', 10); // Number of records to display per page
define('DEFAULT_USER_ID', 1); // Placeholder user ID for 'created_by' field

// --- File Upload Configuration ---
define('EVENT_UPLOAD_DIR', __DIR__ . '/../images/uploads/events/'); // Absolute path for event photos
define('STARCAST_UPLOAD_DIR', __DIR__ . '/../images/uploads/starcast/'); // Absolute path for starcast photos

// Create uploads directories if they don't exist
if (!is_dir(EVENT_UPLOAD_DIR)) {
    mkdir(EVENT_UPLOAD_DIR, 0755, true);
}
if (!is_dir(STARCAST_UPLOAD_DIR)) {
    mkdir(STARCAST_UPLOAD_DIR, 0755, true);
}

// Set a default response for AJAX (will be used by handle_photo_upload and other AJAX calls)
$response = ['success' => false, 'message' => ''];

// --- Helper function for file upload ---
function handle_photo_upload($file_input_name, $upload_dir, $current_photo_filename = null) {
    global $response; // Access global response array

    // Check if the file input field was even submitted, and if there's no new file for upload
    if (!isset($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] == UPLOAD_ERR_NO_FILE) {
        return $current_photo_filename; // No new file uploaded, keep the old one
    }

    $upload_file = $_FILES[$file_input_name];

    // Check for general upload errors
    if ($upload_file['error'] != UPLOAD_ERR_OK) {
        $response['message'] = "File upload error: " . $upload_file['error'] . " (Size: " . $upload_file['size'] . ", Code: " . $upload_file['error'] . ")";
        return false;
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB

    // Validate file type
    if (!in_array($upload_file['type'], $allowed_types)) {
        $response['message'] = "Invalid file type. Only JPG, PNG, GIF are allowed.";
        return false;
    }
    // Validate file size
    if ($upload_file['size'] > $max_size) {
        $response['message'] = "File size exceeds the limit of 5MB.";
        return false;
    }

    // Generate unique filename and move uploaded file
    $ext = pathinfo($upload_file['name'], PATHINFO_EXTENSION);
    $new_filename = uniqid('upload_') . '.' . $ext;
    $destination = $upload_dir . $new_filename;

    if (move_uploaded_file($upload_file['tmp_name'], $destination)) {
        // If an old photo exists and a new one is uploaded, delete the old one
        if ($current_photo_filename && file_exists($upload_dir . $current_photo_filename)) {
            unlink($upload_dir . $current_photo_filename);
        }
        return $new_filename; // Return the new filename
    } else {
        $response['message'] = "Failed to move uploaded file. Check directory permissions or disk space.";
        return false;
    }
}


// --- Determine Current View Mode ---
$current_view_mode = 'events'; // Default view
$event_id_to_manage_starcast = null;
$event_name_for_starcast = '';

if (isset($_GET['mode']) && $_GET['mode'] === 'manage_starcast' && isset($_GET['event_id'])) {
    $event_id_to_manage_starcast = (int)$_GET['event_id'];
    // Fetch event name for display in starcast view
    $stmt_event_name = mysqli_prepare($conn, "SELECT event_name FROM event_info WHERE event_id = ?");
    if ($stmt_event_name) {
        mysqli_stmt_bind_param($stmt_event_name, "i", $event_id_to_manage_starcast);
        mysqli_stmt_execute($stmt_event_name);
        $result_event_name = mysqli_stmt_get_result($stmt_event_name);
        if ($row_event_name = mysqli_fetch_assoc($result_event_name)) {
            $event_name_for_starcast = $row_event_name['event_name'];
        }
        mysqli_stmt_close($stmt_event_name);
    }
    $current_view_mode = 'starcast';
}


// --- PHP LOGIC for POST/AJAX Requests (MUST come BEFORE any HTML output) ---

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_type_is_ajax'])) {
    // This block handles ALL AJAX requests for both events & starcast
    $action_type = $_POST['action_type_is_ajax'];

    switch ($action_type) {
        case 'update_status_ajax':
            if ($current_view_mode === 'events') {
                $item_id = (int)$_POST['event_id'];
                $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
                $sql = "UPDATE event_info SET is_active = ? WHERE event_id = ?";
            } elseif ($current_view_mode === 'starcast') {
                $item_id = (int)$_POST['starcast_id'];
                $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
                $sql = "UPDATE event_starcast SET is_active = ? WHERE starcast_id = ?";
            } else {
                $response['message'] = "Invalid view mode for status update.";
                echo json_encode($response);
                exit();
            }

            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) {
                error_log("Failed to prepare update status statement: " . mysqli_error($conn));
                $response['message'] = "Database error (prepare): " . mysqli_error($conn);
            } else {
                mysqli_stmt_bind_param($stmt, "si", $new_status, $item_id);
                if (mysqli_stmt_execute($stmt)) {
                    $response['success'] = true;
                    $response['message'] = "Status updated successfully.";
                } else {
                    error_log("Failed to execute update status statement: " . mysqli_error($conn)); // Log verbose error
                    $response['message'] = "Database error (execute): " . mysqli_error($conn); // Generic message to user
                }
                mysqli_stmt_close($stmt);
            }
            break;

        case 'bulk_delete_ajax':
        case 'bulk_status_change_ajax':
            $item_ids_json = $_POST['ids'];
            $item_ids = json_decode($item_ids_json, true);

            if (!is_array($item_ids) || empty($item_ids)) {
                $response['message'] = "No items selected or invalid data.";
                echo json_encode($response);
                exit();
            }

            $sanitized_ids = array_map('intval', $item_ids);
            $placeholders = implode(',', array_fill(0, count($sanitized_ids), '?'));
            $param_types = str_repeat('i', count(array_filter($sanitized_ids, fn($val) => is_int($val)))); // Count only integer elements

            if ($current_view_mode === 'events') {
                $table_name = 'event_info';
                $photo_column = 'photo';
                $id_column = 'event_id';
                $upload_dir = EVENT_UPLOAD_DIR;
            } elseif ($current_view_mode === 'starcast') {
                $table_name = 'event_starcast';
                $photo_column = 'photo';
                $id_column = 'starcast_id'; // Assuming starcast_id as primary key
                $upload_dir = STARCAST_UPLOAD_DIR;
            } else {
                $response['message'] = "Invalid view mode for bulk actions.";
                echo json_encode($response);
                exit();
            }

            if ($action_type === 'bulk_delete_ajax') {
                // First, get photo filenames to delete from disk
                $photo_sql = "SELECT $photo_column FROM $table_name WHERE $id_column IN ($placeholders)";
                $stmt_photos = mysqli_prepare($conn, $photo_sql);
                if (!$stmt_photos) {
                    error_log("Failed to prepare bulk delete photo query: " . mysqli_error($conn));
                    $response['message'] = "Database error (prepare photos): " . mysqli_error($conn);
                } else {
                    // FIX: Create references for bind_param
                    $bind_args_photos = [];
                    $bind_args_photos[] = $param_types;
                    foreach ($sanitized_ids as $key => $value) {
                        $bind_args_photos[] = &$sanitized_ids[$key];
                    }
                    call_user_func_array([$stmt_photos, 'bind_param'], $bind_args_photos);

                    mysqli_stmt_execute($stmt_photos);
                    $photo_result = mysqli_stmt_get_result($stmt_photos);

                    $photos_to_delete = [];
                    while ($row = mysqli_fetch_assoc($photo_result)) {
                        if (!empty($row[$photo_column])) {
                            $photos_to_delete[] = $upload_dir . $row[$photo_column];
                        }
                    }
                    mysqli_stmt_close($stmt_photos);
                }

                // Then, delete records from the database
                $delete_sql = "DELETE FROM $table_name WHERE $id_column IN ($placeholders)";
                $stmt_delete = mysqli_prepare($conn, $delete_sql);
                if (!$stmt_delete) {
                    error_log("Failed to prepare bulk delete query: " . mysqli_error($conn));
                    $response['message'] = "Database error (prepare delete): " . mysqli_error($conn);
                } else {
                    // FIX: Create references for bind_param
                    $bind_args_delete = [];
                    $bind_args_delete[] = $param_types;
                    foreach ($sanitized_ids as $key => $value) {
                        $bind_args_delete[] = &$sanitized_ids[$key];
                    }
                    call_user_func_array([$stmt_delete, 'bind_param'], $bind_args_delete);

                    if (mysqli_stmt_execute($stmt_delete)) {
                        // Finally, delete files from disk
                        foreach ($photos_to_delete as $file_path) {
                            if (file_exists($file_path)) {
                                unlink($file_path);
                            }
                        }
                        $response['success'] = true;
                        $response['message'] = mysqli_stmt_affected_rows($stmt_delete) . " item(s) deleted successfully.";
                    } else {
                        error_log("Failed to execute bulk delete statement: " . mysqli_error($conn));
                        $response['message'] = "Failed to delete items: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt_delete);
                }
            } elseif ($action_type === 'bulk_status_change_ajax') {
                $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
                $update_sql = "UPDATE $table_name SET is_active = ? WHERE $id_column IN ($placeholders)";
                $stmt = mysqli_prepare($conn, $update_sql);
                if (!$stmt) {
                    error_log("Failed to prepare bulk status update query: " . mysqli_error($conn));
                    $response['message'] = "Database error (prepare status update): " . mysqli_error($conn);
                } else {
                    $bind_params = array_merge([$new_status], $sanitized_ids);
                    $bind_params_ref = [];
                    // This section was already correctly creating references.
                    foreach ($bind_params as $key => $value) {
                        $bind_params_ref[$key] = &$bind_params[$key];
                    }
                    call_user_func_array([$stmt, 'bind_param'], array_merge(['s' . $param_types], $bind_params_ref));

                    if (mysqli_stmt_execute($stmt)) {
                        $response['success'] = true;
                        $response['message'] = mysqli_stmt_affected_rows($stmt) . " item(s) status updated to '$new_status' successfully.";
                    } else {
                        error_log("Failed to execute bulk status update statement: " . mysqli_error($conn));
                        $response['message'] = "Failed to update item statuses: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
            }
            break;

        default:
            $response['message'] = "Invalid AJAX action.";
            break;
    }
    echo json_encode($response);
    exit(); // IMPORTANT: exit immediately for AJAX requests
}


// --- Handle Add/Update/Delete (Regular Form Submissions - these will trigger a page reload) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['save_event']) || isset($_POST['update_event']) || isset($_POST['delete_event']) || isset($_POST['save_starcast']) || isset($_POST['update_starcast']) || isset($_POST['delete_starcast']))) {

    if ($current_view_mode === 'events') {
        if (isset($_POST['save_event']) || isset($_POST['update_event'])) {
            $event_id_pk = isset($_POST['event_id_pk']) && !empty($_POST['event_id_pk']) ? (int)$_POST['event_id_pk'] : null;
            $current_photo_filename = isset($_POST['current_photo']) ? $_POST['current_photo'] : null;

            $event_name = mysqli_real_escape_string($conn, $_POST['event_name']);
            $event_desc = mysqli_real_escape_string($conn, $_POST['event_desc']);
            $event_language = mysqli_real_escape_string($conn, $_POST['event_language']);
            $event_type = mysqli_real_escape_string($conn, $_POST['event_type']);
            $event_duration = mysqli_real_escape_string($conn, $_POST['event_duration']);
            $genre = mysqli_real_escape_string($conn, $_POST['genre']);
            $event_category = mysqli_real_escape_string($conn, $_POST['event_category']);
            $event_trailer_link = isset($_POST['event_trailer_link']) && !empty($_POST['event_trailer_link']) ? mysqli_real_escape_string($conn, $_POST['event_trailer_link']) : NULL;
            $details_page = mysqli_real_escape_string($conn, $_POST['details_page']);

            $event_start_date = isset($_POST['event_start_date']) && !empty($_POST['event_start_date']) ? $_POST['event_start_date'] : NULL;
            $movie_end = isset($_POST['movie_end']) && !empty($_POST['movie_end']) ? $_POST['movie_end'] : NULL;

            $is_active = mysqli_real_escape_string($conn, $_POST['is_active']);
            $created_by = DEFAULT_USER_ID;

            // Date validation
            if (!empty($event_start_date) && !empty($movie_end) && strtotime($movie_end) < strtotime($event_start_date)) {
                $_SESSION['message'] = "End Date (\"" . htmlspecialchars($movie_end) . "\") cannot be earlier than Start Date (\"" . htmlspecialchars($event_start_date) . "\"). Please correct the dates.";
                $_SESSION['message_type'] = "danger";
                goto end_process_event;
            }

            $photo_filename = handle_photo_upload('photo', EVENT_UPLOAD_DIR, $current_photo_filename);

            if ($photo_filename === false) {
                $_SESSION['message'] = isset($response['message']) ? $response['message'] : "Event photo upload failed.";
                $_SESSION['message_type'] = "danger";
            } else {
                // Basic validation for required fields
                if (empty($event_name) || empty($event_type) || empty($event_duration) || empty($genre) || empty($event_category) || empty($details_page) || empty($is_active) || empty($event_start_date)) {
                    $_SESSION['message'] = "Required fields (Event Name, Type, Duration, Genre, Category, Details Page, Status, Start Date) are missing.";
                    $_SESSION['message_type'] = "danger";
                } else {
                    if (isset($_POST['save_event'])) {
                        $sql = "INSERT INTO event_info (event_name, event_desc, event_language, event_type, event_duration, genre, event_category, photo, event_trailer_link, details_page, event_start_date, movie_end, created_by, created_on, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
                        $stmt = mysqli_prepare($conn, $sql);
                        if ($stmt === false) {
                            error_log("DB Prepare Error (INSERT event): " . mysqli_error($conn));
                            $_SESSION['message'] = "Database error preparing statement for adding event.";
                            $_SESSION['message_type'] = "danger";
                            goto end_process_event;
                        }
                        mysqli_stmt_bind_param($stmt, "ssssssssssssis",
                            $event_name, $event_desc, $event_language, $event_type, $event_duration,
                            $genre, $event_category, $photo_filename, $event_trailer_link, $details_page,
                            $event_start_date, $movie_end, $created_by, $is_active
                        );
                    } elseif (isset($_POST['update_event']) && $event_id_pk) {
                        $sql = "UPDATE event_info SET event_name = ?, event_desc = ?, event_language = ?, event_type = ?, event_duration = ?, genre = ?, event_category = ?, photo = ?, event_trailer_link = ?, details_page = ?, event_start_date = ?, movie_end = ?, is_active = ?
                            WHERE event_id = ?";
                        $stmt = mysqli_prepare($conn, $sql);
                        if ($stmt === false) {
                            error_log("DB Prepare Error (UPDATE event): " . mysqli_error($conn));
                            $_SESSION['message'] = "Database error preparing statement for updating event.";
                            $_SESSION['message_type'] = "danger";
                            goto end_process_event; // Jump to end of processing to redirect
                        }
                        mysqli_stmt_bind_param($stmt, "sssssssssssssi",
                            $event_name, $event_desc, $event_language, $event_type, $event_duration,
                            $genre, $event_category, $photo_filename, $event_trailer_link, $details_page,
                            $event_start_date, $movie_end, $is_active, $event_id_pk
                        );
                    }

                    if (isset($stmt) && mysqli_stmt_execute($stmt)) {
                        $_SESSION['message'] = "Event " . (isset($_POST['save_event']) ? "saved" : "updated") . " successfully!";
                        $_SESSION['message_type'] = "success";
                    } else {
                        ($_SESSION['message'] = "Error processing event: " . mysqli_error($conn)); // Fallback for execute error
                        $_SESSION['message_type'] = "danger";
                    }
                    if(isset($stmt)) mysqli_stmt_close($stmt);
                }
            }
        } elseif (isset($_POST['delete_event'])) {
            $event_id_pk = (int)$_POST['event_id_to_delete'];
            $photo_to_delete = $_POST['photo_to_delete'];

            $sql = "DELETE FROM event_info WHERE event_id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt === false) {
                error_log("DB Prepare Error (DELETE event): " . mysqli_error($conn));
                $_SESSION['message'] = "Database error preparing statement.";
                $_SESSION['message_type'] = "danger";
                goto end_process_event;
            }
            mysqli_stmt_bind_param($stmt, "i", $event_id_pk);
            if (mysqli_stmt_execute($stmt)) {
                if (!empty($photo_to_delete) && file_exists(EVENT_UPLOAD_DIR . $photo_to_delete)) {
                    unlink(EVENT_UPLOAD_DIR . $photo_to_delete);
                }
                $_SESSION['message'] = "Event deleted successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error deleting event: " . mysqli_error($conn);
                $_SESSION['message_type'] = "danger";
            }
            mysqli_stmt_close($stmt);
        }
        end_process_event: // Label for goto statement
        header("Location: index.php");
        exit(); // Exit after header redirect
    } // End of events logic

    else if ($current_view_mode === 'starcast') {
        // Starcast CRUD Logic
        $redirect_params = "mode=manage_starcast&event_id={$event_id_to_manage_starcast}";

        if (isset($_POST['save_starcast']) || isset($_POST['update_starcast'])) {
            $starcast_id_pk = isset($_POST['starcast_id_pk']) && !empty($_POST['starcast_id_pk']) ? (int)$_POST['starcast_id_pk'] : null;
            $current_photo_filename = isset($_POST['current_photo']) ? $_POST['current_photo'] : null;

            $starcast_name = mysqli_real_escape_string($conn, $_POST['starcast_name']);
            $designation = mysqli_real_escape_string($conn, $_POST['designation']);
            $is_active = mysqli_real_escape_string($conn, $_POST['is_active']);
            $created_by = DEFAULT_USER_ID; // Assuming 1 for simplicity

            $photo_filename = handle_photo_upload('photo', STARCAST_UPLOAD_DIR, $current_photo_filename);

            if ($photo_filename === false) {
                $_SESSION['message'] = isset($response['message']) ? $response['message'] : "Starcast photo upload failed.";
                $_SESSION['message_type'] = "danger";
            } else {
                if (empty($starcast_name) || empty($designation) || empty($is_active)) {
                    $_SESSION['message'] = "Required fields (Starcast Name, Designation, Status) are missing.";
                    $_SESSION['message_type'] = "danger";
                } else {
                    if (isset($_POST['save_starcast'])) {
                        $sql = "INSERT INTO event_starcast (starcast_name, designation, photo, event_id, created_by, created_on, is_active)
                            VALUES (?, ?, ?, ?, ?, NOW(), ?)";
                        $stmt = mysqli_prepare($conn, $sql);
                        if ($stmt === false) {
                            error_log("DB Prepare Error (INSERT starcast): " . mysqli_error($conn));
                            $_SESSION['message'] = "Database error preparing statement for adding starcast.";
                            $_SESSION['message_type'] = "danger";
                            goto end_process_starcast;
                        }
                        mysqli_stmt_bind_param($stmt, "sssiis",
                            $starcast_name, $designation, $photo_filename, $event_id_to_manage_starcast, $created_by, $is_active
                        );
                    } elseif (isset($_POST['update_starcast']) && $starcast_id_pk) {
                        $sql = "UPDATE event_starcast SET starcast_name = ?, designation = ?, photo = ?, is_active = ?
                            WHERE starcast_id = ? AND event_id = ?";
                        $stmt = mysqli_prepare($conn, $sql);
                        if ($stmt === false) {
                            error_log("DB Prepare Error (UPDATE starcast): " . mysqli_error($conn));
                            $_SESSION['message'] = "Database error preparing statement for updating starcast.";
                            $_SESSION['message_type'] = "danger";
                            goto end_process_starcast;
                        }
                        mysqli_stmt_bind_param($stmt, "sssiii",
                            $starcast_name, $designation, $photo_filename, $is_active, $starcast_id_pk, $event_id_to_manage_starcast
                        );
                    }

                    if (isset($stmt) && mysqli_stmt_execute($stmt)) {
                        $_SESSION['message'] = "Starcast " . (isset($_POST['save_starcast']) ? "saved" : "updated") . " successfully!";
                        $_SESSION['message_type'] = "success";
                    } else {
                        $_SESSION['message'] = "Error processing starcast: " . mysqli_error($conn);
                        $_SESSION['message_type'] = "danger";
                    }
                    if(isset($stmt)) mysqli_stmt_close($stmt);
                }
            }
        } elseif (isset($_POST['delete_starcast'])) {
            $starcast_id_pk = (int)$_POST['starcast_id_to_delete'];
            $photo_to_delete = $_POST['photo_to_delete'];

            $sql = "DELETE FROM event_starcast WHERE starcast_id = ? AND event_id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt === false) {
                error_log("DB Prepare Error (DELETE starcast): " . mysqli_error($conn));
                $_SESSION['message'] = "Database error preparing statement.";
                $_SESSION['message_type'] = "danger";
                goto end_process_starcast;
            }
            mysqli_stmt_bind_param($stmt, "ii", $starcast_id_pk, $event_id_to_manage_starcast);
            if (mysqli_stmt_execute($stmt)) {
                if (!empty($photo_to_delete) && file_exists(STARCAST_UPLOAD_DIR . $photo_to_delete)) {
                    unlink(STARCAST_UPLOAD_DIR . $photo_to_delete);
                }
                $_SESSION['message'] = "Starcast deleted successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error deleting starcast: " . mysqli_error($conn);
                $_SESSION['message_type'] = "danger";
            }
            mysqli_stmt_close($stmt);
        }
        end_process_starcast: // Label for goto statement
        header("Location: index.php?" . $redirect_params);
        exit();
    } // End of starcast logic
}

// --- End of PHP LOGIC for POST/AJAX Requests ---


// --- Initial Data Fetching for HTML Display (GET requests) ---
$pageTitle = ($current_view_mode === 'events') ? "Manage Events" : "Manage Starcast for " . htmlspecialchars($event_name_for_starcast);

// Initialize variables for forms
$edit_mode = false;

// Event-specific data
$event_languages = []; $event_types = []; $event_genres = []; $event_categories = [];
$popular_tags = ["Movie", "Music", "Plays", "Sports", "Comedy", "Drama", "Action"];
$filter_language = ''; $filter_type = ''; $filter_genre = ''; $filter_category = '';
$filter_status = ''; $search_term = ''; $filter_popular_tag = '';

// Starcast-specific data
$starcast_edit_mode = false;
$starcast_to_edit = [
    'starcast_id' => '', 'starcast_name' => '', 'designation' => '',
    'photo' => '', 'is_active' => 'yes'
];

if ($current_view_mode === 'events') {
    // Fetch data for dropdowns (e.g., distinct languages, types, genres, categories)
    $lang_sql = "SELECT DISTINCT event_language FROM event_info WHERE event_language IS NOT NULL AND event_language != '' ORDER BY event_language ASC";
    $lang_result = mysqli_query($conn, $lang_sql);
    while ($row = mysqli_fetch_assoc($lang_result)) { if (!empty($row['event_language'])) $event_languages[] = $row['event_language']; }

    $type_sql = "SELECT DISTINCT event_type FROM event_info WHERE event_type IS NOT NULL AND event_type != '' ORDER BY event_type ASC";
    $type_result = mysqli_query($conn, $type_sql);
    while ($row = mysqli_fetch_assoc($type_result)) { if (!empty($row['event_type'])) $event_types[] = $row['event_type']; }

    $genre_sql = "SELECT DISTINCT genre FROM event_info WHERE genre IS NOT NULL AND genre != '' ORDER BY genre ASC";
    $genre_result = mysqli_query($conn, $genre_sql);
    while ($row = mysqli_fetch_assoc($genre_result)) { if (!empty($row['genre'])) $event_genres[] = $row['genre']; }

    $category_sql = "SELECT DISTINCT event_category FROM event_info WHERE event_category IS NOT NULL AND event_category != '' ORDER BY event_category ASC";
    $category_result = mysqli_query($conn, $category_sql);
    while ($row = mysqli_fetch_assoc($category_result)) { if (!empty($row['event_category'])) $event_categories[] = $row['event_category']; }

    $event_to_edit = [
        'event_id' => '', 'event_name' => '', 'event_desc' => '', 'event_language' => '',
        'event_type' => '', 'event_duration' => '', 'genre' => '', 'event_category' => '',
        'photo' => '', 'event_trailer_link' => '', 'details_page' => '',
        'event_start_date' => '', 'movie_end' => '', 'is_active' => 'yes'
    ];

    // Filters (pulled from GET parameters)
    $filter_language = isset($_GET['filter_language']) ? mysqli_real_escape_string($conn, $_GET['filter_language']) : '';
    $filter_type = isset($_GET['filter_type']) ? mysqli_real_escape_string($conn, $_GET['filter_type']) : '';
    $filter_genre = isset($_GET['filter_genre']) ? mysqli_real_escape_string($conn, $_GET['filter_genre']) : '';
    $filter_category = isset($_GET['filter_category']) ? mysqli_real_escape_string($conn, $_GET['filter_category']) : '';
    $filter_status = isset($_GET['filter_status']) ? mysqli_real_escape_string($conn, $_GET['filter_status']) : '';
    $search_term = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
    $filter_popular_tag = isset($_GET['filter_popular_tag']) ? mysqli_real_escape_string($conn, $_GET['filter_popular_tag']) : '';

    // Handle Edit Request (GET request)
    if (isset($_GET['edit_id'])) {
        $edit_id = (int)$_GET['edit_id'];
        $sql = "SELECT * FROM event_info WHERE event_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $edit_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($event = mysqli_fetch_assoc($result)) {
            $edit_mode = true;
            // Format dates for HTML date input (if not NULL)
            $event['event_start_date'] = !empty($event['event_start_date']) && $event['event_start_date'] != '0000-00-00' ? date('Y-m-d', strtotime($event['event_start_date'])) : '';
            $event['movie_end'] = !empty($event['movie_end']) && $event['movie_end'] != '0000-00-00' ? date('Y-m-d', strtotime($event['movie_end'])) : '';
            $event_to_edit = $event;
        } else {
            $_SESSION['message'] = "Event not found.";
            $_SESSION['message_type'] = "danger";
        }
        mysqli_stmt_close($stmt);
    }

    // Pagination and Filtering Logic for main events
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = RECORDS_PER_PAGE;
    $offset = ($page - 1) * $records_per_page;

    $where_clauses = [];
    $params = [];
    $param_types = "";

    if (!empty($filter_language)) { $where_clauses[] = "event_language = ?"; $params[] = $filter_language; $param_types .= "s"; }
    if (!empty($filter_type)) { $where_clauses[] = "event_type = ?"; $params[] = $filter_type; $param_types .= "s"; }
    if (!empty($filter_genre)) { $where_clauses[] = "genre = ?"; $params[] = $filter_genre; $param_types .= "s"; }
    if (!empty($filter_category)) { $where_clauses[] = "event_category = ?"; $params[] = $filter_category; $param_types .= "s"; }
    if (!empty($filter_status)) { $where_clauses[] = "is_active = ?"; $params[] = $filter_status; $param_types .= "s"; }
    if (!empty($search_term)) {
        $where_clauses[] = "(event_name LIKE ? OR event_desc LIKE ? OR event_language LIKE ? OR event_type LIKE ? OR genre LIKE ? OR event_category LIKE ?)";
        $searchTermLike = "%" . $search_term . "%";
        $params[] = $searchTermLike; $params[] = $searchTermLike; $params[] = $searchTermLike;
        $params[] = $searchTermLike; $params[] = $searchTermLike; $params[] = $searchTermLike;
        $param_types .= "ssssss";
    }
    if (!empty($filter_popular_tag)) {
        $where_clauses[] = "(event_name LIKE ? OR event_desc LIKE ? OR event_type LIKE ? OR genre LIKE ? OR event_category LIKE ?)";
        $tagLike = "%" . $filter_popular_tag . "%";
        $params[] = $tagLike; $params[] = $tagLike; $params[] = $tagLike;
        $params[] = $tagLike; $params[] = $tagLike;
        $param_types .= "sssss";
    }

    $where_sql = "";
    if (count($where_clauses) > 0) { $where_sql = " WHERE " . implode(" AND ", $where_clauses); }

    // --- Fetch Event Stats (affected by filters) ---
    $total_events = 0; $active_events = 0; $inactive_events = 0;
    // Total Events (with current filters)
    $stats_sql_total = "SELECT COUNT(*) as total FROM event_info" . $where_sql;
    $stmt_total = mysqli_prepare($conn, $stats_sql_total);
    if (!empty($param_types)) {
        // FIX for line 594: create references for bind_param
        $bind_args_total = [];
        $bind_args_total[] = $param_types;
        foreach ($params as $key => $value) {
            $bind_args_total[] = &$params[$key];
        }
        call_user_func_array([$stmt_total, 'bind_param'], $bind_args_total);
    }
    mysqli_stmt_execute($stmt_total);
    $result_total = mysqli_stmt_get_result($stmt_total);
    $total_events = mysqli_fetch_assoc($result_total)['total'] ?? 0;
    mysqli_stmt_close($stmt_total);

    // Active Events (with current filters)
    $stats_sql_active = "SELECT COUNT(*) as total FROM event_info" . $where_sql . (empty($where_sql) ? " WHERE" : " AND") . " is_active = ?";
    $stmt_active = mysqli_prepare($conn, $stats_sql_active);
    $temp_param_types_active = $param_types . "s"; $temp_params_active = array_merge($params, ['yes']);
    if (!empty($temp_param_types_active)) {
        // FIX for line 602: create references for bind_param
        $bind_args_active = [];
        $bind_args_active[] = $temp_param_types_active;
        foreach ($temp_params_active as $key => $value) {
            $bind_args_active[] = &$temp_params_active[$key];
        }
        call_user_func_array([$stmt_active, 'bind_param'], $bind_args_active);
    }
    mysqli_stmt_execute($stmt_active); $result_active = mysqli_stmt_get_result($stmt_active);
    $active_events = mysqli_fetch_assoc($result_active)['total'] ?? 0; mysqli_stmt_close($stmt_active);

    // Inactive Events (with current filters)
    $stats_sql_inactive = "SELECT COUNT(*) as total FROM event_info" . $where_sql . (empty($where_sql) ? " WHERE" : " AND") . " is_active = ?";
    $stmt_inactive = mysqli_prepare($conn, $stats_sql_inactive);
    $temp_param_types_inactive = $param_types . "s"; $temp_params_inactive = array_merge($params, ['no']);
    if (!empty($temp_param_types_inactive)) {
        // FIX for line 609: create references for bind_param
        $bind_args_inactive = [];
        $bind_args_inactive[] = $temp_param_types_inactive;
        foreach ($temp_params_inactive as $key => $value) {
            $bind_args_inactive[] = &$temp_params_inactive[$key];
        }
        call_user_func_array([$stmt_inactive, 'bind_param'], $bind_args_inactive);
    }
    mysqli_stmt_execute($stmt_inactive); $result_inactive = mysqli_stmt_get_result($stmt_inactive);
    // Corrected line 717: Changed $result_inactive to $result_inactive_starcast
    $inactive_events = mysqli_fetch_assoc($result_inactive)['total'] ?? 0; mysqli_stmt_close($stmt_inactive);

    // Get total records for pagination after filtering
    $total_records = $total_events;
    $total_pages = ceil($total_records / $records_per_page);

    // Fetch events for the current page with filters
    $events_sql = "SELECT * FROM event_info" . $where_sql . " ORDER BY event_name ASC LIMIT ? OFFSET ?";
    $current_params = array_merge($params, [$records_per_page, $offset]);
    $current_param_types = $param_types . "ii";

    $events_stmt = mysqli_prepare($conn, $events_sql);
    if (!$events_stmt) { die("ERROR: Could not prepare events statement. " . mysqli_error($conn)); }
    // FIX for line 617: create references for bind_param
    $bind_args_events = [];
    $bind_args_events[] = $current_param_types;
    foreach ($current_params as $key => $value) {
        $bind_args_events[] = &$current_params[$key];
    }
    call_user_func_array([$events_stmt, 'bind_param'], $bind_args_events);

    mysqli_stmt_execute($events_stmt);
    $events_result = mysqli_stmt_get_result($events_stmt);
    mysqli_stmt_close($events_stmt);

    // Define upload directory for display (relative to this script's location)
    $base_url_path_events = 'uploads/events/';

} else if ($current_view_mode === 'starcast') {
    // Starcast Data Fetching and Logic
    // If we're in starcast mode, event_id_to_manage_starcast is guaranteed to be set
    $event_id = $event_id_to_manage_starcast;

    // Handle Edit Request for Starcast (GET request)
    if (isset($_GET['edit_starcast_id'])) {
        $edit_starcast_id = (int)$_GET['edit_starcast_id'];
        $sql = "SELECT * FROM event_starcast WHERE starcast_id = ? AND event_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $edit_starcast_id, $event_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($starcast = mysqli_fetch_assoc($result)) {
            $starcast_edit_mode = true;
            $starcast_to_edit = $starcast;
        } else {
            $_SESSION['message'] = "Starcast member not found.";
            $_SESSION['message_type'] = "danger";
        }
        mysqli_stmt_close($stmt);
    }
    
    // --- Fetch Starcast Stats ---
    $total_starcast = 0; $active_starcast = 0; $inactive_starcast = 0;

    $stats_sql_total_starcast = "SELECT COUNT(*) as total FROM event_starcast WHERE event_id = ?";
    $stmt_total_starcast = mysqli_prepare($conn, $stats_sql_total_starcast);
    mysqli_stmt_bind_param($stmt_total_starcast, "i", $event_id);
    mysqli_stmt_execute($stmt_total_starcast);
    $result_total_starcast = mysqli_stmt_get_result($stmt_total_starcast);
    $total_starcast = mysqli_fetch_assoc($result_total_starcast)['total'] ?? 0;
    mysqli_stmt_close($stmt_total_starcast);

    $stats_sql_active_starcast = "SELECT COUNT(*) as total FROM event_starcast WHERE event_id = ? AND is_active = 'yes'";
    $stmt_active_starcast = mysqli_prepare($conn, $stats_sql_active_starcast);
    mysqli_stmt_bind_param($stmt_active_starcast, "i", $event_id);
    mysqli_stmt_execute($stmt_active_starcast);
    $result_active_starcast = mysqli_stmt_get_result($stmt_active_starcast);
    $active_starcast = mysqli_fetch_assoc($result_active_starcast)['total'] ?? 0;
    mysqli_stmt_close($stmt_active_starcast);

    $stats_sql_inactive_starcast = "SELECT COUNT(*) as total FROM event_starcast WHERE event_id = ? AND is_active = 'no'";
    $stmt_inactive_starcast = mysqli_prepare($conn, $stats_sql_inactive_starcast);
    mysqli_stmt_bind_param($stmt_inactive_starcast, "i", $event_id);
    mysqli_stmt_execute($stmt_inactive_starcast);
    // Corrected line: Ensure the variable being fetched is the one returned by mysqli_stmt_get_result
    $result_inactive_starcast = mysqli_stmt_get_result($stmt_inactive_starcast);
    $inactive_starcast = mysqli_fetch_assoc($result_inactive_starcast)['total'] ?? 0;
    mysqli_stmt_close($stmt_inactive_starcast);

    // Fetch starcast for the selected event
    $starcast_sql = "SELECT * FROM event_starcast WHERE event_id = ? ORDER BY starcast_name ASC";
    $starcast_stmt = mysqli_prepare($conn, $starcast_sql);
    mysqli_stmt_bind_param($starcast_stmt, "i", $event_id);
    mysqli_stmt_execute($starcast_stmt);
    $starcast_result = mysqli_stmt_get_result($starcast_stmt);
    mysqli_stmt_close($starcast_stmt);

    $base_url_path_starcast = 'uploads/starcast/';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>

    <!-- Bootstrap CSS (via CDN) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CSS (via CDN) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Custom CSS (Embedded) -->
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
            display: flex; /* Flexbox for sidebar and main content */
            transition: background-color 0.3s ease;
        }

        /* Sidebar Styling */
        .sidebar {
            width: var(--sidebar-width-collapsed);
            background-color: var(--nav-dark);
            color: var(--nav-text);
            height: 100vh;
            position: fixed; /* Fixed position */
            top: 0;
            left: 0;
            overflow-x: hidden; /* Hide horizontal scrollbar */
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
        .sidebar.is-open .sidebar-nav .nav-link i { /* Use .is-open for icon margin */
            margin-right: 15px;
        }

        .sidebar-nav .nav-link span {
            white-space: nowrap; /* Prevent text wrapping */
            opacity: 0; /* Hidden by default when collapsed */
            transition: opacity var(--sidebar-transition-duration) ease-in-out 0.1s;
            flex-grow: 1; /* Allow text to take remaining space */
        }

        .sidebar.is-open .sidebar-nav .nav-link span { /* Use .is-open for text visibility */
            opacity: 1;
        }

        /* Main Content Area */
        .main-content {
            margin-left: var(--sidebar-width-collapsed); /* Initial margin for narrow sidebar */
            flex-grow: 1; /* Take up remaining space */
            padding: 20px;
            transition: margin-left 0.3s ease; /* Smooth transition */
            width: calc(100% - var(--sidebar-width-collapsed)); /* Ensure width calculation for flex behavior */
            min-height: 100vh; /* Ensure it takes full viewport height */
        }

        /* When sidebar expands (has .is-open class), push main content */
        body.sidebar-is-open .main-content { /* Class added to body by JS */
            margin-left: var(--sidebar-width-expanded);
            width: calc(100% - var(--sidebar-width-expanded));
        }

        /* General Theme & Component Styling */
        .container-fluid {
            background-color: var(--primary-bg); /* Consistent background */
        }

        /* Cards */
        .card {
            background-color: var(--secondary-bg); /* Slightly lighter dark for cards */
            border: 1px solid var(--card-border); /* Darker border */
            color: var(--text-color);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4); /* Subtle shadow for depth */
            border-radius: 8px; /* Rounded corners */
        }
        .card-header {
            background-color: #3a3a3a; /* Darker header */
            border-bottom: 1px solid #444444;
            color: var(--accent-orange); /* Orange for header titles */
            border-top-left-radius: 8px; /* Match card radius */
            border-top-right-radius: 8px;
        }
        .card-title {
            color: var(--accent-yellow); /* Yellow for primary titles */
            font-size: 1.25em; /* Slightly larger title */
        }
        .card-subtitle {
            color: var(--accent-orange) !important; /* Darker orange for subtitles */
            font-size: 0.9em;
        }
        .form-label {
            color: var(--text-color);
        }
        .form-control, .form-select, .input-group-text {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
            border-radius: 5px;
        }
        .form-control:focus, .form-select:focus {
            background-color: #3a3a3a;
            color: var(--text-color);
            border-color: var(--accent-orange); /* Orange focus glow */
            box-shadow: 0 0 0 0.25rem rgba(255, 165, 0, 0.25);
        }
        textarea.form-control {
            resize: vertical; /* Allow vertical resizing */
        }

        /* Buttons */
        .btn-primary {
            background: var(--gradient-primary);
            border: none;
            color: white;
            transition: all 0.3s ease;
            border-radius: 5px;
        }
        .btn-primary:hover {
            background: var(--gradient-secondary); /* Lighter Orange on hover */
            border-color: var(--accent-orange);
            color: white;
            transform: translateY(-2px); /* Lift effect */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4); /* Stronger shadow */
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
            transform: translateY(-1px);
        }
        .btn-danger {
            background: var(--gradient-primary);
            border-color: var(--accent-red);
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .btn-danger:hover {
            background-color: var(--accent-red);
            border-color: var(--accent-red);
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-info {
            background-color: var(--accent-orange);
            border-color: var(--accent-orange);
            color: var(--nav-text);
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .btn-info:hover {
            background-color: var(--accent-peach);
            border-color: var(--accent-peach);
            transform: translateY(-1px);
        }
        .btn-success {
            background-color: var(--accent-yellow);
            border-color: var(--accent-yellow);
            color: var(--nav-dark);
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .btn-success:hover {
            background-color: #ffeb3b;
            border-color: #ffeb3b;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.4);
        }
        .btn-warning {
            background-color: var(--accent-orange);
            border-color: var(--accent-orange);
            color: var(--nav-text);
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .btn-warning:hover {
            background-color: #ffbe4a;
            border-color: #ffbe4a;
            transform: translateY(-1px);
        }
        .btn-outline-light {
            border-color: var(--text-color);
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        .btn-outline-light:hover {
            background-color: var(--text-color);
            color: var(--primary-bg);
        }

        /* Badges */
        .badge {
            font-size: 0.8em;
            font-weight: normal;
            padding: 0.4em 0.6em;
            border-radius: 3px;
        }
        .badge.bg-success {
            background-color: var(--accent-yellow) !important;
            color: var(--secondary-bg) !important;
        }
        .badge.bg-danger {
            background-color: var(--accent-red) !important;
            color: var(--text-color) !important;
        }
        .badge.bg-info {
            background-color: var(--accent-orange) !important;
            color: var(--text-color) !important;
        }

        /* Stat Boxes */
        .stat-box {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }
        .stat-box h3 {
            color: var(--accent-yellow);
            font-size: 1.25rem;
        }
        .stat-box p {
            font-size: 2rem;
            font-weight: bold;
            color: var(--accent-orange);
        }

        /* Filters section */
        form.row.gx-3.gy-2 {
            background-color: var(--secondary-bg) !important;
            border-radius: 8px;
            padding: 15px !important;
        }
        .form-check-input:checked {
            background-color: var(--accent-orange);
            border-color: var(--accent-orange);
        }
        .form-check-input:focus {
            box-shadow: 0 0 0 0.25rem rgba(255, 165, 0, 0.25);
        }
        .form-check-input.bulk-checkbox {
            width: 1.25em;
            height: 1.25em;
        }

        /* Icons */
        .text-danger { color: var(--accent-red) !important; }
        .text-warning { color: var(--accent-yellow) !important; }
        .text-muted { color: var(--light-text-color) !important; }
        i.fas, i.far {
            color: var(--accent-orange);
        }
        i.fa-theater-masks { color: var(--accent-peach); }

        /* Pagination */
        .pagination .page-item .page-link {
            background-color: var(--secondary-bg);
            color: var(--accent-orange);
            border: 1px solid var(--card-border);
            border-radius: 5px;
            margin: 0 2px;
        }
        .pagination .page-item .page-link:hover {
            background-color: #444444;
            color: var(--accent-yellow);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--accent-yellow);
            border-color: var(--accent-yellow);
            color: var(--primary-bg);
        }
        .pagination .page-item.disabled .page-link {
            background-color: #222222;
            border-color: #444444;
            color: #777777;
        }

        /* Custom Switch for Status Toggler */
        .custom-switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 24px;
            vertical-align: top;
            margin-top: 5px;
        }
        .custom-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .custom-switch .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--accent-red);
            transition: .4s;
            border-radius: 24px;
        }
        .custom-switch .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        .custom-switch input:checked + .slider {
            background-color: var(--accent-orange);
        }
        .custom-switch input:checked + .slider:before {
            transform: translateX(16px);
        }

        /* Event Card Specifics */
        .event-thumbnail {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 0.75rem;
        }
        .event-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            min-height: 550px;
        }
        .event-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.6);
        }
        .card-body .form-check {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1;
        }
        .card-text.small {
            font-size: 0.85em;
            line-height: 1.4;
        }
        .card-text.text-muted.small {
            min-height: 40px;
            flex-grow: 1;
        }
        .card-text i.fas, .card-text i.far {
            color: var(--accent-peach);
        }

        /* For starcast cards specifically */
        .starcast-card .event-thumbnail {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 0.75rem auto;
            display: block;
        }
        .starcast-card .card-body {
            text-align: center;
        }
        .starcast-card .actions {
            text-align: center !important;
        }
        .starcast-card {
            min-height: auto;
            max-height: 450px;
        }


        /* Placing status toggle and actions correctly */
        .card-body .d-flex.justify-content-between.align-items-end {
            flex-grow: 1;
            align-items: flex-end;
        }
        .card-body .d-flex.justify-content-between.align-items-end > div:first-child {
            margin-right: auto;
        }
        .card-body .d-flex.justify-content-between.align-items-end > div:last-child {
            text-align: right;
        }

        /* Modals for delete confirmation */
        .modal-content {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
        }
        .modal-header {
            border-bottom: 1px solid var(--card-border);
        }
        .modal-footer {
            border-top: 1px solid var(--card-border);
        }
        .btn-close {
            filter: invert(1);
        }
        .modal-title {
            color: var(--accent-yellow) !important;
        }
        .back-to-events-btn {
            background-color: #6c757d;
            color: white;
            border-color: #6c757d;
            margin-left: 0;
            margin-right: auto;
        }
        .back-to-events-btn:hover {
            background-color: #5a6268;
            border-color: #545b62;
        }


        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .col-md-4, .col-lg-3 {
                flex: 0 0 50%;
                max-width: 50%;
            }
            .sidebar {
                width: 0;
                padding-top: 60px;
                box-shadow: none;
            }
            .sidebar.is-open {
                width: var(--sidebar-width-expanded);
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.5);
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
                width: 100%;
            }
            body.sidebar-is-open .main-content {
                margin-left: 0;
            }

            .sidebar-toggle-btn {
                display: block;
                position: fixed;
                top: 10px;
                left: 10px;
                z-index: 1030;
                background-color: var(--nav-dark);
                color: var(--nav-text);
                border: none;
                padding: 10px 15px;
                border-radius: 5px;
                cursor: pointer;
                box-shadow: 0 2px 5px rgba(0,0,0,0.3);
                transition: transform 0.2s ease;
            }
            .filter-group-spacing {
                margin-bottom: 15px;
            }

            .metric-card {
                padding: 15px;
            }
            .list-group-item {
                padding: 8px 12px;
                font-size: 0.9rem;
            }
            th, td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }
            .filter-checkbox-group {
                max-height: 120px;
            }
        }
        @media (min-width: 769px) { /* Desktop */
            .sidebar-toggle-btn {
                display: none;
            }
            .filter-group-spacing {
                margin-bottom: 0;
            }
        }
        /* Toast Notifications */
        .toast-container {
            z-index: 1070;
            right: 0;
            top: 0;
        }
        .toast {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
        }
        .toast .btn-close {
            filter: invert(1);
        }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <!-- Toasts will be appended here by JavaScript -->
    </div>

<!-- Sidebar Toggle Button for Small Screens -->
<button class="sidebar-toggle-btn d-md-none" id="sidebarToggleBtn">
 <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <a href="../dashboard.php" title="Catchify Dashboard">
      <img src="../images/logo.png" alt="Catchify Logo" class="logo-img">
      <h3>Catchify Admin</h3>
    </a>
  </div>
  <nav class="sidebar-nav">
    <ul class="nav flex-column">
      <li class="nav-item">
        <a class="nav-link" href="../dashboard.php" title="Dashboard">
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
              <a class="nav-link" href="../event_handler.php" title="Manage Events">
                <i class="fas fa-edit"></i>
                <span>Manage Events</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../event_assignment/event_assignment.php" title="Event Schedules">
                <i class="fas fa-clock"></i>
                <span>Event Schedules</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../event_schedule_tickets/event_schedule_tickets.php" title="Event Ticket Types">
                <i class="fas fa-ticket-alt"></i>
                <span>Event Ticket Types</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../event_dashboard.php" title="Event Dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Event Dashboard</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../event_booking_detailed.php" title="Event Reports">
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
                            <a class="nav-link" href="../cities/index.php" title="Manage Venues">
                                <i class="fas fa-warehouse"></i>
                                <span>Manage Cities</span>
                            </a>
                        </li>
            <li class="nav-item">
              <a class="nav-link" href="../venues/index.php" title="Manage Venues">
                <i class="fas fa-warehouse"></i>
                <span>Manage Venues</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../venue_schedules/venue_schedules.php" title="Venue Schedules">
                <i class="fas fa-calendar-check"></i>
                <span>Venue Schedules</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../venue_tickets/index.php" title="Venue Ticket Types">
                <i class="fas fa-ticket-alt"></i>
                <span>Venue Ticket Types</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../venue_dashboard.php" title="Venue Dashboard">
                <i class="fas fa-chart-pie"></i>
                <span>Venue Dashboard</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="../venue_booking_detailed.php" title="Venue Reports">
                <i class="fas fa-clipboard-list"></i>
                <span>Venue Reports</span>
              </a>
            </li>
          </ul>
        </div>
      </li>
       
      <li class="nav-item">
        <a class="nav-link" href="../manage_promos.php" title="Promo Codes">
          <i class="fas fa-tag"></i>
          <span>Promo Codes</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link" href="../scanqr.php" title="Promo Codes">
          <i class="fas fa-qrcode"></i>
          <span>Scan Ticket QR</span>
        </a>
      </li>

      <!-- Manage Users (from file 2) - Marked Active -->
                <li class="nav-item">
                    <a class="nav-link" href="../manage_users.php" title="Manage Users">
                        <i class="fas fa-users-cog"></i>
                        <span>Manage Users</span>
                    </a>
                </li>

      <li class="nav-item">
        <a class="nav-link" href="../logout.php" title="Logout">
          <i class="fas fa-sign-out-alt"></i>
          <span>Logout</span>
        </a>
      </li>
    </ul>
  </nav>
</aside>


    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="container py-4">
            <h2 class="section-title"><?php echo $pageTitle; ?></h2>

            <!-- Buttons for Add/Edit Form and Back to Events -->
            <div class="d-flex justify-content-end align-items-center mb-3">
                <?php if ($current_view_mode === 'starcast'): ?>
                    <a href="index.php" class="btn btn-secondary back-to-events-btn me-auto"><i class="fas fa-arrow-left"></i> Back to All Events</a>
                    <button id="toggleStarcastFormButton" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> <?php echo $starcast_edit_mode ? 'View Edit Form' : 'Add New Starcast'; ?>
                    </button>
                <?php else: // Events View ?>
                    <button id="toggleEventFormButton" class="btn btn-primary ms-auto">
                        <i class="fas fa-plus-circle"></i> <?php echo $edit_mode ? 'View Edit Form' : 'Add New Event'; ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($current_view_mode === 'events'): ?>
                <!-- Event Stats Row -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Total Events <br> (Filtered)</h3>
                            <p><?php echo $total_events; ?></p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Active Events <br> (Filtered)</h3>
                            <p><?php echo $active_events; ?></p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-box">
                            <h3>Inactive Events <br> (Filtered)</h3>
                            <p><?php echo $inactive_events; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Add/Edit Event Form -->
                <div id="eventFormContainer" class="card mb-4" style="<?php echo $edit_mode ? '' : 'display: none;'; ?>">
                    <div class="card-header">
                        <h3 class="mb-0"><?php echo $edit_mode ? 'Edit Event' : 'Add New Event'; ?></h3>
                    </div>
                    <div class="card-body">
                        <form action="index.php" method="POST" enctype="multipart/form-data">
                            <?php if ($edit_mode): ?>
                                <input type="hidden" name="event_id_pk" value="<?php echo htmlspecialchars($event_to_edit['event_id']); ?>">
                                <input type="hidden" name="current_photo" value="<?php echo htmlspecialchars($event_to_edit['photo']); ?>">
                            <?php endif; ?>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="event_name" class="form-label">Event Name</label>
                                    <input type="text" class="form-control" id="event_name" name="event_name" value="<?php echo htmlspecialchars($event_to_edit['event_name']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="event_language" class="form-label">Language</label>
                                    <input list="languages" class="form-control" id="event_language" name="event_language" value="<?php echo htmlspecialchars($event_to_edit['event_language']); ?>">
                                    <datalist id="languages">
                                        <?php foreach ($event_languages as $lang): ?>
                                            <option value="<?php echo htmlspecialchars($lang); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="event_type" class="form-label">Type</label>
                                    <input list="types" class="form-control" id="event_type" name="event_type" value="<?php echo htmlspecialchars($event_to_edit['event_type']); ?>" required>
                                    <datalist id="types">
                                        <?php foreach ($event_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="event_duration" class="form-label">Duration (e.g., 2h 30m)</label>
                                    <input type="text" class="form-control" id="event_duration" name="event_duration" value="<?php echo htmlspecialchars($event_to_edit['event_duration']); ?>" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="genre" class="form-label">Genre</label>
                                    <input list="genres" class="form-control" id="genre" name="genre" value="<?php echo htmlspecialchars($event_to_edit['genre']); ?>" required>
                                    <datalist id="genres">
                                        <?php foreach ($event_genres as $genre): ?>
                                            <option value="<?php echo htmlspecialchars($genre); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="event_category" class="form-label">Category</label>
                                    <input list="categories" class="form-control" id="event_category" name="event_category" value="<?php echo htmlspecialchars($event_to_edit['event_category']); ?>" required>
                                    <datalist id="categories">
                                        <?php foreach ($event_categories as $category): ?>
                                            <option value="<?php echo htmlspecialchars($category); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label for="event_desc" class="form-label">Event Description</label>
                                <textarea class="form-control" id="event_desc" name="event_desc" rows="2"><?php echo htmlspecialchars($event_to_edit['event_desc']); ?></textarea>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label for="details_page" class="form-label">Details Page Content</label>
                                <textarea class="form-control" id="details_page" name="details_page" rows="3" required><?php echo htmlspecialchars($event_to_edit['details_page']); ?></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="event_start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="event_start_date" name="event_start_date" value="<?php echo htmlspecialchars($event_to_edit['event_start_date']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="movie_end" class="form-label">End Date (optional)</label>
                                    <input type="date" class="form-control" id="movie_end" name="movie_end" value="<?php echo htmlspecialchars($event_to_edit['movie_end']); ?>">
                                    <div class="invalid-feedback" id="movieEndFeedback" style="display: none;">
                                        End Date cannot be earlier than Start Date.
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="event_trailer_link" class="form-label">Event Trailer Link (YouTube/Vimeo URL)</label>
                                <input type="url" class="form-control" id="event_trailer_link" name="event_trailer_link" value="<?php echo htmlspecialchars($event_to_edit['event_trailer_link']); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="photo" class="form-label">Event Photo</label>
                                <input class="form-control" type="file" id="event_photo" name="photo" accept="image/*">
                                <div id="eventPhotoPreviewContainer" class="mt-2" style="display: none;">
                                    <strong>Preview:</strong> <br>
                                    <img id="eventPhotoPreview" src="" alt="Photo Preview" style="max-width: 150px; height: auto; margin-top: 5px;">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="is_active" class="form-label">Status</label>
                                <select class="form-select" id="event_is_active" name="is_active" required>
                                    <option value="yes" <?php echo ($event_to_edit['is_active'] == 'yes') ? 'selected' : ''; ?>>Active</option>
                                    <option value="no" <?php echo ($event_to_edit['is_active'] == 'no') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <?php if ($edit_mode): ?>
                                <button type="submit" name="update_event" class="btn btn-primary">Update Event</button>
                                <a href="index.php" class="btn btn-secondary">Cancel Edit</a>
                            <?php else: ?>
                                <button type="submit" name="save_event" class="btn btn-primary">Save Event</button>
                                <a href="index.php" class="btn btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Filters and Events List -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0 d-inline">Events List</h3>
                        <small class="ms-2 text-muted">(Showing <?php echo mysqli_num_rows($events_result); ?> of <?php echo $total_records; ?> events)</small>
                    </div>
                    <div class="card-body">
                        <!-- Filter Form -->
                        <form method="GET" action="index.php" class="row gx-3 gy-2 align-items-center mb-4">
                            <div class="col-sm-3">
                                <label class="visually-hidden" for="search">Search</label>
                                <input type="text" class="form-control" id="search" name="search" placeholder="Search name, desc, genre..." value="<?php echo htmlspecialchars($search_term); ?>">
                            </div>
                            <div class="col-sm-auto">
                                <label class="visually-hidden" for="filter_language">Language</label>
                                <select class="form-select" id="filter_language" name="filter_language">
                                    <option value="">All Languages</option>
                                    <?php foreach ($event_languages as $lang): ?>
                                        <option value="<?php echo htmlspecialchars($lang); ?>" <?php echo ($filter_language == $lang) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($lang); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <label class="visually-hidden" for="filter_type">Type</label>
                                <select class="form-select" id="filter_type" name="filter_type">
                                    <option value="">All Types</option>
                                    <?php foreach ($event_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($filter_type == $type) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <label class="visually-hidden" for="filter_genre">Genre</label>
                                <select class="form-select" id="filter_genre" name="filter_genre">
                                    <option value="">All Genres</option>
                                    <?php foreach ($event_genres as $genre): ?>
                                        <option value="<?php echo htmlspecialchars($genre); ?>" <?php echo ($filter_genre == $genre) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($genre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <label class="visually-hidden" for="filter_category">Category</label>
                                <select class="form-select" id="filter_category" name="filter_category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($event_categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category); ?>" <?php echo ($filter_category == $category) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- New Popular Tags Filter -->
                            <div class="col-sm-auto">
                                <label class="visually-hidden" for="filter_popular_tag">Popular Tags</label>
                                <select class="form-select" id="filter_popular_tag" name="filter_popular_tag">
                                    <option value="">Popular Tags</option>
                                    <?php foreach ($popular_tags as $tag): ?>
                                        <option value="<?php echo htmlspecialchars($tag); ?>" <?php echo ($filter_popular_tag == $tag) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tag); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <label class="visually-hidden" for="filter_status">Status</label>
                                <select class="form-select" id="filter_status" name="filter_status">
                                    <option value="">All Statuses</option>
                                    <option value="yes" <?php echo ($filter_status == 'yes') ? 'selected' : ''; ?>>Active</option>
                                    <option value="no" <?php echo ($filter_status == 'no') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <button type="submit" class="btn btn-info w-100"><i class="fas fa-filter"></i> Filter</button>
                            </div>
                            <div class="col-sm-auto">
                                <a href="index.php" class="btn btn-secondary w-100"><i class="fas fa-times-circle"></i> Clear</a>
                            </div>
                        </form>

                        <hr style="border-top: 1px solid var(--card-border); margin: 1rem 0;">

                        <!-- Bulk Actions -->
                        <div class="d-flex justify-content-start align-items-center mb-3">
                            <span class="me-2 text-warning">Bulk Actions:</span>
                            <button type="button" class="btn btn-sm btn-danger me-2" id="eventBulkDeleteBtn">
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-success me-2" id="eventBulkActivateBtn">
                                <i class="fas fa-toggle-on"></i> Activate Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-warning" id="eventBulkDeactivateBtn">
                                <i class="fas fa-toggle-off"></i> Deactivate Selected
                            </button>
                        </div>

                        <!-- Event Cards -->
                        <div class="row">
                            <?php
                            if (mysqli_num_rows($events_result) > 0):
                                while ($event = mysqli_fetch_assoc($events_result)):
                                    ?>
                                    <div class="col-md-4 col-lg-3 mb-3">
                                        <div class="card event-card h-100 position-relative">
                                            <div class="form-check position-absolute" style="top: 10px; right: 10px;">
                                                <input class="form-check-input bulk-checkbox" type="checkbox" value="<?php echo $event['event_id']; ?>" id="event_<?php echo $event['event_id']; ?>">
                                                <label class="form-check-label visually-hidden" for="event_<?php echo $event['event_id']; ?>">Select Event</label>
                                            </div>

                                            <div class="card-body d-flex flex-column">
                                                <?php if (!empty($event['photo'])): ?>
                                                    <img src="<?php echo $base_url_path_events . htmlspecialchars($event['photo']); ?>" class="img-fluid mb-2 rounded event-thumbnail" alt="<?php echo htmlspecialchars($event['event_name']); ?>">
                                                <?php endif; ?>
                                                <h5 class="card-title"><?php echo htmlspecialchars($event['event_name']); ?></h5>
                                                <h6 class="card-subtitle mb-2 text-warning">
                                                    Duration: <?php echo htmlspecialchars($event['event_duration']); ?>
                                                </h6>
                                                <p class="card-text mb-1 small">
                                                    <i class="fas fa-language"></i> Lang: <?php echo htmlspecialchars($event['event_language']); ?>
                                                </p>
                                                <p class="card-text mb-1 small">
                                                    <i class="fas fa-tag"></i> Type: <?php echo htmlspecialchars($event['event_type']); ?>
                                                </p>
                                                <p class="card-text mb-1 small">
                                                    <i class="fas fa-film"></i> Genre: <?php echo htmlspecialchars($event['genre']); ?>
                                                </p>
                                                <p class="card-text mb-1 small">
                                                    <i class="fas fa-layer-group"></i> Cat: <?php echo htmlspecialchars($event['event_category']); ?>
                                                </p>
                                                <?php if (!empty($event['event_start_date']) && !$event['event_start_date'] == '0000-00-00'): ?>
                                                    <p class="card-text mb-1 small">
                                                        <i class="fas fa-calendar-alt"></i> Start: <?php echo htmlspecialchars(date('m/d/Y', strtotime($event['event_start_date']))); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (!empty($event['movie_end']) && !$event['movie_end'] == '0000-00-00'): ?>
                                                    <p class="card-text mb-1 small">
                                                        <i class="fas fa-calendar-times"></i> End: <?php echo htmlspecialchars(date('m/d/Y', strtotime($event['movie_end']))); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <p class="card-text text-muted small mt-2">
                                                    <?php echo nl2br(htmlspecialchars(substr($event['event_desc'], 0, 80) . (strlen($event['event_desc']) > 80 ? '...' : ''))); ?>
                                                </p>


                                                <div class="mt-auto pt-2 d-flex justify-content-between align-items-end">
                                                    <!-- Status Toggle Switch -->
                                                    <div class="d-flex align-items-center">
                                                        <label class="custom-switch me-2">
                                                            <input type="checkbox" class="status-toggle"
                                                                   data-item-id="<?php echo $event['event_id']; ?>"
                                                                <?php echo ($event['is_active'] == 'yes') ? 'checked' : ''; ?>>
                                                            <span class="slider round"></span>
                                                        </label>
                                                        <span class="status-badge badge <?php echo ($event['is_active'] == 'yes') ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo ($event['is_active'] == 'yes') ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                    </div>

                                                    <div class="actions">
                                                        <?php if (!empty($event['event_trailer_link'])): ?>
                                                            <a href="<?php echo htmlspecialchars($event['event_trailer_link']); ?>" target="_blank" class="badge bg-info mt-1"><i class="fas fa-play-circle"></i> Trailer</a>
                                                        <?php endif; ?>
                                                        <br>
                                                        <a href="index.php?edit_id=<?php echo $event['event_id']; ?>" class="btn btn-sm btn-secondary me-1">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteEventModal_<?php echo $event['event_id']; ?>">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                        <a href="index.php?mode=manage_starcast&event_id=<?php echo $event['event_id']; ?>" class="btn btn-sm btn-primary mt-1">
                                                            <i class="fas fa-asterisk"></i> Starcast
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Delete Confirmation Modal for Event -->
                                        <div class="modal fade" id="deleteEventModal_<?php echo $event['event_id']; ?>" tabindex="-1" aria-labelledby="deleteEventModalLabel_<?php echo $event['event_id']; ?>" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="deleteEventModalLabel_<?php echo $event['event_id']; ?>">Confirm Deletion</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        Are you sure you want to delete the event "<?php echo htmlspecialchars($event['event_name']); ?>"?
                                                        <br><small class="text-warning">This action cannot be undone.</small>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <form action="index.php" method="POST" style="display: inline;">
                                                            <input type="hidden" name="event_id_to_delete" value="<?php echo $event['event_id']; ?>">
                                                            <input type="hidden" name="photo_to_delete" value="<?php echo htmlspecialchars($event['photo']); ?>">
                                                            <button type="submit" name="delete_event" class="btn btn-danger">Delete Event</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                    <?php
                                endwhile;
                            else:
                                ?>
                                <div class="col-12">
                                    <p class="text-center p-4">No events found matching your criteria.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php
                                    $query_params = [];
                                    if (!empty($filter_language)) $query_params['filter_language'] = $filter_language;
                                    if (!empty($filter_type)) $query_params['filter_type'] = $filter_type;
                                    if (!empty($filter_genre)) $query_params['filter_genre'] = $filter_genre;
                                    if (!empty($filter_category)) $query_params['filter_category'] = $filter_category;
                                    if (!empty($filter_status)) $query_params['filter_status'] = $filter_status;
                                    if (!empty($search_term)) $query_params['search'] = $search_term;
                                    if (!empty($filter_popular_tag)) $query_params['filter_popular_tag'] = $filter_popular_tag;

                                    $query_string = http_build_query($query_params);
                                    $base_page_url = "index.php?" . $query_string . (empty($query_string) ? "" : "&") . "page=";
                                    ?>

                                    <li class="page-item <?php if($page <= 1){ echo 'disabled'; } ?>">
                                        <a class="page-link" href="<?php if($page > 1){ echo $base_page_url . ($page - 1); } else { echo '#'; } ?>">Previous</a>
                                    </li>

                                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php if($page == $i) {echo 'active'; } ?>">
                                            <a class="page-link" href="<?php echo $base_page_url . $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>

                                    <li class="page-item <?php if($page >= $total_pages) { echo 'disabled'; } ?>">
                                        <a class="page-link" href="<?php if($page < $total_pages) { echo $base_page_url . ($page + 1); } else { echo '#'; } ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>

                    </div>
                </div>

            <?php else: // Starcast View ?>
                <div class="card mb-4" id="starcastManagementSection">
                    <div class="card-header">
                        <h3 class="mb-0">Manage Starcast for: <span class="text-primary"><?php echo htmlspecialchars($event_name_for_starcast); ?></span></h3>
                    </div>
                    <div class="card-body">
                        <!-- Starcast Stats Row -->
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <div class="stat-box">
                                    <h3>Total Starcast</h3>
                                    <p><?php echo $total_starcast; ?></p>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="stat-box">
                                    <h3>Active Starcast</h3>
                                    <p><?php echo $active_starcast; ?></p>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="stat-box">
                                    <h3>Inactive Starcast</h3>
                                    <p><?php echo $inactive_starcast; ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Add/Edit Starcast Form (Hidden by default, shown on edit or toggle) -->
                        <div id="starcastFormContainer" class="card mb-4" style="<?php echo $starcast_edit_mode ? '' : 'display: none;'; ?>">
                            <div class="card-header">
                                <h4 class="mb-0"><?php echo $starcast_edit_mode ? 'Edit Starcast Member' : 'Add New Starcast Member'; ?></h4>
                            </div>
                            <div class="card-body">
                                <form action="index.php?mode=manage_starcast&event_id=<?php echo htmlspecialchars($event_id_to_manage_starcast); ?>" method="POST" enctype="multipart/form-data">
                                    <?php if ($starcast_edit_mode): ?>
                                        <input type="hidden" name="starcast_id_pk" value="<?php echo htmlspecialchars($starcast_to_edit['starcast_id']); ?>">
                                        <input type="hidden" name="current_photo" value="<?php echo htmlspecialchars($starcast_to_edit['photo']); ?>">
                                    <?php endif; ?>

                                    <div class="mb-3">
                                        <label for="starcast_name" class="form-label">Starcast Name</label>
                                        <input type="text" class="form-control" id="starcast_name" name="starcast_name" value="<?php echo htmlspecialchars($starcast_to_edit['starcast_name']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="designation" class="form-label">Designation</label>
                                        <input type="text" class="form-control" id="designation" name="designation" value="<?php echo htmlspecialchars($starcast_to_edit['designation']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="starcast_photo" class="form-label">Starcast Photo</label>
                                        <input class="form-control" type="file" id="starcast_photo" name="photo" accept="image/*">
                                        <div id="starcastPhotoPreviewContainer" class="mt-2" style="display: none;">
                                            <strong>Preview:</strong> <br>
                                            <img id="starcastPhotoPreview" src="" alt="Photo Preview" style="max-width: 150px; height: auto; margin-top: 5px;">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="starcast_is_active" class="form-label">Status</label>
                                        <select class="form-select" id="starcast_is_active" name="is_active" required>
                                            <option value="yes" <?php echo ($starcast_to_edit['is_active'] == 'yes') ? 'selected' : ''; ?>>Active</option>
                                            <option value="no" <?php echo ($starcast_to_edit['is_active'] == 'no') ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <?php if ($starcast_edit_mode): ?>
                                        <button type="submit" name="update_starcast" class="btn btn-primary">Update Starcast</button>
                                        <a href="index.php?mode=manage_starcast&event_id=<?php echo htmlspecialchars($event_id_to_manage_starcast); ?>" class="btn btn-secondary">Cancel Edit</a>
                                    <?php else: ?>
                                        <button type="submit" name="save_starcast" class="btn btn-primary">Save Starcast</button>
                                        <a href="index.php?mode=manage_starcast&event_id=<?php echo htmlspecialchars($event_id_to_manage_starcast); ?>" class="btn btn-secondary">Cancel</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <hr style="border-top: 1px solid var(--card-border); margin: 1rem 0;">

                        <!-- Bulk Actions for Starcast -->
                        <div class="d-flex justify-content-start align-items-center mb-3">
                            <span class="me-2 text-warning">Bulk Actions:</span>
                            <button type="button" class="btn btn-sm btn-danger me-2" id="starcastBulkDeleteBtn">
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-success me-2" id="starcastBulkActivateBtn">
                                <i class="fas fa-toggle-on"></i> Activate Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-warning" id="starcastBulkDeactivateBtn">
                                <i class="fas fa-toggle-off"></i> Deactivate Selected
                            </button>
                        </div>

                        <!-- Starcast Cards -->
                        <div class="row">
                            <?php
                            if (mysqli_num_rows($starcast_result) > 0):
                                while ($starcast = mysqli_fetch_assoc($starcast_result)): // Ensure starcast_result is used here
                                    ?>
                                    <div class="col-md-4 col-lg-3 mb-3">
                                        <div class="card event-card starcast-card h-100 position-relative">
                                            <div class="form-check position-absolute" style="top: 10px; right: 10px;">
                                                <input class="form-check-input bulk-checkbox" type="checkbox" value="<?php echo $starcast['starcast_id']; ?>" id="starcast_<?php echo $starcast['starcast_id']; ?>">
                                                <label class="form-check-label visually-hidden" for="starcast_<?php echo $starcast['starcast_id']; ?>">Select Starcast</label>
                                            </div>

                                            <div class="card-body d-flex flex-column">
                                                <?php if (!empty($starcast['photo'])): ?>
                                                    <img src="<?php echo $base_url_path_starcast . htmlspecialchars($starcast['photo']); ?>" class="img-fluid mb-2 rounded event-thumbnail" alt="<?php echo htmlspecialchars($starcast['starcast_name']); ?>">
                                                <?php endif; ?>
                                                <h5 class="card-title"><?php echo htmlspecialchars($starcast['starcast_name']); ?></h5>
                                                <h6 class="card-subtitle mb-2 text-warning">
                                                    <?php echo htmlspecialchars($starcast['designation']); ?>
                                                </h6>

                                                <div class="mt-auto pt-2 d-flex flex-column align-items-center">
                                                    <!-- Status Toggle Switch -->
                                                    <div class="d-flex align-items-center mb-2">
                                                        <label class="custom-switch me-2">
                                                            <input type="checkbox" class="status-toggle"
                                                                   data-item-id="<?php echo $starcast['starcast_id']; ?>"
                                                                <?php echo ($starcast['is_active'] == 'yes') ? 'checked' : ''; ?>>
                                                            <span class="slider round"></span>
                                                        </label>
                                                        <span class="status-badge badge <?php echo ($starcast['is_active'] == 'yes') ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo ($starcast['is_active'] == 'yes') ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                    </div>

                                                    <div class="actions">
                                                        <a href="index.php?mode=manage_starcast&event_id=<?php echo htmlspecialchars($event_id_to_manage_starcast); ?>&edit_starcast_id=<?php echo $starcast['starcast_id']; ?>" class="btn btn-sm btn-secondary me-1">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteStarcastModal_<?php echo $starcast['starcast_id']; ?>">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Delete Confirmation Modal for Starcast -->
                                        <div class="modal fade" id="deleteStarcastModal_<?php echo $starcast['starcast_id']; ?>" tabindex="-1" aria-labelledby="deleteStarcastModalLabel_<?php echo $starcast['starcast_id']; ?>" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="deleteStarcastModalLabel_<?php echo $starcast['starcast_id']; ?>">Confirm Deletion</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        Are you sure you want to delete the starcast member "<?php echo htmlspecialchars($starcast['starcast_name']); ?>"?
                                                        <br><small class="text-warning">This action cannot be undone.</small>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <form action="index.php?mode=manage_starcast&event_id=<?php echo htmlspecialchars($event_id_to_manage_starcast); ?>" method="POST" style="display: inline;">
                                                            <input type="hidden" name="starcast_id_to_delete" value="<?php echo $starcast['starcast_id']; ?>">
                                                            <input type="hidden" name="photo_to_delete" value="<?php echo htmlspecialchars($starcast['photo']); ?>">
                                                            <button type="submit" name="delete_starcast" class="btn btn-danger">Delete Starcast</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                    <?php
                                endwhile;
                            else:
                                ?>
                                <div class="col-12">
                                    <p class="text-center p-4">No starcast members found for this event. Click "Add New Starcast" to add one.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

            <?php endif; ?>
        </div>
    </main>

    <!-- Bootstrap JS (via CDN - placed at end of body for performance) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JavaScript (Embedded) -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const currentViewMode = "<?php echo $current_view_mode; ?>";
            const eventIdToManageStarcast = "<?php echo $event_id_to_manage_starcast; ?>";

            const eventFormContainer = document.getElementById('eventFormContainer');
            const toggleEventFormButton = document.getElementById('toggleEventFormButton');

            const starcastFormContainer = document.getElementById('starcastFormContainer');
            const toggleStarcastFormButton = document.getElementById('toggleStarcastFormButton');

            // --- Sidebar Functionality (Copied from dashboard.php) ---
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');

            const setSidebarOpen = (isOpen) => {
                if (isOpen) {
                    sidebar.classList.add('is-open');
                    document.body.classList.add('sidebar-is-open');
                    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-times"></i>';
                } else {
                    sidebar.classList.remove('is-open');
                    document.body.classList.remove('sidebar-is-open');
                    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
                }
            };

            if (sidebar && mainContent) {
                // Only enable hover for desktop
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
                sidebarToggleBtn.addEventListener('click', function () {
                    setSidebarOpen(!sidebar.classList.contains('is-open'));
                });

                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function (event) {
                    if (window.innerWidth <= 768 && sidebar.classList.contains('is-open')) {
                        if (!sidebar.contains(event.target) && !sidebarToggleBtn.contains(event.target)) {
                            setSidebarOpen(false);
                        }
                    }
                });
            }

            // --- Toast Notification Function (Copied from dashboard.php) ---
            function showToast(message, type) {
                const toastContainer = document.querySelector('.toast-container');
                if (!toastContainer) return;

                const toastElement = document.createElement('div');
                toastElement.classList.add('toast', 'align-items-center', 'border-0');
                // Bootstrap 5.3 uses text-bg-* for background colors
                switch(type) {
                    case 'success': toastElement.classList.add('text-bg-success'); break;
                    case 'danger': toastElement.classList.add('text-bg-danger'); break;
                    case 'warning': toastElement.classList.add('text-bg-warning'); break;
                    case 'info':
                    default: toastElement.classList.add('text-bg-info'); break;
                }
                // Original toast styling might be conflicting, ensure text-bg-* classes are respected:
                if (type === 'danger') {
                    toastElement.style.backgroundColor = 'var(--accent-red)';
                    toastElement.style.color = 'var(--text-color)';
                } else if (type === 'success') {
                    toastElement.style.backgroundColor = 'var(--accent-yellow)';
                    toastElement.style.color = 'var(--nav-dark)';
                } else if (type === 'info') {
                    toastElement.style.backgroundColor = 'var(--accent-orange)';
                    toastElement.style.color = 'var(--nav-text)';
                } else if (type === 'warning') { // Assuming this is for something else, e.g., muted messages
                    toastElement.style.backgroundColor = 'var(--accent-orange)';
                    toastElement.style.color = 'var(--nav-text)';
                }


                toastElement.setAttribute('role', 'alert');
                toastElement.setAttribute('aria-live', 'assertive');
                toastElement.setAttribute('aria-atomic', 'true');
                toastElement.setAttribute('data-bs-delay', '2500'); // 2.5 seconds

                toastElement.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message} <!-- CORRECT SYNTAX FOR TEMPLATE LITERAL -->
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                `;
                toastContainer.appendChild(toastElement);

                const toast = new bootstrap.Toast(toastElement);
                toast.show();

                // Remove element after it's hidden to prevent clutter
                toastElement.addEventListener('hidden.bs.toast', function () {
                    if(toastElement.parentNode) {
                        toastElement.parentNode.removeChild(toastElement);
                    }
                });
            }

            // Check for session messages on page load and display toast
            const sessionMessage = <?php echo isset($_SESSION['message']) ? json_encode($_SESSION['message']) : 'null'; ?>;
            const sessionMessageType = <?php echo isset($_SESSION['message_type']) ? json_encode($_SESSION['message_type']) : 'null'; ?>;

            if (sessionMessage && sessionMessageType) {
                showToast(sessionMessage, sessionMessageType); // Directly use `sessionMessageType` as custom CSS handles mapping
                // Clear session messages after displaying
                <?php
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
                ?>
            }

            // --- Form Toggle Functionality ---
            if (currentViewMode === 'events') {
                if (toggleEventFormButton) {
                    toggleEventFormButton.addEventListener('click', function() {
                        if (eventFormContainer.style.display === 'none' || !eventFormContainer.style.display) {
                            eventFormContainer.style.display = 'block';
                            toggleEventFormButton.innerHTML = '<i class="fas fa-minus-circle"></i> Hide Form';
                        } else {
                            eventFormContainer.style.display = 'none';
                            // Reset the form if hidden and it's for Add New Event
                            const eventIdPk = document.querySelector('#eventFormContainer input[name="event_id_pk"]');
                            if (!eventIdPk || eventIdPk.value === '') { // If new record
                                eventFormContainer.querySelector('form').reset();
                                // Clear temporary photo preview if it's new
                                const eventPhotoPreview = document.getElementById('eventPhotoPreview');
                                const eventPhotoPreviewContainer = document.getElementById('eventPhotoPreviewContainer');
                                if (eventPhotoPreview.dataset.objectUrl) {
                                    URL.revokeObjectURL(eventPhotoPreview.dataset.objectUrl);
                                    delete eventPhotoPreview.dataset.objectUrl;
                                }
                                eventPhotoPreview.src = '';
                                eventPhotoPreviewContainer.style.display = 'none';
                            }
                            toggleEventFormButton.innerHTML = '<i class="fas fa-plus-circle"></i> Add New Event';
                        }
                    });
                }
                <?php if ($edit_mode): ?>
                eventFormContainer.style.display = 'block';
                if (toggleEventFormButton) toggleEventFormButton.innerHTML = '<i class="fas fa-minus-circle"></i> Hide Form';
                <?php endif; ?>

            } else if (currentViewMode === 'starcast') {
                if (toggleStarcastFormButton) {
                    toggleStarcastFormButton.addEventListener('click', function() {
                        if (starcastFormContainer.style.display === 'none' || !starcastFormContainer.style.display) {
                            starcastFormContainer.style.display = 'block';
                            toggleStarcastFormButton.innerHTML = '<i class="fas fa-minus-circle"></i> Hide Form';
                        } else {
                            starcastFormContainer.style.display = 'none';
                            // Reset the form if hidden and it's for Add New Starcast
                            const starcastIdPk = document.querySelector('#starcastFormContainer input[name="starcast_id_pk"]');
                            if (!starcastIdPk || starcastIdPk.value === '') { // If new record
                                starcastFormContainer.querySelector('form').reset();
                                // Clear temporary photo preview
                                const starcastPhotoPreview = document.getElementById('starcastPhotoPreview');
                                const starcastPhotoPreviewContainer = document.getElementById('starcastPhotoPreviewContainer');
                                if (starcastPhotoPreview.dataset.objectUrl) {
                                    URL.revokeObjectURL(starcastPhotoPreview.dataset.objectUrl);
                                    delete starcastPhotoPreview.dataset.objectUrl;
                                }
                                starcastPhotoPreview.src = '';
                                starcastPhotoPreviewContainer.style.display = 'none';
                            }
                            toggleStarcastFormButton.innerHTML = '<i class="fas fa-plus-circle"></i> Add New Starcast';
                        }
                    });
                }
                <?php if ($starcast_edit_mode): ?>
                starcastFormContainer.style.display = 'block';
                if (toggleStarcastFormButton) toggleStarcastFormButton.innerHTML = '<i class="fas fa-minus-circle"></i> Hide Form';
                <?php endif; ?>
            }

            // --- Frontend Date Validation (for events only) ---
            if (currentViewMode === 'events') {
                const startDateInput = document.getElementById('event_start_date');
                const endDateInput = document.getElementById('movie_end');
                const movieEndFeedback = document.getElementById('movieEndFeedback');

                function validateEndDate() {
                    const startDateVal = startDateInput.value;
                    const endDateVal = endDateInput.value;

                    if (startDateVal && endDateVal) {
                        const startDate = new Date(startDateVal + 'T00:00:00');
                        const endDate = new Date(endDateVal + 'T00:00:00');

                        if (endDate < startDate) {
                            endDateInput.classList.add('is-invalid');
                            movieEndFeedback.style.display = 'block';
                            return false;
                        } else {
                            endDateInput.classList.remove('is-invalid');
                            movieEndFeedback.style.display = 'none';
                        }
                    } else {
                        endDateInput.classList.remove('is-invalid');
                        movieEndFeedback.style.display = 'none';
                    }
                    return true;
                }

                startDateInput.addEventListener('change', validateEndDate);
                endDateInput.addEventListener('change', validateEndDate);
                startDateInput.addEventListener('input', validateEndDate);
                endDateInput.addEventListener('input', validateEndDate);
                // Call on load in case of edit mode or pre-filled values
                setTimeout(validateEndDate, 100);
            }

            // --- Frontend Image Preview ---
            function setupPhotoPreview(photoInputId, photoPreviewId, photoPreviewContainerId, currentDbPhotoUrl) {
                const photoInput = document.getElementById(photoInputId);
                const photoPreviewContainer = document.getElementById(photoPreviewContainerId);
                const photoPreview = document.getElementById(photoPreviewId);

                // Initial setup if there's an existing photo from DB
                if (currentDbPhotoUrl) {
                    photoPreview.src = currentDbPhotoUrl;
                    photoPreviewContainer.style.display = 'block';
                }

                if (photoInput) { // Check if the element exists
                    photoInput.addEventListener('change', function() {
                        // Remove previous object URL if any was created
                        if (photoPreview.dataset.objectUrl) {
                            URL.revokeObjectURL(photoPreview.dataset.objectUrl);
                            delete photoPreview.dataset.objectUrl;
                        }

                        if (this.files && this.files[0]) {
                            const newPhotoUrl = URL.createObjectURL(this.files[0]);
                            photoPreview.src = newPhotoUrl;
                            photoPreviewContainer.style.display = 'block';
                            photoPreview.dataset.objectUrl = newPhotoUrl; // Store the new object URL for later revocation
                        } else {
                            // If file input cleared or no file selected:
                            if (currentDbPhotoUrl) {
                                // Revert to displaying the original photo if it existed
                                photoPreview.src = currentDbPhotoUrl;
                                photoPreviewContainer.style.display = 'block';
                            } else {
                                // No original photo, so hide preview
                                photoPreview.src = '';
                                photoPreviewContainer.style.display = 'none';
                            }
                        }
                    });
                }
            }

            if (currentViewMode === 'events') {
                const eventCurrentDbPhotoUrl = "<?php echo $edit_mode && !empty($event_to_edit['photo']) ? $base_url_path_events . htmlspecialchars($event_to_edit['photo']) : ''; ?>";
                setupPhotoPreview('event_photo', 'eventPhotoPreview', 'eventPhotoPreviewContainer', eventCurrentDbPhotoUrl);
            } else if (currentViewMode === 'starcast') {
                const starcastCurrentDbPhotoUrl = "<?php echo $starcast_edit_mode && !empty($starcast_to_edit['photo']) ? $base_url_path_starcast . htmlspecialchars($starcast_to_edit['photo']) : ''; ?>";
                setupPhotoPreview('starcast_photo', 'starcastPhotoPreview', 'starcastPhotoPreviewContainer', starcastCurrentDbPhotoUrl);
            }


            // --- Handle Status Toggle (AJAX) ---
            document.querySelectorAll('.status-toggle').forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const itemId = this.dataset.itemId;
                    const newStatus = this.checked ? 'yes' : 'no';
                    const currentToggle = this;
                    const originalStatus = this.checked ? 'no' : 'yes'; // For reverting on error
                    const statusBadge = currentToggle.closest('.d-flex').querySelector('.status-badge');

                    const formData = new FormData();
                    formData.append('action_type_is_ajax', 'update_status_ajax');
                    if (currentViewMode === 'events') {
                        formData.append('event_id', itemId);
                    } else if (currentViewMode === 'starcast') {
                        formData.append('starcast_id', itemId);
                    }
                    formData.append('new_status', newStatus);

                    fetch('index.php', { // AJAX request to the same file
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showToast(data.message, 'success');
                                if (statusBadge) {
                                    statusBadge.classList.toggle('bg-success', newStatus === 'yes');
                                    statusBadge.classList.toggle('bg-danger', newStatus === 'no');
                                    statusBadge.textContent = newStatus === 'yes' ? 'Active' : 'Inactive';
                                }
                            } else {
                                showToast(data.message || 'Could not update status.', 'danger');
                                currentToggle.checked = (originalStatus === 'yes'); // Revert toggle if update failed
                            }
                        })
                        .catch(error => {
                            console.error('AJAX Error:', error);
                            showToast('An error occurred while updating status. See console for details.', 'danger');
                            currentToggle.checked = (originalStatus === 'yes'); // Revert toggle on network error
                        });
                });
            });

            // --- Bulk Actions ---
            function getSelectedItems() { // Removed checkboxClass parameter, as it's not used consistently
                const selectedIds = [];
                document.querySelectorAll('.bulk-checkbox:checked').forEach(checkbox => { // Corrected selector
                    selectedIds.push(checkbox.value);
                });
                return selectedIds;
            }

            function performBulkAction(context, actionType, status = null) {
                let ids;
                let redirectUrl = 'index.php'; // Default redirect for events
                if (context === 'events') {
                    ids = getSelectedItems(); // Call without argument
                } else if (context === 'starcast') {
                    ids = getSelectedItems(); // Call without argument
                    redirectUrl = `index.php?mode=manage_starcast&event_id=${eventIdToManageStarcast}`; // Use eventIdToManageStarcast
                }

                if (ids.length === 0) {
                    showToast('Please select at least one item for this action.', 'info');
                    return;
                }

                let confirmMessage = `Are you sure you want to perform this action on ${ids.length} selected items?`;
                if (actionType === 'bulk_delete_ajax') {
                    confirmMessage = `Are you sure you want to delete ${ids.length} selected items? This action cannot be undone.`;
                } else if (actionType === 'bulk_status_change_ajax') {
                    confirmMessage = `Are you sure you want to ${status === 'yes' ? 'activate' : 'deactivate'} ${ids.length} selected items?`;
                }

                if (!confirm(confirmMessage)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action_type_is_ajax', actionType);
                formData.append('ids', JSON.stringify(ids));
                if (status) { // Only append status if it's a status change action
                    formData.append('new_status', status);
                }

                fetch(redirectUrl, { // AJAX request to the same file, with current mode params if starcast
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast(data.message, 'success');
                            setTimeout(() => { window.location.reload(); }, 2500);
                        } else {
                            showToast('Action failed: ' + (data.message || 'Unknown error.'), 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Bulk action AJAX error:', error);
                        showToast('An error occurred during bulk action. See console for details.', 'danger');
                    });
            }

            // Assign event listeners based on current view mode
            if (currentViewMode === 'events') {
                const eventBulkDeleteBtn = document.getElementById('eventBulkDeleteBtn');
                const eventBulkActivateBtn = document.getElementById('eventBulkActivateBtn');
                const eventBulkDeactivateBtn = document.getElementById('eventBulkDeactivateBtn');

                if (eventBulkDeleteBtn) eventBulkDeleteBtn.addEventListener('click', () => performBulkAction('events', 'bulk_delete_ajax'));
                if (eventBulkActivateBtn) eventBulkActivateBtn.addEventListener('click', () => performBulkAction('events', 'bulk_status_change_ajax', 'yes'));
                if (eventBulkDeactivateBtn) eventBulkDeactivateBtn.addEventListener('click', () => performBulkAction('events', 'bulk_status_change_ajax', 'no'));
            } else if (currentViewMode === 'starcast') {
                const starcastBulkDeleteBtn = document.getElementById('starcastBulkDeleteBtn');
                const starcastBulkActivateBtn = document.getElementById('starcastBulkActivateBtn');
                const starcastBulkDeactivateBtn = document.getElementById('starcastBulkDeactivateBtn');

                if (starcastBulkDeleteBtn) starcastBulkDeleteBtn.addEventListener('click', () => performBulkAction('starcast', 'bulk_delete_ajax'));
                if (starcastBulkActivateBtn) starcastBulkActivateBtn.addEventListener('click', () => performBulkAction('starcast', 'bulk_status_change_ajax', 'yes'));
                if (starcastBulkDeactivateBtn) starcastBulkDeactivateBtn.addEventListener('click', () => performBulkAction('starcast', 'bulk_status_change_ajax', 'no'));
            }

            // Clean up object URL when navigating away or closing window
            window.addEventListener('beforeunload', function() {
                // event photo
                const eventPhotoPreview = document.getElementById('eventPhotoPreview');
                if (eventPhotoPreview && eventPhotoPreview.dataset.objectUrl) {
                    URL.revokeObjectURL(eventPhotoPreview.dataset.objectUrl);
                }
                // starcast photo
                const starcastPhotoPreview = document.getElementById('starcastPhotoPreview');
                if (starcastPhotoPreview && starcastPhotoPreview.dataset.objectUrl) {
                    URL.revokeObjectURL(starcastPhotoPreview.dataset.objectUrl);
                }
            });
        });
    </script>
</body>
</html>