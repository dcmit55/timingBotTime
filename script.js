/* =============== ProductionWebSystemV3 - script.js (clean) =============== */
const api = "server";

/* ---------- Global state ---------- */
let autoId = null; // interval auto refresh
let isEditing = false; // sedang edit inline?
let inflight = null; // AbortController untuk fetch berjalan
let lastChangeTs = 0; // timestamp perubahan terakhir (server)
let pendingRefresh = false; // perlu refresh sesudah edit?
let sse = null; // Server-Sent Events connection
let changeWatcherId = null; // fallback polling last_change

// 🆕 Cache data dari Laravel API
let projectsData = []; // data dari http://127.0.0.1:8000/api/v1/projects
let employeesData = []; // data dari http://127.0.0.1:8000/api/v1/employees
let partsCache = {}; // cache parts berdasarkan project_id: { project_id: [...parts] }

// 🆕 Flag untuk mencegah refresh setelah auto-save
let justSavedTimestamp = 0; // timestamp terakhir auto-save
const SAVE_COOLDOWN = 2000; // 2 detik cooldown setelah save

async function loadProjects() {
  try {
    console.log("[loadProjects] Fetching from server/projects_proxy.php...");
    const res = await fetch(`${api}/projects_proxy.php`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }

    const data = await res.json();

    // Validasi response
    if (data.error) {
      // Cek jika error 401 (Unauthorized - token tidak valid)
      if (data.http_code === 401) {
        throw new Error(`401|${data.error}|${data.message}`);
      }
      throw new Error(`API Error: ${data.error}`);
    }

    if (!Array.isArray(data)) {
      throw new Error("Invalid data format: expected array");
    }

    projectsData = data;
    console.log(
      `[loadProjects] Loaded ${projectsData.length} projects:`,
      projectsData
    );
  } catch (e) {
    console.error("[loadProjects] Error:", e);

    // Cek apakah error adalah 401 Unauthorized
    if (e.message.startsWith("401|")) {
      const [code, error, message] = e.message.split("|");
      alert(
        `🔒 AUTHENTICATION ERROR\n\n` +
          `Token API tidak valid atau sudah tidak aktif!\n\n` +
          `${message}\n\n` +
          `Detail: ${error}`
      );
    } else {
      alert(
        `Failed to load projects: ${e.message}\n\nPastikan Token dan API Laravel Valid`
      );
    }
    projectsData = [];
  }
}

/* ---------- 🆕 Load Data dari Laravel API ---------- */

async function loadEmployees() {
  try {
    console.log("[loadEmployees] Fetching from server/employees_proxy.php...");
    const res = await fetch(`${api}/employees_proxy.php`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }

    const data = await res.json();

    // Validasi response
    if (data.error) {
      // Cek jika error 401 (Unauthorized - token tidak valid)
      if (data.http_code === 401) {
        throw new Error(`401|${data.error}|${data.message}`);
      }
      throw new Error(`API Error: ${data.error}`);
    }

    if (!Array.isArray(data)) {
      throw new Error("Invalid data format: expected array");
    }

    employeesData = data;
    console.log(
      `[loadEmployees] Loaded ${employeesData.length} employees:`,
      employeesData
    );
  } catch (e) {
    console.error("[loadEmployees] Error:", e);

    // Cek apakah error adalah 401 Unauthorized
    if (e.message.startsWith("401|")) {
      const [code, error, message] = e.message.split("|");
      alert(
        `🔒 AUTHENTICATION ERROR\n\n` +
          `Token API tidak valid atau sudah tidak aktif!\n\n` +
          `${message}\n\n` +
          `Detail: ${error}`
      );
    } else {
      alert(
        `Failed to load employees: ${e.message}\n\nPastikan Laravel API berjalan di http://127.0.0.1:8000`
      );
    }
    employeesData = [];
  }
}

/**
 * 🆕 Load Parts dari Laravel API berdasarkan project_id
 * @param {number} projectId - ID project yang dipilih
 * @returns {Promise<Array>} Array of parts
 */
async function loadParts(projectId) {
  // Cek cache dulu
  if (partsCache[projectId]) {
    console.log(`[loadParts] Using cached parts for project_id=${projectId}`);
    return partsCache[projectId];
  }

  try {
    console.log(`[loadParts] Fetching parts for project_id=${projectId}...`);
    const res = await fetch(`${api}/parts_proxy.php?project_id=${projectId}`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }

    const data = await res.json();

    // Validasi response
    if (data.error) {
      throw new Error(`API Error: ${data.error}`);
    }

    // Unwrap 'data' key jika ada
    let parts = data;
    if (data.data && Array.isArray(data.data)) {
      parts = data.data;
    }

    if (!Array.isArray(parts)) {
      throw new Error("Invalid data format: expected array");
    }

    // Simpan ke cache
    partsCache[projectId] = parts;
    console.log(
      `[loadParts] Loaded ${parts.length} parts for project_id=${projectId}:`,
      parts
    );

    return parts;
  } catch (e) {
    console.error(`[loadParts] Error for project_id=${projectId}:`, e);
    // Tidak menampilkan alert, hanya log error di console
    return [];
  }
}

