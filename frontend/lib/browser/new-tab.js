/**
 * Opening a sign-in in a new tab only once its address exists.
 *
 * Opening an empty tab on the click and filling it in later kept the browser's
 * permission to open a tab, but left `about:blank` in the address bar for the
 * whole round trip (Krishna, 2026-09-29). The tab now opens straight onto the
 * real address, so its first page is the destination.
 *
 * The tab opens when the answer arrives and nothing is offered in its place
 * (Krishna, 2026-09-30): a second "Open" button in a toast was one click too
 * many. When a slow answer lands after the browser's ~5 s window, the browser
 * shows its own pop-up notice, and allowing pop-ups for the panel once makes
 * every later sign-in open directly.
 */

/** A GET address in a new tab. False when the browser refused. */
export function openUrlInNewTab(url) {
  const tab = window.open(url, "_blank");
  if (!tab) return false;
  // The site runs its own code in that tab; without this it could reach back
  // through window.opener and navigate the panel.
  try {
    tab.opener = null;
  } catch {
    // Already cross-origin on some browsers — nothing left to cut.
  }
  return true;
}

/**
 * A POST in a new tab, for sign-ins that must not put their token in a URL.
 * Submitted from this page with `target="_blank"`, so the tab's first
 * navigation is the POST itself.
 */
export function postInNewTab(url, fields) {
  const form = document.createElement("form");
  form.method = "POST";
  form.action = url;
  form.target = "_blank";
  form.rel = "noopener";
  form.hidden = true;
  for (const [name, value] of Object.entries(fields)) {
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }
  document.body.appendChild(form);
  form.submit();
  form.remove();
  return true;
}
