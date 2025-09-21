<?php
session_start();
require_once 'config/db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$searchTerm = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
$results = [];

try {
    $stmt = $pdo->prepare("SELECT event_id, event_name, event_type FROM event_info 
                          WHERE event_name LIKE :search 
                          AND is_active = 'yes'
                          ORDER BY event_name LIMIT 5");
    $stmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Search failed: " . $e->getMessage());
}

echo json_encode($results);
?>