<?php

/*
WPOnlineBackup_Compressor_None - Doesn't compress data. This is a stub class that just returns data uncompressed
Get_ZIP_Code() will return the compression code which should be stored in a ZIP archive - 0x00 in this case
*/

class WPOnlineBackup_Compressor_None
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $writer;

	/*private*/ var $rolling_size;
	/*private*/ var $rolling_crc;
	/*private*/ var $rolling_hash_ctx;
	/*private*/ var $rolling_hash_len;

	/*private*/ var $tf;

	/*public*/ function WPOnlineBackup_Compressor_None( & $WPOnlineBackup )
	{
// Store the main object
		$this->WPOnlineBackup = & $WPOnlineBackup;

		$this->rolling_size = false;
	}

	/*public*/ function Get_ZIP_Code()
	{
		return 0x00;
	}

	/*public*/ function Save()
	{
// Return the state
		$state = array(
			'rolling_size'	=> $this->rolling_size,
		);

// Convert hash_ctx into CRC field
		if ( $this->rolling_size !== false ) {

			$state['rolling_crc'] = $this->rolling_crc;

			if ( $this->rolling_hash_ctx !== false && $this->rolling_hash_len ) {
				$copy_ctx = hash_copy( $this->rolling_hash_ctx );
				$state['rolling_hash_ctx'] = hexdec( hash_final( $copy_ctx, false ) );
				$state['rolling_hash_len'] = $this->rolling_hash_len;
			} else {
				$state['rolling_hash_ctx'] = false;
			}

		}

		return $state;
	}

	/*public*/ function Load( $state, & $writer, $rotate )
	{
// Repopulate the saved state, and store the new endpoint writer
		$this->rolling_size = $state['rolling_size'];

		if ( $this->rolling_size !== false ) {

			$this->rolling_crc = $state['rolling_crc'];

			if ( $this->WPOnlineBackup->Get_Env( 'hash_copy_available' ) ) $this->rolling_hash_ctx = hash_init( 'crc32b' );
			else $this->rolling_hash_ctx = false;
			$this->rolling_hash_len = 0;
			if ( $state['rolling_hash_ctx'] !== false ) {
				if ( $this->rolling_crc ) $this->rolling_crc = WPOnlineBackup_Functions::Combine_CRC32( $this->crc, $state['rolling_hash_ctx'], $state['rolling_hash_len'] );
				else $this->rolling_crc = $state['rolling_hash_ctx'];
			}

		}

		$this->writer = & $writer;

		return true;
	}

	/*public*/ function Open( & $writer )
	{
		$this->writer = & $writer;

		return true;
	}

	/*public*/ function CleanUp()
	{
// Close the hash_ctx
		if ( $this->rolling_size !== false && $this->rolling_hash_ctx !== false ) $crc = hexdec( hash_final( $this->rolling_hash_ctx, false ) );
	}

	/*public*/ function Atomic( $data )
	{
		$size = $zlen = strlen( $data );
		$crc = crc32( $data );

		return compact( 'size', 'crc', 'zlen', 'data' );
	}

	/*public*/ function Start_Stream()
	{
// ASSERTION - We are not in the middle of a stream deflation with Start_Stream
// Initialise other parameters
		$this->rolling_size = 0;
		$this->rolling_crc = false;

		if ( $this->WPOnlineBackup->Get_Env( 'hash_copy_available' ) ) $this->rolling_hash_ctx = hash_init( 'crc32b' );
		else $this->rolling_hash_ctx = false;
		$this->rolling_hash_len = 0;

		return true;
	}

// Write as part of a rolling delflation process
	/*public*/ function Write_Stream( $data, $len = false, $no_write = false )
	{
// ASSERTION - We are currently in the middle of a stream deflation with Start_Stream
// Since we always write directly because we have no compression to do, if we don't want to write, return false to say we want to
		if ( $no_write ) return false;

// If len is false, use strlen(), otherwise truncate
		if ( $len === false ) $len = strlen( $data );
		else $data = substr( $data, 0, $len );

// Add to size
		$this->rolling_size += $len;

// Update crc and zlen
		if ( $this->rolling_hash_ctx !== false ) {
			hash_update( $this->rolling_hash_ctx, $data );
			$this->rolling_hash_len += $len;
		} else if ($this->rolling_crc !== false) $this->rolling_crc = WPOnlineBackup_Functions::Combine_CRC32( $this->rolling_crc, crc32( $data ), $len );
		else $this->rolling_crc = crc32( $data );

// Write the data
		if ( ( $ret = $this->writer->Write( $data, $len ) ) !== true ) return $ret;

		return true;
	}

	/*public*/ function End_Stream()
	{
// ASSERTION - We are currently in the middle of a stream deflation with Start_Stream
// Nothing really to do here
		return true;
	}

	/*public*/ function Commit_Stream()
	{
// ASSERTION - We have just finalised a stream with End_Stream() and are ready to commit it
		$size = $zlen = $this->rolling_size;

// Finalize crc if we used hash_init
		if ( $this->rolling_hash_ctx !== false ) $crc = hexdec( hash_final( $this->rolling_hash_ctx, false ) );
		else $crc = $this->rolling_crc;

		return compact( 'size', 'crc', 'zlen' );
	}

	/*public*/ function CleanUp_Stream()
	{
		$this->rolling_size = false;

		return true;
	}
}

?>
