--TEST--
https://github.com/sebastianbergmann/phpunit/issues/4632
--XFAIL--
TestDox logging has not been migrated to events yet.
See https://github.com/sebastianbergmann/phpunit/issues/4702 for details.
--FILE--
<?php declare(strict_types=1);
$_SERVER['argv'][] = '--do-not-cache-result';
$_SERVER['argv'][] = '--no-configuration';
$_SERVER['argv'][] = '--no-progress';
$_SERVER['argv'][] = '--testdox';
$_SERVER['argv'][] = '--repeat';
$_SERVER['argv'][] = '3';
$_SERVER['argv'][] = __DIR__ . '/4632/Issue4632Test.php';

require_once __DIR__ . '/../../bootstrap.php';

PHPUnit\TextUI\Application::main();
--EXPECTF--
PHPUnit %s by Sebastian Bergmann and contributors.

Runtime: %s

Time: %s, Memory: %s

Issue4632 (PHPUnit\TestFixture\Issue4632)
 ✘ One
   │
   │ Failed asserting that false is true.
   │
   │ %sIssue4632Test.php:%d
   │

 ✘ One
   │
   │ Failed asserting that false is true.
   │
   │ %sIssue4632Test.php:%d
   │

 ✘ One
   │
   │ Failed asserting that false is true.
   │
   │ %sIssue4632Test.php:%d
   │

FAILURES!
Tests: 3, Assertions: 3, Failures: 3.
