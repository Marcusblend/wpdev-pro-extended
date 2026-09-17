<?php

declare(strict_types=1);

use ProExtended\Support\LineDiff;

T::group('LineDiff');

T::same('', LineDiff::unified("a\nb", "a\nb"), 'no diff for equal text');
$diff = LineDiff::unified("a\nb\nc\nd", "a\nb\nX\nd");
T::ok(str_contains($diff, "-c\n+X"), 'shows the changed line', $diff);
T::ok(str_contains($diff, ' a') && str_contains($diff, ' d'), 'includes context');
$long = LineDiff::unified('', implode("\n", range(1, 1000)), 3, 400);
T::ok(str_contains($long, 'diff truncated'), 'truncates long diffs');
T::same(401, count(explode("\n", $long)), 'stops at the line limit');
