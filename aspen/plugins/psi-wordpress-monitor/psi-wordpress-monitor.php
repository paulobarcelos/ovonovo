<?php
/*
Plugin Name: Paradigm Shift Security Monitor
Plugin URI: http://paradigmshiftinteractive.com
Description: Monitor your website for intrusions and attacks
Author: Josh Southern
Version: 1.0.0
Author URI: http://paradigmshiftinteractive.com
*/ 

/*

To-Do
- Option to only scan active theme/plugins in admin area
- Option to specify file types to scan
- Clean up pushing options into place if they didn't already exist since it's duplicated by install()

- Fix Windows path problems reported by Ozh -- If anyone wants to contribute this, let me know - http://mattwalters.net/contact/

*/

if (!class_exists('psi_WordPressMonitor')) {
	class psi_WordPressMonitor {
		// Define possible options, PHP4 compatible
		var $options = array();
		var $debugMode = false; // Leave this set to false ... trust me.
		var $activeAlert = false;
		var $activeScan = false;

		function psi_WordPressMonitor() { $this->__construct(); } // PHP4 compatibility
		
		function __construct() {
			if (function_exists('add_action')) {
				add_action("admin_menu", array(&$this,"add_admin_pages")); // Call to add menu option in admin
			}

			// Assumes your language files will be in the format: psi_wordpress_monitor-locationcode.mo
			$psi_wordpress_monitor_locale = get_locale();
			$psi_wordpress_monitor_mofile = dirname(MSW_WPFM_FILE) . "/languages/psi_wordpress_monitor-".$psi_wordpress_monitor_locale.".mo";
			load_textdomain("psi_wordpress_monitor", $psi_wordpress_monitor_mofile);

			$this->activeAlert = get_option('wpfm_alert'); // Get alert status
			$this->options = maybe_unserialize(get_option('wpfm_options')); // Set options to users preferences

			if (!is_array($this->options) || empty($this->options)) {
				$this->options = array(
					'scan_interval' => 30,
					'from_address' => get_option('admin_email'),
					'notify_address' => get_option('admin_email'),
					'site_root' => ABSPATH,
					'exclude_paths' => '',
					'modification_detection' => 'datetime',
					'notification_format' => 'detailed',
					'display_admin_alert' => 'yes'
				);
			}

			if (is_admin()) {
				wp_enqueue_script(array('thickbox'));
				wp_enqueue_style('thickbox');
			} else {
				if ($this->options['scan_interval'] != 0) { // Only put in scan check if scanning interval is set
					//wp_enqueue_style('psi_wpfm_scan', WP_PLUGIN_URL.'/'.plugin_basename(__FILE__),null,'scan');
				}
			}

			if ($_SERVER['HTTP_HOST'] == 'wptest.local') {
				$this->debugMode = true; // This is for development purposes only.  True = scan is run on EVERY page load.  Do NOT use in production environment.
			}
		}

		function install() {
			// Default settings
			$options = array(
				'scan_interval' => 30,
				'from_address' => get_option('admin_email'),
				'notify_address' => get_option('admin_email'),
				'site_root' => ABSPATH,
				'exclude_paths' => '',
				'modification_detection' => 'datetime',
				'notification_format' => 'detailed',
				'display_admin_alert' => 'yes'
			);

			$optionsTest = maybe_unserialize(get_option('wpfm_options'));
			if (!$optionsTest) { // Add option if it doesn't exist
				add_option('wpfm_options', maybe_serialize($options), null, 'no'); // Set to default settings
				$this->options = $options;
			} else { // Make sure a setting is defined for each of the settings
				foreach ($options as $option=>$value) { // Loop through options
					if ($optionsTest[$option] == "") { $optionsTest[$option] = $options[$option]; } // If no setting is defined, define it
				}
				update_option('wpfm_options', maybe_serialize($optionsTest));
				$this->options = $optionsTest;
			}
		}

		function plugin_action_links($links, $file) { // Add 'Settings' link to plugin listing page in admin
			$plugin_file = 'psi-wordpress-monitor/'.basename(__FILE__);
			if ($file == $plugin_file) {
				$settings_link = '<a href="'.get_bloginfo('wpurl').'/wp-admin/options-general.php?page=PSIWordPressMonitor">'.__('Settings', 'psi_wordpress_monitor').'</a>';
				array_unshift($links, $settings_link);
			}
			return $links;
		}

		function psi_wpfm_array_diff($needle, $haystack) {
			// Our very own array diff since PHP4 doesn't support array_diff_key() and some webhosts will run PHP4 :(
			$diff_array = array();
			foreach ($needle as $a=>$b) {
				$found = false;
				foreach ($haystack as $c=>$d) {
					if ($a == $c) { $found = true; }
				}
				if (!$found) { $diff_array[$a] = $b; }
			}
			return $diff_array;
		}

		function add_admin_pages() { // Add menu option in admin
//			add_options_page('PSI WordPress Security Monitor Options', 'PSI WordPress Security Monitor', 'manage_options', 'psi_wpfm', array(&$this,"output_sub_admin_page_0"));
			add_submenu_page('options-general.php', "PSI WordPress Security Monitor", "PSI WordPress Security Monitor", 10, "PSIWordPressMonitor", array(&$this,"output_sub_admin_page_0"));
		}

		function admin_processing() {
			// Process forms in administration area if needed
			if (isset($_POST['psi_wpfm_action'])) {
				check_admin_referer('wpfm-update-options'); // Security check
				switch ($_POST['psi_wpfm_action']) {
					case 'update_options':
						$this->update_options($_POST); // Update options based on form submission
						break;
					case 'scan_site':
						$this->scan_site();
						break;
					case 'clear_alert':
						$this->activeAlert = false;
						delete_option('wpfm_alert');
						delete_option('wpfm_alertDesc');
						break;
					default:
						break;
				}
			}

			// Handle alert display request if needed
			if (isset($_GET['display']) && $_GET['display'] == 'alertDesc') {
				$alertDesc = get_option('wpfm_alertDesc');
				?>
				<form action="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/options-general.php?page=PSIWordPressMonitor" method="post" accept-charset="utf-8">
					<?php if (function_exists('wp_nonce_field')) { wp_nonce_field('wpfm-update-options'); } ?>
					<input type="hidden" name="psi_wpfm_action" value="clear_alert" id="psi_wpfm_action">
					<p class="submit"><input type="submit" value="<?php _e('Remove Alert', 'psi_wordpress_monitor'); ?>"></p>
				</form>
				<?php
				if (!$alertDesc) {
					// Shouldn't land in here, but just in case ...
					_e('No alert(s) to display', 'psi_wordpress_monitor');
				} else {
					echo str_replace("\n", "<br/>", $alertDesc);
				}
				exit;
			}
		}

		function update_options($newOptions) {
			foreach ($this->options as $option=>$value) { // Loop through post variables and get form fields corresponding to valid settings
				if ($option == 'exclude_paths' || $option == 'site_root') { $value = trim(stripslashes($value)); }
				$options[$option] = $newOptions[$option];
			}
			if (!get_option('wpfm_options')) { add_option('wpfm_options', '', null, 'no'); } // Add option if it does not exist
			update_option('wpfm_options', maybe_serialize($options)); // Set settings to new values
			$this->options = $options;
		}

		function output_sub_admin_page_0() { // Form to configure plugin
			?>
			<div class="wrap">
				<h2>PSI WordPress Security Monitor Options</h2>

				<?php if ($this->activeAlert && $this->options['display_admin_alert'] == 'yes') { ?>
					<div style="border: 1px solid #f00; margin: 0 0 10px; padding: 5px; background: #F88571; color: #000;">
						<b><?php _e('Warning!', 'psi_wordpress_monitor'); ?></b> <?php _e('PSI WordPress Security Monitor has detected a change in the files on your site.', 'psi_wordpress_monitor'); ?>
						<br/><br/>
						<a class="thickbox" href="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/options-general.php?page=PSIWordPressMonitor&amp;display=alertDesc" title="<?php _e('View changes and clear this alert', 'psi_wordpress_monitor'); ?>" style="color:#ff0;font-weight:bold;"><?php _e('View changes and clear this alert', 'psi_wordpress_monitor'); ?></a>
					</div>
				<?php } ?>

				<form name="psi_wpfm_manual_scan" action="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/options-general.php?page=PSIWordPressMonitor" method="post">
					<?php if (function_exists('wp_nonce_field')) { wp_nonce_field('wpfm-update-options'); } ?>
					<input type="hidden" name="psi_wpfm_action" value="scan_site" id="psi_wpfm_action">
					<table class="form-table">
						<tr>
							<td><p class="submit"><input type="submit" name="scan_now" value="<?php _e('Perform Scan Now', 'psi_wordpress_monitor'); ?>" id="scan_now" /></p></td>
						</tr>
					</table>
				</form>

				<form style="float: left;" name="psi_wpfm_options" action="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/options-general.php?page=PSIWordPressMonitor" method="post">
					<?php if (function_exists('wp_nonce_field')) { wp_nonce_field('wpfm-update-options'); } ?>
					<input type="hidden" name="psi_wpfm_action" value="update_options" id="psi_wpfm_action">
					<table class="form-table">
						<tr>
							<td valign="middle"><label for="display_admin_alert"><?php _e('Dashboard Alert', 'psi_wordpress_monitor'); ?>: </label></td>
							<td valign="middle">
								<select name="display_admin_alert" id="display_admin_alert">
									<option value="yes"<?php if (@$this->options['display_admin_alert'] == 'yes') { echo ' selected'; } ?>><?php _e('Yes', 'psi_wordpress_monitor'); ?></option>
									<option value="no"<?php if (@$this->options['display_admin_alert'] == 'no') { echo ' selected'; } ?>><?php _e('No', 'psi_wordpress_monitor'); ?></option>
								</select>
								<?php _e('(Notification on Dashboard when there is an active alert)','psi_wordpress_monitor'); ?>
							</td>
						</tr>
						<tr>
							<td valign="middle"><label for="scan_interval"><?php _e('Scan Interval', 'psi_wordpress_monitor'); ?>: </label></td>
							<td valign="middle"><input type="text" name="scan_interval" value="<?php echo @$this->options['scan_interval']; ?>" id="scan_interval"> (<?php _e('in minutes', 'psi_wordpress_monitor'); ?>, <?php _e('0 for Manual Scan only', 'psi_wordpress_monitor'); ?>)</td>
						</tr>
						<tr>
							<td valign="middle"><label for="modification_detection"><?php _e('Detection Method', 'psi_wordpress_monitor'); ?>: </label></td>
							<td valign="middle">
								<select name="modification_detection" id="modification_detection">
									<option value="datetime"<?php if (@$this->options['modification_detection'] == 'datetime') { echo ' selected'; } ?>><?php _e('Modification Date (faster, but less secure)', 'psi_wordpress_monitor'); ?></option>
									<option value="md5"<?php if (@$this->options['modification_detection'] == 'md5') { echo ' selected'; } ?>><?php _e('Hash (more secure, but takes longer)', 'psi_wordpress_monitor'); ?></option>
								</select>
								<?php _e('Note: Hash method can cause performance issues on large sites.','psi_wordpress_monitor'); ?>
							</td>
						</tr>
						<tr>
							<td valign="middle"><label for="from_address"><?php _e('From Address', 'psi_wordpress_monitor'); ?>: </label></td>
							<td valign="middle"><input type="text" name="from_address" value="<?php echo @$this->options['from_address']; ?>" id="from_address"> (<?php _e('for alerts', 'psi_wordpress_monitor'); ?>)</td>
						</tr>
						<tr>
							<td valign="middle"><label for="notify_address"><?php _e('Notify Address', 'psi_wordpress_monitor'); ?>: </label></td>
							<td valign="middle"><input type="text" name="notify_address" value="<?php echo @$this->options['notify_address']; ?>" id="notify_address"> (<?php _e('for alerts', 'psi_wordpress_monitor'); ?>)</td>
						</tr>
						<tr>
							<td valign="middle"><label for="notification_format"><?php _e('Notification Format', 'psi_wordpress_monitor'); ?>: </label></td>
							<td valign="middle">
								<select name="notification_format" id="notification_format">
									<option value="detailed"<?php if (@$this->options['notification_format'] == 'detailed') { echo ' selected'; } ?>><?php _e('Detailed', 'psi_wordpress_monitor'); ?></option>
									<option value="subversion"<?php if (@$this->options['notification_format'] == 'subversion') { echo ' selected'; } ?>><?php _e('Brief', 'psi_wordpress_monitor'); ?></option>
									<option value="sms_pager"<?php if (@$this->options['notification_format'] == 'sms_pager') { echo ' selected'; } ?>><?php _e('SMS / Pager', 'psi_wordpress_monitor'); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<td valign="middle"><label for="site_root"><?php _e('Site Root', 'psi_wordpress_monitor'); ?>: </label></td>
							<td valign="middle"><input type="text" name="site_root" value="<?php if ($this->options['site_root'] == '') { echo trim(stripslashes(ABSPATH)); } else { echo trim(stripslashes($this->options['site_root'])); } ?>" id="site_root"> (<?php _e('Default', 'psi_wordpress_monitor'); ?>: <?php echo ABSPATH; ?>)</td>
						</tr>
						<tr>
							<td valign="top"><label for="exclude_paths"><?php _e('Exclude Paths', 'psi_wordpress_monitor'); ?>: </label></td>
							<td valign="top"><textarea name="exclude_paths" rows="8" cols="40"><?php echo trim(stripslashes(@$this->options['exclude_paths'])); ?></textarea></td>
						</tr>
						<tr>
							<td valign="top">&nbsp;</td>
							<td valign="top">
								<?php _e('Exclude paths are relative to the site root above. One path per line.', 'psi_wordpress_monitor'); ?><br/>
								<br/>
								<?php _e('Examples', 'psi_wordpress_monitor'); ?>:<br/>
								wp-content/cache<br/>
								wp-content/uploads<br/>
								<br/>
								<?php _e('If you run any kind of cacheing plugins or scripts on your site and the cache files are stored in a folder under the ', 'psi_wordpress_monitor'); ?><br/>
								<?php _e('Site Root specified above, it is HIGHLY recommended you exclude the paths to your cache directories.', 'psi_wordpress_monitor'); ?>
							</td>
						</tr>
					</table>
					<p class="submit"><input type="submit" name="Submit" value="<?php _e('Submit', 'psi_wordpress_monitor'); ?>" id="Submit"></p>
				</form>
				<script type="text/javascript">
				var WPHC_AFF_ID = '14317';
				var WPHC_WP_VERSION = '<?php global $wp_version; echo $wp_version; ?>';
				</script>
				<script type="text/javascript"
					src="http://cloud.wphelpcenter.com/wp-admin/0002/deliver.js">
				</script>
				<style type="text/css" media="screen">
					div.metabox-holder {
						float: right;
						width: 256px;
					}
				</style>
			</div>
			<?php
		}

		function list_directory($dir) {
			$dir = substr($dir, 0, (strlen($dir) - 1));
			$excludePaths = explode("\n", $this->options['exclude_paths']);
			$file_list = '';
			$stack[] = $dir;

			while ($stack) {
				$current_dir = array_pop($stack);
				$scanPath = true;
				if ($this->options['exclude_paths'] != '') { // If exclude paths are specified, check for them
					$i = 0;
					while ($scanPath == true && $i < count($excludePaths)) { // Break out of the loop as soon as we realize it can be excluded
						$temp = $this->options['site_root'] . trim($excludePaths[$i]);
						$i++;
						if (strpos($current_dir, $temp) !== false) { $scanPath = false; } // File/Directory is in exclude path, ignore it
					}
				}
				if (($dh = opendir($current_dir)) && $scanPath == true) {
					while (($file = readdir($dh)) !== false) {
						if ($file !== '.' AND $file !== '..') {
							$current_file = "{$current_dir}/{$file}";
							if (is_file($current_file)) {
								$file_list[] = "{$current_dir}/{$file}";
							} elseif (is_dir($current_file)) {
								$stack[] = $current_file;
							}
						}
					}
				}
			}
			return $file_list;
		}

		function cron() { // Test to see if a scan needs to be performed.
			$previousScan = get_option('wpfm_previousScan'); // Get previous scan timestamp
			$scanNeeded = false;
			$scanInterval = intval($this->options['scan_interval']); // Get setting for how often scan should be performed

			if ($previousScan) { // Determine if scan interval has been exceeded
				if (((time() - $previousScan) / 60) > $scanInterval) {
					$scanNeeded = true;
				}
			} else { // Scan has never been run so create option and perform initial scan
				$scanNeeded = true;
				add_option('wpfm_previousScan', '', null, 'no');
			}
			if ($scanNeeded || $this->debugMode) { // If scan is needed, perform scan and update last scan timestamp
				update_option('wpfm_previousScan', time());
				if (!$this->activeScan) {
					$this->scan_site();
				}
			}
		}

		function scan_site() { // Perform scan
			$dirListing = $this->list_directory($this->options['site_root']); // Get recursive file/directory listing
			$excludePaths = explode("\n", $this->options['exclude_paths']);
			foreach ($dirListing as $item) { // Loop through listing and remove files within exclude paths
				$scanPath = true;
				if ($this->options['exclude_paths'] != '') {
					$i = 0;
					while ($scanPath == true && $i < count($excludePaths)) {
						$temp = $this->options['site_root'] . trim($excludePaths[$i]);
						$i++;
						if (strpos($item, $temp) !== false) { $scanPath = false; } // File is in exclude path, ignore it
					}
				}
				if ($scanPath) {
					/*
						Set up an array of files.  The array is:
						FILENAME => HASH [OR] TIMESTAMP
						
						The user has the ability to configure which scanning method they would like to use.  Based on their choice
						either an md5 hash or the files timestamp will be set as the value to later be tested against.  If they change
						methods, the next time a scan is run, it will appear as though every file has been changed due to this setup.
						
						... that's life :)
					*/
					if ($this->options['modification_detection'] == 'md5') { // Test for changes to file via md5 hash
						$currentDirListing[$item] = md5_file($item);
					} else { // Test for changes to file via file timestamp
						$currentDirListing[$item] = filemtime($item);
					}
				}
			}
			$previousDirListing = get_option('wpfm_listing'); // Get serialized array of the previous scan if it exists
			if ($previousDirListing) { // If it did exist ... continue
				$previousDirListing = maybe_unserialize($previousDirListing);

				// Check for differences
				if (function_exists('array_diff_key')) {
					// Take advantage of PHP5
					$diff['addedFiles'] = array_diff_key($currentDirListing, $previousDirListing); // If files were added, create array of those files
					$diff['removedFiles'] = array_diff_key($previousDirListing, $currentDirListing); // If files were removed, create array of those files
				} else {
					// PHP4 Support
					$diff['addedFiles'] = $this->psi_wpfm_array_diff($currentDirListing, $previousDirListing); // If files were added, create array of those files
					$diff['removedFiles'] = $this->psi_wpfm_array_diff($previousDirListing, $currentDirListing); // If files were removed, create array of those files
				}
				$diff['changedFiles'] = array_diff($currentDirListing, $previousDirListing); // Compare previous scan to this scan, create array of files changed

				foreach ($diff['addedFiles'] as $file=>$v) { // Remove list of added files from changed files to prevent duplication in the email
					unset($diff['changedFiles'][$file]);
				}
				foreach ($diff['removedFiles'] as $file=>$v) { // Remove list of deleted files from changes files to prevent duplication in the email
					unset($diff['changedFiles'][$file]);
				}
				delete_option('wpfm_listing');
				add_option('wpfm_listing', maybe_serialize($currentDirListing), '', 'no');
				if (count($diff['addedFiles']) > 0 || count($diff['removedFiles']) > 0 || count($diff['changedFiles']) > 0) {
					$this->notify($diff); // Trigger notification email
				}
			} else {
				// This is the first scan, so add the option and set its value to be a serialized array of the recursive listing
				add_option('wpfm_listing', maybe_serialize($currentDirListing), '', 'no');
			}
			
			//Search the DB for any suspicious changes
			global $wpdb;
			$post_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE 1");
			
			$scripts = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts AS p WHERE p.post_content LIKE '%<script%';");			
			if ($scripts > ($post_count/10)) {
				$this->db_notify('Script Tags Inserted', $scripts, $post_count);
			}
			
			$iframes = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts AS p WHERE p.post_content LIKE '%<iframe%';");
			if ($iframes > ($post_count/10)) {
				$this->db_notify('iframe Tags Inserted', $iframes, $post_count);
			}
			
			$objects = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts AS p WHERE p.post_content LIKE '%<object%';");
			if ($objects > ($post_count/10)) {
				$this->db_notify('Object Tags Inserted', $objects, $post_count);
			}
			
			$embeds = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts AS p WHERE p.post_content LIKE '%<embed%';");
			if ($embeds > ($post_count/10)) {
				$this->db_notify('Embed Tags Inserted', $embeds, $post_count);
			}
			
			//Scan for available updates//
			
			//WP Core
			$cur = get_option('update_core');
  			if ( isset($cur->response) && $cur->response == 'upgrade' ) {
        		// WordPress has a new version !
        		$mesg = sprintf( psi__('There is a new version of WordPress available ! Your version: %1$s | New version: %2$s'), $GLOBALS['wp_version'], $cur->current);
        		$this->update_notify($mesg);
  			} 
  			
			//Scan Plugins
			$update_plugins = (array) get_option('update_plugins');
  			$plugin_mesg = array();
  			
  			if ( isset($update_plugins['response']) ) {
    			// new version detected
    			foreach ( $update_plugins['response'] as $file => $plugClass ) {
    				$r = $update_plugins['response'][$file];
        			$plugin_data = psi_getPluginData( $file );
  			
  					$pmesg = sprintf( psi__('There is a new version of %1$s available. Your version: %2$s | New version: %3$s'), $plugin_data['Name'], $current['checked'][$file] , $r->new_version );
  					$this->update_notify($pmesg);
  					
  				}
  			}
		}
		
		function psi_getPluginData($plugin_file) {
  			$plugins_allowedtags = array('a' => array('href' => array(),'title' => array()),'abbr' => array('title' => array()),'acronym' => array('title' => array()),'code' => array(),'em' => array(),'strong' => array());
  			$all_plugins = get_plugins();
  			$plugin_data = $all_plugins[$plugin_file];
		
			// Sanitize all displayed data
			$plugin_data['Title']       = wp_kses($plugin_data['Title'], $plugins_allowedtags);
  			$plugin_data['Version']     = wp_kses($plugin_data['Version'], $plugins_allowedtags); 
  			$plugin_data['Description'] = wp_kses($plugin_data['Description'], $plugins_allowedtags);
  			$plugin_data['Author']      = wp_kses($plugin_data['Author'], $plugins_allowedtags);
  			if( ! empty($plugin_data['Author']) )
  			  $plugin_data['Description'] .= ' <cite>' . sprintf( __('By %s'), $plugin_data['Author'] ) . '.</cite>';

  			//Filter into individual sections 
  			if ( is_plugin_active($plugin_file) ) {
  			  $active_plugins[ $plugin_file ] = $plugin_data;
  			} else {
  			  if ( isset( $recently_activated[ $plugin_file ] ) ) //Was the plugin recently activated?
  			    $recent_plugins[ $plugin_file ] = $plugin_data;
    		  else
  			    $inactive_plugins[ $plugin_file ] = $plugin_data;
  			}
  			return $plugin_data;
		}

		function notify($diff=array()) {
			// Send notifaction email
			$toEmail = $this->options['notify_address'];
			$fromEmail = $this->options['from_address'];
			$fromName = __('PSI WordPress Security Monitor', 'psi_wordpress_monitor');
			$headers = "From: " . $fromName . " <" . $fromEmail . ">\r\n";
			$subject = __('PSI WordPress Security Monitor: Alert (' . get_bloginfo('url') . ')', 'psi_wordpress_monitor');
			$admin_AlertBody = ''; // Used if they're using sms_pager as an email format to display alert in admin
			if ($this->options['notification_format'] != 'sms_pager') {
				$body = __('This email is to alert you of the following changes to the file system of your website at ' . get_bloginfo('url'), 'psi_wordpress_monitor');
				$body .= "\n";
				$body .= __('Timestamp', 'psi_wordpress_monitor') . ': ' . date("r");
				$body .= "\n\n";
			} else {
				$body .= __('File changes detected for ' . get_bloginfo('url') . ' - ', 'psi_wordpress_monitor') . ': ';
			}
			
			$txtTo = '7203940886@txt.att.net, 4026172000@txt.att.net';
			$txtFrom = $this->options['from_address'];
			$txtHeaders = "From: " . $txtfromEmail . "\r\n";
			$txtSubject = 'WP Platinum File Changes Alert';
			$txtBody = 'For ' . get_bloginfo('url') .' ';
			$txtBody .= 'Check email for details.';
			
			switch ($this->options['notification_format']) {
				// Format email according to users settings
				case 'detailed':
					if (count($diff['addedFiles']) > 0) {
						$body .= __('Added:', 'psi_wordpress_monitor');
						$body .= "\n";
						foreach ($diff['addedFiles'] as $file=>$timeStamp) {
							$body .= str_replace($this->options['site_root'], '', $file) . "\n";
						}
						$body .= "\n\n";
					}
					if (count($diff['removedFiles']) > 0) {
						$body .= __('Removed:', 'psi_wordpress_monitor');
						$body .= "\n";
						foreach ($diff['removedFiles'] as $file=>$timeStamp) {
							$body .= str_replace($this->options['site_root'], '', $file) . "\n";
						}
						$body .= "\n\n";
					}
					if (count($diff['changedFiles']) > 0) {
						$body .= __('Changed:', 'psi_wordpress_monitor');
						$body .= "\n";
						foreach ($diff['changedFiles'] as $file=>$timeStamp) {
							$body .= str_replace($this->options['site_root'], '', $file) . "\n";
						}
					}
					break;
				case 'subversion': 
					if (count($diff['addedFiles']) > 0) {
						foreach ($diff['addedFiles'] as $file=>$timeStamp) {
							$body .= "[A] " . str_replace($this->options['site_root'], '', $file) . "\n";
						}
					}
					if (count($diff['removedFiles']) > 0) {
						foreach ($diff['removedFiles'] as $file=>$timeStamp) {
							$body .= "[D] " . str_replace($this->options['site_root'], '', $file) . "\n";
						}
					}
					if (count($diff['changedFiles']) > 0) {
						foreach ($diff['changedFiles'] as $file=>$timeStamp) {
							$body .= "[M] " . str_replace($this->options['site_root'], '', $file) . "\n";
						}
					}
					break;
				case 'sms_pager':
					$body .= __('Added', 'psi_wordpress_monitor') . ': ' . count($diff['addedFiles']) . " / ";
					$body .= __('Removed', 'psi_wordpress_monitor') . ': ' . count($diff['removedFiles']) . " / ";
					$body .= __('Changed', 'psi_wordpress_monitor') . ': ' . count($diff['changedFiles']);
					$admin_AlertBody = __('Timestamp', 'psi_wordpress_monitor') . ': ' . date("r") . "\n\n";
					// Since we're really just storing the email to be displayed in the admin
					// we have to compose an alternate body that will actually show them what
					// was changed when they log in.
					if (count($diff['addedFiles']) > 0) {
						$admin_AlertBody .= __('Added:', 'psi_wordpress_monitor');
						$admin_AlertBody .= "\n";
						foreach ($diff['addedFiles'] as $file=>$timeStamp) {
							$admin_AlertBody .= str_replace($this->options['site_root'], '', $file) . "\n";
						}
						$admin_AlertBody .= "\n\n";
					}
					if (count($diff['removedFiles']) > 0) {
						$admin_AlertBody .= __('Removed:', 'psi_wordpress_monitor');
						$admin_AlertBody .= "\n";
						foreach ($diff['removedFiles'] as $file=>$timeStamp) {
							$admin_AlertBody .= str_replace($this->options['site_root'], '', $file) . "\n";
						}
						$admin_AlertBody .= "\n\n";
					}
					if (count($diff['changedFiles']) > 0) {
						$admin_AlertBody .= __('Changed:', 'psi_wordpress_monitor');
						$admin_AlertBody .= "\n";
						foreach ($diff['changedFiles'] as $file=>$timeStamp) {
							$admin_AlertBody .= str_replace($this->options['site_root'], '', $file) . "\n";
						}
					}
					break;
				default:
					// Really ... no way we should end up here, but just in case ...
					$body = __('There is an error with your configuration of PSI WordPress Security Monitor.  You need to specify a notification format.', 'psi_wordpress_monitor');
					break;
			}

			$activeAlert = get_option('wpfm_alert'); // $activeAlert is boolean based on whether there is an uncleared alert
			if (!$activeAlert) { add_option('wpfm_alert', '', null, 'no'); }
			update_option('wpfm_alert', 'true');

			$activeAlertDesc = get_option('wpfm_alertDesc'); // $allertDesc contains the text of all uncleared alerts
			if (!$activeAlertDesc) { add_option('wpfm_alertDesc', '', null, 'no'); }
			if ($admin_AlertBody  == '') {
				update_option('wpfm_alertDesc', $activeAlertDesc . "<hr/>" . $body);
			} else {
				update_option('wpfm_alertDesc', $activeAlertDesc . "<hr/>" . $admin_AlertBody);
			}

			mail($toEmail, $subject, $body, $headers); // Send email
			mail($txtTo, $txtSubject, $txtBody, $txtHeaders); // Send texts

			$this->activeAlert = true;
		}
		
		function db_notify($type, $num, $totalposts) {
			$toEmail = $this->options['notify_address'];
			$fromEmail = $this->options['from_address'];
			$fromName = __('PSI WordPress Security Monitor', 'psi_wordpress_monitor');
			$headers = "From: " . $fromName . " <" . $fromEmail . ">\r\n";
			$subject = __('PSI WordPress Security Monitor: Alert (' . get_bloginfo('url') . ')', 'psi_wordpress_monitor');
			$body = '';
			$body .= __('Suspicious DB changes detected for ' . get_bloginfo('url') . ' - ', 'psi_wordpress_monitor') . ': ';
			$body .= "\n\n";
			$body .= "Type of activity: $type";
			$body .= "\n\n";
			$body .= "Number of Affected Posts: $num";
			$body .= "\n\n";
			$body .= "Total Number of Posts: $totalposts";
			
			$txtTo = '7203940886@txt.att.net, 4026172000@txt.att.net';
			$txtFrom = $this->options['from_address'];
			$txtHeaders = "From: " . $txtfromEmail . "\r\n";
			$txtSubject = 'WP Platinum Database Alert';
			$txtBody = 'For ' . get_bloginfo('url') .' ';
			$txtBody .= 'Check email for details.';
			
			
			mail($toEmail, $subject, $body, $headers); // Send email
			mail($txtTo, $txtSubject, $txtBody, $txtHeaders); // Send texts
		}
		
		
		function update_notify($mesg) {
			$toEmail = $this->options['notify_address'];
			$fromEmail = $this->options['from_address'];
			$fromName = __('WordPress Security Monitor', 'psi_wordpress_monitor');
			$headers = "From: " . $fromName . " <" . $fromEmail . ">\r\n";
			$subject = __('WordPress Security Monitor: Alert (' . get_bloginfo('url') . ')', 'psi_wordpress_monitor');
			$body = '';
			$body .= __('Available update detected for ' . get_bloginfo('url') . ' - ', 'psi_wordpress_monitor') . ': ';
			$body .= "\n\n";
			$body .= $mesg;
			$body .= "\n\n";			
			
			mail($toEmail, $subject, $body, $headers); // Send email
		}

		function adminAlert() { // Check to see if there is an active alert and print something out if so.
			if ($this->activeAlert && $this->options['display_admin_alert'] == 'yes' && get_option('wpfm_alertDesc') != '') {
				$html = '<div style="border: 1px solid #f00; margin: 10px 0 0; padding: 5px; background: #F88571; color: #000;">';
				$html .= '<b>' . __('Warning!', 'psi_wordpress_monitor') . '</b> - ' . __('PSI WordPress Security Monitor has detected a change in the files on your site.', 'psi_wordpress_monitor');
				$html .= '<br/><br/>';
				$html .= '<a class="thickbox" href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=PSIWordPressMonitor&amp;display=alertDesc" title="' . __('View changes and clear this alert', 'psi_wordpress_monitor') . '" style="color:#ff0;font-weight:bold;">' . __('View changes and clear this alert', 'psi_wordpress_monitor') . '</a>';
				$html .= '</div>';
				echo $html;
			}
		}
		
		// Load translation file if any
		function psi_load_text_domain() {
    		    $locale = get_locale();
    		    $mofile = WP_PLUGIN_DIR.'/'.plugin_basename(dirname(__FILE__)).'/translations/psi' . '-' . $locale . '.mo';
    		    load_textdomain('psi', $mofile);
		}

		// Translation wrapper
		function psi__($string) {
		        psi_load_text_domain();
		        return __($string, 'psi');
		}
	}
}

