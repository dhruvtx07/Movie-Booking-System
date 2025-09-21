<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!defined('BASE_URL')) {
    // Attempt to load config if not already included (e.g., direct access to a page)
    // This is a fallback, ideally config.php is included by the calling script
    $configPath = __DIR__ . '/../config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    } else {
        die("Configuration file not found. Critical error.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Venue Management'; ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=<?php echo time(); // Cache buster ?>">
</head>
<body>

    <!-- Vertical Hover Navbar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-cogs"></i> <!-- Icon for header -->
            <span class="nav-text">Admin</span>
        </div>
        <a href="<?php echo BASE_URL; ?>index.php">
            <i class="fas fa-tachometer-alt"></i><span class="nav-text">Dashboard</span>
        </a>
        <a href="<?php echo BASE_URL; ?>cities/">
            <i class="fas fa-city"></i><span class="nav-text">Cities</span>
        </a>
        <a href="<?php echo BASE_URL; ?>venues/">
            <i class="fas fa-place-of-worship"></i><span class="nav-text">Venues</span>
        </a>
        <a href="<?php echo BASE_URL; ?>venue_tickets/">   миллионов <!-- ADD THIS LINE -->
            <i class="fas fa-ticket-alt"></i><span class="nav-text">Venue Tickets</span> <!-- ADD THIS LINE -->
        </a>  
        <!-- Add more links as needed -->
        <!-- Example:
        <a href="<?php echo BASE_URL; ?>events/">
            <i class="fas fa-calendar-alt"></i><span class="nav-text">Events</span>
        </a>
        <a href="<?php echo BASE_URL; ?>bookings/">
            <i class="fas fa-ticket-alt"></i><span class="nav-text">Bookings</span>
        </a>
        -->
    </div>

    <div class="main-container">
        <div class="container-fluid main-content">
            <?php
            // Display session messages
            if (isset($_SESSION['message'])):
            ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
            endif;
            ?>
            <!-- Content will go here -->