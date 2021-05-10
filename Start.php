<?php

declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Tools;
use danog\MadelineProto\MTProto;
use function Amp\ByteStream\getOutputBufferStream;

/**
 * Manages simple logging in and out.
 */
class Start
{
    private API    $mp;
    private bool   $botLogin;
    private string $botToken;
    private string $phone;
    private string $password;

    function __construct(API $mp)
    {
        $this->mp = $mp;
        echo ("The Start::__constructor is invoked" . PHP_EOL);
    }

    public function startBot(string $botToken = null): \Generator
    {
        $this->botLogin = true;
        $this->botToken = $botToken;
        return yield $this->start();
    }
    public function startUser(string $phone = null, string $password = null): \Generator
    {
        $this->botLogin = false;
        $this->phone    = $phone;
        $this->password = $password;
        return yield $this->start();
    }

    /**
     * Log in to telegram (via CLI or web).
     *
     * @return \Generator
     */
    public function start(): \Generator
    {
        echo ("The Start::start method is invoked" . PHP_EOL);
        if ($this->mp->authorized === MTProto::LOGGED_IN) {
            return yield from $this->mp->fullGetSelf();
        }
        if (PHP_SAPI === 'cli') {
            //if (\strpos(yield Tools::readLine('Do you want to login as user or bot (u/b)? '), 'b') !== false) {
            if ($this->botLogin) {
                if (!$this->botToken) {
                    $this->botToken = yield Tools::readLine('Enter your bot token: ');
                }
                yield from $this->mp->botLogin($this->botToken);
            } else {
                if (!$this->phone) {
                    $this->phone = yield Tools::readLine('Enter your phone number: ');
                }
                $sentCode = yield from $this->mp->phoneLogin($this->phone);
                echo (toJSON($sentCode) . PHP_EOL);

                $phoneCode     = yield Tools::readLine('Enter the phone code: ');
                $authorization = yield from $this->mp->completePhoneLogin($phoneCode);
                echo (toJSON($authorization) . PHP_EOL);

                if ($authorization['_'] === 'account.password') {
                    if (!$this->password) {
                        $this->password = yield Tools::readLine('Please enter your password (hint ' . $authorization['hint'] . '): ');
                    }
                    $authorization = yield from $this->mp->complete2faLogin($this->password);
                }
                if ($authorization['_'] === 'account.needSignup') {
                    $firstName = yield Tools::readLine('Please enter your first name: ');
                    $lastName  = yield Tools::readLine('Please enter your last name (can be empty): ');
                    $authorization = (yield from $this->mp->completeSignup($firstName, $lastName));
                }
            }
            $this->mp->serialize();
            return yield from $this->mp->fullGetSelf();
        }
        if ($this->mp->authorized === MTProto::NOT_LOGGED_IN) {
            if (isset($_POST['phone_number'])) {
                yield from $this->webPhoneLogin();
            } elseif (isset($_POST['token'])) {
                yield from $this->webBotLogin();
            } else {
                yield from $this->webEcho();
            }
        } elseif ($this->mp->authorized === MTProto::WAITING_CODE) {
            if (isset($_POST['phone_code'])) {
                yield from $this->webCompletePhoneLogin();
            } else {
                yield from $this->webEcho("You didn't provide a phone code!");
            }
        } elseif ($this->mp->authorized === MTProto::WAITING_PASSWORD) {
            if (isset($_POST['password'])) {
                yield from $this->webComplete2faLogin();
            } else {
                yield from $this->webEcho("You didn't provide the password!");
            }
        } elseif ($this->mp->authorized === MTProto::WAITING_SIGNUP) {
            if (isset($_POST['first_name'])) {
                yield from $this->webCompleteSignup();
            } else {
                yield from $this->webEcho("You didn't provide the first name!");
            }
        }
        if ($this->mp->authorized === MTProto::LOGGED_IN) {
            $this->mp->serialize();
            return yield from $this->mp->fullGetSelf();
        }
        exit;
    }

    private function webPhoneLogin(): \Generator
    {
        try {
            yield from $this->mp->phoneLogin($_POST['phone_number']);
            yield from $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webCompletePhoneLogin(): \Generator
    {
        try {
            yield from $this->mp->completePhoneLogin($_POST['phone_code']);
            yield from $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webComplete2faLogin(): \Generator
    {
        try {
            yield from $this->mp->complete2faLogin($_POST['password']);
            yield from $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webCompleteSignup(): \Generator
    {
        try {
            yield from $this->mp->completeSignup($_POST['first_name'], isset($_POST['last_name']) ? $_POST['last_name'] : '');
            yield from $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webBotLogin(): \Generator
    {
        try {
            yield from $this->mp->botLogin($_POST['token']);
            yield from $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield from $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    /**
     * Echo page to console.
     *
     * @param string $message Error message
     *
     * @return \Generator
     */
    private function webEcho(string $message = ''): \Generator
    {
        $stdout = getOutputBufferStream();
        switch ($this->mp->authorized) {
            case MTProto::NOT_LOGGED_IN:
                if (isset($_POST['type'])) {
                    if ($_POST['type'] === 'phone') {
                        yield $stdout->write($this->webEchoTemplate('Enter your phone number<br><b>' . $message . '</b>', '<input type="text" name="phone_number" placeholder="Phone number" required/>'));
                    } else {
                        yield $stdout->write($this->webEchoTemplate('Enter your bot token<br><b>' . $message . '</b>', '<input type="text" name="token" placeholder="Bot token" required/>'));
                    }
                } else {
                    yield $stdout->write($this->webEchoTemplate('Do you want to login as user or bot?<br><b>' . $message . '</b>', '<select name="type"><option value="phone">User</option><option value="bot">Bot</option></select>'));
                }
                break;
            case MTProto::WAITING_CODE:
                yield $stdout->write($this->webEchoTemplate('Enter your code<br><b>' . $message . '</b>', '<input type="text" name="phone_code" placeholder="Phone code" required/>'));
                break;
            case MTProto::WAITING_PASSWORD:
                yield $stdout->write($this->webEchoTemplate('Enter your password<br><b>' . $message . '</b>', '<input type="password" name="password" placeholder="Hint: ' . $this->mp->authorization['hint'] . '" required/>'));
                break;
            case MTProto::WAITING_SIGNUP:
                yield $stdout->write($this->webEchoTemplate('Sign up please<br><b>' . $message . '</b>', '<input type="text" name="first_name" placeholder="First name" required/><input type="text" name="last_name" placeholder="Last name"/>'));
                break;
        }
    }

    /**
     * Format message according to template.
     *
     * @param string $message Message
     * @param string $form    Form contents
     *
     * @return string
     */
    private function webEchoTemplate(string $message, string $form): string
    {
        return $web_template = "" .
            "<!DOCTYPE html>" .
            "<html>" .
            "<head>" .
            "    <title>MadelineProto</title>" .
            "</head>" .
            "<body>" .
            "    <h1>MadelineProto</h1>" .
            "    <form method'POST'>$form<button type='submit'>Go</button></form>" .
            "    <p>$message</p>" .
            "</body>" .
            "</html>";
    }
}