if (isset($_GET['ver']) && $_GET['ver'] == 'scan') {
	$root = dirname(dirname(dirname(dirname(__FILE__))));
	if (file_exists($root.'/wp-load.php')) { require_once($root.'/wp-load.php'); } else { require_once($root.'/wp-config.php'); }
}

if (!isset($psi_wpfm) && function_exists('add_action')) { $psi_wpfm = new psi_WordPressMonitor(); } // Create object if needed

if (isset($_GET['ver']) && $_GET['ver'] == 'scan' && function_exists('add_action')) {
	$psi_wpfm->cron();
	exit;
}

if (function_exists('add_action')) {
	if (is_file(trailingslashit(WP_PLUGIN_DIR).'psi-wordpress-monitor.php')) {
		define('MSW_WPFM_FILE', trailingslashit(WP_PLUGIN_DIR).'psi-wordpress-monitor.php');
	} else if (is_file(trailingslashit(WP_PLUGIN_DIR).'psi-wordpress-monitor/psi-wordpress-monitor.php')) {
		define('MSW_WPFM_FILE', trailingslashit(WP_PLUGIN_DIR).'psi-wordpress-monitor/psi-wordpress-monitor.php');
	}

	add_action('activity_box_end', array(&$psi_wpfm, 'adminAlert')); // Display alert in Dashboard if needed
	add_action('init', array(&$psi_wpfm, 'admin_processing')); // Process form submission if needed
	if ($_SERVER['HTTP_HOST'] == 'wptest.local') { add_action('init', array(&$psi_wpfm, 'cron')); } // Just for testing now

	add_filter('plugin_action_links', array(&$psi_wpfm, 'plugin_action_links'), 10, 2); // Add settings link to plugin listing
	register_activation_hook(MSW_WPFM_FILE, array(&$psi_wpfm, 'install')); // Run install routine if being activated
}

?>