import { createApp } from 'vue'
import App from './App.vue'

const el = document.getElementById('lantern')
if (el) {
	createApp(App).mount(el)
}
