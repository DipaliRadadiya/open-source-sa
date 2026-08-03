import { getTranslations } from "next-intl/server";

/**
 * "Changed by admin, 1 day ago" for one settings group, or null.
 *
 * Null when the API has no record for the group — a card nobody has touched
 * should say nothing rather than "never". The absence of a record is not itself
 * a fact worth a line on the card.
 */
export async function changedFor(lastChanged, group) {
  const entry = lastChanged?.[group];
  if (!entry?.user?.username || !entry?.at_human) return null;

  const t = await getTranslations("settings.common");
  return t("changedBy", { user: entry.user.username, when: entry.at_human });
}
