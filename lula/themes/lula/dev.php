<?php 
	if (is_home()) 			echo "is_home()<br/>";
	if (is_front_page()) 	echo "is_front_page()<br/>";
	if (is_404())			echo "is_404()<br/>";
	if (is_search())		echo "is_search()<br/>";
	if (is_date())			echo "is_date()<br/>";
	if (is_author())		echo "is_author()<br/>";
	if (is_tag())			echo "is_tag()<br/>";
	if (is_tax())			echo "is_tax()<br/>";
	if (is_archive())		echo "is_archive()<br/>";
	if (is_single())		echo "is_single()<br/>";
	if (is_attachment())	echo "is_attachment()<br/>";
	if (is_page())			echo "is_page()<br/>";
?>
<?php _e('The loop:<br/>'); ?>
<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php the_title(); ?> (<?php the_permalink(); ?>)<br/>
<?php endwhile; else: ?>
<?php _e('Sorry, no posts matched your criteria.'); ?>
<?php endif; ?>