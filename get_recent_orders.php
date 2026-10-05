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

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/db.php';

if (ob_get_level()) {
    ob_clean();
}

function respond($data, $code = 200)
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function columnExistsBH($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);

    $result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM `$table` LIKE '$column'"
    );

    return $result && mysqli_num_rows($result) > 0;
}

if (!isset($conn) || !$conn) {
    respond([
        'success' => false,
        'message' => 'Database connection failed.',
        'orders' => []
    ], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'success' => false,
        'message' => 'POST request required.',
        'orders' => []
    ], 405);
}

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    respond([
        'success' => false,
        'message' => 'Invalid JSON request.',
        'orders' => []
    ], 400);
}

$userId = isset($input['user_id'])
    ? (int)$input['user_id']
    : 0;

$orderType = isset($input['order_type'])
    ? trim((string)$input['order_type'])
    : '';

if ($userId <= 0) {
    respond([
        'success' => false,
        'message' => 'Invalid user ID.',
        'orders' => []
    ], 400);
}

/*
|--------------------------------------------------------------------------
| NORMALIZE ORDER TYPE
|--------------------------------------------------------------------------
*/

$normalizedOrderType = '';

if ($orderType !== '') {

    $lowerType = strtolower($orderType);

    if (strpos($lowerType, 'dine') !== false) {
        $normalizedOrderType = 'Dine In';

    } elseif (
        strpos($lowerType, 'pick') !== false ||
        strpos($lowerType, 'take out') !== false ||
        strpos($lowerType, 'takeout') !== false ||
        strpos($lowerType, 'advance') !== false
    ) {
        $normalizedOrderType = 'Advance Order';

    } else {
        respond([
            'success' => false,
            'message' => 'Invalid order type.',
            'orders' => []
        ], 400);
    }
}

/*
|--------------------------------------------------------------------------
| OPTIONAL COLUMNS
|--------------------------------------------------------------------------
|
| These checks allow the API to work with the existing database while
| returning additional information when those columns exist.
|
|--------------------------------------------------------------------------
*/

$hasPaymentType =
    columnExistsBH($conn, 'orders', 'payment_type');

$hasReceiptNumber =
    columnExistsBH($conn, 'orders', 'receipt_number');

$hasAddons =
    columnExistsBH($conn, 'order_items', 'addons');

$hasAddonsPrice =
    columnExistsBH($conn, 'order_items', 'addons_price');

$hasSizePrice =
    columnExistsBH($conn, 'order_items', 'size_price');

$hasSpecialInstructions =
    columnExistsBH($conn, 'order_items', 'special_instructions');

/*
|--------------------------------------------------------------------------
| BUILD SELECT
|--------------------------------------------------------------------------
*/

$orderExtraSelect = '';

if ($hasPaymentType) {
    $orderExtraSelect .= ", o.payment_type";
} else {
    $orderExtraSelect .= ", '' AS payment_type";
}

if ($hasReceiptNumber) {
    $orderExtraSelect .= ", o.receipt_number";
} else {
    $orderExtraSelect .= ", '' AS receipt_number";
}

$itemExtraSelect = '';

if ($hasAddons) {
    $itemExtraSelect .= ", oi.addons";
} else {
    $itemExtraSelect .= ", '' AS addons";
}

if ($hasAddonsPrice) {
    $itemExtraSelect .= ", oi.addons_price";
} else {
    $itemExtraSelect .= ", 0 AS addons_price";
}

if ($hasSizePrice) {
    $itemExtraSelect .= ", oi.size_price";
} else {
    $itemExtraSelect .= ", 0 AS size_price";
}

if ($hasSpecialInstructions) {
    $itemExtraSelect .= ", oi.special_instructions";
} else {
    $itemExtraSelect .= ", '' AS special_instructions";
}

$sql = "
    SELECT
        o.id AS order_id,
        o.user_id,
        o.order_type,
        o.status,
        o.payment_method
        $orderExtraSelect,
        o.payment_status,
        o.total,
        o.paid_amount,
        o.balance,
        o.created_at,

        oi.id AS order_item_id,
        oi.product_id,
        oi.quantity,
        oi.price,
        oi.flavor,
        oi.size,
        oi.sugar,
        oi.ice
        $itemExtraSelect,

        p.name AS product_name,
        p.image AS product_image

    FROM orders o

    INNER JOIN order_items oi
        ON oi.order_id = o.id

    LEFT JOIN products p
        ON p.id = oi.product_id

    WHERE o.user_id = ?
";

$params = [$userId];
$types = 'i';

if ($normalizedOrderType !== '') {

    $sql .= "
        AND LOWER(TRIM(o.order_type))
            = LOWER(TRIM(?))
    ";

    $params[] = $normalizedOrderType;
    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| LATEST ORDERS FIRST
|--------------------------------------------------------------------------
|
| One order can contain multiple items. order_item_id keeps the items
| in their original order inside each order.
|
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY
        o.id DESC,
        oi.id ASC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    respond([
        'success' => false,
        'message' => 'Failed to prepare database query.',
        'orders' => []
    ], 500);
}

/*
|--------------------------------------------------------------------------
| DYNAMIC bind_param
|--------------------------------------------------------------------------
*/

$bindValues = [];
$bindValues[] = $types;

foreach ($params as $key => $value) {
    $bindValues[] = &$params[$key];
}

