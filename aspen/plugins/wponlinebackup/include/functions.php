<?php

/*
WPOnlineBackup_Functions - Misc static functions
CRC32_Combine - Allows us to process data in small chunks and not have to run crc32() on a huge file which could take a long time
Int_To_Word - Writes a number to a file in binary
Int_To_Dword - Writes a number to a file in binary
*/

/*
There is a code snippet posted by petteri at qred dot fi at http://www.php.net/manual/en/function.crc32.php#76080 with a
correction posted by chernyshevsky at hotmail dot com at http://uk2.php.net/manual/en/function.crc32.php#100062. The code snippet
is a port from ZLIB code (crc32_combine specifically.)

----------------------------------------------------------------------------------------------------
Following is the licence for ZLIB which crc32_combine was ported from
----------------------------------------------------------------------------------------------------

  zlib.h -- interface of the 'zlib' general purpose compression library
  version 1.2.5, April 19th, 2010

  Copyright (C) 1995-2010 Jean-loup Gailly and Mark Adler

  This software is provided 'as-is', without any express or implied
  warranty.  In no event will the authors be held liable for any damages
  arising from the use of this software.

  Permission is granted to anyone to use this software for any purpose,
  including commercial applications, and to alter it and redistribute it
  freely, subject to the following restrictions:

  1. The origin of this software must not be misrepresented; you must not
     claim that you wrote the original software. If you use this software
     in a product, an acknowledgment in the product documentation would be
     appreciated but is not required.
  2. Altered source versions must be plainly marked as such, and must not be
     misrepresented as being the original software.
  3. This notice may not be removed or altered from any source distribution.

  Jean-loup Gailly        Mark Adler
  jloup@gzip.org          madler@alumni.caltech.edu


  The data format used by the zlib library is described by RFCs (Request for
  Comments) 1950 to 1952 in the files http://www.ietf.org/rfc/rfc1950.txt
  (zlib format), rfc1951.txt (deflate format) and rfc1952.txt (gzip format).

----------------------------------------------------------------------------------------------------

 * crc32.c -- compute the CRC-32 of a data stream
 * Copyright (C) 1995-2006, 2010 Mark Adler
 * For conditions of distribution and use, see copyright notice in zlib.h
 *
 * Thanks to Rodney Brown <rbrown64@csc.com.au> for his contribution of faster
 * CRC methods: exclusive-oring 32 bits of data at a time, and pre-computing
 * tables for updating the shift register in one step with three exclusive-ors
 * instead of four steps with four exclusive-ors.  This results in about a
 * factor of two increase in speed on a Power PC G4 (PPC7455) using gcc -O3.
*/
/*
There is also a code snippet from ZipStream library, specifially DOS_Time and Pack_Fields.

Contains derivations from ZipStream by Paul Duncan with minor improvements and additions

In general this allows us to write a ZIP file within any stream that we can fwrite to, and it means we can stream data
to the zip file as well, as opposed to having to write to a temporary file and then compress and then encrypt

Issue with ZipStream however is that for large file compression it loops and gzdeflates in blocks. This however does not
work as they are separate streams, and to join two streams together is very difficult due to incorrect alignment of bits.

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

// Get available algorithms if we have hash_algos
if ( function_exists( 'hash_algos' ) ) {
	$WPOnlineBackup_Functions_Have_SHA256 = in_array( 'sha256', hash_algos() );
} else {
	$WPOnlineBackup_Functions_Have_SHA256 = false;
}

// Are we missing sha256?
if ( !function_exists( 'sha256' ) ) {

	// Missing sha256 - see if we have a hash function that supports it
	if ( function_exists( 'hash' ) && $WPOnlineBackup_Functions_Have_SHA256 ) {

		// We have hash supporting SHA256, define the sha256 function using it
		function sha256( $data )
		{
			return hash( 'sha256', $data );
		}

	} else {

		// OK, no hash or it doesn't have sha256 - import our own code
		require_once WPONLINEBACKUP_PATH . '/include/sha256.php';

	}

}

// Are we missing a hash_hmac that supports SHA256?
if ( function_exists( 'hash_hmac' ) && $WPOnlineBackup_Functions_Have_SHA256 ) {

	// We have hash_hmac supporting SHA256, define our own function using it
	// We're defining our own function so we can still define if hash_hmac already exists but doesn't support sha256
	function WPOnlineBackup_hash_hmac( $algo, $data, $key, $raw_output = false )
	{
		return hash_hmac( $algo, $data, $key, $raw_output );
	}

} else {

	// No hash_hmac or it doesn't support SHA256, so let us define our own that can use any function as the algorithm
	function WPOnlineBackup_hash_hmac( $algo, $data, $key, $raw_output = false )
	{
		$size = strlen( $algo( 'test' ) );
		$pack = 'H' . $size;
		$opad = str_repeat( chr( 0x5C ), $size );
		$ipad = str_repeat( chr( 0x36 ), $size );

		if ( strlen( $key ) > $size ) {
			$key = str_pad( pack( $pack, $algo( $key ) ), $size, chr( 0x00 ) );
		} else {
			$key = str_pad( $key, $size, chr( 0x00 ) );
		}

		for ( $i = 0; $i < strlen( $key ) - 1; $i++ ) {
			$opad[$i] = $opad[$i] ^ $key[$i];
			$ipad[$i] = $ipad[$i] ^ $key[$i];
		}

		$output = $algo( $opad . pack( $pack, $algo( $ipad . $data ) ) );

		return $raw_output ? pack( $pack, $output ) : $output;
	}

}

class WPOnlineBackup_Functions
{
// Convert a UNIX timestamp to a DOS timestamp.
	/*private static*/ function DOS_Time( $time )
	{
// get date array for timestamp
		$d = getdate( $time );

// set lower-bound on dates
		if ( $d['year'] < 1980 ) $d = array( 'year' => 1980, 'mon' => 1, 'mday' => 1, 'hours' => 0, 'minutes' => 0, 'seconds' => 0 );

// remove extra years from 1980
		$d['year'] -= 1980;

// return date string
		return ( $d['year'] << 25 ) | ( $d['mon'] << 21 ) | ( $d['mday'] << 16 ) | ( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 );
	}

