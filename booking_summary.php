<?php
session_start();

date_default_timezone_set('Asia/Kolkata');

require_once 'config/db_config.php';
require_once 'links.php';
require_once 'phpqrcode/qrlib.php'; // Include QR Code library

// Redirect to login if not authenticated (except for auth pages)
$auth_pages = [$login_page, $register_page, $forgot_pass];

if (!isset($_SESSION['user_id'])) {
    if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// Initialize selected city if not set (only for logged-in users)
if (isset($_SESSION['user_id'])) {
    $_SESSION['selected_city'] = $_SESSION['selected_city'] ?? '';
    
    // Handle city selection (auto-submit)
    if (isset($_POST['city'])) {
        $_SESSION['selected_city'] = $_POST['city'];
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
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

// Database configuration
$host = 'localhost';
$dbname = 'event_mg';
$dbusername = 'root';
$password = '';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? $_SESSION['user_id'] : null;
$username = $isLoggedIn ? $_SESSION['username'] : 'Guest';

// Connect to database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbusername, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'submit_rating':
                handleSubmitRating($pdo, $userId);
                break;
            case 'update_rating':
                handleUpdateRating($pdo, $userId);
                break;
            case 'delete_rating':
                handleDeleteRating($pdo, $userId);
                break;
            case 'send_email':
                handleSendEmail($pdo, $userId);
                break;
        }
    }
    // Redirect to prevent form resubmission
    header("Location: ".$_SERVER['REQUEST_URI']);
    exit();
}

