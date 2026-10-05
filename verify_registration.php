<?php
// Start session.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

$message = '';
$status = 'error';
$verified = false;
$displayEmail = $_SESSION['registration_email'] ?? '';
$verificationExpires = null;

/*
|--------------------------------------------------------------------------
| Get the current registration verification expiry.
|--------------------------------------------------------------------------
| The registration page stores the expiry in users.verification_expires.
| We read it on both GET and POST so the countdown can start immediately.
|--------------------------------------------------------------------------
*/
if ($displayEmail !== '') {
    $expiryStmt = mysqli_prepare(
        $conn,
        'SELECT email, verification_expires
         FROM users
         WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
         LIMIT 1'
    );

    if ($expiryStmt) {
        mysqli_stmt_bind_param($expiryStmt, 's', $displayEmail);
        mysqli_stmt_execute($expiryStmt);
        mysqli_stmt_store_result($expiryStmt);

        if (mysqli_stmt_num_rows($expiryStmt) > 0) {
            mysqli_stmt_bind_result(
                $expiryStmt,
                $foundEmail,
                $foundVerificationExpires
            );
            mysqli_stmt_fetch($expiryStmt);

            $displayEmail = $foundEmail;
            $verificationExpires = $foundVerificationExpires;
        }

        mysqli_stmt_close($expiryStmt);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $otp = trim($_POST['otp'] ?? '');
    $email = strtolower(trim($_SESSION['registration_email'] ?? ''));

    // Validate the submitted OTP.
    if ($email === '') {

        $message =
            'Your registration session has expired. Please register again.';

    } elseif (!preg_match('/^[0-9]{6}$/', $otp)) {

        $message =
            'Please enter the 6-digit verification code.';

    } else {

        // Find the pending account.
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id, email, email_verified, verification_code, verification_expires
             FROM users
             WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1'
        );

        if (!$stmt) {

            error_log(
                'BLACKHABIT VERIFY PREPARE ERROR: ' .
                mysqli_error($conn)
            );

            $message =
                'Database error. Please try again.';

        } else {

            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if (mysqli_stmt_num_rows($stmt) === 0) {

                mysqli_stmt_close($stmt);

                $message =
                    'Registration account not found. Please register again.';

            } else {

                mysqli_stmt_bind_result(
                    $stmt,
                    $userId,
                    $userEmail,
                    $emailVerified,
                    $storedOtpHash,
                    $verificationExpires
                );

                mysqli_stmt_fetch($stmt);
                mysqli_stmt_close($stmt);

                $displayEmail = $userEmail;

                if ((int)$emailVerified === 1) {

                    $verified = true;
                    $status = 'success';

                    $message =
                        'Your email address is already verified.';

                    unset($_SESSION['registration_email']);

                } elseif (
                    empty($verificationExpires) ||
                    strtotime($verificationExpires) === false
                ) {

                    $message =
                        'This verification code is no longer valid. Please register again.';

                } elseif (strtotime($verificationExpires) < time()) {

                    $message =
                        'This verification code has expired. Please register again.';

                } else {

                    // Compare the submitted OTP with the stored hash.
                    $otpHash = hash('sha256', $otp);

                    if (!hash_equals(
                        (string)$storedOtpHash,
                        $otpHash
                    )) {

                        $message =
                            'Incorrect verification code. Please try again.';

                    } else {

                        // Activate the account.
                        $update = mysqli_prepare(
                            $conn,
                            'UPDATE users
                             SET email_verified = 1,
                                 verification_code = NULL,
                                 verification_expires = NULL
                             WHERE id = ?
                               AND email_verified = 0
                             LIMIT 1'
                        );

                        if (!$update) {

                            error_log(
                                'BLACKHABIT VERIFY UPDATE PREPARE ERROR: ' .
                                mysqli_error($conn)
                            );

                            $message =
                                'Database error. Please try again.';

                        } else {

                            $id = (int)$userId;

                            mysqli_stmt_bind_param(
                                $update,
                                'i',
                                $id
                            );

                            if (
                                mysqli_stmt_execute($update) &&
                                mysqli_stmt_affected_rows($update) === 1
                            ) {

                                $verified = true;
                                $status = 'success';

                                $message =
                                    'Your email has been verified successfully! You can now log in.';

                                $_SESSION['registration_success'] =
                                    $message;

                                unset(
                                    $_SESSION['registration_email']
                                );

                                $verificationExpires = null;

                            } else {

                                error_log(
                                    'BLACKHABIT VERIFY UPDATE ERROR: ' .
                                    mysqli_stmt_error($update)
                                );

                                $message =
                                    'Unable to verify your account. Please try again.';
                            }

                            mysqli_stmt_close($update);
                        }
                    }
                }
            }
        }
    }
}

if (
    !$verified &&
    $message === '' &&
    $displayEmail === ''
) {
    $message =
        'No registration verification is waiting. Please register first.';
}

/*
|--------------------------------------------------------------------------
| Countdown seconds
|--------------------------------------------------------------------------
*/
$remainingSeconds = 0;

if (
    !$verified &&
    !empty($verificationExpires) &&
    strtotime($verificationExpires) !== false
) {
    $remainingSeconds = max(
        0,
        strtotime($verificationExpires) - time()
    );
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

<title>Verify Account - BLACKHABIT</title>

<link
    rel="icon"
    type="image/x-icon"
    href="favicon_io/favicon.ico"
>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    background: #0f0f0f;
    color: #fff;
    font-family: Arial, Helvetica, sans-serif;
}

