"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Copy, Check } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

const RESET_MS = 1500;

// Only failure toasts: a clipboard write can be refused (insecure context, denied permission).
export function CopyButton({ value, label, className, text = false }) {
  const t = useTranslations("common");
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return undefined;
    const id = setTimeout(() => setCopied(false), RESET_MS);
    return () => clearTimeout(id);
  }, [copied]);

  if (!value) return null;

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
    } catch {
      toast.error(t("copyFailed"));
    }
  }

  const title = label ?? t("copy");

  /* For when the value is not beside the button. */
  if (text) {
    return (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={copy}
        className={className}
      >
        {copied ? <Check className="text-success" /> : <Copy />}
        {copied ? t("copied") : title}
      </Button>
    );
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          onClick={copy}
          aria-label={title}
          className={cn(
            "flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
            className,
          )}
        >
          {copied ? (
            <Check className="size-3.5 text-success" />
          ) : (
            <Copy className="size-3.5" />
          )}
        </button>
      </TooltipTrigger>
      <TooltipContent>{copied ? t("copied") : title}</TooltipContent>
    </Tooltip>
  );
}
