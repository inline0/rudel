import assert from "node:assert/strict"
import { mkdir, mkdtemp, rm, writeFile } from "node:fs/promises"
import { tmpdir } from "node:os"
import { basename, join, resolve } from "node:path"
import test from "node:test"
import * as NodeServices from "@effect/platform-node/NodeServices"
import * as Effect from "effect/Effect"
import * as Command from "./command.ts"
import { wordpress, withWordPress, WordPressInstallation } from "./wp-env.ts"

const root = resolve(import.meta.dirname, "../..")
const empty = { exitCode: 0, stdout: "", stderr: "" }
const installationAt = (directory: string) => ({
  workDirectoryPath: directory,
  dockerComposeConfigPath: join(directory, "docker-compose.yml"),
})
const installationLayer = (directory: string) =>
  Effect.provideService(WordPressInstallation, {
    resolve: () => Effect.succeed(installationAt(directory)),
  })

test("destroy is idempotent for a fresh home and a cache directory without Compose state", async () => {
  const home = await mkdtemp(join(tmpdir(), "rudel-fresh-home-"))
  const directory = join(home, "project-hash")
  const calls: Array<Command.CommandCall> = []
  const program = wordpress(root)
    .destroy()
    .pipe(
      Effect.provide(NodeServices.layer),
      installationLayer(directory),
      Effect.provideService(Command.Command, {
        run: (call) => {
          calls.push(call)
          return Effect.succeed(empty)
        },
      })
    )
  try {
    assert.deepEqual(await Effect.runPromise(program), empty)
    await mkdir(directory)
    await writeFile(join(directory, "partial-download"), "")
    assert.deepEqual(await Effect.runPromise(program), empty)
    assert.deepEqual(
      calls.map((call) => [call.command, call.args]),
      [0, 1].map(() => [
        "docker",
        [
          "ps",
          "--all",
          "--filter",
          `label=com.docker.compose.project=${basename(directory)}`,
          "--format",
          "{{.ID}}",
        ],
      ])
    )
    assert.ok(calls.every((call) => call.mutates === false))
  } finally {
    await rm(home, { recursive: true, force: true })
  }
})

test("missing Compose state with existing project containers is a visible teardown failure", async () => {
  const home = await mkdtemp(join(tmpdir(), "rudel-orphan-state-"))
  try {
    const program = wordpress(root)
      .destroy()
      .pipe(
        Effect.provide(NodeServices.layer),
        installationLayer(home),
        Effect.provideService(Command.Command, {
          run: () =>
            Effect.succeed({ ...empty, stdout: "existing-container\n" }),
        })
      )
    await assert.rejects(
      Effect.runPromise(program),
      /is missing but project containers still exist/
    )
  } finally {
    await rm(home, { recursive: true, force: true })
  }
})

test("an existing Compose installation is confirmed and its destroy failures propagate", async () => {
  const directory = await mkdtemp(join(tmpdir(), "rudel-compose-state-"))
  const calls: Array<Command.CommandCall> = []
  try {
    await writeFile(join(directory, "docker-compose.yml"), "services: {}\n")
    const program = wordpress(root)
      .destroy()
      .pipe(Effect.provide(NodeServices.layer), installationLayer(directory))
    await Effect.runPromise(
      program.pipe(
        Effect.provideService(Command.Command, {
          run: (call) => {
            calls.push(call)
            return Effect.succeed(empty)
          },
        })
      )
    )
    assert.equal(calls.length, 1)
    assert.equal(calls[0].command, "pnpm")
    assert.deepEqual(calls[0].args, ["exec", "wp-env", "destroy"])
    assert.equal(calls[0].stdin, "y\n")
    assert.notEqual(calls[0].check, false)
    await assert.rejects(
      Effect.runPromise(
        program.pipe(
          Effect.provideService(Command.Command, {
            run: () =>
              Effect.fail(
                new Command.CommandError({
                  message: "Compose teardown failed",
                  exitCode: 1,
                })
              ),
          })
        )
      ),
      /Compose teardown failed/
    )
  } finally {
    await rm(directory, { recursive: true, force: true })
  }
})

test("a failed scoped teardown remains visible after the fallback stop", async () => {
  const directory = await mkdtemp(join(tmpdir(), "rudel-cleanup-state-"))
  const calls: Array<Command.CommandCall> = []
  try {
    await writeFile(join(directory, "docker-compose.yml"), "services: {}\n")
    const program = withWordPress(root, "destroy", () => Effect.void).pipe(
      Effect.provide(NodeServices.layer),
      installationLayer(directory),
      Effect.provideService(Command.Command, {
        run: (call) => {
          calls.push(call)
          return call.args?.includes("destroy")
            ? Effect.fail(
                new Command.CommandError({
                  message: "Compose teardown failed",
                  exitCode: 1,
                })
              )
            : Effect.succeed(empty)
        },
      })
    )
    await assert.rejects(Effect.runPromise(program), /Compose teardown failed/)
    assert.deepEqual(calls.at(-1)?.args, ["exec", "wp-env", "stop"])
  } finally {
    await rm(directory, { recursive: true, force: true })
  }
})
