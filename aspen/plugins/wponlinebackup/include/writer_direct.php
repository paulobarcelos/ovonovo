<?php

/*
WPOnlineBackup_Writer_Direct - Just writes data
We use this as a wrapper around fopen / fwrite / fclose so we can create other wrappers
	to perform things such as on-the-fly encryption with the mcrypt extension
*/

class WPOnlineBackup_Writer_Direct
{
// The disk we are writing to - this is managed externally to these wrappers
	/*private*/ var $disk;

	/*private*/ var $size;

	/*private*/ var $volatile;

	/*private*/ var $register_temps;

	/*public*/ function WPOnlineBackup_Writer_Direct( & $WPOnlineBackup )
	{
		$this->register_temps = false;
	}

	/*public*/ function Register_Temps( $value )
	{
		$this->register_temps = $value;
	}

	/*public*/ function Save()
	{
// ASSERTION - There is no volatile data

		$state = array(
			'size'		=> $this->size,
			'volatile'	=> $this->volatile,
		);

		return $state;
	}

	/*public*/ function Load( $state, & $disk, $rotation )
	{
// There isn't a state to restore, so just store the disk and initialise the other variables
		$this->disk = & $disk;
		$this->size = $state['size'];

		$this->volatile = $state['volatile'];

		return true;
	}

	/*public*/ function Open( & $disk )
	{
// ASSERTION - The file is closed

// Store the file handle
		$this->disk = & $disk;

		$this->size = 0;

// Not volatile
		$this->volatile = false;

		return true;
	}

	/*public*/ function Close()
	{
// ASSERTION - The file is open and there is no volatile data
		return array(
			'size'	=> $this->size,
		);
	}

	/*public*/ function CleanUp()
	{
	}

	/*public*/ function Write( $data, $len = null )
	{
// ASSERTION - The file is open
		if ( $this->volatile !== false ) {

// Volatile write is required - pass it through
			if ( !is_int( $ret = $this->Volatile_Write( $data, $len ) ) ) return $ret;

			return true;

		}

		if ( is_null( $len ) ) $len = strlen( $data );

		$this->size += $len;

// Write
		if ( ( $ret = $this->disk->Write( $data, $len ) ) !== true ) return $ret;

		return true;
	}

	/*private*/ function Start_Volatile()
	{
// ASSERTION - The file is open and not currently volatile
// Set the offset and length
		$this->volatile = $this->size;

// Return the current position
		return true;
	}

	/*public*/ function Volatile_Write( $data, $len = null )
	{
// ASSERTION - The file is open and is currently volatile

// If no size specified, use strlen
		if ( is_null( $len ) ) $len = strlen( $data );

// Get the current position
		$cur_pos = $this->size;

		$this->size += $len;

// All data is volatile in a raw file so we can just use Write
		if ( ( $ret = $this->disk->Write( $data, $len ) ) !== true ) return $ret;

// Return the current position
		return $cur_pos - $this->volatile;
	}

	/*public*/ function Volatile_Rewrite( $pos, $data, $length = null )
	{
// ASSERTION - The file is open
// ASSERTION - The file has volatile data
// ASSERTION - Pos points to a position in the volatile data
// ASSERTION - Length will not go past the end of volatile data

// Rewrite the data
		if ( ( $ret = $this->disk->Rewrite( $this->volatile + $pos, $data, $length ) ) !== true ) return $ret;

		return true;
	}

	/*public*/ function Solidify()
	{
// Reset the volatile position
		$this->volatile = false;

		return true;
	}

	/*public*/ function Commit()
	{
		return true;
	}

	/*public*/ function Branch( & $branch )
	{
		return $this->disk->Branch( $branch );
	}
}

?>
