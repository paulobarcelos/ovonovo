<?php

/*
WPOnlineBackup_HTTP_Range - Uploads a file to the user's browser with full support for resume and multiple download threads
Currently using previous version that only supports most range requests: Single range requests, byte ranges, offsets and suffix ranges
Multiple byte ranges are not supported
*/

class WPOnlineBackup_HTTP_Range
{
	/*public static*/ function Dump( $filepath, $filename, $offset = 0 )
	{
		if ( ( $f = @fopen( $filepath, 'rb' ) ) === false || ( $size = @filesize( $filepath ) ) === false )
			return false;

		$size -= $offset;

		$range = array_key_exists( 'HTTP_RANGE', $_SERVER ) ? $_SERVER['HTTP_RANGE'] : '';

		if ( preg_match( '/^bytes=([0-9]+)-([0-9]+)?$/', $range, $matches ) ) {

			$start = intval( $matches[1] );
			if ( array_key_exists( 2, $matches ) ) $finish = intval( $matches[2] );
			else $finish = $size - 1;

			if ( $finish < $start ) {

				$start = 0;
				$length = $size;

				if ( @fseek( $f, $offset, SEEK_SET ) != 0 ) return false;

			} else {

				$length = ( $finish - $start ) + 1;

				if ( !( $start < $length ) ) {

					@fclose( $f );

					header( 'HTTP/1.0 416 Requested range not satisfiable' );
					header( 'Status: 416 Requested range not satisfiable' );
					return true;

				}

				if ( @fseek( $f, $offset + $start, SEEK_SET ) != 0 ) return false;

				header( 'HTTP/1.0 206 Partial Content' );
				header( 'Status: 206 Partial Content' );
				header( 'Content-Range: bytes ' . $start . '-' . ( ( $start + $length ) - 1 ) . '/' . $size );

			}

		} else if ( preg_match( '/bytes=-([0-9]+)/', $range, $matches ) ) {

			$suffix = intval( $matches[1] );

			if ( $suffix > $size ) {

				$start = 0;
				$length = $size;

				if ( @fseek( $f, $offset, SEEK_SET ) != 0 ) return false;

				header( 'HTTP/1.0 206 Partial Content' );
				header( 'Status: 206 Partial Content' );
				header( 'Content-Range: bytes 0-' . ( $size - 1 ) . '/' . $size );

			} else if ( $suffix == 0 ) {

				@fclose( $f );

				header( 'HTTP/1.0 416 Requested range not satisfiable' );
				header( 'Status: 416 Requested range not satisfiable' );
				return true;

			} else {

				$length = $suffix;
				$start = $size - $suffix;

				if ( @fseek( $f, $offset + $start, SEEK_SET ) != 0 ) return false;

				header( 'HTTP/1.0 206 Partial Content' );
				header( 'Status: 206 Partial Content' );
				header( 'Content-Range: bytes ' . $start . '-' . ( ( $start + $length ) - 1 ) . '/' . $size );

			}

		} else {

			$start = 0;
			$length = $size;

			if ( @fseek( $f, $offset, SEEK_SET ) != 0 ) return false;

		}

		// Force buffers to empty
		$buffers = ob_get_level();
		while ( $buffers-- )
			@ob_end_clean();

		// Try to guess the type
		$ext = substr( $filename, -4 );
		if ( $ext == '.zip' )
			$type = 'application/zip';
		else
			$type = 'application/octet-stream';

		@set_time_limit(0);
		@ignore_user_abort(false);

		header( 'Content-Type: ' . $type );
		header( 'Content-Length: ' . $length );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Accept-Ranges: bytes' );

		while ( $length ) {
			$l = ( $length > 1048576 ? 1048576 : $length );
			if ( ( $data = @fread( $f, $l ) ) === false ) break;
			echo $data;
			$length -= $l;
		}
		@fclose( $f );

		// Catch anything we get left with
		ob_start( array( 'WPOnlineBackup_HTTP_Range', 'Capture_Junk' ) );

		return true;
	}

	/*private static*/ function Capture_Junk( $input )
	{
		return '';
	}
}

?>
