<?php

$root = dirname(__DIR__, 3);
$files = [];
$hashes = [];
foreach (['front-end/src/assets', 'front-end/public', 'back-end/storage/app/public'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! preg_match('/\.(png|jpe?g|webp|avif|svg|woff2?|ttf|ico)$/i', $file->getFilename())) {
            continue;
        }
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $dimensions = @getimagesize($file->getPathname());
        $files[] = ['path' => $path, 'bytes' => $file->getSize(), 'width' => $dimensions[0] ?? null, 'height' => $dimensions[1] ?? null];
        $hashes[hash_file('sha256', $file->getPathname())][] = $path;
    }
}
usort($files, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
$duplicates = array_values(array_filter($hashes, fn ($paths) => count($paths) > 1));
file_put_contents($root.'/docs/performance/asset-inventory.json', json_encode(['files' => $files, 'identical_files' => $duplicates], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($files).' assets inventoried; '.count($duplicates).' identical groups'.PHP_EOL;
