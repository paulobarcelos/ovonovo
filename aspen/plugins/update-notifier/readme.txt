=== Update Notifier ===
Contributors: duck_
Tags: upgrade, security, email, notification, admin
Requires at least: 3.0
Tested up to: 3.0.1
Stable tag: 1.4.1

Sends email notifications if a new version of WordPress available. Notifications about updates for plugins and themes can also be sent.

== Description ==

If you don't check your admin panel on your WordPress install very often (maybe because you prefer to use remote publishing) or you want to make sure
that your clients' WordPress installations are updated, then this is the plugin for you. You don't have to login to your admin panel regularly,
suscribe to an RSS feed, or do anything apart from installing this plugin to be notified when an update to WordPress is released.

All you have to do is install Update Notifier and forget it until you receive an email telling you to update.

To change Update Notifier's options, go to Update Notifier under the main Settings menu. From there you can add a secondary email address
which will also receive update notifications and you can activate update notifications for themes and plugins.

== Installation ==

1. Download the .zip file
1. Upload and install via the Add New plugin submenu in WordPress
1. Activate the plugin
1. (Optional) Go to Update Notifier submenu in the Settings menu and set your preferred settings for Update Notifier
1. Wait for an email when the next version of WordPress is released

== Frequently Asked Questions ==

= I have the plugin enabled on my WordPress installation, but I'm not receiving emails when a new update is released. Why? =
Try using the test email functionality on the Update Notifier settings page to see if you can receive emails from Update Notifier at all.

Make sure that the admin email address (see General Settings in WordPress) is set correctly to your email address.

Otherwise, have you tried looking in your spam folder? To prevent Update Notifier emails going into your spam you should set up a filter making
sure that emails with the subject: "Updates are available for your WordPress site", are not placed in your spam folder.

= Why am I receiving notifications telling me to upgrade from version 'abc' (or similar)? =
**NB:** This should be fixed as of 1.4.1, if you continue to encounter any issues please get in contact via the support forum.

You probably have a plugin installed which is changing the global `$wp_version` variable. This means that when WordPress does its automatic check
for updates it asks wordpress.org if there are versions newer than 'abc', obviously 'abc' doesn't exist and so the response is that an update is
available (even if your site already has the latest version of WordPress!). This response is stored by WordPress and when Update Notifier checks
this stored value it sees that allegedly an update available and sends an unnecessary email notification.

For example the WP Security Scan plugin changes the internal version to 'abc', to disable this comment out line 53 of securityscan.php
which reads `add_action("init",mrt_remove_wp_version,1);`

== Upgrade Notice ==

= 1.4.1 =
Mitigate problems caused by $wp_version mangling (no more 'abc' emails); filter the subject

== Changelog ==

= 1.4.1 =
* Mitigate problems caused by $wp_version mangling (no more 'abc' emails)
* Remove some backwards compatibility (this is an update program after all!)
* Added filter, 'updatenotifier_subject', to email subject text

= 1.4 =
* WordPress 3.0 support
* Combined notification emails (new generic subject is "Updates are available for your WordPress site")
* Ability to send test email
* Option to only send notifications to specified secondary email

= 1.3 =
* Added links in email to admin panel for faster upgrading
* Moved settings out of Misc to own settings page
* Settings now held in one option (old settings will not be remembered on plugin upgrade)
* Added warning to settings page if the WordPress version is invalid

= 1.2 =
* Added optional notifications for plugin updates
* Added optional notifications for theme updates
* Plugin doesn't support versions less than 2.8
* Clean removal of options from the database upon uninstall

= 1.1 =
* Added option to send the notification emails to a second email address

= 1.0 =
* Initial release
* Sends email to admin when WordPress core needs updating