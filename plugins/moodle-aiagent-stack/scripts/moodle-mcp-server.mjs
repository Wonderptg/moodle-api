#!/usr/bin/env node
import {
  TOOL_ACTIONS,
  TOOL_DESCRIPTIONS,
  VALID_LAYERS,
  VALID_RISKS,
  handleToolPayload,
} from "../shared/runtime.mjs";

const PROTOCOL_VERSION = "2024-11-05";
const SERVER_VERSION = "0.1.0";

function stringSchema(description) {
  return { type: "string", description };
}

function booleanSchema(description, defaultValue) {
  return { type: "boolean", description, default: defaultValue };
}

function actionSchema(toolName, extra = {}) {
  return {
    type: "object",
    additionalProperties: false,
    required: ["action"],
    properties: {
      action: {
        type: "string",
        enum: TOOL_ACTIONS[toolName],
        description: "Tool action to run.",
      },
      params: {
        type: "object",
        additionalProperties: true,
        description: "Action-specific parameters. Use moodle_catalog get_command when unsure.",
      },
      profile: stringSchema("CLI profile name."),
      name: stringSchema("CLI profile name alias used by auth actions."),
      configDir: stringSchema("CLI config directory."),
      envFile: stringSchema("Optional .env file passed to scripts/moodle_cli.py."),
      dryRun: booleanSchema("Preview write actions instead of changing Moodle state.", true),
      confirm: booleanSchema("Required when dryRun=false for write/destructive actions.", false),
      idempotencyKey: stringSchema("Stable client idempotency key for write actions."),
      ...extra,
    },
  };
}

const TOOL_SCHEMAS = {
  moodle_catalog: {
    type: "object",
    additionalProperties: false,
    required: ["action"],
    properties: {
      action: { type: "string", enum: TOOL_ACTIONS.moodle_catalog },
      query: stringSchema("Free-text search query. Used by action=search."),
      id: stringSchema("Command or function id. Used by get_command/get_function."),
      domain: stringSchema("Optional manifest domain filter, such as quiz or course."),
      layer: { type: "string", enum: VALID_LAYERS, default: "all" },
      risk: { type: "string", enum: VALID_RISKS, default: "all" },
      limit: { type: "number", minimum: 1, maximum: 50, default: 20 },
    },
  },
  moodle_auth: actionSchema("moodle_auth", {
    baseUrl: stringSchema("Moodle base URL."),
    service: stringSchema("Moodle external service shortname."),
    offline: booleanSchema("Skip network checks where supported.", false),
    noWait: booleanSchema("Return login device code immediately.", true),
    openBrowser: booleanSchema("Allow opening a browser during login.", false),
  }),
  moodle_doctor: actionSchema("moodle_doctor", {
    error: stringSchema("Error text/code to explain."),
    baseUrl: stringSchema("Moodle base URL."),
    service: stringSchema("Moodle external service shortname."),
    token: stringSchema("Web service token for one-off doctor checks."),
    offline: booleanSchema("Skip network checks.", false),
  }),
  moodle_course: actionSchema("moodle_course"),
  moodle_questionbank: actionSchema("moodle_questionbank"),
  moodle_quiz: actionSchema("moodle_quiz"),
  moodle_calendar: actionSchema("moodle_calendar"),
  moodle_assignment: actionSchema("moodle_assignment"),
  moodle_forum: actionSchema("moodle_forum"),
  moodle_api: actionSchema("moodle_api", {
    functionId: stringSchema("Manifest-listed local_aiagentapi function id."),
  }),
};

function mcpToolResult(payload, isError = false) {
  return {
    content: [{ type: "text", text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload,
    isError,
  };
}

function toolsList() {
  return Object.keys(TOOL_ACTIONS).map((name) => ({
    name,
    description: TOOL_DESCRIPTIONS[name],
    inputSchema: TOOL_SCHEMAS[name],
  }));
}

function jsonRpcResponse(id, result) {
  return { jsonrpc: "2.0", id, result };
}

function jsonRpcError(id, code, message, data) {
  return { jsonrpc: "2.0", id, error: { code, message, ...(data === undefined ? {} : { data }) } };
}

function sendMessage(message) {
  const body = Buffer.from(JSON.stringify(message), "utf8");
  process.stdout.write(`Content-Length: ${body.length}\r\n\r\n`);
  process.stdout.write(body);
}

async function dispatch(message) {
  if (!message || typeof message !== "object") return;
  const id = message.id;
  const method = message.method;

  if (method === "notifications/initialized") return;

  try {
    if (method === "initialize") {
      sendMessage(jsonRpcResponse(id, {
        protocolVersion: PROTOCOL_VERSION,
        capabilities: { tools: {} },
        serverInfo: { name: "moodle-aiagent-stack", version: SERVER_VERSION },
      }));
      return;
    }

    if (method === "tools/list") {
      sendMessage(jsonRpcResponse(id, { tools: toolsList() }));
      return;
    }

    if (method === "tools/call") {
      const params = message.params || {};
      const payload = handleToolPayload({ host: "mcp" }, params.name, params.arguments || {});
      sendMessage(jsonRpcResponse(id, mcpToolResult(payload, payload.ok === false)));
      return;
    }

    if (method === "ping") {
      sendMessage(jsonRpcResponse(id, {}));
      return;
    }

    sendMessage(jsonRpcError(id, -32601, `Method not found: ${method}`));
  } catch (error) {
    sendMessage(jsonRpcError(id, -32603, error instanceof Error ? error.message : String(error)));
  }
}

function processInput() {
  let buffer = Buffer.alloc(0);

  process.stdin.on("data", (chunk) => {
    buffer = Buffer.concat([buffer, chunk]);

    while (true) {
      const headerEnd = buffer.indexOf("\r\n\r\n");
      if (headerEnd === -1) return;

      const header = buffer.subarray(0, headerEnd).toString("ascii");
      const match = header.match(/Content-Length:\s*(\d+)/i);
      if (!match) {
        buffer = buffer.subarray(headerEnd + 4);
        continue;
      }

      const length = Number.parseInt(match[1], 10);
      const bodyStart = headerEnd + 4;
      const bodyEnd = bodyStart + length;
      if (buffer.length < bodyEnd) return;

      const body = buffer.subarray(bodyStart, bodyEnd).toString("utf8");
      buffer = buffer.subarray(bodyEnd);

      try {
        dispatch(JSON.parse(body));
      } catch (error) {
        sendMessage(jsonRpcError(null, -32700, error instanceof Error ? error.message : String(error)));
      }
    }
  });
}

processInput();
