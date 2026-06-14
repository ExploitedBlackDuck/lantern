<script>
export default {
	name: 'RepoList',
	props: {
		repos: { type: Array, default: () => [] },
		activeId: { type: String, default: null },
	},
	emits: ['select'],
}
</script>

<template>
	<div class="lantern-repolist">
		<h3 style="margin:4px 8px;">Repositories</h3>
		<ul role="list" class="lantern-tree">
			<li v-for="repo in repos" :key="repo.id">
				<button
					type="button"
					class="lantern-repo-item"
					:aria-current="repo.id === activeId ? 'true' : 'false'"
					:class="{ 'is-active': repo.id === activeId }"
					@click="$emit('select', repo)">
					<span class="name">{{ repo.name }}</span>
				</button>
			</li>
		</ul>
	</div>
</template>
