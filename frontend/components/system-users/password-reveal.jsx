import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { CopyButton } from "@/components/ui/copy-button";

// The stored OS password, masked, with copy only (Krishna, 13 Oct: no eye). The backend
// stores it in plaintext so an admin can copy it for server login.
export function PasswordReveal({ password, className }) {
  const t = useTranslations("systemUsers.detail");

  if (!password) {
    return <span className="text-muted-foreground">{t("passwordNotSet")}</span>;
  }

  return (
    <div className={cn("flex w-full items-center gap-1", className)}>
      <code className="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1.5 font-mono text-sm" aria-hidden>
        {"•".repeat(Math.min(password.length, 10))}
      </code>
      <CopyButton value={password} label={t("copy")} className="size-8" />
    </div>
  );
}
