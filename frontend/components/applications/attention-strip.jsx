import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { AlertTriangle, CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { SectionJumpLink } from "@/components/ui/section-jump-link";

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
     * One finding per row, each beside its own button.
     *
     * The findings used to be joined into a single sentence with the buttons
     * gathered at the right, which worked while every label was three words
     * this page had written itself. The server's own checks send whole
     * sentences — "SSL certificate expires in 0 days." — and five of those run
     * together above five unattached buttons leaves no way to tell which button
     * belongs to which sentence.
     *
     * Rows are dense enough that one finding still reads as a band rather than
     * a list of one.
     */
    <div className="rounded-xl border border-warning/30 bg-warning/5 px-4 py-2.5 sm:py-3">
      <div className="flex items-center gap-2.5">
        <AlertTriangle className="size-4 shrink-0 text-warning" />
        <p className="text-sm font-semibold leading-tight">{t("title")}</p>
      </div>

      <ul className="mt-1.5 space-y-1.5 sm:ml-[26px] sm:mt-1">
        {items.map((item) => (
          <li
            key={item.key}
            className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
          >
            {/* min-w-48 rather than min-w-0: beside a shrink-0 button, a plain
                min-w-0 lets the sentence squeeze to one word per line. */}
            <span className="min-w-48 text-sm leading-snug wrap-anywhere text-muted-foreground">
              {item.label}
            </span>
            {item.action && item.href ? (
              <span className="shrink-0">
                {item.href.startsWith("#") ? (
                  <SectionJumpLink href={item.href}>{item.action}</SectionJumpLink>
                ) : (
                  <Button asChild variant="outline" size="sm">
                    <Link href={item.href} prefetch={false}>
                      {item.action}
                    </Link>
                  </Button>
                )}
              </span>
            ) : null}
          </li>
        ))}
      </ul>
    </div>
  );
}
