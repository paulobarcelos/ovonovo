<?php get_header(); ?>
<div id="main-content">

<?php 
$simpleFields = simple_fields_get_post_group_values($post->ID,"Homepage Fields", false, 1); 

$topLeft = $simpleFields[2][0];
$topRight = $simpleFields[1][0];
$botLeft = $simpleFields[4][0];
$botRight = $simpleFields[3][0];
$botLeftUsefulLinks = $simpleFields[5][0]; 
?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
  	<div id="column" class="grid-6col2">
  	  <?php echo $topLeft; ?>
  	</div>
  	
  	<div id="column" class="grid-6col2">
  	  <?php echo $topRight; ?>
  	</div>
  	
  	<div style="clear: both; height: 1px;"></div>
  	
  	
 	
  	<div id="column" class="grid-6col2 blog">
  	  <?php //echo $botRight; ?>
  	  <h2><a href="/blog/">Property Management Blog</a></h2>
  	  <?php 
      $args=array(
   		'post_type'=>'post',
   		'posts_per_page' => 3
	  );
	  $latest_post_query = new WP_Query($args); ?>
	  <?php while ($latest_post_query->have_posts()) : $latest_post_query->the_post(); ?>
	    <p>By <?php the_author_meta('first_name'); ?>, <em><?php the_author_meta('last_name'); ?></em></p>
	    <p><strong><a class="heading" href="<?php echo the_permalink(); ?>"><?php echo the_title(); ?></a></strong><br />
        <?php echo get_the_excerpt(); ?></p>
	  <?php endwhile; ?>   	  
  	</div>

	<div id="column" class="grid-6col2">
  	  <?php echo $botLeft; ?>
  	</div>
	
	<div id="column" class="grid-6col2">
  	  <?php echo $botLeftUsefulLinks; ?>
  	</div>


<?php endwhile; endif; ?>	


</div><!-- END main content -->
<?php get_footer(); ?>


