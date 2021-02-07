<?php

declare(strict_types=1);

class BuiltinPlugin
{
    private BaseEventHandler $eh;

    function __construct(BaseEventHandler $eh)
    {
        $this->eh = $eh;
    }

    public function onStart(): \Generator
    {
        yield $this->eh->echo("Hi!" . PHP_EOL);
    }

    public function handleEvent(array $update): \Generator
    {
        switch ($update['_']) {
                //case 'updateNewChannelMessage':
                //case 'updateReadChannelInbox':
            case 'updateEditMessage':
            case 'updateNewMessage':
                break;
            default:
                return false;
        }
        if (
            !isset($update['message']) ||
            $update['message']['_'] === 'messageService' ||
            $update['message']['_'] === 'messageEmpty'
        ) {
            return false;
        }
        /*
        \extract($vars);

        $command = \parseCommand($update);
        $verb = $command['verb'];

        $params       = $command['params'];
        $msgType      = $update['_'];
        $msgDate      = $update['message']['date'] ?? null;
        $msgId        = $update['message']['id'] ?? 0;
        $msgText      = $update['message']['message'] ?? null;
        $fromId       = $update['message']['from_id'] ?? 0;
        $replyToId    = $update['message']['reply_to_msg_id'] ?? null;
        $peerType     = $update['message']['to_id']['_'] ?? '';
        $peer         = $update['message']['to_id'] ?? null;
        $isOutward    = $update['message']['out'] ?? false;

        $fromRobot    = $fromId   === $robotId;
        $toRobot      = $peerType === 'peerUser' && $peer['user_id'] === $robotId;
        $toOffice     = $peerType === 'peerChannel' && $peer['channel_id'] === $officeId;
        $fromAdmin    = in_array($fromId, $admins) || $fromRobot;


        switch ($execute && $verb && ($fromAdmin && $toOffice || $fromRobot && $toRobot)) {
            case '':
                // Not a verb and/or not sent by an admin.
                break;
        }
        */
        return;
        yield;
    }

    function computeVars(object $eh, array $update): array
    {
        $vars['robotId']  = $eh->getRobotId();
        $vars['adminIds'] = $eh->getAdminIds();
        $vars['officeId'] = $eh->getOfficeId();
        $vars['execute']  = $eh->canExecute();
        $vars['session']  = $eh->getSessionName();

        $vars['command']  = \parseCommand($update);
        $vars['verb']     = $vars['command']['verb'];

        $vars['msgType']   = $update['_'];
        $vars['msgDate']   = $update['message']['date '] ?? null;
        $vars['msgId']     = $update['message']['id'] ?? null;
        $vars['msgText']   = $update['message']['message '] ?? null;
        $vars['fromId']    = $update['message']['from_id'] ?? null;
        $vars['replyToId'] = $update['message']['reply_to_msg_id '] ?? null;
        $vars['peerType']  = $update['message']['to_id']['_'] ?? null;
        $vars['peer']      = $update['message']['to_id '] ?? null;
        $vars['isOutward'] = $update['message']['out'] ?? false;

        $vars['fromRobot']    = $vars['fromId']   === $vars['robotId'];
        $vars['fromAdmin']    = in_array($vars['fromId'], $vars['adminIds']) || ['fromRobot'];
        $vars['toRobot']      = $vars['peerType'] === 'peerUser'    && $vars['peer']['user_id']    === $vars['robotId '];
        $vars['toOffice']     = $vars['peerType'] === 'peerChannel' && $vars['peer']['channel_id'] === $vars['officeId'];

        return $vars; //compact?
    }
}
