<?php

/*
WPOnlineBackup_BootStrap - Workhouse for the overall backup
Coordinates the different types of backups: Files, Database tables.
*/

// Define the backup status codes
define( 'WPONLINEBACKUP_STATUS_NONE',		0 ); // Referenced manually in wponlinebackup.php Activate() (bootstrap is not included because status table is empty at the time and it is a waste to include all this for just this...)
define( 'WPONLINEBACKUP_STATUS_STARTING',	1 );
define( 'WPONLINEBACKUP_STATUS_RUNNING',	2 );
define( 'WPONLINEBACKUP_STATUS_TICKING',	3 );
define( 'WPONLINEBACKUP_STATUS_CHECKING',	4 );
define( 'WPONLINEBACKUP_STATUS_STOPPING',	5 ); // Referenced manually in js/progress.js doAJAXSuccess()

// Define the activity types
define( 'WPONLINEBACKUP_ACTIVITY_BACKUP',	0 );
define( 'WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP',	1 );
define( 'WPONLINEBACKUP_ACTIVITY_RESTORE',	2 );

// Define the activity media types
define( 'WPONLINEBACKUP_MEDIA_UNKNOWN',		0 ); // Mainly for backwards compatibility when we didn't store the target for backups
define( 'WPONLINEBACKUP_MEDIA_DOWNLOAD',	1 );
define( 'WPONLINEBACKUP_MEDIA_EMAIL',		2 );
define( 'WPONLINEBACKUP_MEDIA_ONLINE',		3 );

// Define the activity completion status codes
define( 'WPONLINEBACKUP_COMP_RUNNING',		0 ); // Running
define( 'WPONLINEBACKUP_COMP_SUCCESSFUL',	1 ); // Successful
define( 'WPONLINEBACKUP_COMP_PARTIAL',		2 ); // Completed, but with errors (so SOME data was backed up)
define( 'WPONLINEBACKUP_COMP_UNEXPECTED',	3 ); // Failed - timed out and never recovered - WP-Cron broken?
define( 'WPONLINEBACKUP_COMP_FAILED',		4 ); // Failed - mainly where backup file could not be opened, or online transmission fails for incrementals
define( 'WPONLINEBACKUP_COMP_TIMEOUT',		5 ); // Failed - timed out too many times and never made progress (reached max_frozen_retries)
define( 'WPONLINEBACKUP_COMP_SLOWTIMEOUT',	6 ); // Failed - timed out too many times and did make progress each time (reaches max_progress_retries)
define( 'WPONLINEBACKUP_COMP_STOPPED',		7 ); // Failed - a user stopped the backup

// Define the event codes
define( 'WPONLINEBACKUP_EVENT_INFORMATION',	0 );
define( 'WPONLINEBACKUP_EVENT_WARNING',		1 );
define( 'WPONLINEBACKUP_EVENT_ERROR',		2 );

// Define the bin codes and names
define( 'WPONLINEBACKUP_BIN_DATABASE',		1 );
define( 'WPONLINEBACKUP_BIN_FILESYSTEM',	2 );

// Update status flags
define( 'WPONLINEBACKUP_UPSTATUS_IGNORESTOP',	1 );
define( 'WPONLINEBACKUP_UPSTATUS_PROGRESSRAW',	2 );

// WP-Cron runs events in succession - so we could have Perform_Check() running, and then immediately after
// (in the same PHP process) Perform(). So we use this global to ensure we run once per process!
// We also use this to detect how long we've been running and adjust max_execution_time as necessary
$WPOnlineBackup_Init = time();
$WPOnlineBackup_Perform_Once = false;

