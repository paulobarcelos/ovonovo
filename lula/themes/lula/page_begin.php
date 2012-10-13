<?php get_header(); ?>
		<div id="main-content">
			<?php
			$slideshowPage = get_page_by_title("Slideshow");
			$slideshowID = $slideshowPage->ID;
			$pagelist = get_pages('sort_column=menu_order&sort_order=asc&exclude='.$slideshowID);
			$pages = array();
			foreach ($pagelist as $page) $pages[] += $page->ID;
			$totalPages = count($pages);
			$current = array_search($post->ID, $pages);			
			$prev = $current-1;
			$next = $current+1;
			if($prev == -1) $prev = $totalPages-1;
			if($next == $totalPages) $next = 0;
			$prevID = $pages[$prev];
			$nextID = $pages[$next];
			?>		
			<a href="<?php echo get_permalink($prevID); ?>" title="<?php echo get_the_title($prevID); ?>" id="left-arrow-2"></a>
			<a href="<?php echo get_permalink($nextID); ?>" title="<?php echo get_the_title($nextID); ?>" id="right-arrow-2"></a>
			
			