<?php
/**
 * The Template for displaying all single posts.
 *
 * @package WordPress
 * @subpackage Aspen Management Theme
 * @since Aspen Theme 1.0
 */

?>

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
		<div id="sub-title"><h2>Property Management Blog</h2></div>
		<div id="back-to-home"><a href="<?php bloginfo('url'); ?>" title="<?php bloginfo('name'); ?>">back to home</a> &gt; <a href="/blog/">back to blog home</a></div>
		
		<div id="subnav-group">
			<ul id="page-subnav" class="blog">
				<?php wp_get_archives(array('type'=>'postbypost', 'limit'=>'5')); ?>
			</ul>
		
			<h3 class="subnav">Post Archive</h3>
			<ul class="page-subnav archives">
			 	<?php wp_get_archives(array('type'=>'monthly', 'show_post_count'=>1)); ?>
			</ul>
		</div>
		
	</div><!-- END subnav -->
	
	<!-- START entry-content -->
	<div class="entry-content">
	<div class="grid-8col">
	  <?php if (have_posts()) : while (have_posts()) : the_post(); // the loop ?>
	
	  <div class="postDivSingle">
        <h3><?php the_title(); ?></h3>
      	
		<div class="dateDiv"><?php the_time(__('F j, Y', ' ')) ?>, <?php the_author_meta('first_name') ?>, <em><?php the_author_meta('last_name') ?></em></div>
    	
    	<!--<div id="socialshare">
	   		<div id="facebooklike"><fb:like href="<?php the_permalink(); ?>" width="90" layout="button_count" show_faces="false"></fb:like></div>
	   		<div id="tweetme"><a href="http://twitter.com/share" class="twitter-share-button" data-count="horizontal">Tweet</a></div>
	   		<div id="plusone"><g:plusone size="medium"></g:plusone></div>   
	   		<div id="linkedin"><script type="in/share" data-counter="right"></script></div>
	 	</div>-->
    	
    	<div>
		  <?php the_content(); ?>
		</div>
	  
	    <div class="catDiv">Posted in: <?php printf(get_the_category_list(', ')); ?>  <?php edit_post_link(__('Edit', ' '), '| ', ' '); ?></div>	
	  
	    <div class="postNavigation">
		  <div class="alignleft"><?php previous_post_link('&laquo; %link', '%title', FALSE, '23'); ?></div>
		  <div class="alignright"><?php next_post_link('%link &raquo;', '%title', FALSE, '23'); ?></div>
	    </div>
	    
	  </div>

	  <?php //comments_template(); ?>
	

	<?php endwhile; endif; ?>
       
        
    <div class="clear"></div>
    
  </div>     
  </div><!-- END entry-content -->	
	  
</div><!-- END main content -->

<?php get_footer(); ?>