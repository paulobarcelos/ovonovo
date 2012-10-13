=== Online Backup for WordPress ===
Contributors: Driskell
Tags: backup, online backup, wordpress backup, database backup, db backup, mysql backup, free backup
Requires at least: 2.8.6
Tested up to: 3.4.1
Stable tag: 2.2.6

Online Backup for WordPress allows you to easily backup your WordPress site with encryption to email, download or free 100 MB secure online storage.

== Description ==

Backup Technology's free [Online Backup](http://www.backup-technology.com/) plugin provides protection for WordPress sites and their data. With the vast amount of time and investment that goes into running a blog or website, it is essential that a simple system is available for backing up.

Unlike other plugins, Online Backup for WordPress can even encrypt your backup data to keep it secure while it is downloaded, emailed, and even while it is stored.

The plugin can backup your site's database (containing settings, pages, posts and comments) as well as its filesystem (containing media, attachments, themes and plugins) to any one of three places:

1. A downloadable ZIP file
1. Your email inbox
1. Backup Technology's secure data centres with 100 MB **free** online storage space

**Online Backup for WordPress Features:**

* Easy to use
* Configuration checklist to help you get started
* Schedule daily or weekly backups to email or free online storage
* Download on-demand backups in ZIP file
* Backup only files that have changed since the last backup
* Full AES encryption support
* Exclude files and folders
* Exclude comments in trash or marked as spam
* Backup the WordPress parent folder if your WordPress blog is only part of your website
* Free online storage available in secure data centres
* Support forum is actively monitored to help users with any issues
 
*Download the plugin and protect your blog now!*
 
Visit Backup Technology's website for more information on their services for WordPress, Online Backup and [Disaster Recovery](http://www.backup-technology.com/)

== Installation ==

Step 1: Install the plugin.

Step 2: Go to the Tools -> Online Backup page and click General Settings. The defaults are usually fine, but you can tailor to your needs and choose any database content, files or folders that you don't wish to backup.

