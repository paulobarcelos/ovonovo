<?php
/*
Plugin Name: Online Backup for WordPress
Plugin URI: http://www.backup-technology.com/free-wordpress-backup/
Description: Online Backup for WordPress can automatically backup your WordPress database and filesystem on a configurable schedule and can incrementally send the backup compressed (and optionally encrypted using DES or AES) to our online vault where you can later retrieve it. Backups can also be emailed to you or produced on-demand and downloaded straight to your computer. You can view the current status and change settings at "Tools -> Online Backup", or by clicking the "View Status" link next to the plugin name in the Plugins list.
Author: Jason Woods @ Backup Technology
Version: 2.2.6
Author URI: http://www.backup-technology.com/
Licence: GPLv2 - See LICENCE.txt
*/

/*
This file must be called wponlinebackup.php, or the uninstall.php file must also be modified to reflect the plugin's new filename
If this file is renamed and uninstall.php is not modified, the uninstaller will not trigger when the plugin is removed
*/

// Die if we haven't been included by WordPress
if ( !defined( 'ABSPATH' ) ) die();

// Version
define( 'WPONLINEBACKUP_VERSION', '2.2.6' );
define( 'WPONLINEBACKUP_DBVERSION', 7 );

// Prepare the paths
// - Symlink issue in plugin_basename but appears to be a planned fix in WordPress internally using an add_filter on plugin_basename
//   so we can treat symlinking as something the person configuring the blog should be accounting for.
define( 'WPONLINEBACKUP_FILE', plugin_basename( __FILE__ ) );
define( 'WPONLINEBACKUP_FILEPATH', __FILE__ );
define( 'WPONLINEBACKUP_DIR', dirname( WPONLINEBACKUP_FILE ) );
define( 'WPONLINEBACKUP_LANG', WPONLINEBACKUP_DIR . '/lang' );
define( 'WPONLINEBACKUP_PATH', preg_replace( '#/$#', '', plugin_dir_path( __FILE__ ) ) ); // BTL code styling requires we do not have forward slash!
define( 'WPONLINEBACKUP_TMP', WPONLINEBACKUP_PATH . '/tmp' );
// WPONLINEBACKUP_URL requires the use of plugin_dir_url which should only be called during init()
// It is also only used in the administration pages, so it is now defined in WPOnlineBackup_Admin::Init() which is our admin_init action

// Ensure PHP newline is defined (it is since PHP 4.3.10 and PHP 5.0.2)
if ( !defined( 'PHP_EOL' ) )
	define( 'PHP_EOL', "\n" ); // Default to Linux style

// Exception helpers
function OBFW_Exception()
{
	// PHP 4 does not have error_get_last()
	// - We could possibly improve this by passing $php_errormsg to this function as if track_errors in On it will have the error message
	// - A better solution would probably to set our own error handler - but that may not be worth the time as people are gradually shifting to PHP 5
	if ( !function_exists('error_get_last') )
		return 'Unknown error';

	$err = error_get_last();
	if ( is_null($err) )
		return 'No message was logged.';

	// If the last error was an E_STRICT notice it will most likely be due to our PHP 4 compatibility so pretend no message was logged
	if ( defined('E_STRICT') && $err['type'] == E_STRICT )
		return 'No error message was logged.';

	return 'An error happened at: ' . basename( $err['file'] ) . '(' . $err['line'] . ')' . PHP_EOL .
		$err['message'];
}

function OBFW_Exception_WP( $wp_error )
{
	$codes = $wp_error->get_error_codes();
	$messages = $wp_error->get_error_messages();

	$errors = array();
	foreach ( $codes as $key => $code )
		$errors[] = '[Error Code ' . $code . '] ' . $messages[$key];

	return implode( PHP_EOL, $errors );
}

/*
WPOnlineBackup - Main wrapper, contains the minimum functions required to get things going
Other required code is in the include directory and only brought in if required
*/

class WPOnlineBackup
{
	// Settings and capabilities arrays
	/*private*/ var $settings_stored = null; // These are the STORED settings
	/*private*/ var $settings_default = null; // These are the DEFAULT settings, for where there isn't one overriding it in the STORED settings
	/*private*/ var $env = null;

	// Bootstrap object
	/*public*/ var $bootstrap = null;

	// Scheduler object
	/*public*/ var $scheduler = null;

	// Administration object
	/*public*/ var $admin = null;

