<?php
use PHPUnit\Framework\TestCase;

/**
 * Lint guard (item #21): asserts every tracked PHP file is free of syntax
 * errors. This is the DB-free surrogate for a full page-boot smoke test — it
 * catches the most common breakage (a typo taking down a page) in CI without
 * needing a database or web server.
 */
final class LintTest extends TestCase
{
    public function testAllPhpFilesParse(): void
    {
        $root = dirname(__DIR__);
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        $errors = [];
        $checked = 0;
        foreach ($rii as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php') continue;
            if (strpos($path, '/vendor/') !== false) continue;
            $checked++;
            $out = [];
            $code = 0;
            exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $errors[] = $path . ': ' . implode(' ', $out);
            }
        }
        $this->assertGreaterThan(0, $checked, 'expected to lint some PHP files');
        $this->assertSame([], $errors, "PHP syntax errors:\n" . implode("\n", $errors));
    }
}
