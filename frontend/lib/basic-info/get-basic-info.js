import { cache } from "react";
import { DEFAULT_PASSWORD_POLICY } from "@/lib/auth/password-rules";

const DEFAULT_BASIC_INFO = {
  registration_open: false,
  app_version: null,
  locales_available: [],
  cookie_auth_enabled: true,
  password_policy: DEFAULT_PASSWORD_POLICY,
};

export const getBasicInfo = cache(async () => {
  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/basic-info`, {
      cache: "no-store",
    });

    if (!res.ok) return DEFAULT_BASIC_INFO;

    const data = await res.json();
    const info = data?.basic_info;
    if (!info) return DEFAULT_BASIC_INFO;

    return { ...DEFAULT_BASIC_INFO, ...info, password_policy: info.password_policy ?? DEFAULT_PASSWORD_POLICY };
  } catch {
    return DEFAULT_BASIC_INFO;
  }
});
