"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { CircleCheck, Database, TriangleAlert } from "lucide-react";
import { applicationById } from "@/lib/backups/database-availability";
import { Card } from "@/components/ui/card";
import { CardIcon } from "@/components/ui/card-icon";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { AttachApplicationDialog } from "@/components/databases/attach-application-dialog";
import { FACT_LABEL } from "@/lib/theme/facts";

export function DatabaseDetailsCard({
  database,
  engineName,
  engineVersion = null,
  engineDown = false,
  lastExport,
  canManage,
  applications = [],
  databaseCounts = null,
  databasesKnown = false,
  siteTypes = [],
}) {
  const t = useTranslations("databases");
  const tu = useTranslations("databases.usedBy");

  const [open, setOpen] = useState(false);

  const application = applicationById(applications, database.application_id);
  const attachedButUnknown = database.application_id !== null
    && database.application_id !== undefined
    && application === null;

  const cells = [
    {
      key: "engine",
      label: t("columns.engine"),
      value: (
        <>
          {engineName}
          {engineVersion ? (
            <span className="ml-1.5 font-mono text-xs font-normal text-muted-foreground">{engineVersion}</span>
          ) : null}
        </>
      ),
    },
    { key: "users", label: t("columns.users"), value: database.users?.length ?? 0 },
    {
      key: "export",
      label: t("columns.lastExport"),
      value: lastExport === undefined ? "—" : (lastExport ?? t("columns.neverExported")),
    },
    {
      key: "usedBy",
      label: tu("title"),
      action: (
        <ReasonTooltip reason={canManage ? null : DENIED}>
          <button
            type="button"
            disabled={!canManage}
            onClick={() => setOpen(true)}
            className="text-xs font-medium text-primary underline-offset-4 hover:underline disabled:opacity-50"
          >
            {application || attachedButUnknown ? tu("change") : tu("attach")}
          </button>
        </ReasonTooltip>
      ),
      value: application ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <Link
              href={`/applications/${application.id}`}
              prefetch={false}
              className="block truncate underline-offset-4 hover:underline"
            >
              {application.name}
            </Link>
          </TooltipTrigger>
          <TooltipContent side="bottom">{application.name}</TooltipContent>
        </Tooltip>
      ) : attachedButUnknown ? (
        <span className="text-muted-foreground">{tu("unknownSite")}</span>
      ) : (
        <span className="text-muted-foreground">{t("columns.notLinked")}</span>
      ),
    },
  ];

  return (
    <>
      <Card className="@container/details gap-0 overflow-hidden py-0">
        <div className="flex items-center gap-3 border-b px-5 py-3.5">
          <CardIcon icon={Database} />
          <h2 className="text-[15px] font-semibold tracking-tight">{t("detailsTitle")}</h2>
        </div>

        <dl className="grid gap-px bg-border @md/details:grid-cols-2 @3xl/details:grid-cols-4">
          {cells.map((cell) => (
            <div key={cell.key} className="min-w-0 space-y-1 bg-card px-5 py-3.5">
              <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-0.5">
                <dt className={FACT_LABEL}>{cell.label}</dt>
                {cell.action ?? null}
              </div>
              <dd className="min-w-0 text-sm font-semibold">{cell.value}</dd>
            </div>
          ))}
        </dl>

        <p className="flex items-start gap-2 border-t px-5 py-3 text-xs text-muted-foreground">
          {application ? (
            <CircleCheck className="mt-px size-3.5 shrink-0 text-success" aria-hidden />
          ) : (
            <TriangleAlert className="mt-px size-3.5 shrink-0 text-warning" aria-hidden />
          )}
          {application ? tu("included") : attachedButUnknown ? tu("unknownSite") : tu("noneWarning")}
        </p>
      </Card>

      {canManage ? (
        <AttachApplicationDialog
          database={database}
          open={open}
          onOpenChange={setOpen}
          applications={applications}
          databaseCounts={databaseCounts}
          databasesKnown={databasesKnown}
          siteTypes={siteTypes}
        />
      ) : null}
    </>
  );
}
