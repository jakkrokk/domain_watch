"use strict";

document.querySelectorAll("[data-open-create]").forEach((button) =>
  button.addEventListener("click", () => {
    const panel = document.getElementById("create-panel");
    panel.hidden = !panel.hidden;
    if (!panel.hidden) panel.querySelector('input[name="name"]').focus();
  }),
);

document.querySelectorAll("[data-copy]").forEach((button) =>
  button.addEventListener("click", async () => {
    const source = document.getElementById(button.dataset.copy);
    const status = button.parentElement.querySelector(".copy-status");
    if (!source || !status) return;
    const text = source.value ?? source.textContent;
    const fallback = () => {
      const field = document.createElement("textarea");
      field.value = text;
      field.readOnly = true;
      field.style.position = "fixed";
      field.style.left = "-9999px";
      document.body.appendChild(field);
      try {
        field.focus();
        field.select();
        if (!document.execCommand("copy")) throw new Error("Copy failed");
      } finally {
        field.remove();
        button.focus({ preventScroll: true });
      }
    };
    button.disabled = true;
    status.textContent = "";
    try {
      if (window.isSecureContext && navigator.clipboard?.writeText) {
        try {
          await navigator.clipboard.writeText(text);
        } catch {
          fallback();
        }
      } else fallback();
      status.textContent = "コピーしました";
    } catch {
      status.textContent =
        "コピーできませんでした。本文を選択してコピーしてください。";
    } finally {
      button.disabled = false;
    }
  }),
);

