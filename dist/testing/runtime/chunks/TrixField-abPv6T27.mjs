import { jsx, jsxs } from "react/jsx-runtime";
import { useRef, useState, useMemo, useEffect, useId } from "react";
import { u as useTranslation, b as sanitizeRichText, w as withQuery, B as BASE_PATH, c as safeNavigationUrl, d as urlProtocol, e as useModalHistoryBackToClose } from "./index-DtgiJWoT.mjs";
import { createPortal } from "react-dom";
import { EyeIcon, EyeSlashIcon, XIcon } from "@phosphor-icons/react";
var t = "2.1.19";
const e = "[data-trix-attachment]", i = { preview: { presentation: "gallery", caption: { name: true, size: true } }, file: { caption: { size: true } } }, n = { default: { tagName: "div", parse: false }, quote: { tagName: "blockquote", nestable: true }, heading1: { tagName: "h1", terminal: true, breakOnReturn: true, group: false }, code: { tagName: "pre", terminal: true, htmlAttributes: ["language"], text: { plaintext: true } }, bulletList: { tagName: "ul", parse: false }, bullet: { tagName: "li", listAttribute: "bulletList", group: false, nestable: true, test(t3) {
  return r(t3.parentNode) === n[this.listAttribute].tagName;
} }, numberList: { tagName: "ol", parse: false }, number: { tagName: "li", listAttribute: "numberList", group: false, nestable: true, test(t3) {
  return r(t3.parentNode) === n[this.listAttribute].tagName;
} }, attachmentGallery: { tagName: "div", exclusive: true, terminal: true, parse: false, group: false } }, r = (t3) => {
  var e2;
  return null == t3 || null === (e2 = t3.tagName) || void 0 === e2 ? void 0 : e2.toLowerCase();
}, o = navigator.userAgent.match(/android\s([0-9]+.*Chrome)/i), s = o && parseInt(o[1]);
var a = { composesExistingText: /Android.*Chrome/.test(navigator.userAgent), recentAndroid: s && s > 12, samsungAndroid: s && navigator.userAgent.match(/Android.*SM-/), forcesObjectResizing: /Trident.*rv:11/.test(navigator.userAgent), supportsInputEvents: "undefined" != typeof InputEvent && ["data", "getTargetRanges", "inputType"].every(((t3) => t3 in InputEvent.prototype)) }, l = { ADD_ATTR: ["language"], SAFE_FOR_XML: false, RETURN_DOM: true }, c = { attachFiles: "Attach Files", bold: "Bold", bullets: "Bullets", byte: "Byte", bytes: "Bytes", captionPlaceholder: "Add a caption…", code: "Code", heading1: "Heading", indent: "Increase Level", italic: "Italic", link: "Link", numbers: "Numbers", outdent: "Decrease Level", quote: "Quote", redo: "Redo", remove: "Remove", strike: "Strikethrough", undo: "Undo", unlink: "Unlink", url: "URL", urlPlaceholder: "Enter a URL…", GB: "GB", KB: "KB", MB: "MB", PB: "PB", TB: "TB" };
const u = [c.bytes, c.KB, c.MB, c.GB, c.TB, c.PB];
var h = { prefix: "IEC", precision: 2, formatter(t3) {
  switch (t3) {
    case 0:
      return "0 ".concat(c.bytes);
    case 1:
      return "1 ".concat(c.byte);
    default:
      let e2;
      "SI" === this.prefix ? e2 = 1e3 : "IEC" === this.prefix && (e2 = 1024);
      const i2 = Math.floor(Math.log(t3) / Math.log(e2)), n2 = (t3 / Math.pow(e2, i2)).toFixed(this.precision).replace(/0*$/, "").replace(/\.$/, "");
      return "".concat(n2, " ").concat(u[i2]);
  }
} };
const d = "\uFEFF", g = " ", m = function(t3) {
  for (const e2 in t3) {
    const i2 = t3[e2];
    this[e2] = i2;
  }
  return this;
}, p = document.documentElement, f = p.matches, b = function(t3) {
  let { onElement: e2, matchingSelector: i2, withCallback: n2, inPhase: r2, preventDefault: o2, times: s2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  const a2 = e2 || p, l2 = i2, c2 = "capturing" === r2, u2 = function(t4) {
    null != s2 && 0 == --s2 && u2.destroy();
    const e3 = x(t4.target, { matchingSelector: l2 });
    null != e3 && (null == n2 || n2.call(e3, t4, e3), o2 && t4.preventDefault());
  };
  return u2.destroy = () => a2.removeEventListener(t3, u2, c2), a2.addEventListener(t3, u2, c2), u2;
}, v = function(t3) {
  let { bubbles: e2, cancelable: i2, attributes: n2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  e2 = false !== e2, i2 = false !== i2;
  const r2 = document.createEvent("Events");
  return r2.initEvent(t3, e2, i2), null != n2 && m.call(r2, n2), r2;
}, A = function(t3) {
  let { onElement: e2, bubbles: i2, cancelable: n2, attributes: r2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  const o2 = null != e2 ? e2 : p, s2 = v(t3, { bubbles: i2, cancelable: n2, attributes: r2 });
  return o2.dispatchEvent(s2);
}, y = function(t3, e2) {
  if (1 === (null == t3 ? void 0 : t3.nodeType)) return f.call(t3, e2);
}, x = function(t3) {
  let { matchingSelector: e2, untilNode: i2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  for (; t3 && t3.nodeType !== Node.ELEMENT_NODE; ) t3 = t3.parentNode;
  if (null != t3) {
    if (null == e2) return t3;
    if (t3.closest && null == i2) return t3.closest(e2);
    for (; t3 && t3 !== i2; ) {
      if (y(t3, e2)) return t3;
      t3 = t3.parentNode;
    }
  }
}, C = (t3) => document.activeElement !== t3 && E(t3, document.activeElement), E = function(t3, e2) {
  if (t3 && e2) for (; e2; ) {
    if (e2 === t3) return true;
    e2 = e2.parentNode;
  }
}, S = function(t3) {
  var e2;
  if (null === (e2 = t3) || void 0 === e2 || !e2.parentNode) return;
  let i2 = 0;
  for (t3 = t3.previousSibling; t3; ) i2++, t3 = t3.previousSibling;
  return i2;
}, R = (t3) => {
  var e2;
  return null == t3 || null === (e2 = t3.parentNode) || void 0 === e2 ? void 0 : e2.removeChild(t3);
}, T = function(t3) {
  let { onlyNodesOfType: e2, usingFilter: i2, expandEntityReferences: n2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  const r2 = (() => {
    switch (e2) {
      case "element":
        return NodeFilter.SHOW_ELEMENT;
      case "text":
        return NodeFilter.SHOW_TEXT;
      case "comment":
        return NodeFilter.SHOW_COMMENT;
      default:
        return NodeFilter.SHOW_ALL;
    }
  })();
  return document.createTreeWalker(t3, r2, null != i2 ? i2 : null, true === n2);
}, k = (t3) => {
  var e2;
  return null == t3 || null === (e2 = t3.tagName) || void 0 === e2 ? void 0 : e2.toLowerCase();
}, w = function(t3) {
  let e2, i2, n2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  "object" == typeof t3 ? (n2 = t3, t3 = n2.tagName) : n2 = { attributes: n2 };
  const r2 = document.createElement(t3);
  if (null != n2.editable && (null == n2.attributes && (n2.attributes = {}), n2.attributes.contenteditable = n2.editable), n2.attributes) for (e2 in n2.attributes) i2 = n2.attributes[e2], r2.setAttribute(e2, i2);
  if (n2.style) for (e2 in n2.style) i2 = n2.style[e2], r2.style[e2] = i2;
  if (n2.data) for (e2 in n2.data) i2 = n2.data[e2], r2.dataset[e2] = i2;
  return n2.className && n2.className.split(" ").forEach(((t4) => {
    r2.classList.add(t4);
  })), n2.textContent && (r2.textContent = n2.textContent), n2.childNodes && [].concat(n2.childNodes).forEach(((t4) => {
    r2.appendChild(t4);
  })), r2;
};
let D;
const L = function() {
  if (null != D) return D;
  D = [];
  for (const t3 in n) {
    const e2 = n[t3];
    e2.tagName && D.push(e2.tagName);
  }
  return D;
}, N = (t3) => O(null == t3 ? void 0 : t3.firstChild), I = function(t3) {
  let { strict: e2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : { strict: true };
  return e2 ? O(t3) : O(t3) || !O(t3.firstChild) && (function(t4) {
    return L().includes(k(t4)) && !L().includes(k(t4.firstChild));
  })(t3);
}, O = (t3) => F(t3) && "block" === (null == t3 ? void 0 : t3.data), F = (t3) => (null == t3 ? void 0 : t3.nodeType) === Node.COMMENT_NODE, P = function(t3) {
  let { name: e2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  if (t3) return _(t3) ? t3.data === d ? !e2 || t3.parentNode.dataset.trixCursorTarget === e2 : void 0 : P(t3.firstChild);
}, M = (t3) => y(t3, e), B = (t3) => _(t3) && "" === (null == t3 ? void 0 : t3.data), _ = (t3) => (null == t3 ? void 0 : t3.nodeType) === Node.TEXT_NODE, j = { level2Enabled: true, getLevel() {
  return this.level2Enabled && a.supportsInputEvents ? 2 : 0;
}, pickFiles(t3) {
  const e2 = w("input", { type: "file", multiple: true, hidden: true, id: this.fileInputId });
  e2.addEventListener("change", (() => {
    t3(e2.files), R(e2);
  })), R(document.getElementById(this.fileInputId)), document.body.appendChild(e2), e2.click();
} };
var W = { removeBlankTableCells: false, tableCellSeparator: " | ", tableRowSeparator: "\n" }, U = { bold: { tagName: "strong", inheritable: true, parser(t3) {
  const e2 = window.getComputedStyle(t3);
  return "bold" === e2.fontWeight || e2.fontWeight >= 600;
} }, italic: { tagName: "em", inheritable: true, parser: (t3) => "italic" === window.getComputedStyle(t3).fontStyle }, href: { groupTagName: "a", parser(t3) {
  const i2 = "a:not(".concat(e, ")"), n2 = t3.closest(i2);
  if (n2) return n2.getAttribute("href");
} }, strike: { tagName: "del", inheritable: true }, frozen: { style: { backgroundColor: "highlight" } } }, V = { getDefaultHTML: () => '<div class="trix-button-row">\n      <span class="trix-button-group trix-button-group--text-tools" data-trix-button-group="text-tools">\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-bold" data-trix-attribute="bold" data-trix-key="b" title="'.concat(c.bold, '" tabindex="-1">').concat(c.bold, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-italic" data-trix-attribute="italic" data-trix-key="i" title="').concat(c.italic, '" tabindex="-1">').concat(c.italic, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-strike" data-trix-attribute="strike" title="').concat(c.strike, '" tabindex="-1">').concat(c.strike, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-link" data-trix-attribute="href" data-trix-action="link" data-trix-key="k" title="').concat(c.link, '" tabindex="-1">').concat(c.link, '</button>\n      </span>\n\n      <span class="trix-button-group trix-button-group--block-tools" data-trix-button-group="block-tools">\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-heading-1" data-trix-attribute="heading1" title="').concat(c.heading1, '" tabindex="-1">').concat(c.heading1, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-quote" data-trix-attribute="quote" title="').concat(c.quote, '" tabindex="-1">').concat(c.quote, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-code" data-trix-attribute="code" title="').concat(c.code, '" tabindex="-1">').concat(c.code, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-bullet-list" data-trix-attribute="bullet" title="').concat(c.bullets, '" tabindex="-1">').concat(c.bullets, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-number-list" data-trix-attribute="number" title="').concat(c.numbers, '" tabindex="-1">').concat(c.numbers, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-decrease-nesting-level" data-trix-action="decreaseNestingLevel" title="').concat(c.outdent, '" tabindex="-1">').concat(c.outdent, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-increase-nesting-level" data-trix-action="increaseNestingLevel" title="').concat(c.indent, '" tabindex="-1">').concat(c.indent, '</button>\n      </span>\n\n      <span class="trix-button-group trix-button-group--file-tools" data-trix-button-group="file-tools">\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-attach" data-trix-action="attachFiles" title="').concat(c.attachFiles, '" tabindex="-1">').concat(c.attachFiles, '</button>\n      </span>\n\n      <span class="trix-button-group-spacer"></span>\n\n      <span class="trix-button-group trix-button-group--history-tools" data-trix-button-group="history-tools">\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-undo" data-trix-action="undo" data-trix-key="z" title="').concat(c.undo, '" tabindex="-1">').concat(c.undo, '</button>\n        <button type="button" class="trix-button trix-button--icon trix-button--icon-redo" data-trix-action="redo" data-trix-key="shift+z" title="').concat(c.redo, '" tabindex="-1">').concat(c.redo, '</button>\n      </span>\n    </div>\n\n    <div class="trix-dialogs" data-trix-dialogs>\n      <div class="trix-dialog trix-dialog--link" data-trix-dialog="href" data-trix-dialog-attribute="href">\n        <div class="trix-dialog__link-fields">\n          <input type="url" name="href" class="trix-input trix-input--dialog" placeholder="').concat(c.urlPlaceholder, '" aria-label="').concat(c.url, '" data-trix-validate-href required data-trix-input>\n          <div class="trix-button-group">\n            <input type="button" class="trix-button trix-button--dialog" value="').concat(c.link, '" data-trix-method="setAttribute">\n            <input type="button" class="trix-button trix-button--dialog" value="').concat(c.unlink, '" data-trix-method="removeAttribute">\n          </div>\n        </div>\n      </div>\n    </div>') };
const z = { interval: 5e3 };
var H = Object.freeze({ __proto__: null, attachments: i, blockAttributes: n, browser: a, css: { attachment: "attachment", attachmentCaption: "attachment__caption", attachmentCaptionEditor: "attachment__caption-editor", attachmentMetadata: "attachment__metadata", attachmentMetadataContainer: "attachment__metadata-container", attachmentName: "attachment__name", attachmentProgress: "attachment__progress", attachmentSize: "attachment__size", attachmentToolbar: "attachment__toolbar", attachmentGallery: "attachment-gallery" }, dompurify: l, fileSize: h, input: j, keyNames: { 8: "backspace", 9: "tab", 13: "return", 27: "escape", 37: "left", 39: "right", 46: "delete", 68: "d", 72: "h", 79: "o" }, lang: c, parser: W, textAttributes: U, toolbar: V, undo: z });
class q {
  static proxyMethod(t3) {
    const { name: e2, toMethod: i2, toProperty: n2, optional: r2 } = J(t3);
    this.prototype[e2] = function() {
      let t4, o2;
      var s2, a2;
      i2 ? o2 = r2 ? null === (s2 = this[i2]) || void 0 === s2 ? void 0 : s2.call(this) : this[i2]() : n2 && (o2 = this[n2]);
      return r2 ? (t4 = null === (a2 = o2) || void 0 === a2 ? void 0 : a2[e2], t4 ? K.call(t4, o2, arguments) : void 0) : (t4 = o2[e2], K.call(t4, o2, arguments));
    };
  }
}
const J = function(t3) {
  const e2 = t3.match(G);
  if (!e2) throw new Error("can't parse @proxyMethod expression: ".concat(t3));
  const i2 = { name: e2[4] };
  return null != e2[2] ? i2.toMethod = e2[1] : i2.toProperty = e2[1], null != e2[3] && (i2.optional = true), i2;
}, { apply: K } = Function.prototype, G = new RegExp("^(.+?)(\\(\\))?(\\?)?\\.(.+?)$");
var X, Y, $;
class Z extends q {
  static box() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : "";
    return t3 instanceof this ? t3 : this.fromUCS2String(null == t3 ? void 0 : t3.toString());
  }
  static fromUCS2String(t3) {
    return new this(t3, it(t3));
  }
  static fromCodepoints(t3) {
    return new this(nt(t3), t3);
  }
  constructor(t3, e2) {
    super(...arguments), this.ucs2String = t3, this.codepoints = e2, this.length = this.codepoints.length, this.ucs2Length = this.ucs2String.length;
  }
  offsetToUCS2Offset(t3) {
    return nt(this.codepoints.slice(0, Math.max(0, t3))).length;
  }
  offsetFromUCS2Offset(t3) {
    return it(this.ucs2String.slice(0, Math.max(0, t3))).length;
  }
  slice() {
    return this.constructor.fromCodepoints(this.codepoints.slice(...arguments));
  }
  charAt(t3) {
    return this.slice(t3, t3 + 1);
  }
  isEqualTo(t3) {
    return this.constructor.box(t3).ucs2String === this.ucs2String;
  }
  toJSON() {
    return this.ucs2String;
  }
  getCacheKey() {
    return this.ucs2String;
  }
  toString() {
    return this.ucs2String;
  }
}
const Q = 1 === (null === (X = Array.from) || void 0 === X ? void 0 : X.call(Array, "👼").length), tt = null != (null === (Y = " ".codePointAt) || void 0 === Y ? void 0 : Y.call(" ", 0)), et = " 👼" === (null === ($ = String.fromCodePoint) || void 0 === $ ? void 0 : $.call(String, 32, 128124));
let it, nt;
it = Q && tt ? (t3) => Array.from(t3).map(((t4) => t4.codePointAt(0))) : function(t3) {
  const e2 = [];
  let i2 = 0;
  const { length: n2 } = t3;
  for (; i2 < n2; ) {
    let r2 = t3.charCodeAt(i2++);
    if (55296 <= r2 && r2 <= 56319 && i2 < n2) {
      const e3 = t3.charCodeAt(i2++);
      56320 == (64512 & e3) ? r2 = ((1023 & r2) << 10) + (1023 & e3) + 65536 : i2--;
    }
    e2.push(r2);
  }
  return e2;
}, nt = et ? (t3) => String.fromCodePoint(...Array.from(t3 || [])) : function(t3) {
  return (() => {
    const e2 = [];
    return Array.from(t3).forEach(((t4) => {
      let i2 = "";
      t4 > 65535 && (t4 -= 65536, i2 += String.fromCharCode(t4 >>> 10 & 1023 | 55296), t4 = 56320 | 1023 & t4), e2.push(i2 + String.fromCharCode(t4));
    })), e2;
  })().join("");
};
let rt = 0;
class ot extends q {
  static fromJSONString(t3) {
    return this.fromJSON(JSON.parse(t3));
  }
  constructor() {
    super(...arguments), this.id = ++rt;
  }
  hasSameConstructorAs(t3) {
    return this.constructor === (null == t3 ? void 0 : t3.constructor);
  }
  isEqualTo(t3) {
    return this === t3;
  }
  inspect() {
    const t3 = [], e2 = this.contentsForInspection() || {};
    for (const i2 in e2) {
      const n2 = e2[i2];
      t3.push("".concat(i2, "=").concat(n2));
    }
    return "#<".concat(this.constructor.name, ":").concat(this.id).concat(t3.length ? " ".concat(t3.join(", ")) : "", ">");
  }
  contentsForInspection() {
  }
  toJSONString() {
    return JSON.stringify(this);
  }
  toUTF16String() {
    return Z.box(this);
  }
  getCacheKey() {
    return this.id.toString();
  }
}
const st = function() {
  let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [], e2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : [];
  if (t3.length !== e2.length) return false;
  for (let i2 = 0; i2 < t3.length; i2++) {
    if (t3[i2] !== e2[i2]) return false;
  }
  return true;
}, at = function(t3) {
  const e2 = t3.slice(0);
  for (var i2 = arguments.length, n2 = new Array(i2 > 1 ? i2 - 1 : 0), r2 = 1; r2 < i2; r2++) n2[r2 - 1] = arguments[r2];
  return e2.splice(...n2), e2;
}, lt = /[\u05BE\u05C0\u05C3\u05D0-\u05EA\u05F0-\u05F4\u061B\u061F\u0621-\u063A\u0640-\u064A\u066D\u0671-\u06B7\u06BA-\u06BE\u06C0-\u06CE\u06D0-\u06D5\u06E5\u06E6\u200F\u202B\u202E\uFB1F-\uFB28\uFB2A-\uFB36\uFB38-\uFB3C\uFB3E\uFB40\uFB41\uFB43\uFB44\uFB46-\uFBB1\uFBD3-\uFD3D\uFD50-\uFD8F\uFD92-\uFDC7\uFDF0-\uFDFB\uFE70-\uFE72\uFE74\uFE76-\uFEFC]/, ct = (function() {
  const t3 = w("input", { dir: "auto", name: "x", dirName: "x.dir" }), e2 = w("textarea", { dir: "auto", name: "y", dirName: "y.dir" }), i2 = w("form");
  i2.appendChild(t3), i2.appendChild(e2);
  const n2 = (function() {
    try {
      return new FormData(i2).has(e2.dirName);
    } catch (t4) {
      return false;
    }
  })(), r2 = (function() {
    try {
      return t3.matches(":dir(ltr),:dir(rtl)");
    } catch (t4) {
      return false;
    }
  })();
  return n2 ? function(t4) {
    return e2.value = t4, new FormData(i2).get(e2.dirName);
  } : r2 ? function(e3) {
    return t3.value = e3, t3.matches(":dir(rtl)") ? "rtl" : "ltr";
  } : function(t4) {
    const e3 = t4.trim().charAt(0);
    return lt.test(e3) ? "rtl" : "ltr";
  };
})();
let ut = null, ht = null, dt = null, gt = null;
const mt = () => (ut || (ut = vt().concat(ft())), ut), pt = (t3) => n[t3], ft = () => (ht || (ht = Object.keys(n)), ht), bt = (t3) => U[t3], vt = () => (dt || (dt = Object.keys(U)), dt), At = function(t3, e2) {
  yt(t3).textContent = e2.replace(/%t/g, t3);
}, yt = function(t3) {
  const e2 = document.createElement("style");
  e2.setAttribute("type", "text/css"), e2.setAttribute("data-tag-name", t3.toLowerCase());
  const i2 = xt();
  return i2 && e2.setAttribute("nonce", i2), document.head.insertBefore(e2, document.head.firstChild), e2;
}, xt = function() {
  const t3 = Ct("trix-csp-nonce") || Ct("csp-nonce");
  if (t3) {
    const { nonce: e2, content: i2 } = t3;
    return "" == e2 ? i2 : e2;
  }
}, Ct = (t3) => document.head.querySelector("meta[name=".concat(t3, "]")), Et = { "application/x-trix-feature-detection": "test" }, St = function(t3) {
  const e2 = t3.getData("text/plain"), i2 = t3.getData("text/html");
  if (!e2 || !i2) return null == e2 ? void 0 : e2.length;
  {
    const { body: t4 } = new DOMParser().parseFromString(i2, "text/html");
    if (t4.textContent === e2) return !t4.querySelector("*");
  }
}, Rt = /Mac|^iP/.test(navigator.platform) ? (t3) => t3.metaKey : (t3) => t3.ctrlKey;
const Tt = (t3) => setTimeout(t3, 1), kt = function() {
  let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {};
  const e2 = {};
  for (const i2 in t3) {
    const n2 = t3[i2];
    e2[i2] = n2;
  }
  return e2;
}, wt = function() {
  let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {}, e2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  if (Object.keys(t3).length !== Object.keys(e2).length) return false;
  for (const i2 in t3) {
    if (t3[i2] !== e2[i2]) return false;
  }
  return true;
}, Dt = function(t3) {
  if (null != t3) return Array.isArray(t3) || (t3 = [t3, t3]), [It(t3[0]), It(null != t3[1] ? t3[1] : t3[0])];
}, Lt = function(t3) {
  if (null == t3) return;
  const [e2, i2] = Dt(t3);
  return Ot(e2, i2);
}, Nt = function(t3, e2) {
  if (null == t3 || null == e2) return;
  const [i2, n2] = Dt(t3), [r2, o2] = Dt(e2);
  return Ot(i2, r2) && Ot(n2, o2);
}, It = function(t3) {
  return "number" == typeof t3 ? t3 : kt(t3);
}, Ot = function(t3, e2) {
  return "number" == typeof t3 ? t3 === e2 : wt(t3, e2);
};
class Ft extends q {
  constructor() {
    super(...arguments), this.update = this.update.bind(this), this.selectionManagers = [];
  }
  start() {
    this.started || (this.started = true, document.addEventListener("selectionchange", this.update, true));
  }
  stop() {
    if (this.started) return this.started = false, document.removeEventListener("selectionchange", this.update, true);
  }
  registerSelectionManager(t3) {
    if (!this.selectionManagers.includes(t3)) return this.selectionManagers.push(t3), this.start();
  }
  unregisterSelectionManager(t3) {
    if (this.selectionManagers = this.selectionManagers.filter(((e2) => e2 !== t3)), 0 === this.selectionManagers.length) return this.stop();
  }
  notifySelectionManagersOfSelectionChange() {
    return this.selectionManagers.map(((t3) => t3.selectionDidChange()));
  }
  update() {
    this.notifySelectionManagersOfSelectionChange();
  }
  reset() {
    this.update();
  }
}
const Pt = new Ft(), Mt = function() {
  const t3 = window.getSelection();
  if (t3.rangeCount > 0) return t3;
}, Bt = function() {
  var t3;
  const e2 = null === (t3 = Mt()) || void 0 === t3 ? void 0 : t3.getRangeAt(0);
  if (e2 && !jt(e2)) return e2;
}, _t = function(t3) {
  const e2 = window.getSelection();
  return e2.removeAllRanges(), e2.addRange(t3), Pt.update();
}, jt = (t3) => Wt(t3.startContainer) || Wt(t3.endContainer), Wt = (t3) => !Object.getPrototypeOf(t3), Ut = (t3) => t3.replace(new RegExp("".concat(d), "g"), "").replace(new RegExp("".concat(g), "g"), " "), Vt = new RegExp("[^\\S".concat(g, "]")), zt = (t3) => t3.replace(new RegExp("".concat(Vt.source), "g"), " ").replace(/\ {2,}/g, " "), Ht = function(t3, e2) {
  if (t3.isEqualTo(e2)) return ["", ""];
  const i2 = qt(t3, e2), { length: n2 } = i2.utf16String;
  let r2;
  if (n2) {
    const { offset: o2 } = i2, s2 = t3.codepoints.slice(0, o2).concat(t3.codepoints.slice(o2 + n2));
    r2 = qt(e2, Z.fromCodepoints(s2));
  } else r2 = qt(e2, t3);
  return [i2.utf16String.toString(), r2.utf16String.toString()];
}, qt = function(t3, e2) {
  let i2 = 0, n2 = t3.length, r2 = e2.length;
  for (; i2 < n2 && t3.charAt(i2).isEqualTo(e2.charAt(i2)); ) i2++;
  for (; n2 > i2 + 1 && t3.charAt(n2 - 1).isEqualTo(e2.charAt(r2 - 1)); ) n2--, r2--;
  return { utf16String: t3.slice(i2, n2), offset: i2 };
};
class Jt extends ot {
  static fromCommonAttributesOfObjects() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
    if (!t3.length) return new this();
    let e2 = Yt(t3[0]), i2 = e2.getKeys();
    return t3.slice(1).forEach(((t4) => {
      i2 = e2.getKeysCommonToHash(Yt(t4)), e2 = e2.slice(i2);
    })), e2;
  }
  static box(t3) {
    return Yt(t3);
  }
  constructor() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {};
    super(...arguments), this.values = Xt(t3);
  }
  add(t3, e2) {
    return this.merge(Kt(t3, e2));
  }
  remove(t3) {
    return new Jt(Xt(this.values, t3));
  }
  get(t3) {
    return this.values[t3];
  }
  has(t3) {
    return t3 in this.values;
  }
  merge(t3) {
    return new Jt(Gt(this.values, $t(t3)));
  }
  slice(t3) {
    const e2 = {};
    return Array.from(t3).forEach(((t4) => {
      this.has(t4) && (e2[t4] = this.values[t4]);
    })), new Jt(e2);
  }
  getKeys() {
    return Object.keys(this.values);
  }
  getKeysCommonToHash(t3) {
    return t3 = Yt(t3), this.getKeys().filter(((e2) => this.values[e2] === t3.values[e2]));
  }
  isEqualTo(t3) {
    return st(this.toArray(), Yt(t3).toArray());
  }
  isEmpty() {
    return 0 === this.getKeys().length;
  }
  toArray() {
    if (!this.array) {
      const t3 = [];
      for (const e2 in this.values) {
        const i2 = this.values[e2];
        t3.push(t3.push(e2, i2));
      }
      this.array = t3.slice(0);
    }
    return this.array;
  }
  toObject() {
    return Xt(this.values);
  }
  toJSON() {
    return this.toObject();
  }
  contentsForInspection() {
    return { values: JSON.stringify(this.values) };
  }
}
const Kt = function(t3, e2) {
  const i2 = {};
  return i2[t3] = e2, i2;
}, Gt = function(t3, e2) {
  const i2 = Xt(t3);
  for (const t4 in e2) {
    const n2 = e2[t4];
    i2[t4] = n2;
  }
  return i2;
}, Xt = function(t3, e2) {
  const i2 = {};
  return Object.keys(t3).sort().forEach(((n2) => {
    n2 !== e2 && (i2[n2] = t3[n2]);
  })), i2;
}, Yt = function(t3) {
  return t3 instanceof Jt ? t3 : new Jt(t3);
}, $t = function(t3) {
  return t3 instanceof Jt ? t3.values : t3;
};
class Zt {
  static groupObjects() {
    let t3, e2 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [], { depth: i2, asTree: n2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    n2 && null == i2 && (i2 = 0);
    const r2 = [];
    return Array.from(e2).forEach(((e3) => {
      var o2;
      if (t3) {
        var s2, a2, l2;
        if (null !== (s2 = e3.canBeGrouped) && void 0 !== s2 && s2.call(e3, i2) && null !== (a2 = (l2 = t3[t3.length - 1]).canBeGroupedWith) && void 0 !== a2 && a2.call(l2, e3, i2)) return void t3.push(e3);
        r2.push(new this(t3, { depth: i2, asTree: n2 })), t3 = null;
      }
      null !== (o2 = e3.canBeGrouped) && void 0 !== o2 && o2.call(e3, i2) ? t3 = [e3] : r2.push(e3);
    })), t3 && r2.push(new this(t3, { depth: i2, asTree: n2 })), r2;
  }
  constructor() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [], { depth: e2, asTree: i2 } = arguments.length > 1 ? arguments[1] : void 0;
    this.objects = t3, i2 && (this.depth = e2, this.objects = this.constructor.groupObjects(this.objects, { asTree: i2, depth: this.depth + 1 }));
  }
  getObjects() {
    return this.objects;
  }
  getDepth() {
    return this.depth;
  }
  getCacheKey() {
    const t3 = ["objectGroup"];
    return Array.from(this.getObjects()).forEach(((e2) => {
      t3.push(e2.getCacheKey());
    })), t3.join("/");
  }
}
class Qt extends q {
  constructor() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
    super(...arguments), this.objects = {}, Array.from(t3).forEach(((t4) => {
      const e2 = JSON.stringify(t4);
      null == this.objects[e2] && (this.objects[e2] = t4);
    }));
  }
  find(t3) {
    const e2 = JSON.stringify(t3);
    return this.objects[e2];
  }
}
class te {
  constructor(t3) {
    this.reset(t3);
  }
  add(t3) {
    const e2 = ee(t3);
    this.elements[e2] = t3;
  }
  remove(t3) {
    const e2 = ee(t3), i2 = this.elements[e2];
    if (i2) return delete this.elements[e2], i2;
  }
  reset() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
    return this.elements = {}, Array.from(t3).forEach(((t4) => {
      this.add(t4);
    })), t3;
  }
}
const ee = (t3) => t3.dataset.trixStoreKey;
class ie extends q {
  isPerforming() {
    return true === this.performing;
  }
  hasPerformed() {
    return true === this.performed;
  }
  hasSucceeded() {
    return this.performed && this.succeeded;
  }
  hasFailed() {
    return this.performed && !this.succeeded;
  }
  getPromise() {
    return this.promise || (this.promise = new Promise(((t3, e2) => (this.performing = true, this.perform(((i2, n2) => {
      this.succeeded = i2, this.performing = false, this.performed = true, this.succeeded ? t3(n2) : e2(n2);
    })))))), this.promise;
  }
  perform(t3) {
    return t3(false);
  }
  release() {
    var t3, e2;
    null === (t3 = this.promise) || void 0 === t3 || null === (e2 = t3.cancel) || void 0 === e2 || e2.call(t3), this.promise = null, this.performing = null, this.performed = null, this.succeeded = null;
  }
}
ie.proxyMethod("getPromise().then"), ie.proxyMethod("getPromise().catch");
class ne extends q {
  constructor(t3) {
    let e2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    super(...arguments), this.object = t3, this.options = e2, this.childViews = [], this.rootView = this;
  }
  getNodes() {
    return this.nodes || (this.nodes = this.createNodes()), this.nodes.map(((t3) => t3.cloneNode(true)));
  }
  invalidate() {
    var t3;
    return this.nodes = null, this.childViews = [], null === (t3 = this.parentView) || void 0 === t3 ? void 0 : t3.invalidate();
  }
  invalidateViewForObject(t3) {
    var e2;
    return null === (e2 = this.findViewForObject(t3)) || void 0 === e2 ? void 0 : e2.invalidate();
  }
  findOrCreateCachedChildView(t3, e2, i2) {
    let n2 = this.getCachedViewForObject(e2);
    return n2 ? this.recordChildView(n2) : (n2 = this.createChildView(...arguments), this.cacheViewForObject(n2, e2)), n2;
  }
  createChildView(t3, e2) {
    let i2 = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : {};
    e2 instanceof Zt && (i2.viewClass = t3, t3 = re);
    const n2 = new t3(e2, i2);
    return this.recordChildView(n2);
  }
  recordChildView(t3) {
    return t3.parentView = this, t3.rootView = this.rootView, this.childViews.push(t3), t3;
  }
  getAllChildViews() {
    let t3 = [];
    return this.childViews.forEach(((e2) => {
      t3.push(e2), t3 = t3.concat(e2.getAllChildViews());
    })), t3;
  }
  findElement() {
    return this.findElementForObject(this.object);
  }
  findElementForObject(t3) {
    const e2 = null == t3 ? void 0 : t3.id;
    if (e2) return this.rootView.element.querySelector("[data-trix-id='".concat(e2, "']"));
  }
  findViewForObject(t3) {
    for (const e2 of this.getAllChildViews()) if (e2.object === t3) return e2;
  }
  getViewCache() {
    return this.rootView !== this ? this.rootView.getViewCache() : this.isViewCachingEnabled() ? (this.viewCache || (this.viewCache = {}), this.viewCache) : void 0;
  }
  isViewCachingEnabled() {
    return false !== this.shouldCacheViews;
  }
  enableViewCaching() {
    this.shouldCacheViews = true;
  }
  disableViewCaching() {
    this.shouldCacheViews = false;
  }
  getCachedViewForObject(t3) {
    var e2;
    return null === (e2 = this.getViewCache()) || void 0 === e2 ? void 0 : e2[t3.getCacheKey()];
  }
  cacheViewForObject(t3, e2) {
    const i2 = this.getViewCache();
    i2 && (i2[e2.getCacheKey()] = t3);
  }
  garbageCollectCachedViews() {
    const t3 = this.getViewCache();
    if (t3) {
      const e2 = this.getAllChildViews().concat(this).map(((t4) => t4.object.getCacheKey()));
      for (const i2 in t3) e2.includes(i2) || delete t3[i2];
    }
  }
}
class re extends ne {
  constructor() {
    super(...arguments), this.objectGroup = this.object, this.viewClass = this.options.viewClass, delete this.options.viewClass;
  }
  getChildViews() {
    return this.childViews.length || Array.from(this.objectGroup.getObjects()).forEach(((t3) => {
      this.findOrCreateCachedChildView(this.viewClass, t3, this.options);
    })), this.childViews;
  }
  createNodes() {
    const t3 = this.createContainerElement();
    return this.getChildViews().forEach(((e2) => {
      Array.from(e2.getNodes()).forEach(((e3) => {
        t3.appendChild(e3);
      }));
    })), [t3];
  }
  createContainerElement() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : this.objectGroup.getDepth();
    return this.getChildViews()[0].createContainerElement(t3);
  }
}
/*! @license DOMPurify 3.4.2 | (c) Cure53 and other contributors | Released under the Apache license 2.0 and Mozilla Public License 2.0 | github.com/cure53/DOMPurify/blob/3.4.2/LICENSE */
const { entries: oe, setPrototypeOf: se, isFrozen: ae, getPrototypeOf: le, getOwnPropertyDescriptor: ce } = Object;
let { freeze: ue, seal: he, create: de } = Object, { apply: ge, construct: me } = "undefined" != typeof Reflect && Reflect;
ue || (ue = function(t3) {
  return t3;
}), he || (he = function(t3) {
  return t3;
}), ge || (ge = function(t3, e2) {
  for (var i2 = arguments.length, n2 = new Array(i2 > 2 ? i2 - 2 : 0), r2 = 2; r2 < i2; r2++) n2[r2 - 2] = arguments[r2];
  return t3.apply(e2, n2);
}), me || (me = function(t3) {
  for (var e2 = arguments.length, i2 = new Array(e2 > 1 ? e2 - 1 : 0), n2 = 1; n2 < e2; n2++) i2[n2 - 1] = arguments[n2];
  return new t3(...i2);
});
const pe = Me(Array.prototype.forEach), fe = Me(Array.prototype.lastIndexOf), be = Me(Array.prototype.pop), ve = Me(Array.prototype.push), Ae = Me(Array.prototype.splice), ye = Array.isArray, xe = Me(String.prototype.toLowerCase), Ce = Me(String.prototype.toString), Ee = Me(String.prototype.match), Se = Me(String.prototype.replace), Re = Me(String.prototype.indexOf), Te = Me(String.prototype.trim), ke = Me(Number.prototype.toString), we = Me(Boolean.prototype.toString), De = "undefined" == typeof BigInt ? null : Me(BigInt.prototype.toString), Le = "undefined" == typeof Symbol ? null : Me(Symbol.prototype.toString), Ne = Me(Object.prototype.hasOwnProperty), Ie = Me(Object.prototype.toString), Oe = Me(RegExp.prototype.test), Fe = (Pe = TypeError, function() {
  for (var t3 = arguments.length, e2 = new Array(t3), i2 = 0; i2 < t3; i2++) e2[i2] = arguments[i2];
  return me(Pe, e2);
});
var Pe;
function Me(t3) {
  return function(e2) {
    e2 instanceof RegExp && (e2.lastIndex = 0);
    for (var i2 = arguments.length, n2 = new Array(i2 > 1 ? i2 - 1 : 0), r2 = 1; r2 < i2; r2++) n2[r2 - 1] = arguments[r2];
    return ge(t3, e2, n2);
  };
}
function Be(t3, e2) {
  let i2 = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : xe;
  if (se && se(t3, null), !ye(e2)) return t3;
  let n2 = e2.length;
  for (; n2--; ) {
    let r2 = e2[n2];
    if ("string" == typeof r2) {
      const t4 = i2(r2);
      t4 !== r2 && (ae(e2) || (e2[n2] = t4), r2 = t4);
    }
    t3[r2] = true;
  }
  return t3;
}
function _e(t3) {
  for (let e2 = 0; e2 < t3.length; e2++) {
    Ne(t3, e2) || (t3[e2] = null);
  }
  return t3;
}
function je(t3) {
  const e2 = de(null);
  for (const [i2, n2] of oe(t3)) {
    Ne(t3, i2) && (ye(n2) ? e2[i2] = _e(n2) : n2 && "object" == typeof n2 && n2.constructor === Object ? e2[i2] = je(n2) : e2[i2] = n2);
  }
  return e2;
}
function We(t3, e2) {
  for (; null !== t3; ) {
    const i2 = ce(t3, e2);
    if (i2) {
      if (i2.get) return Me(i2.get);
      if ("function" == typeof i2.value) return Me(i2.value);
    }
    t3 = le(t3);
  }
  return function() {
    return null;
  };
}
const Ue = ue(["a", "abbr", "acronym", "address", "area", "article", "aside", "audio", "b", "bdi", "bdo", "big", "blink", "blockquote", "body", "br", "button", "canvas", "caption", "center", "cite", "code", "col", "colgroup", "content", "data", "datalist", "dd", "decorator", "del", "details", "dfn", "dialog", "dir", "div", "dl", "dt", "element", "em", "fieldset", "figcaption", "figure", "font", "footer", "form", "h1", "h2", "h3", "h4", "h5", "h6", "head", "header", "hgroup", "hr", "html", "i", "img", "input", "ins", "kbd", "label", "legend", "li", "main", "map", "mark", "marquee", "menu", "menuitem", "meter", "nav", "nobr", "ol", "optgroup", "option", "output", "p", "picture", "pre", "progress", "q", "rp", "rt", "ruby", "s", "samp", "search", "section", "select", "shadow", "slot", "small", "source", "spacer", "span", "strike", "strong", "style", "sub", "summary", "sup", "table", "tbody", "td", "template", "textarea", "tfoot", "th", "thead", "time", "tr", "track", "tt", "u", "ul", "var", "video", "wbr"]), Ve = ue(["svg", "a", "altglyph", "altglyphdef", "altglyphitem", "animatecolor", "animatemotion", "animatetransform", "circle", "clippath", "defs", "desc", "ellipse", "enterkeyhint", "exportparts", "filter", "font", "g", "glyph", "glyphref", "hkern", "image", "inputmode", "line", "lineargradient", "marker", "mask", "metadata", "mpath", "part", "path", "pattern", "polygon", "polyline", "radialgradient", "rect", "stop", "style", "switch", "symbol", "text", "textpath", "title", "tref", "tspan", "view", "vkern"]), ze = ue(["feBlend", "feColorMatrix", "feComponentTransfer", "feComposite", "feConvolveMatrix", "feDiffuseLighting", "feDisplacementMap", "feDistantLight", "feDropShadow", "feFlood", "feFuncA", "feFuncB", "feFuncG", "feFuncR", "feGaussianBlur", "feImage", "feMerge", "feMergeNode", "feMorphology", "feOffset", "fePointLight", "feSpecularLighting", "feSpotLight", "feTile", "feTurbulence"]), He = ue(["animate", "color-profile", "cursor", "discard", "font-face", "font-face-format", "font-face-name", "font-face-src", "font-face-uri", "foreignobject", "hatch", "hatchpath", "mesh", "meshgradient", "meshpatch", "meshrow", "missing-glyph", "script", "set", "solidcolor", "unknown", "use"]), qe = ue(["math", "menclose", "merror", "mfenced", "mfrac", "mglyph", "mi", "mlabeledtr", "mmultiscripts", "mn", "mo", "mover", "mpadded", "mphantom", "mroot", "mrow", "ms", "mspace", "msqrt", "mstyle", "msub", "msup", "msubsup", "mtable", "mtd", "mtext", "mtr", "munder", "munderover", "mprescripts"]), Je = ue(["maction", "maligngroup", "malignmark", "mlongdiv", "mscarries", "mscarry", "msgroup", "mstack", "msline", "msrow", "semantics", "annotation", "annotation-xml", "mprescripts", "none"]), Ke = ue(["#text"]), Ge = ue(["accept", "action", "align", "alt", "autocapitalize", "autocomplete", "autopictureinpicture", "autoplay", "background", "bgcolor", "border", "capture", "cellpadding", "cellspacing", "checked", "cite", "class", "clear", "color", "cols", "colspan", "controls", "controlslist", "coords", "crossorigin", "datetime", "decoding", "default", "dir", "disabled", "disablepictureinpicture", "disableremoteplayback", "download", "draggable", "enctype", "enterkeyhint", "exportparts", "face", "for", "headers", "height", "hidden", "high", "href", "hreflang", "id", "inert", "inputmode", "integrity", "ismap", "kind", "label", "lang", "list", "loading", "loop", "low", "max", "maxlength", "media", "method", "min", "minlength", "multiple", "muted", "name", "nonce", "noshade", "novalidate", "nowrap", "open", "optimum", "part", "pattern", "placeholder", "playsinline", "popover", "popovertarget", "popovertargetaction", "poster", "preload", "pubdate", "radiogroup", "readonly", "rel", "required", "rev", "reversed", "role", "rows", "rowspan", "spellcheck", "scope", "selected", "shape", "size", "sizes", "slot", "span", "srclang", "start", "src", "srcset", "step", "style", "summary", "tabindex", "title", "translate", "type", "usemap", "valign", "value", "width", "wrap", "xmlns"]), Xe = ue(["accent-height", "accumulate", "additive", "alignment-baseline", "amplitude", "ascent", "attributename", "attributetype", "azimuth", "basefrequency", "baseline-shift", "begin", "bias", "by", "class", "clip", "clippathunits", "clip-path", "clip-rule", "color", "color-interpolation", "color-interpolation-filters", "color-profile", "color-rendering", "cx", "cy", "d", "dx", "dy", "diffuseconstant", "direction", "display", "divisor", "dur", "edgemode", "elevation", "end", "exponent", "fill", "fill-opacity", "fill-rule", "filter", "filterunits", "flood-color", "flood-opacity", "font-family", "font-size", "font-size-adjust", "font-stretch", "font-style", "font-variant", "font-weight", "fx", "fy", "g1", "g2", "glyph-name", "glyphref", "gradientunits", "gradienttransform", "height", "href", "id", "image-rendering", "in", "in2", "intercept", "k", "k1", "k2", "k3", "k4", "kerning", "keypoints", "keysplines", "keytimes", "lang", "lengthadjust", "letter-spacing", "kernelmatrix", "kernelunitlength", "lighting-color", "local", "marker-end", "marker-mid", "marker-start", "markerheight", "markerunits", "markerwidth", "maskcontentunits", "maskunits", "max", "mask", "mask-type", "media", "method", "mode", "min", "name", "numoctaves", "offset", "operator", "opacity", "order", "orient", "orientation", "origin", "overflow", "paint-order", "path", "pathlength", "patterncontentunits", "patterntransform", "patternunits", "points", "preservealpha", "preserveaspectratio", "primitiveunits", "r", "rx", "ry", "radius", "refx", "refy", "repeatcount", "repeatdur", "restart", "result", "rotate", "scale", "seed", "shape-rendering", "slope", "specularconstant", "specularexponent", "spreadmethod", "startoffset", "stddeviation", "stitchtiles", "stop-color", "stop-opacity", "stroke-dasharray", "stroke-dashoffset", "stroke-linecap", "stroke-linejoin", "stroke-miterlimit", "stroke-opacity", "stroke", "stroke-width", "style", "surfacescale", "systemlanguage", "tabindex", "tablevalues", "targetx", "targety", "transform", "transform-origin", "text-anchor", "text-decoration", "text-rendering", "textlength", "type", "u1", "u2", "unicode", "values", "viewbox", "visibility", "version", "vert-adv-y", "vert-origin-x", "vert-origin-y", "width", "word-spacing", "wrap", "writing-mode", "xchannelselector", "ychannelselector", "x", "x1", "x2", "xmlns", "y", "y1", "y2", "z", "zoomandpan"]), Ye = ue(["accent", "accentunder", "align", "bevelled", "close", "columnalign", "columnlines", "columnspacing", "columnspan", "denomalign", "depth", "dir", "display", "displaystyle", "encoding", "fence", "frame", "height", "href", "id", "largeop", "length", "linethickness", "lquote", "lspace", "mathbackground", "mathcolor", "mathsize", "mathvariant", "maxsize", "minsize", "movablelimits", "notation", "numalign", "open", "rowalign", "rowlines", "rowspacing", "rowspan", "rspace", "rquote", "scriptlevel", "scriptminsize", "scriptsizemultiplier", "selection", "separator", "separators", "stretchy", "subscriptshift", "supscriptshift", "symmetric", "voffset", "width", "xmlns"]), $e = ue(["xlink:href", "xml:id", "xlink:title", "xml:space", "xmlns:xlink"]), Ze = he(/\{\{[\w\W]*|[\w\W]*\}\}/gm), Qe = he(/<%[\w\W]*|[\w\W]*%>/gm), ti = he(/\$\{[\w\W]*/gm), ei = he(/^data-[\-\w.\u00B7-\uFFFF]+$/), ii = he(/^aria-[\-\w]+$/), ni = he(/^(?:(?:(?:f|ht)tps?|mailto|tel|callto|sms|cid|xmpp|matrix):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i), ri = he(/^(?:\w+script|data):/i), oi = he(/[\u0000-\u0020\u00A0\u1680\u180E\u2000-\u2029\u205F\u3000]/g), si = he(/^html$/i), ai = he(/^[a-z][.\w]*(-[.\w]+)+$/i);
var li = Object.freeze({ __proto__: null, ARIA_ATTR: ii, ATTR_WHITESPACE: oi, CUSTOM_ELEMENT: ai, DATA_ATTR: ei, DOCTYPE_NAME: si, ERB_EXPR: Qe, IS_ALLOWED_URI: ni, IS_SCRIPT_OR_DATA: ri, MUSTACHE_EXPR: Ze, TMPLIT_EXPR: ti });
const ci = 1, ui = 3, hi = 7, di = 8, gi = 9, mi = function() {
  return "undefined" == typeof window ? null : window;
};
var pi = (function t2() {
  let e2 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : mi();
  const i2 = (e3) => t2(e3);
  if (i2.version = "3.4.2", i2.removed = [], !e2 || !e2.document || e2.document.nodeType !== gi || !e2.Element) return i2.isSupported = false, i2;
  let { document: n2 } = e2;
  const r2 = n2, o2 = r2.currentScript, { DocumentFragment: s2, HTMLTemplateElement: a2, Node: l2, Element: c2, NodeFilter: u2, NamedNodeMap: h2 = e2.NamedNodeMap || e2.MozNamedAttrMap, HTMLFormElement: d2, DOMParser: g2, trustedTypes: m2 } = e2, p2 = c2.prototype, f2 = We(p2, "cloneNode"), b2 = We(p2, "remove"), v2 = We(p2, "nextSibling"), A2 = We(p2, "childNodes"), y2 = We(p2, "parentNode");
  if ("function" == typeof a2) {
    const t3 = n2.createElement("template");
    t3.content && t3.content.ownerDocument && (n2 = t3.content.ownerDocument);
  }
  let x2, C2 = "";
  const { implementation: E2, createNodeIterator: S2, createDocumentFragment: R2, getElementsByTagName: T2 } = n2, { importNode: k2 } = r2;
  let w2 = { afterSanitizeAttributes: [], afterSanitizeElements: [], afterSanitizeShadowDOM: [], beforeSanitizeAttributes: [], beforeSanitizeElements: [], beforeSanitizeShadowDOM: [], uponSanitizeAttribute: [], uponSanitizeElement: [], uponSanitizeShadowNode: [] };
  i2.isSupported = "function" == typeof oe && "function" == typeof y2 && E2 && void 0 !== E2.createHTMLDocument;
  const { MUSTACHE_EXPR: D2, ERB_EXPR: L2, TMPLIT_EXPR: N2, DATA_ATTR: I2, ARIA_ATTR: O2, IS_SCRIPT_OR_DATA: F2, ATTR_WHITESPACE: P2, CUSTOM_ELEMENT: M2 } = li;
  let { IS_ALLOWED_URI: B2 } = li, _2 = null;
  const j2 = Be({}, [...Ue, ...Ve, ...ze, ...qe, ...Ke]);
  let W2 = null;
  const U2 = Be({}, [...Ge, ...Xe, ...Ye, ...$e]);
  let V2 = Object.seal(de(null, { tagNameCheck: { writable: true, configurable: false, enumerable: true, value: null }, attributeNameCheck: { writable: true, configurable: false, enumerable: true, value: null }, allowCustomizedBuiltInElements: { writable: true, configurable: false, enumerable: true, value: false } })), z2 = null, H2 = null;
  const q2 = Object.seal(de(null, { tagCheck: { writable: true, configurable: false, enumerable: true, value: null }, attributeCheck: { writable: true, configurable: false, enumerable: true, value: null } }));
  let J2 = true, K2 = true, G2 = false, X2 = true, Y2 = false, $2 = true, Z2 = false, Q2 = false, tt2 = false, et2 = false, it2 = false, nt2 = false, rt2 = true, ot2 = false;
  const st2 = "user-content-";
  let at2 = true, lt2 = false, ct2 = {}, ut2 = null;
  const ht2 = Be({}, ["annotation-xml", "audio", "colgroup", "desc", "foreignobject", "head", "iframe", "math", "mi", "mn", "mo", "ms", "mtext", "noembed", "noframes", "noscript", "plaintext", "script", "style", "svg", "template", "thead", "title", "video", "xmp"]);
  let dt2 = null;
  const gt2 = Be({}, ["audio", "video", "img", "source", "image", "track"]);
  let mt2 = null;
  const pt2 = Be({}, ["alt", "class", "for", "id", "label", "name", "pattern", "placeholder", "role", "summary", "title", "value", "style", "xmlns"]), ft2 = "http://www.w3.org/1998/Math/MathML", bt2 = "http://www.w3.org/2000/svg", vt2 = "http://www.w3.org/1999/xhtml";
  let At2 = vt2, yt2 = false, xt2 = null;
  const Ct2 = Be({}, [ft2, bt2, vt2], Ce);
  let Et2 = Be({}, ["mi", "mo", "mn", "ms", "mtext"]), St2 = Be({}, ["annotation-xml"]);
  const Rt2 = Be({}, ["title", "style", "font", "a", "script"]);
  let Tt2 = null;
  const kt2 = ["application/xhtml+xml", "text/html"];
  let wt2 = null, Dt2 = null;
  const Lt2 = n2.createElement("form"), Nt2 = function(t3) {
    return t3 instanceof RegExp || t3 instanceof Function;
  }, It2 = function() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {};
    if (Dt2 && Dt2 === t3) return;
    t3 && "object" == typeof t3 || (t3 = {}), t3 = je(t3), Tt2 = -1 === kt2.indexOf(t3.PARSER_MEDIA_TYPE) ? "text/html" : t3.PARSER_MEDIA_TYPE, wt2 = "application/xhtml+xml" === Tt2 ? Ce : xe, _2 = Ne(t3, "ALLOWED_TAGS") && ye(t3.ALLOWED_TAGS) ? Be({}, t3.ALLOWED_TAGS, wt2) : j2, W2 = Ne(t3, "ALLOWED_ATTR") && ye(t3.ALLOWED_ATTR) ? Be({}, t3.ALLOWED_ATTR, wt2) : U2, xt2 = Ne(t3, "ALLOWED_NAMESPACES") && ye(t3.ALLOWED_NAMESPACES) ? Be({}, t3.ALLOWED_NAMESPACES, Ce) : Ct2, mt2 = Ne(t3, "ADD_URI_SAFE_ATTR") && ye(t3.ADD_URI_SAFE_ATTR) ? Be(je(pt2), t3.ADD_URI_SAFE_ATTR, wt2) : pt2, dt2 = Ne(t3, "ADD_DATA_URI_TAGS") && ye(t3.ADD_DATA_URI_TAGS) ? Be(je(gt2), t3.ADD_DATA_URI_TAGS, wt2) : gt2, ut2 = Ne(t3, "FORBID_CONTENTS") && ye(t3.FORBID_CONTENTS) ? Be({}, t3.FORBID_CONTENTS, wt2) : ht2, z2 = Ne(t3, "FORBID_TAGS") && ye(t3.FORBID_TAGS) ? Be({}, t3.FORBID_TAGS, wt2) : je({}), H2 = Ne(t3, "FORBID_ATTR") && ye(t3.FORBID_ATTR) ? Be({}, t3.FORBID_ATTR, wt2) : je({}), ct2 = !!Ne(t3, "USE_PROFILES") && (t3.USE_PROFILES && "object" == typeof t3.USE_PROFILES ? je(t3.USE_PROFILES) : t3.USE_PROFILES), J2 = false !== t3.ALLOW_ARIA_ATTR, K2 = false !== t3.ALLOW_DATA_ATTR, G2 = t3.ALLOW_UNKNOWN_PROTOCOLS || false, X2 = false !== t3.ALLOW_SELF_CLOSE_IN_ATTR, Y2 = t3.SAFE_FOR_TEMPLATES || false, $2 = false !== t3.SAFE_FOR_XML, Z2 = t3.WHOLE_DOCUMENT || false, et2 = t3.RETURN_DOM || false, it2 = t3.RETURN_DOM_FRAGMENT || false, nt2 = t3.RETURN_TRUSTED_TYPE || false, tt2 = t3.FORCE_BODY || false, rt2 = false !== t3.SANITIZE_DOM, ot2 = t3.SANITIZE_NAMED_PROPS || false, at2 = false !== t3.KEEP_CONTENT, lt2 = t3.IN_PLACE || false, B2 = (function(t4) {
      try {
        return Oe(t4, ""), true;
      } catch (t5) {
        return false;
      }
    })(t3.ALLOWED_URI_REGEXP) ? t3.ALLOWED_URI_REGEXP : ni, At2 = "string" == typeof t3.NAMESPACE ? t3.NAMESPACE : vt2, Et2 = Ne(t3, "MATHML_TEXT_INTEGRATION_POINTS") && t3.MATHML_TEXT_INTEGRATION_POINTS && "object" == typeof t3.MATHML_TEXT_INTEGRATION_POINTS ? je(t3.MATHML_TEXT_INTEGRATION_POINTS) : Be({}, ["mi", "mo", "mn", "ms", "mtext"]), St2 = Ne(t3, "HTML_INTEGRATION_POINTS") && t3.HTML_INTEGRATION_POINTS && "object" == typeof t3.HTML_INTEGRATION_POINTS ? je(t3.HTML_INTEGRATION_POINTS) : Be({}, ["annotation-xml"]);
    const e3 = Ne(t3, "CUSTOM_ELEMENT_HANDLING") && t3.CUSTOM_ELEMENT_HANDLING && "object" == typeof t3.CUSTOM_ELEMENT_HANDLING ? je(t3.CUSTOM_ELEMENT_HANDLING) : de(null);
    if (V2 = de(null), Ne(e3, "tagNameCheck") && Nt2(e3.tagNameCheck) && (V2.tagNameCheck = e3.tagNameCheck), Ne(e3, "attributeNameCheck") && Nt2(e3.attributeNameCheck) && (V2.attributeNameCheck = e3.attributeNameCheck), Ne(e3, "allowCustomizedBuiltInElements") && "boolean" == typeof e3.allowCustomizedBuiltInElements && (V2.allowCustomizedBuiltInElements = e3.allowCustomizedBuiltInElements), Y2 && (K2 = false), it2 && (et2 = true), ct2 && (_2 = Be({}, Ke), W2 = de(null), true === ct2.html && (Be(_2, Ue), Be(W2, Ge)), true === ct2.svg && (Be(_2, Ve), Be(W2, Xe), Be(W2, $e)), true === ct2.svgFilters && (Be(_2, ze), Be(W2, Xe), Be(W2, $e)), true === ct2.mathMl && (Be(_2, qe), Be(W2, Ye), Be(W2, $e))), q2.tagCheck = null, q2.attributeCheck = null, Ne(t3, "ADD_TAGS") && ("function" == typeof t3.ADD_TAGS ? q2.tagCheck = t3.ADD_TAGS : ye(t3.ADD_TAGS) && (_2 === j2 && (_2 = je(_2)), Be(_2, t3.ADD_TAGS, wt2))), Ne(t3, "ADD_ATTR") && ("function" == typeof t3.ADD_ATTR ? q2.attributeCheck = t3.ADD_ATTR : ye(t3.ADD_ATTR) && (W2 === U2 && (W2 = je(W2)), Be(W2, t3.ADD_ATTR, wt2))), Ne(t3, "ADD_URI_SAFE_ATTR") && ye(t3.ADD_URI_SAFE_ATTR) && Be(mt2, t3.ADD_URI_SAFE_ATTR, wt2), Ne(t3, "FORBID_CONTENTS") && ye(t3.FORBID_CONTENTS) && (ut2 === ht2 && (ut2 = je(ut2)), Be(ut2, t3.FORBID_CONTENTS, wt2)), Ne(t3, "ADD_FORBID_CONTENTS") && ye(t3.ADD_FORBID_CONTENTS) && (ut2 === ht2 && (ut2 = je(ut2)), Be(ut2, t3.ADD_FORBID_CONTENTS, wt2)), at2 && (_2["#text"] = true), Z2 && Be(_2, ["html", "head", "body"]), _2.table && (Be(_2, ["tbody"]), delete z2.tbody), t3.TRUSTED_TYPES_POLICY) {
      if ("function" != typeof t3.TRUSTED_TYPES_POLICY.createHTML) throw Fe('TRUSTED_TYPES_POLICY configuration option must provide a "createHTML" hook.');
      if ("function" != typeof t3.TRUSTED_TYPES_POLICY.createScriptURL) throw Fe('TRUSTED_TYPES_POLICY configuration option must provide a "createScriptURL" hook.');
      x2 = t3.TRUSTED_TYPES_POLICY, C2 = x2.createHTML("");
    } else void 0 === x2 && (x2 = (function(t4, e4) {
      if ("object" != typeof t4 || "function" != typeof t4.createPolicy) return null;
      let i3 = null;
      const n3 = "data-tt-policy-suffix";
      e4 && e4.hasAttribute(n3) && (i3 = e4.getAttribute(n3));
      const r3 = "dompurify" + (i3 ? "#" + i3 : "");
      try {
        return t4.createPolicy(r3, { createHTML: (t5) => t5, createScriptURL: (t5) => t5 });
      } catch (t5) {
        return console.warn("TrustedTypes policy " + r3 + " could not be created."), null;
      }
    })(m2, o2)), null !== x2 && "string" == typeof C2 && (C2 = x2.createHTML(""));
    ue && ue(t3), Dt2 = t3;
  }, Ot2 = Be({}, [...Ve, ...ze, ...He]), Ft2 = Be({}, [...qe, ...Je]), Pt2 = function(t3) {
    ve(i2.removed, { element: t3 });
    try {
      y2(t3).removeChild(t3);
    } catch (e3) {
      b2(t3);
    }
  }, Mt2 = function(t3, e3) {
    try {
      ve(i2.removed, { attribute: e3.getAttributeNode(t3), from: e3 });
    } catch (t4) {
      ve(i2.removed, { attribute: null, from: e3 });
    }
    if (e3.removeAttribute(t3), "is" === t3) if (et2 || it2) try {
      Pt2(e3);
    } catch (t4) {
    }
    else try {
      e3.setAttribute(t3, "");
    } catch (t4) {
    }
  }, Bt2 = function(t3) {
    let e3 = null, i3 = null;
    if (tt2) t3 = "<remove></remove>" + t3;
    else {
      const e4 = Ee(t3, /^[\r\n\t ]+/);
      i3 = e4 && e4[0];
    }
    "application/xhtml+xml" === Tt2 && At2 === vt2 && (t3 = '<html xmlns="http://www.w3.org/1999/xhtml"><head></head><body>' + t3 + "</body></html>");
    const r3 = x2 ? x2.createHTML(t3) : t3;
    if (At2 === vt2) try {
      e3 = new g2().parseFromString(r3, Tt2);
    } catch (t4) {
    }
    if (!e3 || !e3.documentElement) {
      e3 = E2.createDocument(At2, "template", null);
      try {
        e3.documentElement.innerHTML = yt2 ? C2 : r3;
      } catch (t4) {
      }
    }
    const o3 = e3.body || e3.documentElement;
    return t3 && i3 && o3.insertBefore(n2.createTextNode(i3), o3.childNodes[0] || null), At2 === vt2 ? T2.call(e3, Z2 ? "html" : "body")[0] : Z2 ? e3.documentElement : o3;
  }, _t2 = function(t3) {
    return S2.call(t3.ownerDocument || t3, t3, u2.SHOW_ELEMENT | u2.SHOW_COMMENT | u2.SHOW_TEXT | u2.SHOW_PROCESSING_INSTRUCTION | u2.SHOW_CDATA_SECTION, null);
  }, jt2 = function(t3) {
    return t3 instanceof d2 && ("string" != typeof t3.nodeName || "string" != typeof t3.textContent || "function" != typeof t3.removeChild || !(t3.attributes instanceof h2) || "function" != typeof t3.removeAttribute || "function" != typeof t3.setAttribute || "string" != typeof t3.namespaceURI || "function" != typeof t3.insertBefore || "function" != typeof t3.hasChildNodes);
  }, Wt2 = function(t3) {
    return "function" == typeof l2 && t3 instanceof l2;
  };
  function Ut2(t3, e3, n3) {
    pe(t3, ((t4) => {
      t4.call(i2, e3, n3, Dt2);
    }));
  }
  const Vt2 = function(t3) {
    let e3 = null;
    if (Ut2(w2.beforeSanitizeElements, t3, null), jt2(t3)) return Pt2(t3), true;
    const n3 = wt2(t3.nodeName);
    if (Ut2(w2.uponSanitizeElement, t3, { tagName: n3, allowedTags: _2 }), $2 && t3.hasChildNodes() && !Wt2(t3.firstElementChild) && Oe(/<[/\w!]/g, t3.innerHTML) && Oe(/<[/\w!]/g, t3.textContent)) return Pt2(t3), true;
    if ($2 && t3.namespaceURI === vt2 && "style" === n3 && Wt2(t3.firstElementChild)) return Pt2(t3), true;
    if (t3.nodeType === hi) return Pt2(t3), true;
    if ($2 && t3.nodeType === di && Oe(/<[/\w]/g, t3.data)) return Pt2(t3), true;
    if (z2[n3] || !(q2.tagCheck instanceof Function && q2.tagCheck(n3)) && !_2[n3]) {
      if (!z2[n3] && qt2(n3)) {
        if (V2.tagNameCheck instanceof RegExp && Oe(V2.tagNameCheck, n3)) return false;
        if (V2.tagNameCheck instanceof Function && V2.tagNameCheck(n3)) return false;
      }
      if (at2 && !ut2[n3]) {
        const e4 = y2(t3) || t3.parentNode, i3 = A2(t3) || t3.childNodes;
        if (i3 && e4) {
          for (let n4 = i3.length - 1; n4 >= 0; --n4) {
            const r3 = f2(i3[n4], true);
            e4.insertBefore(r3, v2(t3));
          }
        }
      }
      return Pt2(t3), true;
    }
    return t3 instanceof c2 && !(function(t4) {
      let e4 = y2(t4);
      e4 && e4.tagName || (e4 = { namespaceURI: At2, tagName: "template" });
      const i3 = xe(t4.tagName), n4 = xe(e4.tagName);
      return !!xt2[t4.namespaceURI] && (t4.namespaceURI === bt2 ? e4.namespaceURI === vt2 ? "svg" === i3 : e4.namespaceURI === ft2 ? "svg" === i3 && ("annotation-xml" === n4 || Et2[n4]) : Boolean(Ot2[i3]) : t4.namespaceURI === ft2 ? e4.namespaceURI === vt2 ? "math" === i3 : e4.namespaceURI === bt2 ? "math" === i3 && St2[n4] : Boolean(Ft2[i3]) : t4.namespaceURI === vt2 ? !(e4.namespaceURI === bt2 && !St2[n4]) && !(e4.namespaceURI === ft2 && !Et2[n4]) && !Ft2[i3] && (Rt2[i3] || !Ot2[i3]) : !("application/xhtml+xml" !== Tt2 || !xt2[t4.namespaceURI]));
    })(t3) ? (Pt2(t3), true) : "noscript" !== n3 && "noembed" !== n3 && "noframes" !== n3 || !Oe(/<\/no(script|embed|frames)/i, t3.innerHTML) ? (Y2 && t3.nodeType === ui && (e3 = t3.textContent, pe([D2, L2, N2], ((t4) => {
      e3 = Se(e3, t4, " ");
    })), t3.textContent !== e3 && (ve(i2.removed, { element: t3.cloneNode() }), t3.textContent = e3)), Ut2(w2.afterSanitizeElements, t3, null), false) : (Pt2(t3), true);
  }, zt2 = function(t3, e3, i3) {
    if (H2[e3]) return false;
    if (rt2 && ("id" === e3 || "name" === e3) && (i3 in n2 || i3 in Lt2)) return false;
    const r3 = W2[e3] || q2.attributeCheck instanceof Function && q2.attributeCheck(e3, t3);
    if (K2 && !H2[e3] && Oe(I2, e3)) ;
    else if (J2 && Oe(O2, e3)) ;
    else if (!r3 || H2[e3]) {
      if (!(qt2(t3) && (V2.tagNameCheck instanceof RegExp && Oe(V2.tagNameCheck, t3) || V2.tagNameCheck instanceof Function && V2.tagNameCheck(t3)) && (V2.attributeNameCheck instanceof RegExp && Oe(V2.attributeNameCheck, e3) || V2.attributeNameCheck instanceof Function && V2.attributeNameCheck(e3, t3)) || "is" === e3 && V2.allowCustomizedBuiltInElements && (V2.tagNameCheck instanceof RegExp && Oe(V2.tagNameCheck, i3) || V2.tagNameCheck instanceof Function && V2.tagNameCheck(i3)))) return false;
    } else if (mt2[e3]) ;
    else if (Oe(B2, Se(i3, P2, ""))) ;
    else if ("src" !== e3 && "xlink:href" !== e3 && "href" !== e3 || "script" === t3 || 0 !== Re(i3, "data:") || !dt2[t3]) {
      if (G2 && !Oe(F2, Se(i3, P2, ""))) ;
      else if (i3) return false;
    } else ;
    return true;
  }, Ht2 = Be({}, ["annotation-xml", "color-profile", "font-face", "font-face-format", "font-face-name", "font-face-src", "font-face-uri", "missing-glyph"]), qt2 = function(t3) {
    return !Ht2[xe(t3)] && Oe(M2, t3);
  }, Jt2 = function(t3) {
    Ut2(w2.beforeSanitizeAttributes, t3, null);
    const { attributes: e3 } = t3;
    if (!e3 || jt2(t3)) return;
    const n3 = { attrName: "", attrValue: "", keepAttr: true, allowedAttributes: W2, forceKeepAttr: void 0 };
    let r3 = e3.length;
    for (; r3--; ) {
      const o3 = e3[r3], { name: s3, namespaceURI: a3, value: l3 } = o3, c3 = wt2(s3), u3 = l3;
      let h3 = "value" === s3 ? u3 : Te(u3);
      if (n3.attrName = c3, n3.attrValue = h3, n3.keepAttr = true, n3.forceKeepAttr = void 0, Ut2(w2.uponSanitizeAttribute, t3, n3), h3 = n3.attrValue, !ot2 || "id" !== c3 && "name" !== c3 || 0 === Re(h3, st2) || (Mt2(s3, t3), h3 = st2 + h3), $2 && Oe(/((--!?|])>)|<\/(style|script|title|xmp|textarea|noscript|iframe|noembed|noframes)/i, h3)) {
        Mt2(s3, t3);
        continue;
      }
      if ("attributename" === c3 && Ee(h3, "href")) {
        Mt2(s3, t3);
        continue;
      }
      if (n3.forceKeepAttr) continue;
      if (!n3.keepAttr) {
        Mt2(s3, t3);
        continue;
      }
      if (!X2 && Oe(/\/>/i, h3)) {
        Mt2(s3, t3);
        continue;
      }
      Y2 && pe([D2, L2, N2], ((t4) => {
        h3 = Se(h3, t4, " ");
      }));
      const d3 = wt2(t3.nodeName);
      if (zt2(d3, c3, h3)) {
        if (x2 && "object" == typeof m2 && "function" == typeof m2.getAttributeType) if (a3) ;
        else switch (m2.getAttributeType(d3, c3)) {
          case "TrustedHTML":
            h3 = x2.createHTML(h3);
            break;
          case "TrustedScriptURL":
            h3 = x2.createScriptURL(h3);
        }
        if (h3 !== u3) try {
          a3 ? t3.setAttributeNS(a3, s3, h3) : t3.setAttribute(s3, h3), jt2(t3) ? Pt2(t3) : be(i2.removed);
        } catch (e4) {
          Mt2(s3, t3);
        }
      } else Mt2(s3, t3);
    }
    Ut2(w2.afterSanitizeAttributes, t3, null);
  }, Kt2 = function(t3) {
    let e3 = null;
    const i3 = _t2(t3);
    for (Ut2(w2.beforeSanitizeShadowDOM, t3, null); e3 = i3.nextNode(); ) Ut2(w2.uponSanitizeShadowNode, e3, null), Vt2(e3), Jt2(e3), e3.content instanceof s2 && Kt2(e3.content);
    Ut2(w2.afterSanitizeShadowDOM, t3, null);
  };
  return i2.sanitize = function(t3) {
    let e3 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {}, n3 = null, o3 = null, a3 = null, c3 = null;
    if (yt2 = !t3, yt2 && (t3 = "<!-->"), "string" != typeof t3 && !Wt2(t3) && "string" != typeof (t3 = (function(t4) {
      switch (typeof t4) {
        case "string":
          return t4;
        case "number":
          return ke(t4);
        case "boolean":
          return we(t4);
        case "bigint":
          return De ? De(t4) : "0";
        case "symbol":
          return Le ? Le(t4) : "Symbol()";
        case "undefined":
        default:
          return Ie(t4);
        case "function":
        case "object": {
          if (null === t4) return Ie(t4);
          const e4 = t4, i3 = We(e4, "toString");
          if ("function" == typeof i3) {
            const t5 = i3(e4);
            return "string" == typeof t5 ? t5 : Ie(t5);
          }
          return Ie(t4);
        }
      }
    })(t3))) throw Fe("dirty is not a string, aborting");
    if (!i2.isSupported) return t3;
    if (Q2 || It2(e3), i2.removed = [], "string" == typeof t3 && (lt2 = false), lt2) {
      const e4 = t3.nodeName;
      if ("string" == typeof e4) {
        const t4 = wt2(e4);
        if (!_2[t4] || z2[t4]) throw Fe("root node is forbidden and cannot be sanitized in-place");
      }
    } else if (t3 instanceof l2) n3 = Bt2("<!---->"), o3 = n3.ownerDocument.importNode(t3, true), o3.nodeType === ci && "BODY" === o3.nodeName || "HTML" === o3.nodeName ? n3 = o3 : n3.appendChild(o3);
    else {
      if (!et2 && !Y2 && !Z2 && -1 === t3.indexOf("<")) return x2 && nt2 ? x2.createHTML(t3) : t3;
      if (n3 = Bt2(t3), !n3) return et2 ? null : nt2 ? C2 : "";
    }
    n3 && tt2 && Pt2(n3.firstChild);
    const u3 = _t2(lt2 ? t3 : n3);
    for (; a3 = u3.nextNode(); ) Vt2(a3), Jt2(a3), a3.content instanceof s2 && Kt2(a3.content);
    if (lt2) return t3;
    if (et2) {
      if (Y2) {
        n3.normalize();
        let t4 = n3.innerHTML;
        pe([D2, L2, N2], ((e4) => {
          t4 = Se(t4, e4, " ");
        })), n3.innerHTML = t4;
      }
      if (it2) for (c3 = R2.call(n3.ownerDocument); n3.firstChild; ) c3.appendChild(n3.firstChild);
      else c3 = n3;
      return (W2.shadowroot || W2.shadowrootmode) && (c3 = k2.call(r2, c3, true)), c3;
    }
    let h3 = Z2 ? n3.outerHTML : n3.innerHTML;
    return Z2 && _2["!doctype"] && n3.ownerDocument && n3.ownerDocument.doctype && n3.ownerDocument.doctype.name && Oe(si, n3.ownerDocument.doctype.name) && (h3 = "<!DOCTYPE " + n3.ownerDocument.doctype.name + ">\n" + h3), Y2 && pe([D2, L2, N2], ((t4) => {
      h3 = Se(h3, t4, " ");
    })), x2 && nt2 ? x2.createHTML(h3) : h3;
  }, i2.setConfig = function() {
    It2(arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {}), Q2 = true;
  }, i2.clearConfig = function() {
    Dt2 = null, Q2 = false;
  }, i2.isValidAttribute = function(t3, e3, i3) {
    Dt2 || It2({});
    const n3 = wt2(t3), r3 = wt2(e3);
    return zt2(n3, r3, i3);
  }, i2.addHook = function(t3, e3) {
    "function" == typeof e3 && ve(w2[t3], e3);
  }, i2.removeHook = function(t3, e3) {
    if (void 0 !== e3) {
      const i3 = fe(w2[t3], e3);
      return -1 === i3 ? void 0 : Ae(w2[t3], i3, 1)[0];
    }
    return be(w2[t3]);
  }, i2.removeHooks = function(t3) {
    w2[t3] = [];
  }, i2.removeAllHooks = function() {
    w2 = { afterSanitizeAttributes: [], afterSanitizeElements: [], afterSanitizeShadowDOM: [], beforeSanitizeAttributes: [], beforeSanitizeElements: [], beforeSanitizeShadowDOM: [], uponSanitizeAttribute: [], uponSanitizeElement: [], uponSanitizeShadowNode: [] };
  }, i2;
})();
pi.addHook("uponSanitizeAttribute", (function(t3, e2) {
  if ("data-trix-serialized-attributes" === e2.attrName) return void (e2.keepAttr = false);
  /^data-trix-/.test(e2.attrName) && (e2.forceKeepAttr = true);
}));
const fi = "style href src width height language class".split(" "), bi = "javascript:".split(" "), vi = "script iframe form noscript".split(" ");
class Ai extends q {
  static setHTML(t3, e2, i2) {
    const n2 = new this(e2, i2).sanitize(), r2 = n2.getHTML ? n2.getHTML() : n2.outerHTML;
    t3.innerHTML = r2;
  }
  static sanitize(t3, e2) {
    const i2 = new this(t3, e2);
    return i2.sanitize(), i2;
  }
  constructor(t3) {
    let { allowedAttributes: e2, forbiddenProtocols: i2, forbiddenElements: n2, purifyOptions: r2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    super(...arguments), this.allowedAttributes = e2 || fi, this.forbiddenProtocols = i2 || bi, this.forbiddenElements = n2 || vi, this.purifyOptions = r2 || {}, this.body = yi(t3);
  }
  sanitize() {
    this.sanitizeElements(), this.normalizeListElementNesting();
    const t3 = Object.assign({}, l, this.purifyOptions);
    return pi.setConfig(t3), this.body = pi.sanitize(this.body), this.body;
  }
  getHTML() {
    return this.body.innerHTML;
  }
  getBody() {
    return this.body;
  }
  sanitizeElements() {
    const t3 = T(this.body), e2 = [];
    for (; t3.nextNode(); ) {
      const i2 = t3.currentNode;
      switch (i2.nodeType) {
        case Node.ELEMENT_NODE:
          this.elementIsRemovable(i2) ? e2.push(i2) : this.sanitizeElement(i2);
          break;
        case Node.COMMENT_NODE:
          e2.push(i2);
      }
    }
    return e2.forEach(((t4) => R(t4))), this.body;
  }
  sanitizeElement(t3) {
    return t3.hasAttribute("href") && this.forbiddenProtocols.includes(t3.protocol) && t3.removeAttribute("href"), Array.from(t3.attributes).forEach(((e2) => {
      let { name: i2 } = e2;
      this.allowedAttributes.includes(i2) || 0 === i2.indexOf("data-trix") || t3.removeAttribute(i2);
    })), t3;
  }
  normalizeListElementNesting() {
    return Array.from(this.body.querySelectorAll("ul,ol")).forEach(((t3) => {
      const e2 = t3.previousElementSibling;
      e2 && "li" === k(e2) && e2.appendChild(t3);
    })), this.body;
  }
  elementIsRemovable(t3) {
    if ((null == t3 ? void 0 : t3.nodeType) === Node.ELEMENT_NODE) return this.elementIsForbidden(t3) || this.elementIsntSerializable(t3);
  }
  elementIsForbidden(t3) {
    return this.forbiddenElements.includes(k(t3));
  }
  elementIsntSerializable(t3) {
    return "false" === t3.getAttribute("data-trix-serialize") && !M(t3);
  }
}
const yi = function() {
  let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : "";
  t3 = t3.replace(/<\/html[^>]*>[^]*$/i, "</html>");
  const e2 = document.implementation.createHTMLDocument("");
  return e2.documentElement.innerHTML = t3, Array.from(e2.head.querySelectorAll("style")).forEach(((t4) => {
    e2.body.appendChild(t4);
  })), e2.body;
}, { css: xi } = H;
class Ci extends ne {
  constructor() {
    super(...arguments), this.attachment = this.object, this.attachment.uploadProgressDelegate = this, this.attachmentPiece = this.options.piece;
  }
  createContentNodes() {
    return [];
  }
  createNodes() {
    let t3;
    const e2 = t3 = w({ tagName: "figure", className: this.getClassName(), data: this.getData(), editable: false }), i2 = this.getHref();
    return i2 && (t3 = w({ tagName: "a", editable: false, attributes: { href: i2, tabindex: -1 } }), e2.appendChild(t3)), this.attachment.hasContent() ? Ai.setHTML(t3, this.attachment.getContent()) : this.createContentNodes().forEach(((e3) => {
      t3.appendChild(e3);
    })), t3.appendChild(this.createCaptionElement()), this.attachment.isPending() && (this.progressElement = w({ tagName: "progress", attributes: { class: xi.attachmentProgress, value: this.attachment.getUploadProgress(), max: 100 }, data: { trixMutable: true, trixStoreKey: ["progressElement", this.attachment.id].join("/") } }), e2.appendChild(this.progressElement)), [Ei("left"), e2, Ei("right")];
  }
  createCaptionElement() {
    const t3 = w({ tagName: "figcaption", className: xi.attachmentCaption }), e2 = this.attachmentPiece.getCaption();
    if (e2) t3.classList.add("".concat(xi.attachmentCaption, "--edited")), t3.textContent = e2;
    else {
      let e3, i2;
      const n2 = this.getCaptionConfig();
      if (n2.name && (e3 = this.attachment.getFilename()), n2.size && (i2 = this.attachment.getFormattedFilesize()), e3) {
        const i3 = w({ tagName: "span", className: xi.attachmentName, textContent: e3 });
        t3.appendChild(i3);
      }
      if (i2) {
        e3 && t3.appendChild(document.createTextNode(" "));
        const n3 = w({ tagName: "span", className: xi.attachmentSize, textContent: i2 });
        t3.appendChild(n3);
      }
    }
    return t3;
  }
  getClassName() {
    const t3 = [xi.attachment, "".concat(xi.attachment, "--").concat(this.attachment.getType())], e2 = this.attachment.getExtension();
    return e2 && t3.push("".concat(xi.attachment, "--").concat(e2)), t3.join(" ");
  }
  getData() {
    const t3 = { trixAttachment: JSON.stringify(this.attachment), trixContentType: this.attachment.getContentType(), trixId: this.attachment.id }, { attributes: e2 } = this.attachmentPiece;
    return e2.isEmpty() || (t3.trixAttributes = JSON.stringify(e2)), this.attachment.isPending() && (t3.trixSerialize = false), t3;
  }
  getHref() {
    if (!Si(this.attachment.getContent(), "a")) {
      const t3 = this.attachment.getHref();
      if (t3 && pi.isValidAttribute("a", "href", t3)) return t3;
    }
  }
  getCaptionConfig() {
    var t3;
    const e2 = this.attachment.getType(), n2 = kt(null === (t3 = i[e2]) || void 0 === t3 ? void 0 : t3.caption);
    return "file" === e2 && (n2.name = true), n2;
  }
  findProgressElement() {
    var t3;
    return null === (t3 = this.findElement()) || void 0 === t3 ? void 0 : t3.querySelector("progress");
  }
  attachmentDidChangeUploadProgress() {
    const t3 = this.attachment.getUploadProgress(), e2 = this.findProgressElement();
    e2 && (e2.value = t3);
  }
}
const Ei = (t3) => w({ tagName: "span", textContent: d, data: { trixCursorTarget: t3, trixSerialize: false } }), Si = function(t3, e2) {
  const i2 = w("div");
  return Ai.setHTML(i2, t3 || ""), i2.querySelector(e2);
};
class Ri extends Ci {
  constructor() {
    super(...arguments), this.attachment.previewDelegate = this;
  }
  createContentNodes() {
    return this.image = w({ tagName: "img", attributes: { src: "" }, data: { trixMutable: true } }), this.refresh(this.image), [this.image];
  }
  createCaptionElement() {
    const t3 = super.createCaptionElement(...arguments);
    return t3.textContent || t3.setAttribute("data-trix-placeholder", c.captionPlaceholder), t3;
  }
  refresh(t3) {
    var e2;
    t3 || (t3 = null === (e2 = this.findElement()) || void 0 === e2 ? void 0 : e2.querySelector("img"));
    if (t3) return this.updateAttributesForImage(t3);
  }
  updateAttributesForImage(t3) {
    const e2 = this.attachment.getURL(), i2 = this.attachment.getPreviewURL();
    if (t3.src = i2 || e2, i2 === e2) t3.removeAttribute("data-trix-serialized-attributes");
    else {
      const i3 = JSON.stringify({ src: e2 });
      t3.setAttribute("data-trix-serialized-attributes", i3);
    }
    const n2 = this.attachment.getWidth(), r2 = this.attachment.getHeight(), o2 = this.attachment.getAttribute("alt");
    null != n2 && (t3.width = n2), null != r2 && (t3.height = r2), null != o2 && (t3.alt = o2);
    const s2 = ["imageElement", this.attachment.id, t3.src, t3.width, t3.height].join("/");
    t3.dataset.trixStoreKey = s2;
  }
  attachmentDidChangeAttributes() {
    return this.refresh(this.image), this.refresh();
  }
}
class Ti extends ne {
  constructor() {
    super(...arguments), this.piece = this.object, this.attributes = this.piece.getAttributes(), this.textConfig = this.options.textConfig, this.context = this.options.context, this.piece.attachment ? this.attachment = this.piece.attachment : this.string = this.piece.toString();
  }
  createNodes() {
    let t3 = this.attachment ? this.createAttachmentNodes() : this.createStringNodes();
    const e2 = this.createElement();
    if (e2) {
      const i2 = (function(t4) {
        for (; null !== (e3 = t4) && void 0 !== e3 && e3.firstElementChild; ) {
          var e3;
          t4 = t4.firstElementChild;
        }
        return t4;
      })(e2);
      Array.from(t3).forEach(((t4) => {
        i2.appendChild(t4);
      })), t3 = [e2];
    }
    return t3;
  }
  createAttachmentNodes() {
    const t3 = this.attachment.isPreviewable() ? Ri : Ci;
    return this.createChildView(t3, this.piece.attachment, { piece: this.piece }).getNodes();
  }
  createStringNodes() {
    var t3;
    if (null !== (t3 = this.textConfig) && void 0 !== t3 && t3.plaintext) return [document.createTextNode(this.string)];
    {
      const t4 = [], e2 = this.string.split("\n");
      for (let i2 = 0; i2 < e2.length; i2++) {
        const n2 = e2[i2];
        if (i2 > 0) {
          const e3 = w("br");
          t4.push(e3);
        }
        if (n2.length) {
          const e3 = document.createTextNode(this.preserveSpaces(n2));
          t4.push(e3);
        }
      }
      return t4;
    }
  }
  createElement() {
    let t3, e2, i2;
    const n2 = {};
    for (e2 in this.attributes) {
      i2 = this.attributes[e2];
      const o2 = bt(e2);
      if (o2) {
        if (o2.tagName) {
          var r2;
          const e3 = w(o2.tagName);
          r2 ? (r2.appendChild(e3), r2 = e3) : t3 = r2 = e3;
        }
        if (o2.styleProperty && (n2[o2.styleProperty] = i2), o2.style) for (e2 in o2.style) i2 = o2.style[e2], n2[e2] = i2;
      }
    }
    if (Object.keys(n2).length) for (e2 in t3 || (t3 = w("span")), n2) i2 = n2[e2], t3.style[e2] = i2;
    return t3;
  }
  createContainerElement() {
    for (const t3 in this.attributes) {
      const e2 = this.attributes[t3], i2 = bt(t3);
      if (i2 && i2.groupTagName) {
        const n2 = {};
        return n2[t3] = e2, w(i2.groupTagName, n2);
      }
    }
  }
  preserveSpaces(t3) {
    return this.context.isLast && (t3 = t3.replace(/\ $/, g)), t3 = t3.replace(/(\S)\ {3}(\S)/g, "$1 ".concat(g, " $2")).replace(/\ {2}/g, "".concat(g, " ")).replace(/\ {2}/g, " ".concat(g)), (this.context.isFirst || this.context.followsWhitespace) && (t3 = t3.replace(/^\ /, g)), t3;
  }
}
class ki extends ne {
  constructor() {
    super(...arguments), this.text = this.object, this.textConfig = this.options.textConfig;
  }
  createNodes() {
    const t3 = [], e2 = Zt.groupObjects(this.getPieces()), i2 = e2.length - 1;
    for (let r2 = 0; r2 < e2.length; r2++) {
      const o2 = e2[r2], s2 = {};
      0 === r2 && (s2.isFirst = true), r2 === i2 && (s2.isLast = true), wi(n2) && (s2.followsWhitespace = true);
      const a2 = this.findOrCreateCachedChildView(Ti, o2, { textConfig: this.textConfig, context: s2 });
      t3.push(...Array.from(a2.getNodes() || []));
      var n2 = o2;
    }
    return t3;
  }
  getPieces() {
    return Array.from(this.text.getPieces()).filter(((t3) => !t3.hasAttribute("blockBreak")));
  }
}
const wi = (t3) => /\s$/.test(null == t3 ? void 0 : t3.toString()), { css: Di } = H;
class Li extends ne {
  constructor() {
    super(...arguments), this.block = this.object, this.attributes = this.block.getAttributes();
  }
  createNodes() {
    const t3 = [document.createComment("block")];
    if (this.block.isEmpty()) t3.push(w("br"));
    else {
      var e2;
      const i2 = null === (e2 = pt(this.block.getLastAttribute())) || void 0 === e2 ? void 0 : e2.text, n2 = this.findOrCreateCachedChildView(ki, this.block.text, { textConfig: i2 });
      t3.push(...Array.from(n2.getNodes() || [])), this.shouldAddExtraNewlineElement() && t3.push(w("br"));
    }
    if (this.attributes.length) return t3;
    {
      let e3;
      const { tagName: i2 } = n.default;
      this.block.isRTL() && (e3 = { dir: "rtl" });
      const r2 = w({ tagName: i2, attributes: e3 });
      return t3.forEach(((t4) => r2.appendChild(t4))), [r2];
    }
  }
  createContainerElement(t3) {
    const e2 = {};
    let i2;
    const n2 = this.attributes[t3], { tagName: r2, htmlAttributes: o2 = [] } = pt(n2);
    if (0 === t3 && this.block.isRTL() && Object.assign(e2, { dir: "rtl" }), "attachmentGallery" === n2) {
      const t4 = this.block.getBlockBreakPosition();
      i2 = "".concat(Di.attachmentGallery, " ").concat(Di.attachmentGallery, "--").concat(t4);
    }
    return Object.entries(this.block.htmlAttributes).forEach(((t4) => {
      let [i3, n3] = t4;
      o2.includes(i3) && (e2[i3] = n3);
    })), w({ tagName: r2, className: i2, attributes: e2 });
  }
  shouldAddExtraNewlineElement() {
    return /\n\n$/.test(this.block.toString());
  }
}
class Ni extends ne {
  static render(t3) {
    const e2 = w("div"), i2 = new this(t3, { element: e2 });
    return i2.render(), i2.sync(), e2;
  }
  constructor() {
    super(...arguments), this.element = this.options.element, this.elementStore = new te(), this.setDocument(this.object);
  }
  setDocument(t3) {
    t3.isEqualTo(this.document) || (this.document = this.object = t3);
  }
  render() {
    if (this.childViews = [], this.shadowElement = w("div"), !this.document.isEmpty()) {
      const t3 = Zt.groupObjects(this.document.getBlocks(), { asTree: true });
      Array.from(t3).forEach(((t4) => {
        const e2 = this.findOrCreateCachedChildView(Li, t4);
        Array.from(e2.getNodes()).map(((t5) => this.shadowElement.appendChild(t5)));
      }));
    }
  }
  isSynced() {
    return Oi(this.shadowElement, this.element);
  }
  sync() {
    const t3 = v("trix-before-render", { cancelable: false, attributes: { render: (t4, e3) => {
      for (; t4.lastChild; ) t4.removeChild(t4.lastChild);
      t4.appendChild(e3);
    } } });
    this.element.dispatchEvent(t3);
    const e2 = this.createDocumentFragmentForSync();
    return t3.render(this.element, e2), this.didSync();
  }
  didSync() {
    return this.elementStore.reset(Ii(this.element)), Tt((() => this.garbageCollectCachedViews()));
  }
  createDocumentFragmentForSync() {
    const t3 = document.createDocumentFragment();
    return Array.from(this.shadowElement.childNodes).forEach(((e2) => {
      t3.appendChild(e2.cloneNode(true));
    })), Array.from(Ii(t3)).forEach(((t4) => {
      const e2 = this.elementStore.remove(t4);
      e2 && t4.parentNode.replaceChild(e2, t4);
    })), t3;
  }
}
const Ii = (t3) => t3.querySelectorAll("[data-trix-store-key]"), Oi = (t3, e2) => Fi(t3.innerHTML) === Fi(e2.innerHTML), Fi = (t3) => t3.replace(/&nbsp;/g, " ");
function Bi(t3) {
  var e2 = (function(t4, e3) {
    if ("object" != typeof t4 || !t4) return t4;
    var i2 = t4[Symbol.toPrimitive];
    if (void 0 !== i2) {
      var n2 = i2.call(t4, e3);
      if ("object" != typeof n2) return n2;
      throw new TypeError("@@toPrimitive must return a primitive value.");
    }
    return ("string" === e3 ? String : Number)(t4);
  })(t3, "string");
  return "symbol" == typeof e2 ? e2 : String(e2);
}
function _i(t3, e2, i2) {
  return (e2 = Bi(e2)) in t3 ? Object.defineProperty(t3, e2, { value: i2, enumerable: true, configurable: true, writable: true }) : t3[e2] = i2, t3;
}
function ji(t3, e2) {
  return Vi(t3, Ui(t3, e2, "get"));
}
function Wi(t3, e2, i2) {
  return zi(t3, Ui(t3, e2, "set"), i2), i2;
}
function Ui(t3, e2, i2) {
  if (!e2.has(t3)) throw new TypeError("attempted to " + i2 + " private field on non-instance");
  return e2.get(t3);
}
function Vi(t3, e2) {
  return e2.get ? e2.get.call(t3) : e2.value;
}
function zi(t3, e2, i2) {
  if (e2.set) e2.set.call(t3, i2);
  else {
    if (!e2.writable) throw new TypeError("attempted to set read only private field");
    e2.value = i2;
  }
}
function Hi(t3, e2, i2) {
  if (!e2.has(t3)) throw new TypeError("attempted to get private field on non-instance");
  return i2;
}
function qi(t3, e2) {
  if (e2.has(t3)) throw new TypeError("Cannot initialize the same private elements twice on an object");
}
function Ji(t3, e2, i2) {
  qi(t3, e2), e2.set(t3, i2);
}
class Ki extends ot {
  static registerType(t3, e2) {
    e2.type = t3, this.types[t3] = e2;
  }
  static fromJSON(t3) {
    const e2 = this.types[t3.type];
    if (e2) return e2.fromJSON(t3);
  }
  constructor(t3) {
    let e2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    super(...arguments), this.attributes = Jt.box(e2);
  }
  copyWithAttributes(t3) {
    return new this.constructor(this.getValue(), t3);
  }
  copyWithAdditionalAttributes(t3) {
    return this.copyWithAttributes(this.attributes.merge(t3));
  }
  copyWithoutAttribute(t3) {
    return this.copyWithAttributes(this.attributes.remove(t3));
  }
  copy() {
    return this.copyWithAttributes(this.attributes);
  }
  getAttribute(t3) {
    return this.attributes.get(t3);
  }
  getAttributesHash() {
    return this.attributes;
  }
  getAttributes() {
    return this.attributes.toObject();
  }
  hasAttribute(t3) {
    return this.attributes.has(t3);
  }
  hasSameStringValueAsPiece(t3) {
    return t3 && this.toString() === t3.toString();
  }
  hasSameAttributesAsPiece(t3) {
    return t3 && (this.attributes === t3.attributes || this.attributes.isEqualTo(t3.attributes));
  }
  isBlockBreak() {
    return false;
  }
  isEqualTo(t3) {
    return super.isEqualTo(...arguments) || this.hasSameConstructorAs(t3) && this.hasSameStringValueAsPiece(t3) && this.hasSameAttributesAsPiece(t3);
  }
  isEmpty() {
    return 0 === this.length;
  }
  isSerializable() {
    return true;
  }
  toJSON() {
    return { type: this.constructor.type, attributes: this.getAttributes() };
  }
  contentsForInspection() {
    return { type: this.constructor.type, attributes: this.attributes.inspect() };
  }
  canBeGrouped() {
    return this.hasAttribute("href");
  }
  canBeGroupedWith(t3) {
    return this.getAttribute("href") === t3.getAttribute("href");
  }
  getLength() {
    return this.length;
  }
  canBeConsolidatedWith(t3) {
    return false;
  }
}
_i(Ki, "types", {});
class Gi extends ie {
  constructor(t3) {
    super(...arguments), this.url = t3;
  }
  perform(t3) {
    const e2 = new Image();
    e2.onload = () => (e2.width = this.width = e2.naturalWidth, e2.height = this.height = e2.naturalHeight, t3(true, e2)), e2.onerror = () => t3(false), e2.src = this.url;
  }
}
class Xi extends ot {
  static attachmentForFile(t3) {
    const e2 = new this(this.attributesForFile(t3));
    return e2.setFile(t3), e2;
  }
  static attributesForFile(t3) {
    return new Jt({ filename: t3.name, filesize: t3.size, contentType: t3.type });
  }
  static fromJSON(t3) {
    return new this(t3);
  }
  constructor() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {};
    super(t3), this.releaseFile = this.releaseFile.bind(this), this.attributes = Jt.box(t3), this.didChangeAttributes();
  }
  setAttribute(t3, e2) {
    this.setAttributes({ [t3]: e2 });
  }
  getAttribute(t3) {
    return this.attributes.get(t3);
  }
  hasAttribute(t3) {
    return this.attributes.has(t3);
  }
  getAttributes() {
    return this.attributes.toObject();
  }
  setAttributes() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {};
    const e2 = this.attributes.merge(t3);
    var i2, n2, r2, o2;
    if (!this.attributes.isEqualTo(e2)) return this.attributes = e2, this.didChangeAttributes(), null === (i2 = this.previewDelegate) || void 0 === i2 || null === (n2 = i2.attachmentDidChangeAttributes) || void 0 === n2 || n2.call(i2, this), null === (r2 = this.delegate) || void 0 === r2 || null === (o2 = r2.attachmentDidChangeAttributes) || void 0 === o2 ? void 0 : o2.call(r2, this);
  }
  didChangeAttributes() {
    if (this.isPreviewable()) return this.preloadURL();
  }
  isPending() {
    return null != this.file && !(this.getURL() || this.getHref());
  }
  isPreviewable() {
    return this.attributes.has("previewable") ? this.attributes.get("previewable") : Xi.previewablePattern.test(this.getContentType());
  }
  getType() {
    return this.hasContent() ? "content" : this.isPreviewable() ? "preview" : "file";
  }
  getURL() {
    return this.attributes.get("url");
  }
  getHref() {
    return this.attributes.get("href");
  }
  getFilename() {
    return this.attributes.get("filename") || "";
  }
  getFilesize() {
    return this.attributes.get("filesize");
  }
  getFormattedFilesize() {
    const t3 = this.attributes.get("filesize");
    return "number" == typeof t3 ? h.formatter(t3) : "";
  }
  getExtension() {
    var t3;
    return null === (t3 = this.getFilename().match(/\.(\w+)$/)) || void 0 === t3 ? void 0 : t3[1].toLowerCase();
  }
  getContentType() {
    return this.attributes.get("contentType");
  }
  hasContent() {
    return this.attributes.has("content");
  }
  getContent() {
    return this.attributes.get("content");
  }
  getWidth() {
    return this.attributes.get("width");
  }
  getHeight() {
    return this.attributes.get("height");
  }
  getFile() {
    return this.file;
  }
  setFile(t3) {
    if (this.file = t3, this.isPreviewable()) return this.preloadFile();
  }
  releaseFile() {
    this.releasePreloadedFile(), this.file = null;
  }
  getUploadProgress() {
    return null != this.uploadProgress ? this.uploadProgress : 0;
  }
  setUploadProgress(t3) {
    var e2, i2;
    if (this.uploadProgress !== t3) return this.uploadProgress = t3, null === (e2 = this.uploadProgressDelegate) || void 0 === e2 || null === (i2 = e2.attachmentDidChangeUploadProgress) || void 0 === i2 ? void 0 : i2.call(e2, this);
  }
  toJSON() {
    return this.getAttributes();
  }
  getCacheKey() {
    return [super.getCacheKey(...arguments), this.attributes.getCacheKey(), this.getPreviewURL()].join("/");
  }
  getPreviewURL() {
    return this.previewURL || this.preloadingURL;
  }
  setPreviewURL(t3) {
    var e2, i2, n2, r2;
    if (t3 !== this.getPreviewURL()) return this.previewURL = t3, null === (e2 = this.previewDelegate) || void 0 === e2 || null === (i2 = e2.attachmentDidChangeAttributes) || void 0 === i2 || i2.call(e2, this), null === (n2 = this.delegate) || void 0 === n2 || null === (r2 = n2.attachmentDidChangePreviewURL) || void 0 === r2 ? void 0 : r2.call(n2, this);
  }
  preloadURL() {
    return this.preload(this.getURL(), this.releaseFile);
  }
  preloadFile() {
    if (this.file) return this.fileObjectURL = URL.createObjectURL(this.file), this.preload(this.fileObjectURL);
  }
  releasePreloadedFile() {
    this.fileObjectURL && (URL.revokeObjectURL(this.fileObjectURL), this.fileObjectURL = null);
  }
  preload(t3, e2) {
    if (t3 && t3 !== this.getPreviewURL()) {
      this.preloadingURL = t3;
      return new Gi(t3).then(((i2) => {
        let { width: n2, height: r2 } = i2;
        return this.getWidth() && this.getHeight() || this.setAttributes({ width: n2, height: r2 }), this.preloadingURL = null, this.setPreviewURL(t3), null == e2 ? void 0 : e2();
      })).catch((() => (this.preloadingURL = null, null == e2 ? void 0 : e2())));
    }
  }
}
_i(Xi, "previewablePattern", /^image(\/(gif|png|webp|jpe?g)|$)/);
class Yi extends Ki {
  static fromJSON(t3) {
    return new this(Xi.fromJSON(t3.attachment), t3.attributes);
  }
  constructor(t3) {
    super(...arguments), this.attachment = t3, this.length = 1, this.ensureAttachmentExclusivelyHasAttribute("href"), this.attachment.hasContent() || this.removeProhibitedAttributes();
  }
  ensureAttachmentExclusivelyHasAttribute(t3) {
    this.hasAttribute(t3) && (this.attachment.hasAttribute(t3) || this.attachment.setAttributes(this.attributes.slice([t3])), this.attributes = this.attributes.remove(t3));
  }
  removeProhibitedAttributes() {
    const t3 = this.attributes.slice(Yi.permittedAttributes);
    t3.isEqualTo(this.attributes) || (this.attributes = t3);
  }
  getValue() {
    return this.attachment;
  }
  isSerializable() {
    return !this.attachment.isPending();
  }
  getCaption() {
    return this.attributes.get("caption") || "";
  }
  isEqualTo(t3) {
    var e2;
    return super.isEqualTo(t3) && this.attachment.id === (null == t3 || null === (e2 = t3.attachment) || void 0 === e2 ? void 0 : e2.id);
  }
  toString() {
    return "￼";
  }
  toJSON() {
    const t3 = super.toJSON(...arguments);
    return t3.attachment = this.attachment, t3;
  }
  getCacheKey() {
    return [super.getCacheKey(...arguments), this.attachment.getCacheKey()].join("/");
  }
  toConsole() {
    return JSON.stringify(this.toString());
  }
}
_i(Yi, "permittedAttributes", ["caption", "presentation"]), Ki.registerType("attachment", Yi);
class $i extends Ki {
  static fromJSON(t3) {
    const e2 = { ...t3.attributes };
    return e2.href && !pi.isValidAttribute("a", "href", e2.href) && delete e2.href, new this(t3.string, e2);
  }
  constructor(t3) {
    super(...arguments), this.string = ((t4) => t4.replace(/\r\n?/g, "\n"))(t3), this.length = this.string.length;
  }
  getValue() {
    return this.string;
  }
  toString() {
    return this.string.toString();
  }
  isBlockBreak() {
    return "\n" === this.toString() && true === this.getAttribute("blockBreak");
  }
  toJSON() {
    const t3 = super.toJSON(...arguments);
    return t3.string = this.string, t3;
  }
  canBeConsolidatedWith(t3) {
    return t3 && this.hasSameConstructorAs(t3) && this.hasSameAttributesAsPiece(t3);
  }
  consolidateWith(t3) {
    return new this.constructor(this.toString() + t3.toString(), this.attributes);
  }
  splitAtOffset(t3) {
    let e2, i2;
    return 0 === t3 ? (e2 = null, i2 = this) : t3 === this.length ? (e2 = this, i2 = null) : (e2 = new this.constructor(this.string.slice(0, t3), this.attributes), i2 = new this.constructor(this.string.slice(t3), this.attributes)), [e2, i2];
  }
  toConsole() {
    let { string: t3 } = this;
    return t3.length > 15 && (t3 = t3.slice(0, 14) + "…"), JSON.stringify(t3.toString());
  }
}
Ki.registerType("string", $i);
class Zi extends ot {
  static box(t3) {
    return t3 instanceof this ? t3 : new this(t3);
  }
  constructor() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
    super(...arguments), this.objects = t3.slice(0), this.length = this.objects.length;
  }
  indexOf(t3) {
    return this.objects.indexOf(t3);
  }
  splice() {
    for (var t3 = arguments.length, e2 = new Array(t3), i2 = 0; i2 < t3; i2++) e2[i2] = arguments[i2];
    return new this.constructor(at(this.objects, ...e2));
  }
  eachObject(t3) {
    return this.objects.map(((e2, i2) => t3(e2, i2)));
  }
  insertObjectAtIndex(t3, e2) {
    return this.splice(e2, 0, t3);
  }
  insertSplittableListAtIndex(t3, e2) {
    return this.splice(e2, 0, ...t3.objects);
  }
  insertSplittableListAtPosition(t3, e2) {
    const [i2, n2] = this.splitObjectAtPosition(e2);
    return new this.constructor(i2).insertSplittableListAtIndex(t3, n2);
  }
  editObjectAtIndex(t3, e2) {
    return this.replaceObjectAtIndex(e2(this.objects[t3]), t3);
  }
  replaceObjectAtIndex(t3, e2) {
    return this.splice(e2, 1, t3);
  }
  removeObjectAtIndex(t3) {
    return this.splice(t3, 1);
  }
  getObjectAtIndex(t3) {
    return this.objects[t3];
  }
  getSplittableListInRange(t3) {
    const [e2, i2, n2] = this.splitObjectsAtRange(t3);
    return new this.constructor(e2.slice(i2, n2 + 1));
  }
  selectSplittableList(t3) {
    const e2 = this.objects.filter(((e3) => t3(e3)));
    return new this.constructor(e2);
  }
  removeObjectsInRange(t3) {
    const [e2, i2, n2] = this.splitObjectsAtRange(t3);
    return new this.constructor(e2).splice(i2, n2 - i2 + 1);
  }
  transformObjectsInRange(t3, e2) {
    const [i2, n2, r2] = this.splitObjectsAtRange(t3), o2 = i2.map(((t4, i3) => n2 <= i3 && i3 <= r2 ? e2(t4) : t4));
    return new this.constructor(o2);
  }
  splitObjectsAtRange(t3) {
    let e2, [i2, n2, r2] = this.splitObjectAtPosition(tn(t3));
    return [i2, e2] = new this.constructor(i2).splitObjectAtPosition(en(t3) + r2), [i2, n2, e2 - 1];
  }
  getObjectAtPosition(t3) {
    const { index: e2 } = this.findIndexAndOffsetAtPosition(t3);
    return this.objects[e2];
  }
  splitObjectAtPosition(t3) {
    let e2, i2;
    const { index: n2, offset: r2 } = this.findIndexAndOffsetAtPosition(t3), o2 = this.objects.slice(0);
    if (null != n2) if (0 === r2) e2 = n2, i2 = 0;
    else {
      const t4 = this.getObjectAtIndex(n2), [s2, a2] = t4.splitAtOffset(r2);
      o2.splice(n2, 1, s2, a2), e2 = n2 + 1, i2 = s2.getLength() - r2;
    }
    else e2 = o2.length, i2 = 0;
    return [o2, e2, i2];
  }
  consolidate() {
    const t3 = [];
    let e2 = this.objects[0];
    return this.objects.slice(1).forEach(((i2) => {
      var n2, r2;
      null !== (n2 = (r2 = e2).canBeConsolidatedWith) && void 0 !== n2 && n2.call(r2, i2) ? e2 = e2.consolidateWith(i2) : (t3.push(e2), e2 = i2);
    })), e2 && t3.push(e2), new this.constructor(t3);
  }
  consolidateFromIndexToIndex(t3, e2) {
    const i2 = this.objects.slice(0).slice(t3, e2 + 1), n2 = new this.constructor(i2).consolidate().toArray();
    return this.splice(t3, i2.length, ...n2);
  }
  findIndexAndOffsetAtPosition(t3) {
    let e2, i2 = 0;
    for (e2 = 0; e2 < this.objects.length; e2++) {
      const n2 = i2 + this.objects[e2].getLength();
      if (i2 <= t3 && t3 < n2) return { index: e2, offset: t3 - i2 };
      i2 = n2;
    }
    return { index: null, offset: null };
  }
  findPositionAtIndexAndOffset(t3, e2) {
    let i2 = 0;
    for (let n2 = 0; n2 < this.objects.length; n2++) {
      const r2 = this.objects[n2];
      if (n2 < t3) i2 += r2.getLength();
      else if (n2 === t3) {
        i2 += e2;
        break;
      }
    }
    return i2;
  }
  getEndPosition() {
    return null == this.endPosition && (this.endPosition = 0, this.objects.forEach(((t3) => this.endPosition += t3.getLength()))), this.endPosition;
  }
  toString() {
    return this.objects.join("");
  }
  toArray() {
    return this.objects.slice(0);
  }
  toJSON() {
    return this.toArray();
  }
  isEqualTo(t3) {
    return super.isEqualTo(...arguments) || Qi(this.objects, null == t3 ? void 0 : t3.objects);
  }
  contentsForInspection() {
    return { objects: "[".concat(this.objects.map(((t3) => t3.inspect())).join(", "), "]") };
  }
}
const Qi = function(t3) {
  let e2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : [];
  if (t3.length !== e2.length) return false;
  let i2 = true;
  for (let n2 = 0; n2 < t3.length; n2++) {
    const r2 = t3[n2];
    i2 && !r2.isEqualTo(e2[n2]) && (i2 = false);
  }
  return i2;
}, tn = (t3) => t3[0], en = (t3) => t3[1];
class nn extends ot {
  static textForAttachmentWithAttributes(t3, e2) {
    return new this([new Yi(t3, e2)]);
  }
  static textForStringWithAttributes(t3, e2) {
    return new this([new $i(t3, e2)]);
  }
  static fromJSON(t3) {
    return new this(Array.from(t3).map(((t4) => Ki.fromJSON(t4))));
  }
  constructor() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
    super(...arguments);
    const e2 = t3.filter(((t4) => !t4.isEmpty()));
    this.pieceList = new Zi(e2);
  }
  copy() {
    return this.copyWithPieceList(this.pieceList);
  }
  copyWithPieceList(t3) {
    return new this.constructor(t3.consolidate().toArray());
  }
  copyUsingObjectMap(t3) {
    const e2 = this.getPieces().map(((e3) => t3.find(e3) || e3));
    return new this.constructor(e2);
  }
  appendText(t3) {
    return this.insertTextAtPosition(t3, this.getLength());
  }
  insertTextAtPosition(t3, e2) {
    return this.copyWithPieceList(this.pieceList.insertSplittableListAtPosition(t3.pieceList, e2));
  }
  removeTextAtRange(t3) {
    return this.copyWithPieceList(this.pieceList.removeObjectsInRange(t3));
  }
  replaceTextAtRange(t3, e2) {
    return this.removeTextAtRange(e2).insertTextAtPosition(t3, e2[0]);
  }
  moveTextFromRangeToPosition(t3, e2) {
    if (t3[0] <= e2 && e2 <= t3[1]) return;
    const i2 = this.getTextAtRange(t3), n2 = i2.getLength();
    return t3[0] < e2 && (e2 -= n2), this.removeTextAtRange(t3).insertTextAtPosition(i2, e2);
  }
  addAttributeAtRange(t3, e2, i2) {
    const n2 = {};
    return n2[t3] = e2, this.addAttributesAtRange(n2, i2);
  }
  addAttributesAtRange(t3, e2) {
    return this.copyWithPieceList(this.pieceList.transformObjectsInRange(e2, ((e3) => e3.copyWithAdditionalAttributes(t3))));
  }
  removeAttributeAtRange(t3, e2) {
    return this.copyWithPieceList(this.pieceList.transformObjectsInRange(e2, ((e3) => e3.copyWithoutAttribute(t3))));
  }
  setAttributesAtRange(t3, e2) {
    return this.copyWithPieceList(this.pieceList.transformObjectsInRange(e2, ((e3) => e3.copyWithAttributes(t3))));
  }
  getAttributesAtPosition(t3) {
    var e2;
    return (null === (e2 = this.pieceList.getObjectAtPosition(t3)) || void 0 === e2 ? void 0 : e2.getAttributes()) || {};
  }
  getCommonAttributes() {
    const t3 = Array.from(this.pieceList.toArray()).map(((t4) => t4.getAttributes()));
    return Jt.fromCommonAttributesOfObjects(t3).toObject();
  }
  getCommonAttributesAtRange(t3) {
    return this.getTextAtRange(t3).getCommonAttributes() || {};
  }
  getExpandedRangeForAttributeAtOffset(t3, e2) {
    let i2, n2 = i2 = e2;
    const r2 = this.getLength();
    for (; n2 > 0 && this.getCommonAttributesAtRange([n2 - 1, i2])[t3]; ) n2--;
    for (; i2 < r2 && this.getCommonAttributesAtRange([e2, i2 + 1])[t3]; ) i2++;
    return [n2, i2];
  }
  getTextAtRange(t3) {
    return this.copyWithPieceList(this.pieceList.getSplittableListInRange(t3));
  }
  getStringAtRange(t3) {
    return this.pieceList.getSplittableListInRange(t3).toString();
  }
  getStringAtPosition(t3) {
    return this.getStringAtRange([t3, t3 + 1]);
  }
  startsWithString(t3) {
    return this.getStringAtRange([0, t3.length]) === t3;
  }
  endsWithString(t3) {
    const e2 = this.getLength();
    return this.getStringAtRange([e2 - t3.length, e2]) === t3;
  }
  getAttachmentPieces() {
    return this.pieceList.toArray().filter(((t3) => !!t3.attachment));
  }
  getAttachments() {
    return this.getAttachmentPieces().map(((t3) => t3.attachment));
  }
  getAttachmentAndPositionById(t3) {
    let e2 = 0;
    for (const n2 of this.pieceList.toArray()) {
      var i2;
      if ((null === (i2 = n2.attachment) || void 0 === i2 ? void 0 : i2.id) === t3) return { attachment: n2.attachment, position: e2 };
      e2 += n2.length;
    }
    return { attachment: null, position: null };
  }
  getAttachmentById(t3) {
    const { attachment: e2 } = this.getAttachmentAndPositionById(t3);
    return e2;
  }
  getRangeOfAttachment(t3) {
    const e2 = this.getAttachmentAndPositionById(t3.id), i2 = e2.position;
    if (t3 = e2.attachment) return [i2, i2 + 1];
  }
  updateAttributesForAttachment(t3, e2) {
    const i2 = this.getRangeOfAttachment(e2);
    return i2 ? this.addAttributesAtRange(t3, i2) : this;
  }
  getLength() {
    return this.pieceList.getEndPosition();
  }
  isEmpty() {
    return 0 === this.getLength();
  }
  isEqualTo(t3) {
    var e2;
    return super.isEqualTo(t3) || (null == t3 || null === (e2 = t3.pieceList) || void 0 === e2 ? void 0 : e2.isEqualTo(this.pieceList));
  }
  isBlockBreak() {
    return 1 === this.getLength() && this.pieceList.getObjectAtIndex(0).isBlockBreak();
  }
  eachPiece(t3) {
    return this.pieceList.eachObject(t3);
  }
  getPieces() {
    return this.pieceList.toArray();
  }
  getPieceAtPosition(t3) {
    return this.pieceList.getObjectAtPosition(t3);
  }
  contentsForInspection() {
    return { pieceList: this.pieceList.inspect() };
  }
  toSerializableText() {
    const t3 = this.pieceList.selectSplittableList(((t4) => t4.isSerializable()));
    return this.copyWithPieceList(t3);
  }
  toString() {
    return this.pieceList.toString();
  }
  toJSON() {
    return this.pieceList.toJSON();
  }
  toConsole() {
    return JSON.stringify(this.pieceList.toArray().map(((t3) => JSON.parse(t3.toConsole()))));
  }
  getDirection() {
    return ct(this.toString());
  }
  isRTL() {
    return "rtl" === this.getDirection();
  }
}
class rn extends ot {
  static fromJSON(t3) {
    return new this(nn.fromJSON(t3.text), t3.attributes, t3.htmlAttributes);
  }
  constructor(t3, e2, i2) {
    super(...arguments), this.text = on(t3 || new nn()), this.attributes = e2 || [], this.htmlAttributes = i2 || {};
  }
  isEmpty() {
    return this.text.isBlockBreak();
  }
  isEqualTo(t3) {
    return !!super.isEqualTo(t3) || this.text.isEqualTo(null == t3 ? void 0 : t3.text) && st(this.attributes, null == t3 ? void 0 : t3.attributes) && wt(this.htmlAttributes, null == t3 ? void 0 : t3.htmlAttributes);
  }
  copyWithText(t3) {
    return new rn(t3, this.attributes, this.htmlAttributes);
  }
  copyWithoutText() {
    return this.copyWithText(null);
  }
  copyWithAttributes(t3) {
    return new rn(this.text, t3, this.htmlAttributes);
  }
  copyWithoutAttributes() {
    return this.copyWithAttributes(null);
  }
  copyUsingObjectMap(t3) {
    const e2 = t3.find(this.text);
    return e2 ? this.copyWithText(e2) : this.copyWithText(this.text.copyUsingObjectMap(t3));
  }
  addAttribute(t3) {
    const e2 = this.attributes.concat(hn(t3));
    return this.copyWithAttributes(e2);
  }
  addHTMLAttribute(t3, e2) {
    const i2 = Object.assign({}, this.htmlAttributes, { [t3]: e2 });
    return new rn(this.text, this.attributes, i2);
  }
  removeAttribute(t3) {
    const { listAttribute: e2 } = pt(t3), i2 = gn(gn(this.attributes, t3), e2);
    return this.copyWithAttributes(i2);
  }
  removeLastAttribute() {
    return this.removeAttribute(this.getLastAttribute());
  }
  getLastAttribute() {
    return dn(this.attributes);
  }
  getAttributes() {
    return this.attributes.slice(0);
  }
  getAttributeLevel() {
    return this.attributes.length;
  }
  getAttributeAtLevel(t3) {
    return this.attributes[t3 - 1];
  }
  hasAttribute(t3) {
    return this.attributes.includes(t3);
  }
  hasAttributes() {
    return this.getAttributeLevel() > 0;
  }
  getLastNestableAttribute() {
    return dn(this.getNestableAttributes());
  }
  getNestableAttributes() {
    return this.attributes.filter(((t3) => pt(t3).nestable));
  }
  getNestingLevel() {
    return this.getNestableAttributes().length;
  }
  decreaseNestingLevel() {
    const t3 = this.getLastNestableAttribute();
    return t3 ? this.removeAttribute(t3) : this;
  }
  increaseNestingLevel() {
    const t3 = this.getLastNestableAttribute();
    if (t3) {
      const e2 = this.attributes.lastIndexOf(t3), i2 = at(this.attributes, e2 + 1, 0, ...hn(t3));
      return this.copyWithAttributes(i2);
    }
    return this;
  }
  getListItemAttributes() {
    return this.attributes.filter(((t3) => pt(t3).listAttribute));
  }
  isListItem() {
    var t3;
    return null === (t3 = pt(this.getLastAttribute())) || void 0 === t3 ? void 0 : t3.listAttribute;
  }
  isTerminalBlock() {
    var t3;
    return null === (t3 = pt(this.getLastAttribute())) || void 0 === t3 ? void 0 : t3.terminal;
  }
  breaksOnReturn() {
    var t3;
    return null === (t3 = pt(this.getLastAttribute())) || void 0 === t3 ? void 0 : t3.breakOnReturn;
  }
  findLineBreakInDirectionFromPosition(t3, e2) {
    const i2 = this.toString();
    let n2;
    switch (t3) {
      case "forward":
        n2 = i2.indexOf("\n", e2);
        break;
      case "backward":
        n2 = i2.slice(0, e2).lastIndexOf("\n");
    }
    if (-1 !== n2) return n2;
  }
  contentsForInspection() {
    return { text: this.text.inspect(), attributes: this.attributes };
  }
  toString() {
    return this.text.toString();
  }
  toJSON() {
    return { text: this.text, attributes: this.attributes, htmlAttributes: this.htmlAttributes };
  }
  getDirection() {
    return this.text.getDirection();
  }
  isRTL() {
    return this.text.isRTL();
  }
  getLength() {
    return this.text.getLength();
  }
  canBeConsolidatedWith(t3) {
    return !this.hasAttributes() && !t3.hasAttributes() && this.getDirection() === t3.getDirection();
  }
  consolidateWith(t3) {
    const e2 = nn.textForStringWithAttributes("\n"), i2 = this.getTextWithoutBlockBreak().appendText(e2);
    return this.copyWithText(i2.appendText(t3.text));
  }
  splitAtOffset(t3) {
    let e2, i2;
    return 0 === t3 ? (e2 = null, i2 = this) : t3 === this.getLength() ? (e2 = this, i2 = null) : (e2 = this.copyWithText(this.text.getTextAtRange([0, t3])), i2 = this.copyWithText(this.text.getTextAtRange([t3, this.getLength()]))), [e2, i2];
  }
  getBlockBreakPosition() {
    return this.text.getLength() - 1;
  }
  getTextWithoutBlockBreak() {
    return cn(this.text) ? this.text.getTextAtRange([0, this.getBlockBreakPosition()]) : this.text.copy();
  }
  canBeGrouped(t3) {
    return this.attributes[t3];
  }
  canBeGroupedWith(t3, e2) {
    const i2 = t3.getAttributes(), r2 = i2[e2], o2 = this.attributes[e2];
    return o2 === r2 && !(false === pt(o2).group && !(() => {
      if (!gt) {
        gt = [];
        for (const t4 in n) {
          const { listAttribute: e3 } = n[t4];
          null != e3 && gt.push(e3);
        }
      }
      return gt;
    })().includes(i2[e2 + 1])) && (this.getDirection() === t3.getDirection() || t3.isEmpty());
  }
}
const on = function(t3) {
  return t3 = sn(t3), t3 = ln(t3);
}, sn = function(t3) {
  let e2 = false;
  const i2 = t3.getPieces();
  let n2 = i2.slice(0, i2.length - 1);
  const r2 = i2[i2.length - 1];
  return r2 ? (n2 = n2.map(((t4) => t4.isBlockBreak() ? (e2 = true, un(t4)) : t4)), e2 ? new nn([...n2, r2]) : t3) : t3;
}, an = nn.textForStringWithAttributes("\n", { blockBreak: true }), ln = function(t3) {
  return cn(t3) ? t3 : t3.appendText(an);
}, cn = function(t3) {
  const e2 = t3.getLength();
  if (0 === e2) return false;
  return t3.getTextAtRange([e2 - 1, e2]).isBlockBreak();
}, un = (t3) => t3.copyWithoutAttribute("blockBreak"), hn = function(t3) {
  const { listAttribute: e2 } = pt(t3);
  return e2 ? [e2, t3] : [t3];
}, dn = (t3) => t3.slice(-1)[0], gn = function(t3, e2) {
  const i2 = t3.lastIndexOf(e2);
  return -1 === i2 ? t3 : at(t3, i2, 1);
};
class mn extends ot {
  static fromJSON(t3) {
    return new this(Array.from(t3).map(((t4) => rn.fromJSON(t4))));
  }
  static fromString(t3, e2) {
    const i2 = nn.textForStringWithAttributes(t3, e2);
    return new this([new rn(i2)]);
  }
  constructor() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
    super(...arguments), 0 === t3.length && (t3 = [new rn()]), this.blockList = Zi.box(t3);
  }
  isEmpty() {
    const t3 = this.getBlockAtIndex(0);
    return 1 === this.blockList.length && t3.isEmpty() && !t3.hasAttributes();
  }
  copy() {
    const t3 = (arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {}).consolidateBlocks ? this.blockList.consolidate().toArray() : this.blockList.toArray();
    return new this.constructor(t3);
  }
  copyUsingObjectsFromDocument(t3) {
    const e2 = new Qt(t3.getObjects());
    return this.copyUsingObjectMap(e2);
  }
  copyUsingObjectMap(t3) {
    const e2 = this.getBlocks().map(((e3) => t3.find(e3) || e3.copyUsingObjectMap(t3)));
    return new this.constructor(e2);
  }
  copyWithBaseBlockAttributes() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
    const e2 = this.getBlocks().map(((e3) => {
      const i2 = t3.concat(e3.getAttributes());
      return e3.copyWithAttributes(i2);
    }));
    return new this.constructor(e2);
  }
  replaceBlock(t3, e2) {
    const i2 = this.blockList.indexOf(t3);
    return -1 === i2 ? this : new this.constructor(this.blockList.replaceObjectAtIndex(e2, i2));
  }
  insertDocumentAtRange(t3, e2) {
    const { blockList: i2 } = t3;
    e2 = Dt(e2);
    let [n2] = e2;
    const { index: r2, offset: o2 } = this.locationFromPosition(n2);
    let s2 = this;
    const a2 = this.getBlockAtPosition(n2);
    return Lt(e2) && a2.isEmpty() && !a2.hasAttributes() ? s2 = new this.constructor(s2.blockList.removeObjectAtIndex(r2)) : a2.getBlockBreakPosition() === o2 && n2++, s2 = s2.removeTextAtRange(e2), new this.constructor(s2.blockList.insertSplittableListAtPosition(i2, n2));
  }
  mergeDocumentAtRange(t3, e2) {
    let i2, n2;
    e2 = Dt(e2);
    const [r2] = e2, o2 = this.locationFromPosition(r2), s2 = this.getBlockAtIndex(o2.index).getAttributes(), a2 = t3.getBaseBlockAttributes(), l2 = s2.slice(-a2.length);
    if (st(a2, l2)) {
      const e3 = s2.slice(0, -a2.length);
      i2 = t3.copyWithBaseBlockAttributes(e3);
    } else i2 = t3.copy({ consolidateBlocks: true }).copyWithBaseBlockAttributes(s2);
    const c2 = i2.getBlockCount(), u2 = i2.getBlockAtIndex(0);
    if (st(s2, u2.getAttributes())) {
      const t4 = u2.getTextWithoutBlockBreak();
      if (n2 = this.insertTextAtRange(t4, e2), c2 > 1) {
        i2 = new this.constructor(i2.getBlocks().slice(1));
        const e3 = r2 + t4.getLength();
        n2 = n2.insertDocumentAtRange(i2, e3);
      }
    } else n2 = this.insertDocumentAtRange(i2, e2);
    return n2;
  }
  insertTextAtRange(t3, e2) {
    e2 = Dt(e2);
    const [i2] = e2, { index: n2, offset: r2 } = this.locationFromPosition(i2), o2 = this.removeTextAtRange(e2);
    return new this.constructor(o2.blockList.editObjectAtIndex(n2, ((e3) => e3.copyWithText(e3.text.insertTextAtPosition(t3, r2)))));
  }
  removeTextAtRange(t3) {
    let e2;
    t3 = Dt(t3);
    const [i2, n2] = t3;
    if (Lt(t3)) return this;
    const [r2, o2] = Array.from(this.locationRangeFromRange(t3)), s2 = r2.index, a2 = r2.offset, l2 = this.getBlockAtIndex(s2), c2 = o2.index, u2 = o2.offset, h2 = this.getBlockAtIndex(c2);
    if (n2 - i2 == 1 && l2.getBlockBreakPosition() === a2 && h2.getBlockBreakPosition() !== u2 && "\n" === h2.text.getStringAtPosition(u2)) e2 = this.blockList.editObjectAtIndex(c2, ((t4) => t4.copyWithText(t4.text.removeTextAtRange([u2, u2 + 1]))));
    else {
      let t4;
      const i3 = l2.text.getTextAtRange([0, a2]), n3 = h2.text.getTextAtRange([u2, h2.getLength()]), r3 = i3.appendText(n3);
      t4 = s2 !== c2 && 0 === a2 && l2.getAttributeLevel() >= h2.getAttributeLevel() ? h2.copyWithText(r3) : l2.copyWithText(r3);
      const o3 = c2 + 1 - s2;
      e2 = this.blockList.splice(s2, o3, t4);
    }
    return new this.constructor(e2);
  }
  moveTextFromRangeToPosition(t3, e2) {
    let i2;
    t3 = Dt(t3);
    const [n2, r2] = t3;
    if (n2 <= e2 && e2 <= r2) return this;
    let o2 = this.getDocumentAtRange(t3), s2 = this.removeTextAtRange(t3);
    const a2 = n2 < e2;
    a2 && (e2 -= o2.getLength());
    const [l2, ...c2] = o2.getBlocks();
    return 0 === c2.length ? (i2 = l2.getTextWithoutBlockBreak(), a2 && (e2 += 1)) : i2 = l2.text, s2 = s2.insertTextAtRange(i2, e2), 0 === c2.length ? s2 : (o2 = new this.constructor(c2), e2 += i2.getLength(), s2.insertDocumentAtRange(o2, e2));
  }
  addAttributeAtRange(t3, e2, i2) {
    let { blockList: n2 } = this;
    return this.eachBlockAtRange(i2, ((i3, r2, o2) => n2 = n2.editObjectAtIndex(o2, (function() {
      return pt(t3) ? i3.addAttribute(t3, e2) : r2[0] === r2[1] ? i3 : i3.copyWithText(i3.text.addAttributeAtRange(t3, e2, r2));
    })))), new this.constructor(n2);
  }
  addAttribute(t3, e2) {
    let { blockList: i2 } = this;
    return this.eachBlock(((n2, r2) => i2 = i2.editObjectAtIndex(r2, (() => n2.addAttribute(t3, e2))))), new this.constructor(i2);
  }
  removeAttributeAtRange(t3, e2) {
    let { blockList: i2 } = this;
    return this.eachBlockAtRange(e2, (function(e3, n2, r2) {
      pt(t3) ? i2 = i2.editObjectAtIndex(r2, (() => e3.removeAttribute(t3))) : n2[0] !== n2[1] && (i2 = i2.editObjectAtIndex(r2, (() => e3.copyWithText(e3.text.removeAttributeAtRange(t3, n2)))));
    })), new this.constructor(i2);
  }
  updateAttributesForAttachment(t3, e2) {
    const i2 = this.getRangeOfAttachment(e2), [n2] = Array.from(i2), { index: r2 } = this.locationFromPosition(n2), o2 = this.getTextAtIndex(r2);
    return new this.constructor(this.blockList.editObjectAtIndex(r2, ((i3) => i3.copyWithText(o2.updateAttributesForAttachment(t3, e2)))));
  }
  removeAttributeForAttachment(t3, e2) {
    const i2 = this.getRangeOfAttachment(e2);
    return this.removeAttributeAtRange(t3, i2);
  }
  setHTMLAttributeAtPosition(t3, e2, i2) {
    const n2 = this.getBlockAtPosition(t3), r2 = n2.addHTMLAttribute(e2, i2);
    return this.replaceBlock(n2, r2);
  }
  insertBlockBreakAtRange(t3) {
    let e2;
    t3 = Dt(t3);
    const [i2] = t3, { offset: n2 } = this.locationFromPosition(i2), r2 = this.removeTextAtRange(t3);
    return 0 === n2 && (e2 = [new rn()]), new this.constructor(r2.blockList.insertSplittableListAtPosition(new Zi(e2), i2));
  }
  applyBlockAttributeAtRange(t3, e2, i2) {
    const n2 = this.expandRangeToLineBreaksAndSplitBlocks(i2);
    let r2 = n2.document;
    i2 = n2.range;
    const o2 = pt(t3);
    if (o2.listAttribute) {
      r2 = r2.removeLastListAttributeAtRange(i2, { exceptAttributeName: t3 });
      const e3 = r2.convertLineBreaksToBlockBreaksInRange(i2);
      r2 = e3.document, i2 = e3.range;
    } else r2 = o2.exclusive ? r2.removeBlockAttributesAtRange(i2) : o2.terminal ? r2.removeLastTerminalAttributeAtRange(i2) : r2.consolidateBlocksAtRange(i2);
    return r2.addAttributeAtRange(t3, e2, i2);
  }
  removeLastListAttributeAtRange(t3) {
    let e2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {}, { blockList: i2 } = this;
    return this.eachBlockAtRange(t3, (function(t4, n2, r2) {
      const o2 = t4.getLastAttribute();
      o2 && pt(o2).listAttribute && o2 !== e2.exceptAttributeName && (i2 = i2.editObjectAtIndex(r2, (() => t4.removeAttribute(o2))));
    })), new this.constructor(i2);
  }
  removeLastTerminalAttributeAtRange(t3) {
    let { blockList: e2 } = this;
    return this.eachBlockAtRange(t3, (function(t4, i2, n2) {
      const r2 = t4.getLastAttribute();
      r2 && pt(r2).terminal && (e2 = e2.editObjectAtIndex(n2, (() => t4.removeAttribute(r2))));
    })), new this.constructor(e2);
  }
  removeBlockAttributesAtRange(t3) {
    let { blockList: e2 } = this;
    return this.eachBlockAtRange(t3, (function(t4, i2, n2) {
      t4.hasAttributes() && (e2 = e2.editObjectAtIndex(n2, (() => t4.copyWithoutAttributes())));
    })), new this.constructor(e2);
  }
  expandRangeToLineBreaksAndSplitBlocks(t3) {
    let e2;
    t3 = Dt(t3);
    let [i2, n2] = t3;
    const r2 = this.locationFromPosition(i2), o2 = this.locationFromPosition(n2);
    let s2 = this;
    const a2 = s2.getBlockAtIndex(r2.index);
    if (r2.offset = a2.findLineBreakInDirectionFromPosition("backward", r2.offset), null != r2.offset && (e2 = s2.positionFromLocation(r2), s2 = s2.insertBlockBreakAtRange([e2, e2 + 1]), o2.index += 1, o2.offset -= s2.getBlockAtIndex(r2.index).getLength(), r2.index += 1), r2.offset = 0, 0 === o2.offset && o2.index > r2.index) o2.index -= 1, o2.offset = s2.getBlockAtIndex(o2.index).getBlockBreakPosition();
    else {
      const t4 = s2.getBlockAtIndex(o2.index);
      "\n" === t4.text.getStringAtRange([o2.offset - 1, o2.offset]) ? o2.offset -= 1 : o2.offset = t4.findLineBreakInDirectionFromPosition("forward", o2.offset), o2.offset !== t4.getBlockBreakPosition() && (e2 = s2.positionFromLocation(o2), s2 = s2.insertBlockBreakAtRange([e2, e2 + 1]));
    }
    return i2 = s2.positionFromLocation(r2), n2 = s2.positionFromLocation(o2), { document: s2, range: t3 = Dt([i2, n2]) };
  }
  convertLineBreaksToBlockBreaksInRange(t3) {
    t3 = Dt(t3);
    let [e2] = t3;
    const i2 = this.getStringAtRange(t3).slice(0, -1);
    let n2 = this;
    return i2.replace(/.*?\n/g, (function(t4) {
      e2 += t4.length, n2 = n2.insertBlockBreakAtRange([e2 - 1, e2]);
    })), { document: n2, range: t3 };
  }
  consolidateBlocksAtRange(t3) {
    t3 = Dt(t3);
    const [e2, i2] = t3, n2 = this.locationFromPosition(e2).index, r2 = this.locationFromPosition(i2).index;
    return new this.constructor(this.blockList.consolidateFromIndexToIndex(n2, r2));
  }
  getDocumentAtRange(t3) {
    t3 = Dt(t3);
    const e2 = this.blockList.getSplittableListInRange(t3).toArray();
    return new this.constructor(e2);
  }
  getStringAtRange(t3) {
    let e2;
    const i2 = t3 = Dt(t3);
    return i2[i2.length - 1] !== this.getLength() && (e2 = -1), this.getDocumentAtRange(t3).toString().slice(0, e2);
  }
  getBlockAtIndex(t3) {
    return this.blockList.getObjectAtIndex(t3);
  }
  getBlockAtPosition(t3) {
    const { index: e2 } = this.locationFromPosition(t3);
    return this.getBlockAtIndex(e2);
  }
  getTextAtIndex(t3) {
    var e2;
    return null === (e2 = this.getBlockAtIndex(t3)) || void 0 === e2 ? void 0 : e2.text;
  }
  getTextAtPosition(t3) {
    const { index: e2 } = this.locationFromPosition(t3);
    return this.getTextAtIndex(e2);
  }
  getPieceAtPosition(t3) {
    const { index: e2, offset: i2 } = this.locationFromPosition(t3);
    return this.getTextAtIndex(e2).getPieceAtPosition(i2);
  }
  getCharacterAtPosition(t3) {
    const { index: e2, offset: i2 } = this.locationFromPosition(t3);
    return this.getTextAtIndex(e2).getStringAtRange([i2, i2 + 1]);
  }
  getLength() {
    return this.blockList.getEndPosition();
  }
  getBlocks() {
    return this.blockList.toArray();
  }
  getBlockCount() {
    return this.blockList.length;
  }
  getEditCount() {
    return this.editCount;
  }
  eachBlock(t3) {
    return this.blockList.eachObject(t3);
  }
  eachBlockAtRange(t3, e2) {
    let i2, n2;
    t3 = Dt(t3);
    const [r2, o2] = t3, s2 = this.locationFromPosition(r2), a2 = this.locationFromPosition(o2);
    if (s2.index === a2.index) return i2 = this.getBlockAtIndex(s2.index), n2 = [s2.offset, a2.offset], e2(i2, n2, s2.index);
    for (let t4 = s2.index; t4 <= a2.index; t4++) if (i2 = this.getBlockAtIndex(t4), i2) {
      switch (t4) {
        case s2.index:
          n2 = [s2.offset, i2.text.getLength()];
          break;
        case a2.index:
          n2 = [0, a2.offset];
          break;
        default:
          n2 = [0, i2.text.getLength()];
      }
      e2(i2, n2, t4);
    }
  }
  getCommonAttributesAtRange(t3) {
    t3 = Dt(t3);
    const [e2] = t3;
    if (Lt(t3)) return this.getCommonAttributesAtPosition(e2);
    {
      const e3 = [], i2 = [];
      return this.eachBlockAtRange(t3, (function(t4, n2) {
        if (n2[0] !== n2[1]) return e3.push(t4.text.getCommonAttributesAtRange(n2)), i2.push(pn(t4));
      })), Jt.fromCommonAttributesOfObjects(e3).merge(Jt.fromCommonAttributesOfObjects(i2)).toObject();
    }
  }
  getCommonAttributesAtPosition(t3) {
    let e2, i2;
    const { index: n2, offset: r2 } = this.locationFromPosition(t3), o2 = this.getBlockAtIndex(n2);
    if (!o2) return {};
    const s2 = pn(o2), a2 = o2.text.getAttributesAtPosition(r2), l2 = o2.text.getAttributesAtPosition(r2 - 1), c2 = Object.keys(U).filter(((t4) => U[t4].inheritable));
    for (e2 in l2) i2 = l2[e2], (i2 === a2[e2] || c2.includes(e2)) && (s2[e2] = i2);
    return s2;
  }
  getRangeOfCommonAttributeAtPosition(t3, e2) {
    const { index: i2, offset: n2 } = this.locationFromPosition(e2), r2 = this.getTextAtIndex(i2), [o2, s2] = Array.from(r2.getExpandedRangeForAttributeAtOffset(t3, n2)), a2 = this.positionFromLocation({ index: i2, offset: o2 }), l2 = this.positionFromLocation({ index: i2, offset: s2 });
    return Dt([a2, l2]);
  }
  getBaseBlockAttributes() {
    let t3 = this.getBlockAtIndex(0).getAttributes();
    for (let e2 = 1; e2 < this.getBlockCount(); e2++) {
      const i2 = this.getBlockAtIndex(e2).getAttributes(), n2 = Math.min(t3.length, i2.length);
      t3 = (() => {
        const e3 = [];
        for (let r2 = 0; r2 < n2 && i2[r2] === t3[r2]; r2++) e3.push(i2[r2]);
        return e3;
      })();
    }
    return t3;
  }
  getAttachmentById(t3) {
    for (const e2 of this.getAttachments()) if (e2.id === t3) return e2;
  }
  getAttachmentPieces() {
    let t3 = [];
    return this.blockList.eachObject(((e2) => {
      let { text: i2 } = e2;
      return t3 = t3.concat(i2.getAttachmentPieces());
    })), t3;
  }
  getAttachments() {
    return this.getAttachmentPieces().map(((t3) => t3.attachment));
  }
  getRangeOfAttachment(t3) {
    let e2 = 0;
    const i2 = this.blockList.toArray();
    for (let n2 = 0; n2 < i2.length; n2++) {
      const { text: r2 } = i2[n2], o2 = r2.getRangeOfAttachment(t3);
      if (o2) return Dt([e2 + o2[0], e2 + o2[1]]);
      e2 += r2.getLength();
    }
  }
  getLocationRangeOfAttachment(t3) {
    const e2 = this.getRangeOfAttachment(t3);
    return this.locationRangeFromRange(e2);
  }
  getAttachmentPieceForAttachment(t3) {
    for (const e2 of this.getAttachmentPieces()) if (e2.attachment === t3) return e2;
  }
  findRangesForBlockAttribute(t3) {
    let e2 = 0;
    const i2 = [];
    return this.getBlocks().forEach(((n2) => {
      const r2 = n2.getLength();
      n2.hasAttribute(t3) && i2.push([e2, e2 + r2]), e2 += r2;
    })), i2;
  }
  findRangesForTextAttribute(t3) {
    let { withValue: e2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {}, i2 = 0, n2 = [];
    const r2 = [];
    return this.getPieces().forEach(((o2) => {
      const s2 = o2.getLength();
      (function(i3) {
        return e2 ? i3.getAttribute(t3) === e2 : i3.hasAttribute(t3);
      })(o2) && (n2[1] === i2 ? n2[1] = i2 + s2 : r2.push(n2 = [i2, i2 + s2])), i2 += s2;
    })), r2;
  }
  locationFromPosition(t3) {
    const e2 = this.blockList.findIndexAndOffsetAtPosition(Math.max(0, t3));
    if (null != e2.index) return e2;
    {
      const t4 = this.getBlocks();
      return { index: t4.length - 1, offset: t4[t4.length - 1].getLength() };
    }
  }
  positionFromLocation(t3) {
    return this.blockList.findPositionAtIndexAndOffset(t3.index, t3.offset);
  }
  locationRangeFromPosition(t3) {
    return Dt(this.locationFromPosition(t3));
  }
  locationRangeFromRange(t3) {
    if (!(t3 = Dt(t3))) return;
    const [e2, i2] = Array.from(t3), n2 = this.locationFromPosition(e2), r2 = this.locationFromPosition(i2);
    return Dt([n2, r2]);
  }
  rangeFromLocationRange(t3) {
    let e2;
    t3 = Dt(t3);
    const i2 = this.positionFromLocation(t3[0]);
    return Lt(t3) || (e2 = this.positionFromLocation(t3[1])), Dt([i2, e2]);
  }
  isEqualTo(t3) {
    return this.blockList.isEqualTo(null == t3 ? void 0 : t3.blockList);
  }
  getTexts() {
    return this.getBlocks().map(((t3) => t3.text));
  }
  getPieces() {
    const t3 = [];
    return Array.from(this.getTexts()).forEach(((e2) => {
      t3.push(...Array.from(e2.getPieces() || []));
    })), t3;
  }
  getObjects() {
    return this.getBlocks().concat(this.getTexts()).concat(this.getPieces());
  }
  toSerializableDocument() {
    const t3 = [];
    return this.blockList.eachObject(((e2) => t3.push(e2.copyWithText(e2.text.toSerializableText())))), new this.constructor(t3);
  }
  toString() {
    return this.blockList.toString();
  }
  toJSON() {
    return this.blockList.toJSON();
  }
  toConsole() {
    return JSON.stringify(this.blockList.toArray().map(((t3) => JSON.parse(t3.text.toConsole()))));
  }
}
const pn = function(t3) {
  const e2 = {}, i2 = t3.getLastAttribute();
  return i2 && (e2[i2] = true), e2;
}, fn = function(t3) {
  let e2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
  return { string: t3 = Ut(t3), attributes: e2, type: "string" };
}, bn = (t3, e2) => {
  try {
    return JSON.parse(t3.getAttribute("data-trix-".concat(e2)));
  } catch (t4) {
    return {};
  }
};
class vn extends q {
  static parse(t3, e2) {
    const i2 = new this(t3, e2);
    return i2.parse(), i2;
  }
  constructor(t3) {
    let { referenceElement: e2, purifyOptions: i2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    super(...arguments), this.html = t3, this.referenceElement = e2, this.purifyOptions = i2, this.blocks = [], this.blockElements = [], this.processedElements = [];
  }
  getDocument() {
    return mn.fromJSON(this.blocks);
  }
  parse() {
    try {
      this.createHiddenContainer(), Ai.setHTML(this.containerElement, this.html, { purifyOptions: this.purifyOptions });
      const t3 = T(this.containerElement, { usingFilter: Cn });
      for (; t3.nextNode(); ) this.processNode(t3.currentNode);
      return this.translateBlockElementMarginsToNewlines();
    } finally {
      this.removeHiddenContainer();
    }
  }
  createHiddenContainer() {
    return this.referenceElement ? (this.containerElement = this.referenceElement.cloneNode(false), this.containerElement.removeAttribute("id"), this.containerElement.setAttribute("data-trix-internal", ""), this.containerElement.style.display = "none", this.referenceElement.parentNode.insertBefore(this.containerElement, this.referenceElement.nextSibling)) : (this.containerElement = w({ tagName: "div", style: { display: "none" } }), document.body.appendChild(this.containerElement));
  }
  removeHiddenContainer() {
    return R(this.containerElement);
  }
  processNode(t3) {
    switch (t3.nodeType) {
      case Node.TEXT_NODE:
        if (!this.isInsignificantTextNode(t3)) return this.appendBlockForTextNode(t3), this.processTextNode(t3);
        break;
      case Node.ELEMENT_NODE:
        return this.appendBlockForElement(t3), this.processElement(t3);
    }
  }
  appendBlockForTextNode(t3) {
    const e2 = t3.parentNode;
    if (e2 === this.currentBlockElement && this.isBlockElement(t3.previousSibling)) return this.appendStringWithAttributes("\n");
    if (e2 === this.containerElement || this.isBlockElement(e2)) {
      var i2;
      const t4 = this.getBlockAttributes(e2), n2 = this.getBlockHTMLAttributes(e2);
      st(t4, null === (i2 = this.currentBlock) || void 0 === i2 ? void 0 : i2.attributes) || (this.currentBlock = this.appendBlockForAttributesWithElement(t4, e2, n2), this.currentBlockElement = e2);
    }
  }
  appendBlockForElement(t3) {
    const e2 = this.isBlockElement(t3), i2 = E(this.currentBlockElement, t3);
    if (e2 && !this.isBlockElement(t3.firstChild)) {
      if (!this.isInsignificantTextNode(t3.firstChild) || !this.isBlockElement(t3.firstElementChild)) {
        const e3 = this.getBlockAttributes(t3), n2 = this.getBlockHTMLAttributes(t3);
        if (t3.firstChild) {
          if (i2 && st(e3, this.currentBlock.attributes)) return this.appendStringWithAttributes("\n");
          this.currentBlock = this.appendBlockForAttributesWithElement(e3, t3, n2), this.currentBlockElement = t3;
        }
      }
    } else if (this.currentBlockElement && !i2 && !e2) {
      const e3 = this.findParentBlockElement(t3);
      if (e3) return this.appendBlockForElement(e3);
      this.currentBlock = this.appendEmptyBlock(), this.currentBlockElement = null;
    }
  }
  findParentBlockElement(t3) {
    let { parentElement: e2 } = t3;
    for (; e2 && e2 !== this.containerElement; ) {
      if (this.isBlockElement(e2) && this.blockElements.includes(e2)) return e2;
      e2 = e2.parentElement;
    }
    return null;
  }
  processTextNode(t3) {
    let e2 = t3.data;
    var i2;
    An(t3.parentNode) || (e2 = zt(e2), Rn(null === (i2 = t3.previousSibling) || void 0 === i2 ? void 0 : i2.textContent) && (e2 = En(e2)));
    return this.appendStringWithAttributes(e2, this.getTextAttributes(t3.parentNode));
  }
  processElement(t3) {
    let e2;
    if (M(t3)) {
      if (e2 = bn(t3, "attachment"), Object.keys(e2).length) {
        const i2 = this.getTextAttributes(t3);
        this.appendAttachmentWithAttributes(e2, i2), t3.innerHTML = "";
      }
      return this.processedElements.push(t3);
    }
    switch (k(t3)) {
      case "br":
        return this.isExtraBR(t3) || this.isBlockElement(t3.nextSibling) || this.appendStringWithAttributes("\n", this.getTextAttributes(t3)), this.processedElements.push(t3);
      case "img":
        e2 = { url: t3.getAttribute("src"), contentType: "image" };
        const i2 = ((t4) => {
          const e3 = t4.getAttribute("width"), i3 = t4.getAttribute("height"), n2 = {};
          return e3 && (n2.width = parseInt(e3, 10)), i3 && (n2.height = parseInt(i3, 10)), n2;
        })(t3);
        for (const t4 in i2) {
          const n2 = i2[t4];
          e2[t4] = n2;
        }
        return this.appendAttachmentWithAttributes(e2, this.getTextAttributes(t3)), this.processedElements.push(t3);
      case "tr":
        if (this.needsTableSeparator(t3)) return this.appendStringWithAttributes(W.tableRowSeparator);
        break;
      case "td":
        if (this.needsTableSeparator(t3)) return this.appendStringWithAttributes(W.tableCellSeparator);
    }
  }
  appendBlockForAttributesWithElement(t3, e2) {
    let i2 = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : {};
    this.blockElements.push(e2);
    const n2 = (function() {
      return { text: [], attributes: arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {}, htmlAttributes: arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {} };
    })(t3, i2);
    return this.blocks.push(n2), n2;
  }
  appendEmptyBlock() {
    return this.appendBlockForAttributesWithElement([], null);
  }
  appendStringWithAttributes(t3, e2) {
    return this.appendPiece(fn(t3, e2));
  }
  appendAttachmentWithAttributes(t3, e2) {
    return this.appendPiece((function(t4) {
      return { attachment: t4, attributes: arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {}, type: "attachment" };
    })(t3, e2));
  }
  appendPiece(t3) {
    return 0 === this.blocks.length && this.appendEmptyBlock(), this.blocks[this.blocks.length - 1].text.push(t3);
  }
  appendStringToTextAtIndex(t3, e2) {
    const { text: i2 } = this.blocks[e2], n2 = i2[i2.length - 1];
    if ("string" !== (null == n2 ? void 0 : n2.type)) return i2.push(fn(t3));
    n2.string += t3;
  }
  prependStringToTextAtIndex(t3, e2) {
    const { text: i2 } = this.blocks[e2], n2 = i2[0];
    if ("string" !== (null == n2 ? void 0 : n2.type)) return i2.unshift(fn(t3));
    n2.string = t3 + n2.string;
  }
  getTextAttributes(t3) {
    let e2;
    const i2 = {};
    for (const n2 in U) {
      const r2 = U[n2];
      if (r2.tagName && x(t3, { matchingSelector: r2.tagName, untilNode: this.containerElement })) i2[n2] = true;
      else if (r2.parser) {
        if (e2 = r2.parser(t3), e2) {
          let o2 = false;
          for (const i3 of this.findBlockElementAncestors(t3)) if (r2.parser(i3) === e2) {
            o2 = true;
            break;
          }
          o2 || (i2[n2] = e2);
        }
      } else r2.styleProperty && (e2 = t3.style[r2.styleProperty], e2 && (i2[n2] = e2));
    }
    if (M(t3)) {
      const n2 = bn(t3, "attributes");
      for (const t4 in n2) e2 = n2[t4], i2[t4] = e2;
    }
    return i2;
  }
  getBlockAttributes(t3) {
    const e2 = [];
    for (; t3 && t3 !== this.containerElement; ) {
      for (const r2 in n) {
        const o2 = n[r2];
        var i2;
        if (false !== o2.parse) {
          if (k(t3) === o2.tagName) (null !== (i2 = o2.test) && void 0 !== i2 && i2.call(o2, t3) || !o2.test) && (e2.push(r2), o2.listAttribute && e2.push(o2.listAttribute));
        }
      }
      t3 = t3.parentNode;
    }
    return e2.reverse();
  }
  getBlockHTMLAttributes(t3) {
    const e2 = {}, i2 = Object.values(n).find(((e3) => e3.tagName === k(t3)));
    return ((null == i2 ? void 0 : i2.htmlAttributes) || []).forEach(((i3) => {
      t3.hasAttribute(i3) && (e2[i3] = t3.getAttribute(i3));
    })), e2;
  }
  findBlockElementAncestors(t3) {
    const e2 = [];
    for (; t3 && t3 !== this.containerElement; ) {
      const i2 = k(t3);
      L().includes(i2) && e2.push(t3), t3 = t3.parentNode;
    }
    return e2;
  }
  isBlockElement(t3) {
    if ((null == t3 ? void 0 : t3.nodeType) === Node.ELEMENT_NODE && !M(t3) && !x(t3, { matchingSelector: "td", untilNode: this.containerElement })) return L().includes(k(t3)) || "block" === window.getComputedStyle(t3).display;
  }
  isInsignificantTextNode(t3) {
    if ((null == t3 ? void 0 : t3.nodeType) !== Node.TEXT_NODE) return;
    if (!Sn(t3.data)) return;
    const { parentNode: e2, previousSibling: i2, nextSibling: n2 } = t3;
    return yn(e2.previousSibling) && !this.isBlockElement(e2.previousSibling) || An(e2) ? void 0 : !i2 || this.isBlockElement(i2) || !n2 || this.isBlockElement(n2);
  }
  isExtraBR(t3) {
    return "br" === k(t3) && this.isBlockElement(t3.parentNode) && t3.parentNode.lastChild === t3;
  }
  needsTableSeparator(t3) {
    if (W.removeBlankTableCells) {
      var e2;
      const i2 = null === (e2 = t3.previousSibling) || void 0 === e2 ? void 0 : e2.textContent;
      return i2 && /\S/.test(i2);
    }
    return t3.previousSibling;
  }
  translateBlockElementMarginsToNewlines() {
    const t3 = this.getMarginOfDefaultBlockElement();
    for (let e2 = 0; e2 < this.blocks.length; e2++) {
      const i2 = this.getMarginOfBlockElementAtIndex(e2);
      i2 && (i2.top > 2 * t3.top && this.prependStringToTextAtIndex("\n", e2), i2.bottom > 2 * t3.bottom && this.appendStringToTextAtIndex("\n", e2));
    }
  }
  getMarginOfBlockElementAtIndex(t3) {
    const e2 = this.blockElements[t3];
    if (e2 && e2.textContent && !L().includes(k(e2)) && !this.processedElements.includes(e2)) return xn(e2);
  }
  getMarginOfDefaultBlockElement() {
    const t3 = w(n.default.tagName);
    return this.containerElement.appendChild(t3), xn(t3);
  }
}
const An = function(t3) {
  const { whiteSpace: e2 } = window.getComputedStyle(t3);
  return ["pre", "pre-wrap", "pre-line"].includes(e2);
}, yn = (t3) => t3 && !Rn(t3.textContent), xn = function(t3) {
  const e2 = window.getComputedStyle(t3);
  if ("block" === e2.display) return { top: parseInt(e2.marginTop), bottom: parseInt(e2.marginBottom) };
}, Cn = function(t3) {
  return "style" === k(t3) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
}, En = (t3) => t3.replace(new RegExp("^".concat(Vt.source, "+")), ""), Sn = (t3) => new RegExp("^".concat(Vt.source, "*$")).test(t3), Rn = (t3) => /\s$/.test(t3), Tn = ["contenteditable", "data-trix-id", "data-trix-store-key", "data-trix-mutable", "data-trix-placeholder", "tabindex"], kn = "data-trix-serialized-attributes", wn = "[".concat(kn, "]"), Dn = new RegExp("<!--block-->", "g"), Ln = { "application/json": function(t3) {
  let e2;
  if (t3 instanceof mn) e2 = t3;
  else {
    if (!(t3 instanceof HTMLElement)) throw new Error("unserializable object");
    e2 = vn.parse(t3.innerHTML).getDocument();
  }
  return e2.toSerializableDocument().toJSONString();
}, "text/html": function(t3) {
  let e2;
  if (t3 instanceof mn) e2 = Ni.render(t3);
  else {
    if (!(t3 instanceof HTMLElement)) throw new Error("unserializable object");
    e2 = t3.cloneNode(true);
  }
  return Array.from(e2.querySelectorAll("[data-trix-serialize=false]")).forEach(((t4) => {
    R(t4);
  })), Tn.forEach(((t4) => {
    Array.from(e2.querySelectorAll("[".concat(t4, "]"))).forEach(((e3) => {
      e3.removeAttribute(t4);
    }));
  })), Array.from(e2.querySelectorAll(wn)).forEach(((t4) => {
    try {
      const e3 = JSON.parse(t4.getAttribute(kn));
      t4.removeAttribute(kn);
      for (const i2 in e3) {
        const n2 = e3[i2];
        t4.setAttribute(i2, n2);
      }
    } catch (t5) {
    }
  })), e2.innerHTML.replace(Dn, "");
} };
var Nn = Object.freeze({ __proto__: null });
class In extends q {
  constructor(t3, e2) {
    super(...arguments), this.attachmentManager = t3, this.attachment = e2, this.id = this.attachment.id, this.file = this.attachment.file;
  }
  remove() {
    return this.attachmentManager.requestRemovalOfAttachment(this.attachment);
  }
}
In.proxyMethod("attachment.getAttribute"), In.proxyMethod("attachment.hasAttribute"), In.proxyMethod("attachment.setAttribute"), In.proxyMethod("attachment.getAttributes"), In.proxyMethod("attachment.setAttributes"), In.proxyMethod("attachment.isPending"), In.proxyMethod("attachment.isPreviewable"), In.proxyMethod("attachment.getURL"), In.proxyMethod("attachment.getPreviewURL"), In.proxyMethod("attachment.setPreviewURL"), In.proxyMethod("attachment.getHref"), In.proxyMethod("attachment.getFilename"), In.proxyMethod("attachment.getFilesize"), In.proxyMethod("attachment.getFormattedFilesize"), In.proxyMethod("attachment.getExtension"), In.proxyMethod("attachment.getContentType"), In.proxyMethod("attachment.getFile"), In.proxyMethod("attachment.setFile"), In.proxyMethod("attachment.releaseFile"), In.proxyMethod("attachment.getUploadProgress"), In.proxyMethod("attachment.setUploadProgress");
class On extends q {
  constructor() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
    super(...arguments), this.managedAttachments = {}, Array.from(t3).forEach(((t4) => {
      this.manageAttachment(t4);
    }));
  }
  getAttachments() {
    const t3 = [];
    for (const e2 in this.managedAttachments) {
      const i2 = this.managedAttachments[e2];
      t3.push(i2);
    }
    return t3;
  }
  manageAttachment(t3) {
    return this.managedAttachments[t3.id] || (this.managedAttachments[t3.id] = new In(this, t3)), this.managedAttachments[t3.id];
  }
  attachmentIsManaged(t3) {
    return t3.id in this.managedAttachments;
  }
  requestRemovalOfAttachment(t3) {
    var e2, i2;
    if (this.attachmentIsManaged(t3)) return null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.attachmentManagerDidRequestRemovalOfAttachment) || void 0 === i2 ? void 0 : i2.call(e2, t3);
  }
  unmanageAttachment(t3) {
    const e2 = this.managedAttachments[t3.id];
    return delete this.managedAttachments[t3.id], e2;
  }
}
class Fn {
  constructor(t3) {
    this.composition = t3, this.document = this.composition.document;
    const e2 = this.composition.getSelectedRange();
    this.startPosition = e2[0], this.endPosition = e2[1], this.startLocation = this.document.locationFromPosition(this.startPosition), this.endLocation = this.document.locationFromPosition(this.endPosition), this.block = this.document.getBlockAtIndex(this.endLocation.index), this.breaksOnReturn = this.block.breaksOnReturn(), this.previousCharacter = this.block.text.getStringAtPosition(this.endLocation.offset - 1), this.nextCharacter = this.block.text.getStringAtPosition(this.endLocation.offset);
  }
  shouldInsertBlockBreak() {
    return this.block.hasAttributes() && this.block.isListItem() && !this.block.isEmpty() ? 0 !== this.startLocation.offset : this.breaksOnReturn && "\n" !== this.nextCharacter;
  }
  shouldBreakFormattedBlock() {
    return this.block.hasAttributes() && !this.block.isListItem() && (this.breaksOnReturn && "\n" === this.nextCharacter || "\n" === this.previousCharacter);
  }
  shouldDecreaseListLevel() {
    return this.block.hasAttributes() && this.block.isListItem() && this.block.isEmpty();
  }
  shouldPrependListItem() {
    return this.block.isListItem() && 0 === this.startLocation.offset && !this.block.isEmpty();
  }
  shouldRemoveLastBlockAttribute() {
    return this.block.hasAttributes() && !this.block.isListItem() && this.block.isEmpty();
  }
}
class Pn extends q {
  constructor() {
    super(...arguments), this.document = new mn(), this.attachments = [], this.currentAttributes = {}, this.revision = 0;
  }
  setDocument(t3) {
    var e2, i2;
    if (!t3.isEqualTo(this.document)) return this.document = t3, this.refreshAttachments(), this.revision++, null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.compositionDidChangeDocument) || void 0 === i2 ? void 0 : i2.call(e2, t3);
  }
  getSnapshot() {
    return { document: this.document, selectedRange: this.getSelectedRange() };
  }
  loadSnapshot(t3) {
    var e2, i2, n2, r2;
    let { document: o2, selectedRange: s2 } = t3;
    return null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.compositionWillLoadSnapshot) || void 0 === i2 || i2.call(e2), this.setDocument(null != o2 ? o2 : new mn()), this.setSelection(null != s2 ? s2 : [0, 0]), null === (n2 = this.delegate) || void 0 === n2 || null === (r2 = n2.compositionDidLoadSnapshot) || void 0 === r2 ? void 0 : r2.call(n2);
  }
  insertText(t3) {
    let { updatePosition: e2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : { updatePosition: true };
    const i2 = this.getSelectedRange();
    this.setDocument(this.document.insertTextAtRange(t3, i2));
    const n2 = i2[0], r2 = n2 + t3.getLength();
    return e2 && this.setSelection(r2), this.notifyDelegateOfInsertionAtRange([n2, r2]);
  }
  insertBlock() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : new rn();
    const e2 = new mn([t3]);
    return this.insertDocument(e2);
  }
  insertDocument() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : new mn();
    const e2 = this.getSelectedRange();
    this.setDocument(this.document.insertDocumentAtRange(t3, e2));
    const i2 = e2[0], n2 = i2 + t3.getLength();
    return this.setSelection(n2), this.notifyDelegateOfInsertionAtRange([i2, n2]);
  }
  insertString(t3, e2) {
    const i2 = this.getCurrentTextAttributes(), n2 = nn.textForStringWithAttributes(t3, i2);
    return this.insertText(n2, e2);
  }
  insertBlockBreak() {
    const t3 = this.getSelectedRange();
    this.setDocument(this.document.insertBlockBreakAtRange(t3));
    const e2 = t3[0], i2 = e2 + 1;
    return this.setSelection(i2), this.notifyDelegateOfInsertionAtRange([e2, i2]);
  }
  insertLineBreak() {
    const t3 = new Fn(this);
    if (t3.shouldDecreaseListLevel()) return this.decreaseListLevel(), this.setSelection(t3.startPosition);
    if (t3.shouldPrependListItem()) {
      const e2 = new mn([t3.block.copyWithoutText()]);
      return this.insertDocument(e2);
    }
    return t3.shouldInsertBlockBreak() ? this.insertBlockBreak() : t3.shouldRemoveLastBlockAttribute() ? this.removeLastBlockAttribute() : t3.shouldBreakFormattedBlock() ? this.breakFormattedBlock(t3) : this.insertString("\n");
  }
  insertHTML(t3) {
    const e2 = vn.parse(t3, { purifyOptions: { SAFE_FOR_XML: true } }).getDocument(), i2 = this.getSelectedRange();
    this.setDocument(this.document.mergeDocumentAtRange(e2, i2));
    const n2 = i2[0], r2 = n2 + e2.getLength() - 1;
    return this.setSelection(r2), this.notifyDelegateOfInsertionAtRange([n2, r2]);
  }
  replaceHTML(t3) {
    const e2 = vn.parse(t3).getDocument().copyUsingObjectsFromDocument(this.document), i2 = this.getLocationRange({ strict: false }), n2 = this.document.rangeFromLocationRange(i2);
    return this.setDocument(e2), this.setSelection(n2);
  }
  insertFile(t3) {
    return this.insertFiles([t3]);
  }
  insertFiles(t3) {
    const e2 = [];
    return Array.from(t3).forEach(((t4) => {
      var i2;
      if (null !== (i2 = this.delegate) && void 0 !== i2 && i2.compositionShouldAcceptFile(t4)) {
        const i3 = Xi.attachmentForFile(t4);
        e2.push(i3);
      }
    })), this.insertAttachments(e2);
  }
  insertAttachment(t3) {
    return this.insertAttachments([t3]);
  }
  insertAttachments(t3) {
    let e2 = new nn();
    return Array.from(t3).forEach(((t4) => {
      var n2;
      const r2 = t4.getType(), o2 = null === (n2 = i[r2]) || void 0 === n2 ? void 0 : n2.presentation, s2 = this.getCurrentTextAttributes();
      o2 && (s2.presentation = o2);
      const a2 = nn.textForAttachmentWithAttributes(t4, s2);
      e2 = e2.appendText(a2);
    })), this.insertText(e2);
  }
  shouldManageDeletingInDirection(t3) {
    const e2 = this.getLocationRange();
    if (Lt(e2)) {
      if ("backward" === t3 && 0 === e2[0].offset) return true;
      if (this.shouldManageMovingCursorInDirection(t3)) return true;
    } else if (e2[0].index !== e2[1].index) return true;
    return false;
  }
  deleteInDirection(t3) {
    let e2, i2, n2, { length: r2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    const o2 = this.getLocationRange();
    let s2 = this.getSelectedRange();
    const a2 = Lt(s2);
    if (a2 ? i2 = "backward" === t3 && 0 === o2[0].offset : n2 = o2[0].index !== o2[1].index, i2 && this.canDecreaseBlockAttributeLevel()) {
      const t4 = this.getBlock();
      if (t4.isListItem() ? this.decreaseListLevel() : this.decreaseBlockAttributeLevel(), this.setSelection(s2[0]), t4.isEmpty()) return false;
    }
    return a2 && (s2 = this.getExpandedRangeInDirection(t3, { length: r2 }), "backward" === t3 && (e2 = this.getAttachmentAtRange(s2))), e2 ? (this.editAttachment(e2), false) : (this.setDocument(this.document.removeTextAtRange(s2)), this.setSelection(s2[0]), !i2 && !n2 && void 0);
  }
  moveTextFromRange(t3) {
    const [e2] = Array.from(this.getSelectedRange());
    return this.setDocument(this.document.moveTextFromRangeToPosition(t3, e2)), this.setSelection(e2);
  }
  removeAttachment(t3) {
    const e2 = this.document.getRangeOfAttachment(t3);
    if (e2) return this.stopEditingAttachment(), this.setDocument(this.document.removeTextAtRange(e2)), this.setSelection(e2[0]);
  }
  removeLastBlockAttribute() {
    const [t3, e2] = Array.from(this.getSelectedRange()), i2 = this.document.getBlockAtPosition(e2);
    return this.removeCurrentAttribute(i2.getLastAttribute()), this.setSelection(t3);
  }
  insertPlaceholder() {
    return this.placeholderPosition = this.getPosition(), this.insertString(" ");
  }
  selectPlaceholder() {
    if (null != this.placeholderPosition) return this.setSelectedRange([this.placeholderPosition, this.placeholderPosition + 1]), this.getSelectedRange();
  }
  forgetPlaceholder() {
    this.placeholderPosition = null;
  }
  hasCurrentAttribute(t3) {
    const e2 = this.currentAttributes[t3];
    return null != e2 && false !== e2;
  }
  toggleCurrentAttribute(t3) {
    const e2 = !this.currentAttributes[t3];
    return e2 ? this.setCurrentAttribute(t3, e2) : this.removeCurrentAttribute(t3);
  }
  canSetCurrentAttribute(t3) {
    return pt(t3) ? this.canSetCurrentBlockAttribute(t3) : this.canSetCurrentTextAttribute(t3);
  }
  canSetCurrentTextAttribute(t3) {
    const e2 = this.getSelectedDocument();
    if (e2) {
      for (const t4 of Array.from(e2.getAttachments())) if (!t4.hasContent()) return false;
      return true;
    }
  }
  canSetCurrentBlockAttribute(t3) {
    const e2 = this.getBlock();
    if (e2) return !e2.isTerminalBlock();
  }
  setCurrentAttribute(t3, e2) {
    return pt(t3) ? this.setBlockAttribute(t3, e2) : (this.setTextAttribute(t3, e2), this.currentAttributes[t3] = e2, this.notifyDelegateOfCurrentAttributesChange());
  }
  setHTMLAtributeAtPosition(t3, e2, i2) {
    var n2;
    const r2 = this.document.getBlockAtPosition(t3), o2 = null === (n2 = pt(r2.getLastAttribute())) || void 0 === n2 ? void 0 : n2.htmlAttributes;
    if (r2 && null != o2 && o2.includes(e2)) {
      const n3 = this.document.setHTMLAttributeAtPosition(t3, e2, i2);
      this.setDocument(n3);
    }
  }
  setTextAttribute(t3, e2) {
    const i2 = this.getSelectedRange();
    if (!i2) return;
    const [n2, r2] = Array.from(i2);
    if (n2 !== r2) return this.setDocument(this.document.addAttributeAtRange(t3, e2, i2));
    if ("href" === t3) {
      const t4 = nn.textForStringWithAttributes(e2, { href: e2 });
      return this.insertText(t4);
    }
  }
  setBlockAttribute(t3, e2) {
    const i2 = this.getSelectedRange();
    if (this.canSetCurrentAttribute(t3)) return this.setDocument(this.document.applyBlockAttributeAtRange(t3, e2, i2)), this.setSelection(i2);
  }
  removeCurrentAttribute(t3) {
    return pt(t3) ? (this.removeBlockAttribute(t3), this.updateCurrentAttributes()) : (this.removeTextAttribute(t3), delete this.currentAttributes[t3], this.notifyDelegateOfCurrentAttributesChange());
  }
  removeTextAttribute(t3) {
    const e2 = this.getSelectedRange();
    if (e2) return this.setDocument(this.document.removeAttributeAtRange(t3, e2));
  }
  removeBlockAttribute(t3) {
    const e2 = this.getSelectedRange();
    if (e2) return this.setDocument(this.document.removeAttributeAtRange(t3, e2));
  }
  canDecreaseNestingLevel() {
    var t3;
    return (null === (t3 = this.getBlock()) || void 0 === t3 ? void 0 : t3.getNestingLevel()) > 0;
  }
  canIncreaseNestingLevel() {
    var t3;
    const e2 = this.getBlock();
    if (e2) {
      if (null === (t3 = pt(e2.getLastNestableAttribute())) || void 0 === t3 || !t3.listAttribute) return e2.getNestingLevel() > 0;
      {
        const t4 = this.getPreviousBlock();
        if (t4) return (function() {
          let t5 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : [];
          return st((arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : []).slice(0, t5.length), t5);
        })(t4.getListItemAttributes(), e2.getListItemAttributes());
      }
    }
  }
  decreaseNestingLevel() {
    const t3 = this.getBlock();
    if (t3) return this.setDocument(this.document.replaceBlock(t3, t3.decreaseNestingLevel()));
  }
  increaseNestingLevel() {
    const t3 = this.getBlock();
    if (t3) return this.setDocument(this.document.replaceBlock(t3, t3.increaseNestingLevel()));
  }
  canDecreaseBlockAttributeLevel() {
    var t3;
    return (null === (t3 = this.getBlock()) || void 0 === t3 ? void 0 : t3.getAttributeLevel()) > 0;
  }
  decreaseBlockAttributeLevel() {
    var t3;
    const e2 = null === (t3 = this.getBlock()) || void 0 === t3 ? void 0 : t3.getLastAttribute();
    if (e2) return this.removeCurrentAttribute(e2);
  }
  decreaseListLevel() {
    let [t3] = Array.from(this.getSelectedRange());
    const { index: e2 } = this.document.locationFromPosition(t3);
    let i2 = e2;
    const n2 = this.getBlock().getAttributeLevel();
    let r2 = this.document.getBlockAtIndex(i2 + 1);
    for (; r2 && r2.isListItem() && !(r2.getAttributeLevel() <= n2); ) i2++, r2 = this.document.getBlockAtIndex(i2 + 1);
    t3 = this.document.positionFromLocation({ index: e2, offset: 0 });
    const o2 = this.document.positionFromLocation({ index: i2, offset: 0 });
    return this.setDocument(this.document.removeLastListAttributeAtRange([t3, o2]));
  }
  updateCurrentAttributes() {
    const t3 = this.getSelectedRange({ ignoreLock: true });
    if (t3) {
      const e2 = this.document.getCommonAttributesAtRange(t3);
      if (Array.from(mt()).forEach(((t4) => {
        e2[t4] || this.canSetCurrentAttribute(t4) || (e2[t4] = false);
      })), !wt(e2, this.currentAttributes)) return this.currentAttributes = e2, this.notifyDelegateOfCurrentAttributesChange();
    }
  }
  getCurrentAttributes() {
    return m.call({}, this.currentAttributes);
  }
  getCurrentTextAttributes() {
    const t3 = {};
    for (const e2 in this.currentAttributes) {
      const i2 = this.currentAttributes[e2];
      false !== i2 && bt(e2) && (t3[e2] = i2);
    }
    return t3;
  }
  freezeSelection() {
    return this.setCurrentAttribute("frozen", true);
  }
  thawSelection() {
    return this.removeCurrentAttribute("frozen");
  }
  hasFrozenSelection() {
    return this.hasCurrentAttribute("frozen");
  }
  setSelection(t3) {
    var e2;
    const i2 = this.document.locationRangeFromRange(t3);
    return null === (e2 = this.delegate) || void 0 === e2 ? void 0 : e2.compositionDidRequestChangingSelectionToLocationRange(i2);
  }
  getSelectedRange() {
    const t3 = this.getLocationRange();
    if (t3) return this.document.rangeFromLocationRange(t3);
  }
  setSelectedRange(t3) {
    const e2 = this.document.locationRangeFromRange(t3);
    return this.getSelectionManager().setLocationRange(e2);
  }
  getPosition() {
    const t3 = this.getLocationRange();
    if (t3) return this.document.positionFromLocation(t3[0]);
  }
  getLocationRange(t3) {
    return this.targetLocationRange ? this.targetLocationRange : this.getSelectionManager().getLocationRange(t3) || Dt({ index: 0, offset: 0 });
  }
  withTargetLocationRange(t3, e2) {
    let i2;
    this.targetLocationRange = t3;
    try {
      i2 = e2();
    } finally {
      this.targetLocationRange = null;
    }
    return i2;
  }
  withTargetRange(t3, e2) {
    const i2 = this.document.locationRangeFromRange(t3);
    return this.withTargetLocationRange(i2, e2);
  }
  withTargetDOMRange(t3, e2) {
    const i2 = this.createLocationRangeFromDOMRange(t3, { strict: false });
    return this.withTargetLocationRange(i2, e2);
  }
  getExpandedRangeInDirection(t3) {
    let { length: e2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {}, [i2, n2] = Array.from(this.getSelectedRange());
    return "backward" === t3 ? e2 ? i2 -= e2 : i2 = this.translateUTF16PositionFromOffset(i2, -1) : e2 ? n2 += e2 : n2 = this.translateUTF16PositionFromOffset(n2, 1), Dt([i2, n2]);
  }
  shouldManageMovingCursorInDirection(t3) {
    if (this.editingAttachment) return true;
    const e2 = this.getExpandedRangeInDirection(t3);
    return null != this.getAttachmentAtRange(e2);
  }
  moveCursorInDirection(t3) {
    let e2, i2;
    if (this.editingAttachment) i2 = this.document.getRangeOfAttachment(this.editingAttachment);
    else {
      const n2 = this.getSelectedRange();
      i2 = this.getExpandedRangeInDirection(t3), e2 = !Nt(n2, i2);
    }
    if ("backward" === t3 ? this.setSelectedRange(i2[0]) : this.setSelectedRange(i2[1]), e2) {
      const t4 = this.getAttachmentAtRange(i2);
      if (t4) return this.editAttachment(t4);
    }
  }
  expandSelectionInDirection(t3) {
    let { length: e2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    const i2 = this.getExpandedRangeInDirection(t3, { length: e2 });
    return this.setSelectedRange(i2);
  }
  expandSelectionForEditing() {
    if (this.hasCurrentAttribute("href")) return this.expandSelectionAroundCommonAttribute("href");
  }
  expandSelectionAroundCommonAttribute(t3) {
    const e2 = this.getPosition(), i2 = this.document.getRangeOfCommonAttributeAtPosition(t3, e2);
    return this.setSelectedRange(i2);
  }
  selectionContainsAttachments() {
    var t3;
    return (null === (t3 = this.getSelectedAttachments()) || void 0 === t3 ? void 0 : t3.length) > 0;
  }
  selectionIsInCursorTarget() {
    return this.editingAttachment || this.positionIsCursorTarget(this.getPosition());
  }
  positionIsCursorTarget(t3) {
    const e2 = this.document.locationFromPosition(t3);
    if (e2) return this.locationIsCursorTarget(e2);
  }
  positionIsBlockBreak(t3) {
    var e2;
    return null === (e2 = this.document.getPieceAtPosition(t3)) || void 0 === e2 ? void 0 : e2.isBlockBreak();
  }
  getSelectedDocument() {
    const t3 = this.getSelectedRange();
    if (t3) return this.document.getDocumentAtRange(t3);
  }
  getSelectedAttachments() {
    var t3;
    return null === (t3 = this.getSelectedDocument()) || void 0 === t3 ? void 0 : t3.getAttachments();
  }
  getAttachments() {
    return this.attachments.slice(0);
  }
  refreshAttachments() {
    const t3 = this.document.getAttachments(), { added: e2, removed: i2 } = (function() {
      let t4 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [], e3 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : [];
      const i3 = [], n2 = [], r2 = /* @__PURE__ */ new Set();
      t4.forEach(((t5) => {
        r2.add(t5);
      }));
      const o2 = /* @__PURE__ */ new Set();
      return e3.forEach(((t5) => {
        o2.add(t5), r2.has(t5) || i3.push(t5);
      })), t4.forEach(((t5) => {
        o2.has(t5) || n2.push(t5);
      })), { added: i3, removed: n2 };
    })(this.attachments, t3);
    return this.attachments = t3, Array.from(i2).forEach(((t4) => {
      var e3, i3;
      t4.delegate = null, null === (e3 = this.delegate) || void 0 === e3 || null === (i3 = e3.compositionDidRemoveAttachment) || void 0 === i3 || i3.call(e3, t4);
    })), (() => {
      const t4 = [];
      return Array.from(e2).forEach(((e3) => {
        var i3, n2;
        e3.delegate = this, t4.push(null === (i3 = this.delegate) || void 0 === i3 || null === (n2 = i3.compositionDidAddAttachment) || void 0 === n2 ? void 0 : n2.call(i3, e3));
      })), t4;
    })();
  }
  attachmentDidChangeAttributes(t3) {
    var e2, i2;
    return this.revision++, null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.compositionDidEditAttachment) || void 0 === i2 ? void 0 : i2.call(e2, t3);
  }
  attachmentDidChangePreviewURL(t3) {
    var e2, i2;
    return this.revision++, null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.compositionDidChangeAttachmentPreviewURL) || void 0 === i2 ? void 0 : i2.call(e2, t3);
  }
  editAttachment(t3, e2) {
    var i2, n2;
    if (t3 !== this.editingAttachment) return this.stopEditingAttachment(), this.editingAttachment = t3, null === (i2 = this.delegate) || void 0 === i2 || null === (n2 = i2.compositionDidStartEditingAttachment) || void 0 === n2 ? void 0 : n2.call(i2, this.editingAttachment, e2);
  }
  stopEditingAttachment() {
    var t3, e2;
    this.editingAttachment && (null === (t3 = this.delegate) || void 0 === t3 || null === (e2 = t3.compositionDidStopEditingAttachment) || void 0 === e2 || e2.call(t3, this.editingAttachment), this.editingAttachment = null);
  }
  updateAttributesForAttachment(t3, e2) {
    return this.setDocument(this.document.updateAttributesForAttachment(t3, e2));
  }
  removeAttributeForAttachment(t3, e2) {
    return this.setDocument(this.document.removeAttributeForAttachment(t3, e2));
  }
  breakFormattedBlock(t3) {
    let { document: e2 } = t3;
    const { block: i2 } = t3;
    let n2 = t3.startPosition, r2 = [n2 - 1, n2];
    i2.getBlockBreakPosition() === t3.startLocation.offset ? (i2.breaksOnReturn() && "\n" === t3.nextCharacter ? n2 += 1 : e2 = e2.removeTextAtRange(r2), r2 = [n2, n2]) : "\n" === t3.nextCharacter ? "\n" === t3.previousCharacter ? r2 = [n2 - 1, n2 + 1] : (r2 = [n2, n2 + 1], n2 += 1) : t3.startLocation.offset - 1 != 0 && (n2 += 1);
    const o2 = new mn([i2.removeLastAttribute().copyWithoutText()]);
    return this.setDocument(e2.insertDocumentAtRange(o2, r2)), this.setSelection(n2);
  }
  getPreviousBlock() {
    const t3 = this.getLocationRange();
    if (t3) {
      const { index: e2 } = t3[0];
      if (e2 > 0) return this.document.getBlockAtIndex(e2 - 1);
    }
  }
  getBlock() {
    const t3 = this.getLocationRange();
    if (t3) return this.document.getBlockAtIndex(t3[0].index);
  }
  getAttachmentAtRange(t3) {
    const e2 = this.document.getDocumentAtRange(t3);
    if (e2.toString() === "".concat("￼", "\n")) return e2.getAttachments()[0];
  }
  notifyDelegateOfCurrentAttributesChange() {
    var t3, e2;
    return null === (t3 = this.delegate) || void 0 === t3 || null === (e2 = t3.compositionDidChangeCurrentAttributes) || void 0 === e2 ? void 0 : e2.call(t3, this.currentAttributes);
  }
  notifyDelegateOfInsertionAtRange(t3) {
    var e2, i2;
    return null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.compositionDidPerformInsertionAtRange) || void 0 === i2 ? void 0 : i2.call(e2, t3);
  }
  translateUTF16PositionFromOffset(t3, e2) {
    const i2 = this.document.toUTF16String(), n2 = i2.offsetFromUCS2Offset(t3);
    return i2.offsetToUCS2Offset(n2 + e2);
  }
}
Pn.proxyMethod("getSelectionManager().getPointRange"), Pn.proxyMethod("getSelectionManager().setLocationRangeFromPointRange"), Pn.proxyMethod("getSelectionManager().createLocationRangeFromDOMRange"), Pn.proxyMethod("getSelectionManager().locationIsCursorTarget"), Pn.proxyMethod("getSelectionManager().selectionIsExpanded"), Pn.proxyMethod("delegate?.getSelectionManager");
class Mn extends q {
  constructor(t3) {
    super(...arguments), this.composition = t3, this.undoEntries = [], this.redoEntries = [];
  }
  recordUndoEntry(t3) {
    let { context: e2, consolidatable: i2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    const n2 = this.undoEntries.slice(-1)[0];
    if (!i2 || !Bn(n2, t3, e2)) {
      const i3 = this.createEntry({ description: t3, context: e2 });
      this.undoEntries.push(i3), this.redoEntries = [];
    }
  }
  undo() {
    const t3 = this.undoEntries.pop();
    if (t3) {
      const e2 = this.createEntry(t3);
      return this.redoEntries.push(e2), this.composition.loadSnapshot(t3.snapshot);
    }
  }
  redo() {
    const t3 = this.redoEntries.pop();
    if (t3) {
      const e2 = this.createEntry(t3);
      return this.undoEntries.push(e2), this.composition.loadSnapshot(t3.snapshot);
    }
  }
  canUndo() {
    return this.undoEntries.length > 0;
  }
  canRedo() {
    return this.redoEntries.length > 0;
  }
  createEntry() {
    let { description: t3, context: e2 } = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {};
    return { description: null == t3 ? void 0 : t3.toString(), context: JSON.stringify(e2), snapshot: this.composition.getSnapshot() };
  }
}
const Bn = (t3, e2, i2) => (null == t3 ? void 0 : t3.description) === (null == e2 ? void 0 : e2.toString()) && (null == t3 ? void 0 : t3.context) === JSON.stringify(i2), _n = "attachmentGallery";
class jn {
  constructor(t3) {
    this.document = t3.document, this.selectedRange = t3.selectedRange;
  }
  perform() {
    return this.removeBlockAttribute(), this.applyBlockAttribute();
  }
  getSnapshot() {
    return { document: this.document, selectedRange: this.selectedRange };
  }
  removeBlockAttribute() {
    return this.findRangesOfBlocks().map(((t3) => this.document = this.document.removeAttributeAtRange(_n, t3)));
  }
  applyBlockAttribute() {
    let t3 = 0;
    this.findRangesOfPieces().forEach(((e2) => {
      e2[1] - e2[0] > 1 && (e2[0] += t3, e2[1] += t3, "\n" !== this.document.getCharacterAtPosition(e2[1]) && (this.document = this.document.insertBlockBreakAtRange(e2[1]), e2[1] < this.selectedRange[1] && this.moveSelectedRangeForward(), e2[1]++, t3++), 0 !== e2[0] && "\n" !== this.document.getCharacterAtPosition(e2[0] - 1) && (this.document = this.document.insertBlockBreakAtRange(e2[0]), e2[0] < this.selectedRange[0] && this.moveSelectedRangeForward(), e2[0]++, t3++), this.document = this.document.applyBlockAttributeAtRange(_n, true, e2));
    }));
  }
  findRangesOfBlocks() {
    return this.document.findRangesForBlockAttribute(_n);
  }
  findRangesOfPieces() {
    return this.document.findRangesForTextAttribute("presentation", { withValue: "gallery" });
  }
  moveSelectedRangeForward() {
    this.selectedRange[0] += 1, this.selectedRange[1] += 1;
  }
}
const Wn = function(t3) {
  const e2 = new jn(t3);
  return e2.perform(), e2.getSnapshot();
}, Un = [Wn];
class Vn {
  constructor(t3, e2, i2) {
    this.insertFiles = this.insertFiles.bind(this), this.composition = t3, this.selectionManager = e2, this.element = i2, this.undoManager = new Mn(this.composition), this.filters = Un.slice(0);
  }
  loadDocument(t3) {
    return this.loadSnapshot({ document: t3, selectedRange: [0, 0] });
  }
  loadHTML() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : "";
    const e2 = vn.parse(t3, { referenceElement: this.element }).getDocument();
    return this.loadDocument(e2);
  }
  loadJSON(t3) {
    let { document: e2, selectedRange: i2 } = t3;
    return e2 = mn.fromJSON(e2), this.loadSnapshot({ document: e2, selectedRange: i2 });
  }
  loadSnapshot(t3) {
    return this.undoManager = new Mn(this.composition), this.composition.loadSnapshot(t3);
  }
  getDocument() {
    return this.composition.document;
  }
  getSelectedDocument() {
    return this.composition.getSelectedDocument();
  }
  getSnapshot() {
    return this.composition.getSnapshot();
  }
  toJSON() {
    return this.getSnapshot();
  }
  deleteInDirection(t3) {
    return this.composition.deleteInDirection(t3);
  }
  insertAttachment(t3) {
    return this.composition.insertAttachment(t3);
  }
  insertAttachments(t3) {
    return this.composition.insertAttachments(t3);
  }
  insertDocument(t3) {
    return this.composition.insertDocument(t3);
  }
  insertFile(t3) {
    return this.composition.insertFile(t3);
  }
  insertFiles(t3) {
    return this.composition.insertFiles(t3);
  }
  insertHTML(t3) {
    return this.composition.insertHTML(t3);
  }
  insertString(t3) {
    return this.composition.insertString(t3);
  }
  insertText(t3) {
    return this.composition.insertText(t3);
  }
  insertLineBreak() {
    return this.composition.insertLineBreak();
  }
  getSelectedRange() {
    return this.composition.getSelectedRange();
  }
  getPosition() {
    return this.composition.getPosition();
  }
  getClientRectAtPosition(t3) {
    const e2 = this.getDocument().locationRangeFromRange([t3, t3 + 1]);
    return this.selectionManager.getClientRectAtLocationRange(e2);
  }
  expandSelectionInDirection(t3) {
    return this.composition.expandSelectionInDirection(t3);
  }
  moveCursorInDirection(t3) {
    return this.composition.moveCursorInDirection(t3);
  }
  setSelectedRange(t3) {
    return this.composition.setSelectedRange(t3);
  }
  activateAttribute(t3) {
    let e2 = !(arguments.length > 1 && void 0 !== arguments[1]) || arguments[1];
    return this.composition.setCurrentAttribute(t3, e2);
  }
  attributeIsActive(t3) {
    return this.composition.hasCurrentAttribute(t3);
  }
  canActivateAttribute(t3) {
    return this.composition.canSetCurrentAttribute(t3);
  }
  deactivateAttribute(t3) {
    return this.composition.removeCurrentAttribute(t3);
  }
  setHTMLAtributeAtPosition(t3, e2, i2) {
    this.composition.setHTMLAtributeAtPosition(t3, e2, i2);
  }
  canDecreaseNestingLevel() {
    return this.composition.canDecreaseNestingLevel();
  }
  canIncreaseNestingLevel() {
    return this.composition.canIncreaseNestingLevel();
  }
  decreaseNestingLevel() {
    if (this.canDecreaseNestingLevel()) return this.composition.decreaseNestingLevel();
  }
  increaseNestingLevel() {
    if (this.canIncreaseNestingLevel()) return this.composition.increaseNestingLevel();
  }
  canRedo() {
    return this.undoManager.canRedo();
  }
  canUndo() {
    return this.undoManager.canUndo();
  }
  recordUndoEntry(t3) {
    let { context: e2, consolidatable: i2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    return this.undoManager.recordUndoEntry(t3, { context: e2, consolidatable: i2 });
  }
  redo() {
    if (this.canRedo()) return this.undoManager.redo();
  }
  undo() {
    if (this.canUndo()) return this.undoManager.undo();
  }
}
class zn {
  constructor(t3) {
    this.element = t3;
  }
  findLocationFromContainerAndOffset(t3, e2) {
    let { strict: i2 } = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : { strict: true }, n2 = 0, r2 = false;
    const o2 = { index: 0, offset: 0 }, s2 = this.findAttachmentElementParentForNode(t3);
    s2 && (t3 = s2.parentNode, e2 = S(s2));
    const a2 = T(this.element, { usingFilter: Kn });
    for (; a2.nextNode(); ) {
      const s3 = a2.currentNode;
      if (s3 === t3 && _(t3)) {
        P(s3) || (o2.offset += e2);
        break;
      }
      if (s3.parentNode === t3) {
        if (n2++ === e2) {
          !i2 && I(s3, { strict: i2 }) && (r2 && o2.index++, o2.offset = 0, r2 = true);
          break;
        }
      } else if (!E(t3, s3) && n2 > 0) break;
      I(s3, { strict: i2 }) ? (r2 && o2.index++, o2.offset = 0, r2 = true) : o2.offset += Hn(s3);
    }
    return o2;
  }
  findContainerAndOffsetFromLocation(t3) {
    let e2, i2;
    if (0 === t3.index && 0 === t3.offset) {
      for (e2 = this.element, i2 = 0; e2.firstChild; ) if (e2 = e2.firstChild, N(e2)) {
        i2 = 1;
        break;
      }
      return [e2, i2];
    }
    let [n2, r2] = this.findNodeAndOffsetFromLocation(t3);
    if (n2) {
      if (_(n2)) 0 === Hn(n2) ? (e2 = n2.parentNode.parentNode, i2 = S(n2.parentNode), P(n2, { name: "right" }) && i2++) : (e2 = n2, i2 = t3.offset - r2);
      else {
        if (e2 = n2.parentNode, !I(n2.previousSibling) && !N(e2)) for (; n2 === e2.lastChild && (n2 = e2, e2 = e2.parentNode, !N(e2)); ) ;
        i2 = S(n2), 0 !== t3.offset && i2++;
      }
      return [e2, i2];
    }
  }
  findNodeAndOffsetFromLocation(t3) {
    let e2, i2, n2 = 0;
    for (const r2 of this.getSignificantNodesForIndex(t3.index)) {
      const o2 = Hn(r2);
      if (t3.offset <= n2 + o2) if (_(r2)) {
        if (e2 = r2, i2 = n2, t3.offset === i2 && P(e2)) break;
      } else e2 || (e2 = r2, i2 = n2);
      if (n2 += o2, n2 > t3.offset) break;
    }
    return [e2, i2];
  }
  findAttachmentElementParentForNode(t3) {
    for (; t3 && t3 !== this.element; ) {
      if (M(t3)) return t3;
      t3 = t3.parentNode;
    }
  }
  getSignificantNodesForIndex(t3) {
    const e2 = [], i2 = T(this.element, { usingFilter: qn });
    let n2 = false;
    for (; i2.nextNode(); ) {
      const o2 = i2.currentNode;
      var r2;
      if (O(o2)) {
        if (null != r2 ? r2++ : r2 = 0, r2 === t3) n2 = true;
        else if (n2) break;
      } else n2 && e2.push(o2);
    }
    return e2;
  }
}
const Hn = function(t3) {
  if (t3.nodeType === Node.TEXT_NODE) {
    if (P(t3)) return 0;
    return t3.textContent.length;
  }
  return "br" === k(t3) || M(t3) ? 1 : 0;
}, qn = function(t3) {
  return Jn(t3) === NodeFilter.FILTER_ACCEPT ? Kn(t3) : NodeFilter.FILTER_REJECT;
}, Jn = function(t3) {
  return B(t3) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
}, Kn = function(t3) {
  return M(t3.parentNode) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
};
class Gn {
  createDOMRangeFromPoint(t3) {
    let e2, { x: i2, y: n2 } = t3;
    if (document.caretPositionFromPoint) {
      const { offsetNode: t4, offset: r2 } = document.caretPositionFromPoint(i2, n2);
      return e2 = document.createRange(), e2.setStart(t4, r2), e2;
    }
    if (document.caretRangeFromPoint) return document.caretRangeFromPoint(i2, n2);
    if (document.body.createTextRange) {
      const t4 = Bt();
      try {
        const t5 = document.body.createTextRange();
        t5.moveToPoint(i2, n2), t5.select();
      } catch (t5) {
      }
      return e2 = Bt(), _t(t4), e2;
    }
  }
  getClientRectsForDOMRange(t3) {
    const e2 = Array.from(t3.getClientRects());
    return [e2[0], e2[e2.length - 1]];
  }
}
class Xn extends q {
  constructor(t3) {
    super(...arguments), this.didMouseDown = this.didMouseDown.bind(this), this.selectionDidChange = this.selectionDidChange.bind(this), this.element = t3, this.locationMapper = new zn(this.element), this.pointMapper = new Gn(), this.lockCount = 0, b("mousedown", { onElement: this.element, withCallback: this.didMouseDown });
  }
  getLocationRange() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {};
    return false === t3.strict ? this.createLocationRangeFromDOMRange(Bt()) : t3.ignoreLock ? this.currentLocationRange : this.lockedLocationRange ? this.lockedLocationRange : this.currentLocationRange;
  }
  setLocationRange(t3) {
    if (this.lockedLocationRange) return;
    t3 = Dt(t3);
    const e2 = this.createDOMRangeFromLocationRange(t3);
    e2 && (_t(e2), this.updateCurrentLocationRange(t3));
  }
  setLocationRangeFromPointRange(t3) {
    t3 = Dt(t3);
    const e2 = this.getLocationAtPoint(t3[0]), i2 = this.getLocationAtPoint(t3[1]);
    this.setLocationRange([e2, i2]);
  }
  getClientRectAtLocationRange(t3) {
    const e2 = this.createDOMRangeFromLocationRange(t3);
    if (e2) return this.getClientRectsForDOMRange(e2)[1];
  }
  locationIsCursorTarget(t3) {
    const e2 = Array.from(this.findNodeAndOffsetFromLocation(t3))[0];
    return P(e2);
  }
  lock() {
    0 == this.lockCount++ && (this.updateCurrentLocationRange(), this.lockedLocationRange = this.getLocationRange());
  }
  unlock() {
    if (0 == --this.lockCount) {
      const { lockedLocationRange: t3 } = this;
      if (this.lockedLocationRange = null, null != t3) return this.setLocationRange(t3);
    }
  }
  clearSelection() {
    var t3;
    return null === (t3 = Mt()) || void 0 === t3 ? void 0 : t3.removeAllRanges();
  }
  selectionIsCollapsed() {
    var t3;
    return true === (null === (t3 = Bt()) || void 0 === t3 ? void 0 : t3.collapsed);
  }
  selectionIsExpanded() {
    return !this.selectionIsCollapsed();
  }
  createLocationRangeFromDOMRange(t3, e2) {
    if (null == t3 || !this.domRangeWithinElement(t3)) return;
    const i2 = this.findLocationFromContainerAndOffset(t3.startContainer, t3.startOffset, e2);
    if (!i2) return;
    const n2 = t3.collapsed ? void 0 : this.findLocationFromContainerAndOffset(t3.endContainer, t3.endOffset, e2);
    return Dt([i2, n2]);
  }
  didMouseDown() {
    return this.pauseTemporarily();
  }
  pauseTemporarily() {
    let t3;
    this.paused = true;
    const e2 = () => {
      if (this.paused = false, clearTimeout(i2), Array.from(t3).forEach(((t4) => {
        t4.destroy();
      })), E(document, this.element)) return this.selectionDidChange();
    }, i2 = setTimeout(e2, 200);
    t3 = ["mousemove", "keydown"].map(((t4) => b(t4, { onElement: document, withCallback: e2 })));
  }
  selectionDidChange() {
    if (!this.paused && !C(this.element)) return this.updateCurrentLocationRange();
  }
  updateCurrentLocationRange(t3) {
    var e2, i2;
    if ((null != t3 ? t3 : t3 = this.createLocationRangeFromDOMRange(Bt())) && !Nt(t3, this.currentLocationRange)) return this.currentLocationRange = t3, null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.locationRangeDidChange) || void 0 === i2 ? void 0 : i2.call(e2, this.currentLocationRange.slice(0));
  }
  createDOMRangeFromLocationRange(t3) {
    const e2 = this.findContainerAndOffsetFromLocation(t3[0]), i2 = Lt(t3) ? e2 : this.findContainerAndOffsetFromLocation(t3[1]) || e2;
    if (null != e2 && null != i2) {
      const t4 = document.createRange();
      return t4.setStart(...Array.from(e2 || [])), t4.setEnd(...Array.from(i2 || [])), t4;
    }
  }
  getLocationAtPoint(t3) {
    const e2 = this.createDOMRangeFromPoint(t3);
    var i2;
    if (e2) return null === (i2 = this.createLocationRangeFromDOMRange(e2)) || void 0 === i2 ? void 0 : i2[0];
  }
  domRangeWithinElement(t3) {
    return t3.collapsed ? E(this.element, t3.startContainer) : E(this.element, t3.startContainer) && E(this.element, t3.endContainer);
  }
}
Xn.proxyMethod("locationMapper.findLocationFromContainerAndOffset"), Xn.proxyMethod("locationMapper.findContainerAndOffsetFromLocation"), Xn.proxyMethod("locationMapper.findNodeAndOffsetFromLocation"), Xn.proxyMethod("pointMapper.createDOMRangeFromPoint"), Xn.proxyMethod("pointMapper.getClientRectsForDOMRange");
var Yn = Object.freeze({ __proto__: null, Attachment: Xi, AttachmentManager: On, AttachmentPiece: Yi, Block: rn, Composition: Pn, Document: mn, Editor: Vn, HTMLParser: vn, HTMLSanitizer: Ai, LineBreakInsertion: Fn, LocationMapper: zn, ManagedAttachment: In, Piece: Ki, PointMapper: Gn, SelectionManager: Xn, SplittableList: Zi, StringPiece: $i, Text: nn, UndoManager: Mn }), $n = Object.freeze({ __proto__: null, ObjectView: ne, AttachmentView: Ci, BlockView: Li, DocumentView: Ni, PieceView: Ti, PreviewableAttachmentView: Ri, TextView: ki });
const { lang: Zn, css: Qn, keyNames: tr } = H, er = function(t3) {
  return function() {
    const e2 = t3.apply(this, arguments);
    e2.do(), this.undos || (this.undos = []), this.undos.push(e2.undo);
  };
};
class ir extends q {
  constructor(t3, e2, i2) {
    let n2 = arguments.length > 3 && void 0 !== arguments[3] ? arguments[3] : {};
    super(...arguments), _i(this, "makeElementMutable", er((() => ({ do: () => {
      this.element.dataset.trixMutable = true;
    }, undo: () => delete this.element.dataset.trixMutable })))), _i(this, "addToolbar", er((() => {
      const t4 = w({ tagName: "div", className: Qn.attachmentToolbar, data: { trixMutable: true }, childNodes: w({ tagName: "div", className: "trix-button-row", childNodes: w({ tagName: "span", className: "trix-button-group trix-button-group--actions", childNodes: w({ tagName: "button", className: "trix-button trix-button--remove", textContent: Zn.remove, attributes: { title: Zn.remove }, data: { trixAction: "remove" } }) }) }) });
      return this.attachment.isPreviewable() && t4.appendChild(w({ tagName: "div", className: Qn.attachmentMetadataContainer, childNodes: w({ tagName: "span", className: Qn.attachmentMetadata, childNodes: [w({ tagName: "span", className: Qn.attachmentName, textContent: this.attachment.getFilename(), attributes: { title: this.attachment.getFilename() } }), w({ tagName: "span", className: Qn.attachmentSize, textContent: this.attachment.getFormattedFilesize() })] }) })), b("click", { onElement: t4, withCallback: this.didClickToolbar }), b("click", { onElement: t4, matchingSelector: "[data-trix-action]", withCallback: this.didClickActionButton }), A("trix-attachment-before-toolbar", { onElement: this.element, attributes: { toolbar: t4, attachment: this.attachment } }), { do: () => this.element.appendChild(t4), undo: () => R(t4) };
    }))), _i(this, "installCaptionEditor", er((() => {
      const t4 = w({ tagName: "textarea", className: Qn.attachmentCaptionEditor, attributes: { placeholder: Zn.captionPlaceholder }, data: { trixMutable: true } });
      t4.value = this.attachmentPiece.getCaption();
      const e3 = t4.cloneNode();
      e3.classList.add("trix-autoresize-clone"), e3.tabIndex = -1;
      const i3 = function() {
        e3.value = t4.value, t4.style.height = e3.scrollHeight + "px";
      };
      b("input", { onElement: t4, withCallback: i3 }), b("input", { onElement: t4, withCallback: this.didInputCaption }), b("keydown", { onElement: t4, withCallback: this.didKeyDownCaption }), b("change", { onElement: t4, withCallback: this.didChangeCaption }), b("blur", { onElement: t4, withCallback: this.didBlurCaption });
      const n3 = this.element.querySelector("figcaption"), r2 = n3.cloneNode();
      return { do: () => {
        if (n3.style.display = "none", r2.appendChild(t4), r2.appendChild(e3), r2.classList.add("".concat(Qn.attachmentCaption, "--editing")), n3.parentElement.insertBefore(r2, n3), i3(), this.options.editCaption) return Tt((() => t4.focus()));
      }, undo() {
        R(r2), n3.style.display = null;
      } };
    }))), this.didClickToolbar = this.didClickToolbar.bind(this), this.didClickActionButton = this.didClickActionButton.bind(this), this.didKeyDownCaption = this.didKeyDownCaption.bind(this), this.didInputCaption = this.didInputCaption.bind(this), this.didChangeCaption = this.didChangeCaption.bind(this), this.didBlurCaption = this.didBlurCaption.bind(this), this.attachmentPiece = t3, this.element = e2, this.container = i2, this.options = n2, this.attachment = this.attachmentPiece.attachment, "a" === k(this.element) && (this.element = this.element.firstChild), this.install();
  }
  install() {
    this.makeElementMutable(), this.addToolbar(), this.attachment.isPreviewable() && this.installCaptionEditor();
  }
  uninstall() {
    var t3;
    let e2 = this.undos.pop();
    for (this.savePendingCaption(); e2; ) e2(), e2 = this.undos.pop();
    null === (t3 = this.delegate) || void 0 === t3 || t3.didUninstallAttachmentEditor(this);
  }
  savePendingCaption() {
    if (null != this.pendingCaption) {
      const r2 = this.pendingCaption;
      var t3, e2, i2, n2;
      if (this.pendingCaption = null, r2) null === (t3 = this.delegate) || void 0 === t3 || null === (e2 = t3.attachmentEditorDidRequestUpdatingAttributesForAttachment) || void 0 === e2 || e2.call(t3, { caption: r2 }, this.attachment);
      else null === (i2 = this.delegate) || void 0 === i2 || null === (n2 = i2.attachmentEditorDidRequestRemovingAttributeForAttachment) || void 0 === n2 || n2.call(i2, "caption", this.attachment);
    }
  }
  didClickToolbar(t3) {
    return t3.preventDefault(), t3.stopPropagation();
  }
  didClickActionButton(t3) {
    var e2;
    if ("remove" === t3.target.getAttribute("data-trix-action")) return null === (e2 = this.delegate) || void 0 === e2 ? void 0 : e2.attachmentEditorDidRequestRemovalOfAttachment(this.attachment);
  }
  didKeyDownCaption(t3) {
    var e2, i2;
    if ("return" === tr[t3.keyCode]) return t3.preventDefault(), this.savePendingCaption(), null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.attachmentEditorDidRequestDeselectingAttachment) || void 0 === i2 ? void 0 : i2.call(e2, this.attachment);
  }
  didInputCaption(t3) {
    this.pendingCaption = t3.target.value.replace(/\s/g, " ").trim();
  }
  didChangeCaption(t3) {
    return this.savePendingCaption();
  }
  didBlurCaption(t3) {
    return this.savePendingCaption();
  }
}
class nr extends q {
  constructor(t3, i2) {
    super(...arguments), this.didFocus = this.didFocus.bind(this), this.didBlur = this.didBlur.bind(this), this.didClickAttachment = this.didClickAttachment.bind(this), this.element = t3, this.composition = i2, this.documentView = new Ni(this.composition.document, { element: this.element }), b("focus", { onElement: this.element, withCallback: this.didFocus }), b("blur", { onElement: this.element, withCallback: this.didBlur }), b("click", { onElement: this.element, matchingSelector: "a[contenteditable=false]", preventDefault: true }), b("mousedown", { onElement: this.element, matchingSelector: e, withCallback: this.didClickAttachment }), b("click", { onElement: this.element, matchingSelector: "a".concat(e), preventDefault: true });
  }
  didFocus(t3) {
    var e2;
    const i2 = () => {
      var t4, e3;
      if (!this.focused) return this.focused = true, null === (t4 = this.delegate) || void 0 === t4 || null === (e3 = t4.compositionControllerDidFocus) || void 0 === e3 ? void 0 : e3.call(t4);
    };
    return (null === (e2 = this.blurPromise) || void 0 === e2 ? void 0 : e2.then(i2)) || i2();
  }
  didBlur(t3) {
    this.blurPromise = new Promise(((t4) => Tt((() => {
      var e2, i2;
      C(this.element) || (this.focused = null, null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.compositionControllerDidBlur) || void 0 === i2 || i2.call(e2));
      return this.blurPromise = null, t4();
    }))));
  }
  didClickAttachment(t3, e2) {
    var i2, n2;
    const r2 = this.findAttachmentForElement(e2), o2 = !!x(t3.target, { matchingSelector: "figcaption" });
    return null === (i2 = this.delegate) || void 0 === i2 || null === (n2 = i2.compositionControllerDidSelectAttachment) || void 0 === n2 ? void 0 : n2.call(i2, r2, { editCaption: o2 });
  }
  getSerializableElement() {
    return this.isEditingAttachment() ? this.documentView.shadowElement : this.element;
  }
  render() {
    var t3, e2, i2, n2, r2, o2;
    (this.revision !== this.composition.revision && (this.documentView.setDocument(this.composition.document), this.documentView.render(), this.revision = this.composition.revision), this.canSyncDocumentView() && !this.documentView.isSynced()) && (null === (i2 = this.delegate) || void 0 === i2 || null === (n2 = i2.compositionControllerWillSyncDocumentView) || void 0 === n2 || n2.call(i2), this.documentView.sync(), null === (r2 = this.delegate) || void 0 === r2 || null === (o2 = r2.compositionControllerDidSyncDocumentView) || void 0 === o2 || o2.call(r2));
    return null === (t3 = this.delegate) || void 0 === t3 || null === (e2 = t3.compositionControllerDidRender) || void 0 === e2 ? void 0 : e2.call(t3);
  }
  rerenderViewForObject(t3) {
    return this.invalidateViewForObject(t3), this.render();
  }
  invalidateViewForObject(t3) {
    return this.documentView.invalidateViewForObject(t3);
  }
  isViewCachingEnabled() {
    return this.documentView.isViewCachingEnabled();
  }
  enableViewCaching() {
    return this.documentView.enableViewCaching();
  }
  disableViewCaching() {
    return this.documentView.disableViewCaching();
  }
  refreshViewCache() {
    return this.documentView.garbageCollectCachedViews();
  }
  isEditingAttachment() {
    return !!this.attachmentEditor;
  }
  installAttachmentEditorForAttachment(t3, e2) {
    var i2;
    if ((null === (i2 = this.attachmentEditor) || void 0 === i2 ? void 0 : i2.attachment) === t3) return;
    const n2 = this.documentView.findElementForObject(t3);
    if (!n2) return;
    this.uninstallAttachmentEditor();
    const r2 = this.composition.document.getAttachmentPieceForAttachment(t3);
    this.attachmentEditor = new ir(r2, n2, this.element, e2), this.attachmentEditor.delegate = this;
  }
  uninstallAttachmentEditor() {
    var t3;
    return null === (t3 = this.attachmentEditor) || void 0 === t3 ? void 0 : t3.uninstall();
  }
  didUninstallAttachmentEditor() {
    return this.attachmentEditor = null, this.render();
  }
  attachmentEditorDidRequestUpdatingAttributesForAttachment(t3, e2) {
    var i2, n2;
    return null === (i2 = this.delegate) || void 0 === i2 || null === (n2 = i2.compositionControllerWillUpdateAttachment) || void 0 === n2 || n2.call(i2, e2), this.composition.updateAttributesForAttachment(t3, e2);
  }
  attachmentEditorDidRequestRemovingAttributeForAttachment(t3, e2) {
    var i2, n2;
    return null === (i2 = this.delegate) || void 0 === i2 || null === (n2 = i2.compositionControllerWillUpdateAttachment) || void 0 === n2 || n2.call(i2, e2), this.composition.removeAttributeForAttachment(t3, e2);
  }
  attachmentEditorDidRequestRemovalOfAttachment(t3) {
    var e2, i2;
    return null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.compositionControllerDidRequestRemovalOfAttachment) || void 0 === i2 ? void 0 : i2.call(e2, t3);
  }
  attachmentEditorDidRequestDeselectingAttachment(t3) {
    var e2, i2;
    return null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.compositionControllerDidRequestDeselectingAttachment) || void 0 === i2 ? void 0 : i2.call(e2, t3);
  }
  canSyncDocumentView() {
    return !this.isEditingAttachment();
  }
  findAttachmentForElement(t3) {
    return this.composition.document.getAttachmentById(parseInt(t3.dataset.trixId, 10));
  }
}
class rr extends q {
}
const or = "data-trix-mutable", sr = "[".concat(or, "]"), ar = { attributes: true, childList: true, characterData: true, characterDataOldValue: true, subtree: true };
class lr extends q {
  constructor(t3) {
    super(t3), this.didMutate = this.didMutate.bind(this), this.element = t3, this.observer = new window.MutationObserver(this.didMutate), this.start();
  }
  start() {
    return this.reset(), this.observer.observe(this.element, ar);
  }
  stop() {
    return this.observer.disconnect();
  }
  didMutate(t3) {
    var e2, i2;
    if (this.mutations.push(...Array.from(this.findSignificantMutations(t3) || [])), this.mutations.length) return null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.elementDidMutate) || void 0 === i2 || i2.call(e2, this.getMutationSummary()), this.reset();
  }
  reset() {
    this.mutations = [];
  }
  findSignificantMutations(t3) {
    return t3.filter(((t4) => this.mutationIsSignificant(t4)));
  }
  mutationIsSignificant(t3) {
    if (this.nodeIsMutable(t3.target)) return false;
    for (const e2 of Array.from(this.nodesModifiedByMutation(t3))) if (this.nodeIsSignificant(e2)) return true;
    return false;
  }
  nodeIsSignificant(t3) {
    return t3 !== this.element && !this.nodeIsMutable(t3) && !B(t3);
  }
  nodeIsMutable(t3) {
    return x(t3, { matchingSelector: sr });
  }
  nodesModifiedByMutation(t3) {
    const e2 = [];
    switch (t3.type) {
      case "attributes":
        t3.attributeName !== or && e2.push(t3.target);
        break;
      case "characterData":
        e2.push(t3.target.parentNode), e2.push(t3.target);
        break;
      case "childList":
        e2.push(...Array.from(t3.addedNodes || [])), e2.push(...Array.from(t3.removedNodes || []));
    }
    return e2;
  }
  getMutationSummary() {
    return this.getTextMutationSummary();
  }
  getTextMutationSummary() {
    const { additions: t3, deletions: e2 } = this.getTextChangesFromCharacterData(), i2 = this.getTextChangesFromChildList();
    Array.from(i2.additions).forEach(((e3) => {
      Array.from(t3).includes(e3) || t3.push(e3);
    })), e2.push(...Array.from(i2.deletions || []));
    const n2 = {}, r2 = t3.join("");
    r2 && (n2.textAdded = r2);
    const o2 = e2.join("");
    return o2 && (n2.textDeleted = o2), n2;
  }
  getMutationsByType(t3) {
    return Array.from(this.mutations).filter(((e2) => e2.type === t3));
  }
  getTextChangesFromChildList() {
    let t3, e2;
    const i2 = [], n2 = [];
    Array.from(this.getMutationsByType("childList")).forEach(((t4) => {
      i2.push(...Array.from(t4.addedNodes || [])), n2.push(...Array.from(t4.removedNodes || []));
    }));
    0 === i2.length && 1 === n2.length && O(n2[0]) ? (t3 = [], e2 = ["\n"]) : (t3 = cr(i2), e2 = cr(n2));
    const r2 = t3.filter(((t4, i3) => t4 !== e2[i3])).map(Ut), o2 = e2.filter(((e3, i3) => e3 !== t3[i3])).map(Ut);
    return { additions: r2, deletions: o2 };
  }
  getTextChangesFromCharacterData() {
    let t3, e2;
    const i2 = this.getMutationsByType("characterData");
    if (i2.length) {
      const n2 = i2[0], r2 = i2[i2.length - 1], o2 = (function(t4, e3) {
        let i3, n3;
        return t4 = Z.box(t4), (e3 = Z.box(e3)).length < t4.length ? [n3, i3] = Ht(t4, e3) : [i3, n3] = Ht(e3, t4), { added: i3, removed: n3 };
      })(Ut(n2.oldValue), Ut(r2.target.data));
      t3 = o2.added, e2 = o2.removed;
    }
    return { additions: t3 ? [t3] : [], deletions: e2 ? [e2] : [] };
  }
}
const cr = function() {
  let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : [];
  const e2 = [];
  for (const i2 of Array.from(t3)) switch (i2.nodeType) {
    case Node.TEXT_NODE:
      e2.push(i2.data);
      break;
    case Node.ELEMENT_NODE:
      "br" === k(i2) ? e2.push("\n") : e2.push(...Array.from(cr(i2.childNodes) || []));
  }
  return e2;
};
class ur extends ie {
  constructor(t3) {
    super(...arguments), this.file = t3;
  }
  perform(t3) {
    const e2 = new FileReader();
    return e2.onerror = () => t3(false), e2.onload = () => {
      e2.onerror = null;
      try {
        e2.abort();
      } catch (t4) {
      }
      return t3(true, this.file);
    }, e2.readAsArrayBuffer(this.file);
  }
}
class hr {
  constructor(t3) {
    this.element = t3;
  }
  shouldIgnore(t3) {
    return !!a.samsungAndroid && (this.previousEvent = this.event, this.event = t3, this.checkSamsungKeyboardBuggyModeStart(), this.checkSamsungKeyboardBuggyModeEnd(), this.buggyMode);
  }
  checkSamsungKeyboardBuggyModeStart() {
    this.insertingLongTextAfterUnidentifiedChar() && dr(this.element.innerText, this.event.data) && (this.buggyMode = true, this.event.preventDefault());
  }
  checkSamsungKeyboardBuggyModeEnd() {
    this.buggyMode && "insertText" !== this.event.inputType && (this.buggyMode = false);
  }
  insertingLongTextAfterUnidentifiedChar() {
    var t3;
    return this.isBeforeInputInsertText() && this.previousEventWasUnidentifiedKeydown() && (null === (t3 = this.event.data) || void 0 === t3 ? void 0 : t3.length) > 50;
  }
  isBeforeInputInsertText() {
    return "beforeinput" === this.event.type && "insertText" === this.event.inputType;
  }
  previousEventWasUnidentifiedKeydown() {
    var t3, e2;
    return "keydown" === (null === (t3 = this.previousEvent) || void 0 === t3 ? void 0 : t3.type) && "Unidentified" === (null === (e2 = this.previousEvent) || void 0 === e2 ? void 0 : e2.key);
  }
}
const dr = (t3, e2) => mr(t3) === mr(e2), gr = new RegExp("(".concat("￼", "|").concat(d, "|").concat(g, "|\\s)+"), "g"), mr = (t3) => t3.replace(gr, " ").trim();
class pr extends q {
  constructor(t3) {
    super(...arguments), this.element = t3, this.mutationObserver = new lr(this.element), this.mutationObserver.delegate = this, this.flakyKeyboardDetector = new hr(this.element);
    for (const t4 in this.constructor.events) b(t4, { onElement: this.element, withCallback: this.handlerFor(t4) });
  }
  elementDidMutate(t3) {
  }
  editorWillSyncDocumentView() {
    return this.mutationObserver.stop();
  }
  editorDidSyncDocumentView() {
    return this.mutationObserver.start();
  }
  requestRender() {
    var t3, e2;
    return null === (t3 = this.delegate) || void 0 === t3 || null === (e2 = t3.inputControllerDidRequestRender) || void 0 === e2 ? void 0 : e2.call(t3);
  }
  requestReparse() {
    var t3, e2;
    return null === (t3 = this.delegate) || void 0 === t3 || null === (e2 = t3.inputControllerDidRequestReparse) || void 0 === e2 || e2.call(t3), this.requestRender();
  }
  attachFiles(t3) {
    const e2 = Array.from(t3).map(((t4) => new ur(t4)));
    return Promise.all(e2).then(((t4) => {
      this.handleInput((function() {
        var e3, i2;
        return null === (e3 = this.delegate) || void 0 === e3 || e3.inputControllerWillAttachFiles(), null === (i2 = this.responder) || void 0 === i2 || i2.insertFiles(t4), this.requestRender();
      }));
    }));
  }
  handlerFor(t3) {
    return (e2) => {
      e2.defaultPrevented || this.handleInput((() => {
        if (!C(this.element)) {
          if (this.flakyKeyboardDetector.shouldIgnore(e2)) return;
          this.eventName = t3, this.constructor.events[t3].call(this, e2);
        }
      }));
    };
  }
  handleInput(t3) {
    try {
      var e2;
      null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillHandleInput(), t3.call(this);
    } finally {
      var i2;
      null === (i2 = this.delegate) || void 0 === i2 || i2.inputControllerDidHandleInput();
    }
  }
  createLinkHTML(t3, e2) {
    const i2 = document.createElement("a");
    return i2.href = t3, i2.textContent = e2 || t3, i2.outerHTML;
  }
}
var fr;
_i(pr, "events", {});
const { browser: br, keyNames: vr } = H;
let Ar = 0;
class yr extends pr {
  constructor() {
    super(...arguments), this.resetInputSummary();
  }
  setInputSummary() {
    let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : {};
    this.inputSummary.eventName = this.eventName;
    for (const e2 in t3) {
      const i2 = t3[e2];
      this.inputSummary[e2] = i2;
    }
    return this.inputSummary;
  }
  resetInputSummary() {
    this.inputSummary = {};
  }
  reset() {
    return this.resetInputSummary(), Pt.reset();
  }
  elementDidMutate(t3) {
    var e2, i2;
    return this.isComposing() ? null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.inputControllerDidAllowUnhandledInput) || void 0 === i2 ? void 0 : i2.call(e2) : this.handleInput((function() {
      return this.mutationIsSignificant(t3) && (this.mutationIsExpected(t3) ? this.requestRender() : this.requestReparse()), this.reset();
    }));
  }
  mutationIsExpected(t3) {
    let { textAdded: e2, textDeleted: i2 } = t3;
    if (this.inputSummary.preferDocument) return true;
    const n2 = null != e2 ? e2 === this.inputSummary.textAdded : !this.inputSummary.textAdded, r2 = null != i2 ? this.inputSummary.didDelete : !this.inputSummary.didDelete, o2 = ["\n", " \n"].includes(e2) && !n2, s2 = "\n" === i2 && !r2;
    if (o2 && !s2 || s2 && !o2) {
      const t4 = this.getSelectedRange();
      if (t4) {
        var a2;
        const i3 = o2 ? e2.replace(/\n$/, "").length || -1 : (null == e2 ? void 0 : e2.length) || 1;
        if (null !== (a2 = this.responder) && void 0 !== a2 && a2.positionIsBlockBreak(t4[1] + i3)) return true;
      }
    }
    return n2 && r2;
  }
  mutationIsSignificant(t3) {
    var e2;
    const i2 = Object.keys(t3).length > 0, n2 = "" === (null === (e2 = this.compositionInput) || void 0 === e2 ? void 0 : e2.getEndData());
    return i2 || !n2;
  }
  getCompositionInput() {
    if (this.isComposing()) return this.compositionInput;
    this.compositionInput = new Rr(this);
  }
  isComposing() {
    return this.compositionInput && !this.compositionInput.isEnded();
  }
  deleteInDirection(t3, e2) {
    var i2;
    return false !== (null === (i2 = this.responder) || void 0 === i2 ? void 0 : i2.deleteInDirection(t3)) ? this.setInputSummary({ didDelete: true }) : e2 ? (e2.preventDefault(), this.requestRender()) : void 0;
  }
  serializeSelectionToDataTransfer(t3) {
    var e2;
    if (!(function(t4) {
      if (null == t4 || !t4.setData) return false;
      for (const e3 in Et) {
        const i3 = Et[e3];
        try {
          if (t4.setData(e3, i3), !t4.getData(e3) === i3) return false;
        } catch (t5) {
          return false;
        }
      }
      return true;
    })(t3)) return;
    const i2 = null === (e2 = this.responder) || void 0 === e2 ? void 0 : e2.getSelectedDocument().toSerializableDocument();
    return t3.setData("application/x-trix-document", JSON.stringify(i2)), t3.setData("text/html", Ni.render(i2).innerHTML), t3.setData("text/plain", i2.toString().replace(/\n$/, "")), true;
  }
  canAcceptDataTransfer(t3) {
    const e2 = {};
    return Array.from((null == t3 ? void 0 : t3.types) || []).forEach(((t4) => {
      e2[t4] = true;
    })), e2.Files || e2["application/x-trix-document"] || e2["text/html"] || e2["text/plain"];
  }
  getPastedHTMLUsingHiddenElement(t3) {
    const e2 = this.getSelectedRange(), i2 = { position: "absolute", left: "".concat(window.pageXOffset, "px"), top: "".concat(window.pageYOffset, "px"), opacity: 0 }, n2 = w({ style: i2, tagName: "div", editable: true });
    return document.body.appendChild(n2), n2.focus(), requestAnimationFrame((() => {
      const i3 = n2.innerHTML;
      return R(n2), this.setSelectedRange(e2), t3(i3);
    }));
  }
}
_i(yr, "events", { keydown(t3) {
  this.isComposing() || this.resetInputSummary(), this.inputSummary.didInput = true;
  const e2 = vr[t3.keyCode];
  if (e2) {
    var i2;
    let n3 = this.keys;
    ["ctrl", "alt", "shift", "meta"].forEach(((e3) => {
      var i3;
      t3["".concat(e3, "Key")] && ("ctrl" === e3 && (e3 = "control"), n3 = null === (i3 = n3) || void 0 === i3 ? void 0 : i3[e3]);
    })), null != (null === (i2 = n3) || void 0 === i2 ? void 0 : i2[e2]) && (this.setInputSummary({ keyName: e2 }), Pt.reset(), n3[e2].call(this, t3));
  }
  if (Rt(t3)) {
    const e3 = String.fromCharCode(t3.keyCode).toLowerCase();
    if (e3) {
      var n2;
      const i3 = ["alt", "shift"].map(((e4) => {
        if (t3["".concat(e4, "Key")]) return e4;
      })).filter(((t4) => t4));
      i3.push(e3), null !== (n2 = this.delegate) && void 0 !== n2 && n2.inputControllerDidReceiveKeyboardCommand(i3) && t3.preventDefault();
    }
  }
}, keypress(t3) {
  if (null != this.inputSummary.eventName) return;
  if (t3.metaKey) return;
  if (t3.ctrlKey && !t3.altKey) return;
  const e2 = Er(t3);
  var i2, n2;
  return e2 ? (null === (i2 = this.delegate) || void 0 === i2 || i2.inputControllerWillPerformTyping(), null === (n2 = this.responder) || void 0 === n2 || n2.insertString(e2), this.setInputSummary({ textAdded: e2, didDelete: this.selectionIsExpanded() })) : void 0;
}, textInput(t3) {
  const { data: e2 } = t3, { textAdded: i2 } = this.inputSummary;
  if (i2 && i2 !== e2 && i2.toUpperCase() === e2) {
    var n2;
    const t4 = this.getSelectedRange();
    return this.setSelectedRange([t4[0], t4[1] + i2.length]), null === (n2 = this.responder) || void 0 === n2 || n2.insertString(e2), this.setInputSummary({ textAdded: e2 }), this.setSelectedRange(t4);
  }
}, dragenter(t3) {
  t3.preventDefault();
}, dragstart(t3) {
  var e2, i2;
  return this.serializeSelectionToDataTransfer(t3.dataTransfer), this.draggedRange = this.getSelectedRange(), null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.inputControllerDidStartDrag) || void 0 === i2 ? void 0 : i2.call(e2);
}, dragover(t3) {
  if (this.draggedRange || this.canAcceptDataTransfer(t3.dataTransfer)) {
    t3.preventDefault();
    const n2 = { x: t3.clientX, y: t3.clientY };
    var e2, i2;
    if (!wt(n2, this.draggingPoint)) return this.draggingPoint = n2, null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.inputControllerDidReceiveDragOverPoint) || void 0 === i2 ? void 0 : i2.call(e2, this.draggingPoint);
  }
}, dragend(t3) {
  var e2, i2;
  null === (e2 = this.delegate) || void 0 === e2 || null === (i2 = e2.inputControllerDidCancelDrag) || void 0 === i2 || i2.call(e2), this.draggedRange = null, this.draggingPoint = null;
}, drop(t3) {
  var e2, i2;
  t3.preventDefault();
  const n2 = null === (e2 = t3.dataTransfer) || void 0 === e2 ? void 0 : e2.files, r2 = t3.dataTransfer.getData("application/x-trix-document"), o2 = { x: t3.clientX, y: t3.clientY };
  if (null === (i2 = this.responder) || void 0 === i2 || i2.setLocationRangeFromPointRange(o2), null != n2 && n2.length) this.attachFiles(n2);
  else if (this.draggedRange) {
    var s2, a2;
    null === (s2 = this.delegate) || void 0 === s2 || s2.inputControllerWillMoveText(), null === (a2 = this.responder) || void 0 === a2 || a2.moveTextFromRange(this.draggedRange), this.draggedRange = null, this.requestRender();
  } else if (r2) {
    var l2;
    const t4 = mn.fromJSONString(r2);
    null === (l2 = this.responder) || void 0 === l2 || l2.insertDocument(t4), this.requestRender();
  }
  this.draggedRange = null, this.draggingPoint = null;
}, cut(t3) {
  var e2, i2;
  if (null !== (e2 = this.responder) && void 0 !== e2 && e2.selectionIsExpanded() && (this.serializeSelectionToDataTransfer(t3.clipboardData) && t3.preventDefault(), null === (i2 = this.delegate) || void 0 === i2 || i2.inputControllerWillCutText(), this.deleteInDirection("backward"), t3.defaultPrevented)) return this.requestRender();
}, copy(t3) {
  var e2;
  null !== (e2 = this.responder) && void 0 !== e2 && e2.selectionIsExpanded() && this.serializeSelectionToDataTransfer(t3.clipboardData) && t3.preventDefault();
}, paste(t3) {
  const e2 = t3.clipboardData || t3.testClipboardData, i2 = { clipboard: e2 };
  if (!e2 || Sr(t3)) return void this.getPastedHTMLUsingHiddenElement(((t4) => {
    var e3, n3, r3;
    return i2.type = "text/html", i2.html = t4, null === (e3 = this.delegate) || void 0 === e3 || e3.inputControllerWillPaste(i2), null === (n3 = this.responder) || void 0 === n3 || n3.insertHTML(i2.html), this.requestRender(), null === (r3 = this.delegate) || void 0 === r3 ? void 0 : r3.inputControllerDidPaste(i2);
  }));
  const n2 = e2.getData("URL"), r2 = e2.getData("text/html"), o2 = e2.getData("public.url-name");
  if (n2) {
    var s2, a2, l2;
    let t4;
    i2.type = "text/html", t4 = o2 ? zt(o2).trim() : n2, i2.html = this.createLinkHTML(n2, t4), null === (s2 = this.delegate) || void 0 === s2 || s2.inputControllerWillPaste(i2), this.setInputSummary({ textAdded: t4, didDelete: this.selectionIsExpanded() }), null === (a2 = this.responder) || void 0 === a2 || a2.insertHTML(i2.html), this.requestRender(), null === (l2 = this.delegate) || void 0 === l2 || l2.inputControllerDidPaste(i2);
  } else if (St(e2)) {
    var c2, u2, h2;
    i2.type = "text/plain", i2.string = e2.getData("text/plain"), null === (c2 = this.delegate) || void 0 === c2 || c2.inputControllerWillPaste(i2), this.setInputSummary({ textAdded: i2.string, didDelete: this.selectionIsExpanded() }), null === (u2 = this.responder) || void 0 === u2 || u2.insertString(i2.string), this.requestRender(), null === (h2 = this.delegate) || void 0 === h2 || h2.inputControllerDidPaste(i2);
  } else if (r2) {
    var d2, g2, m2;
    i2.type = "text/html", i2.html = r2, null === (d2 = this.delegate) || void 0 === d2 || d2.inputControllerWillPaste(i2), null === (g2 = this.responder) || void 0 === g2 || g2.insertHTML(i2.html), this.requestRender(), null === (m2 = this.delegate) || void 0 === m2 || m2.inputControllerDidPaste(i2);
  } else if (Array.from(e2.types).includes("Files")) {
    var p2, f2;
    const t4 = null === (p2 = e2.items) || void 0 === p2 || null === (p2 = p2[0]) || void 0 === p2 || null === (f2 = p2.getAsFile) || void 0 === f2 ? void 0 : f2.call(p2);
    if (t4) {
      var b2, v2, A2;
      const e3 = xr(t4);
      !t4.name && e3 && (t4.name = "pasted-file-".concat(++Ar, ".").concat(e3)), i2.type = "File", i2.file = t4, null === (b2 = this.delegate) || void 0 === b2 || b2.inputControllerWillAttachFiles(), null === (v2 = this.responder) || void 0 === v2 || v2.insertFile(i2.file), this.requestRender(), null === (A2 = this.delegate) || void 0 === A2 || A2.inputControllerDidPaste(i2);
    }
  }
  t3.preventDefault();
}, compositionstart(t3) {
  return this.getCompositionInput().start(t3.data);
}, compositionupdate(t3) {
  return this.getCompositionInput().update(t3.data);
}, compositionend(t3) {
  return this.getCompositionInput().end(t3.data);
}, beforeinput(t3) {
  this.inputSummary.didInput = true;
}, input(t3) {
  return this.inputSummary.didInput = true, t3.stopPropagation();
} }), _i(yr, "keys", { backspace(t3) {
  var e2;
  return null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), this.deleteInDirection("backward", t3);
}, delete(t3) {
  var e2;
  return null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), this.deleteInDirection("forward", t3);
}, return(t3) {
  var e2, i2;
  return this.setInputSummary({ preferDocument: true }), null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), null === (i2 = this.responder) || void 0 === i2 ? void 0 : i2.insertLineBreak();
}, tab(t3) {
  var e2, i2;
  null !== (e2 = this.responder) && void 0 !== e2 && e2.canIncreaseNestingLevel() && (null === (i2 = this.responder) || void 0 === i2 || i2.increaseNestingLevel(), this.requestRender(), t3.preventDefault());
}, left(t3) {
  var e2;
  if (this.selectionIsInCursorTarget()) return t3.preventDefault(), null === (e2 = this.responder) || void 0 === e2 ? void 0 : e2.moveCursorInDirection("backward");
}, right(t3) {
  var e2;
  if (this.selectionIsInCursorTarget()) return t3.preventDefault(), null === (e2 = this.responder) || void 0 === e2 ? void 0 : e2.moveCursorInDirection("forward");
}, control: { d(t3) {
  var e2;
  return null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), this.deleteInDirection("forward", t3);
}, h(t3) {
  var e2;
  return null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), this.deleteInDirection("backward", t3);
}, o(t3) {
  var e2, i2;
  return t3.preventDefault(), null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), null === (i2 = this.responder) || void 0 === i2 || i2.insertString("\n", { updatePosition: false }), this.requestRender();
} }, shift: { return(t3) {
  var e2, i2;
  null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), null === (i2 = this.responder) || void 0 === i2 || i2.insertString("\n"), this.requestRender(), t3.preventDefault();
}, tab(t3) {
  var e2, i2;
  null !== (e2 = this.responder) && void 0 !== e2 && e2.canDecreaseNestingLevel() && (null === (i2 = this.responder) || void 0 === i2 || i2.decreaseNestingLevel(), this.requestRender(), t3.preventDefault());
}, left(t3) {
  if (this.selectionIsInCursorTarget()) return t3.preventDefault(), this.expandSelectionInDirection("backward");
}, right(t3) {
  if (this.selectionIsInCursorTarget()) return t3.preventDefault(), this.expandSelectionInDirection("forward");
} }, alt: { backspace(t3) {
  var e2;
  return this.setInputSummary({ preferDocument: false }), null === (e2 = this.delegate) || void 0 === e2 ? void 0 : e2.inputControllerWillPerformTyping();
} }, meta: { backspace(t3) {
  var e2;
  return this.setInputSummary({ preferDocument: false }), null === (e2 = this.delegate) || void 0 === e2 ? void 0 : e2.inputControllerWillPerformTyping();
} } }), yr.proxyMethod("responder?.getSelectedRange"), yr.proxyMethod("responder?.setSelectedRange"), yr.proxyMethod("responder?.expandSelectionInDirection"), yr.proxyMethod("responder?.selectionIsInCursorTarget"), yr.proxyMethod("responder?.selectionIsExpanded");
const xr = (t3) => {
  var e2;
  return null === (e2 = t3.type) || void 0 === e2 || null === (e2 = e2.match(/\/(\w+)$/)) || void 0 === e2 ? void 0 : e2[1];
}, Cr = !(null === (fr = " ".codePointAt) || void 0 === fr || !fr.call(" ", 0)), Er = function(t3) {
  if (t3.key && Cr && t3.key.codePointAt(0) === t3.keyCode) return t3.key;
  {
    let e2;
    if (null === t3.which ? e2 = t3.keyCode : 0 !== t3.which && 0 !== t3.charCode && (e2 = t3.charCode), null != e2 && "escape" !== vr[e2]) return Z.fromCodepoints([e2]).toString();
  }
}, Sr = function(t3) {
  const e2 = t3.clipboardData;
  if (e2) {
    if (e2.types.includes("text/html")) {
      for (const t4 of e2.types) {
        const i2 = /^CorePasteboardFlavorType/.test(t4), n2 = /^dyn\./.test(t4) && e2.getData(t4);
        if (i2 || n2) return true;
      }
      return false;
    }
    {
      const t4 = e2.types.includes("com.apple.webarchive"), i2 = e2.types.includes("com.apple.flat-rtfd");
      return t4 || i2;
    }
  }
};
class Rr extends q {
  constructor(t3) {
    super(...arguments), this.inputController = t3, this.responder = this.inputController.responder, this.delegate = this.inputController.delegate, this.inputSummary = this.inputController.inputSummary, this.data = {};
  }
  start(t3) {
    if (this.data.start = t3, this.isSignificant()) {
      var e2, i2;
      if ("keypress" === this.inputSummary.eventName && this.inputSummary.textAdded) null === (i2 = this.responder) || void 0 === i2 || i2.deleteInDirection("left");
      this.selectionIsExpanded() || (this.insertPlaceholder(), this.requestRender()), this.range = null === (e2 = this.responder) || void 0 === e2 ? void 0 : e2.getSelectedRange();
    }
  }
  update(t3) {
    if (this.data.update = t3, this.isSignificant()) {
      const t4 = this.selectPlaceholder();
      t4 && (this.forgetPlaceholder(), this.range = t4);
    }
  }
  end(t3) {
    return this.data.end = t3, this.isSignificant() ? (this.forgetPlaceholder(), this.canApplyToDocument() ? (this.setInputSummary({ preferDocument: true, didInput: false }), null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), null === (i2 = this.responder) || void 0 === i2 || i2.setSelectedRange(this.range), null === (n2 = this.responder) || void 0 === n2 || n2.insertString(this.data.end), null === (r2 = this.responder) || void 0 === r2 ? void 0 : r2.setSelectedRange(this.range[0] + this.data.end.length)) : null != this.data.start || null != this.data.update ? (this.requestReparse(), this.inputController.reset()) : void 0) : this.inputController.reset();
    var e2, i2, n2, r2;
  }
  getEndData() {
    return this.data.end;
  }
  isEnded() {
    return null != this.getEndData();
  }
  isSignificant() {
    return !br.composesExistingText || this.inputSummary.didInput;
  }
  canApplyToDocument() {
    var t3, e2;
    return 0 === (null === (t3 = this.data.start) || void 0 === t3 ? void 0 : t3.length) && (null === (e2 = this.data.end) || void 0 === e2 ? void 0 : e2.length) > 0 && this.range;
  }
}
Rr.proxyMethod("inputController.setInputSummary"), Rr.proxyMethod("inputController.requestRender"), Rr.proxyMethod("inputController.requestReparse"), Rr.proxyMethod("responder?.selectionIsExpanded"), Rr.proxyMethod("responder?.insertPlaceholder"), Rr.proxyMethod("responder?.selectPlaceholder"), Rr.proxyMethod("responder?.forgetPlaceholder");
class Tr extends pr {
  constructor() {
    super(...arguments), this.render = this.render.bind(this);
  }
  elementDidMutate() {
    return this.scheduledRender ? this.composing ? null === (t3 = this.delegate) || void 0 === t3 || null === (e2 = t3.inputControllerDidAllowUnhandledInput) || void 0 === e2 ? void 0 : e2.call(t3) : void 0 : this.reparse();
    var t3, e2;
  }
  scheduleRender() {
    return this.scheduledRender ? this.scheduledRender : this.scheduledRender = requestAnimationFrame(this.render);
  }
  render() {
    var t3, e2;
    (cancelAnimationFrame(this.scheduledRender), this.scheduledRender = null, this.composing) || (null === (e2 = this.delegate) || void 0 === e2 || e2.render());
    null === (t3 = this.afterRender) || void 0 === t3 || t3.call(this), this.afterRender = null;
  }
  reparse() {
    var t3;
    return null === (t3 = this.delegate) || void 0 === t3 ? void 0 : t3.reparse();
  }
  insertString() {
    var t3;
    let e2 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : "", i2 = arguments.length > 1 ? arguments[1] : void 0;
    return null === (t3 = this.delegate) || void 0 === t3 || t3.inputControllerWillPerformTyping(), this.withTargetDOMRange((function() {
      var t4;
      return null === (t4 = this.responder) || void 0 === t4 ? void 0 : t4.insertString(e2, i2);
    }));
  }
  toggleAttributeIfSupported(t3) {
    var e2;
    if (mt().includes(t3)) return null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformFormatting(t3), this.withTargetDOMRange((function() {
      var e3;
      return null === (e3 = this.responder) || void 0 === e3 ? void 0 : e3.toggleCurrentAttribute(t3);
    }));
  }
  activateAttributeIfSupported(t3, e2) {
    var i2;
    if (mt().includes(t3)) return null === (i2 = this.delegate) || void 0 === i2 || i2.inputControllerWillPerformFormatting(t3), this.withTargetDOMRange((function() {
      var i3;
      return null === (i3 = this.responder) || void 0 === i3 ? void 0 : i3.setCurrentAttribute(t3, e2);
    }));
  }
  deleteInDirection(t3) {
    let { recordUndoEntry: e2 } = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : { recordUndoEntry: true };
    var i2;
    e2 && (null === (i2 = this.delegate) || void 0 === i2 || i2.inputControllerWillPerformTyping());
    const n2 = () => {
      var e3;
      return null === (e3 = this.responder) || void 0 === e3 ? void 0 : e3.deleteInDirection(t3);
    }, r2 = this.getTargetDOMRange({ minLength: this.composing ? 1 : 2 });
    return r2 ? this.withTargetDOMRange(r2, n2) : n2();
  }
  withTargetDOMRange(t3, e2) {
    var i2;
    return "function" == typeof t3 && (e2 = t3, t3 = this.getTargetDOMRange()), t3 ? null === (i2 = this.responder) || void 0 === i2 ? void 0 : i2.withTargetDOMRange(t3, e2.bind(this)) : (Pt.reset(), e2.call(this));
  }
  getTargetDOMRange() {
    var t3, e2;
    let { minLength: i2 } = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : { minLength: 0 };
    const n2 = null === (t3 = (e2 = this.event).getTargetRanges) || void 0 === t3 ? void 0 : t3.call(e2);
    if (n2 && n2.length) {
      const t4 = kr(n2[0]);
      if (0 === i2 || t4.toString().length >= i2) return t4;
    }
  }
  withEvent(t3, e2) {
    let i2;
    this.event = t3;
    try {
      i2 = e2.call(this);
    } finally {
      this.event = null;
    }
    return i2;
  }
}
_i(Tr, "events", { keydown(t3) {
  if (Rt(t3)) {
    var e2;
    const i2 = Ir(t3);
    null !== (e2 = this.delegate) && void 0 !== e2 && e2.inputControllerDidReceiveKeyboardCommand(i2) && t3.preventDefault();
  } else {
    let e3 = t3.key;
    t3.altKey && (e3 += "+Alt"), t3.shiftKey && (e3 += "+Shift");
    const i2 = this.constructor.keys[e3];
    if (i2) return this.withEvent(t3, i2);
  }
}, paste(t3) {
  var e2;
  let i2;
  const n2 = null === (e2 = t3.clipboardData) || void 0 === e2 ? void 0 : e2.getData("URL");
  return Lr(t3) ? (t3.preventDefault(), this.attachFiles(t3.clipboardData.files)) : Nr(t3) ? (t3.preventDefault(), i2 = { type: "text/plain", string: t3.clipboardData.getData("text/plain") }, null === (r2 = this.delegate) || void 0 === r2 || r2.inputControllerWillPaste(i2), null === (o2 = this.responder) || void 0 === o2 || o2.insertString(i2.string), this.render(), null === (s2 = this.delegate) || void 0 === s2 ? void 0 : s2.inputControllerDidPaste(i2)) : n2 ? (t3.preventDefault(), i2 = { type: "text/html", html: this.createLinkHTML(n2) }, null === (a2 = this.delegate) || void 0 === a2 || a2.inputControllerWillPaste(i2), null === (l2 = this.responder) || void 0 === l2 || l2.insertHTML(i2.html), this.render(), null === (c2 = this.delegate) || void 0 === c2 ? void 0 : c2.inputControllerDidPaste(i2)) : void 0;
  var r2, o2, s2, a2, l2, c2;
}, beforeinput(t3) {
  const e2 = this.constructor.inputTypes[t3.inputType], i2 = (n2 = t3, !(!/iPhone|iPad/.test(navigator.userAgent) || n2.inputType && "insertParagraph" !== n2.inputType));
  var n2;
  e2 && (this.withEvent(t3, e2), i2 || this.scheduleRender()), i2 && this.render();
}, input(t3) {
  Pt.reset();
}, dragstart(t3) {
  var e2, i2;
  null !== (e2 = this.responder) && void 0 !== e2 && e2.selectionContainsAttachments() && (t3.dataTransfer.setData("application/x-trix-dragging", true), this.dragging = { range: null === (i2 = this.responder) || void 0 === i2 ? void 0 : i2.getSelectedRange(), point: Or(t3) });
}, dragenter(t3) {
  wr(t3) && t3.preventDefault();
}, dragover(t3) {
  if (this.dragging) {
    t3.preventDefault();
    const i2 = Or(t3);
    var e2;
    if (!wt(i2, this.dragging.point)) return this.dragging.point = i2, null === (e2 = this.responder) || void 0 === e2 ? void 0 : e2.setLocationRangeFromPointRange(i2);
  } else wr(t3) && t3.preventDefault();
}, drop(t3) {
  var e2, i2;
  if (this.dragging) return t3.preventDefault(), null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillMoveText(), null === (i2 = this.responder) || void 0 === i2 || i2.moveTextFromRange(this.dragging.range), this.dragging = null, this.scheduleRender();
  if (wr(t3)) {
    var n2;
    t3.preventDefault();
    const e3 = Or(t3);
    return null === (n2 = this.responder) || void 0 === n2 || n2.setLocationRangeFromPointRange(e3), this.attachFiles(t3.dataTransfer.files);
  }
}, dragend() {
  var t3;
  this.dragging && (null === (t3 = this.responder) || void 0 === t3 || t3.setSelectedRange(this.dragging.range), this.dragging = null);
}, compositionend(t3) {
  this.composing && (this.composing = false, a.recentAndroid || this.scheduleRender());
} }), _i(Tr, "keys", { ArrowLeft() {
  var t3, e2;
  if (null !== (t3 = this.responder) && void 0 !== t3 && t3.shouldManageMovingCursorInDirection("backward")) return this.event.preventDefault(), null === (e2 = this.responder) || void 0 === e2 ? void 0 : e2.moveCursorInDirection("backward");
}, ArrowRight() {
  var t3, e2;
  if (null !== (t3 = this.responder) && void 0 !== t3 && t3.shouldManageMovingCursorInDirection("forward")) return this.event.preventDefault(), null === (e2 = this.responder) || void 0 === e2 ? void 0 : e2.moveCursorInDirection("forward");
}, Backspace() {
  var t3, e2, i2;
  if (null !== (t3 = this.responder) && void 0 !== t3 && t3.shouldManageDeletingInDirection("backward")) return this.event.preventDefault(), null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillPerformTyping(), null === (i2 = this.responder) || void 0 === i2 || i2.deleteInDirection("backward"), this.render();
}, Tab() {
  var t3, e2;
  if (null !== (t3 = this.responder) && void 0 !== t3 && t3.canIncreaseNestingLevel()) return this.event.preventDefault(), null === (e2 = this.responder) || void 0 === e2 || e2.increaseNestingLevel(), this.render();
}, "Tab+Shift"() {
  var t3, e2;
  if (null !== (t3 = this.responder) && void 0 !== t3 && t3.canDecreaseNestingLevel()) return this.event.preventDefault(), null === (e2 = this.responder) || void 0 === e2 || e2.decreaseNestingLevel(), this.render();
} }), _i(Tr, "inputTypes", { deleteByComposition() {
  return this.deleteInDirection("backward", { recordUndoEntry: false });
}, deleteByCut() {
  return this.deleteInDirection("backward");
}, deleteByDrag() {
  return this.event.preventDefault(), this.withTargetDOMRange((function() {
    var t3;
    this.deleteByDragRange = null === (t3 = this.responder) || void 0 === t3 ? void 0 : t3.getSelectedRange();
  }));
}, deleteCompositionText() {
  return this.deleteInDirection("backward", { recordUndoEntry: false });
}, deleteContent() {
  return this.deleteInDirection("backward");
}, deleteContentBackward() {
  return this.deleteInDirection("backward");
}, deleteContentForward() {
  return this.deleteInDirection("forward");
}, deleteEntireSoftLine() {
  return this.deleteInDirection("forward");
}, deleteHardLineBackward() {
  return this.deleteInDirection("backward");
}, deleteHardLineForward() {
  return this.deleteInDirection("forward");
}, deleteSoftLineBackward() {
  return this.deleteInDirection("backward");
}, deleteSoftLineForward() {
  return this.deleteInDirection("forward");
}, deleteWordBackward() {
  return this.deleteInDirection("backward");
}, deleteWordForward() {
  return this.deleteInDirection("forward");
}, formatBackColor() {
  return this.activateAttributeIfSupported("backgroundColor", this.event.data);
}, formatBold() {
  return this.toggleAttributeIfSupported("bold");
}, formatFontColor() {
  return this.activateAttributeIfSupported("color", this.event.data);
}, formatFontName() {
  return this.activateAttributeIfSupported("font", this.event.data);
}, formatIndent() {
  var t3;
  if (null !== (t3 = this.responder) && void 0 !== t3 && t3.canIncreaseNestingLevel()) return this.withTargetDOMRange((function() {
    var t4;
    return null === (t4 = this.responder) || void 0 === t4 ? void 0 : t4.increaseNestingLevel();
  }));
}, formatItalic() {
  return this.toggleAttributeIfSupported("italic");
}, formatJustifyCenter() {
  return this.toggleAttributeIfSupported("justifyCenter");
}, formatJustifyFull() {
  return this.toggleAttributeIfSupported("justifyFull");
}, formatJustifyLeft() {
  return this.toggleAttributeIfSupported("justifyLeft");
}, formatJustifyRight() {
  return this.toggleAttributeIfSupported("justifyRight");
}, formatOutdent() {
  var t3;
  if (null !== (t3 = this.responder) && void 0 !== t3 && t3.canDecreaseNestingLevel()) return this.withTargetDOMRange((function() {
    var t4;
    return null === (t4 = this.responder) || void 0 === t4 ? void 0 : t4.decreaseNestingLevel();
  }));
}, formatRemove() {
  this.withTargetDOMRange((function() {
    for (const i2 in null === (t3 = this.responder) || void 0 === t3 ? void 0 : t3.getCurrentAttributes()) {
      var t3, e2;
      null === (e2 = this.responder) || void 0 === e2 || e2.removeCurrentAttribute(i2);
    }
  }));
}, formatSetBlockTextDirection() {
  return this.activateAttributeIfSupported("blockDir", this.event.data);
}, formatSetInlineTextDirection() {
  return this.activateAttributeIfSupported("textDir", this.event.data);
}, formatStrikeThrough() {
  return this.toggleAttributeIfSupported("strike");
}, formatSubscript() {
  return this.toggleAttributeIfSupported("sub");
}, formatSuperscript() {
  return this.toggleAttributeIfSupported("sup");
}, formatUnderline() {
  return this.toggleAttributeIfSupported("underline");
}, historyRedo() {
  var t3;
  return null === (t3 = this.delegate) || void 0 === t3 ? void 0 : t3.inputControllerWillPerformRedo();
}, historyUndo() {
  var t3;
  return null === (t3 = this.delegate) || void 0 === t3 ? void 0 : t3.inputControllerWillPerformUndo();
}, insertCompositionText() {
  return this.composing = true, this.insertString(this.event.data);
}, insertFromComposition() {
  return this.composing = false, this.insertString(this.event.data);
}, insertFromDrop() {
  const t3 = this.deleteByDragRange;
  var e2;
  if (t3) return this.deleteByDragRange = null, null === (e2 = this.delegate) || void 0 === e2 || e2.inputControllerWillMoveText(), this.withTargetDOMRange((function() {
    var e3;
    return null === (e3 = this.responder) || void 0 === e3 ? void 0 : e3.moveTextFromRange(t3);
  }));
}, insertFromPaste() {
  const { dataTransfer: t3 } = this.event, e2 = { dataTransfer: t3 }, i2 = t3.getData("URL"), n2 = t3.getData("text/html");
  if (i2) {
    var r2;
    let n3;
    this.event.preventDefault(), e2.type = "text/html";
    const o3 = t3.getData("public.url-name");
    n3 = o3 ? zt(o3).trim() : i2, e2.html = this.createLinkHTML(i2, n3), null === (r2 = this.delegate) || void 0 === r2 || r2.inputControllerWillPaste(e2), this.withTargetDOMRange((function() {
      var t4;
      return null === (t4 = this.responder) || void 0 === t4 ? void 0 : t4.insertHTML(e2.html);
    })), this.afterRender = () => {
      var t4;
      return null === (t4 = this.delegate) || void 0 === t4 ? void 0 : t4.inputControllerDidPaste(e2);
    };
  } else if (St(t3)) {
    var o2;
    e2.type = "text/plain", e2.string = t3.getData("text/plain"), null === (o2 = this.delegate) || void 0 === o2 || o2.inputControllerWillPaste(e2), this.withTargetDOMRange((function() {
      var t4;
      return null === (t4 = this.responder) || void 0 === t4 ? void 0 : t4.insertString(e2.string);
    })), this.afterRender = () => {
      var t4;
      return null === (t4 = this.delegate) || void 0 === t4 ? void 0 : t4.inputControllerDidPaste(e2);
    };
  } else if (Dr(this.event)) {
    var s2;
    e2.type = "File", e2.file = t3.files[0], null === (s2 = this.delegate) || void 0 === s2 || s2.inputControllerWillPaste(e2), this.withTargetDOMRange((function() {
      var t4;
      return null === (t4 = this.responder) || void 0 === t4 ? void 0 : t4.insertFile(e2.file);
    })), this.afterRender = () => {
      var t4;
      return null === (t4 = this.delegate) || void 0 === t4 ? void 0 : t4.inputControllerDidPaste(e2);
    };
  } else if (n2) {
    var a2;
    this.event.preventDefault(), e2.type = "text/html", e2.html = n2, null === (a2 = this.delegate) || void 0 === a2 || a2.inputControllerWillPaste(e2), this.withTargetDOMRange((function() {
      var t4;
      return null === (t4 = this.responder) || void 0 === t4 ? void 0 : t4.insertHTML(e2.html);
    })), this.afterRender = () => {
      var t4;
      return null === (t4 = this.delegate) || void 0 === t4 ? void 0 : t4.inputControllerDidPaste(e2);
    };
  }
}, insertFromYank() {
  return this.insertString(this.event.data);
}, insertLineBreak() {
  return this.insertString("\n");
}, insertLink() {
  return this.activateAttributeIfSupported("href", this.event.data);
}, insertOrderedList() {
  return this.toggleAttributeIfSupported("number");
}, insertParagraph() {
  var t3;
  return null === (t3 = this.delegate) || void 0 === t3 || t3.inputControllerWillPerformTyping(), this.withTargetDOMRange((function() {
    var t4;
    return null === (t4 = this.responder) || void 0 === t4 ? void 0 : t4.insertLineBreak();
  }));
}, insertReplacementText() {
  const t3 = this.event.dataTransfer.getData("text/plain"), e2 = this.event.getTargetRanges()[0];
  this.withTargetDOMRange(e2, (() => {
    this.insertString(t3, { updatePosition: false });
  }));
}, insertText() {
  var t3;
  return this.insertString(this.event.data || (null === (t3 = this.event.dataTransfer) || void 0 === t3 ? void 0 : t3.getData("text/plain")));
}, insertTranspose() {
  return this.insertString(this.event.data);
}, insertUnorderedList() {
  return this.toggleAttributeIfSupported("bullet");
} });
const kr = function(t3) {
  const e2 = document.createRange();
  return e2.setStart(t3.startContainer, t3.startOffset), e2.setEnd(t3.endContainer, t3.endOffset), e2;
}, wr = (t3) => {
  var e2;
  return Array.from((null === (e2 = t3.dataTransfer) || void 0 === e2 ? void 0 : e2.types) || []).includes("Files");
}, Dr = (t3) => {
  var e2;
  return (null === (e2 = t3.dataTransfer.files) || void 0 === e2 ? void 0 : e2[0]) && !Lr(t3) && !((t4) => {
    let { dataTransfer: e3 } = t4;
    return e3.types.includes("Files") && e3.types.includes("text/html") && e3.getData("text/html").includes("urn:schemas-microsoft-com:office:office");
  })(t3);
}, Lr = function(t3) {
  const e2 = t3.clipboardData;
  if (e2) {
    return Array.from(e2.types).filter(((t4) => t4.match(/file/i))).length === e2.types.length && e2.files.length >= 1;
  }
}, Nr = function(t3) {
  const e2 = t3.clipboardData;
  if (e2) return e2.types.includes("text/plain") && 1 === e2.types.length;
}, Ir = function(t3) {
  const e2 = [];
  return t3.altKey && e2.push("alt"), t3.shiftKey && e2.push("shift"), e2.push(t3.key), e2;
}, Or = (t3) => ({ x: t3.clientX, y: t3.clientY }), Fr = "[data-trix-attribute]", Pr = "[data-trix-action]", Mr = "".concat(Fr, ", ").concat(Pr), Br = "[data-trix-dialog]", _r = "".concat(Br, "[data-trix-active]"), jr = "".concat(Br, " [data-trix-method]"), Wr = "".concat(Br, " [data-trix-input]"), Ur = (t3, e2) => (e2 || (e2 = zr(t3)), t3.querySelector("[data-trix-input][name='".concat(e2, "']"))), Vr = (t3) => t3.getAttribute("data-trix-action"), zr = (t3) => t3.getAttribute("data-trix-attribute") || t3.getAttribute("data-trix-dialog-attribute");
class Hr extends q {
  constructor(t3) {
    super(t3), this.didClickActionButton = this.didClickActionButton.bind(this), this.didClickAttributeButton = this.didClickAttributeButton.bind(this), this.didClickDialogButton = this.didClickDialogButton.bind(this), this.didKeyDownDialogInput = this.didKeyDownDialogInput.bind(this), this.element = t3, this.attributes = {}, this.actions = {}, this.resetDialogInputs(), b("mousedown", { onElement: this.element, matchingSelector: Pr, withCallback: this.didClickActionButton }), b("mousedown", { onElement: this.element, matchingSelector: Fr, withCallback: this.didClickAttributeButton }), b("click", { onElement: this.element, matchingSelector: Mr, preventDefault: true }), b("click", { onElement: this.element, matchingSelector: jr, withCallback: this.didClickDialogButton }), b("keydown", { onElement: this.element, matchingSelector: Wr, withCallback: this.didKeyDownDialogInput });
  }
  didClickActionButton(t3, e2) {
    var i2;
    null === (i2 = this.delegate) || void 0 === i2 || i2.toolbarDidClickButton(), t3.preventDefault();
    const n2 = Vr(e2);
    return this.getDialog(n2) ? this.toggleDialog(n2) : null === (r2 = this.delegate) || void 0 === r2 ? void 0 : r2.toolbarDidInvokeAction(n2, e2);
    var r2;
  }
  didClickAttributeButton(t3, e2) {
    var i2;
    null === (i2 = this.delegate) || void 0 === i2 || i2.toolbarDidClickButton(), t3.preventDefault();
    const n2 = zr(e2);
    var r2;
    this.getDialog(n2) ? this.toggleDialog(n2) : null === (r2 = this.delegate) || void 0 === r2 || r2.toolbarDidToggleAttribute(n2);
    return this.refreshAttributeButtons();
  }
  didClickDialogButton(t3, e2) {
    const i2 = x(e2, { matchingSelector: Br });
    return this[e2.getAttribute("data-trix-method")].call(this, i2);
  }
  didKeyDownDialogInput(t3, e2) {
    if (13 === t3.keyCode) {
      t3.preventDefault();
      const i2 = e2.getAttribute("name"), n2 = this.getDialog(i2);
      this.setAttribute(n2);
    }
    if (27 === t3.keyCode) return t3.preventDefault(), this.hideDialog();
  }
  updateActions(t3) {
    return this.actions = t3, this.refreshActionButtons();
  }
  refreshActionButtons() {
    return this.eachActionButton(((t3, e2) => {
      t3.disabled = false === this.actions[e2];
    }));
  }
  eachActionButton(t3) {
    return Array.from(this.element.querySelectorAll(Pr)).map(((e2) => t3(e2, Vr(e2))));
  }
  updateAttributes(t3) {
    return this.attributes = t3, this.refreshAttributeButtons();
  }
  refreshAttributeButtons() {
    return this.eachAttributeButton(((t3, e2) => (t3.disabled = false === this.attributes[e2], this.attributes[e2] || this.dialogIsVisible(e2) ? (t3.setAttribute("data-trix-active", ""), t3.classList.add("trix-active")) : (t3.removeAttribute("data-trix-active"), t3.classList.remove("trix-active")))));
  }
  eachAttributeButton(t3) {
    return Array.from(this.element.querySelectorAll(Fr)).map(((e2) => t3(e2, zr(e2))));
  }
  applyKeyboardCommand(t3) {
    const e2 = JSON.stringify(t3.sort());
    for (const t4 of Array.from(this.element.querySelectorAll("[data-trix-key]"))) {
      const i2 = t4.getAttribute("data-trix-key").split("+");
      if (JSON.stringify(i2.sort()) === e2) return A("mousedown", { onElement: t4 }), true;
    }
    return false;
  }
  dialogIsVisible(t3) {
    const e2 = this.getDialog(t3);
    if (e2) return e2.hasAttribute("data-trix-active");
  }
  toggleDialog(t3) {
    return this.dialogIsVisible(t3) ? this.hideDialog() : this.showDialog(t3);
  }
  showDialog(t3) {
    var e2, i2;
    this.hideDialog(), null === (e2 = this.delegate) || void 0 === e2 || e2.toolbarWillShowDialog();
    const n2 = this.getDialog(t3);
    n2.setAttribute("data-trix-active", ""), n2.classList.add("trix-active"), Array.from(n2.querySelectorAll("input[disabled]")).forEach(((t4) => {
      t4.removeAttribute("disabled");
    }));
    const r2 = zr(n2);
    if (r2) {
      const e3 = Ur(n2, t3);
      e3 && (e3.value = this.attributes[r2] || "", e3.select());
    }
    return null === (i2 = this.delegate) || void 0 === i2 ? void 0 : i2.toolbarDidShowDialog(t3);
  }
  setAttribute(t3) {
    var e2;
    const i2 = zr(t3), n2 = Ur(t3, i2);
    return !n2.willValidate || (n2.setCustomValidity(""), n2.checkValidity() && this.isSafeAttribute(n2)) ? (null === (e2 = this.delegate) || void 0 === e2 || e2.toolbarDidUpdateAttribute(i2, n2.value), this.hideDialog()) : (n2.setCustomValidity("Invalid value"), n2.setAttribute("data-trix-validate", ""), n2.classList.add("trix-validate"), n2.focus());
  }
  isSafeAttribute(t3) {
    return !t3.hasAttribute("data-trix-validate-href") || pi.isValidAttribute("a", "href", t3.value);
  }
  removeAttribute(t3) {
    var e2;
    const i2 = zr(t3);
    return null === (e2 = this.delegate) || void 0 === e2 || e2.toolbarDidRemoveAttribute(i2), this.hideDialog();
  }
  hideDialog() {
    const t3 = this.element.querySelector(_r);
    var e2;
    if (t3) return t3.removeAttribute("data-trix-active"), t3.classList.remove("trix-active"), this.resetDialogInputs(), null === (e2 = this.delegate) || void 0 === e2 ? void 0 : e2.toolbarDidHideDialog(((t4) => t4.getAttribute("data-trix-dialog"))(t3));
  }
  resetDialogInputs() {
    Array.from(this.element.querySelectorAll(Wr)).forEach(((t3) => {
      t3.setAttribute("disabled", "disabled"), t3.removeAttribute("data-trix-validate"), t3.classList.remove("trix-validate");
    }));
  }
  getDialog(t3) {
    return this.element.querySelector("[data-trix-dialog=".concat(t3, "]"));
  }
}
class qr extends rr {
  constructor(t3) {
    let { editorElement: e2, document: i2, html: n2 } = t3;
    super(...arguments), this.editorElement = e2, this.selectionManager = new Xn(this.editorElement), this.selectionManager.delegate = this, this.composition = new Pn(), this.composition.delegate = this, this.attachmentManager = new On(this.composition.getAttachments()), this.attachmentManager.delegate = this, this.inputController = 2 === j.getLevel() ? new Tr(this.editorElement) : new yr(this.editorElement), this.inputController.delegate = this, this.inputController.responder = this.composition, this.compositionController = new nr(this.editorElement, this.composition), this.compositionController.delegate = this, this.toolbarController = new Hr(this.editorElement.toolbarElement), this.toolbarController.delegate = this, this.editor = new Vn(this.composition, this.selectionManager, this.editorElement), i2 ? this.editor.loadDocument(i2) : this.editor.loadHTML(n2);
  }
  registerSelectionManager() {
    return Pt.registerSelectionManager(this.selectionManager);
  }
  unregisterSelectionManager() {
    return Pt.unregisterSelectionManager(this.selectionManager);
  }
  render() {
    return this.compositionController.render();
  }
  reparse() {
    return this.composition.replaceHTML(this.editorElement.innerHTML);
  }
  compositionDidChangeDocument(t3) {
    if (this.notifyEditorElement("document-change"), !this.handlingInput) return this.render();
  }
  compositionDidChangeCurrentAttributes(t3) {
    return this.currentAttributes = t3, this.toolbarController.updateAttributes(this.currentAttributes), this.updateCurrentActions(), this.notifyEditorElement("attributes-change", { attributes: this.currentAttributes });
  }
  compositionDidPerformInsertionAtRange(t3) {
    this.pasting && (this.pastedRange = t3);
  }
  compositionShouldAcceptFile(t3) {
    return this.notifyEditorElement("file-accept", { file: t3 });
  }
  compositionDidAddAttachment(t3) {
    const e2 = this.attachmentManager.manageAttachment(t3);
    return this.notifyEditorElement("attachment-add", { attachment: e2 });
  }
  compositionDidEditAttachment(t3) {
    this.compositionController.rerenderViewForObject(t3);
    const e2 = this.attachmentManager.manageAttachment(t3);
    return this.notifyEditorElement("attachment-edit", { attachment: e2 }), this.notifyEditorElement("change");
  }
  compositionDidChangeAttachmentPreviewURL(t3) {
    return this.compositionController.invalidateViewForObject(t3), this.notifyEditorElement("change");
  }
  compositionDidRemoveAttachment(t3) {
    const e2 = this.attachmentManager.unmanageAttachment(t3);
    return this.notifyEditorElement("attachment-remove", { attachment: e2 });
  }
  compositionDidStartEditingAttachment(t3, e2) {
    return this.attachmentLocationRange = this.composition.document.getLocationRangeOfAttachment(t3), this.compositionController.installAttachmentEditorForAttachment(t3, e2), this.selectionManager.setLocationRange(this.attachmentLocationRange);
  }
  compositionDidStopEditingAttachment(t3) {
    this.compositionController.uninstallAttachmentEditor(), this.attachmentLocationRange = null;
  }
  compositionDidRequestChangingSelectionToLocationRange(t3) {
    if (!this.loadingSnapshot || this.isFocused()) return this.requestedLocationRange = t3, this.compositionRevisionWhenLocationRangeRequested = this.composition.revision, this.handlingInput ? void 0 : this.render();
  }
  compositionWillLoadSnapshot() {
    this.loadingSnapshot = true;
  }
  compositionDidLoadSnapshot() {
    this.compositionController.refreshViewCache(), this.render(), this.loadingSnapshot = false;
  }
  getSelectionManager() {
    return this.selectionManager;
  }
  attachmentManagerDidRequestRemovalOfAttachment(t3) {
    return this.removeAttachment(t3);
  }
  compositionControllerWillSyncDocumentView() {
    return this.inputController.editorWillSyncDocumentView(), this.selectionManager.lock(), this.selectionManager.clearSelection();
  }
  compositionControllerDidSyncDocumentView() {
    return this.inputController.editorDidSyncDocumentView(), this.selectionManager.unlock(), this.updateCurrentActions(), this.notifyEditorElement("sync");
  }
  compositionControllerDidRender() {
    this.requestedLocationRange && (this.compositionRevisionWhenLocationRangeRequested === this.composition.revision && this.selectionManager.setLocationRange(this.requestedLocationRange), this.requestedLocationRange = null, this.compositionRevisionWhenLocationRangeRequested = null), this.renderedCompositionRevision !== this.composition.revision && (this.runEditorFilters(), this.composition.updateCurrentAttributes(), this.notifyEditorElement("render")), this.renderedCompositionRevision = this.composition.revision;
  }
  compositionControllerDidFocus() {
    return this.isFocusedInvisibly() && this.setLocationRange({ index: 0, offset: 0 }), this.toolbarController.hideDialog(), this.notifyEditorElement("focus");
  }
  compositionControllerDidBlur() {
    return this.notifyEditorElement("blur");
  }
  compositionControllerDidSelectAttachment(t3, e2) {
    return this.toolbarController.hideDialog(), this.composition.editAttachment(t3, e2);
  }
  compositionControllerDidRequestDeselectingAttachment(t3) {
    const e2 = this.attachmentLocationRange || this.composition.document.getLocationRangeOfAttachment(t3);
    return this.selectionManager.setLocationRange(e2[1]);
  }
  compositionControllerWillUpdateAttachment(t3) {
    return this.editor.recordUndoEntry("Edit Attachment", { context: t3.id, consolidatable: true });
  }
  compositionControllerDidRequestRemovalOfAttachment(t3) {
    return this.removeAttachment(t3);
  }
  inputControllerWillHandleInput() {
    this.handlingInput = true, this.requestedRender = false;
  }
  inputControllerDidRequestRender() {
    this.requestedRender = true;
  }
  inputControllerDidHandleInput() {
    if (this.handlingInput = false, this.requestedRender) return this.requestedRender = false, this.render();
  }
  inputControllerDidAllowUnhandledInput() {
    return this.notifyEditorElement("change");
  }
  inputControllerDidRequestReparse() {
    return this.reparse();
  }
  inputControllerWillPerformTyping() {
    return this.recordTypingUndoEntry();
  }
  inputControllerWillPerformFormatting(t3) {
    return this.recordFormattingUndoEntry(t3);
  }
  inputControllerWillCutText() {
    return this.editor.recordUndoEntry("Cut");
  }
  inputControllerWillPaste(t3) {
    return this.editor.recordUndoEntry("Paste"), this.pasting = true, this.notifyEditorElement("before-paste", { paste: t3 });
  }
  inputControllerDidPaste(t3) {
    return t3.range = this.pastedRange, this.pastedRange = null, this.pasting = null, this.notifyEditorElement("paste", { paste: t3 });
  }
  inputControllerWillMoveText() {
    return this.editor.recordUndoEntry("Move");
  }
  inputControllerWillAttachFiles() {
    return this.editor.recordUndoEntry("Drop Files");
  }
  inputControllerWillPerformUndo() {
    return this.editor.undo();
  }
  inputControllerWillPerformRedo() {
    return this.editor.redo();
  }
  inputControllerDidReceiveKeyboardCommand(t3) {
    return this.toolbarController.applyKeyboardCommand(t3);
  }
  inputControllerDidStartDrag() {
    this.locationRangeBeforeDrag = this.selectionManager.getLocationRange();
  }
  inputControllerDidReceiveDragOverPoint(t3) {
    return this.selectionManager.setLocationRangeFromPointRange(t3);
  }
  inputControllerDidCancelDrag() {
    this.selectionManager.setLocationRange(this.locationRangeBeforeDrag), this.locationRangeBeforeDrag = null;
  }
  locationRangeDidChange(t3) {
    return this.composition.updateCurrentAttributes(), this.updateCurrentActions(), this.attachmentLocationRange && !Nt(this.attachmentLocationRange, t3) && this.composition.stopEditingAttachment(), this.notifyEditorElement("selection-change");
  }
  toolbarDidClickButton() {
    if (!this.getLocationRange()) return this.setLocationRange({ index: 0, offset: 0 });
  }
  toolbarDidInvokeAction(t3, e2) {
    return this.invokeAction(t3, e2);
  }
  toolbarDidToggleAttribute(t3) {
    if (this.recordFormattingUndoEntry(t3), this.composition.toggleCurrentAttribute(t3), this.render(), !this.selectionFrozen) return this.editorElement.focus();
  }
  toolbarDidUpdateAttribute(t3, e2) {
    if (this.recordFormattingUndoEntry(t3), this.composition.setCurrentAttribute(t3, e2), this.render(), !this.selectionFrozen) return this.editorElement.focus();
  }
  toolbarDidRemoveAttribute(t3) {
    if (this.recordFormattingUndoEntry(t3), this.composition.removeCurrentAttribute(t3), this.render(), !this.selectionFrozen) return this.editorElement.focus();
  }
  toolbarWillShowDialog(t3) {
    return this.composition.expandSelectionForEditing(), this.freezeSelection();
  }
  toolbarDidShowDialog(t3) {
    return this.notifyEditorElement("toolbar-dialog-show", { dialogName: t3 });
  }
  toolbarDidHideDialog(t3) {
    return this.thawSelection(), this.editorElement.focus(), this.notifyEditorElement("toolbar-dialog-hide", { dialogName: t3 });
  }
  freezeSelection() {
    if (!this.selectionFrozen) return this.selectionManager.lock(), this.composition.freezeSelection(), this.selectionFrozen = true, this.render();
  }
  thawSelection() {
    if (this.selectionFrozen) return this.composition.thawSelection(), this.selectionManager.unlock(), this.selectionFrozen = false, this.render();
  }
  canInvokeAction(t3) {
    return !!this.actionIsExternal(t3) || !(null === (e2 = this.actions[t3]) || void 0 === e2 || null === (e2 = e2.test) || void 0 === e2 || !e2.call(this));
    var e2;
  }
  invokeAction(t3, e2) {
    return this.actionIsExternal(t3) ? this.notifyEditorElement("action-invoke", { actionName: t3, invokingElement: e2 }) : null === (i2 = this.actions[t3]) || void 0 === i2 || null === (i2 = i2.perform) || void 0 === i2 ? void 0 : i2.call(this);
    var i2;
  }
  actionIsExternal(t3) {
    return /^x-./.test(t3);
  }
  getCurrentActions() {
    const t3 = {};
    for (const e2 in this.actions) t3[e2] = this.canInvokeAction(e2);
    return t3;
  }
  updateCurrentActions() {
    const t3 = this.getCurrentActions();
    if (!wt(t3, this.currentActions)) return this.currentActions = t3, this.toolbarController.updateActions(this.currentActions), this.notifyEditorElement("actions-change", { actions: this.currentActions });
  }
  runEditorFilters() {
    let t3 = this.composition.getSnapshot();
    if (Array.from(this.editor.filters).forEach(((e3) => {
      const { document: i3, selectedRange: n2 } = t3;
      t3 = e3.call(this.editor, t3) || {}, t3.document || (t3.document = i3), t3.selectedRange || (t3.selectedRange = n2);
    })), e2 = t3, i2 = this.composition.getSnapshot(), !Nt(e2.selectedRange, i2.selectedRange) || !e2.document.isEqualTo(i2.document)) return this.composition.loadSnapshot(t3);
    var e2, i2;
  }
  updateInputElement() {
    const t3 = (function(t4, e2) {
      const i2 = Ln[e2];
      if (i2) return i2(t4);
      throw new Error("unknown content type: ".concat(e2));
    })(this.compositionController.getSerializableElement(), "text/html");
    return this.editorElement.setFormValue(t3);
  }
  notifyEditorElement(t3, e2) {
    switch (t3) {
      case "document-change":
        this.documentChangedSinceLastRender = true;
        break;
      case "render":
        this.documentChangedSinceLastRender && (this.documentChangedSinceLastRender = false, this.notifyEditorElement("change"));
        break;
      case "change":
      case "attachment-add":
      case "attachment-edit":
      case "attachment-remove":
        this.updateInputElement();
    }
    return this.editorElement.notify(t3, e2);
  }
  removeAttachment(t3) {
    return this.editor.recordUndoEntry("Delete Attachment"), this.composition.removeAttachment(t3), this.render();
  }
  recordFormattingUndoEntry(t3) {
    const e2 = pt(t3), i2 = this.selectionManager.getLocationRange();
    if (e2 || !Lt(i2)) return this.editor.recordUndoEntry("Formatting", { context: this.getUndoContext(), consolidatable: true });
  }
  recordTypingUndoEntry() {
    return this.editor.recordUndoEntry("Typing", { context: this.getUndoContext(this.currentAttributes), consolidatable: true });
  }
  getUndoContext() {
    for (var t3 = arguments.length, e2 = new Array(t3), i2 = 0; i2 < t3; i2++) e2[i2] = arguments[i2];
    return [this.getLocationContext(), this.getTimeContext(), ...Array.from(e2)];
  }
  getLocationContext() {
    const t3 = this.selectionManager.getLocationRange();
    return Lt(t3) ? t3[0].index : t3;
  }
  getTimeContext() {
    return z.interval > 0 ? Math.floor((/* @__PURE__ */ new Date()).getTime() / z.interval) : 0;
  }
  isFocused() {
    var t3;
    return this.editorElement === (null === (t3 = this.editorElement.ownerDocument) || void 0 === t3 ? void 0 : t3.activeElement);
  }
  isFocusedInvisibly() {
    return this.isFocused() && !this.getLocationRange();
  }
  get actions() {
    return this.constructor.actions;
  }
}
_i(qr, "actions", { undo: { test() {
  return this.editor.canUndo();
}, perform() {
  return this.editor.undo();
} }, redo: { test() {
  return this.editor.canRedo();
}, perform() {
  return this.editor.redo();
} }, link: { test() {
  return this.editor.canActivateAttribute("href");
} }, increaseNestingLevel: { test() {
  return this.editor.canIncreaseNestingLevel();
}, perform() {
  return this.editor.increaseNestingLevel() && this.render();
} }, decreaseNestingLevel: { test() {
  return this.editor.canDecreaseNestingLevel();
}, perform() {
  return this.editor.decreaseNestingLevel() && this.render();
} }, attachFiles: { test: () => true, perform() {
  return j.pickFiles(this.editor.insertFiles);
} } }), qr.proxyMethod("getSelectionManager().setLocationRange"), qr.proxyMethod("getSelectionManager().getLocationRange");
var Jr = Object.freeze({ __proto__: null, AttachmentEditorController: ir, CompositionController: nr, Controller: rr, EditorController: qr, InputController: pr, Level0InputController: yr, Level2InputController: Tr, ToolbarController: Hr }), Kr = Object.freeze({ __proto__: null, MutationObserver: lr, SelectionChangeObserver: Ft }), Gr = Object.freeze({ __proto__: null, FileVerificationOperation: ur, ImagePreloadOperation: Gi });
At("trix-toolbar", "%t {\n  display: block;\n}\n\n%t {\n  white-space: nowrap;\n}\n\n%t [data-trix-dialog] {\n  display: none;\n}\n\n%t [data-trix-dialog][data-trix-active] {\n  display: block;\n}\n\n%t [data-trix-dialog] [data-trix-validate]:invalid {\n  background-color: #ffdddd;\n}");
class Xr extends HTMLElement {
  connectedCallback() {
    "" === this.innerHTML && (this.innerHTML = V.getDefaultHTML());
  }
  get editorElements() {
    if (this.id) {
      var t3;
      const e2 = null === (t3 = this.ownerDocument) || void 0 === t3 ? void 0 : t3.querySelectorAll('trix-editor[toolbar="'.concat(this.id, '"]'));
      return Array.from(e2);
    }
    return [];
  }
  get editorElement() {
    const [t3] = this.editorElements;
    return t3;
  }
}
let Yr = 0;
const $r = function(t3) {
  if (!t3.hasAttribute("contenteditable")) return t3.toggleAttribute("contenteditable", !t3.disabled), (function(t4) {
    let e2 = arguments.length > 1 && void 0 !== arguments[1] ? arguments[1] : {};
    return e2.times = 1, b(t4, e2);
  })("focus", { onElement: t3, withCallback: () => Zr(t3) });
}, Zr = function(t3) {
  return Qr(t3), to();
}, Qr = function(t3) {
  var e2, i2;
  if (null !== (e2 = (i2 = document).queryCommandSupported) && void 0 !== e2 && e2.call(i2, "enableObjectResizing")) return document.execCommand("enableObjectResizing", false, false), b("mscontrolselect", { onElement: t3, preventDefault: true });
}, to = function(t3) {
  var e2, i2;
  if (null !== (e2 = (i2 = document).queryCommandSupported) && void 0 !== e2 && e2.call(i2, "DefaultParagraphSeparator")) {
    const { tagName: t4 } = n.default;
    if (["div", "p"].includes(t4)) return document.execCommand("DefaultParagraphSeparator", false, t4);
  }
}, eo = a.forcesObjectResizing ? { display: "inline", width: "auto" } : { display: "inline-block", width: "1px" };
At("trix-editor", "%t {\n    display: block;\n}\n\n%t:empty::before {\n    content: attr(placeholder);\n    color: graytext;\n    cursor: text;\n    pointer-events: none;\n    white-space: pre-line;\n}\n\n%t a[contenteditable=false] {\n    cursor: text;\n}\n\n%t img {\n    max-width: 100%;\n    height: auto;\n}\n\n%t ".concat(e, " figcaption textarea {\n    resize: none;\n}\n\n%t ").concat(e, " figcaption textarea.trix-autoresize-clone {\n    position: absolute;\n    left: -9999px;\n    max-height: 0px;\n}\n\n%t ").concat(e, " figcaption[data-trix-placeholder]:empty::before {\n    content: attr(data-trix-placeholder);\n    color: graytext;\n}\n\n%t [data-trix-cursor-target] {\n    display: ").concat(eo.display, " !important;\n    width: ").concat(eo.width, " !important;\n    padding: 0 !important;\n    margin: 0 !important;\n    border: none !important;\n}\n\n%t [data-trix-cursor-target=left] {\n    vertical-align: top !important;\n    margin-left: -1px !important;\n}\n\n%t [data-trix-cursor-target=right] {\n    vertical-align: bottom !important;\n    margin-right: -1px !important;\n}"));
var io = /* @__PURE__ */ new WeakMap(), no = /* @__PURE__ */ new WeakMap(), ro = /* @__PURE__ */ new WeakSet();
class oo {
  constructor(t3) {
    var e2, i2;
    qi(e2 = this, i2 = ro), i2.add(e2), _i(this, "value", ""), Ji(this, io, { writable: true, value: void 0 }), Ji(this, no, { writable: true, value: void 0 }), this.element = t3, Wi(this, io, t3.attachInternals()), Wi(this, no, false);
  }
  connectedCallback() {
    Hi(this, ro, so).call(this);
  }
  disconnectedCallback() {
  }
  get form() {
    return ji(this, io).form;
  }
  get name() {
    return this.element.getAttribute("name");
  }
  set name(t3) {
    this.element.setAttribute("name", t3);
  }
  get labels() {
    return ji(this, io).labels;
  }
  get disabled() {
    return ji(this, no) || this.element.hasAttribute("disabled");
  }
  set disabled(t3) {
    this.element.toggleAttribute("disabled", t3);
  }
  get required() {
    return this.element.hasAttribute("required");
  }
  set required(t3) {
    this.element.toggleAttribute("required", t3), Hi(this, ro, so).call(this);
  }
  get validity() {
    return ji(this, io).validity;
  }
  get validationMessage() {
    return ji(this, io).validationMessage;
  }
  get willValidate() {
    return ji(this, io).willValidate;
  }
  formDisabledCallback(t3) {
    Wi(this, no, t3);
  }
  setFormValue(t3) {
    this.value = t3, Hi(this, ro, so).call(this), ji(this, io).setFormValue(this.element.disabled ? void 0 : this.value);
  }
  checkValidity() {
    return ji(this, io).checkValidity();
  }
  reportValidity() {
    return ji(this, io).reportValidity();
  }
  setCustomValidity(t3) {
    Hi(this, ro, so).call(this, t3);
  }
}
function so() {
  let t3 = arguments.length > 0 && void 0 !== arguments[0] ? arguments[0] : "";
  const { required: e2, value: i2 } = this.element, n2 = e2 && !i2, r2 = !!t3, o2 = w("input", { required: e2 }), s2 = t3 || o2.validationMessage;
  ji(this, io).setValidity({ valueMissing: n2, customError: r2 }, s2);
}
var ao = /* @__PURE__ */ new WeakMap(), lo = /* @__PURE__ */ new WeakMap(), co = /* @__PURE__ */ new WeakMap();
class uo {
  constructor(t3) {
    Ji(this, ao, { writable: true, value: void 0 }), Ji(this, lo, { writable: true, value: (t4) => {
      t4.defaultPrevented || t4.target === this.element.form && this.element.reset();
    } }), Ji(this, co, { writable: true, value: (t4) => {
      if (t4.defaultPrevented) return;
      if (this.element.contains(t4.target)) return;
      const e2 = x(t4.target, { matchingSelector: "label" });
      e2 && Array.from(this.labels).includes(e2) && this.element.focus();
    } }), this.element = t3;
  }
  connectedCallback() {
    Wi(this, ao, (function(t3) {
      if (t3.hasAttribute("aria-label") || t3.hasAttribute("aria-labelledby")) return;
      const e2 = function() {
        const e3 = Array.from(t3.labels).map(((e4) => {
          if (!e4.contains(t3)) return e4.textContent;
        })).filter(((t4) => t4)), i2 = e3.join(" ");
        return i2 ? t3.setAttribute("aria-label", i2) : t3.removeAttribute("aria-label");
      };
      return e2(), b("focus", { onElement: t3, withCallback: e2 });
    })(this.element)), window.addEventListener("reset", ji(this, lo), false), window.addEventListener("click", ji(this, co), false);
  }
  disconnectedCallback() {
    var t3;
    null === (t3 = ji(this, ao)) || void 0 === t3 || t3.destroy(), window.removeEventListener("reset", ji(this, lo), false), window.removeEventListener("click", ji(this, co), false);
  }
  get labels() {
    const t3 = [];
    this.element.id && this.element.ownerDocument && t3.push(...Array.from(this.element.ownerDocument.querySelectorAll("label[for='".concat(this.element.id, "']")) || []));
    const e2 = x(this.element, { matchingSelector: "label" });
    return e2 && [this.element, null].includes(e2.control) && t3.push(e2), t3;
  }
  get form() {
    return console.warn("This browser does not support the .form property for trix-editor elements."), null;
  }
  get name() {
    return console.warn("This browser does not support the .name property for trix-editor elements."), null;
  }
  set name(t3) {
    console.warn("This browser does not support the .name property for trix-editor elements.");
  }
  get disabled() {
    return console.warn("This browser does not support the [disabled] attribute for trix-editor elements."), false;
  }
  set disabled(t3) {
    console.warn("This browser does not support the [disabled] attribute for trix-editor elements.");
  }
  get required() {
    return console.warn("This browser does not support the [required] attribute for trix-editor elements."), false;
  }
  set required(t3) {
    console.warn("This browser does not support the [required] attribute for trix-editor elements.");
  }
  get validity() {
    return console.warn("This browser does not support the validity property for trix-editor elements."), null;
  }
  get validationMessage() {
    return console.warn("This browser does not support the validationMessage property for trix-editor elements."), "";
  }
  get willValidate() {
    return console.warn("This browser does not support the willValidate property for trix-editor elements."), false;
  }
  formDisabledCallback(t3) {
  }
  setFormValue(t3) {
  }
  checkValidity() {
    return console.warn("This browser does not support checkValidity() for trix-editor elements."), true;
  }
  reportValidity() {
    return console.warn("This browser does not support reportValidity() for trix-editor elements."), true;
  }
  setCustomValidity(t3) {
    console.warn("This browser does not support setCustomValidity(validationMessage) for trix-editor elements.");
  }
}
var ho = /* @__PURE__ */ new WeakMap();
class go extends HTMLElement {
  constructor() {
    super(), Ji(this, ho, { writable: true, value: void 0 }), this.willCreateInput = true, Wi(this, ho, this.constructor.formAssociated ? new oo(this) : new uo(this));
  }
  get trixId() {
    return this.hasAttribute("trix-id") ? this.getAttribute("trix-id") : (this.setAttribute("trix-id", ++Yr), this.trixId);
  }
  get labels() {
    return ji(this, ho).labels;
  }
  get disabled() {
    const { inputElement: t3 } = this;
    return t3 ? t3.disabled : ji(this, ho).disabled;
  }
  set disabled(t3) {
    const { inputElement: e2 } = this;
    e2 && (e2.disabled = t3), ji(this, ho).disabled = t3;
  }
  get required() {
    return ji(this, ho).required;
  }
  set required(t3) {
    ji(this, ho).required = t3;
  }
  get validity() {
    return ji(this, ho).validity;
  }
  get validationMessage() {
    return ji(this, ho).validationMessage;
  }
  get willValidate() {
    return ji(this, ho).willValidate;
  }
  get type() {
    return this.localName;
  }
  get toolbarElement() {
    var t3;
    if (this.hasAttribute("toolbar")) return null === (t3 = this.ownerDocument) || void 0 === t3 ? void 0 : t3.getElementById(this.getAttribute("toolbar"));
    if (this.parentNode) {
      const t4 = "trix-toolbar-".concat(this.trixId);
      return this.setAttribute("toolbar", t4), this.internalToolbar = w("trix-toolbar", { id: t4 }), this.parentNode.insertBefore(this.internalToolbar, this), this.internalToolbar;
    }
  }
  get form() {
    const { inputElement: t3 } = this;
    return t3 ? t3.form : ji(this, ho).form;
  }
  get inputElement() {
    var t3;
    return this.hasAttribute("input") ? null === (t3 = this.ownerDocument) || void 0 === t3 ? void 0 : t3.getElementById(this.getAttribute("input")) : void 0;
  }
  get editor() {
    var t3;
    return null === (t3 = this.editorController) || void 0 === t3 ? void 0 : t3.editor;
  }
  get name() {
    const { inputElement: t3 } = this;
    return t3 ? t3.name : ji(this, ho).name;
  }
  set name(t3) {
    const { inputElement: e2 } = this;
    e2 ? e2.name = t3 : ji(this, ho).name = t3;
  }
  get value() {
    const { inputElement: t3 } = this;
    return t3 ? t3.value : ji(this, ho).value;
  }
  set value(t3) {
    var e2;
    this.defaultValue = t3, null === (e2 = this.editor) || void 0 === e2 || e2.loadHTML(this.defaultValue);
  }
  attributeChangedCallback(t3, e2, i2) {
    "connected" === t3 && this.isConnected && null != e2 && e2 !== i2 && requestAnimationFrame((() => this.reconnect()));
  }
  notify(t3, e2) {
    if (this.editorController) return A("trix-".concat(t3), { onElement: this, attributes: e2 });
  }
  setFormValue(t3) {
    const { inputElement: e2 } = this;
    e2 && (e2.value = t3), ji(this, ho).setFormValue(t3);
  }
  connectedCallback() {
    if (!this.hasAttribute("data-trix-internal")) {
      if ($r(this), (function(t3) {
        if (!t3.hasAttribute("role")) t3.setAttribute("role", "textbox");
      })(this), !this.editorController) {
        if (A("trix-before-initialize", { onElement: this }), this.defaultValue = this.inputElement ? this.inputElement.value : this.innerHTML, !this.hasAttribute("input") && this.parentNode && this.willCreateInput) {
          const t3 = "trix-input-".concat(this.trixId);
          this.setAttribute("input", t3);
          const e2 = w("input", { type: "hidden", id: t3 });
          this.parentNode.insertBefore(e2, this.nextElementSibling);
        }
        this.editorController = new qr({ editorElement: this, html: this.defaultValue }), requestAnimationFrame((() => A("trix-initialize", { onElement: this })));
      }
      this.editorController.registerSelectionManager(), ji(this, ho).connectedCallback(), this.toggleAttribute("connected", true), (function(t3) {
        if (!document.querySelector(":focus") && t3.hasAttribute("autofocus") && document.querySelector("[autofocus]") === t3) t3.focus();
      })(this);
    }
  }
  disconnectedCallback() {
    var t3;
    null === (t3 = this.editorController) || void 0 === t3 || t3.unregisterSelectionManager(), ji(this, ho).disconnectedCallback(), this.toggleAttribute("connected", false);
  }
  reconnect() {
    this.removeInternalToolbar(), this.disconnectedCallback(), this.connectedCallback();
  }
  removeInternalToolbar() {
    var t3;
    null === (t3 = this.internalToolbar) || void 0 === t3 || t3.remove(), this.internalToolbar = null;
  }
  checkValidity() {
    return ji(this, ho).checkValidity();
  }
  reportValidity() {
    return ji(this, ho).reportValidity();
  }
  setCustomValidity(t3) {
    ji(this, ho).setCustomValidity(t3);
  }
  formDisabledCallback(t3) {
    const { inputElement: e2 } = this;
    e2 && (e2.disabled = t3), this.toggleAttribute("contenteditable", !t3), ji(this, ho).formDisabledCallback(t3);
  }
  formResetCallback() {
    this.reset();
  }
  reset() {
    this.value = this.defaultValue;
  }
}
_i(go, "formAssociated", "ElementInternals" in window), _i(go, "observedAttributes", ["connected"]);
const mo = { VERSION: t, config: H, core: Nn, models: Yn, views: $n, controllers: Jr, observers: Kr, operations: Gr, elements: Object.freeze({ __proto__: null, TrixEditorElement: go, TrixToolbarElement: Xr }), filters: Object.freeze({ __proto__: null, Filter: jn, attachmentGalleryFilter: Wn }) };
Object.assign(mo, Yn), window.Trix = mo, setTimeout((function() {
  customElements.get("trix-toolbar") || customElements.define("trix-toolbar", Xr), customElements.get("trix-editor") || customElements.define("trix-editor", go);
}), 0);
function ImageModal({ src, onClose }) {
  useModalHistoryBackToClose(true, onClose);
  useEffect(() => {
    function handleKeyDown(e2) {
      if (e2.key !== "Escape") return;
      e2.stopImmediatePropagation();
      e2.preventDefault();
      onClose();
    }
    document.addEventListener("keydown", handleKeyDown, true);
    return () => document.removeEventListener("keydown", handleKeyDown, true);
  }, [onClose]);
  return createPortal(
    /* @__PURE__ */ jsxs(
      "div",
      {
        className: "fixed inset-0 flex items-center justify-center bg-black/70",
        style: { zIndex: 9999 },
        onClick: onClose,
        children: [
          /* @__PURE__ */ jsx(
            "button",
            {
              type: "button",
              onClick: onClose,
              className: "absolute top-4 right-4 text-white hover:text-gray-300",
              style: { zIndex: 1e4 },
              "aria-label": "Close",
              children: /* @__PURE__ */ jsx(XIcon, { size: 28, weight: "bold" })
            }
          ),
          /* @__PURE__ */ jsx(
            "img",
            {
              src,
              alt: "",
              className: "max-w-[90vw] max-h-[90vh] object-contain rounded-lg shadow-2xl",
              onClick: (e2) => e2.stopPropagation()
            }
          )
        ]
      }
    ),
    document.body
  );
}
function TrixFieldDisplay({ field, value }) {
  const { t: t3 } = useTranslation("messages");
  const contentRef = useRef(null);
  const [modalSrc, setModalSrc] = useState(null);
  const ext = field;
  const imageClickBehavior = ext.imageClickBehavior || "modal";
  const linkClickBehavior = ext.linkClickBehavior || "same_page";
  const alwaysShow = ext.alwaysShow ?? false;
  const [expanded, setExpanded] = useState(alwaysShow);
  const html = useMemo(
    () => value === null || value === void 0 || value === "" ? "" : sanitizeRichText(String(value)),
    [value]
  );
  useEffect(() => {
    if (!expanded || !contentRef.current) return;
    function handleClick(e2) {
      const target = e2.target;
      const fig = target.closest("figure.attachment");
      if (fig) {
        let attachmentUrl = null;
        const raw = fig.getAttribute("data-trix-attachment");
        if (raw) {
          try {
            const parsed = JSON.parse(raw);
            attachmentUrl = parsed.url || parsed.href || null;
          } catch {
          }
        }
        const inlineImg = fig.querySelector("img");
        if (!attachmentUrl && inlineImg) attachmentUrl = inlineImg.src;
        const safeUrl = safeNavigationUrl(attachmentUrl);
        if (safeUrl !== null) {
          e2.preventDefault();
          e2.stopPropagation();
          const isImage = fig.classList.contains("attachment--preview");
          if (isImage && imageClickBehavior === "modal") {
            setModalSrc(safeUrl);
          } else if (linkClickBehavior === "same_page") {
            window.location.href = safeUrl;
          } else {
            window.open(safeUrl, "_blank", "noopener,noreferrer");
          }
          return;
        }
      }
      const anchor = target.closest("a[href]");
      if (anchor) {
        const safeHref = safeNavigationUrl(anchor.href);
        if (safeHref === null) {
          const protocol = urlProtocol(anchor.href);
          if (protocol !== "mailto:" && protocol !== "tel:") {
            e2.preventDefault();
            e2.stopPropagation();
          }
          return;
        }
        if (linkClickBehavior !== "same_page") {
          e2.preventDefault();
          e2.stopPropagation();
          window.open(safeHref, "_blank", "noopener,noreferrer");
        }
      }
    }
    const el = contentRef.current;
    el.addEventListener("click", handleClick);
    if (linkClickBehavior !== "same_page") {
      el.querySelectorAll("a[href]").forEach((a2) => {
        const anchor = a2;
        anchor.setAttribute("target", "_blank");
        anchor.setAttribute("rel", "noopener noreferrer");
      });
    }
    el.querySelectorAll("figure.attachment").forEach((figNode) => {
      const fig = figNode;
      const raw = fig.getAttribute("data-trix-attachment");
      if (!raw) return;
      try {
        const meta = JSON.parse(raw);
        if (typeof meta.width === "number" && meta.width > 0) {
          fig.style.width = `${meta.width}px`;
          fig.style.maxWidth = "100%";
          const img = fig.querySelector("img");
          if (img) {
            img.setAttribute("width", String(meta.width));
            if (typeof meta.height === "number" && meta.height > 0) {
              img.setAttribute("height", String(meta.height));
            }
            img.style.width = "100%";
            img.style.height = "auto";
          }
        }
      } catch {
      }
    });
    return () => el.removeEventListener("click", handleClick);
  }, [expanded, imageClickBehavior, linkClickBehavior, html]);
  if (value === null || value === void 0 || value === "") {
    return /* @__PURE__ */ jsx("span", { className: "martis-text-muted", children: "—" });
  }
  if (!expanded) {
    return /* @__PURE__ */ jsxs(
      "button",
      {
        type: "button",
        onClick: () => setExpanded(true),
        className: "inline-flex items-center gap-1.5 text-sm",
        style: { color: "var(--martis-accent)" },
        children: [
          /* @__PURE__ */ jsx(EyeIcon, { size: 16, weight: "bold" }),
          t3("show_content")
        ]
      }
    );
  }
  return /* @__PURE__ */ jsxs("div", { className: "relative", children: [
    !alwaysShow && /* @__PURE__ */ jsxs(
      "button",
      {
        type: "button",
        onClick: () => setExpanded(false),
        className: "inline-flex items-center gap-1.5 text-sm mb-2",
        style: { color: "var(--martis-accent)" },
        children: [
          /* @__PURE__ */ jsx(EyeSlashIcon, { size: 16, weight: "bold" }),
          t3("hide")
        ]
      }
    ),
    /* @__PURE__ */ jsx(
      "div",
      {
        ref: contentRef,
        className: "martis-trix-detail prose dark:prose-invert max-w-none text-sm trix-content",
        style: { cursor: imageClickBehavior !== "same_page" ? "default" : void 0 },
        dangerouslySetInnerHTML: { __html: html }
      }
    ),
    modalSrc && /* @__PURE__ */ jsx(ImageModal, { src: modalSrc, onClose: () => setModalSrc(null) })
  ] });
}
function TrixFieldInput({
  field,
  value,
  onChange,
  error,
  resourceKey,
  recordId,
  repeaterRow,
  actionEndpoint,
  pivotEndpoint,
  toolKey
}) {
  const { t: t3 } = useTranslation("messages");
  const containerRef = useRef(null);
  const editorRef = useRef(null);
  const hiddenInputRef = useRef(null);
  const inputId = `trix-input-${field.attribute}-${useId().replace(/:/g, "")}`;
  const lastPropValue = useRef("");
  const internalUpdate = useRef(false);
  useEffect(() => {
    var _a;
    const globalTrix = window.Trix;
    if (!((_a = globalTrix == null ? void 0 : globalTrix.config) == null ? void 0 : _a.lang)) return;
    Object.assign(globalTrix.config.lang, {
      attachFiles: t3("trix_attach", { defaultValue: "Attach Files" }),
      bold: t3("trix_bold", { defaultValue: "Bold" }),
      bullets: t3("trix_bullets", { defaultValue: "Bullets" }),
      code: t3("trix_code", { defaultValue: "Code" }),
      heading1: t3("trix_heading", { defaultValue: "Heading" }),
      indent: t3("trix_indent", { defaultValue: "Increase Level" }),
      italic: t3("trix_italic", { defaultValue: "Italic" }),
      link: t3("trix_link", { defaultValue: "Link" }),
      numbers: t3("trix_numbers", { defaultValue: "Numbers" }),
      outdent: t3("trix_outdent", { defaultValue: "Decrease Level" }),
      quote: t3("trix_quote", { defaultValue: "Quote" }),
      redo: t3("trix_redo", { defaultValue: "Redo" }),
      strike: t3("trix_strike", { defaultValue: "Strikethrough" }),
      undo: t3("trix_undo", { defaultValue: "Undo" }),
      unlink: t3("trix_unlink", { defaultValue: "Unlink" }),
      urlPlaceholder: t3("trix_url_placeholder", { defaultValue: "Enter a URL…" }),
      captionPlaceholder: t3("trix_caption_placeholder", { defaultValue: "Add a caption…" })
    });
  }, [t3]);
  const currentValue = value === null || value === void 0 ? "" : String(value);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;
  const currentValueRef = useRef(currentValue);
  currentValueRef.current = currentValue;
  const extras = field;
  const readonly = !!field.readonly;
  const toolbarSize = extras.toolbarSize;
  const withFiles = !!extras.withFiles && resourceKey !== void 0 && actionEndpoint === void 0 && pivotEndpoint === void 0 && toolKey === void 0;
  const uploadScopeRef = useRef({ resourceKey, recordId, attribute: field.attribute, repeaterRow });
  uploadScopeRef.current = { resourceKey, recordId, attribute: field.attribute, repeaterRow };
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    const initialValue = currentValueRef.current;
    const input = document.createElement("input");
    input.id = inputId;
    input.type = "hidden";
    input.value = initialValue;
    container.appendChild(input);
    hiddenInputRef.current = input;
    lastPropValue.current = initialValue;
    internalUpdate.current = false;
    const editor = document.createElement("trix-editor");
    editor.setAttribute("input", inputId);
    editor.classList.add("trix-content");
    if (readonly) {
      editor.setAttribute("contenteditable", "false");
    }
    container.appendChild(editor);
    editorRef.current = editor;
    const handleChange = () => {
      internalUpdate.current = true;
      onChangeRef.current(input.value);
    };
    editor.addEventListener("trix-change", handleChange);
    editor.addEventListener("trix-initialize", () => {
      const toolbar = editor.previousElementSibling;
      if (toolbar) {
        const selector = '.trix-dialog input[type="url"]';
        const urlInputs = toolbar.querySelectorAll(selector);
        urlInputs.forEach((inp) => {
          inp.setAttribute("type", "text");
          inp.setAttribute("placeholder", "Enter a URL…");
        });
        const linkBtn = toolbar.querySelector('.trix-dialog .trix-button[data-trix-method="setAttribute"]');
        if (linkBtn) {
          linkBtn.addEventListener("click", () => {
            const urlInput = toolbar.querySelector('.trix-dialog input[name="href"]') || toolbar.querySelector('.trix-dialog input[type="text"]');
            if (urlInput && urlInput.value && !/^(https?|mailto|tel|ftp):/i.test(urlInput.value)) {
              urlInput.value = "https://" + urlInput.value;
            }
          }, true);
        }
      }
      if (toolbarSize) {
        const tb = editor.previousElementSibling;
        if (tb) {
          tb.classList.add("martis-trix-toolbar-" + toolbarSize);
        }
      }
      if (toolbar) {
        toolbar.querySelectorAll("button[title]").forEach((btn) => {
          const tip = btn.getAttribute("title");
          if (tip) {
            btn.setAttribute("data-pr-tooltip", tip);
            btn.setAttribute("data-pr-position", "top");
            btn.removeAttribute("title");
          }
        });
      }
    });
    editor.addEventListener(
      "trix-attachment-add",
      ((event) => {
        const attachment = event.attachment;
        if (!attachment.file) return;
        if (!withFiles) {
          attachment.remove();
          return;
        }
        const formData = new FormData();
        formData.append("file", attachment.file);
        const csrfMeta = document.querySelector(
          'meta[name="csrf-token"]'
        );
        const csrfMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        const headers = {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json"
        };
        if (csrfMeta) {
          headers["X-CSRF-TOKEN"] = csrfMeta.content;
        } else if (csrfMatch) {
          headers["X-XSRF-TOKEN"] = decodeURIComponent(csrfMatch[1]);
        }
        attachment.setUploadProgress(10);
        const scope = uploadScopeRef.current;
        const query = new URLSearchParams({ resource: String(scope.resourceKey), field: scope.attribute });
        if (scope.recordId !== void 0 && scope.recordId !== null) query.set("id", String(scope.recordId));
        if (scope.repeaterRow) {
          query.set("repeater", scope.repeaterRow.repeater);
          query.set("repeatable", scope.repeaterRow.repeatable);
        }
        fetch(withQuery(`${BASE_PATH}/api/attachments/upload`, query.toString()), {
          method: "POST",
          body: formData,
          credentials: "same-origin",
          headers
        }).then((response) => {
          attachment.setUploadProgress(50);
          if (!response.ok)
            throw new Error(`Upload failed: ${response.status}`);
          return response.json();
        }).then((data) => {
          attachment.setUploadProgress(100);
          attachment.setAttributes({
            url: data.url,
            href: data.href || data.url
          });
        }).catch((err) => {
          console.error("Trix attachment upload failed:", err);
          attachment.remove();
        });
      })
    );
    editor.addEventListener("trix-file-accept", ((event) => {
      if (!withFiles) {
        event.preventDefault();
      }
    }));
    return () => {
      editor.removeEventListener("trix-change", handleChange);
      container.replaceChildren();
      editorRef.current = null;
      hiddenInputRef.current = null;
    };
  }, [inputId, readonly, toolbarSize, withFiles]);
  useEffect(() => {
    if (!editorRef.current) return;
    if (internalUpdate.current) {
      internalUpdate.current = false;
      lastPropValue.current = currentValue;
      return;
    }
    if (currentValue !== lastPropValue.current) {
      lastPropValue.current = currentValue;
      const trixEditor = editorRef.current;
      if (trixEditor.editor) {
        trixEditor.editor.loadHTML(currentValue);
      }
      if (hiddenInputRef.current) {
        hiddenInputRef.current.value = currentValue;
      }
    }
  }, [currentValue]);
  return /* @__PURE__ */ jsxs("div", { className: "flex flex-col gap-1", children: [
    /* @__PURE__ */ jsx("div", { ref: containerRef, className: "martis-trix-wrapper" }),
    error && /* @__PURE__ */ jsx("small", { className: "text-red-500", children: error })
  ] });
}
export {
  TrixFieldDisplay,
  TrixFieldInput
};
