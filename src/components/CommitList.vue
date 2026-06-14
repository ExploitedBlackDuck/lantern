<script>
import { fetchCommits, fetchDiff } from '../api.js'

export default {
	name: 'CommitList',
	props: {
		repo: { type: Object, required: true },
		refName: { type: String, default: '' },
		path: { type: String, default: '' },
	},
	data() {
		return {
			commits: [], loading: false, error: null, hasMore: false, pageSize: 50,
			openHash: null, diffLines: [], diffLoading: false,
		}
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
			this.commits = []
			this.hasMore = false
			try {
				const data = await fetchCommits(this.repo.id, this.refName, this.path, this.pageSize, 0)
				this.commits = data.commits
				this.hasMore = !!data.hasMore
			} catch (e) {
				this.error = 'Could not load history.'
			} finally {
				this.loading = false
			}
		},
		async loadMore() {
			this.loading = true
			try {
				const data = await fetchCommits(this.repo.id, this.refName, this.path, this.pageSize, this.commits.length)
				this.commits = this.commits.concat(data.commits)
				this.hasMore = !!data.hasMore
			} catch (e) {
				this.error = 'Could not load more history.'
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
		async toggleDiff(hash) {
			if (this.openHash === hash) { this.openHash = null; this.diffLines = []; return }
			this.openHash = hash
			this.diffLines = []
			this.diffLoading = true
			try {
				const text = await fetchDiff(this.repo.id, hash)
				this.diffLines = this.classifyDiff(text)
			} catch (e) {
				this.diffLines = [{ cls: 'meta', text: 'Could not load diff.' }]
			} finally {
				this.diffLoading = false
			}
		},
		// Tag each diff line so the template can colour it. Returns plain text;
		// rendered with {{ }} so file content can never inject markup.
		classifyDiff(text) {
			return (text || '').split('\n').map((line) => {
				let cls = ''
				if (line.startsWith('+') && !line.startsWith('+++')) cls = 'add'
				else if (line.startsWith('-') && !line.startsWith('---')) cls = 'del'
				else if (line.startsWith('@@')) cls = 'hunk'
				else if (line.startsWith('diff ') || line.startsWith('index ') || line.startsWith('+++') || line.startsWith('---')) cls = 'meta'
				return { cls, text: line }
			})
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
			<button type="button" class="lantern-commit-toggle" @click="toggleDiff(c.hash)">
				<div class="subject">{{ c.subject }}</div>
				<div class="meta">
					<code>{{ c.shortHash }}</code> · {{ c.authorName }} · {{ when(c.date) }}
					<span class="lantern-commit-diffhint">{{ openHash === c.hash ? '▾ hide diff' : '▸ diff' }}</span>
				</div>
			</button>
			<div v-if="openHash === c.hash" class="lantern-diff">
				<div v-if="diffLoading" class="lantern-empty">Loading diff…</div>
				<pre v-else class="lantern-code lantern-diff-pre"><template v-for="(l, i) in diffLines" :key="i"><span :class="'dl-' + l.cls">{{ l.text }}</span>
</template></pre>
			</div>
		</div>
		<button v-if="hasMore" type="button" class="lantern-loadmore" :disabled="loading" @click="loadMore">
			{{ loading ? 'Loading…' : 'Load more' }}
		</button>
	</div>
</template>
