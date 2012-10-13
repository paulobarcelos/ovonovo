<?php

/*
WPOnlineBackup_Backup_Tables - Workhouse for the database backup
We pass it the stream we want it to use to store the data
*/

class WPOnlineBackup_Backup_Tables
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $bootstrap;
	/*private*/ var $stream;
	/*private*/ var $progress;
	/*private*/ var $job;

	/*private*/ var $internals;

	/*private*/ var $use_wpdb_api = null;

	/*private*/ var $db_prefix;

	/*public*/ function WPOnlineBackup_Backup_Tables( $WPOnlineBackup, $db_prefix, $db_force_master = '' )
	{
		global $wpdb;

		$this->WPOnlineBackup = $WPOnlineBackup;

		$this->db_prefix = $db_prefix;

		// Generate internal table list
		$this->internals = array(
			$db_prefix . 'wponlinebackup_activity_log',
			$db_prefix . 'wponlinebackup_event_log',
			$db_prefix . 'wponlinebackup_generations',
			$db_prefix . 'wponlinebackup_items',
			$db_prefix . 'wponlinebackup_status',
			$db_prefix . 'wponlinebackup_scan_log',
		);
	}

	/*public*/ function Fetch_Available()
	{
		global $wpdb;

// Grab core table list and append prefixes
		$core_list = $this->WPOnlineBackup->Get_Setting( 'core_tables' );
		foreach ( $core_list as $key => $table ) $core_list[$key] = $this->db_prefix . $table;

// Grab table list - if error happens we'll get 0 tables - can be fine
		$tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );

		$core = array();
		$custom = array();

// Loop tables to extract core tables and custom ones
		foreach ( $tables as $entry ) {

			$entry = $entry[0];

// If table is internal to our plugin, ignore
			if ( in_array( $entry, $this->internals ) ) continue;

// If table is a default core wordpress table, add to the core table list
			if ( in_array( $entry, $core_list ) ) {

				$core[] = $entry;
				continue;

			}

// Add to custom table list
			$custom[$entry] = $entry;

		}

// Return the list of tables
		return array( $core, $custom );
	}

	/*public*/ function Initialise( & $bootstrap, & $progress )
	{
		global $wpdb;

// Grab core table list and append prefixes
		$core_list = $this->WPOnlineBackup->Get_Setting( 'core_tables' );
		foreach ( $core_list as $key => $table ) $core_list[$key] = $this->db_prefix . $table;

// Grab table list
		$tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );

// SHOW TABLES would never return no rows (empty array), and an error returns no rows - so no rows will always be treated as an error
		if ( count( $tables ) == 0 ) {

// Failed. Report it to the event log and return the progress tracker unmodified so we can still backup files
			$bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'Failed to retrieve the table list from the database: %s.' , 'wponlinebackup' ), WPOnlineBackup::Get_WPDB_Last_Error() )
			);

			return true;

		}

// Table === true means open stream
		$progress['jobs'][] = array(
			'processor'	=> 'tables',
			'progress'	=> 0,
			'progresslen'	=> 1,
			'table'		=> true,
			'generation'	=> time(),
		);

// Process table list
		foreach ( $tables as $entry ) {
			$entry = $entry[0];

// Is the table one of our own? Skip
			if ( in_array( $entry, $this->internals ) ) continue;
// Is the table a core table? Don't check anything
			else if ( in_array( $entry, $core_list ) );
// Depending on the inclusion settings, skip the table or not
			else if ( $this->WPOnlineBackup->Get_Setting( 'selection_method' ) == 'include' && !in_array( $entry, $this->WPOnlineBackup->Get_Setting( 'selection_list' ) ) ) continue;
			else if ( $this->WPOnlineBackup->Get_Setting( 'selection_method' ) != 'include' && in_array( $entry, $this->WPOnlineBackup->Get_Setting( 'selection_list' ) ) ) continue;

// Add the table to the progress tracker
			$job = array(
				'processor'	=> 'tables',
				'progress'	=> 0,
				'progresslen'	=> 1,
				'table'		=> $entry,
				'total'		=> null,
				'done'		=> 0,
				'last_id'	=> array(),
				'primary'	=> array(),
				'fields'	=> array(),
			);

// Grab indexes from the table
			$indexes = $wpdb->get_results( 'SHOW INDEXES FROM `' . $entry . '`', ARRAY_A );
			$unique = false;

// SHOW INDEXES might return no rows (empty array), but an error would also return an empty array. get_col_info() is tell-tale sign for errors
			if ( !is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

// Find fields that form part of a PRIMARY key. Also look out for a UNIQUE key
				foreach ( $indexes as $index ) {

					if ( $index['Key_name'] == 'PRIMARY' ) {

// Found part of a PRIMARY key, store the field name, and prepare last_id tracker
						$job['primary'][$index['Seq_in_index']] = $index['Column_name'];
						$job['last_id'][$index['Seq_in_index']] = null;

					} else if ( $unique === false && $index['Non_unique'] == 0 ) {

// Found a UNIQUE key, store the key name and ignore any other UNIQUE keys as we only need one
						$unique = $index['Key_name'];

					}

				}

			} else {

// Failed to gather indexes, report it to the event log
				$bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					sprintf( __( 'Failed to retrieve the index list from table \'%s\': %s.' , 'wponlinebackup' ), $entry, WPOnlineBackup::Get_WPDB_Last_Error() )
				);

			}

