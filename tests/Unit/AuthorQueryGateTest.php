<?php

/**
 * Pins the template_redirect callback uamswp_rue_block_author_query().
 *
 * It ends with `exit` after the redirect, and a mutation that removes a guard would make
 * an in-process call end the test runner. Every case therefore runs in a fresh process,
 * through tests/Support/author-query-in-child.php.
 */

declare(strict_types=1);

/**
 * Runs the callback in a child process.
 *
 * @param  array<string, mixed>  $get  The value of $_GET in the child.
 * @return array{actions: list<array<int, mixed>>, redirects: list<array{string, int}>, returned: bool}
 */
function uamswp_rue_test_author_query(array $get, bool $loggedIn): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Support/author-query-in-child.php', json_encode(['get' => $get, 'logged_in' => $loggedIn], JSON_THROW_ON_ERROR)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    expect($process)->not->toBeFalse('The child PHP process did not start.');

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, "The child exited {$status}: {$stderr}{$stdout}");

    /** @var array{actions: list<array<int, mixed>>, redirects: list<array{string, int}>, returned: bool} */
    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

it('does nothing for a logged-in caller, with or without an author key', function (array $get): void {
    $report = uamswp_rue_test_author_query($get, true);

    expect($report)->toBe(['actions' => [], 'redirects' => [], 'returned' => true]);
})->with([
    'author present' => [['author' => '1']],
    'author absent' => [[]],
]);

it('does nothing for an anonymous request with no author key', function (array $get): void {
    $report = uamswp_rue_test_author_query($get, false);

    expect($report)->toBe(['actions' => [], 'redirects' => [], 'returned' => true]);
})->with([
    'empty query' => [[]],
    'other keys only' => [['s' => 'x', 'authors' => '1', 'Author' => '1']],
    'null value (isset is false)' => [['author' => null]],
]);

it('announces the block, redirects to the home URL with a 301 and ends the request', function (array $get): void {
    $report = uamswp_rue_test_author_query($get, false);

    expect($report['actions'])->toBe([['uamswp_rue_blocked', 'author_query', null]])
        ->and($report['redirects'])->toBe([['https://example.test/', 301]])
        ->and($report['returned'])->toBeFalse();
})->with([
    'numeric' => [['author' => '1']],
    'empty value' => [['author' => '']],
    'non-numeric' => [['author' => 'admin']],
    'array value' => [['author' => ['1']]],
    'with other keys' => [['s' => 'x', 'author' => '7']],
]);
