<?php

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3);
$fixture = json_decode(file_get_contents($root.'/front-end/.performance-check/audit-mysql.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^shishli_perf_[a-f0-9]{12}$/D', $fixture['database'])) {
    throw new RuntimeException('Only the isolated audit schema may receive an image fixture');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $fixture['database'], 'DB_URL' => '', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing audit bootstrap with cached application configuration');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$product = Product::where('uuid', $fixture['product'])->firstOrFail();
$images = json_decode(file_get_contents($root.'/docs/performance/product-image-optimization.json'), true, flags: JSON_THROW_ON_ERROR);
$path = substr($images[0]['path'], 0, -5);
ProductImage::firstOrCreate(['product_id' => $product->id, 'path' => $path], ['disk' => 'public', 'is_primary' => true, 'sort_order' => 0, 'alt_text' => 'Isolated performance photograph']);
echo 'Added one public photograph association only to the synthetic schema after timing measurements'.PHP_EOL;
