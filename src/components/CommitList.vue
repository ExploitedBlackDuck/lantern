<script>
import { fetchCommits, fetchDiff, fetchRangeDiff } from '../api.js'
import { t } from '../l10n.js'

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
			// commit-range compare
			compareBase: null, rangeOpen: false, rangeLines: [], rangeLoading: false,
			rangeBase: '', rangeHead: '',
		}
	},
	watch: {
		repo: 'reset',
		refName: 'reset',
		path: 'reset',
	},
	mounted() {
		this.load()
	},
	methods: {
		reset() {
			this.compareBase = null
			this.rangeOpen = false
			this.openHash = null
			this.load()
		},
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
				this.error = t('Could not load history.')
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
				this.error = t('Could not load more history.')
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
		shortHash(hash) {
			return (hash || '').slice(0, 7)
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
				this.diffLines = [{ cls: 'meta', text: t('Could not load diff.') }]
			} finally {
				this.diffLoading = false
			}
		},
		// --- commit-range compare ---
		setBase(hash) {
			this.compareBase = hash
			this.rangeOpen = false
		},
		cancelCompare() {
			this.compareBase = null
			this.rangeOpen = false
		},
		async compareTo(hash) {
			// Order older→newer so additions read as '+': in a newest-first list
			// the larger index is the older commit, which becomes the base.
			const i1 = this.commits.findIndex((c) => c.hash === this.compareBase)
			const i2 = this.commits.findIndex((c) => c.hash === hash)
			const base = i1 >= i2 ? this.compareBase : hash
			const head = i1 >= i2 ? hash : this.compareBase
			this.rangeBase = this.shortHash(base)
			this.rangeHead = this.shortHash(head)
			this.rangeOpen = true
			this.rangeLoading = true
			this.rangeLines = []
			try {
				const text = await fetchRangeDiff(this.repo.id, base, head)
				this.rangeLines = text ? this.classifyDiff(text) : [{ cls: 'meta', text: t('No differences.') }]
			} catch (e) {
				this.rangeLines = [{ cls: 'meta', text: t('Could not load comparison.') }]
			} finally {
				this.rangeLoading = false
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
		<h3>{{ t('History') }}<span v-if="path"> · {{ path }}</span></h3>
		<div v-if="loading" class="lantern-empty">{{ t('Loading…') }}</div>
		<div v-else-if="error" class="lantern-empty">{{ error }}</div>
		<div v-else-if="!commits.length" class="lantern-empty">{{ t('No commits.') }}</div>

		<p v-if="compareBase" class="lantern-compare-banner">
			{{ t('Comparing from') }} <code>{{ shortHash(compareBase) }}</code> — {{ t('pick another commit\'s') }}
			<strong>{{ t('“compare”') }}</strong> {{ t('to diff against it.') }}
			<button type="button" class="lantern-link" @click="cancelCompare">{{ t('Cancel') }}</button>
		</p>

		<div v-if="rangeOpen" class="lantern-diff lantern-range-diff">
			<p class="lantern-compare-banner">
				{{ t('Diff') }} <code>{{ rangeBase }}</code> … <code>{{ rangeHead }}</code>
				<button type="button" class="lantern-link" @click="rangeOpen = false">{{ t('Close') }}</button>
			</p>
			<div v-if="rangeLoading" class="lantern-empty">{{ t('Loading comparison…') }}</div>
			<pre v-else class="lantern-code lantern-diff-pre"><template v-for="(l, i) in rangeLines" :key="i"><span :class="'dl-' + l.cls">{{ l.text }}</span>
</template></pre>
		</div>

		<div v-for="c in commits" :key="c.hash" class="lantern-commit">
			<button type="button" class="lantern-commit-toggle" @click="toggleDiff(c.hash)">
				<div class="subject">{{ c.subject }}</div>
				<div class="meta">
					<code>{{ c.shortHash }}</code> · {{ c.authorName }} · {{ when(c.date) }}
					<span class="lantern-commit-diffhint">{{ openHash === c.hash ? '▾ ' + t('hide diff') : '▸ ' + t('diff') }}</span>
				</div>
			</button>
			<div class="lantern-commit-actions">
				<button v-if="!compareBase" type="button" class="lantern-link" @click="setBase(c.hash)">⇄ {{ t('compare') }}</button>
				<span v-else-if="compareBase === c.hash" class="lantern-compare-base">{{ t('base') }} ✓</span>
				<button v-else type="button" class="lantern-link" @click="compareTo(c.hash)">⇄ {{ t('compare to this') }}</button>
			</div>
			<div v-if="openHash === c.hash" class="lantern-diff">
				<div v-if="diffLoading" class="lantern-empty">{{ t('Loading diff…') }}</div>
				<pre v-else class="lantern-code lantern-diff-pre"><template v-for="(l, i) in diffLines" :key="i"><span :class="'dl-' + l.cls">{{ l.text }}</span>
</template></pre>
			</div>
		</div>
		<button v-if="hasMore" type="button" class="lantern-loadmore" :disabled="loading" @click="loadMore">
			{{ loading ? t('Loading…') : t('Load more') }}
		</button>
	</div>
</template>
