import { html } from "lit";
import { ref } from "lit/directives/ref.js";
import { sanitizeForIframe, sanitizeForStreaming, buildReceiverSrcdoc } from "./widget-sanitizer.ts";

type RenderWidgetOptions = {
  widgetCode: string;
  title?: string;
  isStreaming: boolean;
  showOverlay?: boolean;
};

const MAX_IFRAME_HEIGHT = 2000;
const STREAM_DEBOUNCE = 120;
const CDN_PATTERN = /cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|unpkg\.com|esm\.sh/;
const heightCache = new Map<string, number>();
const WidgetBase = typeof HTMLElement === "undefined"
  ? class {} as typeof HTMLElement
  : HTMLElement;

function getHeightCacheKey(code: string): string {
  return code.slice(0, 200);
}

class StudentWidgetRendererElement extends WidgetBase {
  private iframe: HTMLIFrameElement | null = null;
  private overlay: HTMLDivElement | null = null;
  private ready = false;
  private finalized = false;
  private height = 0;
  private debounceTimer: number | null = null;
  private lastSent = "";
  private handleWindowMessage = (event: MessageEvent) => {
    if (!event.data || typeof event.data.type !== "string") {
      return;
    }
    if (this.iframe && event.source !== this.iframe.contentWindow) {
      return;
    }

    switch (event.data.type) {
      case "widget:ready":
        this.ready = true;
        this.syncFrame();
        break;
      case "widget:resize":
        if (typeof event.data.height === "number" && event.data.height > 0) {
          const next = Math.min(event.data.height + 2, MAX_IFRAME_HEIGHT);
          this.height = next;
          heightCache.set(getHeightCacheKey(this.widgetCode), next);
          if (this.iframe) {
            this.iframe.style.height = `${next}px`;
          }
        }
        break;
      case "widget:link":
        if (typeof event.data.href === "string" && event.data.href) {
          window.open(event.data.href, "_blank", "noopener,noreferrer");
        }
        break;
      case "widget:sendMessage":
        if (typeof event.data.text === "string" && event.data.text) {
          this.dispatchEvent(new CustomEvent("widget-send-message", {
            detail: event.data.text,
            bubbles: true,
            composed: true,
          }));
        }
        break;
    }
  };

  set widgetCode(value: string) {
    this._widgetCode = value;
    this.syncFrame();
  }

  get widgetCode(): string {
    return this._widgetCode;
  }

  set title(value: string | undefined) {
    this._title = value;
    if (this.iframe) {
      this.iframe.title = value || "Widget";
    }
  }

  get title(): string | undefined {
    return this._title;
  }

  set isStreaming(value: boolean) {
    this._isStreaming = value;
    if (!value) {
      this.finalized = false;
    }
    this.syncFrame();
  }

  get isStreaming(): boolean {
    return this._isStreaming;
  }

  set showOverlay(value: boolean) {
    this._showOverlay = value;
    this.updateOverlay();
  }

  get showOverlay(): boolean {
    return this._showOverlay;
  }

  private _widgetCode = "";
  private _title?: string;
  private _isStreaming = false;
  private _showOverlay = false;

  connectedCallback() {
    if (this.shadowRoot) {
      return;
    }

    const root = this.attachShadow({ mode: "open" });
    const wrapper = document.createElement("div");
    wrapper.className = "widget";

    const style = document.createElement("style");
    style.textContent = `
      :host {
        display: block;
        margin: 8px 0;
      }

      .widget {
        position: relative;
      }

      iframe {
        width: 100%;
        height: 0;
        border: none;
        display: block;
        overflow: hidden;
        border-radius: 16px;
        background: transparent;
      }

      .overlay {
        position: absolute;
        inset: 0;
        border-radius: 16px;
        pointer-events: none;
        display: none;
        background: linear-gradient(
          90deg,
          transparent 0%,
          rgba(110, 98, 87, 0.08) 50%,
          transparent 100%
        );
        background-size: 200% 100%;
        animation: shimmer 1.5s ease-in-out infinite;
      }

      @keyframes shimmer {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
      }
    `;

    const iframe = document.createElement("iframe");
    iframe.sandbox.add("allow-scripts");
    iframe.srcdoc = buildReceiverSrcdoc();
    iframe.title = this.title || "Widget";
    iframe.addEventListener("load", () => {
      this.ready = true;
      this.syncFrame();
    });
    this.iframe = iframe;

    this.height = heightCache.get(getHeightCacheKey(this.widgetCode)) || 0;
    iframe.style.height = `${this.height}px`;

    const overlay = document.createElement("div");
    overlay.className = "overlay";
    this.overlay = overlay;

    wrapper.append(iframe, overlay);
    root.append(style, wrapper);
    window.addEventListener("message", this.handleWindowMessage);
    this.updateOverlay();
    this.syncFrame();
  }

  disconnectedCallback() {
    window.removeEventListener("message", this.handleWindowMessage);
    if (this.debounceTimer !== null) {
      window.clearTimeout(this.debounceTimer);
      this.debounceTimer = null;
    }
  }

  private updateOverlay() {
    if (!this.overlay) {
      return;
    }
    const hasCDN = CDN_PATTERN.test(this.widgetCode);
    const visible = this.showOverlay || (hasCDN && !this.isStreaming && !this.finalized);
    this.overlay.style.display = visible ? "block" : "none";
  }

  private postToFrame(type: "widget:update" | "widget:finalize", htmlContent: string) {
    if (!this.iframe?.contentWindow) {
      return;
    }
    if (this.lastSent === htmlContent && type === "widget:update") {
      return;
    }
    this.lastSent = htmlContent;
    this.iframe.contentWindow.postMessage({ type, html: htmlContent }, "*");
  }

  private syncFrame() {
    this.updateOverlay();
    if (!this.ready || !this.widgetCode || !this.iframe) {
      return;
    }

    if (this.isStreaming) {
      const sanitized = sanitizeForStreaming(this.widgetCode);
      if (this.debounceTimer !== null) {
        window.clearTimeout(this.debounceTimer);
      }
      this.debounceTimer = window.setTimeout(() => {
        this.postToFrame("widget:update", sanitized);
      }, STREAM_DEBOUNCE);
      return;
    }

    if (this.finalized) {
      return;
    }
    this.finalized = true;
    this.postToFrame("widget:finalize", sanitizeForIframe(this.widgetCode));
    window.setTimeout(() => {
      this.updateOverlay();
    }, 400);
  }
}

const ELEMENT_TAG = "student-widget-renderer";
if (typeof customElements !== "undefined" && !customElements.get(ELEMENT_TAG)) {
  customElements.define(ELEMENT_TAG, StudentWidgetRendererElement);
}

export function renderWidget(options: RenderWidgetOptions) {
  return html`
    <student-widget-renderer
      ${ref((element) => {
        const node = element as StudentWidgetRendererElement | null;
        if (!node) {
          return;
        }
        node.widgetCode = options.widgetCode;
        node.title = options.title;
        node.isStreaming = options.isStreaming;
        node.showOverlay = Boolean(options.showOverlay);
      })}
    ></student-widget-renderer>
  `;
}
