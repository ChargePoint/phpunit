<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Framework;

use const LC_ALL;
use const LC_COLLATE;
use const LC_CTYPE;
use const LC_MONETARY;
use const LC_NUMERIC;
use const LC_TIME;
use const PATHINFO_FILENAME;
use const PHP_EOL;
use const PHP_URL_PATH;
use function array_merge;
use function array_values;
use function basename;
use function chdir;
use function class_exists;
use function clearstatcache;
use function count;
use function defined;
use function explode;
use function getcwd;
use function implode;
use function in_array;
use function ini_set;
use function is_array;
use function is_callable;
use function is_int;
use function is_object;
use function is_string;
use function libxml_clear_errors;
use function method_exists;
use function ob_end_clean;
use function ob_get_clean;
use function ob_get_contents;
use function ob_get_level;
use function ob_start;
use function parse_url;
use function pathinfo;
use function preg_replace;
use function setlocale;
use function sprintf;
use function str_contains;
use function trim;
use AssertionError;
use DeepCopy\DeepCopy;
use PHPUnit\Event;
use PHPUnit\Framework\Constraint\Exception as ExceptionConstraint;
use PHPUnit\Framework\Constraint\ExceptionCode;
use PHPUnit\Framework\Constraint\MessageIsOrContains;
use PHPUnit\Framework\Constraint\MessageMatchesRegularExpression;
use PHPUnit\Framework\MockObject\Generator as MockGenerator;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\AnyInvokedCount as AnyInvokedCountMatcher;
use PHPUnit\Framework\MockObject\Rule\InvokedAtLeastCount as InvokedAtLeastCountMatcher;
use PHPUnit\Framework\MockObject\Rule\InvokedAtLeastOnce as InvokedAtLeastOnceMatcher;
use PHPUnit\Framework\MockObject\Rule\InvokedAtMostCount as InvokedAtMostCountMatcher;
use PHPUnit\Framework\MockObject\Rule\InvokedCount as InvokedCountMatcher;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\MockObject\Stub\ConsecutiveCalls as ConsecutiveCallsStub;
use PHPUnit\Framework\MockObject\Stub\Exception as ExceptionStub;
use PHPUnit\Framework\MockObject\Stub\ReturnArgument as ReturnArgumentStub;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback as ReturnCallbackStub;
use PHPUnit\Framework\MockObject\Stub\ReturnSelf as ReturnSelfStub;
use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\MockObject\Stub\ReturnValueMap as ReturnValueMapStub;
use PHPUnit\Framework\TestSize\TestSize;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\Metadata\Api\Groups;
use PHPUnit\Metadata\Api\HookMethods;
use PHPUnit\Metadata\Api\Requirements;
use PHPUnit\Metadata\Parser\Registry as MetadataRegistry;
use PHPUnit\TestRunner\TestResult\PassedTests;
use PHPUnit\TextUI\Configuration\Registry as ConfigurationRegistry;
use PHPUnit\Util\Cloner;
use PHPUnit\Util\Test as TestUtil;
use ReflectionClass;
use ReflectionException;
use ReflectionObject;
use SebastianBergmann\Comparator\Comparator;
use SebastianBergmann\Comparator\Factory as ComparatorFactory;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use SebastianBergmann\Exporter\Exporter;
use SebastianBergmann\GlobalState\ExcludeList as GlobalStateExcludeList;
use SebastianBergmann\GlobalState\Restorer;
use SebastianBergmann\GlobalState\Snapshot;
use SebastianBergmann\Invoker\TimeoutException;
use SebastianBergmann\ObjectEnumerator\Enumerator;
use Throwable;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for PHPUnit
 */
abstract class TestCase extends Assert implements Reorderable, SelfDescribing, Test
{
    private const LOCALE_CATEGORIES = [LC_ALL, LC_COLLATE, LC_CTYPE, LC_MONETARY, LC_NUMERIC, LC_TIME];
    private ?bool $backupGlobals    = null;

    /**
     * @psalm-var list<string>
     */
    private array $backupGlobalsExcludeList = [];
    private ?bool $backupStaticProperties   = null;

    /**
     * @psalm-var array<string,list<class-string>>
     */
    private array $backupStaticPropertiesExcludeList = [];
    private ?Snapshot $snapshot                      = null;
    private ?bool $runClassInSeparateProcess         = null;
    private ?bool $runTestInSeparateProcess          = null;
    private bool $preserveGlobalState                = false;
    private bool $inIsolation                        = false;
    private ?string $expectedException               = null;
    private ?string $expectedExceptionMessage        = null;
    private ?string $expectedExceptionMessageRegExp  = null;
    private null|int|string $expectedExceptionCode   = null;

    /**
     * @psalm-var list<ExecutionOrderDependency>
     */
    private array $providedTests = [];
    private array $data          = [];
    private int|string $dataName = '';
    private string $name;

    /**
     * @psalm-var list<string>
     */
    private array $groups = [];

    /**
     * @psalm-var list<ExecutionOrderDependency>
     */
    private array $dependencies    = [];
    private array $dependencyInput = [];

    /**
     * @psalm-var array<string,string>
     */
    private array $iniSettings                  = [];
    private array $locale                       = [];
    private ?MockGenerator $mockObjectGenerator = null;

    /**
     * @psalm-var list<MockObject>
     */
    private array $mockObjects                                    = [];
    private bool $registerMockObjectsFromTestArgumentsRecursively = false;
    private TestStatus $status;
    private int $numberOfAssertionsPerformed = 0;
    private mixed $testResult                = null;
    private string $output                   = '';
    private ?string $outputExpectedRegex     = null;
    private ?string $outputExpectedString    = null;
    private bool $outputBufferingActive      = false;
    private int $outputBufferingLevel;
    private bool $outputRetrievedForAssertion = false;
    private bool $doesNotPerformAssertions    = false;

    /**
     * @psalm-var list<Comparator>
     */
    private array $customComparators                         = [];
    private ?Event\Code\TestMethod $testValueObjectForEvents = null;
    private bool $wasPrepared                                = false;

