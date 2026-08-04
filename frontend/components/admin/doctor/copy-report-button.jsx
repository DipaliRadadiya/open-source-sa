"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Copy, Check } from "lucide-react";
import { Button } from "@/components/ui/button";

// Copies the whole health report as plain text — the `detail` lines are meant
// to be pasted to support, so this hands over the entire thing in one click.
export function CopyReportButton({ text }) {
  const t = useTranslations("doctor");
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      toast.success(t("copied"));
      setTimeout(() => setCopied(false), 2000);
    } catch {
      toast.error(t("copyFailed"));
    }
  }

  return (
    <Button variant="outline" size="sm" className="shrink-0" onClick={copy}>
      {copied ? <Check className="size-4 text-success" /> : <Copy className="size-4" />}
      {t("copyReport")}
    </Button>
  );
}
