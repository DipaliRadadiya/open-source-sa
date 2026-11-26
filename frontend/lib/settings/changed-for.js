import { getTranslations } from "next-intl/server";

export async function changedFor(lastChanged, group) {
  const entry = lastChanged?.[group];
  if (!entry?.user?.username || !entry?.at_human) return null;

  const t = await getTranslations("settings.common");
  return t("changedBy", { user: entry.user.username, when: entry.at_human });
}
