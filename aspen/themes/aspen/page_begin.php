<?php global $wp_query;
$pageID = $wp_query->post->ID;
$topPageID = getTopParentPostID($pageID);
$topPageTitle = getTopParentPostTitle($pageID);	
$topPageLink = get_page_link($topPageID);
wp_reset_query();

if(has_post_thumbnail($topPageID)) {	
	$postThumbnail = wp_get_attachment_image_src(get_post_thumbnail_id($topPageID), "full");
	define("USE_POSTTHUMBNAIL", $postThumbnail[0]);
}
?>
<?php get_header(); ?>
<div id="main-content">	
	<!-- START subnav -->
	<div>
		<a name="pstart"></a> 
		<div id="sub-title"><h2><?php echo $topPageTitle;?></h2></div>
		<div id="back-to-home"><a href="<?php bloginfo('url'); ?>" title="<?php bloginfo('name'); ?>">back to home</a><?php $children = get_pages('child_of='.$pageID);
		if( count( $children ) == 0 ) {?>
		 &gt; <a href="<?php echo $topPageLink;?>">back to <?php echo $topPageTitle;?> home</a>
		<?php }?></div>
		<ul id="page-subnav">
			<?php 
		  	wp_list_pages(array(
		  		'child_of'=>$topPageID,
		  		'sort_column'=>'menu_order',
		  		'title_li'=>NULL
		  	));
		  	?>
		</ul>
	</div><!-- END subnav -->
	
	<!-- START entry-content -->
	<div class="entry-content">