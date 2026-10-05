<?php

/*
 * BLACKHABIT LOGIN
 *
 * The default session is used only while processing login errors.
 * Successful logins use independent sessions:
 *
 *   Admin   -> BH_ADMIN_SESSION
 *   Cashier -> BH_CASHIER_SESSION
 *
 * This allows Admin and Cashier to stay logged in simultaneously
 * in separate browser tabs.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$email = strtolower(trim($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    $_SESSION['error'] = 'Please enter your email and password.';
    header('Location: index.php');
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = 'Please enter a valid email address.';
    header('Location: index.php');
    exit();
}

$stmt = mysqli_prepare(
    $conn,
    'SELECT id, first_name, middle_name, last_name, email, password, role, email_verified
     FROM users
     WHERE email = ?
     LIMIT 1'
);

if (!$stmt) {
    error_log('BLACKHABIT LOGIN PREPARE ERROR: ' . mysqli_error($conn));
    $_SESSION['error'] = 'Database error. Please try again.';
    header('Location: index.php');
    exit();
}

mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);

if (mysqli_stmt_num_rows($stmt) === 0) {
    mysqli_stmt_close($stmt);
    $_SESSION['error'] = 'Invalid email or password.';
    header('Location: index.php');
    exit();
}

mysqli_stmt_bind_result(
    $stmt,
    $userId,
    $firstName,
    $middleName,
    $lastName,
    $userEmail,
    $storedPassword,
    $userRole,
    $emailVerified
);

mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

if (!password_verify($password, $storedPassword)) {
    $_SESSION['error'] = 'Invalid email or password.';
    header('Location: index.php');
    exit();
}

if ((int)$emailVerified !== 1) {
    $_SESSION['error'] = 'Please verify your Gmail before logging in.';
    header('Location: index.php');
    exit();
}

$roleValue = strtolower(trim((string)$userRole));

if ($roleValue === '1' || $roleValue === 'admin') {
    $role = 'admin';
} elseif ($roleValue === '2' || $roleValue === '3' || $roleValue === 'cashier') {
    $role = 'cashier';
} else {
    $_SESSION['error'] = 'Invalid account role.';
    header('Location: index.php');
    exit();
}

$displayName = trim(
    (string)$firstName . ' ' .
    (string)$middleName . ' ' .
    (string)$lastName
);

if ($displayName === '') {
    $displayName = (string)$userEmail;
}

/*
 * Finish the temporary/default session before changing the session name.
 * The role-specific session is then created independently.
 */
session_write_close();

if ($role === 'admin') {
    session_name('BH_ADMIN_SESSION');
} else {
    session_name('BH_CASHIER_SESSION');
}

session_start();
session_regenerate_id(true);

/*
 * Remove any stale login/OTP data from THIS role's session only.
 */
unset(
    $_SESSION['login_otp_hash'],
    $_SESSION['login_otp_expires'],
    $_SESSION['login_otp_attempts'],
    $_SESSION['login_otp_last_sent'],
    $_SESSION['offline_login_otp'],
    $_SESSION['login_user_id'],
    $_SESSION['login_user_email'],
    $_SESSION['login_user_name'],
    $_SESSION['login_user_role'],
    $_SESSION['otp_error']
);

$_SESSION['user_id'] = (int)$userId;
$_SESSION['email'] = (string)$userEmail;
$_SESSION['name'] = $displayName;
$_SESSION['role'] = $role;
$_SESSION['logged_in'] = true;
$_SESSION['login_time'] = time();

if ($role === 'admin') {
    header('Location: admin_dashboard.php');
    exit();
}

if ($role === 'cashier') {
    header('Location: cashier_dashboard.php');
    exit();
}

// Safety fallback.
session_unset();
session_destroy();

header('Location: index.php?error=Unknown account role');
exit();

?>
