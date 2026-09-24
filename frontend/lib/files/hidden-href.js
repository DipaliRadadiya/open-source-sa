/**
 * Where the "show/hide hidden files" control points.
 *
 * The listing is fetched on the server, so this choice lives in the URL rather
 * than in component state: it has to reach the server to change what comes
 * back. That also makes a view shareable and survives a reload without an
 * effect reading storage after mount.
 */
export function hiddenToggleHref({ appId, path = "", showHidden = true }) {
  const params = new URLSearchParams();

  // Carried, not dropped. The toggle must not send someone back to the site
  // root from three folders deep.
  if (path) params.set("path", path);

  // Both directions are explicit: the remembered choice (lib/files/view-prefs)
  // can be "hide", and a bare URL would then answer with the cookie instead of
  // the click.
  params.set("hidden", showHidden ? "0" : "1");

  const query = params.toString();

  return `/applications/${appId}/files${query ? `?${query}` : ""}`;
}
