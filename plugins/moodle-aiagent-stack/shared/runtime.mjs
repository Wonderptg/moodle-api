import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, isAbsolute, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const PLUGIN_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const DEFAULT_MANIFEST_PATH = "skills/moodle-aiagent-cli/references/command-manifest.v0.1.json";
const DEFAULT_REPO_ROOT = "../..";
const DEFAULT_CLI_PATH = "scripts/moodle_cli.py";

export const VALID_LAYERS = ["shortcut", "api", "raw", "all"];
export const VALID_RISKS = ["read", "local_write", "write", "high_write", "destructive", "all"];
export const WRITE_RISKS = new Set(["write", "high_write", "destructive"]);

export const TOOL_ACTIONS = {
  moodle_catalog: ["search", "get_command", "get_function", "list_domains"],
  moodle_auth: ["setup", "login_start", "status", "list_profiles", "use_profile", "logout"],
  moodle_doctor: ["check", "explain_error"],
  moodle_course: [
    "context",
    "list_courses",
    "outline",
    "list_activities",
    "activity_detail",
    "due",
    "resources",
    "grades",
    "progress",
    "notifications",
  ],
  moodle_questionbank: ["categories", "search", "pick_random", "render_html"],
  moodle_quiz: [
    "list",
    "create_practice",
    "resolve_random",
    "attempts",
    "start",
    "attempt_data",
    "attempt_summary",
    "answer",
    "save_attempt",
    "submit_attempt",
  ],
  moodle_calendar: ["list", "publish_plan", "upsert_plan"],
  moodle_assignment: ["list", "status", "save_draft", "submit_final"],
  moodle_forum: ["discussions", "create_discussion", "reply", "update_post", "delete_post"],
  moodle_api: ["call", "get_function", "list_allowed"],
};

export const TOOL_DESCRIPTIONS = {
  moodle_catalog:
    "Search and inspect the local Moodle command manifest. Start here when the right Moodle action or parameters are unclear. Read-only and performs no Moodle network I/O.",
  moodle_auth:
    "Manage local Moodle CLI profiles and login state through structured CLI actions. Local write actions such as logout/use_profile require confirm=true.",
  moodle_doctor:
    "Diagnose Moodle CLI config, token, service, capability, and connectivity problems. Use explain_error after a failed Moodle tool call.",
  moodle_course:
    "Read Moodle course, activity, resource, grade, progress, notification, and due-work data through structured CLI actions. Read-only.",
  moodle_questionbank:
    "Read Moodle question-bank categories, search questions, pick random questions, and render question HTML. Read-only.",
  moodle_quiz:
    "Read Moodle quizzes and attempts, resolve random questions, and perform guarded quiz writes. Writes default to dryRun=true and require confirm=true plus idempotencyKey when dryRun=false.",
  moodle_calendar:
    "Read Moodle calendar events and publish/upsert study plan events. Writes default to dryRun=true and require confirm=true plus idempotencyKey when dryRun=false.",
  moodle_assignment:
    "Read Moodle assignments and guardedly save drafts or submit final work. Writes default to dryRun=true and require confirm=true plus idempotencyKey when dryRun=false.",
  moodle_forum:
    "Read Moodle forum discussions and guardedly create, reply, update, or delete posts. delete_post also requires destructive-action enablement when dryRun=false.",
  moodle_api:
    "Optional advanced escape hatch for manifest-listed local_aiagentapi functions. Prefer domain tools first. call is disabled unless the API tool is explicitly enabled.",
};

function optionsWithDefaults(options = {}) {
  return {
    config: options.config && typeof options.config === "object" ? options.config : {},
    env: options.env && typeof options.env === "object" ? options.env : process.env,
    host: options.host || "mcp",
  };
}

function rawConfig(options, key) {
  const config = options.config || {};
  if (!Object.prototype.hasOwnProperty.call(config, key)) return undefined;
  const value = config[key];
  return value === undefined || value === null || value === "" ? undefined : value;
}

function rawEnv(options, name) {
  const value = (options.env || process.env)[name];
  return value === undefined || value === "" ? undefined : value;
}

