import { generateFilePath } from '@nextcloud/router'
import { createApp } from 'vue'
import App from './App.vue'

// Code-split chunks (highlight.js languages, the Markdown renderer) must be
// fetched from the app's REAL static dir. Webpack's `publicPath: 'auto'`
// resolves to /apps/lantern/js/ — where Nextcloud routes the entry bundle — but
// the sibling chunks are only served from /custom_apps/.../lantern/js/, so they
// 404 (breaking README rendering and syntax highlighting). Pin the public path
// explicitly. Must run before any dynamic import() fires.
// eslint-disable-next-line camelcase, no-undef
__webpack_public_path__ = generateFilePath('lantern', '', 'js/')

const el = document.getElementById('lantern')
if (el) {
	// First-paint context from the server (see templates/main.php) so the empty
	// state can address admins vs. regular users correctly.
	createApp(App, {
		isAdmin: el.dataset.isAdmin === '1',
		settingsUrl: el.dataset.settingsUrl || '',
	}).mount(el)
}
