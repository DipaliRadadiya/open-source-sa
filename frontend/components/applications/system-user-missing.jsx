"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { FailurePanel } from "@/components/ui/failure-panel";
import { PageHeader } from "@/components/ui/page-header";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DeleteApplicationDialog } from "@/components/applications/delete-application-dialog";

/**
 * Every screen of an application whose system user no longer exists. The API
 * answers 409 on every `/applications/{id}/...` route except opening and
 * deleting, so one panel says it once and offers delete.
 */
export function SystemUserMissing({ application, canDelete }) {
  const t = useTranslations("applications.systemUserMissing");
  const ta = useTranslations("applications");
  const [deleteOpen, setDeleteOpen] = useState(false);

  return (
    <div className="space-y-6">
      <PageHeader title={application.name} />
      <FailurePanel
        title={t("title")}
        description={t("description", { name: application.name })}
        action={
          <ReasonTooltip reason={canDelete ? null : ta("noPermission")}>
            <Button variant="destructive" onClick={() => setDeleteOpen(true)} disabled={!canDelete}>
              <Trash2 className="size-4" />
              {ta("actions.delete")}
            </Button>
          </ReasonTooltip>
        }
      />
      <DeleteApplicationDialog
        application={application}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        redirectTo="/applications"
      />
    </div>
  );
}
