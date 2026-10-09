// No back button: the breadcrumb handles navigation. `actions` sit to the
// right of the title (the page's one primary action), wrapping below on a phone.
export function PageHeader({ title, subtitle, actions = null, children }) {
  const heading = (
    <div className="min-w-0 space-y-1">
      <h1 className="text-2xl font-semibold tracking-tight break-words">{title}</h1>
      {subtitle ? <p className="text-sm text-muted-foreground">{subtitle}</p> : null}
      {children}
    </div>
  );
  if (!actions) return heading;
  return (
    <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
      {heading}
      <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>
    </div>
  );
}
