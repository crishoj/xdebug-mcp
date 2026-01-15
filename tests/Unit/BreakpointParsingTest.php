<?php

declare(strict_types=1);

namespace Koriym\XdebugMcp\Tests\Unit;

use Koriym\XdebugMcp\BreakpointParser;
use Koriym\XdebugMcp\InvalidBreakpointFormatException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Tests for breakpoint parsing functionality
 */
class BreakpointParsingTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        // Create a temporary test file for line breakpoint tests
        $this->testFile = tempnam(sys_get_temp_dir(), 'bp_test_') . '.php';
        file_put_contents($this->testFile, "<?php\necho 'line 2';\necho 'line 3';\necho 'line 4';\necho 'line 5';\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testParseCallBreakpoint(): void
    {
        $result = BreakpointParser::parse('call:myFunction');

        $this->assertCount(1, $result);
        $this->assertEquals('call', $result[0]['type']);
        $this->assertEquals('myFunction', $result[0]['function']);
    }

    public function testParseCallBreakpointWithClassMethod(): void
    {
        $result = BreakpointParser::parse('call:MyClass::myMethod');

        $this->assertCount(1, $result);
        $this->assertEquals('call', $result[0]['type']);
        $this->assertEquals('MyClass::myMethod', $result[0]['function']);
    }

    public function testParseReturnBreakpoint(): void
    {
        $result = BreakpointParser::parse('return:calculate');

        $this->assertCount(1, $result);
        $this->assertEquals('return', $result[0]['type']);
        $this->assertEquals('calculate', $result[0]['function']);
    }

    public function testParseReturnBreakpointWithClassMethod(): void
    {
        $result = BreakpointParser::parse('return:Calculator::add');

        $this->assertCount(1, $result);
        $this->assertEquals('return', $result[0]['type']);
        $this->assertEquals('Calculator::add', $result[0]['function']);
    }

    public function testParseExceptionBreakpoint(): void
    {
        $result = BreakpointParser::parse('exception:RuntimeException');

        $this->assertCount(1, $result);
        $this->assertEquals('exception', $result[0]['type']);
        $this->assertEquals('RuntimeException', $result[0]['exception']);
    }

    public function testParseExceptionBreakpointWildcard(): void
    {
        $result = BreakpointParser::parse('exception:*');

        $this->assertCount(1, $result);
        $this->assertEquals('exception', $result[0]['type']);
        $this->assertEquals('*', $result[0]['exception']);
    }

    public function testParseLineBreakpoint(): void
    {
        $result = BreakpointParser::parse($this->testFile . ':3');

        $this->assertCount(1, $result);
        $this->assertEquals('line', $result[0]['type']);
        $this->assertEquals(3, $result[0]['line']);
        $this->assertNull($result[0]['condition']);
    }

    public function testParseLineBreakpointWithCondition(): void
    {
        $result = BreakpointParser::parse($this->testFile . ':3:$x==null');

        $this->assertCount(1, $result);
        $this->assertEquals('line', $result[0]['type']);
        $this->assertEquals(3, $result[0]['line']);
        $this->assertEquals('$x==null', $result[0]['condition']);
    }

    public function testParseMixedBreakpoints(): void
    {
        $result = BreakpointParser::parse($this->testFile . ':2,call:doSomething,exception:*,return:process');

        $this->assertCount(4, $result);

        // Line breakpoint
        $this->assertEquals('line', $result[0]['type']);
        $this->assertEquals(2, $result[0]['line']);

        // Call breakpoint
        $this->assertEquals('call', $result[1]['type']);
        $this->assertEquals('doSomething', $result[1]['function']);

        // Exception breakpoint
        $this->assertEquals('exception', $result[2]['type']);
        $this->assertEquals('*', $result[2]['exception']);

        // Return breakpoint
        $this->assertEquals('return', $result[3]['type']);
        $this->assertEquals('process', $result[3]['function']);
    }

    public function testParseCallBreakpointEmptyFunctionThrows(): void
    {
        $this->expectException(InvalidBreakpointFormatException::class);
        $this->expectExceptionMessage('function name required');

        BreakpointParser::parse('call:');
    }

    public function testParseReturnBreakpointEmptyFunctionThrows(): void
    {
        $this->expectException(InvalidBreakpointFormatException::class);
        $this->expectExceptionMessage('function name required');

        BreakpointParser::parse('return:');
    }

    public function testParseExceptionBreakpointEmptyClassThrows(): void
    {
        $this->expectException(InvalidBreakpointFormatException::class);
        $this->expectExceptionMessage('exception class required');

        BreakpointParser::parse('exception:');
    }

    public function testParseInvalidFormatThrows(): void
    {
        $this->expectException(InvalidBreakpointFormatException::class);

        BreakpointParser::parse('invalid_format_no_colon');
    }

    public function testParseNonExistentFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Breakpoint file not found');

        BreakpointParser::parse('/nonexistent/file.php:10');
    }

    public function testParseCallBreakpointWithNamespacedClass(): void
    {
        $result = BreakpointParser::parse('call:App\\Services\\UserService::authenticate');

        $this->assertCount(1, $result);
        $this->assertEquals('call', $result[0]['type']);
        $this->assertEquals('App\\Services\\UserService::authenticate', $result[0]['function']);
    }

    public function testParseMultipleCallBreakpoints(): void
    {
        $result = BreakpointParser::parse('call:func1,call:func2,call:Class::method');

        $this->assertCount(3, $result);
        $this->assertEquals('call', $result[0]['type']);
        $this->assertEquals('func1', $result[0]['function']);
        $this->assertEquals('call', $result[1]['type']);
        $this->assertEquals('func2', $result[1]['function']);
        $this->assertEquals('call', $result[2]['type']);
        $this->assertEquals('Class::method', $result[2]['function']);
    }

    public function testParseDockerCommandSkipsLocalValidation(): void
    {
        // When isDockerCommand is true, local file validation should be skipped
        $result = BreakpointParser::parse('/app/script.php:10', true);

        $this->assertCount(1, $result);
        $this->assertEquals('line', $result[0]['type']);
        $this->assertEquals('/app/script.php', $result[0]['file']);
        $this->assertEquals(10, $result[0]['line']);
    }

    public function testParseEmptySpecReturnsEmptyArray(): void
    {
        $result = BreakpointParser::parse('');

        $this->assertCount(0, $result);
    }

    public function testParseOnlyCommasReturnsEmptyArray(): void
    {
        $result = BreakpointParser::parse(',,,');

        $this->assertCount(0, $result);
    }

    public function testParseWithWhitespace(): void
    {
        $result = BreakpointParser::parse('  call:myFunction  ,  return:other  ');

        $this->assertCount(2, $result);
        $this->assertEquals('myFunction', $result[0]['function']);
        $this->assertEquals('other', $result[1]['function']);
    }
}
