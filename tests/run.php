<?php

/**
 * A test run without a package manager.
 *
 * The SDK has no runtime dependencies, and asking an integrator to install
 * Composer before they can see the tests pass would be a strange first
 * impression. `php tests/run.php` is enough; the suite is PHPUnit-shaped
 * enough to port later if that becomes worth doing.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'FourPay\\')) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('FourPay\\'))) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

// Exception and interface files declare more than one type or live beside their
// namesake; load them eagerly rather than teaching the autoloader exceptions.
foreach (glob(__DIR__ . '/../src/Exception/*.php') ?: [] as $file) {
    require_once $file;
}
require_once __DIR__ . '/../src/Webhooks.php';
require_once __DIR__ . '/../src/Agentic/AcpSessionStore.php';
require_once __DIR__ . '/../src/Agentic/Ap2.php';
require_once __DIR__ . '/../src/Agentic/Acp.php';

$GLOBALS['fourpay_tests'] = ['passed' => 0, 'failed' => []];

function test(string $name, callable $body): void
{
    try {
        $body();
        $GLOBALS['fourpay_tests']['passed']++;
        fwrite(STDOUT, "  ok  {$name}\n");
    } catch (Throwable $error) {
        $GLOBALS['fourpay_tests']['failed'][] = [$name, $error];
        fwrite(STDOUT, "  FAIL {$name}\n       " . $error->getMessage() . "\n");
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $message !== '' ? $message . ': ' : '',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertTrueValue(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(string $expectedClass, callable $body, ?string $messageContains = null): void
{
    try {
        $body();
    } catch (Throwable $error) {
        if (!($error instanceof $expectedClass)) {
            throw new RuntimeException(
                "expected {$expectedClass}, got " . $error::class . ': ' . $error->getMessage()
            );
        }
        if ($messageContains !== null && !str_contains($error->getMessage(), $messageContains)) {
            throw new RuntimeException(
                "expected message containing \"{$messageContains}\", got \"{$error->getMessage()}\""
            );
        }
        return;
    }
    throw new RuntimeException("expected {$expectedClass}, nothing was thrown");
}

require_once __DIR__ . '/support.php';

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    fwrite(STDOUT, basename($file) . "\n");
    require $file;
}

$passed = $GLOBALS['fourpay_tests']['passed'];
$failed = count($GLOBALS['fourpay_tests']['failed']);
fwrite(STDOUT, "\n{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
