<script>
import RepoList from './components/RepoList.vue'
import TreeBrowser from './components/TreeBrowser.vue'
import BlobViewer from './components/BlobViewer.vue'
import CommitList from './components/CommitList.vue'
import EmptyState from './components/EmptyState.vue'
import ReadmeView from './components/ReadmeView.vue'
import RefPicker from './components/RefPicker.vue'
import SearchBox from './components/SearchBox.vue'
import GlobalSearchBox from './components/GlobalSearchBox.vue'
import MyReposManager from './components/MyReposManager.vue'
import ForgeRepoManager from './components/ForgeRepoManager.vue'
import { fetchRepos, searchRepo, searchAllRepos } from './api.js'
import { t, n } from './l10n.js'

export default {
	name: 'App',
	components: { RepoList, TreeBrowser, BlobViewer, CommitList, EmptyState, ReadmeView, RefPicker, SearchBox, GlobalSearchBox, MyReposManager, ForgeRepoManager },
	props: {
		// Server-provided first-paint context (see templates/main.php).
		isAdmin: { type: Boolean, default: false },
		settingsUrl: { type: String, default: '' },
	},
	data() {
		return {
			repos: [],
			activeRepo: null,
			ref: '',
			path: '',
			// Entries of the currently-browsed directory, lifted from TreeBrowser
			// so the README for this directory can render without re-fetching.
			entries: [],
			// 'tree' shows the directory browser + selected blob; 'history'
			// shows the commit list for the current ref/path; 'search' shows
			// in-repo search results.
			view: 'tree',
			selectedBlobPath: null,
			searchQuery: '',
			searchResults: [],
			searching: false,
			// cross-repo (global) search
			globalQuery: '',
			globalResults: [],
			globalSearching: false,
			globalTruncated: false,
			globalSearchedRepos: 0,
			error: null,
			loading: true,
		}
	},
	async mounted() {
		await this.loadRepos()
		// Restore deep-linked state from the URL query (?repo=&ref=&path=&blob=);
		// the #L.. hash for line ranges is handled inside BlobViewer.
		const q = new URLSearchParams(window.location.search)
		const wanted = q.get('repo')
		const target = wanted && this.repos.find((r) => r.id === wanted)
		if (target) {
			this.activeRepo = target
			this.ref = q.get('ref') || ''
			this.path = q.get('path') || ''
			this.selectedBlobPath = q.get('blob') || null
			this.view = 'tree'
		} else if (this.repos.length && !this.activeRepo) {
			this.selectRepo(this.repos[0])
		}
		this.loading = false
	},
	methods: {
		// Reflect navigation state in the URL (query params) so a file/line view
		// is a shareable link. replaceState avoids spamming history.
		syncUrl() {
			if (!this.activeRepo) return
			const p = new URLSearchParams()
			p.set('repo', this.activeRepo.id)
			if (this.ref) p.set('ref', this.ref)
			if (this.path) p.set('path', this.path)
			if (this.selectedBlobPath) p.set('blob', this.selectedBlobPath)
			const url = window.location.pathname + '?' + p.toString() + window.location.hash
			window.history.replaceState(null, '', url)
		},
		async loadRepos() {
			try {
				this.repos = await fetchRepos()
				// If the active repo vanished (e.g. removed), drop the selection.
				if (this.activeRepo && !this.repos.some((r) => r.id === this.activeRepo.id)) {
					this.activeRepo = null
				}
			} catch (e) {
				this.error = t('Could not load repositories.')
			}
		},
		onReposChanged() {
			this.loadRepos().then(() => {
				if (this.repos.length && !this.activeRepo) {
					this.selectRepo(this.repos[0])
				}
			})
		},
		selectRepo(repo) {
			this.activeRepo = repo
			this.ref = ''
			this.path = ''
			this.selectedBlobPath = null
			this.entries = []
			this.searchQuery = ''
			this.searchResults = []
			this.view = 'tree'
			this.syncUrl()
		},
		onNavigate(path) {
			this.path = path
			this.selectedBlobPath = null
			this.entries = []
			this.syncUrl()
		},
		onEntries(entries) {
			this.entries = entries
		},
		selectRef(ref) {
			// Switching ref resets to the repo root: a path/file valid on one
			// branch may not exist on another, and 404s mid-browse are jarring.
			this.ref = ref
			this.path = ''
			this.selectedBlobPath = null
			this.entries = []
			this.syncUrl()
		},
		async onSearch(q) {
			this.searchQuery = q
			if (!q) { this.searchResults = []; this.view = 'tree'; return }
			this.view = 'search'
			this.searching = true
			try {
				const data = await searchRepo(this.activeRepo.id, this.ref, q)
				this.searchResults = data.matches
			} catch (e) {
				this.searchResults = []
			} finally {
				this.searching = false
			}
		},
		async onGlobalSearch(q) {
			this.globalQuery = q
			if (!q) { this.globalResults = []; if (this.view === 'globalsearch') this.view = 'tree'; return }
			this.view = 'globalsearch'
			this.globalSearching = true
			this.globalResults = []
			try {
				const data = await searchAllRepos(q)
				this.globalResults = data.results
				this.globalTruncated = !!data.truncated
				this.globalSearchedRepos = data.searchedRepos || 0
			} catch (e) {
				this.globalResults = []
			} finally {
				this.globalSearching = false
			}
		},
		openGlobalResult(group, m) {
			// Jump to the matched repo + file + line.
			const repo = this.repos.find((r) => r.id === group.repo.id)
			if (!repo) return
			this.activeRepo = repo
			this.ref = group.ref || ''
			window.location.hash = '#L' + m.line
			const i = m.path.lastIndexOf('/')
			this.path = i === -1 ? '' : m.path.slice(0, i)
			this.selectedBlobPath = m.path
			this.entries = []
			this.view = 'tree'
			this.syncUrl()
		},
		openResult(m) {
			// Jump to the matched file + line. Set the hash before the blob so
			// BlobViewer picks up the line range when it loads.
			window.location.hash = '#L' + m.line
			const i = m.path.lastIndexOf('/')
			this.path = i === -1 ? '' : m.path.slice(0, i)
			this.selectedBlobPath = m.path
			this.view = 'tree'
			this.syncUrl()
		},
		onOpenBlob(path) {
			this.selectedBlobPath = path
			this.syncUrl()
		},
		onRefResolved(ref) {
			if (ref) { this.ref = ref; this.syncUrl() }
		},
		showTree() {
			this.view = 'tree'
		},
		showHistory() {
			this.view = 'history'
		},
	},
}
</script>

