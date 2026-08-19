"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { FileQuestion } from "lucide-react";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/data-table/empty-state";

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
 * Its own state, with the way out attached.
 */
export function PageOutOfRange({ lastPage = 1 }) {
  const t = useTranslations("common.pageOutOfRange");
  const params = useSearchParams();

  // Keep every filter, drop only the page — the filters are what the reader
  // chose, and silently discarding them would be a second surprise.
  const back = new URLSearchParams(params);
  back.delete("page");
  const href = back.size ? `?${back}` : "?";

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
