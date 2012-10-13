<?php
/*
Template Name: About Template
*/
?>
<?php include("page_begin.php");?>

<?php
$bigTypeField = simple_fields_get_post_group_values(get_the_ID(),"Big Type", true, 1);
$bigTypes = $bigTypeField["File"];
$randomBigType = $bigTypes[array_rand($bigTypes,1)];
$randomBigTypeImage = wp_get_attachment_image_src($randomBigType, "full"); 

$aboutField = simple_fields_get_post_group_values(get_the_ID(),"About Info", true, 1);
$column1 = $aboutField["Column 1"][0];
$column2 = $aboutField["Column 2"][0];
?>
<div id="about-content">
	<div id="line-container" style="background:url(<?php echo $randomBigTypeImage[0]; ?>) no-repeat;"></div>
	<div id="black-line-1"></div>
	<div class="left-column">
		<p class="grid-4col"><?php echo $column1; ?></p>
	</div>	
	<div class="right-column">
		<p class="grid-4col"><?php echo $column2; ?></p>
	</div>	
</div>

<?php include("page_end.php");?>