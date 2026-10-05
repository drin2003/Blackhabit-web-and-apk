<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

function cashierActive($pages)
{
    global $currentPage;
    return in_array($currentPage, (array)$pages, true) ? 'active' : '';
}

$inventoryOpen = in_array($currentPage, [
    'inventory_management.php',
    'supplies_management.php'
], true);
?>

<style>
/* =========================================================
   BLACKHABIT CASHIER SIDEBAR — TABLET / iPAD LANDSCAPE
   These rules override shared style.css for tablet landscape
   without changing the existing PHP/session/navigation logic.
   ========================================================= */

@media screen and (min-width: 768px) and (max-width: 1366px) and (orientation: landscape) {

    html,
    body {
        overflow-x: hidden;
    }

    .sidebar {
        width: 220px !important;
        min-width: 220px !important;
    }

    .sidebar .logo {
        padding-left: 14px !important;
        padding-right: 14px !important;
    }

    .sidebar .logo h2 {
        font-size: 25px !important;
        white-space: nowrap;
        letter-spacing: 1px;
    }

    .sidebar .logo p {
        font-size: 11px !important;
    }

    .sidebar-menu {
        padding-left: 10px !important;
        padding-right: 10px !important;
    }

    .sidebar-menu > li > a {
        min-height: 48px;
        padding: 10px 12px !important;
        gap: 12px;
        font-size: 14px !important;
    }

    .sidebar-menu > li > a i {
        width: 20px;
        min-width: 20px;
        text-align: center;
    }

    .sidebar-menu .dropdown-menu {
        padding-left: 18px !important;
    }

    .sidebar-menu .dropdown-menu a {
        min-height: 40px;
        padding: 9px 12px !important;
        font-size: 12px !important;
    }

    /* Shared pages use .main beside the fixed sidebar. */
    .main {
        margin-left: 220px !important;
        width: calc(100% - 220px) !important;
        max-width: calc(100% - 220px) !important;
        min-width: 0 !important;
    }

    .menu-toggle {
        display: none !important;
    }

    .sidebar-overlay {
        display: none !important;
    }
}

/* Smaller tablet landscape */
@media screen and (min-width: 768px) and (max-width: 899px) and (orientation: landscape) {

    .sidebar {
        width: 205px !important;
        min-width: 205px !important;
    }

    .sidebar .logo h2 {
        font-size: 22px !important;
    }

    .sidebar-menu > li > a {
        padding-left: 10px !important;
        padding-right: 10px !important;
        font-size: 13px !important;
    }

    .main {
        margin-left: 205px !important;
        width: calc(100% - 205px) !important;
        max-width: calc(100% - 205px) !important;
    }
}
</style>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <div class="logo">
        <h2>BLACKHABIT</h2>
        <p>CASHIER PANEL</p>
    </div>

    <ul class="sidebar-menu">
        <li class="<?php echo cashierActive('cashier_dashboard.php'); ?>">
            <a href="cashier_dashboard.php">
                <i class="fa-solid fa-table-cells-large"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li class="<?php echo cashierActive('cashier_pos.php'); ?>">
            <a href="cashier_pos.php">
                <i class="fa-solid fa-cash-register"></i>
                <span>POS</span>
            </a>
        </li>

        <li class="<?php echo cashierActive('cashier_order_management.php'); ?>">
            <a href="cashier_order_management.php">
                <i class="fa-solid fa-cart-shopping"></i>
                <span>Orders</span>
            </a>
        </li>

        <li class="dropdown <?php echo $inventoryOpen ? 'open' : ''; ?>">
            <a href="#" class="dropdown-btn"
               aria-expanded="<?php echo $inventoryOpen ? 'true' : 'false'; ?>">
                <i class="fa-solid fa-box"></i>
                <span>Inventory</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </a>

            <ul class="dropdown-menu">
                <li class="<?php echo cashierActive('inventory_management.php'); ?>">
                    <a href="inventory_management.php?role=cashier">
                        Inventory
                    </a>
                </li>

                <li class="<?php echo cashierActive('supplies_management.php'); ?>">
                    <a href="supplies_management.php?role=cashier">
                        Supplies
                    </a>
                </li>
            </ul>
        </li>

        <li class="logout">
            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </li>
    </ul>
</div>

<script>
(function () {
    function initCashierSidebar() {
        if (window.blackHabitCashierSidebarInitialized) return;
        window.blackHabitCashierSidebarInitialized = true;

        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', function () {
                sidebar.classList.toggle('active');

                if (overlay) {
                    overlay.classList.toggle('active');
                }
            });
        }

        if (overlay && sidebar) {
            overlay.addEventListener('click', function () {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            });
        }

        document.querySelectorAll('.sidebar .dropdown-btn').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                const parent = this.closest('.dropdown');
                if (!parent) return;

                const isOpen = parent.classList.toggle('open');

                this.setAttribute(
                    'aria-expanded',
                    isOpen ? 'true' : 'false'
                );
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initCashierSidebar,
            { once: true }
        );
    } else {
        initCashierSidebar();
    }
})();
</script>
