<?php
session_start();

/*
 * =========================================================
 * BLACKHABIT IMAGE PATHS
 * Automatically finds the actual logo/background extension.
 * Supports PNG / JPG / JPEG / WebP.
 * =========================================================
 */

$logoMatches = glob(__DIR__ . '/assets/logo/Blackhabit_logo.*');

$logoFile = !empty($logoMatches)
    ? 'assets/logo/' . basename($logoMatches[0])
    : '';

$backgroundMatches = glob(__DIR__ . '/assets/images/Background.*');

$backgroundFile = !empty($backgroundMatches)
    ? 'assets/images/' . basename($backgroundMatches[0])
    : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>BlackHabit Portal Login</title>


    <!-- =====================================================
         FAVICON
    ====================================================== -->

    <link
        rel="icon"
        type="image/x-icon"
        href="favicon_io/favicon.ico"
    >


    <!-- =====================================================
         GOOGLE FONTS
    ====================================================== -->

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <!-- =====================================================
         FONT AWESOME
    ====================================================== -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
    >


<style>

/* =========================================================
   RESET
========================================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}


html,
body {
    width: 100%;
    min-height: 100%;
}


body {
    font-family: 'Poppins', sans-serif;
    background: #080808;
    color: #ffffff;
}


/* =========================================================
   FULL PAGE BACKGROUND
========================================================= */

.login-page {

    width: 100%;
    min-height: 100vh;

    position: relative;

    display: flex;

    overflow-x: hidden;
    overflow-y: visible;

    /*
     * The background is handled by .login-page::before
     * so it stays fixed while the page content scrolls.
     */

    background: transparent;
}


/* =========================================================
   FIXED FULL-PAGE BACKGROUND
========================================================= */

.login-page::before {

    content: "";

    position: fixed;

    inset: 0;

    width: 100vw;
    height: 100vh;

    z-index: 0;

    pointer-events: none;

    background-image:
        linear-gradient(
            rgba(0, 0, 0, 0.18),
            rgba(0, 0, 0, 0.28)
        ),
        url("<?php echo htmlspecialchars($backgroundFile, ENT_QUOTES, 'UTF-8'); ?>");

    background-size: cover;

    background-position: center center;

    background-repeat: no-repeat;
}



/* =========================================================
   LEFT BRAND SECTION
========================================================= */

.brand-section {

    width: 52%;

    min-height: 100vh;

    position: relative;

    z-index: 1;

    display: flex;

    align-items: center;

    padding: 55px 65px;

    overflow: hidden;

    /*
     * IMPORTANT:
     * Transparent so the full-page background remains visible.
     */

    background: transparent;
}


/* =========================================================
   LEFT SECTION DECORATIVE GLOW
========================================================= */

.brand-section::before {

    content: "";

    position: absolute;

    width: 500px;
    height: 500px;

    left: -300px;
    bottom: -250px;

    border-radius: 50%;

    background:
        radial-gradient(
            circle,
            rgba(220, 174, 85, 0.10),
            transparent 68%
        );

    pointer-events: none;
}


/* =========================================================
   LEFT SECTION GOLD DIVIDER
========================================================= */

.brand-section::after {

    content: "";

    position: absolute;

    width: 2px;

    height: 120%;

    right: -1px;

    top: -10%;

    background:
        linear-gradient(
            to bottom,
            transparent,
            rgba(220, 174, 85, 0.55),
            rgba(220, 174, 85, 0.95),
            rgba(220, 174, 85, 0.55),
            transparent
        );

    transform: rotate(0deg);

    box-shadow:
        0 0 16px rgba(220, 174, 85, 0.45);
}


/* =========================================================
   BRAND CONTENT
========================================================= */

.brand-content {

    position: relative;

    z-index: 3;

    width: 100%;

    max-width: 620px;
}


/* =========================================================
   BRAND LOGO
========================================================= */

