import { useState } from "react";
import { Eye, EyeOff, Copy } from "lucide-react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";

// Shows the stored OS password masked, with reveal + copy. The backend stores it
// in plaintext so an admin can copy it for server login.
export function PasswordReveal({ password, className }) {
  const t = useTranslations("systemUsers.detail");
  const [shown, setShown] = useState(false);

  if (!password) {
    return <span className="text-muted-foreground">{t("passwordNotSet")}</span>;
  }

  async function copy() {
    try {
      await navigator.clipboard.writeText(password);
      toast.success(t("copied"));
    } catch {
      /* clipboard blocked — no-op */
    }
  }

  return (
    <div className={cn("flex w-full items-center gap-1", className)}>
      {/* Wraps once revealed rather than truncating: a generated password is wider than
          the narrow column, and the point is to read all of it. */}
      <code
        className={cn(
          "min-w-0 flex-1 rounded bg-muted px-2 py-1.5 font-mono text-sm",
          shown ? "whitespace-normal break-all" : "truncate",
        )}
      >
        {shown ? password : "•".repeat(Math.min(password.length, 10))}
      </code>
      <IconTooltip label={shown ? t("hide") : t("reveal")}>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-8"
          onClick={() => setShown((v) => !v)}
          aria-label={shown ? t("hide") : t("reveal")}
        >
          {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
        </Button>
      </IconTooltip>
      <IconTooltip label={t("copy")}>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-8"
          onClick={copy}
          aria-label={t("copy")}
        >
          <Copy className="size-4" />
        </Button>
      </IconTooltip>
    </div>
  );
}
