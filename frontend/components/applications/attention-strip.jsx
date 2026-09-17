import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { AlertTriangle, ArrowRight, CheckCircle2 } from "lucide-react";
import { SectionJumpLink } from "@/components/ui/section-jump-link";

/*
 * One finding, as a tile.
 *
 * This was a full-width row with the label at one edge and the action at the
 * other. `justify-between` put "SSL not installed" and "Issue SSL" ~900px
 * apart, and two of those stacked made an amber rectangle that was mostly air
 * — reported as taking "too much empty unused space", which it did.
 *
 * The whole tile is the target, so the pairing is made by the object lighting
 * up rather than by two things being roughly level, and the action sits
 * directly under the words it belongs to. `h-full` so two findings of
 * different lengths still read as one row instead of one box hanging short.
 */
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
    /*
      One band, one line where it fits.

      This was a heading row above a grid of half-width tiles, each holding
      three words on one line and its link on the next — reported twice as
      taking too much empty space, and both times the space was the layout
      rather than the colour.

      Now the heading sits inline with the findings and each finding is a chip
      sized to its own text, so two short ones take a single row instead of a
      heading plus two tall boxes. `flex-wrap` is what keeps it honest when the
      server sends whole sentences — "SSL certificate expires in 0 days." —
      five of which simply wrap onto further lines instead of being squeezed.
    */
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
              // An issue kind the panel has no screen for still gets its chip.
              // No hover, because there is nowhere to go.
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