function boolFromValue(value, defaultValue) {
  if (typeof value === "boolean") return value;
  if (value === undefined) return defaultValue;
  return ["1", "true", "yes", "on"].includes(String(value).toLowerCase());
}

function stringConfig(options, key, envName, defaultValue) {
  return String(rawConfig(options, key) ?? rawEnv(options, envName) ?? defaultValue);
}

function boolConfig(options, key, envName, defaultValue) {
  const configured = rawConfig(options, key);
  if (configured !== undefined) return boolFromValue(configured, defaultValue);
  return boolFromValue(rawEnv(options, envName), defaultValue);
}

function intConfig(options, key, envName, defaultValue, min, max) {
  const value = rawConfig(options, key) ?? rawEnv(options, envName);
  const parsed = Number.parseInt(String(value ?? ""), 10);
  if (!Number.isFinite(parsed)) return defaultValue;
  return Math.max(min, Math.min(max, parsed));
}

function stringSetConfig(options, key, envName) {
  const configured = rawConfig(options, key);
  if (Array.isArray(configured)) return new Set(configured.map(String).map((item) => item.trim()).filter(Boolean));
  const raw = rawEnv(options, envName);
  return new Set(String(raw || "").split(",").map((item) => item.trim()).filter(Boolean));
}

function trueGateLabel(options, key, envName) {
  return options.host === "openclaw" ? `plugin config ${key}=true` : `${envName}=true`;
}

function configuredManifestPath(options) {
  const configured = stringConfig(options, "manifestPath", "MOODLE_COMMAND_MANIFEST", DEFAULT_MANIFEST_PATH);
  return isAbsolute(configured) ? configured : resolve(PLUGIN_ROOT, configured);
}

function configuredMaxSearchResults(options) {
  return intConfig(options, "maxSearchResults", "MOODLE_MAX_SEARCH_RESULTS", 20, 1, 50);
}

function configuredRepoRoot(options) {
  const configured = stringConfig(options, "repoRoot", "MOODLE_REPO_ROOT", DEFAULT_REPO_ROOT);
  return isAbsolute(configured) ? configured : resolve(PLUGIN_ROOT, configured);
}

function configuredCliPath(options, repoRoot) {
  const configured = stringConfig(options, "cliPath", "MOODLE_CLI_PATH", DEFAULT_CLI_PATH);
  return isAbsolute(configured) ? configured : resolve(repoRoot, configured);
}

function configuredPythonBin(options) {
  return stringConfig(options, "pythonBin", "MOODLE_PYTHON_BIN", "python3");
}

function configuredCommandTimeout(options) {
  return intConfig(options, "commandTimeoutMs", "MOODLE_COMMAND_TIMEOUT_MS", 60000, 1000, 300000);
}

function configuredDefaultWriteDryRun(options) {
  return boolConfig(options, "defaultWriteDryRun", "MOODLE_DEFAULT_WRITE_DRY_RUN", true);
}

function configuredAllowDestructive(options) {
  return boolConfig(options, "allowDestructive", "MOODLE_ALLOW_DESTRUCTIVE", false);
}

function configuredEnableApiTool(options) {
  return boolConfig(options, "enableApiTool", "MOODLE_ENABLE_API_TOOL", false);
}

function configuredAllowApiWrites(options) {
  return boolConfig(options, "allowApiWrites", "MOODLE_ALLOW_API_WRITES", false);
}

function configuredAllowedApiFunctions(options) {
  return stringSetConfig(options, "allowedApiFunctions", "MOODLE_ALLOWED_API_FUNCTIONS");
}

export function loadManifest(options = {}) {
  return JSON.parse(readFileSync(configuredManifestPath(optionsWithDefaults(options)), "utf8"));
}

export function manifestArray(manifest, key) {
  return Array.isArray(manifest[key]) ? manifest[key].filter((entry) => entry && typeof entry === "object") : [];
}

function normalizeSearchText(value) {
  return String(value).toLowerCase().replace(/[_-]/g, " ").replace(/\s+/g, " ").trim();
}

