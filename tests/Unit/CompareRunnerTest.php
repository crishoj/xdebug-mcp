<?php

declare(strict_types=1);

namespace Koriym\XdebugMcp\Tests\Unit;

use Koriym\XdebugMcp\CompareRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CompareRunnerTest extends TestCase
{
    /**
     * @return mixed
     */
    private function invokeMethod(CompareRunner $runner, string $method, array $args = [])
    {
        $ref = new ReflectionClass($runner);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($runner, $args);
    }

    private function createRunner(): CompareRunner
    {
        return new CompareRunner([
            'break' => 'test.php:10',
            'run_a' => 'php test.php 1',
            'run_b' => 'php test.php 2',
        ]);
    }

    public function testComputeDiffWithChangedVariables(): void
    {
        $runner = $this->createRunner();

        $varsA = ['$x' => 'int: 10', '$y' => 'int: 20', '$name' => 'string: hello'];
        $varsB = ['$x' => 'int: 99', '$y' => 'int: 20', '$name' => 'string: world'];

        $diff = $this->invokeMethod($runner, 'computeDiff', [$varsA, $varsB]);

        $this->assertSame(['a' => 'int: 10', 'b' => 'int: 99'], $diff['changed']['$x']);
        $this->assertSame(['a' => 'string: hello', 'b' => 'string: world'], $diff['changed']['$name']);
        $this->assertSame(['$y'], $diff['unchanged']);
        $this->assertSame([], $diff['only_in_a']);
        $this->assertSame([], $diff['only_in_b']);
    }

    public function testComputeDiffWithOnlyInA(): void
    {
        $runner = $this->createRunner();

        $varsA = ['$x' => 'int: 10', '$extra' => 'string: only_a'];
        $varsB = ['$x' => 'int: 10'];

        $diff = $this->invokeMethod($runner, 'computeDiff', [$varsA, $varsB]);

        $this->assertSame([], $diff['changed']);
        $this->assertSame(['$x'], $diff['unchanged']);
        $this->assertSame(['$extra'], $diff['only_in_a']);
        $this->assertSame([], $diff['only_in_b']);
    }

    public function testComputeDiffWithOnlyInB(): void
    {
        $runner = $this->createRunner();

        $varsA = ['$x' => 'int: 10'];
        $varsB = ['$x' => 'int: 10', '$new_var' => 'string: only_b'];

        $diff = $this->invokeMethod($runner, 'computeDiff', [$varsA, $varsB]);

        $this->assertSame([], $diff['changed']);
        $this->assertSame(['$x'], $diff['unchanged']);
        $this->assertSame([], $diff['only_in_a']);
        $this->assertSame(['$new_var'], $diff['only_in_b']);
    }

    public function testComputeDiffWithEmptyVariables(): void
    {
        $runner = $this->createRunner();

        $diff = $this->invokeMethod($runner, 'computeDiff', [[], []]);

        $this->assertSame([], $diff['changed']);
        $this->assertSame([], $diff['unchanged']);
        $this->assertSame([], $diff['only_in_a']);
        $this->assertSame([], $diff['only_in_b']);
    }

    public function testComputeDiffWithCompletelyDifferentVariables(): void
    {
        $runner = $this->createRunner();

        $varsA = ['$a' => 'int: 1', '$b' => 'int: 2'];
        $varsB = ['$c' => 'int: 3', '$d' => 'int: 4'];

        $diff = $this->invokeMethod($runner, 'computeDiff', [$varsA, $varsB]);

        $this->assertSame([], $diff['changed']);
        $this->assertSame([], $diff['unchanged']);
        $this->assertSame(['$a', '$b'], $diff['only_in_a']);
        $this->assertSame(['$c', '$d'], $diff['only_in_b']);
    }

    public function testGenerateHints(): void
    {
        $runner = $this->createRunner();

        $diff = [
            'changed' => ['$x' => ['a' => 'int: 10', 'b' => 'int: 99']],
            'unchanged' => ['$y'],
            'only_in_a' => ['$extra'],
            'only_in_b' => [],
        ];
        $varsA = ['$x' => 'int: 10', '$y' => 'int: 20', '$extra' => 'string: test'];
        $varsB = ['$x' => 'int: 99', '$y' => 'int: 20'];

        $hints = $this->invokeMethod($runner, 'generateHints', [$diff, $varsA, $varsB]);

        $this->assertContains('$x: int: 10 → int: 99 (changed)', $hints);
        $this->assertContains('$extra: string: test (only in run_a)', $hints);
        $this->assertContains('1 variable(s) unchanged, 1 variable(s) changed, 3 total', $hints);
    }

    public function testParseBreakSpec(): void
    {
        $runner = $this->createRunner();

        $result = $this->invokeMethod($runner, 'parseBreakSpec', ['src/Calculator.php:25']);
        $this->assertSame(['file' => 'src/Calculator.php', 'line' => 25], $result);
    }

    public function testParseBreakSpecWithCondition(): void
    {
        $runner = $this->createRunner();

        $result = $this->invokeMethod($runner, 'parseBreakSpec', ['src/Calculator.php:25:$x>0']);
        $this->assertSame(['file' => 'src/Calculator.php', 'line' => 25], $result);
    }

    public function testExtractVariablesFromResult(): void
    {
        $runner = $this->createRunner();

        $result = [
            'breaks' => [
                [
                    'step' => 1,
                    'location' => ['file' => 'test.php', 'line' => 10],
                    'variables' => ['$x' => 'int: 10', '$y' => 'int: 20'],
                    'recording_type' => 'full',
                ],
            ],
        ];

        $vars = $this->invokeMethod($runner, 'extractVariables', [$result]);
        $this->assertSame(['$x' => 'int: 10', '$y' => 'int: 20'], $vars);
    }

    public function testExtractVariablesFromEmptyResult(): void
    {
        $runner = $this->createRunner();

        $vars = $this->invokeMethod($runner, 'extractVariables', [['breaks' => []]]);
        $this->assertSame([], $vars);
    }

    public function testExtractLocationFromResult(): void
    {
        $runner = $this->createRunner();

        $result = [
            'breaks' => [
                [
                    'location' => ['file' => 'src/test.php', 'line' => 42],
                    'variables' => [],
                ],
            ],
        ];

        $location = $this->invokeMethod($runner, 'extractLocation', [$result]);
        $this->assertSame(['file' => 'src/test.php', 'line' => 42], $location);
    }

    public function testExtractStatusBreak(): void
    {
        $runner = $this->createRunner();

        $result = [
            'breaks' => [
                ['location' => ['file' => 'test.php', 'line' => 1], 'variables' => []],
            ],
        ];

        $status = $this->invokeMethod($runner, 'extractStatus', [$result]);
        $this->assertSame('break', $status);
    }

    public function testExtractStatusNoBreak(): void
    {
        $runner = $this->createRunner();

        $status = $this->invokeMethod($runner, 'extractStatus', [['breaks' => []]]);
        $this->assertSame('no_break', $status);
    }
}
