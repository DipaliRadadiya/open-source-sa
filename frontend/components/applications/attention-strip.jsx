import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { AlertTriangle, ArrowRight, CheckCircle2 } from "lucide-react";
import { SectionJumpLink } from "@/components/ui/section-jump-link";

/*
 * The row, and why it is a row rather than a label with a button parked at the
 * far right.
 *
 * `justify-between` across a full-width band put "SSL not installed" at one
 * edge and "Issue SSL" at the other with ~900px of nothing between them, and
 * two of those stacked gave four things floating in a rectangle: nothing said
 * which button belonged to which sentence except being roughly level with it.
 * Outline buttons of different widths made the right edge ragged on top of it.
 *
 * So the whole row is the target, the way the admin dashboard's attention list
 * already does it. The distance stops mattering once the thing being pointed at
 * lights up as one object, and the action can drop to a text link — which also
 * ends the ragged-width problem, because there is no box to be ragged.
 *
 * Stacked below `sm`, side by side above it, rather than letting flex-wrap
 * decide per row: wrapping on measurement meant a short finding kept its action
 * inline while the next one dropped it to a second line, so one phone screen
 * showed the same two rows in two different shapes.
 */
const ROW =
  "group flex flex-col items-start gap-1 px-4 py-2.5 transition-colors " +
  "sm:flex-row sm:items-center sm:gap-x-4 " +
  "hover:bg-warning/10 focus-visible:bg-warning/10 focus-visible:outline-none " +
  "focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring";

function Finding({ label, action }) {
  return (
    <>
      {/* min-w-48, not min-w-0: beside a shrink-0 action, min-w-0 lets a whole
          sentence squeeze into one word per line. */}
      <span className="min-w-48 flex-1 text-sm leading-snug wrap-anywhere">{label}</span>
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

/**
 * What is not right about this site, above everything that is.
 *
 * The cards below each answer one question well, but a reader arriving at a site
 * they did not set up has to open four of them to learn there is no certificate,
 * no backup and nothing guarding it. This says it once, at the top, with the
 * screen that fixes each one.
 *
 * The actions navigate rather than act. Every one of these needs a decision the
 * strip cannot make: a certificate needs its type, a backup schedule needs a
 * destination and a frequency, and the protections each need real configuration.
 * A button here that fired a request would either guess those or fail.
 *
 * Amber, not red. These are risks to attend to, not failures that just happened
 * — a site with no certificate is serving perfectly well over http. Red is for
 * something broken now, and spending it here leaves nothing louder for when a
 * site is actually down.
 */
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
    /*
     * One finding per row, each row its own target.
     *
     * The findings used to be joined into a single sentence with the buttons
     * gathered at the right, which worked while every label was three words
     * this page had written itself. The server's own checks send whole
     * sentences — "SSL certificate expires in 0 days." — and five of those run
     * together above five unattached buttons leaves no way to tell which button
     * belongs to which sentence.
     *
     * `overflow-hidden` so the first and last rows' hover tint is clipped by
     * the rounded border instead of squaring off its corners.
     */
    <div className="overflow-hidden rounded-xl border border-warning/30 bg-warning/5">
      <div className="flex items-center gap-2.5 px-4 py-2.5">
        <AlertTriangle className="size-4 shrink-0 text-warning" />
        <p className="text-sm font-semibold leading-tight">{t("title")}</p>
      </div>

      {/* Dividers, not spacing: the rows are a list of separate problems, and a
          gap alone left it ambiguous whether the action on the right belonged to
          the line above it or below. */}
      <ul className="divide-y divide-warning/20 border-t border-warning/20">
        {items.map((item) => (
          <li key={item.key}>
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
              // An issue kind the panel has no screen for still gets its row.
              // No hover, because there is nowhere to go.
              <p className="px-4 py-2.5 text-sm leading-snug wrap-anywhere">{item.label}</p>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
