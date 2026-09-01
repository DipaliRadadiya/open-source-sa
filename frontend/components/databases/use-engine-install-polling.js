"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { getEngines } from "@/lib/api/databases";
import { enginesResponseSchema } from "@/lib/schemas/database";
import {
  installingEngineName,
  markEngineInstalling,
} from "@/lib/databases/install-lifecycle";

const POLL_MS = 5000;
const SLOW_AFTER_MS = 3 * 60 * 1000;
const POLL_FAILURE_LIMIT = 3;

/**
 * One polling owner for every database-engine install surface.
 *
 * `markStarted()` writes the queued state immediately, before the first poll,
 * so closing the confirmation dialog never leaves the page looking unchanged.
 * The API remains authoritative after that: a successful install disappears
 * from runtime_installs and comes back as a running engine; a failure stays on
 * the engine row with the backend's reason.
 */
export function useEngineInstallPolling(initialEngines = []) {
  const router = useRouter();
  const [snapshot, setSnapshot] = useState({
    initial: initialEngines,
    polled: null,
  });
  const [slowEngine, setSlowEngine] = useState(null);
  const [pollIssueEngine, setPollIssueEngine] = useState(null);

  // A refreshed Server Component is newer than any client-side snapshot. React
  // permits this guarded render-time adjustment and re-renders immediately;
  // using an effect would show the stale rows for one committed frame.
  if (snapshot.initial !== initialEngines) {
    setSnapshot({ initial: initialEngines, polled: null });
  }

  const engines = snapshot.polled ?? snapshot.initial;
  const installingEngine = installingEngineName(engines);

  useEffect(() => {
    if (!installingEngine) return undefined;

    let active = true;
    let polling = false;
    let failedPolls = 0;
    const controller = new AbortController();

    const slowId = setTimeout(() => {
      if (active) setSlowEngine(installingEngine);
    }, SLOW_AFTER_MS);

    async function poll() {
      if (!active || polling || document.hidden) return;
      polling = true;

      try {
        const { data } = await getEngines({ signal: controller.signal });
        if (!active) return;

        const parsed = enginesResponseSchema.safeParse(data);
        if (!parsed.success) {
          failedPolls += 1;
          const issue = parsed.error.issues?.[0];
          console.warn(
            `[database-install] engine poll shape mismatch${
              issue
                ? `: ${issue.path.join(".")} — ${issue.message}`
                : ""
            }`,
          );
          if (failedPolls >= POLL_FAILURE_LIMIT) {
            setPollIssueEngine(installingEngine);
          }
          return;
        }

        failedPolls = 0;
        setPollIssueEngine(null);
        setSnapshot((current) => ({
          ...current,
          polled: parsed.data.engines,
        }));

        const current = parsed.data.engines.find(
          (engine) => engine.engine === installingEngine,
        );
        if (!current || current.install_status !== "installing") {
          router.refresh();
        }
      } catch (error) {
        if (!active || error?.name === "CanceledError") return;
        failedPolls += 1;
        if (failedPolls >= POLL_FAILURE_LIMIT) {
          setPollIssueEngine(installingEngine);
        }
      } finally {
        polling = false;
      }
    }

    void poll();
    const pollId = setInterval(poll, POLL_MS);
    const onVisibility = () => {
      if (!document.hidden) void poll();
    };
    document.addEventListener("visibilitychange", onVisibility);

    return () => {
      active = false;
      controller.abort();
      clearTimeout(slowId);
      clearInterval(pollId);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [installingEngine, router]);

  function markStarted(engineName) {
    setSlowEngine(null);
    setPollIssueEngine(null);
    setSnapshot((current) => {
      const rows = current.polled ?? current.initial;
      return {
        ...current,
        polled: markEngineInstalling(rows, engineName),
      };
    });
  }

  // Guarded on `installingEngine` because both sides are null when nothing is
  // installing, and `null === null` is true — so a server with no install
  // running rendered "we temporarily lost progress updates" permanently, about
  // an install that did not exist. Neither flag means anything without one.
  const installing = Boolean(installingEngine);

  return {
    engines,
    installingEngine,
    slow: installing && slowEngine === installingEngine,
    pollIssue: installing && pollIssueEngine === installingEngine,
    markStarted,
  };
}
