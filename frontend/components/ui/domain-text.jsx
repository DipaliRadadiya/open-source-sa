import { cn } from "@/lib/utils";
import { splitDomain } from "@/lib/format/domain";

/**
 * A domain that keeps its ending when there is not enough room.
 *
 * `truncate` alone drops the tail, which on a domain is the identifying part —
 * seven sites sharing a subdomain prefix all rendered as the same ellipsised
 * string on a phone. This truncates the subdomain and pins the registrable
 * domain, so "a-very-long-customer-subdo….example.co.uk" still says which site
 * it is.
 *
 * `title` carries the whole thing for a hover, and the text is one string to a
 * screen reader — the split is presentational, so the two spans must not read
 * as two separate words.
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
      {/* min-w-0 so the head may shrink below its content; without it the flex
          item keeps its intrinsic width and pushes the tail out of the box. */}
      <span className="min-w-0 truncate">{head}</span>
      {/* shrink-0 is the whole point: this part never gives up space. */}
      <span className="shrink-0">{tail}</span>
    </span>
  );
}
