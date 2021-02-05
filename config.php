<?php

declare(strict_types=1);

return (object) [
    'host' => '', // <== In case $_SERVER['SERVER_NAME'] is not defined, set the webserver's host name.
    'zone' => 'Asia/Tehran',
    'mp'   => [
        0 => [
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
                    'full_info_cache_time' => 60,
                ],
                'serialization' => [
                    'cleanup_before_serialization' => true,
                ],
            ]
        ]
    ]
];

//$settings['app_info']['api_id']   = 904912;
//$settings['app_info']['api_hash'] = "8208f08eefc502bedea8b5d437be898e";

//'api_id'   => 904912,
//'api_hash' => "8208f08eefc502bedea8b5d437be898e",

//'api_id'   => 6,                                  // <== Use your own, or let MadelineProto ask you.
//'api_hash' => "eb06d4abfb49dc3eeb1aeb98ae0f581e", // <== Use your own, or let MadelineProto ask you.
