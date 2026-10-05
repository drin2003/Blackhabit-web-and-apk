<?php

/*
|--------------------------------------------------------------------------
| BLACKHABIT - PRINT KITCHEN ORDER
|--------------------------------------------------------------------------
*/

$from =
    strtolower(
        trim((string)($_GET['from'] ?? ''))
    );

$referer =
    strtolower(
        (string)($_SERVER['HTTP_REFERER'] ?? '')
    );


/*
|--------------------------------------------------------------------------
| SELECT CORRECT SESSION
|--------------------------------------------------------------------------
*/

if ($from === 'cashier') {

    session_name('BH_CASHIER_SESSION');

} elseif ($from === 'admin') {

    session_name('BH_ADMIN_SESSION');

} elseif (
    strpos($referer, 'cashier_') !== false
) {

    session_name('BH_CASHIER_SESSION');

} elseif (
    strpos($referer, 'admin_') !== false
) {

    session_name('BH_ADMIN_SESSION');

} elseif (
    isset($_COOKIE['BH_CASHIER_SESSION']) &&
    !isset($_COOKIE['BH_ADMIN_SESSION'])
) {

    session_name('BH_CASHIER_SESSION');

} elseif (
    isset($_COOKIE['BH_ADMIN_SESSION']) &&
    !isset($_COOKIE['BH_CASHIER_SESSION'])
) {

    session_name('BH_ADMIN_SESSION');

} else {

    session_name('BH_ADMIN_SESSION');
}

session_start();


/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

$role =
    strtolower(
        trim((string)($_SESSION['role'] ?? ''))
    );

$roleId =
    (int)($_SESSION['role'] ?? 0);

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !(
        $role === 'admin' ||
        $role === 'cashier' ||
        in_array($roleId, [1, 2, 3], true)
    )
) {

    die(
        'Your session has expired. Please log in again.'
    );
}


require_once 'db.php';


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function showValue(
    $value,
    array $hidden = [
        'Default',
        'Original',
        'Normal',
        '100%'
    ]
): bool {

    return
        $value !== null &&
        trim((string)$value) !== '' &&
        !in_array(
            trim((string)$value),
            $hidden,
            true
        );
}


/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/

$orderId =
    (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    die('Invalid order number.');
}


$stmt = mysqli_prepare(
    $conn,
    "SELECT
        o.*,
        u.first_name,
        u.last_name
     FROM orders o
     LEFT JOIN users u
        ON u.id = o.user_id
     WHERE o.id = ?
     LIMIT 1"
);

if (!$stmt) {
    die('Unable to load order.');
}

