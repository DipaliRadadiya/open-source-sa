import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { AlertTriangle, CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";

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
     * A named heading with the findings beneath it, not one long sentence
     * stretched between the two edges of a 1100px band. Justified across that
     * width the strip had a title at the far left, three buttons at the far
     * right and roughly 500px of nothing in the middle — which reads as an
     * empty box even though every pixel of it is doing something.
     *
     * `max-w-xl` on the text is what actually fixes it: the message stops
     * growing with the viewport, so the block stays a paragraph beside its
     * actions instead of a thin line spanning the screen.
     */
    <div className="flex flex-col gap-2 rounded-xl border border-warning/30 bg-warning/5 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-4 sm:py-3">
      <div className="flex min-w-0 items-start gap-2.5">
        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
        <div className="min-w-0 max-w-xl space-y-0.5">
          <p className="text-sm font-semibold leading-tight max-sm:sr-only">{t("title")}</p>
          {/* The findings themselves, not a count that sends the reader hunting
              for which of the cards below is the unhappy one. */}
          <p className="text-sm leading-snug wrap-anywhere text-muted-foreground">
            {items.map((item) => item.label).join(" · ")}
          </p>
        </div>
      </div>

      {/* shrink-0 so the buttons never compress into stacked single words, and
          wrap so a narrow screen puts them on one line rather than three full
          width rows — that stacking is what made this 166px tall on a phone. */}
      <div className="flex shrink-0 flex-wrap gap-1.5 sm:gap-2">
        {items.map((item) => (
          <Button key={item.key} asChild variant="outline" size="sm">
            <Link href={item.href} prefetch={false}>
              {item.action}
            </Link>
          </Button>
        ))}
      </div>
    </div>
  );
}
