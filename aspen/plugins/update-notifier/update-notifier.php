<?php
/*
Plugin Name: Update Notifier
Plugin URI: http://lionsgoroar.co.uk/wordpress/update-notifier/
Description: Sends email notifications to the admin if a new version of WordPress available. Notifications about updates for plugins and themes can also be sent.
Version: 1.4.1
Author: Jon Cave
Author URI: http://lionsgoroar.co.uk/
*/

/*	Copyright 2010 Jon Cave

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/* activate / deactivate / uninstall */
function updatenotifier_activate() {
	// clear any existing schedules first
	wp_clear_scheduled_hook( 'updatenotifier_sendmail' );
	// schedule daily check
	wp_schedule_event( time(), 'daily', 'updatenotifier_sendmail' );

	$current = get_option( 'updatenote_options' );
	$defaults = array(
		'secondemail' => '', 'plugins' => 'No', 'themes' => 'No',
		'emailadmin' => false, 'version' => '1.4.1'
	);

	if ( ! $current )
		add_option( 'updatenote_options', $defaults );
	else
		updatenotifier_upgrade( $current, $defaults );
}
register_activation_hook( __FILE__, 'updatenotifier_activate' );

function updatenotifier_deactivate() {
	wp_clear_scheduled_hook( 'updatenotifier_sendmail' );
	unregister_setting( 'updatenote-settings', 'updatenote_options', 'updatenote_validate' );
}
register_deactivation_hook( __FILE__, 'updatenotifier_deactivate' );

function updatenotifier_uninstall() {
	delete_option( 'updatenote_options' );
}
register_uninstall_hook( __FILE__, 'updatenotifier_uninstall' );

function updatenotifier_upgrade( $current, $defaults ) {
	if ( ! isset( $current['version'] ) ) {
		$options['secondemail'] = $current[0];
		$options['plugins'] = $current[1];
		$options['themes'] = $current[2];
		$options = array_merge( $defaults, $options );
		update_option( 'updatenote_options', $options );
	} else if ( $current['version'] != '1.4.1' ) {
		$current['version'] = '1.4.1';
		update_option( 'updatenote_options', $current );
	}
}

/* update checks */
add_action( 'updatenotifier_sendmail', 'updatenote_updatechecker' );

function updatenote_updatechecker() {
	$options = get_option( 'updatenote_options' );
	$message = '';
	$update_core = get_site_transient( 'update_core' );
	if ( ! empty($update_core) && isset($update_core->updates) && is_array($update_core->updates)
		&& isset($update_core->updates[0]->response) && 'upgrade' == $update_core->updates[0]->response
	) {
		$newversion = $update_core->updates[0]->current;
		$oldversion = $update_core->version_checked;
		$blogurl = esc_url( home_url() );
		$message = "It's time to update the version of WordPress running at $blogurl from version $oldversion to $newversion.\n\n";

		// don't let $wp_version mangling plugins mess this up
		if ( ! preg_match( '/^(\d+\.)?(\d+\.)?(\d+)$/', $oldversion ) ) {
			include( ABSPATH . WPINC . '/version.php' );
			$message = $wp_version == $newversion ? '' : "It's time to update the version of WordPress running at $blogurl from version $wp_version to $newversion.\n\n";
		}
	}

	if ( $options['plugins'] != 'No' )
		$message .= updatenote_plugincheck( $options['plugins'] );

	if ( $options['themes'] != 'No' )
		$message .= updatenote_themecheck( $options['themes'] );

	if ( ! empty($message) ) {
		$upgradelink = admin_url( 'update-core.php' );
		$message .= "To perform the necessary updates visit your admin panel ($upgradelink).";
		$admin = get_option( 'admin_email' );
		$extra_email = $options['secondemail'];
		$subject = apply_filters( 'updatenotifier_subject', 'Updates are available for your WordPress site' );

		if ( ! $options['emailadmin'] )
			wp_mail($admin, $subject, $message);
		if ( is_email($extra_email) )
			wp_mail($extra_email, $subject, $message);
	}
}

