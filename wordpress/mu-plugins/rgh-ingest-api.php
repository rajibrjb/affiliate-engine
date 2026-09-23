<?php
/**
 * Ingest REST endpoint for the n8n content pipeline.
 *
 * POST /wp-json/rgh/v1/publish-review
 * Header: X-RGH-Api-Key: <RGH_INGEST_API_KEY>
 *
 * Body (JSON): {
 *   product_key, title, category, image_url, image_credit, source_url,
 *   score, review_count, sources, pros[], cons[], aspect_chips[{label,sentiment}],
 *   verdict, excerpt, content_sections[{heading, html}], status,
 *   gallery_image_urls[{url, credit}], video_url, image_source,
 *   hero_banner_base64, hero_banner_mime
 * }
 *
 * Dedupes on product_key (a stable slug) so re-running discovery for the
 * same product updates the existing post instead of creating a duplicate -
 * this is what makes the "top 10 unique products" pipeline idempotent.
 */
defined( 'ABSPATH' ) || exit;

// Real key lives in wp-content/rgh-secrets.php, which is NOT committed to git
// (see .gitignore) and deployed separately per environment - a hardcoded
// literal here would mean the same secret sitting in a shared repo for both
// local dev and production, and leaking one leaks both.
$rgh_secrets_file = WP_CONTENT_DIR . '/rgh-secrets.php';
if ( file_exists( $rgh_secrets_file ) ) {
	require_once $rgh_secrets_file;
}
if ( ! defined( 'RGH_INGEST_API_KEY' ) ) {
	// Fail closed: an unconfigured environment refuses every request rather
	// than silently accepting a blank/missing key.
	define( 'RGH_INGEST_API_KEY', '' );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'rgh/v1', '/publish-review', array(
		'methods'             => 'POST',
		'callback'            => 'rgh_ingest_publish_review',
		'permission_callback' => 'rgh_ingest_check_key',
	) );
} );

// Site sits behind Cloudflare - CF-Connecting-IP is the real caller, REMOTE_ADDR
// alone would be Cloudflare's edge IP and would lock every caller out together.
function rgh_ingest_get_ip() {
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
	}
	return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
}

function rgh_ingest_check_key( WP_REST_Request $request ) {
	$ip = rgh_ingest_get_ip();
	$lockout_key = 'rgh_ingest_fails_' . md5( $ip );
	$fails = (int) get_transient( $lockout_key );
	if ( $fails >= 10 ) {
		return new WP_Error( 'rgh_locked_out', 'Too many invalid API key attempts from this address. Try again later.', array( 'status' => 429 ) );
	}

	// Optional defense-in-depth beyond the key itself - only enabled if
	// RGH_INGEST_ALLOWED_IPS is defined in rgh-secrets.php.
	if ( defined( 'RGH_INGEST_ALLOWED_IPS' ) && is_array( RGH_INGEST_ALLOWED_IPS ) && ! empty( RGH_INGEST_ALLOWED_IPS ) ) {
		if ( ! in_array( $ip, RGH_INGEST_ALLOWED_IPS, true ) ) {
			return new WP_Error( 'rgh_forbidden_ip', 'This address is not allowed to call this endpoint.', array( 'status' => 403 ) );
		}
	}

	$key = $request->get_header( 'x-rgh-api-key' );
	if ( '' === RGH_INGEST_API_KEY || ! $key || ! hash_equals( RGH_INGEST_API_KEY, $key ) ) {
		set_transient( $lockout_key, $fails + 1, 15 * MINUTE_IN_SECONDS );
		return new WP_Error( 'rgh_unauthorized', 'Invalid or missing API key', array( 'status' => 401 ) );
	}
	delete_transient( $lockout_key );
	return true;
}

/**
 * Blocks SSRF via the image-sideloading functions below: rejects anything
 * that isn't a plain http(s) URL resolving to a public IP. Without this, a
 * caller with a valid API key (or a leaked one) could make the server fetch
 * internal-only addresses - another container on this host's Docker network
 * locally, or a cloud metadata endpoint (169.254.169.254) if this were ever
 * hosted on a cloud VM.
 */
function rgh_ingest_url_is_safe( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	if ( empty( $parts['host'] ) ) {
		return false;
	}
	$host = $parts['host'];
	$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}
	return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

function rgh_ingest_find_or_create_category( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) { return 0; }
	$term = term_exists( $name, 'category' );
	if ( ! $term ) {
		$term = wp_insert_term( $name, 'category' );
	}
	if ( is_wp_error( $term ) ) { return 0; }
	return (int) ( is_array( $term ) ? $term['term_id'] : $term );
}

