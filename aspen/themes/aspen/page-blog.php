<?php
/**
 * @package WordPress
 * @subpackage Aspen_Theme
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
		<div id="back-to-home"><a href="<?php bloginfo('url'); ?>" title="<?php bloginfo('name'); ?>">back to home</a>
		<?php $children = get_pages('child_of='.$pageID);
		if( count( $children ) != 0 ) {?>
		 &gt; <a href="<?php echo $topPageLink;?>">back to <?php echo $topPageTitle;?> home</a>
		<?php }?></div>
		
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
	
	<?php $orig = $wp_query->query; $orig[pagename] = null; //Preserve the original query for the lower half of the page
	if (empty($orig[paged])) { //We only want to display the introductory text on the first page of posts
	  $blog_args = array(
	  				'page_id'  => '551',
	  				);
	  $blog_query = new WP_Query($blog_args);
  	  while ($blog_query->have_posts()) : $blog_query->the_post(); ?>     
    
      <?php the_content(); ?>
	  <?php endwhile; ?>
	  	  
	  <?php } ?>

  
      <?php
      //Restore the original query
      query_posts($orig); ?>
      <?php if (have_posts()) : ?>
		<?php while (have_posts()) : the_post(); // the loop ?>	

			<div class="postDiv" id="post-<?php the_ID(); ?>">
				<h4><a href="<?php the_permalink() ?>" rel="bookmark" title="<?php printf(__('Permanent Link to %s', ' '), the_title_attribute('echo=0')); ?>"><?php the_title(); ?></a></h4>
				<div class="dateDiv"><?php the_time(__('F j, Y', ' ')) ?>, <?php the_author_meta('first_name'); ?>, <em><?php the_author_meta('last_name'); ?></em></div>
				<?php the_excerpt(); ?>

				<div class="catDiv">Posted in: <?php printf(get_the_category_list(', ')); ?>  <?php edit_post_link(__('Edit', ' '), '| ', ' '); ?></div>
			</div>
			   

		<?php endwhile; ?>
		<div class="postNavigation">
			<div class="alignleft"><?php next_posts_link(__('&laquo; Older Entries', ' ')) ?></div>
			<div class="alignright"><?php previous_posts_link(__('Newer Entries &raquo;', ' ')) ?></div>
		</div>	
		<?php endif; ?>	
       
        
        <div class="clear"></div>
        
      </div>     
	  </div><!-- END entry-content -->	
	  
	</div><!-- END main content -->

<?php get_footer(); ?>
