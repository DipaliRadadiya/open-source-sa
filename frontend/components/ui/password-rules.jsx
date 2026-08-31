"use client";

import { Check, Circle } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { passwordRules } from "@/lib/auth/password-rules";

/**
 * The live requirement checklist under a new-password field.
 *
 * Shown from the start rather than after a failed submit: the rules are the
 * thing you need while you are inventing the password, not after it has been
 * rejected.
 */
export function PasswordRules({ value, className }) {
  const t = useTranslations("common.passwordRules");

  return (
    <ul className={cn("space-y-1 pt-0.5", className)}>
      {passwordRules(value).map((rule) => (
        <li
          key={rule.key}
          className={cn(
            "flex items-center gap-1.5 text-xs",
            rule.ok ? "text-foreground" : "text-muted-foreground",
          )}
        >
          {rule.ok ? (
            <Check className="size-3.5 text-success" />
          ) : (
            <Circle className="size-3.5 text-muted-foreground/50" />
          )}
          {t(rule.key)}
        </li>
      ))}
    </ul>
  );
}
