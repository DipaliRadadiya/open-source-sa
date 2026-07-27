import { cache } from "react";

const DEFAULT_BASIC_INFO = {
  registration_open: false,
  app_version: null,
  locales_available: [],
  cookie_auth_enabled: true,
};

export const getBasicInfo = cache(async () => {
  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/basic-info`, {
      cache: "no-store",
    });

    if (!res.ok) return DEFAULT_BASIC_INFO;

    const data = await res.json();
    return data?.basic_info ?? DEFAULT_BASIC_INFO;
  } catch {
    return DEFAULT_BASIC_INFO;
  }
});
