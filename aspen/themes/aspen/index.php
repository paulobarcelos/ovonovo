<?php get_header(); ?>
<div id="main-content">
<?php if (is_home()){?>
	<?php 
  	$pages = get_pages('parent=0&sort_column=post_date&sort_order=asc'); 
  	$maxPages = 2;
  	$curPage = 0;
  	foreach ($pages as $page) { ?>
  	<div id="column" class="grid-6col2">
  	<?php if($curPage < $maxPages){
  			$pageTitle = $page->post_title;
  			$pageExcerpt = $page->post_excerpt;	
  			$pageLink =  get_page_link($page->ID); ?>
  		<h2><?php echo $pageTitle;?></h2>
  		<p><?php echo $pageExcerpt;?></p>
  		<p><a href="<?php echo $pageLink;?>" alt="Learn more about <?php echo $pageTitle;?>…">Learn more about <?php echo $pageTitle;?>…</a></p>			
	<?php }?>
	</div> <!-- END column -->
	<?php $curPage ++;
 	 }?>

<?php } else {?>
	<div id="column" class="grid-6col2">
		<h2>Not Found</h2>
		<p>Sorry, but you are looking for something that isn't here.</p>
	</div> <!-- END column -->
<?php }?>
</div><!-- END main content -->
<?php get_footer(); ?>


