import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2 } from "lucide-react";
import { getArchiveJobs } from "@/lib/api/files";
import { ARCHIVE_IN_FLIGHT, archiveJobsResponseSchema } from "@/lib/schemas/file-archive-job";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { formatBytes } from "@/lib/format/bytes";

// Two rates: this must poll even when idle (jobs started in another tab or before
// page load), so the idle rate is kept slow.
const POLL_ACTIVE_MS = 2000;
const POLL_IDLE_MS = 15000;

// Compress/extract are queued (202), so without this the button looks broken.
// Refreshes on completion: the listing is server-rendered.
export function ArchiveJobsBanner({ appId }) {
  const t = useTranslations("applications.files.archiveJobs");
  const router = useRouter();
  const format = useFormatter();
  const [jobs, setJobs] = useState([]);
  // Finished jobs already announced; completed rows stay in the response for five
  // minutes.
  const announced = useRef(new Set());
  // The API also returns recent completions, so the first response on each page
  // load is not news; only jobs that finish while the page is open are announced.
  const primed = useRef(false);

  // In state rather than derived from `jobs`, so the effect re-runs and the switch
  // back to idle happens on the poll that sees the last job land.
  const [rate, setRate] = useState(POLL_IDLE_MS);

  useEffect(() => {
    let active = true;
    const controller = new AbortController();

    async function load() {
      try {
        const { data } = await getArchiveJobs(appId, { signal: controller.signal });
        const parsed = archiveJobsResponseSchema.safeParse(data);
        if (!parsed.success || !active) return;

        const rows = parsed.data.data;
        if (!primed.current) {
          primed.current = true;
          for (const job of rows) {
            if (!ARCHIVE_IN_FLIGHT.includes(job.status)) announced.current.add(job.id);
          }
        }
        setJobs(rows);
        setRate(rows.some((job) => ARCHIVE_IN_FLIGHT.includes(job.status))
          ? POLL_ACTIVE_MS
          : POLL_IDLE_MS);

        let landed = false;

        for (const job of rows) {
          if (ARCHIVE_IN_FLIGHT.includes(job.status)) continue;
          if (announced.current.has(job.id)) continue;
          announced.current.add(job.id);

          if (job.status === "completed") {
            landed = true;
            toast.success(t(`done.${job.operation}`, { target: job.target }));
          } else {
            // The reason is stored as a code, so the message is already in the viewer's locale.
            toast.error(job.message ?? t("failed"));
          }
        }

        // Refresh only when something finished, not on every poll.
        if (landed) router.refresh();
      } catch {
        // A failed poll is silent; the next one is two seconds away.
      }
    }

    load();
    const id = setInterval(load, rate);

    return () => {
      active = false;
      controller.abort();
      clearInterval(id);
    };
  }, [appId, router, t, rate]);

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
