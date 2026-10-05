<?php

/*
|--------------------------------------------------------------------------
| BLACK HABIT - FORGOT PASSWORD API
|--------------------------------------------------------------------------
| Flutter flow:
|
| 1. User enters Gmail address.
| 2. This API generates a 6-digit verification code.
| 3. Code is stored in users.verification_code.
| 4. Code expires after 5 minutes.
| 5. Code is emailed using PHP mail().
| 6. Flutter opens reset_password_screen.dart.
|
| Existing website password-reset files are not used or modified here.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_config.php';

function response_json($success, $message, $code = 200, $extra = array())
{
    http_response_code($code);

    $response = array(
        'success' => (bool)$success,
        'status'  => (bool)$success,
        'message' => $message
    );

    if (is_array($extra) && !empty($extra)) {
        $response = array_merge($response, $extra);
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | ONLY POST
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response_json(
            false,
            'POST request required.',
            405
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE
    |--------------------------------------------------------------------------
    */

    if (!isset($conn) || !($conn instanceof mysqli)) {
        response_json(
            false,
            'Database connection failed.',
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | READ FLUTTER JSON
    |--------------------------------------------------------------------------
    */

    $rawInput = file_get_contents('php://input');

    if ($rawInput === false || trim($rawInput) === '') {
        response_json(
            false,
            'Empty request body.',
            400
        );
    }

    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        response_json(
            false,
            'Invalid JSON data.',
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EMAIL
    |--------------------------------------------------------------------------
    */

    $email = strtolower(
        trim(
            (string)(
                isset($input['email'])
                    ? $input['email']
                    : ''
            )
        )
    );

    if (
        $email === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        response_json(
            false,
            'Please enter a valid email address.',
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BLACK HABIT CUSTOMER ACCOUNTS USE GMAIL
    |--------------------------------------------------------------------------
    */

    if (
        strlen($email) < 10 ||
        substr($email, -10) !== '@gmail.com'
    ) {
        response_json(
            false,
            'Please use a Gmail address.',
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GENERIC MESSAGE
    |--------------------------------------------------------------------------
    | This avoids revealing whether an account exists.
    |--------------------------------------------------------------------------
    */

    $genericMessage =
        'If an account exists for this email, a 6-digit reset code has been sent to your Gmail.';

    /*
    |--------------------------------------------------------------------------
    | FIND CUSTOMER
    |--------------------------------------------------------------------------
    | role = 3 is used for BLACK HABIT customer accounts.
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            email_verified
        FROM users
        WHERE LOWER(email) = ?
          AND role = 3
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception(
            'User lookup prepare failed: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $email
    );

    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        throw new Exception(
            'User lookup execute failed: ' .
            $error
        );
    }

    if (!mysqli_stmt_store_result($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        throw new Exception(
            'User result failed: ' .
            $error
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ACCOUNT NOT FOUND
    |--------------------------------------------------------------------------
    */

    if (mysqli_stmt_num_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);

        response_json(
            true,
            $genericMessage
        );
    }

    /*
    |--------------------------------------------------------------------------
    | USER DATA
    |--------------------------------------------------------------------------
    */

    $userId = null;
    $firstName = null;
    $middleName = null;
    $lastName = null;
    $userEmail = null;
    $emailVerified = null;

    mysqli_stmt_bind_result(
        $stmt,
        $userId,
        $firstName,
        $middleName,
        $lastName,
        $userEmail,
        $emailVerified
    );

    if (!mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);

        throw new Exception(
            'Unable to read the customer account.'
        );
    }

    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | EMAIL MUST BE VERIFIED
    |--------------------------------------------------------------------------
    */

    if ((int)$emailVerified !== 1) {
        response_json(
            true,
            $genericMessage
        );
    }

    $userId = (int)$userId;

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER NAME
    |--------------------------------------------------------------------------
    */

    $nameParts = array(
        trim((string)$firstName),
        trim((string)$middleName),
        trim((string)$lastName)
    );

    $cleanNameParts = array();

    foreach ($nameParts as $part) {
        if ($part !== '') {
            $cleanNameParts[] = $part;
        }
    }

    $fullName = implode(
        ' ',
        $cleanNameParts
    );

    if ($fullName === '') {
        $fullName = 'BLACK HABIT Customer';
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE 6-DIGIT CODE
    |--------------------------------------------------------------------------
    */

    $resetCode = str_pad(
        (string)random_int(0, 999999),
        6,
        '0',
        STR_PAD_LEFT
    );

    /*
    |--------------------------------------------------------------------------
    | 5-MINUTE EXPIRATION
    |--------------------------------------------------------------------------
    */

    $expiresAt = date(
        'Y-m-d H:i:s',
        time() + (5 * 60)
    );

    /*
    |--------------------------------------------------------------------------
    | SAVE CODE
    |--------------------------------------------------------------------------
    |
    | Uses the existing verification_code and verification_expires
    | columns in the users table.
    |--------------------------------------------------------------------------
    */

    $updateSql = "
        UPDATE users
        SET
            verification_code = ?,
            verification_expires = ?
        WHERE id = ?
        LIMIT 1
    ";

    $updateStmt = mysqli_prepare(
        $conn,
        $updateSql
    );

    if (!$updateStmt) {
        throw new Exception(
            'Reset code update prepare failed: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $updateStmt,
        'ssi',
        $resetCode,
        $expiresAt,
        $userId
    );

    if (!mysqli_stmt_execute($updateStmt)) {
        $error = mysqli_stmt_error($updateStmt);
        mysqli_stmt_close($updateStmt);

        throw new Exception(
            'Reset code update failed: ' .
            $error
        );
    }

    mysqli_stmt_close($updateStmt);

    /*
    |--------------------------------------------------------------------------
    | EMAIL CONTENT
    |--------------------------------------------------------------------------
    */

    $subject = 'BLACK HABIT - Your Password Reset Code';

    $safeName = htmlspecialchars(
        $fullName,
        ENT_QUOTES,
        'UTF-8'
    );

    $safeCode = htmlspecialchars(
        $resetCode,
        ENT_QUOTES,
        'UTF-8'
    );

    $message = '
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>BLACK HABIT Password Reset</title>
</head>

<body style="
    margin:0;
    padding:30px 15px;
    background:#f2f2f2;
    font-family:Arial,Helvetica,sans-serif;
">

<div style="
    max-width:550px;
    margin:0 auto;
    background:#262424;
    border-radius:18px;
    overflow:hidden;
">

    <div style="
        background:#111111;
        padding:30px 20px;
        text-align:center;
    ">

        <h1 style="
            margin:0;
            color:#F1B812;
            font-size:28px;
            letter-spacing:5px;
        ">
            BLACK HABIT
        </h1>

        <p style="
            margin:8px 0 0;
            color:#cccccc;
            font-size:13px;
            letter-spacing:2px;
        ">
            PASSWORD RESET
        </p>

    </div>

    <div style="
        padding:35px 25px;
        text-align:center;
    ">

        <h2 style="
            color:#ffffff;
            margin:0 0 20px;
        ">
            Reset Your Password
        </h2>

        <p style="
            color:#cccccc;
            font-size:15px;
            line-height:1.6;
        ">
            Hello <strong>' . $safeName . '</strong>,
        </p>

        <p style="
            color:#cccccc;
            font-size:15px;
            line-height:1.6;
        ">
            We received a request to reset your
            BLACK HABIT password.
        </p>

        <p style="
            color:#cccccc;
            font-size:14px;
            line-height:1.5;
            margin-bottom:10px;
        ">
            Enter this 6-digit code in the BLACK HABIT app:
        </p>

        <div style="
            display:inline-block;
            background:#111111;
            border:2px solid #F1B812;
            border-radius:12px;
            padding:18px 28px;
            margin:10px 0 20px;
        ">

            <span style="
                color:#F1B812;
                font-size:34px;
                font-weight:bold;
                letter-spacing:9px;
                font-family:Arial,Helvetica,sans-serif;
            ">
                ' . $safeCode . '
            </span>

        </div>

        <p style="
            color:#999999;
            font-size:13px;
            line-height:1.6;
        ">
            This code will expire in
            <strong style="color:#F1B812;">
                5 minutes
            </strong>.
        </p>

        <p style="
            color:#777777;
            font-size:12px;
            line-height:1.5;
            margin-top:25px;
        ">
            If you did not request a password reset,
            you can safely ignore this email.
        </p>

    </div>

    <div style="
        background:#111111;
        padding:18px;
        text-align:center;
    ">

        <p style="
            margin:0;
            color:#888888;
            font-size:11px;
        ">
            BLACK HABIT
        </p>

    </div>

</div>

</body>
</html>
';

    /*
    |--------------------------------------------------------------------------
    | SEND EMAIL USING PHP MAIL()
    |--------------------------------------------------------------------------
    |
    | This matches the existing BLACK HABIT mail flow.
    | No PHPMailer / SMTP connection is required here.
    |--------------------------------------------------------------------------
    */

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $headers = array();

    $headers[] = 'MIME-Version: 1.0';

    $headers[] =
        'Content-Type: text/html; charset=UTF-8';

    $headers[] =
        'Content-Transfer-Encoding: 8bit';

    $headers[] =
        'From: ' .
        MAIL_FROM_NAME .
        ' <' .
        MAIL_FROM_EMAIL .
        '>';

    $headers[] =
        'Reply-To: ' .
        MAIL_FROM_EMAIL;

    $headers[] =
        'X-Mailer: PHP/' .
        phpversion();

    $result = @mail(
        $email,
        $encodedSubject,
        $message,
        implode("\r\n", $headers)
    );

    /*
    |--------------------------------------------------------------------------
    | MAIL FAILED
    |--------------------------------------------------------------------------
    | Clear the reset code so a code that was not delivered cannot be used.
    |--------------------------------------------------------------------------
    */

    if (!$result) {

        $clearStmt = mysqli_prepare(
            $conn,
            "
            UPDATE users
            SET
                verification_code = NULL,
                verification_expires = NULL
            WHERE id = ?
            LIMIT 1
            "
        );

        if ($clearStmt) {

            mysqli_stmt_bind_param(
                $clearStmt,
                'i',
                $userId
            );

            mysqli_stmt_execute(
                $clearStmt
            );

            mysqli_stmt_close(
                $clearStmt
            );
        }

        error_log(
            'BLACK HABIT FORGOT PASSWORD: PHP mail() failed for ' .
            $email
        );

        response_json(
            false,
            'We could not send the password reset code. Please try again later.',
            503
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    response_json(
        true,
        'A 6-digit password reset code has been sent to your Gmail. The code is valid for 5 minutes.',
        200,
        array(
            'expires_in_seconds' => 300
        )
    );

} catch (Throwable $e) {

    error_log(
        'BLACK HABIT FORGOT PASSWORD ERROR: ' .
        $e->getMessage()
    );

    response_json(
        false,
        'Password reset server error. Please try again later.',
        500
    );
}

?>