// If we didn't find a PRIMARY key, but we found a UNIQUE key, locate the columns that form that key, and pretend it is a PRIMARY
// - For what we require, PRIMARY and UNIQUE both provide what we need - a unique key
			if ( count( $job['primary'] ) == 0 && $unique ) {

				foreach ( $indexes as $index ) {

					if ($index['Key_name'] == $unique) {

// Found a column from the UNIQUE key, store the field name, and prepare last_id tracker
						$job['primary'][$index['Seq_in_index']] = $index['Column_name'];
						$job['last_id'][$index['Seq_in_index']] = null;

					}

				}

			}

// If we have a unique key, sort the columns in order of sequence in the key (which we set as the key!)
// When pulling fields out, we will use these keys, in that order - resulting in not only fast queries, but allowing us to keep the backup as consistent as is possible
			if ( count( $job['primary'] ) ) {

				ksort( $job['primary'] );
				ksort( $job['last_id'] );

			} else {

// No key was found! Report it to the event log, and set last_id tracker to null so we know there is none
				$bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_WARNING,
					sprintf( __( 'Table \'%s\' has no PRIMARY or UNIQUE key! Backup of this table may be inconsistent; some rows could get missed. See FAQ for more information.' , 'wponlinebackup' ), $entry )
				);
				$job['last_id'] = null;

			}

// Grab the field list for use later when generating INSERT statements
			$fields = $wpdb->get_results( 'DESCRIBE `' . $entry . '`', ARRAY_A );

// DESCRIBE will always return at least one row, so an empty array (no rows) or error (no rows) both are considered an error
			if ( count( $fields ) ) {

// Store the fields
				foreach ( $fields as $field ) $job['fields'][] = $field['Field'];

			} else {

// Report it to the event log
				$bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					sprintf( __( 'Failed to retrieve field list from table \'%s\': %s. Table will be skipped.' , 'wponlinebackup' ), $entry, WPOnlineBackup::Get_WPDB_Last_Error() )
				);

				continue;

			}

			$progress['jobs'][] = $job;

		}

// Table === false means end stream, commit and cleanup
		$progress['jobs'][] = array(
			'processor'	=> 'tables',
			'progress'	=> 0,
			'progresslen'	=> 1,
			'table'		=> false,
		);

