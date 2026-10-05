<?php

/*
|--------------------------------------------------------------------------
| BLACK HABIT - FIREBASE FCM NOTIFICATION SENDER
|--------------------------------------------------------------------------
|
| Sends push notifications using Firebase Cloud Messaging HTTP v1.
|
| Firebase service-account JSON is stored OUTSIDE public_html.
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| FIND FIREBASE SERVICE ACCOUNT
|--------------------------------------------------------------------------
*/

function getFirebaseServiceAccount()
{
    /*
    Actual folder structure:
    
    blackhabitapi.whf.bz/
    │
    ├── firebase_secure/
    │   └── Firebase service-account JSON
    │
    └── public_html/
        └── blackhabit_api/
            └── send_fcm_notification.php
    */


      $secureFolder = dirname(__DIR__) . '/firebase_secure';


    /*
    |--------------------------------------------------------------------------
    | CHECK SECURE FOLDER
    |--------------------------------------------------------------------------
    */

    if (!is_dir($secureFolder)) {

        throw new Exception(
            'Firebase secure folder was not found.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FIND JSON KEY
    |--------------------------------------------------------------------------
    */

    $files = glob(
        $secureFolder . '/*.json'
    );


    if (!$files || count($files) === 0) {

        throw new Exception(
            'Firebase service-account JSON file was not found.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | USE FIRST JSON FILE
    |--------------------------------------------------------------------------
    */

    $jsonFile = $files[0];


    /*
    |--------------------------------------------------------------------------
    | READ JSON
    |--------------------------------------------------------------------------
    */

    $json = file_get_contents(
        $jsonFile
    );


    if ($json === false) {

        throw new Exception(
            'Unable to read Firebase service-account file.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DECODE JSON
    |--------------------------------------------------------------------------
    */

    $serviceAccount =
        json_decode(
            $json,
            true
        );


    if (!is_array($serviceAccount)) {

        throw new Exception(
            'Invalid Firebase service-account JSON.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK REQUIRED VALUES
    |--------------------------------------------------------------------------
    */

    if (
        empty($serviceAccount['client_email']) ||
        empty($serviceAccount['private_key']) ||
        empty($serviceAccount['project_id'])
    ) {

        throw new Exception(
            'Firebase service-account information is incomplete.'
        );
    }


    return $serviceAccount;
}


/*
|--------------------------------------------------------------------------
| CREATE JWT
|--------------------------------------------------------------------------
*/

function createFirebaseJWT(
    $serviceAccount
) {

    $now = time();


    /*
    |--------------------------------------------------------------------------
    | JWT HEADER
    |--------------------------------------------------------------------------
    */

    $header = [

        'alg' => 'RS256',

        'typ' => 'JWT'

    ];


    /*
    |--------------------------------------------------------------------------
    | JWT PAYLOAD
    |--------------------------------------------------------------------------
    */

    $payload = [

        'iss' =>
            $serviceAccount['client_email'],

        'scope' =>
            'https://www.googleapis.com/auth/firebase.messaging',

        'aud' =>
            'https://oauth2.googleapis.com/token',

        'iat' =>
            $now,

        'exp' =>
            $now + 3600

    ];


    /*
    |--------------------------------------------------------------------------
    | BASE64 URL ENCODE
    |--------------------------------------------------------------------------
    */

    $base64UrlEncode =
        function ($data) {

            return rtrim(

                strtr(

                    base64_encode($data),

                    '+/',

                    '-_'

                ),

                '='
            );
        };


    /*
    |--------------------------------------------------------------------------
    | ENCODE HEADER
    |--------------------------------------------------------------------------
    */

    $encodedHeader =
        $base64UrlEncode(
            json_encode($header)
        );


    /*
    |--------------------------------------------------------------------------
    | ENCODE PAYLOAD
    |--------------------------------------------------------------------------
    */

    $encodedPayload =
        $base64UrlEncode(
            json_encode($payload)
        );


    /*
    |--------------------------------------------------------------------------
    | UNSIGNED TOKEN
    |--------------------------------------------------------------------------
    */

    $unsignedToken =
        $encodedHeader .
        '.' .
        $encodedPayload;


    /*
    |--------------------------------------------------------------------------
    | LOAD PRIVATE KEY
    |--------------------------------------------------------------------------
    */

    $privateKey =
        openssl_pkey_get_private(
            $serviceAccount['private_key']
        );


    if ($privateKey === false) {

        throw new Exception(
            'Unable to load Firebase private key.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SIGN JWT
    |--------------------------------------------------------------------------
    */

    $signature = '';


    $signed =
        openssl_sign(

            $unsignedToken,

            $signature,

            $privateKey,

            OPENSSL_ALGO_SHA256

        );


    if (!$signed) {

        throw new Exception(
            'Unable to sign Firebase JWT.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | RETURN JWT
    |--------------------------------------------------------------------------
    */

    return
        $unsignedToken .
        '.' .
        $base64UrlEncode(
            $signature
        );
}


/*
|--------------------------------------------------------------------------
| GET FIREBASE ACCESS TOKEN
|--------------------------------------------------------------------------
*/

function getFirebaseAccessToken(
    $serviceAccount
) {

    /*
    |--------------------------------------------------------------------------
    | CREATE JWT
    |--------------------------------------------------------------------------
    */

    $jwt =
        createFirebaseJWT(
            $serviceAccount
        );


    /*
    |--------------------------------------------------------------------------
    | POST DATA
    |--------------------------------------------------------------------------
    */

    $postData =
        http_build_query([

            'grant_type' =>
                'urn:ietf:params:oauth:grant-type:jwt-bearer',

            'assertion' =>
                $jwt

        ]);


    /*
    |--------------------------------------------------------------------------
    | GOOGLE OAUTH
    |--------------------------------------------------------------------------
    */

    $ch =
        curl_init(
            'https://oauth2.googleapis.com/token'
        );


    curl_setopt_array(
        $ch,
        [

            CURLOPT_POST =>
                true,

            CURLOPT_POSTFIELDS =>
                $postData,

            CURLOPT_HTTPHEADER =>
                [

                    'Content-Type: application/x-www-form-urlencoded'

                ],

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_TIMEOUT =>
                30

        ]
    );


    /*
    |--------------------------------------------------------------------------
    | EXECUTE
    |--------------------------------------------------------------------------
    */

    $response =
        curl_exec($ch);


    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    $curlError =
        curl_error($ch);


    curl_close($ch);


    /*
    |--------------------------------------------------------------------------
    | CURL ERROR
    |--------------------------------------------------------------------------
    */

    if ($response === false) {

        throw new Exception(
            'Google OAuth request failed: ' .
            $curlError
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DECODE RESPONSE
    |--------------------------------------------------------------------------
    */

    $result =
        json_decode(
            $response,
            true
        );


    /*
    |--------------------------------------------------------------------------
    | GOOGLE ERROR
    |--------------------------------------------------------------------------
    */

    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        $message =
            $result['error_description']
            ??
            $result['error']
            ??
            'Unknown Google OAuth error.';


        throw new Exception(
            'Firebase authentication failed: ' .
            $message
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ACCESS TOKEN CHECK
    |--------------------------------------------------------------------------
    */

    if (
        empty(
            $result['access_token']
        )
    ) {

        throw new Exception(
            'Firebase access token was not returned.'
        );
    }


    return
        $result['access_token'];
}


/*
|--------------------------------------------------------------------------
| SEND FCM NOTIFICATION
|--------------------------------------------------------------------------
*/

function sendFCMNotification(
    $fcmToken,
    $title,
    $body,
    $orderId = null
) {

    /*
    |--------------------------------------------------------------------------
    | CLEAN TOKEN
    |--------------------------------------------------------------------------
    */

    $fcmToken =
        trim(
            (string)$fcmToken
        );


    /*
    |--------------------------------------------------------------------------
    | TOKEN CHECK
    |--------------------------------------------------------------------------
    */

    if ($fcmToken === '') {

        return [

            'success' =>
                false,

            'message' =>
                'FCM token is empty.'

        ];
    }


    try {

        /*
        |--------------------------------------------------------------------------
        | LOAD SERVICE ACCOUNT
        |--------------------------------------------------------------------------
        */

        $serviceAccount =
            getFirebaseServiceAccount();


        /*
        |--------------------------------------------------------------------------
        | GET ACCESS TOKEN
        |--------------------------------------------------------------------------
        */

        $accessToken =
            getFirebaseAccessToken(
                $serviceAccount
            );


        /*
        |--------------------------------------------------------------------------
        | PROJECT ID
        |--------------------------------------------------------------------------
        */

        $projectId =
            $serviceAccount['project_id'];


        /*
        |--------------------------------------------------------------------------
        | FCM HTTP V1 URL
        |--------------------------------------------------------------------------
        */

        $url =
            'https://fcm.googleapis.com/v1/projects/' .
            rawurlencode($projectId) .
            '/messages:send';


        /*
        |--------------------------------------------------------------------------
        | BUILD MESSAGE
        |--------------------------------------------------------------------------
        */

        $message = [

            'message' => [

                'token' =>
                    $fcmToken,


                /*
                |--------------------------------------------------------------------------
                | NOTIFICATION
                |--------------------------------------------------------------------------
                */

                'notification' => [

                    'title' =>
                        (string)$title,

                    'body' =>
                        (string)$body

                ],


                /*
                |--------------------------------------------------------------------------
                | ANDROID
                |--------------------------------------------------------------------------
                */

                'android' => [

                    'priority' =>
                        'high',

                    'notification' => [

                        'channel_id' =>
                            'order_channel',

                        'sound' =>
                            'default'

                    ]

                ],


                /*
                |--------------------------------------------------------------------------
                | DATA
                |--------------------------------------------------------------------------
                */

                'data' => [

                    'type' =>
                        'order_update',

                    'order_id' =>
                        $orderId !== null
                            ? (string)$orderId
                            : '',

                    'title' =>
                        (string)$title,

                    'body' =>
                        (string)$body

                ]

            ]

        ];


        /*
        |--------------------------------------------------------------------------
        | CURL REQUEST
        |--------------------------------------------------------------------------
        */

        $ch =
            curl_init($url);


        curl_setopt_array(
            $ch,
            [

                CURLOPT_POST =>
                    true,

                CURLOPT_POSTFIELDS =>
                    json_encode(
                        $message
                    ),

                CURLOPT_HTTPHEADER =>
                    [

                        'Authorization: Bearer ' .
                        $accessToken,

                        'Content-Type: application/json'

                    ],

                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_TIMEOUT =>
                    30

            ]
        );


        /*
        |--------------------------------------------------------------------------
        | SEND
        |--------------------------------------------------------------------------
        */

        $response =
            curl_exec($ch);


        $httpCode =
            curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );


        $curlError =
            curl_error($ch);


        curl_close($ch);


        /*
        |--------------------------------------------------------------------------
        | CURL ERROR
        |--------------------------------------------------------------------------
        */

        if ($response === false) {

            return [

                'success' =>
                    false,

                'message' =>
                    'FCM request failed: ' .
                    $curlError

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | FIREBASE ERROR
        |--------------------------------------------------------------------------
        */

        if (
            $httpCode < 200 ||
            $httpCode >= 300
        ) {

            $errorData =
                json_decode(
                    $response,
                    true
                );


            $errorMessage =
                $errorData['error']['message']
                ??
                'Unknown Firebase error.';


            return [

                'success' =>
                    false,

                'http_code' =>
                    $httpCode,

                'message' =>
                    $errorMessage,

                'response' =>
                    $errorData

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | SUCCESS
        |--------------------------------------------------------------------------
        */

        return [

            'success' =>
                true,

            'http_code' =>
                $httpCode,

            'message' =>
                'Notification sent successfully.',

            'response' =>
                json_decode(
                    $response,
                    true
                )

        ];


    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | ERROR
        |--------------------------------------------------------------------------
        */

        return [

            'success' =>
                false,

            'message' =>
                $e->getMessage()

        ];
    }
}

?>