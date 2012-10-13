<div id ="footer">
	<div id="bottom-left"></div>
	<div id="bottom-right"></div>
	<div id="black-line-two"></div>
	<?php 
	$contactPage = get_page_by_title('Contact');
	$contactPageID = $contactPage->ID;
			
	$simpleFields = simple_fields_get_post_group_values($contactPageID,"Contact Info", false, 1);
	
	$address = $simpleFields[1][0];
	$email = $simpleFields[2][0];
	$phone = $simpleFields[3][0];
	$fax = $simpleFields[4][0];
	$facebook = $simpleFields[5][0];
	$twitter = $simpleFields[6][0];
	?>
	<div class="column firstColumn grid-4col">
		<h3>Where we are</h3>
		<p><?php echo $address; ?></p>
	</div>
	<div class="column grid-4col">
		<h3>Contact Us for a Quote</h3>
		<p>email ><a href="mailto:<?php echo $email; ?>"> <?php echo $email; ?></a></p>
		<p><span>phone &gt;</span> <span class="phone"><?php echo $phone; ?></span></p>
		<p><span>fax &gt;</span> <span class="phone"><?php echo $fax; ?></span></p>
	</div>
	<div class="column grid-4col">
		<h3>Follow us, if you'd like</h3>
		<p><a href="<?php echo $facebook; ?>">Facebook</a></p>
		<p><a href="<?php echo $twitter; ?>">Twitter</a></p>
		<p><a href="http://feedburner.google.com/fb/a/mailverify?uri=aspen-management-blog&amp;loc=en_US"  target="_blank">RSS feed</a></p>
	</div>
	<div id="copyright">
		&copy;2010 <?php bloginfo('name'); ?> &mdash; <a href="http://brycelicht.com" target="_blank">Site by BRL design</a>
	</div>
	<div id="up-arrow"><a href="#top" id="scroll"></a></div>	
</div><!-- End Footer -->
<?php wp_footer(); ?>
</div><!-- End content -->
</div><!-- End Page warp -->	
</body>
</html>