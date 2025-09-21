<?php
session_start();
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

// Search functionality
$searchResults = [];
if ($isLoggedIn && isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = '%' . $_GET['search'] . '%';
    
    try {
        $stmt = $pdo->prepare("SELECT event_id, event_name, event_type FROM event_info 
                              WHERE event_name LIKE :search 
                              AND is_active = 'yes'
                              ORDER BY event_name LIMIT 10");
        $stmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
        $stmt->execute();
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Search failed: " . $e->getMessage());
    }
}

// Get now showing events if city is selected
$nowShowing = [];
if ($isLoggedIn && !empty($_SESSION['selected_city'])) {
    try {
        $stmt = $pdo->prepare("SELECT e.event_id, e.event_name, e.photo, e.event_type, 
                              MIN(s.show_time) as next_show_time
                              FROM event_info e
                              JOIN shows s ON e.event_id = s.event_id
                              JOIN venues v ON s.venue_id = v.venue_id
                              WHERE v.city_id = :city_id
                              AND e.is_active = 'yes'
                              AND s.show_date >= CURDATE()
                              GROUP BY e.event_id
                              ORDER BY e.event_name
                              LIMIT 12");
        $stmt->bindParam(':city_id', $_SESSION['selected_city'], PDO::PARAM_INT);
        $stmt->execute();
        $nowShowing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch now showing events: " . $e->getMessage());
    }
}


date_default_timezone_set('Asia/Kolkata');

// Include the QR Code library
require_once 'phpqrcode/qrlib.php';

// Check if booking confirmation data exists
if (!isset($_SESSION['booking_confirmation'])) {
    header("Location: $home_page");
    exit();
}

$confirmation = $_SESSION['booking_confirmation'];
$customerEmail = $_SESSION['email'] ?? ''; // Get email from session if exists

// Initialize download counter if not set
if (!isset($_SESSION['qr_downloaded'])) {
    $_SESSION['qr_downloaded'] = 0;
}

// Generate QR code data
$qrData = http_build_query([
    'booking_ref' => $confirmation['booking_ref'],
    'event_id' => $confirmation['event_id'],
    'venue_id' => $confirmation['venue_id'],
    'schedule_id' => $confirmation['schedule_id']
]);

// QR Code handling - local generation
$qrCodeDir = 'temp/qrcodes/';

try {
    // Create directory if it doesn't exist
    if (!file_exists($qrCodeDir)) {
        if (!mkdir($qrCodeDir, 0755, true)) {
            throw new Exception('Failed to create QR code directory');
        }
    }
    
    // Sanitize booking ref for filename
    $sanitizedRef = preg_replace('/[^a-zA-Z0-9_-]/', '', $confirmation['booking_ref']);
    $qrCodeFilename = 'qr_' . $sanitizedRef . '.png';
    $qrCodePath = $qrCodeDir . $qrCodeFilename;
    
    // Generate QR code and save to file
    QRcode::png($qrData, $qrCodePath, QR_ECLEVEL_L, 10, 2);
    
    // Verify QR code was created
    if (!file_exists($qrCodePath)) {
        throw new Exception('QR code generation failed');
    }
    
} catch (Exception $e) {
    // Log error and use a fallback
    error_log('QR code generation error: ' . $e->getMessage());
    // You might want to use a default QR code or handle this differently
}


// Format date and time
$eventDate = date('l, F j, Y', strtotime($confirmation['slot_time']));
$eventTime = date('h:i A', strtotime($confirmation['slot_time']));

// Group tickets by type and collect locations
$groupedTickets = [];
foreach ($confirmation['tickets'] as $ticket) {
    $type = $ticket['type'];
    if (!isset($groupedTickets[$type])) {
        $groupedTickets[$type] = [
            'tickets' => [],
            'locations' => []
        ];
    }
    $groupedTickets[$type]['tickets'][] = $ticket;
    if (!empty($ticket['location'])) {
        $groupedTickets[$type]['locations'][] = $ticket['location'];
    }
}




// Load PHPMailer
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';



// Function to send confirmation email with stylish QR card
function sendConfirmationEmail($customerEmail, $confirmation, $qrCodePath, $eventDate, $eventTime, $groupedTickets) {
    try {
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
        $mail->setFrom('tdhruv425@gmail.com', 'Your Booking System');
        $mail->addAddress($customerEmail);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Booking Confirmation: ' . htmlspecialchars($confirmation['event_name']);
        
        // Add the QR code as an embedded image
        $qrCID = 'qr-code-' . $confirmation['booking_ref'];
        $mail->addEmbeddedImage($qrCodePath, $qrCID, 'ticket_qr_code.png');
        
        // Build email body with full styling
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
                    /* New QR card styles */
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
                    <p>Your tickets for ' . htmlspecialchars($confirmation['event_name']) . '</p>
                </div>
                
                <div class="email-body">
                    <h2 class="event-title">' . htmlspecialchars($confirmation['event_name']) . '</h2>
                    
                    <div class="booking-id">Booking Reference: ' . $confirmation['booking_ref'] . '</div>
                    
                    <div class="info-grid">
                        <div>
                            <div class="info-item"><i class="fas fa-calendar-day"></i> <strong>Date:</strong> ' . $eventDate . '</div>
                            <div class="info-item"><i class="fas fa-clock"></i> <strong>Time:</strong> ' . $eventTime . '</div>
                        </div>
                        <div>
                            <div class="info-item"><i class="fas fa-map-marker-alt"></i> <strong>Venue:</strong> ' . htmlspecialchars($confirmation['venue_name']) . '</div>
                            <div class="info-item"><i class="fas fa-map-marker-alt"></i> <strong>Sub Venue:</strong> ' . htmlspecialchars($confirmation['sub_venue_name']) . '</div>
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
                            <div><strong>Event:</strong> ' . htmlspecialchars(substr($confirmation['event_name'], 0, 30)) . '</div>
                            <div><strong>Date:</strong> ' . date('M j, Y', strtotime($confirmation['slot_time'])) . '</div>
                            <div><strong>Time:</strong> ' . date('g:i A', strtotime($confirmation['slot_time'])) . '</div>
                            <div style="margin-top: 8px;"><span class="qr-ref">Ref: ' . $confirmation['booking_ref'] . '</span></div>
                        </div>

                    </div>

                    <div class="payment-summary">
                        <h3><i class="fas fa-receipt text-primary"></i> Payment Summary</h3>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Tickets:</span>
                            <span>₹' . number_format($confirmation['total_amount'], 2) . '</span>
                        </div>

                        
                    </div>'
                        ;

                        
        
        if (isset($confirmation['promo_discount']) && $confirmation['promo_discount'] > 0) {
            $emailBody .= '
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: #28a745;">
                            <span>Discount:</span>
                            <span>-₹' . number_format($confirmation['promo_discount'], 2) . '</span>
                        </div>';
        }
        
        $emailBody .= '
                        <div class="divider"></div>
                        <div style="display: flex; justify-content: space-between; font-weight: bold;">
                            <span>Total Paid:</span>
                            <span>₹' . number_format($confirmation['total_amount'], 2) . '</span>
                        </div>
                    </div>

                    
                        <div style="display: flex; justify-content: space-between; font-weight: bold;">
                            <span>Paid Via</span>
                            <span>: ' . htmlspecialchars(substr($confirmation['payment_method'], 0, 30)) . '</span>
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
        
        $mail->Body = $emailBody;
        $mail->AltBody = "Booking Confirmation\n\n" .
            "Event: " . $confirmation['event_name'] . "\n" .
            "Date: " . $eventDate . "\n" .
            "Time: " . $eventTime . "\n" .
            "Venue: " . $confirmation['venue_name'] . "\n" .
            "Booking Reference: " . $confirmation['booking_ref'] . "\n\n" .
            "Tickets:\n";
        
        foreach ($groupedTickets as $type => $data) {
            $mail->AltBody .= $type . " (" . count($data['tickets']) . "): " . 
                             implode(', ', array_unique($data['locations'])) . "\n";
        }
        
        $mail->AltBody .= "\nTotal Paid: ₹" . number_format($confirmation['total_amount'], 2) . "\n\n" .
                          "Thank you for your booking! Please find the QR code attached to this email.";
        
        // Also attach the QR code as a separate file
        $mail->addAttachment($qrCodePath, 'ticket_qr_code.png');
        
        // Send email
        if ($mail->send()) {
            return ['status' => 'success', 'message' => 'Email sent successfully'];
        } else {
            return ['status' => 'error', 'message' => 'Mailer Error: ' . $mail->ErrorInfo];
        }
        
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'Exception Error: ' . $e->getMessage()];
    }
}


// Handle AJAX email request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_email_request'])) {
    header('Content-Type: application/json');
    
    if (!empty($customerEmail)) {
        $result = sendConfirmationEmail($customerEmail, $confirmation, $qrCodePath, $eventDate, $eventTime, $groupedTickets);
        echo json_encode($result);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No email address found']);
    }
    exit();
}

