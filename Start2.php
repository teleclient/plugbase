<?php

/**
 * ApiStart module.
 */

//namespace danog\MadelineProto\ApiWrappers;

use danog\MadelineProto\MyTelegramOrgWrapper;
use danog\MadelineProto\Tools;
use function Amp\ByteStream\getStdout;
use function Amp\ByteStream\getOutputBufferStream;

/**
 * Manages simple logging in and out.
 */
class Start
{
    public function APIStart(array $settings): \Generator
    {
        if (PHP_SAPI === 'cli') {
            $stdout = getStdout();
            yield $stdout->write('You did not define a valid API ID/API hash. Do you want to define it now manually, or automatically? (m/a)' . PHP_EOL);
            $ma = yield Tools::readLine('Your choice (m/a): ');
            if (\strpos($ma, 'm') !== false) {
                yield $stdout->write(
                    '' . PHP_EOL .
                        '1) Login to my.telegram.org' . PHP_EOL .
                        '2) Go to API development tools' . PHP_EOL .
                        '3) App title: your app\'s name, can be anything' . PHP_EOL .
                        '    Short name: your app\'s short name, can be anything' . PHP_EOL .
                        '    URL: your app/website\'s URL, or t.me/yourusername' . PHP_EOL .
                        '    Platform: anything ' . PHP_EOL .
                        '    Description: Describe your app here' . PHP_EOL .
                        '4) Click on create application' . PHP_EOL
                );
                $app['api_id']   = yield Tools::readLine('5) Enter your API ID: ');
                $app['api_hash'] = yield Tools::readLine('6) Enter your API hash: ');
                return $app;
            }
            $this->myTelegramOrgWrapper = new \danog\MadelineProto\MyTelegramOrgWrapper($settings);
            yield from $this->myTelegramOrgWrapper->login(yield Tools::readLine('Enter a phone number that is already registered on Telegram: '));
            yield from $this->myTelegramOrgWrapper->completeLogin(yield Tools::readLine('Enter the verification code you received in telegram: '));
            if (!(yield from $this->myTelegramOrgWrapper->hasApp())) {
                $app_title   = yield Tools::readLine('Enter the app\'s name, can be anything: ');
                $short_name  = yield Tools::readLine('Enter the app\'s short name, can be anything: ');
                $url         = yield Tools::readLine('Enter the app/website\'s URL, or t.me/yourusername: ');
                $description = yield Tools::readLine('Describe your app: ');
                $app = (yield from $this->myTelegramOrgWrapper->createApp([
                    'app_title'     => $app_title,
                    'app_shortname' => $short_name,
                    'app_url'       => $url,
                    'app_platform'  => 'web',
                    'app_desc'      => $description
                ]));
            } else {
                $app = (yield from $this->myTelegramOrgWrapper->getApp());
            }
            return $app;
        }
        $this->gettingApiId = true;
        if (!isset($this->myTelegramOrgWrapper)) {
            if (isset($_POST['api_id']) && isset($_POST['api_hash'])) {
                $app['api_id']   = (int) $_POST['api_id'];
                $app['api_hash'] = $_POST['api_hash'];
                $this->gettingApiId = false;
                return $app;
            } elseif (isset($_POST['phone_number'])) {
                yield from $this->webAPIPhoneLogin($settings);
            } else {
                yield from $this->webAPIEcho();
            }
        } elseif (!$this->myTelegramOrgWrapper->loggedIn()) {
            if (isset($_POST['code'])) {
                yield from $this->webAPICompleteLogin();
                if (yield from $this->myTelegramOrgWrapper->hasApp()) {
                    return yield from $this->myTelegramOrgWrapper->getApp();
                }
                yield from $this->webAPIEcho();
            } elseif (isset($_POST['api_id']) && isset($_POST['api_hash'])) {
                $app['api_id'] = (int) $_POST['api_id'];
                $app['api_hash'] = $_POST['api_hash'];
                $this->gettingApiId = false;
                return $app;
            } elseif (isset($_POST['phone_number'])) {
                yield from $this->webAPIPhoneLogin($settings);
            } else {
                $this->myTelegramOrgWrapper = null;
                yield from $this->webAPIEcho();
            }
        } else {
            if (isset($_POST['app_title'], $_POST['app_shortname'], $_POST['app_url'], $_POST['app_platform'], $_POST['app_desc'])) {
                $app = (yield from $this->webAPICreateApp());
                $this->gettingApiId = false;
                return $app;
            }
            yield from $this->webAPIEcho("You didn't provide all of the required parameters!");
        }
        return null;
    }

