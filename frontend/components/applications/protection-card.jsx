import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { ChevronRight, Shield, ShieldCheck, ShieldOff } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * What is guarding this site, in one place.
 *
 * Four switches that each live on their own screen, so the only way to know the
 * site's posture was to open four of them and remember. Every value here is
 * already in the application payload the page has fetched — this card costs
 * nothing extra.
 *
 * Status, not control. Three of the four cannot be turned ON without real
 * choices — password protection needs a username and password, fail2ban needs
 * ban and retry windows, and the firewall's PUT requires `mode` AND
 * `categories`, so a switch here would silently wipe a site's rule selection.
 * The fourth is a choice between three bot policies, not an on/off. A row that
 * only worked in one direction, or that destroyed settings, would be worse than
 * a link.
 *
 * Each row is icon + word + colour, never colour alone: about 8% of men cannot
 * separate red from green, and "is my firewall on" is not a question to answer
 * in hue.
 */
export async function ProtectionCard({ application, items }) {
  const t = await getTranslations("applications.protection");

  if (items.length === 0) return null;

  const on = items.filter((item) => item.on).length;

  return (
    // The strip's "Review security" lands here. scroll-mt keeps the card clear
    // of the sticky header cluster, whose height is measured and published as
    // `--app-chrome` — a fixed number would be wrong the moment the reboot
    // banner appears or the breadcrumb wraps.
    <Card
      id="security"
      tabIndex={-1}
      aria-labelledby="security-heading"
      className="relative scroll-mt-[calc(var(--app-chrome,7rem)_+_1rem)] focus:outline-none after:pointer-events-none after:absolute after:inset-0 after:rounded-xl after:border-2 after:border-primary/40 after:opacity-0 after:transition-opacity after:duration-200 data-[jump-highlight=true]:after:opacity-100 motion-reduce:after:transition-none"
    >
      <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
        <div className="min-w-0 space-y-1">
          <CardTitle
            id="security-heading"
            as="h2"
            className="flex items-center gap-2 text-lg font-semibold"
          >
            <Shield className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          {/* Says what the four rows below ARE. Without it the card is a list of
              nouns and a count, and whether they are settings, alerts or a log
              is left to the reader to work out from the rows. */}
          <CardDescription>{t("description")}</CardDescription>
        </div>
        <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
          {t("summary", { on, total: items.length })}
        </span>
      </CardHeader>
      <CardContent className="p-0">
        <ul className="divide-y border-t">
          {items.map((item) => {
            const Icon = item.on ? ShieldCheck : ShieldOff;
            return (
              <li key={item.key}>
                <Link
                  href={item.href}
                  prefetch={false}
                  className="flex items-center gap-3 px-6 py-3 transition-colors hover:bg-muted/50"
                >
                  {/* Off is amber, and only on the mark. Muted grey read as
                      disabled — as though the row were unavailable rather than
                      switched off. Red would be wrong the other way: none of
                      these is broken, and spending red on "you have not turned
                      this on" leaves nothing louder for a site that is down. */}
                  <Icon
                    className={`size-4 shrink-0 ${item.on ? "text-success" : "text-warning"}`}
                  />
                  <span className="min-w-0 flex-1 truncate text-sm font-medium">{item.label}</span>
                  {/* The state in words. A coloured dot alone makes the reader
                      guess which colour means protected. */}
                  <span
                    className={`shrink-0 text-sm ${item.on ? "text-foreground" : "text-muted-foreground"}`}
                  >
                    {item.state}
                  </span>
                  <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                </Link>
              </li>
            );
          })}
        </ul>
      </CardContent>
    </Card>
  );
}
