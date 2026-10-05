<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . "/db.php";

if (!isset($conn) || !$conn) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed."
    ]);
    exit();
}

mysqli_set_charset($conn, "utf8mb4");

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function tableExists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);

    $result = mysqli_query(
        $conn,
        "SHOW TABLES LIKE '$table'"
    );

    return $result &&
           mysqli_num_rows($result) > 0;
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

    return $result &&
           mysqli_num_rows($result) > 0;
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

function qty($value)
{
    if ($value === null || $value === '') {
        return 0;
    }

    $value = (float)$value;

    return $value < 0 ? 0 : $value;
}

function productionCapacity($stock, $required)
{
    $stock = qty($stock);
    $required = qty($required);

    if ($required <= 0) {
        return PHP_INT_MAX;
    }

    return (int)floor(
        $stock / $required
    );
}

/*
|--------------------------------------------------------------------------
| CONVERT RECIPE QUANTITY TO THE INGREDIENT'S DATABASE UNIT
|--------------------------------------------------------------------------
|
| Recipe templates use practical base quantities:
| - grams for solid/powder ingredients
| - milliliters for liquid ingredients
| - kilograms/liters may already be entered as decimal values
| - pieces stay unchanged
|
| The database may store:
|   kg  -> convert grams to kg when the recipe value is > 1
|   L   -> convert milliliters to liters when the recipe value is > 1
|   g/ml/pc -> keep unchanged
|
| This prevents values such as 120 ml from being compared against
| 100 L as if 120 were 120 L.
|--------------------------------------------------------------------------
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
            // Existing automatic formulas commonly use grams.
            // Decimal values <= 1 are already kg.
            return $recipeQty > 1
                ? $recipeQty / 1000
                : $recipeQty;

        case 'l':
        case 'lt':
        case 'liter':
        case 'liters':
        case 'litre':
        case 'litres':
            // Existing automatic formulas commonly use milliliters.
            // Decimal values <= 1 are already liters.
            return $recipeQty > 1
                ? $recipeQty / 1000
                : $recipeQty;

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
|--------------------------------------------------------------------------
| PRODUCT STOCK COLUMN
|--------------------------------------------------------------------------
*/

$productStockColumn = findColumn(
    $conn,
    "products",
    [
        "stock",
        "current_stock",
        "quantity",
        "available_stock"
    ]
);

/*
|--------------------------------------------------------------------------
| RECIPES
|--------------------------------------------------------------------------
*/

$hasRecipes = tableExists(
    $conn,
    "recipes"
);

$recipeProductColumn = null;
$recipeIngredientColumn = null;
$recipeRegularColumn = null;
$recipeLargeColumn = null;

if ($hasRecipes) {

    $recipeProductColumn = findColumn(
        $conn,
        "recipes",
        [
            "product_id"
        ]
    );

    $recipeIngredientColumn = findColumn(
        $conn,
        "recipes",
        [
            "ingredient_id"
        ]
    );

    $recipeRegularColumn = findColumn(
        $conn,
        "recipes",
        [
            "regular_qty",
            "quantity",
            "qty",
            "regular_quantity"
        ]
    );

    $recipeLargeColumn = findColumn(
        $conn,
        "recipes",
        [
            "large_qty",
            "large_quantity"
        ]
    );
}

/*
|--------------------------------------------------------------------------
| INGREDIENTS
|--------------------------------------------------------------------------
*/

$hasIngredients = tableExists(
    $conn,
    "ingredients"
);

$ingredientStockColumn = null;
$ingredientMinimumColumn = null;

if ($hasIngredients) {

    $ingredientStockColumn = findColumn(
        $conn,
        "ingredients",
        [
            "current_stock",
            "stock",
            "quantity",
            "available_stock"
        ]
    );

    $ingredientMinimumColumn = findColumn(
        $conn,
        "ingredients",
        [
            "minimum_stock",
            "stock_limit",
            "minimum_quantity"
        ]
    );
}

/*
|--------------------------------------------------------------------------
| PRODUCT SUPPLIES
|--------------------------------------------------------------------------
*/

$hasProductSupplies = tableExists(
    $conn,
    "product_supplies"
);

$productSupplyProductColumn = null;
$productSupplySupplyColumn = null;
$productSupplyRegularColumn = null;
$productSupplyLargeColumn = null;

if ($hasProductSupplies) {

    $productSupplyProductColumn = findColumn(
        $conn,
        "product_supplies",
        [
            "product_id"
        ]
    );

    $productSupplySupplyColumn = findColumn(
        $conn,
        "product_supplies",
        [
            "supply_id"
        ]
    );

    $productSupplyRegularColumn = findColumn(
        $conn,
        "product_supplies",
        [
            "regular_qty",
            "quantity",
            "qty",
            "regular_quantity"
        ]
    );

    $productSupplyLargeColumn = findColumn(
        $conn,
        "product_supplies",
        [
            "large_qty",
            "large_quantity"
        ]
    );
}

