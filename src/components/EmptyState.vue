<script>
export default {
	name: 'EmptyState',
	props: {
		// True when the current user can configure repositories.
		isAdmin: { type: Boolean, default: false },
		// Link to the Lantern admin settings section (empty if unknown).
		settingsUrl: { type: String, default: '' },
	},
}
</script>

<template>
	<div class="lantern-emptystate" role="region" aria-label="Getting started with Lantern">
		<svg class="lantern-emptystate-icon" viewBox="0 0 24 24" width="56" height="56" aria-hidden="true">
			<path fill="currentColor"
				d="M12 2a3 3 0 0 0-3 3c0 .35.06.69.17 1H8a1 1 0 0 0-1 1v1H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V7a1 1 0 0 0-1-1h-1.17c.11-.31.17-.65.17-1a3 3 0 0 0-3-3m0 2a1 1 0 0 1 1 1 1 1 0 0 1-1 1 1 1 0 0 1-1-1 1 1 0 0 1 1-1M9 8h6v1H9zm-3 3h12v9H6zm6 1.5a2.5 2.5 0 0 0-2.5 2.5c0 1.74 2.5 4.5 2.5 4.5s2.5-2.76 2.5-4.5A2.5 2.5 0 0 0 12 12.5m0 1.5a1 1 0 0 1 1 1 1 1 0 0 1-1 1 1 1 0 0 1-1-1 1 1 0 0 1 1-1" />
		</svg>

		<h2 class="lantern-emptystate-title">Welcome to Lantern</h2>

		<p class="lantern-emptystate-lead">
			Lantern shines a light on git repositories stored on this Nextcloud
			server — browse their files, read code with syntax highlighting, and
			look through their commit history. All read-only.
		</p>

		<template v-if="isAdmin">
			<p class="lantern-emptystate-action">
				No repositories are configured yet. Add one to get started.
			</p>
			<a v-if="settingsUrl" class="lantern-emptystate-button" :href="settingsUrl">
				Configure repositories
			</a>
			<p v-else class="lantern-emptystate-hint">
				Go to Settings → Administration → Lantern to add a repository.
			</p>
		</template>
		<template v-else>
			<p class="lantern-emptystate-action">
				No repositories yet. An administrator can add server-side repos
				under Settings → Administration → Lantern.
			</p>
		</template>

		<p class="lantern-emptystate-tip">
			You can also add your own repositories from the sidebar — a folder in
			<strong>your Files</strong>, or a <strong>GitHub</strong> or
			<strong>GitLab</strong> repository.
		</p>

		<p class="lantern-emptystate-scope">
			<strong>Good to know:</strong> Lantern can browse repositories on this
			server (admin-configured paths or folders in your Files) and GitHub or
			GitLab repositories you connect. It is read-only and never modifies a repo.
		</p>
	</div>
</template>
