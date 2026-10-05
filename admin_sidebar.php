<?php
// BLACKHABIT ADMIN SIDEBAR

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

function adminActive($pages) {
    global $currentPage;
    return in_array($currentPage, (array)$pages, true) ? 'active' : '';
}

$ordersOpen = ($currentPage === 'admin_order_management.php');

$reportsOpen = in_array($currentPage, [
    'sales_report.php',
    'stock_in.php',
    'stock_out.php'
], true);

$inventoryOpen = in_array($currentPage, [
    'inventory_management.php',
    'ingredient_management.php',
    'supplies_management.php',
    'recipe_management.php',
    'recipe_details.php'
], true);
?>

<style>
/* ==========================================================
   BLACKHABIT ADMIN SIDEBAR
   Responsive: Desktop / iPad / Tablet Landscape / Phone
   ========================================================== */

.sidebar {
    width: 250px;
    max-width: 250px;
    height: 100vh;
    max-height: 100vh;
    box-sizing: border-box;
    overflow: hidden;
}

.sidebar-menu {
    width: 100%;
    box-sizing: border-box;
}

/* Keep BLACKHABIT logo on one line */
.sidebar .logo h2 {
    white-space: nowrap;
    overflow: visible;
    text-align: center;
    font-size: 22px;
    line-height: 1.1;
    letter-spacing: 1px;
}

.sidebar-menu > li > a,
.sidebar-menu .dropdown-btn {
    box-sizing: border-box;
    min-height: 40px;
    padding-top: 8px;
    padding-bottom: 8px;
}

.sidebar-menu .dropdown-menu {
    box-sizing: border-box;
    width: 100%;
}

/* Keep dropdowns inside the sidebar */
.sidebar .dropdown {
    position: relative;
}

.sidebar .dropdown-menu {
    overflow: hidden;
    max-height: 0;
    opacity: 0;
    visibility: hidden;
    transition: max-height .22s ease, opacity .18s ease;
}

.sidebar .dropdown.open > .dropdown-menu {
    max-height: 500px;
    opacity: 1;
    visibility: visible;
}

.sidebar .dropdown-btn .arrow {
    margin-left: auto;
    transition: transform .2s ease;
}

.sidebar .dropdown.open > .dropdown-btn .arrow {
    transform: rotate(180deg);
}

/* Active dropdown parent */
.sidebar .dropdown.open > .dropdown-btn {
    color: inherit;
}

/* ==========================================================
   TABLET LANDSCAPE
   ========================================================== */
@media (min-width: 768px) and (max-width: 1366px) and (orientation: landscape) {

    .sidebar {
        width: 220px;
        max-width: 220px;
    }

    .sidebar .logo {
        padding: 14px 14px;
    }

    .sidebar .logo h2 {
        font-size: 20px;
        letter-spacing: .4px;
        white-space: nowrap;
    }

    .sidebar .logo p {
        font-size: 10px;
    }

    .sidebar-menu {
        padding-left: 10px;
        padding-right: 10px;
    }

    .sidebar-menu > li > a,
    .sidebar-menu .dropdown-btn {
        min-height: 39px;
        padding: 7px 11px;
        font-size: 13px;
    }

    .sidebar-menu > li > a i:first-child,
    .sidebar-menu .dropdown-btn > i:first-child {
        width: 18px;
        min-width: 18px;
        font-size: 14px;
    }

    .sidebar-menu .dropdown-menu a {
        padding: 6px 10px 6px 39px;
        font-size: 12px;
    }

    .sidebar-menu .logout {
        margin-top: 12px;
    }
}

/* ==========================================================
   SMALL TABLETS / PORTRAIT TABLETS
   ========================================================== */
@media (min-width: 768px) and (max-width: 1024px) {

    .sidebar {
        width: 215px;
        max-width: 215px;
    }

    .sidebar .logo h2 {
        font-size: 19px;
        white-space: nowrap;
    }

    .sidebar-menu > li > a,
    .sidebar-menu .dropdown-btn {
        font-size: 12px;
    }
}

/* ==========================================================
   PHONE
   ========================================================== */
@media (max-width: 767px) {

    .sidebar {
        width: min(285px, 82vw);
        max-width: 285px;
        height: 100vh;
        max-height: 100vh;
        transform: translateX(-105%);
        transition: transform .25s ease;
        z-index: 1100;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .sidebar.active {
        transform: translateX(0);
    }

    .sidebar-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.58);
        z-index: 1090;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity .2s ease;
    }

    .sidebar-overlay.active {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .sidebar .logo {
        padding: 18px 16px;
    }

    .sidebar .logo h2 {
        font-size: 20px;
        white-space: nowrap;
    }

    .sidebar-menu {
        padding-left: 10px;
        padding-right: 10px;
    }

    .sidebar-menu > li > a,
    .sidebar-menu .dropdown-btn {
        min-height: 45px;
        padding: 10px 12px;
    }

    .sidebar-menu .dropdown-menu a {
        padding: 9px 10px 9px 42px;
    }
}