Step 3: **[Optional]** Register for your 100 MB of free online storage space on our [wordpress backup portal](https://wordpress.backup-technology.com/ "Online Backup for WordPress Portal") and connect the plugin to your storage by clicking Online Backup Settings on the Tools > Online Backup page, and entering the username and password you just registered.

Step 4: Schedule when to run your backup by clicking Schedule.

**You can find an excellent video guide (kindly donated by Jupiter Jim) that explains how to install and setup the plugin for backup to our online vault, at the following website:**

http://jupiterjimsmarketingteam.com/online-backup-wordpress-plugin-backup-technology-video-tutorial/

== Screenshots ==

1. Online Backup for WordPress dashboard.
2. On-demand backup.
3. Running an online backup

== Support ==

For answers to common queries and solutions to common problems, check out our FAQ at https://wordpress.backup-technology.com/FAQ.

If you encounter any issues not covered in the FAQ, please start a new topic in the plugin's support forum [here](http://wordpress.org/support/plugin/wponlinebackup). Backup Technology monitor the forum every few days and will be able to provide you with assistance there.

Feedback and feature requests are also very welcome in the forum.

**Please note, we are only able to provide technical support on the WordPress forum mentioned above, and not by email, telephone or the contact forms on our website.**

*We hope that you find our plugin useful! Thank you.*

== Changelog ==

= 2.2.6 =
* File size of the backup when sending via email is reported to the event log before attempting to send
* Support forum link is updated in the Help tab
* Fix a problem on some installations where the plugin always reports that a backup is already running, when one isn't
* Minor tweaks

= 2.2.5 =
* Encryption keys are now masked; however, a checkbox is provided to reveal them if required
* Prevent timeout during download of manual backup files
* Fix an issue that can cause email backups to be sent that are not readable
* Fix a PHP warning when starting manual backups that can crash the page on some strict error configurations
* Other minor tweaks

= 2.2.4 =
* Fix a database error that sometimes occurs during filesystem backup
* Fix schedule start time when WordPress is using a different time-zone
* Improve detection of blog URL in cases where plugins may be filtering it
* Fix backups that randomly ran for longer than normal due to irregular MySQL configurations
* Fix more junk errors

= 2.2.3 =
* Fix infinite loop on some Windows installations when downloading a backup file through the plugin
* Fix an issue that was preventing the plugin from pausing when it needed to wait for the server
* Fixed the retry of requests to the online backup vault, which were not working correctly when introduced in 2.2.2

= 2.2.2 =
* Tidied up error reporting on filesystem errors and added better file size reporting (it would sometimes appear as Array)
* Symbolic links are now skipped instead of being followed to fix problems with symbolic link loops and default exclusions being effectively ignored due to alternative paths to the WordPress installation being followed
* Fix failures with background backups on installations accessed from different URLs due to services such as CloudFlare
* Requests to the online backup vault are now retried up to 3 times to mitigate issues resulting from transient network failures
* Writes to the backup data files are now retried if they do not fully complete to mitigate filesystem errors such as "Write to <file> partially completed"
* More code changes to resolve "Junk received" errors during online backups
* Display times based on the local time of the blog, and not in UCT
* Fix "Unexpected stop" scheduled activities appearing in the activity log
* Available and used quota is now visible on the Online Backup settings page (if not visible, running a backup or reconnecting the plugin to the online vault will make it appear)

= 2.2.1 =
* Temporary directory automatic creation was broken by the improved error reporting, it is now working again
* Plugin no longer reports on Decrypt a Backup that the tmp/decrypt folder must be created manually if no backup processes have ever run
* Fixed plugin on some PHP 4 hosts where it would sit and hang at waiting for backup to start
* Updated legend on Activity Log page to include Download and Online backup types
* Tweak plugin description in readme and in plugin
* Various minor tweaks and fixes

= 2.2.0 =
* Improved error reporting; solutions to common problems will be reported in the event log to ease self-diagnosis
* Added the ability to stop a running backup
* Added the ability to exclude arbitrary folders and files from the filesystem backup
* Fixed a progress bar display issue in Safari
* More aggressive management of backup data files
* Improved handling of timeouts
* The server would report junk received if the blog description was longer than 255 characters in length during online backup
* Fixed issue where sometimes a file would be counted twice in the activity log file counts
* Encrypted backup files can now be uploaded via FTP and decrypted using the plugin
* Timeouts can no longer cause backup corruptions
* AES128 and AES192 are now fixed (they were acting just like AES256 since 2.0.0)
* Fix broken error reporting causing timeouts when plugin runs on PHP 4
* Backup starts much quicker with JavaScript enabled
* Backup progress can now auto-refresh when no JavaScript
* Tested with WordPress 3.3 and 3.3.1
* Tweaked plugin description
* Various minor tweaks and fixes

= 2.1.2 =
* Fix miscalculated backup transfer size

= 2.1.1 =
* Fixed a race condition that triggered a "Received junk" error from the server because it started retrieving the backup quicker than the backup could save its status to the database
* Improved logging of "Received junk" errors during online backup to help squash more of them

= 2.1.0 =
* Filesystem is now enabled in schedule by default to simplify initial configuration
* Fixed "Last chance to enable encryption" warning when connecting plugin to the vault for the first time
* Encryption details are only saved to the online vault AFTER a backup has been run
* Fixed missing cdrbuffer.php errors
* Fixed incorrect .rc extension from zip file download
* Fixed broken PBKDF2 when PHP version is lower than 5.3
* Backups are now more reliable and finish quicker on sites with low visitor counts that resulted in constant Unexpected Stop and Timeout problems
* Backups are now more reliable on sites that previously experienced memory exhaustion during database backup that resulted in constant Unexpected Stop and Timeout problems
* Fixed broken installation on servers with InnoDB as the default storage engine
* File backup schema is now automatically set to UTF-8 to resolve issues with international filenames
* Backup transfer to the online vault is now more accurate when WordPress is in a subfolder
* Fixed broken backup when the parent folder of WordPress is inaccessible
* Minor tweaks

= 2.0.2 =
* Fix broken backup on fresh installations of 2.0.1. Workaround is to go to General Settings and save.

= 2.0.1 =
* Simplified and improved user interface further
* Activity logs are now only kept for 6 months, and an option has been added to change this value
* Blog title and description are now correctly displayed in the backup vault Portal
* Large files that cause the backup to timeout are now skipped after two failed attempts
* Timeouts on relatively slow servers (an issue introduced in 2.0.0) are now fixed
* Minor tweaks

= 2.0.0 =
* Fixed a parse error on PHP4 servers
* Improved error reporting of Online Backup settings page

= 2.0.0rc2 =
* Fixed an issue where files compressed on PHP 5.3.x could not be accessed

= 2.0.0rc1 =
* Filesystem backup feature added with option to additionally backup the parent folder
* Added the ability to skip comments marked as spam and trash
* Backup files are now generated as ZIP files for universal compatibility
* Filesystem backup to the online servers is now incremental
* Performance of compression and encryption has been greatly improved by performing on-the-fly
* Better handling and recovery of time outs
* Activity log now included with events for all past activities
* Improved user interface
* Temporary directory is now only used if absolutely necessary
* Automatic detection of temporary directory when it is absolutely necessary
* Multiple blogs can now be backed up with the same online account, each with 100MiB of quota (only using 2.0.0 and later, 1.0.9 and earlier still require unique accounts)
* Point in time snapshots of the WordPress site can now be downloaded online (using plugin 2.0.0 backup data only)
* The new plugin will start backing up your blog as if it were a different blog, so you will begin a whole new 100MiB quota. Database backups from 1.0.9 and earlier will still be available online under a separate "Blog" entry. This is due to major changes in how the data is stored across the data centres.
* Blog URL no longer needs to be configured on the online Portal - it is picked up automatically
* Various other improvements and additions

= 1.0.91 =
* Fixed deprecation error messages when WordPress DEBUG mode is enabled

= 1.0.9 =
* Fixed an issue where encryption could add extra bytes to the end of the compressed file making decompression fail in some compression programs
* Fix issue that could cause password to show as invalid even though it IS valid and online backups do actually work
* Fix for scheduling issue on some servers.

= 1.0.8 =
* In some setups, after a restore, the plugin wouldn't function correctly.
* Improved protection added to the backup file transmission to prevent other plugins adding output.

= 1.0.72 =
* Fixed a fatal error when saving settings.

= 1.0.71 =
* Accounted for WordPress's strict magic GPC quotes.

= 1.0.7 =
* Fixed an issue where one would not be able to disable encryption.
* Added a potential fix for issues on Windows servers.

= 1.0.6 =
* Fixed an issue on WordPress sites where WordPress is not at the root of the domain. (Thanks Richard Benwell for pointing this out and providing a fix!)
* Super cache was breaking the backup fetch. Added a workaround.
* Fixed the uninstaller. (Thanks Chris Larson for pointing this out!)
* Fixes the junk error - it will now report the correct error about temporary directory been cleared. (Thanks Ian Grindey and those who used 1.0.5.1d!)
* Download link now correctly downloads over HTTPS if FORCE_SSL is enabled.
* "Table has no PRIMARY key! Some rows could get missed." no longer shows for tables with UNIQUE keys.
* Various other minor improvements.

= 1.0.5 =
* Fixed so the plugin works in a PHP4 environment.
* Fixed an issue with the download button on manual backups.
* Added a See FAQ link to the "Table does not have PRIMARY key!" warning.
* Improved layout of a few pages to make them easier to understand.
* Changed scheduling so one can configure the exact day, hour or minute for the backup.
* Added a fix for failed online backups and multiple backups getting triggered by wp-cron.
* Added a view log link to view the result of the last backup.
* Adjusted a few error messages to contain possible solutions.
* Various other minor bug fixes and improvements.

= 1.0.3 =
* Fixed an issue where backups would always fail if the server did not support encryption.

= 1.0.2 =
* Minor tweaks and bug fixes.

== Upgrade Notice ==

= 2.0.0 =
With version 2.0 you can now backup your blog's files!
WARNING: With version 2.0 you will no longer be able to send a backup to both email and the online vault at the same time in a scheduled backup. If you currently have both set in your schedule, the email option will be removed upon upgrade.

= 1.0.71 =
This update addresses issues with disabling encryption and issues running on Windows servers.

= 1.0.6 =
Fixes issues with wp_super_cache plugin and sites where WordPress is in a subfolder. Also fixes the junk error so it correctly reports the real error.

= 1.0.5 =
Fixes the failed backup issues some people were experiencing.
NOTE: The plugin no longer uses the built-in WordPress schedules and now has it's own options that are much more configurable: Weekly, Daily, Twice Daily, Four Times Daily and Hourly. The plugin will do its best to allocate the nearest schedule to the one you currently have. However, we recommend you check the schedule settings afterwards to ensure the one selected is the best for your requirements.

= 1.0.3 =
Fixes an issue where backups would always fail if the server did not support encryption.
