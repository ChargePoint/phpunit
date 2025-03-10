--TEST--
The right events are emitted in the right order for a test with an output expectation
--SKIPIF--
<?php declare(strict_types=1);
if (DIRECTORY_SEPARATOR === '\\') {
    print "skip: this test does not work on Windows / GitHub Actions\n";
}
--FILE--
<?php declare(strict_types=1);
$traceFile = tempnam(sys_get_temp_dir(), __FILE__);

$_SERVER['argv'][] = '--do-not-cache-result';
$_SERVER['argv'][] = '--no-configuration';
$_SERVER['argv'][] = '--disallow-test-output';
$_SERVER['argv'][] = '--no-output';
$_SERVER['argv'][] = '--log-events-text';
$_SERVER['argv'][] = $traceFile;
$_SERVER['argv'][] = __DIR__ . '/../regression/445/Issue445Test.php';

require __DIR__ . '/../../bootstrap.php';

PHPUnit\TextUI\Application::main(false);

print file_get_contents($traceFile);

unlink($traceFile);
--EXPECTF--
Test Runner Started (PHPUnit %s using %s)
Test Runner Configured
Test Suite Loaded (3 tests)
Test Suite Sorted
Event Facade Sealed
Test Runner Execution Started (3 tests)
Test Suite Started (PHPUnit\TestFixture\Issue445Test, 3 tests)
Test Preparation Started (PHPUnit\TestFixture\Issue445Test::testOutputWithExpectationBefore)
Test Prepared (PHPUnit\TestFixture\Issue445Test::testOutputWithExpectationBefore)
Assertion Succeeded (Constraint: is equal to 'test', Value: 'test')
Test Passed (PHPUnit\TestFixture\Issue445Test::testOutputWithExpectationBefore)
Test Finished (PHPUnit\TestFixture\Issue445Test::testOutputWithExpectationBefore)
Test Preparation Started (PHPUnit\TestFixture\Issue445Test::testOutputWithExpectationAfter)
Test Prepared (PHPUnit\TestFixture\Issue445Test::testOutputWithExpectationAfter)
Assertion Succeeded (Constraint: is equal to 'test', Value: 'test')
Test Passed (PHPUnit\TestFixture\Issue445Test::testOutputWithExpectationAfter)
Test Finished (PHPUnit\TestFixture\Issue445Test::testOutputWithExpectationAfter)
Test Preparation Started (PHPUnit\TestFixture\Issue445Test::testNotMatchingOutput)
Test Prepared (PHPUnit\TestFixture\Issue445Test::testNotMatchingOutput)
Assertion Failed (Constraint: is equal to 'foo', Value: 'bar')
Test Failed (PHPUnit\TestFixture\Issue445Test::testNotMatchingOutput)
Failed asserting that two strings are equal.
Test Finished (PHPUnit\TestFixture\Issue445Test::testNotMatchingOutput)
Test Suite Finished (PHPUnit\TestFixture\Issue445Test, 3 tests)
Test Runner Execution Finished
Test Runner Finished
