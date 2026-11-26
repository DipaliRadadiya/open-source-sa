import { apiMessage } from "../api/error-message.js";

export async function failedRead(res) {
  let data = null;
  try {
    data = await res.json();
  } catch {
    // not JSON
  }
  return { status: "failed", log: null, message: apiMessage({ response: { data } }, null) || null };
}