function rgh_ingest_sideload_image( $url, $post_id, $credit_text, $alt_text = '' ) {
	if ( empty( $url ) ) { return 0; }
	if ( ! rgh_ingest_url_is_safe( $url ) ) { return 0; }
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $url, 20 );
	if ( is_wp_error( $tmp ) ) { return 0; }

	$file_array = array(
		'name'     => sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) ?: 'product.jpg' ),
		'tmp_name' => $tmp,
	);
	if ( ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $file_array['name'] ) ) {
		$file_array['name'] .= '.jpg';
	}

	$attachment_id = media_handle_sideload( $file_array, $post_id, $credit_text ?: null );
	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $tmp );
		return 0;
	}
	if ( $credit_text ) {
		update_post_meta( $attachment_id, '_rgh_photo_credit', sanitize_text_field( $credit_text ) );
	}
	// Image alt text is a real, distinct SEO/accessibility signal from the
	// caption media_handle_sideload() already set above - screen readers and
	// Google Images both read _wp_attachment_image_alt, not post_excerpt.
	if ( $alt_text ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
	}
	return (int) $attachment_id;
}

/**
 * Sideloads a base64-encoded image (the pipeline's AI-generated hero banner -
 * Gemini returns image bytes directly, not a URL, so this can't reuse
 * download_url() like rgh_ingest_sideload_image() above).
 */
function rgh_ingest_sideload_base64_image( $base64_data, $mime_type, $post_id, $alt_text = '' ) {
	if ( empty( $base64_data ) ) { return 0; }
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$decoded = base64_decode( $base64_data, true );
	if ( false === $decoded ) { return 0; }

	$ext = 'png';
	if ( false !== strpos( (string) $mime_type, 'jpeg' ) || false !== strpos( (string) $mime_type, 'jpg' ) ) { $ext = 'jpg'; }
	elseif ( false !== strpos( (string) $mime_type, 'webp' ) ) { $ext = 'webp'; }

	$tmp = wp_tempnam( 'rgh-hero-banner.' . $ext );
	if ( ! $tmp || false === file_put_contents( $tmp, $decoded ) ) { return 0; }

	$file_array = array(
		'name'     => 'rgh-hero-banner-' . (int) $post_id . '.' . $ext,
		'tmp_name' => $tmp,
	);

	$attachment_id = media_handle_sideload( $file_array, $post_id );
	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $tmp );
		return 0;
	}
	if ( $alt_text ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
	}
	return (int) $attachment_id;
}

/** Truncate at a word boundary rather than mid-word - never trust an LLM's own claim that it stayed under a length limit. */
function rgh_truncate_at_word( $text, $max ) {
	$text = trim( wp_strip_all_tags( (string) $text ) );
	if ( mb_strlen( $text ) <= $max ) { return $text; }
	$truncated = mb_substr( $text, 0, $max );
	$last_space = mb_strrpos( $truncated, ' ' );
	if ( false !== $last_space ) {
		$truncated = mb_substr( $truncated, 0, $last_space );
	}
	return rtrim( $truncated, " \t\n\r\0\x0B.,;:" );
}

function rgh_ingest_build_extra_content( $body ) {
	$html = '';

	if ( ! empty( $body['best_for'] ) || ! empty( $body['not_best_for'] ) ) {
		$html .= '<div class="rgh-fit-callout">';
		if ( ! empty( $body['best_for'] ) ) {
			$html .= '<p><strong>Best for:</strong> ' . esc_html( $body['best_for'] ) . '</p>';
		}
		if ( ! empty( $body['not_best_for'] ) ) {
			$html .= '<p><strong>Not ideal for:</strong> ' . esc_html( $body['not_best_for'] ) . '</p>';
		}
		$html .= '</div>';
	}

	if ( ! empty( $body['content_sections'] ) && is_array( $body['content_sections'] ) ) {
		foreach ( $body['content_sections'] as $section ) {
			$heading = isset( $section['heading'] ) ? $section['heading'] : '';
			$content = isset( $section['content'] ) ? $section['content'] : '';
			if ( '' === trim( (string) $content ) ) { continue; }
			if ( $heading ) { $html .= '<h3>' . esc_html( $heading ) . '</h3>'; }
			$html .= '<p>' . esc_html( $content ) . '</p>';
		}
	}

	if ( ! empty( $body['faq'] ) && is_array( $body['faq'] ) ) {
		$html .= '<h3>Frequently asked questions</h3>';
		foreach ( $body['faq'] as $qa ) {
			if ( empty( $qa['question'] ) || empty( $qa['answer'] ) ) { continue; }
			$html .= '<h4>' . esc_html( $qa['question'] ) . '</h4><p>' . esc_html( $qa['answer'] ) . '</p>';
		}
	}

	if ( ! empty( $body['alternatives'] ) && is_array( $body['alternatives'] ) ) {
		$html .= '<h3>Alternatives worth a look</h3><ul>';
		foreach ( $body['alternatives'] as $alt ) {
			if ( empty( $alt['name'] ) ) { continue; }
			$html .= '<li><strong>' . esc_html( $alt['name'] ) . '</strong>' . ( ! empty( $alt['reason'] ) ? ' &mdash; ' . esc_html( $alt['reason'] ) : '' ) . '</li>';
		}
		$html .= '</ul>';
	}

	if ( ! empty( $body['source_url'] ) ) {
		$html .= '<p class="rgh-source-link"><a href="' . esc_url( $body['source_url'] ) . '" rel="nofollow noopener" target="_blank">See the official product page &rarr;</a></p>';
	}

	return $html;
}

