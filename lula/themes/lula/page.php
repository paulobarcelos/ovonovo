<?php
the_post();
$redirectFields = simple_fields_get_post_group_values(get_the_ID(),"301 Redirect", true, 1);
$redirectTo = $redirectFields["Redirect to"][0];
if($redirectTo == "Home"){
	Header( "HTTP/1.1 301 Moved Permanently" ); 
	Header( "Location: ".get_bloginfo('url') );
}

?>
<?php include("page_begin.php");?>
<?php include("page_end.php");?>