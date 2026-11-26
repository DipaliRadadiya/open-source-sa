import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ArrowRight, Globe2, Loader2, Globe } from "lucide-react";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DomainText } from "@/components/ui/domain-text";

const SHOWN = 4;

export function DomainsCard({ application, domains = [], certificate = null, failed = false, href = null }) {
  const t = useTranslations("applications.domains");

  const secure = certificate?.status === "active";
  const issuing = certificate?.status === "pending" || certificate?.status === "issuing";
  const promptCertificate = !failed && !secure && !issuing;

  const RANK = { primary: 0, alias: 1, redirect: 2 };
  const ordered = [...domains].sort((a, b) => (RANK[a.type] ?? 3) - (RANK[b.type] ?? 3));
  const rows = ordered.length
    ? ordered.slice(0, SHOWN)
    : [{ id: "self", domain: application.domain, type: "primary", type_title: null }];
  const extra = Math.max(0, ordered.length - SHOWN);

  return (
    <Card>
      {issuing ? <AutoRefresh intervalMs={5000} stopAfterMs={300000} /> : null}
      <CardHeader className="items-center border-b">
        <CardTitle icon={Globe} as="h2" className="flex flex-wrap items-center gap-2">
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
