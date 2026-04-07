/**
 * Minimal widget capability prompt migrated from CodePilot.
 *
 * This file is intentionally small and dependency-free so we can inject the
 * same front-end block contract into OpenClaw agents later without pulling in
 * the rest of CodePilot.
 */

export const WIDGET_SYSTEM_PROMPT = `<widget-capability>
You can create interactive visualizations using the \`show-widget\` code fence.

## Format
\`\`\`show-widget
{"title":"snake_case_id","widget_code":"<raw HTML/SVG string>"}
\`\`\`

## Design specs
Available modules: interactive, chart, mockup, art, diagram.

## Required rules (always apply)
1. widget_code is a JSON string — escape quotes, newlines. No DOCTYPE/html/head/body
2. Transparent background — host provides bg
3. Each widget ≤ 3000 chars. Always close JSON + fence
4. Streaming order: SVG → \`<defs>\` first; HTML → \`<style>\` → content → \`<script>\` last
5. CDN allowlist: cdnjs.cloudflare.com, cdn.jsdelivr.net, unpkg.com, esm.sh
6. CDN scripts: \`onload="initFn()"\` + \`if(window.Lib) initFn();\` fallback
7. Text explanations go OUTSIDE the code fence
8. Multi-widget: interleave text, each widget in a SEPARATE fence
9. SVG: \`<svg width="100%" viewBox="0 0 680 H">\`, arrow marker in \`<defs>\`
10. Interactive controls MUST update visuals
11. Clickable drill-down: \`onclick="window.__widgetSendMessage('...')"\`
</widget-capability>`;