.brand-logo {

    width: 105px;

    height: 105px;

    display: block;

    object-fit: contain;

    background: transparent;

    border-radius: 50%;

    margin-bottom: 25px;

    filter:
        drop-shadow(
            0 12px 30px rgba(0, 0, 0, 0.50)
        );
}


/* =========================================================
   BRAND NAME
========================================================= */

.brand-name {

    color: #e0b45d;

    font-size: 15px;

    font-weight: 600;

    letter-spacing: 7px;

    margin-bottom: 22px;
}


/* =========================================================
   MAIN SLOGAN
========================================================= */

.brand-title {

    margin: 0;

    color: #ffffff;

    font-size: clamp(43px, 5vw, 72px);

    line-height: 1.04;

    font-weight: 800;

    letter-spacing: -1.5px;

    text-shadow:
        0 4px 25px rgba(0, 0, 0, 0.65);
}


.brand-title span {

    display: block;

    color: #e0b45d;

    text-shadow:
        0 4px 25px rgba(0, 0, 0, 0.65);
}


/* =========================================================
   SUBTITLE
========================================================= */

.brand-subtitle {

    margin: 23px 0 0;

    color: #ffffff;

    font-size: 13px;

    font-weight: 500;

    letter-spacing: 4px;

    text-shadow:
        0 2px 12px rgba(0, 0, 0, 0.80);
}


/* =========================================================
   FEATURES
========================================================= */

.brand-features {

    display: flex;

    align-items: center;

    gap: 42px;

    margin-top: 48px;
}


.feature {

    display: flex;

    align-items: center;

    gap: 10px;
}


.feature-icon {

    width: 43px;

    height: 43px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #d4a94f;

    border-radius: 50%;

    color: #e0b45d;

    font-size: 15px;

    background:
        rgba(0, 0, 0, 0.25);

    backdrop-filter: blur(4px);
}


.feature-text strong {

    display: block;

    color: #ffffff;

    font-size: 10px;

    letter-spacing: 1px;

    text-shadow:
        0 2px 8px rgba(0, 0, 0, 0.80);
}


.feature-text span {

    display: block;

    color: #dddddd;

    font-size: 8px;

    margin-top: 2px;

    text-shadow:
        0 2px 8px rgba(0, 0, 0, 0.80);
}


/* =========================================================
   DECORATIVE COFFEE CUP
========================================================= */

.coffee-cup {

    position: absolute;

    z-index: 2;

    right: 7%;

    bottom: -45px;

    width: 165px;

    height: 225px;

    border-radius:
        12px 12px 38px 38px;

    background:
        linear-gradient(
            90deg,
            #111111,
            #292929,
            #101010
        );

    border: 1px solid #444444;

    transform: rotate(-3deg);

    box-shadow:
        0 25px 45px rgba(0, 0, 0, 0.70);
}


.coffee-cup::before {

    content: "";

    position: absolute;

    top: -15px;

    left: 10px;

    right: 10px;

    height: 29px;

    background: #111111;

    border: 2px solid #4b4b4b;

    border-radius: 50%;
}


.cup-logo {

    position: absolute;

    width: 73px;

    height: 73px;

    object-fit: contain;

    background: transparent;

    border-radius: 50%;

    top: 63px;

    left: 50%;

    transform: translateX(-50%);
}


.cup-text {

    position: absolute;

    left: 0;

    right: 0;

    bottom: 35px;

    text-align: center;

    color: #cfa456;

    font-size: 10px;

    letter-spacing: 2px;
}


/* =========================================================
   RIGHT LOGIN SECTION
========================================================= */

.login-section {

    width: 48%;

    min-height: 100vh;

    position: relative;

    z-index: 1;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 40px;

    /*
     * IMPORTANT:
     * Transparent so café background continues behind card.
     */

    background: transparent;
}


/* =========================================================
   RIGHT DECORATIVE GLOW
========================================================= */

