// @nextcloud/babel-config exports a COMPLETE babel config object (presets +
// plugins), not a preset — so it must be re-exported here, not listed as a
// preset string in a .babelrc. The previous .babelrc was incorrect.
module.exports = require('@nextcloud/babel-config')
