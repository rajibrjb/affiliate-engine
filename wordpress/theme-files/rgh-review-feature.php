<?php
/**
 * "Product Review" feature for ReviewGeekHub.
 *
 * Adds a meta box to the post editor (Score, Sources, Aspect chips, Pros,
 * Cons, Verdict). On save it:
 *   1) Stores the fields as post meta.
 *   2) Mirrors the score into ReHub's native review fields, so Schema.org
 *      Review/AggregateRating markup and score-based sorting work exactly
 *      like posts made with the theme's own Review Box block.
 *   3) Auto-renders the same `.rgh-post` consensus box via a `the_content`
 *      filter — no post ever needs hand-written HTML for this again.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', function () {
	add_meta_box(
		'rgh_review_box',
		'Product Review (ReviewGeekHub)',
		'rgh_render_review_metabox',
		'post',
		'normal',
		'high'
	);
} );

function rgh_render_review_metabox( $post ) {
	wp_nonce_field( 'rgh_review_save', 'rgh_review_nonce' );
	$score    = get_post_meta( $post->ID, '_rgh_score', true );
	$count    = get_post_meta( $post->ID, '_rgh_review_count', true );
	$sources  = get_post_meta( $post->ID, '_rgh_sources', true ) ?: 'Amazon, Reddit, YouTube';
	$chips    = get_post_meta( $post->ID, '_rgh_aspect_chips', true );
	$chips_txt = '';
	if ( ! empty( $chips ) ) {
		foreach ( $chips as $c ) { $chips_txt .= $c['label'] . '|' . $c['sentiment'] . "\n"; }
	}
	$pros    = get_post_meta( $post->ID, '_rgh_pros', true );
	$cons    = get_post_meta( $post->ID, '_rgh_cons', true );
	$verdict = get_post_meta( $post->ID, '_rgh_verdict', true );
	?>
	<style>
		.rgh-mb-row{margin-bottom:14px}
		.rgh-mb-row label{display:block;font-weight:600;margin-bottom:4px}
		.rgh-mb-row .desc{color:#666;font-size:12px;margin-top:3px}
		.rgh-mb-row input[type=text],.rgh-mb-row input[type=number]{width:100%;max-width:420px}
		.rgh-mb-row textarea{width:100%;font-family:ui-monospace,monospace;font-size:13px}
		.rgh-mb-cols{display:flex;gap:20px}
		.rgh-mb-cols > div{flex:1}
	</style>
	<div class="rgh-mb-row">
		<label>Consensus Score (0&ndash;10)</label>
		<input type="number" step="0.1" min="0" max="10" name="rgh_score" value="<?php echo esc_attr( $score ); ?>">
	</div>
	<div class="rgh-mb-cols">
		<div class="rgh-mb-row">
			<label>Review count</label>
			<input type="number" name="rgh_review_count" value="<?php echo esc_attr( $count ); ?>">
		</div>
		<div class="rgh-mb-row">
			<label>Sources</label>
			<input type="text" name="rgh_sources" value="<?php echo esc_attr( $sources ); ?>">
		</div>
	</div>
	<div class="rgh-mb-row">
		<label>Aspect chips (one per line: <code>Label|good</code> or <code>Label|mixed</code>)</label>
		<textarea name="rgh_aspect_chips" rows="4" placeholder="Battery life|good&#10;Comfort|good&#10;Price|mixed"><?php echo esc_textarea( $chips_txt ); ?></textarea>
	</div>
	<div class="rgh-mb-cols">
		<div class="rgh-mb-row">
			<label>What owners praise (one point per line)</label>
			<textarea name="rgh_pros" rows="4"><?php echo esc_textarea( $pros ); ?></textarea>
		</div>
		<div class="rgh-mb-row">
			<label>Common complaints (one point per line)</label>
			<textarea name="rgh_cons" rows="4"><?php echo esc_textarea( $cons ); ?></textarea>
		</div>
	</div>
	<div class="rgh-mb-row">
		<label>The consensus (verdict paragraph)</label>
		<textarea name="rgh_verdict" rows="3"><?php echo esc_textarea( $verdict ); ?></textarea>
		<div class="desc">This box renders automatically at the top of the post — write the post body below as normal, no HTML needed.</div>
	</div>
	<?php
}

add_action( 'save_post_post', function ( $post_id ) {
	if ( ! isset( $_POST['rgh_review_nonce'] ) || ! wp_verify_nonce( $_POST['rgh_review_nonce'], 'rgh_review_save' ) ) { return; }
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

	$score   = isset( $_POST['rgh_score'] ) ? floatval( $_POST['rgh_score'] ) : '';
	$count   = isset( $_POST['rgh_review_count'] ) ? intval( $_POST['rgh_review_count'] ) : '';
	$sources = sanitize_text_field( $_POST['rgh_sources'] ?? '' );
	$pros    = sanitize_textarea_field( $_POST['rgh_pros'] ?? '' );
	$cons    = sanitize_textarea_field( $_POST['rgh_cons'] ?? '' );
	$verdict = sanitize_textarea_field( $_POST['rgh_verdict'] ?? '' );

	update_post_meta( $post_id, '_rgh_score', $score );
	update_post_meta( $post_id, '_rgh_review_count', $count );
	update_post_meta( $post_id, '_rgh_sources', $sources );
	update_post_meta( $post_id, '_rgh_pros', $pros );
	update_post_meta( $post_id, '_rgh_cons', $cons );
	update_post_meta( $post_id, '_rgh_verdict', $verdict );

	// Parse "Label|sentiment" lines into the same chip structure the homepage
	// shortcodes already read.
	$chips = array();
	$raw_chips = explode( "\n", (string) ( $_POST['rgh_aspect_chips'] ?? '' ) );
	foreach ( $raw_chips as $line ) {
		$line = trim( $line );
		if ( '' === $line || strpos( $line, '|' ) === false ) { continue; }
		list( $label, $sentiment ) = array_map( 'trim', explode( '|', $line, 2 ) );
		$sentiment = in_array( $sentiment, array( 'good', 'mixed', 'bad' ), true ) ? $sentiment : 'good';
		$chips[] = array( 'label' => sanitize_text_field( $label ), 'sentiment' => $sentiment );
	}
	update_post_meta( $post_id, '_rgh_aspect_chips', $chips );

	// Mirror into ReHub's native fields so schema.org Review/AggregateRating
	// output and score-based sorting work the same as the built-in Review Box.
	if ( $score !== '' ) {
		update_post_meta( $post_id, 'rehub_review_overall_score', $score );
		update_post_meta( $post_id, 'rehub_review_editor_score', $score );
		update_post_meta( $post_id, '_review_post_score_manual', $score );
		update_post_meta( $post_id, '_review_post_summary_text', $verdict );
		update_post_meta( $post_id, '_review_post_pros_text', $pros );
		update_post_meta( $post_id, '_review_post_cons_text', $cons );
		if ( $count !== '' ) {
			update_post_meta( $post_id, 'post_user_raitings', array(
				'criteria' => array( array( 'name' => 'Consensus', 'count' => $count, 'value' => $score * $count, 'average' => number_format( $score, 1 ) ) ),
			) );
		}
	}
} );

/**
 * Tag anchor ids onto the body's own headings/callout so the sticky section nav
 * can jump to them, and turn the "Frequently asked questions" h4/p run into a
 * native <details> accordion. Returns which sections were actually found so the
 * nav only ever links to things that exist on this particular post.
 */