	/*public*/ function WPOnlineBackup()
	{
		// Upgrade check... Since 3.1 the activation hook doesn't trigger during updates (WordPress #14915)
		if ( get_option( 'wponlinebackup_db_version', '' ) === '1.0' || get_option( 'wponlinebackup_db_version', 0 ) < WPONLINEBACKUP_DBVERSION )
			$this->Activate();

		// Check the DB tables exist - creating them if needed - we exclude this option during backup meaning we get the default here - 1
		if ( get_option( 'wponlinebackup_check_tables', 1 ) )
			$this->Check_Tables();

		// Register activation, deactivation, and uninstallation hooks
		register_activation_hook( WPONLINEBACKUP_FILE, array( & $this, 'Activate' ) );
		register_deactivation_hook( WPONLINEBACKUP_FILE, array( & $this, 'Deactivate' ) );

		// Register initialisation hook
		add_action( 'init', array( & $this, 'Init' ) );

		// Register backup hook
		add_action( 'wponlinebackup_start', array( & $this, 'Action_Start' ) );
		add_action( 'wponlinebackup_perform', array( & $this, 'Action_Perform' ) );
		add_action( 'wponlinebackup_perform_check', array( & $this, 'Action_Perform_Check' ) );

		// Are we admin page?
		if ( is_admin() ) {

			// Bring in the administration
			require_once WPONLINEBACKUP_PATH . '/include/admin.php';

			$this->admin = new WPOnlineBackup_Admin( $this );

		}
	}

