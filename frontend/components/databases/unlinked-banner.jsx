import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { DatabaseZap } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * Databases that belong to no site, and so are in no backup.
 *
 * The discovery step for the whole feature. Attaching only helps someone who
 * already suspects a database is unlinked — and nothing else on this screen
 * would tell them, because an unlinked database looks exactly like a linked one
 * until you read the column.
 *
 * Modelled on the adopt banner it sits beside: same amber, same shape, same
 * "here is a thing and here is the way to deal with it". Its action filters the
 * list rather than fixing anything, because which site each one belongs to is a
 * decision only the reader can make.
 *
 * Hidden at zero, and hidden while the list is ALREADY filtered to unlinked —
 * announcing "3 are not linked" above a list of exactly those three is telling
 * someone what they are looking at.
 */
export async function UnlinkedBanner({ count = 0, filtered = false }) {
  const t = await getTranslations("databases.unlinked");

  if (count < 1 || filtered) return null;

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      <div className="flex items-start gap-2.5">
        <DatabaseZap className="mt-0.5 size-4 shrink-0 text-warning" />
        <div className="space-y-0.5">
          <p className="text-sm font-medium">{t("title", { count })}</p>
          {/* The consequence, not the state: "not linked" means nothing to
              someone who does not already know what the link is for. */}
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
