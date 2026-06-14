<?php
/** App shell. The Vue bundle (lantern-main) mounts into #lantern.
 * data-* attributes carry first-paint context (admin? settings URL?) so the
 * empty state can guide the user without an extra round-trip. */
?>
<div id="lantern"
	data-is-admin="<?php p(!empty($_['is_admin']) ? '1' : '0'); ?>"
	data-settings-url="<?php p($_['settings_url'] ?? ''); ?>"></div>