const dataNode = document.getElementById("chart-data");
if (dataNode) {
  const data = JSON.parse(dataNode.textContent);
  const host = document.getElementById("trend-chart");
  const tooltip = document.getElementById("chart-tooltip");
  const select = document.getElementById("chart-target");
  const colors = { openai: "#5978e7", gemini: "#23a58f" };
  const names = { openai: "ChatGPT", gemini: "Gemini" };
  const NS = "http://www.w3.org/2000/svg";
  const create = (tag, attrs = {}, text) => {
    const node = document.createElementNS(NS, tag);
    for (const [key, value] of Object.entries(attrs))
      node.setAttribute(key, value);
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const render = () => {
    host.replaceChildren();
    tooltip.hidden = true;
    const width = Math.max(host.clientWidth - 40, 290);
    const height = width < 480 ? 190 : 245;
    const left = 38,
      right = width - 20,
      top = 19,
      bottom = height - 34;
    const svg = create("svg", {
      viewBox: `0 0 ${width} ${height}`,
      "aria-hidden": "true",
    });
    // Points are labelled links, so the graph is keyboard-operable too.
    svg.removeAttribute("aria-hidden");
    const key = select.value;
    const series = data.series[key];
    const x = (i) =>
      data.labels.length === 1
        ? (left + right) / 2
        : left + ((right - left) * i) / (data.labels.length - 1);
    const y = (value) => bottom - ((bottom - top) * value) / 100;
    for (const value of [0, 25, 50, 75, 100]) {
      svg.append(
        create("line", {
          x1: left,
          y1: y(value),
          x2: right,
          y2: y(value),
          stroke: "#e9edf5",
          "stroke-dasharray": value === 0 ? "" : "3 4",
        }),
      );
      svg.append(
        create(
          "text",
          {
            x: left - 12,
            y: y(value) + 3,
            "text-anchor": "end",
            fill: "#a1aec2",
            "font-size": 9,
          },
          value + "%",
        ),
      );
    }
    const step = Math.max(
      1,
      Math.ceil(data.labels.length / (width < 480 ? 4 : 8)),
    );
    data.labels.forEach((day, i) => {
      if (i % step !== 0 && i !== data.labels.length - 1) return;
      if (
        i === data.labels.length - 1 &&
        i !== 0 &&
        i % step !== 0 &&
        i % step < step * 0.65
      )
        return;
      svg.append(
        create(
          "text",
          {
            x: x(i),
            y: height - 10,
            "text-anchor": "middle",
            fill: "#a1aec2",
            "font-size": 9,
          },
          day.slice(5).replace("-", "/"),
        ),
      );
    });
    let points = 0;
    for (const provider of ["openai", "gemini"]) {
      let path = "",
        continuous = false;
      series[provider].forEach((stat, i) => {
        if (stat.pct === null) {
          continuous = false;
          return;
        }
        path += `${continuous ? " L" : " M"}${x(i)} ${y(stat.pct)}`;
        continuous = true;
      });
      svg.append(
        create("path", {
          d: path,
          fill: "none",
          stroke: colors[provider],
          "stroke-width": 2.4,
          "stroke-linejoin": "round",
          "stroke-linecap": "round",
        }),
      );
    }
    for (const provider of ["openai", "gemini"]) {
      series[provider].forEach((stat, i) => {
        if (stat.pct === null) return;
        points++;
        const link = create("a", {
          href:
            "/?" +
            new URLSearchParams({
              newa: "prompts",
              id: data.project,
              start: data.labels[i],
              end: data.labels[i],
            }),
          "aria-label": `${data.labels[i]} ${names[provider]} ${stat.pct}%、${stat.n}回中${stat.hits}回出現`,
        });
        const overlap = series.openai[i].pct === series.gemini[i].pct;
        const pointX = x(i) + (overlap ? (provider === "openai" ? -6 : 6) : 0);
        const radius = data.labels.length > 60 ? 2 : 4;
        link.append(
          create("circle", {
            cx: pointX,
            cy: y(stat.pct),
            r: radius + 5,
            fill: "transparent",
          }),
        );
        link.append(
          create("circle", {
            cx: pointX,
            cy: y(stat.pct),
            r: radius,
            fill: colors[provider],
            stroke: "#fff",
            "stroke-width": 1.5,
          }),
        );
        const show = () => {
          tooltip.textContent = `${data.labels[i]} · ${names[provider]}\n${stat.pct}%（${stat.n}回中${stat.hits}回）`;
          tooltip.hidden = false;
          const panelRect = host.parentElement.getBoundingClientRect();
          const rect = link.getBoundingClientRect();
          tooltip.style.left =
            Math.max(
              8,
              Math.min(panelRect.width - 180, rect.left - panelRect.left - 60),
            ) + "px";
          tooltip.style.top = Math.max(4, rect.top - panelRect.top - 60) + "px";
        };
        link.addEventListener("mouseenter", show);
        link.addEventListener("focus", show);
        link.addEventListener("mouseleave", () => (tooltip.hidden = true));
        link.addEventListener("blur", () => (tooltip.hidden = true));
        svg.append(link);
      });
    }
    host.append(svg);
    host.setAttribute(
      "aria-label",
      `${select.options[select.selectedIndex].text}のChatGPTとGeminiの日別出現率。グラフの各点から日別の一覧を開けます。`,
    );
    if (!points) {
      const empty = document.createElement("div");
      empty.className = "chart-no-data";
      empty.textContent = "この期間の計測データはありません";
      host.append(empty);
    }
  };
  select.addEventListener("change", render);
  let resizeTimer;
  window.addEventListener("resize", () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(render, 100);
  });
  render();
}

const shotPrompts = document.getElementById("shot-prompts");
if (shotPrompts) {
  const countNode = document.getElementById("shot-prompt-count");
  const updateCount = () => {
    const count = shotPrompts.value.split(/\r\n|[\n\r\v\f\u0085\u2028\u2029]/).filter((line) => line.trim()).length;
    countNode.textContent = `${count} / 50件（1行1件・空行は除外）`;
    shotPrompts.setCustomValidity(count > 50 ? "プロンプトは50件まで入力できます。" : "");
  };
  shotPrompts.addEventListener("input", updateCount);
  updateCount();
}
document.querySelectorAll("[data-shot-url]").forEach((row) => {
  row.addEventListener("click", (event) => {
    if (event.target.closest("a, button") || window.getSelection()?.toString()) return;
    window.location.href = row.dataset.shotUrl;
  });
});
