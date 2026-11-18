<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors','0');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    http_response_code(405);header('Allow: POST');echo '{"ok":false}';exit;
}
try {
    $root=realpath($_SERVER['DOCUMENT_ROOT']??'');
    if ($root===false) throw new RuntimeException('Merchant root is unavailable.');
    $private=dirname($root,2).'/admin/redbeards-client-bot';
    $settings=json_decode(file_get_contents($private.'/settings.json'),true,32,JSON_THROW_ON_ERROR);
    $provided=$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'';
    $secret=$settings['webhookSecret']??'';
    if (!is_string($secret) || strlen($secret)<32 || !is_string($provided) || !hash_equals($secret,$provided)) {
        http_response_code(403);echo '{"ok":false}';exit;
    }
    $body=file_get_contents('php://input',false,null,0,262145);
    if (!is_string($body) || strlen($body)>262144) { http_response_code(413);echo '{"ok":false}';exit; }
    $services=require $private.'/bootstrap.php';
    $services['controller']->receive($body,$provided);
    echo '{"ok":true}';
} catch (Throwable $e) {
    error_log('redbeards_widget_webhook '.get_class($e));
    http_response_code(503);echo '{"ok":false}';
}