function rgh_ingest_publish_review( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( empty( $body['title'] ) || empty( $body['product_key'] ) ) {
		return new WP_Error( 'rgh_bad_request', 'title and product_key are required', array( 'status' => 400 ) );
	}

	$product_key = sanitize_title( $body['product_key'] );

	$existing = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => array( 'publish', 'draft', 'pending' ),
		'meta_key'       => '_rgh_product_key',
		'meta_value'     => $product_key,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	$status = ! empty( $body['status'] ) && in_array( $body['status'], array( 'publish', 'draft', 'pending' ), true )
		? $body['status'] : 'publish';

	$postarr = array(
		'post_title'   => wp_strip_all_tags( $body['title'] ),
		'post_status'  => $status,
		'post_type'    => 'post',
		'post_content' => rgh_ingest_build_extra_content( $body ),
		'post_excerpt' => isset( $body['excerpt'] ) ? wp_strip_all_tags( $body['excerpt'] ) : '',
		// The pipeline publishes via this REST endpoint with no real logged-in
		// user, so post_author defaulted to 0 - get_userdata(0) returning false
		// is what caused the theme's native schema output (and byline avatar)
		// to silently break. A real author id fixes both.
		'post_author'  => 1,
	);

	if ( ! empty( $existing ) ) {
		$postarr['ID'] = $existing[0];
		$post_id       = wp_update_post( $postarr, true );
		$created       = false;
	} else {
		$post_id = wp_insert_post( $postarr, true );
		$created = true;
	}

	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'rgh_insert_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
	}

	update_post_meta( $post_id, '_rgh_product_key', $product_key );

	if ( ! empty( $body['category'] ) ) {
		$cat_id = rgh_ingest_find_or_create_category( $body['category'] );
		if ( $cat_id ) { wp_set_post_categories( $post_id, array( $cat_id ) ); }
	}

	$score = isset( $body['score'] ) ? floatval( $body['score'] ) : '';
	if ( $score !== '' && $score > 10 ) { $score = round( $score / 10, 1 ); }
	update_post_meta( $post_id, '_rgh_score', $score );
	update_post_meta( $post_id, '_rgh_review_count', isset( $body['review_count'] ) ? intval( $body['review_count'] ) : '' );
	update_post_meta( $post_id, '_rgh_sources', sanitize_text_field( $body['sources'] ?? '' ) );
	update_post_meta( $post_id, '_rgh_pros', implode( "\n", array_map( 'sanitize_text_field', (array) ( $body['pros'] ?? array() ) ) ) );
	update_post_meta( $post_id, '_rgh_cons', implode( "\n", array_map( 'sanitize_text_field', (array) ( $body['cons'] ?? array() ) ) ) );
	update_post_meta( $post_id, '_rgh_verdict', sanitize_textarea_field( $body['verdict'] ?? '' ) );

	$chips = array();
	foreach ( (array) ( $body['aspect_chips'] ?? array() ) as $c ) {
		if ( empty( $c['label'] ) ) { continue; }
		$sentiment = in_array( $c['sentiment'] ?? '', array( 'good', 'mixed', 'bad' ), true ) ? $c['sentiment'] : 'good';
		$chips[] = array( 'label' => sanitize_text_field( $c['label'] ), 'sentiment' => $sentiment );
	}
	update_post_meta( $post_id, '_rgh_aspect_chips', $chips );

	if ( $score !== '' ) {
		update_post_meta( $post_id, 'rehub_review_overall_score', $score );
		update_post_meta( $post_id, 'rehub_review_editor_score', $score );
		update_post_meta( $post_id, '_review_post_score_manual', $score );
		update_post_meta( $post_id, '_review_post_summary_text', $body['verdict'] ?? '' );
	}

	if ( ! empty( $body['product_name'] ) ) {
		update_post_meta( $post_id, '_rgh_product_name', sanitize_text_field( $body['product_name'] ) );
	}

	// SEO title/meta description are deliberately separate from the on-page H1
	// (post_title, kept long and descriptive) and on-site excerpt (kept
	// readable for card teasers) - these are the actual <title>/<meta
	// name="description"> tag overrides, length-capped here as a deterministic
	// safety net regardless of what the pipeline's own SEO-score gate already
	// enforced, matching this codebase's "never trust the model's self-report"
	// convention used for the citation-id strip.
	$seo_title = rgh_truncate_at_word( $body['seo_title'] ?? $body['title'], 60 );
	$meta_description = rgh_truncate_at_word( $body['meta_description'] ?? $body['excerpt'] ?? '', 160 );

	if ( class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
		\AIOSEO\Plugin\Common\Models\Post::savePost( $post_id, array(
			'title'          => $seo_title,
			'description'    => $meta_description,
			'og_title'       => $seo_title,
			'og_description' => $meta_description,
			'twitter_title'  => $seo_title,
			'twitter_description' => $meta_description,
			'focus_keyword'  => sanitize_text_field( $body['focus_keyword'] ?? '' ),
		) );
	}

	$default_alt = ! empty( $body['product_name'] ) ? sanitize_text_field( $body['product_name'] ) . ' product photo' : '';

	// Tracks where each post's featured image actually came from ('manufacturer' -
	// scraped from the product's own source_url at discovery time, typically a
	// copyrighted press photo, not freely licensed - vs 'openverse'/'none'). Not
	// used for any rendering; it exists so a future pass can query for
	// `_rgh_image_source = 'manufacturer'` posts and review/clean those up.
	if ( ! empty( $body['image_source'] ) ) {
		update_post_meta( $post_id, '_rgh_image_source', sanitize_text_field( $body['image_source'] ) );
	}

	$attachment_id = 0;
	if ( ! empty( $body['image_url'] ) && ( $created || ! has_post_thumbnail( $post_id ) ) ) {
		$attachment_id = rgh_ingest_sideload_image( $body['image_url'], $post_id, $body['image_credit'] ?? '', $body['image_alt'] ?? $default_alt );
		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
			if ( ! empty( $body['image_credit'] ) ) {
				$credit_html = '<p class="rgh-photo-credit"><em>Photo: ' . esc_html( $body['image_credit'] ) . '</em></p>';
				$postarr2 = array( 'ID' => $post_id, 'post_content' => $credit_html . get_post_field( 'post_content', $post_id ) );
				wp_update_post( $postarr2 );
			}
		}
	}

	$gallery_ids = array();
	if ( ! empty( $body['gallery_image_urls'] ) && is_array( $body['gallery_image_urls'] ) ) {
		foreach ( $body['gallery_image_urls'] as $img ) {
			$url    = is_array( $img ) ? ( $img['url'] ?? '' ) : $img;
			$credit = is_array( $img ) ? ( $img['credit'] ?? '' ) : '';
			$alt    = is_array( $img ) ? ( $img['alt'] ?? $default_alt ) : $default_alt;
			if ( empty( $url ) ) { continue; }
			$id = rgh_ingest_sideload_image( $url, $post_id, $credit, $alt );
			if ( $id ) { $gallery_ids[] = $id; }
		}
	}
	if ( $gallery_ids ) {
		update_post_meta( $post_id, '_rgh_gallery', $gallery_ids );
	}

	if ( ! empty( $body['video_url'] ) ) {
		update_post_meta( $post_id, '_rgh_video_url', esc_url_raw( $body['video_url'] ) );
	}

	// AI-generated decorative hero banner (Nano Banana / Gemini 2.5 Flash Image) -
	// a stylistic image the pipeline generates itself, so unlike every other image
	// on this post it carries no third-party licensing question at all. Only
	// generated/sideloaded once per post (gated the same way the featured image
	// is) so an idempotent re-publish doesn't re-call Gemini and re-upload a new
	// banner every time discovery re-runs for the same product_key.
	$banner_id = 0;
	if ( ! empty( $body['hero_banner_base64'] ) && ( $created || ! get_post_meta( $post_id, '_rgh_hero_banner_id', true ) ) ) {
		$banner_alt = ! empty( $body['product_name'] ) ? sanitize_text_field( $body['product_name'] ) . ' - decorative banner' : 'Decorative banner';
		$banner_id  = rgh_ingest_sideload_base64_image( $body['hero_banner_base64'], $body['hero_banner_mime'] ?? 'image/png', $post_id, $banner_alt );
		if ( $banner_id ) {
			update_post_meta( $post_id, '_rgh_hero_banner_id', $banner_id );
			update_post_meta( $banner_id, '_rgh_image_source', 'ai_generated' );
		}
	}

	return rest_ensure_response( array(
		'post_id'       => $post_id,
		'created'       => $created,
		'status'        => $status,
		'link'          => get_permalink( $post_id ),
		'attachment_id' => $attachment_id,
		'gallery_ids'   => $gallery_ids,
		'banner_id'     => $banner_id,
	) );
}
