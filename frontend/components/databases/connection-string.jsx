import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { connectionStringParts } from "@/lib/databases/connection-parts";
import { CopyButton } from "@/components/ui/copy-button";

// Each part in its own colour so user, host, port and database can be told apart at a
// glance; the password is never on screen, only in what Copy puts on the clipboard.
const PART = {
  user: "text-sky-700 dark:text-sky-300",
  host: "text-emerald-700 dark:text-emerald-300",
  port: "text-amber-700 dark:text-amber-300",
  rest: "text-violet-700 dark:text-violet-300",
};

function Coloured({ value }) {
  const parts = connectionStringParts(value);
  if (!parts) return value.replace(/:[^:@/]*@/, ":••••••@");
  return (
    <>
      <span className="text-muted-foreground">{parts.scheme}://</span>
      <span className={PART.user}>{parts.username}</span>
      <span className="text-muted-foreground">:••••••@</span>
      <span className={PART.host}>{parts.host}</span>
      {parts.port ? (
        <>
          <span className="text-muted-foreground">:</span>
          <span className={PART.port}>{parts.port}</span>
        </>
      ) : null}
      {parts.rest ? (
        <>
          <span className="text-muted-foreground">/</span>
          <span className={PART.rest}>{parts.rest}</span>
        </>
      ) : null}
    </>
  );
}

/**
 * `block`: a labelled panel with a "Copy connection string" button (Overview).
 * `inline`: the string and a small Copy button in one well (a user's row).
 */
export function ConnectionString({ value, variant = "block", hint = null, className }) {
  const t = useTranslations("databases.credentials");
  if (!value) return null;

  if (variant === "inline") {
    return (
      <div className={cn("flex items-center gap-3 rounded-lg border bg-muted/40 py-1.5 pr-1.5 pl-3", className)}>
        <code className="min-w-0 flex-1 truncate font-mono text-xs">
          <Coloured value={value} />
        </code>
        {/* "Copy": the row it sits in already names what is copied. */}
        <CopyButton value={value} text />
      </div>
    );
  }

  return (
    <div className={cn("space-y-2 rounded-xl border bg-muted/40 p-3.5", className)}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
          {t("connectionString")}
        </p>
        <CopyButton value={value} label={t("copyString")} text />
      </div>
      {/* Wraps at any character: on a phone the string is wider than the card. */}
      <code className="block font-mono text-[13px] leading-relaxed [overflow-wrap:anywhere]">
        <Coloured value={value} />
      </code>
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}
