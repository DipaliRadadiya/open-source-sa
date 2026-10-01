import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { ChevronRight, Shield, ShieldCheck, ShieldOff } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

// Status only, no toggles: enabling most of these needs real choices, and a
// toggle could wipe settings (the firewall PUT requires `mode` and `categories`).
export async function ProtectionCard({ application, items }) {
  const t = await getTranslations("applications.protection");

  if (items.length === 0) return null;

  const on = items.filter((item) => item.on).length;

  return (
    // Target of the strip's "Review security"; scroll-mt uses the measured
    // `--app-chrome` height of the sticky header, never a fixed number.
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
            /* A row with its own control (the folder lock) acts in place, so no link. */
            if (!item.href) {
              return (
                <li key={item.key} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-6 py-3">
                  {/* Label stays on one line; state and button wrap below it together. As
                      flex-1 the label was squeezed to one word per line. */}
                  <span className="flex items-center gap-3 whitespace-nowrap">
                    <Icon
                      className={`size-4 shrink-0 ${item.on ? "text-success" : "text-warning"}`}
                    />
                    <span className="text-sm font-medium">{item.label}</span>
                  </span>
                  <span className="ms-auto flex items-center gap-3">
                    <span
                      className={`text-sm ${item.on ? "text-foreground" : "text-muted-foreground"}`}
                    >
                      {item.state}
                    </span>
                    {item.control ?? null}
                  </span>
                </li>
              );
            }
            return (
              <li key={item.key}>
                <Link
                  href={item.href}
                  prefetch={false}
                  className="flex items-center gap-3 px-6 py-3 transition-colors hover:bg-muted/50"
                >
                  {/* Off is amber on the mark only: grey reads as unavailable, red is kept for
                      real breakage. */}
                  <Icon
                    className={`size-4 shrink-0 ${item.on ? "text-success" : "text-warning"}`}
                  />
                  <span className="min-w-0 flex-1 text-sm font-medium break-words">{item.label}</span>
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
