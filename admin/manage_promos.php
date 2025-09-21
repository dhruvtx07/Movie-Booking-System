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

// User ID for operations
$userId = $_SESSION['user_id'] ?? null; // Use null if not set, for safety

// Connect to database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'"); // Set charset to UTF-8
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Check if userId is available before proceeding with operations
if ($userId === null) {
    // This case should ideally be caught by the login check, but as a safeguard.
    // If we reach here, it implies the user is not logged in but somehow
    // bypassed the header redirect. Or the session variable isn't correctly set.
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'User not authenticated. Please log in first.'];
    header("Location: $login_page");
    exit();
}

// Function to handle promo code update (full edit)
function handleUpdatePromo($pdo, $userId) {
    if (!isset($_POST['code_id']) || !isset($_POST['code']) || !isset($_POST['code_value']) || !isset($_POST['is_active']) || !isset($_POST['max_redemptions'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid promo code data for update.'];
        return;
    }

    $codeId = intval($_POST['code_id']);
    $code = trim($_POST['code']); // Trim whitespace
    $codeValue = intval($_POST['code_value']);
    $isActive = $_POST['is_active'];
    $maxRedemptions = intval($_POST['max_redemptions']);
    
    // Input validation
    if (empty($code) || $codeValue <= 0 || ($isActive !== 'yes' && $isActive !== 'no') || $maxRedemptions <= 0) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid input. Code, value, status, or max redemptions are not valid.'];
        return;
    }

    try {
        $pdo->beginTransaction(); // Start a transaction for multiple updates

        $stmt = $pdo->prepare("
            UPDATE promo_codes
            SET
                code = ?,
                code_value = ?,
                is_active = ?,
                max_redemptions = ?,
                created_on = NOW() -- Assuming created_on needs to be updated on modification
            WHERE code_id = ? AND created_by = ?
        ");
        $stmt->execute([
            $code,
            $codeValue,
            $isActive,
            $maxRedemptions,
            $codeId,
            $userId
        ]);

        $pdo->commit(); // Commit the transaction

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Promo code updated successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'Promo code not found or no changes made (or you don\'t have permission).'];
        }
    } catch (PDOException $e) {
        $pdo->rollBack(); // Rollback on error
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update promo code: ' . $e->getMessage()];
    }
}