mysqli_stmt_bind_param(
    $stmt,
    'i',
    $orderId
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$order =
    $result
        ? mysqli_fetch_assoc($result)
        : null;

mysqli_stmt_close($stmt);

if (!$order) {
    die('Order not found.');
}


/*
|--------------------------------------------------------------------------
| ORDER ITEMS
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        oi.*,
        p.name,
        p.category
     FROM order_items oi
     INNER JOIN products p
        ON p.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY oi.id ASC"
);

if (!$stmt) {
    die('Unable to load order items.');
}

mysqli_stmt_bind_param(
    $stmt,
    'i',
    $orderId
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$items = [];

while (
    $result &&
    ($row = mysqli_fetch_assoc($result))
) {
    $items[] = $row;
}

mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| DISPLAY VALUES
|--------------------------------------------------------------------------
*/

$name =
    trim(
        ($order['first_name'] ?? '') .
        ' ' .
        ($order['last_name'] ?? '')
    );

if ($name === '') {
    $name = 'Walk-in Guest';
}

$receipt =
    trim(
        (string)(
            $order['receipt_number'] ?? ''
        )
    );

if ($receipt === '') {

    $created =
        $order['created_at']
            ?? date('Y-m-d H:i:s');

    $receipt =
        'BH-' .
        date(
            'Ymd',
            strtotime($created)
        ) .
        '-' .
        str_pad(
            (string)$orderId,
            6,
            '0',
            STR_PAD_LEFT
        );
}

$orderType =
    trim(
        (string)(
            $order['order_type'] ?? ''
        )
    );

$normalizedOrderType = strtolower(trim($orderType));

if (
    in_array($normalizedOrderType, [
        'advance order',
        'pick-up',
        'pick up',
        'pickup',
        'pick-up order'
    ], true)
) {
    $orderType = 'Pick-Up';
} elseif (
    in_array($normalizedOrderType, [
        'dine in',
        'dine-in',
        'dine in order'
    ], true)
) {
    $orderType = 'Dine-In';
} else {
    $orderType = 'Dine-In';
}

$createdAt =
    $order['created_at']
        ?? date('Y-m-d H:i:s');

$total =
    (float)($order['total'] ?? 0);

?>

<?php

/*
|--------------------------------------------------------------------------
| 58MM FIXED-WIDTH KITCHEN RECEIPT
|--------------------------------------------------------------------------
| Uses the same thermal-print method as the working customer receipt.
| Kitchen copy intentionally contains NO prices or payment information.
*/

$thermalWidth = 32;
$thermalLines = [];

function thermalCenter(string $text, int $width = 32): string
{
    $text = trim($text);

    $len = function_exists('mb_strlen')
        ? mb_strlen($text, 'UTF-8')
        : strlen($text);

    if ($len >= $width) {
        return substr($text, 0, $width);
    }

    return str_repeat(
        ' ',
        (int)floor(($width - $len) / 2)
    ) . $text;
}

function thermalTwoColumn(
    string $left,
    string $right,
    int $width = 32,
    int $gap = 2
): string {
    $rightLen = function_exists('mb_strlen')
        ? mb_strlen($right, 'UTF-8')
        : strlen($right);

    $leftMax = max(
        1,
        $width - $rightLen - $gap
    );

    $leftLen = function_exists('mb_strlen')
        ? mb_strlen($left, 'UTF-8')
        : strlen($left);

    if ($leftLen > $leftMax) {
        $left = function_exists('mb_substr')
            ? mb_substr($left, 0, $leftMax, 'UTF-8')
            : substr($left, 0, $leftMax);
    }

    $leftLen = function_exists('mb_strlen')
        ? mb_strlen($left, 'UTF-8')
        : strlen($left);

    return $left .
        str_repeat(
            ' ',
            max(
                1,
                $width - $leftLen - $rightLen
            )
        ) .
        $right;
}

function thermalWrap(
    string $text,
    int $width = 32
): array {
    $text = trim(
        preg_replace('/\s+/', ' ', $text)
    );

    if ($text === '') {
        return [];
    }

    $words = preg_split('/\s+/', $text);
    $lines = [];
    $line = '';

    foreach ($words as $word) {

        $test =
            ($line === '')
                ? $word
                : $line . ' ' . $word;

        $len = function_exists('mb_strlen')
            ? mb_strlen($test, 'UTF-8')
            : strlen($test);

        if ($len <= $width) {
            $line = $test;
        } else {

            if ($line !== '') {
                $lines[] = $line;
            }

            $wordLen = function_exists('mb_strlen')
                ? mb_strlen($word, 'UTF-8')
                : strlen($word);

            while ($wordLen > $width) {

                $lines[] =
                    function_exists('mb_substr')
                        ? mb_substr(
                            $word,
                            0,
                            $width,
                            'UTF-8'
                        )
                        : substr(
                            $word,
                            0,
                            $width
                        );

                $word =
                    function_exists('mb_substr')
                        ? mb_substr(
                            $word,
                            $width,
                            null,
                            'UTF-8'
                        )
                        : substr(
                            $word,
                            $width
                        );

                $wordLen =
                    function_exists('mb_strlen')
                        ? mb_strlen(
                            $word,
                            'UTF-8'
                        )
                        : strlen($word);
            }

            $line = $word;
        }
    }

    if ($line !== '') {
        $lines[] = $line;
    }

    return $lines;
}

$thermalLines[] =
    thermalCenter(
        'BLACK HABIT',
        $thermalWidth
    );

$thermalLines[] =
    thermalCenter(
        'MILK TEA AND SHAWARMA',
        $thermalWidth
    );

$thermalLines[] =
    thermalCenter(
        'GOOD HABITS, BETTER DAYS',
        $thermalWidth
    );

$thermalLines[] =
    str_repeat('-', $thermalWidth);

$thermalLines[] =
    thermalCenter(
        'KITCHEN STAFF ORDER',
        $thermalWidth
    );

$thermalLines[] =
    thermalCenter(
        strtoupper($orderType),
        $thermalWidth
    );

$thermalLines[] =
    str_repeat('-', $thermalWidth);

$thermalLines[] =
    thermalTwoColumn(
        'Order #',
        (string)$orderId,
        $thermalWidth
    );

$thermalLines[] =
    thermalTwoColumn(
        'Receipt',
        $receipt,
        $thermalWidth
    );

$thermalLines[] =
    thermalTwoColumn(
        'Customer',
        $name,
        $thermalWidth
    );

$thermalLines[] =
    thermalTwoColumn(
        'Order Type',
        $orderType,
        $thermalWidth
    );

$thermalLines[] =
    thermalTwoColumn(
        'Date',
        date(
            'M d, Y h:i A',
            strtotime($createdAt)
        ),
        $thermalWidth
    );

$thermalLines[] =
    str_repeat('-', $thermalWidth);

foreach ($items as $item) {

    $qty = max(
        1,
        (int)($item['quantity'] ?? 1)
    );

    $itemName = trim(
        (string)(
            $item['name'] ?? 'Product'
        )
    );

    /*
     * Kitchen quantity is deliberately plain ASCII "x".
     * This avoids the Unicode multiplication sign on
     * Generic / Text Only printers.
     */
    foreach (
        thermalWrap(
            $qty . 'x ' . $itemName,
            $thermalWidth
        ) as $line
    ) {
        $thermalLines[] = $line;
    }

    if (
        isset($item['category']) &&
        trim((string)$item['category']) !== ''
    ) {
        foreach (
            thermalWrap(
                '  Category: ' .
                trim((string)$item['category']),
                $thermalWidth
            ) as $line
        ) {
            $thermalLines[] = $line;
        }
    }

    foreach (
        ['size', 'flavor', 'sugar', 'ice']
        as $field
    ) {

        if (
            isset($item[$field]) &&
            trim((string)$item[$field]) !== ''
        ) {

            foreach (
                thermalWrap(
                    '  ' .
                    ucfirst($field) .
                    ': ' .
                    trim((string)$item[$field]),
                    $thermalWidth
                ) as $line
            ) {
                $thermalLines[] = $line;
            }
        }
    }

    $addons = trim(
        (string)($item['addons'] ?? '')
    );

    if ($addons !== '') {

        foreach (
            thermalWrap(
                '  Add-ons: ' . $addons,
                $thermalWidth
            ) as $line
        ) {
            $thermalLines[] = $line;
        }
    }

    $nataQty = (int)(
        $item['nata_addon_qty'] ?? 0
    );

    if ($nataQty > 0) {
        $thermalLines[] =
            '  Nata Add-on: ' . $nataQty;
    }

    $special = trim(
        (string)(
            $item['special_instructions'] ?? ''
        )
    );

    if ($special !== '') {

        foreach (
            thermalWrap(
                '  Note: ' . $special,
                $thermalWidth
            ) as $line
        ) {
            $thermalLines[] = $line;
        }
    }

    $thermalLines[] =
        str_repeat('-', $thermalWidth);
}

$thermalLines[] =
    thermalCenter(
        'KITCHEN STAFF COPY',
        $thermalWidth
    );

?>

<!doctype html>
<html lang="en">
<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
    BLACK HABIT - Kitchen Staff Order
    <?php echo h($orderId); ?>
</title>

<style>
* {
    box-sizing:border-box;
}

html,
body {
    margin:0;
    padding:0;
    width:100%;
}

body {
    background:#eee;
    color:#111;
    font-family:"Courier New", Courier, monospace;
    font-size:10px;
    line-height:1.22;
}

.receipt {
    width:58mm;
    max-width:58mm;
    margin:18px auto;
    padding:3.5mm 2.4mm;
    background:#fff;
}

.center {
    text-align:center;
}

.brand {
    font-family:Arial, Helvetica, sans-serif;
    font-size:15px;
    font-weight:900;
    letter-spacing:1.4px;
    line-height:1.05;
}

.title {
    margin-top:2px;
    font-size:8px;
    font-weight:700;
    letter-spacing:.7px;
}

.store-note {
    margin-top:2px;
    font-size:7.5px;
    letter-spacing:.6px;
}

.receipt-title {
    margin:4px 0 1px;
    font-family:Arial, Helvetica, sans-serif;
    font-size:13px;
    font-weight:900;
    letter-spacing:1.1px;
}

.line {
    border-top:1px dashed #111;
    margin:5px 0;
}

.meta {
    font-size:8.5px;
}

.meta-row {
    display:grid;
    grid-template-columns:32% 68%;
    width:100%;
    margin:1.5px 0;
}

.meta-label {
    font-weight:700;
    white-space:nowrap;
}

.meta-value {
    text-align:right;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:clip;
}

.item {
    padding:3px 0;
}

.item-name {
    font-weight:700;
    line-height:1.15;
    overflow-wrap:anywhere;
}

.item-detail {
    margin-top:1px;
    padding-left:6px;
    font-size:7.8px;
    line-height:1.15;
    overflow-wrap:anywhere;
}

.thermal-print {
    display:none;
}

.thermal-print pre {
    margin:0;
    padding:0;
    white-space:pre;
    font-family:"Courier New", Courier, monospace;
    font-size:10px;
    line-height:1.15;
    font-weight:700;
}

.print {
    margin:14px auto;
    text-align:center;
}

.print button {
    border:0;
    border-radius:5px;
    padding:10px 18px;
    background:#111;
    color:#fff;
    font-weight:700;
    cursor:pointer;
}

@media print {

    @page {
        size:58mm auto;
        margin:0;
    }

    html,
    body {
        width:58mm;
        min-width:58mm;
        margin:0;
        padding:0;
        background:#fff;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    /*
     * This is the important part:
     * physical printing uses fixed-width thermal text,
     * exactly like the working customer receipt.
     */
    .receipt {
        display:none !important;
    }

    .thermal-print {
        display:block !important;
        width:58mm;
        max-width:58mm;
        margin:0;
        padding:2mm 2mm;
        background:#fff;
    }

    .print {
        display:none !important;
    }
}
</style>

</head>

<body>

<div class="receipt">

    <div class="center brand">
        BLACK HABIT
    </div>

    <div class="center title">
        MILK TEA AND SHAWARMA
    </div>

    <div class="center store-note">
        GOOD HABITS, BETTER DAYS
    </div>

    <div class="line"></div>

    <div class="center receipt-title">
        KITCHEN STAFF ORDER
    </div>

    <div class="center">
        <?php echo h(strtoupper($orderType)); ?>
    </div>

    <div class="line"></div>

    <div class="meta">

        <div class="meta-row">
            <div class="meta-label">Order #</div>
            <div class="meta-value">
                <?php echo h($orderId); ?>
            </div>
        </div>

        <div class="meta-row">
            <div class="meta-label">Receipt</div>
            <div class="meta-value">
                <?php echo h($receipt); ?>
            </div>
        </div>

        <div class="meta-row">
            <div class="meta-label">Customer</div>
            <div class="meta-value">
                <?php echo h($name); ?>
            </div>
        </div>

        <div class="meta-row">
            <div class="meta-label">Order Type</div>
            <div class="meta-value">
                <?php echo h($orderType); ?>
            </div>
        </div>

        <div class="meta-row">
            <div class="meta-label">Date</div>
            <div class="meta-value">
                <?php echo h(
                    date(
                        'M d, Y h:i A',
                        strtotime($createdAt)
                    )
                ); ?>
            </div>
        </div>

    </div>

    <div class="line"></div>

    <?php foreach ($items as $item): ?>

        <?php
        $qty = max(
            1,
            (int)($item['quantity'] ?? 1)
        );

        $itemName = trim(
            (string)(
                $item['name'] ?? 'Product'
            )
        );
        ?>

        <div class="item">

            <div class="item-name">
                <?php echo h(
                    $qty . 'x ' . $itemName
                ); ?>
            </div>

            <?php
            $details = [];

            if (
                isset($item['category']) &&
                trim((string)$item['category']) !== ''
            ) {
                $details[] =
                    'Category: ' .
                    trim((string)$item['category']);
            }

            foreach (
                ['size', 'flavor', 'sugar', 'ice']
                as $field
            ) {
                if (
                    isset($item[$field]) &&
                    trim((string)$item[$field]) !== ''
                ) {
                    $details[] =
                        ucfirst($field) .
                        ': ' .
                        trim((string)$item[$field]);
                }
            }

            $addons = trim(
                (string)($item['addons'] ?? '')
            );

            if ($addons !== '') {
                $details[] =
                    'Add-ons: ' . $addons;
            }

            $nataQty = (int)(
                $item['nata_addon_qty'] ?? 0
            );

            if ($nataQty > 0) {
                $details[] =
                    'Nata Add-on: ' . $nataQty;
            }

            $special = trim(
                (string)(
                    $item['special_instructions'] ?? ''
                )
            );

            if ($special !== '') {
                $details[] =
                    'Note: ' . $special;
            }
            ?>

            <?php foreach ($details as $detail): ?>

                <div class="item-detail">
                    <?php echo h($detail); ?>
                </div>

            <?php endforeach; ?>

        </div>

        <div class="line"></div>

    <?php endforeach; ?>

    <div class="center">
        <strong>KITCHEN STAFF COPY</strong>
    </div>

</div>

<div class="thermal-print">
<pre><?php
echo h(
    implode(
        "\n",
        $thermalLines
    )
);
?></pre>
</div>

<div class="print">
    <button
        type="button"
        onclick="window.print()"
    >
        Print Kitchen Order
    </button>
</div>

</body>
</html>