function textBlob(entry) {
  const keys = ["id", "domain", "tool", "action", "layer", "risk", "webservice", "description"];
  const parts = keys.map((key) => entry[key]).filter((value) => value !== undefined && value !== null).map(String);
  const examples = Array.isArray(entry.examples) ? entry.examples.map(String) : [];
  const repairHints = Array.isArray(entry.repairHints) ? entry.repairHints.map(String) : [];
  return [...parts, ...examples, ...repairHints].join("\n").toLowerCase();
}

function matchesSearch(entry, params) {
  if (params.domain && entry.domain !== params.domain) return false;
  if (params.layer && params.layer !== "all" && entry.layer !== params.layer) return false;
  if (params.risk && params.risk !== "all" && entry.risk !== params.risk) return false;

  const rawQuery = String(params.query || "").trim().toLowerCase();
  if (!rawQuery) return true;

  const blob = textBlob(entry);
  if (blob.includes(rawQuery)) return true;

  const normalizedBlob = normalizeSearchText(blob);
  const terms = normalizeSearchText(rawQuery).split(" ").filter(Boolean);
  return terms.every((term) => normalizedBlob.includes(term));
}

function compactCommand(entry) {
  return {
    id: entry.id,
    domain: entry.domain,
    tool: entry.tool,
    action: entry.action,
    layer: entry.layer,
    risk: entry.risk,
    cli: entry.cli,
    webservice: entry.webservice,
    supportsDryRun: entry.supportsDryRun,
    requiresConfirm: entry.requiresConfirm,
    requiresIdempotencyKey: entry.requiresIdempotencyKey,
    examples: entry.examples || [],
    repairHints: entry.repairHints || [],
  };
}

function byId(entries, id) {
  if (!id) return undefined;
  return entries.find((entry) => entry.id === id);
}

function commandForToolAction(manifest, toolName, action) {
  return manifestArray(manifest, "commands").find((entry) => entry.tool === toolName && entry.action === action);
}

export function okPayload(data, extras = {}) {
  return { ok: true, ...extras, data };
}

export function errorPayload(message, extras = {}) {
  return { ok: false, error: message, ...extras };
}

export function openClawToolResult(payload) {
  return {
    content: [{ type: "text", text: JSON.stringify(payload, null, 2) }],
    details: payload,
  };
}

function profileName(params) {
  return String(params.profile || params.name || "").trim() || undefined;
}

function paramsObject(params) {
  return params.params && typeof params.params === "object" && !Array.isArray(params.params) ? params.params : {};
}

function readParam(topLevel, nested, key) {
  if (Object.prototype.hasOwnProperty.call(nested, key)) return nested[key];
  if (Object.prototype.hasOwnProperty.call(topLevel, key)) return topLevel[key];
  if (key === "name" || key === "profile") return profileName(topLevel);
  return undefined;
}

function readStringParam(topLevel, nested, key) {
  const value = readParam(topLevel, nested, key);
  if (typeof value === "string" && value.trim()) return value.trim();
  if (typeof value === "number" && Number.isFinite(value)) return String(value);
  return undefined;
}

function readBoolParam(topLevel, nested, key) {
  const value = readParam(topLevel, nested, key);
  return typeof value === "boolean" ? value : undefined;
}

function readIdempotencyKey(topLevel, nested, required = false) {
  const value = String(topLevel.idempotencyKey || nested.idempotencyKey || nested.idempotency_key || "").trim();
  if (!value && required) throw new Error("idempotencyKey is required");
  return value || undefined;
}

function isMissingManifestValue(value) {
  if (value === undefined || value === null || value === "") return true;
  if (Array.isArray(value)) return value.length === 0 || value.every((item) => isMissingManifestValue(item));
  return false;
}

