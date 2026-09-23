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
