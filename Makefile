.ONESHELL:
.PHONY:
devpush:
	DIRTY=0
	cd vendor/helhum/typo3-console
	if git commit -a; then git push && DIRTY=1; fi
	cd ../../..
	[ $$DIRTY -eq 1 ] && COMPOSER_PROCESS_TIMEOUT=2000 composer update --ignore-platform-reqs --prefer-source
	git commit -a && git push platform master

.PHONY:
deploylog: devpush
	platform ssh -- 'less /var/log/deploy.log'
