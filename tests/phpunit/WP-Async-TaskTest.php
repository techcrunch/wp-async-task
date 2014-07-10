<?php

class WP_Async_Task_Tests extends PHPUnit_Framework_TestCase {

	/**
	 * Set up some test mocks
	 */
	public function setUp() {
		WordPress_Mocker::cleanup();
		WordPress_Mocker::register_handler( 'wp_nonce_tick', function () {
			return 1;
		} );
		WordPress_Mocker::register_handler( 'wp_hash', function ( $string ) {
			return md5( $string );
		} );
	}

	/**
	 * Clean up after the test
	 */
	public function tearDown() {
		WordPress_Mocker::cleanup();
	}

	/**
	 * Test that the correct actions are registered based on the auth level
	 */
	public function test_auth_level() {
		$hooks = array();
		WordPress_Mocker::register_handler( 'add_action', function ( $hook, $func ) use ( &$hooks ) {
			$hooks[$hook] = $func;
		} );
		$both     = new Async();
		$expected = array(
			'async'                            => array( $both, 'launch' ),
			'admin_post_wp_async_async'        => array( $both, 'handle_postback' ),
			'admin_post_nopriv_wp_async_async' => array( $both, 'handle_postback' ),
		);
		$this->assertEquals( $expected, $hooks );

		$hooks     = array();
		$logged_in = new Async( WP_Async_Task::LOGGED_IN );
		$expected  = array(
			'async'                     => array( $logged_in, 'launch' ),
			'admin_post_wp_async_async' => array( $logged_in, 'handle_postback' ),
		);
		$this->assertEquals( $expected, $hooks );

		$hooks      = array();
		$logged_out = new Async( WP_Async_Task::LOGGED_OUT );
		$expected   = array(
			'async'                            => array( $logged_out, 'launch' ),
			'admin_post_nopriv_wp_async_async' => array( $logged_out, 'handle_postback' ),
		);
		$this->assertEquals( $expected, $hooks );
	}

	/**
	 * Test that the constructor throws an Exception if action is undefined
	 */
	public function test_empty_action() {
		try {
			new No_Action();
			$this->assertTrue( false );
		} catch ( Exception $e ) {
			$this->assertTrue( true );
		}
	}

	/**
	 * Test that throwing an Exception in prepare_data stops a postback from firing
	 */
	public function test_exception_stops_launch_sequence() {
		WordPress_Mocker::register_handler( 'Async::prepare_data', function () {
			throw new Exception;
		} );
		$add_action_shutdown_called = false;
		$async                      = new Async();
		WordPress_Mocker::register_handler( 'add_action', function ( $hook, $callback ) use ( &$add_action_shutdown_called, $async ) {
			$add_action_shutdown_called = ( $hook === 'shutdown' && $callback === array( $async, 'launch_on_shutdown' ) );
		} );
		$async->launch();
		$this->assertFalse( $add_action_shutdown_called );
	}

