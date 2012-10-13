<?php

/*
WPOnlineBackup_Compressor_Deflate - Compresses data using the DEFLATE algorithm and flushes to a writer
Also has Atomic function to perform one time compression of an entire string - quick for small files and bits of data
Get_ZIP_Code() will return the compression code which should be stored in a ZIP archive - 0x08 in this case
*/

/*
Contains derivations from ZipStream by Paul Duncan with minor improvements and additions

In general this allows us to write a ZIP file within any stream that we can fwrite to, and it means we can stream data
to the zip file as well, as opposed to having to write to a temporary file and then compress and then encrypt

Issue with ZipStream however is that for large file compression it loops and gzdeflates in blocks. This however does not
work as they are separate streams, and to join two streams together is very difficult due to incorrect alignment of bits.
For simplicity and 

----------------------------------------------------------------------------------------------------
Licence for ZipStream which we derived from
----------------------------------------------------------------------------------------------------

##########################################################################
# ZipStream - Streamed, dynamically generated zip archives.              #
# by Paul Duncan <pabs@pablotron.org>                                    #
#                                                                        #
# Copyright (C) 2007-2009 Paul Duncan <pabs@pablotron.org>               #
#                                                                        #
# Permission is hereby granted, free of charge, to any person obtaining  #
# a copy of this software and associated documentation files (the        #
# "Software"), to deal in the Software without restriction, including    #
# without limitation the rights to use, copy, modify, merge, publish,    #
# distribute, sublicense, and/or sell copies of the Software, and to     #
# permit persons to whom the Software is furnished to do so, subject to  #
# the following conditions:                                              #
#                                                                        #
# The above copyright notice and this permission notice shall be         #
# included in all copies or substantial portions of the of the Software. #
#                                                                        #
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,        #
# EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF     #
# MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. #
# IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR      #
# OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,  #
# ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR  #
# OTHER DEALINGS IN THE SOFTWARE.                                        #
##########################################################################

*/

