<?php

function add_action() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function add_filter() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function do_action() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function apply_filters() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function admin_url() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function wp_remote_post() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function is_user_logged_in() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function wp_create_nonce() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function wp_nonce_tick() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function wp_hash() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function wp_verify_nonce() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}

function wp_die() {
	return WordPress_Mocker::handle_function( __FUNCTION__, func_get_args() );
}
