<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'links.php'; // Include the links.php file for common links and configurations

// Database configuration
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Redirect to login if not authenticated (using definitions from links.php)
// The $login_page variable should be defined in links.php
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("Location: $login_page");
    exit();
}

// User ID and Username set from session
$adminUserId = $_SESSION['user_id'];
$adminUsername = $_SESSION['username'];

// Connect to database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set charset to UTF-8
    $pdo->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- Utility Functions (unchanged from original file 2) ---

// Function to handle adding new user
function handleAddUser($pdo, $adminUserId) {
    if (!isset($_POST['name']) || !isset($_POST['email']) || !isset($_POST['password']) ||
        !isset($_POST['is_host']) || !isset($_POST['is_admin']) || !isset($_POST['is_active'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid user data for add.'];
        return;
    }

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $plainPassword = $_POST['password'];
    $isHost = $_POST['is_host'];
    $isAdmin = $_POST['is_admin'];
    $isActive = $_POST['is_active'];

    // Input validation
    if (empty($name) || empty($email) || empty($plainPassword) ||
        ($isHost !== 'yes' && $isHost !== 'no') ||
        ($isAdmin !== 'yes' && $isAdmin !== 'no') ||
        ($isActive !== 'yes' && $isActive !== 'no')) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid input. All fields are required and must be valid.'];
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid email format.'];
        return;
    }
    if (strlen($plainPassword) < 6) { // Example password policy
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Password must be at least 6 characters long.'];
        return;
    }

    // Hash the password
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

    try {
        $pdo->beginTransaction();

        // Check if email already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        if ($checkStmt->fetchColumn() > 0) {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'An account with this email already exists.'];
            $pdo->rollBack();
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, pwd, is_host, is_admin, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            $email,
            $hashedPassword,
            $isHost,
            $isAdmin,
            $isActive
        ]);

        $pdo->commit();

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'User added successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to add user.'];
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to add user: ' . $e->getMessage()];
    }
}

// Function to handle user update (full edit)
function handleUpdateUser($pdo, $adminUserId) {
    if (!isset($_POST['userid']) || !isset($_POST['name']) || !isset($_POST['email']) ||
        !isset($_POST['is_host']) || !isset($_POST['is_admin']) || !isset($_POST['is_active'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid user data for update.'];
        return;
    }

    $userIdToUpdate = intval($_POST['userid']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $plainPassword = $_POST['password'] ?? ''; // Password might be empty if not changed
    $isHost = $_POST['is_host'];
    $isAdmin = $_POST['is_admin'];
    $isActive = $_POST['is_active'];

    // Input validation
    if (empty($name) || empty($email) ||
        ($isHost !== 'yes' && $isHost !== 'no') ||
        ($isAdmin !== 'yes' && $isAdmin !== 'no') ||
        ($isActive !== 'yes' && $isActive !== 'no')) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid input. All fields are required and must be valid.'];
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid email format.'];
        return;
    }
    if (!empty($plainPassword) && strlen($plainPassword) < 6) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'New password must be at least 6 characters long if provided.'];
        return;
    }

    try {
        $pdo->beginTransaction();

        // Check if email already exists for another user
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND userid != ?");
        $checkStmt->execute([$email, $userIdToUpdate]);
        if ($checkStmt->fetchColumn() > 0) {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'An account with this email already exists for another user.'];
            $pdo->rollBack();
            return;
        }

        // Prevent admin from deactivating/demoting themselves
        if ($userIdToUpdate == $adminUserId) {
            // Fetch current user's admin status from DB to compare
            $currentAdminStatusStmt = $pdo->prepare("SELECT is_admin FROM users WHERE userid = ?");
            $currentAdminStatusStmt->execute([$adminUserId]);
            $currentAdminStatus = $currentAdminStatusStmt->fetchColumn();

            if ($currentAdminStatus == 'yes' && $isAdmin == 'no') {
                $_SESSION['message'] = ['type' => 'warning', 'text' => 'You cannot demote yourself from admin.'];
                $pdo->rollBack();
                return;
            }
            if ($isActive == 'no') {
                $_SESSION['message'] = ['type' => 'warning', 'text' => 'You cannot deactivate your own account.'];
                $pdo->rollBack();
                return;
            }
        }

        $sql = "UPDATE users SET name = ?, email = ?, is_host = ?, is_admin = ?, is_active = ?";
        $params = [$name, $email, $isHost, $isAdmin, $isActive];

        if (!empty($plainPassword)) {
            $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
            $sql .= ", pwd = ?";
            $params[] = $hashedPassword;
        }

        $sql .= " WHERE userid = ?";
        $params[] = $userIdToUpdate;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'User updated successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'User not found or no changes made.'];
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update user: ' . $e->getMessage()];
    }
}

