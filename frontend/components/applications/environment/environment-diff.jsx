import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ChevronRight, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { getEnvironmentDiff } from "@/lib/api/environment";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";

/**
 * The old and new value of every variable one change touched.
 *
 * Loaded when the row is opened, not with the history list: it reads one or two
 * backup files off the server, and most rows are never expanded. The values are
 * read from those files rather than from the activity log — see
 * `EnvironmentDiff` on the backend for why that distinction matters.
 */
export function EnvironmentDiff({ appId, entry }) {
  const t = useTranslations("applications.environment.history");
  const [open, setOpen] = useState(false);
  const [state, setState] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);

  async function toggle() {
    if (open) {
      setOpen(false);
      return;
    }

    setOpen(true);

    // Fetched once per row and kept. Reopening a row should not re-read files
    // off the server, and the answer cannot change: it describes a past state.
    if (state || loading) return;

    setLoading(true);
    setError(null);
    try {
      const data = await getEnvironmentDiff(appId, entry.id);
      setState(data?.diff ?? { available: false, changes: [] });
    } catch (e) {
      setError(apiMessage(e, t("diffFailed")));
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="mt-1">
      <Button
        type="button"
        variant="ghost"
        size="sm"
        onClick={toggle}
        className="h-6 gap-1 px-1.5 text-xs text-muted-foreground"
        aria-expanded={open}
      >
        {open ? (
          <ChevronDown className="size-3.5" />
        ) : (
          <ChevronRight className="size-3.5" />
        )}
        {t("showValues")}
      </Button>

      {open ? (
        <div className="mt-1.5 rounded-lg border">
          {loading ? (
            <p className="flex items-center gap-2 p-3 text-xs text-muted-foreground">
              <Loader2 className="size-3.5 animate-spin" />
              {t("diffLoading")}
            </p>
          ) : error ? (
            <p className="p-3 text-xs text-muted-foreground">{error}</p>
          ) : !state?.available ? (
            // Not the same as "nothing changed": the file holding the previous
            // version has been deleted, so this change cannot be described.
            <p className="p-3 text-xs text-muted-foreground">
              {t("diffUnavailable")}
            </p>
          ) : state.changes.length === 0 ? (
            <p className="p-3 text-xs text-muted-foreground">{t("noKeys")}</p>
          ) : (
            <table className="w-full text-xs">
              <thead className="border-b text-muted-foreground">
                <tr>
                  <th className="p-2 text-start font-normal">{t("colKey")}</th>
                  <th className="p-2 text-start font-normal">{t("colBefore")}</th>
                  <th className="p-2 text-start font-normal">{t("colAfter")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {state.changes.map((change) => (
                  <tr key={change.key}>
                    <td className="p-2 align-top font-mono font-medium">
                      {change.key}
                    </td>
                    <Value value={change.before} tone="removed" />
                    <Value value={change.after} tone="added" />
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      ) : null}
    </div>
  );
}

/**
 * One side of a change.
 *
 * `break-all` because these are keys and URLs with no spaces to wrap on, and a
 * long one would otherwise push the table wider than the card. An absent side
 * is an em dash rather than an empty cell — a blank reads as a value that is
 * blank, which is a thing a `.env` can genuinely hold.
 */
function Value({ value, tone }) {
  const t = useTranslations("applications.environment.history");

  if (value === null || value === undefined) {
    return (
      <td className="p-2 align-top text-muted-foreground" aria-label={t("absent")}>
        —
      </td>
    );
  }

  return (
    <td
      className={cn(
        "p-2 align-top font-mono break-all",
        tone === "removed" ? "text-destructive" : "text-success",
      )}
    >
      {value === "" ? (
        <span className="italic text-muted-foreground">{t("emptyValue")}</span>
      ) : (
        value
      )}
    </td>
  );
}
