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

export const SUBGRID = "@3xl/versions:col-span-full @3xl/versions:grid @3xl/versions:grid-cols-subgrid";
