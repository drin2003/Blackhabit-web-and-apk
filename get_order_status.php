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
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed."
    ]);

    exit();
}

mysqli_set_charset($conn, "utf8mb4");

$orderId = isset($_GET['order_id'])
    ? (int)$_GET['order_id']
    : 0;

$userId = isset($_GET['user_id'])
    ? (int)$_GET['user_id']
    : 0;

if ($orderId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid order ID."
    ]);

    exit();
}

/*
|--------------------------------------------------------------------------
| GET ORDER
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "
    SELECT
        id,
        user_id,
        status,
        order_type,
        created_at
    FROM orders
    WHERE id = ?
    LIMIT 1
    "
);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare order query."
    ]);

    mysqli_close($conn);
    exit();
}

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $orderId
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$order = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$order) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Order not found."
    ]);

    mysqli_close($conn);
    exit();
}

/*
|--------------------------------------------------------------------------
| ORDER TYPE
|--------------------------------------------------------------------------
*/

$orderType = strtolower(
    trim(
        (string)($order['order_type'] ?? '')
    )
);

$isDineIn =
    $orderType === 'dine in' ||
    $orderType === 'dine-in' ||
    $orderType === 'dine_in';

/*
|--------------------------------------------------------------------------
| OWNERSHIP
|--------------------------------------------------------------------------
|
| Pick-up / Advance Order:
| user_id must match.
|
| Dine-In:
| guest customers are allowed to check the status using
| the order ID contained in their QR code.
|
|--------------------------------------------------------------------------
*/

if (!$isDineIn) {

    if ($userId <= 0) {

        http_response_code(403);

        echo json_encode([
            "success" => false,
            "message" => "Login is required for this order."
        ]);

        mysqli_close($conn);
        exit();
    }

    $databaseUserId = (int)(
        $order['user_id'] ?? 0
    );

    if (
        $databaseUserId <= 0 ||
        $databaseUserId !== $userId
    ) {

        http_response_code(403);

        echo json_encode([
            "success" => false,
            "message" => "You are not authorized to view this order."
        ]);

        mysqli_close($conn);
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| RETURN STATUS
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        "success" => true,
        "order_id" => (int)$order['id'],
        "status" => (string)$order['status'],
        "order_type" => (string)$order['order_type'],
        "created_at" => $order['created_at']
    ],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);

mysqli_close($conn);

?>