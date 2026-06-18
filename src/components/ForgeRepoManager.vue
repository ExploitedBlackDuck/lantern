<script>
import { fetchForgeRepos, addForgeRepo, removeForgeRepo } from '../api.js'
import { t } from '../l10n.js'

export default {
	name: 'ForgeRepoManager',
	emits: ['changed'],
	data() {
		return { open: false, mine: [], kind: 'github', owner: '', repo: '', project: '', host: '', token: '', name: '', status: '', busy: false }
	},
	computed: {
		isGitlab() {
			return this.kind === 'gitlab'
		},
		// The slug sent to the backend: owner/repo for GitHub, the project path
		// (which may be nested groups) for GitLab.
		slug() {
			return this.isGitlab
				? this.project.trim().replace(/^\/+|\/+$/g, '')
				: `${this.owner.trim()}/${this.repo.trim()}`
		},
	},
	mounted() {
		this.refresh()
	},
	methods: {
		async refresh() {
			try {
				this.mine = await fetchForgeRepos()
			} catch (e) {
				this.mine = []
			}
		},
		forgeLabel(r) {
			return r.kind === 'gitlab' ? 'GitLab' : 'GitHub'
		},
		async add() {
			if (this.isGitlab ? !this.project.trim() : (!this.owner.trim() || !this.repo.trim())) {
				this.status = this.isGitlab ? t('Enter the project path (group/.../project).') : t('Enter both an owner and a repository name.')
				return
			}
			this.busy = true
			this.status = t('Adding…')
			try {
				await addForgeRepo(this.kind, this.slug, this.host.trim(), this.token.trim(), this.name.trim())
				this.owner = this.repo = this.project = this.host = this.token = this.name = ''
				this.status = ''
				this.open = false
				await this.refresh()
				this.$emit('changed')
			} catch (e) {
				this.status = e?.response?.data?.error || t('Could not add that repository.')
			} finally {
				this.busy = false
			}
		},
		async remove(id) {
			try {
				await removeForgeRepo(id)
				await this.refresh()
				this.$emit('changed')
			} catch (e) { /* leave list as-is */ }
		},
	},
}
</script>

<template>
	<div class="lantern-myrepos">
		<ul v-if="mine.length" class="lantern-tree" role="list">
			<li v-for="r in mine" :key="r.id" class="lantern-myrepos-row">
				<span class="name">{{ r.name }} <span class="lantern-myrepos-invalid">({{ forgeLabel(r) }}: {{ r.slug }})</span></span>
				<button type="button" class="lantern-myrepos-remove" :aria-label="t('Remove {name}', { name: r.name })" @click="remove(r.id)">×</button>
			</li>
		</ul>

		<button type="button" class="lantern-link lantern-myrepos-toggle" @click="open = !open">
			{{ open ? t('− Cancel') : t('+ Add a GitHub or GitLab repository') }}
		</button>

		<div v-if="open" class="lantern-myrepos-form">
			<label class="lantern-forge-kind">
				<span>{{ t('Forge') }}</span>
				<select v-model="kind" :aria-label="t('Forge type')">
					<option value="github">GitHub</option>
					<option value="gitlab">GitLab</option>
				</select>
			</label>

			<template v-if="isGitlab">
				<input v-model="host" type="text" :placeholder="t('instance URL (blank = gitlab.com)')" :aria-label="t('GitLab instance URL')">
				<input v-model="project" type="text" :placeholder="t('group/subgroup/project')" :aria-label="t('GitLab project path')">
			</template>
			<template v-else>
				<input v-model="owner" type="text" :placeholder="t('owner (user or org)')" :aria-label="t('GitHub owner')">
				<input v-model="repo" type="text" :placeholder="t('repository')" :aria-label="t('GitHub repository')">
			</template>

			<input v-model="token" type="password" :placeholder="t('personal access token (private repos)')" :aria-label="t('Access token')" autocomplete="off">
			<input v-model="name" type="text" :placeholder="t('Display name (optional)')" :aria-label="t('Display name')">
			<div class="lantern-myrepos-actions">
				<button type="button" class="primary" :disabled="busy" @click="add">{{ t('Add') }}</button>
			</div>
			<p class="lantern-myrepos-status">
				{{ t('The token is stored encrypted and used only to read this repo. A public repo can be added without one.') }}
			</p>
			<p v-if="status" class="lantern-myrepos-status">{{ status }}</p>
		</div>
	</div>
</template>