    /**
     * Returns a matcher that matches when the method is executed
     * zero or more times.
     */
    final public static function any(): AnyInvokedCountMatcher
    {
        return new AnyInvokedCountMatcher;
    }

    /**
     * Returns a matcher that matches when the method is never executed.
     */
    final public static function never(): InvokedCountMatcher
    {
        return new InvokedCountMatcher(0);
    }

    /**
     * Returns a matcher that matches when the method is executed
     * at least N times.
     */
    final public static function atLeast(int $requiredInvocations): InvokedAtLeastCountMatcher
    {
        return new InvokedAtLeastCountMatcher(
            $requiredInvocations
        );
    }

    /**
     * Returns a matcher that matches when the method is executed at least once.
     */
    final public static function atLeastOnce(): InvokedAtLeastOnceMatcher
    {
        return new InvokedAtLeastOnceMatcher;
    }

    /**
     * Returns a matcher that matches when the method is executed exactly once.
     */
    final public static function once(): InvokedCountMatcher
    {
        return new InvokedCountMatcher(1);
    }

    /**
     * Returns a matcher that matches when the method is executed
     * exactly $count times.
     */
    final public static function exactly(int $count): InvokedCountMatcher
    {
        return new InvokedCountMatcher($count);
    }

    /**
     * Returns a matcher that matches when the method is executed
     * at most N times.
     */
    final public static function atMost(int $allowedInvocations): InvokedAtMostCountMatcher
    {
        return new InvokedAtMostCountMatcher($allowedInvocations);
    }

    final public static function returnValue(mixed $value): ReturnStub
    {
        return new ReturnStub($value);
    }

    final public static function returnValueMap(array $valueMap): ReturnValueMapStub
    {
        return new ReturnValueMapStub($valueMap);
    }

    final public static function returnArgument(int $argumentIndex): ReturnArgumentStub
    {
        return new ReturnArgumentStub($argumentIndex);
    }

    final public static function returnCallback(callable $callback): ReturnCallbackStub
    {
        return new ReturnCallbackStub($callback);
    }

    /**
     * Returns the current object.
     *
     * This method is useful when mocking a fluent interface.
     */
    final public static function returnSelf(): ReturnSelfStub
    {
        return new ReturnSelfStub;
    }

    final public static function throwException(Throwable $exception): ExceptionStub
    {
        return new ExceptionStub($exception);
    }

