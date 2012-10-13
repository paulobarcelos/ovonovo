<?php

/*
WPOnlineBackup_Backup_Email - Sends the backup via PHPMailer
Simply attaches the backup and sends it to the specified address
*/

class WPOnlineBackup_Backup_Email
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $bootstrap;
	/*private*/ var $stream;
	/*private*/ var $progress;
	/*private*/ var $job;

	/*private*/ var $PHPMailer;
	/*private*/ var $attachment_data;
	/*private*/ var $attachment_filename;

	/*public*/ function WPOnlineBackup_Backup_Email( $WPOnlineBackup, $db_prefix, $db_force_master = '' )
	{
		$this->WPOnlineBackup = $WPOnlineBackup;

		// Need formatting functions here - for Memory_Limit
		require_once WPONLINEBACKUP_PATH . '/include/formatting.php';
	}

	/*public*/ function CleanUp( $ticking = false )
	{
	}

	/*public*/ function Backup( & $bootstrap, & $stream, & $progress, & $job )
	{
		// Save variables and send email
		$this->bootstrap = & $bootstrap;
		$this->stream = & $stream;
		$this->progress = & $progress;
		$this->job = & $job;

		return $this->Send_Email();
	}

	/*public*/ function Action_PHPMailer_Init( & $PHPMailer )
	{
		// Save the PHPMailer instance, and add the attachment with the filename
		$this->PHPMailer = & $PHPMailer;
		$PHPMailer->AddStringAttachment( $this->attachment_data, $this->attachment_filename );

		// Free up the memory
		$this->attachment_data = '';
	}

	/*private*/ function Send_Email()
	{
		global $wpdb;

		// Pre-calculate the backup size and store the text representation
		$text_size = WPOnlineBackup_Formatting::Fix_B( $this->progress['file_set']['size'], true );

		// Change the progress message
		if ( $this->job['progress'] == 0 ) {

			$this->progress['message'] = __( 'Sending email...' , 'wponlinebackup' );

			// Log the size of the backup to help with diagnosis using the event log
			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				sprintf( __( 'Preparing to email the backup which is %s in size.' ), $text_size )
			);

			$this->job['progress'] = 1;

			$this->bootstrap->Tick();

		}

		// Check we aren't too big to process. Add 50% to the filesize to allow for MIME encoding and headers etc, and take 5MB from Memory_Limit for processing
		if ( ( $new_size = $this->progress['file_set']['size'] * 2.5 ) > ( $memory_limit = WPOnlineBackup_Formatting::Memory_Limit() ) - 5*1024*1024 ) {

			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'The amount of memory required to encode the backup into email format (around %s) will consume most, if not all, of PHP\'s available memory of %s.' , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $new_size, true ), WPOnlineBackup_Formatting::Fix_B( $memory_limit, true ) ) . PHP_EOL .
					'Failed at: ' . __FILE__ . ':' . __LINE__
			);

			return sprintf( __( 'The backup file is too large to send as an email attachment. (%s).' , 'wponlinebackup' ), $text_size );

		}

		// Open the backup file for reading into memory
		if ( false === ( $f = @fopen( $this->progress['file_set']['file'], 'r' ) ) )
			return 'Failed to open the backup file for attaching to the email. PHP: ' . OBFW_Exception();

		// Seek past the start
		if ( 0 !== @fseek( $f, $this->progress['file_set']['offset'], SEEK_SET ) )
			return 'Failed to perpare the backup file for attaching to the email. PHP: ' . OBFW_Exception();

		// Read all the data into an output buffer
		ob_start();
		if ( false === @fpassthru( $f ) )
			return 'Failed to read the backup file for attaching to the email. PHP: ' . OBFW_Exception();

		// Grab the output buffer contents and immediately clear the output buffer to free memory
		$this->attachment_data = ob_get_contents();
		ob_end_clean();

		// Calculate the attachment filename
		$this->attachment_filename = preg_replace( '#^(?:.*)backup([^/]*).php$#', 'WPOnlineBackup_Full\\1', $this->progress['file_set']['file'] );

		// Hook into the PHPMailer initialisation so we can borrow a reference to PHPMailer and add the attachment to the email with our own filename
		add_action( 'phpmailer_init', array( & $this, 'Action_PHPMailer_Init' ) );

		// Prepare the email body
		$body = sprintf( __( 'Online Backup for WordPress backup successfully completed. The size of the backup is %s.', 'wponlinebackup' ), $text_size );

		// Require pluggable.php to define wp_mail
		require_once ABSPATH . 'wp-includes/pluggable.php';

		// Send the email
		if ( @wp_mail( $this->progress['config']['email_to'], __( 'Online Backup for WordPress backup completed' , 'wponlinebackup' ), $body, '' ) === false ) {

			$error = OBFW_Exception();

			// Free memory in case it wasn't already
			$this->attachment_data = '';
			$this->attachment_filename = '';

			// Report the error - more information is available in ErrorInfo - use the reference to phpMailer we stole in the hook function
			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				__( 'Failed to send an email containing the backup file.' , 'wponlinebackup' ) . PHP_EOL .
					'Failed at: ' . __FILE__ . ':' . __LINE__ . PHP_EOL .
					'PHPMailer: ' . ( isset( $this->PHPMailer->ErrorInfo ) ? $this->PHPMailer->ErrorInfo : 'ErrorInfo unavailable' ) . PHP_EOL .
					$error
			);

			return sprintf( __( 'Failed to send an email containing the backup file; it may be too large to send via email (%s).' , 'wponlinebackup' ), $text_size );

		} else {

			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				sprintf( __( 'Successfully emailed the backup to %s.' , 'wponlinebackup' ), $this->progress['config']['email_to'] )
			);

		}

		// Remove the hook
		remove_action('phpmailer_init', array(& $this, 'phpmailer_init'));

		// Free memory in case it wasn't already
		$this->attachment_data = '';
		$this->attachment_filename = '';

		$this->job['progress'] = 100;

		return true;
	}
}

?>
