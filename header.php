<?php
/** Minimal document head. */
defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php // Dark preference applied pre-paint to avoid a light flash. ?>
	<script>try{if(localStorage.getItem('cc_dark')==='1'){document.documentElement.classList.add('dark')}}catch(e){}</script>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
