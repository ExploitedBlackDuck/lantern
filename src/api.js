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

export async function fetchCommits(repoId, ref, path, limit = 50) {
	const { data } = await axios.get(base(`/api/repos/${encodeURIComponent(repoId)}/commits`), {
		params: { ref: ref || '', path: path || '', limit },
	})
	return data
}
