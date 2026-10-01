import { getTranslations } from "next-intl/server";

// Charset and collation live in the Tables tab.
export async function DatabaseFacts({ database, hideSize = false }) {
  const t = await getTranslations("databases.detail");

  const facts = [
    hideSize ? null : database.size_human,
    database.created_at_human
      ? t("createdWhen", { when: database.created_at_human })
      : null,
  ].filter(Boolean);

  if (facts.length === 0) return null;

  return (
    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
      {facts.map((fact, index) => (
        <span key={fact} className="flex items-center gap-2">
          {index > 0 ? <span aria-hidden>·</span> : null}
          <span className="font-mono">{fact}</span>
        </span>
      ))}
    </p>
  );
}
