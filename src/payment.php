<?php
// Start session and check login
session_start();
date_default_timezone_set('Asia/Kolkata');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
require_once 'config/db_config.php';

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

// Check if required session data exists
if (!isset($_SESSION['booking_data'])) {
    die("Missing booking data in session. Please go back and try again.");
}

// Get data from session
$bookingData = $_SESSION['booking_data'];
$selectedTickets = $bookingData['selected_tickets'] ?? [];
$userId = $_SESSION['user_id'] ?? 0;

// Validate required data
if (empty($bookingData['event_id']) || empty($bookingData['schedule_id']) || empty($selectedTickets) || $userId == 0) {
    die("Invalid booking data in session. Please go back and try again.");
}

// Calculate total amount and group tickets by type
$selectedTicketsByType = [];
$totalAmount = 0;

foreach ($selectedTickets as $ticket) {
    if (!isset($ticket['type'], $ticket['price'], $ticket['id'], $ticket['location'])) {
        die("Invalid ticket structure in session. Missing required fields.");
    }
    
    if (!isset($selectedTicketsByType[$ticket['type']])) {
        $selectedTicketsByType[$ticket['type']] = [];
    }
    $selectedTicketsByType[$ticket['type']][] = $ticket;
    $totalAmount += $ticket['price'];
}

if ($totalAmount <= 0) {
    die("Invalid ticket prices in session. Total amount cannot be zero or negative.");
}

// Initialize promo code variables
$promoCode = '';
$promoDiscount = 0;
$promoMessage = '';
$promoError = '';
$promoApplied = false;
$code_id = null;

// Fetch available promo codes from database
$availablePromoCodes = [];
try {
    $stmt = $pdo->query("SELECT * FROM promo_codes WHERE is_active = 'yes' ");
    $availablePromoCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch promo codes: " . $e->getMessage());
}

// Process promo code if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['apply_promo'])) {
        $promoCode = trim($_POST['promo_code']);
        
        if (!empty($promoCode)) {
            // Check promo code in database
            $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code = ? AND is_active = 'yes'");
            $stmt->execute([$promoCode]);
            $promoData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($promoData) {
                $code_id = $promoData['code_id'];
                // Ensure discount doesn't make total negative
                $promoDiscount = min($promoData['code_value'], $totalAmount);
                $promoMessage = "Promo code applied successfully! ₹{$promoDiscount} discount applied.";
                $promoApplied = true;
                
                // Store promo data in session
                $_SESSION['promo_code'] = $promoData;
                $_SESSION['promo_code_id'] = $code_id;
                
                // Debug logging
                error_log("Promo code applied. Code ID: {$code_id}, Discount: ₹{$promoDiscount}");
            } else {
                $promoError = "Invalid or expired promo code";
            }
        } else {
            $promoError = "Please enter a promo code";
        }
    } elseif (isset($_POST['apply_promo_from_list'])) {
        // Apply promo code from the list
        $promoCodeId = (int)$_POST['promo_code_id'];
        
        // Find the selected promo code
        foreach ($availablePromoCodes as $promo) {
            if ($promo['code_id'] == $promoCodeId) {
                $code_id = $promo['code_id'];
                $promoCode = $promo['code'];
                // Ensure discount doesn't make total negative
                $promoDiscount = min($promo['code_value'], $totalAmount);
                $promoMessage = "Promo code '{$promoCode}' applied successfully! ₹{$promoDiscount} discount applied.";
                $promoApplied = true;
                
                // Store promo data in session
                $_SESSION['promo_code'] = $promo;
                $_SESSION['promo_code_id'] = $code_id;
                break;
            }
        }
    } elseif (isset($_POST['remove_promo'])) {
        unset($_SESSION['promo_code']);
        unset($_SESSION['promo_code_id']);
        $promoCode = '';
        $promoDiscount = 0;
        $promoApplied = false;
        $code_id = null;
    }
}

// Check if promo code is already applied from session
if (isset($_SESSION['promo_code'])) {
    $promoData = $_SESSION['promo_code'];
    $promoCode = $promoData['code'];
    $code_id = $promoData['code_id'];
    $promoDiscount = min($promoData['code_value'], $totalAmount);
    $promoApplied = true;
}

// Calculate convenience fee and GST
$convenienceFee = 0;
$gst = round(($totalAmount - $promoDiscount + $convenienceFee) * 0, 2);
$grandTotal = $totalAmount - $promoDiscount + $convenienceFee + $gst;

// Store the calculated total in session for payment processing
$_SESSION['booking_data']['total_amount'] = $grandTotal;
$_SESSION['booking_data']['promo_discount'] = $promoDiscount;

