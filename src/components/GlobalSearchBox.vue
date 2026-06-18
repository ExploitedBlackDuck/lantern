<script>
export default {
	name: 'GlobalSearchBox',
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
	<form class="lantern-searchbox lantern-globalsearch" role="search" @submit.prevent="submit">
		<label for="lantern-global-search-input" class="lantern-refpicker-label">{{ t('Search all repositories') }}</label>
		<div class="lantern-searchbox-row">
			<input
				id="lantern-global-search-input"
				v-model="query"
				type="search"
				:placeholder="t('Find across every repo…')"
				@search="query === '' && clear()">
			<button type="submit" class="primary">{{ t('Go') }}</button>
		</div>
	</form>
</template>
