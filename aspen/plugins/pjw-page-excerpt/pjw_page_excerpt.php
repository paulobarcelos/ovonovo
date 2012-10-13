<?php
/*
   Plugin Name: PJW Page Excerpt
   Plugin URI: http://blog.ftwr.co.uk/wordpress/page-excerpt/
   Description: Allows you to edit excerpts for Pages.
   Author: Peter Westwood
   Version: 0.02
   Author URI: http://blog.ftwr.co.uk/

 */

class pjw_page_excerpt
{
		function pjw_page_excerpt()
		{
			if ( function_exists('add_meta_box') ){
				add_meta_box( 'postexcerpt', __('Excerpt'), array(&$this, 'meta_box'), 'page'  );
			} else {
				add_action('dbx_page_advanced', array(&$this,'post_excerpt'));
			}
		}

		function meta_box()
		{
			global $post;
			?>
			<textarea rows="1" cols="40" name="excerpt" tabindex="6" id="excerpt"><?php echo $post->post_excerpt ?></textarea>
			<p><?php _e('Excerpts are optional hand-crafted summaries of your content. You can <a href="http://codex.wordpress.org/Template_Tags/the_excerpt" target="_blank">use them in your template</a>'); ?></p>
			<?php
		}

		function post_excerpt()
		{
			global $post;
			?>
			<div class="dbx-box-wrapper">
			<fieldset id="postexcerpt" class="dbx-box">
			<div class="dbx-handle-wrapper">
			<h3 class="dbx-handle"><?php _e('Optional Excerpt') ?></h3>
			</div>
			<div class="dbx-content-wrapper">
			<div class="dbx-content"><textarea rows="1" cols="40" name="excerpt" tabindex="6" id="excerpt"><?php echo $post->post_excerpt ?></textarea></div>
			</div>
			</fieldset>
			</div>
			<?php
		}
}

/* Initialise outselves lambda stylee */
add_action('admin_menu', create_function('','global $pjw_page_excerpt; $pjw_page_excerpt = new pjw_page_excerpt;'));
?>
