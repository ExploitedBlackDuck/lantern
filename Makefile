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

# Sign + package for the app store (requires your app cert in ~/.nextcloud).
.PHONY: appstore
appstore: build
	mkdir -p build/artifacts
	rsync -a \
		appinfo lib templates img css js \
		build/artifacts/$(app_name)/
	tar -czf build/artifacts/$(app_name).tar.gz -C build/artifacts $(app_name)

# Stage 1 release: build the frontend, then package ONLY the runtime files
# (including the built js/) into a drop-in-installable tarball. Excludes
# node_modules, src, tests, and build scaffolding.
.PHONY: release
release: build
	rm -rf build/release/$(app_name)
	mkdir -p build/release/$(app_name)
	cp -r appinfo lib templates img css js COPYING LICENSE CHANGELOG.md README.md \
		build/release/$(app_name)/
	cp -r docs build/release/$(app_name)/docs
	tar -czf build/release/$(app_name).tar.gz -C build/release $(app_name)
	@echo "Release tarball: build/release/$(app_name).tar.gz"
	@echo "NOTE: run after 'npm run build' so js/ exists; sign before store upload."