function rgh_enhance_body_content( $content ) {
	$has_performance = false;

	// The first narrative heading that isn't FAQ/Alternatives anchors "Performance".
	$content = preg_replace_callback(
		'/<h3>(?!Frequently asked questions|Alternatives worth a look)(.*?)<\/h3>/',
		function ( $m ) use ( &$has_performance ) {
			if ( $has_performance ) { return $m[0]; }
			$has_performance = true;
			return '<h3 id="rgh-performance">' . $m[1] . '</h3>';
		},
		$content
	);

	$has_alternatives = strpos( $content, '<h3>Alternatives worth a look</h3>' ) !== false;
	if ( $has_alternatives ) {
		$content = str_replace(
			'<h3>Alternatives worth a look</h3>',
			'<h3 id="rgh-alternatives">Alternatives worth a look</h3>',
			$content
		);
	}

	$has_faq = false;
	if ( preg_match( '/<h3>Frequently asked questions<\/h3>(.*?)(?=<h3|$)/s', $content, $m ) ) {
		$has_faq  = true;
		$faq_body = $m[1];
		$faq_html = preg_replace_callback(
			'/<h4>(.*?)<\/h4>\s*<p>(.*?)<\/p>/s',
			function ( $mm ) {
				return '<details class="rgh-faq-item"><summary>' . $mm[1]
					. '<svg class="chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></summary>'
					. '<div class="rgh-faq-a"><p>' . $mm[2] . '</p></div></details>';
			},
			$faq_body
		);
		$content = str_replace(
			'<h3>Frequently asked questions</h3>' . $faq_body,
			'<h3 id="rgh-faq">Frequently asked questions</h3><div class="rgh-faq-list">' . $faq_html . '</div>',
			$content
		);
	}

	$has_who_for = strpos( $content, 'class="rgh-fit-callout"' ) !== false;
	if ( $has_who_for ) {
		$content = str_replace( 'class="rgh-fit-callout"', 'class="rgh-fit-callout" id="rgh-who-for"', $content );
	}

	return array(
		'html'             => $content,
		'has_performance'  => $has_performance,
		'has_faq'          => $has_faq,
		'has_alternatives' => $has_alternatives,
		'has_who_for'      => $has_who_for,
	);
}

