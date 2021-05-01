# plugbase

A Pluggable Base for MadelineProto Library 

The program's core accepts two types of plugins; event-handler plugins, and repeating task plugins.

## Event-Handler plugins

Each event-handler plugin extends an abstract class which provides basic services to plugins which minimimizes the efforts for writing a new plugin.

Each plugin must provide a method __invokes which is invoked on each individual incomming telegram update message. An example of a working plugin is as follows:

```php
<?php

class PingHandler extends AbstractHandler implements Handler
{
    public function __invoke(array $update, array $vars, BaseEventHandler $eh): \Generator
    {
        if($vars['msgType'] === 'updateNewMessage' && strtolower($vars['msgText']) === 'ping') {
            yield $eh->messages->sendMessage([
                'peer'            => $vars['peer'],
                'reply_to_msg_id' => $vars['msgId'],
                'message'         => 'Pong'
            ]);
            $eh->logger("Command '/ping' successfuly executed at " . $eh->formatTime() . '!');
        }
        return true; // return false if $update is not handled
    }
}
```
If you need to initialize your class, you can add the onStart method to your class, which will be invoked once before calling the first __invoked invocation:

```php
    public function onStart(BaseEventHandler $eh): \Generator
    {
        // your code here
    }
```

No need to mention that you can use any features of PHP class in your plugins.

To integrate your handler plugin, you must add it's name as an element to the handler array in the config.php file.

```php
'loops' => [... , 'PingHandler', ...],
```

