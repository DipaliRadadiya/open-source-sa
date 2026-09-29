/**
 * Opening a sign-in in a new tab only once its address exists.
 *
 * Opening an empty tab on the click and filling it in later kept the browser's
 * permission to open a tab, but left `about:blank` in the address bar for the
 * whole round trip (Krishna, 2026-09-29). The tab now opens straight onto the
 * real address, so its first page is the destination.
 *
 * The browser still requires a recent click. Where it reports that the click
 * has expired — the round trip took too long — both helpers return false
 * without trying, and the caller offers a button, which is a fresh click.
 */

/** Whether the browser would still let this page open a tab. */
export function canOpenTab() {
  const activation = typeof navigator === "undefined" ? null : navigator.userActivation;
  return activation ? activation.isActive : true;
}

/** A GET address in a new tab. False when the browser refused. */
export function openUrlInNewTab(url) {
  if (!canOpenTab()) return false;
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
  if (!canOpenTab()) return false;
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
