<?php

declare(strict_types=1);

namespace Koriym\XdebugMcp;

use Koriym\XdebugMcp\Exceptions\InvalidArgumentException;
use Koriym\XdebugMcp\Exceptions\InvalidLineException;
use RuntimeException;

use function array_filter;
use function array_map;
use function basename;
use function count;
use function explode;
use function file;
use function file_exists;
use function getcwd;
use function preg_match;
use function realpath;
use function str_starts_with;
use function trim;

use const FILE_IGNORE_NEW_LINES;

/**
 * Parse breakpoint specifications for xstep debugger
 */
final class BreakpointParser
{
    /**
     * Parse breakpoint specifications
     * Format: file.php:line[:condition][,file2.php:line2,...]
     *         call:functionName
     *         return:functionName
     *         exception:ExceptionClass (or exception:* for all exceptions)
     *
     * @param string $breakSpec       Breakpoint specification string
     * @param bool   $isDockerCommand If true, skip local file validation (files are in container)
     *
     * @return list<array{type: string, file?: string, line?: int, condition?: string|null, function?: string, exception?: string}>
     *
     * @throws InvalidBreakpointFormatException
     * @throws RuntimeException
     * @throws InvalidLineException
     */
    public static function parse(string $breakSpec, bool $isDockerCommand = false): array
    {
        $breakpoints = [];
        $specs = array_filter(array_map('trim', explode(',', $breakSpec)));

        foreach ($specs as $spec) {
            if ($spec === '') {
                continue;
            }

            // Check for special breakpoint types: call:, return:, exception:
            if (preg_match('/^(call|return):(.*)$/', $spec, $m)) {
                $breakpoint = self::parseCallOrReturnBreakpoint($m[1], $m[2]);
                if ($breakpoint !== null) {
                    $breakpoints[] = $breakpoint;

                    continue;
                }
            }

            if (preg_match('/^exception:(.*)$/', $spec, $m)) {
                $breakpoint = self::parseExceptionBreakpoint($m[1]);
                if ($breakpoint !== null) {
                    $breakpoints[] = $breakpoint;

                    continue;
                }
            }

            // Parse line breakpoint: file:line[:condition]
            $breakpoint = self::parseLineBreakpoint($spec, $isDockerCommand);
            $breakpoints[] = $breakpoint;
        }

        return $breakpoints;
    }

    /**
     * Parse call or return breakpoint
     *
     * @return array{type: string, function: string}|null
     *
     * @throws InvalidBreakpointFormatException
     */
    private static function parseCallOrReturnBreakpoint(string $type, string $functionPart): array|null
    {
        $function = trim($functionPart);
        if ($function === '') {
            throw new InvalidBreakpointFormatException(
                "Invalid {$type} breakpoint: function name required\n" .
                "Use: {$type}:functionName or {$type}:ClassName::methodName",
            );
        }

        return [
            'type' => $type,
            'function' => $function,
        ];
    }

    /**
     * Parse exception breakpoint
     *
     * @return array{type: string, exception: string}|null
     *
     * @throws InvalidBreakpointFormatException
     */
    private static function parseExceptionBreakpoint(string $exceptionPart): array|null
    {
        $exception = trim($exceptionPart);
        if ($exception === '') {
            throw new InvalidBreakpointFormatException(
                "Invalid exception breakpoint: exception class required\n" .
                "Use: exception:ExceptionClass or exception:* for all exceptions",
            );
        }

        return [
            'type' => 'exception',
            'exception' => $exception,
        ];
    }

    /**
     * Parse line breakpoint
     *
     * @return array{type: string, file: string, line: int, condition: string|null}
     *
     * @throws InvalidBreakpointFormatException
     * @throws RuntimeException
     * @throws InvalidLineException
     */
    private static function parseLineBreakpoint(string $spec, bool $isDockerCommand): array
    {
        // Parse file:line[:condition] (supports Windows paths and conditions with colons)
        if (! preg_match('/^(.*):(\d+)(?::(.*))?$/', $spec, $m)) {
            throw new InvalidBreakpointFormatException(
                "Invalid breakpoint format: {$spec}\n" .
                "Use: file.php:line or file.php:line:condition\n" .
                "     call:functionName or return:functionName\n" .
                "     exception:ExceptionClass or exception:*\n" .
                "Examples:\n" .
                "  - script.php:42\n" .
                "  - script.php:42:\$user==null\n" .
                "  - call:MyClass::process\n" .
                "  - return:calculate\n" .
                "  - exception:InvalidArgumentException\n" .
                '  - file1.php:10,call:doSomething,exception:*',
            );
        }

        $file = $m[1];
        $line = (int) $m[2];
        $condition = $m[3] ?? null;

        // For Docker commands, skip local file validation - the path is inside the container
        if ($isDockerCommand) {
            if ($line <= 0) {
                throw new InvalidLineException("Invalid line number: {$line} in {$file}");
            }

            return [
                'type' => 'line',
                'file' => $file,
                'line' => $line,
                'condition' => $condition,
            ];
        }

        // Try to resolve file path - convert to absolute path for Xdebug
        $resolvedFile = self::resolveFilePath($file);

        if ($resolvedFile === null) {
            throw new RuntimeException("Breakpoint file not found: {$file} (searched current dir and common paths)");
        }

        $file = $resolvedFile;

        if ($line <= 0) {
            throw new InvalidLineException("Invalid line number: {$line} in {$file}");
        }

        // Validate line number against actual file content
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new InvalidArgumentException("Could not read file: {$file}");
        }

        $maxLine = count($lines);
        if ($line > $maxLine) {
            throw new InvalidLineException("Line {$line} does not exist in {$file} (file has {$maxLine} lines)");
        }

        return [
            'type' => 'line',
            'file' => $file,
            'line' => $line,
            'condition' => $condition,
        ];
    }

    /**
     * Resolve file path to absolute path
     */
    private static function resolveFilePath(string $file): string|null
    {
        if (file_exists($file)) {
            return realpath($file) ?: null;
        }

        if (! str_starts_with($file, '/')) {
            $cwd = getcwd();
            if ($cwd !== false && file_exists($cwd . '/' . $file)) {
                return realpath($cwd . '/' . $file) ?: null;
            }
        }

        // Try common project paths
        $commonPaths = [
            $file,
            './' . $file,
            'tests/fake/' . basename($file),
        ];

        foreach ($commonPaths as $testPath) {
            if (file_exists($testPath)) {
                return realpath($testPath) ?: null;
            }
        }

        return null;
    }
}
