<?php include("page_begin.php");?>

<div id="blog-single-content">
	<div id="back-to-blog">
		<?php $blogPage = get_page_by_title("Blog");?>
		<?php $blogLink = get_permalink($blogPage)?>
		<a href="<?php echo $blogLink ?>">&lt; Back to blog</a>
	</div>
	<div id="black-line-1"></div>	

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
			<h2><?php the_title(); ?></h2>			
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
				<?php $postID = get_the_ID();?>
				<?php $thepost = get_post($postID); ?>
				<?php echo $thepost->post_content; ?>
				<?php
				$imagesField = simple_fields_get_post_group_values(get_the_ID(),"Related Images", true, 1);
				$images = $imagesField["Image"];
				$totalImages = count($images);
				?>
				<?php if($totalImages > 0 && $images[0] != 0){?>
				<?php for($i = 0; $i < $totalImages; $i++){?>
				<?php $image = wp_get_attachment_image_src($images[$i], "medium"); ?> 
					<div id="blog-image"><img src="<?php echo $image[0];?>" width=<?php echo $image[1];?> height=<?php echo $image[2];?> alt="<?php the_title(); ?>"/></div>
				<?php }?>
				<?php }?>
			</div>
		</div>
	</div>
	<div id="black-line-2"></div>
	<div id="blog-nav">
		<div id="older-posts"><?php previous_post_link('%link', 'Previous Post &gt;'); ?></div>
		<div id="newer-posts"><?php next_post_link('%link', '&lt; Next Post'); ?></div>
	</div>
</div>

<?php include("page_end.php");?>