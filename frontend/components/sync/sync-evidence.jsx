import { useTranslations } from "next-intl";
import { confidenceBand } from "@/lib/schemas/sync";
import { cn } from "@/lib/utils";

// Keys differ per resource type and are printed raw: translated labels would
// go stale when a discoverer adds a field.
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

  /* Most discoverers hardcode 100, which is not a measurement. */
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
