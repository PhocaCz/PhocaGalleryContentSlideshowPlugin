/*!
 * fadeslideshow.js – lightweight fade slideshow wrapper for Phoca Gallery Slideshow Plugin
 *
 * Purpose:
 * - Provides a compatible `fadeSlideShow({...})` constructor used by the Phoca Gallery Slideshow content plugin.
 * - Replaces the legacy "Ultimate Fade In Slideshow" dependency which is no longer shipped with recent Phoca/Joomla setups.
 *
 * Features:
 * - Fade transitions, autoplay
 * - Hover pause (desktop)
 * - Swipe (touch) + optional mouse drag swipe
 * - Lazy loading / deferred start
 *
 * Compatibility:
 * - Joomla 5/6, modern browsers
 *
 * Maintainer: Andy Marchand - Feuerwehr Worb (community patch)
 * Version: 4.4.0-j6fix.1 (2026-01-06)
 * License: GNU/GPL v2 or later
 */
 (function () {
  function toInt(v, fallback) {
    var n = parseInt(v, 10);
    return isNaN(n) ? fallback : n;
  }

  function pickImageUrl(entry) {
    if (!entry) return null;
    if (typeof entry === "string") return entry;
    if (Array.isArray(entry)) return entry[0] || null;
    return null;
  }

  function pickDesc(entry) {
    if (Array.isArray(entry)) return entry[3] || "";
    return "";
  }

  function absolutizeUrl(url) {
    if (!url) return url;

    // already absolute or special schemes
    if (/^(https?:)?\/\//i.test(url) || /^data:/i.test(url) || /^blob:/i.test(url)) {
      return url;
    }

    // root-relative
    if (url.charAt(0) === "/") {
      return window.location.origin + url;
    }

    // relative to current path
    var base = window.location.href.split("#")[0].split("?")[0];
    base = base.substring(0, base.lastIndexOf("/") + 1);
    return base + url;
  }

  function clampIndex(i, len) {
    if (!len) return 0;
    i = i % len;
    if (i < 0) i += len;
    return i;
  }

  // Public API expected by the plugin: new fadeSlideShow({ ... })
  window.fadeSlideShow = function (settings) {
    settings = settings || {};
    var wrapperId = settings.wrapperid || settings.wrapperId;
    var wrapper = wrapperId ? document.getElementById(wrapperId) : null;
    if (!wrapper) return;

    var images = Array.isArray(settings.imagearray) ? settings.imagearray.slice() : [];
    images = images
      .map(function (e) {
        var u = pickImageUrl(e);
        return { url: absolutizeUrl(u), desc: pickDesc(e) };
      })
      .filter(function (x) {
        return !!x.url;
      });

    if (!images.length) return;

    var pause = toInt(settings.displaymode && settings.displaymode.pause, 3000);
    var fadeDuration = toInt(settings.fadeduration, 600);
    var randomize = !!(settings.displaymode && settings.displaymode.randomize);

    if (randomize) {
      images.sort(function () {
        return Math.random() - 0.5;
      });
    }

    // Wrapper styling + UX
    wrapper.style.position = "relative";
    wrapper.style.overflow = "hidden";
    if (!wrapper.style.width) wrapper.style.width = "100%";
    if (!wrapper.style.height) wrapper.style.height = "100%";

    // Cursor + selection/drag behaviour on desktop
    wrapper.style.cursor = "grab";
    wrapper.style.userSelect = "none";
    wrapper.style.webkitUserSelect = "none";
    wrapper.style.msUserSelect = "none";
    wrapper.style.webkitUserDrag = "none";
    wrapper.style.touchAction = "pan-y"; // allow vertical scroll, we handle horizontal swipe

    // Prevent native dragstart inside wrapper (image ghost-drag)
    wrapper.addEventListener(
      "dragstart",
      function (e) {
        e.preventDefault();
      },
      { passive: false }
    );

    function makeImg() {
      var img = document.createElement("img");
      img.style.position = "absolute";
      img.style.inset = "0";
      img.style.width = "100%";
      img.style.height = "100%";
      img.style.objectFit = "contain";
      img.style.opacity = "0";
      img.style.transition = "opacity " + fadeDuration + "ms ease";
      img.style.willChange = "opacity";
      img.alt = "";

      // prevent native dragging
      img.draggable = false;
      img.style.userSelect = "none";
      img.style.webkitUserDrag = "none";
      img.style.pointerEvents = "auto";
      return img;
    }

    var imgA = makeImg();
    var imgB = makeImg();
    wrapper.innerHTML = "";
    wrapper.appendChild(imgA);
    wrapper.appendChild(imgB);

    var idx = 0;
    var front = imgA;
    var back = imgB;

    var isHoverPaused = false;
    var isPointerDown = false;
    var pointerId = null;
    var startX = 0;
    var startY = 0;
    var lastX = 0;
    var dragDetected = false;

    var SWIPE_THRESHOLD = 35; // px
    var VERTICAL_GUARD = 45;  // px (ignore if mostly vertical)

    function setCursor(state) {
      wrapper.style.cursor = state === "down" ? "grabbing" : "grab";
    }

    function show(i, first) {
      var item = images[i];
      if (!item) return;

      if (first) {
        front.onload = function () {
          front.style.opacity = "1";
          front.style.zIndex = "2";
          back.style.opacity = "0";
          back.style.zIndex = "1";
        };
        front.onerror = function () {
          front.onload = front.onerror = null;
          idx = (idx + 1) % images.length;
          show(idx, true);
        };
        front.src = item.url;
        return;
      }

      back.onload = function () {
        back.style.zIndex = "2";
        front.style.zIndex = "1";
        back.style.opacity = "1";
        front.style.opacity = "0";
      };

      back.onerror = function () {
        back.onload = back.onerror = null;
        idx = (idx + 1) % images.length;

        var tmp = front;
        front = back;
        back = tmp;

        show(idx, false);
      };

      back.src = item.url;
    }

    function swapLayers() {
      var tmp = front;
      front = back;
      back = tmp;
    }

    function next() {
      idx = clampIndex(idx + 1, images.length);
      swapLayers();
      show(idx, false);
    }

    function prev() {
      idx = clampIndex(idx - 1, images.length);
      swapLayers();
      show(idx, false);
    }

    // First render
    show(idx, true);

    // Timer
    var timer = setInterval(function () {
      if (!isHoverPaused && !isPointerDown) {
        next();
      }
    }, pause);

    function restartTimer() {
      clearInterval(timer);
      timer = setInterval(function () {
        if (!isHoverPaused && !isPointerDown) {
          next();
        }
      }, pause);
    }

    // Hover pause (desktop)
    wrapper.addEventListener("mouseenter", function () {
      isHoverPaused = true;
    });
    wrapper.addEventListener("mouseleave", function () {
      isHoverPaused = false;
    });

    // Pointer-based swipe (mouse + touch)
    wrapper.addEventListener(
      "pointerdown",
      function (e) {
        // only primary button for mouse
        if (e.pointerType === "mouse" && e.button !== 0) return;

        isPointerDown = true;
        pointerId = e.pointerId;
        startX = lastX = e.clientX;
        startY = e.clientY;
        dragDetected = false;

        setCursor("down");

        try {
          wrapper.setPointerCapture(pointerId);
        } catch (_) {}

        // prevent text selection / native interactions
        e.preventDefault();
      },
      { passive: false }
    );

    wrapper.addEventListener(
      "pointermove",
      function (e) {
        if (!isPointerDown || e.pointerId !== pointerId) return;

        lastX = e.clientX;
        var dx = lastX - startX;
        var dy = e.clientY - startY;

        // If it becomes clearly vertical, let scroll happen (especially touch)
        if (!dragDetected && Math.abs(dy) > VERTICAL_GUARD && Math.abs(dy) > Math.abs(dx)) {
          // release drag mode
          return;
        }

        if (Math.abs(dx) > 6) {
          dragDetected = true;
        }

        // If dragging horizontally, stop browser from doing weird things
        if (dragDetected) {
          e.preventDefault();
        }
      },
      { passive: false }
    );

    function endPointer(e) {
      if (!isPointerDown || e.pointerId !== pointerId) return;

      isPointerDown = false;
      pointerId = null;

      setCursor("up");

      var dx = lastX - startX;
      var dy = e.clientY - startY;

      // Only treat as swipe if mostly horizontal and above threshold
      if (Math.abs(dx) >= SWIPE_THRESHOLD && Math.abs(dx) > Math.abs(dy)) {
        if (dx < 0) {
          next();
        } else {
          prev();
        }
        restartTimer();
      }
    }

    wrapper.addEventListener("pointerup", endPointer, { passive: true });
    wrapper.addEventListener("pointercancel", endPointer, { passive: true });

    // public stop() like original library
    this.stop = function () {
      clearInterval(timer);
    };
  };
})();
