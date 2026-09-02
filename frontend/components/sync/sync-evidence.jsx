import { useTranslations } from "next-intl";
import { confidenceBand } from "@/lib/schemas/sync";
import { cn } from "@/lib/utils";

/**
 * The evidence behind one guess.
 *
 * Rendered as whatever keys arrived, because they genuinely differ per resource
 * type — an application carries {path, document_root, owner}, php_settings
 * carries {pool, values}, a firewall rule carries {to, from}. Any fixed set of
 * columns here would be blank for two thirds of the rows, which is why this is
 * a per-row panel rather than part of the table.
 *
 * Keys are printed raw and monospaced: they are the API's own names, and a
 * translated label would invent a mapping that goes stale the moment a
 * discoverer adds a field.
 */
function EvidenceValue({ value }) {
  if (value == null) return <span className="text-muted-foreground">—</span>;
  if (typeof value === "boolean") return <span>{String(value)}</span>;

  // php_settings ships a nested `values` object of ini directives.
  if (typeof value === "object") {
    return (
      <div className="space-y-0.5">
        {Object.entries(value).map(([key, nested]) => (
          <div key={key} className="font-mono text-xs">
            <span className="text-muted-foreground">{key}</span>
            {" = "}
            <span>{String(nested)}</span>
          </div>
        ))}
      </div>
    );
  }

  return <span className="font-mono text-xs break-all">{String(value)}</span>;
}

export function SyncEvidence({ item }) {
  const t = useTranslations("sync");
  const entries = Object.entries(item.evidence ?? {});

  /* Confidence is only shown where it varies. Seven of the nine discoverers
     hardcode 100 — a score on those rows is not a measurement, it is the
     absence of one, and printing it invites people to compare it against a
     website's 40 as though the two came from the same scale. */
  const showConfidence = item.confidence != null && item.confidence < 100;
  const band = confidenceBand(item.confidence);

  return (
    <div className="space-y-3 bg-muted/30 px-4 py-3">
      {showConfidence ? (
        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
          <span
            className={cn(
              "rounded-md px-1.5 py-0.5 text-xs font-medium",
              band === "medium" && "bg-warning/10 text-warning",
              band === "low" && "bg-destructive/10 text-destructive",
            )}
          >
            {t(`confidence.${band}`)}
          </span>
          <span className="text-xs text-muted-foreground">
            {t("confidence.score", { score: item.confidence })}
          </span>
        </div>
      ) : null}

      {item.reason ? <p className="text-sm">{item.reason}</p> : null}

      {entries.length ? (
        <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-[max-content_1fr]">
          {entries.map(([key, value]) => (
            <div key={key} className="contents">
              <dt className="font-mono text-xs text-muted-foreground">{key}</dt>
              <dd className="min-w-0">
                <EvidenceValue value={value} />
              </dd>
            </div>
          ))}
        </dl>
      ) : (
        <p className="text-sm text-muted-foreground">{t("evidence.none")}</p>
      )}
    </div>
  );
}