// Email handling - only when send_email button is clicked (fallback for non-JS)
$emailMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    if (!empty($customerEmail)) {
        $result = sendConfirmationEmail($customerEmail, $confirmation, $qrCodePath, $eventDate, $eventTime, $groupedTickets);
        if ($result['status'] === 'success') {
            $emailMessage = '<div class="alert alert-success">Email sent successfully to ' . htmlspecialchars($customerEmail) . '!</div>';
        } else {
            $emailMessage = '<div class="alert alert-danger">Failed to send email to ' . htmlspecialchars($customerEmail) . '. Error: ' . htmlspecialchars($result['message']) . '</div>';
        }
    } else {
        $emailMessage = '<div class="alert alert-warning">No email address found to send confirmation.</div>';
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation - <?= htmlspecialchars($confirmation['event_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        body {
            padding-top: 110px; /* 56px (top navbar) + 54px (second navbar) */
            background-color: var(--primary-bg);
            color: var(--text-color);
            transition: all 0.3s ease;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 110px;
        }

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
    top: 54px; /* Height of the first navbar */
    left: 0;
    right: 0;
    z-index: 1020;
    white-space: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    margin-top: 0; /* Ensure no margin */
    border-top: none; /* Remove any border */
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

        

        /* Mobile menu dropdown */
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
        
        /* Add this to your existing CSS */
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
            margin-top: 40px; /* Slightly more padding for very small screens */
    }
    
    .container.my-4 {
        margin-top: 1rem !important; /* Reduce top margin on small screens */
        padding-top: 0.5rem; /* Add some padding to ensure content is visible */
    }
            
        }


