import { getTranslations } from "next-intl/server";

/**
 * Size and age, on one line under the name.
 *
 * This was a two-by-two card taking 90px at the top of the page, and then a
 * four-item line. Charset and collation have since moved into the Tables tab —
 * they describe the storage, they are read once at most, and they were sitting
 * above the connection details people actually came for.
 */
export async function DatabaseFacts({ database }) {
  const t = await getTranslations("databases.detail");

  const facts = [
    database.size_human,
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