.login-section::before {

    content: "";

    position: absolute;

    width: 450px;

    height: 450px;

    right: -250px;

    top: -200px;

    border-radius: 50%;

    background:
        radial-gradient(
            circle,
            rgba(220, 174, 85, 0.12),
            transparent 70%
        );

    pointer-events: none;
}


/* =========================================================
   LOGIN CARD
========================================================= */

.login-card {

    width: 100%;

    max-width: 420px;

    position: relative;

    z-index: 5;

    padding: 32px 34px;

    /*
     * GLASS EFFECT
     */

    background:
        linear-gradient(
            145deg,
            rgba(25, 25, 25, 0.84),
            rgba(10, 10, 10, 0.72)
        );

    backdrop-filter: blur(10px);

    -webkit-backdrop-filter: blur(10px);

    border: 1px solid rgba(255, 255, 255, 0.16);

    border-radius: 18px;

    box-shadow:
        0 30px 80px rgba(0, 0, 0, 0.60);
}


/* =========================================================
   LOGIN LOGO WRAPPER
========================================================= */

.login-logo-wrapper {

    display: flex;

    justify-content: center;

    align-items: center;

    margin-bottom: 12px;
}


/* =========================================================
   LOGIN LOGO
========================================================= */

.login-logo {

    width: 82px;

    height: 82px;

    object-fit: contain;

    background: transparent;

    border-radius: 50%;

    display: block;

    filter:
        drop-shadow(
            0 10px 25px rgba(0, 0, 0, 0.55)
        );
}


/* =========================================================
   LOGIN BRAND
========================================================= */

.login-brand {

    text-align: center;

    color: #ffffff;

    font-size: 24px;

    font-weight: 800;

    letter-spacing: 4px;

    text-shadow:
        0 2px 15px rgba(0, 0, 0, 0.60);
}


.login-system {

    text-align: center;

    color: #d9aa50;

    font-size: 11px;

    margin-top: 5px;

    letter-spacing: 1.5px;
}


/* =========================================================
   WELCOME BOX
========================================================= */

.welcome {

    margin-top: 24px;

    margin-bottom: 19px;

    padding: 18px 20px;

    border-left: 3px solid #dcae55;

    border-radius: 7px;

    background:
        rgba(0, 0, 0, 0.25);
}


.welcome h2 {

    margin: 0 0 4px;

    color: #e2b35b;

    font-size: 20px;

    font-weight: 700;
}


.welcome p {

    margin: 0;

    color: #aaaaaa;

    font-size: 11px;
}


/* =========================================================
   ERROR
========================================================= */

.error {

    width: 100%;

    padding: 11px 13px;

    margin-bottom: 18px;

    border-radius: 8px;

    background:
        rgba(130, 30, 30, 0.25);

    border: 1px solid #713838;

    color: #ff9b9b;

    font-size: 11px;
}


/* =========================================================
   INPUT BOX
========================================================= */

.input-box {

    margin-bottom: 17px;
}


.input-box label {

    display: block;

    margin-bottom: 7px;

    color: #dfae55;

    font-size: 11px;

    font-weight: 600;
}


/* =========================================================
   INPUT WRAPPER
========================================================= */

.input-wrapper {

    position: relative;
}


/* =========================================================
   INPUT ICON
========================================================= */

.input-wrapper > i:first-child {

    position: absolute;

    left: 15px;

    top: 50%;

    transform: translateY(-50%);

    color: #777777;

    pointer-events: none;

    z-index: 2;
}


/* =========================================================
   TEXT INPUT
========================================================= */

.input-box input {

    width: 100%;

    height: 51px;

    padding: 0 15px 0 43px;

    border: 1px solid rgba(255, 255, 255, 0.15);

    border-radius: 9px;

    outline: none;

    background:
        rgba(30, 30, 30, 0.72);

    color: #ffffff;

    font-family: 'Poppins', sans-serif;

    font-size: 12px;

    transition: 0.2s ease;
}