// Return the progress tracker
		return true;
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

		// Cache whether we're bypassing the WPDB API or not
		if ( is_null( $this->use_wpdb_api ) ) $this->use_wpdb_api = $this->WPOnlineBackup->Get_Setting( 'use_wpdb_api' );

		if ( $job['table'] === true ) {

			$progress['message'] = __( 'Starting database backup...' , 'wponlinebackup' );

			$progress['rcount']++;

			if ( ( $ret = $stream->Start_Stream(
				WPONLINEBACKUP_BIN_DATABASE,
				'OBFW_Database.sql',
				$size,
				array(
					'item_id'	=> 1,
					'parent_id'	=> 0,
					'mod_time'	=> $job['generation'],
					'backup_time'	=> $job['generation'],
				)
			) ) !== true ) return $this->bootstrap->FSError( __LINE__, __FILE__, '\'Database/OBFW_Database.sql\'', $ret );

// translators: date() function string when displaying dump time in database backup header
			$date_format = __( 'd-m-Y H.i.s T' , 'wponlinebackup' );

			$header =	'-- ' . _x( 'Online Backup for WordPress', 'Database backup header' , 'wponlinebackup' ) . PHP_EOL .
					'-- ' . sprintf( _x( 'Version %s', 'Database backup header' , 'wponlinebackup' ), WPONLINEBACKUP_VERSION ) . PHP_EOL .
					'-- ' . _x( 'http://www.backup-technology.com/free-wordpress-backup/', 'Database backup header' , 'wponlinebackup' ) . PHP_EOL .
					'--' . PHP_EOL .
					'-- ' . sprintf( _x( 'Blog: %s', 'Database backup header' , 'wponlinebackup' ), get_bloginfo( 'wpurl' ) ) . PHP_EOL .
					'-- ' . sprintf( _x( 'Dump Time: %s', 'Database backup header' , 'wponlinebackup' ), date( $date_format ) ) . PHP_EOL .
					PHP_EOL .
					'SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";' . PHP_EOL .
					PHP_EOL;

			$progress['rsize'] += strlen( $header );

			$ret = $stream->Write_Stream($header);

			$job['progress'] = 100;

		} else if ( $job['table'] === false ) {

			$progress['message'] = __( 'Finalising database backups...' , 'wponlinebackup' );

			if ( $job['progress'] == 0 ) {

				if ( ( $ret = $stream->End_Stream() ) !== true ) return $this->bootstrap->FSError( __LINE__, __FILE__, '\'Database/OBFW_Database.sql\'', $ret );

				$job['progress'] = 33;

				$bootstrap->Tick( false, true );

			}

			if ( $job['progress'] == 33 ) {

				if ( ( $ret = $stream->Commit_Stream() ) !== true ) return $this->bootstrap->FSError( __LINE__, __FILE__, '\'Database/OBFW_Database.sql\'', $ret );

				$job['progress'] = 66;

				$bootstrap->Tick( false, true );

			}

			if ( $job['progress'] == 66 ) {

				$ret = $stream->CleanUp_Stream();

				if ( $ret === true ) {

					$bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						__( 'Database backup completed.' , 'wponlinebackup' )
					);

				}

				$job['progress'] = 100;

			}

		} else {

			$ret = $this->Backup_Table();

		}

		return $ret === true ? true : $this->bootstrap->FSError( __LINE__, __FILE__, '\'Database/OBFW_Database.sql\'', $ret );
	}

	/*private*/ function Query( $fields, $table, $where, $extra, $start )
	{
		global $wpdb;

		// Build the query
		$query =
			'SELECT ' . $fields . ' ' .
			'FROM `' . $table . '`' .
			$where .
			$extra . ' ' .
			'LIMIT ' . $start . $this->WPOnlineBackup->Get_Setting( 'dump_segment_size' );

		if ( $this->use_wpdb_api ) {

			// Use the wacky WPDB API
			$result = $wpdb->get_results( $query, ARRAY_N );

			// Could get back 0 rows (empty array), but error is also an empty array, so lets check get_col_info()
			// My head hurts... We should be failing if there is an error! Not giving 0 rows?! Especially bad during backup!
			if ( is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					sprintf( __( 'Failed to retrieve rows in table \'%s\' using WPDB API: %s. The table may only be partially backed up.' , 'wponlinebackup' ), $this->job['table'], WPOnlineBackup::Get_WPDB_Last_Error() )
				);

				return false;

			}

			reset($result);

			return $result;

		}

		$unbuffered = false;

		while (42) {

			// Steal the database connection resource from WPDB and work with MySQL client directly - we checked this is possible already
			// This way, we can actually do an unbuffered MySQL query and not worry about memory
			if ( $unbuffered )
				$result = @mysql_unbuffered_query( $query, $wpdb->dbh );
			else
				$result = @mysql_query( $query, $wpdb->dbh );

			// Check for error
			if ( $result === false ) {

				if ( @mysql_errno( $wpdb->dbh ) === 2008 ) {

					if ( !$unbuffered ) {
						// MySQL ran out of memory - switch to unbuffered for this segment
						$unbuffered = true;
						continue;
					}

				}

				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					sprintf( __( 'Failed to retrieve rows in table \'%s\' using MySQL client: %s. The table may only be partially backed up.' , 'wponlinebackup' ), $this->job['table'], @mysql_error( $wpdb->dbh ) )
				);

				return false;

			}

			break;

		}

		return $result;
	}

	/*private*/ function Fetch_Row( & $result )
	{
		if ( $this->use_wpdb_api ) {

			// Try to conserve memory as much as possible by discarding entries as we finish with them
			if ( ( $row = current($result) ) === false ) return false;
			$key = key($result);
			next($result);
			unset($result[$key]);
			return $row;

		}

		// Use MySQL client directly
		return @mysql_fetch_array( $result, MYSQL_NUM );
	}

	/*private*/ function Free_Result( $result )
	{
		// Only worked with buffered query but we'll run anyway as we don't store what type it was yet (TODO)
		if ( !$this->use_wpdb_api )
			@mysql_free_result( $result );
	}

	/*public*/ function Backup_Table()
	{
		global $wpdb;

		while ( $this->job['progress'] != 100 ) {

			$where = array();

			if ( count( $this->job['primary'] ) ) {

// We have a primary or unique key, add an order by clause
				$extra = ' ORDER BY ' . WPOnlineBackup_Backup_Tables::Implode_Backquote( ' ASC, ', $this->job['primary'] ) . ' ASC';

// Calculate the where clause based on the key if we already have the table information
// If we don't have the table information yet, we don't give a WHERE so we can get the first set of rows
				if ( !is_null( $this->job['total'] ) ) {

					$previous = array();

// We search for records with ID higher than the last id.
// For multi-column, we check for where the first ID is higher, or the first ID is the same and the second ID is higher, and so on
					foreach ( $this->job['primary'] as $index => $column ) {

						$value = $this->job['last_id'][$index];

// Calls _real_escape if it exists - escape() seems to call _weak_escape() instead
						$wpdb->escape_by_ref( $value );

						if ( count( $previous ) ) $where[] = '(' . implode( ' AND ', $previous ) . ' AND `' . $column . '` > \'' . $this->job['last_id'][$index] . '\')';
						else $where[] = '`' . $column . '` > \'' . $this->job['last_id'][$index] . '\'';
						$previous[] = '`' . $column . '` = \'' . $this->job['last_id'][$index] . '\'';

					}

					if ( count( $where ) > 1 )
						$where = array( '(' . implode( ' OR ', $where ) . ')' );

				}

// When using a key, we don't need a start offset, as we calculate it based on IDs
				$start = '';

			} else {

				$extra = '';

// No primary or unique key available, so we failback to setting a start offset
				if ( !is_null( $this->job['total'] ) ) {
					$start = $this->job['done'] . ', ';
				} else $start = '';

			}

			if ( $this->job['table'] == $this->db_prefix . 'comments' ) {

				if ( $this->WPOnlineBackup->Get_Setting( 'ignore_spam_comments' ) ) $where[] = '`comment_approved` <> \'spam\'';
				if ( $this->WPOnlineBackup->Get_Setting( 'ignore_trash_comments' ) ) $where[] = '`comment_approved` <> \'trash\'';

			} else if ( $this->job['table'] == $this->db_prefix . 'options' ) {

				// Remove this options - it will trigger our tables to be created on restore
				$where[] = '`option_name` <> \'wponlinebackup_check_tables\'';

			}

			$where = implode( ' AND ', $where );
			if ( $where ) $where = ' WHERE ' . $where;

			if ( is_null( $this->job['total'] ) ) {

				$this->progress['message'] = sprintf( __( 'Backing up %s...' , 'wponlinebackup' ), $this->job['table'] );

// Table information doesn't exist, so let's gather it, first by getting the CREATE TABLE dump
				$wpdb->query( 'SET sql_quote_show_create = 1' );

				$create = $wpdb->get_var( 'SHOW CREATE TABLE `' . $this->job['table'] . '`', 1 );

// SHOW CREATE TABLE should always return a row, so 0 rows (null) or error (null) both are considered an error
				if ( is_null( $create ) ) {

// Failed to gather table information - report, and skip the table
					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_ERROR,
						sprintf( __( 'Failed to retrieve information for table \'%s\': %s. The table will be skipped.' , 'wponlinebackup' ), $this->job['table'], WPOnlineBackup::Get_WPDB_Last_Error() )
					);

					$this->job['progress'] = 100;

					break;

				}

				$create .= ';' . PHP_EOL . PHP_EOL;
				$this->progress['rsize'] += strlen( $create );
				if ( ( $ret = $this->stream->Write_Stream( $create ) ) !== true ) return $ret;

// Get the total number of rows in the table, so we can provide progress information if required
				$this->job['total'] = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->job['table'] . '`' . $where );

// SELECT COUNT(*) should always return a row, so 0 rows (null) or error (null) both are considered an error
				if ( is_null( $this->job['total'] ) ) {

// Failed to gather row count - report, and skip the table
					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_ERROR,
						sprintf( __( 'Failed to retrieve row count for table \'%s\': %s. The table will be skipped.' , 'wponlinebackup' ), $this->job['table'], WPOnlineBackup::Get_WPDB_Last_Error() )
					);

					$this->job['progress'] = 100;

					break;

				}

				if ( $this->job['table'] == 'wp_comments' ) {

					if ( $this->WPOnlineBackup->Get_Setting( 'ignore_trash_comments' ) ) 
						$this->bootstrap->Log_Event(
							WPONLINEBACKUP_EVENT_INFORMATION,
							__( 'Ignoring comments in the trash.' , 'wponlinebackup' )
						);

					if ( $this->WPOnlineBackup->Get_Setting( 'ignore_spam_comments' ) ) 
						$this->bootstrap->Log_Event(
							WPONLINEBACKUP_EVENT_INFORMATION,
							__( 'Ignoring comments that are marked as spam.' , 'wponlinebackup' )
						);

				}

				$this->bootstrap->Tick();

			}

			$fields = WPOnlineBackup_Backup_Tables::Implode_Backquote( ',', $this->job['fields'] );

			// Begin retrieving data
			if ( ( $result = $this->Query( $fields, $this->job['table'], $where, $extra, $start ) ) === false ) {

				// Failure is logged inside Query()
				$this->job['progress'] = 100;

				break;

			}

			$this->bootstrap->Tick();

