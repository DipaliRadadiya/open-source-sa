"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2 } from "lucide-react";
import { getArchiveJobs } from "@/lib/api/files";
import { ARCHIVE_IN_FLIGHT, archiveJobsResponseSchema } from "@/lib/schemas/file-archive-job";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { formatBytes } from "@/lib/format/bytes";

const POLL_MS = 2000;

/**
 * Says that an archive is being built, because nothing else can.
 *
 * Compress and extract moved to the queue: the request now returns 202 and the
 * archive appears some minutes later. Without this the button looks broken —
 * it closes its dialog, nothing changes in the listing, and the file shows up
 * later with no explanation. That is worse than the timeout it replaced,
 * because at least the timeout said something eventually.
 *
 * `router.refresh()` on completion rather than inserting the row here: the
 * listing is server-rendered, so a client-side write that does not refresh
 * leaves the sibling card showing page-load state forever.
 */
export function ArchiveJobsBanner({ appId }) {
  const t = useTranslations("applications.files.archiveJobs");
  const router = useRouter();
  const format = useFormatter();
  const [jobs, setJobs] = useState([]);
  // Which finished jobs have already been announced. Without it the toast
  // fires on every poll for the five minutes a completed row stays visible.
  const announced = useRef(new Set());

  useEffect(() => {
    let active = true;
    const controller = new AbortController();

    async function load() {
      try {
        const { data } = await getArchiveJobs(appId, { signal: controller.signal });
        const parsed = archiveJobsResponseSchema.safeParse(data);
        if (!parsed.success || !active) return;

        const rows = parsed.data.data;
        setJobs(rows);

        let landed = false;

        for (const job of rows) {
          if (ARCHIVE_IN_FLIGHT.includes(job.status)) continue;
          if (announced.current.has(job.id)) continue;
          announced.current.add(job.id);

          if (job.status === "completed") {
            landed = true;
            toast.success(t(`done.${job.operation}`, { target: job.target }));
          } else {
            // The reference is what support asks for; the message is already
            // in this viewer's locale because the reason is stored as a code.
            toast.error(job.message ?? t("failed"));
          }
        }

        // Only when something actually finished. Refreshing on every poll
        // would re-render the whole listing every two seconds.
        if (landed) router.refresh();
      } catch {
        // A failed poll is not worth a message of its own — the next one is
        // two seconds away, and a toast per failure would bury the screen if
        // the panel goes briefly unreachable.
      }
    }

    load();
    const id = setInterval(load, POLL_MS);

    return () => {
      active = false;
      controller.abort();
      clearInterval(id);
    };
  }, [appId, router, t]);

  const running = jobs.filter((job) => ARCHIVE_IN_FLIGHT.includes(job.status));

  if (running.length === 0) return null;

  return (
    <div className="space-y-2">
      {running.map((job) => (
        <Alert key={job.id}>
          <Loader2 className="size-4 animate-spin" />
          <AlertDescription>
            {t(`running.${job.operation}`, { target: job.target })}
            {job.size_bytes > 0 ? ` — ${formatBytes(job.size_bytes, format)}` : null}
          </AlertDescription>
        </Alert>
      ))}
    </div>
  );
}
