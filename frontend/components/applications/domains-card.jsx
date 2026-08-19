import Link from "next/link";
import { useTranslations } from "next-intl";
import { ArrowRight, Globe2, ShieldCheck, ShieldOff } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

// Four rows plus a counted remainder. Enough to answer "what answers to this
// site" for the overwhelming majority, without letting a site with forty
// aliases turn the dashboard into a domain list — that screen already exists.
const SHOWN = 4;

/**
 * The two questions this card answers are "what names does it answer to" and
 * "is it encrypted". Everything else about a domain belongs on the Domains
 * screen.
 *
 * It used to print the primary domain and then "1 alias · no redirects" — a
 * count standing in for data the page had already fetched and thrown away.
 * Naming the domains is both the better answer and what gives this card the
 * same weight as Security beside it; two cards in a row with half the content
 * of each other is what made the grid look ragged.
 *
 * Rows deliberately match the Security card's rhythm — same padding, same
 * divider, same mono-value-then-muted-label shape. Gestalt similarity: two
 * cards side by side reading as one system rather than two.
 *
 * No certificate is a normal state, not an error — it says so plainly instead
 * of showing a red failure for a site that simply serves plain HTTP.
 */
export function DomainsCard({ application, domains = [], certificate = null, failed = false, href = null }) {
  const t = useTranslations("applications.domains");

  const secure = certificate?.status === "active";
  const promptCertificate = !failed && !secure;

  // Primary first, then aliases, then redirects — the order somebody reads them
  // in, not the order the API happened to return.
  const RANK = { primary: 0, alias: 1, redirect: 2 };
  const ordered = [...domains].sort((a, b) => (RANK[a.type] ?? 3) - (RANK[b.type] ?? 3));
  // A site always has at least its own name, even before a domain row exists.
  const rows = ordered.length
    ? ordered.slice(0, SHOWN)
    : [{ id: "self", domain: application.domain, type: "primary", type_title: null }];
  const extra = Math.max(0, ordered.length - SHOWN);

  return (
    <Card>
      <CardHeader className="gap-1.5">
        <div className="min-w-0 space-y-1">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <Globe2 className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          <CardDescription>{t("description")}</CardDescription>
        </div>
        {failed ? null : secure ? (
          <Badge
            variant={certificate.expiring_soon ? "warning" : "success"}
            className="w-fit gap-1.5 font-normal"
          >
            <ShieldCheck className="size-3" />
            {t("secured")}
          </Badge>
        ) : (
          <Badge variant="secondary" className="w-fit gap-1.5 font-normal">
            <ShieldOff className="size-3" />
            {t("noCertificate")}
          </Badge>
        )}
      </CardHeader>

      <CardContent className="flex flex-1 flex-col p-0">
        {failed ? (
          <p className="px-(--card-spacing) text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : (
          <ul className="divide-y border-t">
            {rows.map((domain) => (
              <li key={domain.id} className="flex items-center gap-3 px-6 py-3">
                <Globe2 className="size-4 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1 truncate font-mono text-xs">{domain.domain}</span>
                <span className="shrink-0 text-xs text-muted-foreground">
                  {domain.type_title ?? t(`types.${domain.type}`)}
                </span>
              </li>
            ))}
            {extra > 0 ? (
              <li className="px-6 py-2.5 text-xs text-muted-foreground">{t("more", { count: extra })}</li>
            ) : null}
            {secure && certificate.expires_at_human ? (
              <li className="px-6 py-2.5 text-xs text-muted-foreground">
                {t("expires", { when: certificate.expires_at_human })}
              </li>
            ) : null}
          </ul>
        )}

        {href ? (
          // No mt-auto: a site with a single domain has a short list, and
          // floating the button to the card foot left ~90px of nothing
          // between the two.
          <div className="px-(--card-spacing) pt-(--card-spacing)">
            <Button asChild variant={promptCertificate ? "default" : "outline"} size="sm">
              <Link href={promptCertificate ? `${href}?tab=ssl` : href} prefetch={false}>
                {promptCertificate ? t("issueCertificate") : t("manage")}
                <ArrowRight className="size-3.5" />
              </Link>
            </Button>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
