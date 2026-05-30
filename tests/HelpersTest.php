<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DB-free helpers in lib/helpers.php (item #21).
 */
final class HelpersTest extends TestCase
{
    public function testHEscapesHtml(): void
    {
        $this->assertSame('&lt;script&gt;', h('<script>'));
        $this->assertSame('a &amp; b', h('a & b'));
        $this->assertSame('&quot;x&quot;', h('"x"'));
        $this->assertSame('', h(null));
    }

    public function testUserInitials(): void
    {
        $this->assertSame('JD', user_initials('John Doe'));
        $this->assertSame('AN', user_initials('anton'));      // single word -> first two letters
        $this->assertSame('?', user_initials('   '));
        $this->assertSame('ML', user_initials('Maria Garcia Lopez')); // first word + last word
    }

    public function testFormatDate(): void
    {
        $this->assertSame('2026-05-30', format_date('2026-05-30 14:00:00'));
        $this->assertSame('', format_date(null));
        $this->assertSame('', format_date(''));
    }

    public function testCsrfTokenIsStableWithinSession(): void
    {
        $_SESSION = [];
        $a = csrf_token();
        $b = csrf_token();
        $this->assertSame($a, $b, 'csrf_token must be stable across calls');
        $this->assertSame(32, strlen($a), 'token is 16 random bytes hex-encoded');
    }

    public function testAppLogWritesFile(): void
    {
        $logFile = dirname(__DIR__) . '/storage/logs/app.log';
        @unlink($logFile);
        app_log('unit-test-context', new RuntimeException('boom'), ['k' => 'v']);
        $this->assertFileExists($logFile);
        $contents = (string)file_get_contents($logFile);
        $this->assertStringContainsString('unit-test-context', $contents);
        $this->assertStringContainsString('boom', $contents);
    }

    public function testAppLogNeverThrows(): void
    {
        // Should be a no-op-safe call even with no exception.
        app_log('context-only');
        $this->assertTrue(true);
    }
}
