<?php
/*
Template Name: Quote List
*/
?>

<?php include("page_begin.php");?>

<?php
$simpleFields = simple_fields_get_post_group_values($pageID,"Quotes", false, 1);

$authors = $simpleFields[1];
$quotes = $simpleFields[2];
			
$totalEntries = count($authors);
?>
<?php for($i = 0; $i < $totalEntries; $i++){?>
<div id="column" class="grid-8col, client-quote">
	<p class="client-quote-text"><?php echo $quotes[$i]; ?></p><p><em class="client-author"><?php echo $authors[$i]; ?></em></p>
</div>
<?php }?>
		
<?php include("page_end.php");?>
