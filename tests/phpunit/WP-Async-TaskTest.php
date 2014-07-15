<?php

use WP_Mock\Tools\TestCase;

class WP_Async_Task_Tests extends TestCase {

	/**
	 * Set up some test mocks
	 */
	public function setUp() {
		parent::setUp();
		$_COOKIE = array();
		$_POST   = array();
	}

	public function tearDown() {
		parent::tearDown();
		$_COOKIE = array();
		$_POST   = array();
	}

	/**
	 * Test that the correct actions are registered based on the auth level
	 */
	public function test_auth_level_both() {
		$async = new Async( false );

		WP_Mock::expectActionAdded( 'async', array( $async, 'launch' ), 10, 20 );
		WP_Mock::expectActionAdded( 'admin_post_wp_async_async', array( $async, 'handle_postback' ) );
		WP_Mock::expectActionAdded( 'admin_post_nopriv_wp_async_async', array( $async, 'handle_postback' ) );

		$async->__construct( WP_Async_Task::BOTH );

		$this->assertConditionsMet();
	}

	/**
	 * Test that the correct actions are registered based on the auth level
	 */
	public function test_auth_level_logged_in_only() {
		$async = new Async( false );

		WP_Mock::expectActionAdded( 'async', array( $async, 'launch' ), 10, 20 );
		WP_Mock::expectActionAdded( 'admin_post_wp_async_async', array( $async, 'handle_postback' ) );

		$async->__construct( WP_Async_Task::LOGGED_IN );

		$this->assertConditionsMet();
	}

	/**
	 * Test that the correct actions are registered based on the auth level
	 */
	public function test_auth_level_logged_out_only() {
		$async = new Async( false );

		WP_Mock::expectActionAdded( 'async', array( $async, 'launch' ), 10, 20 );
		WP_Mock::expectActionAdded( 'admin_post_nopriv_wp_async_async', array( $async, 'handle_postback' ) );

		$async->__construct( WP_Async_Task::LOGGED_OUT );

		$this->assertConditionsMet();
	}

	/**
	 * Test that the constructor throws an Exception if action is undefined
	 *
	 * @expectedException \Exception
	 */
	public function test_empty_action() {
		new EmptyAsync();
	}

	/**
	 * Test that throwing an Exception in prepare_data stops a postback from firing
	 */
	public function test_exception_stops_launch_sequence() {
		$async = $this->getMockAsync( 'Async', array( 'prepare_data', 'create_async_nonce' ) );
		$arg1  = rand( 0, 9 );
		$arg2  = rand( 10, 99 );
		$async->shouldReceive( 'prepare_data' )
			->once()
			->with( array( $arg1, $arg2 ) )
			->andThrow( 'Exception' );
		$async->shouldReceive( 'create_async_nonce' )->never();
		/** @var Async $async */
		$async->launch( $arg1, $arg2 );
		$this->assertConditionsMet();
	}

	/**
	 * Test that launch sets the correct action and _nonce values in the body
	 */
	public function test_launch() {
		$async = $this->getMockAsync( 'Async', array( 'prepare_data', 'create_async_nonce' ) );
		$arg   = 'arg' . rand( 0, 9 );
		$async->shouldReceive( 'prepare_data' )
			->once()
			->with( array( $arg ) )
			->andReturn( array( 'foo' => $arg ) );
		$nonce = substr( md5( 'async' . rand( 0, 9 ) ), - 12, 10 );
		$async->shouldReceive( 'create_async_nonce' )
			->once()
			->with()
			->andReturn( $nonce );
		$body_data = new ReflectionProperty( 'Async', '_body_data' );
		$body_data->setAccessible( true );

		WP_Mock::wpFunction( 'has_action', array(
			'times'  => 1,
			'args'   => array( 'shutdown', array( $async, 'launch_on_shutdown' ) ),
			'return' => false,
		) );

		WP_Mock::expectActionAdded( 'shutdown', array( $async, 'launch_on_shutdown' ) );

		/** @var Async $async */
		$async->launch( $arg );

		$data = $body_data->getValue( $async );

		$this->assertArrayHasKey( 'action', $data );
		$this->assertEquals( 'wp_async_async', $data['action'] );
		$this->assertArrayHasKey( '_nonce', $data );
		$this->assertEquals( $nonce, $data['_nonce'] );

		$this->assertConditionsMet();
	}

