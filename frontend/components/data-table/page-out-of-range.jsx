"use client";

import { useEffect } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { FileQuestion } from "lucide-react";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/data-table/empty-state";
import { outOfRangeHref } from "@/lib/tables/out-of-range-href";

/**
 * `?page=99` on a list with one page.
 *
 * The API answers 200 with an empty array rather than 404, so a list falls
 * through to its "nothing here yet" empty state — the applications list said
 * "you have no sites" on ?page=2 of a ten-site server, and the backup lists
 * directly beneath a summary row saying "12 Total backups". Worse, the pager
 * only renders when there are rows, so the page that told you there was
 * nothing here also removed the control that would take you back.
 *
 * Deleting the last row on the last page lands here too, and there the reader
 * did not ask to be anywhere unusual — they deleted a thing and the page they
 * were on stopped existing. So this moves them itself, to the page that now
 * holds the end of the list rather than to page 1: deleting the only row on
 * page 3 of 3 belongs on page 2, not back at the start of a long list.
 *
 * The state below still renders, with the way out attached, for the moment
 * before the navigation lands and for anyone whose JS has not run.
 */
export function PageOutOfRange({ lastPage = 1 }) {
  const t = useTranslations("common.pageOutOfRange");
  const params = useSearchParams();
  const router = useRouter();

  const href = outOfRangeHref(params, lastPage);

  // `replace`, not `push`: the page that no longer exists must not become a
  // Back-button destination, or Back returns to the same empty state.
  useEffect(() => {
    router.replace(href, { scroll: false });
  }, [href, router]);

  return (
    <EmptyState
      icon={FileQuestion}
      title={t("title")}
      description={t("description", { lastPage })}
      action={
        <Button asChild variant="outline">
          <Link href={href}>{t("action")}</Link>
        </Button>
      }
    />
  );
}
