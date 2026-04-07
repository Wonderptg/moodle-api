export type ShowWidgetData = {
  title?: string;
  widget_code: string;
};

export type WidgetSegment =
  | { type: "text"; content: string }
  | { type: "widget"; data: ShowWidgetData };

export type StreamingWidgetParseResult = {
  hasWidgetFence: boolean;
  segments: WidgetSegment[];
  partialWidget: ShowWidgetData | null;
  scriptsTruncated: boolean;
};

export function parseAllShowWidgets(text: string): WidgetSegment[] {
  const segments: WidgetSegment[] = [];
  const fenceRegex = /```show-widget\s*\n?([\s\S]*?)\n?\s*```/g;
  let lastIndex = 0;
  let match: RegExpExecArray | null;
  let foundAny = false;

  while ((match = fenceRegex.exec(text)) !== null) {
    foundAny = true;
    const before = text.slice(lastIndex, match.index).trim();
    if (before) {
      segments.push({ type: "text", content: before });
    }

    try {
      const json = JSON.parse(match[1]);
      if (json.widget_code) {
        segments.push({
          type: "widget",
          data: {
            title: typeof json.title === "string" ? json.title : undefined,
            widget_code: String(json.widget_code),
          },
        });
      }
    } catch {
      // Ignore malformed widget blocks.
    }

    lastIndex = match.index + match[0].length;
  }

  if (!foundAny) {
    const fenceStart = text.indexOf("```show-widget");
    if (fenceStart === -1) {
      return [];
    }
    const before = text.slice(0, fenceStart).trim();
    if (before) {
      segments.push({ type: "text", content: before });
    }
    const fenceBody = text.slice(fenceStart + "```show-widget".length).trim();
    const widget = extractTruncatedWidget(fenceBody);
    if (widget) {
      segments.push({ type: "widget", data: widget });
    }
    return segments;
  }

  const remaining = text.slice(lastIndex).trim();
  if (remaining) {
    const truncFenceStart = remaining.indexOf("```show-widget");
    if (truncFenceStart !== -1) {
      const beforeTrunc = remaining.slice(0, truncFenceStart).trim();
      if (beforeTrunc) {
        segments.push({ type: "text", content: beforeTrunc });
      }
      const truncBody = remaining.slice(truncFenceStart + "```show-widget".length).trim();
      const widget = extractTruncatedWidget(truncBody);
      if (widget) {
        segments.push({ type: "widget", data: widget });
      }
    } else {
      segments.push({ type: "text", content: remaining });
    }
  }

  return segments;
}

export function parseStreamingShowWidgets(content: string): StreamingWidgetParseResult {
  const hasWidgetFence = /```show-widget/.test(content);
  if (!hasWidgetFence) {
    return {
      hasWidgetFence: false,
      segments: [],
      partialWidget: null,
      scriptsTruncated: false,
    };
  }

  const lastFenceStart = content.lastIndexOf("```show-widget");
  const afterLastFence = content.slice(lastFenceStart);
  const lastFenceClosed = /```show-widget\s*\n?[\s\S]*?\n?\s*```/.test(afterLastFence);

  if (lastFenceClosed) {
    return {
      hasWidgetFence: true,
      segments: parseAllShowWidgets(content),
      partialWidget: null,
      scriptsTruncated: false,
    };
  }

  const beforePart = content.slice(0, lastFenceStart).trim();
  const hasCompletedFences = beforePart.length > 0 && /```show-widget/.test(beforePart);
  const completedSegments = hasCompletedFences ? parseAllShowWidgets(beforePart) : [];
  const segments = !hasCompletedFences && beforePart
    ? [{ type: "text", content: beforePart } satisfies WidgetSegment]
    : completedSegments;

  const fenceBody = content.slice(lastFenceStart + "```show-widget".length).trim();
  let partialWidget = extractTruncatedWidget(fenceBody);
  let scriptsTruncated = false;

  if (partialWidget) {
    const lastScript = partialWidget.widget_code.lastIndexOf("<script");
    if (lastScript !== -1) {
      const afterScript = partialWidget.widget_code.slice(lastScript);
      if (!/<script[\s\S]*?<\/script>/i.test(afterScript)) {
        partialWidget = {
          ...partialWidget,
          widget_code: partialWidget.widget_code.slice(0, lastScript).trim(),
        };
        scriptsTruncated = true;
        if (!partialWidget.widget_code) {
          partialWidget = null;
        }
      }
    }
  }

  return {
    hasWidgetFence: true,
    segments,
    partialWidget,
    scriptsTruncated,
  };
}

function extractTruncatedWidget(fenceBody: string): ShowWidgetData | null {
  try {
    const json = JSON.parse(fenceBody);
    if (json.widget_code) {
      return {
        title: typeof json.title === "string" ? json.title : undefined,
        widget_code: String(json.widget_code),
      };
    }
  } catch {
    // Expected for streaming/truncated widget JSON.
  }

  const keyIdx = fenceBody.indexOf('"widget_code"');
  if (keyIdx === -1) {
    return null;
  }
  const colonIdx = fenceBody.indexOf(":", keyIdx + 13);
  if (colonIdx === -1) {
    return null;
  }
  const quoteIdx = fenceBody.indexOf('"', colonIdx + 1);
  if (quoteIdx === -1) {
    return null;
  }

  let raw = fenceBody.slice(quoteIdx + 1);
  raw = raw.replace(/"\s*\}\s*$/, "");
  if (raw.endsWith("\\")) {
    raw = raw.slice(0, -1);
  }

  try {
    const widgetCode = raw
      .replace(/\\\\/g, "\x00BACKSLASH\x00")
      .replace(/\\n/g, "\n")
      .replace(/\\t/g, "\t")
      .replace(/\\r/g, "\r")
      .replace(/\\"/g, '"')
      .replace(/\x00BACKSLASH\x00/g, "\\");

    if (widgetCode.length < 10) {
      return null;
    }

    const titleMatch = fenceBody.match(/"title"\s*:\s*"([^"]*?)"/);
    return {
      title: titleMatch ? titleMatch[1] : undefined,
      widget_code: widgetCode,
    };
  } catch {
    return null;
  }
}
