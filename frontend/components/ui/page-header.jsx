/**
 * The heading every detail screen opens with: a title and one line saying what
 * the screen is for.
 *
 * Ten pages had this block copied out by hand, which is how the firewall page
 * ended up with a title that disagreed with its own sidebar label — there was
 * no single place to look at them together.
 *
 * It used to carry a "Back to …" button too. The breadcrumb above the page now
 * does that job properly — it names the site you are in and lets you stop
 * there, where the button always jumped past it to the full list.
 *
 * `children` renders under the subtitle for the screens that carry more (a
 * status badge, a facts row); the pages with a genuinely different heading —
 * the application and database detail pages, which lead with a name and a
 * status — keep their own markup rather than being bent into this one.
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
