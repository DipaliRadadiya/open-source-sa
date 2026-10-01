import axios from "axios";

// All backend routes are under /api, so bake the prefix into baseURL —
// endpoint calls stay clean (api.post("/login") -> .../api/login).
export const api = axios.create({
  baseURL: `${process.env.NEXT_PUBLIC_API_URL}/api`,
  withCredentials: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
  // Axios >= 1.6 only attaches the XSRF header same-origin unless this is set;
  // the API is a different origin (Sanctum SPA), so without it mutations 419.
  withXSRFToken: true,
});

// Send the UI locale (NEXT_LOCALE cookie) so backend messages match; otherwise
// the browser's own Accept-Language applies.
api.interceptors.request.use((config) => {
  if (typeof document !== "undefined") {
    const match = document.cookie.match(/(?:^|;\s*)NEXT_LOCALE=([^;]+)/);
    if (match) {
      config.headers["Accept-Language"] = decodeURIComponent(match[1]);
    }
  }
  return config;
});

// The CSRF-cookie endpoint sits at the root, NOT under /api, so it can't go
// through this instance's baseURL.
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

    // 419 = expired XSRF token: fetch a fresh one and replay exactly once
    // (`_csrfRetried`), so a genuinely rejected token surfaces instead of looping.
    if (status === 419 && config && !config._csrfRetried && typeof window !== "undefined") {
      config._csrfRetried = true;
      try {
        await refreshCsrfCookie();
        return api(config);
      } catch {
        // Couldn't renew — fall through and report the original failure.
      }
    }

    if (status === 401 && typeof window !== "undefined") {
      window.location.href = "/login";
    }
    return Promise.reject(error);
  }
);
