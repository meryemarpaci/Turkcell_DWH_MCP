(() => {
  const messagesEl = document.getElementById("messages");
  const form = document.getElementById("chatForm");
  const input = document.getElementById("input");
  const btnSend = document.getElementById("btnSend");
  const btnNew = document.getElementById("btnNewChat");
  const healthEl = document.getElementById("healthStatus");
  const topbar = document.getElementById("topbar");

  const SESSION_KEY = "dwh_chat_session_id";
  let sessionId = localStorage.getItem(SESSION_KEY) || crypto.randomUUID().replace(/-/g, "").slice(0, 16);
  localStorage.setItem(SESSION_KEY, sessionId);
  let busy = false;

  function scrollChatToBottom() {
    requestAnimationFrame(() => {
      messagesEl.scrollTop = messagesEl.scrollHeight;
    });
  }

  function showEmpty() {
    if (messagesEl.children.length) return;
    topbar?.classList.remove("hidden");
    messagesEl.innerHTML = "";
  }

  function clearEmpty() {
    const empty = messagesEl.querySelector(".empty");
    if (empty) empty.remove();
    topbar?.classList.add("hidden");
  }

  function el(html) {
    const t = document.createElement("template");
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  }

  function errorText(data) {
    const parts = [];
    if (data && data.error) parts.push(String(data.error));
    if (Array.isArray(data?.errors) && data.errors.length) {
      parts.push(data.errors.map((e, i) => `[${i + 1}] ${e}`).join("\n"));
    }
    if (data && data.primary_error) parts.push("primary: " + data.primary_error);
    return parts.join("\n") || "Yanıt alınamadı.";
  }

  function logLiveStep(entry) {
    if (!entry || !entry.stage) return;
    const stage = entry.stage;
    const t = typeof entry.t_ms === "number" ? `${entry.t_ms}ms` : "";
    if (stage === "llm_request") {
      console.log(`%c→ AI düşünüyor`, "color:#1f4d3a;font-weight:700", t, entry.provider || "");
    } else if (stage === "llm_response") {
      const tools = Array.isArray(entry.tool_calls) ? entry.tool_calls.join(", ") : "";
      console.log(
        `%c← AI yanıt`,
        "color:#1f4d3a;font-weight:700",
        tools ? `araç: ${tools}` : "metin",
        entry.text_preview || "",
        t
      );
    } else if (stage === "tool_call") {
      console.log(`%c⚙ Araç: ${entry.tool}`, "color:#b08968;font-weight:700", t);
      if (entry.sql) console.log("%cSQL", "font-weight:600", entry.sql);
      else if (entry.args) console.log("args", entry.args);
    } else if (stage === "mcp_request") {
      console.log(
        `%c→ MCP: ${entry.tool}`,
        "color:#7c5cbf;font-weight:700",
        entry.endpoint,
        entry.args_keys?.length ? `(${entry.args_keys.join(", ")})` : ""
      );
    } else if (stage === "mcp_response") {
      console.log(
        `%c← MCP: ${entry.tool}`,
        "color:#2e86ab;font-weight:700",
        entry.ok === false ? "HATA" : "ok",
        `${entry.elapsed_ms}ms`
      );
    } else if (stage === "mcp_error") {
      console.warn(`%c✗ MCP hata: ${entry.tool}`, "color:#c0392b;font-weight:700", entry.error, `${entry.elapsed_ms}ms`);
    } else if (stage === "tool_result") {
      console.log(
        `%c✓ Sonuç: ${entry.tool}`,
        "color:#1f4d3a;font-weight:700",
        entry.ok === false ? "HATA" : "ok",
        entry.result_preview || "",
        t
      );
    } else if (stage === "compose_report_start") {
      console.log("%c✎ Anlatım yazılıyor…", "color:#1f4d3a;font-weight:700", entry.provider || "");
    } else if (stage === "compose_report_done") {
      console.log(
        `%c✎ Anlatım hazır`,
        "color:#1f4d3a;font-weight:700",
        entry.repaired ? "(onarılmış)" : "",
        entry.text_preview || ""
      );
    } else if (stage === "provider_attempt" || stage === "provider_engine_ready") {
      console.log(`%c◎ Provider`, "color:#555;font-weight:600", entry.model || entry.engine || entry.provider_slot, t);
    } else if (stage === "provider_failed") {
      console.warn("Provider hata", entry.model || entry.provider_slot, entry.error);
    } else if (stage === "api_request" || stage === "provider_plan" || stage === "api_success") {
      console.log(`· ${stage}`, entry);
    } else {
      console.log(`· ${stage}`, entry);
    }
  }

  function logPipeline(label, payload, meta = {}) {
    const title = `[DWH Agent] ${label} · HTTP ${meta.status ?? "?"} · ${meta.ms ?? payload?.total_ms ?? "?"}ms · ${payload?.provider || "—"}`;
    console.groupCollapsed(title);
    console.log("özet", {
      ok: payload?.ok,
      type: payload?.type,
      provider: payload?.provider,
      llm_calls: payload?.llm_calls,
      message: payload?.message,
    });
    if (payload?.reports?.length) {
      console.log(
        "raporlar",
        payload.reports.map((r) => ({
          title: r.title,
          type: r.report_type,
          rows: r.meta?.row_count,
        }))
      );
    }
    console.groupEnd();
  }

  async function readNdjsonStream(res, onLog) {
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buf = "";
    let finalData = null;
    let errorData = null;
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buf += decoder.decode(value, { stream: true });
      let nl;
      while ((nl = buf.indexOf("\n")) >= 0) {
        const line = buf.slice(0, nl).trim();
        buf = buf.slice(nl + 1);
        if (!line) continue;
        let ev;
        try {
          ev = JSON.parse(line);
        } catch {
          console.warn("[DWH] ndjson parse", line.slice(0, 200));
          continue;
        }
        if (ev.event === "log" && ev.log) onLog(ev.log);
        else if (ev.event === "result") finalData = ev.data;
        else if (ev.event === "error") {
          errorData = ev.data || { ok: false, error: ev.error || "stream error" };
          if (ev.log) onLog(ev.log);
        }
      }
    }
    return { finalData, errorData };
  }

  async function sendMessage(text) {
    if (!text || busy) return;
    busy = true;
    btnSend.disabled = true;
    appendUser(text);
    appendTyping();
    const t0 = performance.now();
    console.group(`[DWH Agent] canlı · ${text.slice(0, 72)}`);
    console.log("soru", { session_id: sessionId, message: text });
    try {
      const res = await fetch("/api/chat.php", {
        method: "POST",
        headers: { "Content-Type": "application/json; charset=utf-8" },
        body: JSON.stringify({ message: text, session_id: sessionId, stream: true }),
      });
      const ctype = res.headers.get("content-type") || "";
      let data = null;
      if (ctype.includes("ndjson") && res.body) {
        const { finalData, errorData } = await readNdjsonStream(res, logLiveStep);
        data = finalData || errorData;
        if (!data) {
          data = { ok: false, error: "Akış tamamlandı ama sonuç gelmedi." };
        }
      } else {
        data = await res.json();
        (data.logs || []).forEach(logLiveStep);
      }
      const ms = Math.round(performance.now() - t0);
      logPipeline(data?.ok ? "OK" : "FAIL", data, { status: res.status, ms, message: text });
      if (!data?.ok) {
        appendAssistant({ type: "error", message: errorText(data) });
      } else {
        appendAssistant(data);
      }
    } catch (err) {
      console.error("[DWH] network/parse error", err);
      appendAssistant({
        type: "error",
        message: String(err && err.message ? err.message : err),
      });
    } finally {
      console.groupEnd();
      busy = false;
      btnSend.disabled = false;
      removeTyping();
      scrollChatToBottom();
    }
  }

  function appendUser(text) {
    clearEmpty();
    const node = el(`
      <article class="msg user">
        <div class="meta">Sen</div>
        <div class="bubble"></div>
      </article>`);
    node.querySelector(".bubble").textContent = text;
    messagesEl.appendChild(node);
    scrollChatToBottom();
  }

  function appendTyping() {
    const node = el(`
      <article class="msg assistant" id="typingMsg">
        <div class="meta">Asistan</div>
        <div class="bubble"><div class="typing"><span></span><span></span><span></span></div></div>
      </article>`);
    messagesEl.appendChild(node);
    scrollChatToBottom();
  }

  function removeTyping() {
    document.getElementById("typingMsg")?.remove();
  }

  function renderMarkdown(text) {
    const raw = String(text || "").trim();
    if (!raw) return "";
    const lines = raw.replace(/\r\n/g, "\n").split("\n");
    const out = [];
    let inList = false;
    const flushList = () => {
      if (inList) {
        out.push("</ul>");
        inList = false;
      }
    };
    for (const line of lines) {
      const t = line.trim();
      if (!t || t === "---" || t === "***") {
        flushList();
        continue;
      }
      const heading = t.replace(/^#{1,6}\s+/, "").replace(/\*\*/g, "");
      if (/^#{1,6}\s+/.test(t)) {
        flushList();
        out.push(`<p class="md-h">${escapeHtml(heading)}</p>`);
        continue;
      }
      const bullet = t.match(/^[-*•]\s+(.*)$/) || t.match(/^\*\*(.+?)\*\*:?\s*(.*)$/);
      if (t.startsWith("- ") || t.startsWith("* ") || t.startsWith("• ")) {
        if (!inList) {
          out.push('<ul class="md-ul">');
          inList = true;
        }
        const itemRaw = t.replace(/^[-*•]\s+/, "");
        const item = escapeHtml(itemRaw).replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
        out.push(`<li>${item}</li>`);
        continue;
      }
      flushList();
      const para = escapeHtml(t).replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
      out.push(`<p class="md-p">${para}</p>`);
    }
    flushList();
    return `<div class="md-body">${out.join("")}</div>`;
  }

  function seriesFromReport(r) {
    if (!Array.isArray(r.series) || !r.series.length) return [];
    const type = (r.report_type || "").toLowerCase();
    const kind = (r.chart_kind || "").toLowerCase();
    // Trends + explicit bar breakdowns
    if (type === "trend" || kind === "bar" || r.presentation === "top_entities") {
      return r.series.filter((s) => (s.points || []).length >= 2).slice(0, 2);
    }
    return [];
  }

  function renderChartBlock(r, chartId) {
    const series = seriesFromReport(r);
    if (!series.length) return { html: "", series: null, chartId: null, chartKind: "line" };
    return {
      html: `<div class="chart-wrap"><canvas id="${chartId}" height="160"></canvas></div>`,
      series,
      chartId,
      chartKind: (r.chart_kind || "line").toLowerCase() === "bar" ? "bar" : "line",
    };
  }

  function mountCharts(root, jobs) {
    if (!jobs.length || typeof Chart === "undefined") return;
    jobs.forEach(({ chartId, series, chartKind }) => {
      const canvas = root.querySelector("#" + chartId);
      if (!canvas || !series?.length) return;
      const labels = series[0].points.map((p) => p.x);
      const colors = ["#1f6b4a", "#b08968"];
      const isBar = chartKind === "bar";
      new Chart(canvas, {
        type: isBar ? "bar" : "line",
        data: {
          labels,
          datasets: series.map((s, i) => ({
            label: s.name,
            data: s.points.map((p) => p.y),
            borderColor: colors[i % colors.length],
            backgroundColor: isBar ? colors[i % colors.length] + "cc" : "transparent",
            tension: isBar ? 0 : 0.25,
            pointRadius: isBar ? 0 : 2,
            borderWidth: 2,
          })),
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: series.length > 1 } },
          scales: {
            x: { ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 12 } },
            y: { beginAtZero: true },
          },
        },
      });
    });
  }

  function renderClarify(clarify) {
    if (!clarify) return "";
    const questions = (clarify.questions || [])
      .map((q) => `<div>• ${escapeHtml(q)}</div>`)
      .join("");
    const chips = (clarify.filter_suggestions || [])
      .map((f) => {
        const label = f.label || f.field || String(f);
        const example = f.example || "";
        const payload = example ? `${label}: ${example}` : label;
        return `<button type="button" class="chip" data-fill="${escapeAttr(payload)}">${escapeHtml(label)}${example ? ` · ${escapeHtml(example)}` : ""}</button>`;
      })
      .join("");
    return `
      <div class="clarify-box">
        ${questions ? `<div>${questions}</div>` : ""}
        <div class="chip-row">${chips}</div>
      </div>`;
  }

  function renderReports(reports, chartJobs) {
    if (!reports || !reports.length) return "";
    return reports
      .map((r, idx) => {
        const type = (r.report_type || "").toLowerCase();
        const chartId = `chart_${Date.now()}_${idx}`;
        const chart = renderChartBlock(r, chartId);
        if (chart.series) chartJobs.push({ chartId, series: chart.series, chartKind: chart.chartKind });

        // Compact KPI strip — skip on trend charts (series already tells the story)
        let kpiHtml = "";
        const kpiList = (r.kpi || []).slice(0, 4);
        if (kpiList.length && !chart.series) {
          kpiHtml = `<div class="kpi-strip">${kpiList
            .map(
              (k) =>
                `<span class="kpi-chip"><span class="kpi-chip-label">${escapeHtml(String(k.name))}</span>` +
                `<span class="kpi-chip-value">${escapeHtml(formatNum(k.value))}${
                  k.unit ? ` ${escapeHtml(k.unit)}` : ""
                }</span></span>`
            )
            .join("")}</div>`;
        }

        // Strategic table: prefer rollup / presentation_table over raw head rows
        let table = null;
        let tableLabel = "Tablo";
        if (r.presentation_table?.columns && r.presentation_table?.rows?.length) {
          table = r.presentation_table;
          tableLabel = r.presentation_table.label || "Özet tablo";
        } else if (r.rollup?.by_state?.length) {
          table = {
            columns: Object.keys(r.rollup.by_state[0]),
            rows: r.rollup.by_state,
          };
          tableLabel = "Eyalet rollup (tüm data)";
        } else if ((r.meta?.row_count || 0) <= 40 && r.table?.columns && r.table?.rows?.length) {
          table = r.table;
          tableLabel = "Tablo";
        } else if (r.presentation === "top_entities" && r.table?.rows?.length) {
          table = r.table;
          tableLabel = `Top ${r.table.rows.length}${r.meta?.groups_total ? ` / ${r.meta.groups_total}` : ""}`;
        }

        let tableHtml = "";
        const isBrowse = type === "browse" || r.delivery === "ui_only";
        // If we already have a chart for this report, skip duplicate huge tables
        const showTable =
          table?.columns &&
          table.rows?.length &&
          (isBrowse || !chart.series || (table.rows.length <= 30 && type !== "trend"));
        if (showTable) {
          const maxShow = isBrowse ? 100 : Math.min(30, table.rows.length);
          const rows = (table.rows || []).slice(0, maxShow);
          const head = table.columns.map((c) => `<th>${escapeHtml(c)}</th>`).join("");
          const body = rows
            .map(
              (row) =>
                `<tr>${table.columns
                  .map((c) => `<td>${escapeHtml(formatCell(row[c]))}</td>`)
                  .join("")}</tr>`
            )
            .join("");
          const total = r.meta?.row_count ?? table.rows.length;
          const label = isBrowse
            ? `Tablo · ${rows.length}/${total}`
            : `${tableLabel} · ${rows.length}${total > rows.length ? ` / ${total}` : ""}`;
          tableHtml = renderTablePanel(label, head, body);
        }
        return `
          <div class="report-box">
            <div class="report-title">${escapeHtml(r.title || "Özet")}</div>
            ${kpiHtml}
            ${chart.html}
            ${tableHtml}
          </div>`;
      })
      .join("");
  }

  function renderTablePanel(label, head, body) {
    return `
      <div class="table-panel" data-table-panel>
        <div class="table-panel-bar">
          <span class="table-panel-title">${escapeHtml(label)}</span>
          <button type="button" class="table-fs-btn" data-table-fs aria-label="Tam ekran" title="Tam ekran">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <path d="M9 3H3v6M15 3h6v6M9 21H3v-6M21 15v6h-6"/>
            </svg>
          </button>
        </div>
        <div class="table-wrap"><table class="data"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>
      </div>`;
  }

  const ICON_EXPAND = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 3H3v6M15 3h6v6M9 21H3v-6M21 15v6h-6"/></svg>`;
  const ICON_COLLAPSE = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 3v5H3M16 3v5h5M8 21v-5H3M21 16h-5v5"/></svg>`;

  let fsState = null;
  let fsBusy = false;

  function ensureFsLayer() {
    let layer = document.getElementById("tableFsLayer");
    if (layer) return layer;
    layer = el(`
      <div class="table-fs-layer" id="tableFsLayer" aria-hidden="true">
        <div class="table-fs-backdrop" data-fs-close></div>
        <div class="table-fs-stage" id="tableFsStage"></div>
      </div>`);
    document.body.appendChild(layer);
    layer.querySelector("[data-fs-close]").addEventListener("click", () => collapseTableFs());
    return layer;
  }

  function setFsBtn(panel, expanded) {
    const btn = panel.querySelector("[data-table-fs]");
    if (!btn) return;
    btn.innerHTML = expanded ? ICON_COLLAPSE : ICON_EXPAND;
    btn.setAttribute("aria-label", expanded ? "Küçült" : "Tam ekran");
    btn.title = expanded ? "Küçült" : "Tam ekran";
  }

  function flipTo(panel, fromRect, ms) {
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const last = panel.getBoundingClientRect();
    if (reduced || last.width < 1 || fromRect.width < 1) {
      panel.style.transition = "";
      panel.style.transform = "";
      return Promise.resolve();
    }
    const dx = fromRect.left - last.left;
    const dy = fromRect.top - last.top;
    const sx = fromRect.width / last.width;
    const sy = fromRect.height / last.height;
    panel.style.transformOrigin = "top left";
    panel.style.transition = "none";
    panel.style.transform = `translate(${dx}px, ${dy}px) scale(${sx}, ${sy})`;
    // force reflow
    void panel.offsetWidth;
    return new Promise((resolve) => {
      const done = () => {
        panel.style.transition = "";
        panel.style.transform = "";
        panel.removeEventListener("transitionend", onEnd);
        resolve();
      };
      const onEnd = (e) => {
        if (e.propertyName === "transform") done();
      };
      panel.addEventListener("transitionend", onEnd);
      panel.style.transition = `transform ${ms}ms cubic-bezier(0.22, 1, 0.36, 1)`;
      panel.style.transform = "none";
      setTimeout(done, ms + 80);
    });
  }

  async function expandTableFs(panel) {
    if (fsBusy || !panel) return;
    if (fsState?.panel === panel) return;
    if (fsState) await collapseTableFs(true);
    fsBusy = true;
    try {
      const layer = ensureFsLayer();
      const stage = document.getElementById("tableFsStage");
      const first = panel.getBoundingClientRect();

      const placeholder = document.createElement("div");
      placeholder.className = "table-fs-placeholder";
      placeholder.style.height = `${Math.max(48, first.height)}px`;
      panel.parentNode.insertBefore(placeholder, panel);

      setFsBtn(panel, true);
      stage.appendChild(panel);
      layer.classList.add("is-open");
      layer.setAttribute("aria-hidden", "false");
      document.documentElement.style.overflow = "hidden";

      fsState = { panel, placeholder };
      await flipTo(panel, first, 440);
    } finally {
      fsBusy = false;
    }
  }

  async function collapseTableFs(immediate = false) {
    if (!fsState) return;
    if (fsBusy && !immediate) return;
    fsBusy = true;
    const { panel, placeholder } = fsState;
    const layer = ensureFsLayer();
    const stage = document.getElementById("tableFsStage");
    try {
      const from = panel.getBoundingClientRect();
      const to = placeholder.getBoundingClientRect();

      if (immediate || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        placeholder.parentNode?.insertBefore(panel, placeholder);
        placeholder.remove();
      } else {
        // Fly from fullscreen card → chat slot while still over the dimmed layer
        panel.style.position = "fixed";
        panel.style.left = `${from.left}px`;
        panel.style.top = `${from.top}px`;
        panel.style.width = `${from.width}px`;
        panel.style.height = `${from.height}px`;
        panel.style.margin = "0";
        panel.style.zIndex = "90";
        panel.style.transition = "none";
        void panel.offsetWidth;
        await new Promise((resolve) => {
          const done = () => {
            panel.removeEventListener("transitionend", onEnd);
            resolve();
          };
          const onEnd = (e) => {
            if (e.propertyName === "width" || e.propertyName === "transform") done();
          };
          panel.addEventListener("transitionend", onEnd);
          panel.style.transition =
            "left 400ms cubic-bezier(0.22, 1, 0.36, 1), top 400ms cubic-bezier(0.22, 1, 0.36, 1), width 400ms cubic-bezier(0.22, 1, 0.36, 1), height 400ms cubic-bezier(0.22, 1, 0.36, 1), border-radius 400ms ease";
          panel.style.left = `${to.left}px`;
          panel.style.top = `${to.top}px`;
          panel.style.width = `${to.width}px`;
          panel.style.height = `${to.height}px`;
          panel.style.borderRadius = "14px";
          setTimeout(done, 460);
        });
        placeholder.parentNode?.insertBefore(panel, placeholder);
        placeholder.remove();
        panel.style.position = "";
        panel.style.left = "";
        panel.style.top = "";
        panel.style.width = "";
        panel.style.height = "";
        panel.style.margin = "";
        panel.style.zIndex = "";
        panel.style.transition = "";
        panel.style.borderRadius = "";
      }

      setFsBtn(panel, false);
      layer.classList.remove("is-open");
      layer.setAttribute("aria-hidden", "true");
      document.documentElement.style.overflow = "";
      while (stage.firstChild) stage.removeChild(stage.firstChild);
      fsState = null;
    } finally {
      fsBusy = false;
    }
  }

  function bindTableFullscreen(root) {
    root.querySelectorAll("[data-table-fs]").forEach((btn) => {
      if (btn.dataset.bound === "1") return;
      btn.dataset.bound = "1";
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        const panel = btn.closest("[data-table-panel]");
        if (!panel) return;
        if (fsState?.panel === panel) collapseTableFs();
        else expandTableFs(panel);
      });
    });
  }

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && fsState) {
      e.preventDefault();
      collapseTableFs();
    }
  });

  function metaLabel(type) {
    if (type === "error") return "Sistem";
    if (type === "clarify") return "Asistan · netleştirme";
    if (type === "report") return "Asistan · özet";
    return "Asistan";
  }

  function appendAssistant(payload) {
    removeTyping();
    clearEmpty();
    const node = el(`
      <article class="msg assistant">
        <div class="meta"></div>
        <div class="bubble"></div>
      </article>`);
    node.querySelector(".meta").textContent = metaLabel(payload.type);
    const bubble = node.querySelector(".bubble");
    const chartJobs = [];
    bubble.innerHTML =
      renderMarkdown(payload.message || "") +
      renderClarify(payload.clarify) +
      renderReports(payload.reports, chartJobs);
    messagesEl.appendChild(node);
    mountCharts(bubble, chartJobs);
    bindTableFullscreen(bubble);
    scrollChatToBottom();

    bubble.querySelectorAll(".chip").forEach((chip) => {
      chip.addEventListener("click", () => {
        input.value = (input.value ? input.value + "\n" : "") + chip.getAttribute("data-fill");
        input.focus();
        autosize();
      });
    });
  }

  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }
  function escapeAttr(s) {
    return escapeHtml(s).replaceAll("'", "&#39;");
  }
  function formatNum(v) {
    if (typeof v === "number") return new Intl.NumberFormat("tr-TR", { maximumFractionDigits: 2 }).format(v);
    return String(v);
  }
  function formatCell(v) {
    if (v === null || v === undefined) return "";
    if (typeof v === "number") return formatNum(v);
    return String(v);
  }

  function autosize() {
    input.style.height = "auto";
    input.style.height = Math.min(input.scrollHeight, 160) + "px";
  }
  input.addEventListener("input", autosize);
  input.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = "";
    autosize();
    sendMessage(text);
  });

  btnNew.addEventListener("click", () => {
    sessionId = crypto.randomUUID().replace(/-/g, "").slice(0, 16);
    localStorage.setItem(SESSION_KEY, sessionId);
    messagesEl.innerHTML = "";
    showEmpty();
  });

  fetch("/api/profile.php")
    .then((r) => r.json())
    .then((p) => {
      if (!p?.ok) return;
      const sub = document.getElementById("brandSub");
      const hint = document.getElementById("sidebarHint");
      const topSub = document.getElementById("topSub");
      if (sub) sub.textContent = p.ui_subtitle || p.display_name || p.id;
      if (hint) {
        hint.textContent =
          `Aktif profil: ${p.display_name || p.id}. Doğal dilde sor; join/filtre/metrik profil + SQL ile çözülür.`;
      }
      const examples = Array.isArray(p.ui_examples) ? p.ui_examples.filter(Boolean) : [];
      if (topSub && examples.length) {
        topSub.textContent = "Örnek: “" + examples[0] + "”";
      }
    })
    .catch(() => {});

  fetch("/api/health.php")
    .then((r) => r.json())
    .then((d) => {
      if (!d.ok) {
        healthEl.textContent = "Bağlantı sorunu";
        return;
      }
      const name = d.profile?.display_name || d.profile?.id || "DWH";
      healthEl.textContent = `Hazır · ${name}`;
    })
    .catch(() => {
      healthEl.textContent = "Sunucu kapalı";
    });

  showEmpty();
})();
