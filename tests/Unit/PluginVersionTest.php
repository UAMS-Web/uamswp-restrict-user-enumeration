<?php

/**
 * Holds the plugin's version in one place: the header.
 */

declare(strict_types=1);

/**
 * The plugin file's source.
 */
function uamswp_rue_test_plugin_source(): string
{
    $source = file_get_contents(dirname(__DIR__, 2).'/uamswp-restrict-user-enumeration.php');

    expect($source)->toBeString();

    return (string) $source;
}

/**
 * Reads a header the way `get_file_data()` does: the first 8 KB, one
 * case-insensitive, multiline pattern per header.
 */
function uamswp_rue_test_header(string $source, string $header): string
{
    $head = str_replace("\r", "\n", substr($source, 0, 8192));

    $matched = preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*'.preg_quote($header, '/').':(.*)$/mi', $head, $match);

    expect($matched)->toBe(1, "The {$header} header is missing from the first 8 KB.");

    return trim($match[1]);
}

it('names the repository in the Plugin URI header', function (): void {
    expect(uamswp_rue_test_header(uamswp_rue_test_plugin_source(), 'Plugin URI'))
        ->toBe('https://github.com/UAMS-Web/uamswp-restrict-user-enumeration');
});

it('writes the version header as a three-segment release number', function (): void {
    expect(uamswp_rue_test_header(uamswp_rue_test_plugin_source(), 'Version'))->toMatch('/^\d+\.\d+\.\d+$/');
});

it('reads the version constant from the header', function (): void {
    expect(uamswp_rue_test_plugin_source())->toContain(
        "define('UAMSWP_RUE_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);"
    );
});

it('holds no version literal define', function (): void {
    expect(preg_match('/define\(\s*[\'"]UAMSWP_RUE_VERSION[\'"]\s*,\s*[\'"][^\'"]*[\'"]/', uamswp_rue_test_plugin_source()))
        ->toBe(0);
});
