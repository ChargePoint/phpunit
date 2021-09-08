<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Event\Test;

use const PHP_EOL;
use function sprintf;
use PHPUnit\Event\Code;
use PHPUnit\Event\Event;
use PHPUnit\Event\Telemetry;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for PHPUnit
 */
final class SkippedDueToMissingDependency implements Event
{
    private Telemetry\Info $telemetryInfo;

    private Code\TestMethod $testMethod;

    private string $message;

    public function __construct(Telemetry\Info $telemetryInfo, Code\TestMethod $testMethod, string $message)
    {
        $this->telemetryInfo = $telemetryInfo;
        $this->testMethod    = $testMethod;
        $this->message       = $message;
    }

    public function telemetryInfo(): Telemetry\Info
    {
        return $this->telemetryInfo;
    }

    public function testMethod(): Code\TestMethod
    {
        return $this->testMethod;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function asString(): string
    {
        return sprintf(
            'Test Skipped Due To Missing Dependency (%s)%s%s',
            $this->testMethod->id(),
            PHP_EOL,
            $this->message
        );
    }
}