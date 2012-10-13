<?php define("USE_JQUERY", 1);?>
<?php define("USE_JQUERY_UI", 1);?>
<?php define("USE_DOJO", 1);?>
<?php get_header(); ?>
<!-- toggle script -->	
<script>
	$(document).ready(function(){
		//Hide (Collapse) the toggle containers on load
		$(".toggle_container").hide(); 
		//Switch the "Open" and "Close" state per click then slide up/down (depending on open/close state)
		$("h2.trigger").click(function(){
			$(this).toggleClass("active").next().slideToggle(250);
			return false; //Prevent the browser jump to the link anchor
		});
		//Open the first tab on load
		$("h2.tab0").toggleClass("active").next().slideToggle(250);
	});
</script>

<div id="main-content">
<?php if (is_home()){?>

<?php $loop = new WP_Query( array( 'post_type' => 'contents' ) ); ?>
<?php $tabCount = 0;?>
<?php while ( $loop->have_posts() ) : $loop->the_post(); ?>
<h2 class="trigger <?php echo 'tab'.$tabCount; ?>"><a href="#"><?php the_title(); ?></a></h2>
	<div class="toggle_container">
		<div class="block">
			<div class="red-line"></div>
			<?php 
			$layoutField = simple_fields_get_post_group_values(get_the_ID(),"Layout", true, 1);			
			$layout = $layoutField["Type (Needs to match Simple Fields)"][0];			
			?>
			
			<?php if ($layout == "Paragraph List + Topic List") { ?>		
				<?php 
				$paragraphListField = simple_fields_get_post_group_values(get_the_ID(),"Paragraph List", true, 1);			
				$titles = $paragraphListField["Title"];	
				$descriptions = $paragraphListField["Description"];
				$totalEntries = count($titles);
				?>
				<div class="left-column">
				<?php for($i = 0; $i < $totalEntries; $i++){ ?>
					<h3><?php echo $titles[$i]; ?></h3>
					<p><?php echo $descriptions[$i]; ?></p>
				<?php } ?>
				</div>
				<?php 
				$topicListTitleField = simple_fields_get_post_group_values(get_the_ID(),"Topic List Title", true, 1);			
				$title = $topicListTitleField["Title"][0];
				
				$topicListFields = simple_fields_get_post_group_values(get_the_ID(),"Topic List", true, 1);			
				$topics = $topicListFields["Topic"];
				$totalTopics = count($topics);			
				?>
				<div class="right-column">
					<h3><?php echo $title; ?></h3>
					<ul>
					<?php for($i = 0; $i < $totalTopics; $i++){ ?>					
						<li><?php echo $topics[$i]; ?></li>
					<?php } ?>
					</ul>
				</div>
			<?php } elseif ($layout == "Client List") { ?>		
				<?php 
				$clientsFields = simple_fields_get_post_group_values(get_the_ID(),"Client List", true, 1);			
				$names = $clientsFields["Name"];	
				$descriptions = $clientsFields["Description"];
				$totalClients = count ($names);
				?>
				<?php for($i = 0; $i < $totalClients; $i++){ ?>
				<div class="client">
					<h4><?php echo $names[$i]; ?></h4>
				</div>
				<div class="client-description">
					<p><?php echo $descriptions[$i]; ?></p>
				</div>
				<?php } ?>
			<?php } elseif ($layout == "Quote List") { ?>		
				<?php 
				$quotesFields = simple_fields_get_post_group_values(get_the_ID(),"Quote List", true, 1);			
				$quotes = $quotesFields["Quote"];	
				$authors = $quotesFields["Author"];
				$totalQuotes = count ($quotes);
				?>
				<?php for($i = 0; $i < $totalQuotes; $i++){ ?>
				<div class="one-column">
					<p>"<?php echo $quotes[$i]; ?>"</p></br>
					<p class="author">&mdash; <?php echo $authors[$i]; ?></p>
				</div>
				<?php if($i < ($totalQuotes-1)){ ?>	
					<div class="red-line-dotted"></div>	
				<?php } ?>		
				<?php } ?>
			<?php } elseif ($layout == "Image + Text") { ?>
				<?php 
				$simpleFields = simple_fields_get_post_group_values(get_the_ID(),"Image + Text", true, 1);			
				$image = $simpleFields["Image"][0];	
				$leftColumn = $simpleFields["Left Column"][0];
				$rightColumn = $simpleFields["Right Column"][0];
				?>
				<div class="left-column">
				<?php if($image != 0){?>
					<?php $img = wp_get_attachment_image_src($image, "medium"); ?>
					<img class="bio-image" src="<?php echo $img[0];?>" width=<?php echo $img[1];?> height=<?php echo $img[2];?> alt="<?php bloginfo('name'); ?>"/>
				<?php }?>
					<p><?php echo $leftColumn; ?></p>
				</div>
				<div class="right-column">
					<p><?php echo $rightColumn; ?></p>
				</div>

			<?php } elseif ($layout == "Paragraph List (2 columns)") { ?>
				<?php 
				$paragraphListLeftField = simple_fields_get_post_group_values(get_the_ID(),"Paragraph List (left column)", true, 1);			
				$titlesLeft = $paragraphListLeftField["Title"];	
				$descriptionsLeft = $paragraphListLeftField["Description"];
				$totalLeftEntries = count($titlesLeft);
				
				$paragraphListRightField = simple_fields_get_post_group_values(get_the_ID(),"Paragraph List (right column)", true, 1);			
				$titlesRight = $paragraphListRightField["Title"];	
				$descriptionsRight = $paragraphListRightField["Description"];
				$totalRightEntries = count($titlesRight);
				?>
				<div class="left-column">
				<?php for($i = 0; $i < $totalLeftEntries; $i++){ ?>
					<h3><?php echo $titlesLeft[$i]; ?></h3>
					<p><?php echo $descriptionsLeft[$i]; ?></p>
				<?php } ?>
				</div>
				<div class="right-column">
				<?php for($i = 0; $i < $totalRightEntries; $i++){ ?>
					<h3><?php echo $titlesRight[$i]; ?></h3>
					<p><?php echo $descriptionsRight[$i]; ?></p>
				<?php } ?>
				</div>			
			<?php } elseif ($layout == "Image List") {?>
				<?php 
				$imagesFields = simple_fields_get_post_group_values(get_the_ID(),"Image List", true, 1);			
				$images = $imagesFields["Image"];	
				$captions = $imagesFields["Caption"];
				$totalImages = count ($images);
				$imageIndex = 0;
				?>
				<?php for($i = 0; $i < $totalImages; $i++){ ?>
					<?php if($imageIndex == 0){ ?>	
						<div class="image-item-left">	
					<?php } else { ?>
						<div class="image-item-other">
					<?php }?>
						<div id="image-item">
							<?php $img = wp_get_attachment_image_src($images[$i], "full"); ?>
							<img src="<?php echo $img[0];?>" width=<?php echo $img[1];?> height=<?php echo $img[2];?> alt="<?php bloginfo('name'); ?>"/>
						</div>
						<div class="caption">
							<p><?php echo $captions[$i]; ?></p>
						</div>
					</div>
					<?php $imageIndex++; ?>
					<?php if($imageIndex == 3) { $imageIndex = 0; } ?>	
				<?php } ?>
				
			<?php } ?>
			
			<div class="red-line"></div>
		</div>
	</div><!--END toggle_container-->
<?php $tabCount++; ?>
<?php endwhile; ?>
	<?php } else {?>
	<h2 class="trigger"><a href="#">Not Found</a></h2>
	<div class="toggle_container">
		<div class="block">
			<div class="red-line"></div>
			<div class="left-column">
				<p>Sorry, but you are looking for something that isn't here.</p>
			</div>
			<div class="red-line"></div>
		</div>
	</div><!--END toggle_container-->	
<?php }?>
</div><!-- END main-content -->
<?php get_footer(); ?>