:root {
    --bms-black: #1a1a1a;
    --bms-dark: #2a2a2a;
    --bms-red: #e63946;
    --bms-peach: #ff9a8b;
    --bms-orange: #ff7e33;
    --bms-light: #f8f9fa;
    --bms-gradient: linear-gradient(135deg, var(--bms-red) 0%, var(--bms-orange) 100%);
    
    /* Light mode variables */
    --bg-color: var(--bms-black);
    --container-bg: var(--bms-dark);
    --text-color: white;
    --form-bg: rgba(255, 255, 255, 0.05);
    --form-border: rgba(255, 255, 255, 0.1);
    --placeholder-color: rgba(255, 255, 255, 0.4);
    --divider-color: rgba(255, 255, 255, 0.1);
    --link-color: var(--bms-peach);
    --link-hover: white;
    --text-muted: rgba(255, 255, 255, 0.7);
}

.light-mode {
    --bg-color: #f5f5f5;
    --container-bg: white;
    --text-color: #333;
    --form-bg: rgba(0, 0, 0, 0.05);
    --form-border: rgba(0, 0, 0, 0.1);
    --placeholder-color: rgba(0, 0, 0, 0.4);
    --divider-color: rgba(0, 0, 0, 0.1);
    --link-color: var(--bms-red);
    --link-hover: var(--bms-orange);
    --text-muted: rgba(0, 0, 0, 0.6);
}

body {
    background-color: var(--bg-color);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: var(--text-color);
    transition: background-color 0.3s ease, color 0.3s ease;
    min-height: 100vh;
    padding: 20px;
}

