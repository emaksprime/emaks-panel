<?php

declare(strict_types=1);

use Tests\Support\IsolatedPostgreSqlEnvironment;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    IsolatedPostgreSqlEnvironment::assertConfigurationCacheAbsent(dirname(__DIR__));
    IsolatedPostgreSqlEnvironment::assertProcessEnvironment();
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(78);
}