.box {
    width: 100%;
    max-width: 450px;
    background: #191919;
    border: 1px solid #303030;
    border-radius: 16px;
    padding: 40px;
    box-shadow: 0 20px 50px rgba(0,0,0,.45);
    text-align: center;
}

.logo h1 {
    margin: 0;
    font-size: 32px;
    letter-spacing: 2px;
}

.logo p {
    margin: 6px 0 28px;
    color: #c59d5f;
}

.icon {
    font-size: 54px;
    margin-bottom: 12px;
}

h2 {
    margin: 0 0 12px;
    font-size: 23px;
}

.email {
    color: #c59d5f;
    font-weight: 600;
    word-break: break-word;
    margin-bottom: 18px;
}

.description {
    color: #aaa;
    font-size: 14px;
    line-height: 1.7;
}

.message {
    padding: 14px;
    border-radius: 10px;
    margin: 20px 0;
    font-size: 14px;
    line-height: 1.6;
}

.success {
    background: #16351f;
    border: 1px solid #2d8a4a;
    color: #9be0ad;
}

.error {
    background: #3b1818;
    border: 1px solid #a33;
    color: #ffb0b0;
}

.otp {
    width: 100%;
    padding: 15px;
    border: 1px solid #444;
    border-radius: 10px;
    background: #242424;
    color: #fff;
    text-align: center;
    font-size: 26px;
    letter-spacing: 8px;
    outline: none;
    margin-top: 20px;
}

.otp:focus {
    border-color: #c59d5f;
    box-shadow: 0 0 0 2px rgba(197,157,95,.2);
}

.countdown {
    margin-top: 14px;
    color: #aaa;
    font-size: 14px;
}

.countdown strong {
    color: #c59d5f;
    font-variant-numeric: tabular-nums;
    letter-spacing: .5px;
}

.countdown.expired strong {
    color: #ff8f8f;
}

.btn {
    display: block;
    width: 100%;
    padding: 13px;
    border: 0;
    border-radius: 8px;
    background: #c59d5f;
    color: #111;
    font-weight: bold;
    margin-top: 20px;
    cursor: pointer;
    text-decoration: none;
}

.btn:hover {
    background: #e0b97f;
}

.btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.small {
    margin-top: 18px;
    color: #777;
    font-size: 12px;
}

@media (max-width: 500px) {
    .box {
        padding: 30px 22px;
    }

    .logo h1 {
        font-size: 27px;
    }
}
</style>

</head>

<body>

<div class="box">

<div class="logo">
    <h1>BLACKHABIT</h1>
    <p>Email Verification</p>
</div>

<?php if ($verified): ?>

<div class="icon">✓</div>

<h2>Account Verified</h2>

<?php if ($displayEmail !== ''): ?>

<div class="email">
    <?= htmlspecialchars(
        $displayEmail,
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</div>

<?php endif; ?>

<div class="message success">
    <?= htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</div>

<a href="index.php" class="btn">
    CONTINUE TO LOGIN
</a>

<?php elseif ($displayEmail !== ''): ?>

<div class="icon">✉</div>

<h2>Verify Your Gmail</h2>

<div class="email">
    <?= htmlspecialchars(
        $displayEmail,
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</div>

<div class="description">
    Enter the 6-digit verification code sent to your Gmail.
</div>

<?php if ($message !== ''): ?>

<div class="message error">
    <?= htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</div>

<?php endif; ?>

<form
    method="POST"
    autocomplete="off"
    id="verification-form"
>

<input
    class="otp"
    type="text"
    name="otp"
    inputmode="numeric"
    autocomplete="one-time-code"
    maxlength="6"
    pattern="[0-9]{6}"
    placeholder="000000"
    required
>

<div
    id="countdown"
    class="countdown"
    data-remaining="<?= (int)$remainingSeconds ?>"
>
    <?php if ($remainingSeconds > 0): ?>

        Code expires in
        <strong id="countdown-value">10:00</strong>

    <?php else: ?>

        <strong id="countdown-value">
            Your verification code has expired.
        </strong>

    <?php endif; ?>
</div>

<button
    id="verify-button"
    class="btn"
    type="submit"
>
    VERIFY ACCOUNT
</button>

</form>

<a href="register.php" class="btn">
    BACK TO REGISTER
</a>

<?php else: ?>

<div class="icon">✉</div>

<h2>Verification Required</h2>

<div class="message error">
    <?= htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</div>

<a href="register.php" class="btn">
    BACK TO REGISTER
</a>

<?php endif; ?>

<div class="small">
    BLACKHABIT System Portal
</div>

</div>

<script>
(function () {

    const countdown =
        document.getElementById('countdown');

    const value =
        document.getElementById('countdown-value');

    const verifyButton =
        document.getElementById('verify-button');

    if (!countdown || !value) {
        return;
    }

    let remaining =
        parseInt(
            countdown.getAttribute('data-remaining') || '0',
            10
        );

    function updateCountdown() {

        if (remaining <= 0) {

            value.textContent =
                'Your verification code has expired.';

            countdown.classList.add('expired');

            if (verifyButton) {
                verifyButton.disabled = true;
            }

            return;
        }

        const minutes =
            Math.floor(remaining / 60);

        const seconds =
            remaining % 60;

        value.textContent =
            String(minutes).padStart(2, '0') +
            ':' +
            String(seconds).padStart(2, '0');

        remaining--;
    }

    updateCountdown();

    setInterval(
        updateCountdown,
        1000
    );

})();
</script>

</body>
</html>
