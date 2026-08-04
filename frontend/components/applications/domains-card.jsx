import Link from "next/link";
import { useTranslations } from "next-intl";
import { ArrowRight, Globe2, ShieldCheck, ShieldOff } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * The two questions this card answers are "what names does it answer to" and
 * "is it encrypted". Everything else about a domain belongs on the Domains
 * screen.
 *
 * No certificate is a normal state, not an error — it says so plainly instead
 * of showing a red failure for a site that simply serves plain HTTP.
 */
export function DomainsCard({ application, domains = [], certificate = null, failed = false, href = null }) {
  const t = useTranslations("applications.domains");

  const primary = domains.find((domain) => domain.type === "primary");
  const aliases = domains.filter((domain) => domain.type === "alias").length;
  const redirects = domains.filter((domain) => domain.type === "redirect").length;
  const secure = certificate?.status === "active";

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
        <CardTitle>{t("title")}</CardTitle>
        {failed ? null : secure ? (
          <Badge
            variant={certificate.expiring_soon ? "warning" : "success"}
            className="gap-1.5 font-normal"
          >
            <ShieldCheck className="size-3" />
            {t("secured")}
          </Badge>
        ) : (
          <Badge variant="secondary" className="gap-1.5 font-normal">
            <ShieldOff className="size-3" />
            {t("noCertificate")}
          </Badge>
        )}
      </CardHeader>
      <CardContent className="space-y-4 text-sm">
        {failed ? (
          <p className="text-muted-foreground">{t("loadFailed")}</p>
        ) : (
          <>
            <div className="flex items-center gap-2">
              <Globe2 className="size-4 shrink-0 text-muted-foreground" />
              <span className="truncate font-mono text-xs">
                {primary?.domain ?? application.domain}
              </span>
            </div>

            <p className="text-xs text-muted-foreground">
              {t("counts", { aliases, redirects })}
            </p>

            {secure && certificate.expires_at_human ? (
              <p className="text-xs text-muted-foreground">
                {t("expires", { when: certificate.expires_at_human })}
              </p>
            ) : null}
          </>
        )}

        {href ? (
          <Button asChild variant="outline" size="sm">
            <Link href={href}>
              {t("manage")}
              <ArrowRight className="size-3.5" />
            </Link>
          </Button>
        ) : null}
      </CardContent>
    </Card>
  );
}