// Create a format string and argument list for pack(), then call
// pack() and return the result.
	/*private static*/ function Pack_Fields( $fields )
	{
		list ( $fmt, $args ) = array( '', array() );
		
// populate format string and argument list
		foreach ( $fields as $field ) {
			$fmt .= $field[0];
			$args[] = $field[1];
		}
		
// prepend format string to argument list
		array_unshift( $args, $fmt );
		
// build output string from header and compressed data
		return call_user_func_array( 'pack', $args );
	}

// posted by petteri at qred dot fi
// http://www.php.net/manual/en/function.crc32.php#76080
	/*private static*/ function Combine_CRC32( $crc1, $crc2, $len2 )
	{
		$odd[0] = 0xedb88320;
		$row = 0x1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[$n] = $row;
			$row <<= 0x1;
		}
		WPOnlineBackup_Functions::GF2_Matrix_Square( $even, $odd );
		WPOnlineBackup_Functions::GF2_Matrix_Square( $odd, $even );
		do {
// apply zeros operator for this bit of len2
			WPOnlineBackup_Functions::GF2_Matrix_Square( $even, $odd );
			if ( $len2 & 0x1 ) $crc1 = WPOnlineBackup_Functions::GF2_Matrix_Times( $even, $crc1 );
			$len2 >>= 0x1;
// if no more bits set, then done
			if ( $len2 == 0x0 ) break;
// another iteration of the loop with odd and even swapped
			WPOnlineBackup_Functions::GF2_Matrix_Square( $odd, $even );
			if ( $len2 & 0x1 ) $crc1 = WPOnlineBackup_Functions::GF2_Matrix_Times( $odd, $crc1 );
			$len2 >>= 0x1;
		} while ( $len2 != 0x0 );
		$crc1 ^= $crc2;
		return $crc1;
	}
	
	/*private static*/ function GF2_Matrix_Square( & $square, & $mat ) {
		for ( $n = 0; $n < 32; $n++ ) $square[$n] = WPOnlineBackup_Functions::GF2_Matrix_Times( $mat, $mat[$n] );
	}
	
	/*private static*/ function GF2_Matrix_Times( $mat, $vec ) {
		$sum = 0;
		$i = 0;
		while ( $vec ) {
			if ( $vec & 0x1 ) $sum ^= $mat[$i];
// correction posted by chernyshevsky at hotmail dot com
// http://uk2.php.net/manual/en/function.crc32.php#100062
// If $vec is signed and shifted right PHP will pad it using the sign bit -
//	the "& 0x7FFFFFFF" forces the padding to be 0
			$vec = ( $vec >> 1 ) & 0x7FFFFFFF;
			$i++;
		}
		return $sum;
	}

	/*public static*/ function PBKDF2( $p, $s, $c, $kl )
	{
		return WPOnlineBackup_Functions::PBKDF2_Internal( $p, $s, $c, $kl, 'sha256' );
	}

	/*private static*/ function PBKDF2_Internal( $p, $s, $c, $kl, $a )
	{
		$hl = strlen( $a( 'test' ) ) / 2;
		$kb = ( $kl + ( $hl - ( $kl % $hl ) ) ) / $hl;
		$dk = '';

		for ( $bn = 1; $bn <= $kb; $bn ++ ) {

			$ib = $b = WPOnlineBackup_hash_hmac( $a, $s . pack( 'N', $bn ), $p, true );

			for ( $i = 1; $i < $c; $i ++ ) $ib ^= ( $b = WPOnlineBackup_hash_hmac( $a, $b, $p, true ) );

			$dk .= $ib;

		}

		return substr( $dk, 0, $kl );
	}

	/*public static*/ function PBKDF2_Broken( $p, $s, $c, $kl )
	{
		$hl = strlen( sha256( 'test' ) ) / 2;
		$kb = ( $kl + ( $hl - ( $kl % $hl ) ) ) / $hl;
		$dk = '';

		for ( $bn = 1; $bn <= $kb; $bn ++ ) {

			$ib = $b = WPOnlineBackup_Functions::hash_hmac_Broken( $s . pack( 'N', $bn ), $p, true );

			for ( $i = 1; $i < $c; $i ++ ) $ib ^= ( $b = WPOnlineBackup_Functions::hash_hmac_Broken( $b, $p, true ) );

			$dk .= $ib;

		}

		return substr( $dk, 0, $kl );
	}

	/*private static*/ function hash_hmac_Broken( $data, $key, $raw_output = false )
	{
		$size = strlen( sha256( 'test' ) );
		$pack = 'H' . $size;
		$size /= 2;
		$opad = str_repeat( chr( 0x5C ), $size );
		$ipad = str_repeat( chr( 0x36 ), $size );

		if ( strlen( $key ) > $size ) {
			$key = str_pad( pack( $pack, sha256( $key ) ), $size, chr( 0x00 ) );
		} else {
			$key = str_pad( $key, $size, chr( 0x00 ) );
		}

		for ( $i = 0; $i < strlen( $key ) - 1; $i++ ) {
			$opad[$i] = $opad[$i] ^ $key[$i];
			$ipad[$i] = $ipad[$i] ^ $key[$i];
		}

		$output = sha256( $opad . pack( $pack, sha256( $ipad . $data ) ) );

		return $raw_output ? pack( $pack, $output ) : $output;
	}
}

?>