// Process payment if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $paymentMethod = $_POST['payment_method'] ?? '';
    
    // Validate payment method
    if (!in_array($paymentMethod, ['credit_card', 'upi', 'netbanking'])) {
        die("Invalid payment method selected.");
    }
    
    // Generate booking reference
    $bookingRef = 'BK-' . date('Ymd-His') . '-' . strtoupper(substr(uniqid(), -4));
    
    // Calculate per-ticket discount
    $numTickets = count($selectedTickets);
    $perTicketDiscount = $numTickets > 0 ? ((float)$promoDiscount / $numTickets) : 0;

    $currentDateTime = date('Y-m-d H:i:s');
    
    try {
        $pdo->beginTransaction();
        
        // Get code_id from session or set to NULL if no promo applied
        $code_id_to_insert = $_SESSION['promo_code_id'] ?? null;

        error_log("Inserting booking with code_id: " . var_export($code_id, true));
        
        foreach ($selectedTickets as $ticket) {
            $ticketBookingAmt = $ticket['price'];
            $ticketTotalAmt = ((float)$ticket['price'] - $perTicketDiscount);

            
            
            // Debug logging
            error_log("Inserting ticket {$ticket['id']} with code_id: " . ($code_id_to_insert ?? 'NULL'));
            
            $stmt = $pdo->prepare("INSERT INTO bookings (
                booking_ref, user_id, event_id, ticket_id, code_id, 
                booking_amt, total_amt, payment_method, checked_in, 
                booked_by, booked_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, 'no',
                ?, ?
            )");
            
            $result = $stmt->execute([
                $bookingRef,
                $userId,
                $bookingData['event_id'],
                $ticket['id'],
                $code_id_to_insert,
                $ticketBookingAmt,
                $ticketTotalAmt,
                $paymentMethod,
                $userId,
                $currentDateTime
            ]);
            
            if (!$result) {
                error_log("Insert failed for ticket {$ticket['id']}");
                $pdo->rollBack();
                die("Error processing booking. Please try again.");
            }
        }
        
        $pdo->commit();
        
        // Store booking confirmation data
        $_SESSION['booking_confirmation'] = [
            'booking_ref' => $bookingRef,
            'event_id' => $bookingData['event_id'],
            'venue_id' => $bookingData['venue_id'],
            'schedule_id' => $bookingData['schedule_id'],
            'event_name' => $bookingData['event_name'],
            'venue_name' => $bookingData['venue_name'],
            'sub_venue_name' => $bookingData['sub_venue_name'],
            'slot_time' => $bookingData['slot_time'],
            'tickets' => $selectedTickets,
            'total_amount' => $grandTotal,
            'original_amount' => $totalAmount,
            'promo_discount' => $promoDiscount,
            'payment_method' => $paymentMethod,
            'code_id_used' => $code_id_to_insert
        ];
        
        header("Location: $booking_confirmation");
        exit();
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Booking error: " . $e->getMessage());
        error_log("SQL Error Info: " . print_r($stmt->errorInfo(), true));
        die("Error processing booking. Please try again or contact support.");
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : '';

// Fetch cities from database (only if logged in)
$cities = [];
if ($isLoggedIn) {
    try {
        $stmt = $pdo->query("SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name");
        $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch cities: " . $e->getMessage());
        $cities = [
            ['city_id' => 1, 'city_name' => 'Mumbai'],
            ['city_id' => 2, 'city_name' => 'Delhi'],
            ['city_id' => 3, 'city_name' => 'Bangalore']
        ];
    }
}

function getCityNameById($cities, $cityId) {
    foreach ($cities as $city) {
        if ($city['city_id'] == $cityId) {
            return $city['city_name'];
        }
    }
    return 'Unknown City';
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - <?= htmlspecialchars($bookingData['event_name'] ?? 'Event') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
/* Navbar Styles */
.profile-pic {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 8px;
}

.dropdown-toggle::after {
    vertical-align: middle;
}

.welcome-text {
    font-size: 0.9rem;
    margin-right: 5px;
    color: var(--nav-text);
}

.logo-img {
    height: 30px;
    width: auto;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
}

.top-navbar {
    background-color: var(--nav-dark) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}

.top-navbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1030;
}

.second-navbar {
    background-color: var(--nav-dark) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}

.second-navbar {
    top: 54px;
    left: 0;
    right: 0;
    z-index: 1020;
    white-space: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    margin-top: 0;
    border-top: none;
}

.second-navbar::-webkit-scrollbar {
    display: none;
}

.second-navbar .navbar-nav {
    display: inline-flex;
    flex-wrap: nowrap;
    flex-direction: row;
}

.second-navbar .nav-item {
    flex-shrink: 0;
}

.second-navbar .nav-link {
    padding: 0.5rem 0.75rem;
    font-size: 0.9rem;
}

.second-navbar .navbar-collapse {
    display: flex !important;
    flex-basis: auto !important;
}

.second-navbar .navbar-toggler {
    display: none !important;
}

.search-form {
    width: 100%;
    max-width: 500px;
    position: relative;
}

.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background-color: var(--card-bg);
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 0 0 8px 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    z-index: 1000;
    max-height: 400px;
    overflow-y: auto;
    display: none;
}

.search-result-item {
    padding: 10px 15px;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    transition: all 0.2s;
    color: var(--text-color);
    text-decoration: none;
    display: block;
}

.search-result-item:hover {
    background-color: var(--secondary-bg);
    transform: translateX(5px);
    color: var(--text-color);
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-type {
    font-size: 0.8rem;
    color: var(--accent-orange);
    text-transform: capitalize;
    font-weight: 500;
}

.search-result-name {
    font-weight: 500;
    transition: all 0.2s;
}

.search-result-item:hover .search-result-name {
    color: var(--accent-orange);
}

.nav-content-wrapper {
    display: flex;
    width: 100%;
    align-items: center;
}

.brand-section {
    flex: 0 0 auto;
}

.search-section {
    flex: 1 1 auto;
    min-width: 0;
    padding: 0 15px;
}

.right-section {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 15px;
}

.city-selector {
    min-width: 150px;
}

.dropdown-menu {
    z-index: 1050;
    background-color: var(--nav-dark);
    border: 1px solid rgba(255,255,255,0.1);
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
}

.city-dropdown-menu {
    max-height: 400px;
    overflow-y: auto;
    width: 300px;
    padding: 0;
}

.city-search-container {
    padding: 0.5rem 1rem;
    position: sticky;
    top: 0;
    background-color: var(--nav-dark);
    z-index: 1;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.city-search-input {
    background-color: rgba(255,255,255,0.1);
    color: white;
    border: none;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    width: 100%;
}

.city-search-input:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(255,107,53,0.5);
}

.city-item {
    padding: 0.5rem 1rem;
    cursor: pointer;
    transition: all 0.2s;
}

.city-item:hover {
    background-color: rgba(255,255,255,0.1);
}

.dropdown-item {
    color: var(--nav-text);
    transition: all 0.2s ease;
}

.dropdown-item:hover {
    background: var(--gradient-secondary);
    color: white;
    transform: translateX(5px);
}

.btn-danger {
    background: var(--gradient-primary);
    border: none;
    box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
    transition: all 0.3s ease;
}

.btn-danger:hover {
    background: var(--gradient-primary);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(229, 9, 20, 0.4);
}

.btn-outline-light {
    color: var(--nav-text);
    border-color: var(--nav-text);
    background: transparent;
    transition: all 0.3s ease;
}

.nav-link {
    color: var(--nav-text);
    transition: all 0.2s;
    position: relative;
    padding: 0.5rem 1rem;
    white-space: nowrap;
}

.nav-link::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    width: 0;
    height: 2px;
    background: var(--gradient-primary);
    transition: all 0.3s ease;
    transform: translateX(-50%);
}

.nav-link:hover {
    color: var(--nav-text);
}

.nav-link:hover::after {
    width: 100%;
}

.active-nav {
    color: var(--nav-text) !important;
    font-weight: bold;
}

