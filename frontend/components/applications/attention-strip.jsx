import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { AlertTriangle, ArrowRight, CheckCircle2 } from "lucide-react";
import { SectionJumpLink } from "@/components/ui/section-jump-link";

/* One finding as a chip; the whole chip is the link target. */
const ROW =
  "group inline-flex max-w-full items-center gap-x-2 gap-y-0.5 rounded-lg border " +
  "border-warning/25 bg-background/70 px-2.5 py-1.5 transition-colors " +
  "hover:border-warning/50 hover:bg-background focus-visible:outline-none " +
  "focus-visible:ring-2 focus-visible:ring-ring";

function Finding({ label, action }) {
  return (
    <>
      <span className="text-sm leading-snug wrap-anywhere">{label}</span>
      <span className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary">
        {action}
        <ArrowRight
          className="size-3.5 transition-transform group-hover:translate-x-0.5"
          aria-hidden
        />
      </span>
    </>
  );
}

// Links rather than actions: each fix needs decisions the strip cannot make.
// Amber, not red: red is kept for a site that is actually down.
export async function AttentionStrip({ items }) {
  const t = await getTranslations("applications.attention");

  if (items.length === 0) {
    return (
      <div className="flex items-center gap-2 rounded-xl border bg-muted/30 px-4 py-2.5 text-sm">
        <CheckCircle2 className="size-4 shrink-0 text-success" />
        <span className="text-muted-foreground">{t("allClear")}</span>
      </div>
    );
  }

  return (
    /* Heading inline with the findings; chips size to their text and wrap, since
       the server's checks can send whole sentences. */
    <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-warning/30 bg-warning/5 p-2.5">
      <p className="inline-flex shrink-0 items-center gap-2 text-sm font-semibold leading-tight">
        <AlertTriangle className="size-4 shrink-0 text-warning" />
        {t("title")}
      </p>

      <ul className="flex min-w-0 flex-wrap items-center gap-2">
        {items.map((item) => (
          <li key={item.key} className="min-w-0">
            {item.action && item.href ? (
              item.href.startsWith("#") ? (
                <SectionJumpLink href={item.href} className={ROW}>
                  <Finding label={item.label} action={item.action} />
                </SectionJumpLink>
              ) : (
                <Link href={item.href} prefetch={false} className={ROW}>
                  <Finding label={item.label} action={item.action} />
                </Link>
              )
            ) : (
              // An issue kind with no panel screen still gets a chip, without hover.
              <p className="rounded-lg border border-warning/25 bg-background/70 px-2.5 py-1.5 text-sm leading-snug wrap-anywhere">
                {item.label}
              </p>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
