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

  function logPipeline(label, payload, meta = {}) {
    const logs = Array.isArray(payload?.logs) ? payload.logs : [];
    const title = `[DWH] ${label} · ${meta.status ?? "?"} · ${meta.ms ?? payload?.total_ms ?? "?"}ms · ${payload?.provider || "—"}`;
    console.groupCollapsed(title);
    console.log("request", {
      session_id: sessionId,
      message: meta.message,
      http_status: meta.status,
    });
    console.log("response_summary", {
      ok: payload?.ok,
      type: payload?.type,
      provider: payload?.provider,
      fallback: payload?.fallback,
      llm_calls: payload?.llm_calls,
      primary_error: payload?.primary_error,
      errors: payload?.errors,
      message_preview: (payload?.message || "").slice(0, 200),
    });
    if (logs.length) {
      console.table(
        logs.map((e) => ({
          t_ms: e.t_ms,
          stage: e.stage,
          provider: e.provider || e.engine || e.provider_slot || "",
          detail:
            e.error ||
            e.text_preview ||
            e.result_preview ||
            (e.tool_calls ? e.tool_calls.join(",") : "") ||
            (e.tool || "") ||
            (e.reason || "") ||
            "",
        }))
      );
      logs.forEach((entry, i) => {
        console.log(`${i + 1}. ${entry.stage}`, entry);
      });
    } else {
      console.warn("logs yok — sunucu eski kod çalışıyor olabilir (php -S yeniden başlat)");
    }
    if (payload?.trace?.length) {
      console.log("tool_trace", payload.trace);
    }
    console.groupEnd();
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
    // Only chart when the agent explicitly chose a trend report with series
    if ((r.report_type || "") !== "trend") return [];
    if (!Array.isArray(r.series) || !r.series.length) return [];
    return r.series.filter((s) => (s.points || []).length >= 2).slice(0, 2);
  }

  function renderChartBlock(r, chartId) {
    const series = seriesFromReport(r);
    if (!series.length) return { html: "", series: null, chartId: null };
    return {
      html: `<div class="chart-wrap"><canvas id="${chartId}" height="160"></canvas></div>`,
      series,
      chartId,
    };
  }

  function mountCharts(root, jobs) {
    if (!jobs.length || typeof Chart === "undefined") return;
    jobs.forEach(({ chartId, series }) => {
      const canvas = root.querySelector("#" + chartId);
      if (!canvas || !series?.length) return;
      const labels = series[0].points.map((p) => p.x);
      const colors = ["#1f6b4a", "#b08968"];
      new Chart(canvas, {
        type: "line",
        data: {
          labels,
          datasets: series.map((s, i) => ({
            label: s.name,
            data: s.points.map((p) => p.y),
            borderColor: colors[i % colors.length],
            backgroundColor: "transparent",
            tension: 0.25,
            pointRadius: 3,
            borderWidth: 2,
            yAxisID: i === 0 ? "y" : "y1",
          })),
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: "index", intersect: false },
          plugins: {
            legend: { position: "bottom", labels: { boxWidth: 10, font: { size: 11 } } },
          },
          scales: {
            y: { position: "left", grid: { color: "rgba(0,0,0,0.06)" } },
            y1: {
              position: "right",
              display: series.length > 1,
              grid: { drawOnChartArea: false },
            },
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
        const kpis = (r.kpi || [])
          .slice(0, 4)
          .map(
            (k) => `
          <div class="kpi-card">
            <div class="label">${escapeHtml(String(k.name))}</div>
            <div class="value">${escapeHtml(formatNum(k.value))}${k.unit ? ` ${escapeHtml(k.unit)}` : ""}</div>
          </div>`
          )
          .join("");
        const chartId = `chart_${Date.now()}_${idx}`;
        const chart = renderChartBlock(r, chartId);
        if (chart.series) chartJobs.push({ chartId, series: chart.series });

        const table = r.table;
        let tableHtml = "";
        const showTable = !chart.series && table?.columns && table.rows?.length;
        if (showTable) {
          const rows = (table.rows || []).slice(0, 6);
          const head = table.columns.map((c) => `<th>${escapeHtml(c)}</th>`).join("");
          const body = rows
            .map(
              (row) =>
                `<tr>${table.columns
                  .map((c) => `<td>${escapeHtml(formatCell(row[c]))}</td>`)
                  .join("")}</tr>`
            )
            .join("");
          const more = table.rows.length > 6 ? `<div class="table-more">${table.rows.length - 6} satır daha gizli</div>` : "";
          tableHtml = `<details class="table-details" open><summary>Tablo (${rows.length} satır)</summary><div class="table-wrap"><table class="data"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>${more}</details>`;
        } else if (chart.series && table?.rows?.length) {
          tableHtml = `<details class="table-details"><summary>Veri tablosu</summary><div class="table-wrap"><table class="data"><thead><tr>${table.columns.map((c) => `<th>${escapeHtml(c)}</th>`).join("")}</tr></thead><tbody>${table.rows.slice(0, 6).map((row) => `<tr>${table.columns.map((c) => `<td>${escapeHtml(formatCell(row[c]))}</td>`).join("")}</tr>`).join("")}</tbody></table></div></details>`;
        }
        return `
          <div class="report-box">
            <div class="report-title">${escapeHtml(r.title || "Özet")}</div>
            ${kpis ? `<div class="kpi-grid">${kpis}</div>` : ""}
            ${chart.html}
            ${tableHtml}
          </div>`;
      })
      .join("");
  }

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

  async function sendMessage(text) {
    if (!text || busy) return;
    busy = true;
    btnSend.disabled = true;
    appendUser(text);
    appendTyping();
    const t0 = performance.now();
    console.log("[DWH] POST /api/chat.php", { session_id: sessionId, message: text });
    try {
      const res = await fetch("/api/chat.php", {
        method: "POST",
        headers: { "Content-Type": "application/json; charset=utf-8" },
        body: JSON.stringify({ message: text, session_id: sessionId }),
      });
      const data = await res.json();
      const ms = Math.round(performance.now() - t0);
      logPipeline(res.ok && data.ok ? "OK" : "FAIL", data, {
        status: res.status,
        ms,
        message: text,
      });
      if (!res.ok || !data.ok) {
        appendAssistant({
          type: "error",
          message: errorText(data),
        });
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
      busy = false;
      btnSend.disabled = false;
      removeTyping();
      scrollChatToBottom();
    }
  }

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

  fetch("/api/health.php")
    .then((r) => r.json())
    .then((d) => {
      healthEl.textContent = d.ok ? "Hazır" : "Bağlantı sorunu";
    })
    .catch(() => {
      healthEl.textContent = "Sunucu kapalı";
    });

  showEmpty();
})();
