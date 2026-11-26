import axios from "axios";
import { isRestartBlip, RESTART_RETRY_MS } from "./restart-blip.js";

export const api = axios.create({
  baseURL: `${process.env.NEXT_PUBLIC_API_URL}/api`,
  withCredentials: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
  // Needed for cross-origin Sanctum, otherwise writes fail with 419.
  withXSRFToken: true,
});

// Send the UI locale so backend messages come back in the same language.
api.interceptors.request.use((config) => {
  if (typeof document !== "undefined") {
    const match = document.cookie.match(/(?:^|;\s*)NEXT_LOCALE=([^;]+)/);
    if (match) {
      config.headers["Accept-Language"] = decodeURIComponent(match[1]);
    }
  }
  return config;
});

// The CSRF cookie endpoint is not under /api, so it can't use the baseURL.
function refreshCsrfCookie() {
  return axios.get(`${process.env.NEXT_PUBLIC_API_URL}/sanctum/csrf-cookie`, {
    withCredentials: true,
  });
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status;
    const config = error.config;

    // 419 = expired XSRF token. Refresh it and retry once.
    if (status === 419 && config && !config._csrfRetried && typeof window !== "undefined") {
      config._csrfRetried = true;
      try {
        await refreshCsrfCookie();
        return api(config);
      } catch {
        // couldn't renew; report the original error
      }
    }

    if (config?.retryRestart && !config._restartRetried && isRestartBlip(error)) {
      config._restartRetried = true;
      await new Promise((resolve) => setTimeout(resolve, RESTART_RETRY_MS));
      return api(config);
    }

    if (status === 401 && typeof window !== "undefined") {
      window.location.href = "/login";
    }
    return Promise.reject(error);
  }
);
