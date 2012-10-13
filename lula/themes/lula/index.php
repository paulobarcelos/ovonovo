<?php define("USE_JQUERY", 1);?>
<?php define("USE_SUPERSIZED", 1);?>
<?php get_header(); ?>
<?php
$page = get_page_by_title('Slideshow');
$pageID = $page->ID;
					
$slideshowFields = simple_fields_get_post_group_values($pageID,"Slideshow", true, 1);
$images = $slideshowFields["Image (1600px by 1067px)"];
$titles = $slideshowFields["Title"];
$descriptions = $slideshowFields["Description"];
$totalSlides = count($images);
?>
<script type="text/javascript">			
			jQuery(function($){
				$.supersized({				
					//Functionality
					slideshow               :   1,		//Slideshow on/off
					autoplay				:	0,		//Slideshow starts playing automatically
					start_slide             :   1,		//Start slide
					slide_interval          :   5000,	//Length between transitions
					transition              :   6, 		//0-None, 1-Fade, 2-Slide Top, 3-Slide Right, 4-Slide Bottom, 5-Slide Left, 6-Carousel Right, 7-Carousel Left
					transition_speed		:	1000,	//Speed of transition
					new_window				:	1,		//Image links open in new window/tab
					pause_hover             :   0,		//Pause slideshow on hover
					keyboard_nav            :   1,		//Keyboard navigation on/off
					performance				:	3,		//0-Normal, 1-Hybrid speed/quality, 2-Optimizes image quality, 3-Optimizes transition speed // (Only works for Firefox/IE, not Webkit)

					//Size & Position
					min_width		        :   100,		//Min width allowed (in pixels)
					min_height		        :   100,		//Min height allowed (in pixels)
					vertical_center         :   0,		//Vertically center background
					horizontal_center       :   1,		//Horizontally center background
					fit_portrait         	:   0,		//Portrait images will not exceed browser height
					fit_landscape			:   0,		//Landscape images will not exceed browser width
					
					//Components
					navigation              :   1,		//Slideshow controls on/off
					thumbnail_navigation    :   0,		//Thumbnail navigation
					slide_counter           :   0,		//Display slide numbers
					slide_captions          :   1,		//Slide caption (Pull from "title" in slides array)
					slides 					:  	[		//Slideshow Images
														<?php
														for($i = 0; $i < $totalSlides; $i++){
															$imageURL = wp_get_attachment_image_src($images[$i], "full");
															$imageURL = $imageURL[0];
															echo "{image:'";
															echo $imageURL;
															echo "', title:'";
															echo '<span class="uppercase">';
															echo $titles[$i];
															echo "</span>";
															echo "<br/>";
															echo $descriptions[$i];
															echo "'}";
															if ($i < ($totalSlides-1)) {
																echo ",";
															}
														} ?>  
												]												
				}); 
		    });
</script>
<!--Control Bar-->
	<div id="controls-wrapper">
		<div id="controls">
		
			<!--Slide captions displayed here-->					
			<div id="showtitle">
				<div id="white-line"></div>
			<p class="title" id="slidecaption"></p>				
			</div>	
			<!--Navigation-->
			<div id="navigation">
				<a href="#" id="prevslide"></a>
				<a href="#" id="nextslide"></a>
			</div>			
		</div>
	</div>
<?php get_footer(); ?>