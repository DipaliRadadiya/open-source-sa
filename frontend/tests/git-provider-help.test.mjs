import { test } from "node:test";
import assert from "node:assert/strict";
import { providerFieldSchema, providersResponseSchema } from "../lib/schemas/git.js";

// The backend writes and localizes per-field help — what a self-hosted GitLab
// URL should contain, where a Bitbucket workspace id comes from. The schema
// used to be a strict object without `help`, so Zod dropped it and both fields
// rendered with no explanation while the text sat in the response.
const gitlabHost = {
  name: "host",
  label: "Self-hosted URL",
  required: false,
  type: "text",
  help: "Only for self-hosted GitLab — the base URL of your instance, for example https://git.example.com. Leave empty for gitlab.com.",
};

test("per-field help survives parsing", () => {
  const parsed = providerFieldSchema.safeParse(gitlabHost);
  assert.equal(parsed.success, true);
  assert.match(parsed.data.help, /self-hosted GitLab/);
});

test("a field without help still parses, and does not invent one", () => {
  const parsed = providerFieldSchema.safeParse({ name: "token", label: "Access token" });
  assert.equal(parsed.success, true);
  // Rendering falls back to the provider-level token_help; a placeholder string
  // here would put copy in front of the reader that nobody wrote.
  assert.equal(parsed.data.help ?? null, null);
});

test("help reaches the form through the full providers response", () => {
  const parsed = providersResponseSchema.safeParse({
    providers: [
      {
        name: "gitlab",
        title: "GitLab",
        token_help: "A personal access token with the read_repository scope.",
        fields: [gitlabHost, { name: "token", label: "Access token", required: true, type: "password" }],
      },
    ],
  });

  assert.equal(parsed.success, true);
  const [provider] = parsed.data.providers;
  assert.match(provider.fields[0].help, /Leave empty for gitlab\.com/);
  assert.equal(provider.fields[1].help ?? null, null);
});

test("a null help is accepted — the backend sends null, not an omitted key", () => {
  const parsed = providerFieldSchema.safeParse({ ...gitlabHost, help: null });
  assert.equal(parsed.success, true);
  assert.equal(parsed.data.help, null);
});