/*
|--------------------------------------------------------------------------
| SUPPLIES
|--------------------------------------------------------------------------
*/

$hasSupplies = tableExists(
    $conn,
    "supplies"
);

$supplyStockColumn = null;
$supplyMinimumColumn = null;

if ($hasSupplies) {

    $supplyStockColumn = findColumn(
        $conn,
        "supplies",
        [
            "current_stock",
            "stock",
            "quantity",
            "available_stock"
        ]
    );

    $supplyMinimumColumn = findColumn(
        $conn,
        "supplies",
        [
            "minimum_stock",
            "stock_limit",
            "minimum_quantity"
        ]
    );
}

/*
|--------------------------------------------------------------------------
| GET PRODUCTS
|--------------------------------------------------------------------------
*/

$result = mysqli_query(
    $conn,
    "SELECT * FROM products ORDER BY id DESC"
);

if (!$result) {

    echo json_encode([
        "success" => false,
        "message" => "Query failed."
    ]);

    mysqli_close($conn);
    exit();
}

$products = [];

while ($row = mysqli_fetch_assoc($result)) {

    $productId = isset($row['id'])
        ? (int)$row['id']
        : 0;

    /*
    |--------------------------------------------------------------------------
    | IMAGE
    |--------------------------------------------------------------------------
    |
    | Return filename only.
    | Flutter's ApiService builds the online image URL.
    |
    */

    if (!empty($row['image'])) {

        $image = str_replace(
            "\\",
            "/",
            $row['image']
        );

        $image = ltrim(
            $image,
            "/"
        );

        $image = preg_replace(
            '#^(images|uploads)/#i',
            '',
            $image
        );

        $row['image'] = basename(
            $image
        );

    } else {

        $row['image'] = "";
    }

    /*
    |--------------------------------------------------------------------------
    | STARTING PRODUCT STOCK
    |--------------------------------------------------------------------------
    */

    $calculatedStock = PHP_INT_MAX;

    if ($productStockColumn !== null) {

        $productStock = qty(
            $row[$productStockColumn] ?? 0
        );

        $calculatedStock = (int)$productStock;
    }

    /*
    |--------------------------------------------------------------------------
    | INGREDIENT AVAILABILITY
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | We check BOTH Regular and Large recipes.
    |
    | Example:
    |
    | Mango Syrup = 30 ml
    | Regular = 25 ml
    | Large   = 35 ml
    |
    | Large cannot be produced.
    |
    | Therefore the product becomes unavailable.
    |
    |--------------------------------------------------------------------------
    */

    if (
        $hasRecipes &&
        $recipeProductColumn !== null &&
        $recipeIngredientColumn !== null &&
        $recipeRegularColumn !== null &&
        $hasIngredients &&
        $ingredientStockColumn !== null
    ) {

        $pid = (int)$productId;

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
            WHERE `$recipeProductColumn` = $pid
        ";

        $recipeResult = mysqli_query(
            $conn,
            $recipeSql
        );

        if ($recipeResult) {

            while (
                $recipe = mysqli_fetch_assoc(
                    $recipeResult
                )
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
                    ($regularQty <= 0 && $largeQty <= 0)
                ) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | GET INGREDIENT STOCK
                |--------------------------------------------------------------------------
                */

                $minimumStock = 0;

                if ($ingredientMinimumColumn !== null) {

                    $minimumStockSelect =
                        "`$ingredientMinimumColumn`";

                } else {

                    $minimumStockSelect = "0";
                }

                $ingredientResult = mysqli_query(
                    $conn,
                    "
                    SELECT
                        `$ingredientStockColumn` AS current_stock,
                        COALESCE(
                            $minimumStockSelect,
                            0
                        ) AS minimum_stock,
                        unit AS ingredient_unit
                    FROM ingredients
                    WHERE id = $ingredientId
                    LIMIT 1
                    "
                );

                if (!$ingredientResult) {
                    continue;
                }

                $ingredient = mysqli_fetch_assoc(
                    $ingredientResult
                );

                mysqli_free_result(
                    $ingredientResult
                );

                if (!$ingredient) {

                    $calculatedStock = 0;
                    break;
                }

                $ingredientStock = qty(
                    $ingredient['current_stock'] ?? 0
                );

                $ingredientUnit = strtolower(
                    trim((string)($ingredient['ingredient_unit'] ?? ''))
                );

                // Convert the recipe quantity into the same unit
                // used by the ingredient's stock column.
                $regularRequired = recipeQuantityForUnit(
                    $regularQty,
                    $ingredientUnit
                );

                $largeRequired = recipeQuantityForUnit(
                    $largeQty,
                    $ingredientUnit
                );

                // Minimum/stock-limit is informational only.
                // It must NOT make the product unavailable while stock remains.
                $ingredientMinimum = qty(
                    $ingredient['minimum_stock'] ?? 0
                );

                
                /*
                |--------------------------------------------------------------------------
                | REGULAR CAPACITY
                |--------------------------------------------------------------------------
                */

                if ($regularQty > 0) {

                    $regularCapacity =
                        productionCapacity(
                            $ingredientStock,
                            $regularRequired
                        );

                    if (
                        $regularCapacity <
                        $calculatedStock
                    ) {

                        $calculatedStock =
                            $regularCapacity;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | LARGE CAPACITY
                |--------------------------------------------------------------------------
                |
                | This is the important fix.
                |
                | Large recipes are now included in product
                | availability.
                |
                */

                if ($largeQty > 0) {

                    $largeCapacity =
                        productionCapacity(
                            $ingredientStock,
                            $largeRequired
                        );

                    if (
                        $largeCapacity <
                        $calculatedStock
                    ) {

                        $calculatedStock =
                            $largeCapacity;
                    }
                }

                if ($calculatedStock <= 0) {

                    $calculatedStock = 0;
                    break;
                }
            }

            mysqli_free_result(
                $recipeResult
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SUPPLY AVAILABILITY
    |--------------------------------------------------------------------------
    */

    if (
        $hasProductSupplies &&
        $productSupplyProductColumn !== null &&
        $productSupplySupplyColumn !== null &&
        $productSupplyRegularColumn !== null &&
        $hasSupplies &&
        $supplyStockColumn !== null
    ) {

        $pid = (int)$productId;

        $supplySql = "
            SELECT
                `$productSupplySupplyColumn` AS supply_id,
                `$productSupplyRegularColumn` AS regular_qty
        ";

        if ($productSupplyLargeColumn !== null) {

            $supplySql .= ",
                `$productSupplyLargeColumn` AS large_qty
            ";

        } else {

            $supplySql .= ",
                0 AS large_qty
            ";
        }

        $supplySql .= "
            FROM product_supplies
            WHERE `$productSupplyProductColumn` = $pid
        ";

        $supplyResult = mysqli_query(
            $conn,
            $supplySql
        );

        if ($supplyResult) {

            while (
                $supply = mysqli_fetch_assoc(
                    $supplyResult
                )
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
                    ($regularQty <= 0 && $largeQty <= 0)
                ) {
                    continue;
                }

                if ($supplyMinimumColumn !== null) {

                    $minimumStockSelect =
                        "`$supplyMinimumColumn`";

                } else {

                    $minimumStockSelect = "0";
                }

                $stockResult = mysqli_query(
                    $conn,
                    "
                    SELECT
                        `$supplyStockColumn` AS current_stock,
                        COALESCE(
                            $minimumStockSelect,
                            0
                        ) AS minimum_stock
                    FROM supplies
                    WHERE id = $supplyId
                    LIMIT 1
                    "
                );

                if (!$stockResult) {
                    continue;
                }

                $supplyRow = mysqli_fetch_assoc(
                    $stockResult
                );

                mysqli_free_result(
                    $stockResult
                );

                if (!$supplyRow) {

                    $calculatedStock = 0;
                    break;
                }

                $supplyStock = qty(
                    $supplyRow['current_stock'] ?? 0
                );

                // Minimum/stock-limit is informational only.
                // It must NOT make the product unavailable while stock remains.
                $supplyMinimum = qty(
                    $supplyRow['minimum_stock'] ?? 0
                );

                
                /*
                |--------------------------------------------------------------------------
                | REGULAR CAPACITY
                |--------------------------------------------------------------------------
                */

                if ($regularQty > 0) {

                    $regularCapacity =
                        productionCapacity(
                            $supplyStock,
                            $regularQty
                        );

                    if (
                        $regularCapacity <
                        $calculatedStock
                    ) {

                        $calculatedStock =
                            $regularCapacity;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | LARGE CAPACITY
                |--------------------------------------------------------------------------
                */

                if ($largeQty > 0) {

                    $largeCapacity =
                        productionCapacity(
                            $supplyStock,
                            $largeQty
                        );

                    if (
                        $largeCapacity <
                        $calculatedStock
                    ) {

                        $calculatedStock =
                            $largeCapacity;
                    }
                }

                if ($calculatedStock <= 0) {

                    $calculatedStock = 0;
                    break;
                }
            }

            mysqli_free_result(
                $supplyResult
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FINAL STOCK
    |--------------------------------------------------------------------------
    */

    if ($calculatedStock === PHP_INT_MAX) {
        $calculatedStock = 0;
    }

    $calculatedStock = max(
        0,
        (int)$calculatedStock
    );

    /*
    |--------------------------------------------------------------------------
    | AVAILABILITY
    |--------------------------------------------------------------------------
    */

    $row['stock'] =
        $calculatedStock;

    $row['is_available'] =
        $calculatedStock > 0;

    $row['availability_status'] =
        $row['is_available']
            ? "Available"
            : "Not Available";

    /*
    |--------------------------------------------------------------------------
    | ADD PRODUCT
    |--------------------------------------------------------------------------
    */

    $products[] = $row;
}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

echo json_encode(
    $products,
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);

mysqli_free_result(
    $result
);

mysqli_close(
    $conn
);

?>