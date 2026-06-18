<script>
import { fetchMyRepos, validateMyRepo, addMyRepo, removeMyRepo } from '../api.js'
import { t } from '../l10n.js'

export default {
	name: 'MyReposManager',
	emits: ['changed'],
	data() {
		return { open: false, mine: [], path: '', name: '', status: '', busy: false }
	},
	mounted() {
		this.refresh()
	},
	methods: {
		async refresh() {
			try {
				this.mine = await fetchMyRepos()
			} catch (e) {
				this.mine = []
			}
		},
		async test() {
			this.status = t('Checking…')
			try {
				const r = await validateMyRepo(this.path.trim())
				this.status = (r.ok ? '✓ ' : '✗ ') + r.reason
			} catch (e) {
				this.status = '✗ ' + t('Could not check that path.')
			}
		},
		async add() {
			const p = this.path.trim()
			if (!p) { this.status = t('Enter a folder path inside your Files.'); return }
			this.busy = true
			this.status = t('Adding…')
			try {
				await addMyRepo(p, this.name.trim())
				this.path = ''
				this.name = ''
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
				await removeMyRepo(id)
				await this.refresh()
				this.$emit('changed')
			} catch (e) { /* leave the list as-is on failure */ }
		},
	},
}
</script>

<template>
	<div class="lantern-myrepos">
		<ul v-if="mine.length" class="lantern-tree" role="list">
			<li v-for="r in mine" :key="r.id" class="lantern-myrepos-row">
				<span class="name">{{ r.name }}<span v-if="!r.valid" class="lantern-myrepos-invalid"> {{ t('(unavailable)') }}</span></span>
				<button type="button" class="lantern-myrepos-remove" :aria-label="t('Remove {name}', { name: r.name })" @click="remove(r.id)">×</button>
			</li>
		</ul>

		<button type="button" class="lantern-link lantern-myrepos-toggle" @click="open = !open">
			{{ open ? t('− Cancel') : t('+ Add a repo from your Files') }}
		</button>

		<div v-if="open" class="lantern-myrepos-form">
			<input v-model="path" type="text" :placeholder="t('Folder path in your Files, e.g. code/myproject')" :aria-label="t('Folder path')">
			<input v-model="name" type="text" :placeholder="t('Display name (optional)')" :aria-label="t('Display name')">
			<div class="lantern-myrepos-actions">
				<button type="button" @click="test">{{ t('Test') }}</button>
				<button type="button" class="primary" :disabled="busy" @click="add">{{ t('Add') }}</button>
			</div>
			<p v-if="status" class="lantern-myrepos-status">{{ status }}</p>
		</div>
	</div>
</template>
