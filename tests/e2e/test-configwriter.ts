import { join, resolve } from "node:path"
import * as Effect from "effect/Effect"
import * as FileSystem from "effect/FileSystem"
import { main, script } from "../../scripts/lib/cli.ts"
import * as Command from "../../scripts/lib/command.ts"
import { Assertions } from "../../scripts/lib/wp-env.ts"
const root = resolve(import.meta.dirname, "../..")
export const command = script("test-configwriter", {
  description:
    "Exercise ConfigWriter installation, backup protection and file integrity",
  flags: {},
  run: ({ json, dryRun }) =>
    Effect.scoped(
      Effect.gen(function* () {
        if (dryRun) return
        const fs = yield* FileSystem.FileSystem
        const directory = yield* fs.makeTempDirectoryScoped({
          prefix: "rudel-configwriter-",
        })
        const config = join(directory, "wp-config.php")
        const original = `<?php
define('DB_NAME', 'wordpress');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');

$table_prefix = 'wp_';

define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
`
        yield* fs.writeFileString(config, original)
        const run = (action: string) =>
          Command.run({
            command: "php",
            args: [join(root, "tests/e2e/configwriter.php"), directory, action],
            cwd: root,
          }).pipe(Effect.map((result) => result.stdout))
        const assertions = new Assertions(json)
        yield* assertions.equals(
          "false",
          yield* run("installed"),
          "is_installed() returns false initially"
        )
        yield* run("install")
        const installed = yield* fs.readFileString(config)
        yield* assertions.contains(
          installed,
          "// Rudel environment bootstrap",
          "install() injects marker line"
        )
        yield* assertions.contains(
          installed,
          "require_once",
          "install() injects require_once"
        )
        const lines = installed.split("\n")
        yield* assertions.check(
          lines.findIndex(
            (line) =>
              line.includes("require_once") &&
              line.includes("// Rudel environment bootstrap")
          ) ===
            lines.findIndex(
              (line) =>
                line.includes("wp-settings.php") && !line.includes("Rudel")
            ) -
              1,
          "Bootstrap require immediately precedes wp-settings.php"
        )
        yield* assertions.check(
          !lines.some(
            (line) =>
              line.includes("table_prefix") &&
              line.includes("// Rudel environment bootstrap")
          ),
          "No standalone table prefix fixup line remains"
        )
        yield* assertions.equals(
          "true",
          yield* run("installed"),
          "is_installed() returns true after install"
        )
        const backups = (yield* fs.readDirectory(directory)).filter((file) =>
          file.startsWith("wp-config.php.rudel-backup-")
        )
        yield* assertions.check(backups.length >= 1, "Backup file created")
        if (backups.length > 0) {
          const backup = join(directory, backups[0])
          const contents = yield* fs.readFileString(backup)
          const execution = yield* Command.run({
            command: "php",
            args: [backup],
          })
          yield* assertions.check(
            contents.split("\n")[0].includes("__halt_compiler") &&
              contents.slice(contents.indexOf("\n") + 1) === original &&
              execution.stdout === "",
            "Protected backup preserves original content and rejects execution"
          )
        }
        yield* run("install")
        const twice = yield* fs.readFileString(config)
        yield* assertions.equals(
          installed,
          twice,
          "Second install() is a no-op"
        )
        yield* assertions.check(
          twice.match(/\/\/ Rudel environment bootstrap/g)?.length === 1,
          "Exactly one marker after double install"
        )
        yield* run("uninstall")
        const uninstalled = yield* fs.readFileString(config)
        yield* assertions.excludes(
          uninstalled,
          "// Rudel environment bootstrap",
          "uninstall() removes marker"
        )
        yield* assertions.check(
          !/require_once.*bootstrap/.test(uninstalled),
          "uninstall() removes require_once line"
        )
        yield* assertions.contains(
          uninstalled,
          "define('DB_NAME', 'wordpress')",
          "Original DB_NAME preserved"
        )
        yield* assertions.contains(
          uninstalled,
          "define('DB_USER', 'root')",
          "Original DB_USER preserved"
        )
        yield* assertions.equals(
          "false",
          yield* run("installed"),
          "is_installed() returns false after uninstall"
        )
        yield* run("uninstall")
        yield* assertions.equals(
          uninstalled,
          yield* fs.readFileString(config),
          "Uninstall is a no-op when not installed"
        )
        yield* run("install")
        yield* run("uninstall")
        yield* assertions.equals(
          uninstalled,
          yield* fs.readFileString(config),
          "Install and uninstall preserve file integrity"
        )
        yield* assertions.finish(json)
      })
    ),
})
if (import.meta.main) main(command)
