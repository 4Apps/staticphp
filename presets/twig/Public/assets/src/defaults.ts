import 'core-js/es/object/assign';
import 'base/customPolyfill';
// Side-effect import: bootstrap's js registers itself on the page.
// Was require(), which is a CommonJS call inside an ES module.
import 'bootstrap';

import { CONFIG } from 'base/config';
import { Utils } from 'base/utils';

// Assign stuff to global context
window.Utils = Utils;

// -- BASE --
// import init from './base/js/default.js';
// init($);

// Export all.
// CONFIG carries the build info injected by the bundler. It was never imported here, so
// the whole module was tree-shaken out and the version never reached the browser.
export { CONFIG, Utils };