class WPOnlineBackup_Compressor_Deflate
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $writer;

	/*private*/ var $rolling_buffer;
	/*private*/ var $rolling_len;
	/*private*/ var $rolling_size;
	/*private*/ var $rolling_crc;
	/*private*/ var $rolling_hash_ctx;
	/*private*/ var $rolling_hash_len;
	/*private*/ var $rolling_tempfile;
	/*private*/ var $rolling_gzipname;

	/*private*/ var $register_temps;

	/*public*/ function WPOnlineBackup_Compressor_Deflate( & $WPOnlineBackup )
	{
// Store the main object
		$this->WPOnlineBackup = & $WPOnlineBackup;

		$this->rolling_buffer = false;

		$this->register_temps = false;
	}

	/*public*/ function Get_ZIP_Code()
	{
		return 0x08;
	}

	/*public*/ function Register_Temps( $value )
	{
		$this->register_temps = $value;
	}

	/*public*/ function Save()
	{
// Return the state
		$state = array(
			'rolling_buffer'	=> $this->rolling_buffer,
		);

// Convert hash_ctx into CRC field
		if ( $this->rolling_buffer !== false ) {

			$state['rolling_len'] = $this->rolling_len;
			$state['rolling_size'] = $this->rolling_size;
			$state['rolling_crc'] = $this->rolling_crc;

			if ( $this->rolling_hash_ctx !== false && $this->rolling_hash_len != 0 ) {
				$copy_ctx = hash_copy( $this->rolling_hash_ctx );
				list( $state['rolling_hash_ctx'] ) = array_values( unpack( 'N', hash_final( $copy_ctx, true ) ) );
				$state['rolling_hash_len'] = $this->rolling_hash_len;
			} else {
				$state['rolling_hash_ctx'] = false;
			}

			$state['rolling_tempfile'] = $this->rolling_tempfile !== false ? $this->rolling_tempfile->Save() : false;

			$state['rolling_gzipname'] = $this->rolling_gzipname;

		}

		return $state;
	}

	/*public*/ function Load( $state, & $writer, $rotate )
	{
// First thing first, check we actually have compression available... If not, server configuration has changed
		if ( !$this->WPOnlineBackup->Get_Env( 'deflate_available' ) ) return __( 'Compression is no longer available on the server. The server configuration must have changed during backup. Please run the backup again to run without compression.' , 'wponlinebackup' );

// Repopulate the saved state, and store the new endpoint writer
		$this->rolling_buffer = $state['rolling_buffer'];

		if ( $this->rolling_buffer !== false ) {

			$this->rolling_len = $state['rolling_len'];
			$this->rolling_size = $state['rolling_size'];
			$this->rolling_crc = $state['rolling_crc'];

			if ( $state['rolling_tempfile'] !== false ) {
				if ( ( $ret = $writer->Branch( $this->rolling_tempfile ) ) !== true ) return $ret;
				$this->rolling_tempfile->Load( $state['rolling_tempfile'], $rotate );
			} else $this->rolling_tempfile = false;

			if ( $this->WPOnlineBackup->Get_Env( 'inc_hash_available' ) ) $this->rolling_hash_ctx = hash_init( 'crc32b' );
			else $this->rolling_hash_ctx = false;
			$this->rolling_hash_len = 0;
			if ( $state['rolling_hash_ctx'] !== false ) {
				if ( $this->rolling_crc ) $this->rolling_crc = WPOnlineBackup_Functions::Combine_CRC32( $this->crc, $state['rolling_hash_ctx'], $state['rolling_hash_len'] );
				else $this->rolling_crc = $state['rolling_hash_ctx'];
			}

			$this->rolling_gzipname = $state['rolling_gzipname'];

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
		if ( $this->rolling_buffer !== false ) {

// Close the hash_ctx
			if ( $this->rolling_hash_ctx !== false ) {
				hash_final( $this->rolling_hash_ctx );
				$this->rolling_hash_ctx = false;
			}

// Remove any buffer file
			if ( $this->rolling_tempfile !== false ) {
				$this->rolling_tempfile->Delete( true );
				$this->rolling_tempfile->CleanUp();
			}

// Remove any gzip temp file
			if ( $this->rolling_gzipname !== false ) @unlink( $this->rolling_gzipname );

		}
	}

	/*public*/ function Atomic( $data )
	{
		$size = strlen( $data );
		$crc = crc32( $data );
		$data = gzdeflate( $data );
		$zlen = strlen( $data );

		return compact( 'size', 'crc', 'zlen', 'data' );
	}

	/*public*/ function Start_Stream()
	{
// ASSERTION - We are not in the middle of a stream deflation with Start_Stream
// Initialise other parameters
		$this->rolling_buffer = '';
		$this->rolling_len = 0;
		$this->rolling_size = 0;
		$this->rolling_crc = false;
		$this->rolling_tempfile = false;

		if ( $this->WPOnlineBackup->Get_Env( 'inc_hash_available' ) ) $this->rolling_hash_ctx = hash_init( 'crc32b' );
		else $this->rolling_hash_ctx = false;
		$this->rolling_hash_len = 0;

		return true;
	}

	/*public*/ function Write_Stream( $data, $len = false, $no_write = false )
	{
// ASSERTION - We are currently in the middle of a stream deflation with Start_Stream
// If len is false, use strlen()
		if ( $len === false ) $len = strlen( $data );
		else $data = substr( $data, 0, $len );

// If told not to write, see if we fill the buffer, if we do, return FALSE so we can write the header and call again
		if ( $no_write ) {
			if ( $this->rolling_size + $len > $this->WPOnlineBackup->Get_Setting( 'file_buffer_size' ) ) return false;
		}

// Add to buffer
		$this->rolling_buffer .= $data;
		$this->rolling_len += $len;
		$this->rolling_size += $len;

		if ( $no_write ) return true;

// Is the buffer large?
		if ( $this->rolling_size > $this->WPOnlineBackup->Get_Setting( 'file_buffer_size' ) ) {

			$block_size = $this->rolling_len - ( $this->rolling_len % $this->WPOnlineBackup->Get_Setting( 'file_buffer_size' ) );

			$data = substr( $this->rolling_buffer, 0, $block_size );

// Update crc and zlen
			if ( $this->rolling_hash_ctx !== false ) {
				hash_update( $this->rolling_hash_ctx, $data );
				$this->rolling_hash_len += $block_size;
			} else if ($this->rolling_crc !== false) $this->rolling_crc = WPOnlineBackup_Functions::Combine_CRC32( $this->rolling_crc, crc32( $data ), $block_size );
			else $this->rolling_crc = crc32( $data );

// Open the tempfile if neccessary
			if ( $this->rolling_tempfile === false ) {

// Open the tempfile
				if ( ( $ret = $this->writer->Branch( $this->rolling_tempfile ) ) !== true ) return $ret;

				if ( $this->register_temps ) $this->WPOnlineBackup->bootstrap->Register_Temp( $this->rolling_tempfile->Get_File_Path( 'gzipbuffer' ) );

				if ( ( $ret = $this->rolling_tempfile->Open( 'gzipbuffer' ) ) !== true ) return $ret;

			}

// Write the data
			if ( ( $ret = $this->rolling_tempfile->Write( $data, $block_size ) ) !== true ) return $ret;

			$this->rolling_buffer = substr( $this->rolling_buffer, $block_size );
			$this->rolling_len -= $block_size;

		}

		return true;
	}

	/*private*/ function Partial_GZWrite( $written, $length, $size )
	{
		$e = OBFW_Exception();
		if ( $e ) $e = 'PHP last error: ' . $e;
		else $e = 'PHP has no record of an error.';
		return 'Attempt to compress to file ' . $this->rolling_gzipname . ' only partially succeeded. Only ' . $written . ' of ' . $length . ' bytes were written. (' . $size . ' bytes already written.)' . $e;
	}

	/*public*/ function End_Stream()
	{
		// ASSERTION - We are currently in the middle of a stream deflation with Start_Stream
		// Did we never hit the large file size? If not, just let us call Commit_Stream()
		if ( $this->rolling_size <= $this->WPOnlineBackup->Get_Setting( 'file_buffer_size' ) ) return true;

		// Do we have pending buffer data?
		if ( $this->rolling_len != 0 ) {

			// Update crc and zlen
			if ( $this->rolling_hash_ctx !== false ) {
				hash_update( $this->rolling_hash_ctx, $this->rolling_buffer );
				$this->rolling_hash_len += $this->rolling_len;
			} else if ($this->rolling_crc !== false) $this->rolling_crc = WPOnlineBackup_Functions::Combine_CRC32( $this->rolling_crc, crc32( $this->rolling_buffer ), $this->rolling_len );
			else $this->rolling_crc = crc32( $this->rolling_buffer );

			// Write the data
			if ( ( $ret = $this->rolling_tempfile->Write( $this->rolling_buffer, $this->rolling_len ) ) !== true ) return $ret;

		}

		$this->rolling_buffer = '';

		// Generate temporary filename
		if ( ( $this->rolling_gzipname = @tempnam( $this->WPOnlineBackup->Get_Setting( 'gzip_tmp_dir' ), 'obfw' ) ) === false ) return OBFW_Exception();

		if ( $this->register_temps ) $this->WPOnlineBackup->bootstrap->Register_Temp( $this->rolling_gzipname );

		// Open the tempfile
		if ( ( $tfh = @gzopen( $this->rolling_gzipname, 'wb' ) ) === false ) {
			$this->rolling_gzipname = false;
			return OBFW_Exception();
		}

		$offset = 0;
		$total = 0;

		// Read each block, and write data
		while ( true ) {

			// Read a block
			if ( !is_bool( $ret = $this->rolling_tempfile->Flush( $data, $this->WPOnlineBackup->Get_Setting( 'max_block_size' ) ) ) ) {
				@gzclose( $tfh );
				return $ret;
			}

			$len = strlen( $data );

			$todo_length = $len;

			// Compress into tempfile

			// Protect from partial writes (See WPOnlineBackup_Disk->Write() for more details)
			while ( true ) {

				if ( ( $written = @gzwrite( $tfh, $data, $todo_length ) ) === false ) {
					$e = OBFW_Exception();
					@gzclose( $tfh );
					return $e;
				}

				// If we wrote nothing, fail
				if ( $written == 0 ) {
					$e = $this->Partial_GZWrite( $len - $todo_length, $len, $total );
					@gzclose( $tfh );
					return $e;
				}

				$todo_length -= $written;

				if ( $todo_length == 0 )
					break;

				// Trim off what we wrote
				$data = substr( $data, $written );

			}

			$total += $len;

			// End of file? End loop
			if ( $ret === false ) break;

		}

		// Close the gzip file
		@gzclose( $tfh );

		return true;
	}

	/*public*/ function Commit_Stream()
	{
// ASSERTION - We have just finalised a stream with End_Stream() and are ready to commit it
		$size = $this->rolling_size;

// Did we never hit the large file size? If not, just store with normal deflation
		if ( $size <= $this->WPOnlineBackup->Get_Setting( 'file_buffer_size' ) ) {

// Calculate CRC
			$crc = crc32( $this->rolling_buffer );

// Compress the data
			$this->rolling_buffer = gzdeflate( $this->rolling_buffer );

// Update the compressed data size
			$zlen = strlen( $this->rolling_buffer );

			if ( ( $ret = $this->writer->Write( $this->rolling_buffer, $zlen ) ) !== true ) return $ret;

			$this->rolling_buffer = false;

// Already done everything neccessary, move out
			return compact( 'size', 'crc', 'zlen' );

		}

// Finalize crc if we used hash_init
		if ( $this->rolling_hash_ctx !== false ) {
			list( $inc_crc ) = array_values( unpack( 'N', hash_final( $this->rolling_hash_ctx, true ) ) );
			$this->rolling_hash_ctx = false;
			if ( $this->rolling_crc !== false ) $this->rolling_crc = WPOnlineBackup_Functions::Combine_CRC32( $this->rolling_crc, $inc_crc, $this->rolling_hash_len );
			else $this->rolling_crc = $inc_crc;
		}

		$crc = $this->rolling_crc;

// Close and delete the buffer file
		$this->rolling_tempfile->Delete( true );

		if ( $this->register_temps ) $this->WPOnlineBackup->bootstrap->Unregister_Temp( $this->rolling_tempfile->Get_File_Path() );

		$this->rolling_tempfile = false;

// Get the deflate stream size
		if ( ( $zlen = @filesize( $this->rolling_gzipname ) ) === false ) return OBFW_Exception();

// Take away header and trailer
		$zlen -= 10 + 8;

// Start to transfer the compressed data to our own file
		if ( ( $f = @fopen( $this->rolling_gzipname, 'rb' ) ) === false ) return OBFW_Exception();

// Skip the header
		if ( @fseek( $f, 10, SEEK_SET ) != 0 ) {
			$e = OBFW_Exception();
			@fclose( $f );
			return $e;
		}

// Start transferring
		$data = '';

		while ( !@feof( $f ) ) {

// Always get 8 extra bytes
			if ( ( $extract = @fread( $f, $this->WPOnlineBackup->Get_Setting( 'max_block_size' ) + 8 ) ) === false ) {
				$e = OBFW_Exception();
				@fclose( $f );
				return $e;
			}

			$data .= $extract;

// Write all but the last 8 bytes
			$to_write = strlen( $data ) - 8;

// Stop if we don't have anything to write
			if ( $to_write == 0 ) break;

// Write
			if ( ( $ret = $this->writer->Write( $data, $to_write ) ) !== true ) {
				@fclose( $f );
				return $ret;
			}

// Leave the last 8 on the buffer
			$data = substr( $data, -8 );

		}

// Clean up the tempfile
		@fclose( $f );

		return compact( 'size', 'crc', 'zlen' );
	}

	/*public*/ function CleanUp_Stream()
	{
		@unlink( $this->rolling_gzipname );

		if ( $this->register_temps ) $this->WPOnlineBackup->bootstrap->Unregister_Temp( $this->rolling_gzipname );

		$this->rolling_gzipname = false;

		$this->rolling_buffer = false;

		return true;
	}
}

?>