// Function to handle promo code status update (from toggle switch)
function handleUpdatePromoStatus($pdo, $userId) {
    if (!isset($_POST['code_id']) || !isset($_POST['is_active'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid data for status update.'];
        return;
    }

    $codeId = intval($_POST['code_id']);
    $isActive = $_POST['is_active'];

    if (($isActive !== 'yes' && $isActive !== 'no')) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid status value.'];
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE promo_codes
            SET is_active = ?, created_on = NOW()
            WHERE code_id = ? AND created_by = ?
        ");
        $stmt->execute([$isActive, $codeId, $userId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Promo code status updated successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'Promo code not found or no change made (or you don\'t have permission).'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update promo code status: ' . $e->getMessage()];
    }
}

// Function to handle promo code deletion
function handleDeletePromo($pdo, $userId) {
    if (!isset($_POST['code_id'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid promo code ID for deletion.'];
        return;
    }

    $codeIds = is_array($_POST['code_id']) ? $_POST['code_id'] : [$_POST['code_id']];
    $codeIds = array_filter($codeIds, 'is_numeric');

    if (empty($codeIds)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'No valid promo codes selected for deletion.'];
        return;
    }

    $placeholders = implode(',', array_fill(0, count($codeIds), '?'));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            DELETE FROM promo_codes
            WHERE code_id IN ($placeholders) AND created_by = ?
        ");
        $executeParams = array_merge($codeIds, [$userId]);
        $stmt->execute($executeParams);
        $pdo->commit();

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => $stmt->rowCount() . ' promo code(s) deleted successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'No promo codes found or you don\'t have permission to delete them.'];
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to delete promo code(s): ' . $e->getMessage()];
    }
}

// Function to handle adding new promo code
function handleAddPromo($pdo, $userId) {
    if (!isset($_POST['code']) || !isset($_POST['code_value']) || !isset($_POST['is_active']) || !isset($_POST['max_redemptions'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid promo code data for add.'];
        return;
    }

    $code = trim($_POST['code']);
    $codeValue = intval($_POST['code_value']);
    $isActive = $_POST['is_active'];
    $maxRedemptions = intval($_POST['max_redemptions']);

    if (empty($code) || $codeValue <= 0 || ($isActive !== 'yes' && $isActive !== 'no') || $maxRedemptions <= 0) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid input. Code, value, status, or max redemptions are not valid.'];
        return;
    }

    try {
        $pdo->beginTransaction();

        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM promo_codes WHERE code = ? AND created_by = ?");
        $checkStmt->execute([$code, $userId]);
        if ($checkStmt->fetchColumn() > 0) {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'A promo code with this exact code already exists. Please choose a different one.'];
            $pdo->rollBack();
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO promo_codes (code, code_value, created_by, created_on, is_active, times_used, max_redemptions)
            VALUES (?, ?, ?, NOW(), ?, 0, ?)
        ");
        $stmt->execute([
            $code,
            $codeValue,
            $userId,
            $isActive,
            $maxRedemptions,
        ]);

        $pdo->commit();

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Promo code added successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to add promo code.'];
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to add promo code: Duplicate entry. Please choose a unique code.'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to add promo code: ' . $e->getMessage()];
        }
    }
}

// Function to handle bulk status updates
function handleBulkStatusUpdate($pdo, $userId) {
    if (!isset($_POST['selected_promos']) || !is_array($_POST['selected_promos']) || !isset($_POST['bulk_status'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid bulk status update data.'];
        return;
    }

    $codeIds = $_POST['selected_promos'];
    $newStatus = $_POST['bulk_status'];

    $codeIds = array_filter($codeIds, 'is_numeric');

    if (($newStatus !== 'yes' && $newStatus !== 'no') || empty($codeIds)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid status or no promo codes selected.'];
        return;
    }

    $placeholders = implode(',', array_fill(0, count($codeIds), '?'));

    try {
        $stmt = $pdo->prepare("
            UPDATE promo_codes
            SET is_active = ?
            WHERE code_id IN ($placeholders) AND created_by = ?
        ");
        $executeParams = array_merge([$newStatus], $codeIds, [$userId]);
        $stmt->execute($executeParams);

        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => $stmt->rowCount() . ' promo code(s) status updated to "' . ($newStatus === 'yes' ? 'Active' : 'Inactive') . '"!'];
        } else {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'No promo codes found or you don\'t have permission to update them.'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update bulk status: ' . $e->getMessage()];
    }
}


// Handle promo code actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_promo':
                handleUpdatePromo($pdo, $userId);
                break;
            case 'update_promo_status':
                handleUpdatePromoStatus($pdo, $userId);
                break;
            case 'delete_promo':
                handleDeletePromo($pdo, $userId);
                break;
            case 'add_promo':
                handleAddPromo($pdo, $userId);
                break;
            case 'bulk_delete_promos':
                handleDeletePromo($pdo, $userId);
                break;
            case 'bulk_status_update':
                handleBulkStatusUpdate($pdo, $userId);
                break;
        }
    }
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

// --- Data Fetching for Display ---
$promosPerPage = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $promosPerPage;

$filterStatus = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereClauses = [];
$queryParams = [];

// Ensure filtering by created_by is always present
$whereClauses[] = "created_by = ?";
$queryParams[] = $userId;

if ($filterStatus === 'active') {
    $whereClauses[] = "is_active = 'yes'";
} elseif ($filterStatus === 'inactive') {
    $whereClauses[] = "is_active = 'no'";
}

if (!empty($searchTerm)) {
    $whereClauses[] = "code LIKE ?";
    $queryParams[] = '%' . $searchTerm . '%';
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = " WHERE " . implode(' AND ', $whereClauses);
}

$totalPromosStmt = $pdo->prepare("SELECT COUNT(*) FROM promo_codes" . $whereSql);
$totalPromosStmt->execute($queryParams);
$totalPromos = $totalPromosStmt->fetchColumn();

$promosStmt = $pdo->prepare("
    SELECT
        code_id, code, code_value, created_by, created_on, is_active, times_used, max_redemptions
    FROM
        promo_codes
    " . $whereSql . "
    ORDER BY created_on DESC
    LIMIT ? OFFSET ?
");

$paramIndex = 1;
foreach ($queryParams as $param) {
    $promosStmt->bindValue($paramIndex++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$promosStmt->bindValue($paramIndex++, $promosPerPage, PDO::PARAM_INT); // For LIMIT
$promosStmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT); // For OFFSET

$promosStmt->execute();
$promos = $promosStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Manage Promo Codes - Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom Color Variables - Dark Theme Default (Copied from dashboard.php) */
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

            /* Responsive Font Sizes (Copied from dashboard.php) */
            --section-title-font: 2rem;
            --section-subtitle-font: 1.5rem;
            --metric-card-display-4-font: 2.5rem;
            --metric-card-h5-font: 1.1rem;
        }

        /* WebKit Scrollbar (Chrome, Safari, Edge) (Copied from dashboard.php) */
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

        /* Firefox Scrollbar (Copied from dashboard.php) */
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

        /* New wrapper for Main Content and Footer (Copied from dashboard.php) */
        .content-wrapper {
            display: flex;
            flex-direction: column; /* Stack main content and footer vertically */
            flex-grow: 1; /* Allows it to take up the remaining horizontal space */
            margin-left: var(--sidebar-width-collapsed); /* Initial margin to offset collapsed sidebar */
            transition: margin-left var(--sidebar-transition-duration) ease-in-out;
            min-height: 100vh; /* Ensures the wrapper fills at least the viewport height */
        }

        /* Sidebar Styling (Copied from dashboard.php and adapted) */
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

        /* Caret icon specific styling (Copied from dashboard.php) */
        .sidebar-nav .nav-link .caret-icon {
            font-size: 0.9rem; /* Smaller caret */
            transition: transform 0.3s ease-in-out;
            color: var(--light-text-color); /* Muted color for caret */
            margin-left: auto; /* Push to the right */
            margin-right: 0; /* Override default margin from general icon rule */
        }

        /* Rotate caret when menu is expanded (Copied from dashboard.php) */
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

        /* Sub-menu styling (Copied from dashboard.php) */
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

        /* When sidebar expands (has .is-open class), push content wrapper (Copied from dashboard.php) */
        body.sidebar-is-open .content-wrapper { /* Class added to body by JS */
            margin-left: var(--sidebar-width-expanded);
        }

        /* Main Content Area (Copied from dashboard.php) */
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
            font-size: var(--section-title-font); /* Using CSS variable from dashboard.php */
            margin-bottom: 20px;
            border-bottom: 2px solid var(--accent-red);
            padding-bottom: 10px;
        }

        /* Alert styles (Copied from dashboard.php) */
        .alert {
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            color: white; /* Ensure text is visible on colored alerts */
        }
        .alert-success { background: linear-gradient(90deg, #28a745, #218838); border: none; }
        .alert-danger { background: var(--gradient-primary); border: none; }
        .alert-warning { background: linear-gradient(90deg, var(--accent-yellow), #e0a800); border: none; }
        .alert-info { background: linear-gradient(90deg, var(--accent-orange), var(--accent-peach)); border: none; }

        /* Form elements (Copied from dashboard.php) */
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
        /* Custom styling for form-check-input (general checkbox) (Copied from dashboard.php) */
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
        
        /* Buttons (Copied and merged from dashboard.php) */
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
        .btn-success { /* For Add New User / Promo */
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

        /* Promo Cards (Original from manage_promos.php, merged with general card styles) */
        .promo-card {
            background-color: var(--secondary-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px; /* Use common border-radius */
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative; /* Crucial for absolute positioning of children */
            height: 100%; /* Ensure cards in a row have equal height */
        }
        .promo-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .promo-card .card-title {
            color: var(--accent-orange);
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        .promo-card .card-text strong {
            color: var(--accent-peach);
        }
        .promo-card .text-muted {
            font-size: 0.85rem;
            color: var(--light-text-color) !important;
        }
        .promo-card .promo-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        /* Style for the main checkbox container (bulk select) */
        .promo-card .promo-main-checkbox-container { /* TARGETING THE SPECIFIC CONTAINER */
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 10; /* Ensure it's clearly on top of other card elements */
        }
        /* Custom styling for generic checkboxes (like bulk select checkbox) */
        .promo-card .form-check-input.promo-checkbox {
            width: 1.5em;
            height: 1.5em;
            margin-top: .25em;
            vertical-align: top;
            background-color: #333; /* Darker background for unchecked checkbox */
            border: 1px solid var(--accent-orange); /* Orange border for checkbox */
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            border-radius: .25rem;
            transition: background-color .15s ease-in-out, border-color .15s ease-in-out, box-shadow .15s ease-in-out, background-image .15s ease-in-out;
            cursor: pointer;
        }
        .promo-card .form-check-input.promo-checkbox:checked {
            background-color: var(--accent-red); /* Red when checked */
            border-color: var(--accent-red);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M6 10l3 3l6-6'/%3e%3csvg%3e"); /* Add a checkmark SVG */
            background-size: 100% 100%;
        }
        .promo-card .form-check-input.promo-checkbox:focus {
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
        }

        /* Toggle Switch Styling Fixes (Original from manage_promos.php, fixed/adapted) */
        .form-check.form-switch { 
            display: flex; /* Make the switch container a flex item */
            align-items: center; /* Vertically align input and label */
            gap: 0.5rem; /* Space between input and label */
            width: fit-content; /* Ensure the container only takes necessary width */
            padding-left: 0 !important; /* Remove default padding that might affect alignment */
        }

        /* Styling for the custom toggle input */
        .form-check-input.toggle-switch {
            width: 3.5em; /* Define the explicit width of the toggle track */
            height: 2em; /* Define the explicit height of the toggle track */
            background-color: #6c757d; /* Inactive track color */
            border-radius: 1em; /* Make it pill-like */
            position: relative; /* For positioning the thumb (::after) */
            cursor: pointer;
            -webkit-appearance: none; /* Hide default checkbox */
            -moz-appearance: none;
            appearance: none;
            transition: background-color .3s ease; /* Smooth transition for background color */
            
            /* === FIX for the "two somethings" visual overlap issue === */
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
            border-radius: 50%; /* Circular shape */
            transition: left .3s ease; /* Smooth transition for thumb movement */

            /* ==== Precise positioning for the thumb ==== */
            top: 2px;
            left: 2px;
            width: calc(2em - 4px); /* Thumb width = Track height - (2 * 2px padding) */
            height: calc(2em - 4px); /* Thumb height = Track height - (2 * 2px padding) */
            
            border: none; /* Ensure thumb itself has no border */
            box-shadow: none; /* Ensure thumb itself has no shadow */
        }
        
        .form-check-input.toggle-switch:checked::after {
            left: calc(100% - (2em - 4px) - 2px); /* 100% - thumb_width - right_padding */
        }

        /* Ensure input and label don't shrink within the flex container */
        .form-check.form-switch .form-check-input.toggle-switch {
            flex-shrink: 0; /* Prevent the input from shrinking */
            margin-left: 0 !important; /* Override any lingering Bootstrap margin */
        }

        /* Pagination (Copied from dashboard.php) */
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

        /* Tabs/Filters (Copied from manage_promos.php, confirmed consistent with dashboard.php vibe) */
        .nav-tabs {
            border-bottom-color: var(--card-border);
        }
        .nav-tabs .nav-link {
            color: var(--light-text-color);
            background-color: transparent; /* Ensure no default background */
            border: 1px solid transparent;
            border-bottom-color: var(--card-border); /* Subtle bottom border for inactive tabs */
            transition: all 0.3s ease;
        }
        .nav-tabs .nav-link:hover {
            border-color: var(--card-border) var(--card-border) var(--card-border);
            color: var(--accent-orange);
            background-color: rgba(255, 107, 53, 0.1); /* Light orange hover background */
        }
        .nav-tabs .nav-link.active {
            color: var(--accent-red); /* Active tab text color */
            background-color: var(--secondary-bg); /* Background for active tab */
            border-color: var(--card-border) var(--card-border) var(--secondary-bg); /* Border matches background below it */
            border-bottom-color: var(--secondary-bg); /* To cover the shared bottom border */
            font-weight: bold;
        }
        .nav-tabs .nav-link.active:focus, .nav-tabs .nav-link.active:hover {
            border-color: var(--card-border) var(--card-border) var(--secondary-bg);
        }

        /* Modal styling (Copied from dashboard.php) */
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

        /* Bulk actions bar */
        .bulk-actions-buttons .btn {
            min-width: 120px; /* Ensure buttons have consistent width */
            padding-left: 10px; /* Adjust padding for icons */
            padding-right: 10px;
        }

        /* Alerts Fade Out Animation (Copied from dashboard.php) */
        .fade-out {
            animation: fadeOut 1s ease-out forwards;
        }

        @keyframes fadeOut {
            0% { opacity: 1; height: auto; padding: inherit; margin: inherit; }
            99% { opacity: 0; height: auto; padding: inherit; margin: inherit; }
            100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; display: none; }
        }

        /* NEW STYLING FOR SEARCH BAR - FIXED POSITIONING (Copied from manage_promos.php - it's already well-styled) */

        /* Container for the search input and buttons */
        .search-input-group-fixed {
            position: relative; /* For absolute positioning of the clear button */
            border-radius: 8px; /* Overall rounded corners */
            overflow: hidden; /* Clips children to match rounded corners */
            box-shadow: 0 2px 8px rgba(0,0,0,0.3); /* Add subtle shadow */
            background-color: var(--secondary-bg); /* Dark background for the whole bar */

            /* Override Bootstrap defaults for input-group */
            border: none;
            box-shadow: none; /* Make sure no default Bootstrap shadow interferes */

            /* Added to fix vertical alignment issue in earlier versions */
            display: flex;
            align-items: center;
        }

        /* The actual text input */
        #promoSearch {
            background-color: transparent !important; /* Inherit background from parent */
            border: none !important; /* Remove all borders */
            border-radius: 0 !important; /* Ensure no individual corner radius */
            color: var(--text-color);
            flex-grow: 1; /* Takes up remaining space */
            padding-right: 60px; /* Adjusted: Gives more space for the 'X' button now that it's moved further right. */
        }

        #promoSearch:focus {
            box-shadow: none !important; /* Remove outline/glow on focus */
        }

        /* The clear button ('x') */
        .clear-search-btn {
            position: absolute;
            top: 50%; /* Vertically center */
            right: 10px; /* Adjusted: Moves it closer to the search button. */
            transform: translateY(-50%); /* Centering trick */

            background: none;
            border: none;
            color: var(--light-text-color); /* Muted color */
            font-size: 1.2rem; /* Size of the icon */
            cursor: pointer;
            z-index: 3; /* Ensure it's above the input and search button */
            padding: 0; /* No extra padding for the button area */
            opacity: 0.7; /* Initial transparency */
            transition: opacity 0.2s ease, color 0.2s ease;
        }

        .clear-search-btn:hover {
            opacity: 1; /* Fully visible on hover */
            color: var(--accent-red); /* Highlight on hover */
        }

        /* The search (magnifying glass) button */
        #searchButton {
            background: var(--gradient-primary); /* Your orange-red gradient */
            border: none !important; /* Remove border */
            border-radius: 0 !important; /* Ensure no individual corner radius */
            color: white;
            padding: 8px 15px; /* Maintain standard padding */
            flex-shrink: 0; /* Prevent it from shrinking */
            z-index: 2; /* Keep it below the 'x' if they happen to overlap */
        }

        #searchButton:hover {
            background: var(--gradient-secondary); /* Hover gradient */
        }

        /* Footer Styling (Copied from dashboard.php) */
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


        /* Responsive Adjustments (Merged from both files) */
        @media (max-width: 768px) {
            :root { /* Update font sizes for mobile (from dashboard.php) */
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

            .content-wrapper { /* Adjust this for mobile, it shouldn't shift content */
                margin-left: 0; /* Main content takes full width on small screens, no offset */
                padding-top: 15px; /* Adjust top padding for content */
            }
            /* When sidebar is active, main content doesn't shift, it gets overlaid */
            /* body.sidebar-is-open .content-wrapper rule is overridden by this media query */


            /* Add a button to toggle sidebar on small screens (Copied from dashboard.php) */
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
            .sidebar-toggle-btn:hover {
                transform: scale(1.05); /* Added for better mobile UX */
            }


            .promo-card .promo-main-checkbox-container { /* Specific container needed for position static */
                position: static; /* Adjust checkbox position for better mobile layout */
                margin-bottom: 10px;
                text-align: right;
            }

            /* Adjust layout for smaller screens */
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
            .footer { /* From dashboard.php */
                padding: 15px; /* Less padding */
                text-align: center; /* Center text on small screens */
            }
            .footer .col-md-6 { /* From dashboard.php */
                text-align: center !important; /* Force center for both columns */
            }
            .footer .row { /* From dashboard.php */
                flex-direction: column; /* Stack columns */
            }
            .footer .col-md-6:first-child { /* From dashboard.php */
                margin-bottom: 10px; /* Space between stacked columns */
            }
        }
        @media (min-width: 769px) { /* Desktop */
            .sidebar-toggle-btn {
                display: none; /* Hide on larger screens */
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
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-<?= $_SESSION['message']['type'] ?> alert-dismissible fade show" role="alert">
                        <?= $_SESSION['message']['text'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['message']); ?>
                <?php endif; ?>

                <h2 class="section-title">Manage Promo Codes</h2>

                <div class="row mb-4 align-items-center">
                    <div class="col-md-6 col-lg-4">
                        <div class="input-group search-input-group-fixed">
                            <input type="text" class="form-control" placeholder="Search by Code..." id="promoSearch" value="<?= htmlspecialchars($searchTerm) ?>">
                            <button class="btn clear-search-btn" type="button" id="clearSearchBtn" title="Clear search" style="display: none;">
                                <i class="fas fa-times"></i>
                            </button>
                            <button class="btn btn-primary" type="button" id="searchButton"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 text-md-end mt-3 mt-md-0">
                        <p class="total-promos mb-0">Total: <span class="badge bg-secondary"><?= $totalPromos ?></span> promo codes</p>
                    </div>
                    <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPromoModal">
                            <i class="fas fa-plus me-1"></i> Add New Promo Code
                        </button>
                    </div>
                </div>

                <!-- Tabbed Filters -->
                <ul class="nav nav-tabs mb-4" id="promoTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $filterStatus === 'all' ? 'active' : '' ?>" id="all-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="all" aria-selected="<?= $filterStatus === 'all' ? 'true' : 'false' ?>" onclick="window.location.href='?filter=all&search=<?= urlencode($searchTerm) ?>'">All</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $filterStatus === 'active' ? 'active' : '' ?>" id="active-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="active" aria-selected="<?= $filterStatus === 'active' ? 'true' : 'false' ?>" onclick="window.location.href='?filter=active&search=<?= urlencode($searchTerm) ?>'">Active</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $filterStatus === 'inactive' ? 'active' : '' ?>" id="inactive-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="inactive" aria-selected="<?= $filterStatus === 'inactive' ? 'true' : 'false' ?>" onclick="window.location.href='?filter=inactive&search=<?= urlencode($searchTerm) ?>'">Inactive</button>
                    </li>
                </ul>

                <!-- Bulk Actions -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4">
                    <div class="form-check me-md-3 mb-3 mb-md-0">
                        <input class="form-check-input" type="checkbox" id="selectAllPromos">
                        <label class="form-check-label" for="selectAllPromos">Select All</label>
                    </div>
                    <div class="bulk-actions-buttons">
                        <button class="btn btn-danger me-2" id="bulkDeleteBtn" disabled><i class="fas fa-trash-alt me-1"></i> Delete Selected</button>
                        <button class="btn btn-primary me-2" id="bulkActivateBtn" disabled><i class="fas fa-check-circle me-1"></i> Activate Selected</button>
                        <button class="btn btn-secondary" id="bulkDeactivateBtn" disabled><i class="fas fa-times-circle me-1"></i> Deactivate Selected</button>
                    </div>
                </div>

                <?php if (empty($promos)): ?>
                    <div class="alert alert-info" role="alert">No promo codes found for the selected filters.</div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($promos as $promo): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="promo-card"
                                    data-code-id="<?= htmlspecialchars($promo['code_id']) ?>"
                                    data-code="<?= htmlspecialchars($promo['code']) ?>"
                                    data-code-value="<?= htmlspecialchars($promo['code_value']) ?>"
                                    data-is-active="<?= htmlspecialchars($promo['is_active']) ?>"
                                    data-max-redemptions="<?= htmlspecialchars($promo['max_redemptions']) ?>"
                                    data-times-used="<?= htmlspecialchars($promo['times_used']) ?>">

                                    <!-- Used unique class 'promo-main-checkbox-container' for specific styling -->
                                    <div class="form-check promo-main-checkbox-container"> 
                                        <input class="form-check-input promo-checkbox" type="checkbox" value="<?= $promo['code_id'] ?>">
                                    </div>
                                    <h5 class="card-title"><?= htmlspecialchars($promo['code']) ?></h5>
                                    <p class="card-text">
                                        <strong>Discount:</strong> ₹<?= htmlspecialchars($promo['code_value']) ?>
                                    </p>
                                    <p class="card-text">
                                        <strong>Status:</strong>
                                        <div class="form-check form-switch d-inline-flex align-items-center ms-2">
                                            <input class="form-check-input toggle-switch promo-active-toggle" type="checkbox" role="switch" id="statusToggle<?= $promo['code_id'] ?>"
                                                <?= $promo['is_active'] == 'yes' ? 'checked' : '' ?> data-code-id="<?= $promo['code_id'] ?>">
                                        </div>
                                    </p>
                                    <p class="card-text">
                                        <strong>Used:</strong> <?= htmlspecialchars($promo['times_used']) ?> / <?= htmlspecialchars($promo['max_redemptions']) ?>
                                    </p>
                                    <div class="text-muted">
                                        Created: <?= date('M j, Y g:i A', strtotime($promo['created_on'])) ?>
                                    </div>
                                    <div class="promo-actions">
                                        <button class="btn btn-sm btn-primary edit-promo-btn"
                                            data-bs-toggle="modal" data-bs-target="#editPromoModal">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <form method="post" action="" class="d-inline delete-promo-form">
                                            <input type="hidden" name="action" value="delete_promo">
                                            <input type="hidden" name="code_id" value="<?= htmlspecialchars($promo['code_id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete promo code: <?= htmlspecialchars($promo['code']) ?>?')">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php
                            $totalPages = ceil($totalPromos / $promosPerPage);
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

    <!-- Add Promo Code Modal -->
    <div class="modal fade" id="addPromoModal" tabindex="-1" aria-labelledby="addPromoModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addPromoModalLabel">Add New Promo Code</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_promo">

                        <div class="mb-3">
                            <label for="new_code" class="form-label">Promo Code:</label>
                            <input type="text" class="form-control" id="new_code" name="code" required maxlength="50">
                        </div>

                        <div class="mb-3">
                            <label for="new_code_value" class="form-label">Discount Value (₹):</label>
                            <input type="number" class="form-control" id="new_code_value" name="code_value" required min="1">
                        </div>

                        <div class="mb-3">
                            <label for="new_max_redemptions" class="form-label">Maximum Redemptions:</label>
                            <input type="number" class="form-control" id="new_max_redemptions" name="max_redemptions" required min="1">
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
                        <button type="submit" class="btn btn-primary">Add Promo Code</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Promo Code Modal -->
    <div class="modal fade" id="editPromoModal" tabindex="-1" aria-labelledby="editPromoModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editPromoModalLabel">Edit Promo Code</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_promo">
                        <input type="hidden" name="code_id" id="edit_code_id">

                        <div class="mb-3">
                            <label for="edit_code" class="form-label">Promo Code:</label>
                            <input type="text" class="form-control" id="edit_code" name="code" required maxlength="50">
                        </div>

                        <div class="mb-3">
                            <label for="edit_code_value" class="form-label">Discount Value (₹):</label>
                            <input type="number" class="form-control" id="edit_code_value" name="code_value" required min="1">
                        </div>

                        <div class="mb-3">
                            <label for="edit_max_redemptions" class="form-label">Maximum Redemptions:</label>
                            <input type="number" class="form-control" id="edit_max_redemptions" name="max_redemptions" required min="1">
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
        <div id="selectedPromosInputContainer">
            <!-- Hidden inputs for selected IDs will be added here by JS -->
        </div>
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts after 3 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('fade-out');
                    setTimeout(() => {
                        alert.remove();
                    }, 1000); // Wait for animation to complete before removing
                }, 3000); // 3 seconds
            });

            // Sidebar Toggle (Copied from dashboard.php)
            const sidebar = document.getElementById('sidebar');
            const contentWrapper = document.getElementById('contentWrapper'); // Changed from mainContent in dashboard.php for full wrapper
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

            // --- Active Link and Submenu Management (Copied from dashboard.php) ---
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

            navLinks.forEach(link => {
                // Get the link's href and extract just the filename (e.g., "dashboard.php")
                const linkHref = link.getAttribute('href');
                if (!linkHref || linkHref.startsWith('#')) return; // Skip links that are just collapse toggles

                // Important: Account for paths like 'event_assignment/event_assignment.php'
                let currentFilename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
                let linkFilename = linkHref.substring(linkHref.lastIndexOf('/') + 1);

                // For nested paths (e.g., event_assignment/event_assignment.php)
                if (linkHref.includes('/')) {
                    const parts = linkHref.split('/');
                    if (parts.length > 1) { // Check if there's at least one slash
                        linkFilename = parts[parts.length - 2] + '/' + parts[parts.length - 1];
                        // If currentPath is like /event_assignment/event_assignment.php, currentFilename will be event_assignment.php.
                        // So we need to ensure currentPath.split('/').slice(-2).join('/') matches linkFilename.
                        const currentPathParts = currentPath.split('/');
                        if (currentPathParts.length >= 2) {
                            currentFilename = currentPathParts[currentPathParts.length - 2] + '/' + currentPathParts[currentPathParts.length - 1];
                        }
                    }
                }

                // Fallback for direct filenames if nested check fails or is not applicable
                if (!linkFilename.includes('/') && !currentFilename.includes('/')) {
                   currentFilename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
                   linkFilename = linkHref.substring(linkHref.lastIndexOf('/') + 1);
                }


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

            // --- Caret Icon Rotation on Collapse Events (Copied from dashboard.php) ---
            document.querySelectorAll('.sidebar-nav .collapse').forEach(collapseElement => {
                collapseElement.addEventListener('show.bs.collapse', function () {
                    const toggleLink = document.querySelector(`a[data-bs-target="#${this.id}"]`);
                    if (toggleLink) {
                        const caretIcon = toggleLink.querySelector('.caret-icon');
                        if (caretIcon) caretIcon.style.transform = 'rotate(180deg)';
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


            // Handle search filter (Original manage_promos.php logic)
            const promoSearchInput = document.getElementById('promoSearch');
            const searchButton = document.getElementById('searchButton');
            const clearSearchBtn = document.getElementById('clearSearchBtn'); // Get the clear button

            // Function to toggle clear button visibility
            function toggleClearButton() {
                if (promoSearchInput.value.trim() !== '') {
                    clearSearchBtn.style.display = 'block';
                } else {
                    clearSearchBtn.style.display = 'none';
                }
            }

            function applyFilters() {
                const searchTerm = promoSearchInput.value.trim();
                const currentFilterLink = document.querySelector('.nav-tabs .nav-link.active');
                let filterParam = '';
                if (currentFilterLink) {
                    const currentFilter = currentFilterLink.getAttribute('aria-controls');
                    if (currentFilter !== 'all') {
                        filterParam = `filter=${encodeURIComponent(currentFilter)}`; // Correctly encode
                    }
                }

                let url = '';
                let params = [];
                if (filterParam) {
                    params.push(filterParam);
                }
                if (searchTerm) { // Only add search param if not empty
                    params.push(`search=${encodeURIComponent(searchTerm)}`);
                }

                if (params.length > 0) {
                    url = '?' + params.join('&');
                } else {
                    url = window.location.pathname; // If no params, just go to the base URL
                }

                window.location.href = url;
            }

            if (searchButton) {
                searchButton.addEventListener('click', applyFilters);
            }

            if (promoSearchInput) {
                // Initial check for clear button visibility on page load
                toggleClearButton(); 

                promoSearchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        applyFilters();
                    }
                });

                // Listen for input changes to show/hide the clear button
                promoSearchInput.addEventListener('input', toggleClearButton);
            }

            // Handle clearing the search
            if (clearSearchBtn) {
                clearSearchBtn.addEventListener('click', function() {
                    promoSearchInput.value = ''; // Clear the input field
                    applyFilters(); // Re-apply filters, which will cause a page reload without the search term
                });
            }
            
            // Populate main form's display input for edit mode
            document.getElementById('editPromoModal')?.addEventListener('show.bs.modal', function(event) {
                const promoCard = event.relatedTarget.closest('.promo-card');
                if (!promoCard) return;

                document.getElementById('edit_code_id').value = promoCard.dataset.codeId;
                document.getElementById('edit_code').value = promoCard.dataset.code;
                document.getElementById('edit_code_value').value = promoCard.dataset.codeValue;
                document.getElementById('edit_max_redemptions').value = promoCard.dataset.maxRedemptions;

                document.getElementById('edit_is_active').value = promoCard.dataset.isActive;
            });

            // Helper function for creating hidden inputs
            function createHiddenInput(name, value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                return input;
            }

            // Toggle switch for individual promo status update
            document.querySelectorAll('.promo-active-toggle').forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const promoCard = this.closest('.promo-card');
                    const codeId = promoCard.dataset.codeId;
                    const newStatus = this.checked ? 'yes' : 'no';

                    if (!confirm(`Are you sure you want to change the status of this promo code to "${newStatus === 'yes' ? 'Active' : 'Inactive'}"?`)) {
                        this.checked = !this.checked; // Revert checkbox state if user cancels
                        return;
                    }

                    const form = document.createElement('form');
                    form.method = 'post';
                    form.action = ''; // Submit to self

                    form.appendChild(createHiddenInput('action', 'update_promo_status'));
                    form.appendChild(createHiddenInput('code_id', codeId));
                    form.appendChild(createHiddenInput('is_active', newStatus));

                    document.body.appendChild(form); // Append to body to submit
                    form.submit();
                });
            });


            // Bulk Actions Logic
            const selectAllCheckbox = document.getElementById('selectAllPromos');
            const promoCheckboxes = document.querySelectorAll('.promo-checkbox');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const bulkActivateBtn = document.getElementById('bulkActivateBtn');
            const bulkDeactivateBtn = document.getElementById('bulkDeactivateBtn');
            const bulkActionTypeInput = document.getElementById('bulkActionType');
            const bulkStatusValueInput = document.getElementById('bulkStatusValue');
            const selectedPromosInputContainer = document.getElementById('selectedPromosInputContainer');
            const bulkActionForm = document.getElementById('bulkActionForm');

            function updateBulkActionButtons() {
                const checkedCount = document.querySelectorAll('.promo-checkbox:checked').length;
                if(bulkDeleteBtn) bulkDeleteBtn.disabled = checkedCount === 0;
                if(bulkActivateBtn) bulkActivateBtn.disabled = checkedCount === 0;
                if(bulkDeactivateBtn) bulkDeactivateBtn.disabled = checkedCount === 0;
                if(selectAllCheckbox) {
                    const totalCheckboxes = promoCheckboxes.length;
                    selectAllCheckbox.checked = totalCheckboxes > 0 && checkedCount === totalCheckboxes;
                    selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < totalCheckboxes;
                }
            }

            if(selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    promoCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateBulkActionButtons();
                });
            }

            promoCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkActionButtons);
            });

            function prepareBulkForm(actionType, status = null) {
                selectedPromosInputContainer.innerHTML = '';
                const selectedIds = Array.from(document.querySelectorAll('.promo-checkbox:checked')).map(cb => cb.value);

                if (selectedIds.length === 0) {
                    alert('Please select at least one promo code.');
                    return false;
                }

                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_promos[]';
                    input.value = id;
                    selectedPromosInputContainer.appendChild(input);
                });

                bulkActionTypeInput.value = actionType;
                if (status !== null) {
                    bulkStatusValueInput.name = 'bulk_status';
                    bulkStatusValueInput.value = status;
                } else {
                    // Important: If status is not relevant for this action, remove the name attribute
                    // so it's not sent with an empty value
                    bulkStatusValueInput.removeAttribute('name'); 
                    bulkStatusValueInput.value = ''; 
                }
                return true;
            }

            if(bulkDeleteBtn) {
                bulkDeleteBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to delete the selected promo codes? This action cannot be undone.')) {
                        if (prepareBulkForm('bulk_delete_promos')) {
                            bulkActionForm.submit();
                        }
                    }
                });
            }

            if(bulkActivateBtn) {
                bulkActivateBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to activate the selected promo codes?')) {
                        if (prepareBulkForm('bulk_status_update', 'yes')) {
                            bulkActionForm.submit();
                        }
                    }
                });
            }

            if(bulkDeactivateBtn) {
                bulkDeactivateBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to deactivate the selected promo codes?')) {
                        if (prepareBulkForm('bulk_status_update', 'no')) {
                            bulkActionForm.submit();
                        }
                    }
                });
            }

            updateBulkActionButtons();
        });
    </script>
</body>
</html>