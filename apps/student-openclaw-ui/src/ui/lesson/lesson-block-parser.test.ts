import { describe, expect, it } from "vitest";
import { parseLessonBlocks } from "./lesson-block-parser.ts";

describe("parseLessonBlocks", () => {
  it("parses plan, video, quiz, and analysis blocks", () => {
    const input = [
      "今天我们按这个顺序来：",
      "```plan-card",
      JSON.stringify({
        heading: "今日安排",
        items: [{ title: "概念导学", duration: "5m", status: "active" }],
      }),
      "```",
      "```video-card",
      JSON.stringify({ title: "牛顿第二定律", duration: "6m" }),
      "```",
      "```quiz-card",
      JSON.stringify({
        question: "哪项正确？",
        options: [{ id: "A", text: "答案 A" }],
      }),
      "```",
      "```analysis-card",
      JSON.stringify({
        summary: "需要继续巩固",
        points: [{ label: "速度与加速度", level: "weak" }],
      }),
      "```",
    ].join("\n");

    const segments = parseLessonBlocks(input);
    expect(segments.map((segment) => segment.type)).toEqual([
      "text",
      "plan-card",
      "video-card",
      "quiz-card",
      "analysis-card",
    ]);
  });
});
