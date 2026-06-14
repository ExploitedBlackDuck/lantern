<script>
import { fetchForgeRepos, addForgeRepo, removeForgeRepo } from '../api.js'

export default {
	name: 'ForgeRepoManager',
	emits: ['changed'],
	data() {
		return { open: false, mine: [], owner: '', repo: '', token: '', name: '', status: '', busy: false }
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
		async add() {
			if (!this.owner.trim() || !this.repo.trim()) {
				this.status = 'Enter both an owner and a repository name.'
				return
			}
			this.busy = true
			this.status = 'Adding…'
			try {
				await addForgeRepo(this.owner.trim(), this.repo.trim(), this.token.trim(), this.name.trim())
				this.owner = this.repo = this.token = this.name = ''
				this.status = ''
				this.open = false
				await this.refresh()
				this.$emit('changed')
			} catch (e) {
				this.status = e?.response?.data?.error || 'Could not add that repository.'
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
				<span class="name">{{ r.name }} <span class="lantern-myrepos-invalid">({{ r.owner }}/{{ r.repo }})</span></span>
				<button type="button" class="lantern-myrepos-remove" :aria-label="'Remove ' + r.name" @click="remove(r.id)">×</button>
			</li>
		</ul>

		<button type="button" class="lantern-link lantern-myrepos-toggle" @click="open = !open">
			{{ open ? '− Cancel' : '+ Add a GitHub repository' }}
		</button>

		<div v-if="open" class="lantern-myrepos-form">
			<input v-model="owner" type="text" placeholder="owner (user or org)" aria-label="GitHub owner">
			<input v-model="repo" type="text" placeholder="repository" aria-label="GitHub repository">
			<input v-model="token" type="password" placeholder="personal access token (private repos)" aria-label="Access token" autocomplete="off">
			<input v-model="name" type="text" placeholder="Display name (optional)" aria-label="Display name">
			<div class="lantern-myrepos-actions">
				<button type="button" class="primary" :disabled="busy" @click="add">Add</button>
			</div>
			<p class="lantern-myrepos-status">
				The token is stored encrypted and used only to read this repo. A public repo can be added without one.
			</p>
			<p v-if="status" class="lantern-myrepos-status">{{ status }}</p>
		</div>
	</div>
</template>