/* ---------- Helpers ---------- */
function startAuto() {
  if (autoId === null) {
    autoId = setInterval(() => {
      if (!isEditing) loadData();
    }, 3000);
  }
}
function stopAuto() {
  if (autoId !== null) {
    clearInterval(autoId);
    autoId = null;
  }
  if (inflight) {
    inflight.abort();
    inflight = null;
  }
}
function pauseEditing() {
  isEditing = true;
  stopAuto();
}
function resumeEditing() {
  isEditing = false;
  startAuto();
  if (pendingRefresh) {
    pendingRefresh = false;
    loadData();
  }
}

function updateClock() {
  const el = document.getElementById("datetime");
  if (el) el.textContent = new Date().toLocaleString();
}

async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, { cache: "no-store", ...opts });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

function tdInput(val, onEnter) {
  const i = document.createElement("input");
  i.type = "text";
  i.className = "inline-edit";
  i.value = val ?? "";
  i.size = Math.max(8, String(val ?? "").length);
  i.addEventListener("focus", pauseEditing);
  i.addEventListener("blur", () => setTimeout(resumeEditing, 150));
  if (onEnter) {
    i.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        onEnter();
      }
    });
  }
  return i;
}

/**
 * 🆕 Fungsi: Membuat Select2 dropdown untuk Project
 *
 * @param {string} currentValue - Nilai project yang sedang aktif
 * @param {function} onSelect - Callback saat user memilih project
 * @returns {HTMLSelectElement}
 */
function createProjectSelect(currentValue, onSelect) {
  const select = document.createElement("select");
  select.className = "project-select2";
  select.style.width = "250px";

  // Option kosong
  const emptyOpt = document.createElement("option");
  emptyOpt.value = "";
  emptyOpt.textContent = "-- Pilih Project --";
  select.appendChild(emptyOpt);

  // Ambil project unique dari projectsData
  const uniqueProjects = [...new Set(projectsData.map((p) => p.name))].filter(
    Boolean
  );

  uniqueProjects.forEach((projName) => {
    const opt = document.createElement("option");
    opt.value = projName;
    opt.textContent = projName;
    if (projName === currentValue) opt.selected = true;
    select.appendChild(opt);
  });

  // Inisialisasi Select2 setelah DOM ready
  setTimeout(() => {
    $(select)
      .select2({
        placeholder: "-- Pilih Project --",
        allowClear: true,
        width: "250px",
        dropdownAutoWidth: true,
      })
      .on("change", function () {
        const selectedValue = this.value;
        console.log("[Select2] Project selected:", selectedValue);
        if (onSelect) onSelect(selectedValue);
      });

    // Auto open dropdown
    $(select).select2("open");
  }, 50);

  return select;
}

/**
 * 🆕 Fungsi: Membuat Select2 dropdown untuk Part
 *
 * @param {number} projectId - ID project yang dipilih
 * @param {string} currentValue - Nilai part yang sedang aktif
 * @param {function} onSelect - Callback saat user memilih part
 * @returns {HTMLSelectElement}
 */
function createPartSelect(projectId, currentValue, onSelect) {
  const select = document.createElement("select");
  select.className = "part-select2";
  select.style.width = "200px";

  // Option kosong
  const emptyOpt = document.createElement("option");
  emptyOpt.value = "";
  emptyOpt.textContent = "-- Pilih Part --";
  select.appendChild(emptyOpt);

  // Inisialisasi Select2 setelah DOM ready
  setTimeout(async () => {
    // Load parts dari API berdasarkan project_id
    const parts = await loadParts(projectId);

    // Populate options
    parts.forEach((part) => {
      const opt = document.createElement("option");
      opt.value = part.name || part.part_name; // sesuaikan dengan response API
      opt.textContent = part.name || part.part_name;
      if (opt.value === currentValue) opt.selected = true;
      select.appendChild(opt);
    });

    // Inisialisasi Select2
    $(select)
      .select2({
        placeholder: "-- Pilih Part --",
        allowClear: true,
        width: "200px",
        dropdownAutoWidth: true,
      })
      .on("change", function () {
        const selectedValue = this.value;
        console.log("[Select2] Part selected:", selectedValue);
        if (onSelect) onSelect(selectedValue);
      });

    // Auto open dropdown
    $(select).select2("open");
  }, 50);

  return select;
}

