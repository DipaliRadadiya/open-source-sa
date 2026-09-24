import { useState } from "react";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { getEnvironmentDiff } from "@/lib/api/environment";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";

/**
 * The old and new value of every variable one change touched, loaded when the
 * row is opened rather than with the history list: it reads one or two backup
 * files off the server, and most rows are never expanded. The values come from
 * those files, not from the activity log — see `EnvironmentDiff` on the
 * backend for why that distinction matters.
 *
 * Fetched once per row and kept. Reopening a row should not re-read files off
 * the server, and the answer cannot change: it describes a past state.
 */
export function useEnvironmentDiff(appId, entry) {
  const t = useTranslations("applications.environment.history");
  const [open, setOpen] = useState(false);
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);

  async function toggle() {
    if (open) {
      setOpen(false);
      return;
    }
    setOpen(true);
    if (data || loading) return;

    setLoading(true);
    setError(null);
    try {
      const response = await getEnvironmentDiff(appId, entry.id);
      setData(response?.diff ?? { available: false, changes: [] });
    } catch (e) {
      setError(apiMessage(e, t("diffFailed")));
    } finally {
      setLoading(false);
    }
  }

  return { open, toggle, data, error, loading };
}

const STATUS_TONE = {
  added: "border-success/30 bg-success/10 text-success",
  removed: "border-destructive/30 bg-destructive/10 text-destructive",
  changed: "border-primary/30 bg-primary/10 text-primary",
};

export function EnvironmentDiff({ state }) {
  const t = useTranslations("applications.environment.history");
  const { data, error, loading } = state;

  return (
    <div className="overflow-hidden rounded-lg border">
      {loading ? (
        <p className="flex items-center gap-2 p-3 text-xs text-muted-foreground">
          <Loader2 className="size-3.5 animate-spin" />
          {t("diffLoading")}
        </p>
      ) : error ? (
        <p className="p-3 text-xs text-muted-foreground">{error}</p>
      ) : !data?.available ? (
        // Not the same as "nothing changed": the file holding the previous
        // version has been deleted, so this change cannot be described.
        <p className="p-3 text-xs text-muted-foreground">{t("diffUnavailable")}</p>
      ) : data.changes.length === 0 ? (
        <p className="p-3 text-xs text-muted-foreground">{t("noKeysNote")}</p>
      ) : (
        // A table where there is room for three columns, stacked rows where
        // there is not: squeezed, the names broke mid-word, and scrolled, the
        // After column — the answer — sat off-screen.
        <div className="@container">
          <table className="hidden w-full text-xs @md:table">
            <thead className="bg-muted/50 text-muted-foreground">
              <tr>
                <th className="w-1/4 px-3 py-2 text-start font-medium">{t("colKey")}</th>
                <th className="px-3 py-2 text-start font-medium">{t("colBefore")}</th>
                <th className="px-3 py-2 text-start font-medium">{t("colAfter")}</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {data.changes.map((change) => (
                <tr key={change.key}>
                  <td className="px-3 py-2 align-top">
                    <KeyName change={change} />
                  </td>
                  <td className="px-3 py-2 align-top">
                    <Value value={change.before} tone="removed" />
                  </td>
                  <td className="px-3 py-2 align-top">
                    <Value value={change.after} tone="added" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <ul className="divide-y text-xs @md:hidden">
            {data.changes.map((change) => (
              <li key={change.key} className="space-y-2 px-3 py-2.5">
                <KeyName change={change} />
                <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-1.5">
                  <dt className="pt-0.5 text-muted-foreground">{t("colBefore")}</dt>
                  <dd>
                    <Value value={change.before} tone="removed" />
                  </dd>
                  <dt className="pt-0.5 text-muted-foreground">{t("colAfter")}</dt>
                  <dd>
                    <Value value={change.after} tone="added" />
                  </dd>
                </dl>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

function KeyName({ change }) {
  const t = useTranslations("applications.environment.history");
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <span className="font-mono font-medium [overflow-wrap:anywhere]">{change.key}</span>
      {STATUS_TONE[change.status] ? (
        <Badge variant="outline" className={cn("px-1.5 py-0 text-xs font-normal", STATUS_TONE[change.status])}>
          {t(`status.${change.status}`)}
        </Badge>
      ) : null}
    </div>
  );
}

/**
 * One side of a change.
 *
 * Wraps anywhere because these are keys and URLs with no spaces to wrap on. An
 * absent side is an em dash rather than an empty cell — a blank reads as a
 * value that is blank, which is a thing a `.env` can genuinely hold.
 */
function Value({ value, tone }) {
  const t = useTranslations("applications.environment.history");

  if (value === null || value === undefined) {
    return (
      <span className="text-muted-foreground" aria-label={t("absent")}>
        —
      </span>
    );
  }

  return (
    <span
      className={cn(
        "inline-block max-w-full rounded px-1.5 py-0.5 font-mono [overflow-wrap:anywhere]",
        tone === "removed" ? "bg-destructive/10 text-destructive" : "bg-success/10 text-success",
      )}
    >
      {value === "" ? <span className="italic text-muted-foreground">{t("emptyValue")}</span> : value}
    </span>
  );
}
