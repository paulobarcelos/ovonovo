=== PJW Page Excerpt  ===
Tags: pages, excerpt
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=paypal%40ftwr%2eco%2euk&item_name=Peter%20Westwood%20WordPress%20Plugins&no_shipping=1&cn=Donation%20Notes&tax=0&currency_code=GBP&bn=PP%2dDonationsBF&charset=UTF%2d8
Contributors: westi
Requires at least: 2.5
Tested up to: 2.9
Stable tag: 0.02

== Description ==
This plugin allows you to specify a specifc excerpt for WordPress pages.

The plugin adds an extra box to the set an Optional Excerpt for a WordPress page similar to the box which is available for posts. The box is aded using the dbx_page_advanced hook for versions of WordPress earlier than 2.5 and using add_meta_box() for WordPress 2.5 and later.

== Installation ==

1. Upload to your plugins folder, usually `wp-content/plugins/`
2. Activate the plugin on the plugin screen.
3. Write Page excerpts as you would for posts.
4. Use the_excerpt() template tag in your theme to display excerpts for pages.

== Frequently Asked Questions ==

= Why would I use this plugin = 

If you want to add a hierarchical set of pages to your site you will probably want control over the excerpt used when displaying a menu list of pages.
A good system can be achived in conjustion with my PJW Query Child Of plugin (http://wordpress.org/extend/plugins/pjw-query-child-of/) which helps you build post queries to select children of a specific page.
