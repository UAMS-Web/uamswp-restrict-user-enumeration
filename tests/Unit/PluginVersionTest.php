<?php

/**
 * Holds the plugin's version in one place: the header.
 */

declare(strict_types=1);

namespace UamswpRestrictUserEnumeration\Tests\Unit;

use UamswpRestrictUserEnumeration\Tests\Support\UnitTestCase;

final class PluginVersionTest extends UnitTestCase
{
    /**
     * @return string
     */
    private function pluginSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/uamswp-restrict-user-enumeration.php');

        $this->assertIsString($source);

        return $source;
    }

    /**
     * Reads a header the way `get_file_data()` does: the first 8 KB, one
     * case-insensitive, multiline pattern per header.
     *
     * @param  string  $source
     * @param  string  $header
     * @return string
     */
    private function header(string $source, string $header): string
    {
        $head = str_replace("\r", "\n", substr($source, 0, 8192));

        $matched = preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*'.preg_quote($header, '/').':(.*)$/mi', $head, $match);

        $this->assertSame(1, $matched, "The {$header} header is missing from the first 8 KB.");

        return trim($match[1]);
    }

    /**
     * @return void
     */
    public function test_plugin_uri_header_names_the_repository(): void
    {
        $this->assertSame(
            'https://github.com/UAMS-Web/uamswp-restrict-user-enumeration',
            $this->header($this->pluginSource(), 'Plugin URI')
        );
    }

    /**
     * @return void
     */
    public function test_version_header_is_a_three_segment_release_number(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->header($this->pluginSource(), 'Version'));
    }

    /**
     * @return void
     */
    public function test_constant_is_read_from_the_header(): void
    {
        $this->assertStringContainsString(
            "define('UAMSWP_RUE_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);",
            $this->pluginSource()
        );
    }

    /**
     * @return void
     */
    public function test_file_holds_no_version_literal_define(): void
    {
        $this->assertSame(
            0,
            preg_match('/define\(\s*[\'"]UAMSWP_RUE_VERSION[\'"]\s*,\s*[\'"][^\'"]*[\'"]/', $this->pluginSource())
        );
    }
}
