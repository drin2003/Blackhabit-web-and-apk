<?php

require_once __DIR__ . '/mail_config.php';

function sendVerificationEmail(
    string $email,
    string $fullName,
    string $token
): bool {

    $subject = 'BLACK HABIT - Verification Code';

    $safeName = htmlspecialchars(
        $fullName,
        ENT_QUOTES,
        'UTF-8'
    );

    $safeToken = htmlspecialchars(
        $token,
        ENT_QUOTES,
        'UTF-8'
    );

    $message = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLACK HABIT Verification</title>
</head>

<body style="
    margin:0;
    padding:30px 15px;
    background:#f2f2f2;
    font-family:Arial,Helvetica,sans-serif;
">

<div style="
    max-width:500px;
    margin:0 auto;
    background:#ffffff;
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 5px 20px rgba(0,0,0,0.08);
">

    <div style="
        background:#111111;
        padding:30px 20px;
        text-align:center;
    ">
        <h1 style="
            margin:0;
            color:#ffffff;
            font-size:28px;
            letter-spacing:5px;
        ">
            BLACK HABIT
        </h1>

        <p style="
            margin:8px 0 0;
            color:#c9a03c;
            font-size:13px;
            letter-spacing:2px;
        ">
            EMAIL VERIFICATION
        </p>
    </div>

    <div style="
        padding:35px 25px;
        text-align:center;
    ">

        <p style="
            margin:0 0 15px;
            color:#333333;
            font-size:16px;
        ">
            Hello <strong>' . $safeName . '</strong>,
        </p>

        <p style="
            margin:0 0 20px;
            color:#555555;
            font-size:15px;
            line-height:1.6;
        ">
            Your BLACK HABIT verification code is:
        </p>

        <div style="
            display:inline-block;
            background:#f8f3e8;
            border:2px solid #c9a03c;
            border-radius:12px;
            padding:18px 28px;
            margin:5px 0 25px;
        ">
            <span style="
                color:#111111;
                font-size:34px;
                font-weight:bold;
                letter-spacing:8px;
            ">
                ' . $safeToken . '
            </span>
        </div>

        <p style="
            margin:0;
            color:#777777;
            font-size:14px;
            line-height:1.6;
        ">
            This code expires in
            <strong>2 minutes</strong>.
        </p>

        <p style="
            margin:25px 0 0;
            color:#999999;
            font-size:12px;
            line-height:1.6;
        ">
            If you did not create a BLACK HABIT account,
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

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $headers = [];

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

    $result = mail(
        $email,
        $encodedSubject,
        $message,
        implode("\r\n", $headers)
    );

    if (!$result) {
        error_log(
            'BLACK HABIT: PHP mail() failed for ' .
            $email
        );

        return false;
    }

    return true;
}
?>