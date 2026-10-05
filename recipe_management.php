<?php
// BLACKHABIT: Recipe Management uses the independent admin session.
session_name('BH_ADMIN_SESSION');
session_start();

if (
    !isset($_SESSION['role']) ||
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header("Location: index.php");
    exit();
}

include "db.php";

/* =====================================================
   HELPERS
   ===================================================== */

function qty($value)
{
    if ($value === null || $value === '') {
        return 0;
    }

    $value = (float)$value;
    return $value < 0 ? 0 : $value;
}

/*
 * Convert recipe quantity to the database unit used by
 * the ingredient stock.
 *
 * The API uses the same conversion rules:
 * kg  -> grams in recipe are converted to kg
 * L   -> milliliters in recipe are converted to liters
 * g/ml/pc -> unchanged
 */
function recipeQuantityForUnit($recipeQty, $unit)
{
    $recipeQty = qty($recipeQty);
    $unit = strtolower(trim((string)$unit));

    if ($recipeQty <= 0) {
        return 0;
    }

    switch ($unit) {
        case 'kg':
        case 'kgs':
        case 'kilogram':
        case 'kilograms':
            return $recipeQty > 1 ? $recipeQty / 1000 : $recipeQty;

        case 'l':
        case 'lt':
        case 'liter':
        case 'liters':
        case 'litre':
        case 'litres':
            return $recipeQty > 1 ? $recipeQty / 1000 : $recipeQty;

        case 'g':
        case 'gram':
        case 'grams':
        case 'ml':
        case 'milliliter':
        case 'milliliters':
        case 'millilitre':
        case 'millilitres':
        case 'pc':
        case 'pcs':
        case 'piece':
        case 'pieces':
        case 'unit':
        case 'units':
            return $recipeQty;

        default:
            return $recipeQty;
    }
}

/*
 * Returns how many products can be produced from a stock
 * quantity using the required quantity for one product.
 */
function productionCapacity($stock, $required)
{
    $stock = qty($stock);
    $required = qty($required);

    if ($required <= 0) {
        return PHP_INT_MAX;
    }

    return (int)floor($stock / $required);
}

function tableExists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);

    $result = mysqli_query(
        $conn,
        "SHOW TABLES LIKE '$table'"
    );

    return $result && mysqli_num_rows($result) > 0;
}

function columnExists($conn, $table, $column)
{
    if (!tableExists($conn, $table)) {
        return false;
    }

    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);

    $result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM `$table` LIKE '$column'"
    );

    return $result && mysqli_num_rows($result) > 0;
}

