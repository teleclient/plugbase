<?php

declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Tools;
use danog\MadelineProto\Logger;
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
        $authState = authorizationState($this->mp);
        $stateStr  = authorizationStateDesc($authState);
        Logger::log("The Start::start method is invoked with '$stateStr'", Logger::ERROR);
        if ($authState === MTProto::LOGGED_IN) {
            Logger::log("Nothing to be done, returned fullGetSelf.", Logger::ERROR);
            return yield /*from*/ $this->mp->fullGetSelf();
        }
        if (PHP_SAPI === 'cli') {
            //if (\strpos(yield Tools::readLine('Do you want to login as user or bot (u/b)? '), 'b') !== false) {
            if ($this->botLogin) {
                if (!$this->botToken) {
                    $this->botToken = yield Tools::readLine('Enter your bot token: ');
                }
                Logger::log("bot token is: " . $this->botToken, Logger::ERROR);
                $authorization = yield /*from*/ $this->mp->botLogin($this->botToken);
                Logger::log("botLogin result" . toJSON($authorization), Logger::ERROR);
            } else {
                $phoneOrig = $this->phone;
                $retries = 0;
                while (true) {
                    try {
                        if (!$phoneOrig) {
                            $this->phone = yield Tools::readLine('Enter your phone number: ');
                        }
                        Logger::log("The phone number to use is: " . $this->phone, Logger::ERROR);
                        $sentCode = yield /*from*/ $this->mp->phoneLogin($this->phone);
                        Logger::log("phoneLogin result: " . toJSON($sentCode), Logger::ERROR);
                        break;
                    } catch (Throwable $e) {
                        Logger::log("Start: Exception due to phoneLogin", Logger::ERROR);
                        Logger::log((string)$e, Logger::ERROR);
                        Logger::log($e->getMessage(), Logger::ERROR);
                        if ($phoneOrig) {
                            throw $e;
                        }
                        if (strpos($e->getMessage(), 'FLOOD_WAIT_') != false) {
                            // Telegram returned an RPC error: FLOOD_WAIT_X (420) (FLOOD_WAIT_64495),
                            throw $e;
                        }
                        if ($retries++ >= 5) {
                            throw new ErrorException("Retries count to obtain a valid phone number exceeded!");
                        }
                    }
                }

                while (true) {
                    try {
                        $phoneCode = yield Tools::readLine('Enter the phone code: ');
                        Logger::log("phone code is: " . $phoneCode, Logger::ERROR);
                        $authorization = yield /*from*/ $this->mp->completePhoneLogin($phoneCode);
                        Logger::log("completePhoneLogin result" . toJSON($authorization), Logger::ERROR);
                        break;
                    } catch (Throwable $e) {
                        Logger::log("Start: Exception due to phoneCode", Logger::ERROR);
                        Logger::log((string)$e, Logger::ERROR);
                    }
                }

                if ($authorization['_'] === 'account.password') {
                    if (!$this->password) {
                        $this->password = yield Tools::readLine('Please enter your password (hint: ' . $authorization['hint'] . '): ');
                    }
                    Logger::log("2fa password is: " . $this->password, Logger::ERROR);
                    $authorization = yield /*from*/ $this->mp->complete2faLogin($this->password);
                    Logger::log("complete2faLogin result" . toJSON($authorization), Logger::ERROR);
                }
                if ($authorization['_'] === 'account.needSignup') {
                    Logger::log("phone needs app signup", Logger::ERROR);
                    $firstName = yield Tools::readLine('Please enter your first name: ');
                    $lastName  = yield Tools::readLine('Please enter your last name (can be empty): ');
                    $authorization = (yield /*from*/ $this->mp->completeSignup($firstName, $lastName));
                }
            }
            $this->mp->serialize();
            return yield /*from*/ $this->mp->fullGetSelf();
        }
        if ($authState === MTProto::NOT_LOGGED_IN) {
            if (isset($_POST['phone_number'])) {
                yield /*from*/ $this->webPhoneLogin();
            } elseif (isset($_POST['token'])) {
                yield /*from*/ $this->webBotLogin();
            } else {
                yield /*from*/ $this->webEcho();
            }
        } elseif ($authState === MTProto::WAITING_CODE) {
            if (isset($_POST['phone_code'])) {
                yield /*from*/ $this->webCompletePhoneLogin();
            } else {
                yield /*from*/ $this->webEcho("You didn't provide a phone code!");
            }
        } elseif ($authState === MTProto::WAITING_PASSWORD) {
            if (isset($_POST['password'])) {
                yield /*from*/ $this->webComplete2faLogin();
            } else {
                yield /*from*/ $this->webEcho("You didn't provide the password!");
            }
        } elseif ($authState === MTProto::WAITING_SIGNUP) {
            if (isset($_POST['first_name'])) {
                yield /*from*/ $this->webCompleteSignup();
            } else {
                yield /*from*/ $this->webEcho("You didn't provide the first name!");
            }
        }
        if ($authState === MTProto::LOGGED_IN) {
            $this->mp->serialize();
            return yield /*from*/ $this->mp->fullGetSelf();
        }
        exit;
    }

    private function webPhoneLogin(): \Generator
    {
        try {
            yield /*from*/ $this->mp->phoneLogin($_POST['phone_number']);
            yield /*from*/ $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webCompletePhoneLogin(): \Generator
    {
        try {
            yield /*from*/ $this->mp->completePhoneLogin($_POST['phone_code']);
            yield /*from*/ $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webComplete2faLogin(): \Generator
    {
        try {
            yield /*from*/ $this->mp->complete2faLogin($_POST['password']);
            yield /*from*/ $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webCompleteSignup(): \Generator
    {
        try {
            yield /*from*/ $this->mp->completeSignup($_POST['first_name'], isset($_POST['last_name']) ? $_POST['last_name'] : '');
            yield /*from*/ $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webBotLogin(): \Generator
    {
        try {
            yield /*from*/ $this->mp->botLogin($_POST['token']);
            yield /*from*/ $this->webEcho();
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield /*from*/ $this->webEcho('ERROR: ' . $e->getMessage() . '. Try again.');
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
