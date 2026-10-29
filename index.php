<?php
/**
 * Fallback — this theme is a single-page app shell; anything that isn't
 * the front page routes back to it.
 */
wp_safe_redirect( home_url( '/' ) );
exit;