// Function to handle rating submission
function handleSubmitRating($pdo, $userId) {
    if (!$userId || !isset($_POST['event_id']) || !isset($_POST['rating']) || !isset($_POST['rating_desc'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid rating data'];
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO event_ratings (
                event_id, user_gave, max_rating, rating_desc, hastag, created_by, created_on, is_active
            ) VALUES (?, ?, 5, ?, ?, ?, NOW(), 'yes')
        ");
        $stmt->execute([
            $_POST['event_id'],
            $_POST['rating'],
            $_POST['rating_desc'],
            $_POST['hashtag'] ?? null,
            $userId
        ]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Rating submitted successfully!'];
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to submit rating: ' . $e->getMessage()];
    }
}

// Function to handle rating update
function handleUpdateRating($pdo, $userId) {
    if (!$userId || !isset($_POST['rating_id']) || !isset($_POST['rating']) || !isset($_POST['rating_desc'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid rating data'];
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE event_ratings 
            SET user_gave = ?, rating_desc = ?, hastag = ?, created_on = NOW()
            WHERE rating_id = ? AND created_by = ?
        ");
        $stmt->execute([
            $_POST['rating'],
            $_POST['rating_desc'],
            $_POST['hashtag'] ?? null,
            $_POST['rating_id'],
            $userId
        ]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Rating updated successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Rating not found or you dont have permission'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update rating: ' . $e->getMessage()];
    }
}

// Function to handle rating deletion
function handleDeleteRating($pdo, $userId) {
    if (!$userId || !isset($_POST['rating_id'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid rating data'];
        return;
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM event_ratings 
            WHERE rating_id = ? AND created_by = ?
        ");
        $stmt->execute([$_POST['rating_id'], $userId]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Rating deleted successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Rating not found or you dont have permission'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to delete rating: ' . $e->getMessage()];
    }
}

// Function to handle email sending
function handleSendEmail($pdo, $userId) {
    if (!$userId || !isset($_POST['booking_ref'])) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid booking data'];
        return;
    }

    try {
        // Get booking details
        $bookingDetails = getBookingDetails($pdo, $_POST['booking_ref'], $userId);
        if (empty($bookingDetails)) {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Booking not found'];
            return;
        }

        // Generate QR code
        $qrData = http_build_query([
            'booking_ref' => $bookingDetails['summary']['booking_ref'],
            'event_id' => $bookingDetails['summary']['event_id'],
            'venue_id' => $bookingDetails['summary']['venue_id'],
            'schedule_id' => $bookingDetails['summary']['venue_schedule_id']
        ]);

        $qrCodeDir = 'temp/qrcodes/';
        if (!file_exists($qrCodeDir)) {
            mkdir($qrCodeDir, 0755, true);
        }

        $sanitizedRef = preg_replace('/[^a-zA-Z0-9_-]/', '', $bookingDetails['summary']['booking_ref']);
        $qrCodeFilename = 'qr_' . $sanitizedRef . '.png';
        $qrCodePath = $qrCodeDir . $qrCodeFilename;
        
        QRcode::png($qrData, $qrCodePath, QR_ECLEVEL_L, 10, 2);

        // Load PHPMailer
        require_once __DIR__ . '/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/src/SMTP.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'catchifyevents@gmail.com';
        $mail->Password = 'evem orsj qviu nphz';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Sender and recipient
        $mail->setFrom('catchifyevents@gmail.com', 'Your Booking System');
        $mail->addAddress($_SESSION['email']);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Booking Confirmation: ' . htmlspecialchars($bookingDetails['summary']['event_name']);
        
        // Add the QR code as an embedded image
        $qrCID = 'qr-code-' . $bookingDetails['summary']['booking_ref'];
        $mail->addEmbeddedImage($qrCodePath, $qrCID, 'ticket_qr_code.png');
        
        // Build email body
        $emailBody = generateEmailBody($bookingDetails, $qrCID);
        $mail->Body = $emailBody;
        
        // Also attach the QR code as a separate file
        $mail->addAttachment($qrCodePath, 'ticket_qr_code.png');
        
        // Send email
        if ($mail->send()) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Email sent successfully!'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to send email: ' . $mail->ErrorInfo];
        }
        
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Error sending email: ' . $e->getMessage()];
    }
}

// Function to generate email body
function generateEmailBody($bookingDetails, $qrCID) {
    $eventDate = date('l, F j, Y', strtotime($bookingDetails['summary']['slot_starts_at']));
    $eventTime = date('h:i A', strtotime($bookingDetails['summary']['slot_starts_at']));
    
    // Group tickets by type
    $groupedTickets = [];
    foreach ($bookingDetails['tickets'] as $ticket) {
        $type = $ticket['ticket_type'];
        if (!isset($groupedTickets[$type])) {
            $groupedTickets[$type] = ['tickets' => [], 'locations' => []];
        }
        $groupedTickets[$type]['tickets'][] = $ticket;
        if (!empty($ticket['ticket_location'])) {
            $groupedTickets[$type]['locations'][] = $ticket['ticket_location'];
        }
    }
    
    $emailBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
            }
            .email-container {
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            .email-header {
                background: linear-gradient(135deg, #e63946 0%, #ff7e33 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }
            .email-header h1 {
                margin: 0;
                font-size: 28px;
                font-weight: 700;
            }
            .email-body {
                padding: 30px;
                background: #ffffff;
            }
            .event-title {
                color: #e63946;
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 20px;
            }
            .booking-id {
                font-size: 18px;
                font-weight: bold;
                color: #e63946;
                margin-bottom: 20px;
                background: rgba(230, 57, 70, 0.1);
                padding: 10px 15px;
                border-radius: 6px;
                display: inline-block;
            }
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-bottom: 25px;
            }
            .info-item {
                margin-bottom: 8px;
            }
            .info-item i {
                color: #e63946;
                width: 20px;
                text-align: center;
                margin-right: 8px;
            }
            .ticket-card {
                border-left: 4px solid #e63946;
                margin-bottom: 15px;
                background: #f9f9f9;
                border-radius: 6px;
                padding: 15px;
            }
            .ticket-type {
                background: linear-gradient(135deg, #e63946 0%, #ff7e33 100%);
                color: white;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 14px;
                display: inline-block;
                margin-bottom: 10px;
            }
            .qr-container {
                text-align: center;
                margin: 25px 0;
                padding: 15px;
                border: 1px solid #eee;
                border-radius: 8px;
            }
            .qr-container img {
                max-width: 200px;
                height: auto;
            }
            .payment-summary {
                background: #f9f9f9;
                padding: 20px;
                border-radius: 8px;
                margin-top: 20px;
            }
            .divider {
                height: 1px;
                background: #eee;
                margin: 20px 0;
            }
            .footer {
                text-align: center;
                padding: 20px;
                color: #777;
                font-size: 14px;
            }
            .text-primary {
                color: #e63946 !important;
            }
            .qr-card {
                background: white;
                border-radius: 10px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                border-top: 4px solid #e63946;
                padding: 20px;
                max-width: 300px;
                margin: 20px auto;
                text-align: center;
            }
            .qr-card-header {
                margin-bottom: 15px;
            }
            .qr-card-header h4 {
                color: #e63946;
                margin: 0 0 5px 0;
                font-size: 18px;
            }
            .qr-card-header p {
                color: #666;
                margin: 0;
                font-size: 14px;
            }
            .qr-code-wrapper {
                background: white;
                padding: 10px;
                border-radius: 8px;
                display: inline-block;
                border: 1px solid #eee;
            }
            .qr-card-footer {
                margin-top: 15px;
                font-size: 13px;
                color: #666;
            }
            .qr-ref {
                font-weight: bold;
                color: #e63946;
                word-break: break-all;
            }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="email-header">
                <h1><i class="fas fa-check-circle"></i> Booking Confirmed!</h1>
                <p>Your tickets for ' . htmlspecialchars($bookingDetails['summary']['event_name']) . '</p>
            </div>
            
            <div class="email-body">
                <h2 class="event-title">' . htmlspecialchars($bookingDetails['summary']['event_name']) . '</h2>
                
                <div class="booking-id">Booking Reference: ' . $bookingDetails['summary']['booking_ref'] . '</div>
                
                <div class="info-grid">
                    <div>
                        <div class="info-item"><i class="fas fa-calendar-day"></i> <strong>Date:</strong> ' . $eventDate . '</div>
                        <div class="info-item"><i class="fas fa-clock"></i> <strong>Time:</strong> ' . $eventTime . '</div>
                    </div>
                    <div>
                        <div class="info-item"><i class="fas fa-map-marker-alt"></i> <strong>Venue:</strong> ' . htmlspecialchars($bookingDetails['summary']['venue_name']) . '</div>
                        <div class="info-item"><i class="fas fa-map-marker-alt"></i> <strong>Sub Venue:</strong> ' . htmlspecialchars($bookingDetails['summary']['sub_venue_name']) . '</div>
                    </div>
                </div>
                
                <h3><i class="fas fa-ticket-alt text-primary"></i> Your Tickets</h3>';
    
    // Add tickets section
    foreach ($groupedTickets as $type => $data) {
        $emailBody .= '
        <div class="ticket-card">
            <span class="ticket-type">' . htmlspecialchars($type) . ' (' . count($data['tickets']) . ')</span>
            <div><i class="fas fa-chair"></i> Seats: ' . htmlspecialchars(implode(', ', array_unique($data['locations']))) . '</div>
        </div>';
    }
    
    // Add QR code section with embedded image
    $emailBody .= '
                <div class="qr-card">
                    <div class="qr-card-header">
                        <h4><i class="fas fa-qrcode"></i> ENTRY PASS</h4>
                        <p>Present this at the venue</p>
                    </div>
                    <div class="qr-code-wrapper">
                        <img src="cid:' . $qrCID . '" alt="QR Code" style="width: 180px; height: 180px;">
                    </div>
                    <div class="qr-card-footer">
                        <div><strong>Event:</strong> ' . htmlspecialchars(substr($bookingDetails['summary']['event_name'], 0, 30)) . '</div>
                        <div><strong>Date:</strong> ' . date('M j, Y', strtotime($bookingDetails['summary']['slot_starts_at'])) . '</div>
                        <div><strong>Time:</strong> ' . date('g:i A', strtotime($bookingDetails['summary']['slot_starts_at'])) . '</div>
                        <div style="margin-top: 8px;"><span class="qr-ref">Ref: ' . $bookingDetails['summary']['booking_ref'] . '</span></div>
                    </div>
                </div>
                <div class="payment-summary">
                    <h3><i class="fas fa-receipt text-primary"></i> Payment Summary</h3>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>Tickets:</span>
                        <span>₹' . number_format($bookingDetails['summary']['total_booking_amt'], 2) . '</span>
                    </div>';
    
    if ($bookingDetails['summary']['promo_code']) {
        $emailBody .= '
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: #28a745;">
                        <span>Discount:</span>
                        <span>-₹' . number_format($bookingDetails['summary']['total_booking_amt'] - $bookingDetails['summary']['grand_total_amt'], 2) . '</span>
                    </div>';
    }
    
    $emailBody .= '
                    <div class="divider"></div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold;">
                        <span>Total Paid:</span>
                        <span>₹' . number_format($bookingDetails['summary']['grand_total_amt'], 2) . '</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold;">
                        <span>Paid Via</span>
                        <span>: ' . htmlspecialchars(substr($bookingDetails['summary']['payment_method'], 0, 30)) . '</span>
                    </div>
                </div>
                <div class="divider"></div>
                <p>Thank you for your booking! Present this email or the QR code at the venue for entry.</p>
                <p>If you have any questions, please contact our support team.</p>
            </div>
            
            <div class="footer">
                <p>© ' . date('Y') . ' Your Booking System. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return $emailBody;
}

// Function to fetch booking details
function getBookingDetails($pdo, $bookingRef, $userId) {
    try {
        // Get summary info
        $summaryStmt = $pdo->prepare("
            SELECT 
                b.booking_ref,
                b.event_id,
                b.total_booking_amt,
                b.grand_total_amt,
                b.payment_method,
                b.checked_in,
                b.booked_at,
                e.event_name,
                e.event_type,
                v.venue_name,
                v.sub_venue_name,
                c.city_name AS city,
                c.state_name AS state,
                c.country AS country,
                vs.slot_starts_at,
                vs.venue_schedule_id,
                pc.code AS promo_code,
                b.ticket_count
            FROM (
                SELECT 
                    booking_ref,
                    event_id,
                    SUM(booking_amt) AS total_booking_amt,
                    SUM(total_amt) AS grand_total_amt,
                    MAX(payment_method) AS payment_method,
                    MAX(checked_in) AS checked_in,
                    MAX(booked_at) AS booked_at,
                    MAX(code_id) AS code_id,
                    COUNT(*) AS ticket_count
                FROM bookings
                WHERE booking_ref = ? AND booked_by = ?
                GROUP BY booking_ref, event_id
            ) b
            JOIN event_info e ON b.event_id = e.event_id
            JOIN event_schedule es ON e.event_id = es.event_id
            JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
            JOIN venues v ON vs.venue_id = v.venue_id
            JOIN cities c ON v.city_id = c.city_id
            LEFT JOIN promo_codes pc ON b.code_id = pc.code_id
            ORDER BY b.booked_at DESC;
        ");
        $summaryStmt->execute([$bookingRef, $userId]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$summary) {
            return [];
        }
        
        // Get all tickets for this booking
        $ticketsStmt = $pdo->prepare("
            SELECT 
                b.*,
                est.ticket_id,
                est.ticket_type,
                est.ticket_location,
                est.ticket_row,
                est.ticket_column,
                est.ticket_price
            FROM 
                bookings b
            LEFT JOIN event_schedule_tickets est ON b.ticket_id = est.ticket_id
            WHERE b.booking_ref = ? AND b.booked_by = ?
            ORDER BY est.ticket_type, est.ticket_id
        ");
        $ticketsStmt->execute([$bookingRef, $userId]);
        $tickets = $ticketsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'summary' => $summary,
            'tickets' => $tickets
        ];
    } catch (PDOException $e) {
        return [];
    }
}

// Function to fetch user ratings with pagination
function getUserRatings($pdo, $userId, $eventId = null, $limit = 10, $offset = 0) {
    try {
        $sql = "
            SELECT 
                r.*,
                e.event_name,
                e.event_id
            FROM 
                event_ratings r
            JOIN event_info e ON r.event_id = e.event_id
            WHERE r.created_by = :userId
        ";
        
        if ($eventId) {
            $sql .= " AND r.event_id = :eventId";
        }
        
        $sql .= " ORDER BY r.created_on DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        
        // Bind parameters
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        if ($eventId) {
            $stmt->bindValue(':eventId', $eventId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Function to count user ratings
function countUserRatings($pdo, $userId, $eventId = null) {
    try {
        $sql = "SELECT COUNT(*) FROM event_ratings WHERE created_by = ?";
        $params = [$userId];
        
        if ($eventId) {
            $sql .= " AND event_id = ?";
            $params[] = $eventId;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// Fetch data for the current user
$viewingBookingRef = $_GET['booking_ref'] ?? null;
$bookingDetails = [];
$eventId = null;
$existingRatings = [];
$totalRatings = 0;
$ratingsLimit = 10;
$ratingsOffset = isset($_GET['ratings_offset']) ? (int)$_GET['ratings_offset'] : 0;

if ($viewingBookingRef && $isLoggedIn) {
    $bookingDetails = getBookingDetails($pdo, $viewingBookingRef, $userId);
    $eventId = $bookingDetails['summary']['event_id'] ?? null;
    
    if ($eventId) {
        $existingRatings = getUserRatings($pdo, $userId, $eventId, $ratingsLimit, $ratingsOffset);
        $totalRatings = countUserRatings($pdo, $userId, $eventId);
    }
    
    // Generate QR code if booking exists
    if (!empty($bookingDetails)) {
        $qrData = http_build_query([
            'booking_ref' => $bookingDetails['summary']['booking_ref'],
            'event_id' => $bookingDetails['summary']['event_id'],
            'venue_id' => $bookingDetails['summary']['venue_id'],
            'schedule_id' => $bookingDetails['summary']['venue_schedule_id']
        ]);
        
        $qrCodeDir = 'temp/qrcodes/';
        if (!file_exists($qrCodeDir)) {
            mkdir($qrCodeDir, 0755, true);
        }
        
        $sanitizedRef = preg_replace('/[^a-zA-Z0-9_-]/', '', $bookingDetails['summary']['booking_ref']);
        $qrCodeFilename = 'qr_' . $sanitizedRef . '.png';
        $qrCodePath = $qrCodeDir . $qrCodeFilename;
        
        QRcode::png($qrData, $qrCodePath, QR_ECLEVEL_L, 10, 2);
    }
}

// Check if editing a review
$editingReview = isset($_GET['edit_review']);
$reviewToEdit = null;
if ($editingReview && isset($_GET['rating_id']) && $isLoggedIn) {
    $reviewToEdit = getRating($pdo, $_GET['rating_id'], $userId);
    if (!$reviewToEdit) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Review not found or you don\'t have permission to edit it'];
        header("Location: $edit_review");
        exit();
    }
}

// Function to fetch a single rating
function getRating($pdo, $ratingId, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                e.event_name
            FROM 
                event_ratings r
            JOIN event_info e ON r.event_id = e.event_id
            WHERE r.rating_id = ? AND r.created_by = ?
        ");
        $stmt->execute([$ratingId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}
?>




<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Summary - Catchify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
:root {
    --primary-bg: #ffffff;
    --secondary-bg: #f8f9fa;
    --text-color: #141414;
    --accent-red: #e50914;
    --accent-orange: #ff6b35;
    --accent-yellow: #ffc107;
    --accent-peach: #ff9e7d;
    --accent-black: #141414;
    --card-bg: #ffffff;
    --nav-dark: #141414;
    --nav-text: #ffffff;
    --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
    --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
    --border-color: rgba(0,0,0,0.1);
}

[data-bs-theme="dark"] {
    --primary-bg: #121212;
    --secondary-bg: #1e1e1e;
    --text-color: #f8f9fa;
    --accent-red: #e50914;
    --accent-orange: #ff6b35;
    --accent-yellow: #ffc107;
    --accent-peach: #ff9e7d;
    --accent-black: #f8f9fa;
    --card-bg: #1e1e1e;
    --nav-dark: #000000;
    --nav-text: #ffffff;
    --border-color: rgba(255,255,255,0.1);
}

body {
    padding-top: 56px;
    background-color: var(--primary-bg);
    color: var(--text-color);
    transition: all 0.3s ease;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* Navbar styles */
.top-navbar {
    background-color: var(--nav-dark) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1030;
    height: 56px;
}

.second-navbar {
    background-color: var(--nav-dark) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    position: fixed;
    top: 56px;
    left: 0;
    right: 0;
    z-index: 1020;
    white-space: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    height: 54px;
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

.profile-pic {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 8px;
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

.dropdown-menu {
    z-index: 1050;
    background-color: var(--nav-dark);
    border: 1px solid var(--border-color);
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
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

.btn-outline-light:hover {
    color: var(--nav-dark);
    background-color: var(--nav-text);
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
    border: 1px solid var(--border-color);
    border-radius: 0 0 8px 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    z-index: 1000;
    max-height: 400px;
    overflow-y: auto;
    display: none;
}

.search-result-item {
    padding: 10px 15px;
    border-bottom: 1px solid var(--border-color);
    transition: all 0.2s;
    color: var(--text-color);
    text-decoration: none;
    display: block;
}

.search-result-item:hover {
    background-color: var(--secondary-bg);
    transform: translateX(5px);
}

.search-result-type {
    font-size: 0.8rem;
    color: var(--accent-orange);
    text-transform: capitalize;
    font-weight: 500;
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

.booking-details-container, .review-container {
    background-color: var(--card-bg);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.booking-details-container:hover, .review-container:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border-color: var(--accent-red);
}

.section-title {
    font-size: 1.2rem;
    color: var(--accent-orange);
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--border-color);
}

.detail-item {
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-color);
}

.detail-label {
    font-weight: 600;
    color: var(--text-color);
    flex: 1;
}

.detail-value {
    color: var(--text-color);
    flex: 2;
    text-align: right;
}

.ticket-type-header {
    background-color: var(--secondary-bg);
    padding: 10px 15px;
    margin: 20px 0 10px;
    border-radius: 5px;
    font-weight: bold;
    color: var(--text-color);
}

.ticket-table {
    margin-bottom: 20px;
    width: 100%;
}

.ticket-table th {
    background-color: var(--secondary-bg);
    font-weight: 600;
    color: var(--text-color);
}

.ticket-table td {
    background-color: var(--card-bg);
    color: var(--text-color);
}

.badge-checked-in {
    font-size: 0.9rem;
    padding: 5px 10px;
    background: linear-gradient(135deg, var(--accent-yellow) 0%, var(--accent-peach) 100%);
    color: var(--accent-black);
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.rating-stars {
    color: var(--accent-orange);
    font-size: 24px;
    cursor: pointer;
    margin-bottom: 10px;
}

.star-filled {
    color: var(--accent-orange);
}

.star-empty {
    color: #ddd;
}

.review-card {
    transition: all 0.3s ease;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    height: 100%;
    background-color: var(--card-bg);
}

.review-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.card-body {
    padding: 20px;
}

.btn-gradient-primary {
    background: var(--gradient-primary);
    border: none;
    color: white;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
}

.btn-gradient-primary:hover {
    background: var(--gradient-primary);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(229, 9, 20, 0.4);
    color: white;
}

.btn-gradient-secondary {
    background: var(--gradient-secondary);
    border: none;
    color: white;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(255, 107, 53, 0.3);
}

.btn-gradient-secondary:hover {
    background: var(--gradient-secondary);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(255, 107, 53, 0.4);
    color: white;
}

.city-selector .dropdown-menu {
    max-height: 300px;
    overflow-y: auto;
}

.city-search-container {
    padding: 8px 12px;
}

.city-search-input {
    width: 100%;
    padding: 4px 8px;
    border-radius: 4px;
    border: 1px solid var(--border-color);
    background-color: var(--secondary-bg);
    color: var(--text-color);
}

.city-item {
    cursor: pointer;
}

.city-item:hover {
    background-color: var(--secondary-bg);
}

.qr-code-container {
    text-align: center;
    padding: 20px;
    background-color: var(--card-bg);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.qr-code-container img {
    max-width: 100%;
    height: auto;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    body {
        padding-top: 56px;
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
    
    .detail-item {
        flex-direction: column;
    }
    
    .detail-value {
        text-align: left;
        margin-top: 5px;
    }
    
    .col-md-4.text-md-end {
        text-align: left !important;
        margin-top: 15px;
    }
    
    .city-selector {
        min-width: 120px;
    }
}

@media (max-width: 768px) {
    body {
        padding-top: 56px;
    }
    
    .booking-details-container, .review-container {
        padding: 15px;
    }
    
    .section-title {
        font-size: 1.1rem;
    }
    
    .ticket-table {
        font-size: 0.9rem;
    }
}

@media (max-width: 576px) {
    body {
        padding-top: 56px;
    }
    
    .booking-details-container, .review-container {
        padding: 12px;
    }
    
    .detail-item {
        font-size: 0.9rem;
    }
    
    .rating-stars {
        font-size: 20px;
    }
}

.fade-out {
    animation: fadeOut 1s ease-out forwards;
}

@keyframes fadeOut {
    0% { opacity: 1; }
    100% { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }
}

@media (max-width: 992px) {
    .top-navbar {
        height: auto;
        padding-bottom: 0;
    }
    
    .second-navbar {
        background-color: var(--nav-dark) !important;
        position: relative;
        top: 0;
        display: block !important;
        height: auto;
        overflow: visible;
    }
    
    .second-navbar .navbar-nav {
        display: flex !important;
        flex-wrap: wrap;
        padding: 0.5rem 0;
    }
    
    .second-navbar .nav-item {
        flex: 1 0 auto;
        text-align: center;
    }
    .second-navbar{
        display: none;
    }
    
    body {
        padding-top: 70px;
    }
    
    .container.py-5.mt-5 {
        margin-top: 1rem !important;
        padding-top: 1rem;
    }
}

@media (max-width: 768px) {
    .top-navbar {
        height: auto;
        padding-bottom: 0;
    }
    
    .second-navbar {
        background-color: var(--nav-dark) !important;
        position: relative;
        top: 0;
        display: block !important;
        height: auto;
        overflow: visible;
    }
    
    .second-navbar .navbar-nav {
        display: flex !important;
        flex-wrap: wrap;
        padding: 0.5rem 0;
    }
    
    .second-navbar .nav-item {
        flex: 1 0 auto;
        text-align: center;
    }
    .second-navbar{
        display: none;
    }
    
    body {
        padding-top: 40px;
    }
    
    .container.py-5.mt-5 {
        margin-top: 1rem !important;
        padding-top: 1rem;
    }
}

/* Toast notification */
.toast-notification {
    position: fixed;
    bottom: 20px;
    right: 20px;
    min-width: 250px;
    padding: 15px;
    border-radius: 6px;
    background: var(--card-bg);
    color: var(--text-color);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 1000;
    transform: translateY(100px);
    opacity: 0;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    border-left: 4px solid;
}

.toast-notification.show {
    transform: translateY(0);
    opacity: 1;
}

.toast-notification.success {
    border-left-color: #28a745;
}

.toast-notification.error {
    border-left-color: #dc3545;
}

.toast-notification.warning {
    border-left-color: #ffc107;
}

.toast-notification.info {
    border-left-color: #17a2b8;
}

.toast-icon {
    margin-right: 10px;
    font-size: 1.2rem;
}

.toast-content {
    flex: 1;
}

.toast-close {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    font-size: 1rem;
    margin-left: 10px;
}

.btn-loading {
    position: relative;
    pointer-events: none;
}

.btn-loading::after {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    margin: auto;
    border: 3px solid transparent;
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: button-loading-spinner 1s ease infinite;
}

@keyframes button-loading-spinner {
    from {
        transform: rotate(0turn);
    }
    to {
        transform: rotate(1turn);
    }
}

.no-transition *,
.no-transition *::before,
.no-transition *::after {
    transition: none !important;
    animation: none !important;
}

.no-transition .confirmation-container {
    animation: none !important;
}

.no-transition .ticket-card {
    transform: none !important;
    box-shadow: none !important;
}
.confirmation-container {
    background: var(--card-bg) !important;
}
.no-transition .ticket-card,
.no-transition .payment-summary,
.no-transition .qr-code-container {
    background: var(--card-bg) !important;
    opacity: 1 !important;
}

.pdf-light-mode {
    --primary-bg: #f5f5f5 !important;
    --secondary-bg: #ffffff !important;
    --text-color: #333 !important;
    --card-bg: white !important;
    --border-color: rgba(0, 0, 0, 0.1) !important;
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
                                   placeholder="Search for movies..." aria-label="Search"
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
                                <li><a class="dropdown-item active"<?= basename($_SERVER['PHP_SELF']) == $movies_page ? 'active' : '' ?>" href="<?= $movies_page ?>"><i class="fas fa-film"></i> Movies</a></li>
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

    <div class="container py-5 mt-5">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message']['type'] ?> alert-dismissible fade show">
                <?= $_SESSION['message']['text'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <?php if (!$isLoggedIn): ?>
            <div class="alert alert-warning">
                Please <a href="<?=$login_page?>" class="alert-link">login</a> to view your bookings.
            </div>

        <?php elseif (empty($bookingDetails)): ?>
            <div class="alert alert-danger">
                Booking not found or you don't have permission to view it.
            </div>
            <a href="<?=$my_bookings?>" class="btn btn-primary">Back to My Bookings</a>

        <?php else: ?>
            <h2 class="mb-4">Booking Details</h2>
            
            <div class="booking-details-container">
                <h4 class="section-title">Event Information</h4>
                <div class="row">
                    <div class="col-md-6">
                        <div class="detail-item">
                            <span class="detail-label">Event Name:</span>
                            <span class="detail-value"><?= htmlspecialchars($bookingDetails['summary']['event_name']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Event Type:</span>
                            <span class="detail-value"><?= htmlspecialchars($bookingDetails['summary']['event_type']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Date & Time:</span>
                            <span class="detail-value"><?= date('M j, Y g:i A', strtotime($bookingDetails['summary']['slot_starts_at'])) ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-item">
                            <span class="detail-label">Venue:</span>
                            <span class="detail-value"><?= htmlspecialchars($bookingDetails['summary']['venue_name']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Sub Venue:</span>
                            <span class="detail-value"><?= htmlspecialchars($bookingDetails['summary']['sub_venue_name']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Location:</span>
                            <span class="detail-value">
                                <?= htmlspecialchars($bookingDetails['summary']['city']) ?>, 
                                <?= htmlspecialchars($bookingDetails['summary']['state']) ?>, 
                                <?= htmlspecialchars($bookingDetails['summary']['country']) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="booking-details-container">
                <h4 class="section-title">Booking Summary</h4>
                <div class="row">
                    <div class="col-md-6">
                        <div class="detail-item">
                            <span class="detail-label">Booking Reference:</span>
                            <span class="detail-value"><?= htmlspecialchars($bookingDetails['summary']['booking_ref']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Booked At:</span>
                            <span class="detail-value"><?= date('M j, Y g:i A', strtotime($bookingDetails['summary']['booked_at'])) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Payment Method:</span>
                            <span class="detail-value"><?= htmlspecialchars($bookingDetails['summary']['payment_method']) ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-item">
                            <span class="detail-label">Total Booking Amount:</span>
                            <span class="detail-value">₹<?= number_format($bookingDetails['summary']['total_booking_amt'], 2) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Grand Total:</span>
                            <span class="detail-value">₹<?= number_format($bookingDetails['summary']['grand_total_amt'], 2) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Checked In:</span>
                            <span class="detail-value">
                                <span class="badge bg-<?= $bookingDetails['summary']['checked_in'] === 'Yes' ? 'success' : 'warning' ?> badge-checked-in">
                                    <?= htmlspecialchars($bookingDetails['summary']['checked_in']) ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
                <?php if ($bookingDetails['summary']['promo_code']): ?>
                    <div class="detail-item mt-3">
                        <span class="detail-label">Promo Code:</span>
                        <span class="detail-value"><?= htmlspecialchars($bookingDetails['summary']['promo_code']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="booking-details-container">
                <h4 class="section-title">Ticket Details (<?= $bookingDetails['summary']['ticket_count'] ?> tickets)</h4>
                <?php 
                // Group tickets by type
                $groupedTickets = [];
                foreach ($bookingDetails['tickets'] as $ticket) {
                    $type = $ticket['ticket_type'];
                    if (!isset($groupedTickets[$type])) {
                        $groupedTickets[$type] = [];
                    }
                    $groupedTickets[$type][] = $ticket;
                }
                ?>
                
                <?php foreach ($groupedTickets as $type => $tickets): ?>
                    <div class="ticket-type-header">
                        <?= htmlspecialchars($type) ?> (<?= count($tickets) ?> tickets)
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered ticket-table">
                            <thead>
                                <tr>
                                    <th>Ticket ID</th>
                                    <th>Location</th>
                                    <th>Row</th>
                                    <th>Column</th>
                                    <th>Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($ticket['ticket_id']) ?></td>
                                        <td><?= htmlspecialchars($ticket['ticket_location']) ?></td>
                                        <td><?= htmlspecialchars($ticket['ticket_row']) ?></td>
                                        <td><?= htmlspecialchars($ticket['ticket_column'] ?? 'N/A') ?></td>
                                        <td>₹<?= number_format($ticket['ticket_price'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- QR Code Section -->
            <div class="booking-details-container">
                <h4 class="section-title">Your Entry Pass</h4>
                <div class="row">
                    <div class="col-md-6">
                        <div class="qr-code-container">
                            <img src="<?= $qrCodePath ?>" alt="QR Code" class="img-fluid mb-3">
                            <p class="text-muted">Scan this QR code at the venue for entry</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex flex-column h-100 justify-content-center">
                            <h5>Booking Reference:</h5>
                            <p class="text-muted"><?= $bookingDetails['summary']['booking_ref'] ?></p>
                            
                            <h5 class="mt-3">Event:</h5>
                            <p class="text-muted"><?= htmlspecialchars($bookingDetails['summary']['event_name']) ?></p>
                            
                            <h5 class="mt-3">Venue:</h5>
                            <p class="text-muted"><?= htmlspecialchars($bookingDetails['summary']['venue_name']) ?></p>
                            
                            <div class="mt-4">
                                <button type="button" class="btn btn-gradient-primary me-2" id="downloadQrBtn">
                                    <i class="fas fa-download me-2"></i> Download QR Code
                                </button>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="send_email">
                                    <input type="hidden" name="booking_ref" value="<?= $bookingDetails['summary']['booking_ref'] ?>">
                                    <button type="submit" class="btn btn-gradient-secondary" id="emailButton">
                                        <i class="fas fa-paper-plane me-2"></i> Send to Email
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Rating Section -->
            <div class="booking-details-container" id="rate">
                <h4 class="section-title">Rate This Event</h4>
                
                <!-- Add Review Form -->
                <div class="mb-5">
                    <h5>Add Your Review:</h5>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="submit_rating">
                        <input type="hidden" name="event_id" value="<?= $bookingDetails['summary']['event_id'] ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Your Rating:</label>
                            <div class="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star star-empty" data-rating="<?= $i ?>"></i>
                                <?php endfor; ?>
                                <input type="hidden" name="rating" id="rating-value" value="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="rating_desc" class="form-label">Review:</label>
                            <textarea class="form-control" id="rating_desc" name="rating_desc" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="hashtag" class="form-label">Tags (optional):</label>
                            <input type="text" class="form-control" id="hashtag" name="hashtag" placeholder="#action #fun">
                        </div>
                        
                        <button type="submit" class="btn btn-gradient-primary">Submit Rating</button>
                    </form>
                </div>
                
                <!-- Existing Reviews -->
                <?php if (!empty($existingRatings)): ?>
                    <h5 class="mb-3">Your Previous Reviews:</h5>
                    <div class="row">
                        <?php foreach ($existingRatings as $rating): ?>
                            <div class="col-md-6 mb-4">
                                <div class="card review-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="mb-2">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star <?= $i <= $rating['user_gave'] ? 'star-filled' : 'star-empty' ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <a class="dropdown-item" href="<?=$edit_review?>?booking_ref=<?= $viewingBookingRef ?>&edit_review=1&rating_id=<?= $rating['rating_id'] ?>">
                                                            <i class="fas fa-edit me-2"></i>Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <form method="post" action="">
                                                            <input type="hidden" name="action" value="delete_rating">
                                                            <input type="hidden" name="rating_id" value="<?= $rating['rating_id'] ?>">
                                                            <button type="submit" class="dropdown-item" onclick="return confirm('Are you sure?')">
                                                                <i class="fas fa-trash me-2"></i>Delete
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        <p class="card-text mt-2"><?= htmlspecialchars($rating['rating_desc']) ?></p>
                                        <?php if ($rating['hastag']): ?>
                                            <div class="text-muted mb-2">Tags: <?= htmlspecialchars($rating['hastag']) ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted small">
                                            Posted on <?= date('M j, Y g:i A', strtotime($rating['created_on'])) ?>
                                            <?php if ($rating['created_on']): ?>
                                                <br>Updated on <?= date('M j, Y g:i A', strtotime($rating['created_on'])) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- See More Reviews Button -->
                    <?php if ($totalRatings > ($ratingsOffset + $ratingsLimit)): ?>
                        <div class="text-center mt-3">
                            <a href="?booking_ref=<?= $viewingBookingRef ?>&ratings_offset=<?= $ratingsOffset + $ratingsLimit ?>#rate" class="btn btn-gradient-secondary">
                                See More Reviews
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="mt-3">
                <a href="<?=$my_bookings?>" class="btn btn-gradient-primary">Back to My Bookings</a>
            </div>
        <?php endif; ?>
    </div>

    <footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Catchify. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    if (sessionStorage.getItem('forceRefresh') === 'true') {
        sessionStorage.removeItem('forceRefresh');
        location.reload(true);
    }
    
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
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        } else {
            themeIcon.classList.remove('fa-sun');
            themeIcon.classList.add('fa-moon');
        }
    }

    // Rating stars interaction
    document.querySelectorAll('.rating-stars .fa-star').forEach(star => {
        star.addEventListener('click', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            const container = this.parentElement;
            
            // Update visual stars
            container.querySelectorAll('.fa-star').forEach((s, i) => {
                if (i < rating) {
                    s.classList.remove('star-empty');
                    s.classList.add('star-filled');
                } else {
                    s.classList.remove('star-filled');
                    s.classList.add('star-empty');
                }
            });
            
            // Update hidden input value
            document.getElementById('rating-value').value = rating;
        });
    });

    // Initialize stars for existing ratings
    document.querySelectorAll('.existing-rating').forEach(ratingContainer => {
        const rating = parseInt(ratingContainer.getAttribute('data-rating'));
        const stars = ratingContainer.querySelectorAll('.fa-star');
        
        stars.forEach((star, i) => {
            if (i < rating) {
                star.classList.add('star-filled');
                star.classList.remove('star-empty');
            } else {
                star.classList.add('star-empty');
                star.classList.remove('star-filled');
            }
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

    // Smooth scroll to rating section if anchor is present
    if (window.location.hash === '#rate') {
        const ratingSection = document.getElementById('rate');
        if (ratingSection) {
            setTimeout(() => {
                ratingSection.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    }

    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.classList.add('fade-out');
            setTimeout(() => {
                alert.remove();
            }, 1000);
        }, 2500);
    });

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

    // Function to show toast notification
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast-notification ${type}`;
        
        const iconClass = {
            'success': 'fas fa-check-circle',
            'error': 'fas fa-exclamation-circle',
            'warning': 'fas fa-exclamation-triangle',
            'info': 'fas fa-info-circle'
        }[type];
        
        toast.innerHTML = `
            <i class="${iconClass} toast-icon"></i>
            <div class="toast-content">${message}</div>
            <button class="toast-close">&times;</button>
        `;
        
        document.body.appendChild(toast);
        
        // Show the toast
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);
        
        // Close button handler
        toast.querySelector('.toast-close').addEventListener('click', () => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        });
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }
    
    // Function to set button loading state
    function setButtonLoading(button, isLoading) {
        if (isLoading) {
            button.classList.add('btn-loading');
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>${button.innerHTML}`;
        } else {
            button.classList.remove('btn-loading');
            button.disabled = false;
            // Remove spinner and restore original content
            const originalText = button.getAttribute('data-original-text');
            if (originalText) {
                button.innerHTML = originalText;
            }
        }
    }

    // Function to generate QR card HTML
    function generateQrCardHTML(qrImageSrc, bookingRef, eventName, eventDate, eventTime, venueName) {
        return `
        <div id="qr-card-container" style="
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-top: 4px solid #e63946;
            padding: 20px;
            width: 300px;
            text-align: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        ">
            <div style="margin-bottom: 15px;">
                <h4 style="color: #e63946; margin: 0 0 5px 0; font-size: 18px;">
                    <i class="fas fa-qrcode"></i> ENTRY PASS
                </h4>
                <p style="color: #666; margin: 0; font-size: 14px;">Present this at the venue</p>
            </div>
            <div style="background: white; padding: 10px; border-radius: 8px; display: inline-block; border: 1px solid #eee;">
                <img src="${qrImageSrc}" alt="QR Code" style="width: 180px; height: 180px;">
            </div>
            <div style="margin-top: 15px; font-size: 13px; color: #666;">
                <div><strong>Event:</strong> ${eventName.substring(0, 30)}</div>
                <div><strong>Date:</strong> ${eventDate}</div>
                <div><strong>Venue:</strong> ${venueName.substring(0, 30)}</div>
                <div><strong>Time:</strong> ${eventTime}</div>
                <div style="margin-top: 8px;">
                    <span style="font-weight: bold; color: #e63946; word-break: break-all;">
                        Ref: ${bookingRef}
                    </span>
                </div>
            </div>
        </div>`;
    }

    // Function to download QR code
    function downloadQRCode() {
        const button = document.getElementById('downloadQrBtn');
        const originalText = button.innerHTML;
        button.setAttribute('data-original-text', originalText);
        setButtonLoading(button, true);
        
        // Generate the QR card HTML
        const qrCardHTML = generateQrCardHTML(
            '<?= $qrCodePath ?>',
            '<?= $bookingDetails['summary']['booking_ref'] ?>',
            '<?= htmlspecialchars($bookingDetails['summary']['event_name']) ?>',
            '<?= date('M j, Y', strtotime($bookingDetails['summary']['slot_starts_at'])) ?>',
            '<?= date('g:i A', strtotime($bookingDetails['summary']['slot_starts_at'])) ?>',
            '<?= htmlspecialchars($bookingDetails['summary']['venue_name']) ?>'
        );
        
        // Create a temporary div to render the QR card
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = qrCardHTML;
        document.body.appendChild(tempDiv);
        
        // Use html2canvas to capture the QR card
        html2canvas(tempDiv.querySelector('#qr-card-container'), {
            scale: 2, // Higher quality
            backgroundColor: null, // Transparent background
            logging: false
        }).then(canvas => {
            // Convert canvas to image and download
            const link = document.createElement('a');
            link.download = `entry_pass_<?= $bookingDetails['summary']['booking_ref'] ?>.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
            
            showToast('Entry Pass downloaded successfully!', 'success');
        }).catch(error => {
            console.error('Error generating QR card:', error);
            showToast('Failed to generate Entry Pass. Please try again.', 'error');
        }).finally(() => {
            // Clean up
            document.body.removeChild(tempDiv);
            setButtonLoading(button, false);
        });
    }

    // Function to download ticket as PDF
    function downloadTicketAsPDF() {
        const button = document.getElementById('downloadTicketBtn');
        const originalText = button.innerHTML;
        button.setAttribute('data-original-text', originalText);
        setButtonLoading(button, true);
        
        // Store current theme
        const currentTheme = document.documentElement.className;
        
        // Add PDF light mode class
        document.documentElement.classList.add('pdf-light-mode');
        
        // Add no-transition class to body to disable all transitions
        document.body.classList.add('no-transition');
        
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'pt', 'a4');
        const element = document.querySelector('.confirmation-container');
        
        // Add some padding around the content
        const padding = 20;
        
        // Small delay to allow CSS changes to take effect
        setTimeout(() => {
            html2canvas(element, {
                scale: 2, // Higher quality
                logging: false,
                useCORS: true,
                allowTaint: true,
                scrollX: 0,
                scrollY: 0,
                backgroundColor: '#ffffff' // Force white background
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const imgWidth = doc.internal.pageSize.getWidth() - (padding * 2);
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                
                doc.addImage(imgData, 'PNG', padding, padding, imgWidth, imgHeight);
                doc.save(`ticket_${'<?= $bookingDetails['summary']['booking_ref'] ?>'}.pdf`);
                
                showToast('Ticket downloaded as PDF successfully!', 'success');
            }).catch(error => {
                console.error('Error generating PDF:', error);
                showToast('Failed to generate PDF. Please try again.', 'error');
            }).finally(() => {
                // Remove PDF light mode class and restore original theme
                document.documentElement.classList.remove('pdf-light-mode');
                document.documentElement.className = currentTheme;
                
                // Remove no-transition class after capture
                document.body.classList.remove('no-transition');
                setButtonLoading(button, false);
            });
        }, 500); // Increased delay to ensure theme change is applied
    }

    // Event listeners for download buttons
    document.getElementById('downloadQrBtn')?.addEventListener('click', downloadQRCode);
    document.getElementById('downloadTicketBtn')?.addEventListener('click', downloadTicketAsPDF);

    // Email form handling with AJAX
    const emailButton = document.getElementById('emailButton');
    if (emailButton) {
        emailButton.addEventListener('click', function(e) {
            e.preventDefault();
            const originalText = emailButton.innerHTML;
            emailButton.setAttribute('data-original-text', originalText);
            setButtonLoading(emailButton, true);
            
            // Send AJAX request
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=send_email&booking_ref=<?= $bookingDetails["summary"]["booking_ref"] ?>'
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text();
                }
            })
            .catch(error => {
                console.error('Network error:', error);
                showToast('Network error while sending email', 'error');
            })
            .finally(() => {
                setButtonLoading(emailButton, false);
            });
        });
    }
});
</script>
</body>
</html>

    