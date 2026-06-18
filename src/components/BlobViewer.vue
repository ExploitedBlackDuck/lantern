<script>
import { fetchBlob, fetchBlame, rawUrl } from '../api.js'
// Only the tiny hljs CORE is in the main bundle; each language is a separate
// chunk pulled in on demand (see ensureLanguage). This trims the main bundle
// substantially versus importing highlight.js/lib/common (~35 languages).
import hljs from 'highlight.js/lib/core'

// Above this size we skip highlighting and render plain text. Highlighting a
// multi-megabyte file blocks the main thread; plain text stays responsive.
const HIGHLIGHT_LIMIT = 256 * 1024

// Explicit per-language loaders. Each import() is a string literal so webpack
// emits one on-demand chunk per language (highlight.js's package "exports"
// field blocks a dynamic directory import, so a template literal won't resolve).
// Only the language of the file being viewed is fetched; the rest never load.
const LANG_LOADERS = {
	javascript: () => import('highlight.js/lib/languages/javascript'),
	typescript: () => import('highlight.js/lib/languages/typescript'),
	python: () => import('highlight.js/lib/languages/python'),
	ruby: () => import('highlight.js/lib/languages/ruby'),
	php: () => import('highlight.js/lib/languages/php'),
	go: () => import('highlight.js/lib/languages/go'),
	rust: () => import('highlight.js/lib/languages/rust'),
	java: () => import('highlight.js/lib/languages/java'),
	c: () => import('highlight.js/lib/languages/c'),
	cpp: () => import('highlight.js/lib/languages/cpp'),
	csharp: () => import('highlight.js/lib/languages/csharp'),
	bash: () => import('highlight.js/lib/languages/bash'),
	powershell: () => import('highlight.js/lib/languages/powershell'),
	json: () => import('highlight.js/lib/languages/json'),
	yaml: () => import('highlight.js/lib/languages/yaml'),
	ini: () => import('highlight.js/lib/languages/ini'),
	xml: () => import('highlight.js/lib/languages/xml'),
	css: () => import('highlight.js/lib/languages/css'),
	scss: () => import('highlight.js/lib/languages/scss'),
	sql: () => import('highlight.js/lib/languages/sql'),
	markdown: () => import('highlight.js/lib/languages/markdown'),
	diff: () => import('highlight.js/lib/languages/diff'),
}

const loadedLangs = new Set()
async function ensureLanguage(lang) {
	if (!lang || !LANG_LOADERS[lang]) return false
	if (loadedLangs.has(lang) || hljs.getLanguage(lang)) return true
	try {
		const mod = await LANG_LOADERS[lang]()
		hljs.registerLanguage(lang, mod.default)
		loadedLangs.add(lang)
		return true
	} catch (e) {
		return false
	}
}

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

// Extensions we render inline as <img> via the raw endpoint (raster only; the
// backend refuses to serve anything else inline — see RepoController).
const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'avif']

function escapeHtml(s) {
	return s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))
}

// Split highlight.js output into per-line HTML, re-opening any <span> that was
// left open at a newline (multi-line comments/strings) and closing it at the
// line end. hljs only emits <span class="…"> tags, so the class value never
// contains '>', making this regex scan safe.
function splitHighlightedLines(html) {
	const out = []
	let open = []
	for (const line of html.split('\n')) {
		const prefixed = open.join('') + line
		const re = /<(\/?)span([^>]*)>/g
		let m
		while ((m = re.exec(line)) !== null) {
			if (m[1] === '/') open.pop()
			else open.push('<span' + m[2] + '>')
		}
		out.push(prefixed + '</span>'.repeat(open.length))
	}
	return out
}

