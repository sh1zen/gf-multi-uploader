=== Multi Uploader for Gravity Forms ===
Contributors: sh1zen
Tags: uploader, gravity forms, file uploader, gravity forms uploader, plupload
Donate link: https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+GF-Multi-Uploader.&currency_code=EUR
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.7
License: GNU v3.0 License
URI: https://github.com/sh1zen/wp-optimizer/blob/master/LICENSE

Chunked Multiple file uploads, from images, videos to pdf. Files stored in WP Media Library.

== Description ==

This is an advanced upload plugin for those who need a little more than the default multi file upload of Gravity Forms. 

The plugin options page provides you with granular control over many Plupload parameters from file extension filters to chunked uploading and runtimes. 

All files are uploaded to the WordPress media library on successful form submission making for easy access and management. 

**FEATURES**

* ***Safety:*** validation of both file extension and mime type.
* ***Privacy:*** filenames changed once added to media library.
* ***Advanced Customization:*** many options and many hooks to modify any plugin rule.
* ***Large File Support:*** enabled by chunked file uploads.
* ***Media library integration:*** all files are uploaded to the WordPress media library on successful form submission making for easy access and management.
* ***Entry list creation integration:***  A list of all correctly uploaded files, with relative link.

**DONATIONS**

This plugin is free and always will be, but if you are feeling generous and want to show your support, you can buy me a
beer or coffee [here](https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+GF-Multi-Uploader.&currency_code=EUR), I will really appreciate it.

== Installation ==

This section describes how to install the plugin. In general, there are 3 ways to install this plugin like any other
WordPress plugin.

**1. VIA WORDPRESS DASHBOARD**

* Click on ‘Add New’ in the plugins dashboard
* Search for 'WP Optimizer'
* Click ‘Install Now’ button
* Activate the plugin from the same page or from the Plugins Dashboard

**2. VIA UPLOADING THE PLUGIN TO WORDPRESS DASHBOARD**

* Download the plugin to your computer
  from [https://wordpress.org/plugins/wp-optimizer/](https://wordpress.org/plugins/gravity-forms-multi-uploader/)
* Click on 'Add New' in the plugins dashboard
* Click on 'Upload Plugin' button
* Select the zip file of the plugin that you have downloaded to your computer before
* Click 'Install Now'
* Activate the plugin from the Plugins Dashboard

**3. VIA FTP**

* Download the plugin to your computer
  from [https://wordpress.org/plugins/wp-optimizer/](https://wordpress.org/plugins/gravity-forms-multi-uploader/)
* Unzip the zip file, which will extract the main directory
* Upload the main directory (included inside the extracted folder) to the /wp-content/plugins/ directory of your website
* Activate the plugin from the Plugins Dashboard

**FOR MULTISITE INSTALLATION**

* Log in to your primary site and go to "My Sites" » "Network Admin" » "Plugins"
* Install the plugin following one of the above ways
* Network activate the plugin

**INSTALLATION DONE, A NEW LABEL WILL BE DISPLAYED ON YOUR ADMIN MENU**

== Frequently Asked Questions ==

= What to do if I run in some issues after upgrade? =

Deactivate the plugin and reactivate it, if this doesn't work try to uninstall and reinstall it. That should
work! Otherwise, go to the new added module "Setting" and try a reset.

= Change Plupload Language Dynamically =

Use 'gfmu_uploader_i18n_script' filter to select language for Plupload:

add_filter( 'gfmu_uploader_i18n_script', 'plupload_i18n' );
function plupload_i18n( $i18n_filename ) {
    return 'es';
}

== Changelog ==

= 1.1.7 =

* fixed issue on GF_MultiUploader_Field.class.php filename
* extended support to WordPress 6.8
* updated translations

= 1.1.5 =

* fixed Arbitrary File Upload CVE-2025-23921

= 1.1.3 =

* extended support to WordPress 6.7
* updated translations
* fixed some bugs

= 1.1.2 =

* extended support to WordPress 6.5
* updated translations
* fixed filesize and file modified time bugs

= 1.1.0 =

* extended support to WordPress 6.4
* extended support to PHP 8.3
* moved minimum PHP supported version to 7.4
* updated translations
* added support for mp4 media as suggested

= 1.0.10 =

* extended support to WordPress 6.3
* extended support to PHP 8.2
* moved minimum PHP supported version to 7.0
* moved minimum WordPress supported version to 5.0
* updated translations
* improved performances

= 1.0.9 =

* extended support to WordPress 6.1
* updated translations

= 1.0.8 =

* improved performances
* extended support to WordPress 6.0

= 1.0.7 =

* extended supported image formats to webp

= 1.0.6 =

* fixed some bugs
* tested up to WordPress 5.9 and PHP 8.1

= 1.0.3 =

* updated translations.
* improved upload performances
* fixed a bug reported during delete operation

= 1.0.0 =

* first public release.

== Hooks ==

Filters:
* 'gfmu_plugin_locale'
* 'gfmu_before_attach_uploads'
* 'gfmu_maybe_insert_attachment'
* 'gfmu_server_validation_args'
* 'gfmu_insert_attachment_args'
* 'gfmu_field_options'
* 'gfmu_save_entry'
