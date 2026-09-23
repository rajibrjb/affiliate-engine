<?php
/**
 * Wraps the plain content pages (About, Contact, Affiliate Disclosure, Terms of
 * Use, Privacy Policy) in the same design system as the rest of the site, instead
 * of the theme's default naked-paragraphs-on-white-page look. No new fields -
 * these pages already have real, hand-written content; this only adds the shared
 * header + card chrome around whatever content already exists.
 */
defined( 'ABSPATH' ) || exit;

function rgh_content_page_slugs() {
	return array( 'about', 'contact', 'affiliate-disclosure', 'terms-of-use', 'privacy-policy' );
}

add_filter( 'the_content', function ( $content ) {
	if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) { return $content; }
	if ( ! in_array( get_post_field( 'post_name' ), rgh_content_page_slugs(), true ) ) { return $content; }

	$box  = '<div class="rgh-home rgh-page">';
	$box .= '<div class="rgh-page-header"><h1>' . esc_html( get_the_title() ) . '</h1></div>';
	$box .= '<div class="rgh-page-card">' . $content . '</div>';
	$box .= '</div>';

	return $box;
}, 5 );

/**
 * Category archives have no per-term SEO title/description configured, so they
 * fall back to a generic "{term} - {site}" title and no meta description at
 * all. Set both explicitly instead of leaving search results to guess.
 */
// AIOSEO builds its own complete title string via pre_get_document_title,
// which short-circuits document_title_parts entirely - a filter on the parts
// hook alone never runs for the final output, so this has to win at the same
// pre_get_document_title stage, after AIOSEO's own callback.
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( is_category() ) {
		return single_cat_title( '', false ) . ' Reviews - ' . get_bloginfo( 'name' );
	}
	return $title;
}, PHP_INT_MAX );

add_action( 'wp_head', function () {
	if ( ! is_category() ) { return; }
	$term  = get_queried_object();
	$name  = single_cat_title( '', false );
	$count = isset( $term->count ) ? (int) $term->count : 0;
	$desc  = $count > 0
		? sprintf( '%d real owner-reviewed %s breakdowns on ReviewGeekHub, ranked by Consensus Score.', $count, strtolower( $name ) )
		: sprintf( 'Real owner-reviewed %s breakdowns on ReviewGeekHub, ranked by Consensus Score.', strtolower( $name ) );
	echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
}, 4 );
