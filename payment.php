<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
require_once 'config/db_config.php';
require_once 'links.php';

// Helper function to release held tickets
function releaseHeldTickets(PDO $pdo, array $ticketIds, int $userId): void {
    if (empty($ticketIds)) {
        return;
    }
    // Only release tickets held by this specific user AND whose holds are still active
    // This prevents releasing a ticket if it was already booked by someone else after hold expired, or released manually
    try {
        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        // NOTE: removed 'AND held_until > NOW()' from WHERE clause here.
        // This function might be called when hold expired but user is still on payment page.
        // We still want to clear the 'held' flags for this user.
        $stmt = $pdo->prepare("UPDATE event_schedule_tickets SET is_held = 'no', held_by_user_id = NULL, held_until = NULL WHERE ticket_id IN ($placeholders) AND held_by_user_id = ? AND is_held = 'yes'");
        $stmt->execute(array_merge($ticketIds, [$userId]));
        error_log("Successfully released " . $stmt->rowCount() . " held tickets for user $userId. IDs: " . implode(', ', $ticketIds));
    } catch (PDOException $e) {
        error_log("Failed to release held tickets for user $userId: " . $e->getMessage());
        // Handle gracefully, perhaps log to a critical errors file.
    }
}


// Redirect to login if not authenticated (except for auth pages)
$auth_pages = [$login_page, $register_page, $forgot_pass];

if (!isset($_SESSION['user_id'])) {
    if (!in_array(basename($_SERVER['PHP_SELF']), $auth_pages)) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: $login_page");
        exit();
    }
}

// Check if required session data exists from booking_processor
if (!isset($_SESSION['booking_data']) || empty($_SESSION['booking_data']) || !isset($_SESSION['booking_data']['held_ticket_ids'])) {
    $_SESSION['error_message'] = "Booking process interrupted. Critical data missing. Please start over from event selection. (Code: PAY_SESSION_MISSING)";
    header("Location: {$events_page}"); // Redirect to a more general page if critical data is missing
    exit();
}

// Get data from session
$bookingData = $_SESSION['booking_data'];
$selectedTickets = $bookingData['selected_tickets'] ?? [];
$userId = $_SESSION['user_id'] ?? 0;
$heldTicketIds = $bookingData['held_ticket_ids'] ?? [];

// Validate essential booking data from session
if (empty($bookingData['event_id']) || empty($bookingData['schedule_id']) || empty($bookingData['venue_id']) || empty($selectedTickets) || empty($heldTicketIds) || !is_array($heldTicketIds) || $userId == 0 || (isset($bookingData['user_id']) && $bookingData['user_id'] != $userId) ) {
    $_SESSION['error_message'] = "Invalid or incomplete booking data in session. Please restart the booking process. (Code: PAY_INCOMPLETE_SESSION)";
    releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold if data is bad
    header("Location: {$events_page}");
    exit();
}

// Validate ticket structure and calculate total amount (this is the subtotal before discount/fees)
$selectedTicketsByType = [];
$totalAmount = 0;

foreach ($selectedTickets as $ticket) {
    // Ensure 'seatId' is present in the ticket structure for validation purposes and display
    if (!isset($ticket['type'], $ticket['price'], $ticket['id'], $ticket['location'], $ticket['seatId'])) {
        $_SESSION['error_message'] = "Corrupted ticket data in session. Required fields missing. Please re-select your tickets. (Code: PAY_CORRUPTED_TICKET)";
        releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
        header("Location: {$book_event}?event_id={$bookingData['event_id']}&schedule_id={$bookingData['schedule_id']}"); // Redirect to ticket map
        exit();
    }

    // Also check if this ticket ID from $selectedTickets is actually in $heldTicketIds
    if (!in_array($ticket['id'], $heldTicketIds)) {
        $_SESSION['error_message'] = "Mismatch found in selected tickets. Please re-select your tickets. (Code: PAY_TICKET_ID_MISMATCH)";
        releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
        header("Location: {$book_event}?event_id={$bookingData['event_id']}&schedule_id={$bookingData['schedule_id']}"); // Redirect to ticket map
        exit();
    }

    $ticket['price'] = (float)$ticket['price']; // Ensure price is float
    if (!isset($selectedTicketsByType[$ticket['type']])) {
        $selectedTicketsByType[$ticket['type']] = [];
    }
    $selectedTicketsByType[$ticket['type']][] = $ticket;
    $totalAmount += $ticket['price'];
}

if ($totalAmount <= 0) {
    $_SESSION['error_message'] = "Ticket prices are invalid or zero. Please re-select your tickets. (Code: PAY_ZERO_TOTAL)";
    releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
    header("Location: {$book_event}?event_id={$bookingData['event_id']}&schedule_id={$bookingData['schedule_id']}"); // Redirect to ticket map
    exit();
}

