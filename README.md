TYPO3 CMS on Platformsh
=====
Installation:
* `git clone https://github.com/ksjogo/platformsh-typo3-template.git`
* `cd platformsh-typo3-template`
* Go to http://platform.sh and create a new project, select *import your existing code* when asked
* Copy the git remote config from the follow-up form and run it - looks like
  * `git remote add platform XYZ@git.eu.platform.sh:XYZ.git`
* Add your SSH key if necessary
  * https://accounts.platform.sh/user/
  * `cat ~/.ssh/id_rsa.pub`
  * *Account Settings* *SSH Keys*
* `git push -u platform master`
* Click *finish* in the platformsh dialog
* 🐢+☕️
* Click *access site*
* Click the url
* Add `/typo3` to your browser adress bar [ctrl-l \<end\> /typo3]
* [Maybe reload due to an updating gateway]
* Login with *admin* *password* [Change your password]
