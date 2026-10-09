import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ArrowRight, Globe2, Loader2 } from "lucide-react";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DomainText } from "@/components/ui/domain-text";

// Four rows plus a counted remainder; the full list is on the Domains screen.
const SHOWN = 4;

// No certificate is a normal state (plain HTTP), not an error.
export function DomainsCard({ application, domains = [], certificate = null, failed = false, href = null }) {
  const t = useTranslations("applications.domains");

  const secure = certificate?.status === "active";
  // A new site gets its certificate automatically; do not prompt for one on its way.
  const issuing = certificate?.status === "pending" || certificate?.status === "issuing";
  const promptCertificate = !failed && !secure && !issuing;

  // Primary, then aliases, then redirects, regardless of API order.
  const RANK = { primary: 0, alias: 1, redirect: 2 };
  const ordered = [...domains].sort((a, b) => (RANK[a.type] ?? 3) - (RANK[b.type] ?? 3));
  // A site always has at least its own name, even before a domain row exists.
  const rows = ordered.length
    ? ordered.slice(0, SHOWN)
    : [{ id: "self", domain: application.domain, type: "primary", type_title: null }];
  const extra = Math.max(0, ordered.length - SHOWN);

  return (
    <Card>
      {/* Re-reads until the certificate is issued or fails. */}
      {issuing ? <AutoRefresh intervalMs={5000} stopAfterMs={300000} /> : null}
      {/* HTTPS and its renewal date are the HTTPS tile's; the card lists the names. The
          button sits in the header: a footer strip for one button was mostly empty (7 Oct). */}
      <CardHeader className="items-center border-b">
        <CardTitle as="h2" className="flex flex-wrap items-center gap-2">
          {t("title")}
          {!failed && issuing ? (
            <Badge variant="muted" className="gap-1.5 font-normal">
              <Loader2 className="size-3 animate-spin" />
              {t("ssl.issuing")}
            </Badge>
          ) : null}
        </CardTitle>
        {href ? (
          <CardAction className="row-span-1 self-center">
            <Button asChild variant={promptCertificate ? "default" : "outline"} size="sm">
              <Link href={promptCertificate ? `${href}?tab=ssl` : href} prefetch={false}>
                {promptCertificate ? t("issueCertificate") : t("manage")}
                <ArrowRight className="size-3.5" />
              </Link>
            </Button>
          </CardAction>
        ) : null}
      </CardHeader>

      <CardContent className="p-0">
        {failed ? (
          <p className="px-(--card-spacing) text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : (
          <ul className="-mt-(--card-spacing) divide-y">
            {rows.map((domain) => (
              <li key={domain.id} className="flex items-center gap-3 px-(--card-spacing) py-3">
                <Globe2 className="size-4 shrink-0 text-muted-foreground" />
                <DomainText domain={domain.domain} className="min-w-0 flex-1 font-mono text-xs" />
                <Badge variant={domain.type === "primary" ? "default" : "outline"} className="shrink-0 font-normal">
                  {domain.type_title ?? t(`types.${domain.type}`)}
                </Badge>
              </li>
            ))}
            {extra > 0 ? (
              <li className="px-(--card-spacing) py-2.5 text-xs text-muted-foreground">{t("more", { count: extra })}</li>
            ) : null}
            {issuing ? (
              <li className="px-(--card-spacing) py-2.5 text-xs text-muted-foreground">{t("ssl.issuingBody")}</li>
            ) : null}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
