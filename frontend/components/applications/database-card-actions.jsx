"use client";

import { useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { ArrowRight, Link2, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { AttachDatabaseDialog } from "@/components/applications/attach-database-dialog";
import { CreateDatabaseDialog } from "@/components/databases/create-database-dialog";

/**
 * What the Database card lets you actually DO, from the site's own page.
 *
 * Every one of these used to be a link to `/databases` — a list of every
 * database on the server, from which the reader had to find their way back to
 * the thing they were already looking at. Three different states all pointing
 * at one page that answered none of them.
 *
 * Now each state does its own job where it stands:
 *   has one          → open THAT database
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

  // Attached already: the useful destination is that database, not the list.
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

  // Nothing to attach: offering "Attach" would open a picker with no options,
  // which is a dead end wearing a button. Creating one is the real next step.
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
