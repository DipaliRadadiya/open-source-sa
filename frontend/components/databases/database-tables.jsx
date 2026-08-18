"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations, useFormatter } from "next-intl";
import { Loader2, Table2, TriangleAlert, Wrench } from "lucide-react";
import { optimizeDatabase, repairDatabase } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { formatBytes } from "@/lib/format/bytes";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

/**
 * What is inside the database, and the two things you can do to it.
 *
 * Deliberately not a data browser — the API returns names and sizes only, and
 * browsing rows is phpMyAdmin's job. This answers "why is this database 2 GB",
 * which is the question a size figure on the list page raises and cannot
 * answer.
 */
export function DatabaseTables({ database, tables = [], canManage }) {
  const t = useTranslations("databases.tables");
  const router = useRouter();
  const format = useFormatter();
  const [pending, setPending] = useState(null);
  const [running, setRunning] = useState(false);

  // OPTIMIZE and REPAIR are SQL statements; on Mongo the API accepts them and
  // does nothing, so offering them there would be theatre.
  const isMongo = database?.driver === "mongo";

  async function run() {
    setRunning(true);
    try {
      if (pending === "repair") await repairDatabase(database.id);
      else await optimizeDatabase(database.id);
      toast.success(t(`${pending}Done`));
      setPending(null);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t(`${pending}Failed`)));
    } finally {
      setRunning(false);
    }
  }

  const totalRows = tables.reduce((sum, table) => sum + (table.rows ?? 0), 0);
  const estimated = tables.length > 0 && !isMongo;

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
          <div className="flex items-center gap-2.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
              <Table2 className="size-3.5" />
            </span>
            <div>
              <h2 className="text-base font-semibold tracking-tight">
                {t(isMongo ? "titleMongo" : "title")}
              </h2>
              <p className="text-sm text-muted-foreground">
                {tables.length
                  ? t(isMongo ? "summaryMongo" : "summary", {
                      count: tables.length,
                      rows: format.number(totalRows),
                    })
                  : t("description")}
              </p>
            </div>
          </div>

          {/* Only where they do something. Both are safe — one reclaims space,
              the other rebuilds damaged tables — but repair locks tables while
              it runs, so it asks first. */}
          {!isMongo && tables.length > 0 ? (
            <div className="flex items-center gap-2">
              <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={!canManage || running}
                  onClick={() => setPending("optimize")}
                >
                  {t("optimize")}
                </Button>
              </ReasonTooltip>
              <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={!canManage || running}
                  onClick={() => setPending("repair")}
                >
                  <Wrench className="size-4" />
                  {t("repair")}
                </Button>
              </ReasonTooltip>
            </div>
          ) : null}
        </div>

        <CardContent className="px-5 py-0">
          {tables.length === 0 ? (
            <div className="py-8 text-center">
              <p className="text-sm text-muted-foreground">{t("empty")}</p>
            </div>
          ) : (
            /* Bounded, because the list is every table in the database and
               that is not a small number for the applications this panel
               installs — Nextcloud ships ~150, a mature WordPress more.
               Unbounded, the card grew to whatever the schema happened to be
               and pushed the charset footer, and everything below the card,
               off the screen. Roughly ten rows before it scrolls; short
               lists never show a scrollbar. */
            <div className="max-h-96 divide-y overflow-y-auto">
              {tables.map((table) => (
                <div
                  key={table.name}
                  className="flex items-center justify-between gap-4 py-2.5"
                >
                  <span className="truncate font-mono text-sm">{table.name}</span>
                  <span className="flex shrink-0 items-center gap-4 text-sm text-muted-foreground">
                    {/* Row counts on InnoDB are an estimate, and saying so
                        beats someone treating it as a total. */}
                    <span className="tabular-nums">
                      {t("rowCount", { rows: format.number(table.rows ?? 0) })}
                    </span>
                    <span className="w-16 text-right tabular-nums">
                      {formatBytes(table.size_bytes ?? 0, format)}
                    </span>
                  </span>
                </div>
              ))}
            </div>
          )}
        </CardContent>

        {/* Charset and collation belong to the storage, not to the page
            heading where they used to sit. Read once, if ever. */}
        {estimated || database?.collation ? (
          <div className="space-y-1 border-t bg-muted/30 px-5 py-3">
            {database?.collation ? (
              <p className="text-xs leading-relaxed text-muted-foreground">
                {t("charsetLine", {
                  charset: database.charset ?? "—",
                  collation: database.collation,
                })}
              </p>
            ) : null}
            {estimated ? (
              <p className="text-xs leading-relaxed text-muted-foreground">
                {t("rowsEstimated")}
              </p>
            ) : null}
          </div>
        ) : null}
      </Card>

      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(next) => !next && setPending(null)}
        icon={pending === "repair" ? TriangleAlert : Wrench}
        tone={pending === "repair" ? "warning" : "default"}
        title={t(pending === "repair" ? "repairTitle" : "optimizeTitle")}
        description={t(
          pending === "repair" ? "repairDescription" : "optimizeDescription",
        )}
        cancelLabel={t("cancel")}
        confirmLabel={
          running ? (
            <>
              <Loader2 className="size-4 animate-spin" />
              {t("working")}
            </>
          ) : (
            t(pending === "repair" ? "repair" : "optimize")
          )
        }
        pending={running}
        onConfirm={run}
      />
    </>
  );
}
