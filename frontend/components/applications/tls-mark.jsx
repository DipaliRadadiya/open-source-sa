import { ShieldCheck, ShieldOff } from "lucide-react";
import { cn } from "@/lib/utils";
import { HoverHint } from "@/components/ui/hover-hint";

export function isServedOverTls(application) {
  return String(application?.url ?? "").startsWith("https://");
}

export function TlsMark({ application, label, className }) {
  if (!application?.url) return null;

  const secure = isServedOverTls(application);
  const Icon = secure ? ShieldCheck : ShieldOff;

  return (
    <HoverHint content={label}><Icon
      role="img"
      aria-label={label}
      className={cn(
        "size-3.5 shrink-0",
        secure ? "text-success" : "text-muted-foreground",
        className,
      )}
    /></HoverHint>
  );
}
