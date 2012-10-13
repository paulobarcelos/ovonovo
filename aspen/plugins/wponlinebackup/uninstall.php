<?php

/*
Uninstall code
*/

// Check the WP_UNINSTALL_PLUGIN and make sure we are uninstalling wponlinebackup.php
if (
	defined( 'WP_UNINSTALL_PLUGIN' )
&&	strtolower( WP_UNINSTALL_PLUGIN ) == strtolower( plugin_basename( dirname( __FILE__ ) ) . '/wponlinebackup.php' )
) {

	global $wpdb;

	function WPOnlineBackup_Get_WPDB_Prefix()
	{
		global $wpdb;

		// Multisite can give different prefix for different blog... so make sure we get the base prefix
		// This call is more reliable than accessing base_prefix directly
		if ( is_callable( array( $wpdb, 'get_blog_prefix' ) ) )
			return $wpdb->get_blog_prefix( 0 );

		// No multisite features so return the standard prefix
		return $wpdb->prefix;
	}

	$db_prefix = WPOnlineBackup_Get_WPDB_Prefix();

	//TODO:Check for in progress backups and delete the files if at all possible.

	// Cleanup the options
	delete_option( 'wponlinebackup_db_version' );
	delete_option( 'wponlinebackup_status' );
	delete_option( 'wponlinebackup_settings' );
	delete_option( 'wponlinebackup_schedule' );
	delete_option( 'wponlinebackup_last_full' );
	delete_option( 'wponlinebackup_temps' );
	delete_option( 'wponlinebackup_bsn' );
	delete_option( 'wponlinebackup_in_sync' );
	delete_option( 'wponlinebackup_quota' );
	delete_option( 'wponlinebackup_last_gzip_tmp_dir' );

	// Cleanup the database tables
	$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_status`' );
	$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_items`' );
	$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_generations`' );
	$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_scan_log`' );
	$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_activity_log`' );
	$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_event_log`' );

	// Cleanup legacy tables in case they never got upgraded (should never happen)
	$wpdb->query( 'DROP TABLE `' . $db_prefix . 'online_backup`' );

}

?>
