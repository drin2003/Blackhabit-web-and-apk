<?php


// Use the same admin session as recipe_management.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('BH_ADMIN_SESSION');
    session_start();
}

// Admin access check
if (
    !isset($_SESSION['role']) ||
    strtolower(trim((string)$_SESSION['role'])) !== 'admin'
) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . "/db.php";

// Get product ID
$product_id = isset($_GET['product_id'])
    ? (int)$_GET['product_id']
    : 0;

// Invalid product ID
if ($product_id <= 0) {
    header("Location: recipe_management.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| GET PRODUCT
|--------------------------------------------------------------------------
*/

$product_sql = "
    SELECT
        id,
        name,
        category,
        regular_price,
        large_price
    FROM products
    WHERE id = ?
    LIMIT 1
";

$product_stmt = mysqli_prepare($conn, $product_sql);

if (!$product_stmt) {
    die("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param($product_stmt, "i", $product_id);
mysqli_stmt_execute($product_stmt);

$product_result = mysqli_stmt_get_result($product_stmt);
$product = $product_result ? mysqli_fetch_assoc($product_result) : null;

mysqli_stmt_close($product_stmt);

// Product does not exist
if (!$product) {

    // AJAX request
    if (
        isset($_GET['ajax']) &&
        $_GET['ajax'] === '1'
    ) {
        http_response_code(404);

        echo '
        <div class="formula-error">
            Product formula not found.
        </div>';

        exit();
    }

    header("Location: recipe_management.php");
    exit();
}

// Check if AJAX
$is_ajax =
    isset($_GET['ajax']) &&
    $_GET['ajax'] === '1';

/*
|--------------------------------------------------------------------------
| FORMAT FORMULA QUANTITY
|--------------------------------------------------------------------------
| Keeps small quantities such as 0.12 L and 0.03 L visible.
*/
function format_formula_quantity($value): string
{
    $value = (float)$value;

    if ($value <= 0) {
        return '—';
    }

    $formatted = number_format($value, 3, '.', '');

    return rtrim(rtrim($formatted, '0'), '.');
}

/*
|--------------------------------------------------------------------------
| GET INGREDIENT FORMULA
|--------------------------------------------------------------------------
*/

$recipe_sql = "
    SELECT
        recipes.id,
        recipes.ingredient_id,
        recipes.regular_qty,
        recipes.large_qty,
        ingredients.ingredient_name,
        ingredients.unit
    FROM recipes
    INNER JOIN ingredients
        ON recipes.ingredient_id = ingredients.id
    WHERE recipes.product_id = ?
    ORDER BY ingredients.ingredient_name ASC
";

$recipe_stmt = mysqli_prepare($conn, $recipe_sql);

if (!$recipe_stmt) {
    die("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param(
    $recipe_stmt,
    "i",
    $product_id
);

mysqli_stmt_execute($recipe_stmt);

$recipe = mysqli_stmt_get_result($recipe_stmt);

/*
|--------------------------------------------------------------------------
| GET SUPPLY FORMULA
|--------------------------------------------------------------------------
*/

$supply_sql = "
    SELECT
        product_supplies.id,
        product_supplies.regular_qty,
        product_supplies.large_qty,
        supplies.supply_name,
        supplies.unit
    FROM product_supplies
    INNER JOIN supplies
        ON product_supplies.supply_id = supplies.id
    WHERE product_supplies.product_id = ?
    ORDER BY supplies.supply_name ASC
";

$supply_stmt = mysqli_prepare($conn, $supply_sql);

if (!$supply_stmt) {
    die("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param(
    $supply_stmt,
    "i",
    $product_id
);

mysqli_stmt_execute($supply_stmt);

$product_supplies = mysqli_stmt_get_result($supply_stmt);


/*
|--------------------------------------------------------------------------
| AJAX RESPONSE
|--------------------------------------------------------------------------
*/

if ($is_ajax) {
?>

<style>
.formula-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.product-name {
    font-size: 28px;
    font-weight: 700;
    color: #c59d5f;
    margin: 0;
}

.product-meta {
    color: #888;
    font-size: 13px;
    margin-top: 5px;
}

.auto-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #d4edda;
    color: #155724;
    padding: 8px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.formula-section {
    margin-top: 22px;
}

.formula-section h3 {
    margin: 0 0 12px;
    font-size: 17px;
}

.formula-section h3 i {
    color: #c59d5f;
    margin-right: 7px;
}

.formula-table-wrap {
    overflow: auto;
    border: 1px solid #2c2c2c;
    border-radius: 8px;
}

.formula-table-wrap table {
    width: 100%;
    border-collapse: collapse;
}

.formula-table-wrap th,
.formula-table-wrap td {
    padding: 11px 13px;
    text-align: left;
    border-bottom: 1px solid #292929;
}

.formula-table-wrap th {
    background: #202020;
    color: #c59d5f;
    font-size: 12px;
}

.formula-table-wrap td {
    font-size: 13px;
}

.formula-empty {
    padding: 18px;
    border: 1px dashed #333;
    border-radius: 8px;
    color: #888;
}

.readonly-note {
    padding: 14px 16px;
    border: 1px solid #2e2e2e;
    background: #151515;
    border-radius: 8px;
    color: #aaa;
    font-size: 13px;
    margin-bottom: 18px;
}

.readonly-note i {
    color: #c59d5f;
    margin-right: 7px;
}

.qty {
    font-weight: 600;
}

.unit {
    color: #888;
}

.formula-error {
    padding: 14px;
    background: #3a2020;
    border: 1px solid #693333;
    color: #ffb3b3;
    border-radius: 8px;
}
</style>

<div class="formula-header">

    <div>

        <h2 class="product-name">
            <?php echo htmlspecialchars($product['name']); ?>
        </h2>

        <div class="product-meta">
            <?php echo htmlspecialchars($product['category']); ?>

            &nbsp;•&nbsp;

            Product #<?php echo (int)$product['id']; ?>
        </div>

    </div>

    <span class="auto-badge">
        <i class="fa-solid fa-wand-magic-sparkles"></i>
        Automatically managed
    </span>

</div>


<div class="readonly-note">

    <i class="fa-solid fa-circle-info"></i>

    This is the saved product formula.
    It shows the
    <strong>ingredients and supplies</strong>
    required for the selected product.

</div>


<!-- =========================================================
     INGREDIENTS
========================================================= -->

<div class="formula-section">

    <h3>
        <i class="fa-solid fa-seedling"></i>
        Ingredients
    </h3>

    <div class="formula-table-wrap">

        <table>

            <thead>

                <tr>
                    <th>Ingredient</th>
                    <th>Regular Quantity</th>
                    <th>Large Quantity</th>
                    <th>Unit</th>
                </tr>

            </thead>

            <tbody>

            <?php if ($recipe && mysqli_num_rows($recipe) > 0): ?>

                <?php while ($row = mysqli_fetch_assoc($recipe)): ?>

                    <tr>

                        <td>
                            <strong>
                                <?php
                                echo htmlspecialchars(
                                    $row['ingredient_name']
                                );
                                ?>
                            </strong>
                        </td>

                        <td class="qty">

                            <?php
                            echo ((float)$row['regular_qty'] > 0)
                                ? format_formula_quantity($row['regular_qty'])
                                : '—';
                            ?>

                        </td>

                        <td class="qty">

                            <?php
                            echo ((float)$row['large_qty'] > 0)
                                ? format_formula_quantity($row['large_qty'])
                                : '—';
                            ?>

                        </td>

                        <td class="unit">

                            <?php
                            echo htmlspecialchars(
                                $row['unit']
                            );
                            ?>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="4"
                        class="formula-empty"
                    >
                        No ingredients configured for this product.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- =========================================================
     SUPPLIES
========================================================= -->

<div class="formula-section">

    <h3>
        <i class="fa-solid fa-boxes-stacked"></i>
        Supplies
    </h3>

    <div class="formula-table-wrap">

        <table>

            <thead>

                <tr>
                    <th>Supply</th>
                    <th>Regular Quantity</th>
                    <th>Large Quantity</th>
                    <th>Unit</th>
                </tr>

            </thead>

            <tbody>

            <?php if (
                $product_supplies &&
                mysqli_num_rows($product_supplies) > 0
            ): ?>

                <?php while (
                    $row = mysqli_fetch_assoc($product_supplies)
                ): ?>

                    <tr>

                        <td>

                            <strong>
                                <?php
                                echo htmlspecialchars(
                                    $row['supply_name']
                                );
                                ?>
                            </strong>

                        </td>

                        <td class="qty">

                            <?php
                            echo ((float)$row['regular_qty'] > 0)
                                ? format_formula_quantity($row['regular_qty'])
                                : '—';
                            ?>

                        </td>

                        <td class="qty">

                            <?php
                            echo ((float)$row['large_qty'] > 0)
                                ? format_formula_quantity($row['large_qty'])
                                : '—';
                            ?>

                        </td>

                        <td class="unit">

                            <?php
                            echo htmlspecialchars(
                                $row['unit']
                            );
                            ?>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="4"
                        class="formula-empty"
                    >
                        No supplies configured for this product.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php

    mysqli_stmt_close($recipe_stmt);
    mysqli_stmt_close($supply_stmt);

    exit();
}

?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Product Formula - BLACKHABIT
</title>

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
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
>


<style>

.formula-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.product-name {
    font-size: 28px;
    font-weight: 700;
    color: #c59d5f;
    margin: 0;
}

.product-meta {
    color: #888;
    font-size: 13px;
    margin-top: 5px;
}

.auto-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #d4edda;
    color: #155724;
    padding: 8px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.section {
    margin-top: 25px;
}

.section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}

.section-title h2 {
    margin: 0;
    font-size: 18px;
}

.readonly-note {
    padding: 14px 16px;
    border: 1px solid #2e2e2e;
    background: #151515;
    border-radius: 8px;
    color: #aaa;
    font-size: 13px;
    margin-bottom: 18px;
}

.readonly-note i {
    color: #c59d5f;
    margin-right: 7px;
}

.empty {
    padding: 35px;
    text-align: center;
    color: #999;
}

.empty i {
    display: block;
    font-size: 34px;
    margin-bottom: 10px;
}

.qty {
    font-weight: 600;
}

.unit {
    color: #888;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #fff;
    text-decoration: none;
    background: #292929;
    padding: 10px 15px;
    border-radius: 6px;
}

.back-btn:hover {
    background: #333;
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


    <!-- TOP BAR -->

    <div class="topbar">

        <button
            class="menu-toggle"
            id="menuToggle"
        >
            <i class="fa-solid fa-bars"></i>
        </button>


        <h1>
            Product Formula
        </h1>


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

                <span>
                    Administrator
                </span>

            </div>

        </div>

    </div>


    <!-- MAIN PANEL -->

    <div class="panel">


        <!-- PRODUCT HEADER -->

        <div class="formula-header">

            <div>

                <h2 class="product-name">

                    <?php
                    echo htmlspecialchars(
                        $product['name']
                    );
                    ?>

                </h2>


                <div class="product-meta">

                    <?php
                    echo htmlspecialchars(
                        $product['category']
                    );
                    ?>

                    &nbsp;•&nbsp;

                    Product #

                    <?php
                    echo (int)$product['id'];
                    ?>

                </div>

            </div>


            <div>

                <span class="auto-badge">

                    <i
                        class="fa-solid fa-wand-magic-sparkles"
                    ></i>

                    Automatically managed

                </span>

            </div>

        </div>


        <!-- NOTE -->

        <div class="readonly-note">

            <i class="fa-solid fa-circle-info"></i>

            This formula is managed from
            <strong>Product Management</strong>.

            You do not need to create a separate recipe here.

            When the product formula is saved,
            this page updates automatically.

        </div>


        <!-- =================================================
             INGREDIENTS
        ================================================= -->

        <div class="section">

            <div class="section-title">

                <h2>

                    <i class="fa-solid fa-seedling"></i>

                    Ingredients

                </h2>

            </div>


            <div class="table-container">

                <table class="product-table">

                    <thead>

                        <tr>

                            <th>
                                Ingredient
                            </th>

                            <th>
                                Regular Quantity
                            </th>

                            <th>
                                Large Quantity
                            </th>

                            <th>
                                Unit
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        $recipe &&
                        mysqli_num_rows($recipe) > 0
                    ): ?>


                        <?php while (
                            $row = mysqli_fetch_assoc($recipe)
                        ): ?>

                            <tr>

                                <td>

                                    <strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $row['ingredient_name']
                                        );
                                        ?>

                                    </strong>

                                </td>


                                <td class="qty">

                                    <?php
                                    echo ((float)$row['regular_qty'] > 0)
                                        ? format_formula_quantity($row['regular_qty'])
                                        : '—';
                                    ?>

                                </td>


                                <td class="qty">

                                    <?php
                                    echo ((float)$row['large_qty'] > 0)
                                        ? format_formula_quantity($row['large_qty'])
                                        : '—';
                                    ?>

                                </td>


                                <td class="unit">

                                    <?php
                                    echo htmlspecialchars(
                                        $row['unit']
                                    );
                                    ?>

                                </td>

                            </tr>


                        <?php endwhile; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="4"
                                class="empty"
                            >

                                <i
                                    class="fa-solid fa-flask"
                                ></i>

                                No ingredients configured
                                for this product.

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>


        <!-- =================================================
             SUPPLIES
        ================================================= -->

        <div class="section">

            <div class="section-title">

                <h2>

                    <i class="fa-solid fa-boxes-stacked"></i>

                    Supplies

                </h2>

            </div>


            <div class="table-container">

                <table class="product-table">

                    <thead>

                        <tr>

                            <th>
                                Supply
                            </th>

                            <th>
                                Regular Quantity
                            </th>

                            <th>
                                Large Quantity
                            </th>

                            <th>
                                Unit
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        $product_supplies &&
                        mysqli_num_rows($product_supplies) > 0
                    ): ?>


                        <?php while (
                            $row = mysqli_fetch_assoc(
                                $product_supplies
                            )
                        ): ?>


                            <tr>

                                <td>

                                    <strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $row['supply_name']
                                        );
                                        ?>

                                    </strong>

                                </td>


                                <td class="qty">

                                    <?php
                                    echo ((float)$row['regular_qty'] > 0)
                                        ? format_formula_quantity($row['regular_qty'])
                                        : '—';
                                    ?>

                                </td>


                                <td class="qty">

                                    <?php
                                    echo ((float)$row['large_qty'] > 0)
                                        ? format_formula_quantity($row['large_qty'])
                                        : '—';
                                    ?>

                                </td>


                                <td class="unit">

                                    <?php
                                    echo htmlspecialchars(
                                        $row['unit']
                                    );
                                    ?>

                                </td>

                            </tr>


                        <?php endwhile; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="4"
                                class="empty"
                            >

                                <i
                                    class="fa-solid fa-box-open"
                                ></i>

                                No supplies configured
                                for this product.

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>


        <!-- BACK BUTTON -->

        <div style="margin-top:25px;">

            <a
                href="recipe_management.php"
                class="back-btn"
            >

                <i
                    class="fa-solid fa-arrow-left"
                ></i>

                Back to Recipe Management

            </a>

        </div>


    </div>

</div>


<!-- =========================================================
     SIDEBAR SCRIPT
========================================================= -->

<script>

const menuToggle =
    document.getElementById('menuToggle');

const sidebar =
    document.getElementById('sidebar');

const overlay =
    document.getElementById('sidebarOverlay');


if (menuToggle && sidebar) {

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


if (overlay && sidebar) {

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

<?php

/*
|--------------------------------------------------------------------------
| CLOSE STATEMENTS
|--------------------------------------------------------------------------
*/

if ($recipe_stmt) {
    mysqli_stmt_close($recipe_stmt);
}

if ($supply_stmt) {
    mysqli_stmt_close($supply_stmt);
}

?>