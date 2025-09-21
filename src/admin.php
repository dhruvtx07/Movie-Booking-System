<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catchify - Admin Panel</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --black: #121212;
            --dark-gray: #1e1e1e;
            --medium-gray: #2a2a2a;
            --light-gray: #3a3a3a;
            --red: #ff3b30;
            --peach: #ff9a8b;
            --orange: #ff9500;
            --yellow: #ffcc00;
            --white: #e0e0e0;
        }
        
        .nav-item.has-submenu {
    position: relative;
}

.sub-menu {
    position: absolute;
    left: 0;
    top: 100%;
    width: 100%;
    z-index: 100;
    /* Add some visual connection */
    border-top: 2px solid var(--orange);
    border-radius: 0 0 10px 10px;
}

/* When sidebar is expanded, make submenu position relative */
.sidebar:hover .sub-menu,
.sidebar.active .sub-menu {
    position: relative;
    top: auto;
    left: auto;
    width: auto;
}

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--black);
            color: var(--white);
            overflow-x: hidden;
        }
        
        .sidebar {
            width: 80px;
            background: var(--dark-gray);
            color: var(--white);
            height: 100vh;
            position: fixed;
            overflow-y: auto;
            transition: all 0.3s;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            /* Hide scrollbar but keep functionality */
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }
        
        .sidebar::-webkit-scrollbar {
            display: none; /* Chrome, Safari and Opera */
        }
        
        .sidebar:hover, .sidebar.active {
            width: 280px;
        }
        
        .sidebar:hover .nav-item-text,
        .sidebar.active .nav-item-text {
            display: inline-block;
        }
        
        .sidebar:hover .sub-menu,
        .sidebar.active .sub-menu {
            display: none; /* Changed from block to none - submenus should only show when clicked */
        }
        
        .sidebar:hover .nav-group-title,
        .sidebar.active .nav-group-title {
            display: block;
        }
        
        .logo-container {
            padding: 1rem;
            text-align: center;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--black);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .logo-wrapper {
            width: 50px;
            height: 50px;
            margin: 0 auto;
            border-radius: 50%;
            background-color: var(--medium-gray);
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid var(--orange);
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .sidebar:hover .logo-wrapper {
            width: 120px;
            height: 120px;
            margin-bottom: 1rem;
        }
        
        .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .brand-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--white);
            letter-spacing: 1px;
            display: none;
            margin-top: 10px;
        }
        
        .sidebar:hover .brand-name {
            display: block;
        }
        
        .nav-menu {
            padding: 1rem 0;
            /* Ensure no scrollbar appears */
            overflow: hidden;
        }
        
        .nav-item {
            padding: 0.75rem 1rem;
            color: var(--white);
            opacity: 0.8;
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            white-space: nowrap;
            overflow: hidden;
            cursor: pointer;
        }
        
        .nav-item:hover, .nav-item.active {
            opacity: 1;
            background: var(--medium-gray);
            border-left: 3px solid var(--orange);
        }
        
        .nav-item i {
            margin-right: 10px;
            font-size: 1.1rem;
            min-width: 24px;
            text-align: center;
        }
        
        .nav-item.active i {
            color: var(--orange);
        }
        
        .nav-item-text {
            display: none;
        }
        
        .nav-group-title {
            padding: 0.75rem 1rem;
            color: var(--peach);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 1rem;
            display: none;
            /* Prevent text wrapping */
            white-space: nowrap;
        }
        
        .sub-menu {
            padding-left: 0;
            background: var(--black);
            display: none; /* Changed to none - submenus should be hidden by default */
            /* Ensure no scrollbar appears */
            overflow: hidden;
        }
        
        .sub-menu .nav-item {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            font-size: 0.9rem;
        }
        
        /* Show submenu when parent has active-submenu class */
        .nav-item.active-submenu + .sub-menu {
            display: block;
        }
        
        /* When sidebar is expanded, submenus are still controlled by click */
        .sidebar.active .nav-item.has-submenu.active + .sub-menu,
        .sidebar:hover .nav-item.has-submenu.active + .sub-menu {
            display: block;
        }
        
        .main-content {
            margin-left: 80px;
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--black);
            transition: margin-left 0.3s;
            /* Hide scrollbar but keep functionality */
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }
        
        .main-content::-webkit-scrollbar {
            display: none; /* Chrome, Safari and Opera */
        }
        
        .sidebar:hover ~ .main-content,
        .sidebar.active ~ .main-content {
            margin-left: 280px;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s;
            background-color: var(--dark-gray);
            color: var(--white);
        }
        
        .card:hover {
            transform: translateY(-5px);
        }
        
        .card-header {
            background-color: var(--medium-gray);
            border-bottom: 1px solid var(--light-gray);
            font-weight: 600;
            color: var(--white);
            border-radius: 10px 10px 0 0 !important;
        }
        
        .table {
            color: var(--white);
            background-color: var(--dark-gray);
            margin-bottom: 0;
        }
        
        .table-hover tbody tr:hover {
            background-color: var(--medium-gray);
        }
        
        .table th, .table td {
            border-color: var(--light-gray);
            padding: 0.75rem 1rem;
        }
        
        .bg-primary {
            background-color: var(--red) !important;
        }
        
        .bg-success {
            background-color: var(--orange) !important;
        }
        
        .bg-warning {
            background-color: var(--yellow) !important;
        }
        
        .bg-info {
            background-color: var(--peach) !important;
        }
        
        .bg-danger {
            background-color: var(--red) !important;
        }
        
        .text-primary {
            color: var(--red) !important;
        }
        
        .text-success {
            color: var(--orange) !important;
        }
        
        .text-warning {
            color: var(--yellow) !important;
        }
        
        .text-info {
            color: var(--peach) !important;
        }
        
        .text-danger {
            color: var(--red) !important;
        }
        
        .btn-primary {
            background-color: var(--red);
            border-color: var(--red);
        }
        
        .btn-primary:hover {
            background-color: #e0352b;
            border-color: #e0352b;
        }
        
        .btn-outline-secondary {
            color: var(--white);
            border-color: var(--light-gray);
        }
        
        .btn-outline-secondary:hover {
            background-color: var(--medium-gray);
            border-color: var(--light-gray);
        }
        
        .text-muted {
            color: var(--peach) !important;
            opacity: 0.7;
        }
        
        .badge {
            color: var(--black) !important;
        }
        
        @media (max-width: 992px) {
            .sidebar {
                width: 280px;
                margin-left: -280px;
            }
            
            .sidebar.active {
                margin-left: 0;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar.active ~ .main-content {
                margin-left: 0;
            }
            
            .sidebar .nav-item-text,
            .sidebar .sub-menu,
            .sidebar .nav-group-title,
            .sidebar .brand-name {
                display: block;
            }
            
            .sidebar .logo-wrapper {
                width: 120px;
                height: 120px;
                margin-bottom: 1rem;
            }
        }
        
        /* Mobile menu button */
        .menu-toggle {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1050;
            background: var(--medium-gray);
            border: none;
            color: var(--white);
            border-radius: 5px;
            padding: 0.5rem;
            display: none;
        }
        
        @media (max-width: 992px) {
            .menu-toggle {
                display: block;
            }
        }


        
    </style>
</head>
<body>
    <button class="menu-toggle d-lg-none">
        <i class="bi bi-list"></i>
    </button>
    
    <div class="sidebar">
        <div class="logo-container">
            <div class="logo-wrapper">
                <!-- Replace with your actual logo -->
                <img src="images/logo.png" alt="Catchify Logo">
            </div>
            <div class="brand-name">Catchify</div>
        </div>
        
        <div class="nav-menu">
            <a href="#" class="nav-item active">
                <i class="bi bi-speedometer2"></i>
                <span class="nav-item-text">Dashboard</span>
            </a>
            
            <div class="nav-group-title">Content Management</div>
            <a href="#" class="nav-item has-submenu">
                <i class="bi bi-building"></i>
                <span class="nav-item-text">Cities</span>
            </a>
            <a href="#" class="nav-item has-submenu">
                <i class="bi bi-geo-alt"></i>
                <span class="nav-item-text">Venues</span>
            </a>
            <div class="sub-menu">
                <a href="#" class="nav-item">Venue Schedules</a>
                <a href="#" class="nav-item">Venue Tickets</a>
            </div>
            
            <a href="#" class="nav-item has-submenu">
                <i class="bi bi-calendar-event"></i>
                <span class="nav-item-text">Events</span>
            </a>
            <div class="sub-menu">
                <a href="#" class="nav-item">Event Schedules</a>
                <a href="#" class="nav-item">Event Schedule Tickets</a>
            </div>
            
            <div class="nav-group-title">Marketing</div>
            <a href="#" class="nav-item">
                <i class="bi bi-tag"></i>
                <span class="nav-item-text">Promo Codes</span>
            </a>
            
            <div class="nav-group-title">Sales & Tickets</div>
            <a href="#" class="nav-item">
                <i class="bi bi-receipt"></i>
                <span class="nav-item-text">Booking Data</span>
            </a>
            <a href="#" class="nav-item">
                <i class="bi bi-ticket-perforated"></i>
                <span class="nav-item-text">Ticket Availability</span>
            </a>
            <a href="#" class="nav-item">
                <i class="bi bi-qr-code-scan"></i>
                <span class="nav-item-text">Scan Ticket/Booking QR</span>
            </a>
            
            <div class="nav-group-title">Settings</div>
            <a href="#" class="nav-item">
                <i class="bi bi-percent"></i>
                <span class="nav-item-text">Commission Percentage</span>
            </a>
            
            <div class="nav-group-title">Community</div>
            <a href="#" class="nav-item">
                <i class="bi bi-chat-square-text"></i>
                <span class="nav-item-text">Reviews</span>
            </a>
            <a href="#" class="nav-item">
                <i class="bi bi-people"></i>
                <span class="nav-item-text">Users</span>
            </a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Dashboard</h2>
                <div>
                    <button class="btn btn-sm btn-outline-secondary d-lg-none menu-toggle">
                        <i class="bi bi-list"></i> Menu
                    </button>
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-2">Total Bookings</h6>
                                    <h3 class="mb-0">1,254</h3>
                                </div>
                                <div class="bg-primary bg-opacity-10 p-3 rounded">
                                    <i class="bi bi-receipt text-primary fs-4"></i>
                                </div>
                            </div>
                            <div class="mt-3">
                                <span class="text-success">↑ 12.5%</span>
                                <span class="text-muted ms-2">vs last month</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-2">Active Events</h6>
                                    <h3 class="mb-0">42</h3>
                                </div>
                                <div class="bg-success bg-opacity-10 p-3 rounded">
                                    <i class="bi bi-calendar-event text-success fs-4"></i>
                                </div>
                            </div>
                            <div class="mt-3">
                                <span class="text-success">↑ 5.3%</span>
                                <span class="text-muted ms-2">vs last month</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-2">Total Revenue</h6>
                                    <h3 class="mb-0">$28,450</h3>
                                </div>
                                <div class="bg-warning bg-opacity-10 p-3 rounded">
                                    <i class="bi bi-currency-dollar text-warning fs-4"></i>
                                </div>
                            </div>
                            <div class="mt-3">
                                <span class="text-success">↑ 8.7%</span>
                                <span class="text-muted ms-2">vs last month</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-2">New Users</h6>
                                    <h3 class="mb-0">187</h3>
                                </div>
                                <div class="bg-info bg-opacity-10 p-3 rounded">
                                    <i class="bi bi-people text-info fs-4"></i>
                                </div>
                            </div>
                            <div class="mt-3">
                                <span class="text-success">↑ 3.2%</span>
                                <span class="text-muted ms-2">vs last month</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4 g-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Recent Bookings</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Booking ID</th>
                                            <th>Event</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>#BK-1001</td>
                                            <td>Summer Music Festival</td>
                                            <td>2023-07-15</td>
                                            <td><span class="badge bg-success">Confirmed</span></td>
                                            <td>$120.00</td>
                                        </tr>
                                        <tr>
                                            <td>#BK-1002</td>
                                            <td>Art Exhibition</td>
                                            <td>2023-07-14</td>
                                            <td><span class="badge bg-success">Confirmed</span></td>
                                            <td>$45.00</td>
                                        </tr>
                                        <tr>
                                            <td>#BK-1003</td>
                                            <td>Theater Performance</td>
                                            <td>2023-07-13</td>
                                            <td><span class="badge bg-warning text-dark">Pending</span></td>
                                            <td>$75.00</td>
                                        </tr>
                                        <tr>
                                            <td>#BK-1004</td>
                                            <td>Comedy Night</td>
                                            <td>2023-07-12</td>
                                            <td><span class="badge bg-danger">Cancelled</span></td>
                                            <td>$60.00</td>
                                        </tr>
                                        <tr>
                                            <td>#BK-1005</td>
                                            <td>Jazz Concert</td>
                                            <td>2023-07-11</td>
                                            <td><span class="badge bg-success">Confirmed</span></td>
                                            <td>$90.00</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <button class="btn btn-primary w-100 mb-3">
                                <i class="bi bi-plus-circle me-2"></i> Add New Event
                            </button>
                            <button class="btn btn-outline-secondary w-100 mb-3">
                                <i class="bi bi-ticket-perforated me-2"></i> Manage Tickets
                            </button>
                            <button class="btn btn-outline-secondary w-100 mb-3">
                                <i class="bi bi-tag me-2"></i> Create Promo Code
                            </button>
                            <button class="btn btn-outline-secondary w-100 mb-3">
                                <i class="bi bi-qr-code-scan me-2"></i> Scan Tickets
                            </button>
                            <button class="btn btn-outline-secondary w-100">
                                <i class="bi bi-gear me-2"></i> Settings
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const menuToggle = document.querySelectorAll('.menu-toggle');
    
    // Toggle sidebar on menu button click
    menuToggle.forEach(button => {
        button.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });
    });
    
    // Handle submenu behavior
    const navItemsWithSubmenu = document.querySelectorAll('.nav-menu > .nav-item.has-submenu');
    
    navItemsWithSubmenu.forEach(item => {
        const submenu = item.nextElementSibling;
        
        // Click to toggle submenu
        item.addEventListener('click', function(e) {
            if (this.getAttribute('href') === '#') {
                e.preventDefault();
            }
            
            const isActive = this.classList.contains('active-submenu');
            
            // Close all other submenus first
            document.querySelectorAll('.nav-item.has-submenu').forEach(otherItem => {
                if (otherItem !== this) {
                    otherItem.classList.remove('active-submenu');
                }
            });
            
            // Toggle this submenu
            this.classList.toggle('active-submenu');
        });
        
        // Add mouseenter/mouseleave events for better hover behavior
        item.addEventListener('mouseenter', function() {
            if (sidebar.classList.contains('active') || sidebar.classList.contains('hover')) {
                this.classList.add('active-submenu');
            }
        });
        
        submenu.addEventListener('mouseenter', function() {
            if (sidebar.classList.contains('active') || sidebar.classList.contains('hover')) {
                item.classList.add('active-submenu');
            }
        });
        
        item.addEventListener('mouseleave', function(e) {
            // Check if mouse is moving to submenu
            const relatedTarget = e.relatedTarget;
            if (!submenu.contains(relatedTarget) && relatedTarget !== submenu) {
                this.classList.remove('active-submenu');
            }
        });
        
        submenu.addEventListener('mouseleave', function(e) {
            // Check if mouse is moving to parent item
            const relatedTarget = e.relatedTarget;
            if (relatedTarget !== item && !item.contains(relatedTarget)) {
                item.classList.remove('active-submenu');
            }
        });
    });
    
    // Track if sidebar is being hovered (for desktop)
    sidebar.addEventListener('mouseenter', function() {
        this.classList.add('hover');
    });
    
    sidebar.addEventListener('mouseleave', function() {
        this.classList.remove('hover');
        // Close all submenus when leaving sidebar
        document.querySelectorAll('.nav-item.has-submenu').forEach(item => {
            item.classList.remove('active-submenu');
        });
    });
    
    // Close submenus when clicking anywhere else
    document.addEventListener('click', function(e) {
        if (!sidebar.contains(e.target)) {
            document.querySelectorAll('.nav-item.has-submenu').forEach(item => {
                item.classList.remove('active-submenu');
            });
        }
    });
});
    </script>
</body>
</html>