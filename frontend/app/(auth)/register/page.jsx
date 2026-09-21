import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";

export const dynamic = "force-dynamic";

import Link from "next/link";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getBasicInfo } from "@/lib/basic-info/get-basic-info";
import { PanelUnavailableCard } from "@/components/sections/panel-unavailable";
import { isPanelUnavailable } from "@/lib/api/unavailable";
import { RateLimitedCard } from "@/components/sections/rate-limited";
import { isRateLimited } from "@/lib/api/rate-limited";
import { RequestFailedCard } from "@/components/sections/request-failed";
import { isRequestFailed, requestFailureProps } from "@/lib/api/request-failed";
import { RegisterForm } from "@/components/forms/register-form";
import { Logo } from "@/components/logo";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";

export default async function RegisterPage() {
  let user, basicInfo, t;
  try {
    [user, basicInfo, t] = await Promise.all([
      getCurrentUser(),
      getBasicInfo(),
      getTranslations("auth"),
    ]);
  } catch (error) {
    // 429 too: the login page is the easiest place in the panel to hit the
    // rate limit (a reload loop while the API is unhappy), and it was the one
    // screen that still answered it with a digest.
    if (isRateLimited(error)) return <RateLimitedCard />;
    if (isPanelUnavailable(error)) return <PanelUnavailableCard />;
    if (isRequestFailed(error)) return <RequestFailedCard {...requestFailureProps(error)} />;
    throw error;
  }

  if (user) redirect("/");
  // Registration is bootstrap-only: once the admin exists the backend closes it.
  if (!basicInfo.registration_open) redirect("/login");

  return (
    <Card className="w-full gap-0 shadow-xl shadow-black/5">
      <CardHeader className="flex flex-col items-center gap-2 pb-6 text-center">
        <Logo className="mb-2 h-9 w-auto" />
        <CardTitle className="text-xl">{t("registerTitle")}</CardTitle>
        <CardDescription>{t("registerSubtitle")}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-6">
        <RegisterForm policy={basicInfo.password_policy} />
        <p className="text-center text-sm text-muted-foreground">
          {t("haveAccount")}{" "}
          <Link href="/login" className="font-medium text-primary hover:underline">
            {t("loginLink")}
          </Link>
        </p>
      </CardContent>
    </Card>
  );
}
