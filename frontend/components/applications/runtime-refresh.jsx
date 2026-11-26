import { useEffect, useRef } from "react";
import { RefreshCw } from "lucide-react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { useRefresh } from "@/hooks/use-refresh";

export function RuntimeRefresh({ runtime, versions }) {
  const t = useTranslations("applications");
  const { pending, refresh } = useRefresh();
  const before = useRef(null);

  useEffect(() => {
    if (pending || before.current === null) return;

    const had = before.current;
    before.current = null;
    const added = versions
      .map((version) => version?.version)
      .filter((version) => version && !had.includes(version));

    if (added.length) {
      toast.success(
        t("form.versionsAdded", { runtime, versions: added.join(", "), count: added.length }),
      );
      return;
    }

    toast.info(t("form.versionsUnchanged", { runtime }));
  }, [pending, versions, runtime, t]);

  return (
    <button
      type="button"
      onClick={() => {
        before.current = versions.map((version) => version?.version).filter(Boolean);
        refresh();
      }}
      disabled={pending}
      className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline disabled:opacity-60 disabled:hover:no-underline"
      aria-label={t("form.refreshVersionsHint", { runtime })}
    >
      <RefreshCw className={cn("size-3", pending && "animate-spin")} />
      {t("form.refreshVersions")}
    </button>
  );
}