	public function test_launch_on_shutdown() {
		$async = $this->getMockAsync( 'Async', array( 'prepare_data', 'create_async_nonce' ) );
		$async->shouldReceive( 'prepare_data' )->andReturn( array() );
		$async->shouldReceive( 'create_async_nonce' )->andReturn( 'asdf' );

		WP_Mock::wpFunction( 'maybe_serialize', array(
			'return' => function ( $thing ) {
					return is_scalar( $thing ) ? $thing : serialize( $thing );
				}
		) );

		$_COOKIE       = array(
			'_some_cookie' => 'Value',
			'foo'          => 'bar',
			'random'       => rand( 0, 999999 ),
			'array'        => array( 'not', 'scalar' ),
		);
		$cookie_header = '';
		array_walk( $_COOKIE, function ( $value, $key ) use ( &$cookie_header ) {
			if ( ! empty( $cookie_header ) ) {
				$cookie_header .= '; ';
			}
			if ( ! is_scalar( $value ) ) {
				$value = serialize( $value );
			}
			$cookie_header .= "$key=" . urlencode( $value );
		} );

		$verify_ssl = (bool) rand( 0, 1 );
		WP_Mock::onFilter( 'https_local_ssl_verify' )->with( true )->reply( $verify_ssl );

		WP_Mock::wpFunction( 'admin_url', array(
			'times'  => 1,
			'args'   => array( 'admin-post.php' ),
			'return' => $url = 'https://tctechcrunch2011.wordpress.com/wp-admin/admin-post.php'
		) );

		WP_Mock::wpFunction( 'wp_remote_post', array(
			'times' => 1,
			'args'  => array(
				$url,
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => $verify_ssl,
					'body'      => array(
						'action' => 'wp_async_async',
						'_nonce' => 'asdf',
					),
					'headers'   => array(
						'cookie' => $cookie_header,
					),
				)
			),
		) );

		/** @var Async $async */
		$async->launch(); // to set up body data, etc.
		$async->launch_on_shutdown();

