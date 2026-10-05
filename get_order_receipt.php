<?php

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db.php';

// Black Habit uses Philippine time for order/receipt timestamps.
date_default_timezone_set('Asia/Manila');

function respond($data, $code = 200)
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function tableColumnExists($conn, $table, $column)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

    $sql = "
        SELECT COUNT(*) AS cnt
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return ((int)($row['cnt'] ?? 0)) > 0;
}

function normalizeOrderType($value)
{
    $value = trim((string)$value);
    $lower = strtolower($value);

    if (
        $lower === 'advance order' ||
        $lower === 'pick-up' ||
        $lower === 'pickup' ||
        $lower === 'pick up' ||
        $lower === 'take out' ||
        $lower === 'take-out'
    ) {
        return 'Pick-up Order';
    }

    if (
        $lower === 'dine in' ||
        $lower === 'dine-in' ||
        $lower === 'dinein'
    ) {
        return 'Dine In';
    }

    if (
        $lower === 'over the counter' ||
        $lower === 'over-the-counter' ||
        $lower === 'otc'
    ) {
        return 'Over-the-Counter';
    }

    return $value;
}

if (!isset($conn) || !$conn) {
    respond([
        'success' => false,
        'message' => 'Database connection failed.'
    ], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'success' => false,
        'message' => 'POST request required.'
    ], 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    respond([
        'success' => false,
        'message' => 'Invalid JSON request.'
    ], 400);
}

$order_id = isset($data['order_id'])
    ? (int)$data['order_id']
    : 0;

if ($order_id <= 0) {
    respond([
        'success' => false,
        'message' => 'Invalid order ID.'
    ], 400);
}

/*
 * Optional ownership check.
 *
 * Existing Flutter receipt calls can continue sending only order_id.
 * If user_id is supplied, the API verifies that the order belongs
 * to that customer account.
 */
$requestedUserId = isset($data['user_id'])
    ? (int)$data['user_id']
    : 0;

/*
 * Load order.
 */
if ($requestedUserId > 0) {
    $orderSql = "
        SELECT
            id AS order_id,
            user_id,
            order_type,
            status,
            payment_method,
            payment_type,
            payment_status,
            total,
            paid_amount,
            balance,
            receipt_number,
            created_at
        FROM orders
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ";

    $orderStmt = mysqli_prepare($conn, $orderSql);

    if (!$orderStmt) {
        respond([
            'success' => false,
            'message' => 'Failed to prepare order query.'
        ], 500);
    }

    mysqli_stmt_bind_param(
        $orderStmt,
        'ii',
        $order_id,
        $requestedUserId
    );
} else {
    $orderSql = "
        SELECT
            id AS order_id,
            user_id,
            order_type,
            status,
            payment_method,
            payment_type,
            payment_status,
            total,
            paid_amount,
            balance,
            receipt_number,
            created_at
        FROM orders
        WHERE id = ?
        LIMIT 1
    ";

    $orderStmt = mysqli_prepare($conn, $orderSql);

    if (!$orderStmt) {
        respond([
            'success' => false,
            'message' => 'Failed to prepare order query.'
        ], 500);
    }

    mysqli_stmt_bind_param(
        $orderStmt,
        'i',
        $order_id
    );
}

if (!mysqli_stmt_execute($orderStmt)) {
    $error = mysqli_stmt_error($orderStmt);
    mysqli_stmt_close($orderStmt);

    respond([
        'success' => false,
        'message' => 'Failed to load order.',
        'error' => $error
    ], 500);
}

$orderResult = mysqli_stmt_get_result($orderStmt);
$order = mysqli_fetch_assoc($orderResult);

mysqli_stmt_close($orderStmt);

if (!$order) {
    respond([
        'success' => false,
        'message' => 'Order not found.'
    ], 404);
}

/*
 * Receipt number:
 * Use the stored receipt number when available.
 * For older orders without one, generate the same display format
 * used by create_order.php: BH-YYYY-######.
 */
$receiptNumber = trim((string)($order['receipt_number'] ?? ''));

if ($receiptNumber === '') {
    $createdAt = (string)($order['created_at'] ?? '');
    $year = '';

    if ($createdAt !== '') {
        $timestamp = strtotime($createdAt);

        if ($timestamp !== false) {
            $year = (new DateTime(
                $createdAt,
                new DateTimeZone('Asia/Manila')
            ))->format('Y');
        }
    }

    if ($year === '') {
        $year = date('Y');
    }

    $receiptNumber =
        'BH-' .
        $year .
        '-' .
        str_pad(
            (string)$order_id,
            6,
            '0',
            STR_PAD_LEFT
        );
}

/*
 * Check optional order-item columns so older database versions
 * do not break the receipt API.
 */
$hasAddons = tableColumnExists(
    $conn,
    'order_items',
    'addons'
);

$hasAddonsPrice = tableColumnExists(
    $conn,
    'order_items',
    'addons_price'
);

$hasSizePrice = tableColumnExists(
    $conn,
    'order_items',
    'size_price'
);

$hasSpecialInstructions = tableColumnExists(
    $conn,
    'order_items',
    'special_instructions'
);

/*
 * Build the item SELECT dynamically.
 *
 * create_order.php stores the selected size directly in price.
 * Therefore size_price is normally 0 and must NOT be added again
 * to the item price. addons_price is stored separately.
 */
$itemColumns = [
    'oi.id AS order_item_id',
    'oi.product_id',
    'oi.quantity',
    'oi.price',
    'oi.flavor',
    'oi.size',
    'oi.sugar',
    'oi.ice',
    'p.name AS product_name',
    'p.image AS product_image'
];

