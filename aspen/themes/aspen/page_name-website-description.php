<?php
/*
Template Name: Simple List (Name, Website and Description)
*/
?>

<?php include("page_begin.php");?>

<?php
$simpleFields = simple_fields_get_post_group_values($pageID,"Name, Link and Description", false, 1);

$names = $simpleFields[1];
$websites = $simpleFields[2];
$websitesURL = $simpleFields[5];
$descriptions = $simpleFields[3];
			
$totalEntries = count($names);
?>
<?php for($i = 0; $i < $totalEntries; $i++){?>
<div class="client">
	<div class="client-name"><p class="grid-2col"><?php echo $names[$i]; ?><br/><a href="<?php echo $websitesURL[$i]; ?>"><?php echo $websites[$i]; ?></a></p></div>
	<div class="client-description"><p class="grid-6col"><?php echo $descriptions[$i]; ?></p></div>
</div>
<?php }?>
		
<?php include("page_end.php");?>
