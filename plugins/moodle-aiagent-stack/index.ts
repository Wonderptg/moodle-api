import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, isAbsolute, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import type { OpenClawPluginApi } from "openclaw/plugin-sdk";

type ManifestEntry = Record<string, unknown>;
type CommandManifest = {
  schemaVersion?: number;
  domains?: ManifestEntry[];
  commands?: ManifestEntry[];
  functions?: ManifestEntry[];
};

type MoodleCatalogParams = {
  action: "search" | "get_command" | "get_function" | "list_domains";
  query?: string;
  id?: string;
  domain?: string;
  layer?: "shortcut" | "api" | "raw" | "all";
  risk?: "read" | "local_write" | "write" | "high_write" | "destructive" | "all";
  limit?: number;
};

type MoodleAuthParams = {
  action: "setup" | "login_start" | "status" | "list_profiles" | "use_profile" | "logout";
  profile?: string;
  name?: string;
  baseUrl?: string;
  service?: string;
  configDir?: string;
  offline?: boolean;
  noWait?: boolean;
  openBrowser?: boolean;
  confirm?: boolean;
};

type MoodleDoctorParams = {
  action: "check" | "explain_error";
  error?: string;
  profile?: string;
  name?: string;
  baseUrl?: string;
  service?: string;
  token?: string;
  configDir?: string;
  offline?: boolean;
};

type MoodleDomainParams = {
  action: string;
  params?: Record<string, unknown>;
  profile?: string;
  configDir?: string;
  dryRun?: boolean;
  confirm?: boolean;
  idempotencyKey?: string;
};

type MoodleCourseParams = MoodleDomainParams & {
  action:
    | "context"
    | "list_courses"
    | "outline"
    | "list_activities"
    | "activity_detail"
    | "due"
    | "resources"
    | "grades"
    | "progress"
    | "notifications";
};

type MoodleQuestionbankParams = MoodleDomainParams & {
  action: "categories" | "search" | "pick_random" | "render_html";
};

type MoodleQuizParams = MoodleDomainParams & {
  action:
    | "list"
    | "create_practice"
    | "resolve_random"
    | "attempts"
    | "start"
    | "attempt_data"
    | "attempt_summary"
    | "answer"
    | "save_attempt"
    | "submit_attempt";
};

type MoodleCalendarParams = MoodleDomainParams & {
  action: "list" | "publish_plan" | "upsert_plan";
};

type MoodleAssignmentParams = MoodleDomainParams & {
  action: "list" | "status" | "save_draft" | "submit_final";
};

type MoodleForumParams = MoodleDomainParams & {
  action: "discussions" | "create_discussion" | "reply" | "update_post" | "delete_post";
};

type MoodleApiParams = MoodleDomainParams & {
  action: "call" | "get_function" | "list_allowed";
  functionId?: string;
};

const PLUGIN_ROOT = dirname(fileURLToPath(import.meta.url));
const DEFAULT_MANIFEST_PATH = "skills/moodle-aiagent-cli/references/command-manifest.v0.1.json";
const DEFAULT_REPO_ROOT = "../..";
const DEFAULT_CLI_PATH = "scripts/moodle_cli.py";
const VALID_LAYERS = ["shortcut", "api", "raw", "all"] as const;
const VALID_RISKS = ["read", "local_write", "write", "high_write", "destructive", "all"] as const;
const OPTIONAL_SCHEMA = Symbol("optionalJsonSchema");

type JsonSchema = Record<string, unknown> & { [OPTIONAL_SCHEMA]?: true };

const Type = {
  Object(properties: Record<string, JsonSchema>, options: Record<string, unknown> = {}): JsonSchema {
    const required: string[] = [];
    const cleanProperties: Record<string, JsonSchema> = {};

    for (const [key, schema] of Object.entries(properties)) {
      const { [OPTIONAL_SCHEMA]: optional, ...cleanSchema } = schema;
      cleanProperties[key] = cleanSchema;
      if (!optional) required.push(key);
    }

    return {
      type: "object",
      properties: cleanProperties,
      ...(required.length > 0 ? { required } : {}),
      ...options,
    };
  },
  Optional(schema: JsonSchema): JsonSchema {
    return { ...schema, [OPTIONAL_SCHEMA]: true };
  },
  Union(schemas: readonly JsonSchema[], options: Record<string, unknown> = {}): JsonSchema {
    return { anyOf: schemas, ...options };
  },
  Literal(value: string | number | boolean): JsonSchema {
    return { const: value };
  },
  String(options: Record<string, unknown> = {}): JsonSchema {
    return { type: "string", ...options };
  },
  Number(options: Record<string, unknown> = {}): JsonSchema {
    return { type: "number", ...options };
  },
  Boolean(options: Record<string, unknown> = {}): JsonSchema {
    return { type: "boolean", ...options };
  },
};

const MoodleCatalogSchema = Type.Object(
  {
    action: Type.Union([
      Type.Literal("search"),
      Type.Literal("get_command"),
      Type.Literal("get_function"),
      Type.Literal("list_domains"),
    ]),
    query: Type.Optional(Type.String({ description: "Free-text search query. Used by action=search." })),
    id: Type.Optional(Type.String({ description: "Command or function id. Used by get_command/get_function." })),
    domain: Type.Optional(Type.String({ description: "Optional manifest domain filter, such as quiz or course." })),
    layer: Type.Optional(
      Type.Union(VALID_LAYERS.map((value) => Type.Literal(value)), {
        description: "Optional layer filter.",
        default: "all",
      }),
    ),
    risk: Type.Optional(
      Type.Union(VALID_RISKS.map((value) => Type.Literal(value)), {
        description: "Optional risk filter.",
        default: "all",
      }),
    ),
    limit: Type.Optional(
      Type.Number({
        description: "Maximum search results.",
        minimum: 1,
        maximum: 50,
        default: 20,
      }),
    ),
  },
  { additionalProperties: false },
);

