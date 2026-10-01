import { ArrowLeft } from "lucide-react";
import Link from "@/components/ui/app-link";

/**
 * "← Back to …" above a detail page's title. The breadcrumb already links
 * up, but it sits in the header strip; on a detail page reached from a list,
 * the way back belongs where the eye starts reading the page.
 */
export function BackLink({ href, children }) {
  return (
    <Link
      href={href}
      prefetch={false}
      className="inline-flex items-center gap-1.5 rounded-md text-sm text-muted-foreground hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
    >
      <ArrowLeft className="size-4" aria-hidden />
      {children}
    </Link>
  );
}
