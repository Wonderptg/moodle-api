import {
  abortChatRun,
  handleChatEvent,
  loadChatHistory,
  sendChatMessage,
  type ChatState,
  type ChatEventPayload,
} from "../ui/controllers/chat.ts";
import { GatewayBrowserClient, type GatewayEventFrame, type GatewayHelloOk } from "../ui/gateway.ts";
import { extractTextCached } from "../ui/chat/message-extract.ts";
import { parseAllShowWidgets, parseStreamingShowWidgets, type WidgetSegment } from "../ui/widgets/show-widget-parser.ts";
import "../ui/widgets/widget-renderer.ts";

type AppSettings = {
  gatewayUrl: string;
  token: string;
  password: string;
  sessionKey: string;
};

const STORAGE_KEY = "student-openclaw-ui.settings.v1";

const ENV_DEFAULTS = {
  gatewayUrl: (import.meta.env.VITE_OPENCLAW_GATEWAY_URL as string | undefined)?.trim() || "",
  token: (import.meta.env.VITE_OPENCLAW_GATEWAY_TOKEN as string | undefined)?.trim() || "",
  password: (import.meta.env.VITE_OPENCLAW_GATEWAY_PASSWORD as string | undefined)?.trim() || "",
  sessionKey: (import.meta.env.VITE_OPENCLAW_SESSION_KEY as string | undefined)?.trim() || "",
  autoConnect: ((import.meta.env.VITE_OPENCLAW_AUTO_CONNECT as string | undefined)?.trim() || "").toLowerCase() === "true",
};

function loadSettings(): AppSettings {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw) as Partial<AppSettings>;
      return {
        gatewayUrl: ENV_DEFAULTS.gatewayUrl || parsed.gatewayUrl || "ws://127.0.0.1:18789",
        token: ENV_DEFAULTS.token || parsed.token || "",
        password: ENV_DEFAULTS.password || parsed.password || "",
        sessionKey: ENV_DEFAULTS.sessionKey || parsed.sessionKey || "agent:student-ui:main",
      };
    }
  } catch {
    // Ignore corrupted local settings.
  }
  return {
    gatewayUrl: ENV_DEFAULTS.gatewayUrl || "ws://127.0.0.1:18789",
    token: ENV_DEFAULTS.token || "",
    password: ENV_DEFAULTS.password || "",
    sessionKey: ENV_DEFAULTS.sessionKey || "agent:student-ui:main",
  };
}

function saveSettings(settings: AppSettings) {
  window.localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
}

function normalizeGatewayUrl(value: string): string {
  const trimmed = value.trim();
  if (!trimmed) return "ws://127.0.0.1:18789";
  if (trimmed.startsWith("ws://") || trimmed.startsWith("wss://")) {
    return trimmed;
  }
  if (trimmed.startsWith("http://")) {
    return `ws://${trimmed.slice("http://".length)}`;
  }
  if (trimmed.startsWith("https://")) {
    return `wss://${trimmed.slice("https://".length)}`;
  }
  return `ws://${trimmed}`;
}

function escapeHtml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function renderText(value: string): string {
  return escapeHtml(value).replace(/\n/g, "<br>");
}

