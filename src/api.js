import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (p) => generateUrl('/apps/lantern' + p)

export async function fetchRepos() {
	const { data } = await axios.get(base('/api/repos'))
	return data.repos
}

export async function fetchTree(repoId, ref, path) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/tree`), {
		params: { ref: ref || '', path: path || '' },
	})
	return data
}

export async function fetchBlob(repoId, ref, path) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/blob`), {
		params: { ref: ref || '', path },
	})
	return data
}

export async function fetchCommits(repoId, ref, path, limit = 50, offset = 0) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/commits`), {
		params: { ref: ref || '', path: path || '', limit, offset },
	})
	return data
}

export async function fetchDiff(repoId, ref) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/diff`), {
		params: { ref },
	})
	return data.diff
}

export async function fetchBlame(repoId, ref, path) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/blame`), {
		params: { ref: ref || '', path },
	})
	return data.blame
}

// Diff between two commits/refs (base..head).
export async function fetchRangeDiff(repoId, baseRef, headRef) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/diff-range`), {
		params: { base: baseRef, head: headRef },
	})
	return data.diff
}

// Search across every repo visible to the user (single-pane "find everywhere").
export async function searchAllRepos(q, limit = 10) {
	const { data } = await axios.get(base('/api/search'), { params: { q, limit } })
	return data
}

export async function fetchRefs(repoId) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/refs`))
	return data.refs
}

export async function searchRepo(repoId, ref, q, limit = 100) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/search`), {
		params: { ref: ref || '', q, limit },
	})
	return data
}

// URL (not a fetch) for raw bytes — used as an <img> src and as download links.
export function rawUrl(repoId, ref, path, download = false) {
	return base(`/api/repos/${encodeURIComponent(repoId)}/raw`)
		+ `?path=${encodeURIComponent(path)}&ref=${encodeURIComponent(ref || '')}`
		+ (download ? '&download=1' : '')
}

// --- the current user's own Files-backed repos (Horizon 2) ---
export async function fetchMyRepos() {
	const { data } = await axios.get(base('/api/my/repos'))
	return data.repos
}

export async function validateMyRepo(path) {
	const { data } = await axios.post(base('/api/my/repos/validate'), { path })
	return data
}

export async function addMyRepo(path, name) {
	const { data } = await axios.post(base('/api/my/repos/add'), { path, name })
	return data
}

export async function removeMyRepo(id) {
	const { data } = await axios.post(base('/api/my/repos/remove'), { id })
	return data
}

// --- the current user's remote-forge repos: GitHub (H3) + GitLab (v2.1) ---
export async function fetchForgeRepos() {
	const { data } = await axios.get(base('/api/forge/repos'))
	return data.repos
}

// kind: 'github' | 'gitlab'; slug: 'owner/repo' or 'group/.../project';
// host: instance base URL ('' = the forge default).
export async function addForgeRepo(kind, slug, host, token, name) {
	const { data } = await axios.post(base('/api/forge/repos/add'), { kind, slug, host, token, name })
	return data
}

export async function removeForgeRepo(id) {
	const { data } = await axios.post(base('/api/forge/repos/remove'), { id })
	return data
}
