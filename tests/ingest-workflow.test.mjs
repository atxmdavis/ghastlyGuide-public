import assert from "node:assert/strict";
import { readFile, mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";
import test from "node:test";

test("source failures and failed calendar downloads do not block later imports", async (t) => {
  const directory = await mkdtemp(path.join(tmpdir(), "ghastly-workflow-"));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const environment = { ...process.env, RUNNER_TEMP: directory, GITHUB_RUN_ID: "fixture", GITHUB_RUN_ATTEMPT: "1" };
  const workflow = await readFile(new URL("../docs/workflow-examples/ingest-production.yml", import.meta.url), "utf8");
  const script = workflow.slice(workflow.indexOf('          sources=')).split("\n").map(line => line.replace(/^ {10}/, "")).join("\n");
  const mock = `ssh() {
    local command="\${@: -1}"
    echo "$command"
    if [[ "$command" == *"--source=tvmaze" && "$SIMULATE_FAILURE" == "yes" ]]; then return 255; fi
    return 0
  }
  curl() { if [[ "$SIMULATE_FETCH_FAILURE" == "yes" ]]; then return 22; fi; return 0; }
  scp() { return 0; }\n`;
  for (const simulateFailure of ["yes", "no"]) {
    const result = spawnSync("bash", ["-e", "-c", mock + script], {
      encoding: "utf8",
      env: { ...environment, SOURCE: "", DREAMHOST_PRIVATE_PATH: "/fixture/private", SIMULATE_FAILURE: simulateFailure },
    });
    assert.equal(result.status, simulateFailure === "yes" ? 1 : 0, result.stderr);
    const sources = [...result.stdout.matchAll(/--source=([\w-]+)/g)].map(match => match[1]);
    assert.deepEqual(sources, ["tmdb", "igdb", "rawg", "asmodee", "lunar", "prh", "tvmaze", "ratings", "availability"]);
    assert.match(result.stdout, /GHASTLY_ASMODEE_CALENDAR_FILE=.*--source=asmodee/);
  }
  const failedDownload = spawnSync("bash", ["-e", "-c", mock + script], {
    encoding: "utf8", env: { ...environment, SOURCE: "", DREAMHOST_PRIVATE_PATH: "/fixture/private", SIMULATE_FETCH_FAILURE: "yes" },
  });
  assert.equal(failedDownload.status, 1, failedDownload.stderr);
  assert.deepEqual([...failedDownload.stdout.matchAll(/--source=([\w-]+)/g)].map(match => match[1]), ["tmdb", "igdb", "rawg", "lunar", "prh", "tvmaze", "ratings", "availability"]);
  const manual = spawnSync("bash", ["-e", "-c", mock + script], {
    encoding: "utf8", env: { ...environment, SOURCE: "ratings", DREAMHOST_PRIVATE_PATH: "/fixture/private" },
  });
  assert.equal(manual.status, 0, manual.stderr);
  assert.deepEqual([...manual.stdout.matchAll(/--source=([\w-]+)/g)].map(match => match[1]), ["ratings"]);
});