/* Prevent sidebar text from causing horizontal overflow */
.sidebar span,
.sidebar a {
    min-width: 0;
}

.sidebar a span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">

    <div class="logo">
        <h2>BLACKHABIT</h2>
        <p>ADMIN PANEL</p>
    </div>

    <ul class="sidebar-menu">

        <!-- DASHBOARD -->
        <li class="<?php echo adminActive('admin_dashboard.php'); ?>">
            <a href="admin_dashboard.php">
                <i class="fa-solid fa-table-cells-large"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- ORDERS -->
        <li class="dropdown <?php echo $ordersOpen ? 'open' : ''; ?>">
            <a href="#"
               class="dropdown-btn"
               data-menu="ordersMenu"
               aria-expanded="<?php echo $ordersOpen ? 'true' : 'false'; ?>">
                <i class="fa-solid fa-ellipsis"></i>
                <span>Orders</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </a>

            <ul class="dropdown-menu" id="ordersMenu">
                <li class="<?php
                    echo (
                        $currentPage === 'admin_order_management.php'
                        && empty($_GET['status'])
                    ) ? 'active' : '';
                ?>">
                    <a href="admin_order_management.php">
                        All Orders
                    </a>
                </li>
            </ul>
        </li>

        <!-- PRODUCTS -->
        <li class="<?php echo adminActive('product_management.php'); ?>">
            <a href="product_management.php">
                <i class="fa-solid fa-mug-hot"></i>
                <span>Products</span>
            </a>
        </li>

        <!-- INVENTORIES -->
        <li class="dropdown <?php echo $inventoryOpen ? 'open' : ''; ?>">
            <a href="#"
               class="dropdown-btn"
               data-menu="inventoryMenu"
               aria-expanded="<?php echo $inventoryOpen ? 'true' : 'false'; ?>">
                <i class="fa-solid fa-box"></i>
                <span>Inventories</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </a>

            <ul class="dropdown-menu" id="inventoryMenu">

                <li class="<?php
                    echo adminActive([
                        'inventory_management.php',
                        'ingredient_management.php'
                    ]);
                ?>">
                    <a href="inventory_management.php?role=admin">
                        Ingredients
                    </a>
                </li>

                <li class="<?php echo adminActive('supplies_management.php'); ?>">
                    <a href="supplies_management.php">
                        Supplies
                    </a>
                </li>

                <li class="<?php
                    echo adminActive([
                        'recipe_management.php',
                        'recipe_details.php'
                    ]);
                ?>">
                    <a href="recipe_management.php">
                        Recipes
                    </a>
                </li>

            </ul>
        </li>

        <!-- REPORTS -->
        <li class="dropdown <?php echo $reportsOpen ? 'open' : ''; ?>">
            <a href="#"
               class="dropdown-btn"
               data-menu="reportsMenu"
               aria-expanded="<?php echo $reportsOpen ? 'true' : 'false'; ?>">
                <i class="fa-solid fa-file-lines"></i>
                <span>Reports</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </a>

            <ul class="dropdown-menu" id="reportsMenu">

                <li class="<?php echo adminActive('sales_report.php'); ?>">
                    <a href="sales_report.php">
                        Sales Report
                    </a>
                </li>

            </ul>
        </li>

        <!-- MANAGE ACCOUNTS -->
        <li class="<?php echo adminActive('manage_account.php'); ?>">
            <a href="manage_account.php">
                <i class="fa-solid fa-users"></i>
                <span>Manage Accounts</span>
            </a>
        </li>

        <!-- LOGOUT -->
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
    function initAdminSidebar() {

        if (window.blackHabitAdminSidebarInitialized) {
            return;
        }

        window.blackHabitAdminSidebarInitialized = true;

        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (!sidebar) {
            return;
        }

        /* MOBILE SIDEBAR */
        if (menuToggle) {
            menuToggle.addEventListener('click', function () {
                sidebar.classList.toggle('active');

                if (overlay) {
                    overlay.classList.toggle('active');
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function () {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            });
        }

        /* DROPDOWNS */
        sidebar.querySelectorAll('.dropdown-btn').forEach(function (button) {

            button.addEventListener('click', function (event) {

                event.preventDefault();

                const parent = this.closest('.dropdown');

                if (!parent) {
                    return;
                }

                const isOpen = parent.classList.toggle('open');

                this.setAttribute(
                    'aria-expanded',
                    isOpen ? 'true' : 'false'
                );
            });

        });

        /* Close mobile sidebar after selecting a normal page */
        sidebar.querySelectorAll('a[href]').forEach(function (link) {

            link.addEventListener('click', function () {

                if (this.classList.contains('dropdown-btn')) {
                    return;
                }

                if (window.innerWidth <= 767) {

                    sidebar.classList.remove('active');

                    if (overlay) {
                        overlay.classList.remove('active');
                    }

                }

            });

        });

    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initAdminSidebar,
            { once: true }
        );
    } else {
        initAdminSidebar();
    }

})();
</script>
