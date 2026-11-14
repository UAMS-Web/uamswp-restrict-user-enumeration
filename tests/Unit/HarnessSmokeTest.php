<?php

/**
 * First unit test pinning harness load for UAMSWP Restrict User Enumeration.
 */

declare(strict_types=1);

it('loads the plugin surface', function (): void {
    expect(dirname(__DIR__, 2).'/uamswp-restrict-user-enumeration.php')->toBeFile();
});
