(function () {
  var STORAGE_KEY = "mega_theme";
  var DEFAULT_THEME = "dark";

  function sanitizeTheme(theme) {
    return theme === "light" ? "light" : "dark";
  }

  function getStoredTheme() {
    try {
      return sanitizeTheme(localStorage.getItem(STORAGE_KEY) || DEFAULT_THEME);
    } catch (error) {
      return DEFAULT_THEME;
    }
  }

  function saveTheme(theme) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (error) {
      return;
    }
  }

  function updateToggleUI(theme) {
    var toggles = document.querySelectorAll("[data-theme-toggle]");
    toggles.forEach(function (toggle) {
      var icon = toggle.querySelector("[data-theme-icon]");
      var label = toggle.querySelector("[data-theme-label]");
      var nextTheme = theme === "dark" ? "light" : "dark";

      if (icon) {
        icon.className = nextTheme === "light" ? "bi bi-sun-fill" : "bi bi-moon-stars-fill";
      }

      if (label) {
        label.textContent = nextTheme === "light" ? "Light" : "Dark";
      }

      toggle.setAttribute("aria-label", "Alternar para modo " + nextTheme);
      toggle.setAttribute("title", "Alternar para modo " + nextTheme);
    });
  }

  function applyTheme(theme) {
    var resolved = sanitizeTheme(theme);
    var root = document.documentElement;
    root.setAttribute("data-theme", resolved);
    root.setAttribute("data-bs-theme", resolved);

    if (document.body) {
      document.body.setAttribute("data-bs-theme", resolved);
    }

    updateToggleUI(resolved);
    saveTheme(resolved);
  }

  function initToggle() {
    var toggles = document.querySelectorAll("[data-theme-toggle]");
    toggles.forEach(function (toggle) {
      toggle.addEventListener("click", function () {
        var current = document.documentElement.getAttribute("data-theme") || DEFAULT_THEME;
        applyTheme(current === "dark" ? "light" : "dark");
      });
    });

    var activeTheme = sanitizeTheme(document.documentElement.getAttribute("data-theme") || DEFAULT_THEME);
    if (document.body) {
      document.body.setAttribute("data-bs-theme", activeTheme);
    }
    updateToggleUI(activeTheme);
  }

  var initialTheme = getStoredTheme();
  applyTheme(initialTheme);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initToggle);
  } else {
    initToggle();
  }
})();
