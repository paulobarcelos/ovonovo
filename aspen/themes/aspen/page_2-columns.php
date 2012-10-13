<?php
/*
Template Name: 2 Text Columns
*/
?>
<?php include("page_begin.php");?>

<?php
$simpleFieldsDescription = simple_fields_get_post_group_values($pageID,"2 Columns Text", false, 1);

$leftColumn = $simpleFieldsDescription[1][0];
$rightColumn = $simpleFieldsDescription[2][0];
?>

<div id="column" class="grid-4col">			
	<?php echo $leftColumn; ?>
</div>
<div id="column" class="grid-4col">			
	<?php echo $rightColumn; ?>
</div>

<?php include("page_end.php");?>
