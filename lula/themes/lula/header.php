<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>

	<head>

		<meta http-equiv="Content-Type" content="<?php bloginfo('html_type'); ?>; charset=<?php bloginfo('charset'); ?>" />

		<link rel="icon" href="<?php bloginfo('stylesheet_directory');?>/images/favicon.png" />	

		<?php 

		if (is_home()) {

			$page = get_page_by_title('Slideshow');

			$pageID = $page->ID;

					

			$simpleFields = simple_fields_get_post_group_values($pageID,"SEO", true, 1);

			$metaDescription = htmlentities($simpleFields["Description (160 characters)"][0]);		

		} elseif (is_single()) {	

			the_post();

			$excerpt =  get_the_excerpt();

			if ($excerpt != ""){

				$metaDescription = htmlentities($excerpt);

			}

		} elseif (is_page()){

			the_post();

			$simpleFields = simple_fields_get_post_group_values(get_the_ID(),"SEO", true, 1);

			$metaDescription = htmlentities($simpleFields["Description (160 characters)"][0]);		

		} else {

			$hideDescription = 1;

		}

		?>

		<?php if($hideDescription != 1) {?>

			<meta name="description" content ="<?php echo $metaDescription;?>"/>

		<?php } ?>	

		

		<?php 

		if (is_home()) {

			$title = get_bloginfo ('description');

		} elseif (is_single() || is_page()) {

			$title = get_the_title ();

		} else {

			$title = "Not Found";

		}

		?>

		<?php if (is_home()) { ?>

		<title><?php bloginfo('name'); ?> - <?php echo $title;?></title>

		<?php } else {?>

		<title><?php echo $title;?> - <?php bloginfo('name'); ?></title>

		<?php } ?>

		

		

		<link rel="stylesheet" href="<?php bloginfo('stylesheet_url'); ?>" type="text/css" media="screen" />

		<link rel="pingback" href="<?php bloginfo('pingback_url'); ?>" />

		

		<!-- Load Fonts -->

		<link href='http://fonts.googleapis.com/css?family=Droid+Serif:regular,italic,bold,bolditalic&subset=latin' rel='stylesheet' type='text/css'>

		<link href='http://fonts.googleapis.com/css?family=Droid+Sans+Mono' rel='stylesheet' type='text/css'>

		

		<!-- Load JS libraries -->

		<?php if(defined('USE_JQUERY')){ ?>

			<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.5/jquery.min.js"></script>

		<?php }?>

		<?php if(defined('USE_SUPERSIZED')){ ?>

			<script type="text/javascript" src="<?php bloginfo('stylesheet_directory'); ?>/js/effects.core.min.js"></script>

			<script type="text/javascript" src="<?php bloginfo('stylesheet_directory'); ?>/js/effects.slide.min.js"></script>

			<script type="text/javascript" src="<?php bloginfo('stylesheet_directory'); ?>/js/supersized.3.1.lula.min.js"></script>

		<?php }?>

		 

		<!-- google analytics -->

		<script type="text/javascript">

		  var _gaq = _gaq || [];

		  _gaq.push(['_setAccount', 'UA-6603360-14']);

		  _gaq.push(['_trackPageview']);

		

		  (function() {

		    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;

		    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';

		    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);

		  })();		

		</script>

		<?php wp_head(); ?>

	</head>

	<body <?php if(is_home()) echo 'style="background: #fff;"' ?>>

	<?php if(!is_home()){ ?>

		<div id="left"></div>

		<div id="right"></div>

		<div id="top"></div>

		<div id="bottom"></div>

	<?php }?>

		<div id="page-wrap" class="group">

			<div id="content">

				<div id="header">

					<a href="<?php bloginfo('url'); ?>" id="logo-other"><h1 class="hidden"><?php bloginfo('name'); ?></h1></a>

					<p class="hidden"><?php bloginfo ('description');?></p>

					<ul id="main-nav">

						<?php

						$aboutPage = get_page_by_title("About");

						$blogPage = get_page_by_title("Blog");

						$contactPage = get_page_by_title("Contact");

						$shoppingPage = get_page_by_title("Shopping");

						$aboutLink = get_permalink($aboutPage->ID);				

						$blogLink = get_permalink($blogPage->ID);

						$contactLink = get_permalink($contactPage->ID);

						$shoppingLink = get_permalink($shoppingPage->ID);

						

						?>

						<?php if(!is_home()){ ?>

						<li><a href="<?php bloginfo('url'); ?>" id="button-back"><span class="hidden">Home</span></a></li>

						<li><a href="<?php echo $aboutLink; ?>" id="button-about" <?php if(get_the_title() == "About") echo 'class="current"'; ?>><span class="hidden">About</span></a></li>

						<li><a href="<?php echo $blogLink; ?>" id="button-blog" <?php if(get_the_title() == "Blog" || is_single()) echo 'class="current"'; ?>><span class="hidden">Blog</span></a></li>

						<li><a href="<?php echo $contactLink; ?>" id="button-contact" <?php if(get_the_title() == "Contact") echo 'class="current"'; ?>><span class="hidden">Contact</span></a></li>

						<li><a href="<?php echo $shoppingLink; ?>" id="button-shopping" <?php if(get_the_title() == "Shopping") echo 'class="current"'; ?>><span class="hidden">Shopping</span></a></li>

						<li><a href="<?php bloginfo('url'); ?>" id="button-home"><span class="hidden">Home</span></a></li>

						<?php } else {?>

						<li><a href="<?php echo $aboutLink; ?>" id="button-menu"><span class="hidden">About</span></a></li>

						<?php }?>

					</ul>

					<h1 class="hidden"><?php the_title() ?></h1>

				</div><!-- END header -->