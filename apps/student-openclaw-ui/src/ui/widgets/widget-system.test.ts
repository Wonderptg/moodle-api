import { describe, expect, it } from "vitest";
import { buildReceiverSrcdoc, sanitizeForIframe, sanitizeForStreaming } from "./widget-sanitizer.ts";
import { parseAllShowWidgets, parseStreamingShowWidgets } from "./show-widget-parser.ts";
import { WIDGET_SYSTEM_PROMPT } from "./widget-guidelines.ts";

describe("widget sanitization", () => {
  it("strips scripts and handlers for streaming preview", () => {
    const html = '<div onclick="alert(1)">Hello</div><script>alert(1)</script>';
    const result = sanitizeForStreaming(html);
    expect(result).not.toContain("<script");
    expect(result).not.toContain("onclick");
    expect(result).toContain("<div");
  });

  it("keeps scripts for finalized iframe content", () => {
    const html = '<div onclick="go()">Hi</div><script>run()</script><iframe src="x"></iframe>';
    const result = sanitizeForIframe(html);
    expect(result).toContain("<script>run()</script>");
    expect(result).toContain('onclick="go()"');
    expect(result).not.toContain("<iframe");
  });
});

describe("show-widget parsing", () => {
  it("parses complete widgets interleaved with text", () => {
    const input = [
      "Intro",
      "```show-widget",
      '{"title":"demo","widget_code":"<div>Chart</div>"}',
      "```",
      "After",
    ].join("\n");

    const segments = parseAllShowWidgets(input);
    expect(segments).toHaveLength(3);
    expect(segments[0]).toEqual({ type: "text", content: "Intro" });
    expect(segments[1]).toEqual({
      type: "widget",
      data: { title: "demo", widget_code: "<div>Chart</div>" },
    });
    expect(segments[2]).toEqual({ type: "text", content: "After" });
  });

  it("extracts partial widget code during streaming", () => {
    const input = [
      "先看一个图：",
      "```show-widget",
      '{"title":"demo","widget_code":"<svg><rect width=\\"100\\"/></svg><script>go()"',
    ].join("\n");

    const parsed = parseStreamingShowWidgets(input);
    expect(parsed.hasWidgetFence).toBe(true);
    expect(parsed.segments[0]).toEqual({ type: "text", content: "先看一个图：" });
    expect(parsed.partialWidget?.title).toBe("demo");
    expect(parsed.partialWidget?.widget_code).toContain("<svg>");
    expect(parsed.partialWidget?.widget_code).not.toContain("<script");
    expect(parsed.scriptsTruncated).toBe(true);
  });
});

describe("receiver srcdoc", () => {
  it("includes CSP, receiver hooks, and sendMessage bridge", () => {
    const srcdoc = buildReceiverSrcdoc();
    expect(srcdoc).toContain("Content-Security-Policy");
    expect(srcdoc).toContain("widget:update");
    expect(srcdoc).toContain("widget:finalize");
    expect(srcdoc).toContain("widget:resize");
    expect(srcdoc).toContain("window.__widgetSendMessage");
    expect(srcdoc).toContain("connect-src 'none'");
  });
});

describe("widget system prompt", () => {
  it("documents show-widget contract", () => {
    expect(WIDGET_SYSTEM_PROMPT).toContain("```show-widget");
    expect(WIDGET_SYSTEM_PROMPT).toContain("widget_code");
    expect(WIDGET_SYSTEM_PROMPT).toContain("window.__widgetSendMessage");
  });
});
