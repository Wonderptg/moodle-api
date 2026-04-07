class StudentVideoCardElement extends HTMLElement {
  private _data = "";

  set data(value: string) {
    this._data = value;
    this.render();
  }

  connectedCallback() {
    this.render();
  }

  private render() {
    if (!this.isConnected || !this._data) {
      return;
    }
    const parsed = safeParse(this._data);
    if (!parsed || typeof parsed.title !== "string") {
      return;
    }

    this.innerHTML = `
      <section class="lesson-card lesson-card--video">
        <div class="lesson-card__eyebrow">${escapeHtml(parsed.course || "课程视频")}</div>
        <h3 class="lesson-card__title">${escapeHtml(parsed.title)}</h3>
        ${parsed.summary ? `<p class="lesson-card__summary">${escapeHtml(parsed.summary)}</p>` : ""}
        <div class="lesson-card__footer">
          <span class="lesson-chip">${escapeHtml(parsed.duration || "待定时长")}</span>
          <button class="lesson-card__action" data-action="start-video">${escapeHtml(parsed.ctaLabel || "开始学习")}</button>
        </div>
      </section>
    `;

    this.querySelector("[data-action='start-video']")?.addEventListener("click", () => {
      this.dispatchEvent(new CustomEvent("lesson-card-message", {
        detail: `请开始讲解视频《${parsed.title}》，先告诉我这一段最关键的知识点。`,
        bubbles: true,
        composed: true,
      }));
    });
  }
}

class StudentQuizCardElement extends HTMLElement {
  private _data = "";

  set data(value: string) {
    this._data = value;
    this.render();
  }

  connectedCallback() {
    this.render();
  }

  private render() {
    if (!this.isConnected || !this._data) {
      return;
    }
    const parsed = safeParse(this._data);
    if (!parsed || typeof parsed.question !== "string" || !Array.isArray(parsed.options)) {
      return;
    }

    const options = parsed.options
      .filter((item: unknown) => item && typeof item === "object")
      .map((item: unknown) => item as { id?: string; text?: string })
      .filter((item) => typeof item.id === "string" && typeof item.text === "string");

    this.innerHTML = `
      <section class="lesson-card lesson-card--quiz">
        <div class="lesson-card__eyebrow">课堂练习</div>
        <h3 class="lesson-card__title">${escapeHtml(parsed.question)}</h3>
        <div class="lesson-card__options">
          ${options
            .map(
              (option) => `
                <button class="lesson-option" data-option-id="${escapeHtml(option.id!)}">
                  <span class="lesson-option__id">${escapeHtml(option.id!)}</span>
                  <span>${escapeHtml(option.text!)}</span>
                </button>
              `,
            )
            .join("")}
        </div>
        ${parsed.explanation ? `<p class="lesson-card__hint">${escapeHtml(parsed.explanation)}</p>` : ""}
      </section>
    `;

    for (const button of this.querySelectorAll<HTMLElement>("[data-option-id]")) {
      button.addEventListener("click", () => {
        const optionId = button.dataset.optionId;
        if (!optionId) {
          return;
        }
        this.dispatchEvent(new CustomEvent("lesson-card-message", {
          detail: `我选择 ${optionId}。请判断对错，并解释为什么。`,
          bubbles: true,
          composed: true,
        }));
      });
    }
  }
}

class StudentPlanCardElement extends HTMLElement {
  private _data = "";

  set data(value: string) {
    this._data = value;
    this.render();
  }

  connectedCallback() {
    this.render();
  }

  private render() {
    if (!this.isConnected || !this._data) {
      return;
    }
    const parsed = safeParse(this._data);
    if (!parsed || !Array.isArray(parsed.items)) {
      return;
    }

    const items = parsed.items
      .filter((item: unknown) => item && typeof item === "object")
      .map((item: unknown) => item as { title?: string; duration?: string; status?: string })
      .filter((item) => typeof item.title === "string");

    this.innerHTML = `
      <section class="lesson-card lesson-card--plan">
        <div class="lesson-card__eyebrow">${escapeHtml(
          typeof parsed.heading === "string" ? parsed.heading : "今日学习计划",
        )}</div>
        <div class="lesson-plan">
          ${items
            .map(
              (item, index) => `
                <div class="lesson-plan__item lesson-plan__item--${escapeHtml(item.status || "todo")}">
                  <div class="lesson-plan__index">${index + 1}</div>
                  <div class="lesson-plan__body">
                    <div class="lesson-plan__title">${escapeHtml(item.title!)}</div>
                    ${item.duration ? `<div class="lesson-plan__meta">${escapeHtml(item.duration)}</div>` : ""}
                  </div>
                </div>
              `,
            )
            .join("")}
        </div>
      </section>
    `;
  }
}

class StudentAnalysisCardElement extends HTMLElement {
  private _data = "";

  set data(value: string) {
    this._data = value;
    this.render();
  }

  connectedCallback() {
    this.render();
  }

  private render() {
    if (!this.isConnected || !this._data) {
      return;
    }
    const parsed = safeParse(this._data);
    if (!parsed || !Array.isArray(parsed.points)) {
      return;
    }

    const points = parsed.points
      .filter((item: unknown) => item && typeof item === "object")
      .map((item: unknown) => item as { label?: string; detail?: string; level?: string })
      .filter((item) => typeof item.label === "string");

    this.innerHTML = `
      <section class="lesson-card lesson-card--analysis">
        <div class="lesson-card__eyebrow">${escapeHtml(
          typeof parsed.title === "string" ? parsed.title : "薄弱点分析",
        )}</div>
        ${typeof parsed.summary === "string" ? `<p class="lesson-card__summary">${escapeHtml(parsed.summary)}</p>` : ""}
        <div class="lesson-analysis">
          ${points
            .map(
              (point) => `
                <div class="lesson-analysis__item">
                  <span class="lesson-badge lesson-badge--${escapeHtml(point.level || "watch")}">${levelLabel(point.level)}</span>
                  <div class="lesson-analysis__content">
                    <div class="lesson-analysis__label">${escapeHtml(point.label!)}</div>
                    ${point.detail ? `<div class="lesson-analysis__detail">${escapeHtml(point.detail)}</div>` : ""}
                  </div>
                </div>
              `,
            )
            .join("")}
        </div>
        ${
          typeof parsed.nextStep === "string"
            ? `<button class="lesson-card__action" data-action="next-step">${escapeHtml(parsed.nextStep)}</button>`
            : ""
        }
      </section>
    `;

    this.querySelector("[data-action='next-step']")?.addEventListener("click", () => {
      this.dispatchEvent(new CustomEvent("lesson-card-message", {
        detail: typeof parsed.nextStep === "string"
          ? `按照这个下一步继续：${parsed.nextStep}`
          : "请继续针对薄弱点讲解。",
        bubbles: true,
        composed: true,
      }));
    });
  }
}

if (!customElements.get("student-video-card")) {
  customElements.define("student-video-card", StudentVideoCardElement);
}

if (!customElements.get("student-quiz-card")) {
  customElements.define("student-quiz-card", StudentQuizCardElement);
}

if (!customElements.get("student-plan-card")) {
  customElements.define("student-plan-card", StudentPlanCardElement);
}

if (!customElements.get("student-analysis-card")) {
  customElements.define("student-analysis-card", StudentAnalysisCardElement);
}

function safeParse(value: string): Record<string, unknown> | null {
  try {
    return JSON.parse(value) as Record<string, unknown>;
  } catch {
    return null;
  }
}

function escapeHtml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function levelLabel(level?: string): string {
  if (level === "strong") return "掌握";
  if (level === "weak") return "薄弱";
  return "关注";
}
