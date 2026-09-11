import { useEffect, useRef } from "react";
import { RefreshCw } from "lucide-react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { useRefresh } from "@/hooks/use-refresh";

/**
 * Re-read the runtime versions the server has, without losing the form.
 *
 * Installing a PHP version happens on another screen. Before this, the only way
 * to see it in the create form was to reload — which threw away everything
 * already typed, so the real choice was "fill the form again" or "pick a
 * version you did not want".
 *
 * `useRefresh` re-runs the page's server component rather than fetching the
 * list itself. That keeps ONE source for the versions — the same fetch the
 * form was rendered from — and it also re-runs `withRuntimeAvailability`, so a
 * site type greyed out for having no usable PHP becomes selectable in the same
 * press. A client-side fetch into local state would have updated the select and
 * left the type cards stale, which is the half-refresh that looks like a bug.
 *
 * Verified in a real build rather than taken from the docs, because "without
 * losing what you entered" is the whole point: a filled form keeps every value,
 * including the version already chosen, across the refresh.
 *
 * @param runtime  "PHP" | "Node.js" — a technical token, not translated.
 * @param versions the full installed list for this runtime, as rendered.
 */
export function RuntimeRefresh({ runtime, versions }) {
  const t = useTranslations("applications");
  const { pending, refresh } = useRefresh();
  // What was installed at the moment of the press. Null when no press of ours
  // is outstanding — the pending signal is shared with the rest of the page, so
  // without this a refresh started somewhere else would toast about versions.
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

    // Not silence. A version that is still installing is not in this list yet —
    // the form only offers ready ones — so the commonest reason for pressing
    // this button is also the one where nothing appears to happen.
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
      // Same shape and weight as the "Generate" action on password fields: this
      // row is a label, and a second button-looking control in it would compete
      // with the field underneath.
      className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline disabled:opacity-60 disabled:hover:no-underline"
      // "Refresh" alone is ambiguous when the form has one per runtime.
      aria-label={t("form.refreshVersionsHint", { runtime })}
    >
      <RefreshCw className={cn("size-3", pending && "animate-spin")} />
      {t("form.refreshVersions")}
    </button>
  );
}
