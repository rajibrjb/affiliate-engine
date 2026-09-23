<?php
/**
 * ReviewGeekHub dynamic homepage shortcodes.
 * Pulls real posts/categories instead of the static HTML the homepage started with.
 */
defined( 'ABSPATH' ) || exit;

function rgh_category_defs() {
	return array(
		24 => array( 'tint' => '--rgh-blue-tint',   'ink' => '--rgh-blue',   'icon' => '<rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M2 19h20l-2-4H4z"/>' ),
		25 => array( 'tint' => '--rgh-amber-tint',  'ink' => '--rgh-amber',  'icon' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v9h14v-9"/>' ),
		26 => array( 'tint' => '--rgh-violet-tint', 'ink' => '--rgh-violet', 'icon' => '<path d="M4 13a8 8 0 0116 0"/><rect x="2" y="13" width="5" height="7" rx="1.5"/><rect x="17" y="13" width="5" height="7" rx="1.5"/>' ),
		27 => array( 'tint' => '--rgh-teal-tint',   'ink' => '--rgh-teal',   'icon' => '<rect x="6" y="4" width="12" height="16" rx="3"/><circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/>' ),
		28 => array( 'tint' => '--rgh-rose-tint',   'ink' => '--rgh-rose',   'icon' => '<path d="M4 12h4M16 12h4M8 8v8M16 8v8M8 12h8"/>' ),
		29 => array( 'tint' => '--rgh-green-tint',  'ink' => '--rgh-green',  'icon' => '<path d="M12 3v3M12 18v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M3 12h3M18 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/><circle cx="12" cy="12" r="4"/>' ),
	);
}

/** Best available photo for a category — the highest-scored post's featured image. */
function rgh_category_photo( $term_id ) {
	$q = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => 1,
		'cat'            => $term_id,
		'meta_key'       => 'rehub_review_overall_score',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'fields'         => 'ids',
	) );
	if ( empty( $q->posts ) ) { return ''; }
	$post_id = $q->posts[0];
	return has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'medium' ) : '';
}

function rgh_get_categories_data() {
	$defs = rgh_category_defs();
	$out  = array();
	foreach ( $defs as $term_id => $meta ) {
		$term = get_term( $term_id, 'category' );
		if ( ! $term || is_wp_error( $term ) ) { continue; }
		$out[] = array_merge( $meta, array(
			'id'    => $term_id,
			'name'  => $term->name,
			'count' => (int) $term->count,
			'link'  => get_term_link( $term_id, 'category' ),
			'photo' => rgh_category_photo( $term_id ),
		) );
	}
	return $out;
}

function rgh_gauge( $score, $extra_class = '' ) {
	$pct = round( (float) $score * 10 );
	return '<div class="gauge ' . esc_attr( $extra_class ) . '" style="--pct:' . $pct . '"><span>' . number_format( (float) $score, 1 ) . '</span></div>';
}

/** 5-star display converted from our 0-10 Consensus Score scale. */
function rgh_stars_html( $score_out_of_10 ) {
	$pct = max( 0, min( 100, ( (float) $score_out_of_10 / 10 ) * 100 ) );
	$stars = str_repeat( '&#9733;', 5 ); // ★
	return '<span class="stars"><span class="base">' . $stars . '</span><span class="fill" style="width:' . $pct . '%">' . $stars . '</span></span>';
}

function rgh_review_count( $post_id ) {
	$rates = get_post_meta( $post_id, 'post_user_raitings', true );
	if ( ! empty( $rates['criteria'][0]['count'] ) ) {
		return (int) $rates['criteria'][0]['count'];
	}
	return 0;
}

