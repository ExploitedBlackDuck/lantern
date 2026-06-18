<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t, n } from '../l10n.js'

const url = (p) => generateUrl('/apps/lantern' + p)
let rowSeq = 0

export default {
	name: 'AdminApp',
	props: {
		initialRepos: { type: Array, default: () => [] },
		initialAllowedBase: { type: String, default: '' },
		initialGitPath: { type: String, default: '' },
	},
	data() {
		return {
			// Each row carries a stable key plus a transient test result.
			rows: this.initialRepos.map((r) => ({
				key: ++rowSeq,
				id: r.id || '',
				name: r.name || '',
				path: r.path || '',
				groups: Array.isArray(r.groups) ? r.groups.join(', ') : '',
				test: null, // { ok, reason } after a Test path check
			})),
			allowedBase: this.initialAllowedBase,
			gitPath: this.initialGitPath,
			saveStatus: '',
			saving: false,
		}
	},
	methods: {
		addRow() {
			this.rows.push({ key: ++rowSeq, id: '', name: '', path: '', groups: '', test: null })
		},
		removeRow(i) {
			this.rows.splice(i, 1)
		},
		async testRow(row) {
			row.test = { ok: null, reason: t('Testing…') }
			try {
				const { data } = await axios.post(url('/settings/validate-path'), {
					path: row.path,
					allowedBase: this.allowedBase,
				})
				row.test = { ok: data.ok, reason: data.reason }
			} catch (e) {
				row.test = { ok: false, reason: e?.response?.data?.error || t('Test failed.') }
			}
		},
		duplicateIds() {
			const seen = new Set()
			const dupes = new Set()
			for (const r of this.rows) {
				const id = r.id.trim()
				if (!id) continue
				if (seen.has(id)) dupes.add(id)
				seen.add(id)
			}
			return dupes
		},
		async save() {
			// Client-side guards mirror the server's so the admin gets an
			// immediate, specific message; the server re-validates regardless.
			for (const r of this.rows) {
				if (!r.id.trim() || !r.name.trim() || !r.path.trim()) {
					this.saveStatus = t('Every repository needs an id, a name and a path.')
					return
				}
			}
			if (this.duplicateIds().size) {
				this.saveStatus = t('Repository ids must be unique.')
				return
			}
			this.saving = true
			this.saveStatus = t('Saving…')
			const repos = JSON.stringify(this.rows.map((r) => ({
				id: r.id.trim(),
				name: r.name.trim(),
				path: r.path.trim(),
				groups: (r.groups || '').split(',').map((g) => g.trim()).filter(Boolean),
			})))
			try {
				const { data } = await axios.post(url('/settings/save'), {
					repos,
					allowedBase: this.allowedBase,
					gitPath: this.gitPath,
				})
				this.saveStatus = t('Saved ({repos}).', { repos: n('{n} repository', '{n} repositories', data.count, { n: data.count }) })
			} catch (e) {
				this.saveStatus = e?.response?.data?.error || t('Save failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<template>
	<div class="lantern-admin-form">
		<table class="lantern-admin-table">
			<thead>
				<tr>
					<th>{{ t('Id') }}</th>
					<th>{{ t('Display name') }}</th>
					<th>{{ t('Path on this server') }}</th>
					<th>{{ t('Restrict to groups') }}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(row, i) in rows" :key="row.key">
					<td><input v-model="row.id" type="text" placeholder="recon" :aria-label="t('Repository id')"></td>
					<td><input v-model="row.name" type="text" placeholder="Recon scripts" :aria-label="t('Display name')"></td>
					<td>
						<input v-model="row.path" type="text" class="lantern-admin-path"
							placeholder="/srv/git/recon" :aria-label="t('Repository path')"
							@input="row.test = null">
						<span v-if="row.test" class="lantern-admin-testresult"
							:class="{ ok: row.test.ok === true, bad: row.test.ok === false }">
							<template v-if="row.test.ok === true">✓ {{ row.test.reason }}</template>
							<template v-else-if="row.test.ok === false">✗ {{ row.test.reason }}</template>
							<template v-else>{{ row.test.reason }}</template>
						</span>
					</td>
					<td>
						<input v-model="row.groups" type="text" :placeholder="t('(all users)')" :aria-label="t('Restrict to groups (comma-separated)')">
					</td>
					<td class="lantern-admin-rowactions">
						<button type="button" @click="testRow(row)">{{ t('Test path') }}</button>
						<button type="button" class="lantern-admin-remove" @click="removeRow(i)"
							:aria-label="t('Remove repository')">{{ t('Remove') }}</button>
					</td>
				</tr>
				<tr v-if="!rows.length">
					<td colspan="5" class="lantern-admin-norows">{{ t('No repositories yet — add one below.') }}</td>
				</tr>
			</tbody>
		</table>

		<button type="button" @click="addRow">{{ t('+ Add repository') }}</button>

		<p class="lantern-admin-field">
			<label for="lantern-base">{{ t('Allowed base directory (optional)') }}</label><br>
			<input id="lantern-base" v-model="allowedBase" type="text" class="lantern-wide" placeholder="/srv/git">
			<br><span class="settings-hint">{{ t('If set, every repository path must sit inside this directory.') }}</span>
		</p>

		<p class="lantern-admin-field">
			<label for="lantern-gitpath">{{ t('Git binary path (optional)') }}</label><br>
			<input id="lantern-gitpath" v-model="gitPath" type="text" class="lantern-wide" placeholder="/usr/bin/git">
			<br><span class="settings-hint">{{ t('Leave blank to use') }} <code>git</code> {{ t("on the server's PATH. Set an absolute path if git isn't on PATH (e.g. in a container).") }}</span>
		</p>

		<button class="primary" :disabled="saving" @click="save">{{ t('Save') }}</button>
		<span class="lantern-admin-savestatus" aria-live="polite">{{ saveStatus }}</span>
	</div>
</template>
