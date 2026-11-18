<?php
declare(strict_types=1);
namespace Redbeards\TelegramWidget;

use RuntimeException;
use Throwable;

interface BotApi { public function call(string $method, array $parameters): mixed; }

final class TelegramApiFailure extends RuntimeException {
    public function __construct(public readonly int $apiCode, public readonly int $retryAfter = 0) {
        parent::__construct('Telegram API request failed.');
    }
}

final class TelegramApi implements BotApi {
    public function __construct(private string $token, private string $expectedBotId = '8932873076') {
        if (!preg_match('/^([0-9]+):[A-Za-z0-9_-]{32,}$/D', $token, $m) || $m[1] !== $expectedBotId) {
            throw new RuntimeException('Retail bot credential does not match the configured bot.');
        }
    }
    public function __debugInfo(): array { return ['botId'=>$this->expectedBotId]; }
    public function call(string $method, array $parameters): mixed {
        if (!in_array($method, [
            'getMe','getWebhookInfo','setWebhook','deleteWebhook','getMyCommands','setMyCommands','getChatMenuButton','setChatMenuButton',
            'sendMessage','sendPhoto','sendWidget','answerWidgetQuery','editMessageWidgetData','editMessageReplyMarkup','answerCallbackQuery',
        ], true)) throw new RuntimeException('Unsupported bot operation.');
        if (!function_exists('curl_init')) throw new TelegramApiFailure(0);
        $body = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ch = curl_init('https://api.telegram.org/bot'.$this->token.'/'.$method);
        curl_setopt_array($ch, [
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>$body, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (!is_string($response)) throw new TelegramApiFailure(0);
        try { $data = json_decode($response, true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new TelegramApiFailure($http); }
        if ($http < 200 || $http >= 300 || !is_array($data) || ($data['ok'] ?? false) !== true) {
            throw new TelegramApiFailure((int)($data['error_code'] ?? $http), max(0,(int)($data['parameters']['retry_after'] ?? 0)));
        }
        return $data['result'] ?? true;
    }
}