.input-box input::placeholder {

    color: #777777;
}


.input-box input:focus {

    border-color: #c59d5f;

    background:
        rgba(35, 35, 35, 0.82);

    box-shadow:
        0 0 0 3px rgba(197, 157, 95, 0.10);
}


/* =========================================================
   PASSWORD WRAPPER
========================================================= */

.password-wrapper {

    position: relative;
}


/* =========================================================
   PASSWORD EYE
========================================================= */

.password-eye {

    position: absolute;

    right: 15px;

    top: 50%;

    transform: translateY(-50%);

    color: #777777;

    cursor: pointer;

    font-size: 13px;

    transition: 0.2s ease;

    z-index: 3;
}


.password-eye:hover {

    color: #dcae55;
}


/* =========================================================
   FORM OPTIONS
========================================================= */

.form-options {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    margin-top: 6px;

    margin-bottom: 21px;
}


/* =========================================================
   SHOW PASSWORD
========================================================= */

.show {

    display: flex;

    align-items: center;

    gap: 7px;

    color: #dddddd;

    font-size: 10px;
}


.show input {

    width: 14px;

    height: 14px;

    accent-color: #c59d5f;

    cursor: pointer;
}


.show label {

    margin: 0 !important;

    color: #dddddd !important;

    font-size: 10px !important;

    cursor: pointer;
}


/* =========================================================
   FORGOT PASSWORD
========================================================= */

.forgot-password {

    color: #dfae55;

    text-decoration: none;

    font-size: 10px;

    font-weight: 500;

    white-space: nowrap;
}


.forgot-password:hover {

    color: #f0c579;

    text-decoration: underline;
}


/* =========================================================
   SIGN IN BUTTON
========================================================= */

.btn {

    width: 100%;

    height: 51px;

    border: none;

    border-radius: 9px;

    background:
        linear-gradient(
            135deg,
            #e0b366,
            #c59d5f
        );

    color: #111111;

    font-family: 'Poppins', sans-serif;

    font-size: 12px;

    font-weight: 800;

    letter-spacing: 0.5px;

    cursor: pointer;

    transition: 0.2s ease;

    box-shadow:
        0 8px 22px rgba(197, 157, 95, 0.20);
}


.btn:hover {

    background:
        linear-gradient(
            135deg,
            #efc87e,
            #d5a962
        );

    transform: translateY(-1px);

    box-shadow:
        0 10px 25px rgba(197, 157, 95, 0.28);
}


.btn:active {

    transform: translateY(0);
}


/* =========================================================
   DIVIDER
========================================================= */

.account-divider {

    display: flex;

    align-items: center;

    gap: 12px;

    margin: 23px 0 15px;

    color: #777777;

    font-size: 10px;
}


.account-divider::before,
.account-divider::after {

    content: "";

    flex: 1;

    height: 1px;

    background:
        rgba(255, 255, 255, 0.12);
}


/* =========================================================
   LINKS
========================================================= */

.links {

    display: flex;

    width: 100%;

    justify-content: center;

    align-items: center;
}


/* =========================================================
   CREATE ACCOUNT
========================================================= */

.create-account {

    width: 100%;

    height: 47px;

    display: flex;

    align-items: center;

    justify-content: center;

    gap: 9px;

    border: 1px solid #c59d5f;

    border-radius: 9px;

    color: #dfae55;

    text-decoration: none;

    font-size: 11px;

    font-weight: 600;

    transition: 0.2s ease;

    background:
        rgba(0, 0, 0, 0.12);
}


.create-account:hover {

    background:
        rgba(197, 157, 95, 0.10);

    color: #f0c579;
}


/* =========================================================
   FOOTER
========================================================= */

