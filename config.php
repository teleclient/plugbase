<?php

declare(strict_types=1);

error_log("TEST TEST TEST");
// $server_root = realpath($_SERVER["DOCUMENT_ROOT"]);
// $config_serv = "$server_root/php/config.php";
// include("$config_serv");

return [
    'admins'     => [],
    //'ownerid'  => 1234,
    //'officeid' => 1234,
    //'host'     => '', // <== In case $_SERVER['SERVER_NAME'] is not defined, set the webserver's host name.
    'zone'     => 'Asia/Tehran',
    'prefixes' => '!/',
    'edit'     => false,
    'mp'   => [
        0 => [
            'notification' => 'off',
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

function getHelpText(string $prefixes): string
{
    $text = '' .
        '<b>Robot Instructions:</b><br>' .
        '<br>' .
        '>> <b>/help</b><br>' .
        '   To print the robot commands<br>' .
        //">> <b>/loop</b> on/off/state<br>" .
        //"   To query/change state of task repeater.<br>" .
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


//$settings['app_info']['api_id']   = 904912;
//$settings['app_info']['api_hash'] = "8208f08eefc502bedea8b5d437be898e";

//'api_id'   => 904912,
//'api_hash' => "8208f08eefc502bedea8b5d437be898e",

//'api_id'   => 6,                                  // <== Use your own, or let MadelineProto ask you.
//'api_hash' => "eb06d4abfb49dc3eeb1aeb98ae0f581e", // <== Use your own, or let MadelineProto ask you.
