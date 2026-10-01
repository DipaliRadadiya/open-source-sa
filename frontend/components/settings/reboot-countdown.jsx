import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import {
  deadlineFrom,
  remainingSeconds,
  splitRemaining,
} from "@/lib/settings/reboot-countdown";

// `secondsRemaining` is the server's measurement. At zero `onElapsed` fires once and
// the restart curtain takes over.
export function RebootCountdown({ secondsRemaining, onElapsed }) {
  const t = useTranslations("settings.maintenance.reboot");
  // Lazy initializers, since `Date.now()` in render is impure. The parent keys this on
  // `seconds_remaining`, so a new measurement re-anchors.
  const [deadline] = useState(() => deadlineFrom(secondsRemaining, Date.now()));
  const [now, setNow] = useState(() => Date.now());

  // Derived from the current time, not stored as a second counter.
  const left = remainingSeconds(deadline, now);
  const done = left <= 0;

  useEffect(() => {
    if (done) return;

    const timer = setInterval(() => setNow(Date.now()), 1000);

    return () => clearInterval(timer);
  }, [done]);

  // The ref makes the handoff fire once per mount; starting the curtain twice
  // would restart its clock.
  const handedOff = useRef(false);

  useEffect(() => {
    if (!done || handedOff.current) return;

    handedOff.current = true;
    onElapsed?.();
  }, [done, onElapsed]);

  const { hours, minutes, seconds } = splitRemaining(left);

  // Deliberately not a live region: even `polite` announces every second. The
  // absolute time beside it carries the same information for screen readers.
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
