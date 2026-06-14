# Releasing & Signing Lantern

Two stages. Stage 1 (GitHub release) lets anyone install manually. Stage 2
(App Store) requires Nextcloud code-signing.

## Prerequisites for any release

Build the frontend first — the source tarball does not include `js/`:

```bash
npm ci
npm run build          # emits js/lantern-main.js and js/lantern-admin.js
```

Confirm the bundle names are `lantern-main.js` / `lantern-admin.js` (NOT
`lantern-lantern-main.js`). The page silently shows nothing if they're wrong.

Also commit a screenshot at `docs/screenshot-files.png` (the path referenced by
`<screenshot>` in info.xml) — e.g. the Files view with a file open.

## Stage 1 — GitHub release (manual install)

```bash
make release           # builds, then packages runtime-only files incl. js/
# -> build/release/lantern.tar.gz
```

Create the public repo at github.com/ExploitedBlackDuck/lantern, push the source,
tag a release (e.g. v1.0.6), and attach `build/release/lantern.tar.gz`.
Users install by extracting into their `custom_apps/` and running
`occ app:enable lantern` (and ensuring `git` is installed server-side).

## Stage 2 — App Store (apps.nextcloud.com)

### 1. Generate a keypair (app id is lowercase: `lantern`)

```bash
mkdir -p ~/.nextcloud/certificates
cd ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 -keyout lantern.key -out lantern.csr -subj "/CN=lantern"
```

Keep `lantern.key` secret and never commit it.

### 2. Register ownership

Open a pull request adding `lantern.csr` to
https://github.com/nextcloud/app-certificate-requests , linking the public repo.
Make sure your GitHub profile shows a public email. Nextcloud reviews it and
issues `lantern.crt`.

### 3. Sign the built app (LAST step — any change after this requires re-signing)

```bash
# from a Nextcloud server that has occ, with the built app at <path>:
php occ integrity:sign-app \
  --privateKey=$HOME/.nextcloud/certificates/lantern.key \
  --certificate=$HOME/.nextcloud/certificates/lantern.crt \
  --path=/path/to/lantern
# writes appinfo/signature.json
```

### 4. Package & upload

Re-create the tarball (now containing signature.json) and upload via the
"Submit your app" web form on apps.nextcloud.com, or the App Store REST API.
info.xml is schema-validated on upload (it already passes locally).

## Identity note

This release is authored by **Paul Ammann** under the GitHub handle
**ExploitedBlackDuck**. The certificate flow ties to a GitHub account with a
public email, so make sure that account's profile shows a working address
before step 2.
