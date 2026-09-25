"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { getApplicationStatus } from "@/lib/api/applications";

const POLL_MS = 5000;
// The deploy watcher's own cap. Past it the job is stuck, not finishing, and
// polling on would only spend the rate budget.
const LIMIT_MS = 30 * 60 * 1000;

/**
 * Re-renders the application's pages when its setup or deploy ends.
 *
 * The status is read on the server, so an open page kept whatever it was
 * rendered with: after a redeploy the label still said "Deploying" and Clone
 * still said a deploy was running, until someone reloaded. Mounted once in the
 * application layout, so every page and the sidebar are covered, and it asks
 * for the status alone rather than refreshing the page every few seconds.
 */
export function ApplicationStatusWatcher({ id, status }) {
  const router = useRouter();
  const inFlight = status === "pending" || status === "provisioning";

  useEffect(() => {
    if (!inFlight) return;
    const started = Date.now();
    let live = true;
    const timer = window.setInterval(async () => {
      if (Date.now() - started > LIMIT_MS) {
        window.clearInterval(timer);
        return;
      }
      if (document.hidden) return;
      try {
        const next = await getApplicationStatus(id);
        if (live && next && next !== status) {
          window.clearInterval(timer);
          router.refresh();
        }
      } catch {
        // A missed poll is retried on the next tick.
      }
    }, POLL_MS);
    return () => {
      live = false;
      window.clearInterval(timer);
    };
  }, [inFlight, id, status, router]);

  return null;
}
