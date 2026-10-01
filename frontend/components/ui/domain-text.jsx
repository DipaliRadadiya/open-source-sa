import { cn } from "@/lib/utils";
import { splitDomain } from "@/lib/format/domain";

/**
 * A domain that truncates the subdomain and keeps the registrable domain
 * visible, e.g. "a-very-long-subdo….example.co.uk". `title` holds the full
 * value; the split is presentational only.
 */
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