// Function to handle user status update (from toggle switch)
function handleUpdateUserStatus($pdo, $adminUserId) {
    if (!isset($_POST['userid']) || !isset($_POST['is_active'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid data for status update.'];
        return;
    }

    $userIdToUpdate = intval($_POST['userid']);
    $isActive = $_POST['is_active'];

    if (($isActive !== 'yes' && $isActive !== 'no')) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid status value.'];
        return;
    }

    // Prevent admin from deactivating themselves
    if ($userIdToUpdate == $adminUserId && $isActive == 'no') {
        $_SESSION['message'] = ['type' => 'warning', 'text' => 'You cannot deactivate your own account.'];
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE userid = ?");
        $stmt->execute([$isActive, $userIdToUpdate]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'User status updated successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'User not found or no change made.'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update user status: ' . $e->getMessage()];
    }
}

// Function to handle user deletion (single or bulk)
function handleDeleteUser($pdo, $adminUserId) {
    if (!isset($_POST['userid'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid user ID for deletion.'];
        return;
    }

    $userIds = is_array($_POST['userid']) ? $_POST['userid'] : [$_POST['userid']];
    $userIds = array_filter($userIds, 'is_numeric');

    if (empty($userIds)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'No valid users selected for deletion.'];
        return;
    }

    // Prevent admin from deleting themselves
    if (in_array($adminUserId, $userIds)) {
        $_SESSION['message'] = ['type' => 'warning', 'text' => 'You cannot delete your own account.'];
        return;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM users WHERE userid IN ($placeholders)");
        $stmt->execute($userIds);
        $pdo->commit();

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => $stmt->rowCount() . ' user(s) deleted successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'No users found or deleted.'];
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to delete user(s): ' . $e->getMessage()];
    }
}

// Function to handle bulk status updates for users
function handleBulkStatusUpdate($pdo, $adminUserId) {
    if (!isset($_POST['selected_users']) || !is_array($_POST['selected_users']) || !isset($_POST['bulk_status'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid bulk status update data.'];
        return;
    }

    $userIds = $_POST['selected_users'];
    $newStatus = $_POST['bulk_status'];

    $userIds = array_filter($userIds, 'is_numeric');

    if (($newStatus !== 'yes' && $newStatus !== 'no') || empty($userIds)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid status or no users selected.'];
        return;
    }

    // Prevent admin from deactivating themselves in a bulk operation
    $originalUserIdsCount = count($userIds);
    if (in_array($adminUserId, $userIds) && $newStatus == 'no') {
        $userIds = array_diff($userIds, [$adminUserId]); // Remove admin's ID from the list
    }

    // If after removing admin, no users are left to update, or if only admin was selected initially
    if (empty($userIds) && $originalUserIdsCount > 0) {
        $_SESSION['message'] = ['type' => 'warning', 'text' => 'Action failed: The selected user(s) included your own admin account, which cannot be modified this way.'];
        return;
    } else if (empty($userIds)) { // No users were selected initially
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'No valid users to perform bulk action on.'];
        return;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    try {
        $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE userid IN ($placeholders)");
        $executeParams = array_merge([$newStatus], $userIds);
        $stmt->execute($executeParams);

        if ($stmt->rowCount() > 0) {
            $updatedCount = $stmt->rowCount();
            $message = $updatedCount . ' user(s) status updated to "' . ($newStatus === 'yes' ? 'Active' : 'Inactive') . '"!';
            if ($updatedCount < $originalUserIdsCount) {
                $message .= ' (Some users were excluded, e.g., current admin self-deactivation attempt.)';
            }
            $_SESSION['message'] = ['type' => 'success', 'text' => $message];
        } else {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'No users found or no change made.'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update bulk status: ' . $e->getMessage()];
    }
}


// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_user':
                handleAddUser($pdo, $adminUserId);
                break;
            case 'update_user':
                handleUpdateUser($pdo, $adminUserId);
                break;
            case 'update_user_status':
                handleUpdateUserStatus($pdo, $adminUserId);
                break;
            case 'delete_user': // For single delete
                handleDeleteUser($pdo, $adminUserId);
                break;
            case 'bulk_delete_users': // For bulk delete
                handleDeleteUser($pdo, $adminUserId);
                break;
            case 'bulk_status_update':
                handleBulkStatusUpdate($pdo, $adminUserId);
                break;
        }
    }
    // Redirect to prevent form resubmission
    $redirectUrl = $_SERVER['PHP_SELF'];
    $queryParams = [];
    if (isset($_GET['page'])) {
        $queryParams['page'] = $_GET['page'];
    }
    if (isset($_GET['filter'])) {
        $queryParams['filter'] = $_GET['filter'];
    }
    if (isset($_GET['search'])) {
        $queryParams['search'] = $_GET['search'];
    }
    if (!empty($queryParams)) {
        $redirectUrl .= '?' . http_build_query($queryParams);
    }
    header("Location: ".$redirectUrl);
    exit();
}

// --- Data Fetching for Display (unchanged from original file 2) ---
$usersPerPage = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $usersPerPage;

$filterStatus = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereClauses = [];
$queryParams = [];

// Filtering
if ($filterStatus === 'active') {
    $whereClauses[] = "is_active = 'yes'";
} elseif ($filterStatus === 'inactive') {
    $whereClauses[] = "is_active = 'no'";
} elseif ($filterStatus === 'host') {
    $whereClauses[] = "is_host = 'yes'";
} elseif ($filterStatus === 'admin') {
    $whereClauses[] = "is_admin = 'yes'";
}

// Search
if (!empty($searchTerm)) {
    $whereClauses[] = "(name LIKE ? OR email LIKE ?)";
    $queryParams[] = '%' . $searchTerm . '%';
    $queryParams[] = '%' . $searchTerm . '%';
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = " WHERE " . implode(' AND ', $whereClauses);
}

// Total users for pagination
$totalUsersStmt = $pdo->prepare("SELECT COUNT(*) FROM users" . $whereSql);
$totalUsersStmt->execute($queryParams);
$totalUsers = $totalUsersStmt->fetchColumn();

// Fetch users for current page
$usersStmt = $pdo->prepare("
    SELECT
        userid, name, email, is_host, is_admin, is_active
    FROM
        users
    " . $whereSql . "
    ORDER BY userid DESC
    LIMIT ? OFFSET ?
");

// Bind query parameters for fetch
$paramIndex = 0;
foreach ($queryParams as $param) {
    $paramIndex++;
    if (is_int($param)) {
        $usersStmt->bindValue($paramIndex, $param, PDO::PARAM_INT);
    } else {
        $usersStmt->bindValue($paramIndex, $param, PDO::PARAM_STR);
    }
}
$usersStmt->bindValue($paramIndex + 1, $usersPerPage, PDO::PARAM_INT);
$usersStmt->bindValue($paramIndex + 2, $offset, PDO::PARAM_INT);

$usersStmt->execute();
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Manage Users - Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom Color Variables - Dark Theme Default (Copied from file 1) */
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

            /* Responsive Font Sizes (from file 1) */
            --section-title-font: 2rem;
            --section-subtitle-font: 1.5rem;
            --metric-card-display-4-font: 2.5rem;
            --metric-card-h5-font: 1.1rem;
        }

        /* WebKit Scrollbar (Chrome, Safari, Edge) (Copied from file 1) */
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

        /* Firefox Scrollbar (Copied from file 1) */
        * {
            scrollbar-width: thin;
            scrollbar-color: var(--accent-red) var(--secondary-bg);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-color);
            margin: 0;
            display: flex; /* Flexbox for sidebar and content wrapper */
            transition: background-color 0.3s ease;
        }

        /* New wrapper for Main Content and Footer (Copied from file 1) */
        .content-wrapper {
            display: flex;
            flex-direction: column; /* Stack main content and footer vertically */
            flex-grow: 1; /* Allows it to take up the remaining horizontal space */
            margin-left: var(--sidebar-width-collapsed); /* Initial margin to offset collapsed sidebar */
            transition: margin-left var(--sidebar-transition-duration) ease-in-out;
            min-height: 100vh; /* Ensures the wrapper fills at least the viewport height */
        }

        /* Sidebar Styling (Copied from file 1) */
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

        /* Caret icon specific styling (Copied from file 1) */
        .sidebar-nav .nav-link .caret-icon {
            font-size: 0.9rem; /* Smaller caret */
            transition: transform 0.3s ease-in-out;
            color: var(--light-text-color); /* Muted color for caret */
            margin-left: auto; /* Push to the right */
            margin-right: 0; /* Override default margin from general icon rule */
        }

        /* Rotate caret when menu is expanded (Copied from file 1) */
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

        /* Sub-menu styling (Copied from file 1) */
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

        /* When sidebar expands (has .is-open class), push content wrapper (Copied from file 1) */
        body.sidebar-is-open .content-wrapper { /* Class added to body by JS */
            margin-left: var(--sidebar-width-expanded);
        }

        /* Main Content Area (Copied from file 1, adjusted for file 2's context) */
        .main-content {
            flex-grow: 1; /* Take up remaining space AFTER padding */
            padding: 20px;
            /* Remove margin-left and width as they are handled by .content-wrapper now */
        }

        /* General Styles (Copied from file 1) */
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
            font-size: var(--section-title-font); /* Using variable from file 1 */
            margin-bottom: 20px;
            border-bottom: 2px solid var(--accent-red);
            padding-bottom: 10px;
        }
        .section-subtitle { /* Added from file 1, though not directly used in file 2, good for consistency */
            font-size: var(--section-subtitle-font);
            margin-top: 30px;
            margin-bottom: 15px;
            color: var(--accent-peach);
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 5px;
        }

        /* Alert styles (Copied from file 1) */
        .alert {
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            color: white; /* Ensure text is visible on colored alerts */
        }
        .alert-success { background: linear-gradient(90deg, #28a745, #218838); border: none; }
        .alert-danger { background: var(--gradient-primary); border: none; }
        .alert-warning { background: linear-gradient(90deg, var(--accent-yellow), #e0a800); border: none; }
        .alert-info { background: linear-gradient(90deg, var(--accent-orange), var(--accent-peach)); border: none; }

        /* Form elements (Copied from file 1) */
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
        .form-check-label { /* Added for general form-check-label consistency */
            color: var(--text-color);
        }
      
        /* Buttons (Copied and adapted from file 1) */
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
            background: var(--gradient-primary); /* Reusing primary gradient for danger actions like delete */
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
        .btn-outline-secondary { /* Copied from file 1, but not directly used here for now */
            color: var(--light-text-color);
            border-color: var(--card-border);
            background-color: transparent;
        }
        .btn-outline-secondary:hover { /* Copied from file 1 */
            color: var(--text-color);
            background-color: rgba(255, 255, 255, 0.08); /* Subtle hover */
            border-color: var(--light-text-color);
        }

        /* User Cards (Adapted from original file 2, styling aligned with file 1's metric cards) */
        .user-card {
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            height: 100%;
        }
        .user-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .user-card .card-title {
            color: var(--accent-orange);
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        .user-card .card-text strong {
            color: var(--accent-peach);
        }
        .user-card .text-muted {
            font-size: 0.85rem;
            color: var(--light-text-color) !important;
        }
        .user-card .user-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        /* Style for the main checkbox container (bulk select) */
        .user-card .user-main-checkbox-container {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 10;
        }
        /* Custom styling for generic checkboxes (like bulk select checkbox) */
        input[type="checkbox"].form-check-input.user-checkbox { /* Increased specificity here */
            width: 1.5em;
            height: 1.5em;
            margin-top: .25em;
            vertical-align: top;
            background-color: var(--primary-bg); /* Use primary-bg as background for non-selected */
            border: 1px solid var(--accent-orange); /* Orange border for checkbox */
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            border-radius: .25rem;
            transition: background-color .15s ease-in-out, border-color .15s ease-in-out, box-shadow .15s ease-in-out, background-image .15s ease-in-out;
            cursor: pointer;
            flex-shrink: 0; /* Important for alignment in flex containers */
        }
        input[type="checkbox"].form-check-input.user-checkbox:checked { /* Increased specificity here */
            background-color: var(--accent-red); /* Red when checked */
            border-color: var(--accent-red);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M6 10l3 3l6-6'/%3e%3csvg%3e");
            background-size: 100% 100%;
        }
        input[type="checkbox"].form-check-input.user-checkbox:focus { /* Increased specificity here */
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
        }

        /* Toggle Switch Styling Fixes (Original from file 2, adjusted colors) */
        .form-check.form-switch {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: fit-content;
            padding-left: 0 !important;
        }

        /* Styling for the custom toggle input */
        .form-check-input.toggle-switch {
            width: 3.5em;
            height: 2em;
            background-color: #6c757d; /* Inactive track color */
            border-radius: 1em;
            position: relative;
            cursor: pointer;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            transition: background-color .3s ease;
            border: none;
            outline: none;
            box-shadow: none;
        }

        .form-check-input.toggle-switch:checked {
            background: var(--gradient-primary); /* Active track color (gradient) */
            outline: none;
            box-shadow: none;
            border: none;
        }

        /* Styling for the thumb (the movable circle) */
        .form-check-input.toggle-switch::after {
            content: "";
            position: absolute;
            background-color: #fff; /* Thumb color */
            border-radius: 50%;
            transition: left .3s ease;

            top: 2px;
            left: 2px;
            width: calc(2em - 4px);
            height: calc(2em - 4px);

            border: none;
            box-shadow: none;
        }

        .form-check-input.toggle-switch:checked::after {
            left: calc(100% - (2em - 4px) - 2px);
        }

        /* Ensure input and label don't shrink within the flex container */
        .form-check.form-switch .form-check-input.toggle-switch {
            flex-shrink: 0;
            margin-left: 0 !important;
        }

        /* Pagination (Copied from file 1) */
        .pagination .page-item .page-link {
            background-color: var(--secondary-bg);
            border-color: var(--card-border);
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        .pagination .page-item .page-link:hover {
            background: var(--gradient-secondary);
            color: white;
            border-color: var(--accent-orange);
        }
        .pagination .page-item.active .page-link {
            background: var(--gradient-primary);
            border-color: var(--accent-red);
            color: white;
            font-weight: bold;
        }
        .pagination .page-item.disabled .page-link {
            background-color: #333;
            border-color: #444;
            color: #777;
            cursor: not-allowed;
        }

        /* Tabs/Filters (Copied from file 2, adapted colors for theme) */
        .nav-tabs {
            border-bottom-color: var(--card-border);
        }
        .nav-tabs .nav-link {
            color: var(--light-text-color);
            background-color: transparent;
            border: 1px solid transparent;
            border-bottom-color: var(--card-border);
            transition: all 0.3s ease;
        }
        .nav-tabs .nav-link:hover {
            border-color: var(--card-border) var(--card-border) var(--card-border);
            color: var(--accent-orange);
            background-color: rgba(255, 107, 53, 0.1);
        }
        .nav-tabs .nav-link.active {
            color: var(--accent-red);
            background-color: var(--secondary-bg);
            border-color: var(--card-border) var(--card-border) var(--secondary-bg);
            border-bottom-color: var(--secondary-bg);
            font-weight: bold;
        }
        .nav-tabs .nav-link.active:focus, .nav-tabs .nav-link.active:hover {
            border-color: var(--card-border) var(--card-border) var(--secondary-bg);
        }

        /* Modal styling (Copied from file 1) */
        .modal-content {
            background-color: var(--primary-bg);
            color: var(--text-color);
            border: 1px solid var(--card-border);
            box-shadow: 0 5px 15px rgba(0,0,0,0.5);
        }
        .modal-header {
            border-bottom: 1px solid var(--card-border);
            background: var(--gradient-primary);
            color: white;
        }
        .modal-title {
            color: white;
        }
        .modal-footer {
            border-top: 1px solid var(--card-border);
        }
        .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%); /* Make close button white */
        }

        /* Bulk actions bar (Original from file 2, enhanced for theme) */
        .bulk-actions-buttons .btn {
            min-width: 120px;
            padding-left: 10px;
            padding-right: 10px;
        }

        /* Alerts Fade Out Animation (Copied from file 1) */
        .fade-out {
            animation: fadeOut 1s ease-out forwards;
        }

        @keyframes fadeOut {
            0% { opacity: 1; height: auto; padding: inherit; margin: inherit; }
            99% { opacity: 0; height: auto; padding: inherit; margin: inherit; }
            100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; display: none; }
        }

        /* NEW STYLING FOR SEARCH BAR - FIXED POSITIONING (Original from file 2, adapted colors) */

        /* Container for the search input and buttons */
        .search-input-group-fixed {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            background-color: var(--secondary-bg);

            border: none;
            box-shadow: none;

            display: flex;
            align-items: center;
        }

        /* The actual text input */
        #userSearch {
            background-color: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            color: var(--text-color);
            flex-grow: 1;
            padding-right: 60px;
        }

        #userSearch:focus {
            box-shadow: none !important;
        }

        /* The clear button ('x') */
        .clear-search-btn {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);

            background: none;
            border: none;
            color: var(--light-text-color);
            font-size: 1.2rem;
            cursor: pointer;
            z-index: 3;
            padding: 0;
            opacity: 0.7;
            transition: opacity 0.2s ease, color 0.2s ease;
        }

        .clear-search-btn:hover {
            opacity: 1;
            color: var(--accent-red);
        }

        /* The search (magnifying glass) button */
        #searchButton {
            background: var(--gradient-primary);
            border: none !important;
            border-radius: 0 !important;
            color: white;
            padding: 8px 15px;
            flex-shrink: 0;
            z-index: 2;
        }

        #searchButton:hover {
            background: var(--gradient-secondary);
        }

        /* Responsive Adjustments (Copied from file 1, then specific overrides for file 2's components) */
        @media (max-width: 768px) {
            :root { /* Adjust font sizes for mobile view */
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
            .sidebar-toggle-btn:hover { /* Added from original file 2 */
                transform: scale(1.05);
            }

            /* Adjust layout for smaller screens */
            .user-card .user-main-checkbox-container {
                position: static; /* Adjust checkbox position for better mobile layout */
                margin-bottom: 10px;
                text-align: right;
            }

            .row.mb-4.align-items-center > div {
                text-align: center !important; /* Center align all items in the header row */
            }
            .row.mb-4.align-items-center .col-md-6,
            .row.mb-4.align-items-center .col-lg-4 {
                margin-bottom: 15px;
            }
            .bulk-actions-buttons {
                width: 100%;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .bulk-actions-buttons .btn {
                width: 100%;
            }

            .footer { /* Footer adjustments for mobile (copied from file 1 and adapted) */
                padding: 15px;
                text-align: center;
            }
            .footer .col-md-6 {
                text-align: center !important;
            }
            .footer .row {
                flex-direction: column;
            }
            .footer .col-md-6:first-child {
                margin-bottom: 10px;
            }
        }
        @media (min-width: 769px) { /* Desktop */
            .sidebar-toggle-btn {
                display: none; /* Hide on larger screens */
            }
        }

        /* Footer Styling (Copied from file 1) */
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

    <!-- New wrapper for Main Content and Footer (Copied from file 1) -->
    <div class="content-wrapper" id="contentWrapper">
        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="container py-4">
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-<?= $_SESSION['message']['type'] ?> alert-dismissible fade show" role="alert">
                        <?= $_SESSION['message']['text'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php // Message is unset after display. No need to re-unset here for this block. ?>
                <?php endif; ?>

                <h2 class="section-title">Manage Users</h2>

                <div class="row mb-4 align-items-center">
                    <div class="col-md-6 col-lg-4">
                        <div class="input-group search-input-group-fixed">
                            <input type="text" class="form-control" placeholder="Search by Name or Email..." id="userSearch" value="<?= htmlspecialchars($searchTerm) ?>">
                            <button class="btn clear-search-btn" type="button" id="clearSearchBtn" title="Clear search" style="display: <?= !empty($searchTerm) ? 'block' : 'none' ?>;">
                                <i class="fas fa-times"></i>
                            </button>
                            <button class="btn btn-primary" type="button" id="searchButton"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 text-md-end mt-3 mt-md-0">
                        <p class="total-users mb-0">Total: <span class="badge bg-secondary"><?= $totalUsers ?></span> users</p>
                    </div>
                    <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="fas fa-user-plus me-1"></i> Add New User
                        </button>
                    </div>
                </div>

                <!-- Tabbed Filters -->
                <ul class="nav nav-tabs mb-4" id="userTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $filterStatus === 'all' ? 'active' : '' ?>" id="all-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="all" aria-selected="<?= $filterStatus === 'all' ? 'true' : 'false' ?>" onclick="window.location.href='?filter=all&search=<?= urlencode($searchTerm) ?>'">All</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $filterStatus === 'active' ? 'active' : '' ?>" id="active-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="active" aria-selected="<?= $filterStatus === 'active' ? 'true' : 'false' ?>" onclick="window.location.href='?filter=active&search=<?= urlencode($searchTerm) ?>'">Active</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $filterStatus === 'inactive' ? 'active' : '' ?>" id="inactive-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="inactive" aria-selected="<?= $filterStatus === 'inactive' ? 'true' : 'false' ?>" onclick="window.location.href='?filter=inactive&search=<?= urlencode($searchTerm) ?>'">Inactive</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $filterStatus === 'host' ? 'active' : '' ?>" id="host-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="host" aria-selected="<?= $filterStatus === 'host' ? 'true' : 'false' ?>" onclick="window.location.href='?filter=host&search=<?= urlencode($searchTerm) ?>'">Hosts</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $filterStatus === 'admin' ? 'active' : '' ?>" id="admin-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="admin" aria-selected="<?= $filterStatus === 'admin' ? 'true' : 'false' ?>" onclick="window.location.href='?filter=admin&search=<?= urlencode($searchTerm) ?>'">Admins</button>
                    </li>
                </ul>

                <!-- Bulk Actions -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4">
                    <div class="form-check me-md-3 mb-3 mb-md-0">
                        <input class="form-check-input" type="checkbox" id="selectAllUsers">
                        <label class="form-check-label" for="selectAllUsers">Select All</label>
                    </div>
                    <div class="bulk-actions-buttons">
                        <button class="btn btn-danger me-2" id="bulkDeleteBtn" disabled><i class="fas fa-trash-alt me-1"></i> Delete Selected</button>
                        <button class="btn btn-primary me-2" id="bulkActivateBtn" disabled><i class="fas fa-check-circle me-1"></i> Activate Selected</button>
                        <button class="btn btn-secondary" id="bulkDeactivateBtn" disabled><i class="fas fa-times-circle me-1"></i> Deactivate Selected</button>
                    </div>
                </div>

                <?php if (empty($users)): ?>
                    <div class="alert alert-info" role="alert">No users found for the selected filters.</div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($users as $user): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="user-card"
                                    data-userid="<?= htmlspecialchars($user['userid']) ?>"
                                    data-name="<?= htmlspecialchars($user['name']) ?>"
                                    data-email="<?= htmlspecialchars($user['email']) ?>"
                                    data-is-host="<?= htmlspecialchars($user['is_host']) ?>"
                                    data-is-admin="<?= htmlspecialchars($user['is_admin']) ?>"
                                    data-is-active="<?= htmlspecialchars($user['is_active']) ?>">

                                    <div class="form-check user-main-checkbox-container">
                                        <input class="form-check-input user-checkbox" type="checkbox" value="<?= $user['userid'] ?>" <?= ($user['userid'] == $adminUserId) ? 'disabled' : '' ?>>
                                    </div>
                                    <h5 class="card-title"><?= htmlspecialchars($user['name']) ?></h5>
                                    <p class="card-text">
                                        <strong>Email:</strong> <?= htmlspecialchars($user['email']) ?>
                                    </p>
                                    <p class="card-text">
                                        <strong>Permissions:</strong>
                                        <?= $user['is_admin'] == 'yes' ? '<span class="badge bg-danger">Admin</span>' : '' ?>
                                        <?= $user['is_host'] == 'yes' ? '<span class="badge bg-warning text-dark">Host</span>' : '' ?>
                                    </p>
                                    <p class="card-text">
                                        <strong>Status:</strong>
                                        <div class="form-check form-switch d-inline-flex align-items-center ms-2">
                                            <input class="form-check-input toggle-switch user-active-toggle" type="checkbox" role="switch" id="statusToggle<?= $user['userid'] ?>"
                                                <?= $user['is_active'] == 'yes' ? 'checked' : '' ?> data-userid="<?= $user['userid'] ?>"
                                                <?= ($user['userid'] == $adminUserId) ? 'disabled' : '' ?>>
                                        </div>
                                    </p>
                                    <div class="user-actions">
                                        <button class="btn btn-sm btn-primary edit-user-btn"
                                            data-bs-toggle="modal" data-bs-target="#editUserModal">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if ($user['userid'] != $adminUserId): // Prevent admin from deleting themselves ?>
                                            <form method="post" action="" class="d-inline delete-user-form">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="userid" value="<?= htmlspecialchars($user['userid']) ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete user: <?= htmlspecialchars($user['name']) ?>?')">
                                                    <i class="fas fa-trash-alt"></i> Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php
                            $totalPages = ceil($totalUsers / $usersPerPage);
                            $queryString = '?';
                            if (!empty($filterStatus) && $filterStatus !== 'all') {
                                $queryString .= 'filter=' . urlencode($filterStatus) . '&';
                            }
                            if (!empty($searchTerm)) {
                                $queryString .= 'search=' . urlencode($searchTerm) . '&';
                            }
                            $queryString = rtrim($queryString, '&');
                            
                            if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= $queryString . (strpos($queryString, '?') === false ? '?' : '&') ?>page=<?= $page - 1 ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </span>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $queryString . (strpos($queryString, '?') === false ? '?' : '&') ?>page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= $queryString . (strpos($queryString, '?') === false ? '?' : '&') ?>page=<?= $page + 1 ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer (Copied from file 1) -->
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

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUserModalLabel">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_user">

                        <div class="mb-3">
                            <label for="new_name" class="form-label">Name:</label>
                            <input type="text" class="form-control" id="new_name" name="name" required maxlength="100">
                        </div>

                        <div class="mb-3">
                            <label for="new_email" class="form-label">Email:</label>
                            <input type="email" class="form-control" id="new_email" name="email" required maxlength="150">
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label">Password:</label>
                            <input type="password" class="form-control" id="new_password" name="password" required minlength="6">
                        </div>

                        <div class="mb-3">
                            <label for="new_is_host" class="form-label">Is Host?</label>
                            <select class="form-select" id="new_is_host" name="is_host" required>
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_is_admin" class="form-label">Is Admin?</label>
                            <select class="form-select" id="new_is_admin" name="is_admin" required>
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="new_is_active" class="form-label">Status:</label>
                            <select class="form-select" id="new_is_active" name="is_active" required>
                                <option value="yes">Active</option>
                                <option value="no">Inactive</option>
                            </select>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="userid" id="edit_userid">

                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Name:</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required maxlength="100">
                        </div>

                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email:</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required maxlength="150">
                        </div>

                        <div class="mb-3">
                            <label for="edit_password" class="form-label">New Password (leave blank to keep current):</label>
                            <input type="password" class="form-control" id="edit_password" name="password" minlength="6">
                            <small class="form-text text-muted">Enter a new password only if you want to change it.</small>
                        </div>

                        <div class="mb-3">
                            <label for="edit_is_host" class="form-label">Is Host?</label>
                            <select class="form-select" id="edit_is_host" name="is_host" required>
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_is_admin" class="form-label">Is Admin?</label>
                            <select class="form-select" id="edit_is_admin" name="is_admin" required>
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="edit_is_active" class="form-label">Status:</label>
                            <select class="form-select" id="edit_is_active" name="is_active" required>
                                <option value="yes">Active</option>
                                <option value="no">Inactive</option>
                            </select>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Form (hidden) -->
    <form id="bulkActionForm" method="post" action="" style="display: none;">
        <input type="hidden" name="action" id="bulkActionType">
        <input type="hidden" name="bulk_status" id="bulkStatusValue">
        <div id="selectedUsersInputContainer">
            <!-- Hidden inputs for selected IDs will be added here by JS -->
        </div>
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hardcoded admin ID for client-side logic to disable self-modification
            // IMPORTANT: This 'const ADMIN_USER_ID' MUST be dynamic based on the logged-in user
            const ADMIN_USER_ID = <?= json_encode($adminUserId ?? null) ?>; // Pass PHP session ID to JS

            // Auto-hide alerts after 3 seconds (Copied from file 1)
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('fade-out');
                    setTimeout(() => {
                        alert.remove();
                    }, 1000); // Wait for animation to complete before removing
                }, 3000); // 3 seconds
            });

            // Sidebar Toggle (Copied and adapted from file 1)
            const sidebar = document.getElementById('sidebar');
            const contentWrapper = document.getElementById('contentWrapper'); // Changed from mainContent in file 1 to match new structure
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');

            const setSidebarOpen = (isOpen) => {
                if (isOpen) {
                    sidebar.classList.add('is-open');
                    document.body.classList.add('sidebar-is-open'); // Add to body to trigger content-wrapper margin adjustment
                    if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-times"></i>';
                } else {
                    sidebar.classList.remove('is-open');
                    document.body.classList.remove('sidebar-is-open'); // Remove from body
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

            // --- Active Link and Submenu Management (Copied and adapted from file 1) ---
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

            navLinks.forEach(link => {
                // Get the link's href and extract just the filename (e.g., "dashboard.php")
                const linkHref = link.getAttribute('href');
                if (!linkHref || linkHref.startsWith('#')) return; // Skip links that are just collapse toggles

                const currentFilename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
                const linkFilename = linkHref.substring(linkHref.lastIndexOf('/') + 1);

                // For `manage_users.php` specifically, make sure it's active
                if (linkFilename === 'manage_users.php') {
                    link.classList.add('active');
                } else if (linkFilename === currentFilename) { // Check if the link's filename matches the current page's filename
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

            // --- Caret Icon Rotation on Collapse Events (Copied from file 1) ---
            document.querySelectorAll('.sidebar-nav .collapse').forEach(collapseElement => {
                collapseElement.addEventListener('show.bs.collapse', function() {
                    const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                    if (toggleLink) {
                        const caretIcon = toggleLink.querySelector('.caret-icon');
                        if (caretIcon) caretIcon.style.transform = 'rotate(180deg)';
                        toggleLink.classList.add('active'); // Optionally activate parent link on expand
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
                            toggleLink.classList.remove('active');
                        }
                    }
                });
            });


            // Handle search filter
            const userSearchInput = document.getElementById('userSearch');
            const searchButton = document.getElementById('searchButton');
            const clearSearchBtn = document.getElementById('clearSearchBtn');

            function toggleClearButton() {
                if (userSearchInput.value.trim() !== '') {
                    clearSearchBtn.style.display = 'block';
                } else {
                    clearSearchBtn.style.display = 'none';
                }
            }

            function applyFilters() {
                const searchTerm = userSearchInput.value.trim();
                const currentFilterLink = document.querySelector('.nav-tabs .nav-link.active');
                let filterValue = 'all'; // Default filter value
                if (currentFilterLink) {
                    filterValue = currentFilterLink.getAttribute('aria-controls');
                }

                let params = [];
                if (filterValue && filterValue !== 'all') {
                    params.push(`filter=${encodeURIComponent(filterValue)}`);
                }
                if (searchTerm) {
                    params.push(`search=${encodeURIComponent(searchTerm)}`);
                }

                let url = window.location.pathname;
                if (params.length > 0) {
                    url += '?' + params.join('&');
                }
                window.location.href = url;
            }

            if (searchButton) {
                searchButton.addEventListener('click', applyFilters);
            }

            if (userSearchInput) {
                toggleClearButton(); // Initial check for clear button visibility
                userSearchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        applyFilters();
                    }
                });
                userSearchInput.addEventListener('input', toggleClearButton);
            }

            if (clearSearchBtn) {
                clearSearchBtn.addEventListener('click', function() {
                    userSearchInput.value = '';
                    applyFilters();
                });
            }
            
            // Populate main form's display input for edit mode
            document.getElementById('editUserModal')?.addEventListener('show.bs.modal', function(event) {
                const userCard = event.relatedTarget.closest('.user-card');
                if (!userCard) return;

                const userId = userCard.dataset.userid;

                document.getElementById('edit_userid').value = userId;
                document.getElementById('edit_name').value = userCard.dataset.name;
                document.getElementById('edit_email').value = userCard.dataset.email;
                document.getElementById('edit_password').value = '';
                document.getElementById('edit_is_host').value = userCard.dataset.isHost;
                document.getElementById('edit_is_admin').value = userCard.dataset.isAdmin;
                document.getElementById('edit_is_active').value = userCard.dataset.isActive;

                // Disable self-demotion from admin or self-deactivation
                const editIsAdminSelect = document.getElementById('edit_is_admin');
                const editIsActiveSelect = document.getElementById('edit_is_active');
                
                // Compare with ADMIN_USER_ID from PHP
                if (parseInt(userId) === ADMIN_USER_ID && ADMIN_USER_ID !== null) {
                    Array.from(editIsAdminSelect.options).forEach(option => {
                        if (option.value === 'no') {
                            option.disabled = true;
                        }
                    });
                    Array.from(editIsActiveSelect.options).forEach(option => {
                        if (option.value === 'no') {
                            option.disabled = true;
                        }
                    });
                } else {
                    // Ensure options are enabled for other users
                    Array.from(editIsAdminSelect.options).forEach(option => option.disabled = false);
                    Array.from(editIsActiveSelect.options).forEach(option => option.disabled = false);
                }
            });

            // Re-enable disabled options when modal is hidden (for reuse)
            document.getElementById('editUserModal')?.addEventListener('hidden.bs.modal', function(event) {
                const editIsAdminSelect = document.getElementById('edit_is_admin');
                const editIsActiveSelect = document.getElementById('edit_is_active');
                Array.from(editIsAdminSelect.options).forEach(option => option.disabled = false);
                Array.from(editIsActiveSelect.options).forEach(option => option.disabled = false);
            });


            // Helper function for creating hidden inputs
            function createHiddenInput(name, value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                return input;
            }

            // Toggle switch for individual user status update
            document.querySelectorAll('.user-active-toggle').forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const userCard = this.closest('.user-card');
                    const userId = userCard.dataset.userid;
                    const newStatus = this.checked ? 'yes' : 'no';

                    // Client-side check for self-deactivation
                    if (parseInt(userId) === ADMIN_USER_ID && newStatus === 'no' && ADMIN_USER_ID !== null) {
                        alert('You cannot deactivate your own account.');
                        this.checked = true; // Revert checkbox state
                        return;
                    }

                    if (!confirm(`Are you sure you want to change the status of user "${userCard.dataset.name}" to "${newStatus === 'yes' ? 'Active' : 'Inactive'}"?`)) {
                        this.checked = !this.checked; // Revert checkbox state if user cancels
                        return;
                    }

                    const form = document.createElement('form');
                    form.method = 'post';
                    form.action = ''; // Submit to self

                    form.appendChild(createHiddenInput('action', 'update_user_status'));
                    form.appendChild(createHiddenInput('userid', userId));
                    form.appendChild(createHiddenInput('is_active', newStatus));

                    document.body.appendChild(form); // Append to body to submit
                    form.submit();
                });
            });


            // Bulk Actions Logic
            const selectAllCheckbox = document.getElementById('selectAllUsers');
            const userCheckboxes = document.querySelectorAll('.user-checkbox');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const bulkActivateBtn = document.getElementById('bulkActivateBtn');
            const bulkDeactivateBtn = document.getElementById('bulkDeactivateBtn');
            const bulkActionTypeInput = document.getElementById('bulkActionType');
            const bulkStatusValueInput = document.getElementById('bulkStatusValue');
            const selectedUsersInputContainer = document.getElementById('selectedUsersInputContainer');
            const bulkActionForm = document.getElementById('bulkActionForm');

            function updateBulkActionButtons() {
                const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
                if(bulkDeleteBtn) bulkDeleteBtn.disabled = checkedCount === 0;
                if(bulkActivateBtn) bulkActivateBtn.disabled = checkedCount === 0;
                if(bulkDeactivateBtn) bulkDeactivateBtn.disabled = checkedCount === 0;
                if(selectAllCheckbox) {
                    // Count only non-disabled checkboxes for selectAll/indeterminate
                    const totalSelectableCheckboxes = document.querySelectorAll('.user-checkbox:not(:disabled)').length;
                    const checkedSelectableCount = document.querySelectorAll('.user-checkbox:not(:disabled):checked').length;
                    
                    selectAllCheckbox.checked = totalSelectableCheckboxes > 0 && checkedSelectableCount === totalSelectableCheckboxes;
                    selectAllCheckbox.indeterminate = checkedSelectableCount < totalSelectableCheckboxes && checkedSelectableCount > 0;
                }
            }

            if(selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    userCheckboxes.forEach(checkbox => {
                        // Only check/uncheck if not disabled (i.e., not the current admin)
                        if (!checkbox.disabled) {
                            checkbox.checked = this.checked;
                        }
                    });
                    updateBulkActionButtons();
                });
            }

            userCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkActionButtons);
            });

            function prepareBulkForm(actionType, status = null) {
                selectedUsersInputContainer.innerHTML = ''; // Clear previous inputs
                const allSelectedIds = Array.from(document.querySelectorAll('.user-checkbox:checked'))
                                             .map(cb => cb.value);
                let actualSelectedIds = [];
                let adminSelfSelected = false;

                // Filter out admin's own ID if attempting delete or deactivate
                allSelectedIds.forEach(id => {
                    if (parseInt(id) === ADMIN_USER_ID && ADMIN_USER_ID !== null) {
                        if (actionType === 'bulk_delete_users' || (actionType === 'bulk_status_update' && status === 'no')) {
                            adminSelfSelected = true;
                        } else {
                            actualSelectedIds.push(id); // Admin can be included in activate
                        }
                    } else {
                        actualSelectedIds.push(id);
                    }
                });

                if (adminSelfSelected) {
                    if (actionType === 'bulk_delete_users') {
                        alert('You cannot delete your own account via bulk action.');
                    } else if (actionType === 'bulk_status_update' && status === 'no') {
                        alert('You cannot deactivate your own account via bulk action.');
                    }
                    if (actualSelectedIds.length === 0) return false; // If only admin was selected, or other selected fail validation
                }

                if (actualSelectedIds.length === 0) {
                    alert('Please select at least one valid user to perform this action.');
                    return false;
                }

                actualSelectedIds.forEach(id => {
                    selectedUsersInputContainer.appendChild(createHiddenInput('selected_users[]', id));
                });
                
                bulkActionTypeInput.value = actionType;
                if (status !== null) {
                    bulkStatusValueInput.name = 'bulk_status'; // Ensure name is present for status update
                    bulkStatusValueInput.value = status;
                } else {
                    bulkStatusValueInput.removeAttribute('name'); // Remove name if not a status update
                    bulkStatusValueInput.value = '';
                }
                return true;
            }

            if(bulkDeleteBtn) {
                bulkDeleteBtn.addEventListener('click', function() {
                    const allSelected = document.querySelectorAll('.user-checkbox:checked');
                    if (allSelected.length === 0) {
                        alert('Please select at least one user to delete.');
                        return;
                    }

                    if (confirm('Are you sure you want to delete the selected user(s)? This action cannot be undone.')) {
                        if (prepareBulkForm('bulk_delete_users')) {
                            bulkActionForm.submit();
                        }
                    }
                });
            }

            if(bulkActivateBtn) {
                bulkActivateBtn.addEventListener('click', function() {
                    if (document.querySelectorAll('.user-checkbox:checked').length === 0) {
                        alert('Please select at least one user to activate.');
                        return;
                    }

                    if (confirm('Are you sure you want to activate the selected user(s)?')) {
                        if (prepareBulkForm('bulk_status_update', 'yes')) {
                            bulkActionForm.submit();
                        }
                    }
                });
            }

            if(bulkDeactivateBtn) {
                bulkDeactivateBtn.addEventListener('click', function() {
                    const allSelected = document.querySelectorAll('.user-checkbox:checked');
                    if (allSelected.length === 0) {
                        alert('Please select at least one user to deactivate.');
                        return;
                    }

                    if (confirm('Are you sure you want to deactivate the selected user(s)?')) {
                        if (prepareBulkForm('bulk_status_update', 'no')) {
                            bulkActionForm.submit();
                        }
                    }
                });
            }

            updateBulkActionButtons(); // Initial call
        });
    </script>
</body>
</html>