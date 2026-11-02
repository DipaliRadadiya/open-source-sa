import { useTranslations } from "next-intl";
import { Note } from "@/components/ui/note";

// Ubuntu's first-boot updates hold apt, so anything installed meanwhile waits for them.
export function SystemUpdatesNote({ running, className }) {
  const t = useTranslations("setup");
  if (!running) return null;
  return (
    <div role="status" data-testid="system-updates-note" className={className}>
      <Note>{t("systemUpdatesRunning")}</Note>
    </div>
  );
}
