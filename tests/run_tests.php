<?php

declare(strict_types=1);

require_once __DIR__ . '/RagVectorlessTest.php';

use EidCloud\RagVectorless\Tests\RagVectorlessTest;

$testRunner = new RagVectorlessTest();
$passed = $testRunner->runAll();

exit($passed ? 0 : 1);
