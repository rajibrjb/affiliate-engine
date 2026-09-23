<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<?php
/**
 * Overrides the parent theme's dated "communitylist" loop (deal-score thumbs,
 * heart icon, no rating) for the Reviews posts-page with the same review-row
 * component the homepage already uses. See rgh_render_review_index() in
 * rgh-shortcodes.php.
 */
get_header();
?>
<div class="rh-container">
	<div class="rh-content-wrap clearfix">
		<div class="main-side clearfix">
			<?php echo rgh_render_review_index( 'Reviews', "Every product we've broken down, newest first." ); ?>
		</div>
		<?php get_sidebar(); ?>
	</div>
</div>
<?php get_footer(); ?>
