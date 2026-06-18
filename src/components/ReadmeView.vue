<script>
import { fetchBlob } from '../api.js'
import { t, n } from '../l10n.js'

// markdown-it + dompurify (~150 KiB together) are loaded on demand the first
// time a Markdown README is rendered, keeping them out of the main bundle.
let mdPromise = null
async function renderMarkdown(src) {
	if (!mdPromise) {
		mdPromise = Promise.all([import('markdown-it'), import('dompurify')])
			.then(([mdMod, purifyMod]) => {
				// html:false drops raw HTML in the source; DOMPurify scrubs the
				// output too — a hostile README cannot inject active markup. This
				// matters now (trusted admin repos) and more once untrusted
				// user-Files repos arrive in Horizon 2.
				const MarkdownIt = mdMod.default
				const md = new MarkdownIt({ html: false, linkify: true, breaks: false })
				return { md, purify: purifyMod.default }
			})
	}
	const { md, purify } = await mdPromise
	return purify.sanitize(md.render(src))
}

const MD_RE = /\.(md|markdown|mdown)$/i
const README_RE = /^readme(\.(md|markdown|mdown|txt|rst))?$/i

export default {
	name: 'ReadmeView',
	props: {
		repo: { type: Object, required: true },
		refName: { type: String, default: '' },
		path: { type: String, default: '' },
		entries: { type: Array, default: () => [] },
	},
	data() {
		return { html: '', plain: '', loading: false, note: '' }
	},
	computed: {
		readme() {
			const cands = this.entries.filter(
				(e) => e.type === 'blob' && README_RE.test(e.name),
			)
			if (!cands.length) return null
			// Prefer a Markdown README over a plain-text one.
			cands.sort((a, b) => (MD_RE.test(a.name) ? 0 : 1) - (MD_RE.test(b.name) ? 0 : 1))
			return cands[0]
		},
	},
	watch: {
		readme: 'load',
		refName: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			this.html = ''
			this.plain = ''
			this.note = ''
			const r = this.readme
			if (!r) return
			this.loading = true
			try {
				const data = await fetchBlob(this.repo.id, this.refName, r.path)
				const blob = data.blob
				if (!blob || blob.binary || blob.content === null) {
					this.note = blob && blob.truncated
						? t('README is too large to display inline.')
						: t('README could not be displayed.')
					return
				}
				if (MD_RE.test(r.name)) {
					this.html = await renderMarkdown(blob.content)
				} else {
					this.plain = blob.content
				}
			} catch (e) {
				this.note = t('Could not load README.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<template>
	<div class="lantern-readme-wrap">
		<div v-if="loading" class="lantern-empty">{{ t('Loading README…') }}</div>
		<div v-else-if="note" class="lantern-empty">{{ note }}</div>
		<article v-else-if="html" class="lantern-readme" v-html="html" />
		<pre v-else-if="plain" class="lantern-code lantern-readme-plain">{{ plain }}</pre>
		<div v-else class="lantern-empty">
			{{ t('Select a file to view its contents.') }}
		</div>
	</div>
</template>
