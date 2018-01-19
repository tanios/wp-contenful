<?php

//initialising the plugin
$plugin = new Tanios\ContentfulWp\Plugin( dirname( __FILE__ ) );

//content types
if( file_exists( $plugin->getPath() . '/content_types/content_types.php' ) )
{
	include $plugin->getPath() . '/content_types/content_types.php';
}