<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/activity_data.php';

/**
 * Guards the controller/data separation (item #22): the data loader must
 * always return a well-formed shape and degrade gracefully when the DB is
 * unavailable (as in CI without a database), rather than throwing.
 */
final class ActivityDataTest extends TestCase
{
    public function testReturnsWellFormedShapeWithoutDatabase(): void
    {
        $data = activity_page_data(1, 1, 50);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('totalPages', $data);
        $this->assertIsArray($data['rows']);
        $this->assertGreaterThanOrEqual(1, $data['page']);
        $this->assertGreaterThanOrEqual(1, $data['totalPages']);
    }

    public function testPageIsClampedToAtLeastOne(): void
    {
        $data = activity_page_data(1, -5, 50);
        $this->assertSame(1, $data['page']);
    }
}