function getTBody() {
  const table = document.getElementById("opsTable");
  if (!table) throw new Error("#opsTable tidak ditemukan");
  let tb = table.tBodies && table.tBodies[0];
  if (!tb) {
    tb = document.createElement("tbody");
    table.appendChild(tb);
  }
  return tb;
}

// Helper: bikin sel editable dengan single-click (juga double-click)
function makeEditableCell(td, getValue, onEnter) {
  td.classList.add("editable"); // optional (buat styling cursor di CSS)
  const open = () => {
    if (td.querySelector("input")) return;
    pauseEditing();
    td.classList.add("editing");
    const inp = tdInput(getValue() || "", () => onEnter());
    td.textContent = "";
    td.appendChild(inp);
    inp.focus();
    inp.select();
  };
  td.addEventListener("click", open); // single-click
  td.addEventListener("dblclick", open); // double-click
}

const fmtTime = (s) =>
  s
    ? new Date(s.replace(" ", "T")).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
      })
    : "";

/* ---------- Renderer ---------- */
function renderRows(rows) {
  const tb = getTBody();
  tb.innerHTML = "";

  if (!Array.isArray(rows) || rows.length === 0) {
    tb.innerHTML = '<tr><td colspan="12">No data.</td></tr>';
    return;
  }

  const todayStr = new Date().toLocaleDateString();

  const mkSelect = (val, options) => {
    const s = document.createElement("select");
    options.forEach((opt) => {
      const o = document.createElement("option");
      o.value = o.textContent = opt;
      if ((val ?? "") === opt) o.selected = true;
      s.appendChild(o);
    });
    return s;
  };

  rows.forEach((r) => {
    const tr = document.createElement("tr");
    tr.dataset.operatorId = r.id; // cara modern
    tr.setAttribute("data-operator-id", r.id); // fallback/kompat

    // 🆕 Set project_id jika project sudah ada (untuk load parts nanti)
    if (r.project && !r.project_id) {
      const projectInfo = projectsData.find((p) => p.name === r.project);
      if (projectInfo) {
        r.project_id = projectInfo.id;
      }
    }

    const tdDate = document.createElement("td");
    tdDate.textContent = todayStr;
    const tdProj = document.createElement("td");
    tdProj.textContent = r.project || "";

    const tdDept = document.createElement("td"); //  baru
    tdDept.textContent = r.department || "costume"; // ⬅ fix value

    const tdStep = document.createElement("td");
    tdStep.textContent = r.step ?? "Counting";
    tdStep.classList.add("editable");

    // 🆕 AUTO SAVE untuk Step (dengan input tesxt)
    tdStep.addEventListener("click", () => {
      if (tdStep.querySelector("input")) return;

      pauseEditing();
      tdStep.classList.add("editing");

      const oldStep = r.step ?? "";
      const inp = tdInput(oldStep, async () => {
        // Function untuk auto save
        const newStep = inp.value.trim();

        if (newStep !== oldStep) {
          try {
            // Tampilkan loading
            inp.disabled = true;
            inp.value = "⏳ Saving...";

            console.log("[AUTO SAVE Step] Saving:", {
              operator_id: r.id,
              step: newStep,
            });

            // Simpan ke database
            await fetchJSON(`${api}/counter_update.php`, {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                operator_id: r.id,
                step: newStep,
                part: r.part || "",
                status: r.status || "",
                remarks: r.remarks || "",
              }),
            });

            console.log("[AUTO SAVE Step]  Berhasil disimpan!");

            // 🆕 Update timestamp untuk mencegah refresh menimpa data
            justSavedTimestamp = Date.now();

            // Update data
            r.step = newStep;
            tdStep.textContent = newStep;

            // Kembali normal setelah 1 detik
            setTimeout(() => {
              tdStep.textContent = newStep;
              tdStep.classList.remove("editing");
              resumeEditing();
            }, 1000);
          } catch (e) {
            console.error("[AUTO SAVE Step] ❌ Error:", e);
            alert("Gagal menyimpan step: " + e.message);

            // Rollback
            r.step = oldStep;
            tdStep.textContent = oldStep;
            tdStep.classList.remove("editing");
            resumeEditing();
          }
        } else {
          // Tidak ada perubahan
          tdStep.textContent = oldStep;
          tdStep.classList.remove("editing");
          resumeEditing();
        }
      });

      tdStep.textContent = "";
      tdStep.appendChild(inp);
      inp.focus();
      inp.select();

      // Auto save saat blur (kehilangan fokus)
      inp.addEventListener("blur", () => {
        setTimeout(() => {
          if (inp.parentNode === tdStep) {
            // Trigger save via Enter key handler
            const enterEvent = new KeyboardEvent("keydown", { key: "Enter" });
            inp.dispatchEvent(enterEvent);
          }
        }, 150);
      });
    });

    const tdPart = document.createElement("td");
    tdPart.textContent = r.part ?? "";
    tdPart.classList.add("editable");

    // 🆕 AUTO SAVE untuk Part (dengan Select2 dropdown)
    tdPart.addEventListener("click", async () => {
      if (tdPart.querySelector("select")) return; // prevent double-click

      // Cek apakah project sudah dipilih
      if (!r.project) {
        alert("⚠️ Pilih Project terlebih dahulu sebelum memilih Part!");
        return;
      }

      // Cari project_id dari projectsData
      const projectInfo = projectsData.find((p) => p.name === r.project);
      if (!projectInfo || !projectInfo.id) {
        alert("⚠️ Project ID tidak ditemukan. Silakan pilih ulang Project.");
        return;
      }

      pauseEditing();
      tdPart.classList.add("editing");

      const projectId = projectInfo.id;
      const oldPart = r.part ?? "";

      console.log(`[Part Select] Opening dropdown for project_id=${projectId}`);

      // Buat Select2 dropdown untuk Part
      const partSelect = createPartSelect(
        projectId,
        oldPart,
        (selectedPart) => {
          console.log("[Part Select] Part selected:", selectedPart);
        }
      );

      tdPart.textContent = "";
      tdPart.appendChild(partSelect);

      // 🆕 AUTO SAVE: Simpan otomatis saat dropdown ditutup
      $(partSelect).on("select2:close", async () => {
        setTimeout(async () => {
          if (partSelect.parentNode === tdPart) {
            const finalValue = $(partSelect).val();

            // ⭐ PENTING: Update r.part SEBELUM destroy
            r.part = finalValue || "";

            // 🔥 AUTO SAVE ke database jika ada perubahan
            if (finalValue && finalValue !== oldPart) {
              try {
                // Tampilkan loading indicator
                tdPart.textContent = "⏳ Saving...";
                tdPart.style.opacity = "0.6";

                console.log("[AUTO SAVE Part] Saving:", {
                  operator_id: r.id,
                  part: finalValue,
                });

                // Simpan ke database (hanya update part)
                await fetchJSON(`${api}/counter_update.php`, {
                  method: "POST",
                  headers: { "Content-Type": "application/json" },
                  body: JSON.stringify({
                    operator_id: r.id,
                    part: finalValue,
                    update_part_only: true, // flag untuk partial update
                  }),
                });

                console.log("[AUTO SAVE Part] ✅ Berhasil disimpan!");

                // 🆕 Update timestamp untuk mencegah refresh menimpa data
                justSavedTimestamp = Date.now();

                // Tampilkan success indicator
                tdPart.textContent = "✅ " + finalValue;
                tdPart.style.opacity = "1";

                // Kembali normal setelah 1 detik
                setTimeout(() => {
                  tdPart.textContent = finalValue;
                }, 1000);
              } catch (e) {
                console.error("[AUTO SAVE Part] ❌ Error:", e);
                tdPart.textContent = "❌ Error";
                tdPart.style.opacity = "1";
                alert("Gagal menyimpan part: " + e.message);

                // Rollback
                r.part = oldPart;
                setTimeout(() => {
                  tdPart.textContent = oldPart || "";
                }, 1500);
              }
            } else {
              tdPart.textContent = r.part || "";
              tdPart.style.opacity = "1";
            }

            $(partSelect).select2("destroy");
            partSelect.remove();
            tdPart.classList.remove("editing");
            resumeEditing();
          }
        }, 100);
      });
    });

    const tdEmp = document.createElement("td");
    tdEmp.textContent = r.name && r.name.trim() ? r.name : r.code;
    // (hapus handler dblclick lama — kita pakai makeEditableCell di bawah)

    const tdStart = document.createElement("td");
    tdStart.textContent = fmtTime(r.first_hit);
    const tdEnd = document.createElement("td");
    tdEnd.textContent = fmtTime(r.last_hit);
    const tdQty = document.createElement("td");
    tdQty.textContent = r.count ?? 0;

    const tdStat = document.createElement("td");
    tdStat.textContent =
      r.status ?? ((r.count ?? 0) > 0 ? "complete" : "pending");
    tdStat.ondblclick = () => {
      if (tdStat.querySelector("select")) return;
      pauseEditing();
      const sel = mkSelect(tdStat.textContent, [
        "on progress",
        "pending",
        "complete",
      ]);
      tdStat.textContent = "";
      tdStat.appendChild(sel);
      sel.focus();
    };

    const tdRem = document.createElement("td");
    tdRem.textContent = r.remarks ?? "";
    tdRem.ondblclick = () => {
      if (tdRem.querySelector("input")) return;
      pauseEditing();
      const inp = tdInput(r.remarks ?? "", () => btnSave.click());
      tdRem.textContent = "";
      tdRem.appendChild(inp);
      inp.focus();
    };

    const tdAct = document.createElement("td");
    tdAct.className = "actionbar";
    const btnSave = document.createElement("button");
    btnSave.textContent = "Save";

    btnSave.onclick = async () => {
      // Ambil nilai terkini dari cell (kalau sedang edit pakai input/select)
      const name = tdEmp.querySelector("input")
        ? tdEmp.querySelector("input").value.trim()
        : r.name || "";

      // 🆕 Ambil nilai dari Select2 jika ada
      const project = tdProj.querySelector("select")
        ? $(tdProj.querySelector("select")).val()
        : tdProj.querySelector("input")
        ? tdProj.querySelector("input").value.trim()
        : r.project || "";

      const step = tdStep.querySelector("input")
        ? tdStep.querySelector("input").value.trim()
        : r.step ?? "";

      // ⬅️ Part tidak bisa di-edit manual, langsung ambil dari r.part (auto-fill dari Project)
      const part = r.part ?? "";
      const status = tdStat.querySelector("select")
        ? tdStat.querySelector("select").value
        : r.status ?? "";
      const remarks = tdRem.querySelector("input")
        ? tdRem.querySelector("input").value.trim()
        : r.remarks ?? "";

      pauseEditing();
      try {
        // 🆕 Ambil department dari r object (sudah di-set saat select project)
        const department = r.department || "costume";

        console.log("[btnSave] Saving data:", {
          id: r.id,
          name,
          project,
          department,
          step,
          part,
          status,
          remarks,
        });

        // Simpan name/project/department (operator)
        await fetchJSON(`${api}/operators_update.php`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id: r.id, name, project, department }),
        });

        console.log("[btnSave] ✅ Operator data saved successfully");
        // Simpan meta harian (counter)
        await fetchJSON(`${api}/counter_update.php`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            operator_id: r.id,
            step,
            part,
            status,
            remarks,
          }),
        });

        // Optimistic UI
        tdEmp.textContent = name || r.code;
        tdProj.textContent = project;
        tdDept.textContent = department; // 🆕 update UI department
        tdStep.textContent = step || "";
        tdPart.textContent = part || "";
        tdStat.textContent =
          status || ((r.count ?? 0) > 0 ? "complete" : "pending");
        tdRem.textContent = remarks || "";

        Object.assign(r, {
          name,
          project,
          department,
          step,
          part,
          status,
          remarks,
        });

        console.log("[btnSave] Data berhasil disimpan!");

        // Update timestamp untuk mencegah refresh menimpa data
        justSavedTimestamp = Date.now();
      } catch (e) {
        alert("Save failed: " + e.message);
      } finally {
        resumeEditing();
        loadData(); // sync penuh
      }
    };

    // === 🆕 Employee dengan Select2 dropdown + AUTO SAVE ===
    tdEmp.classList.add("editable");
    tdEmp.addEventListener("click", () => {
      if (tdEmp.querySelector("select")) return; // prevent double-click

      pauseEditing();
      tdEmp.classList.add("editing");

      // Buat Select2 dropdown untuk Employee
      const empSelect = document.createElement("select");
      empSelect.className = "employee-select2";
      empSelect.style.width = "250px";

      // Option kosong
      const emptyOpt = document.createElement("option");
      emptyOpt.value = "";
      emptyOpt.textContent = "-- Pilih Employee --";
      empSelect.appendChild(emptyOpt);

      // Populate dari employeesData
      employeesData.forEach((emp) => {
        const opt = document.createElement("option");
        opt.value = emp.name;
        opt.textContent = `${emp.name} (${emp.department || "N/A"})`;
        if (emp.name === r.name) opt.selected = true;
        empSelect.appendChild(opt);
      });

      tdEmp.textContent = "";
      tdEmp.appendChild(empSelect);

      // Initialize Select2
      setTimeout(() => {
        $(empSelect)
          .select2({
            placeholder: "-- Pilih Employee --",
            allowClear: true,
            width: "250px",
          })
          .on("change", function () {
            console.log("[Select2] Employee selected:", this.value);
          });

        $(empSelect).select2("open");
      }, 50);

      // 🆕 AUTO SAVE: Simpan otomatis saat dropdown ditutup
      $(empSelect).on("select2:close", async () => {
        setTimeout(async () => {
          if (empSelect.parentNode === tdEmp) {
            const finalValue = $(empSelect).val();
            const oldName = r.name;

            // ⭐ PENTING: Update r.name SEBELUM destroy
            r.name = finalValue || "";

            // AUTO SAVE ke database jika ada perubahan
            if (finalValue && finalValue !== oldName) {
              try {
                // Tampilkan loading indicator
                tdEmp.textContent = "⏳ Saving...";
                tdEmp.style.opacity = "0.6";

                console.log("[AUTO SAVE Employee] Saving:", {
                  id: r.id,
                  name: finalValue,
                  project: r.project,
                  department: r.department,
                });

                // Simpan ke database
                await fetchJSON(`${api}/operators_update.php`, {
                  method: "POST",
                  headers: { "Content-Type": "application/json" },
                  body: JSON.stringify({
                    id: r.id,
                    name: finalValue,
                    project: r.project || "",
                    department: r.department || "costume",
                  }),
                });

                console.log("[AUTO SAVE Employee]  Berhasil disimpan!");

                // 🆕 Update timestamp untuk mencegah refresh menimpa data
                justSavedTimestamp = Date.now();

                // Tampilkan success indicator
                tdEmp.textContent = " " + (finalValue || r.code);
                tdEmp.style.opacity = "1";

                // Kembali normal setelah 1 detik
                setTimeout(() => {
                  tdEmp.textContent = finalValue || r.code;
                }, 1000);
              } catch (e) {
                console.error("[AUTO SAVE Employee] ❌ Error:", e);
                tdEmp.textContent = "❌ Error";
                tdEmp.style.opacity = "1";
                alert("Gagal menyimpan employee: " + e.message);

                // Rollback
                r.name = oldName;
                setTimeout(() => {
                  tdEmp.textContent = oldName || r.code;
                }, 1500);
              }
            } else {
              tdEmp.textContent = r.name || r.code;
              tdEmp.style.opacity = "1";
            }

            $(empSelect).select2("destroy");
            empSelect.remove();
            tdEmp.classList.remove("editing");
            resumeEditing();
          }
        }, 100);
      });
    });

    // 🆕 Project dengan Select2 + Auto-fill Department & Part + AUTO SAVE
    tdProj.classList.add("editable");
    tdProj.addEventListener("click", () => {
      if (tdProj.querySelector("select")) return; // prevent double-click

      pauseEditing();
      tdProj.classList.add("editing");

      /**
       * Callback saat user memilih project dari dropdown
       * - Auto-fill Department & Part sesuai data dari Laravel API
       */
      const handleProjectSelect = (selectedProject) => {
        console.log("[handleProjectSelect] Selected:", selectedProject);

        // Cari data project yang sesuai
        const projectInfo = projectsData.find(
          (p) => p.name === selectedProject
        );

        if (projectInfo) {
          console.log("[handleProjectSelect] Found project info:", projectInfo);

          // 🔧 FIX: Ambil department dan part dari array
          const dept = projectInfo.departments?.[0]?.name || "costume";
          const part = projectInfo.parts?.[0]?.name || "";

          // ⭐ PENTING: Update data row dengan nilai yang benar
          r.department = dept;
          r.part = part;
          r.project_id = projectInfo.id; // 🆕 Simpan project_id untuk load parts nanti

          // Update UI Department & Part
          tdDept.textContent = dept;
          tdPart.textContent = part;

          console.log(
            "[handleProjectSelect] ✅ Updated | Department:",
            r.department,
            "| Part:",
            r.part,
            "| Project ID:",
            r.project_id
          );
        } else {
          console.warn(
            "[handleProjectSelect] ⚠️ Project not found in projectsData:",
            selectedProject
          );
        }
      };

      // Buat Select2 dropdown
      const select = createProjectSelect(r.project || "", handleProjectSelect);

      tdProj.textContent = "";
      tdProj.appendChild(select);

      // 🆕 AUTO SAVE: Simpan otomatis saat dropdown ditutup
      $(select).on("select2:close", async () => {
        setTimeout(async () => {
          if (select.parentNode === tdProj) {
            const finalValue = $(select).val();
            const oldProject = r.project;

            // ⭐ PENTING: Update r.project SEBELUM destroy
            r.project = finalValue || "";

            // 🔧 FIX: Panggil handleProjectSelect lagi untuk memastikan department & part terupdate
            if (finalValue) {
              handleProjectSelect(finalValue);
            }

            // 🔥 AUTO SAVE ke database jika ada perubahan
            if (finalValue && finalValue !== oldProject) {
              try {
                // Tampilkan loading indicator
                tdProj.textContent = "⏳ Saving...";
                tdProj.style.opacity = "0.6";

                console.log("[AUTO SAVE Project] Saving:", {
                  id: r.id,
                  name: r.name,
                  project: finalValue,
                  department: r.department,
                  part: r.part,
                });

                // Simpan project, department ke database (operators table)
                await fetchJSON(`${api}/operators_update.php`, {
                  method: "POST",
                  headers: { "Content-Type": "application/json" },
                  body: JSON.stringify({
                    id: r.id,
                    name: r.name || "",
                    project: finalValue,
                    department: r.department || "costume",
                  }),
                });

                // 🔧 FIX: HANYA update part di counter_update
                // JANGAN kirim step/status/remarks agar tidak menimpa data yang sudah ada
                // Kita hanya perlu update part karena part mengikuti project
                if (r.part) {
                  await fetchJSON(`${api}/counter_update.php`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                      operator_id: r.id,
                      part: r.part || "",
                      // ⚠️ PENTING: Jangan kirim step/status/remarks
                      // Biarkan backend hanya update field 'part' saja
                      update_part_only: true, // flag untuk backend
                    }),
                  });
                }

                console.log("[AUTO SAVE Project]  Berhasil disimpan!");

                // 🆕 Update timestamp untuk mencegah refresh menimpa data
                justSavedTimestamp = Date.now();

                // Tampilkan success indicator
                tdProj.textContent = " " + finalValue;
                tdProj.style.opacity = "1";

                // Kembali normal setelah 1 detik
                setTimeout(() => {
                  tdProj.textContent = finalValue;
                }, 1000);
              } catch (e) {
                console.error("[AUTO SAVE Project] ❌ Error:", e);
                tdProj.textContent = "❌ Error";
                tdProj.style.opacity = "1";
                alert("Gagal menyimpan project: " + e.message);

                // Rollback
                r.project = oldProject;
                setTimeout(() => {
                  tdProj.textContent = oldProject || "";
                  // Rollback department & part juga
                  handleProjectSelect(oldProject);
                }, 1500);
              }
            } else {
              tdProj.textContent = r.project || "";
              tdProj.style.opacity = "1";
            }

            $(select).select2("destroy"); // destroy Select2
            select.remove();
            tdProj.classList.remove("editing");
            resumeEditing();
          }
        }, 100);
      });
    });

    const btnPlus = document.createElement("button");
    btnPlus.textContent = "+1 test";
    btnPlus.onclick = async () => {
      try {
        await fetchJSON(`${api}/hit.php`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ operator_id: r.id, amount: 1 }),
        });
        await loadData();
      } catch (e) {
        alert("Hit failed: " + e.message);
      }
    };

    const btnLog = document.createElement("button");
    btnLog.textContent = "Log CSV";
    btnLog.onclick = () => {
      let url = `${api}/export_log_operator_csv.php?operator_id=${r.id}`;
      window.location.href = url;
    };

    // const btnLogX = document.createElement('button');
    // btnLogX.textContent = 'Log XLSX';
    // btnLogX.onclick = () => {
    //   const from = prompt('From (YYYY-MM-DD) — kosongkan untuk semua waktu', '');
    //   const to   = prompt('To   (YYYY-MM-DD) — kosongkan untuk semua waktu', '');
    //   let url = `server/export_log_operator_xlsx.php?operator_id=${r.id}`;
    //   if (from) url += `&from=${encodeURIComponent(from)}`;
    //   if (to)   url += `&to=${encodeURIComponent(to)}`;
    //   window.location.href = url;
    // };

    tdAct.append(btnSave, btnPlus, btnLog);
    tr.append(
      tdDate,
      tdProj,
      tdDept,
      tdStep,
      tdPart,
      tdEmp,
      tdStart,
      tdEnd,
      tdQty,
      tdStat,
      tdRem,
      tdAct
    );
    tb.appendChild(tr);
  });
}

