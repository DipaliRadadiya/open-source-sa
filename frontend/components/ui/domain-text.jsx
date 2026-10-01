import { cn } from "@/lib/utils";
import { splitDomain } from "@/lib/format/domain";

// Truncates the subdomain, keeping the registrable domain visible; `title` holds the full value.
export function DomainText({ domain, className }) {
  const value = String(domain ?? "");
  const { head, tail } = splitDomain(value);

  if (!head) {
    return (
      <span className={cn("truncate", className)} title={value}>
        {value}
      </span>
    );
  }

  return (
    <span className={cn("flex min-w-0 items-baseline", className)} title={value}>
      {/* min-w-0 lets the head shrink below its content width. */}
      <span className="min-w-0 truncate">{head}</span>
      {/* shrink-0: the tail never gives up space. */}
      <span className="shrink-0">{tail}</span>
    </span>
  );
}
