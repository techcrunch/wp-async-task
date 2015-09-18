<?php
/**
 * Plugin Name: WP Asynchronous Tasks
 * Version: 1.0
 * Description: Creates an abstract class to execute asynchronous tasks
 * Author: 10up, Eric Mann, Luke Gedeon, John P. Bloch
 * License: MIT
 */

if ( ! class_exists( 'WP_Async_Task' ) ) {
	abstract class WP_Async_Task {

		/**
		 * Constant identifier for a task that should be available to logged-in users
		 *
		 * See constructor documentation for more details.
		 */
		const LOGGED_IN = 1;

		/**
		 * Constant identifier for a task that should be available to logged-out users
		 *
		 * See constructor documentation for more details.
		 */
		const LOGGED_OUT = 2;

		/**
		 * Constant identifier for a task that should be available to all users regardless of auth status
		 *
		 * See constructor documentation for more details.
		 */
		const BOTH = 3;

		/**
		 * This is the argument count for the main action set in the constructor. It
		 * is set to an arbitrarily high value of twenty, but can be overridden if
		 * necessary
		 *
		 * @var int
		 */
		protected $argument_count = 20;

		/**
		 * Priority to fire intermediate action.
		 *
		 * @var int
		 */
		protected $priority = 10;

		/**
		 * @var string
		 */
		protected $action;

		/**
		 * @var array
		 */
		protected $_body_data;

		/**
		 * Constructor to wire up the necessary actions
		 *
		 * Which hooks the asynchronous postback happens on can be set by the
		 * $auth_level parameter. There are essentially three options: logged in users
		 * only, logged out users only, or both. Set this when you instantiate an
		 * object by using one of the three class constants to do so:
		 *  - LOGGED_IN
		 *  - LOGGED_OUT
		 *  - BOTH
		 * $auth_level defaults to BOTH
		 *
		 * @throws Exception If the class' $action value hasn't been set
		 *
		 * @param int $auth_level The authentication level to use (see above)
		 */
		public function __construct( $auth_level = self::BOTH ) {
			if ( empty( $this->action ) ) {
				throw new Exception( 'Action not defined for class ' . __CLASS__ );
			}
			add_action( $this->action, array( $this, 'launch' ), (int) $this->priority, (int) $this->argument_count );
			if ( $auth_level & self::LOGGED_IN ) {
				add_action( "admin_post_wp_async_$this->action", array( $this, 'handle_postback' ) );
			}
			if ( $auth_level & self::LOGGED_OUT ) {
				add_action( "admin_post_nopriv_wp_async_$this->action", array( $this, 'handle_postback' ) );
			}
		}

		/**
		 * Add the shutdown action for launching the real postback if we don't
		 * get an exception thrown by prepare_data().
		 *
		 * @uses func_get_args() To grab any arguments passed by the action
		 */
		public function launch() {
			$data = func_get_args();
			try {
				$data = $this->prepare_data( $data );
			} catch ( Exception $e ) {
				return;
			}

			$data['action'] = "wp_async_$this->action";
			$data['_nonce'] = $this->create_async_nonce();

			$this->_body_data = $data;

			if ( ! has_action( 'shutdown', array( $this, 'launch_on_shutdown' ) ) ) {
				add_action( 'shutdown', array( $this, 'launch_on_shutdown' ) );
			}
		}

		/**
		 * Launch the request on the WordPress shutdown hook
		 *
		 * On VIP we got into data races due to the postback sometimes completing
		 * faster than the data could propogate to the database server cluster.
		 * This made WordPress get empty data sets from the database without
		 * failing. On their advice, we're moving the actual firing of the async
		 * postback to the shutdown hook. Supposedly that will ensure that the
		 * data at least has time to get into the object cache.
		 *
		 * @uses $_COOKIE        To send a cookie header for async postback
		 * @uses apply_filters()
		 * @uses admin_url()
		 * @uses wp_remote_post()
		 */
		public function launch_on_shutdown() {
			if ( ! empty( $this->_body_data ) ) {
				$cookies = array();
				foreach ( $_COOKIE as $name => $value ) {
					$cookies[] = "$name=" . urlencode( is_array( $value ) ? serialize( $value ) : $value );
				}

				$request_args = array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
					'body'      => $this->_body_data,
					'headers'   => array(
						'cookie' => implode( '; ', $cookies ),
					),
				);

				$url = admin_url( 'admin-post.php' );

				wp_remote_post( $url, $request_args );
			}
		}

		/**
		 * Verify the postback is valid, then fire any scheduled events.
		 *
		 * @uses $_POST['_nonce']
		 * @uses is_user_logged_in()
		 * @uses add_filter()
		 * @uses wp_die()
		 */
		public function handle_postback() {
			if ( isset( $_POST['_nonce'] ) && $this->verify_async_nonce( $_POST['_nonce'] ) ) {
				if ( ! is_user_logged_in() ) {
					$this->action = "nopriv_$this->action";
				}
				$this->run_action();
			}

			add_filter( 'wp_die_handler', function() { die(); } );
			wp_die();
		}

		/**
		 * Create a random, one time use token.
		 *
		 * Based entirely on wp_create_nonce() but does not tie the nonce to the
		 * current logged-in user.
		 *
		 * @uses wp_nonce_tick()
		 * @uses wp_hash()
		 *
		 * @return string The one-time use token
		 */
		protected function create_async_nonce() {
			$action = $this->get_nonce_action();
			$i      = wp_nonce_tick();

			return substr( wp_hash( $i . $action . get_class( $this ), 'nonce' ), - 12, 10 );
		}

		/**
		 * Verify that the correct nonce was used within the time limit.
		 *
		 * @uses wp_nonce_tick()
		 * @uses wp_hash()
		 *
		 * @param string $nonce Nonce to be verified
		 *
		 * @return bool Whether the nonce check passed or failed
		 */
		protected function verify_async_nonce( $nonce ) {
			$action = $this->get_nonce_action();
			$i      = wp_nonce_tick();

			// Nonce generated 0-12 hours ago
			if ( substr( wp_hash( $i . $action . get_class( $this ), 'nonce' ), - 12, 10 ) == $nonce ) {
				return 1;
			}

			// Nonce generated 12-24 hours ago
			if ( substr( wp_hash( ( $i - 1 ) . $action . get_class( $this ), 'nonce' ), - 12, 10 ) == $nonce ) {
				return 2;
			}

			// Invalid nonce
			return false;
		}

		/**
		 * Get a nonce action based on the $action property of the class
		 *
		 * @return string The nonce action for the current instance
		 */
		protected function get_nonce_action() {
			$action = $this->action;
			if ( substr( $action, 0, 7 ) === 'nopriv_' ) {
				$action = substr( $action, 7 );
			}
			$action = "wp_async_$action";
			return $action;
		}

		/**
		 * Prepare any data to be passed to the asynchronous postback
		 *
		 * The array this function receives will be a numerically keyed array from
		 * func_get_args(). It is expected that you will return an associative array
		 * so that the $_POST values used in the asynchronous call will make sense.
		 *
		 * The array you send back may or may not have anything to do with the data
		 * passed into this method. It all depends on the implementation details and
		 * what data is needed in the asynchronous postback.
		 *
		 * Do not set values for 'action' or '_nonce', as those will get overwritten
		 * later in launch().
		 *
		 * @throws Exception If the postback should not occur for any reason
		 *
		 * @param array $data The raw data received by the launch method
		 *
		 * @return array The prepared data
		 */
		abstract protected function prepare_data( $data );

		/**
		 * Run the do_action function for the asynchronous postback.
		 *
		 * This method needs to fetch and sanitize any and all data from the $_POST
		 * superglobal and provide them to the do_action call.
		 *
		 * The action should be constructed as "wp_async_task_$this->action"
		 */
		abstract protected function run_action();

	}

}