// -----------------------------------------------------------
// Comprehensive Pre-transaction Validity Checks (before any POST processing or payment forms)
// This ensures that the entities involved in the booking are active and available, AND holds are valid.
// -----------------------------------------------------------
try {
    $pdo->beginTransaction(); // Start a transaction for the pre-checks

    // 1. User active check
    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE userid = ?"); // Standardized to 'userid'
    $stmt->execute([$userId]);
    $userStatus = $stmt->fetchColumn();
    if ($userStatus !== 'yes') {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Your account is currently inactive. Please contact support or log in with an active account. (Code: PAY_USER_INACTIVE)";
        releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
        header("Location: {$logout_page}"); // Log out inactive user
        exit();
    }

    // 2. Event active check
    $stmt = $pdo->prepare("SELECT is_active FROM event_info WHERE event_id = ?"); // Corrected table from 'events'
    $stmt->execute([$bookingData['event_id']]);
    $eventStatus = $stmt->fetchColumn();
    if ($eventStatus !== 'yes') {
        $pdo->rollBack();
        $_SESSION['error_message'] = "The selected event is no longer active or available for booking. Please choose another event. (Code: PAY_EVENT_INACTIVE)";
        releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
        header("Location: {$events_page}"); // Redirect to events page
        exit();
    }

    // 3. Venue active check
    $stmt = $pdo->prepare("SELECT is_active, city_id FROM venues WHERE venue_id = ?");
    $stmt->execute([$bookingData['venue_id']]);
    $venueInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$venueInfo || $venueInfo['is_active'] !== 'yes') {
        $pdo->rollBack();
        $_SESSION['error_message'] = "The venue for this event is currently inactive. Please select another event or venue. (Code: PAY_VENUE_INACTIVE)";
        releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
        header("Location: {$events_page}"); // Redirect to events page
        exit();
    }

    // 4. City active check (derived from venue)
    $stmt = $pdo->prepare("SELECT is_active FROM cities WHERE city_id = ?");
    $stmt->execute([$venueInfo['city_id']]);
    $cityStatus = $stmt->fetchColumn();
    if ($cityStatus !== 'yes') {
        $pdo->rollBack();
        $_SESSION['error_message'] = "The city where this venue is located is currently inactive. Please select another city from the dropdown. (Code: PAY_CITY_INACTIVE)";
        releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
        header("Location: {$home_page}"); // Redirect to home page
        exit();
    }

    // 5. Event Schedule & Venue Schedule active check
    $stmt = $pdo->prepare("SELECT es.is_active, vs.is_active as venue_schedule_active
                         FROM event_schedule es
                         JOIN venue_schedule vs ON es.venue_schedule_id = vs.venue_schedule_id
                         WHERE es.event_schedule_id = ?");
    $stmt->execute([$bookingData['schedule_id']]); // schedule_id in bookingData is event_schedule_id
    $scheduleInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scheduleInfo || $scheduleInfo['is_active'] !== 'yes' || $scheduleInfo['venue_schedule_active'] !== 'yes') {
        $pdo->rollBack();
        $_SESSION['error_message'] = "The selected showtime is no longer available or active. Please check the timings and try again. (Code: PAY_SCHEDULE_INACTIVE)";
        releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
        header("Location: {$events_page}"); // Redirect to events page or event detail page
        exit();
    }

    // 6. CRITICAL CHECK FOR HELD TICKETS
    // Ensure all tickets in heldTicketIds are still held by the current user and not expired
    if (!empty($heldTicketIds)) {
        $placeholders = implode(',', array_fill(0, count($heldTicketIds), '?'));
        // Select for update to lock these rows during the check, preventing race conditions during this crucial step.
        $stmt = $pdo->prepare("
            SELECT ticket_id, is_vacant, is_held, held_by_user_id, held_until
            FROM event_schedule_tickets
            WHERE ticket_id IN ($placeholders) AND event_schedule_id = ? AND is_active = 'yes' FOR UPDATE
        ");
        $stmt->execute(array_merge($heldTicketIds, [$bookingData['schedule_id']]));
        $currentHeldTicketStatuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if the number of returned tickets matches the number of requested held tickets
        if (count($currentHeldTicketStatuses) !== count($heldTicketIds)) {
            $pdo->rollBack();
            error_log("Payment - Some held tickets not found or inactive during immediate payment check for user $userId. Held IDs: " . implode(',', $heldTicketIds));
            $_SESSION['error_message'] = "One or more of your selected seats are no longer available. Please re-select your tickets. (Code: PAY_SOME_HELD_UNAVAILABLE)";
            // Attempt to release any remaining holds for these tickets (e.g., if some were inactivated)
            releaseHeldTickets($pdo, $heldTicketIds, $userId);
            header("Location: {$book_event}?event_id={$bookingData['event_id']}&schedule_id={$bookingData['schedule_id']}");
            exit();
        }

        foreach ($currentHeldTicketStatuses as $status) {
            // Check if it's still marked as vacant, held by the current user, and the hold is still active (not expired)
            if ($status['is_vacant'] !== 'yes' ||
                $status['is_held'] !== 'yes' ||
                $status['held_by_user_id'] != $userId ||
                strtotime($status['held_until']) < time() // Check if hold has expired
            ) {
                $pdo->rollBack();
                error_log("Payment - Held ticket ID {$status['ticket_id']} failed final hold validation for user $userId. Status: " . print_r($status, true));
                $_SESSION['error_message'] = "One or more of your selected seats are no longer on hold for you (e.g., hold expired or taken). Please re-select your tickets. (Code: PAY_HOLD_EXPIRED)";
                // Attempt to release any remaining holds for these tickets
                releaseHeldTickets($pdo, $heldTicketIds, $userId);
                header("Location: {$book_event}?event_id={$bookingData['event_id']}&schedule_id={$bookingData['schedule_id']}");
                exit();
            }
        }
    } else {
        // This case should ideally be caught earlier, but as a final safeguard.
        $pdo->rollBack();
        $_SESSION['error_message'] = "No tickets were specified to be on hold during payment. Please restart booking. (Code: PAY_NO_HELD_TICKETS)";
        header("Location: {$book_event}?event_id={$bookingData['event_id']}"); // Redirect to general event page
        exit();
    }
    // Commit the transaction after all pre-checks pass successfully.
    $pdo->commit();

} catch (PDOException $e) {
    // If a database error occurs during pre-checks, rollback any changes and release holds.
    $pdo->rollBack();
    error_log("Payment page pre-transaction validation database error: " . $e->getMessage());
    $_SESSION['error_message'] = "A critical system error occurred during initial validation. Please try again later. (Code: PAY_PRE_DB_ERR)";
    releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
    header("Location: {$home_page}"); // Redirect to home page
    exit();
}
// -----------------------------------------------------------
// END: Comprehensive Pre-transaction Validity Checks
// -----------------------------------------------------------

// Initialize promo code variables
$promoCode = '';
$promoDiscount = 0;
$promoApplied = false;
$code_id_applied_for_this_transaction = null;

