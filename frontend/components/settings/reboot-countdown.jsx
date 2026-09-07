import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import {
  deadlineFrom,
  remainingSeconds,
  splitRemaining,
} from "@/lib/settings/reboot-countdown";

/**
 * The live half of the pending-restart banner: "Restarting in 14:32", ticking.
 *
 * The banner used to show only the absolute moment, which is the right thing to
 * record and the wrong thing to read — it left the person deciding whether to
 * cancel doing subtraction against a wall clock. This says how long they have.
 *
 * `secondsRemaining` is the server's measurement — see lib/settings/
 * reboot-countdown.js for why it is measured there and not computed here.
 *
 * Reaching zero is a handoff, not an end state. `onElapsed` fires once, and the
 * restart curtain takes it from there: this page was rendered by a server that
 * is now going down, so nothing on it can refresh itself back to the truth.
 */
export function RebootCountdown({ secondsRemaining, onElapsed }) {
  const t = useTranslations("settings.maintenance.reboot");
  // Both anchored in a lazy initializer, which is the only place the current
  // time may be read: `Date.now()` during render is impure, and a re-anchoring
  // effect is the cascading-render pattern the lint rules exist to stop.
  //
  // Re-anchoring on a new measurement is the parent's job instead — it keys
  // this component on `seconds_remaining`, so a router.refresh() that returns a
  // different number remounts it and runs these initializers again.
  const [deadline] = useState(() => deadlineFrom(secondsRemaining, Date.now()));
  const [now, setNow] = useState(() => Date.now());

  // What is left is derived, not stored. The only state is the current time,
  // which is what actually changes; keeping a counter in state as well means
  // two things that can disagree, and an effect to sync them.
  const left = remainingSeconds(deadline, now);
  const done = left <= 0;

  useEffect(() => {
    if (done) return;

    const timer = setInterval(() => setNow(Date.now()), 1000);

    return () => clearInterval(timer);
  }, [done]);

  // Handing the restart to the curtain is an external system to notify, which
  // is what an effect is for. The ref makes it once per mount rather than once
  // per render — starting the curtain twice would restart its own clock.
  const handedOff = useRef(false);

  useEffect(() => {
    if (!done || handedOff.current) return;

    handedOff.current = true;
    onElapsed?.();
  }, [done, onElapsed]);

  const { hours, minutes, seconds } = splitRemaining(left);

  // Deliberately not a live region. `polite` sounds like the careful choice
  // and still queues an announcement every second, which is unusable; the
  // absolute time sits next to this and never moves, so a screen reader has
  // the same information in a form that can actually be read.
  return (
    <span className="font-medium tabular-nums">
      {done
        ? t("imminent")
        : hours > 0
          ? t("countdownHours", { hours, minutes })
          : minutes > 0
            ? t("countdownMinutes", { minutes, seconds })
            : t("countdownSeconds", { seconds })}
    </span>
  );
}
