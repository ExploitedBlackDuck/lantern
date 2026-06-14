<?php
/** @var array $_ */
script('lantern', 'lantern-admin');
?>
<div class="section" id="lantern-admin">
	<h2>Lantern</h2>
	<p class="settings-hint">
		Configure which server-side git repositories Lantern may browse. Add each
		repository's id, display name, and absolute path on this server. Use
		<strong>Test path</strong> to confirm a path is a real git repository
		before saving.
	</p>
	<p class="settings-hint" style="border-left:4px solid var(--color-warning, #e9a000);padding-left:8px;">
		<strong>Access note:</strong> every repository you add here is readable by
		<em>all</em> Lantern users on this server. There is no per-repository
		access control yet. Do not point Lantern at a repository containing
		secrets that some of those users should not see. (Read-only does not mean
		access-restricted.)
	</p>

	<!-- Vue admin form mounts here; initial config delivered via data-* attrs. -->
	<div id="lantern-admin-app"
		data-repos="<?php p($_['repos']); ?>"
		data-allowed-base="<?php p($_['allowed_base']); ?>"
		data-git-path="<?php p($_['git_path']); ?>"></div>
</div>