call_user_func_array(
    [$stmt, 'bind_param'],
    $bindValues
);

if (!mysqli_stmt_execute($stmt)) {

    mysqli_stmt_close($stmt);

    respond([
        'success' => false,
        'message' => 'Failed to load orders.',
        'orders' => []
    ], 500);
}

$result = mysqli_stmt_get_result($stmt);

$orders = [];

while ($row = mysqli_fetch_assoc($result)) {

    $total =
        (float)($row['total'] ?? 0);

    $paidAmount =
        (float)($row['paid_amount'] ?? 0);

    $balance =
        isset($row['balance'])
            ? (float)$row['balance']
            : max(
                0,
                $total - $paidAmount
            );

    $status =
        trim(
            (string)($row['status'] ?? 'Pending')
        );

    $paymentStatus =
        trim(
            (string)(
                $row['payment_status']
                ?? 'Pending'
            )
        );

    $orderTypeValue =
        trim(
            (string)(
                $row['order_type']
                ?? ''
            )
        );

    /*
    |--------------------------------------------------------------------------
    | CANONICAL ORDER NUMBER
    |--------------------------------------------------------------------------
    */

    $receiptNumber =
        trim(
            (string)(
                $row['receipt_number']
                ?? ''
            )
        );

    if ($receiptNumber === '') {

        $year =
            date(
                'Y',
                strtotime(
                    (string)$row['created_at']
                )
            );

        if (!$year || $year === '1970') {
            $year = date('Y');
        }

        $receiptNumber =
            'BH-' .
            $year .
            '-' .
            str_pad(
                (string)$row['order_id'],
                6,
                '0',
                STR_PAD_LEFT
            );
    }

    /*
    |--------------------------------------------------------------------------
    | DISPLAY ORDER TYPE
    |--------------------------------------------------------------------------
    */

    $displayOrderType =
        strtolower($orderTypeValue) === 'advance order'
            ? 'Pick-up Order'
            : $orderTypeValue;

    /*
    |--------------------------------------------------------------------------
    | ITEM TOTAL
    |--------------------------------------------------------------------------
    */

    $itemPrice =
        (float)($row['price'] ?? 0);

    $addonsPrice =
        (float)($row['addons_price'] ?? 0);

    $sizePrice =
        (float)($row['size_price'] ?? 0);

    $quantity =
        (int)($row['quantity'] ?? 0);

    $itemTotal =
        ($itemPrice + $addonsPrice + $sizePrice)
        * $quantity;

    /*
    |--------------------------------------------------------------------------
    | RETURN ONE ROW PER ITEM
    |--------------------------------------------------------------------------
    |
    | This preserves compatibility with the existing Flutter model while
    | providing all order-level information needed to group items by order_id.
    |
    |--------------------------------------------------------------------------
    */

    $orders[] = [

        'order_id' =>
            (int)$row['order_id'],

        'order_item_id' =>
            (int)($row['order_item_id'] ?? 0),

        'receipt_number' =>
            $receiptNumber,

        'order_number' =>
            $receiptNumber,

        'user_id' =>
            (int)$row['user_id'],

        'product_id' =>
            (int)$row['product_id'],

        'product_name' =>
            $row['product_name'] ?? 'Product',

        'product_image' =>
            $row['product_image'] ?? '',

        'quantity' =>
            $quantity,

        'price' =>
            number_format(
                $itemPrice,
                2,
                '.',
                ''
            ),

        'unit_price' =>
            number_format(
                $itemPrice,
                2,
                '.',
                ''
            ),

        'addons_price' =>
            number_format(
                $addonsPrice,
                2,
                '.',
                ''
            ),

        'size_price' =>
            number_format(
                $sizePrice,
                2,
                '.',
                ''
            ),

        'item_total' =>
            number_format(
                $itemTotal,
                2,
                '.',
                ''
            ),

        'total' =>
            number_format(
                $total,
                2,
                '.',
                ''
            ),

        'total_price' =>
            number_format(
                $total,
                2,
                '.',
                ''
            ),

        'total_amount' =>
            number_format(
                $total,
                2,
                '.',
                ''
            ),

        'paid_amount' =>
            number_format(
                $paidAmount,
                2,
                '.',
                ''
            ),

        'balance' =>
            number_format(
                max(0, $balance),
                2,
                '.',
                ''
            ),

        'payment_method' =>
            trim(
                (string)(
                    $row['payment_method']
                    ?? ''
                )
            ),

        'payment_type' =>
            trim(
                (string)(
                    $row['payment_type']
                    ?? ''
                )
            ),

        'payment_status' =>
            $paymentStatus,

        'status' =>
            $status,

        'order_status' =>
            $status,

        'order_type' =>
            $orderTypeValue,

        'display_order_type' =>
            $displayOrderType,

        'flavor' =>
            $row['flavor'] ?? '',

        'size' =>
            $row['size'] ?? '',

        'sugar' =>
            $row['sugar'] ?? '',

        'ice' =>
            $row['ice'] ?? '',

        'addons' =>
            $row['addons'] ?? '',

        'special_instructions' =>
            $row['special_instructions'] ?? '',

        'created_at' =>
            $row['created_at'] ?? ''
    ];
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
|
| Keep the response as a JSON array for compatibility with the current
| Flutter order-history implementation.
|
|--------------------------------------------------------------------------
*/

echo json_encode(
    $orders,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);

?>
