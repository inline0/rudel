import { basename, join, resolve } from "node:path"
import * as Console from "effect/Console"
import * as Effect from "effect/Effect"
import * as FileSystem from "effect/FileSystem"
import { fail, main, script } from "../../scripts/lib/cli.ts"
import * as Command from "../../scripts/lib/command.ts"
import {
  Assertions,
  fetchPage,
  shellQuote,
  withWordPress,
} from "../../scripts/lib/wp-env.ts"

const root = resolve(import.meta.dirname, "../..")
export const jsonField = (source: string, key: string): string => {
  let data: unknown = JSON.parse(source)
  if (Array.isArray(data) && data.length === 1) data = data[0]
  const value =
    data !== null && typeof data === "object"
      ? (data as Record<string, unknown>)[key]
      : undefined
  return value === undefined || value === null
    ? ""
    : typeof value === "object"
      ? JSON.stringify(value)
      : String(value)
}
export const createdId = (output: string) =>
  output.match(/Sandbox created: ([^\s]+)/)?.[1] ?? ""
const lastLine = (output: string) => output.trim().split("\n").at(-1) ?? ""

export const command = script("test-wp-env", {
  description:
    "Exercise request-selected WordPress overlays, Git previews, snapshots and isolation",
  flags: {},
  run: ({ json }) =>
    withWordPress(
      root,
      "destroy",
      (site) =>
        Effect.gen(function* () {
          const fs = yield* FileSystem.FileSystem
          const temporary = yield* fs.makeTempDirectoryScoped({
            prefix: "rudel-e2e-",
          })
          const sandboxIds = new Set<string>()
          yield* Effect.addFinalizer(() =>
            Effect.forEach(
              [...sandboxIds],
              (id) =>
                site
                  .attempt("rudel", "destroy", id, "--force")
                  .pipe(Effect.ignore),
              { discard: true }
            )
          )
          const assertions = new Assertions(json)
          const check = (condition: unknown, label: string, detail = "") =>
            assertions
              .check(condition, label, detail)
              .pipe(Effect.andThen(condition ? Effect.void : fail(label)))
          const config = JSON.parse(
            yield* fs.readFileString(join(root, ".wp-env.json"))
          ) as { port?: number }
          const host = `http://localhost:${config.port ?? 8000}`
          const request = (id = "", cookie = false) =>
            fetchPage(
              `${host}/`,
              id
                ? {
                    headers: cookie
                      ? { Cookie: `rudel_environment=${id}` }
                      : { "X-Rudel-Environment": id },
                  }
                : {}
            )
          const info = (id: string, key: string) =>
            site
              .wp("rudel", "info", id, "--format=json")
              .pipe(Effect.map((output) => jsonField(output, key)))
          const option = (id: string) =>
            site
              .selected(id, "option", "get", "blogname")
              .pipe(Effect.map(lastLine))
          const tableExists = (table: string) =>
            site
              .wp(
                "db",
                "query",
                `SHOW TABLES LIKE '${table}'`,
                "--skip-column-names"
              )
              .pipe(Effect.map((output) => output.split("\n").includes(table)))
          const create = (...args: ReadonlyArray<string>) =>
            Effect.gen(function* () {
              const output = yield* site.wp("rudel", "create", ...args)
              const id = createdId(output)
              yield* check(id !== "", "Sandbox created", output)
              sandboxIds.add(id)
              return id
            })
          const destroy = (id: string) =>
            site
              .wp("rudel", "destroy", id, "--force")
              .pipe(Effect.tap(() => Effect.sync(() => sandboxIds.delete(id))))
          yield* site.destroy()
          for (let attempt = 1; attempt <= 3; attempt++) {
            const result = yield* site.raw(["start"], false)
            if (result.exitCode === 0) break
            if (attempt === 3)
              return yield* fail(
                `wp-env start failed after 3 attempts: ${result.stderr}`
              )
            yield* Console.error(
              `wp-env start failed on attempt ${attempt}; retrying...`
            )
            yield* site.destroy()
            const installation = yield* site.installation()
            yield* fs.remove(installation.workDirectoryPath, {
              recursive: true,
              force: true,
            })
            yield* Effect.sleep("2 seconds")
          }
          yield* site.shell(
            String.raw`perl -0pi -e "s/^define\( 'WP_SITEURL'.*\n//mg; s/^define\( 'WP_HOME'.*\n//mg; s/^define\( 'WP_ALLOW_MULTISITE'.*\n//mg; s/^define\( 'MULTISITE'.*\n//mg; s/^define\( 'SUBDOMAIN_INSTALL'.*\n//mg; s/^\$base = '\/'.*\n//mg; s/^define\( 'DOMAIN_CURRENT_SITE'.*\n//mg; s/^define\( 'PATH_CURRENT_SITE'.*\n//mg; s/^define\( 'SITE_ID_CURRENT_SITE'.*\n//mg; s/^define\( 'BLOG_ID_CURRENT_SITE'.*\n//mg; s/^if \( ! defined\( 'RUDEL_WP_CONFIG_PATH'.*\n//mg" /var/www/html/wp-config.php`
          )
          yield* site.wp("db", "reset", "--yes")
          yield* site.wp(
            "core",
            "install",
            `--url=${host}`,
            "--title=Rudel Host",
            "--admin_user=admin",
            "--admin_password=password",
            "--admin_email=admin@example.test",
            "--skip-email"
          )
          yield* site.wp("plugin", "activate", "rudel")
          yield* site.shell(`mkdir -p /var/www/html/wp-content/themes/rudel-host-theme /var/www/html/wp-content/plugins/shared-demo /var/www/html/wp-content/uploads/2026/06
cat > /var/www/html/wp-content/themes/rudel-host-theme/style.css <<'CSS'
/*
Theme Name: Rudel Host Theme
*/
CSS
cat > /var/www/html/wp-content/themes/rudel-host-theme/index.php <<'PHP'
<!doctype html><html><body><h1 id="site-name"><?php bloginfo( 'name' ); ?></h1><div id="theme-marker">rudel-host-theme</div></body></html>
PHP
cat > /var/www/html/wp-content/plugins/shared-demo/shared-demo.php <<'PHP'
<?php
/* Plugin Name: Shared Demo */
PHP
printf 'shared upload' > /var/www/html/wp-content/uploads/2026/06/shared.txt`)
          yield* site.wp("theme", "activate", "rudel-host-theme")
          yield* check(
            (yield* site.attempt("core", "is-installed")).exitCode === 0,
            "WordPress is installed"
          )
          yield* check(
            (yield* site.attempt("plugin", "is-active", "rudel")).exitCode ===
              0,
            "Rudel plugin is active"
          )
          const status = yield* site.wp("rudel", "status")
          yield* check(
            status.includes("Bootstrap installed") && status.includes("yes"),
            "Runtime bootstrap is installed",
            status
          )
          yield* site.wp(
            "eval",
            "(new \\Rudel\\ConfigWriter())->protect_backups();"
          )
          const backup = lastLine(
            yield* site.wp(
              "eval",
              'echo basename(glob(ABSPATH . "wp-config.php.rudel-backup-*.php")[0]);'
            )
          )
          yield* check(
            [403, 404].includes((yield* fetchPage(`${host}/${backup}`)).status),
            "WordPress configuration backups reject public HTTP access"
          )
          const hostPage = yield* request()
          yield* check(
            hostPage.status === 200 &&
              hostPage.body.includes("Rudel Host") &&
              hostPage.body.includes("rudel-host-theme"),
            "Host site responds without environment selection",
            hostPage.body
          )
          const alpha = yield* create(
            "--name=alpha",
            "--theme=rudel-host-theme"
          )
          const beta = yield* create("--name=beta", "--theme=rudel-host-theme")
          yield* check(alpha !== beta, "Sibling sandbox identifiers differ")
          const alphaPath = yield* info(alpha, "path")
          const alphaPrefix = yield* info(alpha, "table_prefix")
          yield* site.shell(
            `test -d ${shellQuote(`${alphaPath}/themes/rudel-host-theme`)} && test -f ${shellQuote(`${alphaPath}/themes/rudel-host-theme/style.css`)}`
          )
          yield* check(true, "Sandbox has its copied active theme")
          yield* check(
            (yield* info(alpha, "theme_slug")) === "rudel-host-theme" &&
              /^wp_.*_$/.test(alphaPrefix),
            "Sandbox info reports overlay theme and generated table prefix"
          )
          const registryTables = yield* site.wp(
            "db",
            "query",
            `SHOW TABLES LIKE '${alphaPrefix}rudel_%'`,
            "--skip-column-names"
          )
          yield* check(
            (yield* tableExists(`${alphaPrefix}options`)) &&
              !registryTables.includes("rudel"),
            "Sandbox clones WordPress tables without Rudel registry tables"
          )
          yield* site.shell(
            `test ! -d ${shellQuote(`${alphaPath}/plugins`)} && test ! -d ${shellQuote(`${alphaPath}/uploads`)}`
          )
          yield* check(
            true,
            "Sandbox does not create isolated plugin or upload directories"
          )
          yield* site.selected(
            alpha,
            "option",
            "update",
            "blogname",
            "Alpha Site"
          )
          yield* site.selected(
            beta,
            "option",
            "update",
            "blogname",
            "Beta Site"
          )
          yield* check(
            (yield* option(alpha)) === "Alpha Site" &&
              (yield* option(beta)) === "Beta Site" &&
              lastLine(yield* site.wp("option", "get", "blogname")) ===
                "Rudel Host",
            "CLI environment selection isolates option state"
          )
          const directoryName = lastLine(
            yield* site.shell(
              `cd ${shellQuote(alphaPath)} && wp option get blogname`
            )
          )
          const explicitName = lastLine(
            yield* site.shell(
              `cd ${shellQuote(alphaPath)} && RUDEL_ENVIRONMENT=${shellQuote(beta)} wp option get blogname`
            )
          )
          yield* check(
            directoryName === "Alpha Site" && explicitName === "Beta Site",
            "Generated WP-CLI config selects its DB and preserves an explicit selector"
          )
          const alphaPage = yield* request(alpha)
          yield* check(
            alphaPage.status === 200 &&
              alphaPage.body.includes("Alpha Site") &&
              alphaPage.body.includes("rudel-host-theme"),
            "Header-selected sandbox renders DB state and copied theme",
            alphaPage.body
          )
          const betaPage = yield* request(beta, true)
          yield* check(
            betaPage.status === 200 && betaPage.body.includes("Beta Site"),
            "Cookie-selected sandbox renders its own DB state",
            betaPage.body
          )
          yield* site.selected(alpha, "plugin", "activate", "shared-demo")
          yield* site.selected(alpha, "plugin", "is-active", "shared-demo")
          yield* check(
            (yield* site.attempt("plugin", "is-active", "shared-demo"))
              .exitCode !== 0 &&
              (yield* site
                .selected(beta, "plugin", "is-active", "shared-demo")
                .pipe(
                  Effect.as(false),
                  Effect.orElseSucceed(() => true)
                )),
            "Plugin activation state is isolated while files are shared"
          )
          yield* site.shell(
            "test -f /var/www/html/wp-content/plugins/shared-demo/shared-demo.php && test -f /var/www/html/wp-content/uploads/2026/06/shared.txt"
          )
          yield* check(true, "Host plugin and upload files remain shared")
          const gitFixtures = yield* fs.makeTempDirectoryScoped({
            directory: join(root, "tests/e2e"),
            prefix: "git-fixtures.",
          })
          const git = (...args: ReadonlyArray<string>) =>
            Command.run({ command: "git", args, cwd: root })
          for (const name of ["git-theme", "shared-demo"]) {
            const source = join(gitFixtures, `${name}-source`)
            yield* fs.makeDirectory(source)
            yield* git("-C", source, "init", "-q", "--initial-branch=main")
            yield* git("-C", source, "config", "user.name", "Test")
            yield* git(
              "-C",
              source,
              "config",
              "user.email",
              "test@example.test"
            )
            if (name === "git-theme") {
              yield* fs.writeFileString(
                join(source, "style.css"),
                "/*\nTheme Name: Git Theme\n*/\n"
              )
              yield* fs.writeFileString(
                join(source, "index.php"),
                '<?php echo "GitThemeMarker";\n'
              )
            } else {
              yield* fs.writeFileString(
                join(source, "shared-demo.php"),
                `<?php
/* Plugin Name: Shared Demo */
$rudel_git_plugin_global = 'GitPluginMarker';
add_filter( 'option_blogname', function ( $name ) { return $GLOBALS['rudel_git_plugin_global']; } );
`
              )
              yield* fs.writeFileString(
                join(source, "asset.txt"),
                "GitPluginAssetMarker"
              )
            }
            yield* git("-C", source, "add", ".")
            yield* git("-C", source, "commit", "-qm", "initial")
            yield* git(
              "clone",
              "-q",
              "--bare",
              source,
              join(gitFixtures, `${name}.git`)
            )
            yield* git(
              `--git-dir=${join(gitFixtures, `${name}.git`)}`,
              "update-server-info"
            )
          }
          const gitBase = `http://wordpress/wp-content/plugins/rudel/tests/e2e/${basename(gitFixtures)}`
          const gitTheme = yield* create(
            "--name=git-theme",
            `--git=${gitBase}/git-theme.git`,
            "--theme=git-theme"
          )
          const themePage = yield* request(gitTheme)
          yield* check(
            themePage.status === 200 &&
              themePage.body.includes("GitThemeMarker") &&
              (yield* info(gitTheme, "theme_slug")) === "git-theme",
            "Git sandbox serves its previously uninstalled theme",
            themePage.body
          )
          yield* destroy(gitTheme)
          const gitPlugin = yield* create(
            "--name=git-plugin",
            `--git=${gitBase}/shared-demo.git`,
            "--type=plugin"
          )
          const pluginPage = yield* request(gitPlugin)
          yield* site.selected(gitPlugin, "plugin", "is-active", "shared-demo")
          yield* check(
            pluginPage.status === 200 &&
              pluginPage.body.includes("GitPluginMarker") &&
              (yield* site.attempt("plugin", "is-active", "shared-demo"))
                .exitCode !== 0,
            "Git plugin loads globally only in selected sandbox",
            pluginPage.body
          )
          const assetUrl = lastLine(
            yield* site.selected(
              gitPlugin,
              "eval",
              'echo plugins_url("asset.txt", RUDEL_GIT_PLUGIN_FILE), PHP_EOL;'
            )
          )
          yield* check(assetUrl !== "", "Git plugin asset URL is nonempty")
          yield* check(
            (yield* fetchPage(assetUrl)).body === "GitPluginAssetMarker",
            "Git plugin asset URLs serve checkout files"
          )
          const unaffected = yield* request()
          yield* check(
            unaffected.status === 200 &&
              !unaffected.body.includes("GitPluginMarker"),
            "Git preview preserves host plugin execution"
          )
          yield* destroy(gitPlugin)
          yield* site.selected(
            alpha,
            "option",
            "update",
            "blogname",
            "TemplateSavedTitle"
          )
          yield* site.shell(
            `cp '${alphaPath}/themes/rudel-host-theme/index.php' '${alphaPath}/tmp/template-original.php' && printf '\n<?php echo "TemplateSavedMarker"; error_log("TemplateHttpLogMarker"); ?>\n' >> '${alphaPath}/themes/rudel-host-theme/index.php'`
          )
          yield* site.wp(
            "rudel",
            "template",
            "save",
            alpha,
            "--name=point-in-time"
          )
          const templatePrefix = lastLine(
            yield* site.wp(
              "eval",
              'echo (new \\Rudel\\TemplateManager())->get("point-in-time")["table_prefix"];'
            )
          )
          yield* site.selected(
            alpha,
            "option",
            "update",
            "blogname",
            "TemplateChangedTitle"
          )
          yield* site.shell(
            `cp '${alphaPath}/tmp/template-original.php' '${alphaPath}/themes/rudel-host-theme/index.php'`
          )
          const template = yield* create(
            "--name=template-copy",
            "--template=point-in-time"
          )
          const templatePage = yield* request(template)
          yield* check(
            templatePage.status === 200 &&
              templatePage.body.includes("TemplateSavedMarker") &&
              (yield* option(template)) === "TemplateSavedTitle" &&
              lastLine(
                yield* site.selected(
                  template,
                  "eval",
                  'echo current_user_can("manage_options") ? "admin" : "missing";',
                  "--user=admin"
                )
              ) === "admin",
            "Saved template restores tables, theme and administrator permissions",
            templatePage.body
          )
          const missing = yield* site.attempt(
            "rudel",
            "create",
            "--name=missing-template",
            "--template=does-not-exist"
          )
          yield* check(
            (missing.stdout + missing.stderr).includes("Template not found"),
            "Create rejects unknown saved template"
          )
          yield* site.wp(
            "rudel",
            "template",
            "delete",
            "point-in-time",
            "--force"
          )
          const templateTables = lastLine(
            yield* site.wp(
              "db",
              "query",
              `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND LEFT(table_name,LENGTH('${templatePrefix}'))='${templatePrefix}'`,
              "--skip-column-names"
            )
          )
          yield* check(
            templateTables === "0" &&
              (yield* option(template)) === "TemplateSavedTitle",
            "Template deletion removes saved tables and preserves sandboxes"
          )
          const templateLog = yield* site.wp("rudel", "logs", template)
          yield* check(
            templateLog.includes("TemplateHttpLogMarker") &&
              !templateLog.includes("__halt_compiler"),
            "HTTP sandbox logs stay private and CLI hides guard",
            templateLog
          )
          yield* destroy(template)
          yield* site.selected(
            alpha,
            "option",
            "update",
            "blogname",
            "Alpha Site"
          )
          const snapshot = yield* site.wp(
            "rudel",
            "snapshot",
            alpha,
            "--name=baseline"
          )
          yield* check(
            snapshot.includes("Snapshot created: baseline"),
            "Snapshot creation works for overlays",
            snapshot
          )
          for (const path of [
            "logs/debug.php",
            "snapshots/baseline/db_snapshot.json",
            "snapshots/baseline/themes/rudel-host-theme/style.css",
            "metadata/",
            "tmp/",
          ]) {
            const response = yield* fetchPage(
              `${host}/wp-content/rudel-environments/${alpha}/${path}`
            )
            yield* check(
              [403, 404].includes(response.status),
              "Private sandbox files reject HTTP access",
              `${path}: ${response.status}`
            )
          }
          yield* site.wp(
            "eval",
            `if (file_exists('${alphaPath}/snapshots/baseline/snapshot.json') || file_exists('${alphaPath}/snapshots/baseline/db_snapshot.json')) { exit(1); }`
          )
          yield* check(true, "Snapshot metadata stays in MySQL")
          const baseCount = lastLine(
            yield* site.wp(
              "db",
              "query",
              `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND LEFT(table_name,LENGTH('${alphaPrefix}'))='${alphaPrefix}' AND table_name NOT LIKE '%snap_%'`,
              "--skip-column-names"
            )
          )
          for (const args of [[], [`--clone-from=${alpha}`]]) {
            const clone = yield* create("--name=regression-clone", ...args)
            const prefix = yield* info(clone, "table_prefix")
            const count = lastLine(
              yield* site.wp(
                "db",
                "query",
                `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND LEFT(table_name,LENGTH('${prefix}'))='${prefix}'`,
                "--skip-column-names"
              )
            )
            yield* check(
              count === baseCount,
              "Clone excludes sibling and snapshot tables",
              `expected=${baseCount} actual=${count}`
            )
            yield* destroy(clone)
          }
          yield* site.selected(
            alpha,
            "option",
            "update",
            "blogname",
            "Alpha Changed"
          )
          yield* site.wp(
            "rudel",
            "restore",
            alpha,
            "--snapshot=baseline",
            "--force"
          )
          yield* check(
            (yield* option(alpha)) === "Alpha Site",
            "Snapshot restores prior sandbox DB state"
          )
          const deleted = yield* site.wp(
            "rudel",
            "snapshot",
            "delete",
            alpha,
            "--name=baseline"
          )
          yield* site.shell(`test ! -d '${alphaPath}/snapshots/baseline'`)
          yield* check(
            deleted.includes("Snapshot deleted: baseline"),
            "Snapshot delete removes directory and tables",
            deleted
          )
          yield* site.wp("config", "set", "WP_DEBUG", "false", "--raw")
          yield* site.wp("config", "set", "WP_DEBUG_LOG", "false", "--raw")
          const debug = lastLine(
            yield* site.selected(
              alpha,
              "eval",
              'error_log("RudelDebugProbe"); echo ini_get("display_errors") . ":" . ini_get("log_errors");'
            )
          )
          yield* site.shell(
            `grep -q RudelDebugProbe '${alphaPath}/logs/debug.php'`
          )
          yield* check(
            debug === "0:1",
            "Sandbox logs with host debugging disabled and display off",
            debug
          )
          const hostSalt = lastLine(
            yield* site.wp("eval", 'echo wp_salt("auth");')
          )
          const alphaSalt = lastLine(
            yield* site.selected(alpha, "eval", 'echo wp_salt("auth");')
          )
          const betaSalt = lastLine(
            yield* site.selected(beta, "eval", 'echo wp_salt("auth");')
          )
          yield* check(
            hostSalt !== alphaSalt &&
              alphaSalt !== betaSalt &&
              hostSalt !== betaSalt,
            "Auth salts differ between host and sandboxes"
          )
          const authCookie = lastLine(
            yield* site.wp(
              "eval",
              'echo wp_generate_auth_cookie(1,time()+3600,"auth");'
            )
          )
          const hostValid = lastLine(
            yield* site.wp(
              "eval",
              `var_export(wp_validate_auth_cookie('${authCookie}', 'auth'));`
            )
          )
          const foreignValid = lastLine(
            yield* site.selected(
              alpha,
              "eval",
              `var_export(wp_validate_auth_cookie('${authCookie}', 'auth'));`
            )
          )
          yield* check(
            hostValid === "1" && foreignValid === "false",
            "Sandbox rejects valid host auth cookie"
          )
          yield* site.selected(alpha, "plugin", "deactivate", "rudel")
          const inactive = yield* site
            .selected(alpha, "plugin", "is-active", "rudel")
            .pipe(
              Effect.as(false),
              Effect.orElseSucceed(() => true)
            )
          yield* check(
            inactive &&
              (yield* option(alpha)) === "Alpha Site" &&
              (yield* option(beta)) === "Beta Site" &&
              (yield* site.attempt("plugin", "is-active", "rudel")).exitCode ===
                0,
            "Sandbox deactivation preserves shared bootstrap and siblings"
          )
          const mail = lastLine(
            yield* site.selected(
              alpha,
              "eval",
              'var_export(wp_mail("nobody@example.test","DeactivatedSandboxProbe","blocked"));'
            )
          )
          const inactivePage = yield* request(alpha)
          yield* site.shell(
            `grep -q DeactivatedSandboxProbe '${alphaPath}/logs/debug.php'`
          )
          yield* check(
            mail === "true" &&
              inactivePage.status === 200 &&
              inactivePage.body.includes("rudel-host-theme"),
            "Inactive sandbox preserves mail blocking and theme protections"
          )
          const jar = join(temporary, "cookies.txt")
          const body = join(temporary, "body.html")
          yield* fs.writeFileString(
            jar,
            `# Netscape HTTP Cookie File\nlocalhost\tFALSE\t/\tFALSE\t0\trudel_environment\t${alpha}\nlocalhost\tFALSE\t/\tFALSE\t0\trudel_sandbox\t${alpha}\n`
          )
          const browser = (url: string, follow = false) =>
            Command.run({
              command: "curl",
              args: [
                "-sS",
                ...(follow ? ["-L"] : []),
                "-b",
                jar,
                "-c",
                jar,
                url,
                "-o",
                body,
                "-w",
                "%{http_code}",
                "--max-time",
                "15",
              ],
            }).pipe(Effect.map((result) => result.stdout))
          yield* check(
            (yield* browser(`${host}/`)) === "200" &&
              (yield* fs.readFileString(body)).includes("Alpha Site"),
            "Browser cookie jar selects sandbox"
          )
          yield* check(
            (yield* browser(`${host}/?rudel_exit=1&_wpnonce=invalid`)) ===
              "403" &&
              (yield* fs.readFileString(jar)).includes("rudel_environment"),
            "Invalid exit nonce preserves selector"
          )
          const exitUrl = lastLine(
            yield* site.selected(
              alpha,
              "eval",
              "echo \\Rudel\\Rudel::exit_url();"
            )
          )
          const exitStatus = yield* browser(exitUrl, true)
          const cookies = yield* fs.readFileString(jar)
          yield* check(
            exitStatus === "200" &&
              (yield* fs.readFileString(body)).includes("Rudel Host") &&
              !cookies.includes("rudel_environment") &&
              !cookies.includes("rudel_sandbox"),
            "Exit expires current and legacy selectors and returns to host"
          )
          yield* destroy(alpha)
          yield* site.shell(`test ! -d '${alphaPath}'`)
          yield* check(
            !(yield* tableExists(`${alphaPrefix}options`)),
            "Destroy removes sandbox directory and generated tables"
          )
          yield* check(
            (yield* option(beta)) === "Beta Site",
            "Sibling survives destroying another sandbox"
          )
          yield* assertions.finish(json)
        }),
      json
    ),
})
if (import.meta.main) main(command)
