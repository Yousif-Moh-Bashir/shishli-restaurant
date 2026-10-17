<?php

declare(strict_types=1);

$app = require __DIR__.'/bootstrap.php';

exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