    final public static function onConsecutiveCalls(mixed ...$arguments): ConsecutiveCallsStub
    {
        return new ConsecutiveCallsStub($arguments);
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    public function __construct(string $name)
    {
        $this->setName($name);

        $this->status = TestStatus::unknown();
    }

    /**
     * This method is called before the first test of this test class is run.
     */
    public static function setUpBeforeClass(): void
    {
    }

    /**
     * This method is called after the last test of this test class is run.
     */
    public static function tearDownAfterClass(): void
    {
    }

    /**
     * This method is called before each test.
     */
    protected function setUp(): void
    {
    }

    /**
     * Performs assertions shared by all tests of a test case.
     *
     * This method is called between setUp() and test.
     */
    protected function assertPreConditions(): void
    {
    }

    /**
     * Performs assertions shared by all tests of a test case.
     *
     * This method is called between test and tearDown().
     */
    protected function assertPostConditions(): void
    {
    }

    /**
     * This method is called after each test.
     */
    protected function tearDown(): void
    {
    }

    /**
     * Returns a string representation of the test case.
     *
     * @throws Exception
     */
    public function toString(): string
    {
        $buffer = sprintf(
            '%s::%s',
            (new ReflectionClass($this))->getName(),
            $this->name
        );

        return $buffer . $this->dataSetAsStringWithData();
    }

    final public function count(): int
    {
        return 1;
    }

    final public function getActualOutputForAssertion(): string
    {
        $this->outputRetrievedForAssertion = true;

        return $this->output();
    }

    final public function expectOutputRegex(string $expectedRegex): void
    {
        $this->outputExpectedRegex = $expectedRegex;
    }

    final public function expectOutputString(string $expectedString): void
    {
        $this->outputExpectedString = $expectedString;
    }

    /**
     * @psalm-param class-string<Throwable> $exception
     */
    final public function expectException(string $exception): void
    {
        $this->expectedException = $exception;
    }

    final public function expectExceptionCode(int|string $code): void
    {
        $this->expectedExceptionCode = $code;
    }

    final public function expectExceptionMessage(string $message): void
    {
        $this->expectedExceptionMessage = $message;
    }

    final public function expectExceptionMessageMatches(string $regularExpression): void
    {
        $this->expectedExceptionMessageRegExp = $regularExpression;
    }

    /**
     * Sets up an expectation for an exception to be raised by the code under test.
     * Information for expected exception class, expected exception message, and
     * expected exception code are retrieved from a given Exception object.
     */
    final public function expectExceptionObject(\Exception $exception): void
    {
        $this->expectException($exception::class);
        $this->expectExceptionMessage($exception->getMessage());
        $this->expectExceptionCode($exception->getCode());
    }

    final public function expectNotToPerformAssertions(): void
    {
        $this->doesNotPerformAssertions = true;
    }

    final public function status(): TestStatus
    {
        return $this->status;
    }

    /**
     * @throws \SebastianBergmann\CodeCoverage\InvalidArgumentException
     * @throws \SebastianBergmann\CodeCoverage\UnintentionallyCoveredCodeException
     * @throws CodeCoverageException
     */
    final public function run(): void
    {
        if (!$this->handleDependencies()) {
            return;
        }

        if (!$this->shouldRunInSeparateProcess()) {
            (new TestRunner)->run($this);
        } else {
            (new TestRunner)->runInSeparateProcess(
                $this,
                $this->runClassInSeparateProcess && !$this->runTestInSeparateProcess,
                $this->preserveGlobalState
            );
        }
    }

    /**
     * Returns a builder object to create mock objects using a fluent interface.
     *
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $className
     *
     * @psalm-return MockBuilder<RealInstanceType>
     */
    final public function getMockBuilder(string $className): MockBuilder
    {
        return new MockBuilder($this, $className);
    }

    final public function registerComparator(Comparator $comparator): void
    {
        ComparatorFactory::getInstance()->register($comparator);

        Event\Facade::emitter()->testRegisteredComparator($comparator::class);

        $this->customComparators[] = $comparator;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setGroups(array $groups): void
    {
        $this->groups = $groups;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function nameWithDataSet(): string
    {
        return $this->name . $this->dataSetAsString();
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function name(): string
    {
        return $this->name;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function size(): TestSize
    {
        return (new Groups)->size(
            static::class,
            $this->name
        );
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function hasOutput(): bool
    {
        if ($this->output === '') {
            return false;
        }

        if ($this->hasExpectationOnOutput()) {
            return false;
        }

        return true;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function output(): string
    {
        if (!$this->outputBufferingActive) {
            return $this->output;
        }

        return (string) ob_get_contents();
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function doesNotPerformAssertions(): bool
    {
        return $this->doesNotPerformAssertions;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function hasExpectationOnOutput(): bool
    {
        return is_string($this->outputExpectedString) || is_string($this->outputExpectedRegex) || $this->outputRetrievedForAssertion;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function registerMockObjectsFromTestArgumentsRecursively(): void
    {
        $this->registerMockObjectsFromTestArgumentsRecursively = true;
    }

    /**
     * @throws Throwable
     *
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function runBare(): void
    {
        $emitter = Event\Facade::emitter();

        $emitter->testPreparationStarted(
            $this->valueObjectForEvents()
        );

        $this->snapshotGlobalState();
        $this->startOutputBuffering();
        clearstatcache();

        $hookMethods                       = (new HookMethods)->hookMethods(static::class);
        $hasMetRequirements                = false;
        $this->numberOfAssertionsPerformed = 0;
        $currentWorkingDirectory           = getcwd();

        try {
            $this->checkRequirements();
            $hasMetRequirements = true;

            if ($this->inIsolation) {
                $this->invokeBeforeClassHookMethods($hookMethods, $emitter);
            }

            if (method_exists(static::class, $this->name) &&
                MetadataRegistry::parser()->forMethod(static::class, $this->name)->isDoesNotPerformAssertions()->isNotEmpty()) {
                $this->doesNotPerformAssertions = true;
            }

            $this->invokeBeforeTestHookMethods($hookMethods, $emitter);
            $this->invokePreConditionHookMethods($hookMethods, $emitter);

            $emitter->testPrepared(
                $this->valueObjectForEvents()
            );

            $this->wasPrepared = true;
            $this->testResult  = $this->runTest();

            $this->verifyMockObjects();
            $this->invokePostConditionHookMethods($hookMethods, $emitter);

            $this->status = TestStatus::success();
        } catch (IncompleteTest $e) {
            $this->status = TestStatus::incomplete($e->getMessage());

            $emitter->testMarkedAsIncomplete(
                $this->valueObjectForEvents(),
                Event\Code\Throwable::from($e)
            );
        } catch (SkippedTest $e) {
            $this->status = TestStatus::skipped($e->getMessage());

            $emitter->testSkipped(
                $this->valueObjectForEvents(),
                $e->getMessage()
            );
        } catch (AssertionError|AssertionFailedError $e) {
            $this->status = TestStatus::failure($e->getMessage());

            $emitter->testFailed(
                $this->valueObjectForEvents(),
                Event\Code\Throwable::from($e)
            );
        } catch (TimeoutException $e) {
            $this->status = TestStatus::risky($e->getMessage());
        } catch (Throwable $_e) {
            $e            = $_e;
            $this->status = TestStatus::error($_e->getMessage());

            $emitter->testErrored(
                $this->valueObjectForEvents(),
                Event\Code\Throwable::from($_e)
            );
        }

        if ($this->stopOutputBuffering() && !isset($e)) {
            $this->performAssertionsOnOutput();
        }

        if ($this->status->isSuccess()) {
            Event\Facade::emitter()->testPassed(
                $this->valueObjectForEvents(),
            );

            PassedTests::instance()->testMethodPassed(
                $this->valueObjectForEvents(),
                $this->testResult
            );
        }

        $this->mockObjects = [];

        // Tear down the fixture. An exception raised in tearDown() will be
        // caught and passed on when no exception was raised before.
        try {
            if ($hasMetRequirements) {
                $this->invokeAfterTestHookMethods($hookMethods, $emitter);

                if ($this->inIsolation) {
                    $this->invokeAfterClassHookMethods($hookMethods, $emitter);
                }
            }
        } catch (Throwable $_e) {
            $e = $e ?? $_e;
        }

        if (isset($_e)) {
            $this->status = TestStatus::error($_e->getMessage());
        }

        clearstatcache();

        if ($currentWorkingDirectory !== getcwd()) {
            chdir($currentWorkingDirectory);
        }

        $this->restoreGlobalState();
        $this->unregisterCustomComparators();
        $this->cleanupIniSettings();
        $this->cleanupLocaleSettings();
        libxml_clear_errors();

        $this->testValueObjectForEvents = null;

        if (isset($e)) {
            $this->onNotSuccessfulTest($e);
        }
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setName(string $name): void
    {
        $this->name = $name;

        if (is_callable($this->sortId(), true)) {
            $this->providedTests = [new ExecutionOrderDependency($this->sortId())];
        }
    }

    /**
     * @psalm-param list<ExecutionOrderDependency> $dependencies
     *
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setDependencies(array $dependencies): void
    {
        $this->dependencies = $dependencies;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setDependencyInput(array $dependencyInput): void
    {
        $this->dependencyInput = $dependencyInput;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function dependencyInput(): array
    {
        return $this->dependencyInput;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setBackupGlobals(bool $backupGlobals): void
    {
        $this->backupGlobals = $backupGlobals;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setBackupGlobalsExcludeList(array $backupGlobalsExcludeList): void
    {
        $this->backupGlobalsExcludeList = $backupGlobalsExcludeList;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setBackupStaticProperties(bool $backupStaticProperties): void
    {
        $this->backupStaticProperties = $backupStaticProperties;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setBackupStaticPropertiesExcludeList(array $backupStaticPropertiesExcludeList): void
    {
        $this->backupStaticPropertiesExcludeList = $backupStaticPropertiesExcludeList;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setRunTestInSeparateProcess(bool $runTestInSeparateProcess): void
    {
        if ($this->runTestInSeparateProcess === null) {
            $this->runTestInSeparateProcess = $runTestInSeparateProcess;
        }
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setRunClassInSeparateProcess(bool $runClassInSeparateProcess): void
    {
        $this->runClassInSeparateProcess = $runClassInSeparateProcess;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setPreserveGlobalState(bool $preserveGlobalState): void
    {
        $this->preserveGlobalState = $preserveGlobalState;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setInIsolation(bool $inIsolation): void
    {
        $this->inIsolation = $inIsolation;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function isInIsolation(): bool
    {
        return $this->inIsolation;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function result(): mixed
    {
        return $this->testResult;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setResult(mixed $result): void
    {
        $this->testResult = $result;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function registerMockObject(MockObject $mockObject): void
    {
        $this->mockObjects[] = $mockObject;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function addToAssertionCount(int $count): void
    {
        $this->numberOfAssertionsPerformed += $count;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function numberOfAssertionsPerformed(): int
    {
        return $this->numberOfAssertionsPerformed;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function usesDataProvider(): bool
    {
        return !empty($this->data);
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function dataName(): int|string
    {
        return $this->dataName;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function dataSetAsString(): string
    {
        $buffer = '';

        if (!empty($this->data)) {
            if (is_int($this->dataName)) {
                $buffer .= sprintf(' with data set #%d', $this->dataName);
            } else {
                $buffer .= sprintf(' with data set "%s"', $this->dataName);
            }
        }

        return $buffer;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function dataSetAsStringWithData(): string
    {
        if (empty($this->data)) {
            return '';
        }

        return $this->dataSetAsString() . sprintf(
            ' (%s)',
            (new Exporter)->shortenedRecursiveExport($this->data)
        );
    }

    /**
     * Gets the data set of a TestCase.
     *
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function providedData(): array
    {
        return $this->data;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function sortId(): string
    {
        $id = $this->name;

        if (!str_contains($id, '::')) {
            $id = static::class . '::' . $id;
        }

        if ($this->usesDataProvider()) {
            $id .= $this->dataSetAsString();
        }

        return $id;
    }

    /**
     * Returns the normalized test name as class::method.
     *
     * @psalm-return list<ExecutionOrderDependency>
     */
    final public function provides(): array
    {
        return $this->providedTests;
    }

    /**
     * Returns a list of normalized dependency names, class::method.
     *
     * This list can differ from the raw dependencies as the resolver has
     * no need for the [!][shallow]clone prefix that is filtered out
     * during normalization.
     *
     * @psalm-return list<ExecutionOrderDependency>
     */
    final public function requires(): array
    {
        return $this->dependencies;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function setData(int|string $dataName, array $data): void
    {
        $this->dataName = $dataName;
        $this->data     = $data;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function valueObjectForEvents(): Event\Code\TestMethod
    {
        if ($this->testValueObjectForEvents !== null) {
            return $this->testValueObjectForEvents;
        }

        $this->testValueObjectForEvents = Event\Code\TestMethod::fromTestCase($this);

        return $this->testValueObjectForEvents;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    final public function wasPrepared(): bool
    {
        return $this->wasPrepared;
    }

    /**
     * Override to run the test and assert its state.
     *
     * @throws \SebastianBergmann\ObjectEnumerator\InvalidArgumentException
     * @throws AssertionFailedError
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws Throwable
     */
    protected function runTest(): mixed
    {
        $testArguments = array_merge($this->data, $this->dependencyInput);

        $this->registerMockObjectsFromTestArguments($testArguments);

        try {
            $testResult = $this->{$this->name}(...array_values($testArguments));
        } catch (Throwable $exception) {
            if (!$this->shouldExceptionExpectationsBeVerified($exception)) {
                throw $exception;
            }

            $this->verifyExceptionExpectations($exception);

            return null;
        }

        $this->expectedExceptionWasNotRaised();

        return $testResult;
    }

    /**
     * This method is a wrapper for the ini_set() function that automatically
     * resets the modified php.ini setting to its original value after the
     * test is run.
     *
     * @throws Exception
     */
    protected function iniSet(string $varName, string $newValue): void
    {
        $currentValue = ini_set($varName, $newValue);

        if ($currentValue !== false) {
            $this->iniSettings[$varName] = $currentValue;
        } else {
            throw new Exception(
                sprintf(
                    'INI setting "%s" could not be set to "%s".',
                    $varName,
                    $newValue
                )
            );
        }
    }

    /**
     * This method is a wrapper for the setlocale() function that automatically
     * resets the locale to its original value after the test is run.
     *
     * @throws Exception
     */
    protected function setLocale(mixed ...$arguments): void
    {
        if (count($arguments) < 2) {
            throw new Exception;
        }

        [$category, $locale] = $arguments;

        if (!in_array($category, self::LOCALE_CATEGORIES, true)) {
            throw new Exception;
        }

        if (!is_array($locale) && !is_string($locale)) {
            throw new Exception;
        }

        $this->locale[$category] = setlocale($category, 0);

        $result = setlocale(...$arguments);

        if ($result === false) {
            throw new Exception(
                'The locale functionality is not implemented on your platform, ' .
                'the specified locale does not exist or the category name is ' .
                'invalid.'
            );
        }
    }

    /**
     * Creates a test stub for the specified interface or class.
     *
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $originalClassName
     *
     * @psalm-return Stub&RealInstanceType
     */
    protected function createStub(string $originalClassName): Stub
    {
        $stub = $this->createMockObject($originalClassName, false);

        Event\Facade::emitter()->testCreatedStub($originalClassName);

        return $stub;
    }

    /**
     * Creates a mock object for the specified interface or class.
     *
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $originalClassName
     *
     * @psalm-return MockObject&RealInstanceType
     */
    protected function createMock(string $originalClassName): MockObject
    {
        $mock = $this->createMockObject($originalClassName);

        Event\Facade::emitter()->testCreatedMockObject($originalClassName);

        return $mock;
    }

    /**
     * Creates (and configures) a mock object for the specified interface or class.
     *
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $originalClassName
     *
     * @psalm-return MockObject&RealInstanceType
     */
    protected function createConfiguredMock(string $originalClassName, array $configuration): MockObject
    {
        $o = $this->createMockObject($originalClassName);

        foreach ($configuration as $method => $return) {
            $o->method($method)->willReturn($return);
        }

        return $o;
    }

    /**
     * Creates a partial mock object for the specified interface or class.
     *
     * @psalm-param list<string> $methods
     *
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $originalClassName
     *
     * @psalm-return MockObject&RealInstanceType
     */
    protected function createPartialMock(string $originalClassName, array $methods): MockObject
    {
        $partialMock = $this->getMockBuilder($originalClassName)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->onlyMethods($methods)
            ->getMock();

        Event\Facade::emitter()->testCreatedPartialMockObject(
            $originalClassName,
            ...$methods
        );

        return $partialMock;
    }

    /**
     * Creates a test proxy for the specified class.
     *
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $originalClassName
     *
     * @psalm-return MockObject&RealInstanceType
     */
    protected function createTestProxy(string $originalClassName, array $constructorArguments = []): MockObject
    {
        $testProxy = $this->getMockBuilder($originalClassName)
            ->setConstructorArgs($constructorArguments)
            ->enableProxyingToOriginalMethods()
            ->getMock();

        Event\Facade::emitter()->testCreatedTestProxy(
            $originalClassName,
            $constructorArguments
        );

        return $testProxy;
    }

    /**
     * Creates a mock object for the specified abstract class with all abstract
     * methods of the class mocked. Concrete methods are not mocked by default.
     * To mock concrete methods, use the 7th parameter ($mockedMethods).
     *
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $originalClassName
     *
     * @psalm-return MockObject&RealInstanceType
     */
    protected function getMockForAbstractClass(string $originalClassName, array $arguments = [], string $mockClassName = '', bool $callOriginalConstructor = true, bool $callOriginalClone = true, bool $callAutoload = true, array $mockedMethods = [], bool $cloneArguments = false): MockObject
    {
        $mockObject = $this->getMockObjectGenerator()->getMockForAbstractClass(
            $originalClassName,
            $arguments,
            $mockClassName,
            $callOriginalConstructor,
            $callOriginalClone,
            $callAutoload,
            $mockedMethods,
            $cloneArguments
        );

        $this->registerMockObject($mockObject);

        Event\Facade::emitter()->testCreatedMockObjectForAbstractClass($originalClassName);

        return $mockObject;
    }

    /**
     * Creates a mock object based on the given WSDL file.
     *
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType>|string $originalClassName
     *
     * @psalm-return MockObject&RealInstanceType
     */
    protected function getMockFromWsdl(string $wsdlFile, string $originalClassName = '', string $mockClassName = '', array $methods = [], bool $callOriginalConstructor = true, array $options = []): MockObject
    {
        if ($originalClassName === '') {
            $fileName          = pathinfo(basename(parse_url($wsdlFile, PHP_URL_PATH)), PATHINFO_FILENAME);
            $originalClassName = preg_replace('/\W/', '', $fileName);
        }

        if (!class_exists($originalClassName)) {
            eval(
                $this->getMockObjectGenerator()->generateClassFromWsdl(
                    $wsdlFile,
                    $originalClassName,
                    $methods,
                    $options
                )
            );
        }

        $mockObject = $this->getMockObjectGenerator()->getMock(
            $originalClassName,
            $methods,
            ['', $options],
            $mockClassName,
            $callOriginalConstructor,
            false,
            false
        );

        Event\Facade::emitter()->testCreatedMockObjectFromWsdl(
            $wsdlFile,
            $originalClassName,
            $mockClassName,
            $methods,
            $callOriginalConstructor,
            $options
        );

        $this->registerMockObject($mockObject);

        return $mockObject;
    }

    /**
     * Creates a mock object for the specified trait with all abstract methods
     * of the trait mocked. Concrete methods to mock can be specified with the
     * `$mockedMethods` parameter.
     *
     * @psalm-param trait-string $traitName
     */
    protected function getMockForTrait(string $traitName, array $arguments = [], string $mockClassName = '', bool $callOriginalConstructor = true, bool $callOriginalClone = true, bool $callAutoload = true, array $mockedMethods = [], bool $cloneArguments = false): MockObject
    {
        $mockObject = $this->getMockObjectGenerator()->getMockForTrait(
            $traitName,
            $arguments,
            $mockClassName,
            $callOriginalConstructor,
            $callOriginalClone,
            $callAutoload,
            $mockedMethods,
            $cloneArguments
        );

        $this->registerMockObject($mockObject);

        Event\Facade::emitter()->testCreatedMockObjectForTrait($traitName);

        return $mockObject;
    }

    /**
     * Creates an object that uses the specified trait.
     *
     * @psalm-param trait-string $traitName
     */
    protected function getObjectForTrait(string $traitName, array $arguments = [], string $traitClassName = '', bool $callOriginalConstructor = true, bool $callOriginalClone = true, bool $callAutoload = true): object
    {
        return $this->getMockObjectGenerator()->getObjectForTrait(
            $traitName,
            $traitClassName,
            $callAutoload,
            $callOriginalConstructor,
            $arguments
        );
    }

    /**
     * This method is called when a test method did not execute successfully.
     *
     * @throws Throwable
     */
    protected function onNotSuccessfulTest(Throwable $t): void
    {
        throw $t;
    }

    /**
     * @throws Throwable
     */
    private function verifyMockObjects(): void
    {
        foreach ($this->mockObjects as $mockObject) {
            if ($mockObject->__phpunit_hasMatchers()) {
                $this->numberOfAssertionsPerformed++;
            }

            $mockObject->__phpunit_verify(
                $this->shouldInvocationMockerBeReset($mockObject)
            );
        }
    }

    /**
     * @throws SkippedTest
     */
    private function checkRequirements(): void
    {
        if (!$this->name || !method_exists($this, $this->name)) {
            return;
        }

        $missingRequirements = (new Requirements)->requirementsNotSatisfiedFor(
            static::class,
            $this->name
        );

        if (!empty($missingRequirements)) {
            $this->markTestSkipped(implode(PHP_EOL, $missingRequirements));
        }
    }

    private function handleDependencies(): bool
    {
        if ([] === $this->dependencies || $this->inIsolation) {
            return true;
        }

        $passedTests = PassedTests::instance();

        foreach ($this->dependencies as $dependency) {
            if (!$dependency->isValid()) {
                $this->markErrorForInvalidDependency();

                return false;
            }

            if ($dependency->targetIsClass()) {
                $dependencyClassName = $dependency->getTargetClassName();

                if (!class_exists($dependencyClassName)) {
                    $this->markErrorForInvalidDependency($dependency);

                    return false;
                }

                if (!$passedTests->hasTestClassPassed($dependencyClassName)) {
                    $this->markSkippedForMissingDependency($dependency);

                    return false;
                }

                continue;
            }

            $dependencyTarget = $dependency->getTarget();

            if (!$passedTests->hasTestMethodPassed($dependencyTarget)) {
                if (!$this->isCallableTestMethod($dependencyTarget)) {
                    $this->markErrorForInvalidDependency($dependency);
                } else {
                    $this->markSkippedForMissingDependency($dependency);
                }

                return false;
            }

            if ($passedTests->isGreaterThan($dependencyTarget, $this->size())) {
                Event\Facade::emitter()->testConsideredRisky(
                    $this->valueObjectForEvents(),
                    'This test depends on a test that is larger than itself'
                );

                return false;
            }

            $returnValue = $passedTests->returnValue($dependencyTarget);

            if ($dependency->deepClone()) {
                $deepCopy = new DeepCopy;
                $deepCopy->skipUncloneable(false);

                $this->dependencyInput[$dependencyTarget] = $deepCopy->copy($returnValue);
            } elseif ($dependency->shallowClone()) {
                $this->dependencyInput[$dependencyTarget] = clone $returnValue;
            } else {
                $this->dependencyInput[$dependencyTarget] = $returnValue;
            }
        }

        return true;
    }

    private function markErrorForInvalidDependency(?ExecutionOrderDependency $dependency = null): void
    {
        $message = 'This test has an invalid dependency';

        if ($dependency !== null) {
            $message = sprintf(
                'This test depends on "%s" which does not exist',
                $dependency->targetIsClass() ? $dependency->getTargetClassName() : $dependency->getTarget()
            );
        }

        $exception = new InvalidDependencyException($message);

        Event\Facade::emitter()->testErrored(
            $this->valueObjectForEvents(),
            Event\Code\Throwable::from($exception)
        );

        $this->status = TestStatus::error($message);
    }

    private function markSkippedForMissingDependency(ExecutionOrderDependency $dependency): void
    {
        $message = sprintf(
            'This test depends on "%s" to pass',
            $dependency->getTarget()
        );

        Event\Facade::emitter()->testSkipped(
            $this->valueObjectForEvents(),
            $message
        );

        $this->status = TestStatus::skipped($message);
    }

    /**
     * Get the mock object generator, creating it if it doesn't exist.
     */
    private function getMockObjectGenerator(): MockGenerator
    {
        if ($this->mockObjectGenerator === null) {
            $this->mockObjectGenerator = new MockGenerator;
        }

        return $this->mockObjectGenerator;
    }

    private function startOutputBuffering(): void
    {
        ob_start();

        $this->outputBufferingActive = true;
        $this->outputBufferingLevel  = ob_get_level();
    }

    private function stopOutputBuffering(): bool
    {
        if (ob_get_level() !== $this->outputBufferingLevel) {
            while (ob_get_level() >= $this->outputBufferingLevel) {
                ob_end_clean();
            }

            $message = 'Test code or tested code did not (only) close its own output buffers';

            Event\Facade::emitter()->testConsideredRisky(
                $this->valueObjectForEvents(),
                $message
            );

            $this->status = TestStatus::risky($message);

            return false;
        }

        $this->output = ob_get_clean();

        $this->outputBufferingActive = false;
        $this->outputBufferingLevel  = ob_get_level();

        return true;
    }

    private function snapshotGlobalState(): void
    {
        if ($this->runTestInSeparateProcess || $this->inIsolation ||
            (!$this->backupGlobals && !$this->backupStaticProperties)) {
            return;
        }

        $snapshot = $this->createGlobalStateSnapshot($this->backupGlobals === true);

        $this->snapshot = $snapshot;
    }

    private function restoreGlobalState(): void
    {
        if (!$this->snapshot instanceof Snapshot) {
            return;
        }

        if (ConfigurationRegistry::get()->beStrictAboutChangesToGlobalState()) {
            $this->compareGlobalStateSnapshots(
                $this->snapshot,
                $this->createGlobalStateSnapshot($this->backupGlobals === true)
            );
        }

        $restorer = new Restorer;

        if ($this->backupGlobals) {
            $restorer->restoreGlobalVariables($this->snapshot);
        }

        if ($this->backupStaticProperties) {
            $restorer->restoreStaticProperties($this->snapshot);
        }

        $this->snapshot = null;
    }

    private function createGlobalStateSnapshot(bool $backupGlobals): Snapshot
    {
        $excludeList = new GlobalStateExcludeList;

        foreach ($this->backupGlobalsExcludeList as $globalVariable) {
            $excludeList->addGlobalVariable($globalVariable);
        }

        if (!defined('PHPUNIT_TESTSUITE')) {
            $excludeList->addClassNamePrefix('PHPUnit');
            $excludeList->addClassNamePrefix('SebastianBergmann\CodeCoverage');
            $excludeList->addClassNamePrefix('SebastianBergmann\FileIterator');
            $excludeList->addClassNamePrefix('SebastianBergmann\Invoker');
            $excludeList->addClassNamePrefix('SebastianBergmann\Template');
            $excludeList->addClassNamePrefix('SebastianBergmann\Timer');
            $excludeList->addStaticProperty(ComparatorFactory::class, 'instance');

            foreach ($this->backupStaticPropertiesExcludeList as $class => $properties) {
                foreach ($properties as $property) {
                    $excludeList->addStaticProperty($class, $property);
                }
            }
        }

        return new Snapshot(
            $excludeList,
            $backupGlobals,
            (bool) $this->backupStaticProperties,
            false,
            false,
            false,
            false,
            false,
            false,
            false
        );
    }

    private function compareGlobalStateSnapshots(Snapshot $before, Snapshot $after): void
    {
        $backupGlobals = $this->backupGlobals === null || $this->backupGlobals;

        if ($backupGlobals) {
            $this->compareGlobalStateSnapshotPart(
                $before->globalVariables(),
                $after->globalVariables(),
                "--- Global variables before the test\n+++ Global variables after the test\n"
            );

            $this->compareGlobalStateSnapshotPart(
                $before->superGlobalVariables(),
                $after->superGlobalVariables(),
                "--- Super-global variables before the test\n+++ Super-global variables after the test\n"
            );
        }

        if ($this->backupStaticProperties) {
            $this->compareGlobalStateSnapshotPart(
                $before->staticProperties(),
                $after->staticProperties(),
                "--- Static properties before the test\n+++ Static properties after the test\n"
            );
        }
    }

    private function compareGlobalStateSnapshotPart(array $before, array $after, string $header): void
    {
        if ($before != $after) {
            $differ   = new Differ(new UnifiedDiffOutputBuilder($header));
            $exporter = new Exporter;

            Event\Facade::emitter()->testConsideredRisky(
                $this->valueObjectForEvents(),
                'This test modified global state but was not expected to do so' . PHP_EOL .
                trim(
                    $differ->diff(
                        $exporter->export($before),
                        $exporter->export($after)
                    )
                )
            );
        }
    }

    /**
     * @throws \SebastianBergmann\ObjectEnumerator\InvalidArgumentException
     */
    private function shouldInvocationMockerBeReset(MockObject $mock): bool
    {
        $enumerator = new Enumerator;

        if (in_array($mock, $enumerator->enumerate($this->dependencyInput), true)) {
            return false;
        }

        if (!is_array($this->testResult) && !is_object($this->testResult)) {
            return true;
        }

        return !in_array($mock, $enumerator->enumerate($this->testResult), true);
    }

    /**
     * @throws \SebastianBergmann\ObjectEnumerator\InvalidArgumentException
     */
    private function registerMockObjectsFromTestArguments(array $testArguments, array &$visited = []): void
    {
        if ($this->registerMockObjectsFromTestArgumentsRecursively) {
            foreach ((new Enumerator)->enumerate($testArguments) as $object) {
                if ($object instanceof MockObject) {
                    $this->registerMockObject($object);
                }
            }
        } else {
            foreach ($testArguments as $testArgument) {
                if ($testArgument instanceof MockObject) {
                    $testArgument = Cloner::clone($testArgument);

                    $this->registerMockObject($testArgument);
                } elseif (is_array($testArgument) && !in_array($testArgument, $visited, true)) {
                    $visited[] = $testArgument;

                    $this->registerMockObjectsFromTestArguments(
                        $testArgument,
                        $visited
                    );
                }
            }
        }
    }

    private function unregisterCustomComparators(): void
    {
        $factory = ComparatorFactory::getInstance();

        foreach ($this->customComparators as $comparator) {
            $factory->unregister($comparator);
        }

        $this->customComparators = [];
    }

    private function cleanupIniSettings(): void
    {
        foreach ($this->iniSettings as $varName => $oldValue) {
            ini_set($varName, $oldValue);
        }

        $this->iniSettings = [];
    }

    private function cleanupLocaleSettings(): void
    {
        foreach ($this->locale as $category => $locale) {
            setlocale($category, $locale);
        }

        $this->locale = [];
    }

    /**
     * @throws Exception
     */
    private function shouldExceptionExpectationsBeVerified(Throwable $throwable): bool
    {
        $result = false;

        if ($this->expectedException !== null || $this->expectedExceptionCode !== null || $this->expectedExceptionMessage !== null || $this->expectedExceptionMessageRegExp !== null) {
            $result = true;
        }

        if ($throwable instanceof Exception) {
            $result = false;
        }

        if (is_string($this->expectedException)) {
            try {
                $reflector = new ReflectionClass($this->expectedException);
                // @codeCoverageIgnoreStart
            } catch (ReflectionException $e) {
                throw new Exception(
                    $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
            // @codeCoverageIgnoreEnd

            if ($this->expectedException === 'PHPUnit\Framework\Exception' ||
                $this->expectedException === '\PHPUnit\Framework\Exception' ||
                $reflector->isSubclassOf(Exception::class)) {
                $result = true;
            }
        }

        return $result;
    }

    private function shouldRunInSeparateProcess(): bool
    {
        if ($this->inIsolation) {
            return false;
        }

        if ($this->runTestInSeparateProcess) {
            return true;
        }

        if ($this->runClassInSeparateProcess) {
            return true;
        }

        return ConfigurationRegistry::get()->processIsolation();
    }

    private function isCallableTestMethod(string $dependency): bool
    {
        [$className, $methodName] = explode('::', $dependency);

        if (!class_exists($className)) {
            return false;
        }

        $class = new ReflectionClass($className);

        if (!$class->isSubclassOf(__CLASS__)) {
            return false;
        }

        if (!$class->hasMethod($methodName)) {
            return false;
        }

        return TestUtil::isTestMethod(
            $class->getMethod($methodName)
        );
    }

    /**
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $originalClassName
     *
     * @psalm-return MockObject&RealInstanceType
     */
    private function createMockObject(string $originalClassName, bool $register = true): MockObject
    {
        return $this->getMockBuilder($originalClassName)
                    ->disableOriginalConstructor()
                    ->disableOriginalClone()
                    ->disableArgumentCloning()
                    ->disallowMockingUnknownTypes()
                    ->getMock($register);
    }

    /**
     * @throws ExpectationFailedException
     */
    private function performAssertionsOnOutput(): void
    {
        try {
            if ($this->outputExpectedRegex !== null) {
                $this->assertMatchesRegularExpression($this->outputExpectedRegex, $this->output);
            } elseif ($this->outputExpectedString !== null) {
                $this->assertEquals($this->outputExpectedString, $this->output);
            }
        } catch (ExpectationFailedException $e) {
            $this->status = TestStatus::failure($e->getMessage());

            Event\Facade::emitter()->testFailed(
                $this->valueObjectForEvents(),
                Event\Code\Throwable::from($e)
            );

            throw $e;
        }
    }

    private function invokeBeforeClassHookMethods(array $hookMethods, Event\Emitter $emitter): void
    {
        $this->invokeHookMethods(
            $hookMethods['beforeClass'],
            $emitter,
            'testBeforeFirstTestMethodCalled',
            'testBeforeFirstTestMethodFinished'
        );
    }

    private function invokeBeforeTestHookMethods(array $hookMethods, Event\Emitter $emitter): void
    {
        $this->invokeHookMethods(
            $hookMethods['before'],
            $emitter,
            'testBeforeTestMethodCalled',
            'testBeforeTestMethodFinished'
        );
    }

    private function invokePreConditionHookMethods(array $hookMethods, Event\Emitter $emitter): void
    {
        $this->invokeHookMethods(
            $hookMethods['preCondition'],
            $emitter,
            'testPreConditionCalled',
            'testPreConditionFinished'
        );
    }

    private function invokePostConditionHookMethods(array $hookMethods, Event\Emitter $emitter): void
    {
        $this->invokeHookMethods(
            $hookMethods['postCondition'],
            $emitter,
            'testPostConditionCalled',
            'testPostConditionFinished'
        );
    }

    private function invokeAfterTestHookMethods(array $hookMethods, Event\Emitter $emitter): void
    {
        $this->invokeHookMethods(
            $hookMethods['after'],
            $emitter,
            'testAfterTestMethodCalled',
            'testAfterTestMethodFinished'
        );
    }

    private function invokeAfterClassHookMethods(array $hookMethods, Event\Emitter $emitter): void
    {
        $this->invokeHookMethods(
            $hookMethods['afterClass'],
            $emitter,
            'testAfterLastTestMethodCalled',
            'testAfterLastTestMethodFinished'
        );
    }

    /**
     * @psalm-param list<non-empty-string> $hookMethods
     * @psalm-param 'testBeforeFirstTestMethodCalled'|'testBeforeTestMethodCalled'|'testPreConditionCalled'|'testPostConditionCalled'|'testAfterTestMethodCalled'|'testAfterLastTestMethodCalled' $calledMethod
     * @psalm-param 'testBeforeFirstTestMethodFinished'|'testBeforeTestMethodFinished'|'testPreConditionFinished'|'testPostConditionFinished'|'testAfterTestMethodFinished'|'testAfterLastTestMethodFinished' $finishedMethod
     */
    private function invokeHookMethods(array $hookMethods, Event\Emitter $emitter, string $calledMethod, string $finishedMethod): void
    {
        $methodsInvoked = [];

        foreach ($hookMethods as $methodName) {
            if ($this->methodDoesNotExistOrIsDeclaredInTestCase($methodName)) {
                continue;
            }

            $this->{$methodName}();

            $methodInvoked = new Event\Code\ClassMethod(
                static::class,
                $methodName
            );

            $emitter->{$calledMethod}(
                static::class,
                $methodInvoked
            );

            $methodsInvoked[] = $methodInvoked;
        }

        if (!empty($methodsInvoked)) {
            $emitter->{$finishedMethod}(
                static::class,
                ...$methodsInvoked
            );
        }
    }

    private function methodDoesNotExistOrIsDeclaredInTestCase(string $methodName): bool
    {
        $reflector = new ReflectionObject($this);

        return !$reflector->hasMethod($methodName) ||
               $reflector->getMethod($methodName)->getDeclaringClass()->getName() === self::class;
    }

    private function verifyExceptionExpectations(Throwable|\Exception $exception): void
    {
        if ($this->expectedException !== null) {
            $this->assertThat(
                $exception,
                new ExceptionConstraint(
                    $this->expectedException
                )
            );
        }

        if ($this->expectedExceptionMessage !== null) {
            $this->assertThat(
                $exception->getMessage(),
                new MessageIsOrContains(
                    'exception',
                    $this->expectedExceptionMessage
                )
            );
        }

        if ($this->expectedExceptionMessageRegExp !== null) {
            $this->assertThat(
                $exception->getMessage(),
                new MessageMatchesRegularExpression(
                    'exception',
                    $this->expectedExceptionMessageRegExp
                )
            );
        }

        if ($this->expectedExceptionCode !== null) {
            $this->assertThat(
                $exception,
                new ExceptionCode(
                    $this->expectedExceptionCode
                )
            );
        }
    }

    /**
     * @throws AssertionFailedError
     */
    private function expectedExceptionWasNotRaised(): void
    {
        if ($this->expectedException !== null) {
            $this->assertThat(
                null,
                new ExceptionConstraint($this->expectedException)
            );
        } elseif ($this->expectedExceptionMessage !== null) {
            $this->numberOfAssertionsPerformed++;

            throw new AssertionFailedError(
                sprintf(
                    'Failed asserting that exception with message "%s" is thrown',
                    $this->expectedExceptionMessage
                )
            );
        } elseif ($this->expectedExceptionMessageRegExp !== null) {
            $this->numberOfAssertionsPerformed++;

            throw new AssertionFailedError(
                sprintf(
                    'Failed asserting that exception with message matching "%s" is thrown',
                    $this->expectedExceptionMessageRegExp
                )
            );
        } elseif ($this->expectedExceptionCode !== null) {
            $this->numberOfAssertionsPerformed++;

            throw new AssertionFailedError(
                sprintf(
                    'Failed asserting that exception with code "%s" is thrown',
                    $this->expectedExceptionCode
                )
            );
        }
    }
}
