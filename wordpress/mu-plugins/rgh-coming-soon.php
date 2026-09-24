<?php
/**
 * Plugin Name: RGH Coming Soon
 * Description: Shows a Coming Soon interstitial to logged-out visitors and forces the site uncrawlable/unindexable, ahead of the real launch (Roadmap item 9).
 * Version: 1.0.0
 *
 * Added 2026-09-25. The site's own "Discourage search engines" setting
 * (blog_public=0) exists but is silently overridden by AIOSEO, which manages
 * its own robots meta/robots.txt once active (globalRobotsMeta.noindex was
 * false, so the live site was actually indexable/crawlable despite the core
 * WP setting saying otherwise - confirmed live: robots.txt was serving the
 * normal public Allow/Sitemap output, not a Disallow-all one). Rather than
 * hand-edit AIOSEO's large nested JSON option (easy to corrupt) or configure
 * the installed "coming-soon" (SeedProd) plugin - which wp_die()s the whole
 * site if its coming-soon page isn't set up, and that page's format isn't
 * something to hand-construct - this is a small, fully-controlled mu-plugin
 * that wins regardless of what any other plugin's settings say.
 *
 * To turn this off for the real launch: delete this file (redeploy via CI)
 * and separately fix AIOSEO's searchAppearance.advanced.globalRobotsMeta via
 * its own settings UI - removing this file alone does not change that.
 *
 * Deliberately left reachable while this is active:
 * - wp-admin / wp-login.php (core routing, template_redirect never fires
 *   for these - no explicit exclusion needed).
 * - Logged-in visitors (so the site can still be reviewed/worked on).
 * - /wp-json/rgh/ (the n8n ingest pipeline's publish-review endpoint - it
 *   already fails closed on a missing/wrong API key, so leaving it reachable
 *   doesn't expose anything).
 */
defined( 'ABSPATH' ) || exit;

// --- 1. Coming Soon interstitial for every other front-end request ---------
add_action( 'template_redirect', function () {
	if ( is_user_logged_in() ) {
		return;
	}
	$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	if ( 0 === strpos( (string) $path, '/wp-json/rgh/' ) ) {
		return;
	}

	header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
	status_header( 503 );
	nocache_headers();
	?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php bloginfo( 'name' ); ?> — Coming Soon</title>
<style>
	body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
		background:#0F172A; color:#F8FAFC; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,sans-serif; text-align:center; }
	main { padding:24px; }
	h1 { font-size:1.75rem; margin:0 0 12px; }
	p { color:#94A3B8; margin:0; font-size:1rem; }
</style>
</head>
<body>
<main>
	<h1><?php bloginfo( 'name' ); ?> is coming soon</h1>
	<p>We're putting the finishing touches on things. Check back shortly.</p>
</main>
</body>
</html>
	<?php
	exit;
}, 1 );

// --- 2. Force robots.txt to disallow everything, regardless of AIOSEO ------
// Priority 999 so this runs after AIOSEO's own robots_txt filter and wins.
add_filter( 'robots_txt', function () {
	return "User-agent: *\nDisallow: /\n";
}, 999 );

// --- 3. Belt-and-suspenders noindex header on every response ---------------
add_action( 'send_headers', function () {
	header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
} );
