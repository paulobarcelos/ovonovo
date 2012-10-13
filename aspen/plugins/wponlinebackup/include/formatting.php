<?php

/*
WPOnlineBackup_Formatting - Misc formatting functions
Time formatting / Size formatting etc

Fix_Time, Fix_B, Max_Upload_Size are from commercial code
These specific snippets have been gracefully donated to the project for completely unrestricted use
*/

class WPOnlineBackup_Formatting
{
	/*public static*/ function Fix_Time( $val, $text = false )
	{
		$s = $text ? ' ' : '&nbsp;';
		$sb = array();
		if ( $val >= 86400 ) {
			$v = $val % 86400;
			$sb[] = ( ( $val - $v ) / 86400 ) . $s . 'd';
			$val = $v;
		}
		if ( $val >= 3600 ) {
			$v = $val % 3600;
			$sb[] = ( ( $val - $v ) / 3600 ) . $s . 'h';
			$val = $v;
		}
		if ( $val >= 60 ) {
			$v = $val % 60;
			$sb[] = ( ( $val - $v ) / 60 ) . $s . 'm';
			$val = $v;
		}
		if ( count( $sb ) == 0 || $val != 0 ) $sb[] = $val . $s . 's';
		return implode( $s, $sb );
	}

	/*public static*/ function Fix_B( $val, $text = false )
	{
		$s = $text ? ' ' : '&nbsp;';
		if ( $val >= 1125899906842624 ) {
			$val /= 1125899906842624;
			return round( $val, 2 ) . $s . 'PiB';
		} else if ( $val >= 1099511627776) {
			$val /= 1099511627776;
			return round( $val, 2 ) . $s . 'TiB';
		} else if ( $val >= 1073741824) {
			$val /= 1073741824;
			return round( $val, 2 ) . $s . 'GiB';
		} else if ( $val >= 1048576) {
			$val /= 1048576;
			return round( $val, 2 ) . $s . 'MiB';
		} else if ( $val >= 1024) {
			$val /= 1024;
			return round( $val, 2 ) . $s . 'KiB';
		}
		return round( $val, 2 ) . $s . 'B';
	}

	/*public static*/ function Max_Upload_Size()
	{
		if ( ( $memory_limit = ini_get( 'memory_limit' ) ) == '' || $memory_limit == -1 ) $memory_limit = '64M';
		if ( ( $post_max_size = ini_get( 'post_max_size' ) ) == '' ) $post_max_size = '8M';
		if ( ( $upload_max_filesize = ini_get( 'upload_max_filesize' ) ) == '' ) $upload_max_filesize = '8M';

		if ( preg_match( '/^\\s*[0-9]+\\s*(K|M|G)?\\s*$/i', $memory_limit, $matches ) ) {
			switch ( $matches[1] ) {
				case 'K': case 'k': $m = 1024; break;
				case 'M': case 'm': $m = 1024*1024; break;
				case 'G': case 'g': $m = 1024*1024*1024; break;
				default: $m = 1; break;
			}
		} else $m = 1;
		$memory_limit = max( 8*1024*1024, intval( $memory_limit ) * $m ) - 5*1024*1024;

		if ( preg_match( '/^\\s*[0-9]+\\s*(K|M|G)?\\s*$/i', $post_max_size, $matches ) ) {
			switch ( $matches[1] ) {
				case 'K': case 'k': $m = 1024; break;
				case 'M': case 'm': $m = 1024*1024; break;
				case 'G': case 'g': $m = 1024*1024*1024; break;
				default: $m = 1; break;
			}
		} else $m = 1;
		$post_max_size = max( 8*1024*1024, intval( $post_max_size ) * $m ) - 4*1024*1024;

		if ( preg_match( '/^\\s*[0-9]+\\s*(K|M|G)?\\s*$/i', $upload_max_filesize, $matches ) ) {
			switch ( $matches[1] ) {
				case 'K': case 'k': $m = 1024; break;
				case 'M': case 'm': $m = 1024*1024; break;
				case 'G': case 'g': $m = 1024*1024*1024; break;
				default: $m = 1; break;
			}
		} else $m = 1;
		$upload_max_filesize = intval( $upload_max_filesize ) * $m;

		return min( $memory_limit, $post_max_size, $upload_max_filesize );
	}

	/*public static*/ function Memory_Limit()
	{
		if ( ( $memory_limit = ini_get( 'memory_limit' ) ) == '' || $memory_limit == -1 ) $memory_limit = '64M';

		if ( preg_match( '/^\\s*[0-9]+\\s*(K|M|G)?\\s*$/', $memory_limit, $matches ) ) {
			switch ( $matches[1] ) {
				case 'K': $m = 1024; break;
				case 'M': $m = 1024*1024; break;
				case 'G': $m = 1024*1024*1024; break;
				default: $m = 1; break;
			}
		} else $m = 1;
		return max( 8*1024*1024, intval( $memory_limit ) * $m ) - 5*1024*1024;
	}
}

?>
