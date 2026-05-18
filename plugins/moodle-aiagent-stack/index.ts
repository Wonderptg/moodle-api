import type { OpenClawPluginApi } from "openclaw/plugin-sdk";
import {
  TOOL_ACTIONS,
  TOOL_DESCRIPTIONS,
  VALID_LAYERS,
  VALID_RISKS,
  handleToolPayload,
  openClawToolResult,
} from "./shared/runtime.mjs";

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

function actionSchema(toolName: keyof typeof TOOL_ACTIONS): JsonSchema {
  return Type.Union(TOOL_ACTIONS[toolName].map((value) => Type.Literal(value)), {
    description: "Tool action to run.",
  });
}

function paramsSchema(description: string): JsonSchema {
  return Type.Object(
    {},
    {
      additionalProperties: true,
      description,
    },
  );
}

function domainSchema(
  toolName: keyof typeof TOOL_ACTIONS,
  paramsDescription: string,
  options: { write?: boolean; destructive?: boolean } = {},
): JsonSchema {
  return Type.Object(
    {
      action: actionSchema(toolName),
      params: Type.Optional(paramsSchema(paramsDescription)),
      profile: Type.Optional(Type.String({ description: "CLI profile name." })),
      configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
      ...(options.write
        ? {
            dryRun: Type.Optional(
              Type.Boolean({
                description: options.destructive
                  ? "Preview write/destructive actions instead of changing Moodle state."
                  : "Preview write actions instead of changing Moodle state.",
                default: true,
              }),
            ),
            confirm: Type.Optional(
              Type.Boolean({
                description: options.destructive
                  ? "Required when dryRun=false for write/destructive actions."
                  : "Required when dryRun=false for write actions.",
                default: false,
              }),
            ),
            idempotencyKey: Type.Optional(
              Type.String({
                description: options.destructive
                  ? "Stable client idempotency key for write/destructive actions."
                  : "Stable client idempotency key for write actions.",
              }),
            ),
          }
        : {}),
    },
    { additionalProperties: false },
  );
}

const MoodleCatalogSchema = Type.Object(
  {
    action: actionSchema("moodle_catalog"),
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
    action: actionSchema("moodle_auth"),
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
    action: actionSchema("moodle_doctor"),
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

const MoodleCourseSchema = domainSchema(
  "moodle_course",
  "Action-specific parameters. Common keys: courseId, cmid, modname, timestart, timeend, limit, limitFrom, unreadOnly.",
);

const MoodleQuestionbankSchema = domainSchema(
  "moodle_questionbank",
  "Action-specific parameters. Common keys: courseId, contextId, parentId, query, categoryId, recurse, qtypes, limit, count, seed, questionIds.",
);

const MoodleQuizSchema = domainSchema(
  "moodle_quiz",
  "Action-specific parameters. Common keys: courseId, quizId, attemptId, categoryIds, count, responses, answers, preflight.",
  { write: true },
);

const MoodleCalendarSchema = domainSchema(
  "moodle_calendar",
  "Action-specific parameters. Common keys: timestart, timeend, limit, planKey, items, reason.",
  { write: true },
);

const MoodleAssignmentSchema = domainSchema(
  "moodle_assignment",
  "Action-specific parameters. Common keys: courseId, assignId, text, format, acceptSubmissionStatement, reason.",
  { write: true },
);

const MoodleForumSchema = domainSchema(
  "moodle_forum",
  "Action-specific parameters. Common keys: courseId, forumId, postId, subject, message, subscribe, pinned, privateReply, reason.",
  { write: true, destructive: true },
);

const MoodleApiSchema = Type.Object(
  {
    action: actionSchema("moodle_api"),
    functionId: Type.Optional(Type.String({ description: "Manifest-listed local_aiagentapi function id." })),
    params: Type.Optional(
      paramsSchema("Low-level API parameters. Use API names from moodle_catalog get_function."),
    ),
    profile: Type.Optional(Type.String({ description: "CLI profile name." })),
    configDir: Type.Optional(Type.String({ description: "CLI config directory." })),
    dryRun: Type.Optional(Type.Boolean({ description: "Preview write calls where the mapped command supports it.", default: true })),
    confirm: Type.Optional(Type.Boolean({ description: "Required when dryRun=false for write calls.", default: false })),
    idempotencyKey: Type.Optional(Type.String({ description: "Stable client idempotency key for write calls." })),
  },
  { additionalProperties: false },
);

const TOOL_DEFINITIONS: Array<{
  name: keyof typeof TOOL_ACTIONS;
  parameters: JsonSchema;
  optional?: boolean;
}> = [
  { name: "moodle_catalog", parameters: MoodleCatalogSchema },
  { name: "moodle_auth", parameters: MoodleAuthSchema },
  { name: "moodle_doctor", parameters: MoodleDoctorSchema },
  { name: "moodle_course", parameters: MoodleCourseSchema },
  { name: "moodle_questionbank", parameters: MoodleQuestionbankSchema },
  { name: "moodle_quiz", parameters: MoodleQuizSchema },
  { name: "moodle_calendar", parameters: MoodleCalendarSchema },
  { name: "moodle_assignment", parameters: MoodleAssignmentSchema },
  { name: "moodle_forum", parameters: MoodleForumSchema },
  { name: "moodle_api", parameters: MoodleApiSchema, optional: true },
];

function runtimeOptions(api: OpenClawPluginApi) {
  return {
    host: "openclaw",
    config: (api.pluginConfig || {}) as Record<string, unknown>,
  };
}

function openClawError(message: string, action: string) {
  return openClawToolResult({ ok: false, error: message, action });
}

export default function register(api: OpenClawPluginApi): void {
  for (const definition of TOOL_DEFINITIONS) {
    const tool = {
      name: definition.name,
      description: TOOL_DESCRIPTIONS[definition.name],
      parameters: definition.parameters,
      async execute(_toolCallId: string, params: unknown) {
        try {
          return openClawToolResult(handleToolPayload(runtimeOptions(api), definition.name, params));
        } catch (err) {
          return openClawError(err instanceof Error ? err.message : String(err), `${definition.name}.error`);
        }
      },
    };

    if (definition.optional) {
      api.registerTool(tool, { optional: true });
    } else {
      api.registerTool(tool);
    }
  }
}