.login-footer {

    margin-top: 26px;

    padding-top: 17px;

    border-top:
        1px solid rgba(255, 255, 255, 0.10);

    text-align: center;

    color: #888888;

    font-size: 9px;

    letter-spacing: 1px;
}


.login-footer span {

    color: #c59d5f;
}


/* =========================================================
   LARGE TABLET / SMALL DESKTOP
========================================================= */

@media (max-width: 1100px) {

    .brand-section {

        width: 47%;

        padding: 45px;
    }


    .login-section {

        width: 53%;

        padding: 25px;
    }


    .brand-title {

        font-size: 48px;
    }


    .brand-features {

        gap: 20px;
    }


    .coffee-cup {

        width: 135px;

        height: 190px;
    }


    .cup-logo {

        width: 60px;

        height: 60px;
    }
}


/* =========================================================
   IPAD / TABLET PORTRAIT
========================================================= */

@media (max-width: 800px) {

    .login-page {

        display: block;

        min-height: 100vh;

        overflow-x: hidden;

        overflow-y: auto;

        background: transparent;
    }


    .login-page::before {

        background-image:
            linear-gradient(
                rgba(0, 0, 0, 0.18),
                rgba(0, 0, 0, 0.28)
            ),
            url("<?php echo htmlspecialchars($backgroundFile, ENT_QUOTES, 'UTF-8'); ?>");

        background-size: cover;

        background-position: center center;

        background-repeat: no-repeat;
    }


    /* -----------------------------------------
       BRAND
    ----------------------------------------- */

    .brand-section {

        width: 100%;

        min-height: 390px;

        padding: 45px 30px;

        align-items: center;

        justify-content: center;

        text-align: center;
    }


    .brand-section::after {

        display: none;
    }

    /* Clear divider between brand content and login form */
    .login-section {

        border-top: 2px solid rgba(220, 174, 85, 0.80);

        box-shadow:
            0 -8px 22px rgba(220, 174, 85, 0.10);
    }


    .brand-content {

        width: 100%;

        max-width: 700px;
    }


    .brand-logo {

        width: 90px;

        height: 90px;

        margin: 0 auto 18px;
    }


    .brand-name {

        font-size: 12px;

        letter-spacing: 5px;

        margin-bottom: 15px;
    }


    .brand-title {

        font-size: clamp(36px, 8vw, 52px);

        line-height: 1.05;
    }


    .brand-subtitle {

        font-size: 10px;

        letter-spacing: 3px;

        margin-top: 15px;
    }


    .brand-features {

        justify-content: center;

        gap: 28px;

        margin-top: 30px;
    }


    .feature {

        gap: 7px;
    }


    .feature-icon {

        width: 38px;

        height: 38px;

        font-size: 12px;
    }


    .feature-text strong {

        font-size: 8px;
    }


    .feature-text span {

        font-size: 7px;
    }


    .coffee-cup {

        display: none;
    }


    /* -----------------------------------------
       LOGIN
    ----------------------------------------- */

    .login-section {

        width: 100%;

        min-height: auto;

        padding: 25px 20px 45px;

        display: flex;

        align-items: center;

        justify-content: center;
    }


    .login-card {

        width: 100%;

        max-width: 520px;

        padding: 35px 30px;
    }


    .login-logo {

        width: 90px;

        height: 90px;
    }


    .login-brand {

        font-size: 25px;

        letter-spacing: 3px;
    }


    .welcome {

        margin-top: 27px;
    }
}


/* =========================================================
   IPAD PORTRAIT SPECIFIC
========================================================= */

