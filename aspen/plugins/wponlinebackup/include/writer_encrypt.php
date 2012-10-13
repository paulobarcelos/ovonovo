<?php

/*
WPOnlineBackup_Writer_Encrypt - Encrypts data on-the-fly using the mcrypt extension
Can encrypt the data in various formats
We can pass this write to WPOnlineBackup_Archiver_ZIP to essentially write a OBFW encrypted zip file
	(we don't use native ZIP archive encryption... yet)
*/

class WPOnlineBackup_Writer_Encrypt
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $status;

	/*private*/ var $disk;
	/*private*/ var $buffer_disk;

	/*private*/ var $header_pos;

	/*private*/ var $cipher_spec;
	/*private*/ var $key;
	/*private*/ var $iv;
	/*private*/ var $cipher;

	/*private*/ var $totalsize;
	/*private*/ var $encsize;

	/*private*/ var $data;
	/*private*/ var $data_len;

	/*private*/ var $hash_ctx;
	/*private*/ var $hash_len;
	/*private*/ var $crc;

	/*private*/ var $last_cipher;

	/*private*/ var $volatile;
	/*private*/ var $volatile_len;
	/*private*/ var $volatile_ofs;

	/*private*/ var $real_blocksize;
	/*private*/ var $blocksize;

	/*private*/ var $register_temps;

	/*public*/ function WPOnlineBackup_Writer_Encrypt( & $WPOnlineBackup )
	{
		// Store the main object
		$this->WPOnlineBackup = & $WPOnlineBackup;

		// Check we have CRC32 stuff available
		require_once WPONLINEBACKUP_PATH . '/include/functions.php';

		$this->status = 0;
		$this->register_temps = false;
		$this->hash_ctx = false;
		$this->volatile = false;
		$this->buffer_disk = false;
	}

	/*public*/ function Register_Temps( $value )
	{
		$this->register_temps = $value;
	}

	/*public*/ function Save()
	{
		// ASSERTION - There is no volatile data

		// Convert hash_ctx into CRC field
		if ( $this->hash_ctx !== false && $this->hash_len != 0 ) {
			$copy_ctx = hash_copy( $this->hash_ctx );
			list( $hash_ctx ) = array_values( unpack( 'N', hash_final( $copy_ctx, true ) ) );
		} else {
			$hash_ctx = false;
		}

		if ( $this->status == 0 ) {

			// We haven't called Open() yet so just return the status, everything can be left defaults on Load()
			return array(
				'status'	=> $this->status,
			);

		}

		return array(
			'status'		=> $this->status,
			'cipher_spec'		=> $this->cipher_spec,
			'key'			=> $this->key,
			'iv'			=> $this->iv,
			'real_blocksize'	=> $this->real_blocksize,
			'blocksize'		=> $this->blocksize,
			'data'			=> $this->data,
			'data_len'		=> $this->data_len,
			'crc'			=> $this->crc,
			'hash_ctx'		=> $hash_ctx,
			'hash_len'		=> $this->hash_len,
			'totalsize'		=> $this->totalsize,
			'encsize'		=> $this->encsize,
			'last_cipher'		=> $this->last_cipher,
			'buffer_disk'		=> $this->buffer_disk === false ? false : $this->buffer_disk->Save(),
			'volatile'		=> $this->volatile,
			'volatile_len'		=> $this->volatile_len,
			'header_pos'		=> $this->header_pos,
		);
	}

	/*private*/ function Get_Cipher( $cipher_spec )
	{
		// Generate the cipher configuration
		switch ( $cipher_spec ) {

			case 'DES':
				$module = MCRYPT_DES;
				$module_str = 'MCRYPT_DES';
				$key_size = 8;
				break;

			default:
			case 'AES128':
				$module = MCRYPT_RIJNDAEL_128;
				$module_str = 'MCRYPT_RIJNDAEL_128';
				$key_size = 16;
				break;

			case 'AES192':
				$module = MCRYPT_RIJNDAEL_128;
				$module_str = 'MCRYPT_RIJNDAEL_128';
				$key_size = 24;
				break;

			case 'AES256':
				$module = MCRYPT_RIJNDAEL_128;
				$module_str = 'MCRYPT_RIJNDAEL_128';
				$key_size = 32;
				break;

		}

		return array( $module, $module_str, $key_size );
	}

	/*public*/ function Load( $state, & $disk, $rotation )
	{
		// Status
		$this->status = $state['status'];

		// If status is 0 we haven't Open() yet so leave everything as default
		if ( $this->status == 0 ) return;

		// First thing first, check we actually have encryption available... If not, server configuration has changed
		if ( !$this->WPOnlineBackup->Get_Env( 'encryption_available' )
			|| !array_key_exists( $state['cipher_spec'], $this->WPOnlineBackup->Get_Env( 'encryption_types' ) ) )
			return __( 'The selected encryption type is no longer available on the server. The server configuration must have changed during backup. Please change the encryption details and run the backup again. If this was an online backup you may need to contact your host about this as your encryption details cannot be changed.' , 'wponlinebackup' );

		// There isn't a state to restore, so just store the file and initialise the other variables
		$this->disk = & $disk;

		// Load buffer disk, and rotate it
		if ( $state['buffer_disk'] !== false ) {

			$this->buffer_disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );

			if ( ( $ret = $this->buffer_disk->Load( $state['buffer_disk'], $rotation ) ) !== true ) return $ret;

		} else $this->buffer_disk = false;

		$this->cipher_spec = $state['cipher_spec'];

		list ( $module, $module_str, $key_size ) = $this->Get_Cipher( $this->cipher_spec );

		$this->key = $state['key'];
		$this->iv = $state['iv'];
		$this->last_cipher = $state['last_cipher'];

		if ( $this->key != '' ) {

			// Attempt to open the cipher module
			if ( ( $this->cipher = @mcrypt_module_open( $module, '', MCRYPT_MODE_CBC, '' ) ) === false )
				return 'Failed to open encryption module. PHP: ' . OBFW_Exception();

			// Now initialise the cipher so we can start encrypting. Returns -2/-3 on errors, false on incorrect parameters
			if ( ( $ret = @mcrypt_generic_init( $this->cipher, $this->key, is_null( $this->last_cipher ) ? $this->iv : $this->last_cipher ) ) === false || $ret < 0 ) {
				$e = 'Failed to initialise encryption. PHP: ' . OBFW_Exception();
				@mcrypt_module_close( $this->cipher );
				return $e;
			}

		}

		$this->header_pos = $state['header_pos'];

		$this->real_blocksize = $state['real_blocksize'];
		$this->blocksize = $state['blocksize'];
		$this->data = $state['data'];
		$this->data_len = $state['data_len'];
		if ( $this->WPOnlineBackup->Get_Env( 'inc_hash_available' ) ) $this->hash_ctx = hash_init( 'crc32b' );
		else $this->hash_ctx = false;
		$this->hash_len = 0;
		$this->crc = $state['crc'];
		if ( $state['hash_ctx'] !== false ) {
			if ( $this->crc !== false ) $this->crc = WPOnlineBackup_Functions::Combine_CRC32( $this->crc, $state['hash_ctx'], $state['hash_len'] );
			else $this->crc = $state['hash_ctx'];
		}
		$this->totalsize = $state['totalsize'];
		$this->encsize = $state['encsize'];
		$this->volatile = $state['volatile'];
		$this->volatile_len = $state['volatile_len'];

		return true;
	}

	/*public*/ function Open( & $disk, $cipher_spec, $key, $extra = 0 )
	{
		// ASSERTION - The file is closed
		// First thing first, check we actually have encryption available... If not, server configuration has changed
		if ( !$this->WPOnlineBackup->Get_Env( 'encryption_available' )
			|| !array_key_exists( $cipher_spec, $this->WPOnlineBackup->Get_Env( 'encryption_types' ) ) )
			return __( 'The selected encryption type is no longer available on the server. The server configuration must have changed since encryption was configured. Please change the encryption details and run the backup again. If this was an online backup you may need to contact your host about this as your encryption details cannot be changed.' , 'wponlinebackup' );

		// Store the cipher specification and key
		$this->cipher_spec = $cipher_spec;
		$this->key = $key;

		// Store the file handle
		$this->disk = & $disk;

		// Attempt to open the cipher module
		list ( $module, $module_str, $key_size ) = $this->Get_Cipher( $this->cipher_spec );
		if ( ( $this->cipher = @mcrypt_module_open( $module, '', MCRYPT_MODE_CBC, '' ) ) === false )
			return 'Failed to open encryption module. PHP: ' . OBFW_Exception();

		// Get the IV size
		$iv_size = mcrypt_enc_get_iv_size( $this->cipher );

		// Randomly generate an IV - the IV will be stored in the file
		$this->iv = '';
		mt_srand( time() );
		for ( $i = 0; $i < $iv_size; $i++ ) $this->iv .= chr( rand( 0, 255 ) );

		// Generate the encryption key and password authentication value - allow $extra parameter to use a different section of the key
		$dk = WPOnlineBackup_Functions::PBKDF2( $this->key, $this->iv, 1148, $key_size * ( 2 + $extra ) + 2 );
		$this->key = substr( $dk, ( $extra ? $key_size * ( 1 + $extra ) + 2 : 0 ), $key_size );
		$pass_auth = substr( $dk, $key_size * 2, 2 );

		// Now initialise the cipher so we can start encrypting. Returns -2/-3 on errors, false on incorrect parameters
		if ( ( $ret = @mcrypt_generic_init( $this->cipher, $this->key, $this->iv ) ) === false || $ret < 0 ) {
			$e = 'Failed to initialise encryption. PHP: ' . OBFW_Exception();
			@mcrypt_module_close( $this->cipher );
			return $e;
		}

		$this->header_pos = $this->disk->Pos();

		// Write the file header
		// We used to encrypt the header but we don't any more so we can get the actual backup file size without needing the key
		// We'll set an unencrypted size of 0 and fill it in as we close
		$fields = array(
			array( 'a6',	'OBFWEN'		), // Always "OBFWEN"
			array( 'v',	3			), // OBFW_Backup_Writer_Encryption version (Currently 3 - 2 fixed broken PBKDF2 in PHP < 5.2, 3 make AES standardised)
			array( 'v',	0			), // Reserved
			array( 'C',	ord( $pass_auth[0] )	), // Password authentication value
			array( 'C',	ord( $pass_auth[1] )	), // Password authentication value
			array( 'V',	$iv_size		), // Length of IV
			// 16 bytes into header - this is where we start overwriting when we Close()
			array( 'V',	0			), // Total size of the unencrypted data
			array( 'V',	0			), // CRC32 of the unencrypted data
			array( 'V',	0			), // Reserved
		);

		$fields = WPOnlineBackup_Functions::Pack_Fields( $fields ) . $this->iv;

		if ( ( $ret = $this->disk->Write( $fields ) ) !== true ) {
			@mcrypt_generic_deinit( $this->cipher );
			@mcrypt_module_close( $this->cipher );
			return $ret;
		}

		// Grab the real block size and adjust the configured block size to ensure it is an exact divisor
		$this->real_blocksize = mcrypt_enc_get_block_size( $this->cipher );
		if ( ( $rem = $this->WPOnlineBackup->Get_Setting( 'encryption_block_size' ) % $this->real_blocksize ) != 0 ) $this->blocksize = $this->WPOnlineBackup->Get_Setting( 'encryption_block_size' ) + ( $this->real_blocksize - $rem );
		else $this->blocksize = $this->WPOnlineBackup->Get_Setting( 'encryption_block_size' );

		// Prepare the counters
		$this->data = '';
		$this->data_len = 0;
		if ( $this->WPOnlineBackup->Get_Env( 'inc_hash_available' ) ) $this->hash_ctx = hash_init( 'crc32b' );
		else $this->hash_ctx = false;
		$this->hash_len = 0;
		$this->crc = false;
		$this->last_cipher = null;
		$this->totalsize = 0;
		$this->encsize = strlen( $fields );
		$this->volatile = false;
		$this->volatile_len = 0;
		$this->buffer_disk = false;

		// Open() called so set status
		$this->status = 1;

		return true;
	}

	/*private*/ function CleanUp_Cipher()
	{
		// Cleanup
		@mcrypt_generic_deinit( $this->cipher );
		@mcrypt_module_close( $this->cipher );

		$this->iv = '';
		$this->key = '';
		$this->last_cipher = '';
	}

	/*public*/ function Close()
	{
		// ASSERTION - The file is open and does not contain volatile data

		// Write the last block if we still have data
	 	if ( $this->data_len != 0 ) {

			if ( ( $ret = $this->Last_Write() ) !== true ) return $ret;

		}

		// Close the hash_ctx
		if ( $this->hash_ctx !== false ) {
			list( $inc_crc ) = array_values( unpack( 'N', hash_final( $this->hash_ctx, true ) ) );
			if ( $this->crc !== false ) $this->crc = WPOnlineBackup_Functions::Combine_CRC32( $this->crc, $inc_crc, $this->hash_len );
			else $this->crc = $inc_crc;
			$this->hash_ctx = false;
		}

		$this->CleanUp_Cipher();

		// Build the replacement fields and overwrite the header
		$fields = array(
			array( 'V',	$this->totalsize	), // Total size of the unencrypted data
			array( 'V',	$this->crc		), // CRC32 of the unencrypted data
		);

		if ( ( $ret = $this->disk->Rewrite( $this->header_pos + 16, WPOnlineBackup_Functions::Pack_Fields( $fields ) ) ) !== true ) return $ret;

		// Now closed, so set status to 0
		$this->status = 0;

		return array(
			'size'	=> $this->encsize,
		);
	}

	/*public*/ function CleanUp()
	{
		// If status is 0 we haven't opened yet so there is nothing to cleanup
		if ( $this->status == 0 )
			return;

		$this->CleanUp_Cipher();

		// Close the hash_ctx
		if ( $this->hash_ctx !== false ) hash_final( $this->hash_ctx );

		if ( $this->volatile !== false ) {

			// Clear from buffer disk
			if ( $this->buffer_disk !== false ) $this->buffer_disk->Delete( true );

		}
	}

	/*public*/ function Write( $data, $len = null )
	{
// ASSERTION - The file is open
		if ( $this->volatile !== false ) {

// Volatile write is required - pass it through
			if ( !is_int( $ret = $this->Volatile_Write( $data, $len ) ) ) return $ret;

			return true;

		}

// If no length, use strlen()
		if ( is_null( $len ) ) $len = strlen( $data );

// Add the data
		$this->data .= substr( $data, 0, $len );
		$this->data_len += $len;

// Increment the decrypted file size and current decrypted file position
		$this->totalsize += $len;

// We encrypt data in distinct blocks - see if we have enough data for a block
		if ( $this->data_len >= $this->blocksize ) {

// Calculate how many multiples of the blocksize we can write
			$to_write = ( ( $this->data_len - ( $this->data_len % $this->blocksize ) ) / $this->blocksize ) * $this->blocksize;

			$write = substr( $this->data, 0, $to_write );
			$this->data = substr( $this->data, $to_write );
			$this->data_len -= $to_write;

			if ( $this->hash_ctx !== false ) {
				hash_update( $this->hash_ctx, $write );
				$this->hash_len += $to_write;
			} else if ( $this->crc !== false ) $this->crc = WPOnlineBackup_Functions::Combine_CRC32( $this->crc, crc32( $write ), $to_write );
			else $this->crc = crc32( $write );

			$write = mcrypt_generic( $this->cipher, $write );
			$to_write = strlen( $write );
			$this->last_cipher = substr( $write, $to_write - $this->real_blocksize );

			$this->encsize += $to_write;

// Write it
			if ( ( $ret = $this->disk->Write( $write, $to_write ) ) !== true ) {

// Failure means we call Close() - set data_len to 0 to ensure we don't try to write again when Close() is called
				$this->data = '';
				$this->data_len = 0;
				return $ret;

			}

		}

		return true;
	}

	/*public*/ function Start_Volatile()
	{
// ASSERTION - The file is open and not currently volatile
// Set the volatile data length and current size
		$this->volatile_len = 0;
		$this->volatile = $this->data_len;

		return true;
	}

	/*public*/ function Volatile_Write( $data, $len = null )
	{
// ASSERTION - The file is open and currently volatile
// If no length, use strlen()
		if ( is_null( $len ) ) $len = strlen( $data );

		$cur_pos = $this->volatile_len;

// Volatile data is written to the buffer normally, but once it fills, we start writing to a buffer disk
// During solidification we read the buffer disk, commit the data to the main disk, and remove the buffer disk
		if ( $this->buffer_disk !== false ) {

// Just write the data
			if ( ( $ret = $this->buffer_disk->Write( $data, $len ) ) !== true ) return $ret;

// Increase the length of the volatile data
			$this->volatile_len += $len;

// Increment the decrypted file size and current decrypted file position
			$this->totalsize += $len;

// Return the position of this volatile write
			return $cur_pos;

		}

// Increase the length of the volatile data
		$this->volatile_len += $len;

// Add the data
		$this->data .= substr( $data, 0, $len );
		$this->data_len += $len;

// Increment the decrypted file size
		$this->totalsize += $len;

// Have we filled the buffer?
		if ( $this->data_len > $this->blocksize ) {

// Open a new buffer disk, branching out from the existing file
			if ( ( $ret = $this->disk->Branch( $this->buffer_disk ) ) !== true ) return $ret;

			if ( ( $ret = $this->buffer_disk->Open( 'encbuffer' ) ) !== true ) return $ret;

			if ( $this->register_temps ) $this->WPOnlineBackup->bootstrap->Register_Temp( $this->buffer_disk->Get_File_Path() );

// Calculate length of overflow
			$len = $this->data_len - $this->blocksize;

// Start writing the buffer overflow
			if ( ( $ret = $this->buffer_disk->Write( substr( $this->data, $this->blocksize ), $len ) ) !== true ) return $ret . PHP_EOL .
				'Buffer: ' . strlen( substr( $this->data, $this->blocksize ) ) . PHP_EOL .
				'Length: ' . $len;

// Strip the overflow from the buffer
			$this->data = substr( $this->data, 0, $this->blocksize );
			$this->data_len = $this->blocksize;

		}

		return $cur_pos;
	}

	/*public*/ function Volatile_Rewrite( $pos, $data, $length = null )
	{
// ASSERTION - The file is open
// ASSERTION - The file has volatile data
// ASSERTION - Pos points to a position in the volatile data
// ASSERTION - Length will not go past the end of volatile data
		if ( is_null( $length ) ) $length = strlen( $data );

// Length of volatile data in the buffer
		$buffer_volatile = $this->blocksize - $this->volatile;

		if ( $pos < $buffer_volatile ) {

// Overwriting data starting in the buffer
			$write_len = min( $length, $buffer_volatile - $pos );

			$this->data = substr( $this->data, 0, $this->volatile + $pos ) . substr( $data, 0, $write_len ) . substr( $this->data, $this->volatile + $pos + $write_len, $this->blocksize - $this->volatile - $pos - $write_len );

			$length -= $write_len;
			if ( $length == 0 ) return true;

// Still more to rewrite in the actually buffer disk, so update the parameters here to do this
			$write = substr( $write, $write_len );
			$pos = 0;

		} else {

// Map the pos to the buffer_disk
			$pos -= $buffer_volatile;

		}

// Pass onto the buffer disk to perform the rewrite
		return $this->buffer_disk->Rewrite( $pos, $data, $length );
	}

	/*public*/ function Solidify()
	{
// ASSERTION - The file is open
// ASSERTION - There is volatile data
// Do we actually have any data to solidify?
		if ( $this->buffer_disk === false ) {

// We can simply let the buffer fill and then get encrypted as normal - Just reset the volatile pointer
			$this->volatile = false;

			return true;

		}

// Close the buffer disk
		$this->buffer_disk->Close();

// Flush the current buffer - use Last_Write
		if ( ( $ret = $this->Last_Write() ) !== true ) return $ret;

		$offset = 0;

// Now we are at the point where we need to go through all volatile data in the buffer file, and encrypt it
// We'll also need to pick up the tail and add it to the buffer
		if ( ( $rem = $this->WPOnlineBackup->Get_Setting( 'max_block_size' ) % $this->real_blocksize ) != 0 ) $blocksize = $this->WPOnlineBackup->Get_Setting( 'max_block_size' ) + ( $this->real_blocksize - $rem );
		else $blocksize = $this->WPOnlineBackup->Get_Setting( 'max_block_size' );

		do {

// Read a block of the data
			if ( !is_bool( $flush = $this->buffer_disk->Flush( $data, $blocksize ) ) ) return $flush;

			$to_solidify = strlen( $data );

// Do we need to leave some on the buffer?
			if ( $flush === false ) {

				$this->data_len = $to_solidify % $this->real_blocksize;
				$to_solidify -= $this->data_len;

				$this->data = substr( $data, $to_solidify, $this->data_len );

				$data = substr( $data, 0, $to_solidify );

			}

			if ( $this->hash_ctx !== false ) {
				hash_update( $this->hash_ctx, $data );
				$this->hash_len += $to_solidify;
			} else if ($this->crc !== false) $this->crc = WPOnlineBackup_Functions::Combine_CRC32( $this->crc, crc32( $data ), $to_solidify );
			else $this->crc = crc32( $data );

			$data = mcrypt_generic( $this->cipher, $data );
			$to_solidify = strlen( $data );
			$this->last_cipher = substr( $data, $to_solidify - $this->real_blocksize );

			$this->encsize += $to_solidify;

// Write it
			if ( ( $ret = $this->disk->Write( $data, $to_solidify ) ) !== true ) return $ret;

			if ( $flush === false ) break;

		} while ( true );

// Unset the volatile position
		$this->volatile = false;

		return true;
	}

	/*public*/ function Commit()
	{
// Clear from buffer disk
		if ( $this->buffer_disk !== false ) {

			$this->buffer_disk->Delete( true );

			if ( $this->register_temps ) $this->WPOnlineBackup->bootstrap->Unregister_Temp( $this->buffer_disk->Get_File_Path() );

			$this->buffer_disk = false;

		}

		return true;
	}

	/*private*/ function Last_Write()
	{
		if ( $this->hash_ctx !== false ) {
			hash_update( $this->hash_ctx, $this->data );
			$this->hash_len += $this->data_len;
		} else if ($this->crc !== false) $this->crc = WPOnlineBackup_Functions::Combine_CRC32( $this->crc, crc32( $this->data ), $this->data_len );
		else $this->crc = crc32( $this->data );

// Encrypt remaining buffer in place - leave the padding
		$this->data = mcrypt_generic( $this->cipher, $this->data );
		$len = strlen( $this->data );

		$this->encsize += $len;

// Write it
		if ( ( $ret = $this->disk->Write( $this->data, $len ) ) !== true ) return $ret;

// Clear the buffer
		$this->data = '';
		$this->data_len = 0;

		return true;
	}

	/*public*/ function Branch( & $branch )
	{
		return $this->disk->Branch( $branch );
	}
}

?>
