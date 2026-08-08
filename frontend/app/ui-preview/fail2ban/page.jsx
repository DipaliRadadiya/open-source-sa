import { Fail2banPanel } from "@/components/applications/fail2ban/fail2ban-panel";
import { FAIL2BAN_STATES } from "../fixtures";
import { PreviewFrame } from "../preview-frame";

export const dynamic = "force-dynamic";

export const metadata = { title: "Attack protection — UI preview" };

export default async function Fail2banPreviewPage({ searchParams }) {
  const { state } = await searchParams;
  const key = state in FAIL2BAN_STATES ? state : "quiet";
  const data = FAIL2BAN_STATES[key].data;

  return (
    <PreviewFrame
      title="Attack protection"
      states={FAIL2BAN_STATES}
      current={key}
      base="/ui-preview/fail2ban"
    >
      <Fail2banPanel
        appId="1"
        enabled={data.enabled}
        jails={data.jails}
        viewerIp={data.viewerIp}
        canManage
      />
    </PreviewFrame>
  );
}