// Fetch available promo codes from database (only active and not fully used ones)
$availablePromoCodes = [];
try {
    // Only fetch active codes that have remaining redemptions
    $stmt = $pdo->query("SELECT * FROM promo_codes WHERE is_active = 'yes' AND times_used < max_redemptions ORDER BY code DESC");
    $availablePromoCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch promo codes: " . $e->getMessage());
    // No error message to user, as promo code feature might not be essential for booking
}

// Process promo code if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['apply_promo']) || isset($_POST['apply_promo_from_list']))) {
    $promoCodeInput = isset($_POST['apply_promo']) ? trim($_POST['promo_code']) : '';
    $promoCodeId = isset($_POST['apply_promo_from_list']) ? (int)$_POST['promo_code_id'] : 0;

    $promoData = null;

    if (!empty($promoCodeInput)) {
        $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code = ? AND is_active = 'yes' AND times_used < max_redemptions");
        $stmt->execute([$promoCodeInput]);
        $promoData = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($promoCodeId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code_id = ? AND is_active = 'yes' AND times_used < max_redemptions");
        $stmt->execute([$promoCodeId]);
        $promoData = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($promoData) {
        // Ensure that the applied promo code's value does not exceed 75% of the total amount
        if ($promoData['code_value'] > ($totalAmount * 0.75)) {
            $_SESSION['error_message'] = "The selected promo code's value exceeds 75% of your total booking amount (" . ($totalAmount * 0.75) . ") and cannot be applied.";
            // Clear any previously applied promo if a new invalid one is tried
            unset($_SESSION['promo_code']);
            unset($_SESSION['promo_code_id']);
            header("Location: " . $_SERVER['PHP_SELF']); // Redirect to self to show message
            exit();
        }

        if (isset($_SESSION['promo_code_id']) && $_SESSION['promo_code_id'] == $promoData['code_id']) {
            $_SESSION['success_message'] = "Promo code '{$promoData['code']}' is already applied.";
        } else {
            $_SESSION['success_message'] = "Promo code applied successfully! ₹{$promoData['code_value']} discount applied for code: '{$promoData['code']}'.";
        }

        $code_id_applied_for_this_transaction = $promoData['code_id'];
        $promoCode = $promoData['code'];
        $promoDiscount = min($promoData['code_value'], $totalAmount); // Ensure discount doesn't make total negative
        $promoApplied = true;

        // Store promo data in session
        $_SESSION['promo_code'] = $promoData;
        $_SESSION['promo_code_id'] = $code_id_applied_for_this_transaction;

        error_log("Promo code applied. Code ID: {$code_id_applied_for_this_transaction}, Discount: ₹{$promoDiscount}. User: {$userId}");
    } else {
        $_SESSION['error_message'] = "The entered promo code is invalid, inactive, or has been fully used.";
        // Clear any previously applied promo if a new invalid one is tried
        unset($_SESSION['promo_code']);
        unset($_SESSION['promo_code_id']);
    }
    header("Location: " . $_SERVER['PHP_SELF']); // Redirect to self to show message
    exit();

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_promo'])) {
    unset($_SESSION['promo_code']);
    unset($_SESSION['promo_code_id']);
    $promoCode = '';
    $promoDiscount = 0;
    $promoApplied = false;
    $code_id_applied_for_this_transaction = null; // Reset this too
    $_SESSION['success_message'] = "Promo code has been removed.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Rehydrate promo code state from session (after any POST above)
if (isset($_SESSION['promo_code']) && isset($_SESSION['promo_code_id'])) {
    $promoData = $_SESSION['promo_code'];
    $promoCode = $promoData['code'];
    $code_id_applied_for_this_transaction = $promoData['code_id'];
    $promoDiscount = min($promoData['code_value'], $totalAmount); // Recalculate based on current total
    $promoApplied = true;
} else {
    // If no promo code is in session or it was just removed, ensure variables are cleared
    $promoCode = '';
    $promoDiscount = 0;
    $promoApplied = false;
    $code_id_applied_for_this_transaction = null;
}

// Calculate convenience fee and GST
$convenienceFee = 0; // Assuming 0 as per the original code logic, or calculate based on type/amount
$gstPercentage = 0; // Assuming 0% GST as per original comments, will be multiplied by 0.
// If GST is 18%, set it to 0.18
// $gstPercentage = 0.18;
$gst = round(($totalAmount - $promoDiscount + $convenienceFee) * $gstPercentage, 2);
$grandTotal = $totalAmount - $promoDiscount + $convenienceFee + $gst;

// Ensure grandTotal is not negative due to over-discounting
if ($grandTotal < 0) {
    error_log("Calculated grand total became negative: {$grandTotal}. Adjusting to 0.");
    $grandTotal = 0;
}

// Store the calculated total in session for payment processing
$_SESSION['booking_data']['final_amount_to_pay'] = $grandTotal; // clearer key for final amount
$_SESSION['booking_data']['promo_discount_applied'] = $promoDiscount;
$_SESSION['booking_data']['applied_code_id'] = $code_id_applied_for_this_transaction;

// Process payment if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $paymentMethod = $_POST['payment_method'] ?? '';

    // Validate payment method
    if (!in_array($paymentMethod, ['credit_card', 'upi', 'netbanking'])) {
        $_SESSION['error_message'] = "Invalid payment method selected. Please choose a valid payment option.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Generate booking reference - more robust than substr(uniqid(), -4)
    $bookingRef = 'BK-' . date('Ymd-His') . '-' . mt_rand(1000, 9999) . '-' . $userId;

    // Calculate per-ticket discount
    $numTickets = count($selectedTickets);
    $perTicketDiscount = $numTickets > 0 ? ((float)$promoDiscount / $numTickets) : 0;

    $currentDateTime = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        $code_id_for_db_insert = $_SESSION['booking_data']['applied_code_id'] ?? null;

        // This loop inserts records into the bookings table and prepares for final seat status update
        foreach ($selectedTickets as $ticket) {
            // Already validated in pre-transaction checks and at the top, just use it.
            // This ticket['id'] is the event_schedule_tickets.ticket_id
            $ticket_id_to_process = $ticket['id'];

            $ticketBookingAmt = (float)$ticket['price']; // Original price before discount
            $ticketTotalAmt = $ticketBookingAmt - $perTicketDiscount;

            // Ensure individual ticket total amount doesn't go below zero
            if ($ticketTotalAmt < 0) {
                $ticketTotalAmt = 0;
            }

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
                $ticket_id_to_process, // This is the actual ticket_id from event_schedule_tickets
                $code_id_for_db_insert,
                $ticketBookingAmt, // This is the amount before per-ticket discount
                $ticketTotalAmt, // This is the amount after per-ticket discount
                $paymentMethod,
                $userId,
                $currentDateTime
            ]);

            if (!$result) {
                error_log("Insert failed for ticket {$ticket_id_to_process} into bookings table. Error Info: " . print_r($stmt->errorInfo(), true));
                $pdo->rollBack();
                $_SESSION['error_message'] = "Failed to finalize booking for seat " . htmlspecialchars($ticket['seatId']) . ". Please contact support. (Code: PAY_BOOKING_INSERT_FAILED)";
                releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release hold
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        }

        // --- Update status of all FINALLY BOOKED seats to 'not vacant' AND clear hold ---
        // We use $heldTicketIds as these are the exact tickets that were held and are now being converted to booked.
        if (!empty($heldTicketIds)) {
            $placeholders = rtrim(str_repeat('?,', count($heldTicketIds)), ',');
            $updateSeatsStmt = $pdo->prepare("
                UPDATE event_schedule_tickets
                SET is_vacant = 'no', is_held = 'no', held_by_user_id = NULL, held_until = NULL
                WHERE ticket_id IN ($placeholders)
                  AND is_vacant = 'yes'             -- Must be vacant
                  AND is_held = 'yes' AND held_by_user_id = ? -- Must be held by THIS user
            ");
            $updateSeatsStmt->execute(array_merge($heldTicketIds, [$userId]));

            // Crucial: Check if all seats were actually updated. If not, a race condition occurred.
            if ($updateSeatsStmt->rowCount() !== count($heldTicketIds)) {
                $pdo->rollBack();
                error_log("Race condition or partial seat update detected during finalization for booking_ref: {$bookingRef}. Expected " . count($heldTicketIds) . " rows affected, got " . $updateSeatsStmt->rowCount() . " for ticket IDs: " . implode(', ', $heldTicketIds));
                $_SESSION['error_message'] = "Some of your selected seats became unavailable during final payment. Please re-select your tickets. (Code: PAY_SEAT_RACE_CONDITION)";
                // Only release holds if the update failed completely. If partially updated, database will handle.
                // Redirect back to the ticket map page for re-selection
                $redirectToTicketMap = $book_event . "?event_id=" . $bookingData['event_id'] . "&schedule_id=" . $bookingData['schedule_id'];
                header("Location: $redirectToTicketMap");
                exit();
            }
        }

        // --- Update promo code usage count ---
        if ($code_id_for_db_insert !== null) {
            $updatePromoStmt = $pdo->prepare("UPDATE promo_codes SET times_used = times_used + 1 WHERE code_id = ? AND times_used < max_redemptions AND is_active = 'yes'");
            $updatePromoResult = $updatePromoStmt->execute([$code_id_for_db_insert]);

            if (!$updatePromoResult || $updatePromoStmt->rowCount() === 0) {
                error_log("Failed to update promo code times_used for code_id: {$code_id_for_db_insert}. Possibly already maxed out, not found, or inactive. Row Count: " . $updatePromoStmt->rowCount());
                $pdo->rollBack(); // Rollback EVERYTHING if promo update fails
                $_SESSION['error_message'] = "Booking failed: The applied promo code is no longer valid, inactive, or has reached its maximum usage limit. Please remove it and try again. (Code: PAY_PROMO_EXPIRED)";
                // It's important to unset the promo code in session if it failed its usage check
                unset($_SESSION['promo_code']);
                unset($_SESSION['promo_code_id']);
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
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
            'total_amount' => $grandTotal, // The final amount paid
            'original_amount' => $totalAmount + $convenienceFee + ($totalAmount - $promoDiscount + $convenienceFee) * $gstPercentage, // Original amount *if no discount was applied*
            'promo_discount' => $promoDiscount,
            'payment_method' => $paymentMethod,
            'code_id_used' => $code_id_for_db_insert
        ];

        // Clear session data related to current booking so it doesn't linger
        unset($_SESSION['booking_data']); // This clears held_ticket_ids too
        unset($_SESSION['promo_code']);
        unset($_SESSION['promo_code_id']);

        header("Location: {$booking_confirmation}");
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Payment - Booking database error: " . $e->getMessage());
        error_log("Payment - SQLSTATE Error Info: " . print_r($pdo->errorInfo(), true));
        $_SESSION['error_message'] = "An unexpected error occurred during payment finalization. Please try again or contact support if the issue persists. (Code: PAY_TRN_DB_ERR)";
        // Attempt to release holds on DB error during finalization
        releaseHeldTickets($pdo, $heldTicketIds, $userId); // Attempt to release holds
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? ($_SESSION['username'] ?? 'User') : 'Guest'; // Default 'User' if username not set

// Fetch cities from database (only if logged in)
$cities = [];
if ($isLoggedIn) {
    try {
        $stmt = $pdo->query("SELECT city_id, city_name FROM cities WHERE is_active = 'yes' ORDER BY city_name");
        $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Payment - Failed to fetch cities for navbar: " . $e->getMessage());
        // Provide a default list or handle gracefully
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
/* Navbar Styles (from home.php) */
:root { /* Default/Light mode */
    --primary-bg: #ffffff;  /* Body background */
    --secondary-bg: #f8f9fa; /* Card background, filter background */
    --text-color: #141414;  /* Main text */
    --accent-red: #e50914;  /* Strong red accent */
    --accent-orange: #ff6b35;  /* Orange accent */
    --accent-peach: #ff9e7d;  /* Lighter orange accent */
    --accent-black: #141414;  /* Logo text/dark text */
    --card-bg: #ffffff;  /* Specific card background */
    --nav-dark: #141414;  /* Navbar background */
    --nav-text: #ffffff;  /* Navbar text */
    --gradient-primary: linear-gradient(135deg, var(--accent-red) 0%, var(--accent-orange) 100%);
    --gradient-secondary: linear-gradient(135deg, var(--accent-orange) 0%, var(--accent-peach) 100%);
    --border-color: rgba(0,0,0,0.1); /* General border */
    --form-border: rgba(0,0,0,0.1); /* For search/city dropdown border */
    --input-bg: rgba(0,0,0,0.03); /* For search input background */
    --placeholder-color: rgba(0,0,0,0.4); /* For placeholder text */
    --divider-color: rgba(0,0,0,0.1); /* For HR/dividers */
    --link-color: var(--accent-red);
    --link-hover: var(--accent-orange);
    --highlight-color: #FFFF00; /* Used for selected seats (unique to this file) */
    --light-color: #FFDAB9; /* Used for VIP seats (unique to this file) */
    --search-result-bg: var(--card-bg);
    --search-result-text: var(--text-color);
    --search-result-hover: var(--secondary-bg);
    --search-result-border: rgba(0,0,0,0.1);
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
    --border-color: rgba(255,255,255,0.1);
    --form-border: rgba(255,255,255,0.1);
    --input-bg: rgba(255,255,255,0.1);
    --placeholder-color: rgba(255,255,255,0.4);
    --divider-color: rgba(255,255,255,0.1);
    --link-color: var(--accent-red);
    --link-hover: var(--accent-orange);
    --highlight-color: #FFFF00; /* Used for selected seats */
    --light-color: #FFDAB9; /* Used for VIP seats */
    --search-result-bg: var(--secondary-bg);
    --search-result-text: var(--text-color);
    --search-result-hover: rgba(255,255,255,0.05);
    --search-result-border: var(--form-border);
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
    position: fixed; /* Added fixed position */
    top: 60px; /* Height of the first navbar */
    left: 0;
    right: 0;
    z-index: 1020;
    white-space: nowrap;
    overflow-x: auto;
    overflow-y: hidden; /* Changed from hidden in home.php to prevent scrollbar */
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    margin-top: 0; /* Ensure no margin */
    border-top: none; /* Remove any border */
    display: block !important;
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
    color: var(--nav-text); /* Ensure color from variable */
    position: relative; /* Ensure relative pos for ::after */
    transition: all 0.3s ease; /* Ensure transition */
}

.second-navbar .nav-link::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    width: 0;
    height: 2px;
    background: var(--gradient-primary);
    transition: all 0.3s ease;
    transform: translateX(-50%);
    opacity: 0; /* Hidden by default */
}

.second-navbar .nav-link:hover::after {
    width: 100%;
    opacity: 1;
}

.second-navbar .nav-link:hover {
    transform: translateY(-2px);
    color: var(--nav-text); /* Keep text color on hover */
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
    background-color: var(--search-result-bg);
    border: 1px solid var(--search-result-border);
    border-radius: 0 0 8px 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    z-index: 1000;
    max-height: 400px;
    overflow-y: auto;
    display: none;
    color: var(--search-result-text);
}

.search-result-item {
    padding: 10px 15px;
    border-bottom: 1px solid var(--search-result-border);
    transition: all 0.2s;
    color: var(--search-result-text);
    text-decoration: none;
    display: block;
}

.search-result-item:hover {
    background-color: var(--search-result-hover);
    color: var(--search-result-text);
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
    margin-right: 10px; /* Adjusted to keep spacing */
}

.dropdown-menu {
    z-index: 1050;
    background-color: var(--nav-dark);
    border: 1px solid var(--form-border);
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
    border-bottom: 1px solid var(--form-border);
}

.city-search-input {
    background-color: var(--input-bg);
    color: var(--text-color);
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
    color: white; /* Text color on hover */
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
    margin-left: 0;
}

.theme-toggle:hover {
    transform: rotate(30deg);
    background: rgba(255,255,255,0.1);
}

/* Mobile menu dropdown - hidden by default on large screens */
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

/* General Alerts (Consistent with home.php) */
.alert {
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    color: var(--text-color); /* Ensure text color from variable */
    background-color: var(--secondary-bg); /* Default background color */
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
.alert-danger { /* Specific style for error messages */
    background-color: var(--accent-red);
    color: white;
    border: none;
}


/* Payment Page Specific Styles */
body {
    padding-top: 110px; /* Default: Accounts for both navbars on large screens */
    background-color: var(--primary-bg);
    color: var(--text-color);
    transition: all 0.3s ease;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 0.9rem;
}

.payment-container {
    width: 100%;
    margin: 0 auto;
}


/* :root (moved to body scope as data-bs-theme handles it) */
.event-card, .payment-card {
    border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1); /* Use direct shadow value */
    margin-bottom: 20px;
    background: var(--secondary-bg); /* Maps to --container-bg */
    border: 1px solid var(--form-border);
    transition: all 0.3s ease;
    overflow: hidden;
}

.event-header {
    background: var(--gradient-primary); /* Maps to --bms-gradient */
    color: white;
    border-radius: 10px 10px 0 0;
    padding: 15px;
    font-weight: 700;
}

.card-header {
    background-color: var(--secondary-bg); /* Maps to --container-bg */
    border-bottom: 1px solid var(--form-border);
    padding: 15px;
    font-weight: 700;
    color: var(--text-color);
}

.event-body, .card-body {
    background-color: var(--secondary-bg); /* Maps to --container-bg */
    padding: 20px;
    border-radius: 0 0 10px 10px;
    color: var(--text-color);
}

.ticket-card {
    border-left: 4px solid var(--accent-red); /* Maps to --bms-red */
    margin-bottom: 15px;
    background-color: var(--input-bg); /* Maps to --form-bg */
    border-radius: 6px;
    transition: all 0.3s ease;
}

.ticket-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(230, 57, 70, 0.2); /* Original value was defined, keep */
    background-color: var(--secondary-bg); /* Maps to --container-bg */
}

.payment-methods .nav-link {
    border: 1px solid var(--form-border);
    border-radius: 6px;
    margin-bottom: 10px;
    padding: 12px;
    color: var(--text-color);
    background-color: var(--input-bg); /* Maps to --form-bg */
    transition: all 0.3s ease;
}

.payment-methods .nav-link.active {
    border-color: var(--accent-red); /* Maps to --bms-red */
    background: rgba(230, 57, 70, 0.1); /* Original value was defined, keep */
    box-shadow: 0 0 0 0.25rem rgba(230, 57, 70, 0.1); /* Original value was defined, keep */
    color: var(--text-color);
}

.payment-methods .nav-link:hover {
    background-color: var(--input-bg); /* Maps to --form-bg */
    border-color: var(--accent-peach); /* Maps to --bms-peach */
    color: var(--text-color);
}

.payment-icon {
    font-size: 1.3rem;
    margin-right: 8px;
    color: var(--accent-red); /* Maps to --bms-red */
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

.regular-badge { background-color: var(--accent-red); } /* Maps to --bms-red */
.recliner-badge { background-color: var(--accent-orange); } /* Maps to --bms-orange */
.premium-badge { background-color: var(--accent-peach); color: black; } /* Maps to --bms-yellow, black text */
.vip-badge { background-color: var(--light-color); color: black; } /* Maps to --bms-peach, black text */

.card-icons img {
    height: 22px;
    margin-left: 5px;
    filter: grayscale(100%) brightness(var(--icon-brightness, 100%));
}

[data-bs-theme="light"] .card-icons img {
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
    background-color: var(--input-bg); /* Maps to --form-bg */
    color: var(--text-color);
    transition: all 0.3s ease;
}

.form-control::placeholder {
    color: var(--placeholder-color);
}

.form-control:focus {
    border-color: var(--accent-peach); /* Maps to --bms-peach */
    box-shadow: 0 0 0 0.25rem rgba(230, 57, 70, 0.25); /* Original value */
    background-color: var(--input-bg); /* Maps to --form-bg */
    color: var(--text-color);
}

.btn-bms {
    background: var(--gradient-primary); /* Maps to --bms-gradient */
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
    box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3); /* Original value */
}

.btn-bms:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(230, 57, 70, 0.4); /* Original value */
    color: white;
    background: var(--gradient-primary); /* Keep same gradient for hover */
}

.btn-outline-bms {
    border: 1px solid var(--accent-red); /* Maps to --bms-red */
    color: var(--accent-red); /* Maps to --bms-red */
    background: transparent;
    font-weight: 600;
    transition: all 0.3s ease;
    padding: 12px 15px;
    border-radius: 6px;
    height: 100%;
}

.btn-outline-bms:hover {
    background: var(--accent-red); /* Maps to --bms-red */
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
    background-color: rgba(25, 135, 84, 0.15); /* Original value */
    border-color: rgba(25, 135, 84, 0.3); /* Original value */
    color: var(--text-color);
}

.alert-danger {
    background-color: rgba(220, 53, 69, 0.15); /* Original value */
    border-color: rgba(220, 53, 69, 0.3); /* Original value */
    color: var(--text-color);
}

/* Text colors */
h1, h2, h3, h4, h5, h6 {
    color: var(--text-color);
}

.text-muted {
    color: var(--placeholder-color) !important; /* Maps to --text-muted */
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
    top: 110px; /* Adjusted to consider both navbars and top padding */
}

hr {
    border-color: var(--divider-color);
    margin: 15px 0;
}

/* Responsive adjustments for overall page padding and navbars*/
@media (max-width: 767.98px) { /* Bootstrap's 'md' breakpoint */
    body {
        padding-top: 54px; /* Only top navbar visible */
    }
    .second-navbar {
        display: none !important; /* Explicitly hide second navbar */
    }
    .mobile-menu-toggle {
        display: block; /* Show mobile toggle */
    }
    .mobile-menu-dropdown {
        display: block; /* Show mobile dropdown wrapper */
    }
    /* Adjusted sticky top for when only one navbar is present */
    .sticky-top {
        top: 74px; /* 54px topbar + ~20px margin */
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
        margin-right: 0;
    }
    .payment-container {
        padding-left: 15px;
        padding-right: 15px;
    }
}

/* Devices 768px and wider (e.g., most laptops and desktops) */
@media (min-width: 768px) {
    .second-navbar {
        display: block !important; /* Ensure second navbar is visible */
    }
    /* Also ensure mobile menu is hidden on these larger screens */
    .mobile-menu-toggle,
    .mobile-menu-dropdown {
        display: none !important;
    }
}

/* Existing media query for very small mobile screens (e.g., phones) */
@media (max-width: 575.98px) {
    body {
        padding-top: 60px; /* Specific padding for very small screens */
    }
    .payment-container {
        padding: 0.75rem;
    }
    /* Adjusted sticky top for very small screens (60px topbar + ~20px margin) */
    .sticky-top {
        top: 80px;
    }
}

/* Other responsive adjustments */
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
    .event-header, .card-header {
        font-size: 1rem;
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
        position: static; /* Force sticky to be static on smaller screens */
    }

    .ticket-card {
        margin-bottom: 10px;
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
    display: none; /* Changed from none to block for initial load for dynamic display */
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
    display: flex; /* Ensure it becomes flex when active */
}

.loader-content {
    background: var(--secondary-bg); /* Maps to --container-bg */
    padding: 30px;
    border-radius: 10px;
    text-align: center;
    max-width: 400px;
    width: 90%;
}

.loader-spinner {
    border: 5px solid var(--input-bg); /* Maps to --form-bg */
    border-top: 5px solid var(--accent-red); /* Maps to --bms-red */
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

/* Fixes for theme toggle compatibility for forms/cards */
[data-bs-theme="light"] {
    --primary-bg: #f5f5f5; /* Lighter body bg */
    --secondary-bg: white; /* Lighter card bg */
    --text-color: #333;
    --input-bg: rgba(0,0,0,0.03); /* Lighter input bg */
    --form-border: rgba(0,0,0,0.1); /* Lighter border */
    --placeholder-color: rgba(0,0,0,0.4);
    --divider-color: rgba(0,0,0,0.1);
    --search-result-bg: white;
    --search-result-hover: #f8f9fa;
    --search-result-text: #333;
}
[data-bs-theme="light"] .form-control {
    background-color: white;
    color: #333;
    border: 1px solid #ddd;
}
[data-bs-theme="light"] .form-control:focus {
    background-color: white;
    color: #333;
}
[data-bs-theme="light"] .ticket-card {
    background-color: #f8f9fa;
}
[data-bs-theme="light"] .ticket-card:hover {
    background-color: white;
}
[data-bs-theme="light"] hr {
    border-color: #ddd;
}
[data-bs-theme="light"] .payment-methods .nav-link {
    background-color: #f8f9fa;
}
[data-bs-theme="light"] .payment-methods .nav-link:hover {
    background-color: #f0f0f0;
}
[data-bs-theme="light"] .payment-methods .nav-link.active {
    background: rgba(230, 57, 70, 0.1); /* Keep original color */
}

/* Promo Codes Dropdown specific styles */
.promo-codes-dropdown {
    position: relative;
    display: inline-block;
    margin-left: 10px;
    z-index: 1050; /* Higher than other elements */
}

.promo-codes-btn {
    background: var(--gradient-primary);
    border: none;
    color: white;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.promo-codes-btn:hover {
    opacity: 0.9;
}

.promo-codes-content {
    display: none;
    position: absolute;
    background-color: var(--secondary-bg); /* Maps to --container-bg */
    min-width: 300px;
    max-width: 90vw;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    z-index: 1060; /* Higher than other elements */
    border-radius: 8px;
    padding: 15px;
    right: 0;
    left: auto;
    margin-top: 5px;
    border: 1px solid var(--form-border);
    max-height: 250px;
    overflow-y: auto;
    box-sizing: border-box;
}

.promo-codes-content.show {
    display: block;
    animation: fadeIn 0.3s ease-out;
}

.promo-code-item {
    padding: 10px;
    margin-bottom: 8px;
    background-color: var(--input-bg); /* Maps to --form-bg */
    border-radius: 6px;
    border-left: 3px solid var(--accent-red); /* Maps to --bms-red */
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
    color: var(--placeholder-color); /* Maps to --text-muted */
    margin-bottom: 8px;
}

.promo-code-value {
    font-size: 0.9rem;
    color: var(--accent-red); /* Maps to --bms-red */
    font-weight: 600;
}

.apply-promo-btn {
    background: var(--gradient-primary); /* Maps to --bms-gradient */
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
    box-shadow: 0 2px 5px rgba(230, 57, 70, 0.3); /* Original value */
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
    color: var(--placeholder-color); /* Maps to --text-muted */
    font-size: 1.2rem;
    cursor: pointer;
}

/* Responsive adjustments for promo codes dropdown */
@media (max-width: 575px) {
    .promo-codes-content {
        right: auto;
        left: 50%;
        transform: translateX(-50%);
        width: 90vw;
        min-width: unset;
        max-width: 350px;
    }
}
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
                    <a href="<?= $list_your_show ?>" class="nav-link">
                        <i class="fa fa-plus-square me-1"></i>
                        <span class="nav-text">List Your Show</span>
                    </a>
                    <a href="<?= $view_promos ?>" class="nav-link">
                        <i class="fa fa-ticket me-1"></i>
                        <span class="nav-text">Promo Codes</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="container py-4 payment-container">
        <?php
        // Display error messages from session if any
        if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php
            unset($_SESSION['error_message']); // Clear the message after displaying
        endif;
        ?>
        <?php
        // Display success messages from session if any (e.g., promo code applied)
        if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php
            unset($_SESSION['success_message']); // Clear the message after displaying
        endif;
        ?>
        <div class="row">
            <div class="col-lg-8 col-md-8"> <!-- ADDED col-md-8 HERE -->
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
                                    <?= htmlspecialchars($bookingData['genre'] ?? 'Genre') ?> &bull;
                                    <?= htmlspecialchars($bookingData['language'] ?? 'Language') ?> &bull;
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
                                            <p class="mb-1">₹<?= (float)$tickets[0]['price'] ?> each</p>
                                            <p class="mb-0"><strong>₹<?= (float)$tickets[0]['price'] * count($tickets) ?></strong></p>
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
                                        <?php
                                        $display_promo_list = false;
                                        foreach ($availablePromoCodes as $promo):
                                            // Only display if not already maxed out, is active, AND the value is 75% or less of totalAmount
                                            // Added check for totalAmount to prevent division by zero or nonsensical percentage if total is zero
                                            if ($promo['times_used'] < $promo['max_redemptions'] && $promo['is_active'] == 'yes' && ($totalAmount > 0 ? ($promo['code_value'] / $totalAmount) <= 0.75 : $promo['code_value'] == 0)):
                                                $display_promo_list = true;
                                        ?>
                                            <div class="promo-code-item">
                                                <div class="promo-code-title"><?= htmlspecialchars($promo['code']) ?></div>
                                                <div class="promo-code-desc mt-1 mb-2">
                                                    Get ₹<?= htmlspecialchars($promo['code_value']) ?> off!
                                                </div>
                                                <div>
                                                    <span class="promo-code-value">Value: ₹<?= htmlspecialchars($promo['code_value']) ?></span>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="promo_code_id" value="<?= $promo['code_id'] ?>">
                                                        <button type="submit" name="apply_promo_from_list" class="apply-promo-btn">Apply</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php
                                            endif; // End if not maxed out and active
                                        endforeach;
                                        if (!$display_promo_list):
                                        ?>
                                            <p class="text-muted text-center mb-0">No available promo codes at this time.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
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
                                        <input type="text" class="form-control" id="cardNumber" placeholder="1234 5678 9012 3456" required pattern="(\d\s?){15,19}" title="Please enter a 16-digit card number" maxlength="19">
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="cardName" class="form-label">Name on Card</label>
                                            <input type="text" class="form-control" id="cardName" placeholder="John Doe" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="cardExpiry" class="form-label">Expiry</label>
                                            <input type="text" class="form-control" id="cardExpiry" placeholder="MM/YY" required pattern="(0[1-9]|1[0-2])\/?(\d{2})" title="MM/YY format" maxlength="5">
                                        </div>
                                        <div class="col-md-3">
                                            <label for="cardCvv" class="form-label">CVV</label>
                                            <input type="text" class="form-control" id="cardCvv" placeholder="123" required pattern="\d{3,4}" title="3 or 4 digit CVV" maxlength="4">
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
                                        <input type="text" class="form-control" id="upiId" placeholder="yourname@upi" required pattern="[a-zA-Z0-9.\-]+@[a-zA-Z0-9.\-]+" title="Valid UPI ID format (e.g., example@bank)">
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

            <div class="col-lg-4 col-md-4"> <!-- ADDED col-md-4 HERE -->
                <div class="card payment-card sticky-top">
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
                            <span>GST (<?= ($gstPercentage * 100) ?>%):</span>
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

    <footer class="bg-light py-3 mt-4">
        <div class="container text-center">
            <p class="text-muted mb-0">© <?= date('Y') ?> Catchify. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Theme toggle functionality (Copied from file1)
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
                htmlElement.classList.remove('light-mode'); // Use htmlElement, not document.body
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                htmlElement.classList.add('light-mode'); // Use htmlElement
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
        }
        // Initial call to set the icon correctly based on loaded theme
        currentThemeOnLoad = htmlElement.getAttribute('data-bs-theme') || (systemPrefersDark ? 'dark' : 'light');
        updateThemeIcon(currentThemeOnLoad);


        // City search functionality (Copied from file1)
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

        // City selection (Copied from file1)
        cityItems.forEach(item => {
            item.addEventListener('click', function() {
                const cityId = this.getAttribute('data-value');
                document.getElementById('selectedCity').value = cityId;
                document.getElementById('cityForm').submit();
            });
        });

        // Search functionality with AJAX (Copied from file1)
        const searchInput = document.getElementById('searchInput');
        const searchResults = document.getElementById('searchResults');

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.trim();

                if (searchTerm.length >= 2) {
                    const searchPage = '<?= $search ?? 'search.php' ?>'; // Fallback if links.php not fully defined
                    const eventInfoPage = '<?= $event_info_page ?? 'event_info.php' ?>'; // Fallback

                    fetch(`$?search=${encodeURIComponent(searchTerm)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.length > 0) {
                                let html = '';
                                data.forEach(item => {
                                    html += `
                                    <a href="$?id=${item.event_id}" class="search-result-item">
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
                } else {
                    searchResults.style.display = 'none';
                    searchResults.innerHTML = '';
                }
            });

            // Hide results when clicking outside
            document.addEventListener('click', function(e) {
                if (searchInput && searchResults && !searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.style.display = 'none';
                }
            });
        }

        // Promo codes dropdown functionality
        const promoCodesBtn = document.getElementById('promoCodesBtn');
        const promoCodesContent = document.getElementById('promoCodesContent');
        const closePromoCodes = document.getElementById('closePromoCodes');

        if (promoCodesBtn && promoCodesContent) {
            promoCodesBtn.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent doc click from closing it instantly
                promoCodesContent.classList.toggle('show');
            });

            if (closePromoCodes) {
                closePromoCodes.addEventListener('click', function() {
                    promoCodesContent.classList.remove('show');
                });
            }

            // Close when clicking outside
            document.addEventListener('click', function(e) {
                // Check if the click target is outside both the button and the content
                if (promoCodesContent && !promoCodesContent.contains(e.target) && promoCodesBtn && !promoCodesBtn.contains(e.target)) {
                    promoCodesContent.classList.remove('show');
                }
            });
        }

        // Format card number input
        document.getElementById('cardNumber')?.addEventListener('input', function(e) {
            const input = e.target;
            let value = input.value.replace(/\D/g, ''); // Remove non-digits
            let formattedValue = '';
            for (let i = 0; i < value.length; i++) {
                if (i > 0 && i % 4 === 0) {
                    formattedValue += ' ';
                }
                formattedValue += value[i];
            }
            input.value = formattedValue;
        });

        // Format expiry date input
        document.getElementById('cardExpiry')?.addEventListener('input', function(e) {
            const input = e.target;
            let value = input.value.replace(/\D/g, ''); // Remove non-digits
            let formattedValue = value;
            if (value.length > 2) {
                formattedValue = value.substring(0, 2) + '/' + value.substring(2, 4);
            }
            input.value = formattedValue;
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
                    // Only show loader if form validation passes
                    if (this.checkValidity()) {
                        loader.classList.add('active');
                    } else {
                        // If validation fails, Bootstrap's default handling will show errors
                        // We prevent submission here to let Bootstrap's validation work
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            }
        });
    });
</script>

</body>
</html>