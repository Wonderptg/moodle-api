import { definePluginEntry } from "openclaw/plugin-sdk/plugin-entry";
import { registerMoodleTools } from "./shared/openclaw-tools.mjs";

export default definePluginEntry({
  id: "moodle-aiagent-stack",
  name: "Moodle AI Agent Stack",
  description: "OpenClaw tool surface for the local Moodle local_aiagentapi stack.",
  register(api) {
    registerMoodleTools(api);
  },
});
