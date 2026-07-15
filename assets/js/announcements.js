/**
 * Trumpet — "new announcements since your last visit" indicator.
 *
 * Stores the publish timestamp (Unix seconds) of the newest announcement the
 * visitor has actually scrolled into view, in localStorage. On load it compares
 * the newest announcement on the page against that stored value and, if newer
 * ones exist, sets the [announcements_indicator] banner label to "New
 * Announcements". The label reverts to "Announcements" — and the stored value
 * advances — only once the announcements have been scrolled into view, tracked
 * with an IntersectionObserver.
 *
 * The banner ([announcements_indicator]) and the list ([list_announcements])
 * are independent shortcodes, so both are looked up at document scope rather
 * than assuming one is nested inside the other. On a first visit (nothing
 * stored) every announcement counts as unseen, so the banner reads "New
 * Announcements".
 *
 * Storage is per-browser, not per-person: the same visitor on another device
 * starts fresh. That is an accepted trade-off for a public page.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'trumpet_last_seen';
	var LABEL_DEFAULT = 'Announcements';
	var LABEL_NEW = 'New Announcements';

	/**
	 * Set the banner label text and toggle the "has new" state.
	 *
	 * As well as marking the banner, this toggles a `trumpet-has-new` class on
	 * <body> so the theme can style any element (e.g. a text module) based on
	 * whether unseen announcements exist — e.g.
	 *   body.trumpet-has-new .my-text-module { background: #0073aa; }
	 *
	 * @param {HTMLElement} banner
	 * @param {string}      text
	 * @param {boolean}     hasNew
	 */
	function setBannerLabel(banner, text, hasNew) {
		var label = banner.querySelector('.announcements-new-banner__text');
		if (label) {
			label.textContent = text;
		}
		banner.classList.toggle('announcements-new-banner--has-new', hasNew);
		document.body.classList.toggle('trumpet-has-new', hasNew);
	}

	/**
	 * Read the stored "last seen" timestamp. Returns 0 when nothing is stored
	 * or when localStorage is unavailable (e.g. some private-browsing modes).
	 *
	 * @return {number}
	 */
	function readLastSeen() {
		try {
			var value = window.localStorage.getItem(STORAGE_KEY);
			var parsed = parseInt(value, 10);
			return isNaN(parsed) ? 0 : parsed;
		} catch (e) {
			return 0;
		}
	}

	/**
	 * Persist the "last seen" timestamp, never moving it backwards.
	 *
	 * @param {number} timestamp
	 */
	function writeLastSeen(timestamp) {
		try {
			var current = readLastSeen();
			if (timestamp > current) {
				window.localStorage.setItem(STORAGE_KEY, String(timestamp));
			}
		} catch (e) {
			/* Storage unavailable — indicator simply won't persist. */
		}
	}

	function init() {
		var banner = document.querySelector('.announcements-new-banner');
		if (!banner) {
			return;
		}

		var announcements = Array.prototype.slice.call(
			document.querySelectorAll('.announcement[data-published]')
		);
		if (!announcements.length) {
			return;
		}

		var lastSeen = readLastSeen();

		// The newest publish time on the page, and the unseen announcements.
		var newest = 0;
		var unseen = [];
		announcements.forEach(function (el) {
			var published = parseInt(el.getAttribute('data-published'), 10);
			if (isNaN(published)) {
				return;
			}
			if (published > newest) {
				newest = published;
			}
			if (published > lastSeen) {
				unseen.push(el);
				el.classList.add('announcement--unseen');
			}
		});

		if (!unseen.length) {
			return;
		}

		setBannerLabel(banner, LABEL_NEW, true);
		watchUnseen(unseen, newest, banner);
	}

	/**
	 * Watch the unseen announcements; as each scrolls into view, advance the
	 * stored timestamp. Once the newest has been seen, revert the banner label.
	 *
	 * @param {HTMLElement[]} unseen
	 * @param {number}        newest
	 * @param {HTMLElement}   banner
	 */
	function watchUnseen(unseen, newest, banner) {
		// Fallback for browsers without IntersectionObserver: mark all seen for
		// the next visit, but leave the "New Announcements" label for this one.
		if (!('IntersectionObserver' in window)) {
			writeLastSeen(newest);
			return;
		}

		var seenMax = 0;

		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) {
					return;
				}

				var el = entry.target;
				var published = parseInt(el.getAttribute('data-published'), 10);
				if (!isNaN(published) && published > seenMax) {
					seenMax = published;
				}

				el.classList.remove('announcement--unseen');
				writeLastSeen(published);
				observer.unobserve(el);

				if (seenMax >= newest) {
					setBannerLabel(banner, LABEL_DEFAULT, false);
					observer.disconnect();
				}
			});
		}, {
			// Count as "seen" once a reasonable slice is on screen.
			threshold: 0.5
		});

		unseen.forEach(function (el) {
			observer.observe(el);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