function updatenote_plugincheck( $option ) {
	$message = '';
	$update_plugins = get_site_transient( 'update_plugins' );
	if ( ! empty($update_plugins->response) ) {
		$plugins_needupdate = $update_plugins->response;
		$message = "The following plugin(s) have new versions available for download:\n";
		if ( $option == 'Only for active plugins' ) {
			$active = get_option('active_plugins');
			$send_mail = false;
			foreach ( $plugins_needupdate as $key => $plugin ) {
				if ( in_array($key, $active) ) {
					$message .= $plugin->slug . ' (' . $plugin->package . ")\n";
					$send_mail = true;
				}
			}

			if ( ! $send_mail )
				$message = '';
			else
				$message .= "\n";
		} else {
			foreach ( $plugins_needupdate as $plugin )
				$message .= $plugin->slug . ' (' . $plugin->package . ")\n";

			$message .= "\n";
		}
	}

	return $message;
}

function updatenote_themecheck( $option ) {
	$message = '';
	$update_themes = get_site_transient( 'update_themes' );
	if ( ! empty($update_themes->response) ) {
		$themes_needupdate = $update_themes->response;
		$message = "\nThe following theme(s) have new versions available for download:\n";
		if ( $option == 'Only for the active theme' ) {
			$current = strtolower(get_option('current_theme'));
			$send_mail = false;
			foreach ( $themes_needupdate as $key => $value ) {
				if ( $key == $current ) {
					$message .= $key . ' (' . $value['package'] . ")\n";
					$send_mail = true;
				}
			}

			if ( ! $send_mail )
				$message = '';
			else
				$message .= "\n";
		} else {
			foreach ( $themes_needupdate as $key => $value )
				$message .= $key . ' (' . $value['package'] . ")\n";

			$message .= "\n";
		}
	}

	return $message;
}

/* admin options (Settings -> Update Notifier) */
function updatenote_regsettings() {
	register_setting( 'updatenote-settings', 'updatenote_options', 'updatenote_validate' );
}
add_action( 'admin_init', 'updatenote_regsettings' );

function updatenote_create_menu() {
	add_options_page('Update Notifier', 'Update Notifier', 'manage_options', 'update-notifier', 'updatenote_menu');
}
add_action( 'admin_menu', 'updatenote_create_menu' );

