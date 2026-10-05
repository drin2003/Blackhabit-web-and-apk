<?php

header('Content-Type: application/json; charset=UTF-8');

$to = 'caladojm8@gmail.com';
$subject = 'BLACK HABIT Test Email';

$message = '
<html>
<body>
    <h2>BLACK HABIT</h2>
    <p>This is a test email from the GoogieHost PHP server.</p>
</body>
</html>
';

$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-type: text/html; charset=UTF-8';
$headers[] = 'From: BLACK HABIT <blackhabitalaminos@blackhabitapi.whf.bz>';
$headers[] = 'Reply-To: blackhabitalaminos@blackhabitapi.whf.bz';

$sent = mail(
    $to,
    $subject,
    $message,
    implode("\r\n", $headers)
);

echo json_encode([
    'success' => $sent,
    'message' => $sent
        ? 'PHP mail() accepted the message.'
        : 'PHP mail() failed.'
]);