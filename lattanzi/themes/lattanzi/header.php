<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>

	<head profile="http://gmpg.org/xfn/11">

		<meta http-equiv="Content-Type" content="<?php bloginfo('html_type'); ?>; charset=<?php bloginfo('charset'); ?>" />

		<link rel="icon" href="<?php bloginfo('stylesheet_directory');?>/images/favicon.png" />

		<?php if (is_home()) {?>

			<?php 

			$aboutPage = get_page_by_title('About');

			$aboutPageID = $aboutPage->ID;

					

			$simpleFields = simple_fields_get_post_group_values($aboutPageID,"SEO", true, 1);

			$metaDescription = $simpleFields["Site Description (Max 160 charaters. Do not forget to use keywords related to the business!)"][0];

			?>

			<meta name="description" content ="<?php echo $metaDescription;?>"/>

		<?php }?>

		

		

		<?php if (is_home()) {?>		

		<title><?php bloginfo('name'); ?> - <?php echo get_bloginfo ('description');?></title>

		<?php } ?>

		

		<link rel="stylesheet" href="<?php bloginfo('stylesheet_url'); ?>" type="text/css" media="screen" />

		<link rel="pingback" href="<?php bloginfo('pingback_url'); ?>" />

		

		<!-- Load Fonts -->

		<link href='http://fonts.googleapis.com/css?family=Droid+Serif:regular,italic,bold,bolditalic&subset=latin' rel='stylesheet' type='text/css'>



		<!-- fullscrn setup (before loading dojo)-->

		<link rel="stylesheet" href="<?php bloginfo('stylesheet_directory')?>/lib/ttcon/resources/fullscrn.css" type="text/css" />

		<script type="text/javascript">

		djConfig = {

			baseUrl: '<?php bloginfo("stylesheet_directory")?>/',

			modulePaths: {

				'ttcon': 'lib/ttcon'

			}

		};

		</script>

		

		<!-- Load JS libraries -->

		<?php if(defined('USE_JQUERY')){ ?>

			<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.5/jquery.min.js"></script>

		<?php }?>

		<?php if(defined('USE_JQUERY_UI')){ ?>

			<script src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8/jquery-ui.min.js"></script>

		<?php }?>

		<?php if(defined('USE_DOJO')){ ?>

			<script src="http://ajax.googleapis.com/ajax/libs/dojo/1.5/dojo/dojo.xd.js" type="text/javascript"></script> 

		<?php }?>

		 

		<!-- fullscrn setup (after loading dojo)-->

		<script type="text/javascript">

		dojo.require("ttcon.fullscrn");					

		dojo.ready(function() {

			var fullScr = new ttcon.fullscrn({

				image: "<?php bloginfo('stylesheet_directory')?>/images/bg/<?php echo rand(0,2)?>.jpg"

			});			

		});

		</script>



		<!-- google analytics -->

		<script type="text/javascript">

		  var _gaq = _gaq || [];

		  _gaq.push(['_setAccount', 'UA-6603360-10']);

		  _gaq.push(['_trackPageview']);

		

		  (function() {

		    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;

		    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';

		    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);

		  })();		

		</script>

		<?php wp_head(); ?>

	</head>



	<body>

		<div class="fullscrn-body">

		<div id="page-wrap" class="group">

			<div id="content">

				<div id="header">

					<a href="<?php bloginfo('url'); ?>" title="<?php bloginfo('name'); ?>" id="home-link-type"><h1 class="hidden" alt="<?php bloginfo('name');?>"><?php bloginfo('name');?></h1></a>

					<div id="description"><p class="hidden"><?php echo get_bloginfo ('description');?></p></div>

					<a href="<?php bloginfo('url'); ?>" title="<?php bloginfo('name'); ?>" id="home-link-logo"></a>

					<div id="black-line-one"></div>

				</div><!-- END header -->