<?php
/*
Template Name: Accordion List Page
*/
?>

<?php define("USE_JQUERY", 1);?>
<?php define("USE_JQUERY_UI", 1);?>


<?php include("page_begin.php");?>

<!-- accordion script -->	
<script>
	$(document).ready(function() {
		$("#accordion").accordion({
			autoHeight: false,
			navigation: true,
			collapsible: true
		});
		$("#accordion").accordion( "activate" , "false" );
	});
</script>

<?php
$simpleFieldsDescription = simple_fields_get_post_group_values($pageID,"2 Columns Text", false, 1);

$leftColumn = $simpleFieldsDescription[1][0];
$rightColumn = $simpleFieldsDescription[2][0];

$simpleFields = simple_fields_get_post_group_values($pageID,"Name and Description", false, 1);

$names = $simpleFields[1];
$descriptions = $simpleFields[2];
			
$totalEntries = count($names);
?>

<div id="column" class="grid-4col">
	<?php echo $leftColumn; ?>
</div>

<div id="accordion">
<?php for($i = 0; $i < $totalEntries; $i++){?>
	<h3 class="accordion-head"><a href="#"><?php echo $names[$i]; ?></a></h3>
	<div><p><?php echo $descriptions[$i]; ?></p></div>
<?php }?>
</div>

<?php include("page_end.php");?>
