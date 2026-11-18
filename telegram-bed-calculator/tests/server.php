<?php
declare(strict_types=1);
require __DIR__.'/../server/WidgetEngine.php';
require __DIR__.'/../server/SessionStore.php';
use Redbeards\TelegramWidget\WidgetEngine;
use Redbeards\TelegramWidget\SessionStore;

function ok(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $fn,string $message): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($message); }
$models=[3=>['name'=>'Линии'],1=>['name'=>'Геометрия'],7=>['name'=>'Каретная стяжка'],0=>['name'=>'Линии с кантом']];
$fabrics=[];
foreach (['Madera','Bavaro'] as $fabric) foreach (range(2,27) as $code) {
    $fabrics[$fabric][(string)$code]=['name'=>$code===26?'Чёрный':'Светло-серый','hex'=>'#a0a0a0'];
}
$calls=[];
$quote=function(array $c) use (&$calls): array {
    $calls[]=$c;
    // Deliberately distinctive fixture amounts: the engine must use the adapter,
    // not recreate live product prices. These values are never deployed as prices.
    return ['amountRub'=>(12345+($c['fabric']==='Bavaro'?333:0)+($c['legs']?789:0)+($c['thick']?456:0))*$c['quantity'],
        'requiresQuote'=>$c['mattress']];
};
$engine=new WidgetEngine($models,$fabrics,$quote);
$row=$engine->initial();
ok($engine->snapshot($row)['p']==='12 345 ₽','Canonical adapter amount was not used.');
foreach (['1:m2','1:q11','1:s123','1:price=1','0:n1','1:c999','1:n3','1:b2',str_repeat('a',65)] as $bad) rejects(fn()=>$engine->parseRequest($bad),'Invalid action was accepted.');
foreach (['m2','s160junk','q11','b1'] as $bad) rejects(fn()=>$engine->apply($row,$bad),'Invalid direct state mutation was accepted.');
$row=$engine->apply($row,'m7');$row=$engine->apply($row,'s180');$row=$engine->apply($row,'q2');
$row=$engine->apply($row,'n1');$row=$engine->apply($row,'fB');$row=$engine->apply($row,'c26');
ok($row['page']===3 && $row['c']['color']==='26','Colour selection page was not restored.');
rejects(fn()=>$engine->apply($row,'c99'),'Unknown fabric code accepted.');
$row=$engine->apply($row,'fM');ok($row['page']===0 && $row['c']['color']==='2','Fabric switch kept an incompatible code.');
$row=$engine->apply($row,'n2');$row=$engine->apply($row,'l1');$row=$engine->apply($row,'h1');$row=$engine->apply($row,'x1');
$s=$engine->snapshot($row);
ok($s['p']==='27 180 ₽' && $s['q']===1,'Quantity/options or mattress quote classification was lost.');
ok(str_contains($s['g'][1][1][0][0],'+1 578 ₽'),'Option label did not use the actual order-wide canonical price delta.');
ok($engine->unpack($engine->pack($row['c']))===$row['c'],'Checkout lost selected options.');
foreach (['3.160.M.999.0.0.0.1.0','3.160.M.2.0.0.0.11.0','3.160.M.2.0.0.0.1.0.extra','2.160.M.2.0.0.0.1.0'] as $bad) rejects(fn()=>$engine->unpack($bad),'Invalid checkout configuration was accepted.');
foreach (WidgetEngine::MODELS as $model) foreach (['Madera','Bavaro'] as $fabric) foreach (['2','26','27'] as $color) foreach ([1,10] as $qty) foreach ([0,1,2] as $step) {
    $x=$engine->initial();$x['s']=$step;$x['c']=array_replace($x['c'],['model'=>$model,'fabric'=>$fabric,'color'=>$color,'quantity'=>$qty,'legs'=>true,'thick'=>true,'mattress'=>true]);
    $x['page']=intdiv(array_search($color,array_map('strval',array_keys($fabrics[$fabric])),true),8);
    $snap=$engine->snapshot($x);
    ok(strlen(WidgetEngine::encode($snap))<=1024,'Snapshot exceeded native Telegram limit.');
    ok(strlen($snap['u'])<=256,'Checkout link exceeded native Telegram limit.');
}
$db=new PDO('sqlite::memory:');$store=new SessionStore($db);
$first=$engine->initial();$store->create(111,111,10,$first,$engine);
rejects(fn()=>$store->read(111,222,10),'Another customer read private state.');
rejects(fn()=>$store->handle(111,222,10,'q-no','1:q2',$engine),'Another customer mutated private state.');
rejects(fn()=>$store->handle(111,111,11,'q-other','1:q2',$engine),'Unknown widget message accepted.');
$result=$store->handle(111,111,10,'q1','1:q2',$engine);
ok($result['row']['r']===2 && $result['row']['c']['quantity']===2,'Valid action not persisted.');
$duplicate=$store->handle(111,111,10,'q1','1:q2',$engine);
ok($duplicate['duplicate'] && $duplicate['row']['r']===2,'Duplicate request applied twice.');
$stale=$store->handle(111,111,10,'q2','1:q3',$engine);
ok($stale['row']['r']===2 && $stale['row']['c']['quantity']===2 && isset($stale['snapshot']['e']),'Stale action overwrote newer state.');
$photo=$store->handle(111,111,10,'q3','2:photo',$engine);
ok($photo['photo'] && !$store->handle(111,111,10,'q3','2:photo',$engine)['photo'],'Repeated delivery duplicated photograph.');
rejects(fn()=>$store->handle(111,111,10,'q1','2:q5',$engine),'Query identifier reused for another action.');
$broken=new WidgetEngine($models,$fabrics,fn($c)=>throw new RuntimeException('Price adapter unavailable'));
rejects(fn()=>$store->handle(111,111,10,'q4','2:q3',$broken),'Broken price adapter was accepted.');
ok($store->read(111,111,10)['r']===2,'Price failure partially committed state.');
ok((int)$db->query('SELECT count(*) FROM widget_queries WHERE query_id="q4"')->fetchColumn()===0,'Failed transaction was marked complete.');
echo "PASS: canonical prices, quantity/option deltas, quotes, 144 payloads, checkout packing, ownership, stale/duplicate actions and rollback.\n";
