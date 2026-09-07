import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { Database, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * This server has no database engine, said before the form is filled in.
 *
 * A WordPress install here gets through every field, provisions, and fails —
 * and the failure names a driver rather than the thing to go and do. The whole
 * cost of that is paid after the work, which is the wrong order.
 *
 * It does not block. Plenty of site types need no database at all — a static
 * site, a Node app with its own storage — and the panel cannot tell which,
 * because nothing in the API says which types need one. So this states the fact
 * and offers the door, and leaves the judgement to the person who knows what
 * they are building. Amber, not red, for the same reason: nothing is broken.
 *
 * Silent whenever an engine is installed, which on a normal server is always.
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

      {/* Nothing to press while one is already on its way — the databases page
          is where that progress is, and sending someone to press Install a
          second time is how you end up with two attempts. */}
      {installing ? null : (
        <Button size="sm" variant="outline" asChild className="shrink-0">
          <Link href="/databases">{t("action")}</Link>
        </Button>
      )}
    </div>
  );
}
