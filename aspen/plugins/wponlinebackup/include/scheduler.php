<?php

/*
WPOnlineBackup_Scheduler - Manages the savailable schedule options and performs schedule calculations
Also allows a schedule to be restarted
*/

class WPOnlineBackup_Scheduler
{
	// Schedule configuration array
	var $schedule;

	// List of available schedules
	var $schedule_list;

	// List of schedule days and their values in the configuration
	var $schedule_days;

	var $source_list;
	var $target_list;

	/*public*/ function WPOnlineBackup_Scheduler()
	{
		// Load the schedule
		$this->schedule = get_option( 'wponlinebackup_schedule' );

		// Populate the schedule list and schedule days
		$this->schedule_list = array(
			''		=> __( 'Not Scheduled' , 'wponlinebackup' ),
			'hourly'	=> __( 'Hourly' , 'wponlinebackup' ),
			'4daily'	=> __( 'Every 6 Hours (Four Times Daily)' , 'wponlinebackup' ),
			'2daily'	=> __( 'Every 12 Hours (Twice Daily)' , 'wponlinebackup' ),
			'daily'		=> __( 'Daily' , 'wponlinebackup' ),
			'weekly'	=> __( 'Weekly' , 'wponlinebackup' ),
		);

		$this->schedule_days = array(
			0	=> __( 'Sunday' , 'wponlinebackup' ),
			1	=> __( 'Monday' , 'wponlinebackup' ),
			2	=> __( 'Tuesday' , 'wponlinebackup' ),
			3	=> __( 'Wednesday' , 'wponlinebackup' ),
			4	=> __( 'Thursday' , 'wponlinebackup' ),
			5	=> __( 'Friday' , 'wponlinebackup' ),
			6	=> __( 'Saturday' , 'wponlinebackup' ),
		);

		$this->target_list = array(
			'online'	=> __( 'Perform an incremental backup to the online vault' , 'wponlinebackup' ),
			'email'		=> __( 'Perform a full backup and email it to the specified address' , 'wponlinebackup' ),
		);

		// Check the schedule actually exists
		if ( !array_key_exists( $this->schedule['schedule'], $this->schedule_list ) ) $this->schedule['schedule'] = '';
	}

	/*public*/ function Calculate()
	{
		// Calculate the next trigger time
		switch ( $this->schedule['schedule'] ) {

			case 'hourly':
				$now = time();

				// Adjust based on timezone
				list ( $fix_minute ) = $this->Adjust_For_Timezone( $this->schedule['minute'] );

				$next = strtotime( 'today ' . date( 'H', $now ) . ':' . str_pad( $fix_minute, 2, '0', STR_PAD_LEFT ) );
				if ( $next < $now + 60 ) $next = strtotime( '+1 hour', $next );
				break;

			case '4daily':
				$now = time();

				// Adjust based on timezone
				list ( $fix_minute, $fix_hour ) = $this->Adjust_For_Timezone( $this->schedule['minute'], $this->schedule['hour'] );

				while ( $fix_hour >= 6 ) $fix_hour -= 6;

				$next = strtotime( 'today ' . str_pad( $fix_hour, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $fix_minute, 2, '0', STR_PAD_LEFT ) );
				while ( $next < $now + 60 ) $next = strtotime( '+6 hours', $next );
				break;

			case '2daily':
				$now = time();

				// Adjust based on timezone
				list ( $fix_minute, $fix_hour ) = $this->Adjust_For_Timezone( $this->schedule['minute'], $this->schedule['hour'] );

				while ( $fix_hour >= 12 ) $fix_hour -= 12;

				$next = strtotime( 'today ' . str_pad( $fix_hour, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $fix_minute, 2, '0', STR_PAD_LEFT ) );
				while ( $next < $now + 60 ) $next = strtotime( '+12 hours', $next );
				break;

			case 'daily':
				$now = time();

				// Adjust based on timezone
				list ( $fix_minute, $fix_hour ) = $this->Adjust_For_Timezone( $this->schedule['minute'], $this->schedule['hour'] );

				$next = strtotime( 'today ' . $fix_hour . ':' . $fix_minute );
				while ( $next < $now + 60 ) $next = strtotime( '+1 day', $next );
				break;

			case 'weekly':
				$now = time();

				// Adjust based on timezone
				list ( $fix_minute, $fix_hour, $fix_day ) = $this->Adjust_For_Timezone( $this->schedule['minute'], $this->schedule['hour'], $this->schedule['day'] );

				$day = $this->schedule_days[ $fix_day ];

				$next = strtotime( 'this ' . $day . ' ' . str_pad( $fix_hour, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $fix_minute, 2, '0', STR_PAD_LEFT ) );
				if ( $next < $now + 60 ) $next = strtotime( 'next ' . $day . ' ' . str_pad( $fix_hour, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $fix_minute, 2, '0', STR_PAD_LEFT ), $next );
				break;

			default:
				$next = false;
				break;

		}

		return $next;
	}

