import { useEffect, useRef } from "react";
import { RefreshCw } from "lucide-react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { useRefresh } from "@/hooks/use-refresh";

/**
 * Re-read the server's runtime versions without losing the form's values.
 *
 * `useRefresh` re-runs the page's server component rather than fetching here,
 * keeping one source for the versions and also re-running `withAvailability`,
 * so site type cards update in the same press.
 *
 * @param runtime  "PHP" | "Node.js" — a technical token, not translated.
 * @param versions the full installed list for this runtime, as rendered.
 */
export function RuntimeRefresh({ runtime, versions }) {
  const t = useTranslations("applications");
  const { pending, refresh } = useRefresh();
  // Installed versions when this button was pressed; null otherwise. The pending
  // signal is shared with the page, so this avoids toasting for other refreshes.
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

    // Explain an unchanged list: a version still installing is not offered yet.
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
      // Styled like the "Generate" action: a link-like control in a label row.
      className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline disabled:opacity-60 disabled:hover:no-underline"
      // "Refresh" alone is ambiguous when the form has one per runtime.
      aria-label={t("form.refreshVersionsHint", { runtime })}
    >
      <RefreshCw className={cn("size-3", pending && "animate-spin")} />
      {t("form.refreshVersions")}
    </button>
  );
}
