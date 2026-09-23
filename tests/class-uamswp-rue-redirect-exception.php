<?php
/**
 * Carries an intercepted redirect out of the hook that made it.
 *
 * uamswp_rue_block_author_query() ends in wp_safe_redirect() then exit, and
 * exit would take the test runner with it. Throwing from the wp_redirect
 * filter unwinds out of wp_safe_redirect() before exit is reached, which is
 * how the WordPress core canonical tests handle the same problem.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

class UAMSWP_RUE_Redirect_Exception extends Exception {

	/** @var string */
	public $location;

	/** @var int */
	public $status;

	/**
	 * @param string $location Location header the code under test asked for.
	 * @param int    $status   HTTP status it asked for.
	 */
	public function __construct( $location, $status ) {
		$this->location = $location;
		$this->status   = (int) $status;
		parent::__construct( "Redirect to {$location} ({$status})" );
	}

	/**
	 * Catch the next redirect instead of letting it exit the process.
	 *
	 * @return void
	 */
	public static function intercept() {
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new self( $location, $status );
			},
			10,
			2
		);
	}
}
