"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Trash2, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { CardIcon } from "@/components/ui/card-icon";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DeleteDatabaseDialog } from "@/components/databases/delete-database-dialog";

// Its own card at the bottom: destructive actions live at the end of a page.
export function DeleteDatabaseCard({ database, application = null, canManage }) {
  const t = useTranslations("databases.delete");
  const [open, setOpen] = useState(false);

  return (
    <>
      <Card className="flex-row flex-wrap items-center justify-between gap-4 border-destructive/30 px-5 py-4">
        <div className="flex min-w-48 flex-1 items-center gap-3">
          <CardIcon icon={TriangleAlert} tone="destructive" />
          <div className="space-y-0.5">
            <h2 className="text-[15px] font-semibold tracking-tight text-destructive">{t("cardTitle")}</h2>
            <p className="text-sm text-muted-foreground">{t("cardDescription")}</p>
          </div>
        </div>

        <ReasonTooltip reason={canManage ? null : t("noPermission")}>
          <Button
            variant="destructive"
            disabled={!canManage}
            onClick={() => setOpen(true)}
          >
            <Trash2 className="size-4" />
            {t("action")}
          </Button>
        </ReasonTooltip>
      </Card>

      {canManage ? (
        <DeleteDatabaseDialog
          database={database}
          application={application}
          open={open}
          onOpenChange={(next) => !next && setOpen(false)}
          redirectTo="/databases"
        />
      ) : null}
    </>
  );
}
