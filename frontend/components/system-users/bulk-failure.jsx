import { useTranslations } from "next-intl";

// Why a bulk delete kept this account, next to its name, until the next delete or Clear.
export function BulkFailure({ failure }) {
  const t = useTranslations("systemUsers.bulk");
  if (!failure) return null;
  return (
    <p role="status" className="text-xs leading-5 text-destructive">
      {t(`reason.${failure.reason}`)}
      {failure.reference ? <span className="ml-1 font-mono text-muted-foreground">({failure.reference.slice(0, 8)})</span> : null}
    </p>
  );
}
