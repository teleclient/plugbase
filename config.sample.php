<?php

declare(strict_types=1);

return [
    'admins'     => [], // Robot Id will authomatically be included
    //'ownerid'  => 1234,
    //'officeid' => 1234,
    //'host'     => '', // <== In case $_SERVER['SERVER_NAME'] is not defined, set the webserver's host name.
    'zone'     => 'Asia/Tehran',
    'prefixes' => '!/',
    'edit'     => false,
    'maxrestarts' => 10,
    'mp'   => [
        0 => [
            'session'      => 'madeline.madeline',
            'filterlog'    => false,
            'notification' => 'off',
            'phone'    => '+14328364939',
            'password' => '',
            'handlers' => ['BuiltinHandler', 'YourHandler'],
            'loops'    => ['BuiltinLoop',    'YourLoop'],
            'settings' => [
                'app_info' => [
                    'app_version' => SCRIPT_INFO,                     // <== Do not change!
                    'api_id'   => 6,                                  // <== Use your own, or let MadelineProto ask you.
                    'api_hash' => "eb06d4abfb49dc3eeb1aeb98ae0f581e", // <== Use your own, or let MadelineProto ask you.
                ],
                'logger' => [
                    'logger'       => \danog\MadelineProto\Logger::FILE_LOGGER,
                    'logger_param' => __DIR__ . '/MadelineProto.log',
                    'logger_level' => \danog\MadelineProto\Logger::NOTICE,
                    'max_size'     => 100 * 1024 * 1024
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

function getHelpText(string $prefixes): string
{
    $text = '' .
        '<b>Robot Instructions:</b><br>' .
        '<br>' .
        '>> <b>/help</b><br>' .
        '   To print the robot commands<br>' .
        '>> <b>/status</b><br>' .
        '   To query the status of the robot.<br>' .
        '>> <b>/stats</b><br>' .
        '   To query the statistics of the robot.<br>' .
        '>> <b>/notif OFF / ON 20</b><br>' .
        '   No event notification or notify every 20 secs.<br>' .
        '>> <b>/crash</b><br>' .
        '   To generate an exception for testing.<br>' .
        '>> <b>/restart</b><br>' .
        '   To restart the robot.<br>' .
        '>> <b>/stop</b><br>' .
        '   To stop the script.<br>' .
        '>> <b>/logout</b><br>' .
        '   To terminate the robot\'s session.<br>' .
        '<br>' .
        '<b>**Valid prefixes are / and !</b><br>';
    return $text;
}