const MoodleAuthSchema = Type.Object(
  {
    action: Type.Union([
      Type.Literal("setup"),
      Type.Literal("login_start"),
      Type.Literal("status"),
      Type.Literal("list_profiles"),
      Type.Literal("use_profile"),
      Type.Literal("logout"),
    ]),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    name: Type.Optional(Type.String({ description: "Alias for profile." })),
    baseUrl: Type.Optional(Type.String({ description: "Moodle base URL." })),
    service: Type.Optional(Type.String({ description: "Moodle external service shortname." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
    offline: Type.Optional(Type.Boolean({ description: "Skip network checks where supported.", default: false })),
    noWait: Type.Optional(Type.Boolean({ description: "Return login device code immediately.", default: true })),
    openBrowser: Type.Optional(Type.Boolean({ description: "Allow opening a browser during login.", default: false })),
    confirm: Type.Optional(
      Type.Boolean({
        description: "Required for local-write actions such as use_profile and logout.",
        default: false,
      }),
    ),
  },
  { additionalProperties: false },
);

const MoodleDoctorSchema = Type.Object(
  {
    action: Type.Union([Type.Literal("check"), Type.Literal("explain_error")]),
    error: Type.Optional(Type.String({ description: "Error text/code to explain." })),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    name: Type.Optional(Type.String({ description: "Alias for profile." })),
    baseUrl: Type.Optional(Type.String({ description: "Moodle base URL." })),
    service: Type.Optional(Type.String({ description: "Moodle external service shortname." })),
    token: Type.Optional(Type.String({ description: "Web service token for one-off doctor checks." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
    offline: Type.Optional(Type.Boolean({ description: "Skip network checks.", default: false })),
  },
  { additionalProperties: false },
);

const MoodleCourseSchema = Type.Object(
  {
    action: Type.Union([
      Type.Literal("context"),
      Type.Literal("list_courses"),
      Type.Literal("outline"),
      Type.Literal("list_activities"),
      Type.Literal("activity_detail"),
      Type.Literal("due"),
      Type.Literal("resources"),
      Type.Literal("grades"),
      Type.Literal("progress"),
      Type.Literal("notifications"),
    ]),
    params: Type.Optional(
      Type.Object(
        {},
        {
          additionalProperties: true,
          description:
            "Action-specific parameters. Common keys: courseId, cmid, modname, timestart, timeend, limit, limitFrom, unreadOnly.",
        },
      ),
    ),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
  },
  { additionalProperties: false },
);

const MoodleQuestionbankSchema = Type.Object(
  {
    action: Type.Union([
      Type.Literal("categories"),
      Type.Literal("search"),
      Type.Literal("pick_random"),
      Type.Literal("render_html"),
    ]),
    params: Type.Optional(
      Type.Object(
        {},
        {
          additionalProperties: true,
          description:
            "Action-specific parameters. Common keys: courseId, contextId, parentId, query, categoryId, recurse, qtypes, limit, count, seed, questionIds.",
        },
      ),
    ),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
  },
  { additionalProperties: false },
);

const MoodleQuizSchema = Type.Object(
  {
    action: Type.Union([
      Type.Literal("list"),
      Type.Literal("create_practice"),
      Type.Literal("resolve_random"),
      Type.Literal("attempts"),
      Type.Literal("start"),
      Type.Literal("attempt_data"),
      Type.Literal("attempt_summary"),
      Type.Literal("answer"),
      Type.Literal("save_attempt"),
      Type.Literal("submit_attempt"),
    ]),
    params: Type.Optional(
      Type.Object(
        {},
        {
          additionalProperties: true,
          description:
            "Action-specific parameters. Common keys: courseId, quizId, attemptId, categoryIds, count, responses, answers, preflight.",
        },
      ),
    ),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
    dryRun: Type.Optional(Type.Boolean({ description: "Preview write actions instead of changing Moodle state.", default: true })),
    confirm: Type.Optional(Type.Boolean({ description: "Required when dryRun=false for write actions.", default: false })),
    idempotencyKey: Type.Optional(Type.String({ description: "Stable client idempotency key for write actions." })),
  },
  { additionalProperties: false },
);

const MoodleCalendarSchema = Type.Object(
  {
    action: Type.Union([Type.Literal("list"), Type.Literal("publish_plan"), Type.Literal("upsert_plan")]),
    params: Type.Optional(
      Type.Object(
        {},
        {
          additionalProperties: true,
          description: "Action-specific parameters. Common keys: timestart, timeend, limit, planKey, items, reason.",
        },
      ),
    ),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
    dryRun: Type.Optional(Type.Boolean({ description: "Preview write actions instead of changing Moodle state.", default: true })),
    confirm: Type.Optional(Type.Boolean({ description: "Required when dryRun=false for write actions.", default: false })),
    idempotencyKey: Type.Optional(Type.String({ description: "Stable client idempotency key for write actions." })),
  },
  { additionalProperties: false },
);

const MoodleAssignmentSchema = Type.Object(
  {
    action: Type.Union([
      Type.Literal("list"),
      Type.Literal("status"),
      Type.Literal("save_draft"),
      Type.Literal("submit_final"),
    ]),
    params: Type.Optional(
      Type.Object(
        {},
        {
          additionalProperties: true,
          description:
            "Action-specific parameters. Common keys: courseId, assignId, text, format, acceptSubmissionStatement, reason.",
        },
      ),
    ),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
    dryRun: Type.Optional(Type.Boolean({ description: "Preview write actions instead of changing Moodle state.", default: true })),
    confirm: Type.Optional(Type.Boolean({ description: "Required when dryRun=false for write actions.", default: false })),
    idempotencyKey: Type.Optional(Type.String({ description: "Stable client idempotency key for write actions." })),
  },
  { additionalProperties: false },
);

const MoodleForumSchema = Type.Object(
  {
    action: Type.Union([
      Type.Literal("discussions"),
      Type.Literal("create_discussion"),
      Type.Literal("reply"),
      Type.Literal("update_post"),
      Type.Literal("delete_post"),
    ]),
    params: Type.Optional(
      Type.Object(
        {},
        {
          additionalProperties: true,
          description:
            "Action-specific parameters. Common keys: courseId, forumId, postId, subject, message, subscribe, pinned, privateReply, reason.",
        },
      ),
    ),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
    dryRun: Type.Optional(Type.Boolean({ description: "Preview write/destructive actions instead of changing Moodle state.", default: true })),
    confirm: Type.Optional(Type.Boolean({ description: "Required when dryRun=false for write/destructive actions.", default: false })),
    idempotencyKey: Type.Optional(Type.String({ description: "Stable client idempotency key for write/destructive actions." })),
  },
  { additionalProperties: false },
);

const MoodleApiSchema = Type.Object(
  {
    action: Type.Union([Type.Literal("call"), Type.Literal("get_function"), Type.Literal("list_allowed")]),
    functionId: Type.Optional(Type.String({ description: "Manifest-listed local_aiagentapi function id." })),
    params: Type.Optional(
      Type.Object(
        {},
        {
          additionalProperties: true,
          description: "Low-level API parameters. Use API names from moodle_catalog get_function.",
        },
      ),
    ),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
    dryRun: Type.Optional(Type.Boolean({ description: "Preview write calls where the mapped command supports it.", default: true })),
    confirm: Type.Optional(Type.Boolean({ description: "Required when dryRun=false for write calls.", default: false })),
    idempotencyKey: Type.Optional(Type.String({ description: "Stable client idempotency key for write calls." })),
  },
  { additionalProperties: false },
);

function resolveManifestPath(api: OpenClawPluginApi): string {
  const configured = String(api.pluginConfig?.["manifestPath"] || DEFAULT_MANIFEST_PATH);
  return isAbsolute(configured) ? configured : resolve(PLUGIN_ROOT, configured);
}

function configuredMaxSearchResults(api: OpenClawPluginApi): number {
  const value = Number(api.pluginConfig?.["maxSearchResults"] ?? 20);
  if (!Number.isFinite(value)) return 20;
  return Math.max(1, Math.min(50, Math.floor(value)));
}

function loadManifest(api: OpenClawPluginApi): CommandManifest {
  const manifestPath = resolveManifestPath(api);
  const payload = JSON.parse(readFileSync(manifestPath, "utf8")) as CommandManifest;
  return payload;
}

function resolveRepoRoot(api: OpenClawPluginApi): string {
  const configured = String(api.pluginConfig?.["repoRoot"] || DEFAULT_REPO_ROOT);
  return isAbsolute(configured) ? configured : resolve(PLUGIN_ROOT, configured);
}

function resolveCliPath(api: OpenClawPluginApi, repoRoot: string): string {
  const configured = String(api.pluginConfig?.["cliPath"] || DEFAULT_CLI_PATH);
  return isAbsolute(configured) ? configured : resolve(repoRoot, configured);
}

function configuredPythonBin(api: OpenClawPluginApi): string {
  return String(api.pluginConfig?.["pythonBin"] || "python3");
}

function configuredCommandTimeout(api: OpenClawPluginApi): number {
  const value = Number(api.pluginConfig?.["commandTimeoutMs"] ?? 60000);
  if (!Number.isFinite(value)) return 60000;
  return Math.max(1000, Math.min(300000, Math.floor(value)));
}

function configuredDefaultWriteDryRun(api: OpenClawPluginApi): boolean {
  return api.pluginConfig?.["defaultWriteDryRun"] !== false;
}

function configuredAllowDestructive(api: OpenClawPluginApi): boolean {
  return api.pluginConfig?.["allowDestructive"] === true;
}

function configuredEnableApiTool(api: OpenClawPluginApi): boolean {
  return api.pluginConfig?.["enableApiTool"] === true;
}

function configuredAllowApiWrites(api: OpenClawPluginApi): boolean {
  return api.pluginConfig?.["allowApiWrites"] === true;
}

function configuredAllowedApiFunctions(api: OpenClawPluginApi): Set<string> {
  const raw = api.pluginConfig?.["allowedApiFunctions"];
  if (!Array.isArray(raw)) return new Set();
  return new Set(raw.map(String).filter(Boolean));
}

function arrayFromManifest(manifest: CommandManifest, key: "domains" | "commands" | "functions"): ManifestEntry[] {
  const value = manifest[key];
  return Array.isArray(value) ? value.filter((entry): entry is ManifestEntry => Boolean(entry && typeof entry === "object")) : [];
}

function normalizeSearchText(value: string): string {
  return value.toLowerCase().replace(/[_-]/g, " ").replace(/\s+/g, " ").trim();
}

function textBlob(entry: ManifestEntry): string {
  const keys = ["id", "domain", "tool", "action", "layer", "risk", "webservice", "description"];
  const parts = keys.map((key) => entry[key]).filter((value) => value !== undefined && value !== null).map(String);
  const examples = Array.isArray(entry["examples"]) ? entry["examples"].map(String) : [];
  const repairHints = Array.isArray(entry["repairHints"]) ? entry["repairHints"].map(String) : [];
  return [...parts, ...examples, ...repairHints].join("\n").toLowerCase();
}

function matchesSearch(entry: ManifestEntry, params: MoodleCatalogParams): boolean {
  if (params.domain && entry["domain"] !== params.domain) return false;
  if (params.layer && params.layer !== "all" && entry["layer"] !== params.layer) return false;
  if (params.risk && params.risk !== "all" && entry["risk"] !== params.risk) return false;

  const rawQuery = (params.query || "").trim().toLowerCase();
  if (!rawQuery) return true;

  const blob = textBlob(entry);
  if (blob.includes(rawQuery)) return true;

  const normalizedBlob = normalizeSearchText(blob);
  const terms = normalizeSearchText(rawQuery).split(" ").filter(Boolean);
  return terms.every((term) => normalizedBlob.includes(term));
}

function compactCommand(entry: ManifestEntry): ManifestEntry {
  return {
    id: entry["id"],
    domain: entry["domain"],
    tool: entry["tool"],
    action: entry["action"],
    layer: entry["layer"],
    risk: entry["risk"],
    cli: entry["cli"],
    webservice: entry["webservice"],
    supportsDryRun: entry["supportsDryRun"],
    requiresConfirm: entry["requiresConfirm"],
    requiresIdempotencyKey: entry["requiresIdempotencyKey"],
    examples: entry["examples"] || [],
    repairHints: entry["repairHints"] || [],
  };
}

function byId(entries: ManifestEntry[], id: string | undefined): ManifestEntry | undefined {
  if (!id) return undefined;
  return entries.find((entry) => entry["id"] === id);
}

function result(data: unknown, extras: Record<string, unknown> = {}) {
  const payload = { ok: true, ...extras, data };
  return {
    content: [{ type: "text" as const, text: JSON.stringify(payload, null, 2) }],
    details: payload,
  };
}

function error(message: string, extras: Record<string, unknown> = {}) {
  const payload = { ok: false, error: message, ...extras };
  return {
    content: [{ type: "text" as const, text: JSON.stringify(payload, null, 2) }],
    details: payload,
  };
}

function profileName(params: { profile?: string; name?: string }): string | undefined {
  return (params.profile || params.name || "").trim() || undefined;
}

function addOptionalFlag(argv: string[], flag: string, value: string | undefined): void {
  if (value && value.trim()) argv.push(flag, value.trim());
}

function addProfileFlag(argv: string[], params: { profile?: string; name?: string }): void {
  addOptionalFlag(argv, "--name", profileName(params));
}

function buildRootArgs(params: { configDir?: string }): string[] {
  const argv = ["--json"];
  addOptionalFlag(argv, "--config-dir", params.configDir);
  return argv;
}

function buildBusinessRootArgs(params: { configDir?: string; profile?: string }): string[] {
  const argv = buildRootArgs(params);
  addOptionalFlag(argv, "--profile", params.profile);
  return argv;
}

function paramsObject(params: MoodleDomainParams): Record<string, unknown> {
  return params.params && typeof params.params === "object" ? params.params : {};
}

function readStringParam(params: Record<string, unknown>, key: string): string | undefined {
  const value = params[key];
  if (typeof value === "string" && value.trim()) return value.trim();
  if (typeof value === "number" && Number.isFinite(value)) return String(value);
  return undefined;
}

function requireStringParam(params: Record<string, unknown>, key: string): string {
  const value = readStringParam(params, key);
  if (!value) throw new Error(`${key} is required`);
  return value;
}

function readIntParam(params: Record<string, unknown>, key: string, required = false): number | undefined {
  const value = params[key];
  let parsed: number | undefined;
  if (typeof value === "number" && Number.isFinite(value)) parsed = Math.trunc(value);
  if (typeof value === "string" && value.trim()) parsed = Number.parseInt(value.trim(), 10);
  if (required && (parsed === undefined || Number.isNaN(parsed))) {
    throw new Error(`${key} is required`);
  }
  return parsed === undefined || Number.isNaN(parsed) ? undefined : parsed;
}

function readBoolParam(params: Record<string, unknown>, key: string): boolean {
  return params[key] === true;
}

function readOptionalBoolParam(params: Record<string, unknown>, key: string): boolean | undefined {
  const value = params[key];
  return typeof value === "boolean" ? value : undefined;
}

function readStringArrayParam(params: Record<string, unknown>, key: string): string[] {
  const value = params[key];
  if (Array.isArray(value)) return value.map(String).filter(Boolean);
  if (typeof value === "string" && value.trim()) return [value.trim()];
  return [];
}

function readStringArrayParamAny(params: Record<string, unknown>, keys: string[]): string[] {
  for (const key of keys) {
    const values = readStringArrayParam(params, key);
    if (values.length > 0) return values;
  }
  return [];
}

function readIntArrayParam(params: Record<string, unknown>, key: string, required = false): number[] {
  const value = params[key];
  const values = Array.isArray(value) ? value : value === undefined ? [] : [value];
  const parsed = values
    .map((item) => (typeof item === "number" ? Math.trunc(item) : Number.parseInt(String(item), 10)))
    .filter((item) => Number.isFinite(item));
  if (required && parsed.length === 0) throw new Error(`${key} is required`);
  return parsed;
}

function readIntArrayParamAny(params: Record<string, unknown>, keys: string[], required = false): number[] {
  for (const key of keys) {
    const values = readIntArrayParam(params, key);
    if (values.length > 0) return values;
  }
  if (required) throw new Error(`${keys[0]} is required`);
  return [];
}

function readJsonItemsParamAny(params: Record<string, unknown>, keys: string[], required = false): string[] {
  for (const key of keys) {
    const value = params[key];
    if (value === undefined || value === null) continue;
    const values = Array.isArray(value) ? value : [value];
    const serialized = values
      .map((item) => (typeof item === "string" ? item.trim() : JSON.stringify(item)))
      .filter(Boolean);
    if (serialized.length > 0) return serialized;
  }
  if (required) throw new Error(`${keys[0]} is required`);
  return [];
}

function valueForManifestParam(
  callParams: Record<string, unknown>,
  paramName: string,
  schema: ManifestEntry,
  topLevel: MoodleDomainParams,
): unknown {
  if (paramName === "idempotencyKey") {
    return readIdempotencyKey(topLevel, callParams, false);
  }
  if (Object.prototype.hasOwnProperty.call(callParams, paramName)) {
    return callParams[paramName];
  }
  const apiName = schema["api"];
  if (typeof apiName === "string" && Object.prototype.hasOwnProperty.call(callParams, apiName)) {
    return callParams[apiName];
  }
  return undefined;
}

function isMissingManifestValue(value: unknown): boolean {
  if (value === undefined || value === null || value === "") return true;
  if (Array.isArray(value)) return value.length === 0 || value.every((item) => isMissingManifestValue(item));
  return false;
}

function addManifestArg(argv: string[], flag: string, value: unknown, repeatable: boolean): void {
  if (value === undefined || value === null || value === "") return;

  if (typeof value === "boolean") {
    if (value && flag.startsWith("--")) argv.push(flag);
    return;
  }

  const values = Array.isArray(value) ? value : [value];
  if (flag.startsWith("--")) {
    if (repeatable) {
      for (const item of values) {
        argv.push(flag, typeof item === "object" ? JSON.stringify(item) : String(item));
      }
      return;
    }
    argv.push(flag, values.map((item) => (typeof item === "object" ? JSON.stringify(item) : String(item))).join(","));
    return;
  }

  for (const item of values) {
    argv.push(typeof item === "object" ? JSON.stringify(item) : String(item));
  }
}

function addIntFlag(argv: string[], flag: string, value: number | undefined): void {
  if (value !== undefined) argv.push(flag, String(value));
}

function addBooleanFlag(argv: string[], flag: string, value: boolean): void {
  if (value) argv.push(flag);
}

function addRepeatableStringFlag(argv: string[], flag: string, values: string[]): void {
  for (const value of values) argv.push(flag, value);
}

function addRepeatableIntFlag(argv: string[], flag: string, values: number[]): void {
  for (const value of values) argv.push(flag, String(value));
}

function addRepeatableJsonFlag(argv: string[], flag: string, values: string[]): void {
  for (const value of values) argv.push(flag, value);
}

function readControlBool(params: MoodleDomainParams, p: Record<string, unknown>, key: "confirm" | "dryRun"): boolean | undefined {
  const topLevel = params[key];
  if (typeof topLevel === "boolean") return topLevel;
  return readOptionalBoolParam(p, key);
}

function readIdempotencyKey(params: MoodleDomainParams, p: Record<string, unknown>, required = false): string | undefined {
  const value = (params.idempotencyKey || readStringParam(p, "idempotencyKey") || readStringParam(p, "idempotency_key") || "").trim();
  if (!value && required) throw new Error("idempotencyKey is required");
  return value || undefined;
}

function buildWriteBusinessRootArgs(
  api: OpenClawPluginApi,
  params: MoodleDomainParams,
  p: Record<string, unknown>,
  action: string,
  options: { supportsDryRun?: boolean; destructive?: boolean } = {},
): string[] {
  const argv = buildBusinessRootArgs(params);
  const supportsDryRun = options.supportsDryRun !== false;
  const dryRun = supportsDryRun ? readControlBool(params, p, "dryRun") ?? configuredDefaultWriteDryRun(api) : false;

  if (dryRun) {
    argv.push("--dry-run", "--no-input");
    return argv;
  }

  if (options.destructive && !configuredAllowDestructive(api)) {
    throw new Error(`${action} requires plugin config allowDestructive=true when dryRun=false`);
  }

  if (readControlBool(params, p, "confirm") !== true) {
    throw new Error(`${action} requires confirm=true when dryRun=false`);
  }

  argv.push("--force", "--no-input");
  return argv;
}

function parseJsonOutput(stdout: string): unknown {
  const text = stdout.trim();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

function maskSensitiveArgs(argv: string[]): string[] {
  const sensitiveFlags = new Set(["--token", "--password"]);
  const masked: string[] = [];
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

function runMoodleCli(api: OpenClawPluginApi, argv: string[], action: string) {
  const repoRoot = resolveRepoRoot(api);
  const cliPath = resolveCliPath(api, repoRoot);
  const pythonBin = configuredPythonBin(api);
  const timeout = configuredCommandTimeout(api);
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

  if (child.error) {
    return error(child.error.message, { action, audit, stderr });
  }

  const data = parseJsonOutput(stdout);
  if (child.status !== 0) {
    return error(`Moodle CLI exited with code ${child.status}`, {
      action,
      data,
      stderr: stderr.trim(),
      audit,
    });
  }

  return result(data, {
    action,
    meta: { stderr: stderr.trim() || undefined },
    audit,
  });
}

function handleCatalog(api: OpenClawPluginApi, params: MoodleCatalogParams) {
  const manifest = loadManifest(api);
  const commands = arrayFromManifest(manifest, "commands");
  const functions = arrayFromManifest(manifest, "functions");
  const domains = arrayFromManifest(manifest, "domains");
  const maxSearchResults = configuredMaxSearchResults(api);
  const limit = Math.max(1, Math.min(maxSearchResults, Math.floor(Number(params.limit ?? maxSearchResults))));

  if (params.action === "search") {
    const matches = commands.filter((entry) => matchesSearch(entry, params)).slice(0, limit).map(compactCommand);
    return result(matches, {
      action: "search",
      meta: { count: matches.length, limit, schemaVersion: manifest.schemaVersion },
    });
  }

  if (params.action === "get_command") {
    const entry = byId(commands, params.id);
    if (!entry) return error(`Unknown command id: ${params.id || ""}`, { action: "get_command" });
    return result(entry, { action: "get_command" });
  }

  if (params.action === "get_function") {
    const entry = byId(functions, params.id);
    if (!entry) return error(`Unknown function id: ${params.id || ""}`, { action: "get_function" });
    return result(entry, { action: "get_function" });
  }

  if (params.action === "list_domains") {
    return result(domains, {
      action: "list_domains",
      meta: { count: domains.length, schemaVersion: manifest.schemaVersion },
    });
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function handleAuth(api: OpenClawPluginApi, params: MoodleAuthParams) {
  if (params.action === "setup") {
    const profile = profileName(params);
    if (!profile) return error("setup requires profile or name", { action: "setup" });
    if (!params.baseUrl) return error("setup requires baseUrl", { action: "setup" });
    const argv = [...buildRootArgs(params), "setup", "--name", profile, "--base-url", params.baseUrl];
    addOptionalFlag(argv, "--service", params.service);
    return runMoodleCli(api, argv, "setup");
  }

  if (params.action === "login_start") {
    const profile = profileName(params);
    if (!profile) return error("login_start requires profile or name", { action: "login_start" });
    const argv = [...buildRootArgs(params), "login", "--name", profile];
    addOptionalFlag(argv, "--base-url", params.baseUrl);
    addOptionalFlag(argv, "--service", params.service);
    if (params.noWait !== false) argv.push("--no-wait");
    if (params.openBrowser !== true) argv.push("--no-browser");
    return runMoodleCli(api, argv, "login_start");
  }

  if (params.action === "status") {
    const argv = [...buildRootArgs(params), "status"];
    addProfileFlag(argv, params);
    addOptionalFlag(argv, "--base-url", params.baseUrl);
    addOptionalFlag(argv, "--service", params.service);
    if (params.offline) argv.push("--offline");
    return runMoodleCli(api, argv, "status");
  }

  if (params.action === "list_profiles") {
    return runMoodleCli(api, [...buildRootArgs(params), "profile", "list"], "list_profiles");
  }

  if (params.action === "use_profile") {
    const profile = profileName(params);
    if (!profile) return error("use_profile requires profile or name", { action: "use_profile" });
    if (params.confirm !== true) return error("use_profile requires confirm=true", { action: "use_profile" });
    return runMoodleCli(api, [...buildRootArgs(params), "profile", "use", profile], "use_profile");
  }

  if (params.action === "logout") {
    if (params.confirm !== true) return error("logout requires confirm=true", { action: "logout" });
    const argv = [...buildRootArgs(params), "auth", "logout"];
    addProfileFlag(argv, params);
    return runMoodleCli(api, argv, "logout");
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function explainMoodleError(raw: string | undefined) {
  const text = (raw || "").toLowerCase();
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

function handleDoctor(api: OpenClawPluginApi, params: MoodleDoctorParams) {
  if (params.action === "check") {
    const argv = [...buildRootArgs(params)];
    addOptionalFlag(argv, "--profile", profileName(params));
    argv.push("doctor");
    addOptionalFlag(argv, "--base-url", params.baseUrl);
    addOptionalFlag(argv, "--service", params.service);
    addOptionalFlag(argv, "--token", params.token);
    if (params.offline) argv.push("--offline");
    return runMoodleCli(api, argv, "check");
  }

  if (params.action === "explain_error") {
    return result(explainMoodleError(params.error), { action: "explain_error" });
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function handleCourse(api: OpenClawPluginApi, params: MoodleCourseParams) {
  const p = paramsObject(params);
  const argv = buildBusinessRootArgs(params);

  if (params.action === "context") {
    return runMoodleCli(api, [...argv, "context", "get"], "course.context");
  }

  if (params.action === "list_courses") {
    return runMoodleCli(api, [...argv, "courses", "list"], "course.list_courses");
  }

  if (params.action === "outline") {
    return runMoodleCli(api, [...argv, "courses", "outline", "--course-id", String(readIntParam(p, "courseId", true))], "course.outline");
  }

  if (params.action === "list_activities") {
    const call = [...argv, "activities", "list", "--course-id", String(readIntParam(p, "courseId", true))];
    addOptionalFlag(call, "--modname", readStringParam(p, "modname"));
    return runMoodleCli(api, call, "course.list_activities");
  }

  if (params.action === "activity_detail") {
    return runMoodleCli(api, [...argv, "activities", "detail", "--cmid", String(readIntParam(p, "cmid", true))], "course.activity_detail");
  }

  if (params.action === "due") {
    const call = [...argv, "activities", "due"];
    addIntFlag(call, "--course-id", readIntParam(p, "courseId"));
    addIntFlag(call, "--timestart", readIntParam(p, "timestart"));
    addIntFlag(call, "--timeend", readIntParam(p, "timeend"));
    addIntFlag(call, "--limit", readIntParam(p, "limit"));
    return runMoodleCli(api, call, "course.due");
  }

  if (params.action === "resources") {
    return runMoodleCli(api, [...argv, "resources", "list", "--course-id", String(readIntParam(p, "courseId", true))], "course.resources");
  }

  if (params.action === "grades") {
    const call = [...argv, "grades", "overview"];
    addIntFlag(call, "--course-id", readIntParam(p, "courseId"));
    return runMoodleCli(api, call, "course.grades");
  }

  if (params.action === "progress") {
    const call = [...argv, "progress", "course"];
    addIntFlag(call, "--course-id", readIntParam(p, "courseId"));
    return runMoodleCli(api, call, "course.progress");
  }

  if (params.action === "notifications") {
    const call = [...argv, "notifications", "list"];
    addIntFlag(call, "--limit-from", readIntParam(p, "limitFrom"));
    addIntFlag(call, "--limit", readIntParam(p, "limit"));
    addBooleanFlag(call, "--unread-only", readBoolParam(p, "unreadOnly"));
    return runMoodleCli(api, call, "course.notifications");
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function handleQuestionbank(api: OpenClawPluginApi, params: MoodleQuestionbankParams) {
  const p = paramsObject(params);
  const argv = buildBusinessRootArgs(params);

  if (params.action === "categories") {
    const call = [...argv, "questions", "categories"];
    addIntFlag(call, "--course-id", readIntParam(p, "courseId"));
    addIntFlag(call, "--context-id", readIntParam(p, "contextId"));
    addIntFlag(call, "--parent-id", readIntParam(p, "parentId"));
    return runMoodleCli(api, call, "questionbank.categories");
  }

  if (params.action === "search") {
    const call = [...argv, "questions", "search"];
    addOptionalFlag(call, "--query", readStringParam(p, "query"));
    addIntFlag(call, "--course-id", readIntParam(p, "courseId"));
    addIntFlag(call, "--category-id", readIntParam(p, "categoryId"));
    addBooleanFlag(call, "--recurse", readBoolParam(p, "recurse"));
    addRepeatableStringFlag(call, "--qtype", readStringArrayParam(p, "qtypes"));
    addIntFlag(call, "--limit", readIntParam(p, "limit"));
    return runMoodleCli(api, call, "questionbank.search");
  }

  if (params.action === "pick_random") {
    const call = [
      ...argv,
      "questions",
      "pick-random",
      "--category-id",
      String(readIntParam(p, "categoryId", true)),
      "--count",
      String(readIntParam(p, "count", true)),
    ];
    addBooleanFlag(call, "--recurse", readBoolParam(p, "recurse"));
    addIntFlag(call, "--seed", readIntParam(p, "seed"));
    addRepeatableStringFlag(call, "--qtype", readStringArrayParam(p, "qtypes"));
    return runMoodleCli(api, call, "questionbank.pick_random");
  }

  if (params.action === "render_html") {
    const questionIds = readIntArrayParam(p, "questionIds", true);
    const call = [...argv, "questions", "render-html", ...questionIds.map(String)];
    addBooleanFlag(call, "--shuffle-answers", readBoolParam(p, "shuffleAnswers"));
    addIntFlag(call, "--seed", readIntParam(p, "seed"));
    addBooleanFlag(call, "--show-correction", readBoolParam(p, "showCorrection"));
    return runMoodleCli(api, call, "questionbank.render_html");
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function handleQuiz(api: OpenClawPluginApi, params: MoodleQuizParams) {
  const p = paramsObject(params);
  const argv = buildBusinessRootArgs(params);

  if (params.action === "list") {
    return runMoodleCli(api, [...argv, "quiz", "list", "--course-id", String(readIntParam(p, "courseId", true))], "quiz.list");
  }

  if (params.action === "create_practice") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "quiz.create_practice"),
      "quiz",
      "create-practice",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--course-id",
      String(readIntParam(p, "courseId", true)),
    ];
    addIntFlag(call, "--cmid", readIntParam(p, "cmid"));
    addOptionalFlag(call, "--lesson-key", readStringParam(p, "lessonKey"));
    addOptionalFlag(call, "--title", readStringParam(p, "title"));
    addIntFlag(call, "--count", readIntParam(p, "count"));
    addIntFlag(call, "--section", readIntParam(p, "section"));
    addRepeatableIntFlag(call, "--category-id", readIntArrayParamAny(p, ["categoryIds", "categoryId"]));
    addRepeatableStringFlag(call, "--kg-id", readStringArrayParamAny(p, ["kgIds", "kgId"]));
    addRepeatableStringFlag(call, "--qg-id", readStringArrayParamAny(p, ["qgIds", "qgId"]));
    addRepeatableStringFlag(call, "--tag", readStringArrayParamAny(p, ["tags", "tag"]));
    addIntFlag(call, "--seed", readIntParam(p, "seed"));
    addBooleanFlag(call, "--allow-partial", readBoolParam(p, "allowPartial"));
    addOptionalFlag(call, "--selection-mode", readStringParam(p, "selectionMode"));
    addBooleanFlag(call, "--random", readBoolParam(p, "random"));
    addBooleanFlag(call, "--visible", readBoolParam(p, "visible"));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "quiz.create_practice");
  }

  if (params.action === "resolve_random") {
    const call = [...argv, "quiz", "resolve-random", "--quiz-id", String(readIntParam(p, "quizId", true))];
    addIntFlag(call, "--copies", readIntParam(p, "copies"));
    addIntFlag(call, "--seed", readIntParam(p, "seed"));
    addBooleanFlag(call, "--allow-duplicates", readBoolParam(p, "allowDuplicates"));
    return runMoodleCli(api, call, "quiz.resolve_random");
  }

  if (params.action === "attempts") {
    const call = [...argv, "quiz", "attempts"];
    addIntFlag(call, "--course-id", readIntParam(p, "courseId"));
    addIntFlag(call, "--quiz-id", readIntParam(p, "quizId"));
    return runMoodleCli(api, call, "quiz.attempts");
  }

  if (params.action === "start") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "quiz.start"),
      "quiz",
      "start",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--quiz-id",
      String(readIntParam(p, "quizId", true)),
    ];
    addRepeatableJsonFlag(call, "--preflight-json", readJsonItemsParamAny(p, ["preflight", "preflightData", "preflightJson"]));
    addBooleanFlag(call, "--force-new", readBoolParam(p, "forceNew"));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "quiz.start");
  }

  if (params.action === "attempt_data") {
    const call = [...argv, "quiz", "attempt-data", "--attempt-id", String(readIntParam(p, "attemptId", true))];
    addIntFlag(call, "--page", readIntParam(p, "page"));
    addRepeatableJsonFlag(call, "--preflight-json", readJsonItemsParamAny(p, ["preflight", "preflightData", "preflightJson"]));
    return runMoodleCli(api, call, "quiz.attempt_data");
  }

  if (params.action === "attempt_summary") {
    const call = [...argv, "quiz", "attempt-summary", "--attempt-id", String(readIntParam(p, "attemptId", true))];
    addRepeatableJsonFlag(call, "--preflight-json", readJsonItemsParamAny(p, ["preflight", "preflightData", "preflightJson"]));
    return runMoodleCli(api, call, "quiz.attempt_summary");
  }

  if (params.action === "save_attempt") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "quiz.save_attempt"),
      "quiz",
      "save-attempt",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--attempt-id",
      String(readIntParam(p, "attemptId", true)),
    ];
    addRepeatableJsonFlag(call, "--response-json", readJsonItemsParamAny(p, ["responses", "responseJson"], true));
    addRepeatableJsonFlag(call, "--preflight-json", readJsonItemsParamAny(p, ["preflight", "preflightData", "preflightJson"]));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "quiz.save_attempt");
  }

  if (params.action === "submit_attempt") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "quiz.submit_attempt"),
      "quiz",
      "submit-attempt",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--attempt-id",
      String(readIntParam(p, "attemptId", true)),
    ];
    addRepeatableJsonFlag(call, "--response-json", readJsonItemsParamAny(p, ["responses", "responseJson"]));
    addRepeatableJsonFlag(call, "--preflight-json", readJsonItemsParamAny(p, ["preflight", "preflightData", "preflightJson"]));
    addBooleanFlag(call, "--timeup", readBoolParam(p, "timeup"));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "quiz.submit_attempt");
  }

  if (params.action === "answer") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "quiz.answer"),
      "quiz",
      "answer",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--attempt-id",
      String(readIntParam(p, "attemptId", true)),
    ];
    addRepeatableJsonFlag(call, "--answer-json", readJsonItemsParamAny(p, ["answers", "answerJson"], true));
    addBooleanFlag(call, "--submit", readBoolParam(p, "submit"));
    addBooleanFlag(call, "--timeup", readBoolParam(p, "timeup"));
    addRepeatableJsonFlag(call, "--preflight-json", readJsonItemsParamAny(p, ["preflight", "preflightData", "preflightJson"]));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "quiz.answer");
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function handleCalendar(api: OpenClawPluginApi, params: MoodleCalendarParams) {
  const p = paramsObject(params);
  const argv = buildBusinessRootArgs(params);

  if (params.action === "list") {
    const call = [...argv, "calendar", "list"];
    addIntFlag(call, "--timestart", readIntParam(p, "timestart"));
    addIntFlag(call, "--timeend", readIntParam(p, "timeend"));
    addIntFlag(call, "--limit", readIntParam(p, "limit"));
    return runMoodleCli(api, call, "calendar.list");
  }

  if (params.action === "publish_plan") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "calendar.publish_plan"),
      "calendar",
      "publish-plan",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
    ];
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    addRepeatableJsonFlag(call, "--item-json", readJsonItemsParamAny(p, ["items", "itemJson"], true));
    return runMoodleCli(api, call, "calendar.publish_plan");
  }

  if (params.action === "upsert_plan") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "calendar.upsert_plan"),
      "calendar",
      "upsert-plan",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--plan-key",
      requireStringParam(p, "planKey"),
    ];
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    addRepeatableJsonFlag(call, "--item-json", readJsonItemsParamAny(p, ["items", "itemJson"], true));
    return runMoodleCli(api, call, "calendar.upsert_plan");
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function handleAssignment(api: OpenClawPluginApi, params: MoodleAssignmentParams) {
  const p = paramsObject(params);
  const argv = buildBusinessRootArgs(params);

  if (params.action === "list") {
    return runMoodleCli(
      api,
      [...argv, "assignments", "list", "--course-id", String(readIntParam(p, "courseId", true))],
      "assignment.list",
    );
  }

  if (params.action === "status") {
    const call = [...argv, "assignments", "status"];
    addIntFlag(call, "--course-id", readIntParam(p, "courseId"));
    addIntFlag(call, "--assign-id", readIntParam(p, "assignId"));
    return runMoodleCli(api, call, "assignment.status");
  }

  if (params.action === "save_draft") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "assignment.save_draft"),
      "assignments",
      "save-draft",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--assign-id",
      String(readIntParam(p, "assignId", true)),
      "--text",
      requireStringParam(p, "text"),
    ];
    addIntFlag(call, "--format", readIntParam(p, "format"));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "assignment.save_draft");
  }

  if (params.action === "submit_final") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "assignment.submit_final"),
      "assignments",
      "submit-final",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--assign-id",
      String(readIntParam(p, "assignId", true)),
    ];
    addBooleanFlag(call, "--accept-submission-statement", readBoolParam(p, "acceptSubmissionStatement"));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "assignment.submit_final");
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function handleForum(api: OpenClawPluginApi, params: MoodleForumParams) {
  const p = paramsObject(params);
  const argv = buildBusinessRootArgs(params);

  if (params.action === "discussions") {
    const call = [...argv, "forum", "discussions"];
    addIntFlag(call, "--course-id", readIntParam(p, "courseId"));
    addIntFlag(call, "--forum-id", readIntParam(p, "forumId"));
    addIntFlag(call, "--limit", readIntParam(p, "limit"));
    return runMoodleCli(api, call, "forum.discussions");
  }

  if (params.action === "create_discussion") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "forum.create_discussion"),
      "forum",
      "create-discussion",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--forum-id",
      String(readIntParam(p, "forumId", true)),
      "--subject",
      requireStringParam(p, "subject"),
      "--message",
      requireStringParam(p, "message"),
    ];
    addIntFlag(call, "--group-id", readIntParam(p, "groupId"));
    if (readOptionalBoolParam(p, "subscribe") === false) call.push("--no-subscribe");
    addBooleanFlag(call, "--pinned", readBoolParam(p, "pinned"));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "forum.create_discussion");
  }

  if (params.action === "reply") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "forum.reply"),
      "forum",
      "reply",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--post-id",
      String(readIntParam(p, "postId", true)),
      "--subject",
      requireStringParam(p, "subject"),
      "--message",
      requireStringParam(p, "message"),
    ];
    if (readOptionalBoolParam(p, "subscribe") === false) call.push("--no-subscribe");
    addBooleanFlag(call, "--private-reply", readBoolParam(p, "privateReply"));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "forum.reply");
  }

  if (params.action === "update_post") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "forum.update_post"),
      "forum",
      "update-post",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--post-id",
      String(readIntParam(p, "postId", true)),
    ];
    addOptionalFlag(call, "--subject", readStringParam(p, "subject"));
    addOptionalFlag(call, "--message", readStringParam(p, "message"));
    if (readOptionalBoolParam(p, "subscribe") === false) call.push("--no-subscribe");
    addBooleanFlag(call, "--pinned", readBoolParam(p, "pinned"));
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "forum.update_post");
  }

  if (params.action === "delete_post") {
    const call = [
      ...buildWriteBusinessRootArgs(api, params, p, "forum.delete_post", { destructive: true }),
      "forum",
      "delete-post",
      "--idempotency-key",
      String(readIdempotencyKey(params, p, true)),
      "--post-id",
      String(readIntParam(p, "postId", true)),
    ];
    addOptionalFlag(call, "--reason", readStringParam(p, "reason"));
    return runMoodleCli(api, call, "forum.delete_post");
  }

  return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
}

