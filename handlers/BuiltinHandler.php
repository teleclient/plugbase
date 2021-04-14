<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;

class BuiltinHandler extends AbstractHandler implements Handler
{
    const STOPPING_MSG = 'Robot is stopping ...';

    private BaseEventHandler $eh;
    private int              $totalUpdates;

    function __construct(BaseEventHandler $eh)
    {
        parent::__construct($eh);

        $this->eh = $eh;
        $this->totalUpdates = 0;
    }

    public function onStart(BaseEventHandler $eh): \Generator
    {
        $this->eh = $eh;

        // Send a startup notification and wipe it if configured so 
        $notif      = $this->getNotif();
        $notifState = substr($notif, 0, 2) === 'on';
        $notifAge   = strlen($notif) <= 3 ? 0 : intval(substr($notif, 3));
        $dest       = $eh->getRobotId();
        if ($notifState) {
            $nowstr = $eh->formatTime($eh->getHandlerUnserialized());
            $text = SCRIPT_INFO . ' started at ' . $nowstr . ' on ' . hostName() . ' using ' . $eh->getRobotName() . ' account.';
            $result = yield $eh->messages->sendMessage([
                'peer'    => $dest,
                'message' => $text
            ]);
            $eh->logger($text, Logger::ERROR);
            if ($notifAge > 0) {
                $msgid = $result['updates'][1]['message']['id'];
                $eh->callFork((function () use ($eh, $msgid, $notifAge) {
                    try {
                        yield $eh->sleep($notifAge);
                        yield $eh->messages->deleteMessages([
                            'revoke' => true,
                            'id'     => [$msgid]
                        ]);
                        $eh->logger('Robot\'s startup message is deleted.', Logger::ERROR);
                    } catch (\Exception $e) {
                        $eh->logger($e, Logger::ERROR);
                    }
                })());
            }
        }
    }