$itemColumns[] = $hasAddons
    ? 'oi.addons'
    : "'' AS addons";

$itemColumns[] = $hasAddonsPrice
    ? 'oi.addons_price'
    : '0 AS addons_price';

$itemColumns[] = $hasSizePrice
    ? 'oi.size_price'
    : '0 AS size_price';

$itemColumns[] = $hasSpecialInstructions
    ? 'oi.special_instructions'
    : "'' AS special_instructions";

$itemSql = "
    SELECT
        " . implode(",\n        ", $itemColumns) . "
    FROM order_items oi
    LEFT JOIN products p
        ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
";

$itemStmt = mysqli_prepare($conn, $itemSql);

if (!$itemStmt) {
    respond([
        'success' => false,
        'message' => 'Failed to prepare item query.'
    ], 500);
}

mysqli_stmt_bind_param(
    $itemStmt,
    'i',
    $order_id
);

if (!mysqli_stmt_execute($itemStmt)) {
    $error = mysqli_stmt_error($itemStmt);
    mysqli_stmt_close($itemStmt);

    respond([
        'success' => false,
        'message' => 'Failed to load order items.',
        'error' => $error
    ], 500);
}

$itemResult = mysqli_stmt_get_result($itemStmt);

$items = [];

while ($row = mysqli_fetch_assoc($itemResult)) {
    $quantity = (int)($row['quantity'] ?? 0);

    if ($quantity < 0) {
        $quantity = 0;
    }

    $price = (float)($row['price'] ?? 0);
    $addonsPrice = (float)($row['addons_price'] ?? 0);
    $sizePrice = (float)($row['size_price'] ?? 0);

    if ($addonsPrice < 0) {
        $addonsPrice = 0;
    }

    if ($sizePrice < 0) {
        $sizePrice = 0;
    }

    /*
     * create_order.php uses the selected regular/large product price
     * as "price". It currently stores size_price as 0.
     *
     * Keep the calculation compatible with that design while still
     * supporting a non-zero size_price if an older/future order has it.
     */
    $unitTotal = $price + $addonsPrice + $sizePrice;
    $itemTotal = $unitTotal * $quantity;

    $items[] = [
        'order_item_id' => (int)$row['order_item_id'],
        'product_id' => (int)$row['product_id'],
        'product_name' => $row['product_name'] ?? 'Product',
        'product_image' => $row['product_image'] ?? '',
        'quantity' => $quantity,

        'price' => number_format(
            $price,
            2,
            '.',
            ''
        ),

        'unit_price' => number_format(
            $price,
            2,
            '.',
            ''
        ),

        'addons' => trim(
            (string)($row['addons'] ?? '')
        ),

        'addons_price' => number_format(
            $addonsPrice,
            2,
            '.',
            ''
        ),

        'size_price' => number_format(
            $sizePrice,
            2,
            '.',
            ''
        ),

        'unit_total' => number_format(
            $unitTotal,
            2,
            '.',
            ''
        ),

        'item_total' => number_format(
            $itemTotal,
            2,
            '.',
            ''
        ),

        'flavor' => $row['flavor'] ?? '',
        'size' => $row['size'] ?? '',
        'sugar' => $row['sugar'] ?? '',
        'ice' => $row['ice'] ?? '',

        'special_instructions' => trim(
            (string)($row['special_instructions'] ?? '')
        )
    ];
}

mysqli_stmt_close($itemStmt);

if (count($items) === 0) {
    respond([
        'success' => false,
        'message' => 'No items found for this order.'
    ], 404);
}

/*
 * Payment values.
 */
$total = (float)($order['total'] ?? 0);
$paidAmount = (float)($order['paid_amount'] ?? 0);

if (
    isset($order['balance']) &&
    $order['balance'] !== null &&
    $order['balance'] !== ''
) {
    $balance = (float)$order['balance'];
} else {
    $balance = max(
        0,
        $total - $paidAmount
    );
}

$orderType = normalizeOrderType(
    $order['order_type'] ?? ''
);

$status = trim(
    (string)($order['status'] ?? 'Pending')
);

$paymentMethod = trim(
    (string)($order['payment_method'] ?? '')
);

$paymentType = trim(
    (string)($order['payment_type'] ?? '')
);

$paymentStatus = trim(
    (string)($order['payment_status'] ?? '')
);

respond([
    'success' => true,
    'message' => 'Order receipt loaded successfully.',

    /*
     * Keep the exact top-level structure expected by
     * Flutter ServerReceiptScreen:
     * { success, message, order, items }
     */
    'order' => [
        'order_id' => (int)$order['order_id'],

        'user_id' => $order['user_id'] !== null
            ? (int)$order['user_id']
            : null,

        'receipt_number' => $receiptNumber,

        /*
         * Alias useful for newer Flutter screens.
         */
        'order_number' => $receiptNumber,

        'order_type' => $orderType,

        /*
         * Explicit display value so Flutter does not have to
         * guess how Advance Order should appear.
         */
        'display_order_type' => $orderType,

        'status' => $status,

        'payment_method' => $paymentMethod,
        'payment_type' => $paymentType,
        'payment_status' => $paymentStatus,

        'total' => number_format(
            $total,
            2,
            '.',
            ''
        ),

        'paid_amount' => number_format(
            $paidAmount,
            2,
            '.',
            ''
        ),

        'balance' => number_format(
            $balance,
            2,
            '.',
            ''
        ),

        'created_at' => $order['created_at'] ?? ''
    ],

    'items' => $items
]);

mysqli_close($conn);

?>