	/**
	 * Test that launch sets the correct action and _nonce values in the body
	 */
	public function test_action_and_nonce_set() {
		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return false;
		} );
		WordPress_Mocker::register_handler( 'Async::prepare_data', function () {
			return array(
				'foo' => 'Bar',
				'baz' => 'Bat'
			);
		} );
		$remote_body = array();
		WordPress_Mocker::register_handler( 'wp_remote_post', function ( $url, $data ) use ( &$remote_body ) {
			$remote_body = $data['body'];
		} );
		$async = new Async();
		$async->launch();
		$async->launch_on_shutdown();
		$expected_hash = substr( md5( '1wp_async_async' ), - 12, 10 );

		$this->assertArrayHasKey( 'action', $remote_body );
		$this->assertEquals( 'wp_async_async', $remote_body['action'] );
		$this->assertArrayHasKey( '_nonce', $remote_body );
		$this->assertEquals( $expected_hash, $remote_body['_nonce'] );
	}

	/**
	 * Test that the wp_remote_post request is non-blocking
	 */
	public function test_is_non_blocking() {
		$remote_params = array();
		WordPress_Mocker::register_handler( 'wp_remote_post', function ( $url, $data ) use ( &$remote_params ) {
			$remote_params = $data;
		} );
		$async = new Async();
		$async->launch();
		$async->launch_on_shutdown();
		$this->assertEquals( 0.01, $remote_params['timeout'] );
		$this->assertFalse( $remote_params['blocking'] );
	}

	/**
	 * Test that the cookie array gets passed to wp_remote_post
	 */
	public function test_cookies_sent() {
		$remote_headers = array();
		WordPress_Mocker::register_handler( 'wp_remote_post', function ( $url, $data ) use ( &$remote_headers ) {
			$remote_headers = isset( $data['headers'] ) ? $data['headers'] : array();
		} );

		$old_cookies = $_COOKIE;
		$_COOKIE     = array(
			'_some_cookie' => 'Value',
			'foo'          => 'bar',
			'random'       => rand( 0, 999999 ),
		);

		$expected = array();
		foreach ( $_COOKIE as $name => $value ) {
			$expected[] = "$name=" . urlencode( $value );
		}

		$async = new Async();
		$async->launch();
		$async->launch_on_shutdown();

		$this->assertArrayHasKey( 'cookie', $remote_headers );
		$this->assertEquals( implode( '; ', $expected ), $remote_headers['cookie'] );

		$_COOKIE = $old_cookies;
	}

	/**
	 * Test that launch sends the postback to the admin-post.php file
	 */
	public function test_admin_postback_url_used() {
		$target_url = '';
		WordPress_Mocker::register_handler( 'wp_remote_post', function ( $url ) use ( &$target_url ) {
			$target_url = $url;
		} );
		WordPress_Mocker::register_handler( 'admin_url', function ( $uri ) {
			return $uri;
		} );

		$async = new Async();
		$async->launch();
		$async->launch_on_shutdown();

		$this->assertEquals( 'admin-post.php', $target_url );
	}

	/**
	 * Test that no nonce or a bad nonce prevent handle_postback from executing run_action
	 */
	public function test_postback_with_bad_nonces() {
		$old_post = $_POST;
		$_POST    = array();

		$run_action = false;
		WordPress_Mocker::register_handler( 'Async::run_action', function () use ( &$run_action ) {
			$run_action = true;
		} );

		$async = new Async();
		$async->handle_postback();

		$this->assertFalse( $run_action );

		$_POST['_nonce'] = md5( 'failing_nonce' );

		$async->handle_postback();

		$this->assertFalse( $run_action );

		$_POST = $old_post;
	}

	/**
	 * Test that handle_postback adds nopriv_ to the beginning of the action if
	 * the user is logged out
	 */
	public function test_nopriv_added_to_action() {
		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return true;
		} );

		$old_post = $_POST;
		$_POST    = array(
			'_nonce' => substr( md5( '1wp_async_async' ), - 12, 10 ),
		);

		$action = new ReflectionProperty( 'Async', 'action' );
		$action->setAccessible( true );

		$async = new Async();
		$async->handle_postback();

		$this->assertEquals( 'async', $action->getValue( $async ) );

		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return false;
		} );
		$async->handle_postback();

		$this->assertEquals( 'nopriv_async', $action->getValue( $async ) );

		$_POST = $old_post;
	}

	/**
	 * Test that run_action does get executed under the correct circumstances
	 */
	public function test_run_action_executed() {
		$run_action = false;
		WordPress_Mocker::register_handler( 'Async::run_action', function () use ( &$run_action ) {
			$run_action = true;
		} );

		$old_post = $_POST;
		$_POST    = array(
			'_nonce' => substr( md5( '1wp_async_async' ), - 12, 10 ),
		);

		$async = new Async();
		$async->handle_postback();

		$this->assertTrue( $run_action );

		$_POST = $old_post;
	}

	/**
	 * Test that script execution gets killed by wp_die when handle_postback is
	 * run, regardless of whether run_action executed
	 */
	public function test_execution_killed() {
		$killed = false;
		WordPress_Mocker::register_handler( 'wp_die', function () use ( &$killed ) {
			$killed = true;
		} );

		$old_post = $_POST;
		$_POST    = array(
			'_nonce' => substr( md5( '1wp_async_async' ), - 12, 10 ),
		);

		$async = new Async();
		$async->handle_postback();

		$this->assertTrue( $killed );

		$killed = false;
		$_POST  = array();
		$async->handle_postback();

		$this->assertTrue( $killed );

		$_POST = $old_post;
	}

	/**
	 * Test that the create_async_nonce and verify_async_nonce methods use the
	 * core WordPress functions wp_create_nonce and wp_verify_nonce if the user
	 * is logged in.
	 */
	public function test_core_nonces_used() {
		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return true;
		} );

		$core_function_used  = false;
		$core_nonce_callback = function () use ( &$core_function_used ) {
			$core_function_used = true;
		};

		WordPress_Mocker::register_handler( 'wp_create_nonce', $core_nonce_callback );
		WordPress_Mocker::register_handler( 'wp_verify_nonce', $core_nonce_callback );

		$async = new Async();

		$create_nonce = new ReflectionMethod( 'Async', 'create_async_nonce' );
		$create_nonce->setAccessible( true );
		$create_nonce->invoke( $async );

		$this->assertTrue( $core_function_used );

		$core_function_used = false;

		$verify_nonce = new ReflectionMethod( 'Async', 'verify_async_nonce' );
		$verify_nonce->setAccessible( true );
		$verify_nonce->invoke( $async, '' );

		$this->assertTrue( $core_function_used );
	}

	/**
	 * Test that nonces created from different seeds are, in fact, different.
	 */
	public function test_nonce_unique_actions() {
		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return false;
		} );
		$async_handler = new Async();

		// Get a handle to the private method
		$create_nonce = new ReflectionMethod(
			'Async',
			'create_async_nonce'
		);
		$create_nonce->setAccessible( true );

		// Create two nonces from different action seeds
		$first  = $create_nonce->invoke( $async_handler, 'first' );
		$second = $create_nonce->invoke( $async_handler, 'second' );

		$this->assertNotEquals( $first, $second );
	}

	/**
	 * Test that nonces created from the same seed are, in fact, the same.
	 */
	public function test_nonce_same_actions() {
		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return false;
		} );
		$async_handler = new Async();

		// Get a handle to the private method
		$create_nonce = new ReflectionMethod(
			'Async',
			'create_async_nonce'
		);
		$create_nonce->setAccessible( true );

		// Create two nonces from the same action seed
		$first  = $create_nonce->invoke( $async_handler, 'seed' );
		$second = $create_nonce->invoke( $async_handler, 'seed' );

		$this->assertEquals( $first, $second );
	}

	/**
	 * Test that a nonce verifies against the seed that created it.
	 */
	public function test_old_nonce_verification() {
		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return false;
		} );
		$async_handler = new Async();

		// Get a handle to the private method
		$create_nonce = new ReflectionMethod(
			'Async',
			'create_async_nonce'
		);
		$create_nonce->setAccessible( true );

		$verify_nonce = new ReflectionMethod(
			'Async',
			'verify_async_nonce'
		);
		$verify_nonce->setAccessible( true );

		$nonce = $create_nonce->invoke( $async_handler, 'seed' );

		// Increment the tick by 1 so we verify against an old nonce
		WordPress_Mocker::register_handler( 'wp_nonce_tick', function() { return 2; } );

		$this->assertEquals( 2, $verify_nonce->invoke( $async_handler, $nonce, 'seed' ) );
	}

	/**
	 * Test that a nonce verifies against the seed that created it.
	 */
	public function test_nonce_verification() {
		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return false;
		} );
		$async_handler = new Async();

		// Get a handle to the private method
		$create_nonce = new ReflectionMethod(
			'Async',
			'create_async_nonce'
		);
		$create_nonce->setAccessible( true );

		$verify_nonce = new ReflectionMethod(
			'Async',
			'verify_async_nonce'
		);
		$verify_nonce->setAccessible( true );

		$nonce = $create_nonce->invoke( $async_handler, 'seed' );

		$this->assertEquals( 1, $verify_nonce->invoke( $async_handler, $nonce, 'seed' ) );
	}

	/**
	 * Test that a nonce is rejected if it doesn't match the seed.
	 */
	public function test_nonce_rejection() {
		WordPress_Mocker::register_handler( 'is_user_logged_in', function () {
			return false;
		} );
		$async_handler = new Async();

		// Get a handle to the private method
		$create_nonce = new ReflectionMethod(
			'Async',
			'create_async_nonce'
		);
		$create_nonce->setAccessible( true );

		$verify_nonce = new ReflectionMethod(
			'Async',
			'verify_async_nonce'
		);
		$verify_nonce->setAccessible( true );

		$nonce = $create_nonce->invoke( $async_handler, 'seed' );

		$this->assertEquals( false, $verify_nonce->invoke( $async_handler, $nonce, 'differentseed' ) );
	}

}

class Async extends WP_Async_Task {
	protected $action = 'async';

	protected function prepare_data( $data ) {
		return (array) WordPress_Mocker::handle_function( 'Async::prepare_data', array( $data ) );
	}

	protected function run_action() {
		WordPress_Mocker::handle_function( 'Async::run_action' );
	}
}

class No_Action extends WP_Async_Task {
	protected function prepare_data( $data ) {
	}

	protected function run_action() {
	}
}