function formatTime(timestamp?: number | string | null): string {
  if (!timestamp) return "";
  const date = typeof timestamp === "number" ? new Date(timestamp) : new Date(timestamp);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

class StudentOpenClawApp extends HTMLElement {
  private settings = loadSettings();
  private client: GatewayBrowserClient | null = null;
  private gatewayStatus: "idle" | "connecting" | "connected" | "reconnecting" | "error" = "idle";
  private hello: GatewayHelloOk | null = null;
  private infoMessage = "使用 OpenClaw 原生 Gateway WebSocket 聊天。";
  private settingsOpen = true;
  private reconnectAttempts = 0;
  private pendingWidgets: Array<{
    id: string;
    widgetCode: string;
    title?: string;
    isStreaming: boolean;
    showOverlay?: boolean;
  }> = [];
  private chatState: ChatState = {
    client: null,
    connected: false,
    sessionKey: this.settings.sessionKey,
    chatLoading: false,
    chatMessages: [],
    chatThinkingLevel: null,
    chatSending: false,
    chatMessage: "",
    chatAttachments: [],
    chatRunId: null,
    chatStream: null,
    chatStreamStartedAt: null,
    lastError: null,
  };

  connectedCallback() {
    this.render();
    this.bindEvents();
    if (ENV_DEFAULTS.autoConnect || this.settings.token || this.settings.password) {
      void this.connect();
    }
  }

  disconnectedCallback() {
    this.client?.stop();
  }

  private updateSettings(next: Partial<AppSettings>) {
    this.settings = {
      ...this.settings,
      ...next,
    };
    this.chatState.sessionKey = this.settings.sessionKey;
    saveSettings(this.settings);
  }

  private async connect() {
    this.client?.stop();
    this.gatewayStatus = "connecting";
    this.reconnectAttempts = 0;
    this.chatState.client = null;
    this.chatState.connected = false;
    this.chatState.chatMessages = [];
    this.chatState.chatStream = null;
    this.chatState.chatRunId = null;
    this.chatState.lastError = null;
    this.infoMessage = "正在连接 OpenClaw Gateway…";
    this.render();

    const client = new GatewayBrowserClient({
      url: normalizeGatewayUrl(this.settings.gatewayUrl),
      token: this.settings.token || undefined,
      password: this.settings.password || undefined,
      onHello: (hello) => {
        this.client = client;
        this.hello = hello;
        this.gatewayStatus = "connected";
        this.chatState.client = client;
        this.chatState.connected = true;
        this.infoMessage = "已连接，正在读取聊天历史…";
        void this.refreshHistory();
      },
      onEvent: (event) => {
        this.handleGatewayEvent(event);
      },
      onClose: ({ code, reason }) => {
        this.reconnectAttempts += 1;
        this.gatewayStatus = "reconnecting";
        this.chatState.connected = false;
        this.chatState.client = null;
        this.infoMessage = `连接断开，正在重连… (${code}${reason ? `: ${reason}` : ""})`;
        this.render();
      },
    });

    this.client = client;
    client.start();
  }

  private async refreshHistory() {
    if (!this.client || !this.chatState.connected) return;
    await loadChatHistory(this.chatState);
    this.infoMessage = "聊天已同步。";
    this.render();
  }

  private handleGatewayEvent(event: GatewayEventFrame) {
    if (event.event === "chat") {
      const state = handleChatEvent(this.chatState, event.payload as ChatEventPayload);
      if (state === "final" || state === "aborted" || state === "error") {
        void this.refreshHistory();
      } else {
        this.render();
      }
      return;
    }

    if (event.event === "health") {
      this.infoMessage = "Gateway 状态已更新。";
      this.render();
    }
  }

  private async sendCurrentMessage() {
    const message = this.chatState.chatMessage.trim();
    if (!message || !this.chatState.connected || !this.client) return;
    this.chatState.client = this.client;
    this.chatState.sessionKey = this.settings.sessionKey;
    const sent = await sendChatMessage(this.chatState, message);
    if (sent) {
      this.chatState.chatMessage = "";
      this.infoMessage = "消息已发送，等待模型回复…";
    }
    this.render();
  }

  private async stopRun() {
    if (!this.chatState.connected || !this.client) return;
    this.chatState.client = this.client;
    this.chatState.sessionKey = this.settings.sessionKey;
    const stopped = await abortChatRun(this.chatState);
    this.infoMessage = stopped ? "已请求停止当前回复。" : "停止失败。";
    this.render();
  }

  private bindEvents() {
    const root = this;

    this.addEventListener("click", async (event) => {
      const target = event.target as HTMLElement | null;
      const action = target?.closest<HTMLElement>("[data-action]")?.dataset.action;
      if (!action) return;

      if (action === "connect") {
        await this.connect();
        return;
      }
      if (action === "refresh") {
        await this.refreshHistory();
        return;
      }
      if (action === "send") {
        await this.sendCurrentMessage();
        return;
      }
      if (action === "stop") {
        await this.stopRun();
        return;
      }
      if (action === "toggle-settings") {
        this.settingsOpen = !this.settingsOpen;
        this.render();
        return;
      }
      if (action === "sample-greeting") {
        this.chatState.chatMessage = "今天我们先学什么？请按知识点开始上课。";
        this.render();
        return;
      }
      if (action === "sample-quiz") {
        this.chatState.chatMessage = "先给我出一道相关练习题，再讲解薄弱点。";
        this.render();
        return;
      }
      if (action === "sample-widget") {
        this.insertWidgetDemo();
        return;
      }
      if (action === "prompt-widget-svg") {
        this.chatState.chatMessage = "请只输出一个 show-widget 代码围栏，生成一个 SVG 图示，用来解释牛顿第二定律。不要输出别的内容。";
        this.infoMessage = "已填入原生 SVG widget 测试提示词。";
        this.render();
        return;
      }
      if (action === "prompt-widget-html") {
        this.chatState.chatMessage = "请只输出一个 show-widget 代码围栏，生成一个可交互 HTML 小卡片，用来演示学习进度。不要输出别的内容。";
        this.infoMessage = "已填入原生 HTML widget 测试提示词。";
        this.render();
        return;
      }
      if (action === "prompt-widget-mixed") {
        this.chatState.chatMessage = "请先用一句中文解释，再输出一个 show-widget 代码围栏，画出“讲解 -> 练习 -> 反馈 -> 下一步”的学习流程图。";
        this.infoMessage = "已填入文本 + widget 混合测试提示词。";
        this.render();
        return;
      }
    });

    this.addEventListener("input", (event) => {
      const target = event.target as HTMLInputElement | HTMLTextAreaElement | null;
      if (!target?.dataset.field) return;

      if (target.dataset.field === "gatewayUrl") {
        this.updateSettings({ gatewayUrl: target.value });
      } else if (target.dataset.field === "token") {
        this.updateSettings({ token: target.value });
      } else if (target.dataset.field === "password") {
        this.updateSettings({ password: target.value });
      } else if (target.dataset.field === "sessionKey") {
        this.updateSettings({ sessionKey: target.value });
      } else if (target.dataset.field === "message") {
        this.chatState.chatMessage = target.value;
      }
    });

    this.addEventListener("keydown", (event) => {
      const target = event.target as HTMLElement | null;
      if (target instanceof HTMLTextAreaElement && target.dataset.field === "message") {
        if (event.key === "Enter" && !event.shiftKey) {
          event.preventDefault();
          void root.sendCurrentMessage();
        }
      }
    });

    this.addEventListener("widget-send-message", (event) => {
      const custom = event as CustomEvent<string>;
      if (typeof custom.detail !== "string" || !custom.detail.trim()) {
        return;
      }
      this.chatState.chatMessage = custom.detail.trim();
      this.infoMessage = "已把 widget 里的追问填入输入框。";
      this.render();
    });
  }

  private renderMessages() {
    this.pendingWidgets = [];
    const bubbles = this.chatState.chatMessages.map((message) => {
      const role = typeof (message as { role?: unknown }).role === "string"
        ? String((message as { role?: unknown }).role)
        : "assistant";
      const text = extractTextCached(message) || "";
      return `
        <article class="message message--${role}">
          <div class="message__meta">
            <span>${role === "user" ? "你" : "AI Tutor"}</span>
            <span>${formatTime((message as { timestamp?: number; createdAt?: string }).timestamp || (message as { createdAt?: string }).createdAt || null)}</span>
          </div>
          <div class="message__body">${this.renderRichMessage(text || "_空白消息_", false)}</div>
        </article>
      `;
    }).join("");

    const stream = this.chatState.chatStream
      ? `
        <article class="message message--assistant message--streaming">
          <div class="message__meta">
            <span>AI Tutor</span>
            <span>生成中</span>
          </div>
          <div class="message__body">${this.renderRichMessage(this.chatState.chatStream, true)}</div>
        </article>
      `
      : "";

    const empty = !bubbles && !stream
      ? `
        <div class="empty-state">
          <h2>从这里开始上课</h2>
          <p>这个版本直接走 OpenClaw 原生 Gateway WS，不再通过 CodePilot 的兼容层。</p>
          <div class="empty-state__actions">
            <button class="ghost-button" data-action="sample-greeting">生成今日学习开场</button>
            <button class="ghost-button" data-action="sample-quiz">先来一题练习</button>
          </div>
        </div>
      `
      : "";

    return `${empty}${bubbles}${stream}`;
  }

  private renderRichMessage(text: string, isStreaming: boolean): string {
    if (!/```show-widget/.test(text)) {
      return renderText(text);
    }

    if (isStreaming) {
      const parsed = parseStreamingShowWidgets(text);
      if (!parsed.hasWidgetFence) {
        return renderText(text);
      }
      const parts = parsed.segments.map((segment) => this.renderWidgetSegment(segment));
      if (parsed.partialWidget) {
        parts.push(this.renderWidgetMount({
          widgetCode: parsed.partialWidget.widget_code,
          title: parsed.partialWidget.title,
          isStreaming: true,
          showOverlay: parsed.scriptsTruncated,
        }));
      }
      return parts.join("");
    }

    const segments = parseAllShowWidgets(text);
    if (segments.length === 0) {
      return renderText(text);
    }
    return segments.map((segment) => this.renderWidgetSegment(segment)).join("");
  }

  private renderWidgetSegment(segment: WidgetSegment): string {
    if (segment.type === "text") {
      return `<div class="message__text-block">${renderText(segment.content)}</div>`;
    }
    return this.renderWidgetMount({
      widgetCode: segment.data.widget_code,
      title: segment.data.title,
      isStreaming: false,
    });
  }

  private renderWidgetMount(widget: {
    widgetCode: string;
    title?: string;
    isStreaming: boolean;
    showOverlay?: boolean;
  }): string {
    const id = `widget-${crypto.randomUUID()}`;
    this.pendingWidgets.push({ id, ...widget });
    return `<div class="message__widget" data-widget-id="${id}"></div>`;
  }

  private hydrateWidgets() {
    for (const widget of this.pendingWidgets) {
      const mount = this.querySelector<HTMLElement>(`[data-widget-id="${widget.id}"]`);
      if (!mount) {
        continue;
      }
      mount.innerHTML = "";
      const element = document.createElement("student-widget-renderer") as HTMLElement & {
        widgetCode: string;
        title?: string;
        isStreaming: boolean;
        showOverlay?: boolean;
      };
      element.widgetCode = widget.widgetCode;
      element.title = widget.title;
      element.isStreaming = widget.isStreaming;
      element.showOverlay = widget.showOverlay;
      mount.appendChild(element);
    }
  }

  private insertWidgetDemo() {
    const demo = [
      "今天先看一个最小可用的课堂图示块：",
      "```show-widget",
      JSON.stringify({
        title: "lesson_progress_demo",
        widget_code:
          `<svg width="100%" viewBox="0 0 680 260" xmlns="http://www.w3.org/2000/svg">` +
          `<defs><marker id="arrow" markerWidth="10" markerHeight="10" refX="6" refY="3" orient="auto"><path d="M0,0 L0,6 L6,3 z" fill="#2d7b6d"/></marker></defs>` +
          `<rect x="24" y="24" width="632" height="212" rx="18" fill="#fffaf1" stroke="rgba(61,46,35,0.12)"/>` +
          `<text x="48" y="58" font-size="22" fill="#20150f" font-family="ui-sans-serif,system-ui">今天的学习推进</text>` +
          `<text x="48" y="86" font-size="12" fill="#6e6257" font-family="ui-sans-serif,system-ui">OpenClaw UI + CodePilot widget renderer</text>` +
          `<rect x="48" y="116" width="150" height="26" rx="13" fill="#f2d5c8"/><rect x="48" y="116" width="88" height="26" rx="13" fill="#d55a2a"/>` +
          `<text x="208" y="134" font-size="12" fill="#6e6257" font-family="ui-sans-serif,system-ui">知识点讲解 58%</text>` +
          `<rect x="48" y="160" width="150" height="26" rx="13" fill="#d8ebe6"/><rect x="48" y="160" width="112" height="26" rx="13" fill="#2d7b6d"/>` +
          `<text x="208" y="178" font-size="12" fill="#6e6257" font-family="ui-sans-serif,system-ui">练习吸收 74%</text>` +
          `<path d="M382 130 C430 96, 500 96, 546 130" fill="none" stroke="#2d7b6d" stroke-width="3" marker-end="url(#arrow)"/>` +
          `<rect x="382" y="146" width="188" height="50" rx="14" fill="#f5efe4" stroke="rgba(61,46,35,0.12)"/>` +
          `<text x="402" y="168" font-size="14" fill="#20150f" font-family="ui-sans-serif,system-ui">下一步</text>` +
          `<text x="402" y="188" font-size="12" fill="#6e6257" font-family="ui-sans-serif,system-ui">进入小测并根据错因继续讲解</text>` +
          `</svg>`,
      }),
      "```",
    ].join("\n");

    this.chatState.chatMessages = [
      ...this.chatState.chatMessages,
      {
        role: "assistant",
        content: [{ type: "text", text: demo }],
        timestamp: Date.now(),
      },
    ];
    this.infoMessage = "已插入本地 widget demo。";
    this.render();
  }

  private render() {
    const connected = this.gatewayStatus === "connected";
    const statusLabel = {
      idle: "未连接",
      connecting: "连接中",
      connected: "已连接",
      reconnecting: "重连中",
      error: "错误",
    }[this.gatewayStatus];

    this.innerHTML = `
      <div class="student-shell">
        <aside class="left-rail">
          <div class="brand">
            <div class="brand__mark">LS</div>
            <div>
              <div class="brand__title">Lobster Study</div>
              <div class="brand__subtitle">OpenClaw Native Chat</div>
            </div>
          </div>

          <section class="panel">
            <div class="panel__head">
              <h3>连接</h3>
              <button class="mini-button" data-action="toggle-settings">${this.settingsOpen ? "收起" : "展开"}</button>
            </div>
            <div class="status-row">
              <span class="status-dot status-dot--${this.gatewayStatus}"></span>
              <strong>${statusLabel}</strong>
            </div>
            <p class="muted">${escapeHtml(this.infoMessage)}</p>
            ${this.settingsOpen ? `
              <label class="field">
                <span>Gateway URL</span>
                <input data-field="gatewayUrl" value="${escapeHtml(this.settings.gatewayUrl)}" placeholder="ws://127.0.0.1:18789" />
              </label>
              <label class="field">
                <span>Token</span>
                <input data-field="token" value="${escapeHtml(this.settings.token)}" placeholder="gateway auth token" />
              </label>
              <label class="field">
                <span>Password</span>
                <input data-field="password" type="password" value="${escapeHtml(this.settings.password)}" placeholder="optional gateway password" />
              </label>
              <label class="field">
                <span>Session Key</span>
                <input data-field="sessionKey" value="${escapeHtml(this.settings.sessionKey)}" placeholder="agent:student-ui:main" />
              </label>
            ` : ""}
            <div class="actions">
              <button class="primary-button" data-action="connect">${connected ? "重新连接" : "连接 Gateway"}</button>
              <button class="ghost-button" data-action="refresh" ${connected ? "" : "disabled"}>刷新历史</button>
            </div>
          </section>

          <section class="panel">
            <div class="panel__head"><h3>今日计划</h3></div>
            <ul class="agenda">
              <li>1. AI 先说明今天的学习路径</li>
              <li>2. 连续讲 2-3 个知识点</li>
              <li>3. 插入小练习并分析薄弱点</li>
              <li>4. 决定是否进入下一个任务</li>
            </ul>
            <div class="actions" style="margin-top: 12px;">
              <button class="ghost-button" data-action="sample-widget">本地 widget demo</button>
            </div>
          </section>
        </aside>

        <main class="lesson-stage">
          <header class="stage-header">
            <div>
              <p class="eyebrow">Session</p>
              <h1>对话式上课</h1>
            </div>
            <div class="header-pill">${this.hello?.protocol ? `Protocol v${this.hello.protocol}` : "Gateway WS"}</div>
          </header>

          <section class="stream-panel">
            <div class="stream-panel__messages">
              ${this.renderMessages()}
            </div>
          </section>

          <section class="composer">
            <textarea
              data-field="message"
              placeholder="直接让 AI 开始上课，或要求它先出题、再分析薄弱点…"
            >${escapeHtml(this.chatState.chatMessage)}</textarea>
            <div class="composer__actions">
              <span class="muted">Session: ${escapeHtml(this.settings.sessionKey)}</span>
              <div class="actions">
                <button class="ghost-button" data-action="stop" ${this.chatState.chatRunId ? "" : "disabled"}>停止</button>
                <button class="primary-button" data-action="send" ${connected ? "" : "disabled"}>发送</button>
              </div>
            </div>
          </section>
        </main>

        <aside class="right-rail">
          <section class="panel">
            <div class="panel__head"><h3>当前策略</h3></div>
            <p class="muted">这版只测 CodePilot 原生 generative UI 的核心能力：<code>show-widget</code>。</p>
            <ul class="agenda">
              <li>聊天协议：Gateway WebSocket</li>
              <li>方法：<code>chat.history</code> / <code>chat.send</code> / <code>chat.abort</code></li>
              <li>默认会话：<code>agent:student-ui:main</code></li>
              <li>当前不再注入隐藏课堂块契约</li>
            </ul>
          </section>

          <section class="panel">
            <div class="panel__head"><h3>原生测试</h3></div>
            <ul class="agenda">
              <li>明确要求模型输出 <code>show-widget</code> 围栏</li>
              <li>先验证 SVG、HTML、文图混排三种情况</li>
              <li>原生内容稳定后再做学生化改造</li>
            </ul>
            <div class="actions" style="margin-top: 12px; flex-direction: column; align-items: stretch;">
              <button class="ghost-button" data-action="prompt-widget-svg">填入 SVG widget 提示词</button>
              <button class="ghost-button" data-action="prompt-widget-html">填入 HTML widget 提示词</button>
              <button class="ghost-button" data-action="prompt-widget-mixed">填入混合输出提示词</button>
            </div>
          </section>
        </aside>
      </div>
    `;
    this.hydrateWidgets();
  }
}

customElements.define("student-openclaw-app", StudentOpenClawApp);
