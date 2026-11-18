<?php
declare(strict_types=1);

namespace Redbeards\TelegramWidget;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pure widget state machine. $quote must call the existing shop's read-only
 * pricing code and return ['amountRub'=>int, 'requiresQuote'=>bool].
 * It must never create orders, invoices, payments or production notifications.
 */
final class WidgetEngine {
    public const MODELS = [3, 1, 7, 0];
    public const SIZES = [90, 120, 140, 160, 180, 200];
    private Closure $quote;
    private array $cache = [];

    public function __construct(private array $models, private array $fabrics, callable $quote) {
        $this->quote = Closure::fromCallable($quote);
        foreach (self::MODELS as $i) if (!isset($models[$i]['name'])) throw new InvalidArgumentException('Missing active model.');
        foreach (['Madera', 'Bavaro'] as $f) if (!isset($fabrics[$f]['2'])) throw new InvalidArgumentException('Missing standard fabric.');
    }

    public function initial(): array {
        return ['r'=>1, 's'=>0, 'page'=>0, 'c'=>[
            'model'=>3, 'size'=>160, 'fabric'=>'Madera', 'color'=>'2',
            'lift'=>false, 'legs'=>false, 'thick'=>false, 'quantity'=>1,
            'mattress'=>false, 'delivery'=>'pickup',
        ]];
    }

    public function validate(array $c): void {
        $keys = ['model','size','fabric','color','lift','legs','thick','quantity','mattress','delivery'];
        if (array_diff(array_keys($c), $keys) || count($c) !== count($keys)) throw new InvalidArgumentException('Invalid configuration fields.');
        if (!is_int($c['model']) || !in_array($c['model'], self::MODELS, true)) throw new InvalidArgumentException('Inactive model.');
        if (!is_int($c['size']) || !in_array($c['size'], self::SIZES, true)) throw new InvalidArgumentException('Invalid bed size.');
        if (!is_string($c['fabric']) || !in_array($c['fabric'], ['Madera','Bavaro'], true)) throw new InvalidArgumentException('Invalid fabric.');
        if (!is_string($c['color']) || !isset($this->fabrics[$c['fabric']][$c['color']])) throw new InvalidArgumentException('Invalid fabric code.');
        foreach (['lift','legs','thick','mattress'] as $k) if (!is_bool($c[$k])) throw new InvalidArgumentException('Invalid option.');
        if (!is_int($c['quantity']) || $c['quantity'] < 1 || $c['quantity'] > 10) throw new InvalidArgumentException('Invalid quantity.');
        if ($c['delivery'] !== 'pickup') throw new InvalidArgumentException('Delivery is selected at checkout.');
    }

    public function validateRow(array $row): void {
        if (!isset($row['r'],$row['s'],$row['page'],$row['c']) || !is_int($row['r']) || $row['r'] < 1 ||
            !is_int($row['s']) || $row['s'] < 0 || $row['s'] > 2 || !is_int($row['page']) || $row['page'] < 0 ||
            !is_array($row['c'])) throw new InvalidArgumentException('Invalid widget state.');
        $this->validate($row['c']);
        if ($row['page'] >= (int)ceil(count($this->fabrics[$row['c']['fabric']]) / 8)) throw new InvalidArgumentException('Invalid colour page.');
    }

    /** Returns [expectedRevision, absoluteAction]. No client price is accepted. */
    public function parseRequest(string $data): array {
        if (strlen($data) > 64 || !preg_match('/^([1-9][0-9]{0,9}):(n[0-2]|m[0137]|s(?:90|120|140|160|180|200)|q(?:10|[1-9])|f[MB]|c[1-9][0-9]?|p[0-9]|[blhx][01]|photo)$/D', $data, $m)) {
            throw new InvalidArgumentException('Invalid widget action.');
        }
        return [(int)$m[1], $m[2]];
    }