    private function webAPIPhoneLogin(array $settings): \Generator
    {
        try {
            $this->myTelegramOrgWrapper = new MyTelegramOrgWrapper($settings);
            yield from $this->myTelegramOrgWrapper->login($_POST['phone_number']);
            yield from $this->webAPIEcho();
        } catch (\Throwable $e) {
            yield from $this->webAPIEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webAPICompleteLogin(): \Generator
    {
        try {
            yield from $this->myTelegramOrgWrapper->completeLogin($_POST['code']);
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield from $this->webAPIEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield from $this->webAPIEcho('ERROR: ' . $e->getMessage() . '. Try again.');
        }
    }

    private function webAPICreateApp(): \Generator
    {
        try {
            $params = $_POST;
            unset($params['creating_app']);
            $app = (yield from $this->myTelegramOrgWrapper->createApp($params));
            return $app;
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield from $this->webAPIEcho('ERROR: ' . $e->getMessage() . ' Try again.');
        } catch (\danog\MadelineProto\Exception $e) {
            yield from $this->webAPIEcho('ERROR: ' . $e->getMessage() . ' Try again.');
        }
    }

    /**
     * API template.
     *
     * @var string
     */
    private $webApiTemplate = '<!DOCTYPE html><html><head><title>MadelineProto</title></head><body><h1>MadelineProto</h1><p>%s</p><form method="POST">%s<button type="submit"/>Go</button></form></body></html>';

    /**
     * Generate page from template.
     *
     * @param string $message Message
     * @param string $form    Form
     *
     * @return string
     */
    private function webAPIEchoTemplate(string $message, string $form): string
    {
        return \sprintf($this->webApiTemplate, $message, $form);
    }

    /**
     * Get web API login HTML template string.
     *
     * @return string
     */
    public function getWebAPITemplate(): string
    {
        return $this->webApiTemplate;
    }

    /**
     * Set web API login HTML template string.
     *
     * @return string
     */
    public function setWebAPITemplate(string $template): void
    {
        $this->webApiTemplate = $template;
    }

    /**
     * Echo to browser.
     *
     * @param string $message Message to echo
     *
     * @return \Generator
     */
    private function webAPIEcho(string $message = ''): \Generator
    {
        $stdout = getOutputBufferStream();
        if (!isset($this->myTelegramOrgWrapper)) {
            if (isset($_POST['type'])) {
                if ($_POST['type'] === 'manual') {
                    yield $stdout->write($this->webAPIEchoTemplate('Enter your API ID and API hash<br><b>' . $message . '</b><ol>
<li>Login to my.telegram.org</li>
<li>Go to API development tools</li>
<li>
  <ul>
    <li>App title: your app&apos;s name, can be anything</li>
    <li>Short name: your app&apos;s short name, only numbers and letters</li>
    <li>Platform: Web</li>
    <li>Description: describe your app here</li>
  </ul>
</li>
<li>Click on create application</li>
</ol>', '<input type="string" name="api_id" placeholder="API ID" required/><input type="string" name="api_hash" placeholder="API hash" required/>'));
                } else {
                    yield $stdout->write($this->webAPIEchoTemplate('Enter a phone number that is <b>already registered</b> on telegram to get the API ID<br><b>' . $message . '</b>', '<input type="text" name="phone_number" placeholder="Phone number" required/>'));
                }
            } else {
                if ($message) {
                    $message = '<br><br>' . $message;
                }
                yield $stdout->write($this->webAPIEchoTemplate('Do you want to enter the API id and the API hash manually or automatically?<br>Note that you can also provide it directly in the code using the <a href="https://docs.madelineproto.xyz/docs/SETTINGS.html#settingsapp_infoapi_id">settings</a>.<b>' . $message . '</b>', '<select name="type"><option value="automatic">Automatically</option><option value="manual">Manually</option></select>'));
            }
        } else {
            if (!$this->myTelegramOrgWrapper->loggedIn()) {
                yield $stdout->write($this->webAPIEchoTemplate('Enter your code<br><b>' . $message . '</b>', '<input type="text" name="code" placeholder="Code" required/>'));
            } else {
                yield $stdout->write($this->webAPIEchoTemplate('Enter the API info<br><b>' . $message . '</b>', '<input type="hidden" name="creating_app" value="yes" required/>
                    Enter the app name, can be anything: <br><input type="text" name="app_title" required/><br>
                    <br>Enter the app&apos;s short name, alphanumeric, 5-32 chars: <br><input type="text" name="app_shortname" required/><br>
                    <br>Enter the app/website URL, or https://t.me/yourusername: <br><input type="text" name="app_url" required/><br>
                    <br>Enter the app platform: <br>
          <label>
            <input type="radio" name="app_platform" value="android" checked> Android
          </label>
          <label>
            <input type="radio" name="app_platform" value="ios"> iOS
          </label>
          <label>
            <input type="radio" name="app_platform" value="wp"> Windows Phone
          </label>
          <label>
            <input type="radio" name="app_platform" value="bb"> BlackBerry
          </label>
          <label>
            <input type="radio" name="app_platform" value="desktop"> Desktop
          </label>
          <label>
            <input type="radio" name="app_platform" value="web"> Web
          </label>
          <label>
            <input type="radio" name="app_platform" value="ubp"> Ubuntu phone
          </label>
          <label>
            <input type="radio" name="app_platform" value="other"> Other (specify in description)
          </label>
          <br><br>Enter the app description, can be anything: <br><textarea name="app_desc" required></textarea><br><br>
                    '));
            }
        }
    }
}
