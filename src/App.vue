<script>
import RepoList from './components/RepoList.vue'
import TreeBrowser from './components/TreeBrowser.vue'
import BlobViewer from './components/BlobViewer.vue'
import CommitList from './components/CommitList.vue'
import { fetchRepos } from './api.js'

export default {
	name: 'App',
	components: { RepoList, TreeBrowser, BlobViewer, CommitList },
	data() {
		return {
			repos: [],
			activeRepo: null,
			ref: '',
			path: '',
			// 'tree' shows the directory browser + selected blob; 'history'
			// shows the commit list for the current ref/path.
			view: 'tree',
			selectedBlobPath: null,
			error: null,
			loading: true,
		}
	},
	async mounted() {
		try {
			this.repos = await fetchRepos()
			if (this.repos.length) {
				this.selectRepo(this.repos[0])
			}
		} catch (e) {
			this.error = 'Could not load repositories.'
		} finally {
			this.loading = false
		}
	},
	methods: {
		selectRepo(repo) {
			this.activeRepo = repo
			this.ref = ''
			this.path = ''
			this.selectedBlobPath = null
			this.view = 'tree'
		},
		onNavigate(path) {
			this.path = path
			this.selectedBlobPath = null
		},
		onOpenBlob(path) {
			this.selectedBlobPath = path
		},
		onRefResolved(ref) {
			if (ref) this.ref = ref
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
			<TreeBrowser
				v-if="activeRepo"
				:repo="activeRepo"
				:ref-name="ref"
				:path="path"
				@navigate="onNavigate"
				@open-blob="onOpenBlob"
				@ref-resolved="onRefResolved" />
		</aside>

		<main class="lantern-main">
			<div v-if="loading" class="lantern-empty">Loading…</div>
			<div v-else-if="error" class="lantern-empty">{{ error }}</div>
			<div v-else-if="!repos.length" class="lantern-empty">
				No repositories configured. An administrator can add them in
				Settings → Administration → Lantern.
			</div>
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
				<div v-else-if="view === 'tree'" class="lantern-empty">
					Select a file to view its contents.
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
