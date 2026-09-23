<?php

add_action( 'wp_enqueue_scripts', 'enqueue_parent_theme_style' );
function enqueue_parent_theme_style() {
    wp_enqueue_style( 'parent-style', get_template_directory_uri().'/style.css' );
	if (is_rtl()) {
		 wp_enqueue_style( 'parent-rtl', get_template_directory_uri().'/rtl.css', array(), RH_MAIN_THEME_VERSION);
	}
}

add_action( 'wp_enqueue_scripts', 'rgh_enqueue_brand_assets' );
function rgh_enqueue_brand_assets() {
	wp_enqueue_style( 'rgh-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', array(), null );
	wp_enqueue_style( 'rgh-site', get_stylesheet_directory_uri() . '/rgh-site.css', array(), filemtime( get_stylesheet_directory() . '/rgh-site.css' ) );
}

require_once get_stylesheet_directory() . '/rgh-shortcodes.php';
require_once get_stylesheet_directory() . '/rgh-review-feature.php';
require_once get_stylesheet_directory() . '/rgh-pages-feature.php';
require_once get_stylesheet_directory() . '/rgh-schema-feature.php';

?>