/* ---------- Data loader ---------- */
async function loadData() {
  // 🆕 Skip refresh jika baru saja melakukan auto-save (cooldown period)
  const timeSinceLastSave = Date.now() - justSavedTimestamp;
  if (timeSinceLastSave < SAVE_COOLDOWN) {
    console.log(
      `[loadData] ⏸️ Skipping refresh (cooldown: ${Math.ceil(
        (SAVE_COOLDOWN - timeSinceLastSave) / 1000
      )}s remaining)`
    );
    return;
  }

  if (inflight) inflight.abort();
  inflight = new AbortController();
  const signal = inflight.signal;

  try {
    const res = await fetch(`${api}/dashboard_get.php`, {
      cache: "no-store",
      signal,
    });
    const data = await res.json();
    if (signal.aborted) return;
    renderRows(data);
  } catch (e) {
    if (e.name === "AbortError") return;
    console.error("loadData error:", e);
    try {
      const tb = getTBody();
      tb.innerHTML = `<tr><td colspan="12">Failed to load: ${e.message}</td></tr>`;
    } catch (_) {}
  } finally {
    if (inflight && inflight.signal === signal) inflight = null;
  }
}

/* ---------- Change watcher (fallback) ---------- */
function startChangeWatcher() {
  if (changeWatcherId !== null) return;
  changeWatcherId = setInterval(async () => {
    try {
      const r = await fetch(`${api}/last_change.php`, { cache: "no-store" });
      if (!r.ok) return;
      const j = await r.json();
      if (j.ts && j.ts > lastChangeTs) {
        lastChangeTs = j.ts;
        if (!isEditing) await loadData();
        else pendingRefresh = true;
      }
    } catch (_) {}
  }, 1000);
}