<template>
	<div class="lantern-root" style="display:flex;width:100%;height:100%;min-height:0;">
		<aside class="lantern-sidebar">
			<GlobalSearchBox
				v-if="repos.length"
				@search="onGlobalSearch" />
			<RepoList
				:repos="repos"
				:active-id="activeRepo && activeRepo.id"
				@select="selectRepo" />
			<MyReposManager @changed="onReposChanged" />
			<ForgeRepoManager @changed="onReposChanged" />
		</aside>

		<main class="lantern-main">
			<div v-if="loading" class="lantern-empty">{{ t('Loading…') }}</div>
			<div v-else-if="error" class="lantern-empty">{{ error }}</div>
			<EmptyState
				v-else-if="!repos.length"
				:is-admin="isAdmin"
				:settings-url="settingsUrl" />

			<div v-else-if="view === 'globalsearch'" class="lantern-searchresults">
				<div v-if="globalSearching" class="lantern-empty">{{ t('Searching all repositories…') }}</div>
				<template v-else>
					<p class="lantern-search-summary">
						{{ n('{count} repository with matches', '{count} repositories with matches', globalResults.length, { count: globalResults.length }) }}
						{{ t('for “{query}” (searched {searched})', { query: globalQuery, searched: globalSearchedRepos }) }}
						<span v-if="globalTruncated"> {{ t('— more repos not searched (limit reached)') }}</span>
						<button type="button" class="lantern-link" @click="onGlobalSearch('')">{{ t('Clear') }}</button>
					</p>
					<div v-if="!globalResults.length" class="lantern-empty">{{ t('No matches.') }}</div>
					<div v-for="g in globalResults" :key="g.repo.id" class="lantern-global-group">
						<h3 class="lantern-global-repo">{{ g.repo.name }} <span class="lantern-myrepos-invalid">({{ g.repo.provider }})</span></h3>
						<ul class="lantern-tree" role="list">
							<li v-for="(m, idx) in g.matches" :key="idx" class="lantern-search-hit">
								<button type="button" class="lantern-link" @click="openGlobalResult(g, m)">
									{{ m.path }}<span v-if="m.line" class="lantern-search-line">:{{ m.line }}</span>
								</button>
								<code v-if="m.text" class="lantern-search-text">{{ m.text }}</code>
							</li>
						</ul>
					</div>
				</template>
			</div>

			<template v-else-if="activeRepo">
				<div class="lantern-toolbar">
					<div class="lantern-tabs">
						<button :class="{ primary: view === 'tree' }" @click="showTree">{{ t('Files') }}</button>
						<button :class="{ primary: view === 'history' }" @click="showHistory">{{ t('History') }}</button>
					</div>
					<RefPicker
						:repo="activeRepo"
						:current-ref="ref"
						@select="selectRef" />
					<SearchBox @search="onSearch" />
				</div>

				<template v-if="view === 'tree'">
					<TreeBrowser
						:repo="activeRepo"
						:ref-name="ref"
						:path="path"
						:open-path="selectedBlobPath"
						@navigate="onNavigate"
						@open-blob="onOpenBlob"
						@ref-resolved="onRefResolved"
						@entries="onEntries" />
					<BlobViewer
						v-if="selectedBlobPath"
						:repo="activeRepo"
						:ref-name="ref"
						:path="selectedBlobPath" />
					<ReadmeView
						v-else
						:repo="activeRepo"
						:ref-name="ref"
						:path="path"
						:entries="entries" />
				</template>

				<div v-else-if="view === 'search'" class="lantern-searchresults">
					<div v-if="searching" class="lantern-empty">{{ t('Searching…') }}</div>
					<template v-else>
						<p class="lantern-search-summary">
							{{ searchResults.length }}{{ searchResults.length === 200 ? '+' : '' }}
							{{ n('result', 'results', searchResults.length) }} {{ t('for “{query}”', { query: searchQuery }) }}
							<button type="button" class="lantern-link" @click="onSearch('')">{{ t('Clear') }}</button>
						</p>
						<ul class="lantern-tree" role="list">
							<li v-for="(m, idx) in searchResults" :key="idx" class="lantern-search-hit">
								<button type="button" class="lantern-link" @click="openResult(m)">
									{{ m.path }}<span class="lantern-search-line">:{{ m.line }}</span>
								</button>
								<code class="lantern-search-text">{{ m.text }}</code>
							</li>
						</ul>
					</template>
				</div>

				<CommitList
					v-else
					:repo="activeRepo"
					:ref-name="ref"
					:path="path" />
			</template>
		</main>
	</div>
</template>
