import { openBlankTab, paintPlaceholder } from "@/lib/browser/new-tab";

/**
 * Handing a magic-login token to a new tab, safely.
 *
 * Opening the tab, painting its holding page and discarding it are shared with
 * phpMyAdmin — see `lib/browser/new-tab.js`, which also explains why the tab
 * has to exist before the token does. What is left here is the one part that
 * is specific to this token.
 */

/**
 * POST the token into the tab.
 *
 * A form submission, never a URL with the token in the query string. A token in
 * a URL is written to the site's access log, the browser's history and any
 * outbound Referer — and this one is worth a full administrator session for the
 * next sixty seconds.
 *
 * Built through the DOM, never by writing a string of HTML. The token and the
 * URL would both be interpolated into markup otherwise, and "the token is
 * alphanumeric so it cannot break out" stops being true the day the token
 * format changes.
 *
 * The holding page painted while this was being fetched is simply replaced —
 * `document.body` is whatever is there now, and the form goes into it.
 */
export function submitMagicLogin(tab, session) {
  const doc = tab.document;
  const form = doc.createElement("form");
  form.method = "POST";
  form.action = session.url;

  const field = doc.createElement("input");
  field.type = "hidden";
  field.name = "sv_magic_login";
  field.value = session.token;

  form.appendChild(field);
  (doc.body ?? doc.documentElement).appendChild(form);
  form.submit();
}

/**
 * Open WordPress for a session that is already minted — no blank tab first.
 *
 * The tab is created only now, with the login URL and token in hand, and the
 * form goes into it in the same tick, so there is no about:blank wait for the
 * reader to watch. It relies on the click still counting as a user gesture
 * (Chrome and Firefox allow about five seconds after it). If the browser
 * refuses, this returns false and the caller offers a button — a fresh click
 * the browser will honour — instead of failing.
 */
export function openMagicLogin(session, { message, title } = {}) {
  const tab = openBlankTab();
  if (!tab) return false;
  // WordPress takes a couple of seconds to answer the POST; until it does the
  // tab says what it is doing rather than sitting white.
  if (message) paintPlaceholder(tab, message, title);
  submitMagicLogin(tab, session);
  return true;
}
