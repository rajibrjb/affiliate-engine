<?php
/**
 * Correct structured data for review posts.
 *
 * The parent theme's own Review/Product/AggregateRating JSON-LD
 * (re_add_openschema() in rehub-theme/functions.php) has three real defects
 * confirmed on a live post:
 *   - author.name is null (get_userdata() on an empty/invalid post_author)
 *   - itemReviewed.name reuses the full article title ("... Review: Expert
 *     Insights on Performance and Comfort") instead of the actual product name
 *   - aggregateRating.reviewCount is hardcoded to 1 whenever the theme's
 *     "type_user_review" option isn't set to "full_review", ignoring our real
 *     _rgh_review_count - contradicting the count shown elsewhere on the same
 *     page and risking a Google structured-data mismatch flag.
 * Rather than patch around a vendor theme function, its hook is removed and
 * replaced with a version built entirely from data we control.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	remove_action( 'wp_head', 're_add_openschema', 5 );
} );

/** Strip review-title furniture down to a bare product name for schema.org itemReviewed.name. */
function rgh_clean_product_name( $post_id ) {
	$stored = get_post_meta( $post_id, '_rgh_product_name', true );
	if ( $stored ) { return $stored; }

	$title = get_the_title( $post_id );
	$title = preg_replace( '/\s+Review:.*$/i', '', $title );
	$title = str_replace( ' — what owners say', '', $title );
	$title = preg_replace( '/\s+Review$/i', '', $title );
	return trim( $title );
}

add_action( 'wp_head', function () {
	if ( ! is_singular( 'post' ) ) { return; }
	$post_id = get_the_ID();
	$score   = get_post_meta( $post_id, '_rgh_score', true );
	if ( $score === '' || $score === false ) { return; }

	$verdict = get_post_meta( $post_id, '_rgh_verdict', true );
	$count   = (int) get_post_meta( $post_id, '_rgh_review_count', true );
	$body    = $verdict ?: get_the_excerpt( $post_id );

	$item_reviewed = array(
		'@type' => 'Product',
		'name'  => rgh_clean_product_name( $post_id ),
	);
	if ( has_post_thumbnail( $post_id ) ) {
		$item_reviewed['image'] = get_the_post_thumbnail_url( $post_id, 'large' );
	}
	// Only claim an AggregateRating when a real count backs it - an "average"
	// from zero reviews isn't a real aggregate and would be a structured-data
	// mismatch against the page's own "based on expert testing" copy.
	if ( $count > 0 ) {
		$item_reviewed['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'worstRating' => '1',
			'bestRating'  => '10',
			'ratingValue' => round( (float) $score, 1 ),
			'reviewCount' => $count,
		);
	}

	$organization = rehub_option( 'rehub_org_name_review' );

	$jsonld = array(
		'@context'      => 'https://schema.org',
		'@type'         => 'Review',
		'name'          => get_the_title( $post_id ),
		'datePublished' => get_the_date( 'c', $post_id ),
		'dateModified'  => get_the_modified_date( 'c', $post_id ),
		'reviewBody'    => wp_strip_all_tags( $body ),
		'reviewRating'  => array(
			'@type'       => 'Rating',
			'worstRating' => '1',
			'bestRating'  => '10',
			'ratingValue' => round( (float) $score, 1 ),
		),
		// An Organization byline is used rather than a Person - this is
		// aggregated-consensus content (real owner/expert sources cited in the
		// review), not one person's personal test, so Organization is the more
		// accurate claim and is never null the way an unset post author was.
		'author'        => array(
			'@type' => 'Organization',
			'name'  => $organization ?: get_bloginfo( 'name' ),
		),
		'itemReviewed'  => $item_reviewed,
	);
	if ( $organization ) {
		$jsonld['publisher'] = array( '@type' => 'Organization', 'name' => esc_html( $organization ) );
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";

	// FAQPage schema - a direct lever for Google's FAQ rich results and for
	// AI/agentic search engines that prefer clean, structured Q&A extraction
	// over parsing prose. Reads the same "Frequently asked questions" h3 +
	// h4/p pattern the pipeline/ingest already produces in post_content.
	$content = get_post_field( 'post_content', $post_id );
	if ( preg_match( '/<h3[^>]*>Frequently asked questions<\/h3>(.*?)(?=<h3|$)/s', $content, $m )
		&& preg_match_all( '/<h4[^>]*>(.*?)<\/h4>\s*<p[^>]*>(.*?)<\/p>/s', $m[1], $qas, PREG_SET_ORDER ) ) {
		$faq_items = array();
		foreach ( $qas as $qa ) {
			$faq_items[] = array(
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( $qa[1] ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( $qa[2] ),
				),
			);
		}
		if ( $faq_items ) {
			$faq_jsonld = array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => $faq_items,
			);
			echo '<script type="application/ld+json">' . wp_json_encode( $faq_jsonld, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		}
	}
}, 5 );
