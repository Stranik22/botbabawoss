<?php
declare(strict_types=1);

/** Extract method source without loading the shop or its configuration. */
function rbInspectMethods(string $source): array {
    $tokens = token_get_all($source);
    $offsets = []; $offset = 0;
    foreach ($tokens as $i => $token) {
        $offsets[$i] = $offset;
        $offset += strlen(is_array($token) ? $token[1] : $token);
    }
    $methods = []; $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $name = null; $nameAt = $i;
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j] === '(') break;
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1]; $nameAt = $j; break;
            }
        }
        if ($name === null) continue;
        $open = null;
        for ($j = $nameAt + 1; $j < $count; $j++) {
            if ($tokens[$j] === ';') break;
            if ($tokens[$j] === '{') { $open = $j; break; }
        }
        if ($open === null) continue;
        $depth = 0; $end = null;
        for ($j = $open; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
            if ($t === '}' && --$depth === 0) { $end = $j; break; }
        }
        if ($end === null) continue;
        $stop = $offsets[$end] + 1;
        $code = substr($source, $offsets[$i], $stop - $offsets[$i]);
        $methods[$name] = [
            'line' => $tokens[$i][2],
            'bytes' => strlen($code),
            'source' => preg_match('/__construct|price|spec|quote|catalog|normal|validateConfig/i', $name) ? substr($code, 0, 8000) : null,
            'truncated' => preg_match('/__construct|price|spec|quote|catalog|normal|validateConfig/i', $name) && strlen($code) > 8000,
        ];
        $i = $end;
    }
    return $methods;
}

if (defined('RB_INSPECT_LIBRARY_ONLY') && RB_INSPECT_LIBRARY_ONLY === true) return;

/*
 * Replace this placeholder privately with SHA-256 of a fresh 64-hex setup key.
 * Never put a Telegram token or acquiring credential in this file.
 */
$expectedHash = 'REPLACE_WITH_SHA256_OF_THE_ONE_TIME_SETUP_KEY';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
if (!preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Установочный ключ не задан.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Проверка интеграции Telegram</title><h1>Проверка интеграции Telegram</h1><form method="post"><label>Одноразовый ключ <input name="setup_key" type="password" autocomplete="off" required></label><button>Проверить</button></form>';
    exit;
}
$key = $_POST['setup_key'] ?? '';
if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key) || !hash_equals($expectedHash, hash('sha256', $key))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Доступ закрыт.');
}
try {
    $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docroot === false) throw new RuntimeException('Не найден корень сайта.');
    $private = dirname($docroot, 2) . '/admin/redbeards-shop';
    $report = [
        'phpVersion' => PHP_VERSION,
        'extensions' => array_fill_keys(['curl', 'pdo_sqlite', 'gd'], false),
        'webpConversion' => function_exists('imagecreatefromwebp') && function_exists('imagejpeg'),
        'shopSourceFound' => is_readable($private . '/Shop.php'),
    ];
    foreach ($report['extensions'] as $ext => $_) $report['extensions'][$ext] = extension_loaded($ext);
    if ($report['shopSourceFound']) {
        $source = file_get_contents($private . '/Shop.php');
        $report['shopSourceSha256'] = hash('sha256', $source);
        $report['methods'] = rbInspectMethods($source);
    }
    $catalogPath = $docroot . '/quiz/catalog-data.json';
    if (is_readable($catalogPath)) {
        $catalog = json_decode(file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
        $report['catalogKeys'] = array_keys($catalog);
        $report['codes'] = $catalog['codes'] ?? null;
        $report['models'] = [];
        foreach ([3, 1, 7, 0] as $index) {
            $p = $catalog['products'][$index] ?? [];
            $report['models'][$index] = array_intersect_key($p, array_fill_keys(['id','name','size','price','extra','slug'], true));
        }
    }
    $apiPath = $docroot . '/quiz/shop-api.php';
    if (is_readable($apiPath)) $report['apiSource'] = substr(file_get_contents($apiPath), 0, 12000);
    $indexPath=$docroot.'/hotel-beds/calculator/index.html';
    if (is_readable($indexPath)) {
        $index=file_get_contents($indexPath);
        if (preg_match('~<script[^>]*\bsrc=["\'](/hotel-beds/calculator/assets/[^"\']+\.js)["\']~',$index,$match)) {
            $asset=realpath($docroot.$match[1]);
            $prefix=realpath($docroot.'/hotel-beds/calculator/assets');
            if ($asset!==false && $prefix!==false && str_starts_with($asset,$prefix.DIRECTORY_SEPARATOR) && is_readable($asset)) {
                $js=file_get_contents($asset);
                preg_match_all('/rb-config-v[0-9]+/',$js,$keys);
                $report['storefront']=['asset'=>basename($asset),'sha256'=>hash('sha256',$js),'configurationKeys'=>array_values(array_unique($keys[0]))];
                $at=strpos($js,'Молочный');
                if ($at!==false) $report['storefront']['fabricSourceContext']=substr($js,max(0,$at-700),14000);
            }
        }
    }
    $report['probeRemoved'] = unlink(__FILE__);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Проверка не завершена.', 'type' => get_class($e)], JSON_UNESCAPED_UNICODE);
}
