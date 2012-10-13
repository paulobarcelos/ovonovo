<?php

/*
WPOnlineBackup_Decrypt class
Decrypts an encrypted backup file
*/

class WPOnlineBackup_Decrypt
{
	/*private*/ var $WPOnlineBackup;

	/*public*/ function WPOnlineBackup_Decrypt( & $WPOnlineBackup )
	{
		global $wpdb;

		require_once WPONLINEBACKUP_PATH . '/include/functions.php';

		$this->WPOnlineBackup = & $WPOnlineBackup;
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

	/*private*/ function Get_Cipher_NonStandard( $cipher_spec )
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
				$key_size = 32;
				break;

			case 'AES192':
				$module = MCRYPT_RIJNDAEL_192;
				$module_str = 'MCRYPT_RIJNDAEL_192';
				$key_size = 32;
				break;

			case 'AES256':
				$module = MCRYPT_RIJNDAEL_256;
				$module_str = 'MCRYPT_RIJNDAEL_256';
				$key_size = 32;
				break;

		}

		return array( $module, $module_str, $key_size );
	}

	/*public*/ function Decrypt( $file, $file_name, $enc_type, $enc_key )
	{
		// Open the file
		if ( ( $f = @fopen( $file, 'rb' ) ) === false ) {
			return OBFW_Exception();
		}

		// Read the encryption header
		//V1, 28 bytes
		//	CHAR[6]		'OBFWEN'	// Signature, always "OBFWEN"
		//	WORD		$version	// Encryption version
		//	WORD		0		// Reserved
		//	CHAR[2]		$pass_auth	// Password authentication value
		//	DWORD		$iv_size	// Length of IV
		//	DWORD		$len		// Length of data
		//	DWORD		$crc		// CRC32 of unencrypted data
		//	DWORD		0		// Reserved (for HMAC-SHA256 or HMAC-SHA1 incremental algorithm result)

		// Validate what we read
		if ( ( $header = @fread( $f, 28 ) ) === false ) {
			$e = OBFW_Exception();
			@fclose( $f );
			return $e;
		}

		if ( strlen( $header ) != 28 ) {
			@fclose( $f );
			return 'Partially read ' . strlen( $header ) . ' of 28 bytes from encrypted data file for the encryption header.';
		}

		$header = unpack(
			'a6signature/' .
				'vversion/' .
				'vreserved1/' .
				'C2pass_auth/' .
				'Viv_size/' .
				'Vlen/' .
				'Vcrc/' .
				'Vreserved2',
			$header
		);

		if ( $header['signature'] != 'OBFWEN' ) {

			// Return to beginning of file
			if ( @fseek( $f, 0, SEEK_SET ) != 0 ) {
				$e = OBFW_Exception();
				@fclose( $f );
				return $e;
			}

			// Legacy decryption - we didn't have a header previously
			return $this->Legacy_Decrypt( $f, $file_name, $enc_type, $enc_key );

		}

		if ( $header['version'] < 1 || $header['version'] > 3 ) {
			@fclose( $f );
			return 'Unknown version ' . $header['version'] . ' of encrypted data file.';
		}

		// Attempt to open the cipher module
		if ( $header['version'] == 3 )
			list ( $module, $module_str, $key_size ) = $this->Get_Cipher( $enc_type );
		else
			list ( $module, $module_str, $key_size ) = $this->Get_Cipher_NonStandard( $enc_type );

		if ( ( $cipher = @mcrypt_module_open( $module, '', MCRYPT_MODE_CBC, '' ) ) === false ) {
			$e = 'Failed to open encryption module: ' . OBFW_Exception();
			@fclose( $f );
			return $e;
		}

		// Get the IV size
		$iv_size = mcrypt_enc_get_iv_size( $cipher );

		if ( $iv_size != $header['iv_size'] ) {
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return false;
		}

		// Grab the IV
		if ( ( $iv = @fread( $f, $header['iv_size'] ) ) === false ) {
			$e = OBFW_Exception();
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return $e;
		}

		if ( strlen( $iv ) != $header['iv_size'] ) {
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return 'Partially read ' . strlen( $iv ) . ' of ' . $header['iv_size'] . ' bytes from encrypted data file for IV.';
		}

		$extra = 0;

		// Generate the encryption key and password authentication value - allow $extra parameter to use a different section of the key
		$dk = WPOnlineBackup_Functions::PBKDF2( $enc_key, $iv, 1148, $key_size * ( 2 + $extra ) + 2 );
		$key = substr( $dk, ( $extra ? $key_size * ( 1 + $extra ) + 2 : 0 ), $key_size );
		$pass_auth = substr( $dk, $key_size * 2, 2 );

		$header['pass_auth'] = chr( $header['pass_auth1'] ) . chr( $header['pass_auth2'] );

		// While - so we can jump out
		while ( $pass_auth != $header['pass_auth'] ) {

			// Try the broken PBKDF2 call if this is a version 1 file
			if ( $header['version'] == 1 ) {
				$dk = WPOnlineBackup_Functions::PBKDF2_Broken( $enc_key, $iv, 1148, $key_size * ( 2 + $extra ) + 2 );
				$key = substr( $dk, ( $extra ? $key_size * ( 1 + $extra ) + 2 : 0 ), $key_size );
				$pass_auth = substr( $dk, $key_size * 2, 2 );
				if ( $pass_auth == $header['pass_auth'] ) break;
			}

			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return false;

		}

		// Now initialise the cipher so we can start decrypting. Returns -2/-3 on errors, false on incorrect parameters
		if ( ( $ret = @mcrypt_generic_init( $cipher, $key, $iv ) ) === false || $ret < 0 ) {
			$e = 'Failed to initialise encryption. PHP: ' . OBFW_Exception();
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return $e;
		}

		// Grab the real block size and adjust the configured block size to ensure it is an exact divisor
		$real_blocksize = mcrypt_enc_get_block_size( $cipher );
		if ( ( $rem = 8*1024*1024 % $real_blocksize ) != 0 ) $blocksize = 8*1024*1024 + ( $real_blocksize - $rem );
		else $blocksize = 8*1024*1024;

		if ( $this->WPOnlineBackup->Get_Env( 'inc_hash_available' ) ) $hash_ctx = hash_init( 'crc32b' );
		else $hash_ctx = false;
		$crc = false;

		$len = $header['len'];
		if ( ( $rem = $header['len'] % $real_blocksize ) != 0 ) $len += ( $trim = $real_blocksize - $rem );
		else $trim = 0;

		// Prepare a temporary file for decrypting the backup
		if ( ( $tmp = @fopen( $tmp_name = $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ) . '/' . $file_name . '.php', 'wb' ) ) === false ) {
			$e = OBFW_Exception();
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			@unlink( $tmp_name );
			return $e;
		}

		$rejection_header = <<<HEADER
