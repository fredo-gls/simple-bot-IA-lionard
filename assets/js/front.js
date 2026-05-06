(function () {
  "use strict";

  const config = window.LionardSimpleChat || {};
  const STORAGE_KEY = "lionard_simple_chat_history_v1";

  function init() {
    const shell = document.querySelector(".lsc-shell");
    if (!shell) return;

    if (shell.parentElement !== document.body) {
      document.body.appendChild(shell);
    }

    const launcher = shell.querySelector(".lsc-launcher");
    const panel = shell.querySelector(".lsc-panel");
    const close = shell.querySelector(".lsc-close");
    const messages = shell.querySelector(".lsc-messages");
    const form = shell.querySelector(".lsc-form");
    const input = shell.querySelector(".lsc-input");
    const send = shell.querySelector(".lsc-send");
    const restart = shell.querySelector(".lsc-restart");

    if (!launcher || !panel || !messages || !form || !input || !send) return;

    shell.style.setProperty("--lsc-primary", config.primaryColor || "#1652f0");
    shell.style.setProperty("--lsc-accent", config.accentColor || "#f59e0b");

    let history = loadHistory();
    let sending = false;

    function openPanel() {
      panel.hidden = false;
      requestAnimationFrame(() => {
        panel.classList.add("is-open");
        shell.classList.add("is-open");
        launcher.setAttribute("aria-expanded", "true");
        input.focus();
      });
    }

    function closePanel() {
      panel.classList.remove("is-open");
      shell.classList.remove("is-open");
      launcher.setAttribute("aria-expanded", "false");
      window.setTimeout(() => {
        if (!panel.classList.contains("is-open")) {
          panel.hidden = true;
        }
      }, 180);
    }

    function renderInitial() {
      messages.innerHTML = "";
      if (history.length) {
        history.forEach((item) => appendMessage(item.role, item.content, false));
        return;
      }
      appendMessage("assistant", config.greeting || "Bonjour, je suis Lionard. Comment puis-je vous aider ?", false);
    }

    launcher.addEventListener("click", () => {
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
      input.focus();
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
            message: text,
            history: requestHistory,
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
        appendMessage("assistant", (config.strings && config.strings.error) || "Le service est momentanément indisponible, revenez plus tard.", true);
      } finally {
        setSending(false);
      }
    });

    function setSending(value) {
      sending = value;
      send.disabled = value;
      input.disabled = value;
    }

    function appendMessage(role, text, persist) {
      const item = document.createElement("div");
      item.className = "lsc-message lsc-message--" + (role === "user" ? "user" : "assistant");

      const bubble = document.createElement("div");
      bubble.className = "lsc-bubble";

      if (role === "assistant") {
        renderBotContent(String(text || ""), bubble);
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
      const item = document.createElement("div");
      item.className = "lsc-message lsc-message--assistant lsc-message--typing";
      const bubble = document.createElement("div");
      bubble.className = "lsc-bubble lsc-typing";
      for (let i = 0; i < 3; i += 1) {
        bubble.appendChild(document.createElement("span"));
      }
      item.appendChild(bubble);
      messages.appendChild(item);
      messages.scrollTop = messages.scrollHeight;
      return item;
    }

    renderInitial();
  }

  function renderBotContent(raw, container) {
    const text = String(raw || "");
    const pattern = /\[\[button:([^\]|]{1,120})\|([^\]\s]{1,600})\]\]/g;
    let lastIndex = 0;
    let match;
    let renderedButton = false;

    while ((match = pattern.exec(text)) !== null) {
      appendTextWithBreaks(container, text.slice(lastIndex, match.index));
      lastIndex = pattern.lastIndex;

      const label = match[1].trim();
      const url = sanitizeAllowedUrl(match[2].trim());
      if (!label || !url) continue;

      const link = document.createElement("a");
      link.className = "lsc-cta";
      link.href = url;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.textContent = label;
      container.appendChild(link);
      renderedButton = true;
    }

    appendTextWithBreaks(container, text.slice(lastIndex));

    if (renderedButton) {
      container.classList.add("has-cta");
    }
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
      const parsed = new URL(value, window.location.origin);
      if (parsed.protocol !== "https:" && parsed.protocol !== "http:") return "";
      const allowed = Array.isArray(config.allowedHosts) ? config.allowedHosts : [];
      if (!allowed.map((h) => String(h).toLowerCase()).includes(parsed.hostname.toLowerCase())) {
        return "";
      }
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
    } catch (_error) {
      // Ignore storage failures.
    }
  }

  function removeNode(node) {
    if (node && node.parentNode) {
      node.parentNode.removeChild(node);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