.confirmation-container {
    max-width: 1000px;
    margin: 0 auto;
    background: var(--container-bg);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    border: 1px solid var(--form-border);
    transition: all 0.3s ease;
}

.confirmation-header {
    background: var(--bms-gradient);
    color: white;
    padding: 30px;
    text-align: center;
    position: relative;
}

.confirmation-header h2 {
    font-weight: 800;
    margin-bottom: 10px;
    font-size: 32px;
}

.confirmation-body {
    padding: 30px;
}

.event-title {
    color: var(--bms-red);
    font-weight: 700;
    margin-bottom: 20px;
}

.booking-id {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--bms-red);
    margin-bottom: 20px;
    background: rgba(230, 57, 70, 0.1);
    padding: 10px 15px;
    border-radius: 6px;
    display: inline-block;
}

.ticket-card {
    border-left: 4px solid var(--bms-red);
    margin-bottom: 15px;
    background: var(--form-bg);
    border-radius: 6px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    color: var(--text-color);
}

.ticket-card .text-muted {
    color: var(--text-muted) !important;
}

.ticket-card .badge-primary {
    color: white !important;
}

.ticket-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 15px rgba(230, 57, 70, 0.2);
}

.badge-primary {
    background: var(--bms-gradient);
}

.qr-code-container {
    background: var(--container-bg);
    padding: 15px;
    border-radius: 6px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
    text-align: center;
    border: 1px solid var(--form-border);
}

.qr-code-container h5 {
    color: var(--text-color);
}

.qr-code-container .text-muted {
    color: var(--text-muted) !important;
}

.qr-code-container img {
    max-width: 100%;
    height: auto;
    padding: 10px;
    background: white;
}

.payment-summary {
    background: var(--form-bg);
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.btn-bms {
    background: var(--bms-gradient);
    border: none;
    padding: 12px 24px;
    font-weight: 700;
    border-radius: 6px;
    color: white;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
}

.btn-bms:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(230, 57, 70, 0.4);
    color: white;
}

.btn-outline-bms {
    border: 2px solid var(--bms-red);
    color: var(--bms-red);
    background: transparent;
    transition: all 0.3s ease;
}

.btn-outline-bms:hover {
    background: var(--bms-red);
    color: white;
}

.alert {
    border-radius: 6px;
    background-color: rgba(230, 57, 70, 0.2);
    border: 1px solid var(--bms-red);
    color: var(--text-color);
}

.alert-success {
    background-color: rgba(40, 167, 69, 0.2);
    border-color: #28a745;
}

.alert-warning {
    background-color: rgba(255, 193, 7, 0.2);
    border-color: #ffc107;
}

.alert-info {
    background-color: rgba(23, 162, 184, 0.2);
    border-color: #17a2b8;
}

.text-bms {
    color: var(--bms-red);
}

/* Animation */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.confirmation-container {
    animation: fadeIn 0.6s ease-out forwards;
}



/* Responsive adjustments */
@media (max-width: 768px) {
    .confirmation-header {
        padding: 20px 15px;
    }
    
    .confirmation-header h2 {
        font-size: 24px;
    }
    
    .confirmation-body {
        padding: 20px 15px;
    }
}

/* Ticket list styles */
.ticket-list {
    list-style-type: none;
    padding-left: 0;
    margin-bottom: 0;
}

.ticket-list li {
    padding: 5px 0;
    border-bottom: 1px dashed var(--divider-color);
}

.ticket-list li:last-child {
    border-bottom: none;
}

/* Loading spinner */
.spinner-border {
    width: 1rem;
    height: 1rem;
    border-width: 0.15em;
    vertical-align: text-top;
}

/* Button loading state */
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

/* Toast notification */
.toast-notification {
    position: fixed;
    bottom: 20px;
    right: 20px;
    min-width: 250px;
    padding: 15px;
    border-radius: 6px;
    background: var(--container-bg);
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

/* Disabled button state */
.btn-disabled {
    opacity: 0.7;
    pointer-events: none;
}

/* Add this to your existing CSS */
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
    background: var(--container-bg) !important;
}
.no-transition .ticket-card,
.no-transition .payment-summary,
.no-transition .qr-code-container {
    background: var(--container-bg) !important;
    opacity: 1 !important;
}

