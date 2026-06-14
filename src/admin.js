import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Persists admin config via Lantern's own authenticated, admin-only route.
// This deliberately does NOT use the provisioning_api OCS endpoint (which would
// need generateOcsUrl + an OCS-APIRequest header). @nextcloud/axios attaches
// the CSRF token for this index.php-routed POST automatically.
function save(repos, allowedBase, gitPath) {
	return axios.post(generateUrl('/apps/lantern/settings/save'), {
		repos,
		allowedBase,
		gitPath,
	})
}

document.addEventListener('DOMContentLoaded', () => {
	const btn = document.getElementById('lantern-save')
	if (!btn) return
	btn.addEventListener('click', async () => {
		const status = document.getElementById('lantern-save-status')
		const repos = document.getElementById('lantern-repos').value
		const allowedBase = document.getElementById('lantern-base').value
		const gitPath = document.getElementById('lantern-gitpath').value
		status.textContent = ' Saving…'
		try {
			JSON.parse(repos || '[]') // fail fast client-side
		} catch (e) {
			status.textContent = ' Invalid JSON — not saved.'
			return
		}
		try {
			const { data } = await save(repos, allowedBase, gitPath)
			status.textContent = ` Saved (${data.count} repositories).`
		} catch (e) {
			const msg = e?.response?.data?.error || 'Save failed.'
			status.textContent = ' ' + msg
		}
	})
})
