<?php
/**
 * The template for displaying Archive pages.
 *
 * Used to display archive-type pages if nothing more specific matches a query.
 * For example, puts together date-based pages if no date.php file exists.
 *
 * Learn more: http://codex.wordpress.org/Template_Hierarchy
 *
 * @package WordPress
 * @subpackage Birko Theme
 * @since Birko Theme 1.0
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
	
      <?php if (have_posts()) : ?>
      
        <h3 class="team-head"><?php printf(__('Archive for the &#8216;%s&#8217; Category', ' '), single_cat_title('', false)); ?></h3>
	
		<?php while (have_posts()) : the_post(); // the loop ?>	

			<div class="postDiv" id="post-<?php the_ID(); ?>">
				<h4><a href="<?php the_permalink() ?>" rel="bookmark" title="<?php printf(__('Permanent Link to %s', ' '), the_title_attribute('echo=0')); ?>"><?php the_title(); ?></a></h4>
				<div class="dateDiv"><?php the_time(__('F j, Y', ' ')) ?>, <?php the_author_meta('first_name'); ?>, <em><?php the_author_meta('last_name'); ?></em></div>
          
				<img src="<?php bloginfo('template_directory') ?>/images/authors/<?php the_author_ID()?>.jpg" alt="<?php the_author(); ?>" title="<?php the_author(); ?>" class="inset" />
				<?php the_excerpt(); ?>


				<div class="catDiv">Posted in: <?php printf(get_the_category_list(', ')); ?>  <?php edit_post_link(__('Edit', ' '), '| ', ' '); ?> | <?php comments_popup_link(__('No Comments', ' '), __('Comment (1)', ' '), __('Comments (%)', ' '), '', __('Comments Closed', ' ') ); ?></div>
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