.pdf-light-mode {
    --bg-color: #f5f5f5 !important;
    --container-bg: white !important;
    --text-color: #333 !important;
    --form-bg: rgba(0, 0, 0, 0.05) !important;
    --form-border: rgba(0, 0, 0, 0.1) !important;
    --placeholder-color: rgba(0, 0, 0, 0.4) !important;
    --divider-color: rgba(0, 0, 0, 0.1) !important;
    --link-color: var(--bms-red) !important;
    --link-hover: var(--bms-orange) !important;
    --text-muted: rgba(0, 0, 0, 0.6) !important;
}

.pdf-light-mode .ticket-card,
.pdf-light-mode .payment-summary,
.pdf-light-mode .qr-code-container {
    background: white !important;
    border-color: #eee !important;
}

.pdf-light-mode .ticket-card .text-muted {
    color: rgba(0, 0, 0, 0.6) !important;
}


</style>

</head>
<body>

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



    <!-- Main Content -->
<div class="container my-4" style="padding-top: 74px;">
    <div class="confirmation-container">
        <div class="confirmation-header">
            
            <h2><i class="fas fa-check-circle"></i> Booking Confirmed!</h2>
            <p class="mb-0">Your tickets for <?= htmlspecialchars($confirmation['event_name']) ?> are ready</p>
        </div>
        
        <div class="confirmation-body">
            <?php if (isset($emailMessage)) echo $emailMessage; ?>
            
            <div class="row">
                <div class="col-md-8">
                    <h3 class="event-title"><?= htmlspecialchars($confirmation['event_name']) ?></h3>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <p class="mb-2"><i class="fas fa-calendar-day text-bms"></i> <strong>Date:</strong> <?= $eventDate ?></p>
                            <p class="mb-2"><i class="fas fa-clock text-bms"></i> <strong>Time:</strong> <?= $eventTime ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-2"><i class="fas fa-map-marker-alt text-bms"></i> <strong>Venue:</strong> <?= htmlspecialchars($confirmation['venue_name']) ?></p>
                            <p class="mb-2"><i class="fas fa-map-marker-alt text-bms"></i> <strong>Sub Venue:</strong> <?= htmlspecialchars($confirmation['sub_venue_name']) ?></p>
                            <p class="mb-2"><i class="fas fa-credit-card text-bms"></i> <strong>Payment Method:</strong> <?= ucfirst(str_replace('_', ' ', $confirmation['payment_method'])) ?></p>
                            <p class="mb-2"><i class="fas fa-envelope text-bms"></i> <strong>Email:</strong> <?= htmlspecialchars($customerEmail) ?></p>
                        </div>
                    </div>
                    
                    <div class="booking-id">Booking Reference: <?= $confirmation['booking_ref'] ?></div>
                    
                    <h5 class="mb-3"><i class="fas fa-ticket-alt text-bms"></i> Your Tickets</h5>
                    <?php foreach ($groupedTickets as $type => $data): ?>
                        <div class="card ticket-card mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge badge-primary"><?= htmlspecialchars($type) ?> (<?= count($data['tickets']) ?>)</span>
                                </div>
                                <ul class="ticket-list">
                                    <li>
                                        <span class="text-muted small"><i class="fas fa-chair"></i> Seats: <?= htmlspecialchars(implode(', ', array_unique($data['locations']))) ?></span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="col-md-4">
                    <div class="qr-code-container">
    <h5><i class="fas fa-qrcode text-bms"></i> Your Entry Pass</h5>
    <img src="<?= $qrCodePath ?>" alt="QR Code" class="img-fluid my-2">
    <p class="small text-muted">Scan this code at the venue for entry</p>
