import { PhpPanel } from "@/components/applications/php/php-panel";
import { PHP_STATES } from "../fixtures";
import { PreviewFrame } from "../preview-frame";

export const dynamic = "force-dynamic";

export const metadata = { title: "PHP settings — UI preview" };

export default async function PhpPreviewPage({ searchParams }) {
  const { state } = await searchParams;
  const key = state in PHP_STATES ? state : "isolated";

  return (
    <PreviewFrame title="PHP settings" states={PHP_STATES} current={key} base="/ui-preview/php">
      <PhpPanel
        appId="1"
        php={PHP_STATES[key].data}
        timezones={["Etc/UTC", "Europe/London", "Asia/Kolkata", "America/New_York"]}
        canManage
      />
    </PreviewFrame>
  );
}
