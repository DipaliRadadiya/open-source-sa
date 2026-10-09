import { runtimeLabel, runtimeOf } from "@/lib/applications/runtime-of";
import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { ChevronRight } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { Badge } from "@/components/ui/badge";
import { CardList, CardListItem } from "@/components/data-table/card-list";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { ApplicationStatusBadge, ApplicationStatusNotes } from "@/components/applications/application-status-badge";
import { DomainText } from "@/components/ui/domain-text";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { TlsMark, isServedOverTls } from "@/components/applications/tls-mark";
import { gitProviderFor } from "@/lib/applications/git-provider";

// Narrow-screen sites list; the badge shares the facts line so the name fits at 320px.
export function ApplicationsCards({
  applications = [],
  canManage = false,
  canMagicLogin = false,
  gitProviders = new Map(),
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
              className="mt-0.5"
            />
            <div className="min-w-0 flex-1">
              <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                <Link
                  href={`/applications/${application.id}`}
                  prefetch={false}
                  className="inline-flex min-w-0 items-center gap-1 font-medium text-primary underline-offset-4 hover:underline"
                >
                  <span className="truncate" title={application.name}>{application.name}</span>
                  <ChevronRight className="size-3.5 shrink-0" />
                </Link>
                {/* A copy and its source have near-identical names. */}
                {application.is_staging ? (
                  <Badge variant="warning" className="shrink-0 font-normal">
                    {t("stagingBadge")}
                  </Badge>
                ) : null}
              </div>
              {/* The type is printed as text below, so the logo stays unlabelled; see
                  SiteTypeLogo. */}
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
            {/* shrink-0 keeps the menu in place however long the name is. */}
            <div className="-me-2 -mt-1 shrink-0">
              <ApplicationRowActions application={application} canManage={canManage} canMagicLogin={canMagicLogin} />
            </div>
          </div>

          {/* Gaps, not middots: a separator stranded at a wrap reads as a missing value. */}
          <p className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t pt-3 text-xs text-muted-foreground">
            <ApplicationStatusBadge application={application} />
            <span className="truncate text-foreground">
              {application.site_type_title ?? application.site_type}
            </span>
            {/* Omitted, not dashed, when the API has no version: in a wrapped list a dash
                reads as a value. Node and static sites have none. */}
            {/* What it runs on, as in the table's Runs on column. Only PHP and Node: for
                static and Docker the type beside it already says it ("Static site · Static files"). */}
            {["php", "node"].includes(runtimeOf(application)?.kind) ? (
              <span className="whitespace-nowrap tabular-nums">
                {runtimeLabel(runtimeOf(application), t, tDocker)}
              </span>
            ) : null}
            <span className="truncate font-mono">{application.system_user?.username ?? "—"}</span>
            {/* Same as the table's Size column; an unmeasured site is left out rather than
                shown as a dash that reads like zero bytes. */}
            {formatBytes(application.directory_size_bytes, format) ? (
              <span className="whitespace-nowrap tabular-nums">
                {formatBytes(application.directory_size_bytes, format)}
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
