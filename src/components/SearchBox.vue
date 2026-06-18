<script>
export default {
	name: 'SearchBox',
	emits: ['search'],
	data() {
		return { query: '' }
	},
	methods: {
		submit() {
			const q = this.query.trim()
			if (q) this.$emit('search', q)
		},
		clear() {
			this.query = ''
			this.$emit('search', '')
		},
	},
}
</script>

<template>
	<form class="lantern-searchbox" role="search" @submit.prevent="submit">
		<label for="lantern-search-input" class="lantern-refpicker-label">{{ t('Search this repository') }}</label>
		<div class="lantern-searchbox-row">
			<input
				id="lantern-search-input"
				v-model="query"
				type="search"
				:placeholder="t('Find in files…')"
				@search="query === '' && clear()">
			<button type="submit" class="primary">{{ t('Go') }}</button>
		</div>
	</form>
</template>
