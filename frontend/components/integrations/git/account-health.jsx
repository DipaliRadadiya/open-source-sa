import { useTranslations } from "next-intl";
import { CircleAlert, CircleCheck, CircleHelp } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Warn while there is still time to act, and stay quiet before that.
 *
 * The API sends a number of days; a number alone does not tell anyone when to
 * care. Two weeks is enough notice to make a new token without it becoming
 * background noise on every visit.
 */
const WARN_DAYS = 14;
const URGENT_DAYS = 3;

/**
 * Whether this account's token still works, right now.
 *
 * Three states, three treatments — and the third one is the whole reason this
 * component is careful. `unknown` means the provider did not answer, which is
 * not the user's problem and must not be dressed as one: rendering it in red
 * would accuse a perfectly good token every time GitHub has a wobble.
 */
export function AccountHealth({ status, loading }) {
  const t = useTranslations("git.health");

  if (loading) {
    return (
      <div className="flex items-center gap-2">
        <Skeleton className="h-5 w-20 rounded-full" />
        <Skeleton className="h-3 w-24" />
      </div>
    );
  }

  // No row came back for this account at all — same class of non-answer as
  // `unknown`, and treated the same way.
  if (!status) return <StatusText>{t("notChecked")}</StatusText>;

  if (status.status === "invalid") {
    const revoked =
      status.status_title &&
      /(revoked|deleted|invalid|forbidden)/i.test(status.status_title);
    return (
      <Line>
        <Badge variant="destructive" className="font-normal">
          <CircleAlert className="size-3" />
          {t("invalid")}
        </Badge>
        {revoked ? (
          <StatusText tone="warn">
            {t("invalidHintRevoked", { provider: status.provider_title ?? "" })}
          </StatusText>
        ) : (
          <StatusText>{status.status_title ?? t("invalidHint")}</StatusText>
        )}
      </Line>
    );
  }

  if (status.status === "unknown") {
    return (
      <Line>
        <Badge variant="muted" className="font-normal">
          <CircleHelp className="size-3" />
          {t("unknown")}
        </Badge>
        {/* Said outright, because a grey badge alone still reads as trouble. */}
        <StatusText>{t("unknownHint")}</StatusText>
      </Line>
    );
  }

  const days = status.expires_in_days;
  // Bitbucket tokens have no expiry: null means there is none, never that the
  // lookup failed. Nothing is shown, and nothing is implied.
  const expiring = typeof days === "number" && days <= WARN_DAYS;

  return (
    <Line>
      <Badge variant="success" className="font-normal">
        <CircleCheck className="size-3" />
        {t("valid")}
      </Badge>
      {expiring ? (
        <StatusText tone={days <= URGENT_DAYS ? "urgent" : "warn"}>
          {days <= 0 ? t("expired") : t("expiresIn", { days })}
        </StatusText>
      ) : (
        <StatusText>{status.checked_at ? t("checked") : null}</StatusText>
      )}
    </Line>
  );
}

function Line({ children }) {
  return <div className="flex flex-wrap items-center gap-x-2 gap-y-1">{children}</div>;
}

// Renamed off `Note` when the shared note box arrived under that name. This
// is a different thing entirely — a line of tiny coloured status text, not a
// bordered callout — and two components called Note is how the next person
// imports the wrong one.
function StatusText({ children, tone }) {
  if (!children) return null;

  const color =
    tone === "urgent"
      ? "text-destructive"
      : tone === "warn"
        ? "text-warning"
        : "text-muted-foreground";

  return <span className={`text-xs ${color}`}>{children}</span>;
}
