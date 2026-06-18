app_name=lantern

.PHONY: all
all: build

.PHONY: deps
deps:
	npm ci

.PHONY: build
build:
	npm run build

.PHONY: lint
lint:
	npm run lint

# Extract translatable strings from PHP ($l->t / $l->n) and the front end
# (t('…') / n('…','…')) into translationfiles/templates/lantern.pot. English is
# the source language and ships without a catalog; other locales are compiled
# into l10n/<lang>.{js,json}. Requires GNU gettext (xgettext, msgcat).
.PHONY: l10n
l10n:
	mkdir -p translationfiles/templates
	xgettext --language=PHP --from-code=UTF-8 --keyword=t:1 --keyword=n:1,2 \
		--package-name=$(app_name) -o translationfiles/templates/php.pot \
		$$(find lib templates -name '*.php')
	xgettext --language=JavaScript --from-code=UTF-8 --keyword=t:1 --keyword=n:1,2 \
		--package-name=$(app_name) -o translationfiles/templates/js.pot \
		$$(find src -name '*.js' -o -name '*.vue')
	msgcat --use-first translationfiles/templates/js.pot translationfiles/templates/php.pot \
		-o translationfiles/templates/$(app_name).pot
	rm -f translationfiles/templates/php.pot translationfiles/templates/js.pot
	@echo "Template: translationfiles/templates/$(app_name).pot"
	@echo "NOTE: the official Nextcloud translationtool.phar is the production"
	@echo "      extractor; this target mirrors it for local checks."

# Sign + package for the app store (requires your app cert in ~/.nextcloud).
.PHONY: appstore
appstore: build
	mkdir -p build/artifacts
	rsync -a \
		appinfo lib templates img css js l10n \
		build/artifacts/$(app_name)/
	tar -czf build/artifacts/$(app_name).tar.gz -C build/artifacts $(app_name)

# Stage 1 release: build the frontend, then package ONLY the runtime files
# (including the built js/) into a drop-in-installable tarball. Excludes
# node_modules, src, tests, and build scaffolding.
.PHONY: release
release: build
	rm -rf build/release/$(app_name)
	mkdir -p build/release/$(app_name)
	cp -r appinfo lib templates img css js l10n COPYING LICENSE CHANGELOG.md README.md \
		build/release/$(app_name)/
	cp -r docs build/release/$(app_name)/docs
	tar -czf build/release/$(app_name).tar.gz -C build/release $(app_name)
	@echo "Release tarball: build/release/$(app_name).tar.gz"
	@echo "NOTE: run after 'npm run build' so js/ exists; sign before store upload."