function handleApi(api: OpenClawPluginApi, params: MoodleApiParams) {
  const manifest = loadManifest(api);
  const functions = arrayFromManifest(manifest, "functions");
  const commands = arrayFromManifest(manifest, "commands");
  const allowed = configuredAllowedApiFunctions(api);

  const allowedFunctions = functions.filter((entry) => {
    const id = String(entry["id"] || "");
    if (!id.startsWith("local_aiagentapi_")) return false;
    return allowed.size === 0 ? entry["allowedByDefault"] === true : allowed.has(id);
  });

  if (params.action === "list_allowed") {
    return result(allowedFunctions, {
      action: "api.list_allowed",
      meta: { count: allowedFunctions.length, enabled: configuredEnableApiTool(api) },
    });
  }

  const functionId = (params.functionId || "").trim();
  if (!functionId) return error("functionId is required", { action: `api.${params.action}` });

  const functionEntry = byId(functions, functionId);
  if (!functionEntry) return error(`Unknown function id: ${functionId}`, { action: `api.${params.action}` });

  if (params.action === "get_function") {
    return result(functionEntry, { action: "api.get_function" });
  }

  if (params.action !== "call") {
    return error(`Unsupported action: ${(params as { action?: string }).action || ""}`);
  }

  if (!configuredEnableApiTool(api)) {
    return error("moodle_api.call requires plugin config enableApiTool=true", { action: "api.call" });
  }

  if (!String(functionEntry["id"] || "").startsWith("local_aiagentapi_")) {
    return error("moodle_api.call only supports local_aiagentapi_* functions", { action: "api.call", functionId });
  }

  const allowlisted = allowed.size === 0 ? functionEntry["allowedByDefault"] === true : allowed.has(functionId);
  if (!allowlisted) {
    return error("functionId is not allowed by current moodle_api policy", { action: "api.call", functionId });
  }

  const commandEntry = commands.find((entry) => entry["webservice"] === functionId);
  if (!commandEntry) {
    return error(`No structured CLI command maps to ${functionId}`, { action: "api.call", functionId });
  }

  const risk = String(commandEntry["risk"] || functionEntry["risk"] || "read");
  const writeRisk = ["write", "high_write", "destructive"].includes(risk);
  if (writeRisk && !configuredAllowApiWrites(api)) {
    return error("write-capable moodle_api.call requires plugin config allowApiWrites=true", {
      action: "api.call",
      functionId,
      risk,
    });
  }

  const p = paramsObject(params);
  const rootArgs = writeRisk
    ? buildWriteBusinessRootArgs(api, params, p, `api.call:${functionId}`, {
        supportsDryRun: commandEntry["supportsDryRun"] === true,
        destructive: risk === "destructive",
      })
    : buildBusinessRootArgs(params);

  const cli = commandEntry["cli"] as ManifestEntry | undefined;
  const path = Array.isArray(cli?.["path"]) ? cli?.["path"].map(String).filter(Boolean) : [];
  if (path.length === 0) return error(`Command mapping for ${functionId} is missing cli.path`, { action: "api.call" });

  const argv = [...rootArgs, ...path];
  const paramSchemas = commandEntry["params"] as Record<string, ManifestEntry> | undefined;
  const argMap = cli?.["argv"] as Record<string, string> | undefined;
  if (paramSchemas && argMap) {
    for (const [paramName, schema] of Object.entries(paramSchemas)) {
      const value = valueForManifestParam(p, paramName, schema, params);
      if (schema["required"] === true && isMissingManifestValue(value)) {
        throw new Error(`${paramName} is required`);
      }
      const flag = argMap[paramName];
      if (!flag) continue;
      addManifestArg(argv, flag, value, schema["repeatableCli"] === true);
    }
  }

  return runMoodleCli(api, argv, `api.call:${functionId}`);
}