<?php
/*Rejection header*/
__halt_compiler();
}
HEADER;

		$block = strlen( $rejection_header );

		if ( ( $written = @fwrite( $tmp, $rejection_header ) ) === false ) {
			$e = OBFW_Exception();
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			@fclose( $tmp );
			@unlink( $tmp_name );
			return $e;
		}

		if ( $written != $block ) {
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			@fclose( $tmp );
			@unlink( $tmp_name );
			return 'Partially wrote ' . $written . ' of ' . $block . ' bytes to decryption outfile.';
		}

		// Decrypt
		while ( true ) {

			// Try to give us more time - we may improve this to be background processed in segments in the future
			set_time_limit( 30 );

			$block = min( $blocksize, $len );

			if ( ( $data = @fread( $f, $block ) ) === false ) {
				$e = OBFW_Exception();
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				@fclose( $tmp );
				@unlink( $tmp_name );
				return $e;
			}

			if ( strlen( $data ) != $block ) {
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				@fclose( $tmp );
				@unlink( $tmp_name );
				return 'Partially read ' . strlen( $data ) . ' of ' . $block . ' bytes from encrypted data file for decryption.';
			}

			$data = mdecrypt_generic( $cipher, $data );

			if ( ( $len -= $block ) <= 0 ) {

				if ( $trim != 0 ) {
					$data = substr( $data, 0, $trim * -1 );
					$block = strlen( $data );
				}

			}

			if ( ( $written = @fwrite( $tmp, $data ) ) === false ) {
				$e = OBFW_Exception();
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				@fclose( $tmp );
				@unlink( $tmp_name );
				return $e;
			}

			if ( $written != $block ) {
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				@fclose( $tmp );
				@unlink( $tmp_name );
				return 'Partially wrote ' . $written . ' of ' . $block . ' bytes to decryption outfile.';
			}

			if ( $hash_ctx !== false ) hash_update( $hash_ctx, $data );
			else if ( $crc !== false ) $crc = WPOnlineBackup_Functions::Combine_CRC32( $crc, crc32( $data ), $block );
			else $crc = crc32( $data );

			if ( $len <= 0 ) break;

		}

		@mcrypt_generic_deinit( $cipher );
		@mcrypt_module_close( $cipher );
		@fclose( $f );
		@fclose( $tmp );

		if ( $hash_ctx !== false ) $crc = hexdec( hash_final( $hash_ctx, false ) );

		if ( $crc != $header['crc'] ) {
			@unlink( $tmp_name );
			return 'Decryption failed due to CRC mismatch. The file may be corrupt, or the encryption keys invalid.';
		}

		if ( ( $size = @filesize( $tmp_name ) ) !== false )
			header( 'Content-Length: ' . $size );

		if ( ( $tmp = @fopen( $tmp_name, 'r' ) ) === false ) {
			$e = OBFW_Exception();
			@unlink( $tmp_name );
			return $e;
		}

		if ( @fseek( $tmp, strlen( $rejection_header ), SEEK_SET ) != 0 ) {
			$e = OBFW_Exception();
			@unlink( $tmp_name );
			return $e;
		}

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename=' . $file_name );

		@fpassthru( $tmp );

		// Close and cleanup
		@fclose( $tmp );
		@unlink( $tmp_name );

		exit;
	}

	/*private*/ function Legacy_Decrypt( $f, $file_name, $type, $key )
	{
		// Decrypt a backup file generated by version 1 of the plugin
		list ( $module, $module_str, $key_size ) = $this->Get_Cipher( $enc_type );

// Expand the key to the required length
		if ( ( $key_len = strlen( $key ) ) < $key_size ) $key = substr( str_repeat( $key, ( ( $key_size - ( $key_size % $key_len ) ) / $key_len ) + ( $key_size % $key_len == 0 ? 0 : 1 ) ), 0, $key_size );
		else $key = substr( $key, 0, $key_size );

// Open the encryption module
		if ( ( $cipher = @mcrypt_module_open( $module, '', MCRYPT_MODE_CBC, '' ) ) === false ) {
			$e = 'Failed to open encryption module. PHP: ' . OBFW_Exception();
			@fclose( $f );
			return $e;
		}

// Generate IV based on key
		$iv_size = mcrypt_enc_get_iv_size( $cipher );
		$iv = sha1( $key );
		if ( ( $iv_len = strlen( $iv ) ) < $iv_size ) $iv = substr( str_repeat( $iv, ( ( $iv_size - ( $iv_size % $iv_len ) ) / $iv_len ) + ( $iv_size % $iv_len == 0 ? 0 : 1 ) ), 0, $iv_size );
		else $iv = substr( $iv, 0, $iv_size );

// Init encryption
		if ( @mcrypt_generic_init( $cipher, $key, $iv ) === false ) {
			$e = OBFW_Exception();
			@mcrypt_module_close( $cipher );
			return 'Failed to initialize encryption. PHP: ' . $e;
		}

// Read validation header size
		if ( ( $data = @fread( $f, 4 ) ) === false ) {
			$e = OBFW_Exception();
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return $e;
		}

		$unpack = unpack( 'Vdata_len', $data );
		$data_len = $unpack['data_len'];

// This size should really be no more than ENCRYPTION_SEGMENT_SIZE in V1 plugin which was 1048576. We shall ensure it is not above 8MB in case people adjusted it
		if ( $data_len > 1048576*8 ) {
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return false;
		}

		if ( ( $data = @fread( $f, $data_len ) ) === false ) {
			$e = OBFW_Exception();
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return $e;
		}

// Validate the data len by checking we read the right amount, assume keys incorrect if partial read
		if ( strlen( $data ) != $data_len ) {
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return false;
		}

// Decrypt the validation header
		$data = mdecrypt_generic( $cipher, $data );

		if ( strlen( $data ) >= 9 && substr( $data, 0, 9 ) === "\x01\x01ISVALID" ) {

// OK, start full decryption
			$totalsize = false;

		} else if ( strlen( $data ) >= 10 && substr( $data, 0, 4 ) === "OBFW" ) {

			$unpack = unpack( 'vversion', substr( $data, 4, 2 ) );
			$version = $unpack['version'];

// This was the slightly improved encryption - check the version
			if ( $version == 2 ) {

// Version 2 stored the full size of the encrypted data in the header so we could trim the encryption padding correctly
				$unpack = unpack( 'Vtotalsize', substr( $data, 6, 4 ) );
				$totalsize = $unpack['totalsize'];

			} else {
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				return false;
			}

		} else {
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return false;
		}

		if ( $totalsize !== false )
			$donesize = 0;

// Prepare a temporary file
		if ( ( $tmp_name = @tempnam( $this->WPOnlineBackup->Get_Setting( 'gzip_temp_dir' ), 'obfw' ) ) === false ) {
			$e = OBFW_Exception();
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			return $e;
		}

		if ( ( $tmp = @fopen( $tmp_name, 'w' ) ) === false ) {
			$e = OBFW_Exception();
			@mcrypt_generic_deinit( $cipher );
			@mcrypt_module_close( $cipher );
			@fclose( $f );
			@unlink( $tmp_name );
			return $e;
		}

// Loop and decrypt
		while ( !@feof( $f ) ) {

// Try to give us more time - we may improve this to be background processed in segments in the future
			set_time_limit( 30 );

// Grab a block size
			if ( ( $data = @fread( $f, 4 ) ) === false ) {
				$e = OBFW_Exception();
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
// We actually used to end when we couldn't read anymore. Only do this if not the improved method
				if ( $totalsize === false ) break;
				return $e;
			}

			$unpack = unpack( 'Vdata_len', $data );
			$data_len = $unpack['data_len'];

// This size should really be no more than ENCRYPTION_SEGMENT_SIZE in V1 plugin which was 1048576. We shall ensure it is not above 8MB in case people adjusted it
			if ( $data_len > 1048576*8 ) {
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				return false;
			}

			if ( ( $data = @fread( $f, $data_len ) ) === false) {
				$e = OBFW_Exception();
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				return $e;
			}

// Validate the data len by checking we read the right amount, assume keys incorrect if partial read
			if ( strlen( $data ) != $data_len ) {
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				return false;
			}

			$data = mdecrypt_generic( $cipher, $data );

// Check if we are done or not
			if ( $totalsize !== false ) {
				$donesize += $data_len;
				if ( $donesize > $totalsize ) {
					$data = substr( $data, 0, strlen( $data ) - ( $donesize - $totalsize ) );
					$donesize = true;
				}
			}

// Write data to the file
			if ( ( $written = @fwrite( $tmp, $data ) ) === false ) {
				$e = OBFW_Exception();
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				return $e;
			}

			if ( $written != $data_len ) {
				@mcrypt_generic_deinit( $cipher );
				@mcrypt_module_close( $cipher );
				@fclose( $f );
				return 'Partially wrote ' . $written . ' of ' . $data_len . ' bytes to decryption outfile.';
			}

// Are we done?
			if ( $totalsize !== false && $donesize === true ) break;

		}

		@mcrypt_generic_deinit( $cipher );
		@mcrypt_module_close( $cipher );
		@fclose( $f );

		if ( ( $size = @filesize( $tmp_name ) ) !== false )
			header( 'Content-Length: ' . $size );

		if ( ( $tmp = @fopen( $tmp_name, 'r' ) ) === false ) {
			$e = OBFW_Exception();
			@unlink( $tmp_name );
			return $e;
		}

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename=' . $file_name );

		@fflush( $tmp );

		@fclose( $tmp );

		return true;
	}
}

?>
