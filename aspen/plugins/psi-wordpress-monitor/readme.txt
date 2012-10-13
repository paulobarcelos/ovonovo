=== WordPress File Monitor ===
Contributors: mattwalters
Donate link: http://mattwalters.net/projects/
Tags: security, files, monitor, plugin
Requires at least: 3.0
Tested up to: 3.0
Stable tag: 1.0.0

Monitor files under your WordPress installation for changes.  When a change occurs, be notified via email.

== Description ==

Monitors your WordPress installation for added/deleted/changed files.  When a change is detected an email alert can be sent to a specified address.

*Features*

- Monitors file system for added/deleted/changed files
- Sends email when a change is detected
- Multiple email formats for alerts
- Administration area alert to notify you of changes in case email is not received
- Ability to monitor files for changes based on file hash or timestamp
- Ability to exclude directories from scan (for instance if you use a cacheing system that stores its files within the monitored zone)
- Site URL included in notification email in case plugin is in use on multiple sites

Sorry for the delayed release for working with WordPress 3.0.  *NOTE* I haven't tested the latest version with multi-site yet, only single site.

== Installation ==

* Upload to a directory named "wordpress-file-monitor" in your wp-content/plugins/ directory.
* Visit Settings page under Settings -> WordPress File Monitor in your WordPress Administration Area
* Configure plugin options
* Optionally change the path to the Site Root.  If you install WordPress inside a subdirectory for instance, you could set this to the directory above that to monitor files outside of the WordPress installation.

== Changelog ==

= 1.0 =

Original release.