    public function apply(array $row, string $action): array {
        $this->validateRow($row);
        $this->parseRequest($row['r'] . ':' . $action);
        $r = $row; $v = substr($action, 1); $kind = substr($action, 0, 1);
        if ($action === 'photo') return $r;
        if (preg_match('/^n[0-2]$/D', $action)) $r['s'] = (int)$v;
        elseif ($r['s'] === 0 && $kind === 'm') $r['c']['model'] = (int)$v;
        elseif ($r['s'] === 0 && $kind === 's') $r['c']['size'] = (int)$v;
        elseif ($r['s'] === 0 && $kind === 'q') $r['c']['quantity'] = (int)$v;
        elseif ($r['s'] === 1 && in_array($action, ['fM','fB'], true)) {
            $r['c']['fabric'] = $action === 'fM' ? 'Madera' : 'Bavaro';
            $r['c']['color'] = '2'; $r['page'] = 0;
        }
        elseif ($r['s'] === 1 && $kind === 'c' && preg_match('/^[1-9][0-9]?$/D', $v)) {
            $r['c']['color'] = $v;
            $codes = array_map('strval', array_keys($this->fabrics[$r['c']['fabric']]));
            $index = array_search($v, $codes, true);
            if ($index === false) throw new InvalidArgumentException('Invalid colour.');
            $r['page'] = intdiv($index, 8);
        }
        elseif ($r['s'] === 1 && $kind === 'p' && preg_match('/^[0-9]$/D', $v)) $r['page'] = (int)$v;
        elseif ($r['s'] === 2 && preg_match('/^[blhx][01]$/D', $action)) {
            $key = ['b'=>'lift','l'=>'legs','h'=>'thick','x'=>'mattress'][$kind];
            $r['c'][$key] = $v === '1';
        }
        else throw new InvalidArgumentException('Action is not available on this screen.');
        $r['r']++;
        $this->validateRow($r);
        return $r;
    }

    public function pack(array $c): string {
        $this->validate($c);
        return implode('.', [$c['model'],$c['size'],$c['fabric']==='Madera'?'M':'B',$c['color'],
            (int)$c['lift'],(int)$c['legs'],(int)$c['thick'],$c['quantity'],(int)$c['mattress']]);
    }

    public function unpack(string $packed): array {
        if (!preg_match('/^([0137])\.(90|120|140|160|180|200)\.([MB])\.([1-9][0-9]?)\.([01])\.([01])\.([01])\.(10|[1-9])\.([01])$/D', $packed, $m)) throw new InvalidArgumentException('Invalid checkout configuration.');
        $c = ['model'=>(int)$m[1], 'size'=>(int)$m[2], 'fabric'=>$m[3]==='M'?'Madera':'Bavaro', 'color'=>$m[4],
            'lift'=>$m[5]==='1', 'legs'=>$m[6]==='1', 'thick'=>$m[7]==='1', 'quantity'=>(int)$m[8],
            'mattress'=>$m[9]==='1', 'delivery'=>'pickup'];
        $this->validate($c);
        return $c;
    }

    private function price(array $c): array {
        $key = json_encode($c, JSON_THROW_ON_ERROR);
        if (!isset($this->cache[$key])) {
            $p = ($this->quote)($c);
            if (!is_array($p) || !isset($p['amountRub'],$p['requiresQuote']) || !is_int($p['amountRub']) ||
                $p['amountRub'] < 0 || !is_bool($p['requiresQuote'])) throw new RuntimeException('Shop price adapter returned invalid units.');
            $this->cache[$key] = $p;
        }
        return $this->cache[$key];
    }
    private static function money(int $n): string { return number_format($n, 0, ',', ' ') . ' ₽'; }
    private function delta(array $c, string $key): string {
        $on = $off = $c; $on[$key] = true; $off[$key] = false;
        $d = $this->price($on)['amountRub'] - $this->price($off)['amountRub'];
        return $d === 0 ? 'без доплаты' : ($d > 0 ? '+' : '−') . self::money(abs($d));
    }

