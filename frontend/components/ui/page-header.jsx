import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * The heading every detail screen opens with: a way back, a title, and one
 * line saying what the screen is for.
 *
 * Ten pages had this block copied out by hand, which is how the firewall page
 * ended up with a title that disagreed with its own sidebar label — there was
 * no single place to look at them together.
 *
 * `children` renders under the subtitle for the screens that carry more (a
 * status badge, a facts row); the pages with a genuinely different heading —
 * the application and database detail pages, which lead with a name and a
 * status — keep their own markup rather than being bent into this one.
 */
export function PageHeader({ backHref, backLabel, title, subtitle, children }) {
  return (
    <div className="space-y-3">
      {backHref ? (
        <Button asChild variant="ghost" size="sm" className="-ml-2">
          <Link href={backHref}>
            <ArrowLeft className="size-4" />
            {backLabel}
          </Link>
        </Button>
      ) : null}
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {subtitle ? <p className="text-sm text-muted-foreground">{subtitle}</p> : null}
        {children}
      </div>
    </div>
  );
}
