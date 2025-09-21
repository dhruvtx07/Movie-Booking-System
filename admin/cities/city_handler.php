<?php
session_start();
require_once '../config.php'; // Adjust path as necessary

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add or Update City
    if (isset($_POST['save_city']) || isset($_POST['update_city'])) {
        $city_name = mysqli_real_escape_string($conn, $_POST['city_name']);
        $state_name = mysqli_real_escape_string($conn, $_POST['state_name']);
        $country = mysqli_real_escape_string($conn, $_POST['country']);
        $is_active = mysqli_real_escape_string($conn, $_POST['is_active']);
        $city_id = isset($_POST['city_id']) ? mysqli_real_escape_string($conn, $_POST['city_id']) : null;

        // Basic Validation
        if (empty($city_name) || empty($state_name) || empty($country) || empty($is_active)) {
            $_SESSION['message'] = "All fields are required.";
            $_SESSION['message_type'] = "danger";
        } else {
            if (isset($_POST['save_city'])) { // Create
                $sql = "INSERT INTO cities (city_name, state_name, country, is_active) VALUES (?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "ssss", $city_name, $state_name, $country, $is_active);
            } elseif (isset($_POST['update_city']) && $city_id) { // Update
                $sql = "UPDATE cities SET city_name = ?, state_name = ?, country = ?, is_active = ? WHERE city_id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "ssssi", $city_name, $state_name, $country, $is_active, $city_id);
            }

            if (isset($stmt) && mysqli_stmt_execute($stmt)) {
                $_SESSION['message'] = "City " . (isset($_POST['save_city']) ? "saved" : "updated") . " successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error: " . mysqli_error($conn);
                $_SESSION['message_type'] = "danger";
            }
            mysqli_stmt_close($stmt);
        }
        header("Location: index.php");
        exit();
    }

    // Delete City
    if (isset($_POST['delete_city'])) {
        $city_id = mysqli_real_escape_string($conn, $_POST['city_id_to_delete']);
        // Check if city is used in venues
        $check_sql = "SELECT COUNT(*) as count FROM venues WHERE city_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $city_id);
        mysqli_stmt_execute($check_stmt);
        $result = mysqli_stmt_get_result($check_stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($check_stmt);

        if ($row['count'] > 0) {
            $_SESSION['message'] = "Cannot delete city. It is currently assigned to ". $row['count'] . " venue(s).";
            $_SESSION['message_type'] = "danger";
        } else {
            $sql = "DELETE FROM cities WHERE city_id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $city_id);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['message'] = "City deleted successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error deleting city: " . mysqli_error($conn);
                $_SESSION['message_type'] = "danger";
            }
            mysqli_stmt_close($stmt);
        }
        header("Location: index.php");
        exit();
    }
} else {
    // Optional: Handle GET requests if needed, or redirect
    header("Location: index.php");
    exit();
}
?>