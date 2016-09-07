.PHONY:
.ONESHELL:
devpush:
	DIRTY=0
	cd htdocs/typo3conf/ext/bootstrap_package
	if git commit -a; then git push && DIRTY=1; fi
	cd ../../../../vendor/helhum/typo3-console
	if git commit -a; then git push && DIRTY=1; fi
	cd ../../../vendor/typo3fluid/fluid
	if git commit -a; then git push && DIRTY=1; fi
	cd ../../../vendor/typo3/cms
	if git commit -a; then git push && DIRTY=1; fi
	cd ../../../htdocs/typo3conf/ext/bootstrap_package
	if git commit -a; then git push && DIRTY=1; fi
	cd ../../../..
	[ $$DIRTY -eq 1 ] && COMPOSER_PROCESS_TIMEOUT=2000 composer update --ignore-platform-reqs --prefer-source
	git commit -a && git push platform develop

.PHONY:
deploylog: devpush
	platform ssh -- 'less /var/log/deploy.log'