function valueForManifestParam(topLevel, nested, paramName, schema) {
  if (paramName === "idempotencyKey") return readIdempotencyKey(topLevel, nested, false);
  if (Object.prototype.hasOwnProperty.call(nested, paramName)) return nested[paramName];
  if (Object.prototype.hasOwnProperty.call(topLevel, paramName)) return topLevel[paramName];
  if (paramName === "name" || paramName === "profile") return profileName(topLevel);
  if (typeof schema.api === "string" && Object.prototype.hasOwnProperty.call(nested, schema.api)) return nested[schema.api];
  if (typeof schema.api === "string" && Object.prototype.hasOwnProperty.call(topLevel, schema.api)) return topLevel[schema.api];
  return undefined;
}

function addOptionalFlag(argv, flag, value) {
  if (value !== undefined && value !== null && String(value).trim()) argv.push(flag, String(value).trim());
}

function addManifestArg(argv, flag, value, schema) {
  if (isMissingManifestValue(value)) return;

  if (typeof value === "boolean") {
    if (value && flag.startsWith("--")) {
      argv.push(flag);
      return;
    }
    const falseFlag = schema.falseCli || schema.falseFlag || schema.cliFalse;
    if (!value && typeof falseFlag === "string" && falseFlag.startsWith("--")) argv.push(falseFlag);
    return;
  }

  const values = Array.isArray(value) ? value : [value];
  if (flag.startsWith("--")) {
    if (schema.repeatableCli === true) {
      for (const item of values) argv.push(flag, typeof item === "object" ? JSON.stringify(item) : String(item));
      return;
    }
    argv.push(flag, values.map((item) => (typeof item === "object" ? JSON.stringify(item) : String(item))).join(","));
    return;
  }

  for (const item of values) argv.push(typeof item === "object" ? JSON.stringify(item) : String(item));
}

function rootArgs(params) {
  const argv = ["--json"];
  addOptionalFlag(argv, "--config-dir", params.configDir);
  addOptionalFlag(argv, "--env-file", params.envFile);
  return argv;
}

function businessRootArgs(params) {
  const argv = rootArgs(params);
  addOptionalFlag(argv, "--profile", profileName(params));
  return argv;
}

function writeRootArgs(options, params, commandEntry) {
  const nested = paramsObject(params);
  const risk = String(commandEntry.risk || "read");
  const dryRun = commandEntry.supportsDryRun === true
    ? readBoolParam(params, nested, "dryRun") ?? configuredDefaultWriteDryRun(options)
    : false;

  const argv = businessRootArgs(params);
  if (dryRun) {
    argv.push("--dry-run", "--no-input");
    return argv;
  }

  if (risk === "destructive" && !configuredAllowDestructive(options)) {
    throw new Error(
      `${commandEntry.id} requires ${trueGateLabel(options, "allowDestructive", "MOODLE_ALLOW_DESTRUCTIVE")} when dryRun=false`,
    );
  }

  if (readBoolParam(params, nested, "confirm") !== true) {
    throw new Error(`${commandEntry.id} requires confirm=true when dryRun=false`);
  }

  argv.push("--force", "--no-input");
  return argv;
}

function rootArgsForCommand(options, params, commandEntry) {
  const risk = String(commandEntry.risk || "read");
  if (WRITE_RISKS.has(risk)) return writeRootArgs(options, params, commandEntry);
  if (String(commandEntry.id || "").startsWith("auth.")) return rootArgs(params);
  return businessRootArgs(params);
}

export function buildArgvFromCommand(options = {}, params, commandEntry) {
  const resolvedOptions = optionsWithDefaults(options);
  const nested = paramsObject(params);
  if (commandEntry.requiresConfirm === true && !WRITE_RISKS.has(String(commandEntry.risk || ""))) {
    if (readBoolParam(params, nested, "confirm") !== true) {
      throw new Error(`${commandEntry.id} requires confirm=true`);
    }
  }
  if (commandEntry.requiresIdempotencyKey === true) readIdempotencyKey(params, nested, true);

  const cli = commandEntry.cli && typeof commandEntry.cli === "object" ? commandEntry.cli : {};
  const path = Array.isArray(cli.path) ? cli.path.map(String).filter(Boolean) : [];
  if (path.length === 0) throw new Error(`Command mapping for ${commandEntry.id || "unknown"} is missing cli.path`);

  const argv = [...rootArgsForCommand(resolvedOptions, params, commandEntry), ...path];
  const paramSchemas = commandEntry.params && typeof commandEntry.params === "object" ? commandEntry.params : {};
  const argMap = cli.argv && typeof cli.argv === "object" ? cli.argv : {};

  for (const [paramName, schema] of Object.entries(paramSchemas)) {
    const value = valueForManifestParam(params, nested, paramName, schema);
    if (schema.required === true && isMissingManifestValue(value)) throw new Error(`${paramName} is required`);
    const flag = argMap[paramName];
    if (!flag) continue;
    addManifestArg(argv, flag, value, schema);
  }

  applyCommandSpecialArgs(argv, params, commandEntry);
  return argv;
}

