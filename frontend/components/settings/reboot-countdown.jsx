import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import {
  deadlineFrom,
  remainingSeconds,
  splitRemaining,
} from "@/lib/settings/reboot-countdown";

export function RebootCountdown({ secondsRemaining, onElapsed }) {
  const t = useTranslations("settings.maintenance.reboot");
  const [deadline] = useState(() => deadlineFrom(secondsRemaining, Date.now()));
  const [now, setNow] = useState(() => Date.now());

  const left = remainingSeconds(deadline, now);
  const done = left <= 0;

  useEffect(() => {
    if (done) return;

    const timer = setInterval(() => setNow(Date.now()), 1000);

    return () => clearInterval(timer);
  }, [done]);

  const handedOff = useRef(false);

  useEffect(() => {
    if (!done || handedOff.current) return;

    handedOff.current = true;
    onElapsed?.();
  }, [done, onElapsed]);

  const { hours, minutes, seconds } = splitRemaining(left);

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