/** Pull the "official product page" / source link out of the body for the primary CTA button. */
function rgh_extract_buy_link( $content ) {
	if ( preg_match( '/class="rgh-source-link"><a href="([^"]+)"/', $content, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * Pull a "Photo: ... " credit line out of the body. The writer/pipeline drops it in as the
 * first paragraph of the article text, which lands it wherever that text happens to fall in
 * the rendered layout (after the quick verdict, feature grid, video, pros/cons) - nowhere near
 * the actual hero photos it's crediting. Extracting it lets the caller place it directly under
 * the hero gallery instead.
 */
function rgh_extract_photo_credit( $content ) {
	if ( preg_match( '/<p class="rgh-photo-credit">.*?<\/p>/s', $content, $m ) ) {
		return array( $m[0], str_replace( $m[0], '', $content ) );
	}
	return array( '', $content );
}

/** Auto-render the full review template — quick verdict, feature grid, pros/cons, body, final verdict. */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) { return $content; }
	$post_id = get_the_ID();
	$score = get_post_meta( $post_id, '_rgh_score', true );
	if ( $score === '' || $score === false ) { return $content; }

	$count   = get_post_meta( $post_id, '_rgh_review_count', true );
	$sources = get_post_meta( $post_id, '_rgh_sources', true );
	$pros    = array_filter( array_map( 'trim', explode( "\n", (string) get_post_meta( $post_id, '_rgh_pros', true ) ) ) );
	$cons    = array_filter( array_map( 'trim', explode( "\n", (string) get_post_meta( $post_id, '_rgh_cons', true ) ) ) );
	$verdict = trim( (string) get_post_meta( $post_id, '_rgh_verdict', true ) );
	$gallery = get_post_meta( $post_id, '_rgh_gallery', true );
	$video   = get_post_meta( $post_id, '_rgh_video_url', true );

	$buy_link    = rgh_extract_buy_link( $content );
	list( $photo_credit, $content ) = rgh_extract_photo_credit( $content );
	$enhanced    = rgh_enhance_body_content( $content );
	$has_proscons = ! empty( $pros ) || ! empty( $cons );

	// A short first-sentence version for the top card; the full paragraph anchors the bottom banner.
	$verdict_sentences = $verdict ? preg_split( '/(?<=[.!?])\s+/', $verdict ) : array();
	$verdict_short      = ! empty( $verdict_sentences[0] ) ? $verdict_sentences[0] : $verdict;

	// "from 0 reviews" next to a real score reads as contradictory/broken - count only
	// tallies distinct owner-report items found, which is sometimes genuinely zero even
	// when the score is well-supported by manufacturer specs/expert testing instead.
	$basis = ( (int) $count > 0 )
		? 'from ' . number_format( (int) $count ) . ' reviews'
		: 'based on expert testing &amp; official specs';

	$box = '<div class="rgh-home rgh-post">';

	// AI-generated decorative banner (Nano Banana / Gemini 2.5 Flash Image) - a
	// stylistic, non-photorealistic visual sitting above the real hero photo
	// gallery below it. Deliberately never a substitute for an actual product
	// photo (generative models can't reliably reproduce a real device's exact
	// design/logo) - it exists purely to make the top of the post more visually
	// engaging, and it's the one image on this page we actually own outright.
	$banner_id = get_post_meta( $post_id, '_rgh_hero_banner_id', true );
	if ( $banner_id ) {
		$banner_img = wp_get_attachment_image( $banner_id, 'large', false, array( 'loading' => 'eager', 'class' => 'rgh-hero-banner-img' ) );
		if ( $banner_img ) {
			$box .= '<div class="rgh-hero-banner">' . $banner_img . '</div>';
		}
	}

	if ( ! empty( $gallery ) && is_array( $gallery ) ) {
		$box .= '<div class="rgh-hero-gallery">';
		foreach ( $gallery as $attachment_id ) {
			$img = wp_get_attachment_image( $attachment_id, 'large', false, array( 'loading' => 'eager' ) );
			if ( $img ) { $box .= '<div class="rgh-hero-gallery-item">' . $img . '</div>'; }
		}
		$box .= '</div>';
	}

	if ( $photo_credit ) { $box .= $photo_credit; }

	// Sticky section nav - only ever links to sections this particular post actually has.
	$nav_items = array( array( '#rgh-overview', 'Overview' ) );
	if ( $enhanced['has_performance'] )  { $nav_items[] = array( '#rgh-performance', 'Performance' ); }
	if ( $has_proscons )                 { $nav_items[] = array( '#rgh-proscons', 'Pros & Cons' ); }
	if ( $enhanced['has_who_for'] )      { $nav_items[] = array( '#rgh-who-for', "Who it's for" ); }
	if ( $enhanced['has_alternatives'] ) { $nav_items[] = array( '#rgh-alternatives', 'Alternatives' ); }
	if ( $enhanced['has_faq'] )          { $nav_items[] = array( '#rgh-faq', 'FAQ' ); }
	if ( count( $nav_items ) > 1 ) {
		$box .= '<nav class="rgh-section-nav"><div class="rgh-section-nav-inner">';
		foreach ( $nav_items as $item ) {
			$box .= '<a href="' . esc_attr( $item[0] ) . '">' . esc_html( $item[1] ) . '</a>';
		}
		$box .= '</div></nav>';
	}

	// Quick verdict card.
	$box .= '<div class="rgh-quick-verdict" id="rgh-overview">';
	$box .=   rgh_gauge( $score, 'lg' );
	$box .=   '<div class="rgh-quick-verdict-body"><div class="t">Our take</div>';
	if ( $verdict_short ) { $box .= '<p>' . esc_html( $verdict_short ) . '</p>'; }
	$box .=   '<div class="d">' . $basis . ( $sources ? ' &middot; ' . esc_html( $sources ) : '' ) . '</div></div>';
	if ( $buy_link ) {
		$box .= '<a class="rgh-cta" href="' . esc_url( $buy_link ) . '" rel="nofollow noopener sponsored" target="_blank">Check price <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M7 17L17 7M7 7h10v10"/></svg></a>';
	}
	$box .= '</div>';

	// Feature highlight grid (reuses the aspect chips, up to 4).
	$feature_html = rgh_aspect_chips_html( $post_id, 4 );
	if ( $feature_html ) {
		$box .= '<div class="rgh-feature-grid">' . $feature_html . '</div>';
	}

	if ( ! empty( $video ) ) {
		$video_id = rgh_youtube_id( $video );
		if ( $video_id ) {
			$box .= '<div class="rgh-video-feature"><div class="rgh-video-label">Watch it in action</div><div class="rgh-video-embed"><iframe src="https://www.youtube-nocookie.com/embed/' . esc_attr( $video_id ) . '" title="Product video" frameborder="0" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div></div>';
		}
	}

	// Pros & cons split panel.
	if ( $has_proscons ) {
		$box .= '<div class="rgh-proscons" id="rgh-proscons">';
		if ( $pros ) {
			$box .= '<div class="rgh-pros"><h3>What owners praise</h3><ul>';
			foreach ( $pros as $p ) { $box .= '<li>' . esc_html( $p ) . '</li>'; }
			$box .= '</ul></div>';
		}
		if ( $cons ) {
			$box .= '<div class="rgh-cons"><h3>Common complaints</h3><ul>';
			foreach ( $cons as $c ) { $box .= '<li>' . esc_html( $c ) . '</li>'; }
			$box .= '</ul></div>';
		}
		$box .= '</div>';
	}

	// The actual article body - narrative sections, "who it's for", FAQ accordion, alternatives -
	// now flows inside the same card instead of escaping into unstyled theme content below it.
	$box .= $enhanced['html'];

	// Final verdict banner - replaces the theme's native "Total Score" stamp (hidden via CSS),
	// which sat in its own disconnected box below the article with no visual relationship to it.
	$box .= '<div class="rgh-final-verdict">';
	$box .=   '<div class="rgh-final-verdict-score">' . number_format( (float) $score, 1 ) . '<span>/10</span></div>';
	$box .=   '<div class="rgh-final-verdict-body"><div class="lbl">Final verdict</div>';
	if ( $verdict ) { $box .= '<p>' . esc_html( $verdict ) . '</p>'; }
	$box .=   '</div>';
	if ( $buy_link ) {
		$box .= '<a class="rgh-cta rgh-cta-light" href="' . esc_url( $buy_link ) . '" rel="nofollow noopener sponsored" target="_blank">Check price</a>';
	}
	$box .= '</div>';

	// Disclosure sits at the end, after the actual review content - visible and easy to
	// find, but not the very first thing a reader hits.
	$box .= '<p class="rgh-affiliate-notice">We may earn a commission from links on this page. <a href="' . esc_url( home_url( '/affiliate-disclosure/' ) ) . '">See our disclosure</a>.</p>';

	$box .= '</div>';

	return $box;
}, 5 );

/** Extract a YouTube video id from a full URL (watch, youtu.be, shorts, embed forms). */
function rgh_youtube_id( $url ) {
	if ( preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
		return $m[1];
	}
	return '';
}