function updatenote_menu() {
	global $wp_version;
	$options = get_option( 'updatenote_options' );

	if ( isset($_GET['action']) && 'test-email' == $_GET['action'] ) {
		check_admin_referer( 'updatenote-test_email' );

		$extra_email = $options['secondemail'];
		$admin = get_option( 'admin_email' );
		$blogurl = esc_url( home_url() );
		$upgradelink = admin_url( 'update-core.php' );
		$subject = apply_filters( 'updatenotifier_subject', 'Updates are available for your WordPress site' );
		$message = <<<EOM
It's time to update the version of WordPress running at $blogurl from version $wp_version to $wp_version.

The following plugin(s) have new versions available for download:
example (example.com/example.zip)

The following theme(s) have new versions available for download:
example (example.com/example.zip)

To perform the necessary updates visit your admin panel ($upgradelink).
EOM;
		if ( ! $options['emailadmin'] )
			wp_mail($admin, $subject, $message);
		if ( is_email($extra_email) )
			wp_mail($extra_email, $subject, $message);

		$notices = '<div class="updated"><p>Test email sent. Check your inbox.</p></div>';
	}
?>
<div class="wrap">
<h2>Update Notifier</h2>

<?php if ( ! preg_match( '/^(\d+\.)?(\d+\.)?(\d+)$/', $wp_version ) ) : ?>
<div class="error"><p><strong>WordPress version number (<?php echo esc_html($wp_version); ?>) is detected as potentially invalid.</strong> You may encounter some problems, see <a href="http://wordpress.org/extend/plugins/update-notifier/faq/">FAQ</a> for details.</p></div>
<?php endif; ?>
<?php if ( isset( $notices ) ) echo $notices; ?>

<form method="post" action="options.php">
	<?php settings_fields( 'updatenote-settings' ); ?>
	<input type="hidden" name="updatenote_options[version]" value="<?php echo esc_attr( $options['version'] ); ?>" />
	<table class="form-table">
		<tr valign="top">
		<th scope="row">Add Secondary Email</th>
		<td><input type="text" name="updatenote_options[secondemail]" class="regular-text code" value="<?php echo $options['secondemail']; ?>" /><span class="description">This email address will also receive update notifications</span></td>
		</tr>

		<tr valign="top">
		<th scope="row">Only notify the secondary email</th>
		<td><input type="checkbox" name="updatenote_options[emailadmin]" value="1" <?php checked('1', $options['emailadmin']); ?> /></td>
		</tr>

		<tr valign="top">
		<th scope="row">Do you want to be notified about updates for plugins?</th>
		<td><?php $radios = array('No', 'Yes', 'Only for active plugins');
			foreach ( $radios as $radio ) {
				echo "\t<label title='" . esc_attr($radio) . "'><input type='radio' name='updatenote_options[plugins]' value='" . esc_attr($radio) . "'";
				if ( $options['plugins'] === $radio )
					echo " checked='checked'";
				echo ' />' . esc_attr($radio) . "</label><br />\n";
			} ?>
		</td>
		</tr>

		<tr valign="top">
		<th scope="row">Do you want to be notified about updates for themes?</th>
		<td><?php $radios = array('No', 'Yes', 'Only for the active theme');
			foreach ( $radios as $radio ) {
				echo "\t<label title='" . esc_attr($radio) . "'><input type='radio' name='updatenote_options[themes]' value='" . esc_attr($radio) . "'";
				if ( $options['themes'] === $radio )
					echo " checked='checked'";
				echo ' />' . esc_attr($radio) . "</label><br />\n";
			} ?>
		</td>
		</tr>
	</table>

	<p class="submit">
	<input type="submit" class="button-primary" value="Save Changes" />
	<?php $url = admin_url( 'options-general.php?page=update-notifier&amp;action=test-email' ); ?>
	<a href="<?php echo wp_nonce_url( $url, 'updatenote-test_email' ); ?>" class="button-secondary" id="test-email">Send Test Email</a>
	</p>
</form>
</div>
<?php }

function updatenote_validate( $input ) {
	if ( ! is_email($input['secondemail']) ) {
		if ( isset($input['emailadmin']) ) {
			$input['emailadmin'] = false;
			$message = 'You tried to deselect the admin email without providing a valid secondary email address.';
			add_settings_error( 'updatenote-settings', 'no_admin', $message );
		} elseif ( ! empty($input['secondemail']) ) {
			$message = 'You provided an invalid secondary email address.';
			add_settings_error( 'updatenote-settings', 'bad_email', $message );
		}

		$input['secondemail'] = '';
	}

	$options['secondemail'] = $input['secondemail'];
	$options['plugins'] = esc_attr( $input['plugins'] );
	$options['themes'] = esc_attr( $input['themes'] );
	$options['emailadmin'] = ( empty($input['emailadmin']) ) ? false : true;
	$options['version'] = esc_attr( $input['version'] );

	return $options;
}

function updatenote_plugin_links( $links, $file ) {
	$plugin = plugin_basename( __FILE__ );
	if ($file == $plugin) {
		return array_merge( $links, array(
			'<a href="options-general.php?page=update-notifier">Settings</a>',
			'<a href="http://wordpress.org/extend/plugins/update-notifier/faq/">FAQ</a>'
		));
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'updatenote_plugin_links', 10, 2 );