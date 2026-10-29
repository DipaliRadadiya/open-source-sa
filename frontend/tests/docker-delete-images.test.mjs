import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const dialog = read("components/applications/delete-application-dialog.jsx");

// db770e5a (Dipali, 29 Oct): DELETE /applications/{id} takes remove_docker_images on a Docker site.
test("a Docker site's delete offers to remove its images, on by default, to docker managers only", () => {
  assert.match(dialog, /const \[removeDockerImages, setRemoveDockerImages\] = useState\(true\);/);
  assert.match(dialog, /const isDocker = application\?\.serving_profile === "docker";/);
  assert.match(dialog, /\{canManageDocker && isDocker \? \(/);
  assert.match(dialog, /id="delete-app-docker-images"/);
  // Sent both ways to a docker manager; omitted otherwise, so the API keeps them (true would be a 403).
  assert.match(dialog, /removeDockerImages: canManageDocker && isDocker \? removeDockerImages : null,/);
  assert.match(read("lib/api/applications.js"), /if \(removeDockerImages !== null\) params\.remove_docker_images = removeDockerImages;/);
  // On again when the dialog closes.
  assert.match(dialog, /setRemoveDockerImages\(true\);/);
});

test("the network-and-volumes box is gated the same way: without docker manage it was a 403", () => {
  assert.match(dialog, /\{canManageDocker && dockerResourceNames\.length \? \(/);
  assert.match(dialog, /canManageDocker && dockerResourceNames\.length > 0 && removeDockerResources,/);
  for (const page of ["app/(app)/applications/page.jsx", "app/(app)/applications/[application]/page.jsx"]) {
    assert.match(read(page), /canManageDocker=\{can\(permissions, "docker", "manage"\)\}/, page);
  }
  assert.match(read("components/applications/application-row-actions.jsx"), /canManageDocker=\{canManageDocker\}/);
  assert.match(read("components/applications/applications-table.jsx"), /canManageDocker=\{table\.options\.meta\?\.canManageDocker \?\? false\}/);
  assert.match(read("components/applications/applications-cards.jsx"), /canManageDocker=\{canManageDocker\}/);
});

test("the label and hint are in all 8 languages, with Dipali's English", () => {
  for (const locale of LOCALES) {
    const d = JSON.parse(read(`messages/${locale}.json`)).applications.delete;
    assert.ok(d.removeDockerImages && d.removeDockerImagesHint, locale);
  }
  const en = JSON.parse(read("messages/en.json")).applications.delete;
  assert.equal(en.removeDockerImages, "Also remove its Docker images");
  assert.equal(en.removeDockerImagesHint, "Only images no other app uses are removed. They are downloaded again if needed.");
});
