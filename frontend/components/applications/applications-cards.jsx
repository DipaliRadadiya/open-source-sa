import { runtimeLabel, runtimeOf } from "@/lib/applications/runtime-of";
import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { ChevronRight } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { ApplicationOriginBadge } from "@/components/applications/application-origin-badge";
import { Badge } from "@/components/ui/badge";
import { CardList, CardListItem } from "@/components/data-table/card-list";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { ApplicationStatusBadge, ApplicationStatusNotes } from "@/components/applications/application-status-badge";
import { DomainText } from "@/components/ui/domain-text";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { TlsMark, isServedOverTls } from "@/components/applications/tls-mark";
import { gitProviderFor } from "@/lib/applications/git-provider";
import { applicationIconDataUri } from "@/lib/applications/application-icon";
import { HoverHint } from "@/components/ui/hover-hint";
import { shownSizeBytes } from "@/lib/applications/size-measurement";

export function ApplicationsCards({
  applications = [],
  canManage = false,
  canOpen = true,
  canMagicLogin = false,
  canRemoveSystemUser = false,
  canManageDocker = false,
  gitProviders = new Map(),
  siteTypes = [],
}) {
  const t = useTranslations("applications");
  const tDocker = useTranslations("docker");
  const format = useFormatter();

  return (
    <CardList>
      {applications.map((application) => (
        <CardListItem key={application.id}>
          <div className="flex items-start justify-between gap-2">
            <SiteTypeLogo
              name={application.site_type}
              provider={gitProviderFor(application, gitProviders)}
              iconDataUri={applicationIconDataUri(application, siteTypes)}
              className="mt-0.5"
            />
            <div className="min-w-0 flex-1">
              <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                {canOpen ? (
                  <Link
                    href={`/applications/${application.id}`}
                    prefetch={false}
                    className="inline-flex min-w-0 items-center gap-1 font-medium text-foreground underline-offset-4 hover:text-primary hover:underline"
                  >
                    <HoverHint content={application.name}><span className="truncate">{application.name}</span></HoverHint>
                    <ChevronRight className="size-3.5 shrink-0" />
                  </Link>
                ) : (
                  <HoverHint content={application.name}><span className="min-w-0 truncate font-medium text-foreground">{application.name}</span></HoverHint>
                )}
                {application.is_staging ? (
                  <Badge variant="warning" className="shrink-0 font-normal">
                    {t("stagingBadge")}
                  </Badge>
                ) : null}
              </div>
              <div className="flex min-w-0 items-center gap-1">
                <TlsMark
                  application={application}
                  label={isServedOverTls(application) ? t("domains.secured") : t("domains.noCertificate")}
                />
                <DomainText
                  domain={application.domain}
                  className="font-mono text-xs text-muted-foreground"
                />
              </div>
            </div>
            <div className="-me-2 -mt-1 shrink-0">
              <ApplicationRowActions application={application} canManage={canManage} canOpen={canOpen} canMagicLogin={canMagicLogin} canRemoveSystemUser={canRemoveSystemUser} canManageDocker={canManageDocker} />
            </div>
          </div>

          <p className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t pt-3 text-xs text-muted-foreground">
            <ApplicationStatusBadge application={application} />
            <ApplicationOriginBadge origin={application.origin} />
            <span className="truncate text-foreground">
              {application.site_type_title ?? application.site_type}
            </span>
            {["php", "node"].includes(runtimeOf(application)?.kind) ? (
              <span className="whitespace-nowrap tabular-nums">
                {runtimeLabel(runtimeOf(application), t, tDocker)}
              </span>
            ) : null}
            <span className="truncate font-mono">{application.system_user?.username ?? "—"}</span>
            {formatBytes(shownSizeBytes(application), format) ? (
              <span className="whitespace-nowrap tabular-nums">
                {formatBytes(shownSizeBytes(application), format)}
              </span>
            ) : null}
            <span className="whitespace-nowrap">{application.created_at_human ?? "—"}</span>
          </p>

          <ApplicationStatusNotes application={application} className="-mt-1" />
        </CardListItem>
      ))}
    </CardList>
  );
}
