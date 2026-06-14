<script>
import { fetchRefs } from '../api.js'

export default {
	name: 'RefPicker',
	props: {
		repo: { type: Object, required: true },
		currentRef: { type: String, default: '' },
	},
	emits: ['select'],
	data() {
		return { refs: [] }
	},
	computed: {
		branches() {
			return this.refs.filter((r) => r.type === 'branch')
		},
		tags() {
			return this.refs.filter((r) => r.type === 'tag')
		},
	},
	watch: {
		repo: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.refs = await fetchRefs(this.repo.id)
			} catch (e) {
				this.refs = []
			}
		},
	},
}
</script>

<template>
	<div v-if="refs.length" class="lantern-refpicker">
		<label for="lantern-ref-select" class="lantern-refpicker-label">Branch / tag</label>
		<select
			id="lantern-ref-select"
			class="lantern-refpicker-select"
			:value="currentRef"
			@change="$emit('select', $event.target.value)">
			<optgroup v-if="branches.length" label="Branches">
				<option v-for="r in branches" :key="'b/' + r.name" :value="r.name">
					{{ r.name }}{{ r.isDefault ? ' (default)' : '' }}
				</option>
			</optgroup>
			<optgroup v-if="tags.length" label="Tags">
				<option v-for="r in tags" :key="'t/' + r.name" :value="r.name">{{ r.name }}</option>
			</optgroup>
		</select>
	</div>
</template>
