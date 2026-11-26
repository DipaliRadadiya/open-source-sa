import { useTranslations } from "next-intl";
import { CircleAlert, CircleCheck, CircleHelp } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";

const WARN_DAYS = 14;
const URGENT_DAYS = 3;

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
        <StatusText>{t("unknownHint")}</StatusText>
      </Line>
    );
  }

  const days = status.expires_in_days;
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
