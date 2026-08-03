"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DeleteDatabaseDialog } from "@/components/databases/delete-database-dialog";

/**
 * Deleting the database you are looking at.
 *
 * It was only possible from the list, which meant opening a database to check
 * what was in it and then navigating away to get rid of it. Its own card at the
 * bottom rather than a button in the header: destructive actions live at the
 * end of a page, past everything that might change your mind.
 */
export function DeleteDatabaseCard({ database, canManage }) {
  const t = useTranslations("databases.delete");
  const [open, setOpen] = useState(false);

  return (
    <>
      <Card className="flex-row flex-wrap items-center justify-between gap-4 border-destructive/30 px-5 py-4">
        <div className="space-y-1">
          <p className="text-sm font-medium text-destructive">{t("cardTitle")}</p>
          <p className="text-sm text-muted-foreground">{t("cardDescription")}</p>
        </div>

        <ReasonTooltip reason={canManage ? null : t("noPermission")}>
          <Button
            variant="outline"
            disabled={!canManage}
            className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
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
          open={open}
          onOpenChange={(next) => !next && setOpen(false)}
          // Nothing to come back to once it's gone.
          redirectTo="/databases"
        />
      ) : null}
    </>
  );
}
