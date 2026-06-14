<script>
import { fetchTree } from '../api.js'

export default {
	name: 'TreeBrowser',
	props: {
		repo: { type: Object, required: true },
		refName: { type: String, default: '' },
		path: { type: String, default: '' },
		// The file currently open in the main pane, so its row can be marked.
		openPath: { type: String, default: null },
	},
	emits: ['navigate', 'open-blob', 'ref-resolved', 'entries'],
	data() {
		return { entries: [], loading: false, error: null }
	},
	computed: {
		crumbs() {
			const parts = this.path ? this.path.split('/') : []
			const acc = []
			let cur = ''
			for (const p of parts) {
				cur = cur ? cur + '/' + p : p
				acc.push({ label: p, path: cur })
			}
			return acc
		},
	},
	watch: {
		repo: 'load',
		path: 'load',
		refName: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			this.loading = true
			this.error = null
			try {
				const data = await fetchTree(this.repo.id, this.refName, this.path)
				this.entries = data.entries
				this.$emit('ref-resolved', data.ref)
				this.$emit('entries', this.entries)
			} catch (e) {
				this.error = 'Could not load this directory.'
			} finally {
				this.loading = false
			}
		},
		onClick(entry) {
			if (entry.type === 'tree') {
				this.$emit('navigate', entry.path)
			} else if (entry.type === 'blob') {
				this.$emit('open-blob', entry.path)
			}
		},
		goUp() {
			const i = this.path.lastIndexOf('/')
			this.$emit('navigate', i === -1 ? '' : this.path.slice(0, i))
		},
		humanSize(n) {
			if (n === null || n === undefined) return ''
			if (n < 1024) return n + ' B'
			if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB'
			return (n / 1024 / 1024).toFixed(1) + ' MB'
		},
	},
}
</script>

<template>
	<div class="lantern-treebrowser">
		<nav class="lantern-breadcrumb" aria-label="Path">
			<button
				v-if="crumbs.length"
				type="button"
				class="lantern-crumb-link"
				@click="$emit('navigate', '')">{{ repo.name }}</button>
			<span v-else class="lantern-crumb-current">{{ repo.name }}</span>
			<template v-for="(c, i) in crumbs" :key="c.path">
				<span class="lantern-crumb-sep" aria-hidden="true">/</span>
				<button
					v-if="i < crumbs.length - 1"
					type="button"
					class="lantern-crumb-link"
					@click="$emit('navigate', c.path)">{{ c.label }}</button>
				<span v-else class="lantern-crumb-current" aria-current="page">{{ c.label }}</span>
			</template>
		</nav>
		<div v-if="loading" class="lantern-empty">Loading…</div>
		<div v-else-if="error" class="lantern-empty">{{ error }}</div>
		<div v-else-if="!entries.length && !path" class="lantern-empty">This repository is empty.</div>
		<ul v-else class="lantern-tree" role="list">
			<li v-if="path">
				<button type="button" class="lantern-tree-row" @click="goUp">
					<span class="name">..</span>
				</button>
			</li>
			<li v-for="entry in entries" :key="entry.path">
				<button
					type="button"
					class="lantern-tree-row"
					:class="{ 'is-open': entry.type === 'blob' && entry.path === openPath }"
					:aria-current="entry.type === 'blob' && entry.path === openPath ? 'true' : 'false'"
					@click="onClick(entry)">
					<span class="name">{{ entry.type === 'tree' ? '📁' : '📄' }} {{ entry.name }}</span>
					<span class="size">{{ humanSize(entry.size) }}</span>
				</button>
			</li>
		</ul>
	</div>
</template>
