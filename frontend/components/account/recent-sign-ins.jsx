import { useFormatter, useTranslations } from "next-intl";
import { LogIn } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Section } from "@/components/settings/setting-row";
import { parseApiDate } from "@/lib/format/api-date";

// The last few sign-ins, so a stranger's shows up where the password is changed.
// Read from the account's own activity; the newest also has its address on the user.
export function RecentSignIns({ entries = [], failed = false, lastIp = null, onShowAll }) {
  const t = useTranslations("account.signIns");
  const format = useFormatter();

  return (
    <Section
      icon={LogIn}
      title={t("title")}
      description={t("description")}
      actions={
        entries.length ? (
          <Button type="button" variant="outline" onClick={onShowAll}>
            {t("showAll")}
          </Button>
        ) : null
      }
    >
      {failed ? (
        <p className="text-sm text-muted-foreground">{t("failed")}</p>
      ) : entries.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("empty")}</p>
      ) : (
        <ul className="divide-y rounded-lg border">
          {entries.map((entry, index) => {
            const date = parseApiDate(entry.created_at);
            return (
              <li key={entry.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-0.5 px-3 py-2 text-sm">
                <span className="font-medium">{entry.created_at_human}</span>
                <span className="flex items-center gap-3 text-muted-foreground">
                  {/* Only the newest sign-in's address is known (`last_login_ip`). */}
                  {index === 0 && lastIp ? <span className="font-mono text-xs">{lastIp}</span> : null}
                  {date ? <span className="tabular-nums">{format.dateTime(date, { dateStyle: "medium", timeStyle: "short" })}</span> : null}
                </span>
              </li>
            );
          })}
        </ul>
      )}
      <p className="text-xs text-muted-foreground">{t("notYou")}</p>
    </Section>
  );
}