.active-nav::after {
    width: 100%;
    background: var(--gradient-primary);
}

.theme-toggle {
    background: none;
    border: none;
    font-size: 1.2rem;
    color: var(--nav-text);
    cursor: pointer;
    transition: all 0.3s ease;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.theme-toggle:hover {
    transform: rotate(30deg);
    background: rgba(255,255,255,0.1);
}

.alert {
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.alert-info {
    background: var(--gradient-secondary);
    color: white;
    border: none;
}

.alert-warning {
    background: var(--gradient-primary);
    color: white;
    border: none;
}

.mobile-menu-dropdown {
    display: none;
}

.mobile-menu-toggle {
    display: none;
    background: none;
    border: none;
    color: var(--nav-text);
    font-size: 1.2rem;
    padding: 0.5rem;
    margin-left: 0.5rem;
}

.mobile-menu-dropdown .dropdown-menu {
    width: 100%;
    background-color: var(--nav-dark);
}

.mobile-menu-dropdown .dropdown-item {
    padding: 0.75rem 1.5rem;
}

.mobile-menu-dropdown .dropdown-item i {
    margin-right: 10px;
    width: 20px;
    text-align: center;
}

.mobile-menu-dropdown .dropdown-divider {
    border-color: rgba(255,255,255,0.1);
}

.fade-out {
    animation: fadeOut 3s ease-out forwards;
}

@keyframes fadeOut {
    0% { opacity: 1; }
    100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }
}

@media (max-width: 992px) {
    body {
        margin-top: 12px;
    }
    
    .second-navbar {
        display: none;
    }
    
    .mobile-menu-toggle {
        display: block;
    }
    
    .mobile-menu-dropdown {
        display: block;
    }
    
    .nav-content-wrapper {
        flex-wrap: wrap;
    }
    
    .search-section {
        order: 3;
        flex: 0 0 100%;
        padding: 10px 0;
        margin-top: 10px;
    }
    
    .right-section {
        order: 2;
        margin-left: auto;
    }
    
    .city-selector {
        min-width: 120px;
    }
}

@media (max-width: 768px) {
    body {
        margin-top: 40px;
    }
    
    .container.my-4 {
        margin-top: 1rem !important;
        padding-top: 0.5rem;
    }
}

/* Root variables for dark/light theme */
:root {
    --primary-bg: #ffffff;
    --secondary-bg: #f8f9fa;
    --text-color: #141414;
    --accent-red: #e50914;
    --accent-orange: #ff6b35;
    --accent-peach: #ff9e7d;
    --accent-black: #141414;
    --card-bg: #ffffff;
    --nav-dark: #141414;
    --nav-text: #ffffff;
    --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
    --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
}

[data-bs-theme="dark"] {
    --primary-bg: #121212;
    --secondary-bg: #1e1e1e;
    --text-color: #f8f9fa;
    --accent-red: #e50914;
    --accent-orange: #ff6b35;
    --accent-peach: #ff9e7d;
    --accent-black: #f8f9fa;
    --card-bg: #1e1e1e;
    --nav-dark: #000000;
    --nav-text: #ffffff;
    --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
    --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
}

/* Payment Page Specific Styles */
.payment-container {
    margin-top: 110px;
}

/* Rest of your existing payment page styles... */


    :root {
        /* Color definitions */
        --bms-black: #1a1a1a;
        --bms-dark: #2a2a2a;
        --bms-red: #e63946;
        --bms-peach: #ff9a8b;
        --bms-orange: #ff7e33;
        --bms-yellow: #ffc107;
        --bms-light: #f8f9fa;
        
        /* Gradients */
        --bms-gradient: linear-gradient(135deg, var(--bms-red) 0%, var(--bms-orange) 100%);
        --bms-gradient-reverse: linear-gradient(135deg, var(--bms-orange) 0%, var(--bms-red) 100%);
        --bms-gradient-light: linear-gradient(135deg, var(--bms-peach) 0%, var(--bms-yellow) 100%);
        
        /* Dark mode variables */
        --bg-color: var(--bms-black);
        --container-bg: var(--bms-dark);
        --text-color: white;
        --text-muted: rgba(255, 255, 255, 0.7);
        --form-bg: rgba(255, 255, 255, 0.05);
        --form-border: rgba(255, 255, 255, 0.1);
        --placeholder-color: rgba(255, 255, 255, 0.4);
        --divider-color: rgba(255, 255, 255, 0.1);
        --link-color: var(--bms-peach);
        --link-hover: white;
        --card-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        --success-bg: rgba(25, 135, 84, 0.15);
        --error-bg: rgba(220, 53, 69, 0.15);
    }
    
    /* Light mode overrides */
    .light-mode {
        --bg-color: #f5f5f5;
        --container-bg: white;
        --text-color: #333;
        --text-muted: rgba(0, 0, 0, 0.6);
        --form-bg: rgba(0, 0, 0, 0.03);
        --form-border: rgba(0, 0, 0, 0.1);
        --placeholder-color: rgba(0, 0, 0, 0.4);
        --divider-color: rgba(0, 0, 0, 0.1);
        --link-color: var(--bms-red);
        --link-hover: var(--bms-orange);
        --card-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        --success-bg: rgba(25, 135, 84, 0.1);
        --error-bg: rgba(220, 53, 69, 0.1);
    }
    
    body {
        background-color: var(--bg-color);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: var(--text-color);
        transition: background-color 0.3s ease, color 0.3s ease;
        min-height: 100vh;
        line-height: 1.6;
    }
    
    .main-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .payment-container {
        width: 100%;
        margin: 0 auto;
    }
    
    .event-card, .payment-card {
        border-radius: 10px;
        box-shadow: var(--card-shadow);
        margin-bottom: 20px;
        background: var(--container-bg);
        border: 1px solid var(--form-border);
        transition: all 0.3s ease;
        overflow: hidden;
    }
    
    .event-header {
        background: var(--bms-gradient);
        color: white;
        border-radius: 10px 10px 0 0;
        padding: 15px;
        font-weight: 700;
    }
    
    .card-header {
        background-color: var(--container-bg);
        border-bottom: 1px solid var(--form-border);
        padding: 15px;
        font-weight: 700;
        color: var(--text-color);
    }
    
    .event-body, .card-body {
        background-color: var(--container-bg);
        padding: 20px;
        border-radius: 0 0 10px 10px;
        color: var(--text-color);
    }
    
    .ticket-card {
        border-left: 4px solid var(--bms-red);
        margin-bottom: 15px;
        background-color: var(--form-bg);
        border-radius: 6px;
        transition: all 0.3s ease;
    }
    
    .ticket-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(230, 57, 70, 0.2);
        background-color: var(--container-bg);
    }
    
    .payment-methods .nav-link {
        border: 1px solid var(--form-border);
        border-radius: 6px;
        margin-bottom: 10px;
        padding: 12px;
        color: var(--text-color);
        background-color: var(--form-bg);
        transition: all 0.3s ease;
    }
    
    .payment-methods .nav-link.active {
        border-color: var(--bms-red);
        background: rgba(230, 57, 70, 0.1);
        box-shadow: 0 0 0 0.25rem rgba(230, 57, 70, 0.1);
        color: var(--text-color);
    }
    
    .payment-methods .nav-link:hover {
        background-color: var(--form-bg);
        border-color: var(--bms-peach);
        color: var(--text-color);
    }
    
    .payment-icon {
        font-size: 1.3rem;
        margin-right: 8px;
        color: var(--bms-red);
    }
    
    .promo-code-input {
        position: relative;
    }
    
    .total-amount {
        font-size: 1.2rem;
        font-weight: bold;
        color: var(--text-color);
    }
    
    .ticket-type-badge {
        font-size: 0.75rem;
        padding: 5px 8px;
        font-weight: 600;
        color: white;
    }
    
    .regular-badge { background-color: var(--bms-red); }
    .recliner-badge { background-color: var(--bms-orange); }
    .premium-badge { background-color: var(--bms-yellow); color: var(--bms-black); }
    .vip-badge { background-color: var(--bms-peach); color: var(--bms-black); }
    
    .card-icons img {
        height: 22px;
        margin-left: 5px;
        filter: grayscale(100%) brightness(var(--icon-brightness, 100%));
    }
    
    .light-mode .card-icons img {
        --icon-brightness: 70%;
    }
    
    .upi-apps {
        display: flex;
        gap: 8px;
        margin-top: 8px;
        flex-wrap: wrap;
    }
    
    .upi-apps img {
        height: 26px;
        cursor: pointer;
        filter: grayscale(100%) brightness(var(--icon-brightness, 100%));
        transition: filter 0.3s ease;
    }
    
    .upi-apps img:hover {
        filter: grayscale(0%) brightness(100%);
    }
    
    .form-control {
        padding: 12px 15px;
        border-radius: 6px;
        border: 1px solid var(--form-border);
        margin-bottom: 15px;
        background-color: var(--form-bg);
        color: var(--text-color);
        transition: all 0.3s ease;
    }
    
    .form-control::placeholder {
        color: var(--placeholder-color);
    }
    
    .form-control:focus {
        border-color: var(--bms-peach);
        box-shadow: 0 0 0 0.25rem rgba(230, 57, 70, 0.25);
        background-color: var(--form-bg);
        color: var(--text-color);
    }
    
    .btn-bms {
        background: var(--bms-gradient);
        border: none;
        padding: 12px;
        font-weight: 700;
        border-radius: 6px;
        width: 100%;
        margin-top: 12px;
        color: white;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
    }
    
    .btn-bms:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(230, 57, 70, 0.4);
        color: white;
        background: var(--bms-gradient-reverse);
    }
    
    .btn-outline-bms {
        border: 1px solid var(--bms-red);
        color: var(--bms-red);
        background: transparent;
        font-weight: 600;
        transition: all 0.3s ease;
        padding: 12px 15px;
        border-radius: 6px;
        height: 100%;
    }
    
    .btn-outline-bms:hover {
        background: var(--bms-red);
        color: white;
    }
    
    .input-group {
        align-items: stretch;
    }
    
    .input-group .form-control {
        margin-bottom: 0;
    }
    
    .input-group .btn {
        margin-top: 0;
    }
    
    .alert {
        border-radius: 6px;
        border: 1px solid transparent;
        padding: 10px 15px;
        margin-top: 10px;
    }
    
    .alert-success {
        background-color: var(--success-bg);
        border-color: rgba(25, 135, 84, 0.3);
        color: var(--text-color);
    }
    
    .alert-danger {
        background-color: var(--error-bg);
        border-color: rgba(220, 53, 69, 0.3);
        color: var(--text-color);
    }
    
    /* Text colors */
    h1, h2, h3, h4, h5, h6 {
        color: var(--text-color);
    }
    
    .text-muted {
        color: var(--text-muted) !important;
    }
    

    
    /* Animation */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .payment-container {
        animation: fadeIn 0.6s ease-out forwards;
    }
    
    /* Payment summary styles */
    .sticky-top {
        position: sticky;
        z-index: 10;
        top: 20px;
    }
    
    hr {
        border-color: var(--divider-color);
        margin: 15px 0;
    }
    
    /* Responsive adjustments */
    @media (max-width: 1199px) {
        .main-container {
            padding: 15px;
        }
    }
    
    @media (max-width: 991px) {
        .event-header, .card-header {
            padding: 12px;
        }
        
        .event-body, .card-body {
            padding: 15px;
        }
        
        .payment-methods .nav-link {
            padding: 10px;
            font-size: 0.9rem;
        }
        
        .payment-icon {
            font-size: 1.1rem;
        }
    }
    
    @media (max-width: 767px) {
        .main-container {
            padding: 10px;
        }
        
        .event-header, .card-header {
            padding: 10px;
            font-size: 1rem;
        }
        
        .event-body, .card-body {
            padding: 12px;
        }
        
        .form-control {
            padding: 10px 12px;
            font-size: 0.9rem;
        }
        
        .btn-bms, .btn-outline-bms {
            padding: 10px;
            font-size: 0.9rem;
        }
        
        
        
        .sticky-top {
            position: static;
        }
        
        .ticket-card {
            margin-bottom: 10px;
        }
    }
    
    @media (max-width: 575px) {
        .main-container {
            padding: 8px;
        }
        
        .event-header h2 {
            font-size: 1.2rem;
        }
        
        .card-header h5 {
            font-size: 1rem;
        }
        
        .total-amount {
            font-size: 1.1rem;
        }
        
        .upi-apps img {
            height: 22px;
        }
    }
        /* Loader styles */
    .payment-loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.8);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s, visibility 0.3s;
    }
    
    .payment-loader.active {
        opacity: 1;
        visibility: visible;
    }
    
    .loader-content {
        background: var(--container-bg);
        padding: 30px;
        border-radius: 10px;
        text-align: center;
        max-width: 400px;
        width: 90%;
    }
    
    .loader-spinner {
        border: 5px solid var(--form-bg);
        border-top: 5px solid var(--bms-red);
        border-radius: 50%;
        width: 50px;
        height: 50px;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }
    
    .loader-content p {
        color: var(--text-color);
        font-size: 1.1rem;
        margin-top: 15px;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Add these CSS fixes to your existing styles */

/* Fix for body theme toggle */
body[data-bs-theme="light"] {
    background-color: var(--bms-light);
    color: var(--text-color);
}

body[data-bs-theme="dark"] {
    background-color: var(--bg-color);
    color: var(--text-color);
}

/* Fix for search bar in light mode */
.light-mode .search-form .form-control {
    background-color: white;
    color: #333;
    border: 1px solid #ddd;
}

.light-mode .search-form .btn-danger {
    background: var(--gradient-primary);
    color: white;
}

/* Fix for search results in light mode */
.light-mode .search-results {
    background-color: white;
    border: 1px solid #ddd;
}

.light-mode .search-result-item {
    color: #333;
}

.light-mode .search-result-item:hover {
    background-color:rgb(255, 255, 255);
}

.light-mode .search-result-name {
    color: #333;
}

.light-mode .search-result-item:hover .search-result-name {
    color: var(--accent-orange);
}

.light-mode .search-result-type {
    color: var(--accent-orange);
}

/* Fix for dropdown menus in light mode */
.light-mode .dropdown-menu {
    background-color: white;
    border: 1px solid #ddd;
    color: #333;
}

.light-mode .dropdown-item {
    color: #333;
}

.light-mode .dropdown-item:hover {
    color: white;
}

/* Fix for city dropdown in light mode */
.light-mode .city-dropdown-menu {
    background-color: white;
}

.light-mode .city-search-container {
    background-color: white;
}

.light-mode .city-search-input {
    background-color: #f8f9fa;
    color: #333;
}

.light-mode .city-item:hover {
    background-color: #f8f9fa;
}

/* Fix for payment cards in light mode */
.light-mode .payment-card {
    background-color: white;
    border: 1px solid #ddd;
}

.light-mode .card-header {
    background-color: white;
    border-bottom: 1px solid #ddd;
    color: #333;
}

.light-mode .form-control {
    background-color: white;
    color: #333;
    border: 1px solid #ddd;
}

.light-mode .form-control:focus {
    background-color: white;
    color: #333;
}

/* Fix for ticket cards in light mode */
.light-mode .ticket-card {
    background-color: #f8f9fa;
}

.light-mode .ticket-card:hover {
    background-color: white;
}

/* Fix for divider colors in light mode */
.light-mode hr {
    border-color: #ddd;
}

promo-codes-dropdown {
        position: relative;
        display: inline-block;
        margin-left: 10px;
    }
    
    .promo-codes-btn {
        background: var(--bms-gradient-light);
        border: none;
        color: var(--bms-black);
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .promo-codes-btn:hover {
        background: var(--bms-gradient);
        color: white;
    }
    
    .promo-codes-content {
        display: none;
        position: absolute;
        background-color: var(--container-bg);
        min-width: 300px;
        box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        z-index: 1;
        border-radius: 8px;
        padding: 15px;
        right: 0;
        margin-top: 5px;
        border: 1px solid var(--form-border);
    }
    
    .promo-codes-content.show {
        display: block;
        animation: fadeIn 0.3s ease-out;
    }
    
    .promo-code-item {
        padding: 10px;
        margin-bottom: 8px;
        background-color: var(--form-bg);
        border-radius: 6px;
        border-left: 3px solid var(--bms-red);
    }
    
    .promo-code-item:last-child {
        margin-bottom: 0;
    }
    
    .promo-code-title {
        font-weight: 600;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .promo-code-desc {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-bottom: 8px;
    }
    
    .promo-code-value {
        font-size: 0.9rem;
        color: var(--bms-red);
        font-weight: 600;
    }
    
    .apply-promo-btn {
        background: var(--bms-gradient);
        border: none;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8rem;
        float: right;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .apply-promo-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(230, 57, 70, 0.3);
    }
    
    .promo-code-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    
    .close-promo-codes {
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: 1.2rem;
        cursor: pointer;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .promo-codes-content {
            min-width: 250px;
            left: 0;
            right: auto;
        }
    }

    .promo-codes-dropdown {
    position: relative;
    display: inline-block;
    margin-left: 10px;
    z-index: 1050; /* Higher than other elements */
}

.promo-codes-content {
    display: none;
    position: absolute;
    background-color: var(--container-bg);
    min-width: 300px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    z-index: 1060; /* Even higher than the dropdown container */
    border-radius: 8px;
    padding: 15px;
    right: 0;
    margin-top: 5px;
    border: 1px solid var(--form-border);
    /* Ensure it's not clipped by parent containers */
    overflow: visible;
}

/* Add this to prevent parent containers from clipping */
.card-body {
    overflow: visible !important;
}

.payment-card {
    overflow: visible !important;
}
    </style>


</head>
<body>
    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark top-navbar py-2 fixed-top">
        <div class="container">
            <div class="nav-content-wrapper">
                <!-- Logo/Brand -->
                <div class="brand-section">
                    <a class="navbar-brand" href="<?= $home_page ?>">
                        <img src="images/logo.png" alt="Catchify Logo" class="logo-img">
                        <b>Catchify</b>
                    </a>
                </div>

                <!-- Search Bar - Centered (only shown when logged in) -->
                <?php if ($isLoggedIn): ?>
                <div class="search-section">
                    <form class="search-form" method="GET" action="<?= $home_page ?>">
                        <div class="input-group">
                            <input class="form-control" type="search" name="search" id="searchInput" 
                                   placeholder="Search for movies, events, plays..." aria-label="Search"
                                   value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                            <button class="btn btn-danger" type="submit"><i class="fas fa-search"></i></button>
                        </div>
                        <div class="search-results" id="searchResults">
                            <!-- Search results will be populated here via JavaScript -->
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Right Section -->
                <div class="right-section">
                    <!-- Theme Toggle Button -->
                    <button class="theme-toggle" id="themeToggle">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <?php if ($isLoggedIn): ?>
                        <!-- Mobile Menu Dropdown Toggle -->
                        <div class="mobile-menu-dropdown">
                            <button class="mobile-menu-toggle dropdown-toggle" type="button" id="mobileMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-bars"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="mobileMenuDropdown">
                                <li><h6 class="dropdown-header">Menu</h6></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == $movies_page ? 'active' : '' ?>" href="<?= $movies_page ?>"><i class="fas fa-film"></i> Movies</a></li>
                                <li><a class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == $events_page ? 'active' : '' ?>" href="<?= $events_page ?>"><i class="fas fa-calendar-alt"></i> Events</a></li>
                                <li><a class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == $plays_page ? 'active' : '' ?>" href="<?= $plays_page ?>"><i class="fas fa-theater-masks"></i> Plays</a></li>
                                <li><a class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == $sports_page ? 'active' : '' ?>" href="<?= $sports_page ?>"><i class="fas fa-running"></i> Sports</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $list_your_show ?>"><i class="fa fa-plus-square"></i> List Your Show</a></li>
                                <li><a class="dropdown-item" href="<?= $view_promos ?>"><i class="fa fa-ticket"></i> Offers</a></li>
                            </ul>
                        </div>
                        
                        <!-- City Dropdown with Search -->
                        <div class="dropdown city-selector">
                            <button class="btn btn-outline-light dropdown-toggle" type="button" id="cityDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-map-marker-alt me-1"></i>
                                <?= !empty($_SESSION['selected_city']) ? htmlspecialchars(getCityNameById($cities, $_SESSION['selected_city'])) : 'Select City' ?>
                            </button>
                            <ul class="dropdown-menu city-dropdown-menu" aria-labelledby="cityDropdown">
                                <li class="city-search-container">
                                    <input type="text" class="city-search-input" placeholder="Search cities..." id="citySearch">
                                </li>
                                <form method="post" id="cityForm">
                                    <?php foreach ($cities as $city): ?>
                                        <li class="city-item" data-value="<?= $city['city_id'] ?>">
                                            <div class="dropdown-item">
                                                <?= htmlspecialchars($city['city_name']) ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                    <input type="hidden" name="city" id="selectedCity">
                                </form>
                            </ul>
                        </div>
                        
                        <!-- User Profile Dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-outline-light dropdown-toggle d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <?php if (isset($_SESSION['profile_pic']) && !empty($_SESSION['profile_pic'])): ?>
                                    <img src="<?= htmlspecialchars($_SESSION['profile_pic']) ?>" class="profile-pic" alt="Profile">
                                <?php else: ?>
                                    <i class="fas fa-user-circle me-1"></i>
                                <?php endif; ?>
                                <span class="welcome-text">Hi, <?= htmlspecialchars($username) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><h6 class="dropdown-header">Welcome, <?= htmlspecialchars($username) ?></h6></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $my_profile ?>"><i class="fas fa-user me-2"></i> My Profile</a></li>
                                <li><a class="dropdown-item" href="<?= $my_bookings ?>"><i class="fas fa-ticket-alt me-2"></i> My Bookings</a></li>
                                <li><a class="dropdown-item" href="<?= $my_reviews ?>"><i class="fas fa-heart me-2"></i> My Reviews</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $logout_page ?>"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    
    <?php if ($isLoggedIn): ?>
    <!-- Second Navigation Bar - Hidden on mobile -->
    <nav class="navbar navbar-expand-lg navbar-dark second-navbar py-1 fixed-top d-none d-lg-block">
        <div class="container">
            <div class="collapse navbar-collapse" id="secondNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == $movies_page ? 'active-nav' : '' ?>" href="<?= $movies_page ?>">
                            <i class="fas fa-film me-1"></i>
                            <span class="nav-text">Movies</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == $events_page ? 'active-nav' : '' ?>" href="<?= $events_page ?>">
                            <i class="fas fa-calendar-alt me-1"></i>
                            <span class="nav-text">Events</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == $plays_page ? 'active-nav' : '' ?>" href="<?= $plays_page ?>">
                            <i class="fas fa-theater-masks me-1"></i>
                            <span class="nav-text">Plays</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == $sports_page ? 'active-nav' : '' ?>" href="<?= $sports_page ?>">
                            <i class="fas fa-running me-1"></i>
                            <span class="nav-text">Sports</span>
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <a href="<?= $list_your_show  ?>" class="nav-link">
                        <i class="fa fa-plus-square me-1"></i>
                        <span class="nav-text">List Your Show</span>
                    </a>
                    <a href="<?= $view_promos  ?>" class="nav-link">
                        <i class="fa fa-ticket me-1"></i>
                        <span class="nav-text">Promo Codes</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="container py-4 payment-container" style="margin-top: 110px;">
        <div class="row">
            <div class="col-lg-8">
                <div class="event-card">
                    <div class="event-header">
                        <h2 class="mb-0"><?= htmlspecialchars($bookingData['event_name'] ?? 'Event') ?></h2>
                    </div>
                    <div class="event-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="mb-1"><i class="bi bi-calendar-event"></i> 
                                    <?= date('l, F j, Y', strtotime($bookingData['slot_time'] ?? 'now')) ?>
                                </p>
                                <p class="mb-1"><i class="bi bi-clock"></i> 
                                    <?= date('h:i A', strtotime($bookingData['slot_time'] ?? 'now')) ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><i class="bi bi-geo-alt"></i> 
                                    <?= htmlspecialchars($bookingData['venue_name'] ?? 'Venue') ?>
                                    <?php if (!empty($bookingData['sub_venue_name'])): ?>
                                        - <?= htmlspecialchars($bookingData['sub_venue_name']) ?>
                                    <?php endif; ?>
                                </p>
                                <p class="mb-1"><i class="bi bi-film"></i> 
                                    <?= htmlspecialchars($bookingData['genre'] ?? 'Genre') ?> • 
                                    <?= htmlspecialchars($bookingData['language'] ?? 'Language') ?> • 
                                    <?= htmlspecialchars($bookingData['duration'] ?? '0') ?> mins
                                </p>
                            </div>
                        </div>
                        
                        <h5 class="mb-3">Your Tickets</h5>
                        
                        <?php foreach ($selectedTicketsByType as $type => $tickets): ?>
                            <div class="card ticket-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge <?= strtolower($type) ?>-badge ticket-type-badge">
                                            <?= htmlspecialchars($type) ?>
                                        </span>
                                        <span class="text-muted small"><?= count($tickets) ?> ticket<?= count($tickets) > 1 ? 's' : '' ?></span>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-6">
                                            <p class="mb-1"><strong>Seats:</strong> 
                                                <?= implode(', ', array_column($tickets, 'seatId')) ?>
                                            </p>
                                            <p class="mb-1 small text-muted">
                                                <?= htmlspecialchars($tickets[0]['location']) ?>
                                            </p>
                                        </div>
                                        <div class="col-6 text-end">
                                            <p class="mb-1">₹<?= $tickets[0]['price'] ?> each</p>
                                            <p class="mb-0"><strong>₹<?= $tickets[0]['price'] * count($tickets) ?></strong></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="card payment-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Apply Promo Code</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <div class="input-group mb-3">
                                <input type="text" class="form-control" name="promo_code" placeholder="Enter promo code" value="<?= htmlspecialchars($promoCode) ?>" <?= $promoApplied ? 'readonly' : '' ?>>
                                <?php if ($promoApplied): ?>
                                    <button class="btn btn-outline-bms" type="submit" name="remove_promo">Remove</button>
                                <?php else: ?>
                                    <button class="btn btn-bms" type="submit" name="apply_promo">Apply</button>
                                <?php endif; ?>
                                
                                <?php if (!empty($availablePromoCodes)): ?>
                                <div class="promo-codes-dropdown">
                                    <button type="button" class="promo-codes-btn" id="promoCodesBtn">
                                        <i class="fas fa-tag me-1"></i> Available Codes
                                    </button>
                                    <div class="promo-codes-content" id="promoCodesContent">
                                        <div class="promo-code-header">
                                            <h6 class="mb-0">Available Promo Codes</h6>
                                            <button type="button" class="close-promo-codes" id="closePromoCodes">&times;</button>
                                        </div>
                                        <?php foreach ($availablePromoCodes as $promo): ?>
                                            <div class="promo-code-item">
                                                <div class="promo-code-title"><?= htmlspecialchars($promo['code']) ?></div>
                                                
                                                <div>
                                                    <span class="promo-code-value">₹<?= $promo['code_value'] ?> off</span>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="promo_code_id" value="<?= $promo['code_id'] ?>">
                                                        <button type="submit" name="apply_promo_from_list" class="apply-promo-btn">Apply</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($promoMessage)): ?>
                                <div class="alert alert-success"><?= $promoMessage ?></div>
                            <?php elseif (!empty($promoError)): ?>
                                <div class="alert alert-danger"><?= $promoError ?></div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <div class="card payment-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Select Payment Method</h5>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-pills flex-column payment-methods" id="paymentMethodsTab" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="credit-card-tab" data-bs-toggle="pill" href="#credit-card" role="tab">
                                    <i class="bi bi-credit-card payment-icon"></i> Credit/Debit Card
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="upi-tab" data-bs-toggle="pill" href="#upi" role="tab">
                                    <i class="bi bi-phone payment-icon"></i> UPI
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="netbanking-tab" data-bs-toggle="pill" href="#netbanking" role="tab">
                                    <i class="bi bi-bank payment-icon"></i> Net Banking
                                </a>
                            </li>
                        </ul>
                        
                        <div class="tab-content mt-3" id="paymentMethodsTabContent">
                            <div class="tab-pane fade show active" id="credit-card" role="tabpanel">
                                <form method="post" id="cardPaymentForm">
                                    <input type="hidden" name="payment_method" value="credit_card">
                                    <div class="mb-3">
                                        <label for="cardNumber" class="form-label">Card Number</label>
                                        <input type="text" class="form-control" id="cardNumber" placeholder="1234 5678 9012 3456" required>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="cardName" class="form-label">Name on Card</label>
                                            <input type="text" class="form-control" id="cardName" placeholder="John Doe" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="cardExpiry" class="form-label">Expiry</label>
                                            <input type="text" class="form-control" id="cardExpiry" placeholder="MM/YY" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="cardCvv" class="form-label">CVV</label>
                                            <input type="text" class="form-control" id="cardCvv" placeholder="123" required>
                                        </div>
                                    </div>
                                    <div class="mb-3 card-icons">
                                        <small class="text-muted">We accept:</small>
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/5e/Visa_Inc._logo.svg/2560px-Visa_Inc._logo.svg.png" alt="Visa">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/2/2a/Mastercard-logo.svg/1280px-Mastercard-logo.svg.png" alt="Mastercard">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/f/fa/American_Express_logo_%282018%29.svg/1200px-American_Express_logo_%282018%29.svg.png" alt="Amex">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/9d/RuPay.svg/1200px-RuPay.svg.png" alt="RuPay">
                                    </div>
                                    <button type="submit" name="process_payment" class="btn btn-bms">Pay ₹<?= $grandTotal ?></button>
                                </form>
                            </div>
                            
                            <div class="tab-pane fade" id="upi" role="tabpanel">
                                <form method="post" id="upiPaymentForm">
                                    <input type="hidden" name="payment_method" value="upi">
                                    <div class="mb-3">
                                        <label for="upiId" class="form-label">UPI ID</label>
                                        <input type="text" class="form-control" id="upiId" placeholder="yourname@upi" required>
                                    </div>
                                    <div class="mb-3">
                                        <small class="text-muted">Or pay using any UPI app</small>
                                        <div class="upi-apps">
                                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/e/e1/Google_Pay_%28GPay%29_Logo.svg/512px-Google_Pay_%28GPay%29_Logo.svg.png" alt="Google Pay" onclick="document.getElementById('upiId').value = 'yourname@okhdfcbank'">
                                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/9f/PhonePe_Logo.svg/2560px-PhonePe_Logo.svg.png" alt="PhonePe" onclick="document.getElementById('upiId').value = 'yourname@ybl'">
                                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/7/73/Paytm_Logo_%28standalone%29.svg/1200px-Paytm_Logo_%28standalone%29.svg.png" alt="Paytm" onclick="document.getElementById('upiId').value = 'yourname@paytm'">
                                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b5/BHIM_Logo.svg/1200px-BHIM_Logo.svg.png" alt="BHIM" onclick="document.getElementById('upiId').value = 'yourname@upi'">
                                        </div>
                                    </div>
                                    <button type="submit" name="process_payment" class="btn btn-bms">Pay ₹<?= $grandTotal ?></button>
                                </form>
                            </div>
                            
                            <div class="tab-pane fade" id="netbanking" role="tabpanel">
                                <form method="post" id="netbankingPaymentForm">
                                    <input type="hidden" name="payment_method" value="netbanking">
                                    <div class="mb-3">
                                        <label for="bankSelect" class="form-label">Select Bank</label>
                                        <select class="form-select" id="bankSelect" required>
                                            <option value="">Select your bank</option>
                                            <option value="sbi">State Bank of India</option>
                                            <option value="hdfc">HDFC Bank</option>
                                            <option value="icici">ICICI Bank</option>
                                            <option value="axis">Axis Bank</option>
                                            <option value="kotak">Kotak Mahindra Bank</option>
                                            <option value="pnb">Punjab National Bank</option>
                                            <option value="bob">Bank of Baroda</option>
                                            <option value="yes">Yes Bank</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="process_payment" class="btn btn-bms">Pay ₹<?= $grandTotal ?></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card payment-card sticky-top" style="top: 20px;">
                    <div class="card-header">
                        <h5 class="mb-0">Payment Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Tickets Price:</span>
                            <span>₹<?= $totalAmount ?></span>
                        </div>
                        <?php if ($promoApplied): ?>
                            <div class="d-flex justify-content-between mb-2 text-success">
                                <span>Promo Discount (<?= htmlspecialchars($promoCode) ?>):</span>
                                <span>-₹<?= $promoDiscount ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Convenience Fee:</span>
                            <span>₹<?= $convenienceFee ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span>GST (18%):</span>
                            <span>₹<?= $gst ?></span>
                        </div>
                        <hr style="border-color: var(--divider-color);">
                        <div class="d-flex justify-content-between total-amount">
                            <span>Total Amount:</span>
                            <span>₹<?= $grandTotal ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loader HTML -->
    <div class="payment-loader" id="paymentLoader">
        <div class="loader-content">
            <div class="loader-spinner"></div>
            <p>Processing your payment...</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Theme toggle functionality
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const htmlElement = document.documentElement;
        
        const savedTheme = localStorage.getItem('theme');
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        
        if (savedTheme) {
            htmlElement.setAttribute('data-bs-theme', savedTheme);
            updateThemeIcon(savedTheme);
        } else if (systemPrefersDark) {
            htmlElement.setAttribute('data-bs-theme', 'dark');
            updateThemeIcon('dark');
        }
        
        themeToggle.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            htmlElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeIcon(newTheme);
        });
        
        function updateThemeIcon(theme) {
            if (theme === 'dark') {
                document.body.classList.remove('light-mode');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                document.body.classList.add('light-mode');
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
        }
        
        // City search functionality
        const citySearch = document.getElementById('citySearch');
        const cityItems = document.querySelectorAll('.city-item');
        
        if (citySearch) {
            citySearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                
                cityItems.forEach(item => {
                    const cityName = item.textContent.toLowerCase();
                    item.style.display = cityName.includes(searchTerm) ? 'block' : 'none';
                });
            });
        }
        
        // City selection
        cityItems.forEach(item => {
            item.addEventListener('click', function() {
                const cityId = this.getAttribute('data-value');
                document.getElementById('selectedCity').value = cityId;
                document.getElementById('cityForm').submit();
            });
        });

        // Search functionality with AJAX
        const searchInput = document.getElementById('searchInput');
        const searchResults = document.getElementById('searchResults');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.trim();
                
                if (searchTerm.length >= 2) {
                    fetchSearchResults(searchTerm);
                } else {
                    searchResults.style.display = 'none';
                    searchResults.innerHTML = '';
                }
            });
            
            // Hide results when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.style.display = 'none';
                }
            });
        }
        
        function fetchSearchResults(searchTerm) {
            fetch(`<?=$search?>?search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        let html = '';
                        data.forEach(item => {
                            html += `
                                <a href="<?=$event_info_page?>?id=${item.event_id}" class="search-result-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="search-result-name">${item.event_name}</div>
                                        <div class="search-result-type">${item.event_type}</div>
                                    </div>
                                </a>
                            `;
                        });
                        searchResults.innerHTML = html;
                        searchResults.style.display = 'block';
                    } else {
                        searchResults.innerHTML = '<div class="search-result-item">No results found</div>';
                        searchResults.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error fetching search results:', error);
                    searchResults.innerHTML = '<div class="search-result-item">Error loading results</div>';
                    searchResults.style.display = 'block';
                });
        }

        // Promo codes dropdown functionality
        const promoCodesBtn = document.getElementById('promoCodesBtn');
        const promoCodesContent = document.getElementById('promoCodesContent');
        const closePromoCodes = document.getElementById('closePromoCodes');
        
        if (promoCodesBtn && promoCodesContent) {
            promoCodesBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                promoCodesContent.classList.toggle('show');
            });
            
            if (closePromoCodes) {
                closePromoCodes.addEventListener('click', function() {
                    promoCodesContent.classList.remove('show');
                });
            }
            
            // Close when clicking outside
            document.addEventListener('click', function(e) {
                if (!promoCodesContent.contains(e.target) && e.target !== promoCodesBtn) {
                    promoCodesContent.classList.remove('show');
                }
            });
        }

        // Format card number input
        document.getElementById('cardNumber').addEventListener('input', function(e) {
            this.value = this.value.replace(/\s/g, '').replace(/(\d{4})/g, '$1 ').trim();
        });

        // Format expiry date input
        document.getElementById('cardExpiry').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '').replace(/(\d{2})(\d{0,2})/, '$1/$2');
        });

        // Payment loader functionality
        const forms = [
            document.getElementById('cardPaymentForm'),
            document.getElementById('upiPaymentForm'),
            document.getElementById('netbankingPaymentForm')
        ];
        
        const loader = document.getElementById('paymentLoader');
        
        forms.forEach(form => {
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (this.checkValidity()) {
                        loader.classList.add('active');
                    }
                });
            }
        });
    });
</script>

</body>
</html>