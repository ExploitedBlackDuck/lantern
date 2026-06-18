import { createApp } from 'vue'
import AdminApp from './components/AdminApp.vue'
import { t, n } from './l10n.js'

// The admin form is a small Vue app. Initial config is delivered as data-*
// attributes on the mount point (see templates/admin.php). It persists via
// Lantern's own authenticated, admin-only routes; @nextcloud/axios attaches the
// CSRF token automatically.
function parseRepos(raw) {
	try {
		const v = JSON.parse(raw || '[]')
		return Array.isArray(v) ? v : []
	} catch (e) {
		return []
	}
}

document.addEventListener('DOMContentLoaded', () => {
	const el = document.getElementById('lantern-admin-app')
	if (!el) return
	const app = createApp(AdminApp, {
		initialRepos: parseRepos(el.dataset.repos),
		initialAllowedBase: el.dataset.allowedBase || '',
		initialGitPath: el.dataset.gitPath || '',
	})
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
