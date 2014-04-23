=== Google Authenticator - Per User Prompt ===
Contributors:      iandunn
Donate link:       http://nhmin.org
Tags:              google authenticator,two factor authentication
Requires at least: 3.5
Tested up to:      3.9
Stable tag:        0.4
License:           GPLv2 or Later

Modifies the Google Authenticator plugin so that only users with 2FA enabled are prompted for the authentication token.


== Description ==

The [Google Authenticator](http://wordpress.org/plugins/google-authenticator/) plugin is a great way to add two-factor authentication to your site, but it does have one major drawback: it asks every user for the authentication token, regardless of whether they have 2FA enabled or not. This can be confusing for users, which prevents some administrators from using the plugin on multi-user sites.
  
This plugin modifies the way that Google Authenticator behaves so that only users who have it enabled are prompted for the token. If a user doesn't have it enabled, then they'll proceed directly to the Administration Panels; if they do have it enabled then they'll be prompted to enter their 2FA code.


== Installation ==

For help installing this (or any other) WordPress plugin, please read the [Managing Plugins](http://codex.wordpress.org/Managing_Plugins) article on the Codex.

Once the plugin is installed and activated, you don't need to do anything else.


== Frequently Asked Questions ==

= Does this replace the Google Authenticator plugin? =
No, this is built on top of the Google Authenticator plugin and requires it in order to work.

= Is this plugin secure? =
I've done my best to ensure that it is, but just in case I missed anything [I also offer a security bounty](https://hackerone.com/iandunn-projects/) for any vulnerabilities that can be found and privately disclosed in any of my plugins.

= What should I do if I can't login? =
Since this plugin integrates tightly with the Google Authenticator plugin, it's possible that at some point in the future, changes in Google Authenticator will break the customized login process that this plugin implements. If that happens, I'll release an updated version of this plugin to make it compatible with the new changes.
 
You may have difficulty installing the updated version if you can't login, though, so you'll need to deactivate this plugin by some alternate means, and then update it before re-activating it.

There are several alternate methods of deactivating the plugin: you can [delete it via S/FTP, or by changing a database option in phpMyAdmin](http://www.wpbeginner.com/plugins/how-to-deactivate-all-plugins-when-not-able-to-access-wp-admin/), or you can ask your hosting company to delete the plugin for you. 


== Screenshots ==

1. The token prompt no longer appears on the initial login screen
2. If a user has two factor auth enabled, they'll see the prompt on a secondary screen, after they login


== Changelog ==

= v0.4 (2013-12-30) =
* [UPDATE] Added support for new application password format in Google Authenticator 0.45

= v0.3 (2013-12-20) =
* [NEW] Focus automatically set on token input field

= v0.2 (2013-12-11) =
* [FIX] User with valid username/password no longer temporarily logged in before entering 2FA token. Prevents leaking auth cookies. props cathyjf

= v0.1 (2013-12-10) =
* [NEW] Initial release


== Upgrade Notice ==

= 0.4 =
This version adds support for the new application password format that will be used in Google Authenticator 0.45.

= 0.3 =
This version automatically focuses on the 2FA token input field, so that you don't have to click on it or tab to it.

= 0.2 =
This version contains a critical security fix for a bug that would allow an attacker with a valid username/password to bypass the 2FA token prompt. Please upgrade immediately.

= 0.1 =
Initial release.
