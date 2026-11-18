<?php
declare(strict_types=1);
define('RB_INSPECT_LIBRARY_ONLY', true);
require __DIR__ . '/../tools/inspect-shop-template.php';
$source = <<<'PHP'
<?php
class ExampleShop {
    private function price(array $c): array {
        $label = "Кровать {$c['name']}";
        return ['label' => $label, 'amount' => 17000, 'extra' => ['brace' => '}']];
    }
    public function createOrder(array $data): array { return ['private' => 'not selected']; }
    protected function normalizeConfig(array $c): array { return $c; }
}
PHP;
$m = rbInspectMethods($source);
if (array_keys($m) !== ['price', 'createOrder', 'normalizeConfig']) throw new RuntimeException('Incorrect method names.');
if (!str_contains($m['price']['source'], "'amount' => 17000") || !str_ends_with($m['price']['source'], '}')) throw new RuntimeException('Interpolated string broke source extraction.');
if ($m['createOrder']['source'] !== null) throw new RuntimeException('Unrelated method source included.');
if (!str_contains($m['normalizeConfig']['source'], 'return $c;')) throw new RuntimeException('Normalization source missing.');
echo "PASS: method extraction with interpolation, nested arrays and literal braces; unrelated bodies omitted.\n";
