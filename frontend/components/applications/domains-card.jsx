import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ArrowRight, Globe2, Loader2, ShieldCheck, ShieldOff } from "lucide-react";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DomainText } from "@/components/ui/domain-text";

// Four rows plus a counted remainder; the full list is on the Domains screen.
const SHOWN = 4;

/**
 * What names the site answers to, and whether it is encrypted. Everything else
 * belongs on the Domains screen. Rows match the Security card's layout beside
 * it. No certificate is a normal state (plain HTTP), not an error.
 */
export function DomainsCard({ application, domains = [], certificate = null, failed = false, href = null }) {
  const t = useTranslations("applications.domains");

  const secure = certificate?.status === "active";
  // A new application gets its certificate automatically shortly after going
  // live; do not prompt for one already on its way.
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
      {/* Re-reads until the certificate is issued or fails, so the card and the
          attention strip update without a reload. */}
      {issuing ? <AutoRefresh intervalMs={5000} stopAfterMs={300000} /> : null}
      <CardHeader className="gap-1.5">
        <div className="min-w-0 space-y-1">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <Globe2 className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          <CardDescription>{t("description")}</CardDescription>
        </div>
        {failed ? null : issuing ? (
          <Badge variant="muted" className="w-fit gap-1.5 font-normal">
            <Loader2 className="size-3 animate-spin" />
            {t("ssl.issuing")}
          </Badge>
        ) : secure ? (
          <Badge
            variant={certificate.expiring_soon ? "warning" : "success"}
            className="w-fit gap-1.5 font-normal"
          >
            <ShieldCheck className="size-3" />
            {t("secured")}
          </Badge>
        ) : (
          <Badge variant="warning" className="w-fit gap-1.5 font-normal">
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
                <DomainText domain={domain.domain} className="min-w-0 flex-1 font-mono text-xs" />
                <Badge variant={domain.type === "primary" ? "default" : "outline"} className="shrink-0 font-normal">
                  {domain.type_title ?? t(`types.${domain.type}`)}
                </Badge>
              </li>
            ))}
            {extra > 0 ? (
              <li className="px-6 py-2.5 text-xs text-muted-foreground">{t("more", { count: extra })}</li>
            ) : null}
            {issuing ? (
              <li className="px-6 py-2.5 text-xs text-muted-foreground">{t("ssl.issuingBody")}</li>
            ) : null}
            {secure && certificate.expires_at_human ? (
              <li className="px-6 py-2.5 text-xs text-muted-foreground">
                {t("expires", { when: certificate.expires_at_human })}
              </li>
            ) : null}
          </ul>
        )}

        {href ? (
          // No mt-auto: with a short list it would leave a large gap above the button.
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
