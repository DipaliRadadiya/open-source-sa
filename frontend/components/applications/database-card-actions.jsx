"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ArrowRight, Link2, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { AttachDatabaseDialog } from "@/components/applications/attach-database-dialog";
import { CreateDatabaseDialog } from "@/components/databases/create-database-dialog";

/**
 * The Database card's action, by state:
 *   has one          → open that database
 *   none, spares     → attach one here, in a dialog
 *   none, no spares  → create one for this site, in a dialog
 */
export function DatabaseCardActions({
  application,
  databases = [],
  unattached = [],
  engines = [],
  warn = false,
}) {
  const t = useTranslations("applications.databaseCard");
  const [attaching, setAttaching] = useState(false);
  const [creating, setCreating] = useState(false);

  const first = databases[0] ?? null;

  if (first) {
    return (
      <Button asChild variant="outline" size="sm">
        <Link href={`/databases/${first.id}`} prefetch={false}>
          {t("manage")}
          <ArrowRight className="size-3.5" />
        </Link>
      </Button>
    );
  }

  // Nothing to attach, so offer creating one instead of an empty picker.
  if (unattached.length === 0) {
    return (
      <>
        <Button variant={warn ? "default" : "outline"} size="sm" onClick={() => setCreating(true)}>
          <Plus className="size-3.5" />
          {t("create")}
        </Button>
        <CreateDatabaseDialog
          engines={engines}
          open={creating}
          onOpenChange={setCreating}
          applicationId={application.id}
        />
      </>
    );
  }

  return (
    <>
      <Button variant={warn ? "default" : "outline"} size="sm" onClick={() => setAttaching(true)}>
        <Link2 className="size-3.5" />
        {t("attach")}
      </Button>
      <AttachDatabaseDialog
        applicationId={application.id}
        applicationName={application.name}
        databases={unattached}
        open={attaching}
        onOpenChange={setAttaching}
      />
    </>
  );
}
