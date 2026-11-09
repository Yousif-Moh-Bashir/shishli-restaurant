<?php

// Standalone, read-only inventory. Does not bootstrap Laravel or send requests.
function backupImageFilename(string $name): bool
{
    return preg_match('/\.(?:png|jpe?g|webp|gif|avif)\z/i', $name) === 1;
}

function backupStorageInventory(string $root, string $references, int $minimumImages = 95): array
{
    $rootStat = lstat($root);
    $entries = ['.' => ['mode' => $rootStat['mode'] & 07777, 'uid' => $rootStat['uid'], 'gid' => $rootStat['gid'], 'type' => 'directory']];
    $images = 0;
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
        $stat = lstat($path);
        $entry = ['mode' => $stat['mode'] & 07777, 'uid' => $stat['uid'], 'gid' => $stat['gid']];
        if (is_link($path)) {
            $entry += ['type' => 'link', 'target' => readlink($path)];
        } elseif (is_dir($path)) {
            $entry += ['type' => 'directory'];
        } elseif (is_file($path)) {
            $hash = hash_file('sha256', $path);
            clearstatcache(true, $path);
            $after = lstat($path);
            if ($stat['size'] !== $after['size'] || $stat['mtime'] !== $after['mtime'] || $stat['ino'] !== $after['ino']) {
                throw new RuntimeException('A file changed while being inventoried.');
            }
            $entry += ['type' => 'file', 'bytes' => $after['size'], 'sha256' => $hash];
            $bytes += $after['size'];
            $images += (int) backupImageFilename($name);
        } else {
            throw new RuntimeException('Unsupported special file in storage.');
        }
        $entries[$name] = $entry;
    }
    $records = file($references, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($records === []) {
        throw new RuntimeException('No product image references were found.');
    }
    foreach ($records as $line) {
        $parts = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($parts) || count($parts) !== 2 || $parts[0] !== 'public' || !is_string($parts[1]) || $parts[1] === ''
            || str_starts_with($parts[1], '/') || str_contains($parts[1], '\\')
            || str_contains($parts[1], "\0") || preg_match('/^[a-z]:/i', $parts[1])
            || array_intersect(['.', '..', ''], explode('/', $parts[1])) !== []) {
            throw new RuntimeException('Image disk or relative path requires review.');
        }
        if (($entries['app/public/'.$parts[1]]['type'] ?? null) !== 'file') {
            throw new RuntimeException('A referenced original product image is missing.');
        }
    }
    if ($images < $minimumImages) {
        throw new RuntimeException('Fewer than the reviewed 95 image files were found.');
    }
    ksort($entries, SORT_STRING);
    return ['image_files' => $images, 'file_bytes' => $bytes,
        'product_image_references' => count($records), 'entries' => $entries];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException('Inventory filesystem operation failed.');
    });
    try {
        echo json_encode(backupStorageInventory('/source', '/review/images.jsonl'), JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        fwrite(STDERR, "Storage inventory failed; inspect disk configuration, references, permissions, or concurrent writes.\n");
        exit(1);
    }
}
