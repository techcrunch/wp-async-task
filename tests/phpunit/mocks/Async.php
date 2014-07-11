<?php

class Async extends BaseAsync {

	public function __construct( $init = parent::BOTH ) {
		if ( $init ) {
			parent::__construct( $init );
		}
	}

}