function findColumn($conn, $table, $columns)
{
    foreach ($columns as $column) {
        if (columnExists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

/* =====================================================
   DETECT DATABASE COLUMNS
   ===================================================== */

$hasRecipes = tableExists($conn, "recipes");
$hasIngredients = tableExists($conn, "ingredients");
$hasProductSupplies = tableExists($conn, "product_supplies");
$hasSupplies = tableExists($conn, "supplies");

$recipeProductColumn = findColumn(
    $conn,
    "recipes",
    ["product_id"]
);

$recipeIngredientColumn = findColumn(
    $conn,
    "recipes",
    ["ingredient_id"]
);

$recipeRegularColumn = findColumn(
    $conn,
    "recipes",
    ["regular_qty", "quantity", "qty", "regular_quantity"]
);

$recipeLargeColumn = findColumn(
    $conn,
    "recipes",
    ["large_qty", "large_quantity"]
);

$ingredientStockColumn = findColumn(
    $conn,
    "ingredients",
    ["current_stock", "stock", "quantity", "available_stock"]
);

$ingredientMinimumColumn = findColumn(
    $conn,
    "ingredients",
    ["minimum_stock", "stock_limit", "minimum_quantity"]
);

$productSupplyProductColumn = findColumn(
    $conn,
    "product_supplies",
    ["product_id"]
);

$productSupplySupplyColumn = findColumn(
    $conn,
    "product_supplies",
    ["supply_id"]
);

$productSupplyRegularColumn = findColumn(
    $conn,
    "product_supplies",
    ["regular_qty", "quantity", "qty", "regular_quantity"]
);

$productSupplyLargeColumn = findColumn(
    $conn,
    "product_supplies",
    ["large_qty", "large_quantity"]
);

$supplyStockColumn = findColumn(
    $conn,
    "supplies",
    ["current_stock", "stock", "quantity", "available_stock"]
);

$supplyMinimumColumn = findColumn(
    $conn,
    "supplies",
    ["minimum_stock", "stock_limit", "minimum_quantity"]
);

/* =====================================================
   GET PRODUCTS
   ===================================================== */

$products = mysqli_query(
    $conn,
    "SELECT
        p.id,
        p.name,
        p.category,
        p.regular_price,
        p.large_price
     FROM products p
     ORDER BY p.name ASC"
);
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Recipe Management - BLACKHABIT</title>

<link
    rel="icon"
    type="image/x-icon"
    href="favicon_io/favicon.ico"
>

<link
    rel="stylesheet"
    href="style.css?v=<?php echo time(); ?>"
>

<link
    rel="stylesheet"
    href="admin_sidebar.css"
>

<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
>

<style>

.page-intro {
    background: #141414;
    border: 1px solid #2e2e2e;
    border-radius: 10px;
    padding: 18px 20px;
    margin-bottom: 22px;
    color: #aaa;
}

.page-intro h2 {
    margin: 0 0 6px;
    color: #c59d5f;
    font-size: 18px;
}

.page-intro p {
    margin: 0;
    font-size: 13px;
    line-height: 1.6;
}

.status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.status.ready {
    background: #d4edda;
    color: #155724;
}

.status.missing {
    background: #fff3cd;
    color: #856404;
}

.status.low {
    background: #fff3cd;
    color: #856404;
}

.status.out {
    background: #f8d7da;
    color: #721c24;
}

.view-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #c59d5f;
    color: #111;
    text-decoration: none;
    padding: 8px 12px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 12px;
}

.view-btn:hover {
    opacity: .9;
}

.count {
    font-weight: 700;
    color: #fff;
}

.empty {
    text-align: center;
    padding: 35px;
    color: #999;
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

    <div class="topbar">

        <button
            class="menu-toggle"
            id="menuToggle"
        >
            <i class="fa-solid fa-bars"></i>
        </button>

        <h1>Recipe Management</h1>

        <div class="profile">

            <i
                class="fa-solid fa-user-shield"
                style="font-size:24px;color:#c59d5f;"
            ></i>

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

    <div class="page-intro">

        <h2>
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            Automatic Product Formulas
        </h2>

        <p>
            Each product formula includes both ingredients and supplies.
            The saved recipe is used to calculate the materials required
            for every ordered quantity. Recipe status automatically
            reflects whether the current stock is enough to produce the
            product. If any required ingredient or supply is insufficient,
            the product is marked Low Stock or Not Available.
        </p>

    </div>

    <div class="panel">

        <div class="panel-header">

            <h2>
                <i class="fa-solid fa-book-open"></i>
                Product Formulations
            </h2>

        </div>

        <div class="table-container">

            <table class="product-table">

                <thead>

                    <tr>

                        <th>PRODUCT</th>

                        <th>CATEGORY</th>

                        <th>INGREDIENTS</th>

                        <th>SUPPLIES</th>

                        <th>STATUS</th>

                        <th>ACTION</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (
                    $products &&
                    mysqli_num_rows($products) > 0
                ): ?>

                    <?php while ($row = mysqli_fetch_assoc($products)): ?>

                        <?php

                        $productId = (int)$row['id'];

                        /*
                         * =================================================
                         * COUNT FORMULA ITEMS
                         * =================================================
                         */

                        $ingredientCount = 0;
                        $supplyCount = 0;

                        if (
                            $hasRecipes &&
                            $recipeProductColumn !== null &&
                            $recipeIngredientColumn !== null
                        ) {

                            $countSql = "
                                SELECT COUNT(*)
                                FROM recipes
                                WHERE `$recipeProductColumn` = $productId
                            ";

                            $countResult = mysqli_query(
                                $conn,
                                $countSql
                            );

                            if ($countResult) {

                                $countRow =
                                    mysqli_fetch_row($countResult);

                                $ingredientCount =
                                    (int)($countRow[0] ?? 0);

                                mysqli_free_result($countResult);
                            }
                        }

                        if (
                            $hasProductSupplies &&
                            $productSupplyProductColumn !== null
                        ) {

                            $countSql = "
                                SELECT COUNT(*)
                                FROM product_supplies
                                WHERE `$productSupplyProductColumn` = $productId
                            ";

                            $countResult = mysqli_query(
                                $conn,
                                $countSql
                            );

                            if ($countResult) {

                                $countRow =
                                    mysqli_fetch_row($countResult);

                                $supplyCount =
                                    (int)($countRow[0] ?? 0);

                                mysqli_free_result($countResult);
                            }
                        }

                        /*
                         * =================================================
                         * FORMULA COMPLETENESS
                         * =================================================
                         */

                        $hasFormula =
                            $ingredientCount > 0 &&
                            $supplyCount > 0;

                        /*
                         * =================================================
                         * STOCK FLAGS
                         *
                         * These are based on the actual quantity required
                         * by the recipe, NOT only minimum_stock.
                         *
                         * Example:
                         * Current stock = 250
                         * Recipe requires = 300
                         * Result = LOW STOCK
                         * =================================================
                         */

                        $hasOutOfStock = false;
                        $hasLowStock = false;

                        /*
                         * =================================================
                         * INGREDIENT STOCK CHECK
                         * =================================================
                         */

                        if (
                            $hasRecipes &&
                            $recipeProductColumn !== null &&
                            $recipeIngredientColumn !== null &&
                            $recipeRegularColumn !== null &&
                            $hasIngredients &&
                            $ingredientStockColumn !== null
                        ) {

                            $recipeSql = "
                                SELECT
                                    `$recipeIngredientColumn` AS ingredient_id,
                                    `$recipeRegularColumn` AS regular_qty
                            ";

                            if ($recipeLargeColumn !== null) {

                                $recipeSql .= ",
                                    `$recipeLargeColumn` AS large_qty
                                ";

                            } else {

                                $recipeSql .= ",
                                    0 AS large_qty
                                ";
                            }

                            $recipeSql .= "
                                FROM recipes
                                WHERE `$recipeProductColumn` = $productId
                            ";

                            $recipeResult = mysqli_query(
                                $conn,
                                $recipeSql
                            );

                            if ($recipeResult) {

                                while (
                                    $recipe =
                                    mysqli_fetch_assoc($recipeResult)
                                ) {

                                    $ingredientId = (int)(
                                        $recipe['ingredient_id'] ?? 0
                                    );

                                    $regularQty = qty(
                                        $recipe['regular_qty'] ?? 0
                                    );

                                    $largeQty = qty(
                                        $recipe['large_qty'] ?? 0
                                    );

                                    if (
                                        $ingredientId <= 0 ||
                                        ($regularQty <= 0 &&
                                         $largeQty <= 0)
                                    ) {
                                        continue;
                                    }

                                    $minimumStockSelect = "0";

                                    if ($ingredientMinimumColumn !== null) {
                                        $minimumStockSelect =
                                            "`$ingredientMinimumColumn`";
                                    }

                                    $ingredientSql = "
                                        SELECT
                                            `$ingredientStockColumn`
                                                AS current_stock,

                                            COALESCE(
                                                $minimumStockSelect,
                                                0
                                            ) AS minimum_stock,

                                            unit AS ingredient_unit

                                        FROM ingredients

                                        WHERE id = $ingredientId

                                        LIMIT 1
                                    ";

                                    $ingredientResult = mysqli_query(
                                        $conn,
                                        $ingredientSql
                                    );

                                    if (!$ingredientResult) {
                                        continue;
                                    }

                                    $ingredient =
                                        mysqli_fetch_assoc(
                                            $ingredientResult
                                        );

                                    mysqli_free_result(
                                        $ingredientResult
                                    );

                                    /*
                                     * Missing ingredient record:
                                     * product cannot be produced.
                                     */
                                    if (!$ingredient) {

                                        $hasOutOfStock = true;
                                        break;
                                    }

                                    $ingredientStock = qty(
                                        $ingredient['current_stock'] ?? 0
                                    );

                                    $ingredientUnit = strtolower(
                                        trim(
                                            (string)(
                                                $ingredient['ingredient_unit']
                                                ?? ''
                                            )
                                        )
                                    );

                                    /*
                                     * Convert recipe quantity to the
                                     * ingredient stock unit.
                                     */
                                    $regularRequired =
                                        recipeQuantityForUnit(
                                            $regularQty,
                                            $ingredientUnit
                                        );

                                    $largeRequired =
                                        recipeQuantityForUnit(
                                            $largeQty,
                                            $ingredientUnit
                                        );

                                    /*
                                     * =================================================
                                     * REGULAR SIZE CHECK
                                     * =================================================
                                     */

                                    if ($regularQty > 0) {

                                        if (
                                            $ingredientStock <= 0
                                        ) {

                                            $hasOutOfStock = true;
                                            break;
                                        }

                                        if (
                                            $ingredientStock <
                                            $regularRequired
                                        ) {

                                            $hasLowStock = true;
                                        }
                                    }

                                    /*
                                     * =================================================
                                     * LARGE SIZE CHECK
                                     *
                                     * If a product has a large recipe and there
                                     * is not enough stock for one large product,
                                     * the product cannot be ordered.
                                     * =================================================
                                     */

                                    if ($largeQty > 0) {

                                        if (
                                            $ingredientStock <= 0
                                        ) {

                                            $hasOutOfStock = true;
                                            break;
                                        }

                                        if (
                                            $ingredientStock <
                                            $largeRequired
                                        ) {

                                            $hasLowStock = true;
                                        }
                                    }

                                }

                                mysqli_free_result(
                                    $recipeResult
                                );
                            }
                        }

                        /*
                         * =================================================
                         * SUPPLY STOCK CHECK
                         * =================================================
                         */

                        if (
                            !$hasOutOfStock &&
                            $hasProductSupplies &&
                            $productSupplyProductColumn !== null &&
                            $productSupplySupplyColumn !== null &&
                            $productSupplyRegularColumn !== null &&
                            $hasSupplies &&
                            $supplyStockColumn !== null
                        ) {

                            $supplySql = "
                                SELECT
                                    `$productSupplySupplyColumn`
                                        AS supply_id,

                                    `$productSupplyRegularColumn`
                                        AS regular_qty
                            ";

                            if ($productSupplyLargeColumn !== null) {

                                $supplySql .= ",
                                    `$productSupplyLargeColumn`
                                        AS large_qty
                                ";

                            } else {

                                $supplySql .= ",
                                    0 AS large_qty
                                ";
                            }

                            $supplySql .= "
                                FROM product_supplies
                                WHERE `$productSupplyProductColumn`
                                      = $productId
                            ";

                            $supplyResult = mysqli_query(
                                $conn,
                                $supplySql
                            );

                            if ($supplyResult) {

                                while (
                                    $supply =
                                    mysqli_fetch_assoc($supplyResult)
                                ) {

                                    $supplyId = (int)(
                                        $supply['supply_id'] ?? 0
                                    );

                                    $regularQty = qty(
                                        $supply['regular_qty'] ?? 0
                                    );

                                    $largeQty = qty(
                                        $supply['large_qty'] ?? 0
                                    );

                                    if (
                                        $supplyId <= 0 ||
                                        ($regularQty <= 0 &&
                                         $largeQty <= 0)
                                    ) {
                                        continue;
                                    }

                                    $minimumStockSelect = "0";

                                    if ($supplyMinimumColumn !== null) {
                                        $minimumStockSelect =
                                            "`$supplyMinimumColumn`";
                                    }

                                    $supplyStockSql = "
                                        SELECT
                                            `$supplyStockColumn`
                                                AS current_stock,

                                            COALESCE(
                                                $minimumStockSelect,
                                                0
                                            ) AS minimum_stock

                                        FROM supplies

                                        WHERE id = $supplyId

                                        LIMIT 1
                                    ";

                                    $stockResult = mysqli_query(
                                        $conn,
                                        $supplyStockSql
                                    );

                                    if (!$stockResult) {
                                        continue;
                                    }

                                    $supplyRow =
                                        mysqli_fetch_assoc(
                                            $stockResult
                                        );

                                    mysqli_free_result(
                                        $stockResult
                                    );

                                    /*
                                     * Missing supply record:
                                     * product cannot be produced.
                                     */
                                    if (!$supplyRow) {

                                        $hasOutOfStock = true;
                                        break;
                                    }

                                    $supplyStock = qty(
                                        $supplyRow['current_stock'] ?? 0
                                    );

                                    /*
                                     * =================================================
                                     * REGULAR SUPPLY CHECK
                                     * =================================================
                                     */

                                    if ($regularQty > 0) {

                                        if (
                                            $supplyStock <= 0
                                        ) {

                                            $hasOutOfStock = true;
                                            break;
                                        }

                                        if (
                                            $supplyStock <
                                            $regularQty
                                        ) {

                                            $hasLowStock = true;
                                        }
                                    }

                                    /*
                                     * =================================================
                                     * LARGE SUPPLY CHECK
                                     * =================================================
                                     */

                                    if ($largeQty > 0) {

                                        if (
                                            $supplyStock <= 0
                                        ) {

                                            $hasOutOfStock = true;
                                            break;
                                        }

                                        if (
                                            $supplyStock <
                                            $largeQty
                                        ) {

                                            $hasLowStock = true;
                                        }
                                    }

                                }

                                mysqli_free_result(
                                    $supplyResult
                                );
                            }
                        }

                        ?>

                        <tr>

                            <!-- PRODUCT -->

                            <td>

                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $row['name']
                                    );
                                    ?>
                                </strong>

                            </td>

                            <!-- CATEGORY -->

                            <td>

                                <?php
                                echo htmlspecialchars(
                                    $row['category']
                                );
                                ?>

                            </td>

                            <!-- INGREDIENT COUNT -->

                            <td class="count">

                                <?php
                                echo $ingredientCount;
                                ?>

                            </td>

                            <!-- SUPPLY COUNT -->

                            <td class="count">

                                <?php
                                echo $supplyCount;
                                ?>

                            </td>

                            <!-- STATUS -->

                            <td>

                                <?php if (!$hasFormula): ?>

                                    <span class="status missing">

                                        <i
                                            class="fa-solid fa-triangle-exclamation"
                                        ></i>

                                        Incomplete

                                    </span>

                                <?php elseif ($hasOutOfStock): ?>

                                    <span class="status out">

                                        <i
                                            class="fa-solid fa-circle-xmark"
                                        ></i>

                                        Not Available

                                    </span>

                                <?php elseif ($hasLowStock): ?>

                                    <span class="status low">

                                        <i
                                            class="fa-solid fa-triangle-exclamation"
                                        ></i>

                                        Low Stock

                                    </span>

                                <?php else: ?>

                                    <span class="status ready">

                                        <i
                                            class="fa-solid fa-circle-check"
                                        ></i>

                                        Ready

                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- ACTION -->

                            <td>

                                <a
                                    class="view-btn"
                                    href="recipe_details.php?product_id=<?php echo $productId; ?>"
                                >

                                    <i
                                        class="fa-solid fa-eye"
                                    ></i>

                                    View Formula

                                </a>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td
                            colspan="6"
                            class="empty"
                        >
                            No products found.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script>

const menuToggle =
    document.getElementById('menuToggle');

const sidebar =
    document.getElementById('sidebar');

const overlay =
    document.getElementById('sidebarOverlay');

if (
    menuToggle &&
    sidebar
) {

    menuToggle.addEventListener(
        'click',
        () => {

            sidebar.classList.toggle('active');

            if (overlay) {
                overlay.classList.toggle('active');
            }

        }
    );

}

if (
    overlay &&
    sidebar
) {

    overlay.addEventListener(
        'click',
        () => {

            sidebar.classList.remove('active');

            overlay.classList.remove('active');

        }
    );

}

</script>

</body>

</html>