class WPOnlineBackup_BootStrap
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $status = null;
	/*private*/ var $last_tick_status = null;
	/*private*/ var $activity_id;
	/*private*/ var $start_time;

	/*private*/ var $processors = array();
	/*private*/ var $stream = null;

	// Cache
	/*private*/ var $min_execution_time;
	/*private*/ var $max_execution_time;
	/*private*/ var $recovery_time;

	// Database
	/*private*/ var $db_prefix;
	/*private*/ var $db_force_master = '';

	/*public*/ function WPOnlineBackup_BootStrap( & $WPOnlineBackup )
	{
		$this->WPOnlineBackup = & $WPOnlineBackup;

		// Grab the database prefix to use
		$this->db_prefix = WPOnlineBackup::Get_WPDB_Prefix();
	}

	/*private*/ function Load_Status( $disallow_stale = true )
	{
		global $wpdb;

		if ( $disallow_stale ) {

			// Ensure DOING_CRON is set - some plugins, such as DB-Cache plugin, stop caching when this is set
			// Technically this should always be set, but kick start can run stuff sometimes without it being set
			if ( !defined( 'DOING_CRON' ) )
				define( 'DOING_CRON', true );

			// Configure the PHP mysqldn extension mysqlnd-ms plugin if its in use, to allow us to force read-only queries on the master so we don't get stale data
			// This is especially important for grabbing the status and updating the status
			// This shouldn't really be in use on production systems... but just in case
			if ( defined( 'MYSQLND_MS_MASTER_SWITCH' ) )
				$this->db_force_master = '/*' . MYSQLND_MS_MASTER_SWITCH . '*/';

			// HyperDB plugin - force master on everything
			if ( is_callable( array( $wpdb, 'send_reads_to_masters' ) ) )
				$wpdb->send_reads_to_masters();

			// MySQL-Proxy read/write splitting - START TRANSACTION to make sure we go to a master
			// This shouldn't be in use on production systems... but just in case
			$wpdb->query( 'START TRANSACTION' );

		}

		$this->status = array();

		// Grab the data from the database
		$result =
			$wpdb->get_row(
				$this->db_force_master . 'SELECT SQL_NO_CACHE status, time, counter, stop_user, progress FROM `' . $this->db_prefix . 'wponlinebackup_status` LIMIT 1',
				ARRAY_N
			);

		if ( is_null( $result ) )
			$result = array( WPONLINEBACKUP_STATUS_NONE, 0, 0, '', '' );

		if ( $disallow_stale ) {

			// MySQL-Proxy read/write splitting - COMMIT the transaction
			$wpdb->query( 'COMMIT' );

		}

		list ( $this->status['status'], $this->status['time'], $this->status['counter'], $this->status['stop_user'], $this->status['progress'] ) = $result;

		$this->status['progress'] = @unserialize( $this->status['progress'] );

		// If progress data is invalid, blank it out, otherwise, grab the activity_id
		if ( $this->status['progress'] === false ) $this->status['progress'] = array( 'activity_id' => null );
		else $this->activity_id = $this->status['progress']['activity_id'];
	}

	/*public*/ function Fetch_Status()
	{
		// We don't mind stale from front-end, and only front-end calls this
		if ( is_null( $this->status ) )
			$this->Load_Status( false );

		return $this->status;
	}

	/*private*/ function Update_Status( $new_status = false, $new_counter = false, $flags = 0 )
	{
		global $wpdb;

		// If we didn't give a status, leave it the same
		if ( $new_status === false ) $new_status = $this->status['status'];

		// Increase the progress counter so we don't fail to update
		if ( $new_counter === false ) $new_counter = $this->status['counter'] + 1;

		// If we're setting status to NONE, wipe the cache and jobs information as we don't need it anymore
		if ( $new_status == WPONLINEBACKUP_STATUS_NONE ) {
			unset( $this->status['progress']['jobs'] );
			unset( $this->status['progress']['cache'] );
		}

		// Serialize and escape_by_ref (uses _real_escape - better)
		// On_Shutdown calls this function and will already have the progress serialized from last_tick_status, and will give us a flag so we know
		if ( $flags & WPONLINEBACKUP_UPSTATUS_PROGRESSRAW )
			$q_new_progress = $this->status['progress'];
		else
			$q_new_progress = serialize( $this->status['progress'] );

		$wpdb->escape_by_ref( $q_new_progress );

		$where =
			'counter = ' . $this->status['counter'] . ' ' .
			'AND time = ' . $this->status['time'];

		// Update the database
		$now = time();
		$result = $wpdb->query(
			'UPDATE `' . $this->db_prefix . 'wponlinebackup_status` ' .
			'SET status = ' . $new_status . ', ' .
				'time = ' . $now . ', ' .
				'counter = ' . $new_counter . ', ' .
				'progress = \'' . $q_new_progress . '\' ' .
			'WHERE status = ' . $this->status['status'] . ' ' .
				'AND ' . $where
		);

		if ( $result ) {

			$this->have_lock = true;

			// We updated the row, store the time
			$this->status['status'] = $new_status;
			$this->status['time'] = $now;
			$this->status['counter'] = $new_counter;

			// Continue
			return true;

		} else {

			// MySQL-Proxy - START TRANSACTION to make sure we go to a master
			$wpdb->query( 'START TRANSACTION' );

			// We may have lost the lock - see if we lost it because Stop() was called
			$result =
				$wpdb->get_row(
					$this->db_force_master . 'SELECT SQL_NO_CACHE status, stop_user FROM `' . $this->db_prefix . 'wponlinebackup_status` LIMIT 1',
					ARRAY_N
				);

			if ( is_null( $result ) ) $result = array( WPONLINEBACKUP_STATUS_NONE, '' );

			// MySQL-Proxy - COMMIT the transaction
			$wpdb->query( 'COMMIT' );

			list ( $check_status, $stop_user ) = $result;

			// Are we stopping?
			if ( $check_status == WPONLINEBACKUP_STATUS_STOPPING ) {

				// If we're not ignoring the stopping status, make sure we write the stopping back instead of our new status
				if ( !( $flags & WPONLINEBACKUP_UPSTATUS_IGNORESTOP ) ) {

					$new_status = $check_status;

					// Adjust our message to say Stopping backup... otherwise we'll change it away from it!
					// We don't do this if we're ignoring stop, because we only ignore stop if we're about to end (due to failure etc.)
					$this->status['progress']['message'] = __( 'Stopping backup...', 'wponlinebackup' );

					// Remove the jobs list and the cache - we don't need these anymore
					unset( $this->status['progress']['jobs'] );
					unset( $this->status['progress']['cache'] );

					// Prepare the new progress for the query
					// Serialize and escape_by_ref (uses _real_escape - better)
					$q_new_progress = serialize( $this->status['progress'] );
					$wpdb->escape_by_ref( $q_new_progress );

				}

				// Try to keep the lock but with stopping status
				$result = $wpdb->query(
					'UPDATE `' . $this->db_prefix . 'wponlinebackup_status` ' .
					'SET status = ' . $new_status . ', ' .
						'time = ' . $now . ', ' .
						'counter = ' . $new_counter . ', ' .
						'progress = \'' . $q_new_progress . '\' ' .
					'WHERE status = ' . $check_status . ' ' .
						'AND ' . $where
				);

				if ( $result ) {

					$this->have_lock = true;

					// We updated the row, store the time
					$this->status['status'] = $new_status;
					$this->status['time'] = $now;
					$this->status['counter'] = $new_counter;
					$this->status['stop_user'] = $stop_user;

					// Continue - returning 1 lets the caller see if we're stopping or not
					return 1;

				}

			}

		}

		$this->have_lock = false;

		// No row was updated, the mutex lock is lost - abort
		return 0;
	}

	/*public*/ function Start( $config, $type, $with_immediate_effect = false )
	{
		// Load status
		$this->Load_Status();

		// Load settings
		$this->WPOnlineBackup->Load_Settings();

		// Check to see if a backup is already running, and grab the backup lock if possible
		// If a backup is running, but the time_presumed_dead period has passed, we presume the backup to have failed, and allow another to be started
		if (
			(
					$this->status['status'] != WPONLINEBACKUP_STATUS_NONE
				&&	$this->status['time'] > time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
			)
		) {
			return false;
		}

		// Reset activity_id
		$this->activity_id = 0;

		// Prepare the progress and its tracker
		$this->status['progress'] = array(
			'start_time'		=> time(),			// The start time of the backup
			'initialise'		=> 1,				// Whether or not initialisation is complete
			'activity_id'		=> 0,				// Activity ID this backup represents
			'comp'			=> '-',				// Completion status
			'message'		=> __( 'Waiting for the backup to start...' , 'wponlinebackup' ),	// Message to show in monitoring page
			'config'		=> $config,			// Backup configuration
			'type'			=> $type,			// Type of backup
			'frozen_timeouts'	=> 0,				// Timeouts with no progress (compared with max_frozen_timeouts)
			'last_timeout'		=> null,			// Progress at last timeout (used to detect a frozen timeout)
			'progress_timeouts'	=> 0,				// Timeouts with progress (compared with max_progress_timeouts)
			'errors'		=> 0,				// Number of errors
			'warnings'		=> 0,				// Number of warnings
			'jobs'			=> array(),			// Job list - the backup job works its way through these - we populate this below
			'cleanups'		=> array(),			// Cleanup list - jobs that run after the backup completes
			'jobcount'		=> 0,				// Number of jobs - used to calculate progress in percent
			'jobdone'		=> 0,				// Number of jobs done
			'rotation'		=> 0,				// Do we need to rotate due to failure?
			'file'			=> null,			// The backup file - we populate this below
			'file_set'		=> null,			// The resulting backup files
			'rcount'		=> 0,				// Total number of files approached (not necessarily stored)
			'rsize'			=> 0,				// Total size of files approached (not necessarily stored)
			'ticks'			=> 0,				// Tick count
			'update_ticks'		=> $this->WPOnlineBackup->Get_Setting( 'update_ticks' ), // Number of ticks before update. We decrease to 1 on timeout.
			'revert_update_ticks'	=> 0,				// When update_ticks is set to 1 we use this to decide when to change it back
			'tick_progress'		=> array( 0 => false, 1 => 0 ),	// Tick progress when update_ticks is 1 and we're taking care
			'performs'		=> 0,				// Perform count
			'nonce'			=> '',				// Nonce for online collection if we need it
			'bsn'			=> 0,				// Keep track of BSN for incremental backups
			'cache'			=> array(),			// Cached settings - we clear them after backup
		);

		if ( $config['target'] == 'online' ) {

			// Cache the username and password so we ensure we use the same throughout the backup process
			$this->status['progress']['cache']['username'] = $this->WPOnlineBackup->Get_Setting( 'username' );
			$this->status['progress']['cache']['password'] = $this->WPOnlineBackup->Get_Setting( 'password' );

			// Some vaults are experiencing a change of blogurl mid backup due to different URL being accessed, so cache it here
			$this->status['progress']['cache']['blogurl'] = WPOnlineBackup::Get_Main_Site_URL();

		}

		$this->status['progress']['cache']['enc_type'] = $this->WPOnlineBackup->Get_Setting( 'encryption_type' );
		$this->status['progress']['cache']['enc_key'] = $this->WPOnlineBackup->Get_Setting( 'encryption_key' );

		// Update status to starting - ignore stopping status if we're set to stopping
		if ( !$this->Update_Status( WPONLINEBACKUP_STATUS_STARTING, 0, WPONLINEBACKUP_UPSTATUS_IGNORESTOP ) ) return false;

		// Schedule the backup check thread for 65 seconds in the future
		wp_schedule_single_event( time() + 65, 'wponlinebackup_perform_check' );

		if ( $with_immediate_effect ) {

			// A scheduled backup so we start with immediate effect
			$this->Perform( true );

		} else {

			// Manual - Schedule the backup thread for in 5 seconds - hopefully after this page load so we can show the progress from the start if manually starting
			wp_schedule_single_event( time() + 5, 'wponlinebackup_perform' );

		}

		// Backup has started and is ready to run!
		return true;
	}

	/*public*/ function Stop()
	{
		global $wpdb, $current_user;

		// Load status
		$this->Load_Status();

		// Get current user - this function is always called from user-land (admin page)
		get_currentuserinfo();

		// Check we're still running - cancel stop if not
		if ( $this->status['status'] != WPONLINEBACKUP_STATUS_STARTING && $this->status['status'] != WPONLINEBACKUP_STATUS_RUNNING && $this->status['status'] != WPONLINEBACKUP_STATUS_TICKING ) {
			return;
		}

		// Store the user that requested the stop and prepare it for the query
		$stop_user = $current_user->display_name;
		$wpdb->escape_by_ref($stop_user);
	
		// Force the status to be updated to stopping - but only if we're starting/running/ticking
		// We do this because if we lose lock during Update_Status we will check if only status has changed to stopping (like we have here)
		// In which case will update our internal status in the actual running script to stopping and begin to stop
		// This is our way of signalling the running backup process to stop, allowing it to clean up gracefully and tidily
		$result = $wpdb->query(
			'UPDATE `' . $this->db_prefix . 'wponlinebackup_status` ' .
			'SET status = ' . WPONLINEBACKUP_STATUS_STOPPING . ', ' .
				'stop_user = \'' . $stop_user . '\' ' .
			'WHERE status = ' . WPONLINEBACKUP_STATUS_STARTING . ' ' .
				'OR status = ' . WPONLINEBACKUP_STATUS_RUNNING . ' ' .
				'OR status = ' . WPONLINEBACKUP_STATUS_TICKING . ' ' .
				'OR status = ' . WPONLINEBACKUP_STATUS_CHECKING
		);
	}

	/*public*/ function Start_Activity()
	{
		global $wpdb;

		// Cleanup any old stale activity entries - any that are LESS than the current time and have NULL completion time - care not for the result
		$wpdb->query(
			'UPDATE `' . $this->db_prefix . 'wponlinebackup_activity_log` ' .
			'SET end = ' . $this->status['progress']['start_time'] . ', ' .
				'comp = ' . WPONLINEBACKUP_COMP_UNEXPECTED . ' ' .
			'WHERE end IS NULL'
		);

		// Resolve the media
		switch ( $this->status['progress']['config']['target'] ) {
			case 'download':
				$media = WPONLINEBACKUP_MEDIA_DOWNLOAD;
				break;
			case 'email':
				$media = WPONLINEBACKUP_MEDIA_EMAIL;
				break;
			case 'online':
				$media = WPONLINEBACKUP_MEDIA_ONLINE;
				break;
			default:
				$media = WPONLINEBACKUP_MEDIA_UNKNOWN;
				break;
		}

		// Insert a new activity row. Return false if we fail
		if ( $wpdb->query(
			'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_activity_log` ' .
			'(start, end, type, comp, media, compressed, encrypted, errors, warnings, bsize, bcount, rsize, rcount) ' .
			'VALUES ' .
			'(' .
				$this->status['progress']['start_time'] . ', ' .	// Start time
				'NULL, ' .						// End time is null as the activity has yet to finish
				$this->status['progress']['type'] . ', ' .		// Activity type
				WPONLINEBACKUP_COMP_RUNNING . ', ' .			// Current status is running
				$media . ', ' .						// Media
				'0, ' .							// Compressed?
				'0, ' .							// Encrypted?
				'0, ' .							// Number of errors - start at 0
				'0, ' .							// Number of warnings - start at 0
				'0, ' .							// These four fields are described in wponlinebackup.php during creation
				'0, ' .							// -
				'0, ' .							// -
				'0' .							// -
			')'
		) === false ) return WPOnlineBackup::Get_WPDB_Last_Error();

		// Store the activity_id
		$this->status['progress']['activity_id'] = $this->activity_id = $wpdb->insert_id;

		return true;
	}

	/*public*/ function End_Activity( $status, $progress = false )
	{
		global $wpdb;

		// If we didn't complete we won't pass in the progress, so use 0s for the activity
		if ( $progress === false )
			$progress = array(
				'file_set'	=> array(
					'compressed'	=> 0,
					'encrypted'	=> 0,
					'size'		=> 0,
					'files'		=> 0,
				),
				'rsize'		=> 0,
				'rcount'	=> 0,
				'errors'	=> 0,
				'warnings'	=> 0,
			);

		// Update the loaded activity
		// - care not for the return status, best to kick off errors during starting a backup, then starting a backup AND finishing a backup
		//   that and we could be finishing the backup due to database errors anyways - so reporting here would be completely redundant
		$wpdb->update(
			$this->db_prefix . 'wponlinebackup_activity_log',
			array(
				'end'		=> time(),	// Set end time to current time
				'comp'		=> $status,	// Set completion status to the given status
				'errors'	=> $progress['errors'],
				'warnings'	=> $progress['warnings'],
				'compressed'	=> $progress['file_set']['compressed'],
				'encrypted'	=> $progress['file_set']['encrypted'],
				'bsize'		=> $progress['file_set']['size'],
				'bcount'	=> $progress['file_set']['files'],
				'rsize'		=> $progress['rsize'],
				'rcount'	=> $progress['rcount'],
			),
			array(
				'activity_id'	=> $this->activity_id,
			),
			'%d',
			'%d'
		);

		// Update the completion status stored in the progress, we send it to the server if it asks for a backup when we weren't expecting it to
		$this->status['progress']['comp'] = $status;
	}

	/*public*/ function Log_Event( $type, $event )
	{
		global $wpdb;

		// Increase error count if an error is being logged
		if ( $type == WPONLINEBACKUP_EVENT_ERROR )
			$this->status['progress']['errors']++;
		else if ( $type == WPONLINEBACKUP_EVENT_WARNING )
			$this->status['progress']['warnings']++;

		// Insert the event
		$res = $wpdb->insert(
			$this->db_prefix . 'wponlinebackup_event_log',
			array(
				'activity_id'	=> $this->activity_id,	// Current activity
				'time'		=> time(),		// Set event time to current time
				'type'		=> $type,		// Set event type to given type
				'event'		=> $event,		// Set event message to given message
			)
		);

		if ( $res === false ) return WPOnlineBackup::Get_WPDB_Last_Error();

		return true;
	}

	/*public*/ function DBError( $file, $line, $friendly = false )
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			__( 'A database operation failed.' , 'wponlinebackup' ) . PHP_EOL .
				__( 'Please try reinstalling the plugin - in most cases this will repair the database.' , 'wponlinebackup' ) . PHP_EOL .
				__( 'Please contact support if the issue persists, providing the complete event log for the activity. Diagnostic information follows:' , 'wponlinebackup' ) . PHP_EOL . PHP_EOL .
				'Failed at: ' . $file . '(' . $line . ')' . PHP_EOL .
				WPOnlineBackup::Get_WPDB_Last_Error()
		);

		if ( $friendly === false )
			$friendly = __( 'A database operation failed.' , 'wponlinebackup' );

		return $friendly;
	}

	/*public*/ function FSError( $file, $line, $of, $ret, $friendly = false )
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			( $of === false ? __( 'A filesystem operation failed.' , 'wponlinebackup' ) : sprintf( __( 'A filesystem operation failed while processing %s for backup.' , 'wponlinebackup' ), $of ) ) . PHP_EOL .
				__( 'If the following error message is not clear as to the problem and the issue persists, please contact support providing the complete event log for the activity. Diagnostic information follows:' , 'wponlinebackup' ) . PHP_EOL . PHP_EOL .
				'Failed at: ' . $file . '(' . $line . ')' . PHP_EOL .
				$ret
		);

		if ( $friendly === false )
			$friendly = __( 'A filesystem operation failed.' , 'wponlinebackup' );

		return $friendly;
	}

	/*public*/ function COMError( $file, $line, $ret, $friendly = false )
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			__( 'A transmission operation failed.' , 'wponlinebackup' ) . PHP_EOL .
				__( 'If the following error message is not clear as to the problem and the issue persists, please contact support providing the complete event log for the activity. Diagnostic information follows:' , 'wponlinebackup' ) . PHP_EOL . PHP_EOL .
				'Failed at: ' . $file . '(' . $line . ')' . PHP_EOL .
				$ret
		);

		if ( $friendly === false )
			$friendly = __( 'Communication with the online vault failed.' , 'wponlinebackup' );

		return $friendly;
	}

	/*public*/ function MALError( $file, $line, $xml, $parser_ret = false )
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			__( 'An online request succeeded but was malformed.' , 'wponlinebackup' ) . PHP_EOL .
				__( 'Please contact support if the issue persists, providing the complete event log for the activity. Diagnostic information follows:' , 'wponlinebackup' ) . PHP_EOL . PHP_EOL .
				'Failed at: ' . $file . '(' . $line . ')' . PHP_EOL .
				( $parser_ret === false ? 'XML parser succeeded' : 'XML parser: ' . $parser_ret . PHP_EOL ) .
				'XML log:' . PHP_EOL . $xml->log
		);

		return __( 'Communication with the online vault failed.' , 'wponlinebackup' );
	}

	/*public*/ function Register_Temp( $temp )
	{
		$temps = get_option( 'wponlinebackup_temps', array() );
		
		$temps[] = $temp;
		update_option( 'wponlinebackup_temps', $temps );
	}

	/*public*/ function Unregister_Temp( $temp )
	{
		$temps = get_option( 'wponlinebackup_temps', array() );
		
		if ( ( $key = array_search( $temp, $temps ) ) !== false ) {

			unset( $temps[$key] );
			update_option( 'wponlinebackup_temps', $temps );

		}
	}

	/*public*/ function Clean_Temps()
	{
		$temps = get_option( 'wponlinebackup_temps', array() );

		foreach ( $temps as $item )
			@unlink( $item );

		update_option( 'wponlinebackup_temps', array() );
	}

	/*public*/ function On_Shutdown()
	{
		// If we lost the lock and exit, don't bother doing anything
		if ( !$this->have_lock )
			return;

		$what = '';

		if ( $this->status['status'] == WPONLINEBACKUP_STATUS_RUNNING ) {

			// Try to recover status
			if ( !is_null( $this->last_tick_status ) ) {

				// Copy last tick status to current status
				$this->status = $this->last_tick_status;

				// Update status, leave if we've lost the lock
				// If we're stopping we do same as if we're checking... run check to finish off
				if ( !$this->Update_Status( WPONLINEBACKUP_STATUS_CHECKING, false, WPONLINEBACKUP_UPSTATUS_PROGRESSRAW ) )
					return;

			}

			// Trigger the Perform_Check so we can work out failures etc
			$what = '_check';

		} else if ( $this->status['status'] == WPONLINEBACKUP_STATUS_NONE ) {

			// We only add on On_Shutdown() AFTER we mark as running, so if we marked as NONE we just finished
			return;

		} else if ( $this->status['status'] != WPONLINEBACKUP_STATUS_TICKING && $this->status['status'] != WPONLINEBACKUP_STATUS_STOPPING ) {

			// Not idle, not running, not ticking and not stopping, exit
			return;

		}

		// Just in case the below fails, schedule next event
		wp_schedule_single_event( time() + 5, 'wponlinebackup_perform' . $what );

		// Attempt to kick start the backup again - this bit based on spawn_cron()
		$do_url = WPOnlineBackup::Get_Main_Site_URL() . '?wponlinebackup_do' . $what . '&' . time();
		wp_remote_post(
			$do_url,
			array(
				'timeout' => 1,
				'blocking' => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
			)
		);
	}

	/*public*/ function Tick( $next = false, $update = false )
	{
		if ( time() - $this->start_time > $this->recovery_time ) {

			// We've run for way too long - Perform_Check will have run by now, so just quit - let the shutdown function ensure the check is running though
			$this->CleanUp_Processors( true );

			$exit = true;

		} else {

			$exit = false;

			$run_time = time() - $this->start_time;

			if ( $next || $run_time > $this->max_execution_time ) {

				$this->status['progress']['rotation']--;

				// Update the stream state
				if ( is_object( $this->stream ) ) $this->status['progress']['file']['state'] = $this->stream->Save();
				else $this->status['progress']['file'] = null;

				// Reset the update ticks and tick count
				$this->status['progress']['ticks'] = 0;
				$this->status['progress']['update_ticks'] = $this->WPOnlineBackup->Get_Setting( 'update_ticks' );

				// Make sure if an error occurs, we don't end up overwriting the status with the previous status!
				$this->last_tick_status = null;

				// Update status - ignore if we're stopping, we'll sort on the next tick
				$this->Update_Status( WPONLINEBACKUP_STATUS_TICKING );

				$this->CleanUp_Processors( true );

				$exit = true;

				// If we're forcing next, ensure we've run for at least 10 seconds
				if ( $next && $run_time < $this->min_execution_time ) {

					// Sleep a bit, but not too long as to reach max_execution_time
					// We do this to prevent eating too much resources on the server
					if ( ( $sleep_time = $this->min_execution_time - 2 - $run_time ) > 0 ) {

						// In case we get interrupts
						$now = time();
						$end = $now + $sleep_time;
						do {
							sleep( $end - $now );
							$now = time();
						} while ( $now < $end );

					}

				}

			} else {

				if ( $this->status['progress']['update_ticks'] == 1 ) {

					// We made progress, so clear the frozen timeouts counter
					$this->status['progress']['frozen_timeouts'] = 0;

					// We'll store a 0 tick count... but we'll keep the actual tick count so we know when to revert update_ticks
					$ticks = $this->status['progress']['ticks'];
					$this->status['progress']['ticks'] = 0;

					$update = true;

					// We're taking our time at the moment and always updating; if we hit the revert update_ticks value we can revert it
					if ( ++$this->status['progress']['ticks'] >= $this->status['progress']['revert_update_ticks'] ) {

						$this->status['progress']['update_ticks'] = $this->WPOnlineBackup->Get_Setting( 'update_ticks' );

					}

				} else {

					// Only update if tick count reached - speeds things up alot
					if ( $update || ++$this->status['progress']['ticks'] >= $this->status['progress']['update_ticks'] ) {

						$this->status['progress']['ticks'] = 0;

						$update = true;

					}

				}

				// Update the stream state
				if ( is_object( $this->stream ) ) $this->status['progress']['file']['state'] = $this->stream->Save();
				else $this->status['progress']['file'] = null;

				if ( $update ) {

					// Make sure if an error occurs, we don't end up overwriting the status with the previous status!
					$this->last_tick_status = null;

					// Update status, leave if we've lost the lock
					// Check if we're stopping - we'll need to exit then so we can trigger a check in On_Shutdown() like we do when we're finished
					if ( !( $check_stop = $this->Update_Status() ) || $check_stop === 1 ) {

						$this->CleanUp_Processors( true );

						$exit = true;

					}

				} else {

					$this->last_tick_status = $this->status;

					// Split the references away from the real data
					unset( $this->last_tick_status['progress'] );
					$this->last_tick_status['progress'] = serialize( $this->status['progress'] );

				}

				if ( $this->status['progress']['update_ticks'] == 1 ) {

					// Put the tick count back...
					$this->status['progress']['ticks'] = $ticks;

				}

			}

		}

		if ( $exit )
			exit;

		return true;
	}

	/*public*/ function Perform_Check()
	{
		// Load status
		$this->Load_Status();

		if ( $this->status['status'] == WPONLINEBACKUP_STATUS_NONE ) return;

		// Allow an instant start from the overview page when AJAX is enabled
		if ( $this->status['status'] == WPONLINEBACKUP_STATUS_STARTING ) {

			// Perform - but don't ignore timeout
			$this->Perform();

			return;

		}

		$this->WPOnlineBackup->Load_Settings();

		if (
				$this->status['status'] != WPONLINEBACKUP_STATUS_NONE
			&&	$this->status['time'] <= time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
		) return;

		wp_clear_scheduled_hook( 'wponlinebackup_perform_check' );

		// Have we been triggered by On_Shutdown? (Status will be checking) Or if we haven't, has Perform not run in the recovery time?
		if ( $this->status['status'] != WPONLINEBACKUP_STATUS_CHECKING && $this->status['time'] > time() - $this->WPOnlineBackup->Get_Setting( 'timeout_recovery_time' ) ) {

			// Schedule again in future in 60 seconds
			wp_schedule_single_event( time() + 65, 'wponlinebackup_perform_check' );

			// Timeout didn't occur, just exit
			return;

		}

		$this->activity_id = $this->status['progress']['activity_id'];

		$last_timeout = md5( serialize( $this->status['progress']['jobs'] ) );

		// Did we make progress?
		if ( !is_null( $this->status['progress']['last_timeout'] ) ) {

			if ( $last_timeout == $this->status['progress']['last_timeout'] ) {

				if ( ++$this->status['progress']['frozen_timeouts'] > $this->WPOnlineBackup->Get_Setting( 'max_frozen_retries' ) ) {

					// Remove any schedule
					wp_clear_scheduled_hook( 'wponlinebackup_perform' );

					// Run cleanup
					$this->CleanUp();

					// Timeout occurred
					$this->Log_Event(
						WPONLINEBACKUP_EVENT_WARNING,
						$ret = __( 'The backup timed out too many times and no progress was made on any attempt. Your server may be running extremely slow at this time - try scheduling the backup during a quieter period.' , 'wponlinebackup' )
					);

					$this->End_Activity( WPONLINEBACKUP_COMP_TIMEOUT );

					$this->status['progress']['message'] = $ret;

					// Ignore stopping status, too many time outs so we're stopping anyway
					$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );

					return;

				}

			}

		}

		// Reset tick count to 1 so we constantly update to try get past this blockage that caused the timeout, also store the revert count
		$this->status['progress']['revert_update_ticks'] = $this->status['progress']['update_ticks'];
		$this->status['progress']['update_ticks'] = 1;

		$this->status['progress']['last_timeout'] = $last_timeout;

		if ( ++$this->status['progress']['progress_timeouts'] > ( $max_progress_retries = $this->WPOnlineBackup->Get_Setting( 'max_progress_retries' ) ) && $max_progress_retries != 0 ) {

			// Remove any schedule
			wp_clear_scheduled_hook( 'wponlinebackup_perform' );

			// Run cleanup
			$this->CleanUp();

			// Timeout occurred
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_WARNING,
				$ret = __( 'The backup timed out too many times and was progressing too slowly. Your server may be running extremely slow at this time - try scheduling the backup during a quieter period.' , 'wponlinebackup' )
			);

			$this->End_Activity( WPONLINEBACKUP_COMP_SLOWTIMEOUT );

			$this->status['progress']['message'] = $ret;

			// Ignore stopping status, too many time outs so we're stopping anyway
			$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );

			return;

		}

		// Schedule again in future in 60 seconds
		wp_schedule_single_event( time() + 65, 'wponlinebackup_perform_check' );

		// Update the message - bit vague but we don't really know if this is going to be a timeout issue or not
		$this->status['progress']['message'] = __( 'A timeout occurred during backup (large files and slow servers are the common cause of this); trying again...' , 'wponlinebackup' );

		// Run the backup now
		$this->Perform( true );
	}

	/*public*/ function Perform( $ignore_timeout = false )
	{
		global $WPOnlineBackup_Perform_Once, $WPOnlineBackup_Init;

		// Check we haven't already run once during this PHP session
		if ( $WPOnlineBackup_Perform_Once === true ) return;
		$WPOnlineBackup_Perform_Once = true;

		if ( !$ignore_timeout ) {

			// Load status
			$this->Load_Status();

			// If we're not ticking, starting or stopping, we're either not running at all or checking, so lets stop here
			if ( $this->status['status'] != WPONLINEBACKUP_STATUS_TICKING && $this->status['status'] != WPONLINEBACKUP_STATUS_STARTING && $this->status['status'] != WPONLINEBACKUP_STATUS_STOPPING ) return;

			$this->WPOnlineBackup->Load_Settings();

			// Check we're not presumed dead - Perform_Check() will kill the entire process if we are so lets not do anything
			if (
					$this->status['status'] != WPONLINEBACKUP_STATUS_NONE
				&&	$this->status['time'] <= time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
			) return;

			// If we're outside the normal run time, we've probably crashed and awaiting recovery so lets not do anything - Perform_Check() will log the timeout and kick start Perform() again
			if ( $this->status['time'] <= time() - $this->WPOnlineBackup->Get_Setting( 'timeout_recovery_time' ) ) return;

		}

		$this->start_time = time();

		// Ignore user aborts
		@ignore_user_abort( true );

		// Test safe mode
		$safe_mode = ini_get( 'safe_mode' );
		if ( !is_bool( $safe_mode ) ) $safe_mode = preg_match( '/^on$/i', $safe_mode );

		if ( $safe_mode ) {

			// Cannot change time limit in safe mode, so offset the max_execution_time based on how much time we've lost since initialisation, but give a minimum of 5 seconds
			$offset = time() - $WPOnlineBackup_Init;
			$this->max_execution_time = ( $offset > $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ) ? false : $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ) - $offset );
			if ( $this->max_execution_time === false ) $this->max_execution_time = min( 5, $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ) );

		} else {

			$this->max_execution_time = $this->WPOnlineBackup->Get_Setting( 'max_execution_time' );

			// Just set new time limit - don't be over zealous as we don't want to cause issues on the server, so set to twice the max time
			// We should normally pause and resume after the max time, but if we do hang, this will give a little leeway which can only be good
			set_time_limit( $this->max_execution_time * 2 );

		}

		// Minimum execution time can't be more than execution time - we fix default so need to check
		$this->min_execution_time = min( $this->max_execution_time, $this->WPOnlineBackup->Get_Setting( 'min_execution_time' ) );

		$this->recovery_time = $this->WPOnlineBackup->Get_Setting( 'timeout_recovery_time' );

		$this->status['progress']['performs']++;

		// If we're just starting, populate a new activity, if this fails then there is a problem with the internal database tables - needs reactivation - return the message
		if ( $this->activity_id == 0 ) {

			if ( ( $ret = $this->Start_Activity() ) === true ) {

				// Log the starting event to see if the event log is fine and check we're actually logged in if we're doing an online backup
				if ( ( $ret = $this->Log_Event( WPONLINEBACKUP_EVENT_INFORMATION, __( 'Backup starting...' , 'wponlinebackup' ) ) ) !== true ) {

					$this->End_Activity( WPONLINEBACKUP_COMP_FAILED );

				}

			}

			if ( $ret !== true ) {

				// Update the message to say we're stopped now (it would be Stopping... at the moment)
				$this->status['progress']['message'] = sprintf( __( 'The backup failed to start: %s' , 'wponlinebackup' ), $ret );

				// Update status one second time to mark as finished
				$this->Update_Status( WPONLINEBACKUP_STATUS_NONE );

				return;

			}

			// Check we're actually logged into the online vault if we're doing an online backup
			if ( $this->status['progress']['config']['target'] == 'online' && $this->status['progress']['cache']['username'] == '' ) {

				$this->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					__( 'An online backup cannot be performed if the plugin is not currently logged into the online backup servers. Please click \'Online Backup Settings\' and login to enable online backup.' , 'wponlinebackup' )
				);

				$this->End_Activity( WPONLINEBACKUP_COMP_FAILED );

				// Update the message to say we're stopped now (it would be Stopping... at the moment)
				$this->status['progress']['message'] = __( 'The backup could not be started: An online backup cannot be performed if the plugin is not currently logged into the online backup servers.' , 'wponlinebackup' );

				// Update status one second time to mark as finished
				$this->Update_Status( WPONLINEBACKUP_STATUS_NONE );

				return;

			}

		}

		// Store the previous rotation value - we use this when recreating the stream
		$rotation = $this->status['progress']['rotation'];

		// Increase the rotation so if we get interrupted, we implicitly rotate. We'll decrease this back if we exit gracefully.
		$this->status['progress']['rotation']++;

		// Check we still have the lock, but only if we're not stopping
		if ( $this->status['status'] == WPONLINEBACKUP_STATUS_STOPPING ) {

			$check_stop = 1;

		} else {

			// Check for stop again in case we clicked Stop() inbetween us loading the status and doing this update
			if ( !( $check_stop = $this->Update_Status( WPONLINEBACKUP_STATUS_RUNNING ) ) ) return;

		}

		if ( $check_stop === 1 ) {

			// Run cleanup
			$this->CleanUp();

			// Log stopped event
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				sprintf( __( 'The backup was stopped by %s.', 'wponlinebackup' ), $this->status['stop_user'] )
			);

			// Mark as completed
			$this->End_Activity( WPONLINEBACKUP_COMP_STOPPED );

			// Update the message to say we're stopped now (it would be Stopping... at the moment)
			$this->status['progress']['message'] = __( 'The backup was stopped.', 'wponlinebackup' );

			// Update status one second time to mark as finished
			$this->Update_Status( WPONLINEBACKUP_STATUS_NONE );

			return;

		}

		// Clear old temporary files left behind by any previous failed runs as they are now redundant now that we've officially started a new backup run
		$this->Clean_Temps();

		// Register shutdown event - from this point forward we need instant ticking and instant recovery
		register_shutdown_function( array( $this, 'On_Shutdown' ) );

		// Remove any schedule
		wp_clear_scheduled_hook( 'wponlinebackup_perform' );

		// Initialise if required
		if ( $this->status['progress']['initialise'] && ( $ret = $this->Initialise() ) !== true ) {

			// Log the failure event
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				$ret = sprintf( __( 'The backup failed to initialise: %s' , 'wponlinebackup' ), $ret )
			);

			// Mark as failed
			$this->End_Activity( WPONLINEBACKUP_COMP_FAILED );

			// Set message to the error message
			$this->status['progress']['message'] = $ret;

			// End the backup
			$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );

			return;

		}

		// If the stream is null and file is not full (i.e. we don't have a file_set) then we've got a saved stream state so load it
		if ( is_null( $this->stream ) && !is_null( $this->status['progress']['file'] ) ) {

			// Which stream type do we need?
			require_once WPONLINEBACKUP_PATH . '/include/' . strtolower( $this->status['progress']['file']['type'] ) . '.php';

			// Create it
			$name = 'WPOnlineBackup_' . $this->status['progress']['file']['type'];
			$this->stream = new $name( $this->WPOnlineBackup );
			if ( ( $ret = $this->stream->Load( $this->status['progress']['file']['state'], $rotation ) ) !== true )
				$this->stream = null;

		} else {

			$ret = true;

		}

		if ( $ret === true ) {

			// Call the backup processor
			$ret = $this->Backup();

		}

		if ( $ret !== true ) {

			// Clean up the stream if it's still set, otherwise cleanup the file_set if it exists (only one or the other exists)
			if ( !is_null( $this->stream ) ) $this->stream->CleanUp();
			else if ( !is_null( $this->status['progress']['file_set'] ) ) {
				if ( !is_array( $this->status['progress']['file_set']['file'] ) ) @unlink( $this->status['progress']['file_set']['file'] );
				else foreach ( $this->status['progress']['file_set']['file'] as $file ) @unlink( $file );
			}

			// Log event for failure
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				$ret = sprintf( __( 'The backup failed: %s' , 'wponlinebackup' ), $ret )
			);

			// Mark as failed
			$this->End_Activity( WPONLINEBACKUP_COMP_FAILED );

			// Update the status message to be the error message
			$this->status['progress']['message'] = $ret;

			// End te backup
			$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );

			return;

		}

		if ( $this->status['progress']['config']['target'] == 'online' ) {

			// Update the backup serial number
			update_option( 'wponlinebackup_bsn', $this->status['progress']['bsn'] );

			// Cleanup the files, we don't keep them for online backups
			foreach ( $this->status['progress']['file_set']['file'] as $file ) @unlink( $file );
			$this->status['progress']['file_set']['size'] = array_sum( $this->status['progress']['file_set']['size'] );

		} else if ( $this->status['progress']['config']['target'] == 'email' ) {

			// We emailed the backup - no longer need to keep it
			@unlink( $this->status['progress']['file_set']['file'] );

		} else {

			// Store the file path, we keep a full backup until deleted manually
			update_option( 'wponlinebackup_last_full', $this->status['progress']['file_set'] );

		}

		// Run cleanup
		$this->CleanUp();

		// Log the completed event
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_INFORMATION,
			__( 'Backup complete.' , 'wponlinebackup' )
		);

		// Mark the activity as finished
		$this->End_Activity(
			$this->status['progress']['errors'] ? WPONLINEBACKUP_COMP_PARTIAL : WPONLINEBACKUP_COMP_SUCCESSFUL,
			$this->status['progress']
		);

		// End the backup as we're finished
		$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );

		// Clear any hooks left behind to make sure we don't try running anything again (it wouldn't run anything due to the status update but it saves resources)
		wp_clear_scheduled_hook( 'wponlinebackup_perform' );
		wp_clear_scheduled_hook( 'wponlinebackup_perform_check' );
	}

	/*private*/ function CleanUp()
	{
		// Cleanup processors first of all
		$this->CleanUp_Processors();

		// Now cleanup any temporaries - should be empty if this is the CleanUp call for finished or failed backup
		$this->Clean_Temps();

		// Now we scour the tmp and full directories and remove all materials
		// First the tmp directory
		if ( ( $d = @opendir( $p = $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ) ) ) !== false ) {

			while ( ( $f = readdir($d) ) !== false ) {

				// Ignore ., .., .htaccess and full
				if ( $f == '.' || $f == '..' || $f == '.htaccess' || $f == 'full' ) continue;

				// Does it match our file patterns?
				if ( !preg_match( '#^(?:(?:backup\\.data|backup\\.indx)(?:\\.[0-9]+|\\.rc)?|gzipbuffer(?:\\.[0-9]+)?|encbuffer(?:\\.[0-9]+)?|decrypt\\.[A-Za-z0-9\\.]+)\\.php$#', $f ) ) continue;

				// Remove everything else - after online backup completes the tmp folder should be completely empty
				@unlink( $p . '/' . $f );

			}

		}

		// Now the full directory
		if ( ( $d = @opendir( $p = $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ) . '/full' ) ) !== false ) {

			// Grab the current downloadable backup file - we need to make sure we don't delete this
			$last_full = get_option( 'wponlinebackup_last_full', array() );
			if ( isset( $last_full['file'] ) ) $last_full = basename( $last_full['file'] );
			else $last_full = '.';

			while ( ( $f = readdir($d) ) !== false ) {

				// Ignore ., .. and the downloadable backup file if any
				if ( $f == '.' || $f == '..' || $f == $last_full ) continue;

				// Does it match our file patterns?
				if ( !preg_match( '#^(?:backup\\.zip(?:\\.enc)?(?:\\.[0-9]+|\\.rc)?|cdrbuffer(?:\\.[0-9]+)?|gzipbuffer(?:\\.[0-9]+)?|encbuffer(?:\\.[0-9]+)?)\\.php$#', $f ) ) continue;

				// Remove everything else - after online backup completes the tmp folder should be completely empty
				@unlink( $p . '/' . $f );

			}

		}
	}

	/*private*/ function Initialise()
	{
		global $wpdb;

		$progress = & $this->status['progress'];

		// Track the steps we're on
		$next_step = 1;

		// First of all, clean up everything from last backup
		if ( $progress['initialise'] < ++$next_step ) {

			$this->CleanUp();

			$progress['initialise'] = $next_step;

			$this->Tick();

		}

		// First of all, clear back activity logs
		if ( $progress['initialise'] < ++$next_step ) {

			do {

				if ( ( $ret = $wpdb->query(
					'DELETE a, e FROM `' . $this->db_prefix . 'wponlinebackup_activity_log` a ' .
						'LEFT JOIN `' . $this->db_prefix . 'wponlinebackup_event_log` e ON (e.activity_id = a.activity_id) ' .
					'WHERE a.start < ' . strtotime( '-' . $this->WPOnlineBackup->Get_Setting( 'max_log_age' ) . ' months', $progress['start_time'] )
				) ) === false ) return $this->DBError( __LINE__, __FILE__ );

				if ( !$ret ) $progress['initialise'] = $next_step;

				$this->Tick();

			} while ( $ret );

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// Remove last backup from full folder if we're doing a downloadable backup
			if ( $progress['config']['target'] == 'download' ) {

				$last_full = get_option( 'wponlinebackup_last_full', array() );

				if ( array_key_exists( 'file', $last_full ) ) {

					$progress['message'] = __( 'Deleting previous downloadable backup...' , 'wponlinebackup' );

					@unlink( $last_full['file'] );

					$this->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						__( 'Previous downloadable backup deleted.' , 'wponlinebackup' )
					);

				}

				update_option( 'wponlinebackup_last_full', array() );

			}

			$progress['message'] = __( 'Initialising...' , 'wponlinebackup' );

			if ( $progress['config']['target'] == 'online' ) {

				$progress['jobs'][] = array(
					'processor'		=> 'transmission',
					'progress'		=> 0,
					'progresslen'		=> 5,
					'retries'		=> 0,
					'action'		=> 'synchronise',
					'total_items'		=> 0,
					'total_generations'	=> 0,
					'done_items'		=> 0,
					'done_generations'	=> 0,
				);

			}

			$progress['initialise'] = $next_step;

			$this->Tick();

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// Are we backing up the database?
			if ( $progress['config']['backup_database'] ) {

				require_once WPONLINEBACKUP_PATH . '/include/tables.php';
				$tables = new WPOnlineBackup_Backup_Tables( $this->WPOnlineBackup, $this->db_prefix );

				// Initialise - pass ourself so we can log events, and also pass the progress and its tracker
				if ( ( $ret = $tables->Initialise( $this, $progress ) ) !== true )
					return $ret;

			}

			$progress['initialise'] = $next_step;

			$this->Tick();

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// Are we backing up the filesystem?
			if ( $progress['config']['backup_filesystem'] ) {

				require_once WPONLINEBACKUP_PATH . '/include/files.php';
				$files = new WPOnlineBackup_Backup_Files( $this->WPOnlineBackup, $this->db_prefix );

				// Initialise - pass ourselves so we can log events, and also pass the progress and its tracker
				if ( ( $ret = $files->Initialise( $this, $progress ) ) !== true )
					return $ret;

			}

			$progress['initialise'] = $next_step;

			$this->Tick();

		}

		$progress['jobs'][] = array(
			'processor'	=> 'reconstruct',
			'progress'	=> 0,
			'progresslen'	=> 5,
		);

		if ( $progress['config']['target'] == 'online' ) {

			$progress['jobs'][] = array(
				'processor'		=> 'transmission',
				'progress'		=> 0,
				'progresslen'		=> 10,
				'retries'		=> 0,
				'action'		=> 'transmit',
				'total'			=> 0,
				'done'			=> 0,
				'done_retention'	=> 0,
				'retention_size'	=> 0,
				'new_bsn'		=> 0,
				'wait'			=> false,
			);

		} else if ( $progress['config']['target'] == 'email' ) {

			$progress['jobs'][] = array(
				'processor'		=> 'email',
				'progress'		=> 0,
				'progresslen'		=> 10,
			);			

		}

		// Add the cleanups to the end of the job list so they happen only after the main backup jobs have finished
		$progress['jobs'] = array_merge( $progress['jobs'], $progress['cleanups'] );
		$progress['cleanups'] = array();

		foreach ( $progress['jobs'] as $job ) {

			$progress['jobcount'] += $job['progresslen'];

		}

		// Prepare the stream configuration
		// - the streams use this configuration instead of the central configuration so we can use different settings in different streams
		$config = array(
			'designated_path'	=> $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ),
			'compression'		=> $this->WPOnlineBackup->Get_Env( 'deflate_available' ) ? 'DEFLATE' : 'store',
			'encryption'		=> $progress['cache']['enc_type'],
			'encryption_key'	=> $progress['cache']['enc_key'],
		);

		if ( $progress['config']['target'] == 'download' ) {

			// Create the tmp directory - save error if we fail so we can skip the creation of /full and report the error
			if ( !@file_exists( $config['designated_path'] ) && @mkdir( $config['designated_path'], 0700 ) === false )
				$ret = OBFW_Exception();
			else
				$ret = false;

			// Full backups need storing separately
			$config['designated_path'] .= '/full';

		} else {

			$ret = false;

		}

		// If we haven't errored already, check the target path exists
		if ( $ret === false ) {

			if ( !@file_exists( $config['designated_path'] ) && @mkdir( $config['designated_path'], 0700 ) === false ) {

				// Log the error
				$ret = OBFW_Exception();

			}

		}

		// If we had an error, report it and abort the backup
		if ( $ret ) {

			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'The temporary backup directory (%s) where the backup data is processed could not be created.', 'wponlinebackup' ), $config['designated_path'] ) . PHP_EOL .
					__( 'If the below error is related to permissions, you may need to login to your website via FTP and create the folder yourself.', 'wponlinebackup' ) . PHP_EOL .
					__( 'Last PHP error: ', 'wponlinebackup' ) . $ret
			);

			return __( 'Unable to create the temporary backup directory.', 'wponlinebackup' );

		}

		// Check we have a .htaccess and attempt to copy one in now we've validated the existence of the tmp directory
		if ( !@file_exists( $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ) . '/.htaccess' ) )
			@copy( WPONLINEBACKUP_PATH . '/tmp.httpd', $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ) . '/.htaccess' );

		// Set up the required stream
		if ( $progress['config']['target'] == 'online' ) {

			$stream_type = 'Stream_Delta';

		} else {

			$stream_type = 'Stream_Full';

		}

		require_once WPONLINEBACKUP_PATH . '/include/' . strtolower( $stream_type ) . '.php';

		$name = 'WPOnlineBackup_' . $stream_type;
		$this->stream = new $name( $this->WPOnlineBackup );

		// Open the file
		if ( ( $ret = $this->stream->Open( $config, html_entity_decode( get_bloginfo('name'), ENT_QUOTES, get_bloginfo('charset') ), html_entity_decode( get_bloginfo('description'), ENT_QUOTES, get_bloginfo('charset') ) ) ) !== true ) {

			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'The temporary backup file could not be created in the temporary backup directory (%s).', 'wponlinebackup' ), $config['designated_path'] ) . PHP_EOL .
					__( 'If the below error is related to permissions, you may need to login to your website via FTP and change the permissions on the temporary backup directory. The permissions required, in numeric form, are normally 0770, but on some servers you may need to use 0777. Your host will be able to assist if you have any doubts.', 'wponlinebackup' ) . PHP_EOL .
					__( 'Last PHP error: ', 'wponlinebackup' ) . OBFW_Exception()
			);

			return __( 'Unable to create the temporary backup file.', 'wponlinebackup' );

		}

		if ( $progress['config']['target'] == 'email' ) {

			// Check we aren't too big to process. Add 50% to the filesize to allow for MIME encoding and headers etc, and take 5MB from Memory_Limit for processing
			$max = floor( ( ( $memory_limit = WPOnlineBackup_Formatting::Memory_Limit() ) - 5*1024*1024 ) / 2.5 );

			// Impose a limit on the backup size so we don't pointlessly keep backing up if we reach the limit
			$this->stream->Impose_FileSize_Limit( $max, sprintf( __( 'The total backup size has exceeded %s in size and the amount of memory required to encode the backup into email format will require more memory than PHP has available (%s, encoding will require at least 2.5 times the backup size plus 5MB for PHP itself.) The backup has been aborted.' , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $max, true ), WPOnlineBackup_Formatting::Fix_B( $memory_limit, true ) ) );

		}

		// Store the stream state so we can load it when performing
		$progress['file'] = array(
			'type'	=> $stream_type,
			'state'	=> $this->stream->Save(),
		);

		$progress['initialise'] = 0;

		$this->Tick();

		$this->Log_Event(
			WPONLINEBACKUP_EVENT_INFORMATION,
			__( 'Initialisation completed.' , 'wponlinebackup' )
		);

		// Success
		return true;
	}

	/*private*/ function CleanUp_Processors( $ticking = false )
	{
		// For each processor we have loaded, clean it up
		foreach ( $this->processors as $processor ) {

			$processor->CleanUp( $ticking );

		}
	}

	/*private*/ function Fetch_Processor( $processor )
	{
		// If we don't have the processor loaded already, load it
		if ( !array_key_exists( $processor, $this->processors ) ) {

			require_once WPONLINEBACKUP_PATH . '/include/' . $processor . '.php';

			$class = 'WPOnlineBackup_Backup_' . ucfirst( $processor );
			$this->processors[$processor] = new $class( $this->WPOnlineBackup, $this->db_prefix, $this->db_force_master );

		}

		return $this->processors[$processor];
	}

	/*private*/ function Backup()
	{
		// Iterate through keys so we can grab references
		$keys = array_keys( $this->status['progress']['jobs'] );

		$ret = true;

		foreach ( $keys as $key ) {

			$job = & $this->status['progress']['jobs'][$key];

			// Call the correct processor for this job
			switch ( $job['processor'] ) {

				case 'tables':
				case 'files':
				case 'transmission':
				case 'email':
					$processor = $this->Fetch_Processor( $job['processor'] );
					if ( ( $ret = $processor->Backup( $this, $this->stream, $this->status['progress'], $job ) ) !== true ) break 2;
					break;

				case 'reconstruct':
					if ( ( $ret = $this->Reconstruct( $job ) ) !== true ) break 2;
					break;

			}

			// Job done - increase progress and drop the job
			$this->status['progress']['jobdone'] += $job['progresslen'];

			unset( $this->status['progress']['jobs'][$key] );

			// Force an update at the end of each job
			$this->Tick( false, true );

		}

		return $ret;
	}

	/*private*/ function Reconstruct( & $job )
	{
		// Flush all data
		if ( $job['progress'] == 0 ) {

			if ( ( $ret = $this->stream->Flush() ) !== true ) return $ret;

			$job['progress'] = 20;

			$this->Tick( false, true );

		}

		// Close all files
		if ( $job['progress'] == 20 ) {

			if ( ( $ret = $this->stream->Close() ) !== true ) return $ret;

			$job['progress'] = 40;

			$this->Tick();

		}

		// Prepare for reconstruction
		if ( $job['progress'] == 40 ) {

			if ( ( $ret = $this->stream->Start_Reconstruct() ) !== true ) return $ret;

			$job['progress'] = 60;

			$this->Tick();

		}

		// Reconstruct any files that fragmented due to timeouts
		if ( $job['progress'] == 60 ) {

			while ( ( $ret = $this->stream->Do_Reconstruct() ) === true ) {

				$this->Tick();

			}

			if ( !is_array( $ret ) ) return $ret;

			// Store the resulting file set
			$this->status['progress']['file_set'] = array_merge(
				$ret,
				array(
					'files'		=> $this->stream->Files(),
					'compressed'	=> $this->stream->Is_Compressed(),
					'encrypted'	=> $this->stream->Is_Encrypted(),
				)
			);

			$job['progress'] = 95;

			$this->Tick( false, true );

		}

		// End reconstruction - remove any left temporary files etc
		if ( ( $ret = $this->stream->End_Reconstruct() ) !== true ) return $ret;

		// All done, destroy the stream
		$this->stream = null;

		$job['progress'] = 100;

		return true;
	}

	/*public*/ function Process_Pull()
	{
		// Load status
		$this->Load_Status();

		// Send through a content-type header to stop any CDN or rogue plugin modifying our binary stream
		// We had an instance where 0x09 (tab) was getting replaced with 0x20 (space), corrupting the data stream
		header( 'Content-Type: application/octet-stream' );

		// Check we have a backup running
		if ( $this->status['status'] != WPONLINEBACKUP_STATUS_RUNNING && $this->status['status'] != WPONLINEBACKUP_STATUS_TICKING && $this->status['status'] != WPONLINEBACKUP_STATUS_CHECKING )
			die( 'OBFWRF' . $this->status['status'] . ':' . ( isset( $this->status['progress']['comp'] ) ? $this->status['progress']['comp'] : '?' ) );

		// If we're not an online backup, we shouldn't be retrieving
		if ( $this->status['progress']['config']['target'] != 'online' )
			die('OBFWRI2');

		// We may replace all this with WPOnlineBackup_HTTP_Range at some point...

		// Grab the variables - we are safe to assume wponlinebackup_fetch exists in one of _GET and _POST as we don't call this function unless it does
		$nonce = isset( $_GET['wponlinebackup_fetch'] ) ? strval( $_GET['wponlinebackup_fetch'] ) : strval( $_POST['wponlinebackup_fetch'] );
		$which = isset( $_GET['which'] ) ? strval( $_GET['which'] ) : ( isset( $_POST['which'] ) ? strval( $_POST['which'] ) : '' );
		$start = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : ( isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 0 );

		// Check the nonce
		if ( $this->status['progress']['nonce'] == '' )
			die('OBFWRI3');
		else if ( $nonce != $this->status['progress']['nonce'] )
			die('OBFWRI4');

		// Make sure which is acceptable
		$which = ( $which == 'data' ? 'data' : 'indx' );

		// Check we're not starting the transfer past the end of the file
		if ( $start > $this->status['progress']['file_set']['size'][$which] )
			die('OBFWRE1');

		// Open the requested file - open in binary mode! We don't want any conversions happening
		if ( ( $f = @fopen( $this->status['progress']['file_set']['file'][$which], 'rb' ) ) === false )
			die( 'OBFWRE2 ' . OBFW_Exception() );

		if ( @fseek( $f, $this->status['progress']['file_set']['offset'][$which] + $start, SEEK_SET ) != 0 ) {
			$ret = OBFW_Exception();
			@fclose( $f );
			die( 'OBFWRE3 ' . $ret );
		}

		// Clear any data we have in any WordPress buffers - should not get much due to POST but just in case
		$cnt = ob_get_level();
		while ( $cnt-- > 0 )
			ob_end_clean();

		// Avoid timeouts and do not ignore a client abort
		@set_time_limit(0);
		@ignore_user_abort(false);

		// Send the length of the data we're about to pass through - this is OBFWRD (6) + Length of nonce + File Size - Start position
		header( 'Content-Length: ' . ( $this->status['progress']['file_set']['size'][$which] - $start + strlen( $this->status['progress']['nonce'] ) + 6 ) );
		header( 'Content-Disposition: attachment; filename="backup.' . $which . '"' );

		// Print the validation header
		echo 'OBFWRD' . $this->status['progress']['nonce'];

		// Passthrough
		@fpassthru( $f );
		@fclose( $f );

		// Capture any post-request junk - POST should have resolved most of this but double check
		ob_start( 'WPOnlineBackup_Capture_Junk' );

		return true;
	}
}

function WPOnlineBackup_Capture_Junk( $output )
{
	return '';
}

?>
