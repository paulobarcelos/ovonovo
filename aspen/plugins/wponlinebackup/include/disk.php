<?php

/*
WPOnlineBackup_Disk - Performs the disk operations required to write the backup
Also allows spanning accross multiple files in timeout situations and generates patch files if necessary
*/

class WPOnlineBackup_Disk
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $status;

	/*private*/ var $rotate_id;

	/*private*/ var $prefix;

	/*private*/ var $name;
	/*private*/ var $file;
	/*private*/ var $offset;
	/*private*/ var $size;

	/*private*/ var $p_file;
	/*private*/ var $p_offset;
	/*private*/ var $p_size;
	/*private*/ var $p_index;

	/*private*/ var $rotation;
	/*private*/ var $patches;

	/*private*/ var $header;

	/*private*/ var $r_files;
	/*private*/ var $rp_files;

	/*private*/ var $rc_file;
	/*private*/ var $rc_offset;

	/*public*/ function WPOnlineBackup_Disk( & $WPOnlineBackup, $rotate_id = 0 )
	{
		$this->WPOnlineBackup = & $WPOnlineBackup;

		$this->rotate_id = $rotate_id;

		$this->r_files = $this->rp_files = array();
	}

	/*public*/ function Save()
	{
		// Ensure all data is flushed
		if ( $this->file !== false ) fflush( $this->file );
		if ( $this->p_file !== false ) fflush( $this->p_file );
		if ( $this->rc_file !== false ) fflush( $this->rc_file );

		$state = array(
			'status'		=> $this->status,
			'header'		=> $this->header, // In case we change during update
			'rotate_id'		=> $this->rotate_id,
			'prefix'		=> $this->prefix,
			'name'			=> $this->name,
			'offset'		=> $this->offset,
			'size'			=> $this->size,
			'd_offset'		=> $this->d_offset,
			'rotation'		=> $this->rotation,
			'p_offset'		=> $this->p_offset,
			'p_size'		=> $this->p_size,
			'p_index'		=> $this->p_index,
			'p_rindex'		=> $this->p_rindex,
			'patches'		=> $this->patches,
		);

		if ( $this->status == 2 ) {

			$state['rc_offset'] = $this->rc_offset;

		}

		return $state;
	}

	/*public*/ function Load( $state, $rotation = false )
	{
		// ASSERTION - Status is null and we are initialised
		$this->status = $state['status'];

		$this->header = $state['header'];

		$this->rotate_id = $state['rotate_id'];
		if ( $rotation === false ) $rotate = false;
		else $rotate = $rotation != $this->rotate_id ? true : false;

		$this->prefix = $state['prefix'];

		$this->name = $state['name'];
		$this->offset = $state['offset'];
		$this->size = $state['size'];
		$this->d_offset = $state['d_offset'];

		$this->p_file = false;
		$this->p_offset = $state['p_offset'];
		$this->p_size = $state['p_size'];
		$this->p_index = $state['p_index'];
		$this->p_rindex = $state['p_rindex'];

		$this->rotation = $state['rotation'];
		$this->patches = $state['patches'];

		if ( $this->status == 0 && $rotate ) {

			// Rotate the files
			$this->rotation[$this->rotate_id] = array(
				'rotate_id'	=> $this->rotate_id,
				'offset'	=> $this->offset,
				'size'		=> $this->size,
			);

			$this->offset += $this->size;
			$this->size = 0;

			$this->patches[$this->rotate_id] = array(
				'rotate_id'	=> $this->rotate_id,
				'offset'	=> $this->p_offset,
				'size'		=> $this->p_size,
			);

			$this->p_offset += $this->p_size;
			$this->p_size = 0;

			// Fill in any gaps, to ensure we delete all files in cleanup
			$i = $this->rotate_id + 1;

			while ( $i < $rotation ) {

				$this->rotation[$i] = array(
					'rotate_id'	=> $i,
					'offset'	=> 0,
					'size'		=> 0,
				);

				$this->patches[$i] = array(
					'rotate_id'	=> $i,
					'offset'	=> 0,
					'size'		=> 0,
				);

				$i++;

			}

			// Set the new rotation value
			$this->rotate_id = $rotation;

			// Attempt to cleanup rotated files
			$this->CleanUp_Rotated();

		}

		if ( $this->status == 0 ) {

			if ( $this->rotate_id ) $state['name'] .= '.' . $this->rotate_id;

			// Reopen the file
			if ( ( $this->file = @fopen( $this->prefix . '/' . $state['name'] . '.php', $rotate ? 'w+b' : 'r+b' ) ) === false ) return OBFW_Exception();

			if ( $rotate || $this->size == 0 ) {

				if ( !$rotate && @fseek( $this->file, 0, SEEK_SET ) != 0 ) {
					@fclose( $this->file );
					return OBFW_Exception();
				}

				// Write the Rejection header
				if ( ( $ret = $this->Write_Rejection_Header( $this->file ) ) !== true ) {
					@fclose( $this->file );
					return $ret;
				}

			} else {

				// Seek to where we left off
				if ( @fseek( $this->file, strlen( $this->header ) + $this->size, SEEK_SET ) != 0 ) {
					@fclose( $this->file );
					return OBFW_Exception();
				}

			}

			$this->rc_file = false;

		} else if ( $this->status == 2 ) {

			$this->rc_offset = $state['rc_offset'];

			// Open a file to reconstruct into
			if ( ( $this->rc_file = @fopen( $this->prefix . '/' . $state['name'] . '.rc.php', 'r+b' ) ) === false ) return OBFW_Exception();

			if ( $this->rc_offset == 0 ) {

				// Seek to where we left off
				if ( @fseek( $this->rc_file, 0, SEEK_SET ) != 0 ) {
					@fclose( $this->rc_file );
					return OBFW_Exception();
				}

				// Write the Rejection header
				if ( ( $ret = $this->Write_Rejection_Header( $this->rc_file ) ) !== true ) {
					@fclose( $this->rc_file );
					return $ret;
				}

			} else {

				// Seek to where we left off
				if ( @fseek( $this->rc_file, strlen( $this->header ) + $this->rc_offset, SEEK_SET ) != 0 ) {
					@fclose( $this->rc_file );
					return OBFW_Exception();
				}

			}

			$this->d_offset = $this->rc_offset;

			$this->file = false;

		} else {

			$this->file = false;
			$this->rc_file = false;

		}

		// Clean up deleted data if need be
		$this->Delete();

		return true;
	}

	/*public*/ function Initialise( $prefix )
	{
		$this->prefix = $prefix;
	}

	/*public*/ function Open( $name )
	{
		// ASSERTION - Status is null and we are initialised
		$this->status = 0;

		$this->header = <<<HEADER
<?php
/*Rejection header*/
__halt_compiler();
}
HEADER;

		$this->name = $name;

		if ( $this->rotate_id ) $name .= '.' . $this->rotate_id;

		// Create the file with PHP extension so you cannot download it
		if ( ( $this->file = @fopen( $this->prefix . '/' . $name . '.php', 'w+b' ) ) === false ) return OBFW_Exception();

		// Write the Rejection header
		if ( ( $ret = $this->Write_Rejection_Header( $this->file ) ) !== true ) {
			@fclose( $this->file );
			return $ret;
		}

		$this->offset = 0;
		$this->size = 0;
		$this->d_offset = 0;

		$this->p_file = false;
		$this->p_offset = 0;
		$this->p_size = 0;
		$this->p_index = array();
		$this->p_rindex = array();

		$this->rotation = array();
		$this->patches = array();

		$this->rc_file = false;

		return true;
	}

	/*public*/ function Get_File_Path( $name = false )
	{
		if ( $name === false ) $name = $this->name;

		if ( $this->rotate_id ) $name .= '.' . $this->rotate_id;

		return $this->prefix . '/' . $name . '.php';
	}

	/*private*/ function CleanUp_Handles()
	{
		foreach ( $this->r_files as $id => $file ) @fclose( $this->r_files[$id] );
		foreach ( $this->rp_files as $id => $file ) @fclose( $this->rp_files[$id] );

		if ( $this->p_file !== false ) @fclose( $this->p_file );

		if ( $this->file !== false ) @fclose( $this->file );
	}

	/*public*/ function Close()
	{
		$this->CleanUp_Handles();

		$this->p_file = false;
		$this->file = false;

		$this->status = 1;

		return true;
	}

	/*public*/ function CleanUp()
	{
		$this->Delete( true );

		$this->CleanUp_Handles();

		if ( $this->rc_file !== false ) {
			@fclose( $this->rc_file );
			@unlink( $this->prefix . '/' . $this->name . '.rc.php' );
		}
	}

	/*private*/ function Partial_Read( $read, $length, $type, $id )
	{
		$e = OBFW_Exception();
		$name = $this->name;
		switch ( $type ) {
			case 'patch':
				$name .= '.patch';
			default:
				if ( $id ) $name .= '.' . $id;
				break;
		}
		if ( $e ) $e = 'PHP last error: ' . $e;
		else $e = 'PHP has no record of an error.';
		return 'Attempt to read from file ' . $this->prefix . '/' . $name . '.php only partially succeeded. Unexpected end of file. Only ' . $read . ' of ' . $length . ' bytes were read. ' . $e;
	}

	/*private*/ function Partial_Write( $written, $length, $type = '' )
	{
		$e = OBFW_Exception();
		$name = $this->name;
		switch ( $type ) {
			case 'patch':
				$name .= '.patch';
			default:
				if ( $this->rotate_id ) $name .= '.' . $this->rotate_id;
				break;
			case 'rc':
				$name .= '.rc';
				break;
		}
		if ( $e ) $e = 'PHP last error: ' . $e;
		else $e = 'PHP has no record of an error.';
		return 'Attempt to write to file ' . $this->prefix . '/' . $name . '.php only partially succeeded. Only ' . $written . ' of ' . $length . ' bytes were written. (' . $this->size . ' bytes already written.) ' . $e;
	}

	/*public*/ function Write( $data, $length = null )
	{
		// ASSERTION - Status is 0
		if ( is_null( $length ) )
			$length = strlen( $data );

		// Make sure we don't go into the loop with 0 bytes, and pretend we were successful
		if ( $length == 0 )
			return true;

		$todo_length = $length;

		// It seems when you fwrite lots of times it sometimes begins to write less than what you requested
		// Maybe the write buffer is not quite full, but has room for some bytes, and so PHP adds to it and then returns how many fit?
		// Then when the buffer is actually full, it waits so it never returns 0 unless something is wrong... Maybe one needs to check the source.
		// Just loop until we failed, wrote nothing, or finish
		while ( true ) {

			if ( ( $written = @fwrite( $this->file, $data, $todo_length ) ) === false )
				return OBFW_Exception();

			// If we wrote nothing, fail
			if ( $written == 0 )
				return $this->Partial_Write( $length - $todo_length, $length );

			$todo_length -= $written;

			if ( $todo_length == 0 )
				break;

			// Trim off what we wrote
			$data = substr( $data, $written );

		}

		$this->size += $length;

		return true;
	}

	/*public*/ function Pos()
	{
		// Return current position - used for incremental index file.
		return $this->offset + $this->size;
	}

	/*public*/ function Rewrite( $offset, $data, $length = null )
	{
		// ASSERTION - Status is 0
		// ASSERTION - Rewrite always rewrites EXISTING data, and never writes past the current end of the file
		if ( is_null( $length ) )
			$length = strlen( $data );

		if ( $offset >= $this->offset ) {

			// Rewriting in the active file
			if ( @fseek( $this->file, strlen( $this->header ) + ( $offset - $this->offset ), SEEK_SET ) != 0 ) return OBFW_Exception();

			$todo_length = $length;

			// See ->Write() for why we do this loop
			while ( true ) {

				if ( ( $written = @fwrite( $this->file, $data, $todo_length ) ) === false )
					return OBFW_Exception();

				// If we wrote nothing, fail
				if ( $written == 0 )
					return $this->Partial_Write( $length - $todo_length, $length );

				$todo_length -= $written;

				if ( $todo_length == 0 )
					break;

				// Trim off what we wrote
				$data = substr( $data, $written );

			}

			if ( @fseek( $this->file, strlen( $this->header ) + $this->size, SEEK_SET ) != 0 ) return OBFW_Exception();

		} else {

			// Store in patch files only data that is rewritten in rotated files
			$this_length = min( $this->offset - $offset, $length );

			// Open the patches file if needed
			if ( $this->p_file === false ) {

				if ( $this->p_size == 0 ) {

					if ( ( $this->p_file = @fopen( $this->prefix . '/' . $this->name . '.patch.' . $this->rotate_id . '.php', 'w+b' ) ) === false ) return OBFW_Exception();

					if ( ( $ret = $this->Write_Rejection_Header( $this->p_file, 'patch' ) ) !== true ) return $ret;

				} else {

					if ( ( $this->p_file = @fopen( $this->prefix . '/' . $this->name . '.patch.' . $this->rotate_id . '.php', 'r+b' ) ) === false ) return OBFW_Exception();

					// Seek to where we left off
					if ( @fseek( $this->p_file, strlen( $this->header ) + $this->p_size, SEEK_SET ) != 0 ) return OBFW_Exception();

				}

			}

			// Write the patch
			$todo_length = $this_length;

			// See ->Write() for why we do this loop
			while ( true ) {

				if ( ( $written = @fwrite( $this->p_file, $data, $todo_length ) ) === false )
					return OBFW_Exception();

				// If we wrote nothing, fail
				if ( $written == 0 )
					return $this->Partial_Write( $this_length - $todo_length, $this_length, 'patch' );

				$todo_length -= $written;

				if ( $todo_length == 0 )
					break;

				// Trim off what we wrote
				$data = substr( $data, $written );

			}

			// Index the patch so we can find it when we need it
			if ( !array_key_exists( $offset, $this->p_index ) ) {
				$this->p_index[$offset] = array();
				ksort( $this->p_index );
			}
			$this->p_index[$offset][$this_length] = array( $this->rotate_id, $this->p_size );
			ksort( $this->p_index[$offset] );
			if ( !array_key_exists( $this->rotate_id, $this->p_rindex ) ) $this->p_rindex[$this->rotate_id] = 1;
			else $this->p_rindex[$this->rotate_id]++;

			$this->p_size += $this_length;

			// Modify length and offset and recurse if we still have to rewrite some more in the active file
			$length -= $this_length;

			if ( $length == 0 ) return true;

			$offset += $this_length;

			return $this->Rewrite( $offset, substr( $data, $this_length ), $length );

		}

		return true;
	}

	/*public*/ function Flush( & $buffer, $length )
	{
		// ASSERTION - Status is 1 (closed) or 2 (reconstructing)
		$buffer = '';

		$flushed = 0;

		foreach ( $this->rotation as $id => $rotate ) {

			if ( $rotate['size'] == 0 ) continue;

			// Find the file that contains this part of the data
			if ( $rotate['offset'] + $rotate['size'] > $this->d_offset + $flushed ) {

				if ( !array_key_exists( $id, $this->r_files ) ) {

					$name = $this->name;

					if ( $id ) $name .= '.' . $id;

					if ( ( $this->r_files[$id] = @fopen( $this->prefix . '/' . $name . '.php', 'rb' ) ) === false ) {
						$ret = OBFW_Exception();
						unset( $this->r_files[$id] );
						return $ret;
					}

				}

				$available = $rotate['offset'] + $rotate['size'] - $this->d_offset - $flushed;

				// Get the data
				if ( ( $to_flush = min( $length - $flushed, $available ) ) != 0 ) {

					if ( @fseek( $this->r_files[$id], strlen( $this->header ) + $this->d_offset + $flushed - $rotate['offset'], SEEK_SET ) != 0 ) return OBFW_Exception();

					if ( ( $extract = @fread( $this->r_files[$id], $to_flush ) ) === false ) return OBFW_Exception();

					if ( ( $len = strlen( $extract ) ) != $to_flush ) return $this->Partial_Read( $len, $to_flush, '', $id );

					$buffer .= $extract;

					$flushed += $to_flush;

				}

				// If anymore to retrieve from different rotation files, keep looping
				if ( $flushed < $length ) {

					@fclose( $this->r_files[$id] );
					unset( $this->r_files[$id] );

					continue;

				} else if ( $to_flush == $available ) {

					// Also close (closing is optimized for continuous flushes)
					@fclose( $this->r_files[$id] );
					unset( $this->r_files[$id] );

				}

				// More data
				$ret = $this->Patch( $this->d_offset, $length, $buffer, true );

				$this->d_offset += $flushed;

				return $ret;

			}

		}

		// Check the current file
		if ( $this->offset + $this->size >= $this->d_offset + $flushed ) {

			// Check the file is available (won't be if first call)
			if ( $this->file === false ) {

				$name = $this->name;

				if ( $this->rotate_id ) $name .= '.' . $this->rotate_id;

				if ( ( $this->file = @fopen( $this->prefix . '/' . $name . '.php', 'rb' ) ) === false ) return OBFW_Exception();

			}

			$available = $this->offset + $this->size - $this->d_offset - $flushed;

			if ( ( $to_flush = min( $length - $flushed, $available ) ) != 0 ) {

				// Get the data
				if ( @fseek( $this->file, strlen( $this->header ) + $this->d_offset + $flushed - $this->offset, SEEK_SET ) != 0 ) return OBFW_Exception();

				if ( ( $extract = @fread( $this->file, $to_flush ) ) === false ) return OBFW_Exception();

				if ( ( $len = strlen( $extract ) ) != $to_flush ) return $this->Partial_Read( $len, $to_flush, '', $this->rotate_id );

				$buffer .= $extract;

				$flushed += $to_flush;

			}

			// Anymore data?
			$ret = $to_flush < $available ? true : false;

			if ( $ret === false ) {

				// Also close if there is no more data
				@fclose( $this->file );

				$this->file = false;

			}

			$ret = $this->Patch( $this->d_offset + $flushed, $to_flush, $buffer, $ret );

			$this->d_offset += $flushed;

			return $ret;

		}
	}

	/*private*/ function Patch( $offset, $length, & $buffer, $ret )
	{
		// Don't be concerned about loading p_file and not setting it's offset to the end (meaning Rewrite() will overwrite data)
		// Rewrite() should never be called after Flush() (which calls Patch()) is called so it would be a bug if so. Flush() requires the file is closed for changes

		// Find applicable patches
		foreach ( $this->p_index as $p_offset => $index ) {

			foreach ( $index as $p_length => $patch ) {

				// If patch already applied, skip
				if ( $p_offset + $p_length <= $offset ) continue;

				// If patch doesn't apply yet, return as no more apply (patches are in order)
				if ( $p_offset >= $offset + $length ) return $ret;

				// Patch applies, calculate what needs patching
				if ( $p_offset < $offset ) {
					$p_start = $offset - $p_offset;
					$to_copy = min( $p_length - $p_start, $length );
					$to = 0;
				} else {
					$p_start = 0;
					$to_copy = min( $offset + $length - $p_offset, $p_length );
					$to = $p_offset - $offset;
				}

				if ( $to_copy == 0 ) continue;

				if ( $this->rotate_id == $patch[0] ) {

					if ( $this->p_file === false ) {

						if ( ( $this->p_file = @fopen( $this->prefix . '/' . $this->name . '.patch.' . $this->rotate_id . '.php', 'rb' ) ) === false ) return OBFW_Exception();

					}

					$file = $this->p_file;

				} else {

					if ( !array_key_exists( $patch[0], $this->rp_files ) ) {

						$name = $this->name . '.patch';

						if ( $patch[0] ) $name .= '.' . $patch[0];

						if ( ( $this->rp_files[ $patch[0] ] = @fopen( $this->prefix . '/' . $name . '.php', 'rb' ) ) === false ) {
							$ret = OBFW_Exception();
							unset( $this->rp_files[ $patch[0] ] );
							return $ret;
						}

					}

					$file = $this->rp_files[ $patch[0] ];

				}

				// Get the data
				if ( @fseek( $file, strlen( $this->header ) + $patch[1] + $p_start, SEEK_SET ) != 0 ) return OBFW_Exception();

				if ( ( $extract = @fread( $file, $to_copy ) ) === false ) return OBFW_Exception();

				if ( ( $len = strlen( $extract ) ) != $to_copy ) return $this->Partial_Read( $len, $to_copy, 'patch', $patch[0] );

				$buffer = substr_replace( $buffer, $extract, $to, $len );

			}

		}

		return $ret;
	}

	/*public*/ function Delete( $all = false )
	{
		// First, remove patches we no longer need
		foreach ( $this->p_index as $p_offset => $index ) {

			foreach ( $index as $p_length => $patch ) {

				// If patch still required, keep and leave (patches are kept in order)
				if ( !$all && $p_offset + $p_length > $this->d_offset ) break 2;

				// Remove patch, and patch file if neccessary
				unset( $this->p_index[$p_offset][$p_length] );
				$this->p_rindex[ $patch[0] ]--;

				if ( $this->p_rindex[ $patch[0] ] == 0 ) {

					// Ensure we don't have this file open
					if ( array_key_exists( $patch[0], $this->rp_files ) ) {

						@fclose( $this->rp_files[ $patch[0] ] );
						unset( $this->rp_files[ $patch[0] ] );

					}

					$name = $this->name . '.patch';

					if ( $patch[0] ) $name .= '.' . $patch[0];

					@unlink( $this->prefix . '/' . $name . '.php' );

					unset( $this->p_rindex[ $patch[0] ] );

				}

			}

		}

		// Find actual files that can be deleted
		foreach ( $this->rotation as $id => $rotate ) {

			// If file is past the deletion offset, we need it and all ones after it
			if ( !$all && $rotate['offset'] + $rotate['size'] > $this->d_offset ) break;

			// Ensure we don't have this file open
			if ( array_key_exists( $id, $this->r_files ) ) {

				@fclose( $this->r_files[ $id ] );
				unset( $this->r_files[ $id ] );

			}

			$name = $this->name;

			if ( $id ) $name .= '.' . $id;

			@unlink( $this->prefix . '/' . $name . '.php' );

			unset( $this->rotation[ $id ] );

		}

		// Delete completely and close if specified to do so
		if ( $all ) {

			$name = $this->name;

			if ( $this->rotate_id ) $name .= '.' . $this->rotate_id;

			@unlink( $this->prefix . '/' . $name . '.php' );

		}

		return true;
	}

	/*public*/ function Start_Reconstruct()
	{
		// ASSERTION - Haven't already started flushing - we can only Flush OR Reconstruct

		// No reconstruction neccessary, just return true and return the result in Do_Reconstruct()
		if ( count( $this->rotation ) == 0 ) {

			$this->status = 3;

			return true;

		}

		// Open a file to reconstruct into
		if ( ( $this->rc_file = @fopen( $this->prefix . '/' . $this->name . '.rc.php', 'w+b' ) ) === false ) return OBFW_Exception();

		$this->status = 2;

		$this->rc_offset = 0;

		if ( ( $ret = $this->Write_Rejection_Header( $this->rc_file ) ) !== true ) {
			@fclose( $this->rc_file );
			return $ret;
		}

		return true;
	}

	/*public*/ function Do_Reconstruct()
	{
		if ( $this->status == 3 ) {

			$name = $this->name;

			if ( $this->rotate_id ) $name .= '.' . $this->rotate_id;

			return array(
				'file'		=> $this->prefix . '/' . $name . '.php',
				'offset'	=> strlen( $this->header ),
				'size'		=> $this->offset + $this->size,
			);

		}

		// Grab a block of data
		$length = $this->WPOnlineBackup->Get_Setting( 'max_block_size' );

		if ( !is_bool( $ret = $this->Flush( $data, $length ) ) ) {
			@fclose( $this->rc_file );
			return $ret;
		}

		$length = strlen( $data );

		// Flush into the reconstruction file
		$todo_length = $length;

		// See ->Write() for why we do this loop
		while ( true ) {

			if ( ( $written = @fwrite( $this->rc_file, $data, $todo_length ) ) === false ) {
				$ret = OBFW_Exception();
				@fclose( $this->rc_file );
				return $ret;
			}

			// If we wrote nothing, fail
			if ( $written == 0 ) {
				$ret = $this->Partial_Write( $length - $todo_length, $length, 'rc' );
				@fclose( $this->rc_file );
				return $ret;
			}

			$todo_length -= $written;

			if ( $todo_length == 0 )
				break;

			// Trim off what we wrote
			$data = substr( $data, $written );

		}

		$this->rc_offset += $length;

		if ( $ret === false ) {

			// No more data
			$this->status = 3;

			return array(
				'file'		=> $this->prefix . '/' . $this->name . '.rc.php',
				'offset'	=> strlen( $this->header ),
				'size'		=> $this->offset + $this->size,
			);

		}

		return true;
	}

	/*public*/ function End_Reconstruct()
	{
		if ( $this->rc_file !== false ) {

			$this->Delete( true );

			@fclose( $this->rc_file );

			$this->rc_file = false;

		}

		return true;
	}

	/*public*/ function Branch( & $branch )
	{
		// Create a new disk with the same destination folder and rotation ID and return it
		$branch = new WPOnlineBackup_Disk( $this->WPOnlineBackup, $this->rotate_id );

		$branch->Initialise( $this->prefix );

		return true;
	}

	/*private*/ function Write_Rejection_Header( $file, $type = '' )
	{
		// This simply turns the entire file into a PHP script
		// If anyone tries to access it through HTTP, on 99.99% of WordPress servers PHP is PHP...
		// it will parse the file as PHP instead of streaming it to the user
		// PHP 5 - __halt_compiler() cleanly aborts the compiler in the first line of the file
		// 	Users accessing the file see a blank page and no data
		// PHP 4 - We get a little dirty and force a Syntax Error to prevent PHP scanning the entire file
		// 	Users accessing the file see a Syntax Error or a blank page and no data
		// If a server returns the actual code instead of parsing it as PHP - this would already be considered a security issue
		$data = $this->header;

		$length = strlen( $data );

		$todo_length = $length;

		// See ->Write() for why we do this loop
		while ( true ) {

			if ( ( $written = @fwrite( $file, $data, $todo_length ) ) === false )
				return OBFW_Exception();

			// If we wrote nothing, fail
			if ( $written == 0 )
				return $this->Partial_Write( $length - $todo_length, $length, $type );

			$todo_length -= $written;

			if ( $todo_length == 0 )
				break;

			// Trim off what we wrote
			$data = substr( $data, $written );

		}

		return true;
	}

	/*private*/ function CleanUp_Rotated()
	{
		// Loop through rotated files
		foreach ( $this->rotation as $id => $rotate ) {

			$name = $this->name;

			if ( $id ) $name .= '.' . $id;

			// If size is 0, just delete the file
			if ( $rotate['size'] == 0 ) {

				@unlink( $this->prefix . '/' . $name . '.php' );

			} else {

				// Open the file - ignore error we can get access violations and all kinds of crap if it timed out
				if ( ( $file = @fopen( $this->prefix . '/' . $name . '.php', 'r+b' ) ) === false ) continue;

				// Attempt to truncate the file to the correct size
				@ftruncate( $file, strlen( $this->header ) + $rotate['size'] );

				// And close
				@fclose($file);

			}

		}
	}
}

?>
