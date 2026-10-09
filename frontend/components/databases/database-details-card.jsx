"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { CircleCheck, Database, TriangleAlert } from "lucide-react";
import { applicationById } from "@/lib/backups/database-availability";
import { Card } from "@/components/ui/card";
import { CardIcon } from "@/components/ui/card-icon";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { AttachApplicationDialog } from "@/components/databases/attach-application-dialog";

// The facts in one grid, with "Used by" among them rather than a card of its own,
// and what that link means for backups underneath.
export function DatabaseDetailsCard({
  database,
  engineName,
  engineVersion = null,
  // The API reports 0 B while the engine is down, which would read as empty.
  engineDown = false,
  // `undefined` when the exports could not be read: nothing is claimed then.
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
  // Attached to a site this user cannot see (or just deleted); "not linked" would be false.
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
    { key: "size", label: t("detail.size"), value: engineDown ? "—" : (database.size_human ?? "—") },
    { key: "users", label: t("columns.users"), value: database.users?.length ?? 0 },
    {
      key: "export",
      label: t("columns.lastExport"),
      value: lastExport === undefined ? "—" : (lastExport ?? t("columns.neverExported")),
    },
    { key: "created", label: t("detail.created"), value: database.created_at_human ?? "—" },
    {
      key: "usedBy",
      label: tu("title"),
      action: (
        <ReasonTooltip reason={canManage ? null : tu("noPermission")}>
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
      // One line with an ellipsis, the full name in a tooltip: wrapped, it made its box
      // taller than the other five (Krishna, 8 Oct). On a phone the box is full width.
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
          {/* Below: above, it covered "Used by" and Change. */}
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

        {/* All six on one row when the card is wide enough (Krishna, 8 Oct); three, then
            two, then one as it narrows. Measured on the card, not the window. */}
        <dl className="grid gap-px bg-border @md/details:grid-cols-2 @2xl/details:grid-cols-3 @4xl/details:grid-cols-6">
          {cells.map((cell) => (
            <div key={cell.key} className="min-w-0 space-y-1 bg-card px-5 py-3.5">
              {/* Wraps: in a sixth of the card, "Change" was cut off in Russian. */}
              <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-0.5">
                <dt className="text-xs text-muted-foreground">{cell.label}</dt>
                {cell.action ?? null}
              </div>
              <dd className="min-w-0 text-sm font-semibold">{cell.value}</dd>
            </div>
          ))}
        </dl>

        {/* What the link means: site backups only dump the databases attached to them. */}
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