function applyCommandSpecialArgs(argv, params, commandEntry) {
  const nested = paramsObject(params);
  if (commandEntry.id === "auth.login_start") {
    const noWait = readBoolParam(params, nested, "noWait");
    if (noWait !== false && !argv.includes("--no-wait")) argv.push("--no-wait");
    if (readBoolParam(params, nested, "openBrowser") !== true) argv.push("--no-browser");
    addOptionalFlag(argv, "--base-url", readStringParam(params, nested, "baseUrl"));
    addOptionalFlag(argv, "--service", readStringParam(params, nested, "service"));
  }
  if (commandEntry.id === "auth.status") {
    addOptionalFlag(argv, "--base-url", readStringParam(params, nested, "baseUrl"));
    addOptionalFlag(argv, "--service", readStringParam(params, nested, "service"));
    if (readBoolParam(params, nested, "offline") === true) argv.push("--offline");
  }
  if (commandEntry.id === "doctor.check") {
    addOptionalFlag(argv, "--base-url", readStringParam(params, nested, "baseUrl"));
    addOptionalFlag(argv, "--service", readStringParam(params, nested, "service"));
    addOptionalFlag(argv, "--token", readStringParam(params, nested, "token"));
    if (readBoolParam(params, nested, "offline") === true) argv.push("--offline");
  }
}