export default function register(api: OpenClawPluginApi): void {
  api.registerTool({
    name: "moodle_catalog",
    description:
      "Search and inspect the local Moodle command manifest. Start here when the user intent is unclear, " +
      "then call the matching Moodle domain tool. This tool is read-only and performs no Moodle network I/O.",
    parameters: MoodleCatalogSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleCatalog(api, params as MoodleCatalogParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "catalog_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_auth",
    description:
      "Manage local Moodle CLI profiles and login state through structured CLI actions. " +
      "This tool does not perform Moodle business writes. Local write actions such as logout/use_profile require confirm=true.",
    parameters: MoodleAuthSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleAuth(api, params as MoodleAuthParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "auth_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_doctor",
    description:
      "Diagnose Moodle CLI config, token, service, capability, and connectivity problems. " +
      "Use after a failed Moodle tool call; explain_error returns deterministic repair hints from a captured error string.",
    parameters: MoodleDoctorSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleDoctor(api, params as MoodleDoctorParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "doctor_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_course",
    description:
      "Read Moodle course, activity, resource, grade, progress, notification, and due-work data through structured CLI actions. " +
      "Use for course/user context before quiz, assignment, or calendar work. This tool is read-only.",
    parameters: MoodleCourseSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleCourse(api, params as MoodleCourseParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "course_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_questionbank",
    description:
      "Read Moodle question-bank categories, search questions, pick random questions, and render question HTML through structured CLI actions. " +
      "Use before quiz creation when you need category ids or preview question content. This tool is read-only.",
    parameters: MoodleQuestionbankSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleQuestionbank(api, params as MoodleQuestionbankParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "questionbank_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_quiz",
    description:
      "Read Moodle quizzes and attempts, resolve random questions, and perform guarded quiz writes through structured CLI actions. " +
      "For writes, preview first with the default dryRun=true; set dryRun=false only with confirm=true and an idempotencyKey.",
    parameters: MoodleQuizSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleQuiz(api, params as MoodleQuizParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "quiz_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_calendar",
    description:
      "Read Moodle calendar events and publish/upsert study plan events through structured CLI actions. " +
      "For writes, preview first with the default dryRun=true; set dryRun=false only with confirm=true and an idempotencyKey.",
    parameters: MoodleCalendarSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleCalendar(api, params as MoodleCalendarParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "calendar_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_assignment",
    description:
      "Read Moodle assignments and guardedly save drafts or submit final work through structured CLI actions. " +
      "For writes, preview first with the default dryRun=true; set dryRun=false only with confirm=true and an idempotencyKey.",
    parameters: MoodleAssignmentSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleAssignment(api, params as MoodleAssignmentParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "assignment_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_forum",
    description:
      "Read Moodle forum discussions and guardedly create, reply, update, or delete posts through structured CLI actions. " +
      "For writes, preview first with the default dryRun=true; delete_post also requires allowDestructive=true when dryRun=false.",
    parameters: MoodleForumSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleForum(api, params as MoodleForumParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "forum_error" });
      }
    },
  });

  api.registerTool({
    name: "moodle_api",
    description:
      "Optional advanced escape hatch for manifest-listed local_aiagentapi functions. Prefer domain tools first. " +
      "call is disabled unless enableApiTool=true; write calls also require allowApiWrites=true and normal write gates.",
    parameters: MoodleApiSchema,
    async execute(_toolCallId: string, params: unknown) {
      try {
        return handleApi(api, params as MoodleApiParams);
      } catch (err) {
        return error(err instanceof Error ? err.message : String(err), { action: "api_error" });
      }
    },
  }, { optional: true });
}
