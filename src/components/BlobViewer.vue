<script>
import { fetchBlob } from '../api.js'
import hljs from 'highlight.js/lib/common'

// Above this size we skip highlighting and render plain text. highlightAuto on
// a multi-megabyte file blocks the main thread; plain <pre> stays responsive.
const HIGHLIGHT_LIMIT = 256 * 1024

// Map common extensions to highlight.js language ids. Deriving the language
// from the extension is both faster and far more accurate than highlightAuto,
// which frequently mis-detects.
const EXT_LANG = {
	js: 'javascript', mjs: 'javascript', ts: 'typescript', jsx: 'javascript',
	py: 'python', rb: 'ruby', php: 'php', go: 'go', rs: 'rust',
	java: 'java', c: 'c', h: 'c', cpp: 'cpp', cc: 'cpp', cs: 'csharp',
	sh: 'bash', bash: 'bash', zsh: 'bash', ps1: 'powershell',
	json: 'json', yml: 'yaml', yaml: 'yaml', toml: 'ini', ini: 'ini',
	xml: 'xml', html: 'xml', css: 'css', scss: 'scss', sql: 'sql',
	md: 'markdown', markdown: 'markdown', diff: 'diff', patch: 'diff',
}

export default {
	name: 'BlobViewer',
	props: {
		repo: { type: Object, required: true },
		refName: { type: String, default: '' },
		path: { type: String, required: true },
	},
	data() {
		return { blob: null, loading: false, error: null, highlighted: '', plain: '' }
	},
	watch: {
		// Watch all three so the open file refreshes when the ref changes too
		// (matters once the v1.x branch picker lands).
		path: 'load',
		repo: 'load',
		refName: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		langFor(path) {
			const dot = path.lastIndexOf('.')
			if (dot === -1) return null
			const ext = path.slice(dot + 1).toLowerCase()
			return EXT_LANG[ext] || null
		},
		async load() {
			this.loading = true
			this.error = null
			this.highlighted = ''
			this.plain = ''
			try {
				const data = await fetchBlob(this.repo.id, this.refName, this.path)
				this.blob = data.blob
				if (!this.blob || this.blob.binary || this.blob.content === null) return

				const content = this.blob.content
				if (content.length > HIGHLIGHT_LIMIT) {
					this.plain = content // too big to highlight; show as text
					return
				}
				const lang = this.langFor(this.path)
				// hljs escapes its input, so file content can never inject markup.
				this.highlighted = (lang && hljs.getLanguage(lang))
					? hljs.highlight(content, { language: lang }).value
					: hljs.highlightAuto(content).value
			} catch (e) {
				this.error = 'Could not load this file.'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<template>
	<div class="lantern-blobviewer">
		<div v-if="loading" class="lantern-empty">Loading…</div>
		<div v-else-if="error" class="lantern-empty">{{ error }}</div>
		<template v-else-if="blob">
			<h3>{{ path }}</h3>
			<div v-if="blob.binary" class="lantern-binary">
				Binary file ({{ blob.size }} bytes) — not shown.
			</div>
			<div v-else-if="blob.truncated" class="lantern-binary">
				File too large to display inline ({{ blob.size }} bytes).
			</div>
			<pre v-else-if="plain" class="lantern-code">{{ plain }}</pre>
			<pre v-else class="lantern-code"><code v-html="highlighted"></code></pre>
		</template>
	</div>
</template>
