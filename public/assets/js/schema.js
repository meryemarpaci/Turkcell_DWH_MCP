(() => {
  const diagram = document.getElementById("diagram");
  const svg = document.getElementById("joinLines");
  const viewport = document.getElementById("viewport");
  const canvasWrap = document.getElementById("canvasWrap");
  const tableList = document.getElementById("tableList");
  const joinList = document.getElementById("joinList");
  const detailTitle = document.getElementById("detailTitle");
  const detailDesc = document.getElementById("detailDesc");
  const detailBody = document.getElementById("detailBody");

  const CARD_W = 220;
  const HEAD_H = 40;
  const COL_H = 22;
  const WORLD_W = 1400;
  const WORLD_H = 900;

  const LAYOUT = {
    dim_customer: { x: 40, y: 80 },
    dim_geolocation: { x: 40, y: 360 },
    dim_product: { x: 40, y: 620 },
    dim_seller: { x: 320, y: 620 },
    fact_orders: { x: 520, y: 120 },
    fact_order_items: { x: 820, y: 280 },
    fact_order_payments: { x: 820, y: 40 },
    fact_order_reviews: { x: 820, y: 520 },
  };

  let schema = null;
  let selectedTable = null;
  let selectedJoin = null;
  const fkCols = new Map();

  // Pan + zoom state
  let scale = 1;
  let panX = 40;
  let panY = 30;
  let dragging = false;
  let dragMoved = false;
  let lastX = 0;
  let lastY = 0;
  let pointers = new Map();
  let pinchStartDist = 0;
  let pinchStartScale = 1;

  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function kindOf(name) {
    return name.startsWith("dim_") ? "dim" : "fact";
  }

  function applyTransform() {
    viewport.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
  }

  function clampScale(s) {
    return Math.min(2.5, Math.max(0.35, s));
  }

  function zoomAt(clientX, clientY, nextScale) {
    const rect = canvasWrap.getBoundingClientRect();
    const x = clientX - rect.left;
    const y = clientY - rect.top;
    const worldX = (x - panX) / scale;
    const worldY = (y - panY) / scale;
    scale = clampScale(nextScale);
    panX = x - worldX * scale;
    panY = y - worldY * scale;
    applyTransform();
  }

  function buildFkIndex(joins) {
    fkCols.clear();
    joins.forEach((j) => {
      if (!fkCols.has(j.left_table)) fkCols.set(j.left_table, new Set());
      if (!fkCols.has(j.right_table)) fkCols.set(j.right_table, new Set());
      fkCols.get(j.left_table).add(j.left_key);
      fkCols.get(j.right_table).add(j.right_key);
    });
  }

  function renderSide() {
    tableList.innerHTML = "";
    Object.keys(schema.tables)
      .sort()
      .forEach((name) => {
        const info = schema.tables[name];
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "side-item" + (selectedTable === name ? " active" : "");
        btn.innerHTML = `${escapeHtml(name)}<span class="sub">${info.row_count.toLocaleString("tr-TR")} satır · ${kindOf(name)}</span>`;
        btn.addEventListener("click", () => selectTable(name));
        tableList.appendChild(btn);
      });

    joinList.innerHTML = "";
    schema.joins.forEach((j) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "side-item" + (selectedJoin === j.id ? " active" : "");
      btn.innerHTML = `${escapeHtml(j.id)}<span class="sub">${escapeHtml(j.left_table)}.${escapeHtml(j.left_key)} → ${escapeHtml(j.right_table)}.${escapeHtml(j.right_key)}</span>`;
      btn.addEventListener("click", () => selectJoin(j.id));
      joinList.appendChild(btn);
    });
  }

  function renderDiagram() {
    diagram.innerHTML = "";
    Object.entries(schema.tables).forEach(([name, info]) => {
      const pos = LAYOUT[name] || { x: 40, y: 40 };
      const card = document.createElement("article");
      card.className = `tbl-card ${kindOf(name)}` + (selectedTable === name ? " active" : "");
      card.id = `tbl-${name}`;
      card.style.left = pos.x + "px";
      card.style.top = pos.y + "px";
      const fks = fkCols.get(name) || new Set();
      const colsHtml = info.columns
        .map((c) => {
          const isPk = !!c.pk;
          const isFk = fks.has(c.name) && !isPk;
          const hl =
            selectedJoin &&
            schema.joins.some(
              (j) =>
                j.id === selectedJoin &&
                ((j.left_table === name && j.left_key === c.name) ||
                  (j.right_table === name && j.right_key === c.name))
            );
          const cls = ["col", isPk ? "pk" : "", isFk ? "fk" : "", hl ? "hl" : ""].filter(Boolean).join(" ");
          return `<div class="${cls}" data-col="${escapeHtml(c.name)}"><span>${escapeHtml(c.name)}${isPk ? " ★" : isFk ? " ↗" : ""}</span><span class="t">${escapeHtml(c.type || "")}</span></div>`;
        })
        .join("");
      card.innerHTML = `
        <div class="head"><span>${escapeHtml(name)}</span><span class="badge">${kindOf(name)}</span></div>
        <div class="cols">${colsHtml}</div>`;
      card.addEventListener("click", (e) => {
        if (dragMoved) {
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        selectTable(name);
      });
      diagram.appendChild(card);
    });
    drawLines();
  }

  function cardAnchor(table, key, side) {
    const pos = LAYOUT[table];
    if (!pos) return null;
    const cols = schema.tables[table]?.columns || [];
    const idx = Math.max(0, cols.findIndex((c) => c.name === key));
    const y = pos.y + HEAD_H + idx * COL_H + COL_H / 2;
    const x = side === "left" ? pos.x : pos.x + CARD_W;
    return { x, y };
  }

  function drawLines() {
    svg.setAttribute("width", String(WORLD_W));
    svg.setAttribute("height", String(WORLD_H));
    svg.setAttribute("viewBox", `0 0 ${WORLD_W} ${WORLD_H}`);
    svg.innerHTML = "";
    schema.joins.forEach((j) => {
      const a = cardAnchor(j.left_table, j.left_key, "right");
      const b = cardAnchor(j.right_table, j.right_key, "left");
      if (!a || !b) return;
      const active = selectedJoin === j.id || selectedTable === j.left_table || selectedTable === j.right_table;
      const line = document.createElementNS("http://www.w3.org/2000/svg", "line");
      line.setAttribute("x1", String(a.x));
      line.setAttribute("y1", String(a.y));
      line.setAttribute("x2", String(b.x));
      line.setAttribute("y2", String(b.y));
      line.setAttribute("class", active ? "active" : "");
      svg.appendChild(line);

      const mx = (a.x + b.x) / 2;
      const my = (a.y + b.y) / 2 - 6;
      const label = document.createElementNS("http://www.w3.org/2000/svg", "text");
      label.setAttribute("x", String(mx));
      label.setAttribute("y", String(my));
      label.setAttribute("text-anchor", "middle");
      label.textContent = j.id;
      label.style.cursor = "pointer";
      label.style.pointerEvents = "auto";
      label.addEventListener("click", (e) => {
        e.stopPropagation();
        selectJoin(j.id);
      });
      svg.appendChild(label);
    });
  }

  function selectTable(name) {
    selectedTable = name;
    selectedJoin = null;
    renderSide();
    renderDiagram();
    const info = schema.tables[name];
    detailTitle.textContent = name;
    detailDesc.textContent = info.description || "";
    const related = schema.joins.filter((j) => j.left_table === name || j.right_table === name);
    detailBody.innerHTML = `
      <div class="detail-kv">
        <div class="row"><b>Satır</b>${info.row_count.toLocaleString("tr-TR")}</div>
        <div class="row"><b>Tip</b>${kindOf(name)}</div>
        <div class="row"><b>Bağlantılar</b>${
          related.length
            ? related
                .map((j) => `<button type="button" class="chip-join" data-join="${escapeHtml(j.id)}">${escapeHtml(j.id)}</button>`)
                .join("")
            : "—"
        }</div>
      </div>
      <table class="col-table">
        <thead><tr><th>Kolon</th><th>Tip</th><th></th></tr></thead>
        <tbody>
          ${info.columns
            .map((c) => {
              const fks = fkCols.get(name) || new Set();
              const tag = c.pk ? "PK" : fks.has(c.name) ? "bağlantı" : "";
              return `<tr><td>${escapeHtml(c.name)}</td><td>${escapeHtml(c.type || "")}</td><td>${tag}</td></tr>`;
            })
            .join("")}
        </tbody>
      </table>`;
    detailBody.querySelectorAll("[data-join]").forEach((btn) => {
      btn.addEventListener("click", () => selectJoin(btn.getAttribute("data-join")));
    });
  }

  function selectJoin(id) {
    selectedJoin = id;
    selectedTable = null;
    renderSide();
    renderDiagram();
    const j = schema.joins.find((x) => x.id === id);
    if (!j) return;
    detailTitle.textContent = j.id;
    detailDesc.textContent = j.description || "";
    detailBody.innerHTML = `
      <div class="detail-kv">
        <div class="row"><b>Sol</b><button type="button" class="chip-join" data-table="${escapeHtml(j.left_table)}">${escapeHtml(j.left_table)}.${escapeHtml(j.left_key)}</button></div>
        <div class="row"><b>Sağ</b><button type="button" class="chip-join" data-table="${escapeHtml(j.right_table)}">${escapeHtml(j.right_table)}.${escapeHtml(j.right_key)}</button></div>
        <div class="row"><b>İlişki</b>${escapeHtml(j.cardinality || "")}</div>
        <div class="row"><b>Bağlantı</b><code>${escapeHtml(j.left_table)}.${escapeHtml(j.left_key)} = ${escapeHtml(j.right_table)}.${escapeHtml(j.right_key)}</code></div>
      </div>`;
    detailBody.querySelectorAll("[data-table]").forEach((btn) => {
      btn.addEventListener("click", () => selectTable(btn.getAttribute("data-table")));
    });
  }

  // --- Pan / zoom interactions ---
  canvasWrap.addEventListener(
    "wheel",
    (e) => {
      e.preventDefault();
      // Trackpad pinch usually comes as ctrlKey + wheel
      if (e.ctrlKey || e.metaKey) {
        const factor = Math.exp(-e.deltaY * 0.01);
        zoomAt(e.clientX, e.clientY, scale * factor);
      } else {
        panX -= e.deltaX;
        panY -= e.deltaY;
        applyTransform();
      }
    },
    { passive: false }
  );

  canvasWrap.addEventListener("pointerdown", (e) => {
    if (e.button !== 0 && e.pointerType === "mouse") return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    canvasWrap.setPointerCapture(e.pointerId);
    if (pointers.size === 1) {
      dragging = true;
      dragMoved = false;
      lastX = e.clientX;
      lastY = e.clientY;
      canvasWrap.classList.add("is-panning");
    } else if (pointers.size === 2) {
      dragging = false;
      const pts = [...pointers.values()];
      pinchStartDist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
      pinchStartScale = scale;
    }
  });

  canvasWrap.addEventListener("pointermove", (e) => {
    if (!pointers.has(e.pointerId)) return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

    if (pointers.size === 2) {
      const pts = [...pointers.values()];
      const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
      if (pinchStartDist > 0) {
        const midX = (pts[0].x + pts[1].x) / 2;
        const midY = (pts[0].y + pts[1].y) / 2;
        zoomAt(midX, midY, pinchStartScale * (dist / pinchStartDist));
      }
      return;
    }

    if (!dragging) return;
    const dx = e.clientX - lastX;
    const dy = e.clientY - lastY;
    if (Math.abs(dx) + Math.abs(dy) > 3) dragMoved = true;
    panX += dx;
    panY += dy;
    lastX = e.clientX;
    lastY = e.clientY;
    applyTransform();
  });

  function endPointer(e) {
    pointers.delete(e.pointerId);
    if (pointers.size < 2) {
      pinchStartDist = 0;
    }
    if (pointers.size === 0) {
      dragging = false;
      canvasWrap.classList.remove("is-panning");
      setTimeout(() => {
        dragMoved = false;
      }, 0);
    }
  }

  canvasWrap.addEventListener("pointerup", endPointer);
  canvasWrap.addEventListener("pointercancel", endPointer);
  window.addEventListener("resize", () => drawLines());

  applyTransform();

  fetch("/api/schema.php")
    .then((r) => r.json())
    .then((data) => {
      if (!data.ok && data.tables == null) throw new Error(data.error || "schema failed");
      schema = data;
      buildFkIndex(schema.joins || []);
      renderSide();
      renderDiagram();
      const first = Object.keys(schema.tables)[0];
      if (first) selectTable(first);
    })
    .catch((err) => {
      detailTitle.textContent = "Hata";
      detailDesc.textContent = String(err.message || err);
    });
})();
