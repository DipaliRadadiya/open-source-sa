import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";

import {
  REGISTRY_PROVIDERS,
  registryProvider,
  providerForAddress,
  issuesTemporaryTokens,
} from "../lib/docker/registry-providers.js";

const LOCALES = readdirSync("messages")
  .filter((f) => f.endsWith(".json"))
  .map((f) => f.replace(".json", ""));
const messages = Object.fromEntries(
  LOCALES.map((l) => [
    l,
    JSON.parse(readFileSync(`messages/${l}.json`, "utf8")),
  ]),
);

/*
 * The registry form was reported as bad UX, and it was: three text boxes and a
 * placeholder, with no statement of which address, which username, or which token
 * scope each provider wants. None of those are guessable and two of them fail in
 * the worst possible way — a credential the registry refuses, or one Docker
 * silently ignores.
 *
 * So these tests are about the instructions being PRESENT and CORRECT, not about
 * rendering. A missing key renders as the key itself, which is worse than no hint.
 */

test("every provider names where to get the token, in every locale", () => {
  for (const locale of LOCALES) {
    const providers = messages[locale].docker.registries.providers;

    for (const { id } of REGISTRY_PROVIDERS) {
      const copy = providers[id];
      assert.ok(copy, `${locale} is missing provider ${id}`);

      for (const key of [
        "label",
        "step1",
        "step2",
        "usernameHint",
        "tokenHint",
      ]) {
        assert.ok(
          typeof copy[key] === "string" && copy[key].trim().length > 0,
          `${locale}.${id}.${key} is empty`,
        );
      }
    }
  }
});

test("the two traps that produce a credential nobody can debug are stated", () => {
  const providers = messages.en.docker.registries.providers;

  // GHCR: a fine-grained token cannot carry read:packages, so it is refused while
  // looking entirely valid. If the form does not say so, the user blames the panel.
  assert.match(providers.ghcr.step1, /read:packages/);
  assert.match(providers.ghcr.step2 + providers.ghcr.tokenHint, /classic/i);

  // GitLab: the username is the deploy token's own, not the account's.
  assert.match(providers.gitlab.step2, /deploy token/i);

  // Docker Hub: the username is the Docker ID, not the sign-in email.
  assert.match(
    providers.dockerhub.step2 + providers.dockerhub.usernameHint,
    /Docker ID/,
  );
});

test("the fixed-address warning explains that a wrong host is ignored, not rejected", () => {
  // The single worst failure this feature has: Docker ignores a credential stored
  // under an address it does not recognise, and the pull then fails exactly as if
  // none existed. Saying "invalid address" would not convey that.
  assert.match(messages.en.docker.registries.registryHintFixed, /ignored/i);
});

test("hosted providers pin their address; self-hosted ones do not", () => {
  assert.equal(registryProvider("dockerhub").address, "docker.io");
  assert.equal(registryProvider("dockerhub").addressFixed, true);
  assert.equal(registryProvider("ghcr").address, "ghcr.io");
  assert.equal(registryProvider("ghcr").addressFixed, true);

  // GitLab and self-hosted must stay editable: a self-managed GitLab has its own
  // registry host, and pinning it would make the provider unusable.
  assert.equal(registryProvider("gitlab").addressFixed, false);
  assert.equal(registryProvider("other").addressFixed, false);
});

test("an unknown provider id falls back rather than crashing the form", () => {
  assert.equal(registryProvider("nope").id, "dockerhub");
  assert.equal(registryProvider(undefined).id, "dockerhub");
});

test("editing a saved row opens on the provider its address looks like", () => {
  // Otherwise the form defaults to Docker Hub and shows somebody with a GHCR
  // credential the wrong instructions entirely.
  assert.equal(providerForAddress("ghcr.io"), "ghcr");
  assert.equal(providerForAddress("https://ghcr.io/"), "ghcr");
  assert.equal(providerForAddress("docker.io"), "dockerhub");
  assert.equal(providerForAddress("index.docker.io"), "dockerhub");
  assert.equal(providerForAddress("registry.gitlab.com"), "gitlab");
  assert.equal(providerForAddress("registry.example.com:5000"), "other");
  assert.equal(providerForAddress(""), "other");
  assert.equal(providerForAddress(null), "other");
});

test("registries that issue short-lived tokens are recognised", () => {
  // ECR's credentials last twelve hours. A token pasted here authenticates once
  // and then fails, and the panel does not refresh it — so the form has to say so
  // rather than let it be discovered on tomorrow's deploy.
  assert.equal(
    issuesTemporaryTokens("123456789012.dkr.ecr.us-east-1.amazonaws.com"),
    true,
  );
  assert.equal(issuesTemporaryTokens("europe-west1-docker.pkg.dev"), true);
  assert.equal(issuesTemporaryTokens("gcr.io"), true);
  assert.equal(issuesTemporaryTokens("myorg.azurecr.io"), true);

  // And the ordinary ones are not, or the warning becomes noise everybody learns
  // to scroll past.
  assert.equal(issuesTemporaryTokens("docker.io"), false);
  assert.equal(issuesTemporaryTokens("ghcr.io"), false);
  assert.equal(issuesTemporaryTokens("registry.gitlab.com"), false);
  assert.equal(issuesTemporaryTokens(""), false);
});

test("the warning says the panel will not refresh the token", () => {
  // The actionable half. "These expire" invites waiting; "we do not refresh them"
  // tells you the feature will not rescue you.
  assert.match(
    messages.en.docker.registries.temporaryToken,
    /does not refresh|not refresh/i,
  );
});

test("the empty state says what to have ready and that public images need none", () => {
  const registries = messages.en.docker.registries;

  assert.match(registries.emptyBody, /access token/i);
  assert.match(registries.emptyBody, /not your account password/i);
  // The fact that stops somebody configuring a credential they never needed.
  assert.match(registries.emptyBody, /public images/i);
});

test("the card says what to do after saving one", () => {
  // It was previously possible to add a credential and have no idea what happened
  // next. The hint now names where it gets chosen.
  assert.match(
    messages.en.docker.registries.hint,
    /Container settings|create form/i,
  );
});
