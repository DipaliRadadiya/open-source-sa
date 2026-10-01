/**
 * The page heading: a title and a one-line subtitle. No back button; the
 * breadcrumb handles navigation. `children` renders under the subtitle (e.g. a
 * status badge or facts row).
 */
export function PageHeader({ title, subtitle, children }) {
  return (
    <div className="space-y-1">
      <h1 className="text-2xl font-semibold tracking-tight break-words">{title}</h1>
      {subtitle ? <p className="text-sm text-muted-foreground">{subtitle}</p> : null}
      {children}
    </div>
  );
}
