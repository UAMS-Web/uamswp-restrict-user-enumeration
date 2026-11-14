<?php

/**
 * Pins the users sitemap filter and the oEmbed author filter.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

describe('uamswp_rue_remove_users_sitemap', function (): void {
    it('drops the users provider', function (): void {
        expect(uamswp_rue_remove_users_sitemap(new stdClass, 'users'))->toBeFalse();
    });

    it('returns any other provider as it was given', function (string $name): void {
        $provider = new stdClass;

        expect(uamswp_rue_remove_users_sitemap($provider, $name))->toBe($provider);
    })->with([
        'posts',
        'taxonomies',
        'users-extra',
        'Users',
        'user',
        '',
    ]);

    it('returns false unchanged for another provider name when no provider was given', function (): void {
        expect(uamswp_rue_remove_users_sitemap(false, 'posts'))->toBeFalse();
    });
});

describe('uamswp_rue_scrub_oembed_author', function (): void {
    it('returns a value that is not an array as it is', function (mixed $input): void {
        Functions\expect('get_bloginfo')->never();
        Functions\expect('home_url')->never();

        expect(uamswp_rue_scrub_oembed_author($input))->toBe($input);
    })->with([
        'false' => [false],
        'null' => [null],
        'string' => ['not an array'],
    ]);

    it('returns an ArrayAccess object untouched, though isset would find its author keys', function (): void {
        // The only input where the is_array guard matters: for false, null and strings isset() is
        // false anyway, but an ArrayAccess object would be rewritten without the guard.
        Functions\expect('get_bloginfo')->never();
        Functions\expect('home_url')->never();
        $data = new ArrayObject(['author_name' => 'jane.doe', 'author_url' => 'https://example.test/author/jane-doe/']);

        expect(uamswp_rue_scrub_oembed_author($data))->toBe($data)
            ->and($data->getArrayCopy())->toBe(['author_name' => 'jane.doe', 'author_url' => 'https://example.test/author/jane-doe/']);
    });

    it('replaces the author name and URL with the site name and home URL', function (): void {
        Functions\expect('get_bloginfo')->once()->with('name')->andReturn('Example Site');
        Functions\expect('home_url')->once()->with('/')->andReturn('https://example.test/');

        $data = [
            'version' => '1.0',
            'title' => 'A post',
            'author_name' => 'jane.doe',
            'author_url' => 'https://example.test/author/jane-doe/',
            'html' => '<blockquote></blockquote>',
        ];

        expect(uamswp_rue_scrub_oembed_author($data))->toBe([
            'version' => '1.0',
            'title' => 'A post',
            'author_name' => 'Example Site',
            'author_url' => 'https://example.test/',
            'html' => '<blockquote></blockquote>',
        ]);
    });

    it('replaces only the author name when the URL is absent, and adds no key', function (): void {
        Functions\expect('get_bloginfo')->once()->with('name')->andReturn('Example Site');
        Functions\expect('home_url')->never();

        expect(uamswp_rue_scrub_oembed_author(['author_name' => 'jane.doe', 'title' => 'A post']))
            ->toBe(['author_name' => 'Example Site', 'title' => 'A post']);
    });

    it('replaces only the author URL when the name is absent, and adds no key', function (): void {
        Functions\expect('get_bloginfo')->never();
        Functions\expect('home_url')->once()->with('/')->andReturn('https://example.test/');

        expect(uamswp_rue_scrub_oembed_author(['author_url' => 'https://example.test/author/jane-doe/', 'title' => 'A post']))
            ->toBe(['author_url' => 'https://example.test/', 'title' => 'A post']);
    });

    it('leaves a response with neither author key unchanged', function (): void {
        Functions\expect('get_bloginfo')->never();
        Functions\expect('home_url')->never();
        $data = ['version' => '1.0', 'title' => 'A post'];

        expect(uamswp_rue_scrub_oembed_author($data))->toBe($data);
    });

    it('leaves an empty array empty', function (): void {
        Functions\expect('get_bloginfo')->never();
        Functions\expect('home_url')->never();

        expect(uamswp_rue_scrub_oembed_author([]))->toBe([]);
    });

    it('leaves an author key holding null alone, because isset is false for null', function (): void {
        Functions\expect('get_bloginfo')->never();
        Functions\expect('home_url')->never();
        $data = ['author_name' => null, 'author_url' => null];

        expect(uamswp_rue_scrub_oembed_author($data))->toBe($data);
    });

    it('replaces an author key holding an empty string', function (): void {
        Functions\expect('get_bloginfo')->once()->with('name')->andReturn('Example Site');
        Functions\expect('home_url')->once()->with('/')->andReturn('https://example.test/');

        expect(uamswp_rue_scrub_oembed_author(['author_name' => '', 'author_url' => '']))
            ->toBe(['author_name' => 'Example Site', 'author_url' => 'https://example.test/']);
    });
});
