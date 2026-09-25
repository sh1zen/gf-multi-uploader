# GF Multi Uploader
Welcome to the Gravity Forms Multi Uploader repository on GitHub.

[![Author](https://img.shields.io/badge/author-sh1zen-brightgreen.svg)](https://sh1zen.github.io/)
![License: GPLv2 or later](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)
[![Donate](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU)
[![Repo Link](https://img.shields.io/badge/Repo-Link-black.svg)](https://github.com/sh1zen/gf-multi-uploader)


Here you can browse the source, look at open issues and keep track of development.

If you are not a developer, please use the [GF-Multi-Uploader plugin page](https://wordpress.org/plugins/gf-multi-uploader/) on WordPress.org.

## Description

This is the Gravity Forms uploader plugin for those who need a little more than the default multi file upload of Gravity Forms v1.6+ 

Chunked uploads require a writable PHP system temporary directory outside the WordPress document root. After upgrading to 1.1.10, users with unsubmitted forms must upload their files again so the plugin can assign ownership tokens.
The plugin limits temporary staging to 200 pending files and a disk budget tied to the upload size limit.
Private chunk buffers have the same disk budget and a limit of 200 active uploads.

Run the isolated upload regression checks with `php mini-test/upload-security.php`.

## Support
This repository is not suitable for support. Please don't use our issue tracker for support requests. Support can take 
place through the appropriate channel on [our community forum on wp.org](https://wordpress.org/support/plugin/gf-multi-uploader/).

Support requests in issues on this repository will be closed on sight.