@media (min-width: 601px) and (max-width: 800px) {

    .brand-section {

        min-height: 430px;

        padding-top: 55px;

        padding-bottom: 45px;
    }


    .brand-logo {

        width: 100px;

        height: 100px;
    }


    .brand-title {

        font-size: 50px;
    }


    .brand-subtitle {

        font-size: 11px;
    }


    .brand-features {

        gap: 40px;

        margin-top: 35px;
    }


    .feature-icon {

        width: 42px;

        height: 42px;
    }


    .feature-text strong {

        font-size: 9px;
    }


    .feature-text span {

        font-size: 8px;
    }


    .login-section {

        padding: 35px 30px 55px;
    }


    .login-card {

        max-width: 460px;

        padding: 32px 34px;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 600px) {

    .brand-section {

        min-height: 330px;

        padding: 30px 18px;
    }


    .brand-logo {

        width: 75px;

        height: 75px;

        margin-bottom: 13px;
    }


    .brand-name {

        font-size: 10px;

        letter-spacing: 4px;

        margin-bottom: 11px;
    }


    .brand-title {

        font-size: 34px;

        letter-spacing: -0.5px;
    }


    .brand-subtitle {

        font-size: 8px;

        letter-spacing: 2px;

        margin-top: 12px;
    }


    .brand-features {

        gap: 14px;

        margin-top: 23px;
    }


    .feature {

        gap: 5px;
    }


    .feature-icon {

        width: 32px;

        height: 32px;

        font-size: 10px;
    }


    .feature-text strong {

        font-size: 6px;

        letter-spacing: 0.5px;
    }


    .feature-text span {

        display: none;
    }


    .login-section {

        padding: 24px 14px 35px;

        border-top: 2px solid rgba(220, 174, 85, 0.80);

        box-shadow:
            0 -7px 18px rgba(220, 174, 85, 0.10);
    }


    .login-card {

        width: 100%;

        max-width: 430px;

        padding: 26px 20px;

        border-radius: 15px;
    }


    .login-logo {

        width: 80px;

        height: 80px;
    }


    .login-brand {

        font-size: 22px;

        letter-spacing: 2.5px;
    }


    .login-system {

        font-size: 9px;

        letter-spacing: 1px;
    }


    .welcome {

        margin-top: 23px;

        padding: 18px;
    }


    .welcome h2 {

        font-size: 19px;
    }


    .welcome p {

        font-size: 10px;
    }


    .input-box input {

        height: 49px;
    }


    .btn {

        height: 49px;
    }


    .form-options {

        gap: 8px;
    }


    .forgot-password {

        font-size: 9px;
    }


    .show {

        font-size: 9px;
    }


    .show label {

        font-size: 9px !important;
    }


    .create-account {

        height: 45px;
    }
}


/* =========================================================
   SMALL PHONE
========================================================= */

@media (max-width: 400px) {

    .brand-section {

        min-height: 300px;

        padding: 25px 12px;
    }


    .brand-logo {

        width: 68px;

        height: 68px;
    }


    .brand-title {

        font-size: 30px;
    }


    .brand-subtitle {

        font-size: 7px;

        letter-spacing: 1.5px;
    }


    .brand-features {

        gap: 9px;
    }


    .feature-icon {

        width: 29px;

        height: 29px;

        font-size: 9px;
    }


    .feature-text strong {

        font-size: 5.5px;
    }


    .login-section {

        padding: 13px 10px 30px;
    }


    .login-card {

        width: 100%;

        max-width: 410px;

        padding: 24px 17px;
    }


    .login-logo {

        width: 72px;

        height: 72px;
    }


    .login-brand {

        font-size: 20px;
    }


    .login-system {

        font-size: 8px;
    }


    .welcome {

        padding: 16px;

        margin-top: 20px;
    }


    .welcome h2 {

        font-size: 18px;
    }


    .welcome p {

        font-size: 9px;
    }


    .input-box label {

        font-size: 10px;
    }


    .input-box input {

        height: 47px;

        font-size: 11px;
    }


    .btn {

        height: 47px;

        font-size: 11px;
    }


    .login-footer {

        font-size: 7px;
    }
}


/* =========================================================
   VERY SMALL DEVICES
========================================================= */

@media (max-width: 320px) {

    .brand-section {

        min-height: 285px;
    }


    .brand-title {

        font-size: 27px;
    }


    .brand-features {

        gap: 6px;
    }


    .feature-icon {

        width: 27px;

        height: 27px;
    }


    .feature-text strong {

        font-size: 5px;
    }


    .login-card {

        width: 100%;

        max-width: 100%;

        padding: 22px 14px;
    }


    .login-brand {

        font-size: 18px;
    }


    .form-options {

        flex-wrap: wrap;
    }


    .forgot-password {

        margin-left: auto;
    }
}

</style>

</head>


<body>


<div class="login-page">


    <!-- =====================================================
         LEFT BRAND SECTION
    ====================================================== -->

    <section class="brand-section">

        <div class="brand-content">


            <!-- LOGO -->

            <img
                src="<?php echo htmlspecialchars($logoFile, ENT_QUOTES, 'UTF-8'); ?>"
                alt="BLACKHABIT Logo"
                class="brand-logo"
            >


            <!-- BRAND NAME -->

            <div class="brand-name">
                BLACK HABIT
            </div>


            <!-- SLOGAN -->

            <h1 class="brand-title">

                Good Coffee

                <span>
                    Brighter Days
                </span>

            </h1>


            <!-- CATEGORIES -->

            <p class="brand-subtitle">
                MILK TEA • COFFEE • SHAWARMA
            </p>


            <!-- FEATURES -->

            <div class="brand-features">


                <div class="feature">

                    <div class="feature-icon">

                        <i class="fa-solid fa-mug-hot"></i>

                    </div>

                    <div class="feature-text">

                        <strong>
                            GREAT DRINKS
                        </strong>

                        <span>
                            Freshly prepared
                        </span>

                    </div>

                </div>


                <div class="feature">

                    <div class="feature-icon">

                        <i class="fa-solid fa-users"></i>

                    </div>

                    <div class="feature-text">

                        <strong>
                            GOOD COMPANY
                        </strong>

                        <span>
                            Good moments
                        </span>

                    </div>

                </div>


                <div class="feature">

                    <div class="feature-icon">

                        <i class="fa-solid fa-heart"></i>

                    </div>

                    <div class="feature-text">

                        <strong>
                            BETTER DAYS
                        </strong>

                        <span>
                            Good vibes
                        </span>

                    </div>

                </div>


            </div>

        </div>

    </section>



    <!-- =====================================================
         LOGIN SECTION
    ====================================================== -->

    <section class="login-section">


        <div class="login-card">


            <!-- =================================================
                 LOGIN LOGO
            ================================================== -->

            <div class="login-logo-wrapper">

                <img
                    src="<?php echo htmlspecialchars($logoFile, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="BLACKHABIT Logo"
                    class="login-logo"
                >

            </div>


            <!-- =================================================
                 LOGIN BRAND
            ================================================== -->

            <div class="login-brand">
                BLACKHABIT
            </div>


            <div class="login-system">
                System Portal Gateway
            </div>


            <!-- =================================================
                 WELCOME
            ================================================== -->

            <div class="welcome">

                <h2>
                    Welcome Back!
                </h2>

                <p>
                    Sign in to access your account
                </p>

            </div>


            <!-- =================================================
                 ERROR MESSAGE
            ================================================== -->

            <?php

            if (isset($_SESSION['error'])) {

                echo "<div class='error'>"
                    . htmlspecialchars($_SESSION['error'])
                    . "</div>";

                unset($_SESSION['error']);

            } elseif (isset($_GET['error'])) {

                echo "<div class='error'>"
                    . htmlspecialchars($_GET['error'])
                    . "</div>";

            }

            ?>


            <!-- =================================================
                 LOGIN FORM
            ================================================== -->

            <form
                action="login_process.php"
                method="POST"
                autocomplete="on"
            >


                <!-- =================================================
                     EMAIL
                ================================================== -->

                <div class="input-box">

                    <label for="email">
                        Email Address
                    </label>


                    <div class="input-wrapper">

                        <i class="fa-regular fa-envelope"></i>


                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Enter your email"
                            required
                            autocomplete="email"
                        >

                    </div>

                </div>



                <!-- =================================================
                     PASSWORD
                ================================================== -->

                <div class="input-box">

                    <label for="password">
                        Password
                    </label>


                    <div class="password-wrapper">


                        <i
                            class="fa-solid fa-lock"
                            style="
                                position:absolute;
                                left:15px;
                                top:50%;
                                transform:translateY(-50%);
                                color:#777;
                                pointer-events:none;
                                font-size:14px;
                                z-index:2;
                            "
                        ></i>


                        <input
                            type="password"
                            name="password"
                            id="password"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >


                        <i
                            class="fa-solid fa-eye password-eye"
                            id="passwordToggle"
                            aria-label="Show or hide password"
                        ></i>


                    </div>

                </div>



                <!-- =================================================
                     OPTIONS
                ================================================== -->

                <div class="form-options">


                    <div class="show">

                        <input
                            type="checkbox"
                            id="checkShow"
                        >


                        <label for="checkShow">
                            Show Password
                        </label>

                    </div>


                    <a
                        href="forgot_password.php"
                        class="forgot-password"
                    >
                        Forgot Password?
                    </a>


                </div>



                <!-- =================================================
                     SIGN IN
                ================================================== -->

                <button
                    class="btn"
                    type="submit"
                    name="login"
                >
                    SIGN IN
                </button>



                <!-- =================================================
                     DIVIDER
                ================================================== -->

                <div class="account-divider">
                    <span>OR</span>
                </div>



                <!-- =================================================
                     CREATE ACCOUNT
                ================================================== -->

                <div class="links">

                    <a
                        href="register.php"
                        class="create-account"
                    >

                        <i class="fa-regular fa-user"></i>

                        Create Account

                    </a>

                </div>


            </form>



            <!-- =================================================
                 FOOTER
            ================================================== -->

            <div class="login-footer">

                <span>Coffee</span>
                •
                <span>Milk Tea</span>
                •
                <span>Shawarma</span>
                •
                Good Vibes

            </div>


        </div>


    </section>


</div>



<script>

/* =========================================================
   PASSWORD ELEMENTS
========================================================= */

const passwordInput =
    document.getElementById("password");

const checkShow =
    document.getElementById("checkShow");

const passwordToggle =
    document.getElementById("passwordToggle");


/* =========================================================
   SHOW PASSWORD CHECKBOX
========================================================= */

if (checkShow && passwordInput) {

    checkShow.addEventListener(
        "change",
        function () {

            if (this.checked) {

                passwordInput.type = "text";

            } else {

                passwordInput.type = "password";

            }

            updateEyeIcon();

        }
    );

}


/* =========================================================
   PASSWORD EYE
========================================================= */

if (passwordToggle && passwordInput) {

    passwordToggle.addEventListener(
        "click",
        function () {

            if (passwordInput.type === "password") {

                passwordInput.type = "text";

                if (checkShow) {

                    checkShow.checked = true;

                }

            } else {

                passwordInput.type = "password";

                if (checkShow) {

                    checkShow.checked = false;

                }

            }

            updateEyeIcon();

        }
    );

}


/* =========================================================
   UPDATE EYE ICON
========================================================= */

function updateEyeIcon() {

    if (!passwordToggle || !passwordInput) {

        return;

    }


    if (passwordInput.type === "text") {

        passwordToggle.classList.remove("fa-eye");

        passwordToggle.classList.add("fa-eye-slash");

    } else {

        passwordToggle.classList.remove("fa-eye-slash");

        passwordToggle.classList.add("fa-eye");

    }

}


/* =========================================================
   INITIAL EYE STATE
========================================================= */

updateEyeIcon();

</script>


</body>

</html>