/** Icon+label+sentiment mini-stat row — used on the Featured card. */
function rgh_mini_stats_html( $post_id, $max = 3 ) {
	$chips = get_post_meta( $post_id, '_rgh_aspect_chips', true );
	if ( empty( $chips ) ) { return ''; }
	$html = '';
	foreach ( array_slice( $chips, 0, $max ) as $c ) {
		$dot = ( $c['sentiment'] === 'good' ) ? 'good' : 'mixed';
		$html .= '<div class="mini-stat"><span class="dot ' . $dot . '"></span><div><div class="lbl">' . esc_html( $c['label'] ) . '</div><div class="sub">' . esc_html( $c['sentiment'] ) . '</div></div></div>';
	}
	return $html;
}

function rgh_aspect_chips_html( $post_id, $max = 3 ) {
	$chips = get_post_meta( $post_id, '_rgh_aspect_chips', true );
	if ( empty( $chips ) ) { return ''; }
	$html = '';
	foreach ( array_slice( $chips, 0, $max ) as $c ) {
		$dot = ( $c['sentiment'] === 'good' ) ? 'good' : 'mixed';
		$html .= '<div class="aspect-chip"><span class="dot ' . $dot . '"></span>' . esc_html( $c['label'] ) . ' — ' . esc_html( $c['sentiment'] ) . '</div>';
	}
	return $html;
}

function rgh_thumb_style( $post_id ) {
	if ( has_post_thumbnail( $post_id ) ) {
		$url = get_the_post_thumbnail_url( $post_id, 'medium' );
		return 'background-image:url(' . esc_url( $url ) . ');background-size:cover;background-position:center';
	}
	return 'background:linear-gradient(135deg,#2563EB,#14B8A6)';
}

/** Related post ids for the current review: same category, best-scored first, backfilled with latest if too few are scored. */
function rgh_related_post_ids( $post_id, $limit = 4 ) {
	$cat_ids = wp_get_post_categories( $post_id );
	if ( empty( $cat_ids ) ) { return array(); }

	$scored = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => $limit,
		'post__not_in'   => array( $post_id ),
		'category__in'   => $cat_ids,
		'meta_key'       => 'rehub_review_overall_score',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'fields'         => 'ids',
	) );
	$ids = $scored->posts;

	if ( count( $ids ) < $limit ) {
		$rest = new WP_Query( array(
			'post_type'      => 'post',
			'posts_per_page' => $limit - count( $ids ),
			'post__not_in'   => array_merge( array( $post_id ), $ids ),
			'category__in'   => $cat_ids,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		) );
		$ids = array_merge( $ids, $rest->posts );
	}
	return $ids;
}

/** Shared sidebar card renderer — a small "photo + title + rating" link list, used by both Similar Products and Latest Reviews. */
function rgh_render_post_card_list( $ids, $heading ) {
	if ( empty( $ids ) ) { return ''; }
	ob_start();
	echo '<div class="rgh-home"><div class="side-card rgh-mini-list">';
	echo '<h4>' . esc_html( $heading ) . '</h4>';
	foreach ( $ids as $id ) {
		$score = get_post_meta( $id, 'rehub_review_overall_score', true );
		$count = rgh_review_count( $id );
		echo '<a href="' . esc_url( get_permalink( $id ) ) . '" class="mini-list-item">';
		echo '<div class="thumb">';
		if ( has_post_thumbnail( $id ) ) {
			echo get_the_post_thumbnail( $id, 'thumbnail', array( 'loading' => 'lazy', 'alt' => esc_attr( get_the_title( $id ) ) ) );
		} else {
			echo '<div class="thumb-fallback" style="' . esc_attr( rgh_thumb_style( $id ) ) . '"></div>';
		}
		echo '</div>';
		echo '<div class="body"><div class="name">' . esc_html( get_the_title( $id ) ) . '</div>';
		if ( $score !== '' && $score !== false ) {
			echo '<div class="rating-line">' . rgh_stars_html( $score ) . '<span class="rating-num">' . number_format( (float) $score, 1 ) . '</span>';
			if ( $count > 0 ) { echo '<span class="rating-count">(' . number_format( $count ) . ')</span>'; }
			echo '</div>';
		}
		echo '</div></a>';
	}
	echo '</div></div>';
	return ob_get_clean();
}

