<?php
/*
Template Name: Contact Page
*/
?>

<?php include("page_begin.php");?>

<?php
$simpleFields = simple_fields_get_post_group_values($pageID,"Contact Info", false, 1);

$address = $simpleFields[1][0];
$email = $simpleFields[2][0];
$phone = $simpleFields[3][0];
$fax = $simpleFields[4][0];
$facebook = $simpleFields[5][0];
$twitter = $simpleFields[6][0];
$southDirections = $simpleFields[7][0];
$northDirections = $simpleFields[8][0];
?>
<div class="contact">
	<div id="column" class="grid-4col">
		<h3 class="team-head">WHERE WE ARE</h3>
		<p><?php echo $address;?></p>
		<h3 class="team-head">HOW TO REACH US</h3>
		<p>email &gt; <a href="mailto:<?php echo $email;?>"><?php echo $email;?></a>
		<br />phone &gt; <span class="phone"><?php echo $phone;?></span>
		<br />fax &gt; <span class="phone"><?php echo $fax;?></span></p>
		<h3 class="team-head">FOLLOW US, IF YOU'D LIKE</h3>
		<p><a href="<?php echo $facebook;?>">Facebook</a><br /><a href="<?php echo $twitter;?>">Twitter</a></p>
	</div>
	<div id="column" class="grid-4col">
		<h3 class="team-head">DIRECTIONS FROM DENVER (SOUTH)</h3>
		<p><?php echo $southDirections;?></p>
		<h3 class="team-head">DIRECTIONS FROM BOULDER (NORTH)</h3>
		<p><?php echo $northDirections;?></p>
	</div>
</div>

		
<?php include("page_end.php");?>
