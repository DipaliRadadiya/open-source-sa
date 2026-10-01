import { ShieldCheck, ShieldOff } from "lucide-react";
import { cn } from "@/lib/utils";

// The server sets `https` only when the certificate is servable and covers the
// primary domain; the list payload has no certificate field.
export function isServedOverTls(application) {
  return String(application?.url ?? "").startsWith("https://");
}

// A missing certificate is not destructive: every new site lacks one at first.
export function TlsMark({ application, label, className }) {
  if (!application?.url) return null;

  const secure = isServedOverTls(application);
  const Icon = secure ? ShieldCheck : ShieldOff;

  return (
    <Icon
      role="img"
      aria-label={label}
      title={label}
      className={cn(
        "size-3.5 shrink-0",
        secure ? "text-success" : "text-muted-foreground",
        className,
      )}
    />
  );
}
