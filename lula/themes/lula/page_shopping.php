<?php

/*

Template Name: Shopping Template

*/

?>

<?php include("page_begin.php");?>



<?php

$bigTypeField = simple_fields_get_post_group_values(get_the_ID(),"Big Type", true, 1);

$bigTypes = $bigTypeField["File"];

$totalTypesIndexes = count($bigTypes)-1;

$randomBigType = $bigTypes[rand(0,$totalTypesIndexes)];

$randomBigTypeImage = wp_get_attachment_image_src($randomBigType, "full"); 



$shopsFields = simple_fields_get_post_group_values(get_the_ID(),"Shops", true, 1);

$names = $shopsFields["Name"];

$websites = $shopsFields["Website"];

$addresses = $shopsFields["Address"];

$total = count($names);

?>

<div id="shopping-content">

	<div id="line-container" style="background:url(<?php echo $randomBigTypeImage[0]; ?>) no-repeat;"></div>

	<div id="black-line-1"></div>

	<?php for($i = 0; $i < $total; $i++){?>

	<div class="shopping-paragraph">

		<h2><a href="<?php echo $websites[$i]; ?>" target="_blank"><?php echo $names[$i]; ?></a></h2>

		<p><?php echo $addresses[$i]; ?></p>

	</div>

	<?php } ?>
	<div id="black-line-2"></div>
</div>




<?php include("page_end.php");?>