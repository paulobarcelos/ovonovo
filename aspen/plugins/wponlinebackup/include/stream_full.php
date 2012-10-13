<?php

/*
WPOnlineBackup_Stream_Full - Archives data to a zip file archive for full backup
Can compress data, or just store it
Has functions to add files from path, and also to stream data to it
A writer is given to write the archive to -
	which means we can place an archive anywhere - even output directly to the browser
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

class WPOnlineBackup_Stream_Full
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $config;

	/*private*/ var $status;

	/*private*/ var $compressor;
	/*private*/ var $compressor_name;
	/*private*/ var $writer;
	/*private*/ var $writer_name;
	/*private*/ var $disk;

	/*private*/ var $file_count;
	/*private*/ var $cdr_ofs;
	/*private*/ var $last_cdr;
	/*private*/ var $cdr;
	/*private*/ var $ofs;

	/*private*/ var $rolling_name;
	/*private*/ var $rolling_writing;

	/*private*/ var $encrypted;
	/*private*/ var $compressed;

	/*private*/ var $files;

	/*private*/ var $code;

	/*private*/ var $datasize_limit;
	/*private*/ var $filesize_limit;

	/*public*/ function WPOnlineBackup_Stream_Full( & $WPOnlineBackup )
	{
// Store the main object
		$this->WPOnlineBackup = & $WPOnlineBackup;

// Check we have CRC32 stuff available
		require_once WPONLINEBACKUP_PATH . '/include/functions.php';

// Disk object
		require_once WPONLINEBACKUP_PATH . '/include/disk.php';
	}

	/*public*/ function Save()
	{
// Return the state
		$state = array(
			'status'		=> $this->status,
			'config'		=> $this->config,
			'encrypted'		=> $this->encrypted,
			'compressed'		=> $this->compressed,
			'files'			=> $this->files,
			'disk'			=> $this->disk->Save(),
			'datasize_limit'	=> $this->datasize_limit,
			'filesize_limit'	=> $this->filesize_limit,
		);

		if ( $this->status == 0 ) {

			$state['compressor']		= $this->compressor->Save();
			$state['compressor_name']	= $this->compressor_name;
			$state['writer']		= $this->writer !== false ? $this->writer->Save() : false;

			if ( $this->writer !== false ) {
				$state['writer_name']	= $this->writer_name;
			}

			$state['file_count']		= $this->file_count;
			$state['cdr_ofs']		= $this->cdr_ofs;
			$state['ofs']			= $this->ofs;
			$state['cdr']			= $this->cdr === false ? false : $this->cdr->Save();
			$state['last_cdr']		= $this->last_cdr;

			$state['rolling_name']		= $this->rolling_name;
			$state['rolling_writing']	= $this->rolling_writing;

		}

		return $state;
	}

	/*public*/ function Load( $state, $rotation )
	{
// Store the config
		$this->config = $state['config'];

		$this->status = $state['status'];

		$this->encrypted = $state['encrypted'];
		$this->compressed = $state['compressed'];

		$this->files = $state['files'];

		$this->datasize_limit = $state['datasize_limit'];
		$this->filesize_limit = $state['filesize_limit'];

// Reopen the file
		$this->disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );

		if ( ( $ret = $this->disk->Load( $state['disk'], $rotation ) ) !== true ) return $ret;

		if ( $this->status == 0 ) {

			if ( $state['writer'] === false ) {

				$this->writer = false;

			} else {

				$this->writer_name = $state['writer_name'];
				$this->compressor_name = $state['compressor_name'];

// Load the writer
				require_once WPONLINEBACKUP_PATH . '/include/' . strtolower( $this->writer_name ) . '.php';
				$name = 'WPOnlineBackup_' . $this->writer_name;

				$this->writer = new $name( $this->WPOnlineBackup );

				if ( ( $ret = $this->writer->Load( $state['writer'], $this->disk, $rotation ) ) !== true ) {
					$this->disk->CleanUp();
					return $ret;
				}

			}

// Load the compressor
			require_once WPONLINEBACKUP_PATH . '/include/' . strtolower( $this->compressor_name ) . '.php';
			$name = 'WPOnlineBackup_' . $this->compressor_name;

			$this->compressor = new $name( $this->WPOnlineBackup );

			if ( ( $ret = $this->compressor->Load( $state['compressor'], $this->writer, $rotation ) ) !== true ) {
				$this->writer->CleanUp();
				$this->disk->CleanUp();
				return $ret;
			}

			$this->code = $this->compressor->Get_ZIP_Code();

// Repopulate the saved state
			$this->file_count = $state['file_count'];
			$this->cdr_ofs = $state['cdr_ofs'];
			$this->ofs = $state['ofs'];
			if ( $state['cdr'] !== false ) {
				if ( ( $ret = $this->writer->Branch( $this->cdr ) ) !== true || ( $ret = $this->cdr->Load( $state['cdr'], $rotation ) ) !== true ) {
					$this->compressor->CleanUp();
					$this->writer->CleanUp();
					$this->disk->CleanUp();
					return $ret;
				}
			} else {
				$this->cdr = false;
			}
			$this->last_cdr = $state['last_cdr'];

			$this->rolling_name = $state['rolling_name'];
			$this->rolling_writing = $state['rolling_writing'];

		}

		return true;
	}

	/*public*/ function Open( $config, $title, $description )
	{
		// ASSERTION - The file is closed
		$this->config = $config;

		$this->config['designated_ext'] = array();

		// Prepare the writer
		switch ( $this->config[ 'encryption' ] ) {

			// We may implement ZIP AE-1 and AE-2 encryption in the future - but this doesn't encrypt metadata (filenames etc.)
			case 'DES':
			case 'AES128':
			case 'AES192':
			case 'AES256':
				require_once WPONLINEBACKUP_PATH . '/include/writer_encrypt.php';

				$this->writer_name = 'Writer_Encrypt';
				$this->writer = new WPOnlineBackup_Writer_Encrypt( $this->WPOnlineBackup );

				$this->config['designated_ext'][] = '.enc';

				$this->encrypted = true;
				break;

			// No encryption
			default:
				require_once WPONLINEBACKUP_PATH . '/include/writer_direct.php';

				$this->writer_name = 'Writer_Direct';
				$this->writer = new WPOnlineBackup_Writer_Direct( $this->WPOnlineBackup );

				$this->encrypted = false;
				break;

		}

		// Prepare the compressor
		switch ( $this->config['compression'] ) {

			// We only know DEFLATE at the moment
			case 'DEFLATE':
				require_once WPONLINEBACKUP_PATH . '/include/compressor_deflate.php';

				$this->compressor_name = 'Compressor_Deflate';
				$this->compressor = new WPOnlineBackup_Compressor_Deflate( $this->WPOnlineBackup );

				$this->compressed = true;
				break;

			// No compression
			default:
				require_once WPONLINEBACKUP_PATH . '/include/compressor_none.php';

				$this->compressor_name = 'Compressor_None';
				$this->compressor = new WPOnlineBackup_Compressor_None( $this->WPOnlineBackup );

				$this->compressed = false;
				break;

		}

		$this->code = $this->compressor->Get_ZIP_Code();

		$this->config['designated_ext'][] = '.zip';

		$this->config['designated_ext'] = implode( array_reverse( $this->config['designated_ext'] ) );

		$this->disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );

		$this->disk->Initialise( $this->config['designated_path'] );

		if ( ( $ret = $this->disk->Open( 'backup' . $this->config['designated_ext'] ) ) !== true ) return $ret;

		// Pass the file handle to the writer, and open the writer
		if ( ( $ret = $this->writer->Open( $this->disk, $this->config['encryption'], $this->config['encryption_key'] ) ) !== true ) {
			$this->disk->CleanUp();
			return $ret;
		}

		// Pass the writer to the compressor, and open it
		if ( ( $ret = $this->compressor->Open( $this->writer ) ) !== true ) {
			$this->writer->CleanUp();
			$this->disk->CleanUp();
			return $ret;
		}

		$this->status = 0;

		$this->files = 0;

		// Initialize parameters we need for ZIP archiving
		$this->file_count = 0;
		$this->cdr_ofs = 0;
		$this->ofs = 0;
		$this->cdr = false;
		$this->last_cdr = false;

		// Default to no limits
		$this->datasize_limit = null;
		$this->filesize_limit = null;

		// ZIP does not require any header writing so leave here
		return true;
	}

	/*public*/ function Flush()
	{
		// ASSERTION - The file is open
		// Write the CDR
		if ( ( $ret = $this->Add_CDR() ) !== true ) return $ret;

		return true;
	}

	/*public*/ function Close()
	{
		// Close the writer
		if ( !is_array( $ret = $this->writer->Close() ) ) return $ret;

		$this->writer = false;

		// Close the disk
		if ( ( $ret = $this->disk->Close() ) !== true ) return $ret;

		if ( $this->cdr !== false ) {
			$this->cdr->Delete( true );
			$this->cdr = false;
		}

		$this->status = 1;

		return true;
	}

	/*public*/ function CleanUp()
	{
		$this->compressor->CleanUp();
		if ( $this->writer !== false ) $this->writer->CleanUp();

		$this->disk->CleanUp();
		if ( $this->cdr !== false ) $this->cdr->CleanUp();
	}

	/*public*/ function Start_Reconstruct()
	{
		// ASSERTION - Status is 1 - Close() has been called
		return $this->disk->Start_Reconstruct();
	}

	/*public*/ function Do_Reconstruct()
	{
		// ASSERTION - Status is 1 - Close() has been called
		// ASSERTION - Start_Reconstruct has been called
		return $this->disk->Do_Reconstruct();
	}

	/*public*/ function End_Reconstruct()
	{
		// ASSERTION - Status is 1 - Close() has been called
		// ASSERTION - Start_Reconstruct has been called and Do_Reconstruct has returned success
		return $this->disk->End_Reconstruct();
	}

	/*public*/ function Is_Encrypted()
	{
		return $this->encrypted;
	}

	/*public*/ function Is_Compressed()
	{
		return $this->compressed;
	}

	/*public*/ function Files()
	{
		return $this->files;
	}

	/*public*/ function Impose_DataSize_Limit( $size, $message )
	{
		$this->datasize_limit = array( $size, $message );
	}

	/*public*/ function Impose_FileSize_Limit( $size, $message )
	{
		$this->filesize_limit = array( $size, $message );
	}

	/*public*/ function Add_Folder_From_Path( $bin, $folder, $path, & $success, $status )
	{
		$success = true;

		return true;
	}

	// Code derived from:
	// ZipStream - Streamed, dynamically generated zip archives.
	// by Paul Duncan <pabs@pablotron.org>
	/*public*/ function Add_File_From_Path( $bin, $file, $path, & $size, $status )
	{
		// ASSERTION - The file is open and we are not in the middle of a rolling deflation with Open_File

		// Grab the filesize so we can work out how to process it
		if ( is_null( $size ) && ( $size = @filesize( $path ) ) === false ) {

			$size = OBFW_Exception();
			return true;

		}

		// No mod time specified? Find it now
		if ( !array_key_exists( 'mod_time', $status ) ) {

			if ( ( $status['mod_time'] = @filemtime( $path ) ) === false ) {

				$size = OBFW_Exception();
				return true;

			}

		}

		// Compress in one go treating as a string, or piece by piece?
		if ( $size > $this->WPOnlineBackup->Get_Setting( 'max_block_size' ) ) {

			if ( ( $ret = $this->Add_Large_File( $bin, $file, $path, $size, $status ) ) !== true ) return $ret;

			return true;

		} else {

			if ( ( $data = @file_get_contents( $path ) ) === false ) {

				$size = OBFW_Exception();
				return true;

			}

			$size = strlen( $data );

			return $this->Add_File_From_String( $bin, $file, $size, $data, $status );

		}
	}

	/*public*/ function Add_File_From_String( $bin, $file, & $size, $data, $status )
	{
		// Calculate header attributes and compress data
		if ( !is_array( $result = $this->compressor->Atomic( $data ) ) ) return $result;

		$size = array(
			'file_size'	=> $result['size'],
			'stored_size'	=> $result['zlen'],
		);

		// Write file header
		if ( ( $ret = $this->Add_File_Header( $bin, $file, $status, $this->code, $result['crc'], $result['zlen'], $result['size'] ) ) !== true ) return $ret;

		// Write data
		if ( ( $ret = $this->writer->Write( $result['data'] ) ) !== true ) return $ret;

		$this->files++;

		return true;
	}

	/*public*/ function Start_Stream( $bin, $name, & $size, $status )
	{
		// ASSERTION - The file is open and we are not in the middle of a stream started with Start_Stream
		// Store file name
		$this->rolling_name = array( $bin, $name, & $size, $status );
		$this->rolling_writing = false;

		// Start the compressor
		if ( ( $ret = $this->compressor->Start_Stream() ) !== true ) return $ret;

		return true;
	}

	/*public*/ function Write_Stream( $data, $len = false )
	{
		// ASSERTION - The file is open and we are currently in the middle of a stream started with Start_Stream
		// If len is false, use strlen()
		if ( $len === false ) $len = strlen( $data );

		if ( !$this->rolling_writing ) {

			// Call compressor but tell not to write
			if ( !is_bool( $ret = $this->compressor->Write_Stream( $data, $len, true ) ) ) return $ret;

			if ( $ret === false ) {

				// Compressor wants to write, write file header stub volatile and pass through to the normal write below
				if ( ( $ret = $this->Add_File_Header( $this->rolling_name[0], $this->rolling_name[1], $this->rolling_name[2], $this->code, 0, 0, 0, true ) ) !== true ) return $ret;

				$this->rolling_writing = true;

			} else {

				// Doesn't want to write, just return
				return true;

			}

		}

		// Call compressor and allow it to write normally
		if ( ( $ret = $this->compressor->Write_Stream( $data, $len ) ) !== true ) return $ret;

		return true;
	}

	/*public*/ function End_Stream()
	{
		// ASSERTION - The file is open and we are currently in the middle of a stream started with Start_Stream()
		// If we never started writing, the following will perform all in buffer
		if ( ( $ret = $this->compressor->End_Stream() ) !== true ) return $ret;

		return true;
	}

	/*public*/ function Commit_Stream()
	{
		if ( !$this->rolling_writing ) {

			// Not been writing so add the file header now - when we commit it will add the data
			if ( ( $ret = $this->Add_File_Header( $this->rolling_name[0], $this->rolling_name[1], $this->rolling_name[3], $this->code, $result['crc'], $result['zlen'], $result['size'] ) ) !== true ) return $ret;

		}

		// If we never started writing, the following will perform all in buffer and write it out
		if ( !is_array( $result = $this->compressor->Commit_Stream() ) ) return $result;

		$this->rolling_name[2] = array(
			'file_size'	=> $result['size'],
			'stored_size'	=> $result['zlen'],
		);

		if ( $this->rolling_writing ) {

			// Been writing so update the file header and solidify
			if ( ( $ret = $this->Update_File_Header( $result['crc'], $result['zlen'], $result['size'] ) ) !== true ) return $ret;

			if ( ( $ret = $this->writer->Solidify() ) !== true ) return $ret;

		}

		return true;
	}

	/*public*/ function CleanUp_Stream()
	{
		// Cleanup the compressor
		$this->compressor->CleanUp_Stream();

		// If we've been writing, commit the writer
		if ( $this->rolling_writing && ( $ret = $this->writer->Commit() ) !== true ) return $ret;

		$this->rolling_name = false;

		$this->files++;

		return true;
	}

	/*private*/ function Add_Large_File( $bin, $file, $path, & $size, $status )
	{
		// Open file
		if ( !( $fh = @fopen( $path, 'rb' ) ) ) {
			$size = OBFW_Exception();
			return true;
		}

		// Force compressor and writer to register temporary files
		$this->compressor->Register_Temps( true );
		$this->writer->Register_Temps( true );

		$size = 0;
		$zlen = 0;

		// Start the stream
		if ( ( $ret = $this->Start_Stream( $bin, $file, $size, $status ) ) !== true ) {
			@fclose( $fh );
			return $ret;
		}

		$writing = false;

		// Read each block, update crc and zlen and write data
		while ( true ) {

		// Read a block
			if ( ( $data = @fread( $fh, $this->WPOnlineBackup->Get_Setting( 'max_block_size' ) ) ) === false ) {

				$size = OBFW_Exception();

				@fclose( $fh );

				// Restore compressor and writer
				$this->writer->Register_Temps( false );
				$this->compressor->Register_Temps( false );

				return true;

			}

			// Get length of data read
			$len = strlen( $data );

			// Update size
			$size += $len;

			// Write to the stream
			if ( ( $ret = $this->Write_Stream( $data, $len ) ) !== true ) {
				@fclose( $fh );
				return $ret;
			}

			// End of file? End loop
			if ( @feof( $fh ) ) break;

		}

		// Close the file
		@fclose( $fh );

		// End the stream
		if ( ( $ret = $this->End_Stream() ) !== true ) return $ret;

		// Commit the stream
		if ( ( $ret = $this->Commit_Stream() ) !== true ) return $ret;

		// Clean up the stream
		if ( ( $ret = $this->CleanUp_Stream() ) !== true ) return $ret;

		// Restore compressor and writer
		$this->writer->Register_Temps( false );
		$this->compressor->Register_Temps( false );

		return true;
	}

	/*private*/ function Add_File_Header( $bin, $file, $status, $meth, $crc, $zlen, $len, $volatile = false )
	{
		// Strip leading slashes from file name (fixes bug in windows archive viewer)
		$file = preg_replace( '/^[\\/\\\\]+/', '', $file );

		if ( $bin == WPONLINEBACKUP_BIN_DATABASE )
			$file = _x( 'Database', 'Full backup folder name' , 'wponlinebackup' ) . '/' . $file;
		else if ( $bin == WPONLINEBACKUP_BIN_FILESYSTEM )
			$file = _x( 'FileSystem', 'Full backup folder name' , 'wponlinebackup' ) . '/' . $file;
		else
			$file = $bin . '/' . $file;

		// Calculate name length
		$nlen = strlen( $file );

		// Create dos timestamp
		$dts = WPOnlineBackup_Functions::DOS_Time( $time = time() );

		// Build file header
		$fields = array(	// (from V.A of APPNOTE.TXT)
			array( 'V',	0x04034b50	),	// local file header signature
			array( 'C',	20		),	// version needed to extract (lower byte, appnote version)
			array( 'C',	0		),	// version needed to extract (upper byte, compatibility code)
			array( 'v',	0x00		),	// general purpose bit flag
			array( 'v',	$meth		),	// compresion method (deflate or store)
			array( 'V',	$dts		),	// dos timestamp
			// 14 bytes in - this is where Update_File_Header would update
			array( 'V',	$crc		),	// crc32 of data
			array( 'V',	$zlen		),	// compressed data length
			array( 'V',	$len		),	// uncompressed data length
			array( 'v',	$nlen		),	// filename length
			array( 'v',	0		),	// extra data len
		);

		// Pack fields and calculate "total" length
		$fields = WPOnlineBackup_Functions::Pack_Fields( $fields ) . $file;
		$cdr_len = strlen( $fields ) + $zlen;

		// Write the header and filename
		// Is this a volatile header?
		if ( $volatile ) {

			if ( ( $ret = $this->writer->Start_Volatile() ) !== true ) return $ret;

			if ( !is_int( $pos = $this->writer->Volatile_Write( $fields ) ) ) return $pos;

		} else {

			$pos = false;

			if ( ( $ret = $this->writer->Write( $fields ) ) !== true ) return $ret;

		}

		// Add to central directory record and increment offset
		return $this->Add_To_CDR( compact( 'pos', 'file', 'nlen', 'meth', 'dts', 'crc', 'zlen', 'len', 'time' ), $cdr_len );
	}

	/*private*/ function Update_File_Header( $crc, $zlen, $len )
	{
		// Fetch the actual offset from the CDR
		$pos = $this->last_cdr['pos'];

		// Build new values
		$fields = array(	// (from V.A of APPNOTE.TXT)
			array( 'V',	$crc		),	// crc32 of data
			array( 'V',	$zlen		),	// compressed data length
			array( 'V',	$len		),	// uncompressed data length
		);

		// Fill in the blanks
		if ( ( $ret = $this->writer->Volatile_Rewrite( $pos + 14, WPOnlineBackup_Functions::Pack_Fields( $fields ) ) ) !== true ) return $ret;

		// Update the internal offset pointer
		$this->ofs -= $this->last_cdr['zlen'];
		$this->ofs += $zlen;

		// Update the CDR
		$this->last_cdr['crc'] = $crc;
		$this->last_cdr['zlen'] = $zlen;
		$this->last_cdr['len'] = $len;

		return true;
	}

	/*private*/ function Add_To_CDR( $record, $rec_len )
	{
		// Store offset of this entry
		$record['ofs'] = $this->ofs;

		$this->file_count++;

		// If we have a CDR already stored, write it to the CDR buffer, creating one if necessary
		if ( $this->last_cdr !== false ) {

			if ( $this->cdr === false ) {

				if ( ( $ret = $this->writer->Branch( $this->cdr ) ) !== true ) return $ret;

				if ( ( $ret = $this->cdr->Open( 'cdrbuffer' ) ) !== true ) return $ret;

			}

			if ( ( $ret = $this->Add_CDR_File( $this->cdr, $this->last_cdr ) ) !== true ) return $ret;

		}

		// Store this CDR - if we only end up with a single CDR, we don't bother creating the CDR buffer then
		$this->last_cdr = $record;

		// Increase offset to be used for next entry
		$this->ofs += $rec_len;
		return true;
	}

	/*private*/ function Add_CDR_File( & $file, $args )
	{
		// Get dos timestamp
		$dts = WPOnlineBackup_Functions::DOS_Time( $args['time'] );

		$fields = array(	// (from V,F of APPNOTE.TXT)
			array( 'V',	0x02014b50	),	// central file header signature
			array( 'C',	20		),	// version made by (lower byte, appnote version)
			array( 'C',	0		),	// version made by (upper byte, compatibility code)
			array( 'v',	20		),	// version needed to extract
			array( 'v',	0x00		),	// general purpose bit flag
			array( 'v',	$args['meth']	),	// compresion method (deflate or store)
			array( 'V',	$args['dts']	),	// dos timestamp
			array( 'V',	$args['crc']	),	// crc32 of data
			array( 'V',	$args['zlen']	),	// compressed data length
			array( 'V',	$args['len']	),	// uncompressed data length
			array( 'v',	$args['nlen']	),	// filename length
			array( 'v',	0		),	// extra data len
			array( 'v',	0		),	// file comment length
			array( 'v',	0		),	// disk number start
			array( 'v',	0		),	// internal file attributes
			array( 'V',	32		),	// external file attributes
			array( 'V',	$args['ofs']	),	// relative offset of local header
		);

		$fields = WPOnlineBackup_Functions::Pack_Fields( $fields ) . $args['file'];

		// Write the fields
		if ( ( $ret = $file->Write( $fields ) ) !== true ) return $ret;

		// Increment cdr offset
		$this->cdr_ofs += strlen( $fields );

		return true;
	}

	/*private*/ function Add_CDR_EOF()
	{
		$cdr_len = $this->cdr_ofs;
		$cdr_ofs = $this->ofs;

		// Prepare comment
		$comment = 'Backup file generated by Online Backup for WordPress ' . WPONLINEBACKUP_VERSION . PHP_EOL;

		$fields = array(	// (from V,F of APPNOTE.TXT)
			array( 'V',	0x06054b50		),	// end of central file header signature
			array( 'v',	0x00			),	// this disk number
			array( 'v',	0x00			),	// number of disk with cdr
			array( 'v',	$this->file_count	),	// number of entries in the cdr on this disk
			array( 'v',	$this->file_count	),	// number of entries in the cdr
			array( 'V',	$cdr_len		),	// cdr size
			array( 'V',	$cdr_ofs		),	// cdr ofs
			array( 'v',	strlen( $comment )	),	// zip file comment length
		);

		// Write the fields
		if ( ( $ret = $this->writer->Write( WPOnlineBackup_Functions::Pack_Fields( $fields ) . $comment ) ) !== true ) return $ret;

		return true;
	}

	/*private*/ function Add_CDR()
	{
		if ( $this->cdr !== false ) {

			// Close CDR file
			$this->cdr->Close();

			do {

				// Flush the CDR file to the end of the disk, removing CDR data as we go along
				if ( !is_bool( $flush = $this->cdr->Flush( $buffer, $this->WPOnlineBackup->Get_Setting( 'max_block_size' ) ) ) ) return $flush;

				if ( ( $ret = $this->writer->Write( $buffer ) ) !== true ) return $ret;

				if ( $flush === false ) break;

			} while ( true );

		}

		// Add the final CDR record that was not commited to the CDR file
		if ( $this->last_cdr !== false ) {
			if ( ( $ret = $this->Add_CDR_File( $this->writer, $this->last_cdr ) ) !== true ) return $ret;
		}

		// Commit the EOF CDR record
		if ( ( $ret = $this->Add_CDR_EOF() ) !== true ) return $ret;

		return true;
	}
}

?>
