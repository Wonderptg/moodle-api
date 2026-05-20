#!/usr/bin/env node
import { spawn } from "node:child_process";
import { once } from "node:events";
import { resolve } from "node:path";

const ROOT = resolve(import.meta.dirname, "..");
const SERVER = resolve(ROOT, "plugins/moodle-aiagent-stack/scripts/moodle-mcp-server.mjs");
const EXPECTED_TOOLS = [
  "moodle_catalog",
  "moodle_auth",
  "moodle_doctor",
  "moodle_course",
  "moodle_questionbank",
  "moodle_quiz",
  "moodle_calendar",
  "moodle_assignment",
  "moodle_forum",
  "moodle_api",
];

function encode(message) {
  const body = Buffer.from(JSON.stringify(message), "utf8");
  return Buffer.concat([Buffer.from(`Content-Length: ${body.length}\r\n\r\n`, "ascii"), body]);
}

function parseFrames(buffer) {
  const messages = [];
  let rest = buffer;
  while (true) {
    const headerEnd = rest.indexOf("\r\n\r\n");
    if (headerEnd === -1) return { messages, rest };
    const header = rest.subarray(0, headerEnd).toString("ascii");
    const match = header.match(/Content-Length:\s*(\d+)/i);
    if (!match) throw new Error(`missing Content-Length in header: ${header}`);
    const length = Number.parseInt(match[1], 10);
    const bodyStart = headerEnd + 4;
    const bodyEnd = bodyStart + length;
    if (rest.length < bodyEnd) return { messages, rest };
    messages.push(JSON.parse(rest.subarray(bodyStart, bodyEnd).toString("utf8")));
    rest = rest.subarray(bodyEnd);
  }
}

async function main() {
  const child = spawn("node", [SERVER], {
    cwd: ROOT,
    stdio: ["pipe", "pipe", "pipe"],
    env: {
      ...process.env,
      MOODLE_DEFAULT_WRITE_DRY_RUN: "true",
      MOODLE_ENABLE_API_TOOL: "false",
      MOODLE_ALLOW_API_WRITES: "false",
      MOODLE_ALLOW_DESTRUCTIVE: "false",
    },
  });

  let stdout = Buffer.alloc(0);
  const pending = new Map();

  child.stdout.on("data", (chunk) => {
    stdout = Buffer.concat([stdout, chunk]);
    const parsed = parseFrames(stdout);
    stdout = parsed.rest;
    for (const message of parsed.messages) {
      const resolvePending = pending.get(message.id);
      if (resolvePending) {
        pending.delete(message.id);
        resolvePending(message);
      }
    }
  });

  let stderr = "";
  child.stderr.on("data", (chunk) => {
    stderr += chunk.toString("utf8");
  });

  let id = 1;
  function request(method, params = {}) {
    const currentId = id++;
    const promise = new Promise((resolveResponse) => pending.set(currentId, resolveResponse));
    child.stdin.write(encode({ jsonrpc: "2.0", id: currentId, method, params }));
    return promise;
  }

  const initialize = await request("initialize", {
    protocolVersion: "2024-11-05",
    capabilities: {},
    clientInfo: { name: "moodle-mcp-validator", version: "0.1.0" },
  });
  if (initialize.error) throw new Error(`initialize failed: ${JSON.stringify(initialize.error)}`);

  child.stdin.write(encode({ jsonrpc: "2.0", method: "notifications/initialized" }));

  const list = await request("tools/list");
  if (list.error) throw new Error(`tools/list failed: ${JSON.stringify(list.error)}`);
  const toolNames = (list.result?.tools || []).map((tool) => tool.name).sort();
  const missing = EXPECTED_TOOLS.filter((name) => !toolNames.includes(name));
  if (missing.length > 0) throw new Error(`missing MCP tools: ${missing.join(", ")}`);

  const catalog = await request("tools/call", {
    name: "moodle_catalog",
    arguments: { action: "search", query: "course outline", limit: 3 },
  });
  const catalogPayload = catalog.result?.structuredContent;
  if (catalog.error || catalogPayload?.ok !== true || !Array.isArray(catalogPayload.data)) {
    throw new Error(`moodle_catalog search failed: ${JSON.stringify(catalog)}`);
  }

  const explain = await request("tools/call", {
    name: "moodle_doctor",
    arguments: { action: "explain_error", error: "invalidtoken" },
  });
  const explainPayload = explain.result?.structuredContent;
  if (explain.error || explainPayload?.data?.category !== "auth") {
    throw new Error(`moodle_doctor explain_error failed: ${JSON.stringify(explain)}`);
  }

  child.stdin.end();
  await once(child, "exit");
  if (child.exitCode !== 0) throw new Error(`MCP server exited ${child.exitCode}: ${stderr}`);

  console.log(JSON.stringify({
    ok: true,
    tools: toolNames,
    catalogResults: catalogPayload.data.length,
    doctorCategory: explainPayload.data.category,
  }, null, 2));
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
});
