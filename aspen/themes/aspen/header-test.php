<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
	<head profile="http://gmpg.org/xfn/11">
		<meta http-equiv="Content-Type" content="<?php bloginfo('html_type'); ?>; charset=<?php bloginfo('charset'); ?>" />
		<link rel="icon" href="<?php bloginfo('stylesheet_directory');?>/images/favicon.ico" />
		<?php if (is_home()) {?>
			<?php 
			$contactPage = get_page_by_title('How to Reach Us');
			$contactPageID = $contactPage->ID;
					
			$simpleFields = simple_fields_get_post_group_values($contactPageID,"Contact Info", false, 1);
			
			$metaDescription = $simpleFields[9][0];
			?>
			<meta name="description" content ="<?php echo $metaDescription;?>"/>
		<?php }elseif (is_single() || is_page()) { ?>
			<?php if (have_posts()){?>
				<?php while (have_posts()){?>
				 	<?php the_post(); ?>
				 	<?php $excerpt =  htmlentities(get_the_excerpt());?>
				 	<?php if ($excerpt != ""){?>
				 		<meta name="description" content="<?php echo $excerpt; ?>" />
				 	<?php }?>
				 <?php }?>
			<?php } ?>
		<?php } ?>
		
		
		<title><?php 
		$page_title = get_post_meta($post->ID, 'Page Title', true); 
		if (!empty($page_title)) {
			echo $page_title; 
		} else {
			wp_title('');
			if(wp_title('', false)) { echo ' | '; }
			bloginfo('name');
		} ?></title>
		
		<link rel="stylesheet" href="<?php bloginfo('stylesheet_url'); ?>?r=<?php echo time(); ?>" type="text/css" media="screen" />
		<link rel="pingback" href="<?php bloginfo('pingback_url'); ?>" />
		
		<!-- Load Fonts -->
		<link href='http://fonts.googleapis.com/css?family=Droid+Serif:regular,italic,bold,bolditalic&subset=latin' rel='stylesheet' type='text/css'>
		<link href='http://fonts.googleapis.com/css?family=Droid+Sans:regular,bold&subset=latin' rel='stylesheet' type='text/css'>
		
		<!-- Load JS libraries -->
		<?php if(defined('USE_JQUERY')){ ?>
			<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.4.4/jquery.min.js"></script>
		<?php }?>
		<?php if(defined('USE_JQUERY_UI')){?>
			<script src="<?php bloginfo('stylesheet_directory');?>/js/jquery.ui.core.js"></script>
			<script src="<?php bloginfo('stylesheet_directory');?>/js/jquery.ui.widget.js"></script>
			<script src="<?php bloginfo('stylesheet_directory');?>/js/jquery.ui.accordion.js"></script>
		<?php }?>
		<?php if(defined('USE_AUTOCOLUMN')){?>
			<script src="<?php bloginfo('stylesheet_directory');?>/js/autocolumn.js" type="text/javascript" charset="utf-8"></script>
		<?php }?>		

		<?php wp_head(); ?>
		
		<!-- google analytics -->
		<script type="text/javascript">

  var _gaq = _gaq || [];
  _gaq.push(['_setAccount', 'UA-28728256-1']);
  _gaq.push(['_trackPageview']);

  (function() {
    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
  })();

</script>
		
	</head>

	<body>
	<a name="top"></a> 
	<div id="page-wrap" class="group">
		<div id="content">
		<a href="<?php bloginfo('url'); ?>" title="<?php bloginfo('name'); ?>" id="home-link-type"><h1 class="hidden" alt="<?php bloginfo('name');?>"><?php bloginfo('name');?></h1></a>
		<a href="<?php bloginfo('url'); ?>" title="<?php bloginfo('name'); ?>" id="home-link-logo"></a>
		
		<div id="header-one">
			<?php wp_nav_menu( array( 'container' => FALSE, 'theme_location' => 'primary' ) ); ?>
			<div id="top-right"></div>
			<div id="header-angle"></div>
		</div><!-- END header-one -->
		<div id="header-two">
			<a href="<?php bloginfo('url'); ?>" title="<?php bloginfo('name'); ?>" id="home-link-tagline"><?php echo get_bloginfo ('description');?></a>
			<div id="top-left"></div>
		</div><!-- END header-two -->
		<?php 
		$headerImage = get_bloginfo('stylesheet_directory')."/images/header.jpg";
		if(defined('USE_POSTTHUMBNAIL')){
			$headerImage = USE_POSTTHUMBNAIL;
		}
		?>
		<div id="slideshow" style="background: url(<?php echo $headerImage; ?>) no-repeat;"></div>
		<div id="black-line-one"></div>

