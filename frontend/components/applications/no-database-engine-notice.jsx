import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { Database, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * Warns before the form is filled in that the server has no database engine;
 * otherwise e.g. WordPress provisions and then fails on a missing driver.
 *
 * Does not block: the API does not say which site types need a database.
 * Amber, since nothing is broken. Renders nothing when an engine is installed.
 */
export async function NoDatabaseEngineNotice({ installing = false }) {
  const t = await getTranslations("applications.noDatabase");

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-warning/30 bg-warning/5 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex min-w-48 items-start gap-2.5">
        <span className="mt-0.5 shrink-0 text-warning">
          {installing ? (
            <Loader2 className="size-4 animate-spin" aria-hidden />
          ) : (
            <Database className="size-4" aria-hidden />
          )}
        </span>
        <div className="space-y-0.5">
          <p className="text-sm font-medium">
            {installing ? t("installingTitle") : t("title")}
          </p>
          <p className="text-xs leading-5 text-muted-foreground">
            {installing ? t("installingBody") : t("body")}
          </p>
        </div>
      </div>

      {/* No action while an install is in progress, to avoid a second attempt. */}
      {installing ? null : (
        <Button size="sm" variant="outline" asChild className="shrink-0">
          <Link href="/databases">{t("action")}</Link>
        </Button>
      )}
    </div>
  );
}
