<?php
/*
Template Name: Contact Template
*/
?>
<?php include("page_begin.php");?>

<?php
$bigTypeField = simple_fields_get_post_group_values(get_the_ID(),"Big Type", true, 1);
$bigTypes = $bigTypeField["File"];
$randomBigType = $bigTypes[array_rand($bigTypes,1)];
$randomBigTypeImage = wp_get_attachment_image_src($randomBigType, "full"); 

$generalFields = simple_fields_get_post_group_values(get_the_ID(),"Contact Info", true, 1);
$general = $generalFields["General"][0];

$socialFields = simple_fields_get_post_group_values(get_the_ID(),"Social Networks", true, 1);
$names = $socialFields["Name"];
$urls = $socialFields["URL"];
$totalSocial = count($names);

$downloadFields = simple_fields_get_post_group_values(get_the_ID(),"Downloads", true, 1);
$files = $downloadFields["File"];
$titles = $downloadFields["Title"];
$totalDownload = count($files);
?>
<div id="contact-content">
	<div id="line-container" style="background:url(<?php echo $randomBigTypeImage[0]; ?>) no-repeat;"></div>
	<div id="black-line-1"></div>
	<div class="contact-paragraph">
		<h1>General</h1>
		<p><?php echo $general; ?></p>
	</div>	
	<div class="contact-paragraph">
		<h1>Download</h1>
		<p>
		<?php for($i = 0; $i < $totalDownload; $i++){?>
		<a href="<?php echo wp_get_attachment_url($files[$i]); ?>"><?php echo $titles[$i]; ?></a><br/>
		<?php } ?>
		</p>
	</div>
	<div class="contact-paragraph">
		<h1>Social</h1>
		<p>
		<?php for($i = 0; $i < $totalSocial; $i++){?>
		<a href="<?php echo $urls[$i]; ?>" target="_blank"><?php echo $names[$i]; ?></a><br/>
		<?php } ?>
		</p>
	</div>
</div>


<?php include("page_end.php");?>