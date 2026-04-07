import { describe, expect, it } from "vitest";
import {
  extractText,
  extractTextCached,
  extractThinking,
  extractThinkingCached,
  stripStudentUiContract,
} from "./message-extract.ts";

describe("extractTextCached", () => {
  it("matches extractText output", () => {
    const message = {
      role: "assistant",
      content: [{ type: "text", text: "Hello there" }],
    };
    expect(extractTextCached(message)).toBe(extractText(message));
  });

  it("returns consistent output for repeated calls", () => {
    const message = {
      role: "user",
      content: "plain text",
    };
    expect(extractTextCached(message)).toBe("plain text");
    expect(extractTextCached(message)).toBe("plain text");
  });
});

describe("extractThinkingCached", () => {
  it("matches extractThinking output", () => {
    const message = {
      role: "assistant",
      content: [{ type: "thinking", thinking: "Plan A" }],
    };
    expect(extractThinkingCached(message)).toBe(extractThinking(message));
  });

  it("returns consistent output for repeated calls", () => {
    const message = {
      role: "assistant",
      content: [{ type: "thinking", thinking: "Plan A" }],
    };
    expect(extractThinkingCached(message)).toBe("Plan A");
    expect(extractThinkingCached(message)).toBe("Plan A");
  });
});

describe("stripStudentUiContract", () => {
  it("extracts only the original user request from hidden lesson contract messages", () => {
    const raw = [
      "Student UI contract for this turn:",
      "some hidden instructions",
      "",
      "User request:",
      "请开始今天的物理课。",
    ].join("\n");
    expect(stripStudentUiContract(raw)).toBe("请开始今天的物理课。");
  });
});

describe("extractText", () => {
  it("hides the student ui contract for user messages loaded from history", () => {
    const message = {
      role: "user",
      content: [{
        type: "text",
        text: [
          "Student UI contract for this turn:",
          "hidden",
          "",
          "User request:",
          "请开始今天的物理课。",
        ].join("\n"),
      }],
    };
    expect(extractText(message)).toBe("请开始今天的物理课。");
  });
});
