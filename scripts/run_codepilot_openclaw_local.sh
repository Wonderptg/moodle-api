#!/usr/bin/env bash
set -euo pipefail

ROOT="/Users/wonder/Documents/moodle"
CODEPILOT_DIR="$ROOT/references/codepilot-full"
OPENCLAW_CONFIG="/Users/wonder/Documents/openclaw/.openclaw/openclaw.json"
OPENCLAW_STATE="/Users/wonder/Documents/openclaw/.openclaw/state"
PORT="${PORT:-4012}"
HOST="${HOST:-127.0.0.1}"
AGENT_ID="${OPENCLAW_AGENT_ID:-student-ui}"

if [[ ! -f "$OPENCLAW_CONFIG" ]]; then
  echo "OpenClaw config not found: $OPENCLAW_CONFIG" >&2
  exit 1
fi

TOKEN="$(
  python3 - <<'PY'
import json
path = "/Users/wonder/Documents/openclaw/.openclaw/openclaw.json"
with open(path) as f:
    cfg = json.load(f)
print(cfg["gateway"]["auth"]["token"], end="")
PY
)"

if [[ -z "$TOKEN" ]]; then
  echo "Failed to read gateway token from $OPENCLAW_CONFIG" >&2
  exit 1
fi

cd "$CODEPILOT_DIR"

export OPENCLAW_GATEWAY_URL="http://127.0.0.1:18789"
export OPENCLAW_CONFIG_PATH="$OPENCLAW_CONFIG"
export OPENCLAW_STATE_DIR="$OPENCLAW_STATE"
export CODEPILOT_RUNTIME_BACKEND="openclaw"
export OPENCLAW_AGENT_ID="$AGENT_ID"
export OPENCLAW_GATEWAY_TOKEN="$TOKEN"
export HOST
export PORT

echo "Starting CodePilot with OpenClaw backend"
echo "  URL: http://$HOST:$PORT"
echo "  Agent: $AGENT_ID"
echo "  Gateway: $OPENCLAW_GATEWAY_URL"

exec pnpm dev
