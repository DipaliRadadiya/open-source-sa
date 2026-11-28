// Only a Node app (server- or client-side rendering) has a package manager to install with. A
// static or PHP repository may have no package.json at all, so `npm ci` there fails the deploy.
const NODE_RENDERING = ["ssr", "csr"];

/**
 * The build command the create form should show after the rendering type or package manager
 * changes, or undefined to leave the field as it is.
 *
 * Only a value the form filled itself (empty, or one of the package-manager templates) is
 * replaced or cleared; anything the user typed stays.
 */
export function nextBuildCommand({ renderingType, packageManager, templates = {}, current = "" }) {
  const untouched = !current || Object.values(templates).includes(current);
  if (!untouched) return undefined;

  if (!NODE_RENDERING.includes(renderingType)) return current ? "" : undefined;

  const template = templates[packageManager];
  return template && template !== current ? template : undefined;
}
