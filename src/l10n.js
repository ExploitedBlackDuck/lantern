// Translation helpers bound to the Lantern app id. Nextcloud loads the app's
// l10n/<lang>.json automatically; when no catalog matches the active locale the
// source (English) string is returned, so the UI always renders.
//
// Registered globally on each Vue app (see main.js / admin.js) so templates can
// call t('…') / n('…','…',count) directly.
import { translate, translatePlural } from '@nextcloud/l10n'

const APP_ID = 'lantern'

export const t = (text, vars, count) => translate(APP_ID, text, vars, count)
export const n = (singular, plural, count, vars) => translatePlural(APP_ID, singular, plural, count, vars)
