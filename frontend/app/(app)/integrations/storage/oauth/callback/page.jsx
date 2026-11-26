import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { GoogleDriveCallback } from "@/components/integrations/storage/google-drive-callback";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("storage.oauth");
  return { title: t("callbackTitle") };
}

// This path is registered with Google as the OAuth redirect. Don't move it.
export default async function StorageOauthCallbackPage({ searchParams }) {
  const [permissions, params] = await Promise.all([getPermissions(), searchParams]);

  if (!can(permissions, "storage", "manage")) redirect("/");
  return (
    <div className="flex min-h-[60vh] items-center justify-center py-8">
        <GoogleDriveCallback
          code={typeof params?.code === "string" ? params.code : null}
          state={typeof params?.state === "string" ? params.state : null}
          deniedError={typeof params?.error === "string" ? params.error : null}
        />
    </div>
  );
}
