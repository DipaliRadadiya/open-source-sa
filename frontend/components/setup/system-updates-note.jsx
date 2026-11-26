import { useTranslations } from "next-intl";
import { Note } from "@/components/ui/note";

export function SystemUpdatesNote({ running, className }) {
  const t = useTranslations("setup");
  if (!running) return null;
  return (
    <div role="status" data-testid="system-updates-note" className={className}>
      <Note>{t("systemUpdatesRunning")}</Note>
    </div>
  );
}
