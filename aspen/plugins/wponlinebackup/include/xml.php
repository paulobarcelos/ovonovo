<?php

/*
WPOnlineBackup_XML class
Script taken from Troy Wolf [troy@troywolf.com] (who used major sections ripped from Paul Rose.)
Troy Wolf: Modified to replace ":" in object names with "_". This was to support Exchange WebDAV stuff.
Modified slightly for our own use
*/

class WPOnlineBackup_XML
{
	/*private*/ var $log;
	/*private*/ var $data;
	/*private*/ var $parser;
	/*private*/ var $stack;
	/*private*/ var $index;

	/*public*/ function WPOnlineBackup_XML() {
		$this->log = 'New xml() object instantiated.' . PHP_EOL;
	}

	/*public*/ function fetch( $raw_xml, $final = true ) {
		$this->log .= 'fetch() called.' . PHP_EOL;
		$this->log .= 'Raw XML:' . PHP_EOL . $raw_xml . PHP_EOL . PHP_EOL;
		$this->index = 0;
		$this->data = new stdClass();
		$this->stack = array();
		$this->stack[] = & $this->data;
		$this->parser = xml_parser_create( 'UTF-8' );
		xml_set_object( $this->parser, $this );
		xml_set_element_handler( $this->parser, 'tag_open', 'tag_close' );
		xml_set_character_data_handler( $this->parser, 'cdata' );
		xml_parser_set_option( $this->parser, XML_OPTION_TARGET_ENCODING, 'UTF-8' );
		xml_parser_set_option( $this->parser, XML_OPTION_SKIP_WHITE, 1 );
		xml_parser_set_option( $this->parser, XML_OPTION_CASE_FOLDING, false );
		$start = 0;
		$length = strlen( $raw_xml );
		$chunk_size = 32 * 1024 * 1024;
		$ret = true;
		while ( true ) {
			if ( !( $parsed_xml = xml_parse( $this->parser, substr( $raw_xml, $start, $chunk_size ), ( $final = ( $start += $chunk_size ) >= $length ? true : false ) ) ) ) {
				$this->log .= ( $ret = sprintf(
					'XML error: %s at line %d.',
					xml_error_string( xml_get_error_code( $this->parser ) ),
					xml_get_current_line_number( $this->parser )
				) );
				break;
			} else if ( $final ) break;
		}
		xml_parser_free( $this->parser );
		return $ret;
	}

	/*private*/ function tag_open( $parser, $tag, $attrs ) {
		$tag = str_replace( '-', '_', $tag );
		$tag = str_replace( ':', '_', $tag );
		$object = new stdClass();
		$object->_attr = new stdClass();
		foreach( $attrs as $key => $val ) {
			$key = str_replace( '-', '_', $key );
			$key = str_replace( ':', '_', $key );
			$value = $this->clean( $val );
			$object->_attr->$key = $value;
		}
		$temp = & $this->stack[ $this->index ]->$tag;
		$temp[] = & $object;
		$size = sizeof( $temp );
		$this->stack[] = & $temp[ $size - 1 ];
		$this->index++;
	}

	/*private*/ function tag_close( $parser, $tag ) {
		array_pop( $this->stack );
		$this->index--;
	}

	/*private*/ function cdata( $parser, $data ) {
		if( trim( $data ) !== '' ){
			$this->stack[ $this->index ]->_text .= $data;
		}
	}

	/*private*/ function clean( $string ) {
		return trim( $string );
	}
}

?>