    public function __invoke(array $update, array $vars, BaseEventHandler $eh): \Generator
    {
        $this->totalUpdates += 1;

        if (!($vars['fromRobot'] && $vars['toRobot']) && !($vars['fromAdmin'] && $vars['toOffice'])) {
            return false;
        }
        if (!oneOf($update, 'NewMessage|EditMessage') || !hasText($update)) {
            return false;
        }

        if ($eh->newMessage($update)) {

            //Function: Finnish executing the Stop command.
            if ($vars['msgText'] === self::STOPPING_MSG) {
                if (Shutdown::removeCallback('restarter')) {
                    $eh->logger('Self-Restarter disabled.', Logger::ERROR);
                }
                $eh->logger('Robot stopped at ' . $eh->formatTime() . '!', Logger::ERROR);
                yield $eh->stop();
                return true;
            }

            //Function: Finnish executing the Restart command.
            if ($vars['msgText'] === 'Robot is restarting ...') {
                $eh->logger('Robot restarted at ' . $eh->formatTime() . '!', Logger::ERROR);
                yield $eh->restart();
                return true;
            }

            //Function: Finnish executing the Logout command.
            if ($vars['msgText'] === 'Robot is logging out ...') {
                $eh->logger('Robot logged out at ' . $eh->formatTime() . '!', Logger::ERROR);
                yield $eh->logout();
                return true;
            }
        }

        if (!hasText($update) || $update['_'] !== 'updateNewMessage') {
            return false;
        }
        if (!isset($vars['msgText'][0]) || strpos($eh->getPrefixes(), $vars['msgText'][0]) === false) {
            return false;
        }

        extract($vars);
        //$eh->logger("vars: " . toJSON($vars), Logger::ERROR);

        $executed = false;
        switch ($fromRobot ? $verb : '') {
            case '':
                // Not a verb and/or not sent by an admin.
                break;
            case 'ping':
                yield $eh->messages->sendMessage([
                    'peer'            => $peer,
                    'reply_to_msg_id' => $msgId,
                    'message'         => 'Pong'
                ]);
                yield $eh->logger("Command '/ping' successfuly executed at " . $eh->formatTime() . '!', Logger::ERROR);
                $executed = true;
                break;
        }
        switch ($executed ? '' : $verb) {
            case '':
                break;
            case 'help':
                $text = getHelpText($eh->getPrefixes());
                yield respond($eh, $peer, $msgId, $text);
                $eh->logger("Command '/help' successfuly executed at " . $eh->formatTime() . '!', Logger::ERROR);
                break;
            case 'status':
                $sessionCreation = yield $eh->getSessionCreation();
                $peakMemUsage    = formatBytes(\getPeakMemory(), 3);
                $currentMemUsage = formatBytes(\getCurrentMemory(), 3);
                $memoryLimit     = ini_get('memory_limit');
                $memoryLimit     = $memoryLimit === '-1' || $memoryLimit === '0' ? 'MAX' : $memoryLimit;
                $sessionSize     = formatBytes(getFileSize($eh->getSessionName()), 3);
                $launch          = yield \Launch::getPreviousLaunch($eh, LAUNCHES_FILE, SCRIPT_START_TIME);
                if ($launch) {
                    $lastStartTime        = $eh->formatTime($launch['time_start']);
                    $lastEndTime          = $eh->formatTime($launch['time_end']);
                    $lastDowntimeDuration = \UserDate::duration($launch['time_end'], $eh->getScriptStarted());
                    $lastLaunchMethod     = $launch['launch_method'];
                    $lastLaunchDuration   = \UserDate::duration($launch['time_start'], $launch['time_end']);
                    $lastPeakMemory       = formatBytes($launch['memory_end']);
                } else {
                    $lastDowntimeDuration = 'UNAVAILABLE';
                    $lastStartTime        = 'UNAVAILABLE';
                    $lastEndTime          = 'UNAVAILABLE';
                    $lastLaunchMethod     = 'UNAVAILABLE';
                    $lastLaunchDuration   = 'UNAVAILABLE';
                    $lastPeakMemory       = 'UNAVAILABLE';
                }
                $notif      = $this->getNotif();
                $notifState = substr($notif, 0, 2) === 'on' ? 'ON' : 'OFF';
                $notifAge   = $notifState === 'OFF' ? '' : (strlen($notif) <= 3 ? ' / Never wipe' : (' / Wipe after ' . substr($notif, 3) . ' secs'));
                $notifStr   = "$notifState$notifAge";
                $now        = \microtime(true);

                $status  = '<b>STATUS:</b>  (Script: ' . SCRIPT_INFO . ')<br>';
                $status .= "Host: " . hostname() . "<br>";
                $status .= "Robot's Account: " . $eh->getRobotName() . "<br>";
                $status .= "Robot's User-Id: $robotId<br>";
                $status .= "Session Age: "              . \UserDate::duration($sessionCreation,               $now) . "<br>";
                $status .= "Script Age: "               . \UserDate::duration($eh->getScriptStarted(),        $now) . "<br>";
                $status .= "Handler Construction Age: " . \UserDate::duration($eh->getHandlerConstructed(),   $now) . "<br>";
                //$status .= "Handler Unserialized Age: " . \UserDate::duration($eh->getHandlerUnserialized(),$now) . "<br>";
                $status .= "Peak Memory: $peakMemUsage<br>";
                $status .= "Current Memory: $currentMemUsage<br>";
                $status .= "Allowed Memory: $memoryLimit<br>";
                $status .= 'CPU Process Que Size: ' . getCpuUsage() . '<br>';
                $status .= "Session Size: $sessionSize<br>";
                $status .= 'Time: ' . $eh->getZone() . ' ' . $eh->formatTime() . '<br>';
                $status .= 'Updates Processed: ' . $this->totalUpdates . '<br>';
                $status .= 'Notification: ' . $notifStr . PHP_EOL;
                $status .= 'Launch Method: ' . \getLaunchMethod() . '<br>';
                $status .= 'Last Downtime Duration: '   . $lastDowntimeDuration . '<br>';
                $status .= 'Previous Start Time: '      . $lastStartTime . '<br>';
                $status .= 'Previous Stop Time: '       . $lastEndTime . '<br>';
                $status .= 'Previous Launch Method: '   . $lastLaunchMethod . '<br>';
                $status .= 'Previous Launch Duration: ' . $lastLaunchDuration . '<br>';
                $status .= 'Previous Peak Memory: '     . $lastPeakMemory . '<br>';
                yield respond($eh, $peer, $msgId, $status);
                $eh->logger("Command '/status' successfuly executed at " . $eh->formatTime() . '!', Logger::ERROR);
                break;
            case 'stats':
                $text   = "Preparing statistics ....";
                $result = yield respond($eh, $peer, $msgId, $text);
                $resMsgId = $eh->getEditMessage() ? $result[0]['message']['id'] : $result['updates'][0]['id'];
                unset($result);
                $response = yield $eh->contacts->getContacts([]);
                $totalCount  = count($response['users']);
                $mutualCount = 0;
                foreach ($response['users'] as $user) {
                    $mutualCount += ($user['mutual_contact'] ?? false) ? 1 : 0;
                }
                unset($response);
                $totalDialogsOut = 0;
                $peerCounts   = [
                    'user' => 0, 'bot' => 0, 'basicgroup' => 0, 'supergroup' => 0, 'channel' => 0,
                    'chatForbidden' => 0, 'channelForbidden' => 0
                ];
                $params = [];
                yield visitAllDialogs(
                    $eh,
                    $params,
                    function (
                        $mp,
                        int    $totalDialogs,
                        int    $index,
                        int    $botapiId,
                        string $subtype,
                        string $name,
                        ?array $userOrChat,
                        array  $message
                    )
                    use (&$totalDialogsOut, &$peerCounts): void {
                        $totalDialogsOut = $totalDialogs;
                        $peerCounts[$subtype] += 1;
                    }
                );
                $stats  = '<b>STATISTICS</b>  (Script: ' . SCRIPT_INFO . ')<br>';
                $stats .= "Robot's Account: " . $eh->getRobotName() . "<br>";
                $stats .= "Total Dialogs: $totalDialogsOut<br>";
                $stats .= "Users: {$peerCounts['user']}<br>";
                $stats .= "Bots: {$peerCounts['bot']}<br>";
                $stats .= "Basic groups: {$peerCounts['basicgroup']}<br>";
                $stats .= "Forbidden Basic groups: {$peerCounts['chatForbidden']}<br>";
                $stats .= "Supergroups: {$peerCounts['supergroup']}<br>";
                $stats .= "Channels: {$peerCounts['channel']}<br>";
                $stats .= "Forbidden Supergroups or Channels: {$peerCounts['channelForbidden']}<br>";
                $stats .= "Total Contacts: $totalCount<br>";
                $stats .= "Mutual Contacts: $mutualCount";
                yield respond($eh, $peer, $resMsgId, $stats, true);
                break;
            case 'crash':
                $eh->logger("Purposefully crashing the script....", Logger::ERROR);
                $e = new \ErrorException('Artificial exception generated for testing the robot.');
                //yield $this->echo($e->getTraceAsString($e) . PHP_EOL);
                throw $e;
            case 'maxmem':
                $arr = array();
                try {
                    for ($i = 1;; $i++) {
                        $arr[] = md5(strvAL($i));
                    }
                } catch (\Exception $e) {
                    unset($arr);
                    $text = $e->getMessage();
                    $eh->logger($text, Logger::ERROR);
                }
                break;
            case 'notif':
                $params = $command['params'];
                $param1 = strtolower($params[0] ?? '');
                $paramsCount = count($params);
                if (
                    ($param1  !== 'on'  && $param1 !== 'off' && $param1 !== 'state') ||
                    ($param1  === 'on'  && $paramsCount !== 1 && $paramsCount !== 2) ||
                    ($param1  === 'on'  && $paramsCount === 2 && !ctype_digit($params['1'])) ||
                    (($param1 === 'off' || $param1 === 'state') && $paramsCount !== 1)
                ) {
                    $text = "The notif argument must be 'off', 'on', 'on 123', or 'state'.";
                    yield respond($eh, $peer, $msgId, $text);
                    break;
                }
                if ($param1 === 'on') {
                    $notification = 'on' . (!isset($params[1]) ? '' : (' ' . $params[1]));
                    yield $eh->echo("Notification: '$notification'" . PHP_EOL);
                    $this->setNotif($notification);
                } elseif ($param1 === 'off') {
                    $this->setNotif('off');
                }
                $notif = $this->getNotif();
                $notifState = substr($notif, 0, 2) === 'on' ? 'ON' : 'OFF';
                $notifAge   = strlen($notif) <= 3 ? '' : (' / ' . substr($notif, 3) . ' secs');
                $text = "The notif is $notifState$notifAge";
                yield respond($eh, $peer, $msgId, $text);
                break;
            case 'loop_OLD':
                $param = strtolower($params[0] ?? '');
                if (($param === 'on' || $param === 'off' || $param === 'state') && count($params) === 1) {
                    $loopStatePrev = $eh->getLoopState();
                    $loopState = $param === 'on' ? true : ($param === 'off' ? false : $loopStatePrev);
                    $text = 'The loop is ' . ($loopState ? 'ON' : 'OFF') . '!';
                    yield respond($eh, $peer, $msgId, $text, $editMessage);
                    if ($loopState !== $loopStatePrev) {
                        $eh->setLoopState($loopState);
                    }
                } else {
                    $text = "The argument must be 'on', 'off, or 'state'.";
                    yield respond($eh, $peer, $msgId, $text, $editMessage);
                }
                break;
            case 'loop':
                $params      = $command['params'];
                $loopname    = strtolower($params[0] ?? '');
                $action      = strtolower($params[1] ?? '');
                $paramsCount = count($params);
                if (
                    $paramsCount === 2 && $action !== 'on' && $action !== 'off' && $action !== 'state' ||
                    $paramsCount === 1 && $action !== 'on' && $action !== 'off' && $action !== ''
                ) {
                    $text = "The loop action must be one of 'pause', 'resume', or 'state'.";
                    $eh->logger($text, Logger::ERROR);
                    yield respond($eh, $peer, $msgId, $text);
                    break;
                }
                $loopObj = $eh->getLoops()[$loopname] ?? null;
                if (!$loopObj) {
                    $text = "Unknown loop '$loopname'!";
                    $eh->logger($text, Logger::ERROR);
                    yield respond($eh, $peer, $msgId, $text);
                    break;
                }
                if (false) {
                    if ($action === 'off') {
                        yield $loopObj->pause();
                        $text = "The $loopname loop plugin pauseed!";
                        yield respond($eh, $peer, $msgId, $text);
                        $eh->logger($text, Logger::ERROR);
                    } elseif ($action === 'on') {
                        yield $loopObj->resume();
                        $text = "The $loopname loop plugin resumed!";
                        yield respond($eh, $peer, $msgId, $text);
                        $eh->logger($text, Logger::ERROR);
                    } elseif ($action === 'state') {
                        $text = "The command  '/loop $loopname state' received!";
                        yield respond($eh, $peer, $msgId, $text);
                        $eh->logger($text, Logger::ERROR);
                    }
                }
                $loopStatePrev = $eh->getLoopState($loopname);
                $loopState     = $action === 'on' ? true : ($action === 'off' ? false : $loopStatePrev);
                $text = "The $loopname loop plugin state is " . ($loopState ? 'ON' : 'OFF') . '!';
                $eh->logger($text, Logger::ERROR);
                yield respond($eh, $peer, $msgId, $text);
                if ($loopState !== $loopStatePrev) {
                    $eh->setLoopState($loopname, $loopState);
                }
                if ($loopStatePrev === false && $loopState === true) {
                    //$loopObj->resume();
                }
                $eh->logger("The command '$msgText' successfully executed!", Logger::ERROR);
                break;
            case 'restart':
                if (PHP_SAPI === 'cli') {
                    $text = "Command '/restart' is only avaiable under webservers. Ignored!";
                    yield respond($eh, $peer, $msgId, $text);
                    $eh->logger("Command '/restart' is only avaiable under webservers. Ignored!  " . $eh->formatTime() . '!', Logger::ERROR);
                    break;
                }
                $text = 'Robot is restarting ...';
                $eh->logger($text, Logger::ERROR);
                yield respond($eh, $peer, $msgId, $text);
                $eh->setStopReason('restart');
                //$eh->restart();
                break;
            case 'logout':
                $text = 'Robot is logging out ...';
                $eh->logger($text, Logger::ERROR);
                yield respond($eh, $peer, $msgId, $text);
                $eh->setStopReason('logout');
                //$eh->logout();
                break;
            case 'stop':
                $eh->logger(self::STOPPING_MSG, Logger::ERROR);
                yield respond($eh, $peer, $msgId, self::STOPPING_MSG);
                $eh->logger(self::STOPPING_MSG, Logger::ERROR);
                $eh->setStopReason($verb);
                break;
            default:
                $text = "Invalid command: '$msgText'";
                yield respond($eh, $peer, $msgId, $text);
                break;
        } // enf of the command switch
    }

    public function getNotif(): string
    {
        $saved = $this->eh->__get('notification');
        $saved = $saved ?? 'off';
        return $saved;
    }
    public function setNotif(string $notification): void
    {
        if ($notification === '') throw new ErrorException("Invalid parameters: '$notification'");
        $this->eh->__set('notification', $notification);
    }
}
