<?php

/*
WPOnlineBackup_Stream_Delta - Archives data to a special file for delta backup
Can compress data, or just store it
Has functions to add files from path, and also to stream data to it
A writer is given to write the archive to -
	which means we can place an archive anywhere - even output directly to the browser
*/

class WPOnlineBackup_Stream_Delta
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $config;

	/*private*/ var $status;

	/*private*/ var $compressor;
	/*private*/ var $compressor_name;
	/*private*/ var $writer;
	/*private*/ var $writer_name;
	/*private*/ var $data_disk;
	/*private*/ var $indx_disk;

	/*private*/ var $rolling_name;
	/*private*/ var $rolling_writing;
	/*private*/ var $rolling_offset;

	/*private*/ var $encrypted;
	/*private*/ var $compressed;

	/*private*/ var $files;

	/*private*/ var $code;

	/*private*/ var $reconstruct;

	/*private*/ var $datasize_limit;
	/*private*/ var $filesize_limit;

	/*public*/ function WPOnlineBackup_Stream_Delta( & $WPOnlineBackup )
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
			'data_disk'		=> $this->data_disk->Save(),
			'indx_disk'		=> $this->indx_disk->Save(),
			'datasize_limit'	=> $this->datasize_limit,
			'filesize_limit'	=> $this->filesize_limit,
		);

		if ( $this->status == 0 ) {

			$state['compressor']		= $this->compressor->Save();
			$state['compressor_name']	= $this->compressor_name;
			$state['writer_name']		= $this->writer_name;

		} else if ( $this->status == 1 ) {

			$state['compressor']		= $this->compressor->Save();
			$state['compressor_name']	= $this->compressor_name;
			$state['writer']		= $this->writer->Save();
			$state['writer_name']		= $this->writer_name;

			$state['rolling_name']		= $this->rolling_name;
			$state['rolling_writing']	= $this->rolling_writing;
			$state['rolling_offset']	= $this->rolling_offset;

		} else {

			$state['reconstruct']		= $this->reconstruct;

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
		$this->indx_disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );
		$this->data_disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );

		if ( ( $ret = $this->indx_disk->Load( $state['indx_disk'], $rotation ) ) !== true ) return $ret;

		if ( ( $ret = $this->data_disk->Load( $state['data_disk'], $rotation ) ) !== true ) {
			$this->indx_disk->CleanUp();
			return $ret;
		}

		if ( $this->status == 0 || $this->status == 1 ) {

			$this->writer_name = $state['writer_name'];
			$this->compressor_name = $state['compressor_name'];

			// Load the writer
			require_once WPONLINEBACKUP_PATH . '/include/' . strtolower( $this->writer_name ) . '.php';
			$name = 'WPOnlineBackup_' . $this->writer_name;

			$this->writer = new $name( $this->WPOnlineBackup );

			if ( $this->status == 1 && ( $ret = $this->writer->Load( $state['writer'], $this->data_disk, $rotation ) ) !== true ) {
				$this->indx_disk->CleanUp();
				$this->data_disk->CleanUp();
				return $ret;
			}

			// Load the compressor
			require_once WPONLINEBACKUP_PATH . '/include/' . strtolower( $this->compressor_name ) . '.php';
			$name = 'WPOnlineBackup_' . $this->compressor_name;

			$this->compressor = new $name( $this->WPOnlineBackup );

			if ( ( $ret = $this->compressor->Load( $state['compressor'], $this->writer, $rotation ) ) !== true ) {
				$this->writer->CleanUp();
				$this->indx_disk->CleanUp();
				$this->data_disk->CleanUp();
				return $ret;
			}

			$this->code = $this->compressor->Get_ZIP_Code();

			// Repopulate the saved state
			if ( $this->status == 1 ) {

				$this->rolling_name = $state['rolling_name'];
				$this->rolling_writing = $state['rolling_writing'];
				$this->rolling_offset = $state['rolling_offset'];

			}

		} else {

			$this->reconstruct = $state['reconstruct'];

		}

		return true;
	}

	/*public*/ function Open( $config, $title, $description )
	{
		// ASSERTION - The file is closed
		$this->config = $config;

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

		$this->data_disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );
		$this->indx_disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );

		$this->data_disk->Initialise( $this->config['designated_path'] );
		$this->indx_disk->Initialise( $this->config['designated_path'] );

		if ( ( $ret = $this->data_disk->Open( 'backup.data' ) ) !== true ) return $ret;

		if ( ( $ret = $this->indx_disk->Open( 'backup.indx' ) ) !== true ) {
			$this->data_disk->CleanUp();
			return $ret;
		}

		// Pass the writer to the compressor, and open it
		if ( ( $ret = $this->compressor->Open( $this->writer ) ) !== true ) {
			$this->writer->CleanUp();
			$this->data_disk->CleanUp();
			$this->indx_disk->CleanUp();
			return $ret;
		}

		$this->code = $this->compressor->Get_ZIP_Code();

		$this->status = 0;

		$this->files = 0;

		$flags = 0;
		if ( $this->compressed ) $flags |= 0x01;
		if ( $this->encrypted ) $flags |= 0x02;

		$nlen = strlen( $title );
		if ( $nlen > 255 ) {
			$nlen = 255;
			$title = substr( $title, 0, 255 );
		}

		$dlen = strlen( $description );
		if ( $dlen > 255 ) {
			$dlen = 255;
			$description = substr( $description, 0, 255 );
		}

		// Build and write the index header
		$fields = array(
			array( 'a6',	'OBFWDI'	), // Signature, always "OBFWDI"
			array( 'v',	1		), // Delta index version (Currently 1)
			array( 'V',	$flags		), // Bitewise flags: 0x01 = compressed. 0x02 = encrypted. 0x04 = metadata encrypted (not yet implemented)
			array( 'v',	$nlen		), // Length of title
			array( 'v',	$dlen		), // Length of description
		);

		if ( ( $ret = $this->indx_disk->Write( WPOnlineBackup_Functions::Pack_Fields( $fields ) . $title . $description ) ) !== true ) {
			$this->compressor->CleanUp();
			$this->writer->CleanUp();
			$this->data_disk->CleanUp();
			$this->indx_disk->CleanUp();
			return $ret;
		}

		// Default to no limits
		$this->datasize_limit = null;
		$this->filesize_limit = null;

		return true;
	}

	/*public*/ function Flush()
	{
		// ASSERTION - The file is open
		// Write the EOF entry
		if ( ( $ret = $this->Add_EOF_Entry() ) !== true ) return $ret;

		return true;
	}

	/*public*/ function Close()
	{
		$this->writer->CleanUp();
		$this->compressor->CleanUp();

		$this->writer = false;
		$this->compressor = false;

		// Close the disks
		if ( ( $ret = $this->data_disk->Close() ) !== true ) return $ret;
		if ( ( $ret = $this->indx_disk->Close() ) !== true ) return $ret;

		$this->status = 2;

		return true;
	}

	/*public*/ function CleanUp()
	{
		if ( $this->status == 0 || $this->status == 1 ) {
			$this->compressor->CleanUp();
			$this->writer->CleanUp();
		}

		$this->data_disk->CleanUp();
		$this->indx_disk->CleanUp();
	}

	/*public*/ function Start_Reconstruct()
	{
		// ASSERTION - Status is 2 - Close() has been called
		$this->reconstruct = array(
			'data'	=> null,
			'indx'	=> null,
		);

		if ( ( $ret = $this->data_disk->Start_Reconstruct() ) !== true ) return $ret;

		return $this->indx_disk->Start_Reconstruct();
	}

	/*public*/ function Do_Reconstruct()
	{
		// ASSERTION - Status is 2 - Close() has been called
		// ASSERTION - Start_Reconstruct has been called
		// Reconstruct the data file if not finished already
		if ( is_null( $this->reconstruct['data'] ) ) {

			if ( ( $ret1 = $this->data_disk->Do_Reconstruct() ) !== true ) {

				if ( !is_array( $ret1 ) ) return $ret1;

				$this->reconstruct['data'] = $ret1;

			}

		} else $ret1 = false;

		// Reconstruct the index file
		if ( is_null( $this->reconstruct['indx'] ) ) {

			if ( ( $ret2 = $this->indx_disk->Do_Reconstruct() ) !== true ) {

				if ( !is_array( $ret2 ) ) return $ret2;

				$this->reconstruct['indx'] = $ret2;

			}

		} else $ret2 = false;

		// If both now reconstructed, both values will be either false or an array
		if ( $ret1 !== true && $ret2 !== true ) {

			$ret = array();
			foreach ( $this->reconstruct as $disk => $reconstruct ) {
				foreach ( $reconstruct as $key => $value ) $ret[$key][$disk] = $value;
			}

			return $ret;

		}

		return true;
	}

	/*public*/ function End_Reconstruct()
	{
		// ASSERTION - Status is 2 - Close() has been called
		// ASSERTION - Start_Reconstruct has been called and Do_Reconstruct has returned success
		$this->reconstruct = null;

		if ( ( $ret = $this->data_disk->End_Reconstruct() ) !== true ) return $ret;

		return $this->indx_disk->End_Reconstruct();
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
		// ASSERTION - The file is open and we are not in the middle of a rolling deflation with Open_File
		// Write folder entry
		if ( ( $ret = $this->Add_Folder_Entry( $bin, $folder, $status ) ) !== true ) return $ret;

		$success = true;

		return true;
	}

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

			$this->files++;

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
		);

		$pos = $this->data_disk->Pos();

		// Write data as if it was a file so we can split this up
		if ( ( $ret = $this->writer->Open( $this->data_disk, $this->config['encryption'], $this->config['encryption_key'] ) ) !== true ) return $ret;

		if ( ( $ret = $this->writer->Write( $result['data'] ) ) !== true ) return $ret;

		if ( !is_array( $ret = $this->writer->Close() ) ) return $ret;

		$size['stored_size'] = $ret['size'];

		// Write file entry
		if ( ( $ret = $this->Add_File_Entry( $bin, $file, $status, $this->code, $result['crc'], $result['zlen'], $ret['size'], $result['size'], $pos ) ) !== true ) return $ret;

		$this->files++;

		return true;
	}

	/*public*/ function Start_Stream( $bin, $name, & $size, $status )
	{
		// ASSERTION - The file is open and we are not in the middle of a stream started with Start_Stream
		// Store file name
		$this->rolling_name = array( $bin, $name, & $size, $status );
		$this->rolling_writing = false;
		$this->rolling_offset = $this->data_disk->Pos();

		// Start the compressor
		if ( ( $ret = $this->compressor->Start_Stream() ) !== true ) return $ret;

		$this->status = 1;

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

				// Compressor wants to write, open the writer
				if ( ( $ret = $this->writer->Open( $this->data_disk, $this->config['encryption'], $this->config['encryption_key'] ) ) !== true ) return $ret;

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

			// Not been writing so we haven't opened, yet, so open now
			if ( ( $ret = $this->writer->Open( $this->data_disk, $this->config['encryption'], $this->config['encryption_key'] ) ) !== true ) return $ret;

		}

		// If we never started writing, the following will perform all in buffer and write immediately
		if ( !is_array( $result = $this->compressor->Commit_Stream() ) ) return $result;

		$this->rolling_name[2] = array(
			'file_size'	=> $result['size'],
		);

		// Close the writer
		if ( !is_array( $ret = $this->writer->Close() ) ) return $ret;

		$this->rolling_name[2]['stored_size'] = $ret['size'];

		// Write file entry
		if ( ( $ret = $this->Add_File_Entry( $this->rolling_name[0], $this->rolling_name[1], $this->rolling_name[3], $this->code, $result['crc'], $result['zlen'], $ret['size'], $result['size'], $this->rolling_offset ) ) !== true ) return $ret;

		return true;
	}

	/*public*/ function CleanUp_Stream()
	{
		// Cleanup the compressor
		$this->compressor->CleanUp_Stream();

		$this->rolling_name = false;
		$this->rolling_offset = null;

		$this->files++;

		$this->status = 0;

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

	/*private*/ function Add_Folder_Entry( $bin, $folder, $status )
	{
		// Strip leading slashes from folder name
		$folder = preg_replace( '/^[\\/\\\\]+/', '', $folder );

		// Calculate name length
		$nlen = strlen( $folder );

		// Build folder entry
		$fields = array(
			array( 'a3',	'FLD'			),	// Folder header
			array( 'C',	$bin			),	// bin
			array( 'V',	$status['item_id']	),	// item_id
			array( 'V',	$status['parent_id']	),	// parent_id
			array( 'V',	$status['backup_time']	),	// backup time
			array( 'v',	0x0000			),	// padding
			array( 'v',	$nlen			),	// name length
		);

		// Write the entry and name
		if ( ( $ret = $this->indx_disk->Write( WPOnlineBackup_Functions::Pack_Fields( $fields ) . $folder ) ) !== true ) return $ret;

		return true;
	}

	/*private*/ function Add_File_Entry( $bin, $file, $status, $meth, $crc, $zlen, $elen, $len, $offset )
	{
		// Strip leading slashes from file name
		$file = preg_replace( '/^[\\/\\\\]+/', '', $file );

		// Calculate name length
		$nlen = strlen( $file );

		// Build file entry
		$fields = array(
			array( 'a3',	'FLE'			),	// File header
			array( 'C',	$bin			),	// bin
			array( 'V',	$status['item_id']	),	// item_id
			array( 'V',	$status['parent_id']	),	// parent_id
			array( 'V',	$offset			),	// Offset of this file inside the data file
			array( 'V',	$crc			),	// crc32 of data
			array( 'V',	$elen			),	// encrypted data length
			array( 'V',	$zlen			),	// compressed data length
			array( 'V',	$len			),	// uncompressed data length
			array( 'V',	$status['mod_time']	),	// modification time
			array( 'V',	$status['backup_time']	),	// backup time
			array( 'v',	$meth			),	// compresion method (deflate or store)
			array( 'v',	$nlen			),	// filename length
		);

		// Write the entry and filename
		if ( ( $ret = $this->indx_disk->Write( WPOnlineBackup_Functions::Pack_Fields( $fields ) . $file ) ) !== true ) return $ret;

		return true;
	}

	/*private*/ function Add_Deletion_Entry( $bin, $item_id, $backup_time, $deletion_time )
	{
		// Build deletion entry
		$fields = array(
			array( 'a3',	'DEL'			),	// Folder header
			array( 'C',	$bin			),	// bin
			array( 'V',	$item_id		),	// item_id
			array( 'V',	$backup_time		),	// backup_time
			array( 'V',	$deletion_time		),	// deletion_time
		);

		// Write the entry
		if ( ( $ret = $this->indx_disk->Write( WPOnlineBackup_Functions::Pack_Fields( $fields ) ) ) !== true ) return $ret;

		return true;
	}

	/*private*/ function Add_EOF_Entry()
	{
		// Build EOF entry
		$fields = array(
			array( 'a3',	'EOF'			),	// EOF header
			array( 'C',	0x00			),	// padding
		);

		// Write the fields
		if ( ( $ret = $this->indx_disk->Write( WPOnlineBackup_Functions::Pack_Fields( $fields ) ) ) !== true ) return $ret;

		return true;
	}
}

?>
