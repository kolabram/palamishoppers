<?php
// Navigation with submenus - to be included in all admin pages
?>
<nav class="nav-container">
    <ul class="nav">
        <!-- Dashboard -->
        <li class="nav-item">
            <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i>
                Dashboard
            </a>
        </li>

        <!-- Users -->
        <li class="nav-item">
            <button class="nav-link">
                <i class="fas fa-users-cog"></i>
                Users
                <span class="arrow"><i class="fas fa-chevron-down"></i></span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="users.php" class="dropdown-item"><i class="fas fa-users"></i> Manage Users</a></li>
                <li><a href="users.php?action=add" class="dropdown-item"><i class="fas fa-user-plus"></i> Add New User</a></li>
                <li><a href="roles.php" class="dropdown-item"><i class="fas fa-user-tag"></i> Roles & Permissions</a></li>
                <li class="dropdown-divider"></li>
                <li><a href="activity.php" class="dropdown-item"><i class="fas fa-history"></i> User Activity <span class="badge-nav danger">12</span></a></li>
            </ul>
        </li>

        <!-- Products -->
        <li class="nav-item">
            <button class="nav-link">
                <i class="fas fa-boxes"></i>
                Products
                <span class="arrow"><i class="fas fa-chevron-down"></i></span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="products.php" class="dropdown-item"><i class="fas fa-list"></i> All Products</a></li>
                <li><a href="products.php?action=add" class="dropdown-item"><i class="fas fa-plus-circle"></i> Add Product</a></li>
                <li><a href="categories.php" class="dropdown-item"><i class="fas fa-tags"></i> Categories</a></li>
                <li><a href="barcode.php" class="dropdown-item"><i class="fas fa-barcode"></i> Generate Barcode</a></li>
                <li class="dropdown-divider"></li>
                <li><a href="low-stock.php" class="dropdown-item"><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts <span class="notification-dot"></span></a></li>
            </ul>
        </li>

        <!-- Sales -->
        <li class="nav-item">
            <button class="nav-link">
                <i class="fas fa-shopping-cart"></i>
                Sales
                <span class="arrow"><i class="fas fa-chevron-down"></i></span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="sales.php" class="dropdown-item"><i class="fas fa-receipt"></i> All Sales</a></li>
                <li><a href="../cashier/pos.php" class="dropdown-item" target="_blank"><i class="fas fa-cash-register"></i> New Sale (POS)</a></li>
                <li><a href="sales.php?action=returns" class="dropdown-item"><i class="fas fa-undo"></i> Returns</a></li>
                <li class="dropdown-divider"></li>
                <li><a href="invoices.php" class="dropdown-item"><i class="fas fa-file-invoice"></i> Invoices</a></li>
                <li><a href="payments.php" class="dropdown-item"><i class="fas fa-credit-card"></i> Payment Methods</a></li>
            </ul>
        </li>

        <!-- Reports -->
        <li class="nav-item">
            <button class="nav-link">
                <i class="fas fa-chart-bar"></i>
                Reports
                <span class="arrow"><i class="fas fa-chevron-down"></i></span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="reports.php?type=sales" class="dropdown-item"><i class="fas fa-chart-line"></i> Sales Report</a></li>
                <li><a href="reports.php?type=inventory" class="dropdown-item"><i class="fas fa-warehouse"></i> Inventory Report</a></li>
                <li><a href="reports.php?type=products" class="dropdown-item"><i class="fas fa-box"></i> Product Report</a></li>
                <li><a href="reports.php?type=users" class="dropdown-item"><i class="fas fa-users"></i> User Activity Report</a></li>
                <li class="dropdown-divider"></li>
                <li><a href="reports.php?action=export" class="dropdown-item"><i class="fas fa-file-export"></i> Export Reports <span class="badge-nav success">CSV</span><span class="badge-nav">PDF</span></a></li>
            </ul>
        </li>

        <!-- Audit Logs -->
        <li class="nav-item">
            <button class="nav-link">
                <i class="fas fa-clipboard-list"></i>
                Audit Logs
                <span class="arrow"><i class="fas fa-chevron-down"></i></span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="audit.php" class="dropdown-item"><i class="fas fa-list-ul"></i> All Logs</a></li>
                <li><a href="audit.php?filter=login" class="dropdown-item"><i class="fas fa-sign-in-alt"></i> Login History</a></li>
                <li><a href="audit.php?filter=sales" class="dropdown-item"><i class="fas fa-shopping-cart"></i> Sales Logs</a></li>
                <li><a href="audit.php?filter=inventory" class="dropdown-item"><i class="fas fa-archive"></i> Inventory Changes</a></li>
            </ul>
        </li>

        <!-- Inventory -->
        <li class="nav-item">
            <button class="nav-link">
                <i class="fas fa-warehouse"></i>
                Inventory
                <span class="arrow"><i class="fas fa-chevron-down"></i></span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="inventory.php" class="dropdown-item"><i class="fas fa-list"></i> Stock Overview</a></li>
                <li><a href="inventory.php?action=adjust" class="dropdown-item"><i class="fas fa-edit"></i> Adjust Stock</a></li>
                <li><a href="inventory.php?action=transfer" class="dropdown-item"><i class="fas fa-exchange-alt"></i> Stock Transfer</a></li>
                <li><a href="suppliers.php" class="dropdown-item"><i class="fas fa-truck"></i> Suppliers</a></li>
                <li class="dropdown-divider"></li>
                <li><a href="inventory.php?action=history" class="dropdown-item"><i class="fas fa-history"></i> Transaction History</a></li>
            </ul>
        </li>
    </ul>
</nav>