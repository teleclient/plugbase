<?php

declare(strict_types=1);

return [
    'adminIds'    => [], // Robot Id will authomatically be included
    //'host'      => '', // <== In case $_SERVER['SERVER_NAME'] is not defined, set the webserver's host name.
    'zone'        => 'Asia/Tehran',
    'prefixes'    => '!/',
    'edit'        => false,
    'maxrestarts' => 10,
    'mp'   => [
        0  => [
            'session'      => 'madeline.madeline',
            'filterlog'    => false,
            'notification' => 'off',
            'handlers' => ['Builtin', 'My'],
            'loops'    => ['Builtin', 'My'],
            'settings' => [
                'app_info' => [
                    'app_version' => SCRIPT_INFO,                     // <== Do not change!
                    //'api_id'   => 6,                                  // <== Use your own, or let MadelineProto ask you.
                    //'api_hash' => "eb06d4abfb49dc3eeb1aeb98ae0f581e", // <== Use your own, or let MadelineProto ask you.
                ],
                'logger' => [
                    'logger'       => \danog\MadelineProto\Logger::FILE_LOGGER,
                    'logger_param' => __DIR__ . '/MadelineProto.log',
                    'logger_level' => \danog\MadelineProto\Logger::NOTICE,
                    'max_size'     => 100 * 1024 * 1024
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
