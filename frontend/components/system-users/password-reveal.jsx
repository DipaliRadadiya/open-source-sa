"use client";

import { useState } from "react";
import { Eye, EyeOff, Copy } from "lucide-react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";

// Shows the stored OS password masked, with reveal + copy. The backend stores
// it plaintext (operator decision) so an admin can copy it for server login.
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
    <div className={cn("flex w-full items-center gap-2", className)}>
      <code className="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1.5 font-mono text-sm">
        {shown ? password : "•".repeat(Math.min(password.length, 12))}
      </code>
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
    </div>
  );
}
