<?php
/**
 * Plugin Name: RGH Security Hardening
 * Description: Disables XML-RPC's credentialed methods, hides the REST API user-enumeration leak, and adds login rate-limiting with a generic error message. See in-file comments for the incident this was written for.
 * Version: 1.0.0
 *
 * Added 2026-09-23 after a live security review of reviewgeekhub.com found:
 * the WP REST API was handing out the admin's username (their email address)
 * to anyone, unauthenticated, at /wp-json/wp/v2/users; xmlrpc.php was enabled
 * with no plugin depending on it, letting an attacker brute-force many
 * passwords per request via system.multicall, bypassing wp-login.php's normal
 * throttling; and there was no login rate-limiting or lockout at all - no
 * security plugin was installed on the site.
 *
 * This single small mu-plugin (rather than a full security suite like
 * Wordfence/iThemes) fixes exactly those three things and nothing else, to
 * avoid taking on a heavier plugin's scanning overhead/update burden/attack
 * surface for a site that only needed these specific behaviors.
 */
defined( 'ABSPATH' ) || exit;

// --- 1. Disable XML-RPC entirely -------------------------------------------
// No installed plugin (Elementor, Content Egg, GreenShift, Hostinger tools,
// etc.) depends on it - confirmed against the live plugin list before writing
// this. Also strips the RSD/Windows-Live-Writer <link> tags and the
// X-Pingback header, which otherwise still advertise xmlrpc.php even once
// disabled.
add_filter( 'xmlrpc_enabled', '__return_false' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
add_filter( 'wp_headers', function ( $headers ) {
	unset( $headers['X-Pingback'] );
	return $headers;
} );

// --- 2. Stop the REST API from handing out usernames to logged-out callers -
// GET /wp-json/wp/v2/users previously returned the admin's email address
// (which is also their login username) to anyone, no auth required -
// confirmed live. Logged-in requests still work normally (e.g. for anything
// in wp-admin that legitimately needs the users list).
add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );

// --- 3. Login rate limiting + generic error message -------------------------
// Locks an IP out for 15 minutes after 5 failed attempts.
// Site is behind Cloudflare, so CF-Connecting-IP is checked first - REMOTE_ADDR
// alone would be Cloudflare's edge IP, not the real visitor, and would lock
// out everyone behind Cloudflare together.
function rgh_security_get_ip() {
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
	}
	return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
}

function rgh_security_lockout_key() {
	return 'rgh_login_fails_' . md5( rgh_security_get_ip() );
}

// Priority 30: WordPress's own wp_authenticate_username_password() runs at
// priority 20 and unconditionally overwrites whatever $user already is (it
// only short-circuits if $user is already a WP_User, never checks for an
// existing WP_Error) - hooking any earlier gets silently clobbered by core.
// Running after it means a lockout wins even over a correct password, which
// is the point: locked out means locked out until the window expires.
add_filter( 'authenticate', function ( $user, $username, $password ) {
	if ( empty( $username ) && empty( $password ) ) {
		return $user;
	}
	$fails = (int) get_transient( rgh_security_lockout_key() );
	if ( $fails >= 5 ) {
		return new WP_Error( 'rgh_locked_out', __( 'Too many failed login attempts from your network. Please try again in 15 minutes.' ) );
	}
	return $user;
}, 30, 3 );

add_action( 'wp_login_failed', function () {
	$key = rgh_security_lockout_key();
	$fails = (int) get_transient( $key );
	set_transient( $key, $fails + 1, 15 * MINUTE_IN_SECONDS );
} );

add_action( 'wp_login', function () {
	delete_transient( rgh_security_lockout_key() );
} );

// Generic message regardless of whether the username or the password was
// wrong - by default WP confirms a real username exists by saying "the
// password you entered is incorrect" only when the username is valid.
// Leaves the lockout message (rgh_locked_out, added above) untouched - a
// locked-out visitor needs to know to wait, not just that a password was
// wrong.
add_filter( 'login_errors', function ( $message ) {
	global $errors;
	if ( is_wp_error( $errors ) && in_array( 'rgh_locked_out', $errors->get_error_codes(), true ) ) {
		return $message;
	}
	if ( is_wp_error( $errors ) && array_intersect( array( 'invalid_username', 'incorrect_password', 'invalid_email' ), $errors->get_error_codes() ) ) {
		return __( 'Incorrect username or password.' );
	}
	return $message;
} );
