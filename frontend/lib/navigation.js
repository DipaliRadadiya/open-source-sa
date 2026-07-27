// Shared sidebar nav-item styling — the polished active state (soft brand-tint
// pill + rounded left accent bar, roomy h-9 rows, hidden bar when collapsed).
// Used by BOTH the server panel and admin panel sidebars so they never drift.
export const NAV_ITEM_CLASS =
  "relative h-9 pl-3 transition-colors data-[active=true]:bg-primary/10 data-[active=true]:font-medium data-[active=true]:text-primary data-[active=true]:hover:bg-primary/15 data-[active=true]:hover:text-primary data-[active=true]:before:absolute data-[active=true]:before:inset-y-1 data-[active=true]:before:left-0 data-[active=true]:before:w-1 data-[active=true]:before:rounded-r-full data-[active=true]:before:bg-primary group-data-[collapsible=icon]:before:hidden";

// Buckets a flat, already-filtered list of nav items by their sub_level so the
// sidebar can render them under section headers. Pure grouping — no permission
// or panel logic lives here.
export function groupBySubLevel(items) {
  return items.reduce((groups, item) => {
    const key = item.sub_level || "";
    if (!groups[key]) groups[key] = [];
    groups[key].push(item);
    return groups;
  }, {});
}
