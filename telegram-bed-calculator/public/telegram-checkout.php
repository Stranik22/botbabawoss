<?php
declare(strict_types=1);
header('Cache-Control: no-store');
ini_set('display_errors','0');
try {
    $packed=$_GET['c']??'';
    if (!is_string($packed) || strlen($packed)>128) throw new InvalidArgumentException('Invalid configuration.');
    $root=realpath($_SERVER['DOCUMENT_ROOT']??'');
    if ($root===false) throw new RuntimeException('Merchant root is unavailable.');
    $services=require dirname($root,2).'/admin/redbeards-client-bot/bootstrap.php';
    $config=$services['engine']->unpack($packed);
    $slugs=[3=>'line',1=>'geometry',7=>'carriage',0=>'piped-line'];
    $target='/hotel-beds/calculator/?'.http_build_query([
        'model'=>$slugs[$config['model']],'size'=>$config['size'],'base'=>$config['lift']?'lift':'plank',
    ],'','&',PHP_QUERY_RFC3986);
    $configJs=json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    $targetJs=json_encode($target,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Конструктор кровати</title><p>Комплектацию не удалось открыть.</p><a href="/hotel-beds/calculator/">Выбрать кровать в конструкторе</a>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ваша комплектация — Рыжая борода</title>
<style>body{margin:0;padding:32px;background:#f4f0e9;color:#0a0a0b;font:16px/1.5 system-ui}main{max-width:640px;margin:auto}a{display:inline-block;padding:14px 22px;background:#ff5a17;color:#101010;border-radius:24px;text-decoration:none;font-weight:700}dt{color:#706b64}dd{margin:0 0 12px}</style>
<main><h1>Ваша комплектация</h1><dl><dt>Размер и количество</dt><dd><?=htmlspecialchars($config['size'].'×200 · '.$config['quantity'].' шт.',ENT_QUOTES,'UTF-8')?></dd><dt>Ткань</dt><dd><?=htmlspecialchars($config['fabric'].' '.$config['color'],ENT_QUOTES,'UTF-8')?></dd></dl><p id="status">Открываем конструктор с выбранными параметрами.</p><a href="<?=htmlspecialchars($target,ENT_QUOTES,'UTF-8')?>">Продолжить оформление</a></main>
<script>
try{
 localStorage.setItem('rb-config-v2',JSON.stringify(<?=$configJs?>));
 location.replace(<?=$targetJs?>);
}catch(e){
 document.getElementById('status').textContent='Параметры не удалось перенести автоматически. Проверьте комплектацию в конструкторе перед оформлением.';
}
</script></html>