	/*public*/ function Activate()
	{
		global $wpdb;

		// Grab the database prefix to use
		$db_prefix = WPOnlineBackup::Get_WPDB_Prefix();

		// Get current database version
		$dbv = get_option( 'wponlinebackup_db_version', '' );

		// If database version is not 1.0 and not an integer, this is either new installation or 1.0.3 or below
		if ( $dbv !== '1.0' && !is_numeric( $dbv ) ) {

			// Upgrade from 1.0.3 or below, remove old stuff we no longer need.
			if ( get_option('wponlinebackup_progress') !== false ) {

				// No longer used - we use database instead for atomic operations
				delete_option( 'wponlinebackup_progress' );
				delete_option( 'wponlinebackup_status' );

				// Convert schedule from legacy to V1 format
				$this->Load_Scheduler();
				$this->scheduler->Update();

				$dbv = 1;

			} else {

				// New installation - no upgrades required, set to latest DB version
				$dbv = WPONLINEBACKUP_DBVERSION;

			}

		}

		// Translate database version 1.0 into 1
		if ( $dbv === '1.0' ) $dbv = 1;

		if ( $dbv < 2 ) {

			// Upgrade from database version 1 to version 2

			// Drop old table if it exists
			if ( $wpdb->get_var( 'SHOW TABLES LIKE \'' . $db_prefix . 'online_backup\'' ) === $db_prefix . 'online_backup' )
				$wpdb->query( 'DROP TABLE `' . $db_prefix . 'online_backup`' );

			// Load settings - we don't use Load_Settings() as it assumes the settings to be the latest version, of which they aren't at the moment
			$this->settings_stored = get_option( 'wponlinebackup_settings' );

			// Add new settings
			$this->settings_stored = array_merge( $this->settings_stored, array(
				'max_log_age'		=> 6,
				'ignore_trash_comments'	=> false,
				'ignore_spam_comments'	=> false,

				'filesystem_upone'	=> false,
				'filesystem_themes'	=> true,
				'filesystem_plugins'	=> true,
				'filesystem_uploads'	=> true,

				'gzip_tmp_dir'		=> $this->settings_stored['tmp_dir'],
			) );

			// Remove old settings
			unset( $this->settings_stored['tmp_dir'] );

			// If the gzip temporary directory setting is one of the environment variables, or it is "/tmp", unset it so we use the environment variable
			if ( $this->settings_stored['gzip_tmp_dir'] == WPOnlineBackup::Get_Temp() || $this->settings_stored['gzip_tmp_dir'] == '/tmp' ) unset( $this->settings_stored['gzip_tmp_dir'] );

			// Save the new settings
			$this->Save_Settings();

			// Convert schedule from V1 format to V2
			$this->Load_Scheduler();
			$this->scheduler->Update_V1();

		}

		if ( $dbv < 4 ) {

			// Upgrade from database version 2 and 3 to version 4
			// This used to be the upgrade to database version 3 - but we did not add this option on fresh installations
			// So we increase db version again and add the setting again

			// Destroy any legacy schedules that were left behind
			wp_clear_scheduled_hook( 'WPOnlineBackup_Perform' );
			wp_clear_scheduled_hook( 'WPOnlineBackup_Perform_Check' );

			// Load settings - we don't use Load_Settings() as it assumes the settings to be the latest version, of which they aren't at the moment
			$this->settings_stored = get_option( 'wponlinebackup_settings' );

			// Add new settings
			$this->settings_stored = array_merge( $this->settings_stored, array(
				'max_log_age'		=> 6,
			) );

			// Save the new settings
			$this->Save_Settings();

		}

		if ( $dbv < 5 ) {

			// Upgrade from database version 4 to version 5

			// Load settings - we don't use Load_Settings() as it assumes the settings to be the latest version, of which they aren't at the moment
			$this->settings_stored = get_option( 'wponlinebackup_settings' );

			// Remove old settings
			unset( $this->settings_stored['large_file_size'] );
			unset( $this->settings_stored['large_file_block_size'] );
			unset( $this->settings_stored['split_file_size'] );
			unset( $this->settings_stored['split_file_block_size'] );

			// Save the new settings
			$this->Save_Settings();

		}

		if ( $dbv < 7 ) {

			// Upgrade to database version 7

			// Load settings - we don't use Load_Settings() as it assumes the settings to be the latest version, of which they aren't at the moment
			$this->settings_stored = get_option( 'wponlinebackup_settings' );

			// Add new settings
			$this->settings_stored = array_merge( $this->settings_stored, array(
				'filesystem_excludes'	=> '',
			) );

			// Save the new settings
			$this->Save_Settings();

		}

		// If newer DB version - we rollback by deleting the settings - we just cannot know what we'll add or change in the future
		if ( $dbv > WPONLINEBACKUP_DBVERSION ) {

			// Cleanup the options
			delete_option( 'wponlinebackup_db_version' );
			delete_option( 'wponlinebackup_status' );
			delete_option( 'wponlinebackup_settings' );
			delete_option( 'wponlinebackup_schedule' );
			delete_option( 'wponlinebackup_last_full' );
			delete_option( 'wponlinebackup_temps' );
			delete_option( 'wponlinebackup_bsn' );
			delete_option( 'wponlinebackup_in_sync' );

			// Cleanup the database tables
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_status`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_items`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_generations`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_scan_log`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_activity_log`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_event_log`' );

		}

		// Add/update database version setting
		add_option( 'wponlinebackup_db_version', WPOLINEBACKUP_DBVERSION, '', 'yes' );
		update_option( 'wponlinebackup_db_version', WPONLINEBACKUP_DBVERSION );

		// Check the DB tables exist - creating them if needed
		$this->Check_Tables();

		// Create default settings (except db_version and check_tables which are already done)
		add_option( 'wponlinebackup_settings', array(

			'username'		=> '',
			'password'		=> '',

			'encryption_type'	=> '',
			'encryption_key'	=> '',

			'selection_method'	=> 'exclude',
			'selection_list'	=> array(),
			'ignore_trash_comments'	=> false,
			'ignore_spam_comments'	=> false,

			'filesystem_upone'	=> false,
			'filesystem_themes'	=> true,
			'filesystem_plugins'	=> true,
			'filesystem_uploads'	=> true,
			'filesystem_excludes'	=> '',

			'max_log_age'		=> 6,

		), '', 'no' );

		add_option( 'wponlinebackup_schedule', array(
			'schedule'		=> '',
			'day'			=> 0,
			'hour'			=> 0,
			'minute'		=> 0,
			'next_trigger'		=> null,
			'target'		=> 'online',
			'email_to'		=> '',
			'backup_database'	=> true,
			'backup_filesystem'	=> true,
		), '', 'no' );

		add_option( 'wponlinebackup_last_full', array(), '', 'no' );

		add_option( 'wponlinebackup_temps', array(), '', 'no' );

		add_option( 'wponlinebackup_bsn', 0, '', 'no' );

		add_option( 'wponlinebackup_in_sync', 0, '', 'no' );

		add_option( 'wponlinebackup_quota', array(), '', 'no' );

		add_option( 'wponlinebackup_last_gzip_tmp_dir', false, '', 'no' );

		// Force us to find temporary directory again
		update_option( 'wponlinebackup_last_gzip_tmp_dir', '' );

		$this->Load_Scheduler();
		$this->scheduler->Restart();
	}

