<script>
import { fetchCommits } from '../api.js'

export default {
	name: 'CommitList',
	props: {
		repo: { type: Object, required: true },
		refName: { type: String, default: '' },
		path: { type: String, default: '' },
	},
	data() {
		return { commits: [], loading: false, error: null }
	},
	watch: {
		repo: 'load',
		refName: 'load',
		path: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			this.loading = true
			this.error = null
			try {
				const data = await fetchCommits(this.repo.id, this.refName, this.path, 50)
				this.commits = data.commits
			} catch (e) {
				this.error = 'Could not load history.'
			} finally {
				this.loading = false
			}
		},
		when(iso) {
			try {
				return new Date(iso).toLocaleString()
			} catch (e) {
				return iso
			}
		},
	},
}
</script>

<template>
	<div class="lantern-commitlist">
		<h3>History<span v-if="path"> · {{ path }}</span></h3>
		<div v-if="loading" class="lantern-empty">Loading…</div>
		<div v-else-if="error" class="lantern-empty">{{ error }}</div>
		<div v-else-if="!commits.length" class="lantern-empty">No commits.</div>
		<div v-for="c in commits" :key="c.hash" class="lantern-commit">
			<div class="subject">{{ c.subject }}</div>
			<div class="meta">
				<code>{{ c.shortHash }}</code> · {{ c.authorName }} · {{ when(c.date) }}
			</div>
		</div>
	</div>
</template>
