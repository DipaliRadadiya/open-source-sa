import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2 } from "lucide-react";
import { getArchiveJobs } from "@/lib/api/files";
import { ARCHIVE_IN_FLIGHT, archiveJobsResponseSchema } from "@/lib/schemas/file-archive-job";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { formatFileSize } from "@/lib/format/bytes";

const POLL_ACTIVE_MS = 2000;
const POLL_IDLE_MS = 15000;

export function ArchiveJobsBanner({ appId }) {
  const t = useTranslations("applications.files.archiveJobs");
  const router = useRouter();
  const format = useFormatter();
  const [jobs, setJobs] = useState([]);
  const announced = useRef(new Set());
  const primed = useRef(false);

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
            toast.error(job.message ?? t("failed"));
          }
        }

        if (landed) router.refresh();
      } catch {
        // retry on the next poll
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
            {job.size_bytes > 0 ? ` — ${formatFileSize(job.size_bytes, format)}` : null}
          </AlertDescription>
        </Alert>
      ))}
    </div>
  );
}
