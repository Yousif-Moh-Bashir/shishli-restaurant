<?php

// Explicit audit utility: only the two shipped assets, never the visual reference directory.
$root = dirname(__DIR__, 3).'/front-end/src/assets/images';
$assets = [
    ['logo/shishli-logo', 480, 90],
    ['banners/restaurant-reference', 1536, 84],
];
$results = [];
foreach ($assets as [$name, $width, $quality]) {
    $source = imagecreatefrompng($root.'/'.$name.'.png');
    $height = (int) round(imagesy($source) * $width / imagesx($source));
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
    $path = $root.'/'.$name.'.webp';
    $before = filesize($path);
    if (! imagewebp($image, $path, $quality)) {
        throw new RuntimeException('WebP encoding failed');
    }
    clearstatcache(true, $path);
    $results[] = ['asset' => $name, 'before_bytes' => $before, 'after_bytes' => filesize($path), 'width' => $width, 'height' => $height, 'quality' => $quality];
    imagedestroy($image);
    imagedestroy($source);
}
file_put_contents(dirname(__DIR__, 3).'/docs/performance/image-optimization.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
