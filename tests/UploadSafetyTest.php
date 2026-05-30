<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for upload-hardening helpers in lib/task_attachments.php (items #4, #21).
 */
final class UploadSafetyTest extends TestCase
{
    public function testDangerousExtensionsBlocked(): void
    {
        foreach (['php', 'PHP', 'phtml', 'phar', 'svg', 'js', 'exe', 'sh', 'htaccess'] as $ext) {
            $this->assertTrue(upload_is_dangerous_ext($ext), "$ext must be blocked");
            $this->assertTrue(upload_is_dangerous_ext('.' . $ext), ".$ext must be blocked");
        }
    }

    public function testSafeExtensionsAllowed(): void
    {
        foreach (['png', 'jpg', 'pdf', 'docx', 'xlsx', 'zip', 'txt', 'csv'] as $ext) {
            $this->assertFalse(upload_is_dangerous_ext($ext), "$ext should be allowed");
        }
    }

    public function testDangerousMimesBlocked(): void
    {
        $this->assertTrue(upload_is_dangerous_mime('text/html'));
        $this->assertTrue(upload_is_dangerous_mime('image/svg+xml'));
        $this->assertTrue(upload_is_dangerous_mime('application/x-httpd-php'));
        $this->assertFalse(upload_is_dangerous_mime('image/png'));
        $this->assertFalse(upload_is_dangerous_mime(null));
    }

    public function testIniBytesParsing(): void
    {
        $this->assertSame(1024, ini_bytes('1k'));
        $this->assertSame(1024 * 1024, ini_bytes('1M'));
        $this->assertSame(2 * 1024 * 1024 * 1024, ini_bytes('2G'));
        $this->assertSame(500, ini_bytes('500'));
        $this->assertSame(0, ini_bytes(''));
    }

    public function testHumanBytes(): void
    {
        $this->assertSame('1 KB', human_bytes(1024));
        $this->assertSame('1 MB', human_bytes(1024 * 1024));
        $this->assertSame('512 B', human_bytes(512));
    }
}