/* ---------- SSE (instan) dengan fallback watcher ---------- */
function startSSE() {
  if (typeof EventSource === "undefined") {
    startChangeWatcher();
    return;
  }
  if (sse) return;
  try {
    sse = new EventSource(`${api}/events.php`);
    sse.onmessage = async () => {
      if (!isEditing) await loadData();
      else pendingRefresh = true;
    };
    sse.onerror = () => {
      try {
        sse.close();
      } catch (_) {}
      sse = null;
      startChangeWatcher(); // fallback
      setTimeout(startSSE, 2000); // coba lagi
    };
  } catch (_) {
    startChangeWatcher();
  }
}

/* ---------- Buttons ---------- */
function wireButtons() {
  const q = (id) => document.getElementById(id);

  q("refreshBtn")?.addEventListener("click", () => {
    if (!isEditing) loadData();
  });
  q("exportBtn")?.addEventListener(
    "click",
    () => (window.location.href = `${api}/export_today_csv.php`)
  );
  q("exportTimingBtn")?.addEventListener("click", () => {
    const d = prompt("Date (YYYY-MM-DD)? (kosongkan = hari ini)", "");
    const qs = d ? `?date=${encodeURIComponent(d)}` : "";
    window.location.href = `${api}/export_input_timing_csv.php${qs}`;
  });

  q("resetBtn")?.addEventListener("click", async () => {
    if (!confirm("Reset all counts for today to 0?")) return;
    try {
      pauseEditing();
      await fetchJSON(`${api}/reset_today.php`, { method: "POST" });
      await loadData();
    } catch (e) {
      alert("Reset failed: " + e.message);
    } finally {
      resumeEditing();
    }
  });
  q("addBtn")?.addEventListener("click", async () => {
    const name = prompt("Nama operator? (boleh kosong)") ?? "";
    const project = prompt("Project? (boleh kosong)") ?? "";
    const code = prompt("Kode (kosongkan untuk auto, mis. OP31)") ?? "";
    try {
      pauseEditing();
      const res = await fetchJSON(`${api}/operators_add.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, project, code }),
      });
      await loadData();
      alert(`Operator dibuat: ${res.code} (id=${res.id})`);
    } catch (e) {
      alert("Gagal tambah operator: " + e.message);
    } finally {
      resumeEditing();
    }
  });

  // Optional: tombol export log global (jika ada di HTML)
  document.getElementById("exportLogBtn")?.addEventListener("click", () => {
    window.location.href = `${api}/export_log_csv.php`;
  });

  q("exportTimingBtnXLS")?.addEventListener("click", () => {
    const d = prompt("Date (YYYY-MM-DD)? (kosongkan = hari ini)", "");
    const qs = d ? `?date=${encodeURIComponent(d)}` : "";
    window.location.href = `${api}/export_input_timing_xlsx.php${qs}`;
  });
}

/* ---------- 🆕 Bootstrap ---------- */
window.addEventListener("DOMContentLoaded", async () => {
  console.log("[Bootstrap] Initializing...");

  wireButtons();
  updateClock();
  setInterval(updateClock, 1000);

  // 🆕 Load data dari Laravel API terlebih dahulu
  console.log("[Bootstrap] Loading projects and employees from Laravel API...");
  await Promise.all([
    loadProjects(), // Load projects
    loadEmployees(), // Load employees (untuk fitur future jika diperlukan)
  ]);

  console.log("[Bootstrap] Starting auto-refresh and SSE...");
  startAuto();
  loadData();
  startSSE();

  console.log("[Bootstrap] Initialization complete!");
});
/* =========================== end of file ============================ */