</div>
                    
                    <div class="payment-summary">
                        <h5 class="mb-3"><i class="fas fa-receipt text-bms"></i> Payment Summary</h5>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Tickets:</span>
                            <span>₹<?= number_format($confirmation['original_amount'], 2) ?></span>
                        </div>
                        <?php if (isset($confirmation['promo_discount']) && $confirmation['promo_discount'] > 0): ?>
                        <div class="d-flex justify-content-between mb-2 text-success">
                            <span>Discount:</span>
                            <span>-₹<?= number_format($confirmation['promo_discount'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <hr>
                        <div class="d-flex justify-content-between fw-bold">
                            <span>Total Paid:</span>
                            <span>₹<?= number_format($confirmation['total_amount'], 2) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 text-center">
                <form method="post" class="d-inline-block me-2" id="emailForm">
                <button type="button" name="send_email" class="btn btn-bms" id="emailButton">
                    <i class="fas fa-paper-plane me-2"></i>Send Email Confirmation
                </button>
            </form>
                <a href="<?=$home_page?>" class="btn btn-outline-bms me-2" id="homeButton">
                    <i class="fas fa-home me-2"></i>Back to Home
                </a>
                <button type="button" class="btn btn-outline-bms me-2" id="downloadQrBtn">
                    <i class="fas fa-qrcode me-2"></i>Download Booking QR Code
                </button>
                <button type="button" class="btn btn-outline-bms" id="downloadTicketBtn">
                    <i class="fas fa-file-pdf me-2"></i>Download Ticket (PDF)
                </button>
            </div>
        </div>
    </div>
</div>

<footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Catchify. All rights reserved.</p>
        </div>
    </footer>

    



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
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                } else {
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
            }
        
        );

        

            
            
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
                                    <a href="<?$event_info_page?>?id=${item.event_id}" class="search-result-item">
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
      
        });


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
        
        // Function to set theme and store preference
        function setTheme(theme) {
            document.documentElement.className = theme;
            localStorage.setItem('theme', theme);
            
            // Update icon
            const themeIcon = document.getElementById('themeIcon');
            if (theme === 'light-mode') {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
                themeIcon.style.color = '#333';
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
                themeIcon.style.color = 'white';
            }
        }

        // Function to toggle between themes
        function toggleTheme() {
            if (document.documentElement.classList.contains('light-mode')) {
                setTheme('');
            } else {
                setTheme('light-mode');
            }
        }

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
            doc.save(`ticket_${'<?= $confirmation['booking_ref'] ?>'}.pdf`);
            
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

function downloadQRCode() {
    const button = document.getElementById('downloadQrBtn');
    const originalText = button.innerHTML;
    button.setAttribute('data-original-text', originalText);
    setButtonLoading(button, true);
    
    // Generate the QR card HTML
    const qrCardHTML = generateQrCardHTML(
        '<?= $qrCodePath ?>',
        '<?= $confirmation['booking_ref'] ?>',
        '<?= htmlspecialchars($confirmation['event_name']) ?>',
        '<?= date('M j, Y', strtotime($confirmation['slot_time'])) ?>',
        '<?= date('g:i A', strtotime($confirmation['slot_time'])) ?>',
        '<?= htmlspecialchars($confirmation['venue_name']) ?>'
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
        link.download = `entry_pass_<?= $confirmation['booking_ref'] ?>.png`;
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

        document.addEventListener('DOMContentLoaded', () => {
            // Theme handling
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            if (savedTheme) {
                setTheme(savedTheme);
            } else if (!prefersDark) {
                setTheme('light-mode');
            }
            
            document.getElementById('themeToggle').addEventListener('click', toggleTheme);
            
            // QR code download button
            document.getElementById('downloadQrBtn')?.addEventListener('click', downloadQRCode);
            
            // Full ticket PDF download button
            document.getElementById('downloadTicketBtn')?.addEventListener('click', downloadTicketAsPDF);
            
            // Email form handling with AJAX
            const emailButton = document.getElementById('emailButton');
            if (emailButton) {
                emailButton.addEventListener('click', function() {
                    const originalText = emailButton.innerHTML;
                    emailButton.setAttribute('data-original-text', originalText);
                    setButtonLoading(emailButton, true);
                    
                    // Send AJAX request
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'ajax_email_request=1'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast('Email sent successfully!', 'success');
                        } else {
                            showToast('Failed to send email: ' + data.message, 'error');
                            console.error('Email error:', data.message);
                        }
                    })
                    .catch(error => {
                        showToast('Network error while sending email', 'error');
                        console.error('Network error:', error);
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