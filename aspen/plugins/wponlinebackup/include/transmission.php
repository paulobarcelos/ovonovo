<?php

/*
WPOnlineBackup_Backup_Transmission - Handles all communication with the online vault
It will perform a synchronisation of the backup status at the start of the backup if we detect we are out of sync
After a backup, it will initiate and monitor transfer to the online vault.
Previous versions we ended backup and left it for server to fetch - now we wait for it to be fetched so we can update our internal status.
*/

define( 'WPONLINEBACKUP_SERVER', 'https://wordpress.backup-technology.com' );

define( 'API_ERROR_LOGIN_BASE',				0x00000100 );
define( 'API_ERROR_CUSTOM_BASE',			0x00001000 );

define( 'API_ERROR_LOGIN_FAILURE',			API_ERROR_LOGIN_BASE +		0x00001 );

define( 'API_ERROR_WPONLINEBACKUP_BASE',		API_ERROR_CUSTOM_BASE +		0x00000000 );

define( 'API_ERROR_WPONLINEBACKUP_KEY_MISMATCH',	API_ERROR_WPONLINEBACKUP_BASE +	0x00000003 );
define( 'API_ERROR_WPONLINEBACKUP_BLOG_NOT_FOUND',	API_ERROR_WPONLINEBACKUP_BASE +	0x00000004 );
define( 'API_ERROR_WPONLINEBACKUP_NOTHING_RUNNING',	API_ERROR_WPONLINEBACKUP_BASE +	0x00000005 );

define( 'TRANSMISSION_IGNORECONNFAILURE',		1 );
define( 'TRANSMISSION_NOTINBACKUP',			2 );
define( 'TRANSMISSION_RETURNERRORS',			4 );

