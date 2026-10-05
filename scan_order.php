<?php
// BLACKHABIT: Cashier uses its own independent session.
if (session_status() === PHP_SESSION_NONE) {
    session_name('BH_CASHIER_SESSION');
    session_start();
}

/*
|--------------------------------------------------------------------------
| CASHIER ACCESS
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['role']) ||
    (
        strtolower(trim((string)$_SESSION['role'])) !== 'cashier' &&
        (int)$_SESSION['role'] !== 3
    )
) {
    // AJAX must return JSON, never an HTML login page.
    $isAjaxRequest = isset($_GET['ajax']) && $_GET['ajax'] === '1';
    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Cashier session expired. Please log in again.'
        ]);
        exit();
    }

    header("Location: index.php");
    exit();
}

include "db.php";

/*
|--------------------------------------------------------------------------
| GET QR CODE
|--------------------------------------------------------------------------
|
| Current Flutter QR format:
| BLACKHABIT_ORDER:<order_id>
|
| Example:
| BLACKHABIT_ORDER:132
|
| Older receipt-number QR codes are also supported.
|
*/

$code = isset($_GET['code'])
    ? trim($_GET['code'])
    : '';

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';


/*
|--------------------------------------------------------------------------
| AJAX QR LOOKUP FOR CASHIER POS
|--------------------------------------------------------------------------
|
| cashier_pos.php uses:
|
| scan_order.php?code=...&ajax=1
|
| This branch MUST:
|
| 1. Find the order
| 2. Update Pending -> Scanned
| 3. Return the REAL database status
|
| This "Scanned" status is what the Flutter customer application
| watches before opening the receipt screen.
|
*/

