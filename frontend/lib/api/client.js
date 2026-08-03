import axios from "axios";

// All backend routes are under /api, so bake the prefix into baseURL —
// endpoint calls stay clean (api.post("/login") -> .../api/login).
export const api = axios.create({
  baseURL: `${process.env.NEXT_PUBLIC_API_URL}/api`,
  withCredentials: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
  // Since Axios 1.6, the XSRF header is only auto-attached to same-origin
  // requests unless this is set. Our API is a different origin (Sanctum SPA),
  // so this is required or every mutation fails CSRF (419).
  withXSRFToken: true,
});

// Stamp the user's chosen locale (NEXT_LOCALE cookie) on every request so the
// backend localizes validation/error/success messages to match the UI. Falls
// back to the browser's own Accept-Language when the cookie isn't set.
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

    // 419 = the XSRF token expired with the Laravel session. It was only ever
    // fetched at login, so every mutation started failing a couple of hours in
    // and the user's only escape was to sign out and back in. Fetch a fresh
    // token and replay the request once — `_csrfRetried` makes it exactly once,
    // so a genuinely rejected token surfaces instead of looping.
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
