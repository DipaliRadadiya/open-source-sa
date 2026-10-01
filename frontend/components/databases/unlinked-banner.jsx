import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { DatabaseZap } from "lucide-react";
import { Button } from "@/components/ui/button";

// Databases in no site, and so in no backup. Filters rather than attaches:
// only the reader knows which site each belongs to.
export async function UnlinkedBanner({ count = 0, filtered = false }) {
  const t = await getTranslations("databases.unlinked");

  if (count < 1 || filtered) return null;

  return (
    <div data-slot="notice" className="flex flex-col gap-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      <div className="flex items-start gap-2.5">
        <DatabaseZap className="mt-0.5 size-4 shrink-0 text-warning" />
        <div className="space-y-0.5">
          <p className="text-sm font-medium">{t("title", { count })}</p>
          {/* States the consequence, not just "not linked". */}
          <p className="text-xs text-muted-foreground">{t("description")}</p>
        </div>
      </div>

      <Button asChild variant="outline" size="sm" className="shrink-0">
        <Link href="/databases?attached=0" prefetch={false}>
          {t("action")}
        </Link>
      </Button>
    </div>
  );
}