if ($ajax) {

    header('Content-Type: application/json; charset=UTF-8');

    /*
    |--------------------------------------------------------------------------
    | VALIDATE QR CODE
    |--------------------------------------------------------------------------
    */

    if ($code === '') {
        echo json_encode([
            'success' => false,
            'message' => 'No QR code was received.'
        ]);
        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | FIND ORDER
    |--------------------------------------------------------------------------
    */

    $orderId = 0;

    $prefix = 'BLACKHABIT_ORDER:';

    /*
    |--------------------------------------------------------------------------
    | CURRENT FLUTTER QR FORMAT
    |--------------------------------------------------------------------------
    */

    if (
        strncasecmp(
            $code,
            $prefix,
            strlen($prefix)
        ) === 0
    ) {

        $idPart = trim(
            substr(
                $code,
                strlen($prefix)
            )
        );

        if (
            $idPart !== '' &&
            ctype_digit($idPart)
        ) {
            $orderId = (int)$idPart;
        }

        if ($orderId <= 0) {

            echo json_encode([
                'success' => false,
                'message' => 'Invalid BlackHabit order QR code.'
            ]);

            exit();
        }


        /*
        |--------------------------------------------------------------------------
        | SEARCH BY ORDER ID
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                id,
                receipt_number,
                status,
                order_type
            FROM orders
            WHERE id = ?
            LIMIT 1
            "
        );

        if (!$stmt) {

            echo json_encode([
                'success' => false,
                'message' => 'Unable to search for the order.'
            ]);

            exit();
        }

        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $orderId
        );

        if (!mysqli_stmt_execute($stmt)) {

            mysqli_stmt_close($stmt);

            echo json_encode([
                'success' => false,
                'message' => 'Unable to search for the order.'
            ]);

            exit();
        }

        $result = mysqli_stmt_get_result($stmt);

        $found = $result
            ? mysqli_fetch_assoc($result)
            : null;

        mysqli_stmt_close($stmt);

    } else {

        /*
        |--------------------------------------------------------------------------
        | BACKWARD COMPATIBILITY
        |--------------------------------------------------------------------------
        |
        | Older QR codes used receipt_number.
        |
        */

        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                id,
                receipt_number,
                status,
                order_type
            FROM orders
            WHERE receipt_number = ?
            LIMIT 1
            "
        );

        if (!$stmt) {

            echo json_encode([
                'success' => false,
                'message' => 'Unable to search for the order.'
            ]);

            exit();
        }

        mysqli_stmt_bind_param(
            $stmt,
            's',
            $code
        );

        if (!mysqli_stmt_execute($stmt)) {

            mysqli_stmt_close($stmt);

            echo json_encode([
                'success' => false,
                'message' => 'Unable to search for the order.'
            ]);

            exit();
        }

        $result = mysqli_stmt_get_result($stmt);

        $found = $result
            ? mysqli_fetch_assoc($result)
            : null;

        mysqli_stmt_close($stmt);

        if ($found) {
            $orderId = (int)$found['id'];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ORDER NOT FOUND
    |--------------------------------------------------------------------------
    */

    if (!$found || $orderId <= 0) {

        echo json_encode([
            'success' => false,
            'message' =>
                'No order was found for QR code: ' .
                $code
        ]);

        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | CURRENT STATUS
    |--------------------------------------------------------------------------
    */

    $currentStatus = trim(
        (string)($found['status'] ?? '')
    );

    $normalizedStatus = strtolower(
        $currentStatus
    );


    /*
    |--------------------------------------------------------------------------
    | DO NOT RE-SCAN COMPLETED / CANCELLED ORDERS
    |--------------------------------------------------------------------------
    |
    | Only Pending and Scanned are allowed to become Scanned.
    |
    | If the order is already Processing, Ready, Completed,
    | Cancelled, etc., do not move it backwards.
    |
    */

    if (
        $normalizedStatus !== 'pending' &&
        $normalizedStatus !== 'scanned'
    ) {

        echo json_encode([
            'success' => false,
            'message' =>
                'This order cannot be scanned because its current status is "' .
                $currentStatus .
                '".',
            'order_id' => $orderId,
            'receipt_number' =>
                (string)($found['receipt_number'] ?? ''),
            'status' => $currentStatus
        ]);

        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | MARK ORDER AS SCANNED
    |--------------------------------------------------------------------------
    |
    | THIS IS THE IMPORTANT FIX.
    |
    | The old AJAX branch only returned:
    |
    |     status => Scanned
    |
    | without changing the database.
    |
    | Now the database itself becomes:
    |
    |     Pending -> Scanned
    |
    | Flutter get_order_status.php can then detect it.
    |
    */

    $mark = mysqli_prepare(
        $conn,
        "
        UPDATE orders
        SET status = 'Scanned'
        WHERE id = ?
        AND LOWER(TRIM(status)) IN ('pending', 'scanned')
        "
    );

    if (!$mark) {

        echo json_encode([
            'success' => false,
            'message' =>
                'Unable to update the order status.'
        ]);

        exit();
    }

    mysqli_stmt_bind_param(
        $mark,
        'i',
        $orderId
    );

    if (!mysqli_stmt_execute($mark)) {

        mysqli_stmt_close($mark);

        echo json_encode([
            'success' => false,
            'message' =>
                'Unable to mark the order as scanned.'
        ]);

        exit();
    }

    mysqli_stmt_close($mark);


    /*
    |--------------------------------------------------------------------------
    | VERIFY ACTUAL DATABASE STATUS
    |--------------------------------------------------------------------------
    |
    | Read the status again instead of assuming the update worked.
    |
    */

    $verifyStmt = mysqli_prepare(
        $conn,
        "
        SELECT
            id,
            receipt_number,
            status,
            order_type
        FROM orders
        WHERE id = ?
        LIMIT 1
        "
    );

    if (!$verifyStmt) {

        echo json_encode([
            'success' => false,
            'message' =>
                'Order was scanned, but the new status could not be verified.'
        ]);

        exit();
    }

    mysqli_stmt_bind_param(
        $verifyStmt,
        'i',
        $orderId
    );

    if (!mysqli_stmt_execute($verifyStmt)) {

        mysqli_stmt_close($verifyStmt);

        echo json_encode([
            'success' => false,
            'message' =>
                'Order was scanned, but the new status could not be verified.'
        ]);

        exit();
    }

    $verifyResult =
        mysqli_stmt_get_result($verifyStmt);

    $verifiedOrder =
        $verifyResult
            ? mysqli_fetch_assoc($verifyResult)
            : null;

    mysqli_stmt_close($verifyStmt);


    if (!$verifiedOrder) {

        echo json_encode([
            'success' => false,
            'message' =>
                'Order was scanned, but verification failed.'
        ]);

        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | RETURN SUCCESS
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' =>
            'Order scanned successfully.',
        'order_id' =>
            (int)$verifiedOrder['id'],
        'receipt_number' =>
            (string)(
                $verifiedOrder['receipt_number']
                ?? ''
            ),
        'status' =>
            (string)(
                $verifiedOrder['status']
                ?? 'Scanned'
            ),
        'order_type' =>
            (string)(
                $verifiedOrder['order_type']
                ?? ''
            )
    ]);

    exit();
}


/*
|--------------------------------------------------------------------------
| IF NO QR CODE WAS PROVIDED
|--------------------------------------------------------------------------
|
| Display the standalone cashier scanner page.
|
*/

if ($code === '') {
?>

<!DOCTYPE html>

<html>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Scan QR Code</title>

    <script src="https://unpkg.com/html5-qrcode"></script>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #111;
            color: white;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 700px;
            margin: auto;
            padding: 30px 20px;
            text-align: center;
        }

        .logo {
            font-size: 30px;
            font-weight: 700;
            letter-spacing: 2px;
            margin-top: 20px;
        }

        .subtitle {
            color: #c9a15b;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .scanner-card {
            background: #1b1b1b;
            border: 1px solid #333;
            border-radius: 18px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,.3);
        }

        h2 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .description {
            color: #aaa;
            font-size: 14px;
            margin-bottom: 25px;
        }

        #reader {
            width: 100%;
            max-width: 500px;
            margin: auto;
            overflow: hidden;
            border-radius: 15px;
        }

        .start-btn {
            border: none;
            background: #c9a15b;
            color: #111;
            padding: 14px 28px;
            border-radius: 10px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 20px;
        }

        .start-btn:hover {
            opacity: .9;
        }

        .back-btn {
            display: inline-block;
            margin-top: 20px;
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        .back-btn:hover {
            color: #c9a15b;
        }

        @media(max-width:600px) {

            .container {
                padding: 20px 12px;
            }

            .logo {
                font-size: 24px;
            }

            .scanner-card {
                padding: 18px;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="logo">
        BLACKHABIT
    </div>

    <div class="subtitle">
        CASHIER PANEL
    </div>

    <div class="scanner-card">

        <h2>
            <i class="fa-solid fa-qrcode"></i>
            Scan Customer QR
        </h2>

        <p class="description">
            Scan the QR code displayed on the customer's mobile application.
        </p>

        <button
            type="button"
            class="start-btn"
            onclick="startScanner()"
            id="startButton"
        >
            <i class="fa-solid fa-camera"></i>
            Start Scanner
        </button>

        <div id="reader"></div>

        <a
            href="cashier_dashboard.php"
            class="back-btn"
        >
            <i class="fa-solid fa-arrow-left"></i>
            Back to Dashboard
        </a>

    </div>

</div>


<script>

    let scanner = null;
    let scanning = false;
    let scanProcessing = false;


    /*
    |--------------------------------------------------------------------------
    | START SCANNER
    |--------------------------------------------------------------------------
    */

    function startScanner() {

        if (scanning || scanProcessing) {
            return;
        }

        scanner = new Html5Qrcode("reader");

        scanning = true;

        scanner.start(
            {
                facingMode: "environment"
            },
            {
                fps: 10,
                qrbox: {
                    width: 250,
                    height: 250
                }
            },

            /*
            |--------------------------------------------------------------------------
            | QR SUCCESS
            |--------------------------------------------------------------------------
            */

            function(decodedText) {

                if (!scanning || scanProcessing) {
                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | LOCK IMMEDIATELY
                |--------------------------------------------------------------------------
                |
                | Prevents the same QR from triggering multiple requests.
                |
                */

                scanning = false;
                scanProcessing = true;

                console.log(
                    "QR CODE:",
                    decodedText
                );


                /*
                |--------------------------------------------------------------------------
                | STOP CAMERA
                |--------------------------------------------------------------------------
                */

                scanner.stop()
                    .catch(function(error) {

                        console.log(
                            "Scanner stop:",
                            error
                        );

                    })
                    .finally(function() {

                        return scanner.clear()
                            .catch(function(error) {

                                console.log(
                                    "Scanner clear:",
                                    error
                                );

                            });

                    })
                    .finally(function() {

                        /*
                        |--------------------------------------------------------------------------
                        | SEND QR TO AJAX ENDPOINT
                        |--------------------------------------------------------------------------
                        */

                        fetch(
                            "scan_order.php?code=" +
                            encodeURIComponent(decodedText) +
                            "&ajax=1",
                            {
                                method: "GET",
                                headers: {
                                    "Accept":
                                        "application/json"
                                },
                                cache: "no-store"
                            }
                        )

                        .then(function(response) {

                            if (!response.ok) {

                                throw new Error(
                                    "HTTP " +
                                    response.status
                                );

                            }

                            return response.json();

                        })

                        .then(function(data) {

                            console.log(
                                "SCAN RESPONSE:",
                                data
                            );


                            /*
                            |--------------------------------------------------------------------------
                            | SUCCESS
                            |--------------------------------------------------------------------------
                            */

                            if (
                                data &&
                                data.success === true
                            ) {

                                /*
                                |--------------------------------------------------------------------------
                                | Go directly to cashier POS
                                |--------------------------------------------------------------------------
                                */

                                window.location.href =
                                    "cashier_pos.php?scanned_order_id=" +
                                    encodeURIComponent(
                                        data.order_id
                                    );

                                return;
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | SCAN FAILED
                            |--------------------------------------------------------------------------
                            */

                            alert(
                                data &&
                                data.message
                                    ? data.message
                                    : "Unable to scan the order."
                            );

                            scanProcessing = false;

                            window.location.href =
                                "scan_order.php";

                        })

                        .catch(function(error) {

                            console.error(
                                "QR scan request error:",
                                error
                            );

                            alert(
                                "Unable to process the scanned QR code.\n\n" +
                                "Please try again."
                            );

                            scanProcessing = false;

                            window.location.href =
                                "scan_order.php";

                        });

                    });

            },

            /*
            |--------------------------------------------------------------------------
            | NORMAL SCANNER ERRORS
            |--------------------------------------------------------------------------
            |
            | html5-qrcode sends many normal "QR not found yet" messages.
            | Do not show an alert for those.
            |
            */

            function(errorMessage) {

                // Normal scanning errors are ignored.

            }
        )

        .catch(function(error) {

            scanning = false;
            scanProcessing = false;

            alert(
                "Unable to start camera.\n\n" +
                "Please allow camera permission and try again."
            );

            console.error(
                "Camera error:",
                error
            );

        });

    }

</script>

</body>

</html>

<?php

    exit();
}


/*
|--------------------------------------------------------------------------
| NON-AJAX SCAN
|--------------------------------------------------------------------------
|
| This is used when scan_order.php is opened directly with:
|
| scan_order.php?code=BLACKHABIT_ORDER:132
|
|--------------------------------------------------------------------------
*/


$orderId = 0;

$prefix = 'BLACKHABIT_ORDER:';


/*
|--------------------------------------------------------------------------
| CURRENT FLUTTER QR FORMAT
|--------------------------------------------------------------------------
*/

if (
    strncasecmp(
        $code,
        $prefix,
        strlen($prefix)
    ) === 0
) {

    $idPart = trim(
        substr(
            $code,
            strlen($prefix)
        )
    );

    if (
        $idPart !== '' &&
        ctype_digit($idPart)
    ) {
        $orderId = (int)$idPart;
    }

    if ($orderId > 0) {

        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                id,
                receipt_number,
                status,
                order_type
            FROM orders
            WHERE id = ?
            LIMIT 1
            "
        );

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                'i',
                $orderId
            );

            if (mysqli_stmt_execute($stmt)) {

                $query =
                    mysqli_stmt_get_result($stmt);

            } else {

                $query = false;
            }

            mysqli_stmt_close($stmt);

        } else {

            $query = false;
        }

    } else {

        $query = false;
    }

} else {

    /*
    |--------------------------------------------------------------------------
    | OLD RECEIPT NUMBER FORMAT
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            id,
            receipt_number,
            status,
            order_type
        FROM orders
        WHERE receipt_number = ?
        LIMIT 1
        "
    );

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            's',
            $code
        );

        if (mysqli_stmt_execute($stmt)) {

            $query =
                mysqli_stmt_get_result($stmt);

        } else {

            $query = false;
        }

        mysqli_stmt_close($stmt);

    } else {

        $query = false;
    }
}


/*
|--------------------------------------------------------------------------
| QR CODE NOT FOUND
|--------------------------------------------------------------------------
*/

if (
    !$query ||
    mysqli_num_rows($query) === 0
) {

?>

<!DOCTYPE html>

<html>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>QR Code Not Found</title>

    <style>

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #111;
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            text-align: center;
        }

        .box {
            background: #1c1c1c;
            padding: 35px;
            border-radius: 15px;
            width: 90%;
            max-width: 450px;
        }

        .icon {
            font-size: 55px;
            margin-bottom: 15px;
        }

        h2 {
            margin-bottom: 10px;
        }

        p {
            color: #aaa;
            word-break: break-word;
        }

        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 25px;
            background: #c9a15b;
            color: #111;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
        }

    </style>

</head>

<body>

    <div class="box">

        <div class="icon">
            ❌
        </div>

        <h2>
            QR Code Not Found
        </h2>

        <p>
            No order was found for:
        </p>

        <strong>
            <?php
            echo htmlspecialchars(
                $code,
                ENT_QUOTES,
                'UTF-8'
            );
            ?>
        </strong>

        <br>

        <a
            href="scan_order.php"
            class="btn"
        >
            Scan Again
        </a>

    </div>

</body>

</html>

<?php

    exit();
}


/*
|--------------------------------------------------------------------------
| ORDER FOUND
|--------------------------------------------------------------------------
*/

$order = mysqli_fetch_assoc($query);

$orderId = (int)$order['id'];

$currentStatus = trim(
    (string)($order['status'] ?? '')
);

$normalizedStatus = strtolower(
    $currentStatus
);


/*
|--------------------------------------------------------------------------
| DO NOT MOVE COMPLETED/CANCELLED ORDERS BACKWARD
|--------------------------------------------------------------------------
*/

if (
    $normalizedStatus !== 'pending' &&
    $normalizedStatus !== 'scanned'
) {

    header(
        "Location: cashier_pos.php?scanned_order_id=" .
        $orderId
    );

    exit();
}


/*
|--------------------------------------------------------------------------
| MARK ORDER AS SCANNED
|--------------------------------------------------------------------------
*/

$mark = mysqli_prepare(
    $conn,
    "
    UPDATE orders
    SET status = 'Scanned'
    WHERE id = ?
    AND LOWER(TRIM(status)) IN ('pending', 'scanned')
    "
);

if (!$mark) {

    die(
        'Unable to update the order status.'
    );
}

mysqli_stmt_bind_param(
    $mark,
    'i',
    $orderId
);

if (!mysqli_stmt_execute($mark)) {

    mysqli_stmt_close($mark);

    die(
        'Unable to mark the order as scanned.'
    );
}

mysqli_stmt_close($mark);


/*
|--------------------------------------------------------------------------
| OPEN CASHIER POS
|--------------------------------------------------------------------------
*/

header(
    "Location: cashier_pos.php?scanned_order_id=" .
    $orderId
);

exit();

?>