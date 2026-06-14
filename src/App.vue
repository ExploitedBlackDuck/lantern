<script>
import RepoList from './components/RepoList.vue'
import TreeBrowser from './components/TreeBrowser.vue'
import BlobViewer from './components/BlobViewer.vue'
import CommitList from './components/CommitList.vue'
import EmptyState from './components/EmptyState.vue'
import ReadmeView from './components/ReadmeView.vue'
import RefPicker from './components/RefPicker.vue'
import SearchBox from './components/SearchBox.vue'
import MyReposManager from './components/MyReposManager.vue'
import ForgeRepoManager from './components/ForgeRepoManager.vue'
import { fetchRepos, searchRepo } from './api.js'

export default {
	name: 'App',
	components: { RepoList, TreeBrowser, BlobViewer, CommitList, EmptyState, ReadmeView, RefPicker, SearchBox, MyReposManager, ForgeRepoManager },
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
				this.error = 'Could not load repositories.'
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
			<RepoList
				:repos="repos"
				:active-id="activeRepo && activeRepo.id"
				@select="selectRepo" />
			<MyReposManager @changed="onReposChanged" />
			<ForgeRepoManager @changed="onReposChanged" />
			<RefPicker
				v-if="activeRepo"
				:repo="activeRepo"
				:current-ref="ref"
				@select="selectRef" />
			<SearchBox
				v-if="activeRepo"
				@search="onSearch" />
			<TreeBrowser
				v-if="activeRepo"
				:repo="activeRepo"
				:ref-name="ref"
				:path="path"
				@navigate="onNavigate"
				@open-blob="onOpenBlob"
				@ref-resolved="onRefResolved"
				@entries="onEntries" />
		</aside>

		<main class="lantern-main">
			<div v-if="loading" class="lantern-empty">Loading…</div>
			<div v-else-if="error" class="lantern-empty">{{ error }}</div>
			<EmptyState
				v-else-if="!repos.length"
				:is-admin="isAdmin"
				:settings-url="settingsUrl" />
			<template v-else-if="activeRepo">
				<div class="lantern-tabs" style="margin-bottom:8px;">
					<button :class="{ primary: view === 'tree' }" @click="showTree">Files</button>
					<button :class="{ primary: view === 'history' }" @click="showHistory">History</button>
				</div>

				<BlobViewer
					v-if="view === 'tree' && selectedBlobPath"
					:repo="activeRepo"
					:ref-name="ref"
					:path="selectedBlobPath" />
				<ReadmeView
					v-else-if="view === 'tree'"
					:repo="activeRepo"
					:ref-name="ref"
					:path="path"
					:entries="entries" />

				<div v-else-if="view === 'search'" class="lantern-searchresults">
					<div v-if="searching" class="lantern-empty">Searching…</div>
					<template v-else>
						<p class="lantern-search-summary">
							{{ searchResults.length }}{{ searchResults.length === 200 ? '+' : '' }}
							result{{ searchResults.length === 1 ? '' : 's' }} for “{{ searchQuery }}”
							<button type="button" class="lantern-link" @click="onSearch('')">Clear</button>
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
