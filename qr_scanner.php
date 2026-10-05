<?php
session_start();

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'cashier' && $_SESSION['role'] != 3)) {
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Scan QR Code | Black Habit</title>

<script src="https://unpkg.com/html5-qrcode"></script>

<style>
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
    font-family: Arial, sans-serif;
    background: #f5f5f5;
    color: #222;
    text-align: center;
    padding: 20px;
    overflow-x: hidden;
}

/* Main scanner card */
.scanner-page {
    width: 100%;
    max-width: 650px;
    margin: 0 auto;
}

.logo-title {
    margin-top: 10px;
    color: #111;
    font-size: clamp(26px, 5vw, 38px);
    font-weight: 700;
    letter-spacing: 1.5px;
}

.page-title {
    margin-top: 8px;
    margin-bottom: 22px;
    color: #555;
    font-size: clamp(18px, 4vw, 25px);
    font-weight: 600;
}

/* Scanner panel */
.scanner-card {
    width: 100%;
    background: #fff;
    border-radius: 16px;
    padding: clamp(16px, 4vw, 28px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, .10);
    border: 1px solid #e5e5e5;
}

/* Start button */
#startButton {
    width: 100%;
    max-width: 320px;
    min-height: 52px;
    padding: 14px 24px;
    border: none;
    border-radius: 10px;
    background: #c59d5f;
    color: #111;
    font-size: 17px;
    font-weight: 700;
    cursor: pointer;
    transition: .2s ease;
    touch-action: manipulation;
}

#startButton:hover {
    background: #deb474;
}

#startButton:active {
    transform: scale(.98);
}

#startButton:disabled {
    opacity: .65;
    cursor: not-allowed;
}

/* QR reader */
#reader {
    width: 100%;
    max-width: 500px;
    margin: 22px auto 0;
    overflow: hidden;
    border-radius: 12px;
}

/* html5-qrcode generated elements */
#reader video {
    width: 100% !important;
    max-width: 100%;
    height: auto !important;
    border-radius: 10px;
    object-fit: cover;
}

#reader img {
    max-width: 100%;
}

#reader__scan_region {
    width: 100% !important;
}

#reader__dashboard {
    width: 100% !important;
    padding: 10px !important;
}

#reader__dashboard_section_csr button {
    min-height: 44px;
    padding: 10px 16px;
    margin: 5px;
    border-radius: 8px;
    border: 1px solid #ccc;
    cursor: pointer;
}

#reader__status_span {
    font-size: 13px;
}

/* Instructions */
.scanner-note {
    margin-top: 18px;
    color: #777;
    font-size: 14px;
    line-height: 1.5;
}

/* Tablet */
@media (min-width: 769px) and (max-width: 1100px) {
    body {
        padding: 24px;
    }

    .scanner-page {
        max-width: 620px;
    }

    #reader {
        max-width: 500px;
    }
}

/* iPad portrait / small tablet */
@media (min-width: 481px) and (max-width: 768px) {
    body {
        padding: 18px;
    }

    .scanner-card {
        padding: 20px;
    }

    #reader {
        max-width: 480px;
    }
}

/* Phone */
@media (max-width: 480px) {
    body {
        padding: 12px;
    }

    .logo-title {
        margin-top: 5px;
    }

    .page-title {
        margin-bottom: 16px;
    }

    .scanner-card {
        padding: 14px;
        border-radius: 12px;
    }

    #startButton {
        max-width: none;
        font-size: 16px;
    }

    #reader {
        margin-top: 16px;
    }

    .scanner-note {
        font-size: 13px;
    }
}

/* Very small phones */
@media (max-width: 360px) {
    body {
        padding: 8px;
    }

    .scanner-card {
        padding: 10px;
    }

    #reader {
        border-radius: 8px;
    }
}
</style>

</head>

<body>

<main class="scanner-page">

    <h1 class="logo-title">BLACK HABIT</h1>

    <h2 class="page-title">Cashier QR Scanner</h2>

    <section class="scanner-card">

        <button id="startButton" type="button" onclick="startScanner()">
            Start Scanner
        </button>

        <div id="reader"></div>

        <p class="scanner-note">
            Tap <strong>Start Scanner</strong>, allow camera access, and point the camera at the customer's QR code.
        </p>

    </section>

</main>

<script>
let html5QrCode = null;
let scannerRunning = false;
let processingResult = false;

async function startScanner() {

    if (scannerRunning || processingResult) {
        return;
    }

    const button = document.getElementById("startButton");

    try {
        button.disabled = true;
        button.textContent = "Starting Camera...";

        html5QrCode = new Html5Qrcode("reader");

        await html5QrCode.start(
            { facingMode: "environment" },
            {
                fps: 10,
                qrbox: function(viewfinderWidth, viewfinderHeight) {
                    const minEdge = Math.min(viewfinderWidth, viewfinderHeight);

                    return {
                        width: Math.max(180, Math.min(280, Math.floor(minEdge * 0.70))),
                        height: Math.max(180, Math.min(280, Math.floor(minEdge * 0.70)))
                    };
                },
                aspectRatio: 1.0,
                disableFlip: false
            },
            async function(decodedText) {

                if (processingResult) {
                    return;
                }

                processingResult = true;
                button.textContent = "QR Code Detected";

                try {
                    if (html5QrCode && scannerRunning) {
                        await html5QrCode.stop();
                        scannerRunning = false;
                    }
                } catch (stopError) {
                    console.error("Scanner stop error:", stopError);
                }

                window.location.href =
                    "scan_order.php?code=" +
                    encodeURIComponent(decodedText);
            },
            function(errorMessage) {
                // Normal scanning errors are ignored.
            }
        );

        scannerRunning = true;
        button.disabled = false;
        button.textContent = "Scanner Running";

    } catch (error) {

        console.error("Camera error:", error);

        scannerRunning = false;
        button.disabled = false;
        button.textContent = "Start Scanner";

        alert(
            "Unable to start the camera.\n\n" +
            "Please allow camera permission and make sure the page is opened using HTTPS."
        );

        if (html5QrCode) {
            try {
                await html5QrCode.clear();
            } catch (clearError) {
                console.error("Scanner clear error:", clearError);
            }

            html5QrCode = null;
        }
    }
}
</script>

</body>
</html>
