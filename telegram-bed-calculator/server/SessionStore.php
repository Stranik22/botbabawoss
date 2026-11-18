<?php
declare(strict_types=1);
namespace Redbeards\TelegramWidget;

use PDO;
use RuntimeException;
use Throwable;

final class SessionStore {
    public function __construct(private PDO $db) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout=3000');
        $db->exec('CREATE TABLE IF NOT EXISTS widget_sessions (
            chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, message_id INTEGER NOT NULL,
            row_json TEXT NOT NULL, updated_at INTEGER NOT NULL, PRIMARY KEY(chat_id,message_id))');
        $db->exec('CREATE TABLE IF NOT EXISTS widget_queries (
            query_id TEXT PRIMARY KEY, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            message_id INTEGER NOT NULL, action TEXT NOT NULL, received_at INTEGER NOT NULL)');
    }

    private function context(int $chat, int $user, int $message): void {
        if ($chat <= 0 || $user !== $chat || $message <= 0) throw new RuntimeException('Widget is not available.');
    }

    public function create(int $chat, int $user, int $message, array $row, WidgetEngine $engine): void {
        $this->context($chat,$user,$message);
        $engine->validateRow($row);
        $engine->snapshot($row);
        $q=$this->db->prepare('INSERT INTO widget_sessions(chat_id,user_id,message_id,row_json,updated_at) VALUES(?,?,?,?,?)');
        $q->execute([$chat,$user,$message,WidgetEngine::encode($row),time()]);
    }

    public function read(int $chat, int $user, int $message): array {
        $this->context($chat,$user,$message);
        $q=$this->db->prepare('SELECT row_json,user_id FROM widget_sessions WHERE chat_id=? AND message_id=?');
        $q->execute([$chat,$message]);$record=$q->fetch(PDO::FETCH_ASSOC);
        if (!$record || (int)$record['user_id']!==$user) throw new RuntimeException('Widget is not available.');
        return json_decode($record['row_json'],true,32,JSON_THROW_ON_ERROR);
    }

    /**
     * Owns no order/payment tables. Price rendering succeeds before a state update
     * commits. Absolute actions plus query deduplication prevent repeated changes.
     */
    public function handle(int $chat, int $user, int $message, string $queryId, string $data, WidgetEngine $engine): array {
        $this->context($chat,$user,$message);
        if ($queryId==='' || strlen($queryId)>256 || str_contains($queryId,"\0")) throw new RuntimeException('Invalid request identifier.');
        [$expected,$action]=$engine->parseRequest($data);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row=$this->read($chat,$user,$message);
            $known=$this->db->prepare('SELECT chat_id,user_id,message_id,action FROM widget_queries WHERE query_id=?');
            $known->execute([$queryId]);$old=$known->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ((int)$old['chat_id']!==$chat || (int)$old['user_id']!==$user ||
                    (int)$old['message_id']!==$message || $old['action']!==$data) throw new RuntimeException('Widget is not available.');
                $snapshot=$engine->snapshot($row);
                $this->db->exec('COMMIT');
                return ['row'=>$row,'snapshot'=>$snapshot,'photo'=>false,'duplicate'=>true];
            }
            $photo=false;
            if ($row['r']!==$expected) {
                $snapshot=$engine->snapshot($row,'Выбор обновлён. Повторите нажатие.');
            } else {
                $row=$engine->apply($row,$action);
                $snapshot=$engine->snapshot($row);
                $photo=$action==='photo';
                $save=$this->db->prepare('UPDATE widget_sessions SET row_json=?,updated_at=? WHERE chat_id=? AND message_id=? AND user_id=?');
                $save->execute([WidgetEngine::encode($row),time(),$chat,$message,$user]);
            }
            $q=$this->db->prepare('INSERT INTO widget_queries(query_id,chat_id,user_id,message_id,action,received_at) VALUES(?,?,?,?,?,?)');
            $q->execute([$queryId,$chat,$user,$message,$data,time()]);
            $this->db->exec('COMMIT');
            return ['row'=>$row,'snapshot'=>$snapshot,'photo'=>$photo,'duplicate'=>false];
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
}