	/*private*/ function Adjust_For_Timezone( $schedule_minute, $schedule_hour = 0, $schedule_day = 0 )
	{
		// Calculate the hours and minutes offset of the WordPress timezone - make it negative as we're calculating in reverse
		// If want the backup to start at 2am and the WordPress timezone is BST, the backup needs to start at 1am GMT which is 2am BST, so we take the offset backwards
		$seconds = get_option( 'gmt_offset' ) * -3600;
		$hours = ( $seconds - ( $seconds % 3600 ) ) / 3600;
		$seconds -= $hours * 3600;

		// Now add it on to our schedule
		$schedule_hour += $hours;
		$schedule_minute += ( $seconds - ( $seconds % 60 ) ) / 60;

		// Normalise
		if ( $schedule_minute < 0 ) {
			do {
				$schedule_minute += 60;
				$schedule_hours--;
			} while ( $schedule_minute < 0 );
		} else if ( $schedule_minute > 59 ) {
			do {
				$schedule_minute -= 60;
				$schedule_hours++;
			} while ( $schedule_minute > 59 );
		}

		if ( $schedule_hour < 0 ) {
			do {
				$schedule_hour += 24;
				$schedule_day--;
			} while ( $schedule_hour < 0 );
		} else if ( $schedule_hour > 23 ) {
			do {
				$schedule_hour -= 24;
				$schedule_day++;
			} while ( $schedule_hour > 23 );
		}

		if ( $schedule_day < 0 ) {
			do {
				$schedule_day += 7;
			} while ( $schedule_day < 0 );
		} else if ( $schedule_day > 6 ) {
			do {
				$schedule_day -= 7;
			} while ( $schedule_day > 6 );
		}

		return array( $schedule_minute, $schedule_hour, $schedule_day );
	}

	/*public*/ function Restart( $save = true )
	{
		// Clear the previous schedule, if any
		wp_clear_scheduled_hook( 'wponlinebackup_start' );

		// If we have configured a schedule, calculate when it runs and schedule it with WordPress
		if ( $this->schedule['schedule'] != '' && ( $this->schedule['next_trigger'] = $this->Calculate() ) !== false ) {
			wp_schedule_single_event( $this->schedule['next_trigger'], 'wponlinebackup_start' );
		} else {
			$this->schedule['next_trigger'] = null;
		}

		// Save the schedule information (now contains next_trigger)
		if ( $save ) {
			update_option( 'wponlinebackup_schedule', $this->schedule );
		}
	}

	/*public*/ function Update_Legacy()
	{
		// Reload the schedule, this time ignoring if the schedule doesn't exist
		$this->schedule = get_option( 'wponlinebackup_schedule' );

		// Update the schedule...
		$new_schedule = array(
			'schedule'	=> '',
			'day'		=> 0,
			'hour'		=> '00',
			'minute'	=> '00',
			'next_trigger'	=> null,
			'email'		=> $this->schedule['email'],
			'email_to'	=> $this->schedule['email_to'],
			'online'	=> $this->schedule['online'],
		);

		switch ( $this->schedule['schedule'] ) {

			// None
			case '':
				break;

			// Hourly
			case 'hourly':
				$new_schedule['schedule'] = 'hourly';
				break;

			// Twice Daily
			case 'twicedaily':
				$new_schedule['schedule'] = '2daily';
				break;

			// Daily
			case 'daily':
				$new_schedule['schedule'] = 'daily';
				break;

			// Try and get the nearest match
			default:
				$options = wp_get_schedules();

				if ( array_key_exists( $this->schedule['schedule'], $options ) ) {

					if ( $options[ $this->schedule['schedule'] ]['interval'] <= 3600 * 4 ) {

						$new_schedule['schedule'] = 'hourly';

					} else if ( $options[ $this->schedule['schedule'] ]['interval'] <= 3600 * 9 ) {

						$new_schedule['schedule'] = '4daily';

					} else if ( $options[ $this->schedule['schedule'] ]['interval'] <= 3600 * 18 ) {

						$new_schedule['schedule'] = '2daily';

					} else if ( $options[ $this->schedule['schedule'] ]['interval'] <= 8640 * 35 ) {

						$new_schedule['schedule'] = 'daily';

					} else {

						$new_schedule['schedule'] = 'weekly';

					}

				}
				break;

		}

		$this->schedule = $new_schedule;

		update_option( 'wponlinebackup_schedule', $this->schedule );
	}

	/*public*/ function Update_V1()
	{
		// Reload the schedule, this time ignoring if the schedule doesn't exist
		$this->schedule = get_option( 'wponlinebackup_schedule' );

		// We no longer do email AND online due to the way we now handle incrementals, prefer online
		if ( $this->schedule['email'] && !$this->schedule['online'] )
			$target = 'email';
		else
			$target = 'online';

		// Update the schedule...
		$new_schedule = array(
			'schedule'		=> $this->schedule['schedule'],
			'day'			=> $this->schedule['day'],
			'hour'			=> $this->schedule['hour'],
			'minute'		=> $this->schedule['minute'],
			'next_trigger'		=> null,
			'target'		=> $target,
			'email_to'		=> $this->schedule['email_to'],
			'backup_database'	=> true,
			'backup_filesystem'	=> false,
		);

		$this->schedule = $new_schedule;

		update_option( 'wponlinebackup_schedule', $this->schedule );

		// Destroy old schedules
		wp_clear_scheduled_hook( 'WPOnlineBackup_Perform' );
		wp_clear_scheduled_hook( 'WPOnlineBackup_Perform_Check' );
	}
}

?>
