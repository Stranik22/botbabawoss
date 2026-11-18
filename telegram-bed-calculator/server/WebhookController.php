<?php
declare(strict_types=1);
namespace Redbeards\TelegramWidget;

use Closure;
use RuntimeException;
use Throwable;

final class WebhookRejected extends RuntimeException {}

final class WebhookController {
    private Closure $photo;
    public function __construct(
        private WidgetEngine $engine, private SessionStore $sessions, private BotApi $api,
        private string $html, private string $secret, callable $photoResolver
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$secret)) throw new RuntimeException('Invalid webhook secret.');
        if (strlen($html)>32768 || strlen(gzcompress($html,9))>4096) throw new RuntimeException('Widget exceeds Telegram limits.');
        $this->photo=Closure::fromCallable($photoResolver);
    }

    private function context(mixed $from,mixed $chat): ?array {
        if (!is_array($from) || !is_array($chat)) return null;
        if (($chat['type']??null)!=='private' || !is_int($chat['id']??null) || !is_int($from['id']??null) ||
            $chat['id']<=0 || $from['id']!==$chat['id'] || !empty($from['is_bot'])) return null;
        return [$chat['id'],$from['id']];
    }
    private function answer(string $queryId,string $data): bool {
        try { $this->api->call('answerWidgetQuery',['widget_query_id'=>$queryId,'data'=>$data]);return true; }
        catch (TelegramApiFailure $e) {
            // Expired/already-answered query IDs must not cause endless webhook
            // redelivery. Durable state still reaches the message below.
            if ($e->apiCode===400) return false;
            throw $e;
        }
    }
    private function keyboard(string $url): array {
        return ['inline_keyboard'=>[[['text'=>'Открыть конструктор','url'=>$url]]]];
    }
    private function welcome(int $chat,int $user,array $row): void {
        $s=$this->engine->snapshot($row);
        try { $this->sendPhoto($chat,$row,null); } catch (Throwable $e) {}
        try {
            $m=$this->api->call('sendWidget',[
                'chat_id'=>$chat,'html'=>$this->html,'chat_data'=>WidgetEngine::encode($s),
                'allow_queries'=>true,'caption'=>'Кровать под вас. Изготовление — 7 рабочих дней.',
                'reply_markup'=>$this->keyboard($s['u']),
            ]);
            if (!is_array($m) || !is_int($m['message_id']??null) || $m['message_id']<=0) throw new RuntimeException('Widget message was not acknowledged.');
            $this->sessions->create($chat,$user,$m['message_id'],$row,$this->engine);
        } catch (Throwable $e) {
            $this->api->call('sendMessage',[
                'chat_id'=>$chat,'text'=>'Соберите кровать и оформите заказ в конструкторе.',
                'reply_markup'=>$this->keyboard($s['u']),
            ]);
        }
    }
    private function sendPhoto(int $chat,array $row,?int $widget): void {
        $url=($this->photo)($row['c']);$u=is_string($url)?parse_url($url):false;
        if (!$u || ($u['scheme']??'')!=='https' || ($u['host']??'')!=='redbeards.ru' ||
            isset($u['user']) || isset($u['pass']) || isset($u['port'])) throw new RuntimeException('Invalid merchant photo URL.');
        $s=$this->engine->snapshot($row);$c=$row['c'];
        $caption=$s['d']."\n".$s['f'].' · '.($c['lift']?'Подъёмное основание':'Деревянное основание');
        $caption.="\nМатрас и декор не входят в указанную стоимость.";
        if ($c['legs'] || $c['thick']) {
            $notShown=[];if ($c['legs']) $notShown[]='Ножки';if ($c['thick']) $notShown[]='ППУ 40 мм';
            $caption.="\n".implode(' и ',$notShown).' на фото не показаны.';
        }
        $params=['chat_id'=>$chat,'photo'=>$url,'caption'=>$caption];
        if ($widget!==null) $params['reply_markup']=['inline_keyboard'=>[[['text'=>'Продолжить настройку','callback_data'=>'resume:'.$widget]]]];
        $this->api->call('sendPhoto',$params);
    }

    /** Verify the Telegram secret before decoding or processing any user content. */
    public function receive(string $body,string $headerSecret): void {
        if (!hash_equals($this->secret,$headerSecret)) throw new WebhookRejected('Webhook is not authorized.');
        if (strlen($body)>262144) throw new WebhookRejected('Update is too large.');
        $u=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($u) || !is_int($u['update_id']??null)) throw new WebhookRejected('Invalid update.');
        if (is_array($u['widget_query']??null)) {
            $q=$u['widget_query'];$ctx=$this->context($q['from']??[],$q['chat']??[]);
            if ($ctx===null || !is_int($q['message_id']??null) || !is_string($q['id']??null)) return;
            [$chat,$user]=$ctx;
            try {
                if (($q['type']['type']??null)!=='data' || !is_string($q['data']??null)) throw new RuntimeException('Unsupported widget query.');
                $r=$this->sessions->handle($chat,$user,$q['message_id'],$q['id'],$q['data'],$this->engine);
            } catch (Throwable $e) {
                $this->answer($q['id'],'{"error":"restart"}');
                return;
            }
            $wire=WidgetEngine::encode($r['snapshot']);
            // Answer first: photo downloads and message edits must not consume the
            // widget's 15-second reply deadline.
            $answered=$this->answer($q['id'],$wire);
            try { $this->api->call('editMessageWidgetData',['chat_id'=>$chat,'message_id'=>$q['message_id'],'chat_data'=>$wire]); } catch (Throwable $e) {}
            try { $this->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$q['message_id'],'reply_markup'=>$this->keyboard($r['snapshot']['u'])]); } catch (Throwable $e) {}
            if ($r['photo'] && $answered) {
                try { $this->sendPhoto($chat,$r['row'],$q['message_id']); }
                catch (Throwable $e) {
                    $this->api->call('sendMessage',['chat_id'=>$chat,'text'=>'Фото сейчас не загрузилось. Посмотреть выбранную кровать можно в конструкторе.','reply_markup'=>$this->keyboard($r['snapshot']['u'])]);
                }
            }
            return;
        }
        if (is_array($u['callback_query']??null)) {
            $q=$u['callback_query'];$ctx=$this->context($q['from']??[],$q['message']['chat']??[]);
            if ($ctx===null || !is_string($q['id']??null) || !preg_match('/^resume:([1-9][0-9]{0,9})$/D',(string)($q['data']??''),$m)) return;
            [$chat,$user]=$ctx;
            $lease=$this->sessions->claimMessage($u['update_id'],$chat,$user);if ($lease===null) return;
            try {
                try { $row=$this->sessions->read($chat,$user,(int)$m[1]); }
                catch (Throwable $e) { $row=null; }
                if ($row===null) $this->api->call('answerCallbackQuery',['callback_query_id'=>$q['id'],'text'=>'Отправьте /start для нового расчёта.']);
                else {
                    $this->api->call('answerCallbackQuery',['callback_query_id'=>$q['id']]);
                    $row['r']=1;$this->welcome($chat,$user,$row);
                }
                $this->sessions->completeMessage($u['update_id'],$lease);
            } catch (Throwable $e) { $this->sessions->releaseMessage($u['update_id'],$lease);throw $e; }
            return;
        }
        if (!is_array($u['message']??null)) return;
        $m=$u['message'];$ctx=$this->context($m['from']??[],$m['chat']??[]);if ($ctx===null) return;
        [$chat,$user]=$ctx;$lease=$this->sessions->claimMessage($u['update_id'],$chat,$user);if ($lease===null) return;
        try {
            $text=$m['text']??'';
            if (is_string($text) && preg_match('/^\/(?:start|catalog)(?:@redbeards_robot)?(?:\s|$)/i',$text)) $this->welcome($chat,$user,$this->engine->initial());
            else $this->api->call('sendMessage',[
                'chat_id'=>$chat,'text'=>'Отправьте /start, чтобы выбрать кровать. Контакты, доставку и оплату можно заполнить при оформлении заказа.',
                'reply_markup'=>$this->keyboard('https://redbeards.ru/hotel-beds/calculator/'),
            ]);
            $this->sessions->completeMessage($u['update_id'],$lease);
        } catch (Throwable $e) { $this->sessions->releaseMessage($u['update_id'],$lease);throw $e; }
    }
}
