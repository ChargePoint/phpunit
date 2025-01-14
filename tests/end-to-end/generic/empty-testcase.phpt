--TEST--
phpunit ../../_files/EmptyTestCaseTest.php
--FILE--
<?php declare(strict_types=1);
$_SERVER['argv'][] = '--do-not-cache-result';
$_SERVER['argv'][] = '--no-configuration';
$_SERVER['argv'][] = __DIR__ . '/../../_files/EmptyTestCaseTest.php';

require_once __DIR__ . '/../../bootstrap.php';
PHPUnit\TextUI\Application::main();
--EXPECTF--
PHPUnit %s by Sebastian Bergmann and contributors.

Runtime:       %s

There was 1 PHPUnit warning:

1) No tests found in class "PHPUnit\TestFixture\EmptyTestCaseTest".

No tests executed!
