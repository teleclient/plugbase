<?php

declare(strict_types=1);

return (object) [
    'host' => '', // <== In case $_SERVER['SERVER_NAME'] is not defined, set the webserver's host name.
    'zone' => 'Asia/Tehran',
    'mp'   => [
        0 => [
            'phone'    => '+19498364399',
            'password' => '',
            'session'  => 'madeline.madeline',
            'settings' => [
                'app_info' => [
                    'app_version' => SCRIPT_INFO, // <== Do not change!
                    'api_id'   => 904912,
                    'api_hash' => "8208f08eefc502bedea8b5d437be898e",
                ],
                'logger' => [
                    'logger'       => \danog\MadelineProto\Logger::FILE_LOGGER,
                    'logger_level' => \danog\MadelineProto\Logger::NOTICE,
                ],
                'peer' => [
                    //'full_info_cache_time' => 60,
                ],
                'serialization' => [
                    //'cleanup_before_serialization' => true,
                ],
            ]
        ]
    ]
];

function initScript()
{
    \date_default_timezone_set('UTC');
    \ignore_user_abort(true);
    \error_reporting(E_ALL);                                 // always TRUE
    ini_set('max_execution_time',     '0');
    ini_set('ignore_repeated_errors', '1');                 // always TRUE
    ini_set('display_startup_errors', '1');
    ini_set('display_errors',         '1');                 // FALSE only in production or real server
    ini_set('log_errors',             '1');                 // Error logging engine
    ini_set('error_log',              'MadelineProto.log'); // Logging file path
    ini_set('precision',              '18');
}

//$settings['app_info']['api_id']   = 904912;
//$settings['app_info']['api_hash'] = "8208f08eefc502bedea8b5d437be898e";

//'api_id'   => 904912,
//'api_hash' => "8208f08eefc502bedea8b5d437be898e",

//'api_id'   => 6,                                  // <== Use your own, or let MadelineProto ask you.
//'api_hash' => "eb06d4abfb49dc3eeb1aeb98ae0f581e", // <== Use your own, or let MadelineProto ask you.
