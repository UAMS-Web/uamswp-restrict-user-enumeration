<?php

/**
 * First unit test pinning harness load for UAMSWP Restrict User Enumeration.
 */

declare(strict_types=1);

namespace UamswpRestrictUserEnumeration\Tests\Unit;

use UamswpRestrictUserEnumeration\Tests\Support\UnitTestCase;

final class HarnessSmokeTest extends UnitTestCase
{
    /**
     * @return void
     */
    public function test_harness_loads_plugin_surface(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).'/uamswp-restrict-user-enumeration.php');
        $this->assertTrue(true);
    }
}
