<?php
/**
 * Plugin Name: RGH Noindex (Dev Mode)
 * Description: Keeps the site fully public/testable on the real domain, but hidden from search engines - a "development mode" for testing without a Coming Soon wall.
 * Version: 1.0.0
 *
 * Added 2026-09-25, replacing an earlier version of this file
 * (rgh-coming-soon.php) that also blocked anonymous visitors behind a
 * Coming Soon interstitial. That block was removed on request - the site
 * needs to be reachable for real testing - but AIOSEO's own robots setting
 * (searchAppearance.advanced.globalRobotsMeta.noindex = false) still means
 * the site is otherwise indexable, so this keeps the two crawler-facing
 * protections from that version: a hard Disallow-all robots.txt and a
 * noindex response header on every request.
 *
 * To turn off once ready for real search engine indexing: delete this file
 * (redeploy via CI) AND separately flip AIOSEO's own
 * searchAppearance.advanced.globalRobotsMeta.noindex to false in its
 * settings UI - removing this file alone does not do that.
 */
defined( 'ABSPATH' ) || exit;

// Force robots.txt to disallow everything, regardless of AIOSEO.
// Priority 999 so this runs after AIOSEO's own robots_txt filter and wins.
add_filter( 'robots_txt', function () {
	return "User-agent: *\nDisallow: /\n";
}, 999 );

// Noindex header on every response - works even for crawlers that ignore
// robots.txt or fetch pages directly.
add_action( 'send_headers', function () {
	header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
} );
