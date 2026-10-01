"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { HardDrive, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { SetupBackupsDialog } from "@/components/backups/setup-backups-dialog";

/**
 * Nothing on this server is backed up yet. Same structure as the Git and
 * Storage empty states: icon chip, title, reassurance, chips, three steps, one action.
 */
export function BackupsEmptyState({
  applications,
  destinations,
  canManage,
  databaseCounts = null,
  databasesKnown = false,
  siteTypes = null,
  backupOptions = null,
}) {
  const t = useTranslations("backups.empty");
  const [open, setOpen] = useState(false);
  const hasDestination = destinations.length > 0;

  return (
    <>
      <Card className="shadow-sm">
        <CardContent>
          <div className="mx-auto flex max-w-lg flex-col items-center gap-5 py-10 text-center sm:py-12">
            <span className="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15">
              <ShieldCheck className="size-6" aria-hidden />
            </span>

            <div className="space-y-2">
              <p className="text-base font-semibold tracking-tight">{t("title")}</p>
              <p className="max-w-md text-sm leading-6 text-muted-foreground">{t("body")}</p>
              <p className="max-w-md text-xs leading-5 text-muted-foreground">
                {t("reassurance")}
              </p>
            </div>

            {/* What a backup contains, like the provider chips on the other screens. */}
            <div className="flex flex-wrap justify-center gap-2">
              {["files", "database", "schedule", "offsite"].map((item) => (
                <span
                  key={item}
                  className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2.5 py-1.5 text-xs font-medium text-muted-foreground"
                >
                  {t(`covers.${item}`)}
                </span>
              ))}
            </div>

            <div className="w-full rounded-xl border bg-muted/30 p-4 text-left sm:p-5">
              <p className="text-sm font-medium">{t("stepsTitle")}</p>
              <ol className="mt-3 space-y-3 text-sm text-muted-foreground">
                {[t("step1"), t("step2"), t("step3")].map((step, index) => (
                  <li key={step} className="flex items-start gap-3">
                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-background text-xs font-medium text-foreground ring-1 ring-border">
                      {index + 1}
                    </span>
                    <span className="leading-5">{step}</span>
                  </li>
                ))}
              </ol>
            </div>

            {/* Storage is the prerequisite: with no destination, send them there. */}
            {!canManage ? null : hasDestination ? (
              <Button size="lg" onClick={() => setOpen(true)}>
                <ShieldCheck className="size-4" />
                {t("action")}
              </Button>
            ) : (
              <Button size="lg" asChild>
                <Link href="/integrations/storage">
                  <HardDrive className="size-4" />
                  {t("addStorage")}
                </Link>
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      <SetupBackupsDialog
        open={open}
        onOpenChange={setOpen}
        applications={applications}
        databaseCounts={databaseCounts}
        databasesKnown={databasesKnown}
        siteTypes={siteTypes}
        destinations={destinations}
        options={backupOptions}
      />
    </>
  );
}
