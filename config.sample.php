<?php

return [
    'adminIds'    => [], // Robot Id will authomatically be included
    //'ownerid'   => 1234,
    //'officeid'  => 1234,
    //'host'      => '', // <== In case $_SERVER['SERVER_NAME'] is not defined, set the webserver's host name.
    'zone'        => 'UTC',
    'prefixes'    => '!/',
    'source'      => 'phar 5.1.34',
    'edit'        => false,
    'maxrestarts' => 10,
    'mp'   => [
        0  => [
            'session'      => 'madeline.madeline',
            'filterlog'    => false,
            'notification' => 'off',
            'handlers' => ['Builtin', 'My'],
            'loops'    => ['Builtin', 'My', 'Watch'],
            'settings' => [
                'app_info' => [
                    //'api_id'   => 6,                                  // <== Use your own, or let MadelineProto ask you.
                    //'api_hash' => "eb06d4abfb49dc3eeb1aeb98ae0f581e", // <== Use your own, or let MadelineProto ask you.
                    'device_model'   => 'DEVICE_MODEL',
                    'system_version' => 'SYSTEM_VERSION',
                ],
                'logger' => [
                    'logger'       => 2,  // 2:FILE_LOGGER
                    'logger_level' => 2,  // 1:ERROR 2:WARNING 3:NOTICE 4:VERBOSE
                    'logger_param' => __DIR__ . '/MadelineProto.log',
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