	/*public*/ function Check_Tables()
	{
		global $wpdb;

		// Grab the database prefix to use
		$db_prefix = WPOnlineBackup::Get_WPDB_Prefix();

		// Ensure dbDelta is available
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Validate all tables except items
		dbDelta( <<<SQL
CREATE TABLE `{$db_prefix}wponlinebackup_status` (
	`status` TINYINT(1) UNSIGNED NOT NULL,
	`time` INT(10) UNSIGNED NOT NULL,
	`counter` INT(10) UNSIGNED NOT NULL,
	`stop_user` VARCHAR(255) NOT NULL,
	`progress` BLOB NOT NULL,
	PRIMARY KEY  (`status`, `time`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_generations` (
	`bin` INT(10) UNSIGNED NOT NULL,
	`item_id` INT(10) UNSIGNED NOT NULL,
	`backup_time` INT(10) UNSIGNED NOT NULL,
	`deleted_time` int(10) unsigned DEFAULT NULL,
	`file_size` int(10) unsigned DEFAULT NULL,
	`stored_size` int(10) unsigned DEFAULT NULL,
	`mod_time` int(10) unsigned DEFAULT NULL,
	`new_deleted_time` int(10) unsigned DEFAULT NULL,
	`commit` smallint(1) unsigned NOT NULL,
	PRIMARY KEY  (`bin`,`item_id`,`backup_time`),
	KEY `commit` (`commit`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_scan_log` (
	`scan_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`parent_id` INT(10) UNSIGNED NOT NULL,
	`name` VARCHAR(255) NOT NULL,
	PRIMARY KEY  (`scan_id`),
	UNIQUE (`parent_id`,`name`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_activity_log` (
	`activity_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`start` INT(10) UNSIGNED NOT NULL,
	`end` INT(10) UNSIGNED NULL,
	`comp` TINYINT(2) NOT NULL,
	`type` TINYINT(1) NOT NULL,
	`media` TINYINT(1) NOT NULL,
	`encrypted` TINYINT(1) NOT NULL,
	`compressed` TINYINT(1) NOT NULL,
	`errors` INT(10) UNSIGNED NOT NULL,
	`warnings` INT(10) UNSIGNED NOT NULL,
	`bsize` BIGINT(20) UNSIGNED NOT NULL,
	`bcount` INT(10) UNSIGNED NOT NULL,
	`rsize` BIGINT(20) UNSIGNED NOT NULL,
	`rcount` INT(10) UNSIGNED NOT NULL,
	PRIMARY KEY  (`activity_id`),
	KEY `start` (`start`),
	KEY `end` (`end`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_event_log` (
	`event_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`activity_id` INT(10) UNSIGNED NOT NULL,
	`time` INT(10) UNSIGNED NOT NULL,
	`type` TINYINT(1) UNSIGNED NOT NULL,
	`event` TEXT NOT NULL,
	PRIMARY KEY  (`event_id`),
	KEY `activity_id` (`activity_id`)
);
SQL
);

		// Clear the status table
		$wpdb->query( 'DELETE FROM `' . $db_prefix . 'wponlinebackup_status`;' );

		// Pre-populate the status table
		$wpdb->insert(
			$db_prefix . 'wponlinebackup_status',
			array(
				'status'	=> 0, // 0 = WPONLINEBACKUP_STATUS_NONE ( bootstrap is not included yet )
				'time'		=> 0,
				'counter'	=> 0,
				'stop_user'	=> '',
				'progress'	=> '',
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// Prepare items table query - must be MyISAM as InnoDB does not support multi-column index with an AUTO_INCREMENT
		$sql = <<<SQL
CREATE TABLE `{$db_prefix}wponlinebackup_items` (
	`bin` INT(10) UNSIGNED NOT NULL,
	`item_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`parent_id` INT(10) UNSIGNED NOT NULL,
	`type` SMALLINT(1) UNSIGNED NOT NULL,
	`name` VARCHAR(255) NOT NULL,
	`exists` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`file_size` INT(10) UNSIGNED DEFAULT NULL,
	`mod_time` INT(10) UNSIGNED DEFAULT NULL,
	`backup` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`new_exists` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`new_file_size` INT(10) UNSIGNED DEFAULT NULL,
	`new_mod_time` INT(10) UNSIGNED DEFAULT NULL,
	`activity_id` INT(10) UNSIGNED NOT NULL,
	`counter` INT(10) UNSIGNED NOT NULL,
	`path` TEXT NOT NULL,
	PRIMARY KEY  (`bin`,`item_id`),
	UNIQUE `item` (`bin`,`parent_id`,`type`,`name`),
	KEY `browse` (`bin`,`parent_id`,`exists`,`type`,`name`),
	KEY `activity_id` (`activity_id`,`backup`,`bin`,`item_id`),
	KEY `exists` (`bin`,`exists`,`activity_id`)
)
SQL;

		// If the items table already exists, run dbDelta and then adjust the character set of the table and columns
		// Otherwise, create it directly with the character set
		if ( $wpdb->get_var( 'SHOW TABLES LIKE \'' . $db_prefix . 'wponlinebackup_items\'' ) === $db_prefix . 'wponlinebackup_items' ) {

			// Validate the wponlinebackup_items table
			dbDelta( $sql . ';' );

			// Adjust collation
			$wpdb->query( 'ALTER TABLE `' . $db_prefix . 'wponlinebackup_items` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci ENGINE=MyISAM' );
			$wpdb->query( 'ALTER TABLE `' . $db_prefix . 'wponlinebackup_items` ' .
					'MODIFY `name` VARCHAR(255) CHARSET utf8 NOT NULL, ' .
					'MODIFY `path` TEXT CHARSET utf8 NOT NULL' );

		} else {

			// Create
			$wpdb->query( $sql . ' DEFAULT CHARSET utf8 COLLATE utf8_general_ci ENGINE=MyISAM' );

		}

		// Add/update the check tables option
		add_option( 'wponlinebackup_check_tables', 0, '', 'yes' );
		update_option( 'wponlinebackup_check_tables', 0 );
	}

	/*public*/ function Deactivate()
	{
		//TODO:Abort running backups and cleanup files

		// Clear the schedule hooks - we don't want them running when the plugin is deactivated!
		wp_clear_scheduled_hook( 'wponlinebackup_start' );
		wp_clear_scheduled_hook( 'wponlinebackup_perform' );
		wp_clear_scheduled_hook( 'wponlinebackup_perform_check' );
	}

	/*public*/ function Init()
	{
		if ( isset( $_GET['wponlinebackup_fetch'] ) || isset( $_POST['wponlinebackup_fetch'] ) ) {

			// Retrieving a backup
			$this->Load_BootStrap();
			if ( $this->bootstrap->Process_Pull() ) exit;

		} else if ( isset( $_GET['wponlinebackup_do'] ) ) {

			// Kick starting a new backup session
			$this->Load_BootStrap();
			$this->bootstrap->Perform();
			exit;

		} else if ( isset( $_GET['wponlinebackup_do_check'] ) ) {

			// Kick starting a perform check session
			$this->Load_BootStrap();
			$this->bootstrap->Perform_Check();
			exit;

		}
	}

	/*public*/ function Action_Start()
	{
		// Load the bootstrap, scheduler and settings
		$this->Load_Scheduler();
		$this->Load_BootStrap();

		$this->scheduler->Restart( true );

		// Prepare the scheduled backup configuration
		$config = array(
			'backup_database'	=> $this->scheduler->schedule['backup_database'],
			'backup_filesystem'	=> $this->scheduler->schedule['backup_filesystem'],
			'target'		=> $this->scheduler->schedule['target'],
			'email_to'		=> $this->scheduler->schedule['email_to'],
		);

		// Start the backup with immediate effect
		$this->bootstrap->Start( $config, WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP, true );
		exit;
	}

	/*public*/ function Action_Perform()
	{
		// Perform backup - load the bootstrap and run it
		$this->Load_BootStrap();
		$this->bootstrap->Perform();
		exit;
	}

	/*public*/ function Action_Perform_Check()
	{
		// Perform backup check - load the bootstrap and run it
		$this->Load_BootStrap();
		$this->bootstrap->Perform_Check();
		exit;
	}

	/*public*/ function Load_Settings()
	{
		// Check we haven't already loaded
		if ( !is_null( $this->settings_stored ) ) return;

		// Load bits and bobs
		$this->settings_stored = get_option( 'wponlinebackup_settings' );

		// Prepare the defaults - these settings will have "Override default" checkboxes on advanced settings page
		// We store these separately so we can store overriedes in settings_stored
		$this->settings_default = array(
			// Maximum execution time of a backup
			'max_execution_time'	=> null, // on-the-fly in Get_Setting()
			// Minimum execution time so we don't cause a massive stream of local loads - only affects Tick() so if the backup finishes it won't delay it
			'min_execution_time'	=> 15,
			// Time that passes between backup recovery checks - must be at least 120 - 2 times the maximum wordpress cron frequency (60)
			'timeout_recovery_time'	=> 130,
			// Time that must pass before we presume a backup to have died completely, and allow a new one to be started. Set large for sites with low visitor count
			'time_presumed_dead'	=> 7200, // 2 hours
			// Local temporary directory - used for all backup files, except those we cannot protect with a Rejection header
			'local_tmp_dir'		=> WPONLINEBACKUP_TMP,
			// Gzip temporary directory - only used for processing large files which forces us to use gzopen() without a Rejection header
			'gzip_tmp_dir'		=> null, // on-the-fly in Get_Setting()
			// Tables that always backup and are not optional
			'core_tables'		=> array(
				'comments', 'commentmeta', 'links', 'options', 'postmeta', 'posts',
				'term_relationships', 'term_taxonomy', 'terms', 'usermeta', 'users',
			),
			// Block sizes for backup processing - we may make these dynamic in future based on available memory so we can fully optimize backups
			'dump_segment_size'	=> 200, // Rows for table backup - we count data size as we process to not go past max_block_size
			'sync_segment_size'	=> 500, // Rows for synchronisation
			'max_block_size'	=> null, // on-the-fly in Get_Setting()
			// The following sizes will be used for buffers saved in the state to the database - so consider max_allowed_packet MySQL setting (sometimes as low as 1 MiB)...
			'file_buffer_size'	=> 1024 * 8, // 8 KiB
			'encryption_block_size' => 1024 * 8, // 8 KiB
			// Maximum number of retries to make on a backup that keeps timing out, and makes no progress each attempt
			// - if it makes no progress twice, but the next run makes progress, this counter is reset - see max_progress_retries to limit even if progress is made
			'max_frozen_retries'	=> 4,
			// Maximum number of retries to make on a backup that keeps timing out, but actually makes progress each time, 0 = no maximum, keep going
			'max_progress_retries'	=> 0,
			// Some servers are poor and have old certificate chains installed, and do not recognise the wordpress.backup-technology.com certificate
			// - if set to true, this will disable the certificate check, and lower the overall security of the plugin, although it might be considered that encryption alleviates this somewhat
			'ignore_ssl_cert'	=> false,
			// Number of ticks before actually saving the current state - speeds up backup phenominally on fast servers
			// If we timeout, we change to 1 so we keep updating state, until we get past the blockage, where we reset to default again
			'update_ticks'		=> 100,
			// Number of times to retry transmission operations to the online vault
			'remote_api_retries'	=> 5,
			// Should we fall back to the wpdb API? We now prefer to use the MySQL API directly so we can manage memory errors better (they are not fatal in the MySQL client)
			'use_wpdb_api'		=> null,
		);

		// Examine environment capabilities
		$this->env = array(
			'inc_hash_available'	=> function_exists( 'hash_copy' ),
			'deflate_available'	=> function_exists( 'gzdeflate' ),
		);

		// Check encryption capabilities
		$available = array();
		if ( function_exists( 'mcrypt_module_open' ) ) {
			if ( defined( 'MCRYPT_DES' ) && $c = mcrypt_module_open( MCRYPT_DES, '', MCRYPT_MODE_CBC, '' ) ) {
				$available['DES'] = 'DES';
				mcrypt_module_close( $c );
			}
			if ( defined( 'MCRYPT_RIJNDAEL_128' ) && $c = mcrypt_module_open( MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '' ) ) {
				$available['AES128'] = 'AES128';
				$available['AES192'] = 'AES192';
				$available['AES256'] = 'AES256';
				mcrypt_module_close( $c );
			}
		}

		$this->env['encryption_available'] = count( $available ) == 0 ? false : true;
		$this->env['encryption_list'] = array(
			'DES'		=> 'DES',
			'AES128'	=> 'AES128',
			'AES192'	=> 'AES192',
			'AES256'	=> 'AES256',
		);
		$this->env['encryption_types'] = $available;
	}

	/*public*/ function Save_Settings()
	{
		// Save straight back to the database, we will fix up entries such as tmp_dir on load
		update_option( 'wponlinebackup_settings', $this->settings_stored );
	}

	/*public*/ function Delete_Setting( $setting )
	{
		// Only allow settings with defaults to be deleted from the stored settings
		if ( array_key_exists( $setting, $this->settings_stored ) && array_key_exists( $setting, $this->settings_default ) ) {
			unset( $this->settings_stored[ $setting ] );
		}
	}

	/*public*/ function Set_Setting( $setting, $value )
	{
		// Only allow existing settings to be set
		if ( ( $default = array_key_exists( $setting, $this->settings_default ) ) || array_key_exists( $setting, $this->settings_stored ) ) {
			if ( is_null( $value ) && $default ) {
				unset( $this->settings_stored[$setting] );
			} else {
				$this->settings_stored[$setting] = $value;
			}
		}
	}

	/*public*/ function Get_Setting( $setting, $raw = false )
	{
		// Check stored settings - only get from default if not wanting raw
		if ( array_key_exists( $setting, $this->settings_stored ) ) {
			$ret = $this->settings_stored[$setting];
		} else if ( !$raw && array_key_exists( $setting, $this->settings_default ) ) {

			// Cheating - on-the-fly collapsing of specific defaults - we'll still do the path repairs below
			if ( is_null( $this->settings_default[$setting] ) ) {

				switch ($setting) {

					case 'max_execution_time':
						// Based on the max_execution_time of scripts, but maximum of 15 so we don't block singlethreaded servers too long (bad server design though!)
						// Force a minimum of 5 too
						$ret = min( 15, max( 5, floor( ( 2 * ini_get( 'max_execution_time' ) ) / 3 ) ) );
						break;

					case 'gzip_tmp_dir':
						// OK, check what we used last time
						if ( ( $ret = get_option( 'wponlinebackup_last_gzip_tmp_dir', false ) ) === false || ( $ret = WPOnlineBackup::Test_Temp( $ret ) ) === false ) {

							// This is the first time we're grabbing this setting or the path is not writable anymore, try to grab fresh and then store the result
							$ret = WPOnlineBackup::Get_Temp();

							update_option( 'wponlinebackup_last_gzip_tmp_dir', $ret );

						}
						break;

					case 'max_block_size':
						// Grab memory limit
						if ( $memory_limit = ini_get( 'memory_limit' ) == '' ) $memory_limit = '64M';
						if ( preg_match( '/^\\s*[0-9]+\\s*(K|M|G)?\\s*$/i', $memory_limit, $matches ) ) {
							switch ( $matches[1] ) {
								case 'K': case 'k': $m = 1024; break;
								case 'M': case 'm': $m = 1024*1024; break;
								case 'G': case 'g': $m = 1024*1024*1024; break;
								default: $m = 1; break;
							}
						} else $m = 1;
						$memory_limit = max( 10*1024*1024, intval( $memory_limit ) * $m ) - 5*1024*1024;

						// third of memory or 8 MiB
						$ret = min( floor( $memory_limit / 5 ), 1024 * 1024 * 8 );
						break;

					case 'use_wpdb_api':
						// Can we cheat the system and avoid nasty memory problems with the inefficient WPDB? Not to mention the hugely annoying lack of error handling!
						// If only we could use BTL's DB wrapper :(
						global $wpdb;
						if ( isset( $wpdb->dbh ) && function_exists( 'mysql_get_server_info' ) && @mysql_get_server_info( $wpdb->dbh ) !== false )
							$ret = false;
						else
							$ret = true;
						break;

					default:
						$ret = $this->settings_default[$setting];
						break;

				}

				// Store if we set a value
				if ( !is_null($ret) ) $this->settings_default[$setting] = $ret;

			} else {

				// Not cheating, just grab
				$ret = $this->settings_default[$setting];

			}

		} else {
			return null;
		}

		if ( !$raw && ( $setting == 'gzip_tmp_dir' || $setting == 'local_tmp_dir' ) ) {
			// Repair the path - ensure a trailing forward slash and change relative into absolute
			$ret = preg_replace( '#(?:/|\\\\)$#', '', $ret );
			if ( !preg_match( '#^(?:/|\\\\|[A-Za-z]:)#', $ret ) ) $ret = ABSPATH . $ret;
		}

		return $ret;
	}

	/*public*/ function Get_Env( $env )
	{
		return array_key_exists( $env, $this->env ) ? $this->env[ $env ] : null;
	}

	/*public*/ function Load_Scheduler()
	{
		// Check we haven't loaded already
		if ( !is_null( $this->scheduler ) ) return;

		// Load scheduler
		require_once WPONLINEBACKUP_PATH . '/include/scheduler.php';

		// Initialise
		$this->scheduler = new WPOnlineBackup_Scheduler();
	}

	/*public*/ function Load_BootStrap()
	{
		// Check we haven't loaded already
		if ( !is_null( $this->bootstrap ) ) return;

		// Load bootstrap
		require_once WPONLINEBACKUP_PATH . '/include/bootstrap.php';

		// Initialise
		$this->bootstrap = new WPOnlineBackup_BootStrap( $this );
	}

	/*private static*/ function Test_Temp( $tmp )
	{
		if ( ( $tmpfile = @fopen( $tmp . '/obfw.writetest', 'w' ) ) === false ) return false;
		@fclose( $tmpfile );
		@unlink( $tmp . '/obfw.writetest' );
		return $tmp;
	}

	/*private static*/ function Get_Temp_Raw()
	{
		// Try and find the environment variable for the temporary directory
		// If that fails, try and work out if we are on Windows or not, and just give a rough guess
		if ( $ret = WPOnlineBackup::Test_Temp( getenv( 'TMP' ) ) ) return $ret;
		if ( $ret = WPOnlineBackup::Test_Temp( getenv( 'TEMP' ) ) ) return $ret;
		if ( $ret = WPOnlineBackup::Test_Temp( getenv( 'TMPDIR' ) ) ) return $ret;
		if ( function_exists( 'sys_get_temp_dir' ) && ( $ret = WPOnlineBackup::Test_Temp( sys_get_temp_dir() ) ) ) return $ret;
		if ( preg_match( '/^(?:Windows|WINNT)$/i', php_uname( 's' ) ) ) {
			if ( $ret = WPOnlineBackup::Test_Temp( 'C:\\TEMP' ) ) return $ret;
			if ( $ret = WPOnlineBackup::Test_Temp( 'C:\\WINDOWS\\TEMP' ) ) return $ret;
		} else {
			if ( $ret = WPOnlineBackup::Test_Temp( '/tmp' ) ) return $ret;
		}
		return false;
	}

	/*private static*/ function Get_Temp()
	{
		return realpath( WPOnlineBackup::Get_Temp_Raw() );
	}

	/*private static*/ function Convert_Unixtime_To_Wordpress_Unixtime( $unixtime )
	{
		return $unixtime + ( get_option( 'gmt_offset' ) * 3600 );
	}

	/*private static*/ function Get_Main_Site_URL()
	{
		// Remove the site_url filter that might get called - we don't want it messing with the raw URL
		// This fixes problems caused by plugins such as Any-Hostname
		remove_all_filters( 'site_url' );

		if ( function_exists( 'get_site_url' ) ) {

			// Remove a get_site_url specific filter
			remove_all_filters( 'blog_option_siteurl' );

			// Version 3.0 and above with multisite, get the site url for site ID 0 or 1 to attempt to get the root blog's URL
			// Maybe in future we go mad and have site specific plugin control panels... but that sounds insanely complex and at the moment we only allow root admin control
			$site_url = get_site_url( 0, '/', 'http' );

			// If nothing for site ID 0, try site ID 1
			if ( $site_url == '' )
				$site_url = get_site_url( 1, '/', 'http' );

		} else {

			// Remove some site_url specific filters
			remove_all_filters( 'pre_option_siteurl' );
			remove_all_filters( 'option_siteurl' );

			// Standard site_url call
			$site_url = site_url( '/', 'http' );

		}

		return $site_url;
	}

	/*public static*/ function Get_WPDB_Last_Error()
	{
		global $wpdb;
		// $wpdb->last_error is marked private - consolidate calls here so we can update easily
		return 'WPDB error: ' . $wpdb->last_error . '. Last query: ' . $wpdb->last_query;
	}

	// This function is duplicated in uninstall.php so the uninstaller can remove our tables without the need for this file loading
	/*public static*/ function Get_WPDB_Prefix()
	{
		global $wpdb;

		// Multisite can give different prefix for different blog... so make sure we get the base prefix
		// This call is more reliable than accessing base_prefix directly
		if ( is_callable( array( $wpdb, 'get_blog_prefix' ) ) )
			return $wpdb->get_blog_prefix( 0 );

		// No multisite features so return the standard prefix
		return $wpdb->prefix;
	}
}

$WPOnlineBackup = new WPOnlineBackup();

?>
