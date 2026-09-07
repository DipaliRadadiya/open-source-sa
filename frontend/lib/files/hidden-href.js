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

  // Only the non-default is written. Showing is what this screen has always
  // done, so `?hidden=1` on every URL would be noise that says nothing.
  if (showHidden) params.set("hidden", "0");

  const query = params.toString();

  return `/applications/${appId}/files${query ? `?${query}` : ""}`;
}
