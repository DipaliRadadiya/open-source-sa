// Column template for the Installed versions table (PHP and Node). The header and every
// row are subgrids of one grid, so Actions can size to its buttons (longer in German and
// Russian) and still line up with its header. Capped (fit-content), or a rare three-button
// row would squeeze every other column until its badges spill out.
// No support column when the release schedule could not be read (a firewalled server):
// a column of dashes says nothing.
export function rowGrid(runtime, withSupport) {
  if (runtime === "node") {
    return withSupport
      ? "@3xl/versions:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,0.8fr)_fit-content(20rem)]"
      : "@3xl/versions:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,0.8fr)_fit-content(20rem)]";
  }
  return withSupport
    ? "@3xl/versions:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,0.8fr)_fit-content(20rem)]"
    : "@3xl/versions:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_fit-content(20rem)]";
}

// What a row (and its inner line) carries to join the table's columns.
export const SUBGRID = "@3xl/versions:col-span-full @3xl/versions:grid @3xl/versions:grid-cols-subgrid";
