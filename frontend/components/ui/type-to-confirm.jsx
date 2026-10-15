"use client";

import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { CopyButton } from "@/components/ui/copy-button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

// The value to type sits on its own line, apart from the sentence, so a long
// domain cannot blend into the words around it. Never prefilled: typing it IS
// the safeguard.
export function TypeToConfirm({
  id,
  value,
  copyLabel,
  confirm,
  onChange,
  disabled = false,
  matches = false,
}) {
  const t = useTranslations("common");

  return (
    <div className="space-y-2">
      <Label htmlFor={id} className="text-sm">
        {t("typeToConfirm")}
      </Label>
      <div
        id={`${id}-value`}
        className="flex items-center gap-2 rounded-md border bg-muted/60 py-1 pr-1 pl-3"
      >
        <code className="min-w-0 flex-1 py-0.5 font-mono text-sm font-medium break-all text-foreground">
          {value}
        </code>
        <CopyButton value={value} label={copyLabel} className="hover:bg-background" />
      </div>
      <Input
        id={id}
        aria-describedby={`${id}-value`}
        value={confirm}
        onChange={(event) => onChange(event.target.value)}
        disabled={disabled}
        autoComplete="off"
        spellCheck={false}
        placeholder={value}
        className={cn("font-mono", matches && "border-success focus-visible:border-success")}
      />
    </div>
  );
}
