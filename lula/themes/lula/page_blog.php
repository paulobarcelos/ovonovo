<?php
/*
Template Name: Blog Template
*/
?>
<?php include("page_begin.php");?>

<?php
$bigTypeField = simple_fields_get_post_group_values(get_the_ID(),"Big Type", true, 1);
$bigTypes = $bigTypeField["File"];
$randomBigType = $bigTypes[array_rand($bigTypes,1)];
$randomBigTypeImage = wp_get_attachment_image_src($randomBigType, "full"); 
?>
<div id="blog-content">
	<div id="line-container" style="background:url(<?php echo $randomBigTypeImage[0]; ?>) no-repeat;"></div>
	<div id="black-line-1"></div>
	
	<?php $page = (get_query_var('paged')) ? get_query_var('paged') : 1;
	query_posts("showposts=5&paged=$page");
	while ( have_posts() ) : the_post() ?>
	<div class="post">
		<div id="meta">
			<div id="date">
				<p><?php the_time('Y.m.d'); ?></p>
			</div>	
			<div id="author">
				<p>by <?php the_author(); ?></p>
			</div>	
		</div>
		<div id="entry-content">
			<h2><a href="<?php the_permalink() ?>"><?php the_title(); ?></a></h2>		
			<?php 
			$posttags = get_the_tags();
			$totalTags = count($posttags);
			$tagIndex = 0;
			if ($posttags) {?>
			<div id="meta-tags">
				<p>TAGS: 
				 <?php 
				 foreach($posttags as $tag) {
				  	echo "<span class='tag'>";
				    echo $tag->name;
				    echo "</span>";
				    if($tagIndex < ($totalTags-1)) {
				    	echo ", ";
				    }
				    $tagIndex++;
				  }?>
			  	</p>
			 </div>
			<?php }
			?>

			<div id="entry">
				<?php
				$imagesField = simple_fields_get_post_group_values(get_the_ID(),"Related Images", true, 1);
				$images = $imagesField["Image"];
				$totalImages = count($images);
				?>
				<?php the_content(); ?>
				<?php if ($totalImages > 0 && $images[0] != 0){ ?>
					<?php for($i = 0; $i < $totalImages; $i++){?>				
					<?php $image = wp_get_attachment_image_src($images[$i], "medium"); ?> 
						<div id="blog-image"><img src="<?php echo $image[0];?>" width=<?php echo $image[1];?> height=<?php echo $image[2];?> alt="<?php the_title(); ?>"/></div>
					<?php }?>
				<?php }?>
			</div>
		</div>
	</div>
	<div id="black-line-2"></div>		
	<?php endwhile ?>
	<div id="blog-nav">
		<div id="older-posts"><?php next_posts_link('Older Posts &gt;') ?></div>
		<div id="newer-posts"><?php previous_posts_link('&lt; Newer Posts') ?></div>
	</div>
</div>

<?php include("page_end.php");?>