<?php
	/******************************************************************
	 *
	 *   Tidypics Admin Settings 
	 *
	 *******************************************************************/

	include_once dirname(dirname(dirname(dirname(__FILE__)))) . "/engine/start.php";

	global $CONFIG;

	admin_gatekeeper();
	set_context('admin');
	
	$tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';

	$body = elgg_view_title(elgg_echo('tidypics:administration'));
	
	$body .= elgg_view("tidypics/admin/tidypics", array('tab' => $tab));
	
	page_draw(elgg_echo('tidypics:administration'), elgg_view_layout("administration", $body));

?>