/** [rgh_similar_products] — sidebar card of reviews related to the one being read, real photos + rating. */
add_shortcode( 'rgh_similar_products', function () {
	$post_id = get_the_ID();
	if ( ! $post_id ) { return ''; }
	return rgh_render_post_card_list( rgh_related_post_ids( $post_id, 4 ), 'Similar Products' );
} );

/** [rgh_recent_posts] — sidebar card of the latest reviews sitewide, same photo+rating treatment (replaces the unstyled core "Latest Posts" block). */
add_shortcode( 'rgh_recent_posts', function () {
	$exclude = is_singular( 'post' ) ? array( get_the_ID() ) : array();
	$q = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => 4,
		'post__not_in'   => $exclude,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'fields'         => 'ids',
	) );
	return rgh_render_post_card_list( $q->posts, 'Latest Reviews' );
} );

/** [rgh_categories] — the homepage "Browse by category" row, real term counts. */
add_shortcode( 'rgh_categories', function () {
	$cats = rgh_get_categories_data();
	if ( empty( $cats ) ) { return ''; }
	ob_start();
	echo '<div class="cat-row">';
	foreach ( $cats as $c ) {
		echo '<a href="' . esc_url( $c['link'] ) . '" class="cat-item">';
		echo '<div class="ico" style="background:var(' . esc_attr( $c['tint'] ) . ');color:var(' . esc_attr( $c['ink'] ) . ')"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">' . $c['icon'] . '</svg></div>';
		echo '<div class="name">' . esc_html( $c['name'] ) . '</div>';
		echo '<div class="cat-count">' . intval( $c['count'] ) . ' ' . ( $c['count'] === 1 ? 'review' : 'reviews' ) . '</div>';
		echo '</a>';
	}
	echo '</div>';
	return ob_get_clean();
} );

