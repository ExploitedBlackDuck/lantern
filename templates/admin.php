<?php
/** @var array $_ */
script('lantern', 'lantern-admin');
?>
<div class="section" id="lantern-admin">
	<h2>Lantern</h2>
	<p class="settings-hint">
		Configure which server-side git repositories Lantern may browse.
		Provide a JSON array of objects with <code>id</code>, <code>name</code>,
		and absolute <code>path</code>. Optionally restrict all paths to a base
		directory.
	</p>
	<p class="settings-hint" style="border-left:4px solid var(--color-warning, #e9a000);padding-left:8px;">
		<strong>Access note:</strong> every repository you add here is readable by
		<em>all</em> Lantern users on this server. There is no per-repository
		access control yet. Do not point Lantern at a repository containing
		secrets that some of those users should not see. (Read-only does not mean
		access-restricted.)
	</p>
	<p>
		<label for="lantern-base">Allowed base directory (optional)</label><br>
		<input type="text" id="lantern-base" class="lantern-wide"
			value="<?php p($_['allowed_base']); ?>"
			placeholder="/srv/git">
	</p>
	<p>
		<label for="lantern-gitpath">Git binary path (optional)</label><br>
		<input type="text" id="lantern-gitpath" class="lantern-wide"
			value="<?php p($_['git_path']); ?>"
			placeholder="/usr/bin/git">
		<br><span class="settings-hint">Leave blank to use <code>git</code> on
		the server's PATH. Set an absolute path if git isn't on PATH (e.g. in a
		container).</span>
	</p>
	<p>
		<label for="lantern-repos">Repositories (JSON)</label><br>
		<textarea id="lantern-repos" rows="10" class="lantern-wide"><?php p($_['repos']); ?></textarea>
	</p>
	<button id="lantern-save" class="primary">Save</button>
	<span id="lantern-save-status" aria-live="polite"></span>
</div>