// Create an array of fully escaped insert statements
			$insert = array();
			$row_count = 0;
			while ( ( $next_row = $this->Fetch_Row( $result ) ) !== false ) {
				$row = $next_row;
				$values = array();
				$row_count++;
				foreach ( $row as $index => $value ) {
					if ( is_null( $value ) ) $value = 'NULL';
					else if ( is_numeric( $value ) ) ;
// escape_by_ref uses _real_escape - preferred. escape() appears to only use _weak_escape()
					else {
						$wpdb->escape_by_ref( $value );
						$value = '\'' . $value . '\'';
					}
					$values[] = $value;
				}
				$insert[] = '(' . implode( ',', $values ) . ')';
			}

			$this->Free_Result( $result );

			// If 0 rows were returned, we reached the end of the dataset
			// We couldn't use num_rows or anything as we may be an unbuffered query result
			if ( $row_count == 0 ) {

				$this->job['progress'] = 100;

				break;

			}

			$insert = PHP_EOL . implode( ',' . PHP_EOL, $insert );

			$this->job['done'] += $row_count;

			if ( $this->job['done'] >= $this->job['total'] ) $this->job['progress'] = 99;
			else {
				$this->job['progress'] = floor( ( $this->job['done'] * 99 ) / $this->job['total'] );
				if ( $this->job['progress'] >= 100 ) $this->job['progress'] = 99;
			}

			$this->progress['message'] = sprintf( __( 'Backing up %s; %d of %d rows...' , 'wponlinebackup' ), $this->job['table'], $this->job['done'], $this->job['total'] );

// If we are tracking using a key, update the last_id fields
			if ( count( $this->job['primary'] ) ) {
				foreach ( $this->job['primary'] as $index => $column ) {
					$this->job['last_id'][$index] = $row[ array_search( $column, $this->job['fields'] ) ];
				}
			}

// Create the INSERT statement and add to the dump
			$insert = 'INSERT INTO `' . $this->job['table'] . '` (' . $fields . ') VALUES' . $insert . ';' . PHP_EOL . PHP_EOL;
			$this->progress['rsize'] += strlen( $insert );
			if ( ( $ret = $this->stream->Write_Stream( $insert ) ) !== true ) return $ret;

			$this->bootstrap->Tick();

		}

		return true;
	}

	/*private static*/ function Implode_Backquote( $delimeter, $array )
	{
		reset( $array );
		list ( , $value ) = each( $array );
		$return = '`' . $value . '`';
		while ( list ( , $value ) = each( $array ) ) {
			$return .= $delimeter . '`' . $value . '`';
		}
		return $return;
	}
}

?>
