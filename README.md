TYPO3 CMS on Platform.sh
=====
This is the offical example for running TYPO3 CMS v10 on platform.sh.

Refer to [typo3.org](https://typo3.org/) for information on TYPO3 CMS.

This example is maintained by
* [@bmack](https://github.com/bmack/) from [@b13](https://b13.com).

In the news:
* [strategic integration announcement](https://typo3.org/news/article/typo3-and-platformsh-announce-cloud-readiness-and-tech-preview-of-strategic-integration-ahead-of-t3/)
* [release at T3CON](https://typo3.org/news/article/typo3-conference-in-munich-typo3-cms-8-starting-today-with-platformsh-in-the-cloud/)

## Installation
### One-Click-Button
https://accounts.platform.sh/platform/trial/typo3/setup?branch=master

### Details
Have a look at the `.platform.app.yaml` file where an initial configuration and admin user is created.

Due to the nature of platform.sh read-only source code feature, the configuration files `PackageStates.php`
and `LocalConfiguration.php`, which are maintained by TYPO3, are symlinked from `public/typo3conf/` to
a mount located in the `var/` folder.

The installation process once calls

    php src/installer.php install:setup

after building the source code to set up the necessary configuration files, which are then available
to be used after deployment.

At first installation a file `var/installed.lock` is added to find out if the necessary database imports
need to be taken care of.

### TYPO3 Backend Access
* Click *access site*
* Click the url
* Add `/typo3` to your browser address bar [ctrl-l \<end\> /typo3]
* Login with *admin* *password* [Change your password]


## Adding TYPO3 Extensions/composer packages
* Execute `composer require PACKAGE`
* `git add -a && git push platform`
* Ensure to update your database in the Maintenance Area of TYPO3 to fully activate a new extension.


Due to incompatibilities of TYPO3 Console with TYPO3 v10 at the time of writing (Sep 2019), a manual script
to set up TYPO3 on platform.sh was created.