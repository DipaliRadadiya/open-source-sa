import { Fragment } from "react";
import { cn } from "@/lib/utils";
import { splitDomain } from "@/lib/format/domain";
import { HoverHint } from "@/components/ui/hover-hint";

export function DomainText({ domain, className }) {
  const value = String(domain ?? "");
  const { head, tail } = splitDomain(value);

  if (!head) {
    return (
      <HoverHint content={value}><span className={cn("truncate", className)}>
        {value}
      </span></HoverHint>
    );
  }

  return (
    <HoverHint content={value}><span className={cn("flex min-w-0 items-baseline", className)}>
      <span className="min-w-0 truncate">{head}</span>
      <span className="shrink-0">{tail}</span>
    </span></HoverHint>
  );
}

export function WrappedDomain({ domain, className }) {
  const parts = String(domain ?? "").split(".");
  return (
    <span className={cn("[overflow-wrap:anywhere]", className)}>
      {parts.map((part, index) => (
        <Fragment key={index}>
          {index ? <wbr /> : null}
          {index ? "." : null}
          {part}
        </Fragment>
      ))}
    </span>
  );
}
