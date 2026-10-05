<?php
session_start();

if (!isset($_SESSION['role']) || strtolower(trim((string)$_SESSION['role'])) !== 'admin') {
    header("Location: index.php");
    exit();
}

include "db.php";

/* =========================================================
   INGREDIENT SUMMARY
   ========================================================= */

$totalIngredients = 0;
$lowStock = 0;
$totalHistory = 0;

$summaryQuery = mysqli_query($conn, "
    SELECT
        COUNT(*) AS total_ingredients,
        SUM(
            CASE
                WHEN current_stock <= minimum_stock THEN 1
                ELSE 0
            END
        ) AS low_stock
    FROM ingredients
");

if ($summaryQuery) {
    $summary = mysqli_fetch_assoc($summaryQuery);

    $totalIngredients = (int)($summary['total_ingredients'] ?? 0);
    $lowStock = (int)($summary['low_stock'] ?? 0);
}

/* =========================================================
   INGREDIENT HISTORY
   Revised ingredient_history structure:
   id
   ingredient_id
   action
   quantity
   previous_stock
   new_stock
   user_id
   created_at
   ========================================================= */

$historyQuery = mysqli_query($conn, "
    SELECT
        h.id AS history_id,
        h.ingredient_id,
        h.action,
        h.quantity,
        h.previous_stock,
        h.new_stock,
        h.user_id,
        h.created_at,
        i.ingredient_name,
        i.unit
    FROM ingredient_history h
    LEFT JOIN ingredients i
        ON i.id = h.ingredient_id

    UNION ALL

    SELECT
        si.stockin_id AS history_id,
        si.ingredient_id,
        'stock_in' AS action,
        si.quantity,
        NULL AS previous_stock,
        NULL AS new_stock,
        NULL AS user_id,
        si.date_received AS created_at,
        i.ingredient_name,
        i.unit
    FROM stock_in si
    LEFT JOIN ingredients i
        ON i.id = si.ingredient_id
    WHERE si.ingredient_id IS NOT NULL

    UNION ALL

    SELECT
        so.stockout_id AS history_id,
        so.ingredient_id,
        'stock_out' AS action,
        so.quantity,
        NULL AS previous_stock,
        NULL AS new_stock,
        NULL AS user_id,
        so.date_out AS created_at,
        i.ingredient_name,
        i.unit
    FROM stock_out so
    LEFT JOIN ingredients i
        ON i.id = so.ingredient_id
    WHERE so.ingredient_id IS NOT NULL

    ORDER BY created_at DESC, history_id DESC
    LIMIT 500
");

$historyAvailable = ($historyQuery !== false);

if ($historyAvailable) {
    $countQuery = mysqli_query(
        $conn,
        "SELECT
            (SELECT COUNT(*) FROM ingredient_history) +
            (SELECT COUNT(*) FROM stock_in WHERE ingredient_id IS NOT NULL) +
            (SELECT COUNT(*) FROM stock_out WHERE ingredient_id IS NOT NULL)
            AS total"
    );

    if ($countQuery) {
        $countRow = mysqli_fetch_assoc($countQuery);
        $totalHistory = (int)($countRow['total'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Ingredients - BLACKHABIT</title>

<link rel="icon" type="image/x-icon" href="favicon_io/favicon.ico">

<link rel="stylesheet"
      href="style.css?v=<?php echo time(); ?>">

<link rel="stylesheet"
      href="admin_sidebar.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<style>

/* =========================================================
   INGREDIENT INVENTORY
   ========================================================= */

.inventory-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    background: #171717;
    border: 1px solid #2c2c2c;
    border-radius: 13px;
    padding: 17px 19px;
    display: flex;
    align-items: center;
    gap: 13px;
}

.summary-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    background: #272116;
    color: #c59d5f;
    flex-shrink: 0;
}

.summary-card h3 {
    margin: 0;
    font-size: 21px;
}

.summary-card span {
    display: block;
    color: #858585;
    font-size: 11px;
    margin-top: 2px;
}

/* =========================================================
   STOCK STATUS
   ========================================================= */

.stock-value {
    font-weight: 600;
}

.minimum-value {
    color: #aaa;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 20px;
    padding: 5px 10px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .2px;
}

.status-badge.available {
    background: rgba(88, 157, 104, .13);
    color: #8bd59b;
}

.status-badge.low {
    background: rgba(197, 157, 95, .13);
    color: #d8b477;
}

.status-badge.unavailable {
    background: rgba(190, 78, 78, .13);
    color: #e98b8b;
}

/* =========================================================
   HISTORY
   ========================================================= */

.history-panel {
    margin-top: 18px;
}

.history-tools {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 0 0 15px;
}

.history-title h2 {
    margin: 0;
    font-size: 19px;
}

.history-title p {
    margin: 4px 0 0;
    color: #777;
    font-size: 12px;
}

.history-search {
    position: relative;
    width: min(300px, 100%);
}

.history-search i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #777;
}

.history-search input {
    width: 100%;
    box-sizing: border-box;
    background: #191919;
    color: #fff;
    border: 1px solid #303030;
    border-radius: 9px;
    padding: 10px 12px 10px 38px;
    outline: none;
    font-family: inherit;
    font-size: 12px;
}

.history-search input:focus {
    border-color: #c59d5f;
}

.history-table th,
.history-table td {
    white-space: nowrap;
}

.history-table td {
    padding-top: 13px;
    padding-bottom: 13px;
}

.history-ingredient {
    text-align: left !important;
    font-weight: 500;
}

.history-quantity {
    font-weight: 600;
}

.history-type {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 20px;
    padding: 5px 10px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .2px;
}

.history-type.stockin,
.history-type.stock_in,
.history-type.in {
    background: rgba(88, 157, 104, .13);
    color: #8bd59b;
}

.history-type.stockout,
.history-type.stock_out,
.history-type.out,
.history-type.sale,
.history-type.used {
    background: rgba(190, 78, 78, .13);
    color: #e98b8b;
}

.history-type.adjustment {
    background: rgba(197, 157, 95, .13);
    color: #d8b477;
}

.history-empty {
    text-align: center;
    padding: 35px 20px !important;
    color: #777;
}

.history-empty i {
    display: block;
    font-size: 25px;
    margin-bottom: 9px;
    color: #555;
}

/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (max-width: 900px) {

    .inventory-summary {
        grid-template-columns: 1fr;
    }

    .history-tools {
        align-items: stretch;
        flex-direction: column;
    }

    .history-search {
        width: 100%;
    }

    .table-container {
        overflow-x: auto;
    }
}

</style>
<style>

/* ==========================================================
   BLACKHABIT ADMIN - FINAL RESPONSIVE OVERRIDE
   Desktop / iPad landscape / iPad portrait / phone
   This section only changes layout; PHP/database logic is untouched.
   ========================================================== */

html, body {
    max-width: 100%;
    overflow-x: hidden;
}

.main {
    box-sizing: border-box;
    min-width: 0 !important;
    width: auto;
    max-width: 100%;
}

.main * {
    box-sizing: border-box;
}

.topbar {
    min-width: 0;
    max-width: 100%;
}

.topbar h1 {
    min-width: 0;
    overflow-wrap: anywhere;
}

.panel,
.sales-analytics,
.page-intro,
.form-container,
.section,
.dashboard-head {
    max-width: 100%;
    min-width: 0;
}

.table-container,
.formula-table-wrap,
.queue-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: auto !important;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.product-table,
.order-management-table,
.history-table,
.queue-table {
    max-width: none;
}

img, canvas, video, iframe {
    max-width: 100%;
}

button, input, select, textarea {
    max-width: 100%;
}

@media (min-width: 769px) and (max-width: 1366px) {
    .main {
        padding-left: 18px !important;
        padding-right: 18px !important;
    }

    .topbar {
        gap: 12px;
    }

    .topbar h1 {
        font-size: clamp(20px, 2.2vw, 27px) !important;
    }

    .profile {
        min-width: 0;
    }

    .cards {
        gap: 12px !important;
    }

    .panel {
        padding: 16px !important;
    }

    .table-container {
        border-radius: 8px;
    }

    .product-table th,
    .product-table td,
    .order-management-table th,
    .order-management-table td,
    .history-table th,
    .history-table td {
        padding: 9px 8px;
        font-size: 12px;
    }
}

@media (min-width: 769px) and (max-width: 1100px) {
    .cards {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .content-grid,
    .chart-grid {
        grid-template-columns: 1fr !important;
    }

    .inventory-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .sales-overview-header,
    .analytics-header-right,
    .panel-header {
        min-width: 0;
    }
}

@media (min-width: 769px) and (max-width: 900px) {
    .main {
        padding: 14px !important;
    }

    .cards {
        gap: 10px !important;
    }

    .card {
        min-width: 0 !important;
    }

    .quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .inventory-summary {
        grid-template-columns: 1fr !important;
    }

    .history-tools,
    .inventory-toolbar,
    .product-list-toolbar,
    .formula-heading {
        gap: 10px;
    }
}

@media (max-width: 768px) {
    .main {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        margin-left: 0 !important;
        padding: 12px !important;
    }

    .topbar {
        width: 100%;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 14px !important;
        padding-bottom: 12px !important;
    }

    .topbar h1 {
        flex: 1 1 160px;
        font-size: 20px !important;
        line-height: 1.25;
    }

    .profile {
        flex: 0 1 auto;
        margin-left: auto;
    }

    .profile > div {
        display: none;
    }

    .dashboard-head {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px;
    }

    .brand-quote {
        display: none !important;
    }

    .cards {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }

    .card {
        width: 100%;
        min-width: 0 !important;
    }

    .quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
    }

    .content-grid,
    .chart-grid {
        grid-template-columns: 1fr !important;
    }

    .inventory-summary {
        grid-template-columns: 1fr !important;
    }

    .inventory-toolbar,
    .history-tools,
    .product-list-toolbar,
    .formula-heading {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .history-search,
    .inventory-search-box,
    .supply-search-box {
        width: 100% !important;
    }

    .inventory-search-panel,
    .supply-search,
    .date-filter,
    .filter-form {
        width: 100%;
        gap: 8px !important;
    }

    .inventory-search-panel,
    .supply-search,
    .date-filter {
        flex-wrap: wrap;
    }

    .inventory-search-box,
    .supply-search-box {
        flex: 1 1 100%;
    }

    .inventory-search-btn,
    .inventory-reset-btn,
    .supply-search-btn,
    .supply-reset-btn,
    .add-ingredient-btn,
    .add-supply-trigger,
    .btn-new-product {
        min-height: 40px;
    }

    .table-container {
        overflow-x: auto !important;
        overflow-y: hidden;
    }

    .product-table,
    .order-management-table,
    .history-table,
    .queue-table {
        width: max-content !important;
        min-width: 700px;
    }

    .product-table th,
    .product-table td,
    .history-table th,
    .history-table td {
        white-space: nowrap;
        font-size: 11px;
        padding: 9px 7px;
    }

    .order-management-table {
        min-width: 950px !important;
    }

    .order-management-table th,
    .order-management-table td {
        font-size: 11px;
        padding: 9px 7px;
        white-space: nowrap;
    }

    .date-filter {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .date-filter > div,
    .date-actions {
        width: 100% !important;
        margin-left: 0 !important;
    }

    .date-filter input,
    .date-actions .btn {
        width: 100% !important;
    }

    .order-filters {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px !important;
    }

    .filter-btn {
        width: 100%;
        min-width: 0 !important;
        white-space: normal !important;
        text-align: center;
        padding: 9px 6px !important;
    }

    .form-grid {
        grid-template-columns: 1fr !important;
    }

    .formula-row {
        grid-template-columns: 1fr 1fr 1fr 40px !important;
    }

    .add-product-dialog,
    .supply-modal-dialog {
        width: min(96vw, 700px) !important;
        max-width: 96vw !important;
        max-height: calc(100vh - 20px);
        overflow-y: auto;
    }

    .supply-modal-form-grid {
        grid-template-columns: 1fr !important;
    }

    .sales-analytics {
        padding: 14px !important;
    }

    .sales-overview-header,
    .analytics-header-right {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
    }

    .sales-range-form,
    .sales-range-form select,
    .graph-toggle-btn,
    .custom-apply-btn {
        width: 100% !important;
        margin-left: 0 !important;
    }

    .custom-date-fields {
        flex-wrap: wrap !important;
    }

    .custom-date-fields input {
        width: 100% !important;
        flex: 1 1 130px;
    }

    .chart-container {
        height: 280px !important;
        padding: 12px !important;
    }

    .graph-canvas-wrap {
        height: 270px !important;
    }

    .sales-graph-container {
        padding: 12px !important;
    }

    .page-intro {
        padding: 14px !important;
    }

    .page-intro h2 {
        font-size: 17px !important;
    }
}

@media (max-width: 480px) {
    .main {
        padding: 9px !important;
    }

    .topbar h1 {
        font-size: 18px !important;
    }

    .quick-grid {
        grid-template-columns: 1fr !important;
    }

    .order-filters {
        grid-template-columns: 1fr !important;
    }

    .panel {
        padding: 10px !important;
    }

    .panel-header h2 {
        font-size: 16px !important;
    }

    .product-table,
    .history-table {
        min-width: 650px !important;
    }

    .order-management-table {
        min-width: 900px !important;
    }

    .formula-row {
        grid-template-columns: 1fr 1fr 40px !important;
    }

    .formula-row select {
        grid-column: 1 / -1;
    }
}

</style>

</head>

<body>

<?php include "admin_sidebar.php"; ?>

<div class="main">

    <!-- =====================================================
         TOP BAR
         ===================================================== -->

    <div class="topbar">

        <button class="menu-toggle" id="menuToggle">
            <i class="fa-solid fa-bars"></i>
        </button>

        <h1>Ingredient Inventory</h1>

        <div class="profile">

            <i class="fa-solid fa-user-shield"
               style="font-size:24px;color:#c59d5f;"></i>

            <div>

                <h3>
                    <?php
                    echo htmlspecialchars(
                        $_SESSION['name'] ?? 'Admin'
                    );
                    ?>
                </h3>

                <span>Administrator</span>

            </div>

        </div>

    </div>


    <!-- =====================================================
         SUMMARY
         ===================================================== -->

    <div class="inventory-summary">

        <div class="summary-card">

            <div class="summary-icon">
                <i class="fa-solid fa-seedling"></i>
            </div>

            <div>

                <h3>
                    <?php echo $totalIngredients; ?>
                </h3>

                <span>Total Ingredients</span>

            </div>

        </div>


        <div class="summary-card">

            <div class="summary-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>

            <div>

                <h3>
                    <?php echo $lowStock; ?>
                </h3>

                <span>Low / Not Available</span>

            </div>

        </div>


        <div class="summary-card">

            <div class="summary-icon">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>

            <div>

                <h3>
                    <?php echo $totalHistory; ?>
                </h3>

                <span>Ingredient Transactions</span>

            </div>

        </div>

    </div>


    <!-- =====================================================
         CURRENT INGREDIENT STOCK
         ===================================================== -->

    <div class="panel">

        <div class="panel-header">

            <h2>
                <i class="fa-solid fa-boxes-stacked"></i>
                Current Ingredient Stock
            </h2>

        </div>


        <div class="table-container">

            <table class="product-table">

                <thead>

                    <tr>

                        <th>ID</th>

                        <th>Ingredient Name</th>

                        <th>Current Stock</th>

                        <th>Minimum Stock</th>

                        <th>Status</th>

                    </tr>

                </thead>


                <tbody>

                <?php

                $query = mysqli_query(
                    $conn,
                    "
                    SELECT
                        id,
                        ingredient_name,
                        current_stock,
                        minimum_stock,
                        unit
                    FROM ingredients
                    ORDER BY ingredient_name ASC
                    "
                );

                if ($query && mysqli_num_rows($query) > 0) {

                    while ($row = mysqli_fetch_assoc($query)) {

                        $stock = (float)(
                            $row['current_stock'] ?? 0
                        );

                        $minimum = (float)(
                            $row['minimum_stock'] ?? 0
                        );

                        $unit = $row['unit'] ?? '';

                        /*
                         * Availability rule:
                         * current stock <= minimum stock
                         * means the ingredient is no longer
                         * safely available for production.
                         */

                        if ($stock <= 0) {

                            $status = 'Not Available';
                            $statusClass = 'unavailable';
                            $statusIcon = 'fa-circle-xmark';

                        } elseif ($stock <= $minimum) {

                            $status = 'Low Stock';
                            $statusClass = 'low';
                            $statusIcon = 'fa-triangle-exclamation';

                        } else {

                            $status = 'Available';
                            $statusClass = 'available';
                            $statusIcon = 'fa-circle-check';

                        }

                ?>

                    <tr>

                        <td>
                            #<?php
                            echo (int)$row['id'];
                            ?>
                        </td>


                        <td class="history-ingredient">

                            <?php
                            echo htmlspecialchars(
                                $row['ingredient_name'] ?? ''
                            );
                            ?>

                        </td>


                        <td>

                            <strong class="stock-value">

                                <?php
                                echo number_format(
                                    $stock,
                                    0
                                );
                                ?>

                            </strong>

                            <?php
                            echo htmlspecialchars($unit);
                            ?>

                        </td>


                        <td>

                            <strong class="minimum-value">

                                <?php
                                echo number_format(
                                    $minimum,
                                    0
                                );
                                ?>

                            </strong>

                            <?php
                            echo htmlspecialchars($unit);
                            ?>

                        </td>


                        <td>

                            <span class="status-badge <?php
                                echo htmlspecialchars(
                                    $statusClass
                                );
                            ?>">

                                <i class="fa-solid <?php
                                    echo htmlspecialchars(
                                        $statusIcon
                                    );
                                ?>"></i>

                                <?php
                                echo htmlspecialchars($status);
                                ?>

                            </span>

                        </td>

                    </tr>

                <?php

                    }

                } else {

                ?>

                    <tr>

                        <td colspan="5"
                            class="history-empty">

                            <i class="fa-solid fa-box-open"></i>

                            No ingredients recorded yet.

                        </td>

                    </tr>

                <?php } ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- =====================================================
         INGREDIENT HISTORY
         ===================================================== -->

    <div class="panel history-panel">

        <div class="history-tools">

            <div class="history-title">

                <h2>

                    <i class="fa-solid fa-clock-rotate-left"></i>

                    Ingredient History Log

                </h2>

                <p>
                    Automatic record of ingredient usage
                    and inventory transactions.
                </p>

            </div>


            <div class="history-search">

                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="search"
                    id="historySearch"
                    placeholder="Search ingredient..."
                    autocomplete="off"
                >

            </div>

        </div>


        <div class="table-container">

            <table
                class="product-table history-table"
                id="historyTable"
            >

                <thead>

                    <tr>

                        <th>Date & Time</th>

                        <th>Ingredient</th>

                        <th>Quantity</th>

                        <th>Unit</th>

                        <th>Transaction</th>

                    </tr>

                </thead>


                <tbody>

                <?php

                if (
                    $historyAvailable &&
                    mysqli_num_rows($historyQuery) > 0
                ) {

                    while (
                        $history =
                        mysqli_fetch_assoc($historyQuery)
                    ) {

                        $action = strtolower(
                            trim(
                                (string)(
                                    $history['action'] ?? ''
                                )
                            )
                        );

                        if ($action === '') {
                            $action = 'adjustment';
                        }


                        $typeClass = preg_replace(
                            '/[^a-z_]/',
                            '',
                            str_replace(
                                ' ',
                                '_',
                                $action
                            )
                        );

                        if ($typeClass === '') {
                            $typeClass = 'adjustment';
                        }


                        $quantity = (float)(
                            $history['quantity'] ?? 0
                        );


                        $displayAction = ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $action
                            )
                        );


                        /*
                         * Determine icon.
                         */

                        if (
                            in_array(
                                $action,
                                [
                                    'stock in',
                                    'stock_in',
                                    'in',
                                    'restock'
                                ],
                                true
                            )
                        ) {

                            $icon = 'fa-arrow-up';

                        } elseif (
                            in_array(
                                $action,
                                [
                                    'stock out',
                                    'stock_out',
                                    'out',
                                    'sale',
                                    'used',
                                    'usage'
                                ],
                                true
                            )
                        ) {

                            $icon = 'fa-arrow-down';

                        } else {

                            $icon = 'fa-right-left';

                        }

                ?>

                    <tr
                        class="history-row"
                        data-ingredient="<?php
                            echo htmlspecialchars(
                                strtolower(
                                    $history[
                                        'ingredient_name'
                                    ] ?? ''
                                ),
                                ENT_QUOTES
                            );
                        ?>"
                    >

                        <td>

                            <?php

                            $timestamp = strtotime(
                                $history['created_at']
                                ?? ''
                            );

                            if ($timestamp) {

                                echo htmlspecialchars(
                                    date(
                                        'M d, Y h:i A',
                                        $timestamp
                                    )
                                );

                            } else {

                                echo '—';

                            }

                            ?>

                        </td>


                        <td class="history-ingredient">

                            <?php
                            echo htmlspecialchars(
                                $history[
                                    'ingredient_name'
                                ] ?? 'Unknown'
                            );
                            ?>

                        </td>


                        <td class="history-quantity">

                            <?php

                            /*
                             * Stock-in is positive.
                             * Stock-out / usage is negative.
                             */

                            $isStockIn = in_array(
                                $action,
                                [
                                    'stock in',
                                    'stock_in',
                                    'in',
                                    'restock'
                                ],
                                true
                            );

                            if ($isStockIn) {

                                echo '+';

                            } else {

                                echo '-';

                            }

                            echo number_format(
                                abs($quantity),
                                0
                            );

                            ?>

                        </td>


                        <td>

                            <?php
                            echo htmlspecialchars(
                                $history['unit'] ?? ''
                            );
                            ?>

                        </td>


                        <td>

                            <span class="history-type <?php
                                echo htmlspecialchars(
                                    $typeClass
                                );
                            ?>">

                                <i class="fa-solid <?php
                                    echo htmlspecialchars(
                                        $icon
                                    );
                                ?>"></i>

                                <?php
                                echo htmlspecialchars(
                                    $displayAction
                                );
                                ?>

                            </span>

                        </td>

                    </tr>

                <?php

                    }

                } else {

                ?>

                    <tr id="historyEmptyRow">

                        <td
                            colspan="5"
                            class="history-empty"
                        >

                            <i class="fa-solid fa-clock-rotate-left"></i>

                            No ingredient history recorded yet.

                        </td>

                    </tr>

                <?php } ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<script>

/* =========================================================
   INGREDIENT HISTORY SEARCH
   ========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const search =
            document.getElementById(
                "historySearch"
            );

        const rows =
            document.querySelectorAll(
                ".history-row"
            );

        if (!search) {
            return;
        }


        search.addEventListener(
            "input",
            function () {

                const keyword =
                    this.value
                        .trim()
                        .toLowerCase();

                let visible = 0;


                rows.forEach(
                    function (row) {

                        const ingredient =
                            row.dataset.ingredient
                            || "";

                        if (
                            !keyword ||
                            ingredient.includes(keyword)
                        ) {

                            row.style.display = "";

                            visible++;

                        } else {

                            row.style.display =
                                "none";

                        }

                    }
                );


                const oldEmpty =
                    document.getElementById(
                        "historySearchEmpty"
                    );

                if (oldEmpty) {
                    oldEmpty.remove();
                }


                if (
                    visible === 0 &&
                    rows.length > 0
                ) {

                    const tbody =
                        document.querySelector(
                            "#historyTable tbody"
                        );

                    if (!tbody) {
                        return;
                    }


                    const tr =
                        document.createElement(
                            "tr"
                        );

                    tr.id =
                        "historySearchEmpty";


                    tr.innerHTML = `

                        <td colspan="5"
                            class="history-empty">

                            <i class="fa-solid fa-magnifying-glass"></i>

                            No matching ingredient history found.

                        </td>

                    `;


                    tbody.appendChild(tr);

                }

            }
        );

    }
);

</script>

</body>
</html>