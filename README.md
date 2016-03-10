# TechCrunch WP Asynchronous Tasks

TechCrunch WP Asynchronous Tasks plugin for TechCrunch.com

## Quick Start

WP Async Task can be installed as a plugin or bundled in other plugins or a theme. The class definition is wrapped in a `class_exists` check, so it will never run the risk of being accidentally defined twice. Just make sure that the plugin file is being included somehow.

Next, you need to extend the class with your own implementation. Implementations of the class act on an arbitrary action (e.g., `'save_post'`, etc). There are three parts that **must** be present in any class extending `WP_Async_Task`:

1. A protected `$action` property
2. A protected `prepare_data()` method
3. A protected `run_action()` method

```php
<?php

class JPB_Async_Task extends WP_Async_Task {

	protected $action = 'save_post';

	/**
	 * Prepare data for the asynchronous request
	 *
	 * @throws Exception If for any reason the request should not happen
	 *
	 * @param array $data An array of data sent to the hook
	 *
	 * @return array
	 */
	protected function prepare_data( $data ) {}

	/**
	 * Run the async task action
	 */
	protected function run_action() {}

}
```

#### `$action`

The protected `$action` property should be set to the action to which you wish to attach the asynchronous task. For example, if you want to spin off an asynchronous task whenever a post gets saved, you would set this to `save_post`.

#### `prepare_data( $data )`

Use this method to prepare the action's data for use in the asynchronous process. Data will be given to `prepare_data()` as an indexed array, just as it would if you used `func_get_args()` to get a function's arguments. This method needs to return an array containing the data in a more useful format. Since these values will be sent in a `POST` request, it's advisable to stick to scalar values for the most part. For example, on `'save_post'`, the action provides `$post_id` and the `$post` object, so we might do this:

```php
protected function prepare_data($data){
	$post_id = $data[0];
	return array( 'post_id' => $post_id );
}
```

If for any reason the asynchronous task needs to be canceled, you will need to throw an exception:

```php
protected function prepare_data($data){
	$post_id = $data[0];
	$post = $data[1];
	if( 'post' !== $post->post_type ) {
		throw new Exception( 'We only want async tasks for posts' );
	}
	return array( 'post_id' => $post_id );
}
```

The library will handle catching the exception and will prevent the request from running if it catches an Exception.

#### `run_action()`

This method is responsible for running whatever action should trigger the functions that need to run inside the asynchronous request. The convention is to use `"wp_async_$this->action"`, but that is up to the implementation.

```php
protected function run_action() {
	$post_id = $_POST['post_id'];
	$post = get_post( $post_id );
	if ( $post ) {
		// Assuming $this->action is 'save_post'
		do_action( "wp_async_$this->action", $post->ID, $post );
	}
}
```

Make sure that you instantiate your asynchronous task once. Do this no earlier than the `'plugins_loaded'` action.

Finally, update the action of any tasks that you wish to move to the asynchronous task.

For example, you might change this:

```php
add_action( 'save_post', 'really_slow_process', 10, 2 );
```

to this:

```php
add_action( 'wp_async_save_post', 'really_slow_process', 10, 2 );
```

## Contributing

To contribute, please fork the github repository and submit a pull request.

When submitting pull requests, please make sure your changes do not cause any unit tests to fail. To run the unit test suite, make sure you've [installed composer](https://getcomposer.org/doc/00-intro.md) and install the test tools by running

```sh
composer install
```

After you've installed the dev tools, run the unit tests by running

```sh
vendor/bin/phpunit
```

## Copyright

© TechCrunch 2014

## License

This library is licensed under the [MIT](http://opensource.org/licenses/MIT) license. See LICENSE.md for more details.