function parseJsonOutput(stdout) {
  const text = stdout.trim();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

function maskSensitiveArgs(argv) {
  const sensitiveFlags = new Set(["--token", "--password"]);
  const masked = [];
  for (let index = 0; index < argv.length; index += 1) {
    const value = argv[index];
    masked.push(value);
    if (sensitiveFlags.has(value) && index + 1 < argv.length) {
      masked.push("***");
      index += 1;
    }
  }
  return masked;
}

export function runMoodleCli(options = {}, argv, action) {
  const resolvedOptions = optionsWithDefaults(options);
  const repoRoot = configuredRepoRoot(resolvedOptions);
  const cliPath = configuredCliPath(resolvedOptions, repoRoot);
  const pythonBin = configuredPythonBin(resolvedOptions);
  const timeout = configuredCommandTimeout(resolvedOptions);
  const child = spawnSync(pythonBin, [cliPath, ...argv], {
    cwd: repoRoot,
    encoding: "utf8",
    timeout,
    shell: false,
  });

  const stdout = child.stdout || "";
  const stderr = child.stderr || "";
  const audit = {
    command: maskSensitiveArgs([pythonBin, cliPath, ...argv]).join(" "),
    cwd: repoRoot,
    exitCode: child.status,
    signal: child.signal,
  };

  if (child.error) return errorPayload(child.error.message, { action, audit, stderr });

  const data = parseJsonOutput(stdout);
  if (child.status !== 0) {
    return errorPayload(`Moodle CLI exited with code ${child.status}`, {
      action,
      data,
      stderr: stderr.trim(),
      audit,
    });
  }

  return okPayload(data, {
    action,
    meta: { stderr: stderr.trim() || undefined },
    audit,
  });
}

export function handleCatalogPayload(options = {}, params = {}) {
  const resolvedOptions = optionsWithDefaults(options);
  const manifest = loadManifest(resolvedOptions);
  const commands = manifestArray(manifest, "commands");
  const functions = manifestArray(manifest, "functions");
  const domains = manifestArray(manifest, "domains");
  const maxSearchResults = configuredMaxSearchResults(resolvedOptions);
  const limit = Math.max(1, Math.min(maxSearchResults, Math.floor(Number(params.limit ?? maxSearchResults))));

  if (params.action === "search") {
    const matches = commands.filter((entry) => matchesSearch(entry, params)).slice(0, limit).map(compactCommand);
    return okPayload(matches, {
      action: "search",
      meta: { count: matches.length, limit, schemaVersion: manifest.schemaVersion },
    });
  }

  if (params.action === "get_command") {
    const entry = byId(commands, params.id);
    return entry
      ? okPayload(entry, { action: "get_command" })
      : errorPayload(`Unknown command id: ${params.id || ""}`, { action: "get_command" });
  }

  if (params.action === "get_function") {
    const entry = byId(functions, params.id);
    return entry
      ? okPayload(entry, { action: "get_function" })
      : errorPayload(`Unknown function id: ${params.id || ""}`, { action: "get_function" });
  }

  if (params.action === "list_domains") {
    return okPayload(domains, {
      action: "list_domains",
      meta: { count: domains.length, schemaVersion: manifest.schemaVersion },
    });
  }

  return errorPayload(`Unsupported action: ${params.action || ""}`);
}

export function explainMoodleError(raw) {
  const text = String(raw || "").toLowerCase();
  if (!text.trim()) {
    return {
      category: "unknown",
      summary: "No error text was provided.",
      repairHints: ["Run moodle_doctor(action=check) or moodle_auth(action=status) to gather current state."],
    };
  }

  if (text.includes("invalidtoken") || text.includes("token") || text.includes("unauthorized") || text.includes("401")) {
    return {
      category: "auth",
      summary: "The Moodle WebService token is missing, expired, invalid, or not accepted by the target site.",
      repairHints: [
        "Run moodle_auth(action=status, offline=true) to inspect local profile state.",
        "Run moodle_auth(action=login_start) to refresh the profile token.",
        "Confirm the profile points at the intended Moodle base URL and external service.",
      ],
    };
  }

  if (text.includes("access_exception") || text.includes("capability") || text.includes("permission") || text.includes("403")) {
    return {
      category: "permission",
      summary: "The token user likely lacks the required Moodle role/capability in the relevant context.",
      repairHints: [
        "Check that the user has local/aiagentapi:use.",
        "For quiz/question-bank operations, check Moodle quiz and question-bank capabilities in the target course/category context.",
        "Run moodle_doctor(action=check) after permissions are repaired.",
      ],
    };
  }

  if (text.includes("servicenotavailable") || text.includes("function") || text.includes("webservice")) {
    return {
      category: "service",
      summary: "The local_aiagentapi service or WebService function may not be registered/enabled.",
      repairHints: [
        "Run php scripts/register_aiagentapi_service_functions.php in a Moodle code tree if service functions changed.",
        "Confirm the external service shortname is local_aiagentapi.",
        "Run moodle_doctor(action=check) to verify catalog/context calls.",
      ],
    };
  }

  if (text.includes("timeout") || text.includes("econnrefused") || text.includes("connection") || text.includes("could not resolve")) {
    return {
      category: "network",
      summary: "The Moodle base URL may be unreachable from the current machine.",
      repairHints: [
        "Check the profile base URL.",
        "Run moodle_doctor(action=check, offline=true) to separate local profile problems from network problems.",
        "Verify the target Moodle server is running and reachable.",
      ],
    };
  }

  return {
    category: "generic",
    summary: "No specific Moodle repair category matched this error.",
    repairHints: [
      "Run moodle_doctor(action=check) for config/auth/connectivity diagnostics.",
      "Use moodle_catalog(action=search) to confirm the intended command/action and risk gate.",
    ],
  };
}

export function handleApiPayload(options = {}, params = {}) {
  const resolvedOptions = optionsWithDefaults(options);
  const manifest = loadManifest(resolvedOptions);
  const functions = manifestArray(manifest, "functions");
  const commands = manifestArray(manifest, "commands");
  const allowed = configuredAllowedApiFunctions(resolvedOptions);
  const allowedFunctions = functions.filter((entry) => {
    const id = String(entry.id || "");
    if (!id.startsWith("local_aiagentapi_")) return false;
    return allowed.size === 0 ? entry.allowedByDefault === true : allowed.has(id);
  });

  if (params.action === "list_allowed") {
    return okPayload(allowedFunctions, {
      action: "api.list_allowed",
      meta: { count: allowedFunctions.length, enabled: configuredEnableApiTool(resolvedOptions) },
    });
  }

  const functionId = String(params.functionId || paramsObject(params).functionId || "").trim();
  if (!functionId) return errorPayload("functionId is required", { action: `api.${params.action}` });

  const functionEntry = byId(functions, functionId);
  if (!functionEntry) return errorPayload(`Unknown function id: ${functionId}`, { action: `api.${params.action}` });

  if (params.action === "get_function") return okPayload(functionEntry, { action: "api.get_function" });
  if (params.action !== "call") return errorPayload(`Unsupported action: ${params.action || ""}`);

  if (!configuredEnableApiTool(resolvedOptions)) {
    return errorPayload(`moodle_api.call requires ${trueGateLabel(resolvedOptions, "enableApiTool", "MOODLE_ENABLE_API_TOOL")}`, {
      action: "api.call",
    });
  }
  if (!String(functionEntry.id || "").startsWith("local_aiagentapi_")) {
    return errorPayload("moodle_api.call only supports local_aiagentapi_* functions", { action: "api.call", functionId });
  }

  const allowlisted = allowed.size === 0 ? functionEntry.allowedByDefault === true : allowed.has(functionId);
  if (!allowlisted) {
    return errorPayload("functionId is not allowed by current moodle_api policy", { action: "api.call", functionId });
  }

  const commandEntry = commands.find((entry) => entry.webservice === functionId);
  if (!commandEntry) return errorPayload(`No structured CLI command maps to ${functionId}`, { action: "api.call", functionId });

  const risk = String(commandEntry.risk || functionEntry.risk || "read");
  if (WRITE_RISKS.has(risk) && !configuredAllowApiWrites(resolvedOptions)) {
    return errorPayload(
      `write-capable moodle_api.call requires ${trueGateLabel(resolvedOptions, "allowApiWrites", "MOODLE_ALLOW_API_WRITES")}`,
      {
        action: "api.call",
        functionId,
        risk,
      },
    );
  }

  return runMoodleCli(resolvedOptions, buildArgvFromCommand(resolvedOptions, params, commandEntry), `api.call:${functionId}`);
}

export function handleManifestCommandPayload(options = {}, toolName, params = {}) {
  const resolvedOptions = optionsWithDefaults(options);
  const manifest = loadManifest(resolvedOptions);
  const commandEntry = commandForToolAction(manifest, toolName, params.action);
  if (!commandEntry) return errorPayload(`Unsupported action: ${params.action || ""}`, { tool: toolName });
  return runMoodleCli(resolvedOptions, buildArgvFromCommand(resolvedOptions, params, commandEntry), commandEntry.id);
}

export function handleToolPayload(options = {}, name, args) {
  const params = args && typeof args === "object" ? args : {};
  const resolvedOptions = optionsWithDefaults(options);
  try {
    if (name === "moodle_catalog") return handleCatalogPayload(resolvedOptions, params);
    if (name === "moodle_doctor" && params.action === "explain_error") {
      return okPayload(explainMoodleError(params.error ?? paramsObject(params).error), { action: "explain_error" });
    }
    if (name === "moodle_api") return handleApiPayload(resolvedOptions, params);
    if (Object.prototype.hasOwnProperty.call(TOOL_ACTIONS, name)) return handleManifestCommandPayload(resolvedOptions, name, params);
    return errorPayload(`Unknown tool: ${name}`);
  } catch (err) {
    return errorPayload(err instanceof Error ? err.message : String(err), { tool: name, action: params.action });
  }
}