    public function snapshot(array $row, string $notice = ''): array {
        $this->validateRow($row); $this->cache = [];
        $c = $row['c']; $step = $row['s']; $f = $c['fabric']; $qty = $c['quantity'];
        $p = $this->price($c); $groups = [];
        if ($step === 0) {
            $models = [];
            foreach (self::MODELS as $i) $models[] = [$this->models[$i]['name'],'m'.$i,$c['model']===$i];
            $sizes = array_map(fn($s)=>[(string)$s,'s'.$s,$c['size']===$s], self::SIZES);
            $groups = [['Модель',$models],['Спальное место, см',$sizes,'sizes'],
                ['Количество',[['−','q'.max(1,$qty-1),false,null,$qty===1],[$qty.' шт.','noop',true,null,true],['+','q'.min(10,$qty+1),false,null,$qty===10]],'sizes']];
        } elseif ($step === 1) {
            $m = $b = $c; $m['fabric']='Madera'; $b['fabric']='Bavaro'; $m['color']=$b['color']='2';
            $delta = $this->price($b)['amountRub'] - $this->price($m)['amountRub'];
            $bLabel = 'Bavaro' . ($delta ? ' · '.($delta>0?'+':'−').self::money(abs($delta)) : '');
            $options = [];
            foreach (array_slice($this->fabrics[$f], $row['page']*8, 8, true) as $code=>$sample) {
                $options[] = [(string)$code,'c'.$code,$c['color']===(string)$code,$sample['hex']??null];
            }
            $pages = (int)ceil(count($this->fabrics[$f])/8);
            $groups = [['Коллекция',[['Madera','fM',$f==='Madera'],[$bLabel,'fB',$f==='Bavaro']]],
                ['Цвет ткани',$options,'colors'],
                ['Палитра',[['←','p'.max(0,$row['page']-1),false,null,$row['page']===0],[($row['page']+1).' из '.$pages,'noop',false,null,true],['→','p'.min($pages-1,$row['page']+1),false,null,$row['page']===$pages-1]],'sizes']];
        } else {
            $groups = [['Основание',[['Деревянное','b0',!$c['lift']],['Подъёмное','b1',$c['lift']]]],
                ['Опции на '.$qty.' шт.',[
                    [($c['legs']?'✓ ':'').'Ножки · '.$this->delta($c,'legs'),'l'.(int)!$c['legs'],$c['legs']],
                    [($c['thick']?'✓ ':'').'Более мягкое изголовье · '.$this->delta($c,'thick'),'h'.(int)!$c['thick'],$c['thick']],
                    [($c['mattress']?'✓ ':'').'Матрас · по запросу','x'.(int)!$c['mattress'],$c['mattress']],
                ],'stack']];
        }
        $sample = $this->fabrics[$f][$c['color']];
        $s = ['v'=>1,'r'=>$row['r'],'s'=>$step,'n'=>$qty,'q'=>(int)($p['requiresQuote']||$c['mattress']),
            't'=>['Модель и размер','Ткань и цвет','Основание и опции'][$step],
            'p'=>self::money($p['amountRub']), 'd'=>$this->models[$c['model']]['name'].' · '.$c['size'].'×200 · '.$qty.' шт.',
            'f'=>$f.' '.$c['color'].' · '.($sample['name']??$c['color']), 'g'=>$groups,
            'u'=>'https://redbeards.ru/hotel-beds/telegram-checkout.php?c='.$this->pack($c)];
        if ($notice !== '') $s['e'] = $notice;
        if (strlen(self::encode($s)) > 1024) {
            unset($s['e']);
            if (strlen(self::encode($s)) > 1024) throw new RuntimeException('Widget snapshot exceeds Telegram limit.');
        }
        return $s;
    }

    public static function encode(array $snapshot): string {
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