		$this->assertConditionsMet();
	}

	public function test_launch_on_shutdown_empty_body() {
		WP_Mock::wpFunction( 'wp_remote_post', array( 'times' => 0, ) );
		/** @var Async $async */
		$async = $this->getMockAsync();
		$async->launch_on_shutdown();
		$this->assertConditionsMet();
	}

	public function test_handle_postback_nonce_not_set() {
		$async = $this->getMockAsync( 'Async', array( 'verify_async_nonce', 'run_action' ) );
		$async->shouldReceive( 'verify_async_nonce' )->never();
		$async->shouldReceive( 'run_action' )->never();
		WP_Mock::expectFilterAdded( 'wp_die_handler', function () {
			die();
		} );
		WP_Mock::wpFunction( 'wp_die', array( 'times' => 1 ) );

		/** @var Async $async */
		$async->handle_postback();

		$this->assertConditionsMet();
	}

	public function test_handle_postback_invalid_nonce() {
		$async           = $this->getMockAsync( 'Async', array( 'verify_async_nonce', 'run_action' ) );
		$nonce           = 'asdfasdf';
		$_POST['_nonce'] = $nonce;
		$async->shouldReceive( 'verify_async_nonce' )
			->once()
			->with( $nonce )
			->andReturn( false );
		$async->shouldReceive( 'run_action' )->never();
		WP_Mock::expectFilterAdded( 'wp_die_handler', function () {
			die();
		} );
		WP_Mock::wpFunction( 'wp_die', array( 'times' => 1 ) );

		/** @var Async $async */
		$async->handle_postback();

		$this->assertConditionsMet();
	}

	public function test_handle_postback_anon() {
		$async           = $this->getMockAsync( 'Async', array( 'verify_async_nonce', 'run_action' ) );
		$nonce           = 'asdfasdf';
		$_POST['_nonce'] = $nonce;
		$async->shouldReceive( 'verify_async_nonce' )
			->once()
			->with( $nonce )
			->andReturn( true );
		WP_Mock::wpFunction( 'is_user_logged_in', array( 'times' => 1, 'return' => false, ) );

		$async->shouldReceive( 'run_action' )
			->once()
			->with();
		WP_Mock::expectFilterAdded( 'wp_die_handler', function () {
			die();
		} );
		WP_Mock::wpFunction( 'wp_die', array( 'times' => 1 ) );

		/** @var Async $async */
		$async->handle_postback();

		$action = new ReflectionProperty( 'Async', 'action' );
		$action->setAccessible( true );
		$this->assertEquals( 'nopriv_async', $action->getValue( $async ) );

		$this->assertConditionsMet();
	}

	public function test_handle_postback() {
		$async           = $this->getMockAsync( 'Async', array( 'verify_async_nonce', 'run_action' ) );
		$nonce           = 'asdfasdf';
		$_POST['_nonce'] = $nonce;
		$async->shouldReceive( 'verify_async_nonce' )
			->once()
			->with( $nonce )
			->andReturn( true );
		WP_Mock::wpFunction( 'is_user_logged_in', array( 'times' => 1, 'return' => true, ) );
		$async->shouldReceive( 'run_action' )
			->once()
			->with();
		WP_Mock::expectFilterAdded( 'wp_die_handler', function () {
			die();
		} );
		WP_Mock::wpFunction( 'wp_die', array( 'times' => 1 ) );

		/** @var Async $async */
		$async->handle_postback();

		$action = new ReflectionProperty( 'Async', 'action' );
		$action->setAccessible( true );
		$this->assertEquals( 'async', $action->getValue( $async ) );

		$this->assertConditionsMet();
	}

	public function test_create_async_nonce() {
		$async      = $this->getMockAsync();
		$nonce_tick = rand( 10, 99 );
		WP_Mock::wpFunction( 'wp_nonce_tick', array(
			'times'  => 1,
			'args'   => array(),
			'return' => $nonce_tick,
		) );
		$create_nonce = new ReflectionMethod( 'Async', 'create_async_nonce' );
		$create_nonce->setAccessible( true );

		$expected_hash = md5( $nonce_tick . 'wp_async_async' . get_class( $async ) );

		WP_Mock::wpFunction( 'wp_hash', array(
			'times'  => 1,
			'args'   => array( $nonce_tick . 'wp_async_async' . get_class( $async ), 'nonce' ),
			'return' => $expected_hash,
		) );

		$this->assertEquals( substr( $expected_hash, - 12, 10 ), $create_nonce->invoke( $async ) );
		$this->assertConditionsMet();
	}

	public function test_verify_async_nonce_invalid() {
		$async      = $this->getMockAsync();
		$nonce_tick = rand( 10, 99 );
		WP_Mock::wpFunction( 'wp_nonce_tick', array(
			'times'  => 1,
			'args'   => array(),
			'return' => $nonce_tick,
		) );
		$verify_nonce = new ReflectionMethod( 'Async', 'verify_async_nonce' );
		$verify_nonce->setAccessible( true );
		WP_Mock::wpFunction( 'wp_hash', array(
			'times'  => 2,
			'return' => md5( rand( 100, 999 ) ),
		) );

		$this->assertFalse( $verify_nonce->invoke( $async, md5( $nonce_tick ) ) );
		$this->assertConditionsMet();
	}

	public function test_verify_async_nonce_recent() {
		$async      = $this->getMockAsync();
		$nonce_tick = rand( 10, 99 );
		WP_Mock::wpFunction( 'wp_nonce_tick', array(
			'times'  => 1,
			'args'   => array(),
			'return' => $nonce_tick,
		) );
		$verify_nonce = new ReflectionMethod( 'Async', 'verify_async_nonce' );
		$verify_nonce->setAccessible( true );
		$hash  = md5( $nonce_tick . 'wp_async_async' . get_class( $async ) );
		$nonce = substr( $hash, - 12, 10 );
		WP_Mock::wpFunction( 'wp_hash', array(
			'times'  => 1,
			'args'   => array( $nonce_tick . 'wp_async_async' . get_class( $async ), 'nonce' ),
			'return' => function ( $thing ) {
					return md5( $thing );
				}
		) );

		$this->assertSame( 1, $verify_nonce->invoke( $async, $nonce ) );
		$this->assertConditionsMet();
	}

	public function test_verify_async_nonce_old_but_valid() {
		$async      = $this->getMockAsync();
		$nonce_tick = rand( 10, 99 );
		$real_tick  = $nonce_tick - 1;
		WP_Mock::wpFunction( 'wp_nonce_tick', array(
			'times'  => 1,
			'args'   => array(),
			'return' => $nonce_tick,
		) );
		$verify_nonce = new ReflectionMethod( 'Async', 'verify_async_nonce' );
		$verify_nonce->setAccessible( true );
		$hash  = md5( $real_tick . 'wp_async_async' . get_class( $async ) );
		$nonce = substr( $hash, - 12, 10 );
		WP_Mock::wpFunction( 'wp_hash', array(
			'times'  => 1,
			'args'   => array( $nonce_tick . 'wp_async_async' . get_class( $async ), 'nonce' ),
			'return' => function ( $thing ) {
					return md5( $thing );
				}
		) );
		WP_Mock::wpFunction( 'wp_hash', array(
			'times'  => 1,
			'args'   => array( $real_tick . 'wp_async_async' . get_class( $async ), 'nonce' ),
			'return' => function ( $thing ) {
					return md5( $thing );
				}
		) );

		$this->assertSame( 2, $verify_nonce->invoke( $async, $nonce ) );
		$this->assertConditionsMet();
	}

	public function test_verify_async_nonce_nopriv() {
		$async      = $this->getMockAsync();
		$nonce_tick = rand( 10, 99 );
		WP_Mock::wpFunction( 'wp_nonce_tick', array(
			'times'  => 1,
			'args'   => array(),
			'return' => $nonce_tick,
		) );
		$action = new ReflectionProperty( 'Async', 'action' );
		$action->setAccessible( true );
		$action->setValue( $async, 'nopriv_async' );
		$verify_nonce = new ReflectionMethod( 'Async', 'verify_async_nonce' );
		$verify_nonce->setAccessible( true );
		$hash  = md5( $nonce_tick . 'wp_async_async' . get_class( $async ) );
		$nonce = substr( $hash, - 12, 10 );
		WP_Mock::wpFunction( 'wp_hash', array(
			'times'  => 1,
			'args'   => array( $nonce_tick . 'wp_async_async' . get_class( $async ), 'nonce' ),
			'return' => function ( $thing ) {
					return md5( $thing );
				}
		) );

		$this->assertSame( 1, $verify_nonce->invoke( $async, $nonce ) );
		$this->assertConditionsMet();
	}

	/**
	 * Get a mock object for the async task class
	 *
	 * @param string $class   The name of the class to mock
	 * @param array  $methods Which methods to mock
	 * @param mixed  $auth    The auth level to simulate
	 *
	 * @return \Mockery\Mock
	 */
	private function getMockAsync( $class = 'Async', array $methods = array(), $auth = false ) {
		$stub = '';
		if ( ! empty( $methods ) ) {
			$stub = '[' . implode( ',', $methods ) . ']';
		}
		$mockClass = "$class$stub";
		/** @var \Mockery\Mock $mock */
		$mock = Mockery::mock( $mockClass, array( $auth ) );
		$mock->makePartial();
		$mock->shouldAllowMockingProtectedMethods();
		return $mock;
	}

}
