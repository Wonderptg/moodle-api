import type { ShowWidgetData } from "../widgets/show-widget-parser.ts";

export type VideoCardData = {
  title: string;
  duration?: string;
  course?: string;
  summary?: string;
  ctaLabel?: string;
};

export type QuizOption = {
  id: string;
  text: string;
};

export type QuizCardData = {
  question: string;
  options: QuizOption[];
  correctOptionId?: string;
  explanation?: string;
};

export type PlanCardItem = {
  title: string;
  duration?: string;
  status?: "todo" | "active" | "done";
};

export type PlanCardData = {
  heading?: string;
  items: PlanCardItem[];
};

export type AnalysisCardPoint = {
  label: string;
  detail?: string;
  level?: "strong" | "watch" | "weak";
};

export type AnalysisCardData = {
  title?: string;
  summary?: string;
  points: AnalysisCardPoint[];
  nextStep?: string;
};

export type LessonSegment =
  | { type: "text"; content: string }
  | { type: "widget"; data: ShowWidgetData }
  | { type: "video-card"; data: VideoCardData }
  | { type: "quiz-card"; data: QuizCardData }
  | { type: "plan-card"; data: PlanCardData }
  | { type: "analysis-card"; data: AnalysisCardData };

const FENCE_REGEX = /```(show-widget|video-card|quiz-card|plan-card|analysis-card)\s*\n?([\s\S]*?)\n?\s*```/g;

export function parseLessonBlocks(text: string): LessonSegment[] {
  const segments: LessonSegment[] = [];
  let lastIndex = 0;
  let match: RegExpExecArray | null;

  while ((match = FENCE_REGEX.exec(text)) !== null) {
    pushText(segments, text.slice(lastIndex, match.index));
    const block = parseFence(match[1], match[2]);
    if (block) {
      segments.push(block);
    } else {
      pushText(segments, match[0]);
    }
    lastIndex = match.index + match[0].length;
  }

  pushText(segments, text.slice(lastIndex));
  return segments;
}

function parseFence(type: string, body: string): LessonSegment | null {
  try {
    const json = JSON.parse(body) as Record<string, unknown>;

    if (type === "show-widget") {
      if (!json.widget_code) {
        return null;
      }
      return {
        type: "widget",
        data: {
          title: typeof json.title === "string" ? json.title : undefined,
          widget_code: String(json.widget_code),
        },
      };
    }

    if (type === "video-card") {
      if (typeof json.title !== "string") {
        return null;
      }
      return {
        type: "video-card",
        data: {
          title: json.title,
          duration: typeof json.duration === "string" ? json.duration : undefined,
          course: typeof json.course === "string" ? json.course : undefined,
          summary: typeof json.summary === "string" ? json.summary : undefined,
          ctaLabel: typeof json.ctaLabel === "string" ? json.ctaLabel : undefined,
        },
      };
    }

    if (type === "quiz-card") {
      if (typeof json.question !== "string" || !Array.isArray(json.options)) {
        return null;
      }
      const options = json.options
        .map((item) => {
          if (!item || typeof item !== "object") {
            return null;
          }
          const option = item as Record<string, unknown>;
          if (typeof option.id !== "string" || typeof option.text !== "string") {
            return null;
          }
          return {
            id: option.id,
            text: option.text,
          };
        })
        .filter((item): item is QuizOption => item !== null);

      if (options.length === 0) {
        return null;
      }

      return {
        type: "quiz-card",
        data: {
          question: json.question,
          options,
          correctOptionId: typeof json.correctOptionId === "string" ? json.correctOptionId : undefined,
          explanation: typeof json.explanation === "string" ? json.explanation : undefined,
        },
      };
    }

    if (type === "plan-card") {
      if (!Array.isArray(json.items)) {
        return null;
      }
      const items = json.items
        .map((item) => {
          if (!item || typeof item !== "object") {
            return null;
          }
          const row = item as Record<string, unknown>;
          if (typeof row.title !== "string") {
            return null;
          }
          return {
            title: row.title,
            duration: typeof row.duration === "string" ? row.duration : undefined,
            status:
              row.status === "todo" || row.status === "active" || row.status === "done"
                ? row.status
                : undefined,
          };
        })
        .filter((item): item is PlanCardItem => item !== null);

      if (items.length === 0) {
        return null;
      }

      return {
        type: "plan-card",
        data: {
          heading: typeof json.heading === "string" ? json.heading : undefined,
          items,
        },
      };
    }

    if (type === "analysis-card") {
      if (!Array.isArray(json.points)) {
        return null;
      }
      const points = json.points
        .map((item) => {
          if (!item || typeof item !== "object") {
            return null;
          }
          const row = item as Record<string, unknown>;
          if (typeof row.label !== "string") {
            return null;
          }
          return {
            label: row.label,
            detail: typeof row.detail === "string" ? row.detail : undefined,
            level:
              row.level === "strong" || row.level === "watch" || row.level === "weak"
                ? row.level
                : undefined,
          };
        })
        .filter((item): item is AnalysisCardPoint => item !== null);

      if (points.length === 0) {
        return null;
      }

      return {
        type: "analysis-card",
        data: {
          title: typeof json.title === "string" ? json.title : undefined,
          summary: typeof json.summary === "string" ? json.summary : undefined,
          points,
          nextStep: typeof json.nextStep === "string" ? json.nextStep : undefined,
        },
      };
    }
  } catch {
    return null;
  }

  return null;
}

function pushText(segments: LessonSegment[], value: string) {
  const trimmed = value.trim();
  if (trimmed) {
    segments.push({ type: "text", content: trimmed });
  }
}
