<?php

/*
 * BLACKHABIT ROLE-SPECIFIC LOGOUT
 *
 * Admin  -> BH_ADMIN_SESSION
 * Cashier -> BH_CASHIER_SESSION
 *
 * The dashboard referrer identifies which session should be destroyed.
 * This is important when Admin and Cashier are both logged in in the
 * same browser at the same time.
 */

$referer = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));

if (
    strpos($referer, 'cashier_dashboard.php') !== false ||
    strpos($referer, 'cashier_') !== false
) {
    $sessionName = 'BH_CASHIER_SESSION';
} elseif (
    strpos($referer, 'admin_dashboard.php') !== false ||
    strpos($referer, 'admin_') !== false
) {
    $sessionName = 'BH_ADMIN_SESSION';
} elseif (
    isset($_COOKIE['BH_CASHIER_SESSION']) &&
    !isset($_COOKIE['BH_ADMIN_SESSION'])
) {
    $sessionName = 'BH_CASHIER_SESSION';
} elseif (
    isset($_COOKIE['BH_ADMIN_SESSION']) &&
    !isset($_COOKIE['BH_CASHIER_SESSION'])
) {
    $sessionName = 'BH_ADMIN_SESSION';
} else {
    // Both sessions exist but the request does not identify the role.
    // Do not accidentally log out the wrong account.
    header('Location: index.php');
    exit();
}

session_name($sessionName);
session_start();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        $sessionName,
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: index.php');
exit();

?>
