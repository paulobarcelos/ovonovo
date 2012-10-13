<?php
/*
Template Name: Team Page
*/
?>

<?php include("page_begin.php");?>

<?php
$simpleFields = simple_fields_get_post_group_values($pageID,"Member Info", false, 1);

$names = $simpleFields[1];
$roles = $simpleFields[2];
$emails = $simpleFields[3];
$phones = $simpleFields[4];
$pictures = $simpleFields[5];
$descriptions = $simpleFields[6];
			
$totalEntries = count($names);
?>
<?php for($i = 0; $i < $totalEntries; $i++){?>
<div class="team">
	<?php $image = wp_get_attachment_image_src($pictures[$i], "full"); ?> 
	<div class="team-image"><img src="<?php echo $image[0];?>" width=<?php echo $image[1];?> height=<?php echo $image[2];?> alt="<?php echo $names[$i]; ?>"/></div>
	<div id="column" class="grid-5col">
		<h3 class="team-head"><?php echo $names[$i]; ?>, <?php echo $roles[$i]; ?></h3>
		<p><?php echo $descriptions[$i]; ?></p>
		<h3 class="team-head">Contact <?php echo $names[$i]; ?> directly</h3>
		<p>email &gt; <a href="mailto:<?php echo $emails[$i]; ?>"><?php echo $emails[$i]; ?></a><br />phone &gt; <span class="phone"><?php echo $phones[$i]; ?></span></p>
	</div>
</div>
<?php }?>
		
<?php include("page_end.php");?>
