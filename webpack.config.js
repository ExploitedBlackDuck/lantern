// Builds two entry points (main app + admin settings) on top of the shared
// Nextcloud webpack/Vue config. IMPORTANT: @nextcloud/webpack-vue-config
// prepends the app id to each entry name, so entry "main" emits
// js/lantern-main.js and entry "admin" emits js/lantern-admin.js — which is
// exactly what Util::addScript('lantern','lantern-main') /
// script('lantern','lantern-admin') request. Naming the entries
// "lantern-main"/"lantern-admin" would double the prefix
// (js/lantern-lantern-main.js) and silently 404 the whole frontend.
const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
}

module.exports = webpackConfig
