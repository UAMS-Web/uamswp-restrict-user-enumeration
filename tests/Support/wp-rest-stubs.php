<?php

/**
 * The smallest stand-ins for the WordPress REST classes the plugin's gates touch.
 *
 * The Unit suite has no WordPress, so a test that builds a request or checks a
 * returned error uses these. Each is declared only when no other definition
 * exists, so the file can be required from any test or child process.
 */

declare(strict_types=1);

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function __construct(private string $route = '/')
        {
        }

        public function get_route(): string
        {
            return $this->route;
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private array $data = [],
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function __construct(public array $data = [], public int $status = 200)
        {
        }
    }
}
