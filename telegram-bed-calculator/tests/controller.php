<?php
declare(strict_types=1);
require __DIR__.'/../server/WidgetEngine.php';
require __DIR__.'/../server/SessionStore.php';
require __DIR__.'/../server/TelegramApi.php';
require __DIR__.'/../server/WebhookController.php';
use Redbeards\TelegramWidget\{WidgetEngine,SessionStore,BotApi,TelegramApi,TelegramApiFailure,WebhookController};
function check(bool $c,string $m): void { if (!$c) throw new RuntimeException($m); }
function denied(callable $fn,string $m): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($m); }
final class FakeBot implements BotApi {
    public array $calls=[];public array $fail=[];private int $next=100;
    public function call(string $method,array $p): mixed {
        $this->calls[]=[$method,$p];
        if (isset($this->fail[$method])) { $e=$this->fail[$method];unset($this->fail[$method]);throw $e; }
        if (in_array($method,['sendWidget','sendPhoto','sendMessage'],true)) return ['message_id'=>++$this->next];
        return true;
    }
}
$models=[3=>['name'=>'Линии'],1=>['name'=>'Геометрия'],7=>['name'=>'Каретная стяжка'],0=>['name'=>'Линии с кантом']];
$fabrics=['Madera'=>['2'=>['name'=>'Молочный','hex'=>'#dddddd']],'Bavaro'=>['2'=>['name'=>'Молочный','hex'=>'#dddddd']]];
$engine=new WidgetEngine($models,$fabrics,fn($c)=>['amountRub'=>(17000+($c['legs']?500:0)+($c['thick']?700:0))*$c['quantity'],'requiresQuote'=>$c['mattress']]);
$db=new PDO('sqlite::memory:');$store=new SessionStore($db);$api=new FakeBot();
$secret=str_repeat('b',64);$html=file_get_contents(__DIR__.'/../widget.html');
$controller=new WebhookController($engine,$store,$api,$html,$secret,fn($c)=>'https://redbeards.ru/quiz/variants/line/madera-2-plank.webp');
$deliver=fn($u)=>$controller->receive(json_encode($u,JSON_THROW_ON_ERROR),$secret);
denied(fn()=>$controller->receive('{not json','wrong'),'Unauthorized body was processed.');
check($api->calls===[],'Unauthorized request reached bot API.');
$deliver(['update_id'=>0]);check($api->calls===[],'Health probe sent a message.');
$deliver(['update_id'=>1,'message'=>['from'=>['id'=>111,'is_bot'=>false],'chat'=>['id'=>-123,'type'=>'group'],'text'=>'/start']]);
check($api->calls===[],'Group received a private widget.');
$start=['update_id'=>2,'message'=>['from'=>['id'=>111,'is_bot'=>false],'chat'=>['id'=>111,'type'=>'private'],'text'=>'/start']];
$deliver($start);
check(array_column($api->calls,0)===['sendPhoto','sendWidget'],'Welcome sequence was not a real photo plus native widget.');
$sent=$api->calls[1][1];check($sent['allow_queries']===true && !isset($sent['public_data']),'Private widget was not configured correctly.');
$row=$store->read(111,111,102);check($row['r']===1,'Welcome state not bound to its message.');
$before=count($api->calls);$deliver($start);check(count($api->calls)===$before,'Webhook redelivery duplicated welcome.');
$api->calls=[];
$query=['update_id'=>3,'widget_query'=>['id'=>'q1','from'=>['id'=>111,'is_bot'=>false],'chat'=>['id'=>111,'type'=>'private'],'message_id'=>102,'type'=>['type'=>'data'],'data'=>'1:q2']];
$deliver($query);
check(array_column($api->calls,0)===['answerWidgetQuery','editMessageWidgetData','editMessageReplyMarkup'],'Widget reply was delayed behind edits or photos.');
$reply=json_decode($api->calls[0][1]['data'],true,32,JSON_THROW_ON_ERROR);
check($reply['p']==='34 000 ₽' && $reply['n']===2,'Selection did not return canonical order-wide price.');
check(!isset($api->calls[1][1]['reply_markup']),'Unsupported parameter sent to editMessageWidgetData.');
$foreign=$query;$foreign['update_id']=4;$foreign['widget_query']['from']['id']=222;$api->calls=[];$deliver($foreign);
check($api->calls===[],'Another customer received session data.');
$photo=$query;$photo['update_id']=5;$photo['widget_query']['id']='q2';$photo['widget_query']['data']='2:photo';
$api->calls=[];$deliver($photo);
check(array_column($api->calls,0)===['answerWidgetQuery','editMessageWidgetData','editMessageReplyMarkup','sendPhoto'],'Photograph consumed the reply deadline.');
check($api->calls[3][1]['reply_markup']['inline_keyboard'][0][0]['callback_data']==='resume:102','Photo did not retain the active configuration.');
$api->calls=[];$api->fail['answerWidgetQuery']=new TelegramApiFailure(400);$deliver($photo);
check(!in_array('sendPhoto',array_column($api->calls,0),true),'Expired repeated query duplicated a photograph.');
$orphan=$query;$orphan['update_id']=6;$orphan['widget_query']['id']='unknown';$orphan['widget_query']['message_id']=999;$api->calls=[];$deliver($orphan);
check(array_column($api->calls,0)===['answerWidgetQuery'] && json_decode($api->calls[0][1]['data'],true)['error']==='restart','Missing session did not fail safely.');
$resume=['update_id'=>7,'callback_query'=>['id'=>'cb1','from'=>['id'=>111,'is_bot'=>false],'message'=>['chat'=>['id'=>111,'type'=>'private']],'data'=>'resume:102']];
$api->calls=[];$deliver($resume);
check(array_column($api->calls,0)===['answerCallbackQuery','sendPhoto','sendWidget'],'Resume did not open a fresh widget.');
$widget=$db->query('SELECT message_id FROM widget_sessions ORDER BY message_id DESC LIMIT 1')->fetchColumn();
check($store->read(111,111,(int)$widget)['c']['quantity']===2,'Resume lost quantity.');
$before=count($api->calls);$deliver($resume);check(count($api->calls)===$before,'Repeated resume duplicated widgets.');
$api->calls=[];$deliver(['update_id'=>8,'message'=>['from'=>['id'=>111,'is_bot'=>false],'chat'=>['id'=>111,'type'=>'private'],'text'=>'Иванов Иван, адрес доставки...']]);
check(!str_contains(json_encode($api->calls,JSON_UNESCAPED_UNICODE),'Иванов'),'Customer free text was reflected in an outgoing message.');
denied(fn()=>new TelegramApi('8903844839:'.str_repeat('A',40)),'Production bot token accepted by retail adapter.');
$client=new TelegramApi('8932873076:'.str_repeat('A',40));denied(fn()=>$client->call('pay',[]),'Unsupported payment operation reached the bot transport.');
check(!str_contains(print_r($client,true),str_repeat('A',40)),'Credential leaked through debug output.');
$lease=$store->claimMessage(100,111,111);
denied(fn()=>$store->claimMessage(100,111,111),'Concurrent message lease was granted twice.');
$store->releaseMessage(100,$lease);$lease2=$store->claimMessage(100,111,111);check($lease2!==null,'Failed message could not retry.');
$store->completeMessage(100,$lease2);check($store->claimMessage(100,111,111)===null,'Completed update was replayed.');
echo "PASS: webhook secret, private audience, real widget dispatch, fast replies, canonical totals, stale/expired query delivery, photo/resume and incoming update leases.\n";
