"use client";

import { useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Link2 } from "lucide-react";
import { applicationById } from "@/lib/backups/database-availability";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { AttachApplicationDialog } from "@/components/databases/attach-application-dialog";

/**
 * Which site this database belongs to, and the button that changes it.
 *
 * Above the tabs rather than inside them: Users, Tables and Exports are all
 * things the database CONTAINS, and this is a fact ABOUT it.
 *
 * The unattached state is a warning rather than a neutral blank, because it has
 * a consequence nothing else on this page would reveal — backups of a site dump
 * exactly the databases attached to it, so this one is in none of them.
 */
export function UsedByCard({
  database,
  canManage,
  applications = [],
  databaseCounts = null,
  databasesKnown = false,
  siteTypes = [],
}) {
  const t = useTranslations("databases.usedBy");
  const [open, setOpen] = useState(false);

  const application = applicationById(applications, database.application_id);
  // Attached to a site this user cannot see, or one that vanished between the
  // two requests. Saying "not linked" would be a lie that invites an attach.
  const attachedButUnknown = database.application_id !== null
    && database.application_id !== undefined
    && application === null;

  return (
    <>
      <Card className="flex-row flex-wrap items-center justify-between gap-4 px-5 py-4">
        <div className="min-w-48 flex-1 space-y-1">
          <p className="text-sm font-medium">{t("title")}</p>

          {application ? (
            <p className="text-sm text-muted-foreground">
              <Link
                href={`/applications/${application.id}`}
                prefetch={false}
                className="font-medium text-foreground underline-offset-4 hover:underline"
              >
                {application.name}
              </Link>
              {" · "}
              {t("included")}
            </p>
          ) : attachedButUnknown ? (
            <p className="text-sm text-muted-foreground">{t("unknownSite")}</p>
          ) : (
            <p className="text-sm text-warning">{t("noneWarning")}</p>
          )}
        </div>

        <ReasonTooltip reason={canManage ? null : t("noPermission")}>
          <Button variant="outline" disabled={!canManage} onClick={() => setOpen(true)}>
            <Link2 className="size-4" />
            {application || attachedButUnknown ? t("change") : t("attach")}
          </Button>
        </ReasonTooltip>
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
