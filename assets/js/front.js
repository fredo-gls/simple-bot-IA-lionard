(function () {
  "use strict";

  const config = window.LionardSimpleChat || {};
  const STORAGE_KEY = "lionard_simple_chat_history_v1";
  const SESSION_KEY = "lionard_simple_chat_session_v1";

  function init() {
    attachFormliftContextFromQuery();

    const shell = document.querySelector(".lsc-shell");
    if (!shell) return;

    if (shell.parentElement !== document.body) {
      document.body.appendChild(shell);
    }

    const launcher = shell.querySelector(".lsc-launcher");
    const panel    = shell.querySelector(".lsc-panel");
    const close    = shell.querySelector(".lsc-close");
    const messages = shell.querySelector(".lsc-messages");
    const form     = shell.querySelector(".lsc-form");
    const input    = shell.querySelector(".lsc-input");
    const send     = shell.querySelector(".lsc-send");
    const restart  = shell.querySelector(".lsc-restart");

    if (!launcher || !panel || !messages || !form || !input || !send) return;

    shell.style.setProperty("--lsc-primary", config.primaryColor || "#1652f0");
    shell.style.setProperty("--lsc-accent",  config.accentColor  || "#f59e0b");

    let history    = loadHistory();
    let sending    = false;
    let stayClosed = false; // locked after RDV click when rdvKeepClosed is true
    const sessionId = getOrCreateSessionId();

    // ── Panel ──────────────────────────────────────────────────────────────

    function openPanel() {
      if (stayClosed) return;
      panel.hidden = false;
      requestAnimationFrame(() => {
        panel.classList.add("is-open");
        shell.classList.add("is-open");
        launcher.setAttribute("aria-expanded", "true");
        focusInput();
      });
    }

    function closePanel() {
      panel.classList.remove("is-open");
      shell.classList.remove("is-open");
      launcher.setAttribute("aria-expanded", "false");
      window.setTimeout(() => {
        if (!panel.classList.contains("is-open")) panel.hidden = true;
      }, 180);
    }

    // ── RDV ────────────────────────────────────────────────────────────────

    function normalizeUrl(url) {
      try {
        const parsed = new URL(String(url || ""), window.location.origin);
        return (parsed.origin + parsed.pathname).toLowerCase().replace(/\/+$/, "");
      } catch (_error) {
        return String(url || "").toLowerCase().replace(/\/+$/, "");
      }
    }

    function isRdvUrl(url) {
      if (!config.rdvCloseChat) return false;
      const rdvUrls = [config.rdvPersonnelUrl, config.rdvEntrepriseUrl]
        .filter(Boolean)
        .map(normalizeUrl);
      return rdvUrls.length > 0 && rdvUrls.includes(normalizeUrl(url));
    }

    function onAppointmentClick(url) {
      const targetUrl = buildAppointmentUrl(url);
      logRdvEvent(targetUrl);
      sendRdvEvent(targetUrl);
      closePanel();
      if (config.rdvKeepClosed) stayClosed = true;
      openAppointmentModal(targetUrl, () => {
        stayClosed = false;
      });
    }

    // ── Listeners ──────────────────────────────────────────────────────────

    launcher.addEventListener("click", () => {
      if (stayClosed) return;
      if (panel.hidden || !panel.classList.contains("is-open")) {
        openPanel();
      } else {
        closePanel();
      }
    });

    close?.addEventListener("click", closePanel);

    restart?.addEventListener("click", () => {
      history = [];
      saveHistory(history);
      renderInitial();
      focusInput();
    });

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const text = input.value.trim();
      if (!text || sending) return;

      const requestHistory = history.slice(-10);
      input.value = "";
      appendMessage("user", text, true);
      setSending(true);
      const typing = appendTyping();

      try {
        const response = await fetch((config.restUrl || "") + "/chat", {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": config.nonce || "",
          },
          body: JSON.stringify({
            session_id: sessionId,
            page_url: window.location.href,
            message: text,
            history: requestHistory
          }),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
          throw new Error(data && data.message ? data.message : "Erreur");
        }

        removeNode(typing);
        const reply = String(data.reply || "").trim();
        if (!reply) throw new Error("empty");

        history.push({ role: "assistant", content: reply });
        history = history.slice(-20);
        saveHistory(history);
        appendMessage("assistant", reply, false);
      } catch (_error) {
        removeNode(typing);
        appendMessage(
          "assistant",
          (config.strings && config.strings.error) || "Le service est momentanément indisponible, revenez plus tard.",
          true
        );
      } finally {
        setSending(false);
        focusInput();
      }
    });

    // ── Rendering ──────────────────────────────────────────────────────────

    function renderInitial() {
      messages.innerHTML = "";
      if (history.length) {
        history.forEach((item) => appendMessage(item.role, item.content, false));
        focusInput();
        return;
      }
      appendMessage("assistant", config.greeting || "Bonjour, je suis Lionard. Comment puis-je vous aider ?", false);
      focusInput();
    }

    function setSending(value) {
      sending        = value;
      send.disabled  = value;
      input.disabled = value;
    }

    function focusInput() {
      if (input.disabled || stayClosed) return;
      requestAnimationFrame(() => {
        input.focus();
        const length = input.value.length;
        if (typeof input.setSelectionRange === "function") {
          input.setSelectionRange(length, length);
        }
      });
    }

    function appendMessage(role, text, persist) {
      const item     = document.createElement("div");
      item.className = "lsc-message lsc-message--" + (role === "user" ? "user" : "assistant");

      const bubble     = document.createElement("div");
      bubble.className = "lsc-bubble";

      if (role === "assistant") {
        renderBotContent(String(text || ""), bubble, { isRdv: isRdvUrl, onRdvClick: onAppointmentClick });
      } else {
        appendTextWithBreaks(bubble, String(text || ""));
      }

      item.appendChild(bubble);
      messages.appendChild(item);
      messages.scrollTop = messages.scrollHeight;

      if (persist) {
        history.push({ role: "user", content: String(text || "") });
        history = history.slice(-20);
        saveHistory(history);
      }

      return item;
    }

    function appendTyping() {
      const item     = document.createElement("div");
      item.className = "lsc-message lsc-message--assistant lsc-message--typing";
      const bubble   = document.createElement("div");
      bubble.className = "lsc-bubble lsc-typing";
      for (let i = 0; i < 3; i += 1) bubble.appendChild(document.createElement("span"));
      item.appendChild(bubble);
      messages.appendChild(item);
      messages.scrollTop = messages.scrollHeight;
      return item;
    }

    renderInitial();
    bindFormliftMessages();

    function sendRdvEvent(url) {
      fetch((config.restUrl || "") + "/rdv-event", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": config.nonce || ""
        },
        body: JSON.stringify({
          session_id: sessionId,
          page_url: window.location.href,
          rdv_url: url,
          rdv_type: detectRdvType(url)
        })
      }).catch(() => {});
    }

    function bindFormliftMessages() {
      window.addEventListener("message", (event) => {
        const data = event && event.data ? event.data : null;
        if (!data || typeof data !== "object") return;

        if (data.type !== "formlift_submitted" && data.type !== "lsc_formlift_submitted") {
          return;
        }

        fetch((config.restUrl || "") + "/rdv-submit", {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": config.nonce || ""
          },
          body: JSON.stringify({
            session_id: sessionId,
            page_url: window.location.href,
            rdv_url: String(data.rdv_url || ""),
            rdv_type: String(data.rdv_type || detectRdvType(String(data.rdv_url || ""))),
            form_source: String(data.form_source || "formlift"),
            form_data: data.form_data || {}
          })
        }).catch(() => {});
      });
    }

    function detectRdvType(url) {
      const normalized = normalizeUrl(url);
      if (normalized && normalized === normalizeUrl(config.rdvEntrepriseUrl)) {
        return "entreprise";
      }
      return "particulier";
    }

    function buildAppointmentUrl(url) {
      try {
        const target = new URL(url, window.location.origin);
        target.searchParams.set("lsc_session_id", sessionId);
        target.searchParams.set("lsc_rdv_type", detectRdvType(url));
        target.searchParams.set("lsc_rdv_url", String(url || ""));
        target.searchParams.set("lsc_form_source", "lionard-simple-chat");
        target.searchParams.set("lsc_page_url", window.location.href);
        return target.href;
      } catch (_error) {
        return url;
      }
    }
  }

  // ── Module-level helpers ───────────────────────────────────────────────────

  function renderBotContent(raw, container, opts) {
    const text    = String(raw || "");
    const pattern = /\[\[button:([^\]|]{1,120})\|([^\]\s]{1,600})\]\]/g;
    let lastIndex = 0;
    let match;
    let renderedButton = false;

    while ((match = pattern.exec(text)) !== null) {
      appendTextWithBreaks(container, text.slice(lastIndex, match.index));
      lastIndex = pattern.lastIndex;

      const label = match[1].trim();
      const url   = sanitizeAllowedUrl(match[2].trim());
      if (!label || !url) continue;

      const isRdv    = opts && opts.isRdv    && opts.isRdv(url);
      const rdvClick = opts && opts.onRdvClick;

      if (isRdv && rdvClick) {
        const btn      = document.createElement("button");
        btn.type       = "button";
        btn.className  = "lsc-cta lsc-cta--rdv";
        btn.textContent = label;
        btn.addEventListener("click", () => rdvClick(url));
        container.appendChild(btn);
      } else {
        const link      = document.createElement("a");
        link.className  = "lsc-cta";
        link.href       = url;
        link.target     = "_blank";
        link.rel        = "noopener noreferrer";
        link.textContent = label;
        container.appendChild(link);
      }
      renderedButton = true;
    }

    appendTextWithBreaks(container, text.slice(lastIndex));
    if (renderedButton) container.classList.add("has-cta");
  }

  function openAppointmentModal(url, onClose) {
    if (!url) return;

    const overlay     = document.createElement("div");
    overlay.className = "lsc-modal-overlay";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.setAttribute("aria-label", "Prendre rendez-vous");

    const box       = document.createElement("div");
    box.className   = "lsc-modal-box";

    const closeBtn  = document.createElement("button");
    closeBtn.type   = "button";
    closeBtn.className = "lsc-modal-close";
    closeBtn.setAttribute("aria-label", "Fermer");
    closeBtn.innerHTML = "&times;";

    const iframe     = document.createElement("iframe");
    iframe.className = "lsc-modal-iframe";
    iframe.src       = url;
    iframe.title     = "Prendre rendez-vous";
    iframe.setAttribute("allow", "camera; microphone");

    box.appendChild(closeBtn);
    box.appendChild(iframe);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    requestAnimationFrame(() => overlay.classList.add("is-open"));

    function closeModal() {
      overlay.classList.remove("is-open");
      window.setTimeout(() => removeNode(overlay), 250);
      document.removeEventListener("keydown", onKey);
      if (typeof onClose === "function") onClose();
    }

    function onKey(e) {
      if (e.key === "Escape") closeModal();
    }

    closeBtn.addEventListener("click", closeModal);
    overlay.addEventListener("click", (e) => { if (e.target === overlay) closeModal(); });
    document.addEventListener("keydown", onKey);
  }

  function logRdvEvent(url) {
    try {
      document.dispatchEvent(new CustomEvent("lsc:rdv_clicked", { bubbles: true, detail: { url } }));
      if (window.dataLayer && Array.isArray(window.dataLayer)) {
        window.dataLayer.push({ event: "lsc_rdv_clicked", rdv_url: url });
      }
    } catch (_e) {}
  }

  function appendTextWithBreaks(container, value) {
    const parts = String(value || "").split(/\n/);
    parts.forEach((part, index) => {
      if (index > 0) container.appendChild(document.createElement("br"));
      if (part) container.appendChild(document.createTextNode(part));
    });
  }

  function sanitizeAllowedUrl(value) {
    try {
      const parsed  = new URL(value, window.location.origin);
      if (parsed.protocol !== "https:" && parsed.protocol !== "http:") return "";
      const allowed = Array.isArray(config.allowedHosts) ? config.allowedHosts : [];
      if (!allowed.map((h) => String(h).toLowerCase()).includes(parsed.hostname.toLowerCase())) return "";
      return parsed.href;
    } catch (_error) {
      return "";
    }
  }

  function loadHistory() {
    try {
      const data = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || "[]");
      if (!Array.isArray(data)) return [];
      return data
        .filter((item) => item && (item.role === "user" || item.role === "assistant") && typeof item.content === "string")
        .slice(-20);
    } catch (_error) {
      return [];
    }
  }

  function saveHistory(history) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(-20)));
    } catch (_error) {}
  }

  function removeNode(node) {
    if (node && node.parentNode) node.parentNode.removeChild(node);
  }

  function getOrCreateSessionId() {
    try {
      const existing = window.localStorage.getItem(SESSION_KEY);
      if (existing) return existing;
      const value = createSessionId();
      window.localStorage.setItem(SESSION_KEY, value);
      return value;
    } catch (_error) {
      return createSessionId();
    }
  }

  function createSessionId() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    return "lsc-" + Date.now() + "-" + Math.random().toString(16).slice(2);
  }

  function attachFormliftContextFromQuery() {
    try {
      const params = new URLSearchParams(window.location.search);
      const sessionId = params.get("lsc_session_id");
      if (!sessionId) return;

      const forms = document.querySelectorAll("form.formlift-form-container, .formlift-form-container form");
      if (!forms.length) return;

      forms.forEach((form) => {
        ensureHiddenField(form, "lsc_session_id", sessionId);
        ensureHiddenField(form, "lsc_rdv_type", params.get("lsc_rdv_type") || "");
        ensureHiddenField(form, "lsc_rdv_url", params.get("lsc_rdv_url") || window.location.href);
        ensureHiddenField(form, "lsc_form_source", params.get("lsc_form_source") || "lionard-simple-chat");
        ensureHiddenField(form, "lsc_page_url", params.get("lsc_page_url") || window.location.href);
      });
    } catch (_error) {
      // ignore
    }
  }

  function ensureHiddenField(form, name, value) {
    if (!form || !name) return;
    let input = form.querySelector('input[name="' + cssEscape(name) + '"]');
    if (!input) {
      input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      form.appendChild(input);
    }
    input.value = String(value || "");
  }

  function cssEscape(value) {
    return String(value).replace(/"/g, '\\"');
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
