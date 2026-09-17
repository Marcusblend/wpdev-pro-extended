<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$failed = count(T::$failures);
echo sprintf("\n%d passed, %d failed\n", T::$passed, $failed);

foreach (T::$failures as $failure) {
    echo "  - {$failure}\n";
}

exit($failed > 0 ? 1 : 0);