export default {
	name: 'BlobViewer',
	props: {
		repo: { type: Object, required: true },
		refName: { type: String, default: '' },
		path: { type: String, required: true },
	},
	data() {
		return {
			blob: null, loading: false, error: null,
			lines: [], activeStart: 0, activeEnd: 0, copied: false,
			blameOn: false, blameByLine: {}, blameLoading: false,
		}
	},
	computed: {
		isImage() {
			// Only render an <img> once the blob metadata confirms a real image and
			// not a Git LFS pointer: an LFS-backed image's raw endpoint returns the
			// pointer text, not image bytes, so it would render broken. Gating on a
			// loaded blob also avoids firing the raw request before we know the type.
			return !!this.blob && IMAGE_EXT.includes(this.ext) && !this.blob.lfs
		},
		ext() {
			const dot = this.path.lastIndexOf('.')
			return dot === -1 ? '' : this.path.slice(dot + 1).toLowerCase()
		},
		imageSrc() {
			return rawUrl(this.repo.id, this.refName, this.path)
		},
		downloadHref() {
			return rawUrl(this.repo.id, this.refName, this.path, true)
		},
	},
	watch: {
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
			return EXT_LANG[path.slice(dot + 1).toLowerCase()] || null
		},
		parseHash() {
			const m = /^#L(\d+)(?:-L?(\d+))?$/.exec(window.location.hash)
			if (!m) { this.activeStart = this.activeEnd = 0; return }
			const a = parseInt(m[1], 10)
			const b = m[2] ? parseInt(m[2], 10) : a
			this.activeStart = Math.min(a, b)
			this.activeEnd = Math.max(a, b)
		},
		isActive(n) {
			return this.activeStart && n >= this.activeStart && n <= this.activeEnd
		},
		selectLine(n, ev) {
			if (ev && ev.shiftKey && this.activeStart) {
				this.activeEnd = Math.max(this.activeStart, n)
				this.activeStart = Math.min(this.activeStart, n)
			} else {
				this.activeStart = this.activeEnd = n
			}
			const frag = this.activeStart === this.activeEnd
				? `#L${this.activeStart}`
				: `#L${this.activeStart}-L${this.activeEnd}`
			window.history.replaceState(null, '', frag)
		},
		async toggleBlame() {
			this.blameOn = !this.blameOn
			if (!this.blameOn || Object.keys(this.blameByLine).length) return
			this.blameLoading = true
			try {
				const rows = await fetchBlame(this.repo.id, this.refName, this.path)
				const map = {}
				for (const r of rows) map[r.line] = r
				this.blameByLine = map
				if (!rows.length) this.blameOn = false // provider doesn't support blame
			} catch (e) {
				this.blameOn = false
			} finally {
				this.blameLoading = false
			}
		},
		blameFor(n) {
			return this.blameByLine[n] || null
		},
		async copyPermalink() {
			try {
				await navigator.clipboard.writeText(window.location.href)
				this.copied = true
				setTimeout(() => { this.copied = false }, 1500)
			} catch (e) { /* clipboard unavailable; the line anchor is still usable */ }
		},
		scrollToActive() {
			if (!this.activeStart) return
			this.$nextTick(() => {
				const el = document.getElementById('L' + this.activeStart)
				if (el) el.scrollIntoView({ block: 'center' })
			})
		},
		async load() {
			this.loading = true
			this.error = null
			this.lines = []
			this.blob = null
			this.blameOn = false
			this.blameByLine = {}
			try {
				// Always fetch the blob metadata first — even for image extensions —
				// so we can detect a Git LFS pointer (and only then decide whether to
				// render an <img>, the LFS notice, or text). For real images the
				// metadata is cheap: binary content is suppressed server-side.
				const data = await fetchBlob(this.repo.id, this.refName, this.path)
				this.blob = data.blob
				if (!this.blob || this.blob.lfs || this.blob.binary || this.blob.content === null) return

				const content = this.blob.content
				let html = null
				if (content.length <= HIGHLIGHT_LIMIT) {
					const lang = this.langFor(this.path)
					// No highlightAuto: it needs every language bundled, which is
					// exactly the weight we're avoiding. Unknown types render as
					// plain (still line-numbered) text.
					if (await ensureLanguage(lang)) {
						html = hljs.highlight(content, { language: lang }).value
					}
				}
				const lines = html !== null
					? splitHighlightedLines(html)
					: content.split('\n').map(escapeHtml)
				// Drop a single trailing empty line (from the final newline).
				if (lines.length > 1 && lines[lines.length - 1] === '') lines.pop()
				this.lines = lines

				this.parseHash()
				this.scrollToActive()
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
		<div class="lantern-blob-head">
			<h3>{{ path }}</h3>
			<span class="lantern-blob-actions">
				<button v-if="!isImage" type="button" class="lantern-link" @click="toggleBlame">
					{{ blameOn ? 'Hide blame' : 'Blame' }}
				</button>
				<button v-if="activeStart" type="button" class="lantern-link" @click="copyPermalink">
					{{ copied ? 'Link copied' : 'Copy link to lines' }}
				</button>
				<a class="lantern-link" :href="downloadHref">Download</a>
			</span>
		</div>

		<div v-if="loading" class="lantern-empty">Loading…</div>
		<div v-else-if="error" class="lantern-empty">{{ error }}</div>

		<div v-else-if="isImage" class="lantern-image-wrap">
			<img :src="imageSrc" :alt="path" class="lantern-image">
		</div>

		<template v-else-if="blob">
			<div v-if="blob.lfs" class="lantern-binary">
				Stored with Git LFS ({{ blob.lfsSize }} bytes). Lantern references large
				objects rather than fetching them — <a class="lantern-link" :href="downloadHref">download the pointer</a>.
			</div>
			<div v-else-if="blob.binary" class="lantern-binary">
				Binary file ({{ blob.size }} bytes) — <a class="lantern-link" :href="downloadHref">download</a>.
			</div>
			<div v-else-if="blob.truncated" class="lantern-binary">
				File too large to display inline ({{ blob.size }} bytes) —
				<a class="lantern-link" :href="downloadHref">download</a>.
			</div>
			<ol v-else class="lantern-code lantern-codelines" :class="{ 'has-blame': blameOn }">
				<li
					v-for="(line, i) in lines"
					:id="'L' + (i + 1)"
					:key="i"
					:class="{ 'is-active-line': isActive(i + 1) }">
					<span v-if="blameOn" class="blame" :title="blameFor(i + 1) ? (blameFor(i + 1).author + ' · ' + blameFor(i + 1).hash) : ''">{{ blameFor(i + 1) ? blameFor(i + 1).author : '' }}</span><a class="ln" :href="'#L' + (i + 1)" @click.prevent="selectLine(i + 1, $event)">{{ i + 1 }}</a><code class="lc" v-html="line || ' '" /></li>
			</ol>
		</template>
	</div>
</template>
