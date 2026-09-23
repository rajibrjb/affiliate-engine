<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<?php
/**
 * Overrides the parent theme's dated "communitylist" loop for category archives
 * with the same review-row component the homepage/Reviews page use. See
 * rgh_render_review_index() in rgh-shortcodes.php.
 */
get_header();
$term  = single_cat_title( '', false );
$count = (int) $GLOBALS['wp_query']->found_posts;
$intro = $count . ' ' . ( 1 === $count ? 'review' : 'reviews' ) . ' in this category, ranked by relevance.';
?>
<div class="rh-container">
	<div class="rh-content-wrap clearfix">
		<div class="main-side clearfix">
			<?php echo rgh_render_review_index( $term, $intro ); ?>
		</div>
		<?php get_sidebar(); ?>
	</div>
</div>
<?php get_footer(); ?>