class WPOnlineBackup_Backup_Transmission
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $bootstrap;
	/*private*/ var $stream;
	/*private*/ var $progress;
	/*private*/ var $job;

	/*private*/ var $push_file;

	/*private*/ var $db_prefix;

	/*public*/ function WPOnlineBackup_Backup_Transmission( $WPOnlineBackup, $db_prefix, $db_force_master = '' )
	{
		require_once WPONLINEBACKUP_PATH . '/include/xml.php';

		// Need formatting functions here
		require_once WPONLINEBACKUP_PATH . '/include/formatting.php';

		$this->WPOnlineBackup = $WPOnlineBackup;

		$this->db_prefix = $db_prefix;
	}

	/*private*/ function Generate_Key_Hash( $type = false, $key = false )
	{
		// Generate a hash of the key so we can validate it is still the same as the one used previously
		if ( $type === false )
			$type = $this->WPOnlineBackup->Get_Setting( 'encryption_type' );

		if ( $type == '' ) {

			$key = '';

		} else {

			if ( $key === false )
				$key = $this->WPOnlineBackup->Get_Setting( 'encryption_key' );

			$key = sha1( $type . str_pad( $key, 5120, $type . $key, STR_PAD_RIGHT ) );
			for ( $i = 0; $i <= 64; $i++ )
				$key = sha1( $key );

		}

		return $key;
	}

	/*public*/ function Validate_Account()
	{
		// Validate_Account will return the following:
		//	true: if the account DOES have encryption configuration and the key matches
		//	0: if the account DOES have encryption configuration and the key DOES NOT match
		//	false: if the account has NO encryption configuration
		// 	or a generic transmission error

		// Generate query string with the key hash, but tell Status not to commit the key just yet, so we can still last minute change it
		// Normally we will only commit to it during Synchronise, which means a backup has been run, and it is from this point we don't allow key changing
		$q = array(
			'ka'		=> $this->Generate_Key_Hash(),
			'saveka'	=> 0,
		);

		if ( ( $ret = $this->Get( 'Status', $q, $xml, TRANSMISSION_NOTINBACKUP | TRANSMISSION_RETURNERRORS ) ) !== true ) {

			if ( !is_numeric( $ret ) ) return $ret;

			if ( $xml[0] == API_ERROR_WPONLINEBACKUP_KEY_MISMATCH ) return 0;

			if ( $xml[0] == API_ERROR_LOGIN_FAILURE ) return sprintf( __( 'Failed to login to the online vault: %s' , 'wponlinebackup' ), $xml[1] );

			return sprintf( __( 'An online request failed: The server responded with status code %d and error code %s: %s' , 'wponlinebackup' ), $ret, $xml[0], $xml[1] );

		}

		if ( !isset( $xml->data->Status )
			|| !isset( $xml->data->Status[0]->KeySet[0]->_text )
			|| !isset( $xml->data->Status[0]->BSN[0]->_text )
			|| !isset( $xml->data->Status[0]->Items[0]->_text )
			|| !isset( $xml->data->Status[0]->Generations[0]->_text )
			|| !isset( $xml->data->Status[0]->QuotaMax[0]->_text )
			|| !isset( $xml->data->Status[0]->QuotaUsed[0]->_text ) )
			return __( 'An online request failed: The server response was malformed. Please try again later.' , 'wponlinebackup' );

		// Update the quota
		update_option( 'wponlinebackup_quota', array( 'max' => $xml->data->Status[0]->QuotaMax[0]->_text, 'used' => $xml->data->Status[0]->QuotaUsed[0]->_text ) );

		// Status will create the blog if it doesn't exist, so check if we have keys set
		if ( $xml->data->Status[0]->KeySet[0]->_text ) return true;

		return false;
	}

	/*public*/ function CleanUp( $ticking = false )
	{
	}

	/*public*/ function Backup( & $bootstrap, & $stream, & $progress, & $job )
	{
		$this->bootstrap = & $bootstrap;
		$this->stream = & $stream;
		$this->progress = & $progress;
		$this->job = & $job;

		switch ( $job['action'] ) {

			default:
			case 'synchronise':
				$ret = $this->Synchronise();
				break;

			case 'transmit':
				$ret = $this->Transmit();
				break;

		}

		return $ret;
	}

	/*private*/ function Synchronise()
	{
		global $wpdb;

		$api_retries = $this->WPOnlineBackup->Get_Setting( 'remote_api_retries' );

		if ( $this->job['progress'] == 0 ) {

			$this->progress['message'] = __( 'Connecting to online backup vault...' , 'wponlinebackup' );

			// Generate query string
			$q = array(
				'ka'	=> $this->Generate_Key_Hash( $this->progress['cache']['enc_type'], $this->progress['cache']['enc_key'] ),
			);

			// Grab the backup serial number (BSN)
			while ( ( $ret = $this->Get( 'Status', $q, $xml, $this->job['retries'] >= $api_retries ? 0 : TRANSMISSION_IGNORECONNFAILURE ) ) !== true ) {

				if ( $ret !== false ) return $ret;

				// Increase retry count
				$this->job['retries']++;

				$this->bootstrap->Tick( true );

			}

			$this->job['retries'] = 0;

			if ( !isset( $xml->data->Status )
				|| !isset( $xml->data->Status[0]->BSN[0]->_text )
				|| !isset( $xml->data->Status[0]->Items[0]->_text )
				|| !isset( $xml->data->Status[0]->Generations[0]->_text )
				|| !isset( $xml->data->Status[0]->QuotaMax[0]->_text )
				|| !isset( $xml->data->Status[0]->QuotaUsed[0]->_text ) )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

			// Update the quota
			update_option( 'wponlinebackup_quota', array( 'max' => $max = $xml->data->Status[0]->QuotaMax[0]->_text, 'used' => $xml->data->Status[0]->QuotaUsed[0]->_text ) );

			// Impose a limit on the backup size so we don't pointlessly keep backing up if we reach the limit
			// This should probably be a datasize limit but unfortunately the online vault restricts the filesize.
			// The filesize will be bigger if we are marking files as deleted (DEL entries) but for 99.9% of blogs the difference due to DEL entries will be extremely insignificant.
			// So just use filesize until the server only restricts the data and not the DEL entries
			$this->stream->Impose_FileSize_Limit( $max, sprintf( __( 'The total backup size exceeds your maximum online quota of %s, and therefore cannot be transmitted to the online vault. The backup has been aborted.', 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $max, true ) ) );

			// If it's the same as ours, we are fully in sync, set progress to 100 to move onto the backup stages
			if ( ( $this->progress['bsn'] = intval( $xml->data->Status[0]->BSN[0]->_text ) ) == get_option( 'wponlinebackup_bsn', '0' ) && get_option( 'wponlinebackup_in_sync', 0 ) ) {

				$this->job['progress'] = 100;

				return true;

			}

			$this->progress['message'] = __( 'Backup status is out of sync, synchronising with server...' , 'wponlinebackup' );

			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				__( 'Backup status is out of sync, synchronising with server...' , 'wponlinebackup' )
			);

			// We will start downloading the full file list from the server so we are in sync
			$this->job['total_items'] = intval( $xml->data->Status[0]->Items[0]->_text );
			$this->job['total_generations'] = intval( $xml->data->Status[0]->Generations[0]->_text );

			$this->job['progress'] = 1;

			$this->bootstrap->Tick();

		}

		if ( $this->job['progress'] == 1 ) {

			do {

				if ( ( $ret = $wpdb->query(
					'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_items` ' .
					'LIMIT 500'
				) ) === false ) return $this->bootstrap->DBError( __FILE__, __LINE__ );

				$this->bootstrap->Tick();

			} while ( $ret );

			$this->job['progress'] = 2;

		}

		if ( $this->job['progress'] == 2 ) {

			do {

				if ( ( $ret = $wpdb->query(
					'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_generations` ' .
					'LIMIT 500'
				) ) === false ) return $this->bootstrap->DBError( __FILE__, __LINE__ );

				$this->bootstrap->Tick();

			} while ( $ret );

			$this->job['progress'] = 5;

		}

		$segment_size = $this->WPOnlineBackup->Get_Setting( 'sync_segment_size' );

		while ( $this->job['progress'] < 53 ) {

			// Download the file list for this serial number - we pass the serial number so we can detect a change in the middle of the synchronisation and abort
			$query = array(
				'bsn'		=> $this->progress['bsn'],
				'start'		=> $this->job['done_items'],
				'limit'		=> $segment_size,
			);

			while ( ( $ret = $this->Get( 'SynchroniseItems2', $query, $xml, $this->job['retries'] >= $api_retries ? 0 : TRANSMISSION_IGNORECONNFAILURE ) ) !== true ) {

				if ( $ret !== false ) return $ret;

				// Increase retry count
				$this->job['retries']++;

				$this->bootstrap->Tick( true );

			}

			$this->job['retries'] = 0;

			if ( !isset( $xml->data->SynchroniseItems[0]->_attr->Final ) )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

			// If final attribute is 1, we are finished and can exit
			if ( $xml->data->SynchroniseItems[0]->_attr->Final == 1 ) {

				$this->job['progress'] = 53;

				$this->bootstrap->Tick();

				break;

			}

			if ( !isset( $xml->data->SynchroniseItems[0]->Item ) || count( $xml->data->SynchroniseItems[0]->Item ) == 0 )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

			// Build the insert
			$inserts = array();

			foreach ( $xml->data->SynchroniseItems[0]->Item as $item ) {

				if ( !isset( $item->ID[0]->_text )
					|| !isset( $item->Bin[0]->_text )
					|| !isset( $item->ParentID[0]->_text )
					|| !isset( $item->Type[0]->_text )
					|| !isset( $item->Name[0]->_text )
					|| !isset( $item->Exists[0]->_text ) )
					return $this->bootstrap->MALError( __FILE__, __LINE__, $xml, 'Completed entries: ' . count( $inserts ) );

				$item->Bin[0]->_text = intval( $item->Bin[0]->_text );
				$item->ID[0]->_text = intval( $item->ID[0]->_text );
				$item->ParentID[0]->_text = intval( $item->ParentID[0]->_text );
				$item->Type[0]->_text = intval( $item->Type[0]->_text );
				$item->Name[0]->_text = base64_decode( $item->Name[0]->_text );
				$wpdb->escape_by_ref( $item->Name[0]->_text );
				$item->Exists[0]->_text = intval( $item->Exists[0]->_text );
				if ( isset( $item->FileSize[0]->_text ) ) $item->FileSize[0]->_text = intval( $item->FileSize[0]->_text );
				else $item->FileSize[0]->_text = 'NULL';
				if ( isset( $item->ModTime[0]->_text ) ) $item->ModTime[0]->_text = intval( $item->ModTime[0]->_text );
				else $item->ModTime[0]->_text = 'NULL';
				if ( isset( $item->Path[0]->_text ) ) {
					$item->Path[0]->_text = base64_decode( $item->Path[0]->_text );
					$wpdb->escape_by_ref( $item->Path[0]->_text );
				} else {
					$item->Path[0]->_text = '';
				}

				$inserts[] = '(' . $item->Bin[0]->_text . ', ' . $item->ID[0]->_text . ', ' . $item->ParentID[0]->_text . ', ' . $item->Type[0]->_text . ', ' .
						'\'' . $item->Name[0]->_text . '\', ' . $item->Exists[0]->_text . ', ' . $item->FileSize[0]->_text . ', ' .
						$item->ModTime[0]->_text . ', \'' . $item->Path[0]->_text . '\')';

			}

			$this->job['done_items'] += count( $inserts );

			$inserts = implode( ',', $inserts );

			// Do the insert
			if ( $wpdb->query(
				'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_items` ' .
					'(bin, item_id, parent_id, type, name, `exists`, file_size, mod_time, path) ' .
				'VALUES ' . $inserts
			) === false )
				return $this->bootstrap->DBError( __FILE__, __LINE__ );

			// Update the progress counter
			if ( $this->job['done_items'] >= $this->job['total_items'] ) $this->job['progress'] = 52;
			else {
				$this->job['progress'] = 5 + floor( ( $this->job['done_items'] * 48 ) / $this->job['total_items'] );
				if ( $this->job['progress'] >= 53 ) $this->job['progress'] = 52;
			}

			$this->bootstrap->Tick();

		}

		while ( $this->job['progress'] < 100 ) {

			// Download the file list for this serial number - we pass the serial number so we can detect a change in the middle of the synchronisation and abort
			$query = array(
				'bsn'		=> $this->progress['bsn'],
				'start'		=> $this->job['done_generations'],
				'limit'		=> $segment_size,
			);

			while ( ( $ret = $this->Get( 'SynchroniseGenerations', $query, $xml, $this->job['retries'] >= $api_retries ? 0 : TRANSMISSION_IGNORECONNFAILURE ) ) !== true ) {

				if ( $ret !== false ) return $ret;

				// Increase retry count
				$this->job['retries']++;

				$this->bootstrap->Tick( true );

			}

			$this->job['retries'] = 0;

			if ( !isset( $xml->data->SynchroniseGenerations[0]->_attr->Final ) )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

			// If final attribute is 1, we are finished and can exit
			if ( $xml->data->SynchroniseGenerations[0]->_attr->Final == 1 ) {

				$this->job['progress'] = 100;

				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_INFORMATION,
					__( 'Synchronisation of backup data completed.' , 'wponlinebackup' )
				);

				$this->bootstrap->Tick();

				break;

			}

			// Build the insert
			$inserts = array();

			if ( !isset( $xml->data->SynchroniseGenerations[0]->Generation ) || count( $xml->data->SynchroniseGenerations[0]->Generation ) == 0 )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

			foreach ( $xml->data->SynchroniseGenerations[0]->Generation as $generation ) {

				if ( !isset( $generation->Bin[0]->_text )
					|| !isset( $generation->ID[0]->_text )
					|| !isset( $generation->BackupTime[0]->_text ) )
					return $this->bootstrap->MALError( __FILE__, __LINE__, $xml, 'Completed entries: ' . count( $inserts ) );

				$generation->Bin[0]->_text = intval( $generation->Bin[0]->_text );
				$generation->ID[0]->_text = intval( $generation->ID[0]->_text );
				$generation->BackupTime[0]->_text = intval( $generation->BackupTime[0]->_text );
				if ( isset( $generation->DeletedTime[0]->_text ) ) $generation->DeletedTime[0]->_text = intval( $generation->DeletedTime[0]->_text );
				else $generation->DeletedTime[0]->_text = 'NULL';
				if ( isset( $generation->FileSize[0]->_text ) ) $generation->FileSize[0]->_text = intval( $generation->FileSize[0]->_text );
				else $generation->FileSize[0]->_text = 'NULL';
				if ( isset( $generation->ModTime[0]->_text ) ) $generation->ModTime[0]->_text = intval( $generation->ModTime[0]->_text );
				else $generation->ModTime[0]->_text = 'NULL';

				$inserts[] = '(' . $generation->Bin[0]->_text . ', ' . $generation->ID[0]->_text . ', ' . $generation->BackupTime[0]->_text . ', ' .
					$generation->DeletedTime[0]->_text . ', ' . $generation->FileSize[0]->_text . ', ' . $generation->ModTime[0]->_text . ')';

			}

			$this->job['done_generations'] += count( $inserts );

			$inserts = implode( ',', $inserts );

			// Do the insert
			if ( $wpdb->query(
				'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_generations` ' .
					'(bin, item_id, backup_time, deleted_time, file_size, mod_time) ' .
				'VALUES ' . $inserts
			) === false )
				return $this->bootstrap->DBError( __FILE__, __LINE__ );

			// Update the progress counter
			if ( $this->job['done_generations'] >= $this->job['total_generations'] ) $this->job['progress'] = 99;
			else {
				$this->job['progress'] = 53 + floor( ( $this->job['done_generations'] * 47 ) / $this->job['total_generations'] );
				if ( $this->job['progress'] >= 100 ) $this->job['progress'] = 99;
			}

			$this->bootstrap->Tick();

		}

		// Now in sync
		update_option( 'wponlinebackup_in_sync', '1' );
		update_option( 'wponlinebackup_bsn', $this->progress['bsn'] );

		return true;
	}

	/*private*/ function Transmit()
	{
		global $wpdb;

		$api_retries = $this->WPOnlineBackup->Get_Setting( 'remote_api_retries' );

		if ( $this->job['progress'] == 0 ) {

			// Generate random password for server to pull the backup with
			$this->progress['nonce'] = sha1( time() . serialize( $this->progress ) . 'online' );

			// Grab filesizes
			$indx_size = $this->progress['file_set']['size']['indx'];
			$data_size = $this->progress['file_set']['size']['data'];
			$this->job['total'] = $indx_size + $data_size;

			// Progress now 5% so we skip this initialisation
			$this->job['progress'] = 5;

			// Save the above and then force a commit to the database (we only commmit once every X number of ticks)
			// This fixes a rare instance where the server would begin to pull the backup before the GET request we make had completed
			// This meant the Process_Pull method did not see any nonce set, and rejected the backup
			$this->bootstrap->Tick( false, true );

		}

		if ( $this->job['progress'] == 5 ) {

			// Make the request for a pull
			$query = array(
				'bsn'		=> $this->progress['bsn'],
				'indx_size'	=> $indx_size,
				'data_size'	=> $data_size,
				'nonce'		=> $this->progress['nonce'],
				'start_time'	=> $this->progress['start_time'],
			);

			while ( ( $ret = $this->Get( 'Pull', $query, $xml, $this->job['retries'] >= $api_retries ? 0 : TRANSMISSION_IGNORECONNFAILURE ) ) !== true ) {

				if ( $ret !== false ) return $ret;

				// Increase retry count
				$this->job['retries']++;

				$this->bootstrap->Tick( true );

			}

			$this->job['retries'] = 0;

			if ( !isset( $xml->data->Pull )
				|| isset( $xml->data->Pull[0]->_text ) )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

			// Update progress
			$this->progress['message'] = sprintf( __( 'Transmitting the backup files to the server... (total size is %s)' , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $this->job['total'], true ) );

			$this->job['progress'] = 10;

			// Force an immedate tick and tell it to immediately end this script run - we'll check status on next script run as server may take a few minutes to finish
			$this->bootstrap->Tick( true );

		}

		while ( $this->job['progress'] < 99 ) {

			// Some PullStatus results will set a wait flag to force us to essentially sleep for at least wait * min_execution_time
			// This is here to significantly reduce load on our servers from thousands of PullStatus requests, and should not increase backup times that much
			if ( $this->job['wait'] !== false && $this->job['wait'] > time() ) {

				$this->bootstrap->Tick( true );

			}

			$query = array(
				'bsn'		=> $this->progress['bsn'],
				'start'		=> $this->job['done_retention'],
			);

			// Grab status from server
			while ( ( $ret = $this->Get( 'PullStatus', $query, $xml, $this->job['retries'] >= $api_retries ? 0 : TRANSMISSION_IGNORECONNFAILURE ) ) !== true ) {

				if ( $ret !== false ) return $ret;

				// Increase retry count
				$this->job['retries']++;

				$this->bootstrap->Tick( true );

			}

			$this->job['retries'] = 0;

			if ( !isset( $xml->data->PullStatus[0]->_attr->Complete ) )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

			if ( $xml->data->PullStatus[0]->_attr->Complete == '1' ) {

				if ( isset( $xml->data->PullStatus[0]->_text ) )
					return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

				if ( $this->job['progress'] < 80 ) {

					// Processing - set to 80% and update message
					$this->job['progress'] = 80;

					$this->progress['message'] = __( 'Waiting while the server processes the backup data...' , 'wponlinebackup' );

					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						__( 'Transmission of the backup data to the online vault has completed.' , 'wponlinebackup' )
					);

				}

				// Force a tick that schedules the next run - no rush with server processing the backup
				// Also set a wait so we don't call PullStatus again for a bit
				$this->job['wait'] = time() + 30;

				$this->bootstrap->Tick( true );

			} else if ( $xml->data->PullStatus[0]->_attr->Complete == '2' ) {

				if ( $this->job['progress'] < 90 ) {

					// Catch up with messages
					if ( $this->job['progress'] < 80 ) {

						$this->bootstrap->Log_Event(
							WPONLINEBACKUP_EVENT_INFORMATION,
							__( 'Transmission of the backup data to the online vault has completed.' , 'wponlinebackup' )
						);

					}

					// Doing retention - set to 90% and update message
					$this->job['progress'] = 90;

					$this->progress['message'] = __( 'Performing retention...' , 'wponlinebackup' );

					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						__( 'The online vault has successfully processed the backup data.' , 'wponlinebackup' )
					);

					$this->bootstrap->Tick();

				}

				// Perform retention
				if ( !isset( $xml->data->PullStatus[0]->DeletedGeneration ) )
					return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

				$i = 0;

				foreach ( $xml->data->PullStatus[0]->DeletedGeneration as $gen ) {

					if ( !isset( $gen->_attr->Item )
						|| !isset( $gen->_attr->Size )
						|| !isset( $gen->_text ) )
						return $this->bootstrap->MALError( __FILE__, __LINE__, $xml, 'Iteration ' . $i );

					// Do the delete
					if ( $wpdb->query(
						'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_generations` ' .
						'WHERE item_id = ' . intval( $gen->_attr->Item ) . ' AND backup_time = ' . intval( $gen->_text )
					) === false )
						return $this->bootstrap->DBError( __FILE__, __LINE__ );

					++$this->job['done_retention'];
					$this->job['retention_size'] += $gen->_attr->Size;

					$i++;

				}

				// Tick
				$this->bootstrap->Tick();

			} else if ( $xml->data->PullStatus[0]->_attr->Complete == '3' ) {

				// Grab new BSN
				if ( !isset( $xml->data->PullStatus[0]->BSN[0]->_text ) )
					return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

				// Catch up with messages
				if ( $this->job['progress'] < 80 ) {

					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						__( 'Transmission of the backup data to the online vault has completed.' , 'wponlinebackup' )
					);

				}

				if ( $this->job['progress'] < 90 ) {

					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						__( 'The online vault has successfully processed the backup data.' , 'wponlinebackup' )
					);

				}

				// Log the number of removed files and the total size
				if ( $this->job['done_retention'] )
					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						sprintf( _n(
							'Retention completed; deleted %d file with a total size of %s, to reduce the online stored amount to below your maximum quota.',
							'Retention completed; deleted %d files with a total size of %s, to reduce the online stored amount to below your maximum quota.',
							$this->job['done_retention']
						, 'wponlinebackup' ), $this->job['done_retention'], WPOnlineBackup_Formatting::Fix_B( $this->job['retention_size'], true ) )
					);
				else
					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						__( 'Retention was not required, you are still within your maximum quota.' , 'wponlinebackup' )
					);

				$this->job['new_bsn'] = $xml->data->PullStatus[0]->BSN[0]->_text;

				if ( $this->job['progress'] < 99 ) {

					// Completed, set to 99% so we can run the PullComplete
					$this->job['progress'] = 99;

					$this->progress['message'] = __( 'Processing backup log...' , 'wponlinebackup' );

				}

				// Force an update here, the call to PullComplete() will make the server clear all status of this backup,
				// meaning if we die, we can't start calling PullStatus() to grab retention information again because it will all be cleared
				$this->bootstrap->Tick( false, true );

			} else if ( $xml->data->PullStatus[0]->_attr->Complete == '4' ) {

				// Error occurred on server
				if ( !isset( $xml->data->PullStatus[0]->Error[0]->_text ) )
					return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

				switch ( $xml->data->PullStatus[0]->Error[0]->_text ) {
					case 'ALREADY_IN_USE':
						return __( 'The server is already part way through receiving another backup. Please try again in a few moments.' , 'wponlinebackup' );
					case 'CONNECT_FAILED':
						return __( 'The connection to your blog failed. This may be a temporary failure or your blog may not be accessible over the internet. Please try again later.' , 'wponlinebackup' );
					case 'CONNECT_TIMEOUT':
						return __( 'The connection to your blog timed out part way through receiving the backup data. Please check your blog is not experiencing any network issues and try again later.' , 'wponlinebackup' );
					case 'RETRIEVE_FAILED':
						return __( 'The server failed to retrieve data from your blog. Please check your blog is not experiencing any network issues and try again later.' , 'wponlinebackup' );
					case 'EXCEEDS_QUOTA':
						return sprintf( __( 'The backup is larger than your complete quota on the online vault. It cannot be stored. Please reduce the backup size by excluding something - it is currently %s in size.' , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( array_sum( $this->progress['file_set']['size'] ), true ) );
					case 'JUNK':
						return __( 'The server attempted to retrieve the data, but received junk from your blog. This can happen if your blog is not accessible over the internet. Otherwise, you may have a third-party plugin installed that is changing the backup data as the server tries to receive it. Please contact support if this is the case so we may improve compatibility.' , 'wponlinebackup' );
					case 'LOCKED':
						return __( 'The backup data on the server is currently locked. This is usually the case when a request has been made to delete the blog data and the server is still performing the deletion.' , 'wponlinebackup' );
					case 'UNKNOWN_FAILURE':
				}

				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					__( 'The server failed to retrieve the backup. The reason is unknown.' , 'wponlinebackup' ) . PHP_EOL .
						sprintf( __( 'The unknown error token the server returned was: %s' , 'wponlinebackup' ), $xml->data->PullStatus[0]->Error[0]->_text )
				);

				return sprintf( __( 'The server failed to retrieve the backup. The reason is unknown. Please consult the activity log.' , 'wponlinebackup' ) );

			} else {

				// Grab the status
				if ( !isset( $xml->data->PullStatus[0]->ReceivedIndx[0]->_text )
					|| !isset( $xml->data->PullStatus[0]->ReceivedData[0]->_text ) )
					return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

				$this->job['done'] = intval( $xml->data->PullStatus[0]->ReceivedIndx[0]->_text ) + intval( $xml->data->PullStatus[0]->ReceivedData[0]->_text );

				// Update the progress
				$this->progress['message'] = sprintf( __( 'Transmitting the backup files to the server... (%s of %s transferred so far)' , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $this->job['done'], true ), WPOnlineBackup_Formatting::Fix_B( $this->job['total'], true ) );

				if ( $this->job['progress'] < 80 ) {

					if ( $this->job['done'] >= $this->job['total'] ) $this->job['progress'] = 79;
					else {
						$this->job['progress'] = 10 + floor( ( $this->job['done'] * 74 ) / $this->job['total'] );
						if ( $this->job['progress'] >= 100 ) $this->job['progress'] = 79;
					}

				}

				// Force a tick that schedules the next run - no rush with server getting the backup
				// Also set a wait so we don't call PullStatus again for a bit
				$this->job['wait'] = time() + 30;

				$this->bootstrap->Tick( true );

			}

		}

		if ( $this->job['progress'] == 99 ) {

			$query = array(
				'bsn'		=> $this->progress['bsn'],
			);

			// Notify server we are done - make us return errors
			while ( ( $ret = $this->Get( 'PullComplete', $query, $xml, TRANSMISSION_RETURNERRORS | ( $this->job['retries'] >= $api_retries ? 0 : TRANSMISSION_IGNORECONNFAILURE ) ) ) !== true ) {

				if ( $ret !== false ) {

					// If ret is not numeric it was a communication error, so return it
					if ( !is_numeric( $ret ) ) return $ret;

					// We have a server error, let it through
					break;

				}

				// Increase retry count
				$this->job['retries']++;

				$this->bootstrap->Tick( true );

			}

			$this->job['retries'] = 0;

			// Did we get a server error?
			if ( $ret !== true ) {

				// If the server error is a backup is not running - we could easily have died and not managed to save the fact we already have called PullComplete()
				// We consider this a safe assumption as we should always succeed on PullComplete() at least once if our calls to PullStatus() were successful
				// Worst case is PullComplete() never ran and the server side cleanup is delayed until the next backup, and generations previously removed by retention are given to us again on the next backup
				// So if the error is that a backup is not running, continue regardless, and return the error otherwise
				if ( $xml[0] != API_ERROR_WPONLINEBACKUP_NOTHING_RUNNING )
					return $this->API_Failure( $ret, $xml[0], $xml[1] );

			} else {

				if ( !isset( $xml->data->PullComplete[0]->QuotaMax[0]->_text )
					|| !isset( $xml->data->PullComplete[0]->QuotaUsed[0]->_text ) )
					return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );

				// Update the quota
				update_option( 'wponlinebackup_quota', array( 'max' => $xml->data->PullComplete[0]->QuotaMax[0]->_text, 'used' => $xml->data->PullComplete[0]->QuotaUsed[0]->_text ) );

			}

			// Now cleanup deleted generations that have no entries anymore
			if ( $wpdb->query(
				'DELETE i FROM `' . $this->db_prefix . 'wponlinebackup_items` i ' .
					'LEFT JOIN `' . $this->db_prefix . 'wponlinebackup_generations` g ON (g.bin = i.bin AND g.item_id = i.item_id)' .
				'WHERE g.backup_time IS NULL'
			) === false )
				return $this->bootstrap->DBError( __FILE__, __LINE__ );

			// Set status to 100%
			$this->job['status'] = 100;

			$this->progress['bsn'] = $this->job['new_bsn'];

		}

		return true;
	}

	/*private*/ function Get( $api, $data, & $xml, $flags )
	{
		return $this->Request( 'get', $api, $data, $xml, null, $flags );
	}

	/*private*/ function Request( $method, $api, $data, & $xml, $body, $flags )
	{
		$q = array();

		$api = urlencode( $api );

		// Create the query string
		$data['blogurl']	= !( $flags & TRANSMISSION_NOTINBACKUP ) ? $this->progress['cache']['blogurl'] : WPOnlineBackup::Get_Main_Site_URL();
		$data['username']	= !( $flags & TRANSMISSION_NOTINBACKUP ) ? $this->progress['cache']['username'] : $this->WPOnlineBackup->Get_Setting( 'username' );
		$data['password']	= !( $flags & TRANSMISSION_NOTINBACKUP ) ? $this->progress['cache']['password'] : $this->WPOnlineBackup->Get_Setting( 'password' );
		$data['version']	= WPONLINEBACKUP_VERSION;
		$data['lang']		= WPLANG;
		foreach ( $data as $key => $value )
			$q[] = urlencode( $key ) . '=' . urlencode( $value );
		$q = '?' . implode( '&', $q );

		if ( $method == 'get' ) $function = 'wp_remote_get';
		else $function = 'wp_remote_post';

		// Make the request
		$response = call_user_func(
			$function,
			WPONLINEBACKUP_SERVER . '/API/' . $api . $q,
			array(
				'timeout'	=> 30,
				'sslverify'	=> !$this->WPOnlineBackup->Get_Setting( 'ignore_ssl_cert' ),
				'body'		=> $body,
			)
		);

		if ( is_wp_error( $response ) ) {

			if ( $flags & TRANSMISSION_IGNORECONNFAILURE )
				return false;

			if ( !( $flags & TRANSMISSION_RETURNERRORS ) )
				return $this->bootstrap->COMError(
					__FILE__, __LINE__,
					sprintf( __( 'A request failed for API %s.' . PHP_EOL . 'Please ensure your blog is able to perform HTTPS requests to %s. You may need to contact your web host regarding this.' , 'wponlinebackup' ), $api, WPONLINEBACKUP_SERVER ) . PHP_EOL .
						OBFW_Exception_WP( $response ),
					__( 'Communication with the online backup vault failed; more information can be found in the Activity Log.' , 'wponlinebackup' )
				);
			else
				return sprintf( __( 'Communication with the online backup vault failed. Please ensure your blog is able to perform HTTPS requests to %s. You may need to contact your web host regarding this. The error was: %s.' , 'wponlinebackup' ), WPONLINEBACKUP_SERVER, OBFW_Exception_WP( $response ) );

		}

		// Parse the response
		$xml = new WPOnlineBackup_XML();

		if ( ( $ret = $xml->fetch( $response['body'] ) ) !== true ) {

			if ( !( $flags & TRANSMISSION_RETURNERRORS ) )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml, $ret );
			else
				return $ret;

		}

		if ( $response['response']['code'] == 200 ) return true;

		// Parse the error response
		if ( !isset( $xml->data->Error )
			|| !isset( $xml->data->Error[0]->Number )
			|| !isset( $xml->data->Error[0]->Message ) ) {

			if ( !( $flags & TRANSMISSION_RETURNERRORS ) )
				return $this->bootstrap->MALError( __FILE__, __LINE__, $xml );
			else
				return __( 'An online request failed: The server response was malformed. Please try again later.' , 'wponlinebackup' );

		}

		$number = isset( $xml->data->Error[0]->Number[0]->_text ) ? $xml->data->Error[0]->Number[0]->_text : 'Unknown';
		$message = isset( $xml->data->Error[0]->Message[0]->_text ) ? $xml->data->Error[0]->Message[0]->_text : 'Unknown';

		if ( !( $flags & TRANSMISSION_RETURNERRORS ) )
			return $this->API_Failure( $response['response']['code'], $number, $message );
		else {
			$xml = array( $number, $message );

			return $response['response']['code'];
		}
	}

	/*private*/ function API_Failure( $code, $number, $message )
	{
		return $this->bootstrap->COMError(
			__FILE__, __LINE__,
			sprintf( __( 'An online request failed: The server responded with status code %d and error code %s: %s' , 'wponlinebackup' ), $code, $number, $message ),
			sprintf( __( 'The server returned the following error message: %s' , 'wponlinebackup' ), $message )
		);
	}
}

?>
