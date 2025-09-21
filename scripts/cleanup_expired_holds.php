<?php


// cleanup_expired_holds.php

// Adjust this path to correctly point to your db_config.php relative to *this* script
// For example, if cleanup_expired_holds.php is in 'scripts/' and db_config.php is in 'config/'
// both direct under 'your_project_name/', it might be:
// require_once __DIR__ . '/../config/db_config.php';
// Or if db_config.php is in the top-level directory where index.php might be:
// require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/db_config.php'; // <--- ADJUST THIS LINE FOR YOUR SETUP!

// Set timezone (important for accurate time comparisons)
date_default_timezone_set('Asia/Kolkata');

try {
    // Check if $pdo is available from db_config.php
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception("PDO connection failed or not available.");
    }

    // Begin a transaction to ensure atomicity
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE event_schedule_tickets
        SET is_held = 'no', held_by_user_id = NULL, held_until = NULL
        WHERE is_held = 'yes' AND held_until < NOW()
    ");
    $stmt->execute();

    $cleanupCount = $stmt->rowCount(); // Get the number of rows affected

    $pdo->commit(); // Commit the transaction
    
    // Log success or echo for cron output redirection
    // This output will typically go to a log file you specify in the scheduler
    error_log("Cron Job: Cleaned up " . $cleanupCount . " expired ticket holds at " . date('Y-m-d H:i:s'));
    echo "Cleaned up " . $cleanupCount . " expired holds successfully.\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { // Only rollback if a transaction was started
        $pdo->rollBack();
    }
    error_log("Cron Job Error (Database): Failed to clean up expired holds: " . $e->getMessage() . " Script: " . __FILE__ . " at " . date('Y-m-d H:i:s'));
    echo "Error cleaning up expired holds (DB): " . $e->getMessage() . "\n";
} catch (Exception $e) {
    error_log("Cron Job Error (General): " . $e->getMessage() . " Script: " . __FILE__ . " at " . date('Y-m-d H:i:s'));
    echo "Error cleaning up expired holds (General): " . $e->getMessage() . "\n";
} finally {
    $pdo = null; // Explicitly close the connection
}
?>