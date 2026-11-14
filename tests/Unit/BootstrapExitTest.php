<?php

/**
 * Pins that the Unit bootstrap fails, and says why, when the plugin file ends the request.
 */

declare(strict_types=1);

/**
 * Runs tests/bootstrap.php in a fresh process against another plugin file.
 *
 * @return array{status: int, stdout: string, stderr: string}
 */
function uamswp_rue_test_run_bootstrap(string $pluginFile): array
{
    $process = proc_open(
        [PHP_BINARY, '-r', "require '".dirname(__DIR__)."/bootstrap.php';"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['UAMSWP_RUE_TEST_PLUGIN_FILE' => $pluginFile, 'PATH' => (string) getenv('PATH')]
    );

    expect($process)->not->toBeFalse('The child PHP process did not start.');

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

it('fails and names the cause when the plugin file ends the request', function (): void {
    $run = uamswp_rue_test_run_bootstrap(dirname(__DIR__).'/Support/plugin-that-exits.php');

    expect($run['status'])->toBe(1)
        ->and($run['stderr'])->toContain('so no test ran');
});

it('passes silently when the plugin file loads and returns', function (): void {
    // Control for the case above: the same bootstrap, a file that returns.
    $run = uamswp_rue_test_run_bootstrap(dirname(__DIR__).'/Support/plugin-that-returns.php');

    expect($run['status'])->toBe(0)
        ->and($run['stderr'])->toBe('')
        ->and($run['stdout'])->toBe('');
});