/** [rgh_popular_categories] — sidebar list version, real counts + photos. */
add_shortcode( 'rgh_popular_categories', function () {
	$cats = rgh_get_categories_data();
	usort( $cats, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
	$cats = array_slice( $cats, 0, 4 );
	ob_start();
	foreach ( $cats as $c ) {
		echo '<a href="' . esc_url( $c['link'] ) . '" class="side-link">';
		if ( $c['photo'] ) {
			echo '<div class="ico"><img src="' . esc_url( $c['photo'] ) . '" alt="' . esc_attr( $c['name'] ) . '"></div>';
		} else {
			echo '<div class="ico" style="background:var(' . esc_attr( $c['tint'] ) . ');color:var(' . esc_attr( $c['ink'] ) . ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">' . $c['icon'] . '</svg></div>';
		}
		echo '<div><div>' . esc_html( $c['name'] ) . '</div><div class="meta">' . intval( $c['count'] ) . ' breakdowns</div></div>';
		echo '<svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>';
		echo '</a>';
	}
	return ob_get_clean();
} );

/** [rgh_featured] — highest-scoring real post, "Most Recommended" card. */
add_shortcode( 'rgh_featured', function () {
	$q = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => 1,
		'meta_key'       => 'rehub_review_overall_score',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
	if ( ! $q->have_posts() ) { return ''; }
	$q->the_post();
	$id      = get_the_ID();
	$score   = get_post_meta( $id, 'rehub_review_overall_score', true );
	$count   = rgh_review_count( $id );
	$title   = str_replace( ' — what owners say', '', get_the_title() );
	$cats    = get_the_category();
	$catname = ! empty( $cats ) ? $cats[0]->name : '';

	$chips = get_post_meta( $id, '_rgh_aspect_chips', true );
	$hook  = ! empty( $chips[0]['label'] ) ? $chips[0]['label'] : '';
	$dek   = get_the_excerpt();

	ob_start();
	?>
	<div class="featured">
		<div class="featured-visual" style="<?php echo esc_attr( rgh_thumb_style( $id ) ); ?>">
			<div class="featured-badge">Most Recommended</div>
			<?php if ( ! has_post_thumbnail( $id ) ) : ?>
				<svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
			<?php endif; ?>
		</div>
		<div class="featured-body">
			<div class="tag-pill">Most Recommended This Month</div>
			<h3><?php echo esc_html( $title ); ?></h3>
			<div class="rating-line">
				<?php echo rgh_stars_html( $score ); ?>
				<span class="rating-num"><?php echo number_format( (float) $score, 1 ); ?></span>
				<?php if ( $count > 0 ) : ?><span class="rating-count">(<?php echo number_format( $count ); ?> reviews)</span><?php endif; ?>
			</div>
			<p class="dek"><?php if ( $hook ) : ?><strong class="hook"><?php echo esc_html( $hook ); ?>.</strong> <?php endif; echo esc_html( $dek ); ?></p>
			<div class="mini-stats"><?php echo rgh_mini_stats_html( $id ); ?></div>
			<a href="<?php the_permalink(); ?>" class="featured-cta">Read the full breakdown &rarr;</a>
		</div>
	</div>
	<?php
	wp_reset_postdata();
	return ob_get_clean();
} );

/** [rgh_latest_reviews limit="4"] — latest real posts, review-row list. */
add_shortcode( 'rgh_latest_reviews', function ( $atts ) {
	$atts = shortcode_atts( array( 'limit' => 4 ), $atts );
	$q = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => (int) $atts['limit'],
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
	if ( ! $q->have_posts() ) { return ''; }

	ob_start();
	echo '<div class="review-list">';
	while ( $q->have_posts() ) {
		$q->the_post();
		$id     = get_the_ID();
		$score  = get_post_meta( $id, 'rehub_review_overall_score', true );
		$count  = rgh_review_count( $id );
		$cats   = get_the_category();
		$cat    = ! empty( $cats ) ? $cats[0]->name : '';
		$title = get_the_title();
		?>
		<div class="review-row">
			<div class="review-thumb-sm" style="<?php echo esc_attr( rgh_thumb_style( $id ) ); ?>"></div>
			<div class="review-body">
				<div class="eyebrow"><?php echo esc_html( strtoupper( $cat ) ); ?></div>
				<h3><a href="<?php the_permalink(); ?>"><?php echo esc_html( $title ); ?></a></h3>
				<div class="rating-line">
					<?php echo rgh_stars_html( $score ); ?>
					<span class="rating-num"><?php echo number_format( (float) $score, 1 ); ?></span>
					<?php if ( $count > 0 ) : ?><span class="rating-count">(<?php echo number_format( $count ); ?> reviews)</span><?php endif; ?>
				</div>
				<p><?php echo esc_html( get_the_excerpt() ); ?></p>
			</div>
			<div class="review-meta">
				<a href="<?php the_permalink(); ?>" class="read">Read breakdown &rarr;</a>
				<span class="date"><?php echo esc_html( get_the_date( 'M j, Y' ) ); ?></span>
			</div>
		</div>
		<?php
	}
	echo '</div>';
	wp_reset_postdata();
	return ob_get_clean();
} );

/**
 * Renders a full review-list page (title, optional intro, paginated rows) driven by
 * WordPress's own main query - used by home.php (the Reviews posts-page) and
 * category.php (category archives), replacing the theme's dated "communitylist"
 * loop (deal-score thumbs, heart icon, no rating) with the same row component the
 * homepage already uses.
 */
function rgh_render_review_index( $title, $intro = '' ) {
	ob_start();
	echo '<div class="rgh-home rgh-index">';
	echo '<div class="rgh-page-header"><h1>' . esc_html( $title ) . '</h1>';
	if ( $intro ) { echo '<p>' . esc_html( $intro ) . '</p>'; }
	echo '</div>';

	if ( have_posts() ) {
		echo '<div class="review-list">';
		while ( have_posts() ) {
			the_post();
			$id    = get_the_ID();
			$score = get_post_meta( $id, 'rehub_review_overall_score', true );
			$count = rgh_review_count( $id );
			$cats  = get_the_category();
			$cat   = ! empty( $cats ) ? $cats[0]->name : '';
			?>
			<div class="review-row">
				<div class="review-thumb-sm" style="<?php echo esc_attr( rgh_thumb_style( $id ) ); ?>"></div>
				<div class="review-body">
					<?php if ( $cat ) : ?><div class="eyebrow"><?php echo esc_html( strtoupper( $cat ) ); ?></div><?php endif; ?>
					<h3><a href="<?php the_permalink(); ?>"><?php echo esc_html( get_the_title() ); ?></a></h3>
					<?php if ( $score !== '' && $score !== false ) : ?>
					<div class="rating-line">
						<?php echo rgh_stars_html( $score ); ?>
						<span class="rating-num"><?php echo number_format( (float) $score, 1 ); ?></span>
						<?php if ( $count > 0 ) : ?><span class="rating-count">(<?php echo number_format( $count ); ?> reviews)</span><?php endif; ?>
					</div>
					<?php endif; ?>
					<p><?php echo esc_html( get_the_excerpt() ); ?></p>
				</div>
				<div class="review-meta">
					<a href="<?php the_permalink(); ?>" class="read">Read breakdown &rarr;</a>
					<span class="date"><?php echo esc_html( get_the_date( 'M j, Y' ) ); ?></span>
				</div>
			</div>
			<?php
		}
		echo '</div>';
		echo '<div class="rgh-pagination">';
		rehub_pagination();
		echo '</div>';
	} else {
		echo '<p>No reviews here yet.</p>';
	}

	echo '</div>';
	return ob_get_clean();
}

/** [rgh_best_rated limit="10"] — all reviews sorted by Consensus Score, same row markup. */
add_shortcode( 'rgh_best_rated', function ( $atts ) {
	$atts = shortcode_atts( array( 'limit' => 10 ), $atts );
	$q = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => (int) $atts['limit'],
		'meta_key'       => 'rehub_review_overall_score',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
	if ( ! $q->have_posts() ) { return '<p>No rated reviews yet.</p>'; }

	ob_start();
	echo '<div class="review-list">';
	while ( $q->have_posts() ) {
		$q->the_post();
		$id    = get_the_ID();
		$score = get_post_meta( $id, 'rehub_review_overall_score', true );
		$count = rgh_review_count( $id );
		$cats  = get_the_category();
		$cat   = ! empty( $cats ) ? $cats[0]->name : '';
		?>
		<div class="review-row">
			<div class="review-thumb-sm" style="<?php echo esc_attr( rgh_thumb_style( $id ) ); ?>"></div>
			<div class="review-body">
				<div class="eyebrow"><?php echo esc_html( strtoupper( $cat ) ); ?></div>
				<h3><a href="<?php the_permalink(); ?>"><?php echo esc_html( get_the_title() ); ?></a></h3>
				<div class="rating-line">
					<?php echo rgh_stars_html( $score ); ?>
					<span class="rating-num"><?php echo number_format( (float) $score, 1 ); ?></span>
					<?php if ( $count > 0 ) : ?><span class="rating-count">(<?php echo number_format( $count ); ?> reviews)</span><?php endif; ?>
				</div>
				<p><?php echo esc_html( get_the_excerpt() ); ?></p>
			</div>
			<div class="review-meta">
				<a href="<?php the_permalink(); ?>" class="read">Read breakdown &rarr;</a>
				<span class="date"><?php echo esc_html( get_the_date( 'M j, Y' ) ); ?></span>
			</div>
		</div>
		<?php
	}
	echo '</div>';
	wp_reset_postdata();
	return ob_get_clean();
} );
