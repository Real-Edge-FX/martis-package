import { jsx, jsxs, Fragment as Fragment$1 } from "react/jsx-runtime";
import * as React from "react";
import React__default, { Fragment, useState, useRef, useEffect, useCallback } from "react";
import { I as IconBase, G as useMergeProps, P as PrimeReactContext, H as useHandleStyle, J as useUpdateEffect, O as ObjectUtils, K as classNames, L as ComponentBase, M as InputNumber, N as localeOption, Q as Dropdown, S as ariaLabel$2, T as IconUtils, U as Ripple, V as useEventListener, W as useMountEffect, X as UniqueComponentId, Y as useUnmountEffect, Z as FilterMatchMode$2, $ as PrimeReact$2, a0 as FilterService, a1 as FilterOperator, a2 as DomHandler, a3 as SpinnerIcon, a4 as VirtualScroller, a5 as usePrevious, a6 as ChevronDownIcon, a7 as ChevronRightIcon, a8 as Tooltip, a9 as CheckIcon, aa as useOverlayListener, ab as OverlayService, ac as ZIndexUtils, ad as Portal, ae as CSSTransition, af as InputText, ag as useStyle, ah as Button, ai as TimesIcon, aj as registry, u as useTranslation, A as ApiError, R as ResourceIcon, k as useEscapeLayer, ak as ownEntry, al as isHiddenOn, F as FieldDisplay, C as martisEventBus, am as openExternal, c as safeNavigationUrl, an as safeInternalPath, h as apiPath, v as useToast, x as useNavigate, y as useActionResponseModal, n as useModalHistoryLock, j as useQuery, l as useMutation, m as api, ao as componentRegistry, q as FieldInput, D as fieldErrorProps, i as useQueryClient, ap as MartisLoader, r as recordHref, g as routePath, aq as emitRecordEvent, ar as Link, as as DeleteModal } from "./index-DtgiJWoT.mjs";
import { ArrowClockwiseIcon, PlugsConnectedIcon, CompassIcon, WarningIcon, LightningIcon, DotsThreeVerticalIcon, CaretRightIcon, CaretUpDownIcon, EyeIcon, PencilSimpleIcon, TrashIcon as TrashIcon$1, ArrowCounterClockwiseIcon, SkullIcon, CaretUpIcon, CaretDownIcon, XIcon, MagnifyingGlassIcon, PlusIcon as PlusIcon$1 } from "@phosphor-icons/react";
import { createPortal } from "react-dom";
function _extends$f() {
  return _extends$f = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$f.apply(null, arguments);
}
var ArrowDownIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$f({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    fillRule: "evenodd",
    clipRule: "evenodd",
    d: "M6.99994 14C6.91097 14.0004 6.82281 13.983 6.74064 13.9489C6.65843 13.9148 6.58387 13.8646 6.52133 13.8013L1.10198 8.38193C0.982318 8.25351 0.917175 8.08367 0.920272 7.90817C0.923368 7.73267 0.994462 7.56523 1.11858 7.44111C1.24269 7.317 1.41014 7.2459 1.58563 7.2428C1.76113 7.23971 1.93098 7.30485 2.0594 7.42451L6.32263 11.6877V0.677419C6.32263 0.497756 6.394 0.325452 6.52104 0.198411C6.64808 0.0713706 6.82039 0 7.00005 0C7.17971 0 7.35202 0.0713706 7.47906 0.198411C7.6061 0.325452 7.67747 0.497756 7.67747 0.677419V11.6877L11.9407 7.42451C12.0691 7.30485 12.2389 7.23971 12.4144 7.2428C12.5899 7.2459 12.7574 7.317 12.8815 7.44111C13.0056 7.56523 13.0767 7.73267 13.0798 7.90817C13.0829 8.08367 13.0178 8.25351 12.8981 8.38193L7.47875 13.8013C7.41621 13.8646 7.34164 13.9148 7.25944 13.9489C7.17727 13.983 7.08912 14.0004 7.00015 14C7.00012 14 7.00009 14 7.00005 14C7.00001 14 6.99998 14 6.99994 14Z",
    fill: "currentColor"
  }));
}));
ArrowDownIcon.displayName = "ArrowDownIcon";
function _extends$e() {
  return _extends$e = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$e.apply(null, arguments);
}
var ArrowUpIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$e({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    fillRule: "evenodd",
    clipRule: "evenodd",
    d: "M6.51551 13.799C6.64205 13.9255 6.813 13.9977 6.99193 14C7.17087 13.9977 7.34182 13.9255 7.46835 13.799C7.59489 13.6725 7.66701 13.5015 7.66935 13.3226V2.31233L11.9326 6.57554C11.9951 6.63887 12.0697 6.68907 12.1519 6.72319C12.2341 6.75731 12.3223 6.77467 12.4113 6.77425C12.5003 6.77467 12.5885 6.75731 12.6707 6.72319C12.7529 6.68907 12.8274 6.63887 12.89 6.57554C13.0168 6.44853 13.0881 6.27635 13.0881 6.09683C13.0881 5.91732 13.0168 5.74514 12.89 5.61812L7.48846 0.216594C7.48274 0.210436 7.4769 0.204374 7.47094 0.198411C7.3439 0.0713707 7.1716 0 6.99193 0C6.81227 0 6.63997 0.0713707 6.51293 0.198411C6.50704 0.204296 6.50128 0.210278 6.49563 0.216354L1.09386 5.61812C0.974201 5.74654 0.909057 5.91639 0.912154 6.09189C0.91525 6.26738 0.986345 6.43483 1.11046 6.55894C1.23457 6.68306 1.40202 6.75415 1.57752 6.75725C1.75302 6.76035 1.92286 6.6952 2.05128 6.57554L6.31451 2.31231V13.3226C6.31685 13.5015 6.38898 13.6725 6.51551 13.799Z",
    fill: "currentColor"
  }));
}));
ArrowUpIcon.displayName = "ArrowUpIcon";
function _extends$d() {
  return _extends$d = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$d.apply(null, arguments);
}
var AngleDoubleLeftIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$d({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    fillRule: "evenodd",
    clipRule: "evenodd",
    d: "M5.71602 11.164C5.80782 11.2021 5.9063 11.2215 6.00569 11.221C6.20216 11.2301 6.39427 11.1612 6.54025 11.0294C6.68191 10.8875 6.76148 10.6953 6.76148 10.4948C6.76148 10.2943 6.68191 10.1021 6.54025 9.96024L3.51441 6.9344L6.54025 3.90855C6.624 3.76126 6.65587 3.59011 6.63076 3.42254C6.60564 3.25498 6.525 3.10069 6.40175 2.98442C6.2785 2.86815 6.11978 2.79662 5.95104 2.7813C5.78229 2.76598 5.61329 2.80776 5.47112 2.89994L1.97123 6.39983C1.82957 6.54167 1.75 6.73393 1.75 6.9344C1.75 7.13486 1.82957 7.32712 1.97123 7.46896L5.47112 10.9991C5.54096 11.0698 5.62422 11.1259 5.71602 11.164ZM11.0488 10.9689C11.1775 11.1156 11.3585 11.2061 11.5531 11.221C11.7477 11.2061 11.9288 11.1156 12.0574 10.9689C12.1815 10.8302 12.25 10.6506 12.25 10.4645C12.25 10.2785 12.1815 10.0989 12.0574 9.96024L9.03158 6.93439L12.0574 3.90855C12.1248 3.76739 12.1468 3.60881 12.1204 3.45463C12.0939 3.30045 12.0203 3.15826 11.9097 3.04765C11.7991 2.93703 11.6569 2.86343 11.5027 2.83698C11.3486 2.81053 11.19 2.83252 11.0488 2.89994L7.51865 6.36957C7.37699 6.51141 7.29742 6.70367 7.29742 6.90414C7.29742 7.1046 7.37699 7.29686 7.51865 7.4387L11.0488 10.9689Z",
    fill: "currentColor"
  }));
}));
AngleDoubleLeftIcon.displayName = "AngleDoubleLeftIcon";
function _extends$c() {
  return _extends$c = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$c.apply(null, arguments);
}
var AngleDoubleRightIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$c({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    fillRule: "evenodd",
    clipRule: "evenodd",
    d: "M7.68757 11.1451C7.7791 11.1831 7.8773 11.2024 7.9764 11.2019C8.07769 11.1985 8.17721 11.1745 8.26886 11.1312C8.36052 11.088 8.44238 11.0265 8.50943 10.9505L12.0294 7.49085C12.1707 7.34942 12.25 7.15771 12.25 6.95782C12.25 6.75794 12.1707 6.56622 12.0294 6.42479L8.50943 2.90479C8.37014 2.82159 8.20774 2.78551 8.04633 2.80192C7.88491 2.81833 7.73309 2.88635 7.6134 2.99588C7.4937 3.10541 7.41252 3.25061 7.38189 3.40994C7.35126 3.56927 7.37282 3.73423 7.44337 3.88033L10.4605 6.89748L7.44337 9.91463C7.30212 10.0561 7.22278 10.2478 7.22278 10.4477C7.22278 10.6475 7.30212 10.8393 7.44337 10.9807C7.51301 11.0512 7.59603 11.1071 7.68757 11.1451ZM1.94207 10.9505C2.07037 11.0968 2.25089 11.1871 2.44493 11.2019C2.63898 11.1871 2.81949 11.0968 2.94779 10.9505L6.46779 7.49085C6.60905 7.34942 6.68839 7.15771 6.68839 6.95782C6.68839 6.75793 6.60905 6.56622 6.46779 6.42479L2.94779 2.90479C2.80704 2.83757 2.6489 2.81563 2.49517 2.84201C2.34143 2.86839 2.19965 2.94178 2.08936 3.05207C1.97906 3.16237 1.90567 3.30415 1.8793 3.45788C1.85292 3.61162 1.87485 3.76975 1.94207 3.9105L4.95922 6.92765L1.94207 9.9448C1.81838 10.0831 1.75 10.2621 1.75 10.4477C1.75 10.6332 1.81838 10.8122 1.94207 10.9505Z",
    fill: "currentColor"
  }));
}));
AngleDoubleRightIcon.displayName = "AngleDoubleRightIcon";
function _extends$b() {
  return _extends$b = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$b.apply(null, arguments);
}
var AngleRightIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$b({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    d: "M5.25 11.1728C5.14929 11.1694 5.05033 11.1455 4.9592 11.1025C4.86806 11.0595 4.78666 10.9984 4.72 10.9228C4.57955 10.7822 4.50066 10.5916 4.50066 10.3928C4.50066 10.1941 4.57955 10.0035 4.72 9.86283L7.72 6.86283L4.72 3.86283C4.66067 3.71882 4.64765 3.55991 4.68275 3.40816C4.71785 3.25642 4.79932 3.11936 4.91585 3.01602C5.03238 2.91268 5.17819 2.84819 5.33305 2.83149C5.4879 2.81479 5.64411 2.84671 5.78 2.92283L9.28 6.42283C9.42045 6.56346 9.49934 6.75408 9.49934 6.95283C9.49934 7.15158 9.42045 7.34221 9.28 7.48283L5.78 10.9228C5.71333 10.9984 5.63193 11.0595 5.5408 11.1025C5.44966 11.1455 5.35071 11.1694 5.25 11.1728Z",
    fill: "currentColor"
  }));
}));
AngleRightIcon.displayName = "AngleRightIcon";
function _extends$a() {
  return _extends$a = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$a.apply(null, arguments);
}
var AngleLeftIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$a({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    d: "M8.75 11.185C8.65146 11.1854 8.55381 11.1662 8.4628 11.1284C8.37179 11.0906 8.28924 11.0351 8.22 10.965L4.72 7.46496C4.57955 7.32433 4.50066 7.13371 4.50066 6.93496C4.50066 6.73621 4.57955 6.54558 4.72 6.40496L8.22 2.93496C8.36095 2.84357 8.52851 2.80215 8.69582 2.81733C8.86312 2.83252 9.02048 2.90344 9.14268 3.01872C9.26487 3.134 9.34483 3.28696 9.36973 3.4531C9.39463 3.61924 9.36303 3.78892 9.28 3.93496L6.28 6.93496L9.28 9.93496C9.42045 10.0756 9.49934 10.2662 9.49934 10.465C9.49934 10.6637 9.42045 10.8543 9.28 10.995C9.13526 11.1257 8.9448 11.1939 8.75 11.185Z",
    fill: "currentColor"
  }));
}));
AngleLeftIcon.displayName = "AngleLeftIcon";
function _arrayWithHoles$1(r) {
  if (Array.isArray(r)) return r;
}
function _iterableToArrayLimit$1(r, l) {
  var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"];
  if (null != t) {
    var e, n, i, u, a = [], f = true, o = false;
    try {
      if (i = (t = t.call(r)).next, 0 === l) ;
      else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = true) ;
    } catch (r2) {
      o = true, n = r2;
    } finally {
      try {
        if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return;
      } finally {
        if (o) throw n;
      }
    }
    return a;
  }
}
function _arrayLikeToArray$2(r, a) {
  (null == a || a > r.length) && (a = r.length);
  for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e];
  return n;
}
function _unsupportedIterableToArray$2(r, a) {
  if (r) {
    if ("string" == typeof r) return _arrayLikeToArray$2(r, a);
    var t = {}.toString.call(r).slice(8, -1);
    return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray$2(r, a) : void 0;
  }
}
function _nonIterableRest$1() {
  throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.");
}
function _slicedToArray$1(r, e) {
  return _arrayWithHoles$1(r) || _iterableToArrayLimit$1(r, e) || _unsupportedIterableToArray$2(r, e) || _nonIterableRest$1();
}
function _typeof$1(o) {
  "@babel/helpers - typeof";
  return _typeof$1 = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function(o2) {
    return typeof o2;
  } : function(o2) {
    return o2 && "function" == typeof Symbol && o2.constructor === Symbol && o2 !== Symbol.prototype ? "symbol" : typeof o2;
  }, _typeof$1(o);
}
function toPrimitive$1(t, r) {
  if ("object" != _typeof$1(t) || !t) return t;
  var e = t[Symbol.toPrimitive];
  if (void 0 !== e) {
    var i = e.call(t, r);
    if ("object" != _typeof$1(i)) return i;
    throw new TypeError("@@toPrimitive must return a primitive value.");
  }
  return ("string" === r ? String : Number)(t);
}
function toPropertyKey$1(t) {
  var i = toPrimitive$1(t, "string");
  return "symbol" == _typeof$1(i) ? i : i + "";
}
function _defineProperty$1(e, r, t) {
  return (r = toPropertyKey$1(r)) in e ? Object.defineProperty(e, r, {
    value: t,
    enumerable: true,
    configurable: true,
    writable: true
  }) : e[r] = t, e;
}
var classes$3 = {
  root: "p-paginator p-component",
  left: "p-paginator-left-content",
  end: "p-paginator-right-content",
  firstPageIcon: "p-paginator-icon",
  firstPageButton: function firstPageButton(_ref) {
    var disabled = _ref.disabled;
    return classNames("p-paginator-first p-paginator-element p-link", {
      "p-disabled": disabled
    });
  },
  prevPageIcon: "p-paginator-icon",
  prevPageButton: function prevPageButton(_ref2) {
    var disabled = _ref2.disabled;
    return classNames("p-paginator-prev p-paginator-element p-link", {
      "p-disabled": disabled
    });
  },
  nextPageIcon: "p-paginator-icon",
  nextPageButton: function nextPageButton(_ref3) {
    var disabled = _ref3.disabled;
    return classNames("p-paginator-next p-paginator-element p-link", {
      "p-disabled": disabled
    });
  },
  lastPageIcon: "p-paginator-icon",
  lastPageButton: function lastPageButton(_ref4) {
    var disabled = _ref4.disabled;
    return classNames("p-paginator-last p-paginator-element p-link", {
      "p-disabled": disabled
    });
  },
  pageButton: function pageButton(_ref5) {
    var pageLink = _ref5.pageLink, startPageInView = _ref5.startPageInView, endPageInView = _ref5.endPageInView, page = _ref5.page;
    return classNames("p-paginator-page p-paginator-element p-link", {
      "p-paginator-page-start": pageLink === startPageInView,
      "p-paginator-page-end": pageLink === endPageInView,
      "p-highlight": pageLink - 1 === page
    });
  },
  pages: "p-paginator-pages"
};
var styles$2 = "\n@layer primereact {\n    .p-paginator {\n        display: flex;\n        align-items: center;\n        justify-content: center;\n        flex-wrap: wrap;\n    }\n    \n    .p-paginator-left-content {\n        margin-right: auto;\n    }\n    \n    .p-paginator-right-content {\n        margin-left: auto;\n    }\n    \n    .p-paginator-page,\n    .p-paginator-next,\n    .p-paginator-last,\n    .p-paginator-first,\n    .p-paginator-prev,\n    .p-paginator-current {\n        cursor: pointer;\n        display: inline-flex;\n        align-items: center;\n        justify-content: center;\n        line-height: 1;\n        user-select: none;\n        overflow: hidden;\n        position: relative;\n    }\n    \n    .p-paginator-element:focus {\n        z-index: 1;\n        position: relative;\n    }\n}\n";
var PaginatorBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "Paginator",
    __parentMetadata: null,
    totalRecords: 0,
    rows: 0,
    first: 0,
    pageLinkSize: 5,
    rowsPerPageOptions: null,
    alwaysShow: true,
    style: null,
    className: null,
    template: "FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown",
    onPageChange: null,
    leftContent: null,
    rightContent: null,
    dropdownAppendTo: null,
    currentPageReportTemplate: "({currentPage} of {totalPages})",
    children: void 0
  },
  css: {
    classes: classes$3,
    styles: styles$2
  }
});
var CurrentPageReportBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "CurrentPageReport",
    pageCount: null,
    page: null,
    first: null,
    rows: null,
    totalRecords: null,
    reportTemplate: "({currentPage} of {totalPages})",
    template: null,
    children: void 0
  }
});
var FirstPageLinkBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "FirstPageLink",
    disabled: false,
    onClick: null,
    template: null,
    firstPageLinkIcon: null,
    children: void 0
  }
});
var JumpToPageInputBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "JumpToPageInput",
    page: null,
    rows: null,
    pageCount: null,
    disabled: false,
    template: null,
    onChange: null,
    children: void 0,
    metaData: null,
    ptm: null
  }
});
var LastPageLinkBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "LastPageLink",
    disabled: false,
    onClick: null,
    template: null,
    lastPageLinkIcon: null,
    children: void 0
  }
});
var NextPageLinkBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "NextPageLink",
    disabled: false,
    onClick: null,
    template: null,
    nextPageLinkIcon: null,
    children: void 0
  }
});
var PageLinksBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "PageLinks",
    value: null,
    page: null,
    rows: null,
    pageCount: null,
    links: null,
    template: null,
    children: void 0
  }
});
var PrevPageLinkBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "PrevPageLink",
    disabled: false,
    onClick: null,
    template: null,
    prevPageLinkIcon: null,
    children: void 0
  }
});
var RowsPerPageDropdownBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "RowsPerPageDropdown",
    options: null,
    value: null,
    page: null,
    pageCount: null,
    totalRecords: 0,
    appendTo: null,
    onChange: null,
    template: null,
    disabled: false,
    children: void 0
  }
});
function ownKeys$5$1(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$5$1(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$5$1(Object(t), true).forEach(function(r2) {
      _defineProperty$1(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$5$1(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var CurrentPageReport = /* @__PURE__ */ React.memo(function(inProps) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = CurrentPageReportBase.getProps(inProps, context);
  var report = {
    currentPage: props.page + 1,
    totalPages: props.totalPages,
    first: Math.min(props.first + 1, props.totalRecords),
    last: Math.min(props.first + props.rows, props.totalRecords),
    rows: props.rows,
    totalRecords: props.totalRecords
  };
  var text = props.reportTemplate.replace("{currentPage}", report.currentPage).replace("{totalPages}", report.totalPages).replace("{first}", report.first).replace("{last}", report.last).replace("{rows}", report.rows).replace("{totalRecords}", report.totalRecords);
  var currentProps = mergeProps({
    "aria-live": "polite",
    className: "p-paginator-current"
  }, props.ptm("current", {
    hostName: props.hostName
  }));
  var element = /* @__PURE__ */ React.createElement("span", currentProps, text);
  if (props.template) {
    var defaultOptions = _objectSpread$5$1(_objectSpread$5$1({}, report), {
      ariaLive: "polite",
      className: "p-paginator-current",
      element,
      props
    });
    return ObjectUtils.getJSXElement(props.template, defaultOptions);
  }
  return element;
});
CurrentPageReport.displayName = "CurrentPageReport";
function ownKeys$4$1(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$4$1(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$4$1(Object(t), true).forEach(function(r2) {
      _defineProperty$1(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$4$1(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var FirstPageLink = /* @__PURE__ */ React.memo(function(inProps) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = FirstPageLinkBase.getProps(inProps, context);
  var ptm = props.ptm, cx = props.cx;
  var getPTOptions = function getPTOptions2(key) {
    return ptm(key, {
      hostName: props.hostName,
      context: {
        disabled: props.disabled
      }
    });
  };
  var className = classNames("p-paginator-first p-paginator-element p-link", {
    "p-disabled": props.disabled
  });
  var iconClassName = "p-paginator-icon";
  var firstPageIconProps = mergeProps({
    className: cx("firstPageIcon")
  }, getPTOptions("firstPageIcon"));
  var icon = props.firstPageLinkIcon || /* @__PURE__ */ React.createElement(AngleDoubleLeftIcon, firstPageIconProps);
  var firstPageLinkIcon = IconUtils.getJSXIcon(icon, _objectSpread$4$1({}, firstPageIconProps), {
    props
  });
  var firstPageButtonProps = mergeProps({
    type: "button",
    className: cx("firstPageButton", {
      disabled: props.disabled
    }),
    onClick: props.onClick,
    disabled: props.disabled,
    "aria-label": ariaLabel$2("firstPageLabel")
  }, getPTOptions("firstPageButton"));
  var element = /* @__PURE__ */ React.createElement("button", firstPageButtonProps, firstPageLinkIcon, /* @__PURE__ */ React.createElement(Ripple, null));
  if (props.template) {
    var defaultOptions = {
      onClick: props.onClick,
      className,
      iconClassName,
      disabled: props.disabled,
      element,
      props
    };
    return ObjectUtils.getJSXElement(props.template, defaultOptions);
  }
  return element;
});
FirstPageLink.displayName = "FirstPageLink";
function _createClass$1(e, r, t) {
  return Object.defineProperty(e, "prototype", {
    writable: false
  }), e;
}
function _classCallCheck$1(a, n) {
  if (!(a instanceof n)) throw new TypeError("Cannot call a class as a function");
}
var FilterMatchMode$1 = Object.freeze({
  STARTS_WITH: "startsWith",
  CONTAINS: "contains",
  NOT_CONTAINS: "notContains",
  ENDS_WITH: "endsWith",
  EQUALS: "equals",
  NOT_EQUALS: "notEquals",
  IN: "in",
  NOT_IN: "notIn",
  LESS_THAN: "lt",
  LESS_THAN_OR_EQUAL_TO: "lte",
  GREATER_THAN: "gt",
  GREATER_THAN_OR_EQUAL_TO: "gte",
  BETWEEN: "between",
  DATE_IS: "dateIs",
  DATE_IS_NOT: "dateIsNot",
  DATE_BEFORE: "dateBefore",
  DATE_AFTER: "dateAfter",
  CUSTOM: "custom"
});
var PrimeReact$1 = /* @__PURE__ */ _createClass$1(function PrimeReact() {
  _classCallCheck$1(this, PrimeReact);
});
_defineProperty$1(PrimeReact$1, "ripple", false);
_defineProperty$1(PrimeReact$1, "inputStyle", "outlined");
_defineProperty$1(PrimeReact$1, "locale", "en");
_defineProperty$1(PrimeReact$1, "appendTo", null);
_defineProperty$1(PrimeReact$1, "cssTransition", true);
_defineProperty$1(PrimeReact$1, "autoZIndex", true);
_defineProperty$1(PrimeReact$1, "hideOverlaysOnDocumentScrolling", false);
_defineProperty$1(PrimeReact$1, "nonce", null);
_defineProperty$1(PrimeReact$1, "nullSortOrder", 1);
_defineProperty$1(PrimeReact$1, "zIndex", {
  modal: 1100,
  overlay: 1e3,
  menu: 1e3,
  tooltip: 1100,
  toast: 1200
});
_defineProperty$1(PrimeReact$1, "pt", void 0);
_defineProperty$1(PrimeReact$1, "filterMatchModeOptions", {
  text: [FilterMatchMode$1.STARTS_WITH, FilterMatchMode$1.CONTAINS, FilterMatchMode$1.NOT_CONTAINS, FilterMatchMode$1.ENDS_WITH, FilterMatchMode$1.EQUALS, FilterMatchMode$1.NOT_EQUALS],
  numeric: [FilterMatchMode$1.EQUALS, FilterMatchMode$1.NOT_EQUALS, FilterMatchMode$1.LESS_THAN, FilterMatchMode$1.LESS_THAN_OR_EQUAL_TO, FilterMatchMode$1.GREATER_THAN, FilterMatchMode$1.GREATER_THAN_OR_EQUAL_TO],
  date: [FilterMatchMode$1.DATE_IS, FilterMatchMode$1.DATE_IS_NOT, FilterMatchMode$1.DATE_BEFORE, FilterMatchMode$1.DATE_AFTER]
});
_defineProperty$1(PrimeReact$1, "changeTheme", function(currentTheme, newTheme, linkElementId, callback) {
  var _linkElement$parentNo;
  var linkElement = document.getElementById(linkElementId);
  if (!linkElement) {
    throw Error("Element with id ".concat(linkElementId, " not found."));
  }
  var newThemeUrl = linkElement.getAttribute("href").replace(currentTheme, newTheme);
  var newLinkElement = document.createElement("link");
  newLinkElement.setAttribute("rel", "stylesheet");
  newLinkElement.setAttribute("id", linkElementId);
  newLinkElement.setAttribute("href", newThemeUrl);
  newLinkElement.addEventListener("load", function() {
    if (callback) {
      callback();
    }
  });
  (_linkElement$parentNo = linkElement.parentNode) === null || _linkElement$parentNo === void 0 || _linkElement$parentNo.replaceChild(newLinkElement, linkElement);
});
var locales$1 = {
  en: {
    accept: "Yes",
    addRule: "Add Rule",
    am: "AM",
    apply: "Apply",
    cancel: "Cancel",
    choose: "Choose",
    chooseDate: "Choose Date",
    chooseMonth: "Choose Month",
    chooseYear: "Choose Year",
    clear: "Clear",
    completed: "Completed",
    contains: "Contains",
    custom: "Custom",
    dateAfter: "Date is after",
    dateBefore: "Date is before",
    dateFormat: "mm/dd/yy",
    dateIs: "Date is",
    dateIsNot: "Date is not",
    dayNames: ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"],
    dayNamesMin: ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"],
    dayNamesShort: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
    emptyFilterMessage: "No results found",
    emptyMessage: "No available options",
    emptySearchMessage: "No results found",
    emptySelectionMessage: "No selected item",
    endsWith: "Ends with",
    equals: "Equals",
    fileChosenMessage: "{0} files",
    fileSizeTypes: ["B", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"],
    filter: "Filter",
    firstDayOfWeek: 0,
    gt: "Greater than",
    gte: "Greater than or equal to",
    lt: "Less than",
    lte: "Less than or equal to",
    matchAll: "Match All",
    matchAny: "Match Any",
    medium: "Medium",
    monthNames: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
    monthNamesShort: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
    nextDecade: "Next Decade",
    nextHour: "Next Hour",
    nextMinute: "Next Minute",
    nextMonth: "Next Month",
    nextSecond: "Next Second",
    nextYear: "Next Year",
    noFileChosenMessage: "No file chosen",
    noFilter: "No Filter",
    notContains: "Not contains",
    notEquals: "Not equals",
    now: "Now",
    passwordPrompt: "Enter a password",
    pending: "Pending",
    pm: "PM",
    prevDecade: "Previous Decade",
    prevHour: "Previous Hour",
    prevMinute: "Previous Minute",
    prevMonth: "Previous Month",
    prevSecond: "Previous Second",
    prevYear: "Previous Year",
    reject: "No",
    removeRule: "Remove Rule",
    searchMessage: "{0} results are available",
    selectionMessage: "{0} items selected",
    showMonthAfterYear: false,
    startsWith: "Starts with",
    strong: "Strong",
    today: "Today",
    upload: "Upload",
    weak: "Weak",
    weekHeader: "Wk",
    aria: {
      cancelEdit: "Cancel Edit",
      close: "Close",
      collapseLabel: "Collapse",
      collapseRow: "Row Collapsed",
      editRow: "Edit Row",
      expandLabel: "Expand",
      expandRow: "Row Expanded",
      falseLabel: "False",
      filterConstraint: "Filter Constraint",
      filterOperator: "Filter Operator",
      firstPageLabel: "First Page",
      gridView: "Grid View",
      hideFilterMenu: "Hide Filter Menu",
      jumpToPageDropdownLabel: "Jump to Page Dropdown",
      jumpToPageInputLabel: "Jump to Page Input",
      lastPageLabel: "Last Page",
      listLabel: "Option List",
      listView: "List View",
      moveAllToSource: "Move All to Source",
      moveAllToTarget: "Move All to Target",
      moveBottom: "Move Bottom",
      moveDown: "Move Down",
      moveToSource: "Move to Source",
      moveToTarget: "Move to Target",
      moveTop: "Move Top",
      moveUp: "Move Up",
      navigation: "Navigation",
      next: "Next",
      nextPageLabel: "Next Page",
      nullLabel: "Not Selected",
      otpLabel: "Please enter one time password character {0}",
      pageLabel: "Page {page}",
      passwordHide: "Hide Password",
      passwordShow: "Show Password",
      previous: "Previous",
      prevPageLabel: "Previous Page",
      removeLabel: "Remove",
      rotateLeft: "Rotate Left",
      rotateRight: "Rotate Right",
      rowsPerPageLabel: "Rows per page",
      saveEdit: "Save Edit",
      scrollTop: "Scroll Top",
      selectAll: "All items selected",
      selectLabel: "Select",
      selectRow: "Row Selected",
      showFilterMenu: "Show Filter Menu",
      slide: "Slide",
      slideNumber: "{slideNumber}",
      star: "1 star",
      stars: "{star} stars",
      trueLabel: "True",
      unselectAll: "All items unselected",
      unselectLabel: "Unselect",
      unselectRow: "Row Unselected",
      zoomImage: "Zoom Image",
      zoomIn: "Zoom In",
      zoomOut: "Zoom Out"
    }
  }
};
function ariaLabel$1(ariaKey, options) {
  if (ariaKey.includes("__proto__") || ariaKey.includes("prototype")) {
    throw new Error("Unsafe ariaKey detected");
  }
  var _locale = PrimeReact$1.locale;
  try {
    var _ariaLabel = localeOptions$1(_locale).aria[ariaKey];
    if (_ariaLabel) {
      for (var key in options) {
        if (options.hasOwnProperty(key)) {
          _ariaLabel = _ariaLabel.replace("{".concat(key, "}"), options[key]);
        }
      }
    }
    return _ariaLabel;
  } catch (error) {
    throw new Error("The ".concat(ariaKey, " option is not found in the current locale('").concat(_locale, "')."));
  }
}
function localeOptions$1(locale) {
  var _locale = locale || PrimeReact$1.locale;
  if (_locale.includes("__proto__") || _locale.includes("prototype")) {
    throw new Error("Unsafe locale detected");
  }
  return locales$1[_locale];
}
var JumpToPageInput = /* @__PURE__ */ React.memo(function(inProps) {
  useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = JumpToPageInputBase.getProps(inProps, context);
  var ariaLabelValue = ariaLabel$1("jumpToPageInputLabel");
  var onChange = function onChange2(event) {
    if (props.onChange) {
      props.onChange(props.rows * (event.value - 1), props.rows);
    }
  };
  var value = props.totalPages > 0 ? props.page + 1 : 0;
  var element = /* @__PURE__ */ React.createElement(InputNumber, {
    value,
    onChange,
    className: "p-paginator-page-input",
    disabled: props.disabled,
    pt: props.ptm("JTPInput"),
    unstyled: props.unstyled,
    __parentMetadata: {
      parent: props.metaData
    },
    "aria-label": ariaLabelValue
  });
  if (props.template) {
    var defaultOptions = {
      value,
      onChange,
      disabled: props.disabled,
      className: "p-paginator-page-input",
      "aria-label": ariaLabelValue,
      element,
      props
    };
    return ObjectUtils.getJSXElement(props.template, defaultOptions);
  }
  return element;
});
JumpToPageInput.displayName = "JumpToPageInput";
function ownKeys$3$1(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$3$1(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$3$1(Object(t), true).forEach(function(r2) {
      _defineProperty$1(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$3$1(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var LastPageLink = /* @__PURE__ */ React.memo(function(inProps) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = LastPageLinkBase.getProps(inProps, context);
  var ptm = props.ptm, cx = props.cx;
  var getPTOptions = function getPTOptions2(key) {
    return ptm(key, {
      hostName: props.hostName,
      context: {
        disabled: props.disabled
      }
    });
  };
  var className = classNames("p-paginator-last p-paginator-element p-link", {
    "p-disabled": props.disabled
  });
  var iconClassName = "p-paginator-icon";
  var lastPageIconProps = mergeProps({
    className: cx("lastPageIcon")
  }, getPTOptions("lastPageIcon"));
  var icon = props.lastPageLinkIcon || /* @__PURE__ */ React.createElement(AngleDoubleRightIcon, lastPageIconProps);
  var lastPageLinkIcon = IconUtils.getJSXIcon(icon, _objectSpread$3$1({}, lastPageIconProps), {
    props
  });
  var lastPageButtonProps = mergeProps({
    type: "button",
    className: cx("lastPageButton", {
      disabled: props.disabled
    }),
    onClick: props.onClick,
    disabled: props.disabled,
    "aria-label": ariaLabel$2("lastPageLabel")
  }, getPTOptions("lastPageButton"));
  var element = /* @__PURE__ */ React.createElement("button", lastPageButtonProps, lastPageLinkIcon, /* @__PURE__ */ React.createElement(Ripple, null));
  if (props.template) {
    var defaultOptions = {
      onClick: props.onClick,
      className,
      iconClassName,
      disabled: props.disabled,
      element,
      props
    };
    return ObjectUtils.getJSXElement(props.template, defaultOptions);
  }
  return element;
});
LastPageLink.displayName = "LastPageLink";
function ownKeys$2$1(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$2$1(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$2$1(Object(t), true).forEach(function(r2) {
      _defineProperty$1(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$2$1(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var NextPageLink = /* @__PURE__ */ React.memo(function(inProps) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = NextPageLinkBase.getProps(inProps, context);
  var ptm = props.ptm, cx = props.cx;
  var getPTOptions = function getPTOptions2(key) {
    return ptm(key, {
      hostName: props.hostName,
      context: {
        disabled: props.disabled
      }
    });
  };
  var className = classNames("p-paginator-next p-paginator-element p-link", {
    "p-disabled": props.disabled
  });
  var iconClassName = "p-paginator-icon";
  var nextPageIconProps = mergeProps({
    className: cx("nextPageIcon")
  }, getPTOptions("nextPageIcon"));
  var icon = props.nextPageLinkIcon || /* @__PURE__ */ React.createElement(AngleRightIcon, nextPageIconProps);
  var nextPageLinkIcon = IconUtils.getJSXIcon(icon, _objectSpread$2$1({}, nextPageIconProps), {
    props
  });
  var nextPageButtonProps = mergeProps({
    type: "button",
    className: cx("nextPageButton", {
      disabled: props.disabled
    }),
    onClick: props.onClick,
    disabled: props.disabled,
    "aria-label": ariaLabel$2("nextPageLabel")
  }, getPTOptions("nextPageButton"));
  var element = /* @__PURE__ */ React.createElement("button", nextPageButtonProps, nextPageLinkIcon, /* @__PURE__ */ React.createElement(Ripple, null));
  if (props.template) {
    var defaultOptions = {
      onClick: props.onClick,
      className,
      iconClassName,
      disabled: props.disabled,
      element,
      nextPageLinkIcon,
      props
    };
    return ObjectUtils.getJSXElement(props.template, defaultOptions);
  }
  return element;
});
NextPageLink.displayName = "NextPageLink";
var PageLinks = /* @__PURE__ */ React.memo(function(inProps) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = PageLinksBase.getProps(inProps, context);
  var ptm = props.ptm, cx = props.cx;
  var getPTOptions = function getPTOptions2(pageLink, key) {
    return ptm(key, {
      hostName: props.hostName,
      context: {
        active: pageLink - 1 === props.page
      }
    });
  };
  var onPageLinkClick = function onPageLinkClick2(event, pageLink) {
    if (props.onClick) {
      props.onClick({
        originalEvent: event,
        value: pageLink
      });
    }
    event.preventDefault();
  };
  var elements;
  if (props.value) {
    var startPageInView = props.value[0];
    var endPageInView = props.value[props.value.length - 1];
    elements = props.value.map(function(pageLink) {
      var className = classNames("p-paginator-page p-paginator-element p-link", {
        "p-paginator-page-start": pageLink === startPageInView,
        "p-paginator-page-end": pageLink === endPageInView,
        "p-highlight": pageLink - 1 === props.page
      });
      var pageButtonProps = mergeProps({
        type: "button",
        onClick: function onClick(e) {
          return onPageLinkClick(e, pageLink);
        },
        className: cx("pageButton", {
          pageLink,
          startPageInView,
          endPageInView,
          page: props.page
        }),
        disabled: props.disabled,
        "aria-label": ariaLabel$2("pageLabel", {
          page: pageLink
        }),
        "aria-current": pageLink - 1 === props.page ? "true" : void 0
      }, getPTOptions(pageLink, "pageButton"));
      var element = /* @__PURE__ */ React.createElement("button", pageButtonProps, pageLink, /* @__PURE__ */ React.createElement(Ripple, null));
      if (props.template) {
        var defaultOptions = {
          onClick: function onClick(e) {
            return onPageLinkClick(e, pageLink);
          },
          className,
          view: {
            startPage: startPageInView - 1,
            endPage: endPageInView - 1
          },
          page: pageLink - 1,
          currentPage: props.page,
          totalPages: props.totalPages,
          ariaLabel: ariaLabel$2("pageLabel", {
            page: pageLink
          }),
          ariaCurrent: pageLink - 1 === props.page ? "true" : void 0,
          element,
          props
        };
        element = ObjectUtils.getJSXElement(props.template, defaultOptions);
      }
      return /* @__PURE__ */ React.createElement(React.Fragment, {
        key: pageLink
      }, element);
    });
  }
  var pagesProps = mergeProps({
    className: cx("pages")
  }, ptm("pages", {
    hostName: props.hostName
  }));
  return /* @__PURE__ */ React.createElement("span", pagesProps, elements);
});
PageLinks.displayName = "PageLinks";
function ownKeys$1$1(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$1$1(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$1$1(Object(t), true).forEach(function(r2) {
      _defineProperty$1(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$1$1(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var PrevPageLink = /* @__PURE__ */ React.memo(function(inProps) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = PrevPageLinkBase.getProps(inProps, context);
  var ptm = props.ptm, cx = props.cx;
  var getPTOptions = function getPTOptions2(key) {
    return ptm(key, {
      hostName: props.hostName,
      context: {
        disabled: props.disabled
      }
    });
  };
  var className = classNames("p-paginator-prev p-paginator-element p-link", {
    "p-disabled": props.disabled
  });
  var iconClassName = "p-paginator-icon";
  var prevPageIconProps = mergeProps({
    className: cx("prevPageIcon")
  }, getPTOptions("prevPageIcon"));
  var icon = props.prevPageLinkIcon || /* @__PURE__ */ React.createElement(AngleLeftIcon, prevPageIconProps);
  var prevPageLinkIcon = IconUtils.getJSXIcon(icon, _objectSpread$1$1({}, prevPageIconProps), {
    props
  });
  var prevPageButtonProps = mergeProps({
    type: "button",
    className: cx("prevPageButton", {
      disabled: props.disabled
    }),
    onClick: props.onClick,
    disabled: props.disabled,
    "aria-label": ariaLabel$2("prevPageLabel")
  }, getPTOptions("prevPageButton"));
  var element = /* @__PURE__ */ React.createElement("button", prevPageButtonProps, prevPageLinkIcon, /* @__PURE__ */ React.createElement(Ripple, null));
  if (props.template) {
    var defaultOptions = {
      onClick: props.onClick,
      className,
      iconClassName,
      disabled: props.disabled,
      element,
      props
    };
    return ObjectUtils.getJSXElement(props.template, defaultOptions);
  }
  return element;
});
PrevPageLink.displayName = "PrevPageLink";
var RowsPerPageDropdown = /* @__PURE__ */ React.memo(function(inProps) {
  useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = RowsPerPageDropdownBase.getProps(inProps, context);
  var hasOptions = props.options && props.options.length > 0;
  var options = hasOptions ? props.options.map(function(opt) {
    return {
      label: String(opt),
      value: opt
    };
  }) : [];
  var placeholderValue = localeOption("choose");
  var ariaLabelValue = ariaLabel$1("jumpToPageDropdownLabel");
  var element = hasOptions ? /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement(Dropdown, {
    value: props.value,
    options,
    onChange: props.onChange,
    appendTo: props.appendTo,
    disabled: props.disabled,
    placeholder: placeholderValue,
    "aria-label": ariaLabelValue,
    pt: props.ptm("RPPDropdown"),
    unstyled: props.unstyled,
    __parentMetadata: {
      parent: props.metaData
    }
  })) : null;
  if (props.template) {
    var defaultOptions = {
      value: props.value,
      options,
      onChange: props.onChange,
      appendTo: props.appendTo,
      currentPage: props.page,
      totalPages: props.pageCount,
      totalRecords: props.totalRecords,
      disabled: props.disabled,
      ariaLabel: ariaLabelValue,
      element,
      props
    };
    return ObjectUtils.getJSXElement(props.template, defaultOptions);
  }
  return element;
});
RowsPerPageDropdown.displayName = "RowsPerPageDropdown";
function ownKeys$g(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$g(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$g(Object(t), true).forEach(function(r2) {
      _defineProperty$1(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$g(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var Paginator = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = PaginatorBase.getProps(inProps, context);
  var metaData = _objectSpread$g({
    props
  }, props.__parentMetadata);
  var _PaginatorBase$setMet = PaginatorBase.setMetaData(metaData), ptm = _PaginatorBase$setMet.ptm, cx = _PaginatorBase$setMet.cx, isUnstyled = _PaginatorBase$setMet.isUnstyled;
  useHandleStyle(PaginatorBase.css.styles, isUnstyled, {
    name: "paginator"
  });
  var elementRef = React.useRef(null);
  var page = Math.floor(props.first / props.rows);
  var totalPages = Math.ceil(props.totalRecords / props.rows);
  var isFirstPage = page === 0;
  var isLastPage = page === totalPages - 1;
  var isEmpty = totalPages === 0;
  var calculatePageLinkBoundaries = function calculatePageLinkBoundaries2() {
    var numberOfPages = totalPages;
    var visiblePages = Math.min(props.pageLinkSize, numberOfPages);
    var start = Math.max(0, Math.ceil(page - visiblePages / 2));
    var end = Math.min(numberOfPages - 1, start + visiblePages - 1);
    var delta = props.pageLinkSize - (end - start + 1);
    start = Math.max(0, start - delta);
    return [start, end];
  };
  var updatePageLinks = function updatePageLinks2() {
    var pageLinks = [];
    var boundaries = calculatePageLinkBoundaries();
    var start = boundaries[0];
    var end = boundaries[1];
    for (var i = start; i <= end; i++) {
      pageLinks.push(i + 1);
    }
    return pageLinks;
  };
  var changePage = function changePage2(first, rows) {
    var pc = totalPages;
    var p = Math.floor(first / rows);
    if (p >= 0 && p < pc) {
      var newPageState = {
        first,
        rows,
        page: p,
        totalPages: pc
      };
      if (props.onPageChange) {
        props.onPageChange(newPageState);
      }
    }
  };
  var changePageToFirst = function changePageToFirst2(event) {
    changePage(0, props.rows);
    event.preventDefault();
  };
  var changePageToPrev = function changePageToPrev2(event) {
    changePage(props.first - props.rows, props.rows);
    event.preventDefault();
  };
  var onPageLinkClick = function onPageLinkClick2(event) {
    changePage((event.value - 1) * props.rows, props.rows);
  };
  var changePageToNext = function changePageToNext2(event) {
    changePage(props.first + props.rows, props.rows);
    event.preventDefault();
  };
  var changePageToLast = function changePageToLast2(event) {
    changePage((totalPages - 1) * props.rows, props.rows);
    event.preventDefault();
  };
  var onRowsChange = function onRowsChange2(event) {
    var rows = event.value;
    changePage(0, rows);
  };
  React.useImperativeHandle(ref, function() {
    return {
      props,
      getElement: function getElement() {
        return elementRef.current;
      }
    };
  });
  useUpdateEffect(function() {
    if (page > 0 && props.first >= props.totalRecords) {
      changePage((totalPages - 1) * props.rows, props.rows);
    }
  }, [props.totalRecords]);
  var createElement = function createElement2(key, template) {
    var element;
    switch (key) {
      case "FirstPageLink":
        element = /* @__PURE__ */ React.createElement(FirstPageLink, {
          hostName: "Paginator",
          key,
          page,
          totalPages,
          totalRecords: props.totalRecords,
          rows: props.rows,
          onClick: changePageToFirst,
          disabled: isFirstPage || isEmpty,
          template,
          firstPageLinkIcon: props.firstPageLinkIcon,
          ptm,
          cx
        });
        break;
      case "PrevPageLink":
        element = /* @__PURE__ */ React.createElement(PrevPageLink, {
          hostName: "Paginator",
          key,
          page,
          totalPages,
          totalRecords: props.totalRecords,
          rows: props.rows,
          onClick: changePageToPrev,
          disabled: isFirstPage || isEmpty,
          template,
          prevPageLinkIcon: props.prevPageLinkIcon,
          ptm,
          cx
        });
        break;
      case "NextPageLink":
        element = /* @__PURE__ */ React.createElement(NextPageLink, {
          hostName: "Paginator",
          key,
          page,
          totalPages,
          totalRecords: props.totalRecords,
          rows: props.rows,
          onClick: changePageToNext,
          disabled: isLastPage || isEmpty,
          template,
          nextPageLinkIcon: props.nextPageLinkIcon,
          ptm,
          cx
        });
        break;
      case "LastPageLink":
        element = /* @__PURE__ */ React.createElement(LastPageLink, {
          hostName: "Paginator",
          key,
          page,
          totalPages,
          totalRecords: props.totalRecords,
          rows: props.rows,
          onClick: changePageToLast,
          disabled: isLastPage || isEmpty,
          template,
          lastPageLinkIcon: props.lastPageLinkIcon,
          ptm,
          cx
        });
        break;
      case "PageLinks":
        element = /* @__PURE__ */ React.createElement(PageLinks, {
          hostName: "Paginator",
          key,
          page,
          totalPages,
          totalRecords: props.totalRecords,
          rows: props.rows,
          value: updatePageLinks(),
          onClick: onPageLinkClick,
          template,
          ptm,
          cx
        });
        break;
      case "RowsPerPageDropdown":
        element = /* @__PURE__ */ React.createElement(RowsPerPageDropdown, {
          hostName: "Paginator",
          key,
          value: props.rows,
          page,
          totalPages,
          totalRecords: props.totalRecords,
          options: props.rowsPerPageOptions,
          onChange: onRowsChange,
          appendTo: props.dropdownAppendTo,
          template,
          disabled: isEmpty,
          unstyled: props.unstyled,
          ptm,
          cx,
          metaData
        });
        break;
      case "CurrentPageReport":
        element = /* @__PURE__ */ React.createElement(CurrentPageReport, {
          hostName: "Paginator",
          reportTemplate: props.currentPageReportTemplate,
          key,
          page,
          totalPages,
          totalRecords: props.totalRecords,
          rows: props.rows,
          first: props.first,
          template,
          ptm
        });
        break;
      case "JumpToPageInput":
        element = /* @__PURE__ */ React.createElement(JumpToPageInput, {
          hostName: "Paginator",
          key,
          rows: props.rows,
          page,
          totalPages,
          onChange: changePage,
          disabled: isEmpty,
          template,
          ptm,
          unstyled: props.unstyled,
          metaData
        });
        break;
      default:
        element = null;
        break;
    }
    return element;
  };
  var createElements = function createElements2() {
    var template = props.template;
    if (template) {
      if (_typeof$1(template) === "object") {
        return template.layout ? template.layout.split(" ").map(function(value) {
          var key = value.trim();
          return createElement(key, template[key]);
        }) : Object.entries(template).map(function(_ref) {
          var _ref2 = _slicedToArray$1(_ref, 2), key = _ref2[0], _template = _ref2[1];
          return createElement(key, _template);
        });
      }
      return template.split(" ").map(function(value) {
        return createElement(value.trim());
      });
    }
    return null;
  };
  if (!props.alwaysShow && totalPages <= 1) {
    return null;
  }
  var leftContent = ObjectUtils.getJSXElement(props.leftContent, props);
  var rightContent = ObjectUtils.getJSXElement(props.rightContent, props);
  var elements = createElements();
  var leftProps = mergeProps({
    className: cx("left")
  }, ptm("left"));
  var leftElement = leftContent && /* @__PURE__ */ React.createElement("div", leftProps, leftContent);
  var endProps = mergeProps({
    className: cx("end")
  }, ptm("end"));
  var rightElement = rightContent && /* @__PURE__ */ React.createElement("div", endProps, rightContent);
  var rootProps = mergeProps({
    ref: elementRef,
    className: classNames(props.className, cx("root")),
    style: props.style
  }, PaginatorBase.getOtherProps(props), ptm("root"));
  return /* @__PURE__ */ React.createElement("div", rootProps, leftElement, elements, rightElement);
}));
Paginator.displayName = "Paginator";
function _extends$9() {
  return _extends$9 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$9.apply(null, arguments);
}
var BarsIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$9({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    fillRule: "evenodd",
    clipRule: "evenodd",
    d: "M13.3226 3.6129H0.677419C0.497757 3.6129 0.325452 3.54152 0.198411 3.41448C0.0713707 3.28744 0 3.11514 0 2.93548C0 2.75581 0.0713707 2.58351 0.198411 2.45647C0.325452 2.32943 0.497757 2.25806 0.677419 2.25806H13.3226C13.5022 2.25806 13.6745 2.32943 13.8016 2.45647C13.9286 2.58351 14 2.75581 14 2.93548C14 3.11514 13.9286 3.28744 13.8016 3.41448C13.6745 3.54152 13.5022 3.6129 13.3226 3.6129ZM13.3226 7.67741H0.677419C0.497757 7.67741 0.325452 7.60604 0.198411 7.479C0.0713707 7.35196 0 7.17965 0 6.99999C0 6.82033 0.0713707 6.64802 0.198411 6.52098C0.325452 6.39394 0.497757 6.32257 0.677419 6.32257H13.3226C13.5022 6.32257 13.6745 6.39394 13.8016 6.52098C13.9286 6.64802 14 6.82033 14 6.99999C14 7.17965 13.9286 7.35196 13.8016 7.479C13.6745 7.60604 13.5022 7.67741 13.3226 7.67741ZM0.677419 11.7419H13.3226C13.5022 11.7419 13.6745 11.6706 13.8016 11.5435C13.9286 11.4165 14 11.2442 14 11.0645C14 10.8848 13.9286 10.7125 13.8016 10.5855C13.6745 10.4585 13.5022 10.3871 13.3226 10.3871H0.677419C0.497757 10.3871 0.325452 10.4585 0.198411 10.5855C0.0713707 10.7125 0 10.8848 0 11.0645C0 11.2442 0.0713707 11.4165 0.198411 11.5435C0.325452 11.6706 0.497757 11.7419 0.677419 11.7419Z",
    fill: "currentColor"
  }));
}));
BarsIcon.displayName = "BarsIcon";
function _extends$8() {
  return _extends$8 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$8.apply(null, arguments);
}
var PencilIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$8({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    d: "M0.609628 13.959C0.530658 13.9599 0.452305 13.9451 0.379077 13.9156C0.305849 13.8861 0.239191 13.8424 0.18294 13.787C0.118447 13.7234 0.0688234 13.6464 0.0376166 13.5614C0.00640987 13.4765 -0.00560954 13.3857 0.00241768 13.2956L0.25679 10.1501C0.267698 10.0041 0.331934 9.86709 0.437312 9.76516L9.51265 0.705715C10.0183 0.233014 10.6911 -0.0203041 11.3835 0.00127367C12.0714 0.00660201 12.7315 0.27311 13.2298 0.746671C13.7076 1.23651 13.9824 1.88848 13.9992 2.57201C14.0159 3.25554 13.7733 3.92015 13.32 4.4327L4.23648 13.5331C4.13482 13.6342 4.0017 13.6978 3.85903 13.7133L0.667067 14L0.609628 13.959ZM1.43018 10.4696L1.25787 12.714L3.50619 12.5092L12.4502 3.56444C12.6246 3.35841 12.7361 3.10674 12.7714 2.83933C12.8067 2.57193 12.7644 2.30002 12.6495 2.05591C12.5346 1.8118 12.3519 1.60575 12.1231 1.46224C11.8943 1.31873 11.6291 1.2438 11.3589 1.24633C11.1813 1.23508 11.0033 1.25975 10.8355 1.31887C10.6677 1.37798 10.5136 1.47033 10.3824 1.59036L1.43018 10.4696Z",
    fill: "currentColor"
  }));
}));
PencilIcon.displayName = "PencilIcon";
function _extends$7() {
  return _extends$7 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$7.apply(null, arguments);
}
var FilterIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$7({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    d: "M8.64708 14H5.35296C5.18981 13.9979 5.03395 13.9321 4.91858 13.8167C4.8032 13.7014 4.73745 13.5455 4.73531 13.3824V7L0.329431 0.98C0.259794 0.889466 0.217389 0.780968 0.20718 0.667208C0.19697 0.553448 0.219379 0.439133 0.271783 0.337647C0.324282 0.236453 0.403423 0.151519 0.500663 0.0920138C0.597903 0.0325088 0.709548 0.000692754 0.823548 0H13.1765C13.2905 0.000692754 13.4021 0.0325088 13.4994 0.0920138C13.5966 0.151519 13.6758 0.236453 13.7283 0.337647C13.7807 0.439133 13.8031 0.553448 13.7929 0.667208C13.7826 0.780968 13.7402 0.889466 13.6706 0.98L9.26472 7V13.3824C9.26259 13.5455 9.19683 13.7014 9.08146 13.8167C8.96609 13.9321 8.81022 13.9979 8.64708 14ZM5.97061 12.7647H8.02943V6.79412C8.02878 6.66289 8.07229 6.53527 8.15296 6.43177L11.9412 1.23529H2.05884L5.86355 6.43177C5.94422 6.53527 5.98773 6.66289 5.98708 6.79412L5.97061 12.7647Z",
    fill: "currentColor"
  }));
}));
FilterIcon.displayName = "FilterIcon";
function _extends$6() {
  return _extends$6 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$6.apply(null, arguments);
}
var FilterSlashIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$6({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    fillRule: "evenodd",
    clipRule: "evenodd",
    d: "M13.4994 0.0920138C13.5967 0.151519 13.6758 0.236453 13.7283 0.337647C13.7807 0.439133 13.8031 0.553448 13.7929 0.667208C13.7827 0.780968 13.7403 0.889466 13.6707 0.98L11.406 4.06823C11.3099 4.19928 11.1656 4.28679 11.005 4.3115C10.8444 4.33621 10.6805 4.2961 10.5495 4.2C10.4184 4.1039 10.3309 3.95967 10.3062 3.79905C10.2815 3.63843 10.3216 3.47458 10.4177 3.34353L11.9412 1.23529H7.41184C7.24803 1.23529 7.09093 1.17022 6.97509 1.05439C6.85926 0.938558 6.79419 0.781457 6.79419 0.617647C6.79419 0.453837 6.85926 0.296736 6.97509 0.180905C7.09093 0.0650733 7.24803 0 7.41184 0H13.1765C13.2905 0.000692754 13.4022 0.0325088 13.4994 0.0920138ZM4.20008 0.181168H4.24126L13.2013 9.03411C13.3169 9.14992 13.3819 9.3069 13.3819 9.47058C13.3819 9.63426 13.3169 9.79124 13.2013 9.90705C13.1445 9.96517 13.0766 10.0112 13.0016 10.0423C12.9266 10.0735 12.846 10.0891 12.7648 10.0882C12.6836 10.0886 12.6032 10.0728 12.5283 10.0417C12.4533 10.0106 12.3853 9.96479 12.3283 9.90705L9.3142 6.92587L9.26479 6.99999V13.3823C9.26265 13.5455 9.19689 13.7014 9.08152 13.8167C8.96615 13.9321 8.81029 13.9979 8.64714 14H5.35302C5.18987 13.9979 5.03401 13.9321 4.91864 13.8167C4.80327 13.7014 4.73751 13.5455 4.73537 13.3823V6.99999L0.329492 1.02117C0.259855 0.930634 0.21745 0.822137 0.207241 0.708376C0.197031 0.594616 0.21944 0.480301 0.271844 0.378815C0.324343 0.277621 0.403484 0.192687 0.500724 0.133182C0.597964 0.073677 0.709609 0.041861 0.823609 0.0411682H3.86243C3.92448 0.0461551 3.9855 0.060022 4.04361 0.0823446C4.10037 0.10735 4.15311 0.140655 4.20008 0.181168ZM8.02949 6.79411C8.02884 6.66289 8.07235 6.53526 8.15302 6.43176L8.42478 6.05293L3.55773 1.23529H2.0589L5.84714 6.43176C5.92781 6.53526 5.97132 6.66289 5.97067 6.79411V12.7647H8.02949V6.79411Z",
    fill: "currentColor"
  }));
}));
FilterSlashIcon.displayName = "FilterSlashIcon";
function _extends$5() {
  return _extends$5 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$5.apply(null, arguments);
}
var PlusIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$5({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    d: "M7.67742 6.32258V0.677419C7.67742 0.497757 7.60605 0.325452 7.47901 0.198411C7.35197 0.0713707 7.17966 0 7 0C6.82034 0 6.64803 0.0713707 6.52099 0.198411C6.39395 0.325452 6.32258 0.497757 6.32258 0.677419V6.32258H0.677419C0.497757 6.32258 0.325452 6.39395 0.198411 6.52099C0.0713707 6.64803 0 6.82034 0 7C0 7.17966 0.0713707 7.35197 0.198411 7.47901C0.325452 7.60605 0.497757 7.67742 0.677419 7.67742H6.32258V13.3226C6.32492 13.5015 6.39704 13.6725 6.52358 13.799C6.65012 13.9255 6.82106 13.9977 7 14C7.17966 14 7.35197 13.9286 7.47901 13.8016C7.60605 13.6745 7.67742 13.5022 7.67742 13.3226V7.67742H13.3226C13.5022 7.67742 13.6745 7.60605 13.8016 7.47901C13.9286 7.35197 14 7.17966 14 7C13.9977 6.82106 13.9255 6.65012 13.799 6.52358C13.6725 6.39704 13.5015 6.32492 13.3226 6.32258H7.67742Z",
    fill: "currentColor"
  }));
}));
PlusIcon.displayName = "PlusIcon";
function _extends$4() {
  return _extends$4 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$4.apply(null, arguments);
}
var TrashIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$4({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    fillRule: "evenodd",
    clipRule: "evenodd",
    d: "M3.44802 13.9955H10.552C10.8056 14.0129 11.06 13.9797 11.3006 13.898C11.5412 13.8163 11.7632 13.6877 11.9537 13.5196C12.1442 13.3515 12.2995 13.1473 12.4104 12.9188C12.5213 12.6903 12.5858 12.442 12.6 12.1884V4.36041H13.4C13.5591 4.36041 13.7117 4.29722 13.8243 4.18476C13.9368 4.07229 14 3.91976 14 3.76071C14 3.60166 13.9368 3.44912 13.8243 3.33666C13.7117 3.22419 13.5591 3.16101 13.4 3.16101H12.0537C12.0203 3.1557 11.9863 3.15299 11.952 3.15299C11.9178 3.15299 11.8838 3.1557 11.8503 3.16101H11.2285C11.2421 3.10893 11.2487 3.05513 11.248 3.00106V1.80966C11.2171 1.30262 10.9871 0.828306 10.608 0.48989C10.229 0.151475 9.73159 -0.0236625 9.22402 0.00257442H4.77602C4.27251 -0.0171866 3.78126 0.160868 3.40746 0.498617C3.03365 0.836366 2.807 1.30697 2.77602 1.80966V3.00106C2.77602 3.0556 2.78346 3.10936 2.79776 3.16101H0.6C0.521207 3.16101 0.443185 3.17652 0.37039 3.20666C0.297595 3.2368 0.231451 3.28097 0.175736 3.33666C0.120021 3.39235 0.0758251 3.45846 0.0456722 3.53121C0.0155194 3.60397 0 3.68196 0 3.76071C0 3.83946 0.0155194 3.91744 0.0456722 3.9902C0.0758251 4.06296 0.120021 4.12907 0.175736 4.18476C0.231451 4.24045 0.297595 4.28462 0.37039 4.31476C0.443185 4.3449 0.521207 4.36041 0.6 4.36041H1.40002V12.1884C1.41426 12.442 1.47871 12.6903 1.58965 12.9188C1.7006 13.1473 1.85582 13.3515 2.04633 13.5196C2.23683 13.6877 2.45882 13.8163 2.69944 13.898C2.94005 13.9797 3.1945 14.0129 3.44802 13.9955ZM2.60002 4.36041H11.304V12.1884C11.304 12.5163 10.952 12.7961 10.504 12.7961H3.40002C2.97602 12.7961 2.60002 12.5163 2.60002 12.1884V4.36041ZM3.95429 3.16101C3.96859 3.10936 3.97602 3.0556 3.97602 3.00106V1.80966C3.97602 1.48183 4.33602 1.20197 4.77602 1.20197H9.24802C9.66403 1.20197 10.048 1.48183 10.048 1.80966V3.00106C10.0473 3.05515 10.054 3.10896 10.0678 3.16101H3.95429ZM5.57571 10.997C5.41731 10.995 5.26597 10.9311 5.15395 10.8191C5.04193 10.7071 4.97808 10.5558 4.97601 10.3973V6.77517C4.97601 6.61612 5.0392 6.46359 5.15166 6.35112C5.26413 6.23866 5.41666 6.17548 5.57571 6.17548C5.73476 6.17548 5.8873 6.23866 5.99976 6.35112C6.11223 6.46359 6.17541 6.61612 6.17541 6.77517V10.3894C6.17647 10.4688 6.16174 10.5476 6.13208 10.6213C6.10241 10.695 6.05841 10.762 6.00261 10.8186C5.94682 10.8751 5.88035 10.92 5.80707 10.9506C5.73378 10.9813 5.65514 10.9971 5.57571 10.997ZM7.99968 10.8214C8.11215 10.9339 8.26468 10.997 8.42373 10.997C8.58351 10.9949 8.73604 10.93 8.84828 10.8163C8.96052 10.7025 9.02345 10.5491 9.02343 10.3894V6.77517C9.02343 6.61612 8.96025 6.46359 8.84778 6.35112C8.73532 6.23866 8.58278 6.17548 8.42373 6.17548C8.26468 6.17548 8.11215 6.23866 7.99968 6.35112C7.88722 6.46359 7.82404 6.61612 7.82404 6.77517V10.3973C7.82404 10.5564 7.88722 10.7089 7.99968 10.8214Z",
    fill: "currentColor"
  }));
}));
TrashIcon.displayName = "TrashIcon";
function _extends$3() {
  return _extends$3 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$3.apply(null, arguments);
}
var SortAltIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$3({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    d: "M5.64515 3.61291C5.47353 3.61291 5.30192 3.54968 5.16644 3.4142L3.38708 1.63484L1.60773 3.4142C1.34579 3.67613 0.912244 3.67613 0.650309 3.4142C0.388374 3.15226 0.388374 2.71871 0.650309 2.45678L2.90837 0.198712C3.17031 -0.0632236 3.60386 -0.0632236 3.86579 0.198712L6.12386 2.45678C6.38579 2.71871 6.38579 3.15226 6.12386 3.4142C5.98837 3.54968 5.81676 3.61291 5.64515 3.61291Z",
    fill: "currentColor"
  }), /* @__PURE__ */ React.createElement("path", {
    d: "M3.38714 14C3.01681 14 2.70972 13.6929 2.70972 13.3226V0.677419C2.70972 0.307097 3.01681 0 3.38714 0C3.75746 0 4.06456 0.307097 4.06456 0.677419V13.3226C4.06456 13.6929 3.75746 14 3.38714 14Z",
    fill: "currentColor"
  }), /* @__PURE__ */ React.createElement("path", {
    d: "M10.6129 14C10.4413 14 10.2697 13.9368 10.1342 13.8013L7.87611 11.5432C7.61418 11.2813 7.61418 10.8477 7.87611 10.5858C8.13805 10.3239 8.5716 10.3239 8.83353 10.5858L10.6129 12.3652L12.3922 10.5858C12.6542 10.3239 13.0877 10.3239 13.3497 10.5858C13.6116 10.8477 13.6116 11.2813 13.3497 11.5432L11.0916 13.8013C10.9561 13.9368 10.7845 14 10.6129 14Z",
    fill: "currentColor"
  }), /* @__PURE__ */ React.createElement("path", {
    d: "M10.6129 14C10.2426 14 9.93552 13.6929 9.93552 13.3226V0.677419C9.93552 0.307097 10.2426 0 10.6129 0C10.9833 0 11.2904 0.307097 11.2904 0.677419V13.3226C11.2904 13.6929 10.9832 14 10.6129 14Z",
    fill: "currentColor"
  }));
}));
SortAltIcon.displayName = "SortAltIcon";
function _extends$2() {
  return _extends$2 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$2.apply(null, arguments);
}
var SortAmountDownIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$2({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    d: "M4.93953 10.5858L3.83759 11.6877V0.677419C3.83759 0.307097 3.53049 0 3.16017 0C2.78985 0 2.48275 0.307097 2.48275 0.677419V11.6877L1.38082 10.5858C1.11888 10.3239 0.685331 10.3239 0.423396 10.5858C0.16146 10.8477 0.16146 11.2813 0.423396 11.5432L2.68146 13.8013C2.74469 13.8645 2.81694 13.9097 2.89823 13.9458C2.97952 13.9819 3.06985 14 3.16017 14C3.25049 14 3.33178 13.9819 3.42211 13.9458C3.5034 13.9097 3.57565 13.8645 3.63888 13.8013L5.89694 11.5432C6.15888 11.2813 6.15888 10.8477 5.89694 10.5858C5.63501 10.3239 5.20146 10.3239 4.93953 10.5858ZM13.0957 0H7.22468C6.85436 0 6.54726 0.307097 6.54726 0.677419C6.54726 1.04774 6.85436 1.35484 7.22468 1.35484H13.0957C13.466 1.35484 13.7731 1.04774 13.7731 0.677419C13.7731 0.307097 13.466 0 13.0957 0ZM7.22468 5.41935H9.48275C9.85307 5.41935 10.1602 5.72645 10.1602 6.09677C10.1602 6.4671 9.85307 6.77419 9.48275 6.77419H7.22468C6.85436 6.77419 6.54726 6.4671 6.54726 6.09677C6.54726 5.72645 6.85436 5.41935 7.22468 5.41935ZM7.6763 8.12903H7.22468C6.85436 8.12903 6.54726 8.43613 6.54726 8.80645C6.54726 9.17677 6.85436 9.48387 7.22468 9.48387H7.6763C8.04662 9.48387 8.35372 9.17677 8.35372 8.80645C8.35372 8.43613 8.04662 8.12903 7.6763 8.12903ZM7.22468 2.70968H11.2892C11.6595 2.70968 11.9666 3.01677 11.9666 3.3871C11.9666 3.75742 11.6595 4.06452 11.2892 4.06452H7.22468C6.85436 4.06452 6.54726 3.75742 6.54726 3.3871C6.54726 3.01677 6.85436 2.70968 7.22468 2.70968Z",
    fill: "currentColor"
  }));
}));
SortAmountDownIcon.displayName = "SortAmountDownIcon";
function _extends$1() {
  return _extends$1 = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends$1.apply(null, arguments);
}
var SortAmountUpAltIcon = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var pti = IconBase.getPTI(inProps);
  return /* @__PURE__ */ React.createElement("svg", _extends$1({
    ref,
    width: "14",
    height: "14",
    viewBox: "0 0 14 14",
    fill: "none",
    xmlns: "http://www.w3.org/2000/svg"
  }, pti), /* @__PURE__ */ React.createElement("path", {
    d: "M3.63435 0.19871C3.57113 0.135484 3.49887 0.0903226 3.41758 0.0541935C3.255 -0.0180645 3.06532 -0.0180645 2.90274 0.0541935C2.82145 0.0903226 2.74919 0.135484 2.68597 0.19871L0.427901 2.45677C0.165965 2.71871 0.165965 3.15226 0.427901 3.41419C0.689836 3.67613 1.12338 3.67613 1.38532 3.41419L2.48726 2.31226V13.3226C2.48726 13.6929 2.79435 14 3.16467 14C3.535 14 3.84209 13.6929 3.84209 13.3226V2.31226L4.94403 3.41419C5.07951 3.54968 5.25113 3.6129 5.42274 3.6129C5.59435 3.6129 5.76597 3.54968 5.90145 3.41419C6.16338 3.15226 6.16338 2.71871 5.90145 2.45677L3.64338 0.19871H3.63435ZM13.7685 13.3226C13.7685 12.9523 13.4615 12.6452 13.0911 12.6452H7.22016C6.84984 12.6452 6.54274 12.9523 6.54274 13.3226C6.54274 13.6929 6.84984 14 7.22016 14H13.0911C13.4615 14 13.7685 13.6929 13.7685 13.3226ZM7.22016 8.58064C6.84984 8.58064 6.54274 8.27355 6.54274 7.90323C6.54274 7.5329 6.84984 7.22581 7.22016 7.22581H9.47823C9.84855 7.22581 10.1556 7.5329 10.1556 7.90323C10.1556 8.27355 9.84855 8.58064 9.47823 8.58064H7.22016ZM7.22016 5.87097H7.67177C8.0421 5.87097 8.34919 5.56387 8.34919 5.19355C8.34919 4.82323 8.0421 4.51613 7.67177 4.51613H7.22016C6.84984 4.51613 6.54274 4.82323 6.54274 5.19355C6.54274 5.56387 6.84984 5.87097 7.22016 5.87097ZM11.2847 11.2903H7.22016C6.84984 11.2903 6.54274 10.9832 6.54274 10.6129C6.54274 10.2426 6.84984 9.93548 7.22016 9.93548H11.2847C11.655 9.93548 11.9621 10.2426 11.9621 10.6129C11.9621 10.9832 11.655 11.2903 11.2847 11.2903Z",
    fill: "currentColor"
  }));
}));
SortAmountUpAltIcon.displayName = "SortAmountUpAltIcon";
function _extends() {
  return _extends = Object.assign ? Object.assign.bind() : function(n) {
    for (var e = 1; e < arguments.length; e++) {
      var t = arguments[e];
      for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]);
    }
    return n;
  }, _extends.apply(null, arguments);
}
function _typeof(o) {
  "@babel/helpers - typeof";
  return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function(o2) {
    return typeof o2;
  } : function(o2) {
    return o2 && "function" == typeof Symbol && o2.constructor === Symbol && o2 !== Symbol.prototype ? "symbol" : typeof o2;
  }, _typeof(o);
}
function toPrimitive(t, r) {
  if ("object" != _typeof(t) || !t) return t;
  var e = t[Symbol.toPrimitive];
  if (void 0 !== e) {
    var i = e.call(t, r);
    if ("object" != _typeof(i)) return i;
    throw new TypeError("@@toPrimitive must return a primitive value.");
  }
  return ("string" === r ? String : Number)(t);
}
function toPropertyKey(t) {
  var i = toPrimitive(t, "string");
  return "symbol" == _typeof(i) ? i : i + "";
}
function _defineProperty(e, r, t) {
  return (r = toPropertyKey(r)) in e ? Object.defineProperty(e, r, {
    value: t,
    enumerable: true,
    configurable: true,
    writable: true
  }) : e[r] = t, e;
}
function _arrayLikeToArray$1(r, a) {
  (null == a || a > r.length) && (a = r.length);
  for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e];
  return n;
}
function _arrayWithoutHoles(r) {
  if (Array.isArray(r)) return _arrayLikeToArray$1(r);
}
function _iterableToArray(r) {
  if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r);
}
function _unsupportedIterableToArray$1(r, a) {
  if (r) {
    if ("string" == typeof r) return _arrayLikeToArray$1(r, a);
    var t = {}.toString.call(r).slice(8, -1);
    return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray$1(r, a) : void 0;
  }
}
function _nonIterableSpread() {
  throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.");
}
function _toConsumableArray(r) {
  return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray$1(r) || _nonIterableSpread();
}
function _arrayWithHoles(r) {
  if (Array.isArray(r)) return r;
}
function _iterableToArrayLimit(r, l) {
  var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"];
  if (null != t) {
    var e, n, i, u, a = [], f = true, o = false;
    try {
      if (i = (t = t.call(r)).next, 0 === l) ;
      else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = true) ;
    } catch (r2) {
      o = true, n = r2;
    } finally {
      try {
        if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return;
      } finally {
        if (o) throw n;
      }
    }
    return a;
  }
}
function _nonIterableRest() {
  throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.");
}
function _slicedToArray(r, e) {
  return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray$1(r, e) || _nonIterableRest();
}
var getStorage = function getStorage2(stateStorageProp) {
  switch (stateStorageProp) {
    case "local":
      return window.localStorage;
    case "session":
      return window.sessionStorage;
    case "custom":
      return null;
    default:
      throw new Error(stateStorageProp + ' is not a valid value for the state storage, supported values are "local", "session" and "custom".');
  }
};
var ColumnBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "Column",
    align: null,
    alignFrozen: "left",
    alignHeader: null,
    body: null,
    bodyClassName: null,
    bodyStyle: null,
    cellEditValidateOnClose: false,
    cellEditValidator: null,
    cellEditValidatorEvent: "click",
    className: null,
    colSpan: null,
    columnKey: null,
    dataType: "text",
    editor: null,
    excludeGlobalFilter: false,
    expander: false,
    exportField: null,
    exportable: true,
    field: null,
    filter: false,
    filterApply: null,
    filterClear: null,
    filterElement: null,
    filterField: null,
    filterFooter: null,
    filterFunction: null,
    filterHeader: null,
    filterHeaderClassName: null,
    filterHeaderStyle: null,
    filterMatchMode: null,
    filterMatchModeOptions: null,
    filterMaxLength: null,
    filterMenuClassName: null,
    filterMenuStyle: null,
    filterPlaceholder: null,
    filterType: "text",
    footer: null,
    footerClassName: null,
    footerStyle: null,
    frozen: false,
    header: null,
    headerClassName: null,
    headerStyle: null,
    headerTooltip: null,
    headerTooltipOptions: null,
    hidden: false,
    maxConstraints: 2,
    onBeforeCellEditHide: null,
    onBeforeCellEditShow: null,
    onCellEditCancel: null,
    onCellEditComplete: null,
    onCellEditInit: null,
    onFilterApplyClick: null,
    onFilterClear: null,
    onFilterConstraintAdd: null,
    onFilterConstraintRemove: null,
    onFilterMatchModeChange: null,
    onFilterOperatorChange: null,
    reorderable: true,
    resizeable: true,
    rowEditor: false,
    rowReorder: false,
    rowReorderIcon: null,
    rowSpan: null,
    selectionMode: null,
    showAddButton: true,
    showApplyButton: true,
    showClearButton: true,
    showFilterMatchModes: true,
    showFilterMenu: true,
    showFilterMenuOptions: true,
    showFilterOperator: true,
    sortField: null,
    sortFunction: null,
    sortable: false,
    sortableDisabled: false,
    style: null,
    children: void 0
  },
  getCProp: function getCProp(column, name) {
    return ObjectUtils.getComponentProp(column, name, ColumnBase.defaultProps);
  },
  getCProps: function getCProps(column) {
    return ObjectUtils.getComponentProps(column, ColumnBase.defaultProps);
  },
  getCOtherProps: function getCOtherProps(column) {
    return ObjectUtils.getComponentDiffProps(column, ColumnBase.defaultProps);
  }
});
function ownKeys$f(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$f(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$f(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$f(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var styles$1 = "\n@layer primereact {\n    .p-datatable {\n        position: relative;\n    }\n\n    .p-datatable > .p-datatable-wrapper {\n        overflow: auto;\n    }\n\n    .p-datatable-table {\n        border-spacing: 0px;\n        width: 100%;\n    }\n\n    .p-datatable .p-sortable-disabled {\n        cursor: auto;\n    }\n\n    .p-datatable .p-sortable-column {\n        cursor: pointer;\n        user-select: none;\n    }\n\n    .p-datatable .p-sortable-column .p-column-title,\n    .p-datatable .p-sortable-column .p-sortable-column-icon,\n    .p-datatable .p-sortable-column .p-sortable-column-badge {\n        vertical-align: middle;\n    }\n\n    .p-datatable .p-sortable-column .p-sortable-column-badge {\n        display: inline-flex;\n        align-items: center;\n        justify-content: center;\n    }\n\n    .p-datatable-selectable .p-selectable-row,\n    .p-datatable-selectable-cell .p-selectable-cell {\n        cursor: pointer;\n    }\n\n    .p-datatable-drag-selection-helper {\n        position: absolute;\n        z-index: 99999999;\n    }\n\n    /* Scrollable */\n    .p-datatable-scrollable > .p-datatable-wrapper {\n        position: relative;\n    }\n\n    .p-datatable-scrollable-table > .p-datatable-thead {\n        position: sticky;\n        top: 0;\n        z-index: 2;\n    }\n\n    .p-datatable.p-datatable-gridlines .p-datatable-scrollable-table > .p-datatable-thead {\n        top: -1px;\n    }\n\n    .p-datatable-scrollable-table > .p-datatable-frozen-tbody {\n        position: sticky;\n        z-index: 1;\n    }\n\n    .p-datatable-scrollable-table > .p-datatable-tfoot {\n        position: sticky;\n        bottom: 0;\n        z-index: 1;\n    }\n\n    .p-datatable-scrollable .p-frozen-column {\n        position: sticky;\n        background: inherit;\n    }\n\n    .p-datatable-scrollable th.p-frozen-column {\n        z-index: 1;\n    }\n\n    .p-datatable-flex-scrollable {\n        display: flex;\n        flex-direction: column;\n        height: 100%;\n    }\n\n    .p-datatable-flex-scrollable > .p-datatable-wrapper {\n        display: flex;\n        flex-direction: column;\n        flex: 1;\n        height: 100%;\n    }\n\n    .p-datatable-scrollable-table > .p-datatable-tbody > .p-rowgroup-header {\n        position: sticky;\n        z-index: 1;\n    }\n\n    /* Resizable */\n    .p-datatable-resizable-table > .p-datatable-thead > tr > th,\n    .p-datatable-resizable-table > .p-datatable-tfoot > tr > td,\n    .p-datatable-resizable-table > .p-datatable-tbody > tr > td {\n        overflow: hidden;\n        white-space: nowrap;\n    }\n\n    .p-datatable-resizable-table > .p-datatable-thead > tr > th.p-resizable-column:not(.p-frozen-column) {\n        background-clip: padding-box;\n        position: relative;\n    }\n\n    .p-datatable-resizable-table-fit > .p-datatable-thead > tr > th.p-resizable-column:last-child .p-column-resizer {\n        display: none;\n    }\n\n    .p-datatable .p-column-resizer {\n        display: block;\n        position: absolute;\n        top: 0;\n        right: 0;\n        margin: 0;\n        width: 0.5rem;\n        height: 100%;\n        padding: 0px;\n        cursor: col-resize;\n        border: 1px solid transparent;\n    }\n\n    .p-datatable .p-column-header-content {\n        display: flex;\n        align-items: center;\n    }\n\n    .p-datatable .p-column-resizer-helper {\n        width: 1px;\n        position: absolute;\n        z-index: 10;\n        display: none;\n    }\n\n    .p-datatable .p-row-editor-init,\n    .p-datatable .p-row-editor-save,\n    .p-datatable .p-row-editor-cancel {\n        display: inline-flex;\n        align-items: center;\n        justify-content: center;\n        overflow: hidden;\n        position: relative;\n    }\n\n    /* Expand */\n    .p-datatable .p-row-toggler {\n        display: inline-flex;\n        align-items: center;\n        justify-content: center;\n        overflow: hidden;\n        position: relative;\n    }\n\n    /* Reorder */\n    .p-datatable-reorder-indicator-up,\n    .p-datatable-reorder-indicator-down {\n        position: absolute;\n        display: none;\n    }\n\n    .p-reorderable-column,\n    .p-datatable-reorderablerow-handle {\n        cursor: move;\n    }\n\n    /* Loader */\n    .p-datatable .p-datatable-loading-overlay {\n        position: absolute;\n        display: flex;\n        align-items: center;\n        justify-content: center;\n        z-index: 2;\n    }\n\n    /* Filter */\n    .p-column-filter-row {\n        display: flex;\n        align-items: center;\n        width: 100%;\n    }\n\n    .p-column-filter-menu {\n        display: inline-flex;\n        margin-left: auto;\n    }\n\n    .p-column-filter-row .p-column-filter-element {\n        flex: 1 1 auto;\n        width: 1%;\n    }\n\n    .p-column-filter-menu-button,\n    .p-column-filter-clear-button {\n        display: inline-flex;\n        justify-content: center;\n        align-items: center;\n        cursor: pointer;\n        text-decoration: none;\n        overflow: hidden;\n        position: relative;\n    }\n\n    .p-column-filter-overlay {\n        position: absolute;\n        top: 0;\n        left: 0;\n    }\n\n    .p-column-filter-row-items {\n        margin: 0;\n        padding: 0;\n        list-style: none;\n    }\n\n    .p-column-filter-row-item {\n        cursor: pointer;\n    }\n\n    .p-column-filter-add-button,\n    .p-column-filter-remove-button {\n        justify-content: center;\n    }\n\n    .p-column-filter-add-button .p-button-label,\n    .p-column-filter-remove-button .p-button-label {\n        flex-grow: 0;\n    }\n\n    .p-column-filter-buttonbar {\n        display: flex;\n        align-items: center;\n        justify-content: space-between;\n    }\n\n    .p-column-filter-buttonbar .p-button:not(.p-button-icon-only) {\n        width: auto;\n    }\n\n    /* Responsive */\n    .p-datatable .p-datatable-tbody > tr > td > .p-column-title {\n        display: none;\n    }\n\n    /* VirtualScroller */\n    .p-datatable-virtualscroller-spacer {\n        display: flex;\n    }\n\n    .p-datatable .p-virtualscroller .p-virtualscroller-loading {\n        transform: none;\n        min-height: 0;\n        position: sticky;\n        top: 0;\n        left: 0;\n    }\n\n    /* Alignment */\n    .p-datatable .p-datatable-thead > tr > th.p-align-left > .p-column-header-content,\n    .p-datatable .p-datatable-tbody > tr > td.p-align-left,\n    .p-datatable .p-datatable-tfoot > tr > td.p-align-left {\n        text-align: left;\n        justify-content: flex-start;\n    }\n\n    .p-datatable .p-datatable-thead > tr > th.p-align-right > .p-column-header-content,\n    .p-datatable .p-datatable-tbody > tr > td.p-align-right,\n    .p-datatable .p-datatable-tfoot > tr > td.p-align-right {\n        text-align: right;\n        justify-content: flex-end;\n    }\n\n    .p-datatable .p-datatable-thead > tr > th.p-align-center > .p-column-header-content,\n    .p-datatable .p-datatable-tbody > tr > td.p-align-center,\n    .p-datatable .p-datatable-tfoot > tr > td.p-align-center {\n        text-align: center;\n        justify-content: center;\n    }\n}\n";
var classes$2 = {
  root: function root(_ref) {
    var props = _ref.props, selectable = _ref.selectable;
    return classNames("p-datatable p-component", {
      "p-datatable-hoverable-rows": props.rowHover,
      "p-datatable-selectable": selectable && !props.cellSelection,
      "p-datatable-selectable-cell": selectable && props.cellSelection,
      "p-datatable-resizable": props.resizableColumns,
      "p-datatable-resizable-fit": props.resizableColumns && props.columnResizeMode === "fit",
      "p-datatable-scrollable": props.scrollable,
      "p-datatable-flex-scrollable": props.scrollable && props.scrollHeight === "flex",
      "p-datatable-responsive-stack": props.responsiveLayout === "stack",
      "p-datatable-responsive-scroll": props.responsiveLayout === "scroll",
      "p-datatable-striped": props.stripedRows,
      "p-datatable-gridlines": props.showGridlines,
      "p-datatable-grouped-header": props.headerColumnGroup != null,
      "p-datatable-grouped-footer": props.footerColumnGroup != null,
      "p-datatable-sm": props.size === "small",
      "p-datatable-lg": props.size === "large"
    });
  },
  loadingIcon: "p-datatable-loading-icon",
  loadingOverlay: "p-datatable-loading-overlay p-component-overlay",
  header: "p-datatable-header",
  wrapper: "p-datatable-wrapper",
  table: function table(_ref2) {
    var props = _ref2.props;
    return classNames("p-datatable-table", {
      "p-datatable-scrollable-table": props.scrollable,
      "p-datatable-resizable-table": props.resizableColumns,
      "p-datatable-resizable-table-fit": props.resizableColumns && props.columnResizeMode === "fit"
    });
  },
  thead: "p-datatable-thead",
  tfoot: "p-datatable-tfoot",
  footer: "p-datatable-footer",
  checkIcon: "p-checkbox-icon",
  resizeHelper: "p-column-resizer-helper",
  reorderIndicatorUp: "p-datatable-reorder-indicator-up",
  reorderIndicatorDown: "p-datatable-reorder-indicator-down",
  paginator: function paginator(_ref3) {
    var position = _ref3.position;
    return classNames("p-paginator-" + position);
  },
  bodyCell: function bodyCell(_ref4) {
    var selectionMode = _ref4.selectionMode, editor = _ref4.editor, editingState = _ref4.editingState, frozen = _ref4.frozen, cellSelected = _ref4.cellSelected, align = _ref4.align, props = _ref4.bodyProps, getCellParams = _ref4.getCellParams;
    return classNames(_defineProperty({
      "p-selection-column": selectionMode !== null,
      "p-editable-column": editor,
      "p-cell-editing": editor && editingState,
      "p-frozen-column": frozen,
      "p-selectable-cell": props.allowCellSelection && props.isSelectable({
        data: getCellParams(),
        index: props.rowIndex
      }),
      "p-highlight": cellSelected
    }, "p-align-".concat(align), !!align));
  },
  columnTitle: "p-column-title",
  bodyRow: function bodyRow(_ref5) {
    var props = _ref5.rowProps;
    return classNames({
      "p-highlight": !props.allowCellSelection && props.selected || props.contextMenuSelected,
      "p-highlight-contextmenu": props.contextMenuSelected,
      "p-selectable-row": props.allowRowSelection && props.isSelectable({
        data: props.rowData,
        index: props.rowIndex
      }),
      "p-row-odd": props.rowIndex % 2 !== 0
    });
  },
  rowGroupTogglerIcon: "p-row-toggler-icon",
  rowGroupToggler: "p-row-toggler p-link",
  rowGroupHeader: "p-rowgroup-header",
  rowGroupHeaderName: "p-rowgroup-header-name",
  rowGroupFooter: "p-rowgroup-footer",
  rowReorderIcon: "p-datatable-reorderablerow-handle",
  rowTogglerIcon: "p-row-toggler-icon",
  rowToggler: "p-row-toggler p-link",
  rowEditorSaveIcon: "p-row-editor-save-icon",
  rowEditorSaveButton: "p-row-editor-save p-link",
  rowEditorCancelIcon: "p-row-editor-cancel-icon",
  rowEditorCancelButton: "p-row-editor-cancel p-link",
  rowEditorInitIcon: "p-row-editor-init-icon",
  rowEditorInitButton: "p-row-editor-init p-link",
  rowExpansion: "p-datatable-row-expansion",
  virtualScrollerSpacer: function virtualScrollerSpacer(_ref6) {
    var className = _ref6.className;
    return className;
  },
  tbody: function tbody(_ref7) {
    var className = _ref7.className;
    return className;
  },
  filterInput: "p-fluid p-column-filter-element",
  filterMenuButton: function filterMenuButton(_ref8) {
    var overlayVisibleState = _ref8.overlayVisibleState, hasFilter = _ref8.hasFilter;
    return classNames("p-column-filter-menu-button p-link", {
      "p-column-filter-menu-button-open": overlayVisibleState,
      "p-column-filter-menu-button-active": hasFilter()
    });
  },
  headerFilterClearButton: function headerFilterClearButton(_ref9) {
    var hasRowFilter = _ref9.hasRowFilter;
    return classNames("p-column-filter-clear-button p-link", {
      "p-hidden-space": !hasRowFilter()
    });
  },
  filterSeparator: "p-column-filter-separator",
  filterRowItem: function filterRowItem(_ref0) {
    var isRowMatchModeSelected = _ref0.isRowMatchModeSelected, isShowMatchModes = _ref0.isShowMatchModes, value = _ref0.value;
    return isShowMatchModes() ? classNames("p-column-filter-row-item", {
      "p-highlight": value && isRowMatchModeSelected(value)
    }) : void 0;
  },
  filterRowItems: "p-column-filter-row-items",
  filterOperator: "p-column-filter-operator",
  filterConstraints: "p-column-filter-constraints",
  filterConstraint: "p-column-filter-constraint",
  filterAddRule: "p-column-filter-add-rule",
  filterButtonBar: "p-column-filter-buttonbar",
  filterOverlay: function filterOverlay(_ref1) {
    var props = _ref1.columnFilterProps, context = _ref1.context, getColumnProp = _ref1.getColumnProp;
    return classNames("p-column-filter-overlay p-component p-fluid", getColumnProp("filterMenuClassName"), {
      "p-column-filter-overlay-menu": props.display === "menu",
      "p-input-filled": context && context.inputStyle === "filled" || PrimeReact$2.inputStyle === "filled",
      "p-ripple-disabled": context && context.ripple === false || PrimeReact$2.ripple === false
    });
  },
  columnFilter: function columnFilter(_ref10) {
    var props = _ref10.columnFilterProps;
    return classNames("p-column-filter p-fluid", {
      "p-column-filter-row": props.display === "row",
      "p-column-filter-menu": props.display === "menu"
    });
  },
  columnResizer: "p-column-resizer",
  emptyMessage: "p-datatable-emptymessage",
  sortBadge: "p-sortable-column-badge",
  sortIcon: "p-sortable-column-icon",
  headerTitle: "p-column-title",
  headerContent: "p-column-header-content",
  headerCell: function headerCell(_ref11) {
    var props = _ref11.headerProps, frozen = _ref11.frozen, sortMeta = _ref11.sortMeta, align = _ref11.align, _isSortableDisabled = _ref11._isSortableDisabled, getColumnProp = _ref11.getColumnProp;
    return ObjectUtils.isEmpty(props) ? classNames("p-filter-column", {
      "p-frozen-column": frozen
    }) : classNames(_defineProperty({
      "p-filter-column": !props.headerColumnGroup && props.filterDisplay === "row",
      "p-sortable-column": getColumnProp("sortable"),
      "p-resizable-column": props.resizableColumns && getColumnProp("resizeable"),
      "p-highlight": sortMeta.sorted,
      "p-frozen-column": frozen,
      "p-selection-column": getColumnProp("selectionMode"),
      "p-sortable-disabled": getColumnProp("sortable") && _isSortableDisabled,
      "p-reorderable-column": props.reorderableColumns && getColumnProp("reorderable") && !frozen
    }, "p-align-".concat(align), !!align));
  },
  footerCell: function footerCell(_ref12) {
    var getColumnProp = _ref12.getColumnProp, align = _ref12.align;
    return classNames(_defineProperty({
      "p-frozen-column": getColumnProp("frozen")
    }, "p-align-".concat(align), !!align));
  },
  transition: "p-connected-overlay"
};
var inlineStyles = {
  wrapper: {
    overflow: "auto"
  },
  resizeHelper: {
    display: "none"
  },
  reorderIndicatorUp: function reorderIndicatorUp(_ref13) {
    var style = _ref13.style;
    return _objectSpread$f({}, style);
  },
  reorderIndicatorDown: function reorderIndicatorDown(_ref14) {
    var style = _ref14.style;
    return _objectSpread$f({}, style);
  }
};
var DataTableBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "DataTable",
    alwaysShowPaginator: true,
    breakpoint: "960px",
    cellClassName: null,
    cellMemo: true,
    cellMemoProps: ["rowData", "field", "allowCellSelection", "isCellSelected", "editMode", "rowIndex", "tabIndex", "editing", "expanded", "editingMeta", "frozenCol", "alignFrozenCol"],
    cellMemoPropsDepth: 1,
    cellSelection: false,
    checkIcon: null,
    className: null,
    collapsedRowIcon: null,
    columnResizeMode: "fit",
    compareSelectionBy: "deepEquals",
    contextMenuSelection: null,
    csvSeparator: ",",
    currentPageReportTemplate: "({currentPage} of {totalPages})",
    customRestoreState: null,
    customSaveState: null,
    dataKey: null,
    defaultSortOrder: 1,
    dragSelection: false,
    editMode: null,
    editingRows: null,
    emptyMessage: null,
    expandableRowGroups: false,
    expandedRowIcon: null,
    expandedRows: null,
    exportFilename: "download",
    exportFunction: null,
    filterClearIcon: null,
    filterDelay: 300,
    filterDisplay: "menu",
    filterIcon: null,
    filterLocale: void 0,
    filters: null,
    first: 0,
    footer: null,
    footerColumnGroup: null,
    frozenRow: false,
    frozenValue: null,
    frozenWidth: null,
    globalFilter: null,
    globalFilterFields: null,
    globalFilterMatchMode: FilterMatchMode$2.CONTAINS,
    groupRowsBy: null,
    header: null,
    headerColumnGroup: null,
    id: null,
    isDataSelectable: null,
    lazy: false,
    loading: false,
    loadingIcon: null,
    metaKeySelection: false,
    multiSortMeta: null,
    onAllRowsSelect: null,
    onAllRowsUnselect: null,
    onCellClick: null,
    onCellSelect: null,
    onCellUnselect: null,
    onColReorder: null,
    onColumnResizeEnd: null,
    onColumnResizerClick: null,
    onColumnResizerDoubleClick: null,
    onContextMenu: null,
    onContextMenuSelectionChange: null,
    onFilter: null,
    onPage: null,
    onRowClick: null,
    onRowCollapse: null,
    onRowDoubleClick: null,
    onRowEditCancel: null,
    onRowEditChange: null,
    onRowEditComplete: null,
    onRowEditInit: null,
    onRowEditSave: null,
    onRowExpand: null,
    onRowMouseEnter: null,
    onRowMouseLeave: null,
    onRowPointerDown: null,
    onRowPointerUp: null,
    onRowReorder: null,
    onRowSelect: null,
    onRowToggle: null,
    onRowUnselect: null,
    onSelectAllChange: null,
    onSelectionChange: null,
    onSort: null,
    onStateRestore: null,
    onStateSave: null,
    onValueChange: null,
    pageLinkSize: 5,
    paginator: false,
    paginatorClassName: null,
    paginatorDropdownAppendTo: null,
    paginatorLeft: null,
    paginatorPosition: "bottom",
    paginatorRight: null,
    paginatorTemplate: "FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown",
    removableSort: false,
    reorderIndicatorDownIcon: null,
    reorderIndicatorUpIcon: null,
    reorderableColumns: false,
    reorderableRows: false,
    resizableColumns: false,
    responsiveLayout: "scroll",
    rowClassName: null,
    rowEditValidator: null,
    rowEditorCancelIcon: null,
    rowEditorInitIcon: null,
    rowEditorSaveIcon: null,
    rowExpansionTemplate: null,
    rowGroupFooterTemplate: null,
    rowGroupHeaderTemplate: null,
    rowGroupMode: null,
    rowHover: false,
    rows: null,
    rowsPerPageOptions: null,
    scrollHeight: null,
    scrollable: false,
    selectAll: false,
    selectOnEdit: true,
    selection: null,
    selectionAriaLabel: null,
    selectionAutoFocus: true,
    selectionMode: null,
    selectionPageOnly: false,
    showGridlines: false,
    showHeaders: true,
    showRowReorderElement: null,
    showSelectAll: true,
    showSelectionElement: null,
    size: "normal",
    sortField: null,
    sortIcon: null,
    sortMode: "single",
    sortOrder: null,
    stateKey: null,
    stateStorage: "session",
    stripedRows: false,
    style: null,
    tabIndex: 0,
    tableClassName: null,
    tableStyle: null,
    totalRecords: null,
    value: null,
    virtualScrollerOptions: null,
    children: void 0
  },
  css: {
    styles: styles$1,
    classes: classes$2,
    inlineStyles
  }
});
function _objectWithoutPropertiesLoose(r, e) {
  if (null == r) return {};
  var t = {};
  for (var n in r) if ({}.hasOwnProperty.call(r, n)) {
    if (-1 !== e.indexOf(n)) continue;
    t[n] = r[n];
  }
  return t;
}
function _objectWithoutProperties(e, t) {
  if (null == e) return {};
  var o, r, i = _objectWithoutPropertiesLoose(e, t);
  if (Object.getOwnPropertySymbols) {
    var n = Object.getOwnPropertySymbols(e);
    for (r = 0; r < n.length; r++) o = n[r], -1 === t.indexOf(o) && {}.propertyIsEnumerable.call(e, o) && (i[o] = e[o]);
  }
  return i;
}
var classes$1 = {
  box: "p-checkbox-box",
  input: "p-checkbox-input",
  icon: "p-checkbox-icon",
  root: function root2(_ref) {
    var props = _ref.props, checked = _ref.checked, context = _ref.context;
    return classNames("p-checkbox p-component", {
      "p-highlight": checked,
      "p-disabled": props.disabled,
      "p-invalid": props.invalid,
      "p-variant-filled": props.variant ? props.variant === "filled" : context && context.inputStyle === "filled"
    });
  }
};
var CheckboxBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "Checkbox",
    autoFocus: false,
    checked: false,
    className: null,
    disabled: false,
    falseValue: false,
    icon: null,
    id: null,
    inputId: null,
    inputRef: null,
    invalid: false,
    variant: null,
    name: null,
    onChange: null,
    onContextMenu: null,
    onMouseDown: null,
    readOnly: false,
    required: false,
    style: null,
    tabIndex: null,
    tooltip: null,
    tooltipOptions: null,
    trueValue: true,
    value: null,
    children: void 0
  },
  css: {
    classes: classes$1
  }
});
function ownKeys$e(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$e(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$e(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$e(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var Checkbox = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = CheckboxBase.getProps(inProps, context);
  var _React$useState = React.useState(false), _React$useState2 = _slicedToArray(_React$useState, 2), focusedState = _React$useState2[0], setFocusedState = _React$useState2[1];
  var _CheckboxBase$setMeta = CheckboxBase.setMetaData({
    props,
    state: {
      focused: focusedState
    },
    context: {
      checked: props.checked === props.trueValue,
      disabled: props.disabled
    }
  }), ptm = _CheckboxBase$setMeta.ptm, cx = _CheckboxBase$setMeta.cx, isUnstyled = _CheckboxBase$setMeta.isUnstyled;
  useHandleStyle(CheckboxBase.css.styles, isUnstyled, {
    name: "checkbox"
  });
  var elementRef = React.useRef(null);
  var inputRef = React.useRef(props.inputRef);
  var isChecked = function isChecked2() {
    return props.checked === props.trueValue;
  };
  var _onChange = function onChange(event) {
    if (props.disabled || props.readOnly) {
      return;
    }
    if (props.onChange) {
      var _props$onChange;
      var _checked = isChecked();
      var value = _checked ? props.falseValue : props.trueValue;
      var eventData = {
        originalEvent: event,
        value: props.value,
        checked: value,
        stopPropagation: function stopPropagation() {
          event === null || event === void 0 || event.stopPropagation();
        },
        preventDefault: function preventDefault() {
          event === null || event === void 0 || event.preventDefault();
        },
        target: {
          type: "checkbox",
          name: props.name,
          id: props.id,
          value: props.value,
          checked: value
        }
      };
      props === null || props === void 0 || (_props$onChange = props.onChange) === null || _props$onChange === void 0 || _props$onChange.call(props, eventData);
      if (event.defaultPrevented) {
        return;
      }
      DomHandler.focus(inputRef.current);
    }
  };
  var _onFocus = function onFocus(event) {
    var _props$onFocus;
    setFocusedState(true);
    props === null || props === void 0 || (_props$onFocus = props.onFocus) === null || _props$onFocus === void 0 || _props$onFocus.call(props, event);
  };
  var _onBlur = function onBlur(event) {
    var _props$onBlur;
    setFocusedState(false);
    props === null || props === void 0 || (_props$onBlur = props.onBlur) === null || _props$onBlur === void 0 || _props$onBlur.call(props, event);
  };
  React.useImperativeHandle(ref, function() {
    return {
      props,
      focus: function focus() {
        return DomHandler.focus(inputRef.current);
      },
      getElement: function getElement() {
        return elementRef.current;
      },
      getInput: function getInput() {
        return inputRef.current;
      }
    };
  });
  React.useEffect(function() {
    ObjectUtils.combinedRefs(inputRef, props.inputRef);
  }, [inputRef, props.inputRef]);
  useUpdateEffect(function() {
    inputRef.current.checked = isChecked();
  }, [props.checked, props.trueValue]);
  useMountEffect(function() {
    if (props.autoFocus) {
      DomHandler.focus(inputRef.current, props.autoFocus);
    }
  });
  var checked = isChecked();
  var hasTooltip = ObjectUtils.isNotEmpty(props.tooltip);
  var otherProps = CheckboxBase.getOtherProps(props);
  var rootProps = mergeProps({
    id: props.id,
    className: classNames(props.className, cx("root", {
      checked,
      context
    })),
    style: props.style,
    "data-p-highlight": checked,
    "data-p-disabled": props.disabled,
    onContextMenu: props.onContextMenu,
    onMouseDown: props.onMouseDown
  }, otherProps, ptm("root"));
  var createInputElement = function createInputElement2() {
    var ariaProps = ObjectUtils.reduceKeys(otherProps, DomHandler.ARIA_PROPS);
    var inputProps = mergeProps(_objectSpread$e({
      id: props.inputId,
      type: "checkbox",
      className: cx("input"),
      name: props.name,
      tabIndex: props.tabIndex,
      onFocus: function onFocus(e) {
        return _onFocus(e);
      },
      onBlur: function onBlur(e) {
        return _onBlur(e);
      },
      onChange: function onChange(e) {
        return _onChange(e);
      },
      disabled: props.disabled,
      readOnly: props.readOnly,
      required: props.required,
      "aria-invalid": props.invalid,
      checked
    }, ariaProps), ptm("input"));
    return /* @__PURE__ */ React.createElement("input", _extends({
      ref: inputRef
    }, inputProps));
  };
  var createBoxElement = function createBoxElement2() {
    var iconProps = mergeProps({
      className: cx("icon")
    }, ptm("icon"));
    var boxProps = mergeProps({
      className: cx("box", {
        checked
      }),
      "data-p-highlight": checked,
      "data-p-disabled": props.disabled
    }, ptm("box"));
    var icon = checked ? props.icon || /* @__PURE__ */ React.createElement(CheckIcon, iconProps) : null;
    var checkboxIcon = IconUtils.getJSXIcon(icon, _objectSpread$e({}, iconProps), {
      props,
      checked
    });
    return /* @__PURE__ */ React.createElement("div", boxProps, checkboxIcon);
  };
  return /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement("div", _extends({
    ref: elementRef
  }, rootProps), createInputElement(), createBoxElement()), hasTooltip && /* @__PURE__ */ React.createElement(Tooltip, _extends({
    target: elementRef,
    content: props.tooltip,
    pt: ptm("tooltip")
  }, props.tooltipOptions)));
}));
Checkbox.displayName = "Checkbox";
function ownKeys$d(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$d(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$d(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$d(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var RowCheckbox = /* @__PURE__ */ React.memo(function(props) {
  var mergeProps = useMergeProps();
  var getColumnProps = function getColumnProps2() {
    return ColumnBase.getCProps(props.column);
  };
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  var getColumnPTOptions = function getColumnPTOptions2(key) {
    var columnMetaData = {
      props: getColumnProps(),
      parent: props.metaData,
      hostName: props.hostName,
      state: {},
      context: {
        index: props.tabIndex,
        checked: props.checked,
        disabled: props.disabled
      }
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(getColumnProps(), key, columnMetaData));
  };
  var onChange = function onChange2(event) {
    if (!props.disabled) {
      props.onChange(event);
    }
  };
  var checkboxIconProps = mergeProps({
    className: cx("checkIcon")
  }, getColumnPTOptions("rowCheckbox.icon"));
  var icon = props.checked ? props.checkIcon || /* @__PURE__ */ React.createElement(CheckIcon, checkboxIconProps) : null;
  var checkIcon = IconUtils.getJSXIcon(icon, _objectSpread$d({}, checkboxIconProps), {
    props
  });
  var tabIndex = props.disabled ? null : "0";
  var checkboxProps = {
    role: "checkbox",
    tabIndex,
    onChange,
    "aria-label": props.ariaLabel,
    checked: props.checked,
    icon: checkIcon,
    disabled: props.disabled,
    unstyled: props.unstyled,
    pt: getColumnPTOptions("rowCheckbox")
  };
  return /* @__PURE__ */ React.createElement(Checkbox, checkboxProps);
});
RowCheckbox.displayName = "RowCheckbox";
var classes = {
  root: function root3(_ref) {
    var props = _ref.props, context = _ref.context;
    return classNames("p-radiobutton p-component", {
      "p-highlight": props.checked,
      "p-disabled": props.disabled,
      "p-invalid": props.invalid,
      "p-variant-filled": props.variant ? props.variant === "filled" : context && context.inputStyle === "filled"
    });
  },
  box: "p-radiobutton-box",
  input: "p-radiobutton-input",
  icon: "p-radiobutton-icon"
};
var RadioButtonBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "RadioButton",
    autoFocus: false,
    checked: false,
    className: null,
    disabled: false,
    id: null,
    inputId: null,
    inputRef: null,
    invalid: false,
    variant: null,
    name: null,
    onChange: null,
    onClick: null,
    required: false,
    style: null,
    tabIndex: null,
    tooltip: null,
    tooltipOptions: null,
    value: null,
    children: void 0
  },
  css: {
    classes
  }
});
function ownKeys$c(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$c(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$c(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$c(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var RadioButton = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var mergeProps = useMergeProps();
  var context = React.useContext(PrimeReactContext);
  var props = RadioButtonBase.getProps(inProps, context);
  var elementRef = React.useRef(null);
  var inputRef = React.useRef(props.inputRef);
  var _RadioButtonBase$setM = RadioButtonBase.setMetaData({
    props
  }), ptm = _RadioButtonBase$setM.ptm, cx = _RadioButtonBase$setM.cx, isUnstyled = _RadioButtonBase$setM.isUnstyled;
  useHandleStyle(RadioButtonBase.css.styles, isUnstyled, {
    name: "radiobutton"
  });
  var select = function select2(event) {
    onChange(event);
  };
  var onChange = function onChange2(event) {
    if (props.disabled || props.readOnly) {
      return;
    }
    if (props.onChange) {
      var checked = props.checked;
      var radioClicked = event.target instanceof HTMLDivElement;
      var inputClicked = event.target === inputRef.current;
      var isInputToggled = inputClicked && event.target.checked !== checked;
      var isRadioToggled = radioClicked && (DomHandler.hasClass(elementRef.current, "p-radiobutton-checked") === checked ? !checked : false);
      var value = !checked;
      var eventData = {
        originalEvent: event,
        value: props.value,
        checked: value,
        stopPropagation: function stopPropagation() {
          event === null || event === void 0 || event.stopPropagation();
        },
        preventDefault: function preventDefault() {
          event === null || event === void 0 || event.preventDefault();
        },
        target: {
          type: "radio",
          name: props.name,
          id: props.id,
          value: props.value,
          checked: value
        }
      };
      if (isInputToggled || isRadioToggled) {
        var _props$onChange;
        props === null || props === void 0 || (_props$onChange = props.onChange) === null || _props$onChange === void 0 || _props$onChange.call(props, eventData);
        if (event.defaultPrevented) {
          return;
        }
        if (isRadioToggled) {
          inputRef.current.checked = value;
        }
      }
      DomHandler.focus(inputRef.current);
    }
  };
  var onFocus = function onFocus2(event) {
    var _props$onFocus;
    props === null || props === void 0 || (_props$onFocus = props.onFocus) === null || _props$onFocus === void 0 || _props$onFocus.call(props, event);
  };
  var onBlur = function onBlur2(event) {
    var _props$onBlur;
    props === null || props === void 0 || (_props$onBlur = props.onBlur) === null || _props$onBlur === void 0 || _props$onBlur.call(props, event);
  };
  React.useImperativeHandle(ref, function() {
    return {
      props,
      select,
      focus: function focus() {
        return DomHandler.focus(inputRef.current);
      },
      getElement: function getElement() {
        return elementRef.current;
      },
      getInput: function getInput() {
        return inputRef.current;
      }
    };
  });
  React.useEffect(function() {
    if (inputRef.current) {
      inputRef.current.checked = props.checked;
    }
  }, [props.checked]);
  React.useEffect(function() {
    ObjectUtils.combinedRefs(inputRef, props.inputRef);
  }, [inputRef, props.inputRef]);
  useMountEffect(function() {
    if (props.autoFocus) {
      DomHandler.focus(inputRef.current, props.autoFocus);
    }
  });
  var hasTooltip = ObjectUtils.isNotEmpty(props.tooltip);
  var otherProps = RadioButtonBase.getOtherProps(props);
  var rootProps = mergeProps({
    id: props.id,
    className: classNames(props.className, cx("root", {
      context
    })),
    style: props.style,
    "data-p-checked": props.checked
  }, otherProps, ptm("root"));
  delete rootProps.input;
  delete rootProps.box;
  delete rootProps.icon;
  var createInputElement = function createInputElement2() {
    var ariaProps = ObjectUtils.reduceKeys(otherProps, DomHandler.ARIA_PROPS);
    var inputProps = mergeProps(_objectSpread$c({
      id: props.inputId,
      type: "radio",
      name: props.name,
      defaultChecked: props.checked,
      onFocus,
      onBlur,
      onChange,
      disabled: props.disabled,
      readOnly: props.readOnly,
      required: props.required,
      tabIndex: props.tabIndex,
      className: cx("input")
    }, ariaProps), inProps.input, ptm("input"));
    return /* @__PURE__ */ React.createElement("input", _extends({
      ref: inputRef
    }, inputProps));
  };
  var createBoxElement = function createBoxElement2() {
    var boxProps = mergeProps({
      className: cx("box")
    }, inProps.box, ptm("box"));
    var iconProps = mergeProps({
      className: cx("icon")
    }, inProps.icon, ptm("icon"));
    return /* @__PURE__ */ React.createElement("div", boxProps, /* @__PURE__ */ React.createElement("div", iconProps));
  };
  return /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement("div", _extends({
    ref: elementRef
  }, rootProps), createInputElement(), createBoxElement()), hasTooltip && /* @__PURE__ */ React.createElement(Tooltip, _extends({
    target: elementRef,
    content: props.tooltip,
    pt: ptm("tooltip")
  }, props.tooltipOptions)));
}));
RadioButton.displayName = "RadioButton";
var RowRadioButton = /* @__PURE__ */ React.memo(function(props) {
  var mergeProps = useMergeProps();
  var getColumnProps = function getColumnProps2() {
    return ColumnBase.getCProps(props.column);
  };
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo;
  var getColumnPTOptions = function getColumnPTOptions2(key) {
    var columnMetaData = {
      props: getColumnProps(),
      parent: props.metaData,
      hostName: props.hostName,
      state: {},
      context: {
        index: props.tabIndex,
        checked: props.checked,
        disabled: props.disabled
      }
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(getColumnProps(), key, columnMetaData));
  };
  var onChange = function onChange2(event) {
    if (!props.disabled) {
      props.onChange(event);
    }
  };
  var radioButtonProps = {
    role: "radio",
    "aria-checked": props.checked,
    checked: props.checked,
    disabled: props.disabled,
    name: "".concat(props.tableSelector, "_dt_radio"),
    onChange,
    unstyled: props.unstyled,
    pt: getColumnPTOptions("rowRadioButton")
  };
  return /* @__PURE__ */ React.createElement(RadioButton, radioButtonProps);
});
RowRadioButton.displayName = "RowRadioButton";
function ownKeys$b(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$b(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$b(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$b(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var Cell = function Cell2(props) {
  var mergeProps = useMergeProps();
  var _React$useState = React.useState(props.editing), _React$useState2 = _slicedToArray(_React$useState, 2), editingState = _React$useState2[0], setEditingState = _React$useState2[1];
  var _React$useState3 = React.useState(props.rowData), _React$useState4 = _slicedToArray(_React$useState3, 2), editingRowDataState = _React$useState4[0], setEditingRowDataState = _React$useState4[1];
  var _React$useState5 = React.useState({}), _React$useState6 = _slicedToArray(_React$useState5, 2), styleObjectState = _React$useState6[0], setStyleObjectState = _React$useState6[1];
  var elementRef = React.useRef(null);
  var keyHelperRef = React.useRef(null);
  var focusTimeout = React.useRef(null);
  var initFocusTimeout = React.useRef(null);
  var editingRowDataStateRef = React.useRef(null);
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  var getColumnProp = function getColumnProp2(name) {
    return ColumnBase.getCProp(props.column, name);
  };
  var getColumnPTOptions = function getColumnPTOptions2(key) {
    var columnMetaData = {
      props: props.cProps,
      parent: props.metaData,
      hostName: props.hostName,
      state: {
        styleObject: styleObjectState,
        editing: editingState,
        editingRowData: editingRowDataState
      },
      context: {
        index: props.index,
        size: props.metaData.props.size,
        showGridlines: props.metaData.props.showGridlines
      }
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(props.cProps, key, columnMetaData));
  };
  var isEditable = function isEditable2() {
    return ObjectUtils.isNotEmpty(props.editMode) && getColumnProp("editor");
  };
  var cellEditValidateOnClose = function cellEditValidateOnClose2() {
    return getColumnProp("cellEditValidateOnClose");
  };
  var isIgnoredElement = function isIgnoredElement2(element) {
    var isOverlay = function isOverlay2(el) {
      return el.getAttribute && el.getAttribute("data-pr-is-overlay");
    };
    return isOverlay(element) || DomHandler.getParents(element).find(function(el) {
      return isOverlay(el);
    });
  };
  var _useEventListener = useEventListener({
    type: "click",
    listener: function listener(e) {
      if (!isIgnoredElement(e.target) && isOutsideClicked(e.target)) {
        switchCellToViewMode(e, true);
      }
    },
    options: true,
    when: isEditable()
  }), _useEventListener2 = _slicedToArray(_useEventListener, 2), bindDocumentClickListener = _useEventListener2[0], unbindDocumentClickListener = _useEventListener2[1];
  var isOutsideClicked = function isOutsideClicked2(target) {
    return elementRef.current && !(elementRef.current.isSameNode(target) || elementRef.current.contains(target));
  };
  var getStyle = function getStyle2() {
    var bodyStyle = getColumnProp("bodyStyle");
    var columnStyle = getColumnProp("style");
    return props.frozenCol ? Object.assign({}, columnStyle, bodyStyle, styleObjectState) : Object.assign({}, columnStyle, bodyStyle);
  };
  var getCellParams = function getCellParams2() {
    return {
      value: props.resolveFieldData(),
      field: props.field,
      rowData: props.rowData,
      rowIndex: props.rowIndex,
      cellIndex: props.index,
      selected: props.isCellSelected,
      column: props.column,
      props
    };
  };
  var getCellCallbackParams = function getCellCallbackParams2(event) {
    var params = getCellParams();
    return _objectSpread$b({
      originalEvent: event
    }, params);
  };
  var closeCell = function closeCell2(event) {
    var params = getCellCallbackParams(event);
    var onBeforeCellEditHide = getColumnProp("onBeforeCellEditHide");
    if (onBeforeCellEditHide) {
      onBeforeCellEditHide(params);
    }
    setTimeout(function() {
      setEditingState(false);
      unbindDocumentClickListener();
    }, 1);
  };
  var switchCellToViewMode = function switchCellToViewMode2(event, submit) {
    var callbackParams = getCellCallbackParams(event);
    var newRowData = _objectSpread$b({}, editingRowDataStateRef.current);
    var newValue = props.resolveFieldData(newRowData);
    var params = _objectSpread$b(_objectSpread$b({}, callbackParams), {}, {
      newRowData,
      newValue
    });
    var onCellEditCancel = getColumnProp("onCellEditCancel");
    var cellEditValidator = getColumnProp("cellEditValidator");
    var onCellEditComplete = getColumnProp("onCellEditComplete");
    if (!submit && onCellEditCancel) {
      onCellEditCancel(params);
    }
    var valid = true;
    if ((!submit || cellEditValidateOnClose()) && cellEditValidator) {
      valid = cellEditValidator(params);
    }
    if (valid) {
      if (submit && onCellEditComplete) {
        onCellEditComplete(params);
      }
      closeCell(event);
    } else {
      event.preventDefault();
    }
    setEditingRowDataState(newRowData);
  };
  var editorCallback = function editorCallback2(val) {
    var editingRowData = _objectSpread$b({}, editingRowDataState);
    ObjectUtils.mutateFieldData(editingRowData, props.field, val);
    setEditingRowDataState(editingRowData);
    var currentData = props.getEditingRowData();
    if (currentData) {
      ObjectUtils.mutateFieldData(currentData, props.field, val);
    }
    editingRowDataStateRef.current = editingRowData;
  };
  var _onClick = function onClick(event) {
    props.onClick(event, getCellCallbackParams(event), isEditable(), editingState, setEditingState, props.column, bindDocumentClickListener);
  };
  var _onMouseDown = function onMouseDown(event) {
    var params = getCellCallbackParams(event);
    props.onMouseDown && props.onMouseDown(params);
  };
  var _onMouseUp = function onMouseUp(event) {
    var params = getCellCallbackParams(event);
    props.onMouseUp && props.onMouseUp(params);
  };
  var _onKeyDown = function onKeyDown(event) {
    if (props.editMode !== "row") {
      if (event.code === "Enter" || event.code === "NumpadEnter" || event.code === "Tab") {
        switchCellToViewMode(event, true);
      }
      if (event.code === "Escape") {
        switchCellToViewMode(event, false);
      }
    }
    if (props.allowCellSelection) {
      var target = event.target, cell = event.currentTarget;
      switch (event.code) {
        case "ArrowLeft":
          var prevCell = props.findPrevSelectableCell(cell);
          if (prevCell) {
            changeTabIndex(cell, prevCell);
            prevCell.focus();
          }
          event.preventDefault();
          break;
        case "ArrowRight":
          var nextCell = props.findNextSelectableCell(cell);
          if (nextCell) {
            changeTabIndex(cell, nextCell);
            nextCell.focus();
          }
          event.preventDefault();
          break;
        case "ArrowUp":
          var upCell = props.findUpSelectableCell(cell, index);
          if (upCell) {
            changeTabIndex(cell, upCell);
            upCell.focus();
          }
          event.preventDefault();
          break;
        case "ArrowDown":
          var downCell = props.findDownSelectableCell(cell, index);
          if (downCell) {
            changeTabIndex(cell, downCell);
            downCell.focus();
          }
          event.preventDefault();
          break;
        case "Enter":
        case "NumpadEnter":
          if (event.shiftKey || event.ctrlKey) ;
          else if (!DomHandler.isClickable(target)) {
            _onClick(event);
            event.preventDefault();
          }
          break;
        case "Space":
          if (!DomHandler.isClickable(target) && !target.readOnly) {
            _onClick(event);
            event.preventDefault();
          }
          break;
      }
    }
  };
  var _onBlur = function onBlur(event) {
    if (props.editMode !== "row" && editingState && getColumnProp("cellEditValidatorEvent") === "blur") {
      switchCellToViewMode(event, true);
    }
  };
  var onEditorFocus = function onEditorFocus2(event) {
    _onClick(event);
  };
  var onRadioChange = function onRadioChange2(event) {
    props.onRadioChange({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var onRowToggle = function onRowToggle2(event) {
    props.onRowToggle({
      originalEvent: event,
      data: props.rowData
    });
    event.preventDefault();
    event.stopPropagation();
  };
  var onRowEditInit = function onRowEditInit2(event) {
    props.onRowEditInit({
      originalEvent: event,
      data: props.rowData,
      newData: props.getEditingRowData(),
      field: props.field,
      index: props.rowIndex
    });
  };
  var onRowEditSave = function onRowEditSave2(event) {
    props.onRowEditSave({
      originalEvent: event,
      data: props.rowData,
      newData: props.getEditingRowData(),
      field: props.field,
      index: props.rowIndex
    });
    props.focusOnInit(initFocusTimeout, elementRef);
  };
  var onRowEditCancel = function onRowEditCancel2(event) {
    props.onRowEditCancel({
      originalEvent: event,
      data: props.rowData,
      newData: props.getEditingRowData(),
      field: props.field,
      index: props.rowIndex
    });
    props.focusOnInit(initFocusTimeout, elementRef);
  };
  React.useEffect(function() {
    if (props.frozenCol) props.updateStickyPosition(elementRef, props.frozenCol, props.alignFrozenCol, styleObjectState, setStyleObjectState);
    if (props.editMode === "cell" || props.editMode === "row") props.focusOnElement(focusTimeout, editingState, elementRef, keyHelperRef);
  }, [props.editMode, props.editing, editingState, props.frozenCol, props.alignFrozenCol]);
  React.useEffect(function() {
    if (props.editMode === "row" && props.editing !== editingState) {
      setEditingState(props.editing);
    }
  }, [props.editMode, props.editing, editingState]);
  useUpdateEffect(function() {
    if (props.editMode === "cell" || props.editMode === "row") {
      var editingRowData = props.getEditingRowData();
      setEditingRowDataState(editingRowData);
      editingRowDataStateRef.current = editingRowData;
    }
  }, [props.editingMeta]);
  React.useEffect(function() {
    if (props.editMode === "cell" || props.editMode === "row") {
      var callbackParams = getCellCallbackParams();
      var params = _objectSpread$b(_objectSpread$b({}, callbackParams), {}, {
        editing: editingState,
        editingKey: props.editingKey
      });
      props.onEditingMetaChange(params);
    }
  }, [editingState]);
  useUnmountEffect(function() {
    if (editingRowDataStateRef.current) {
      editingRowDataStateRef.current = null;
    }
  });
  var createLoading = function createLoading2() {
    var options = props.getVirtualScrollerOption("getLoaderOptions")(props.rowIndex, {
      cellIndex: props.index,
      cellFirst: props.index === 0,
      cellLast: props.index === props.getVirtualScrollerOption("columns").length - 1,
      cellEven: props.index % 2 === 0,
      cellOdd: props.index % 2 !== 0,
      column: props.column,
      field: props.field
    });
    var content = ObjectUtils.getJSXElement(props.getVirtualScrollerOption("loadingTemplate"), options);
    var bodyCellProps = mergeProps(getColumnPTOptions("bodyCell"), {
      role: "cell"
    });
    return /* @__PURE__ */ React.createElement("td", bodyCellProps, content);
  };
  var createElement = function createElement2() {
    var content;
    var editorKeyHelper;
    var cellSelected = props.allowCellSelection && props.isCellSelected;
    var isRowEditor = props.editMode === "row";
    var tabIndex = props.getTabIndex(cellSelected, props.index);
    var selectionMode = getColumnProp("selectionMode");
    var rowReorder = getColumnProp("rowReorder");
    var header = getColumnProp("header");
    var body = getColumnProp("body");
    var editor = getColumnProp("editor");
    var frozen = props.frozenCol;
    var align = getColumnProp("align");
    var value = props.resolveFieldData();
    var columnBodyOptions = {
      column: props.column,
      field: props.field,
      rowIndex: props.rowIndex,
      frozenRow: props.frozenRow,
      props: props.tableProps
    };
    var rowEditor = ObjectUtils.getPropValue(getColumnProp("rowEditor"), props.rowData, columnBodyOptions);
    var expander = ObjectUtils.getPropValue(getColumnProp("expander"), props.rowData, columnBodyOptions);
    var cellClassName = ObjectUtils.getPropValue(props.cellClassName, value, columnBodyOptions);
    var bodyClassName = ObjectUtils.getPropValue(getColumnProp("bodyClassName"), props.rowData, columnBodyOptions);
    var style = getStyle();
    var columnTitleProps = mergeProps({
      className: cx("columnTitle")
    }, getColumnPTOptions("columnTitle"));
    var title = props.responsiveLayout === "stack" && /* @__PURE__ */ React.createElement("span", columnTitleProps, ObjectUtils.getJSXElement(header, {
      props: props.tableProps
    }));
    if (selectionMode) {
      var showSelection = props.showSelectionElement ? props.showSelectionElement(props.rowData, {
        rowIndex: props.rowIndex,
        props: props.tableProps
      }) : true;
      var label;
      if (showSelection) {
        var ariaLabelField = props.selectionAriaLabel || props.tableProps.dataKey;
        var ariaLabelText = ObjectUtils.resolveFieldData(props.rowData, ariaLabelField);
        label = "".concat(props.isRowSelected ? ariaLabel$2("unselectRow") : ariaLabel$2("selectRow"), " ").concat(ariaLabelText);
      }
      content = showSelection && /* @__PURE__ */ React.createElement(React.Fragment, null, selectionMode === "single" && /* @__PURE__ */ React.createElement(RowRadioButton, {
        hostName: props.hostName,
        column: props.column,
        checked: props.isRowSelected,
        disabled: !props.isSelectable({
          data: props.rowData,
          index: props.rowIndex
        }),
        onChange: onRadioChange,
        tabIndex: props.tabIndex,
        tableSelector: props.tableSelector,
        ariaLabel: label,
        ptCallbacks: props.ptCallbacks,
        metaData: props.metaData,
        unstyled: props.unstyled
      }), selectionMode === "multiple" && /* @__PURE__ */ React.createElement(RowCheckbox, {
        hostName: props.hostName,
        column: props.column,
        checked: props.isRowSelected,
        disabled: !props.isSelectable({
          data: props.rowData,
          index: props.rowIndex
        }),
        onChange: props.onCheckboxChange,
        tabIndex: props.tabIndex,
        ariaLabel: label,
        checkIcon: props.checkIcon,
        ptCallbacks: props.ptCallbacks,
        metaData: props.metaData,
        unstyled: props.unstyled
      }));
    } else if (rowReorder) {
      var showReorder = props.showRowReorderElement ? props.showRowReorderElement(props.rowData, {
        rowIndex: props.rowIndex,
        props: props.tableProps
      }) : true;
      var customIcon = getColumnProp("rowReorderIcon");
      var rowReorderIconProps = mergeProps({
        className: cx("rowReorderIcon")
      }, customIcon ? null : getColumnPTOptions("rowReorderIcon"));
      var rowReorderIcon = customIcon || /* @__PURE__ */ React.createElement(BarsIcon, rowReorderIconProps);
      content = showReorder ? IconUtils.getJSXIcon(rowReorderIcon, _objectSpread$b({}, rowReorderIconProps), {
        props
      }) : null;
    } else if (expander) {
      var rowTogglerIconProps = mergeProps({
        className: cx("rowTogglerIcon"),
        "aria-hidden": true
      }, getColumnPTOptions("rowTogglerIcon"));
      var icon = props.expanded ? props.expandedRowIcon || /* @__PURE__ */ React.createElement(ChevronDownIcon, rowTogglerIconProps) : props.collapsedRowIcon || /* @__PURE__ */ React.createElement(ChevronRightIcon, rowTogglerIconProps);
      var togglerIcon = IconUtils.getJSXIcon(icon, _objectSpread$b({}, rowTogglerIconProps), {
        props
      });
      var ariaControls = "".concat(props.tableSelector, "_content_").concat(props.rowIndex, "_expanded");
      var _ariaLabelField = props.selectionAriaLabel || props.tableProps.dataKey;
      var _ariaLabelText = ObjectUtils.resolveFieldData(props.rowData, _ariaLabelField);
      var _label = "".concat(props.expanded ? ariaLabel$2("collapseLabel") : ariaLabel$2("expandLabel"), " ").concat(_ariaLabelText);
      var expanderProps = {
        onClick: onRowToggle,
        className: cx("rowToggler")
      };
      var rowTogglerProps = mergeProps(_objectSpread$b(_objectSpread$b({}, expanderProps), {}, {
        type: "button",
        "aria-expanded": props.expanded,
        "aria-controls": ariaControls,
        tabIndex: props.tabIndex,
        "aria-label": _label
      }), getColumnPTOptions("rowToggler"));
      content = /* @__PURE__ */ React.createElement("button", rowTogglerProps, togglerIcon, /* @__PURE__ */ React.createElement(Ripple, null));
      if (body) {
        expanderProps.element = content;
        content = ObjectUtils.getJSXElement(body, props.rowData, {
          column: props.column,
          field: props.field,
          rowIndex: props.rowIndex,
          frozenRow: props.frozenRow,
          props: props.tableProps,
          expander: expanderProps
        });
      }
    } else if (isRowEditor && rowEditor) {
      var rowEditorProps = {};
      var rowEditorSaveIconProps = mergeProps({
        className: cx("rowEditorSaveIcon")
      }, getColumnPTOptions("rowEditorSaveIcon"));
      var rowEditorCancelIconProps = mergeProps({
        className: cx("rowEditorCancelIcon")
      }, getColumnPTOptions("rowEditorCancelIcon"));
      var rowEditorInitIconProps = mergeProps({
        className: cx("rowEditorInitIcon")
      }, getColumnPTOptions("rowEditorInitIcon"));
      var rowEditorSaveIcon = IconUtils.getJSXIcon(props.rowEditorSaveIcon || /* @__PURE__ */ React.createElement(CheckIcon, rowEditorSaveIconProps), _objectSpread$b({}, rowEditorSaveIconProps), {
        props
      });
      var rowEditorCancelIcon = IconUtils.getJSXIcon(props.rowEditorCancelIcon || /* @__PURE__ */ React.createElement(TimesIcon, rowEditorCancelIconProps), _objectSpread$b({}, rowEditorCancelIconProps), {
        props
      });
      var rowEditorInitIcon = IconUtils.getJSXIcon(props.rowEditorInitIcon || /* @__PURE__ */ React.createElement(PencilIcon, rowEditorInitIconProps), _objectSpread$b({}, rowEditorInitIconProps), {
        props
      });
      if (editingState) {
        rowEditorProps = {
          editing: true,
          onSaveClick: onRowEditSave,
          saveClassName: cx("rowEditorSaveButton"),
          onCancelClick: onRowEditCancel,
          cancelClassName: cx("rowEditorCancelButton")
        };
        var rowEditorSaveButtonProps = mergeProps({
          type: "button",
          name: "row-save",
          "aria-label": ariaLabel$2("saveEdit"),
          onClick: rowEditorProps.onSaveClick,
          className: rowEditorProps.saveClassName,
          tabIndex: props.tabIndex,
          "data-p-row-editor-save": true
        }, getColumnPTOptions("rowEditorSaveButton"));
        var rowEditorCancelButtonProps = mergeProps({
          type: "button",
          name: "row-cancel",
          "aria-label": ariaLabel$2("cancelEdit"),
          onClick: rowEditorProps.onCancelClick,
          className: rowEditorProps.cancelClassName,
          tabIndex: props.tabIndex
        }, getColumnPTOptions("rowEditorCancelButton"));
        content = /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement("button", rowEditorSaveButtonProps, rowEditorSaveIcon, /* @__PURE__ */ React.createElement(Ripple, null)), /* @__PURE__ */ React.createElement("button", rowEditorCancelButtonProps, rowEditorCancelIcon, /* @__PURE__ */ React.createElement(Ripple, null)));
      } else {
        rowEditorProps = {
          editing: false,
          onInitClick: onRowEditInit,
          initClassName: cx("rowEditorInitButton")
        };
        var rowEditorInitButtonProps = mergeProps({
          type: "button",
          name: "row-edit",
          "aria-label": ariaLabel$2("editRow"),
          onClick: rowEditorProps.onInitClick,
          className: rowEditorProps.initClassName,
          tabIndex: props.tabIndex,
          "data-p-row-editor-init": true
        }, getColumnPTOptions("rowEditorInitButton"));
        content = /* @__PURE__ */ React.createElement("button", rowEditorInitButtonProps, rowEditorInitIcon, /* @__PURE__ */ React.createElement(Ripple, null));
      }
      if (body) {
        rowEditorProps.element = content;
        content = ObjectUtils.getJSXElement(body, props.rowData, {
          column: props.column,
          field: props.field,
          rowIndex: props.rowIndex,
          frozenRow: props.frozenRow,
          props: props.tableProps,
          rowEditor: rowEditorProps
        });
      }
    } else if (body && (!editingState || !editor)) {
      content = body ? ObjectUtils.getJSXElement(body, props.rowData, {
        column: props.column,
        field: props.field,
        rowIndex: props.rowIndex,
        frozenRow: props.frozenRow,
        props: props.tableProps
      }) : value;
    } else if (editor && editingState) {
      content = ObjectUtils.getJSXElement(editor, {
        rowData: editingRowDataState,
        value: props.resolveFieldData(editingRowDataState),
        column: props.column,
        field: props.field,
        rowIndex: props.rowIndex,
        frozenRow: props.frozenRow,
        props: props.tableProps,
        editorCallback
      });
    } else {
      content = value;
    }
    content = typeof content === "boolean" ? content.toString() : content;
    if (!isRowEditor && editor) {
      var editorKeyHelperProps = mergeProps({
        tabIndex: "0",
        className: "p-cell-editor-key-helper p-hidden-accessible",
        onFocus: function onFocus(e) {
          return onEditorFocus(e);
        }
      }, getColumnPTOptions("editorKeyHelperLabel"));
      var editorKeyHelperLabelProps = mergeProps(getColumnPTOptions("editorKeyHelper"));
      editorKeyHelper = /* @__PURE__ */ React.createElement("a", _extends({
        ref: keyHelperRef
      }, editorKeyHelperProps), /* @__PURE__ */ React.createElement("span", editorKeyHelperLabelProps));
    }
    var bodyCellProps = mergeProps({
      style,
      className: classNames(bodyClassName, getColumnProp("className"), cellClassName, cx("bodyCell", {
        selectionMode,
        editor,
        editingState,
        frozen,
        cellSelected,
        align,
        bodyProps: props,
        getCellParams
      })),
      rowSpan: props.rowSpan,
      tabIndex,
      role: "cell",
      onClick: function onClick(e) {
        return _onClick(e);
      },
      onKeyDown: function onKeyDown(e) {
        return _onKeyDown(e);
      },
      onBlur: function onBlur(e) {
        return _onBlur(e);
      },
      onMouseDown: function onMouseDown(e) {
        return _onMouseDown(e);
      },
      onMouseUp: function onMouseUp(e) {
        return _onMouseUp(e);
      },
      "data-p-selectable-cell": props.allowCellSelection && props.isSelectable({
        data: getCellParams(),
        index: props.rowIndex
      }),
      "data-p-selection-column": getColumnProp("selectionMode") != null,
      "data-p-editable-column": isEditable() != null,
      "data-p-cell-editing": editingState,
      "data-p-frozen-column": frozen
    }, getColumnPTOptions("root"), getColumnPTOptions("bodyCell"));
    return /* @__PURE__ */ React.createElement("td", _extends({
      ref: elementRef
    }, bodyCellProps), editorKeyHelper, title, content);
  };
  return props.getVirtualScrollerOption("loading") ? createLoading() : createElement();
};
var RadioCheckCell = /* @__PURE__ */ React.memo(function(props) {
  return /* @__PURE__ */ React.createElement(Cell, props);
}, function(prevProps, nextProps) {
  if (nextProps.cellMemo === false) return false;
  var keysToCompare = ["isRowSelected", "field", "allowCellSelection", "isCellSelected", "editMode", "index", "tabIndex", "editing", "expanded", "editingMeta", "rowData"];
  return ObjectUtils.selectiveCompare(prevProps, nextProps, keysToCompare);
});
RadioCheckCell.displayName = "RadioCheckCell";
var defaultKeysToCompare = ["rowData", "field", "allowCellSelection", "isCellSelected", "editMode", "index", "tabIndex", "editing", "expanded", "editingMeta", "frozenCol", "alignFrozenCol"];
var BodyCell = /* @__PURE__ */ React.memo(function(props) {
  return /* @__PURE__ */ React.createElement(Cell, props);
}, function(prevProps, nextProps) {
  if (nextProps.cellMemo === false) return false;
  var memoProps = nextProps.cellMemoProps;
  var keysToCompare = Array.isArray(memoProps) && memoProps.every(function(prop) {
    return typeof prop === "string";
  }) ? memoProps : defaultKeysToCompare;
  var memoPropsDepth = nextProps.cellMemoPropsDepth;
  var depth = typeof memoPropsDepth === "number" && memoPropsDepth > 0 ? memoPropsDepth : 1;
  return ObjectUtils.selectiveCompare(prevProps, nextProps, keysToCompare, depth);
});
BodyCell.displayName = "BodyCell";
function ownKeys$a(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$a(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$a(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$a(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var BodyRow = /* @__PURE__ */ React.memo(function(props) {
  var mergeProps = useMergeProps();
  var _React$useState = React.useState(false), _React$useState2 = _slicedToArray(_React$useState, 2), editingState = _React$useState2[0], setEditingState = _React$useState2[1];
  var editing = props.onRowEditChange ? props.editing : editingState;
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, cx = _props$ptCallbacks.cx;
  var isRowSelected = !props.allowCellSelection && props.selected || props.contextMenuSelected;
  var getBodyRowPTOptions = function getBodyRowPTOptions2(key) {
    return ptm(key, {
      parent: props.metaData,
      hostName: props.hostName,
      state: {
        editing
      },
      context: {
        index: props.index,
        selectable: props.allowRowSelection && props.isSelectable({
          data: props.rowData,
          index: props.rowIndex
        }),
        selected: isRowSelected,
        stripedRows: props.metaData.props.stripedRows
      }
    });
  };
  var getColumnProp = function getColumnProp2(column, name) {
    return ColumnBase.getCProp(column, name);
  };
  var isFocusable = function isFocusable2() {
    return props.selectionMode && props.selectionModeInColumn !== "single" && props.selectionModeInColumn !== "multiple";
  };
  var isGrouped = function isGrouped2(column) {
    var columnField = getColumnProp(column, "field");
    if (props.groupRowsBy && columnField) {
      return Array.isArray(props.groupRowsBy) ? props.groupRowsBy.indexOf(columnField) > -1 : props.groupRowsBy === columnField;
    }
    return false;
  };
  var equals = function equals2(data1, data2) {
    return props.compareSelectionBy === "equals" ? data1 === data2 : ObjectUtils.equals(data1, data2, props.dataKey);
  };
  var getTabIndex = function getTabIndex2() {
    return isFocusable() && !props.allowCellSelection ? props.rowIndex === 0 ? props.tabIndex : -1 : null;
  };
  var findIndex = function findIndex2(collection, rowData) {
    return (collection || []).findIndex(function(data) {
      return equals(rowData, data);
    });
  };
  var changeTabIndex2 = function changeTabIndex3(currentElement, nextElement) {
    if (currentElement && nextElement) {
      currentElement.tabIndex = -1;
      nextElement.tabIndex = props.tabIndex;
    }
  };
  var findFirstSelectableRow = function findFirstSelectableRow2(row) {
    var firstRow = DomHandler.findSingle(row.parentNode, "tr[data-p-selectable-row]");
    return firstRow ? firstRow : null;
  };
  var _findNextSelectableRow = function findNextSelectableRow(row) {
    var nextRow = row.nextElementSibling;
    return nextRow ? DomHandler.getAttribute(nextRow, "data-p-selectable-row") === true ? nextRow : _findNextSelectableRow(nextRow) : null;
  };
  var _findPrevSelectableRow = function findPrevSelectableRow(row) {
    var prevRow = row.previousElementSibling;
    return prevRow ? DomHandler.getAttribute(prevRow, "data-p-selectable-row") === true ? prevRow : _findPrevSelectableRow(prevRow) : null;
  };
  var findLastSelectableRow = function findLastSelectableRow2(row) {
    var lastRow = DomHandler.findSingle(row.parentNode, "tr[data-p-selectable-row]:last-child");
    return lastRow ? lastRow : null;
  };
  var shouldRenderBodyCell = function shouldRenderBodyCell2(value, column, i) {
    if (getColumnProp(column, "hidden")) {
      return false;
    } else if (props.rowGroupMode && props.rowGroupMode === "rowspan" && isGrouped(column)) {
      var prevRowData = value[i - 1];
      if (prevRowData) {
        var currentRowFieldData = ObjectUtils.resolveFieldData(value[i], getColumnProp(column, "field"));
        var previousRowFieldData = ObjectUtils.resolveFieldData(prevRowData, getColumnProp(column, "field"));
        return currentRowFieldData !== previousRowFieldData;
      }
    }
    return true;
  };
  var calculateRowGroupSize = function calculateRowGroupSize2(value, column, index2) {
    if (isGrouped(column)) {
      var currentRowFieldData = ObjectUtils.resolveFieldData(value[index2], getColumnProp(column, "field"));
      var nextRowFieldData = currentRowFieldData;
      var groupRowSpan = 0;
      while (currentRowFieldData === nextRowFieldData) {
        groupRowSpan++;
        var nextRowData = value[++index2];
        if (nextRowData) {
          nextRowFieldData = ObjectUtils.resolveFieldData(nextRowData, getColumnProp(column, "field"));
        } else {
          break;
        }
      }
      return groupRowSpan === 1 ? null : groupRowSpan;
    }
    return null;
  };
  var _onClick = function onClick(event) {
    props.onRowClick({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onDoubleClick = function onDoubleClick(event) {
    props.onRowDoubleClick({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onPointerDown = function onPointerDown(event) {
    props.onRowPointerDown({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onPointerUp = function onPointerUp(event) {
    props.onRowPointerUp({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var onRightClick = function onRightClick2(event) {
    props.onRowRightClick({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onMouseEnter = function onMouseEnter(event) {
    props.onRowMouseEnter({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onMouseLeave = function onMouseLeave(event) {
    props.onRowMouseLeave({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onTouchEnd = function onTouchEnd(event) {
    props.onRowTouchEnd(event);
  };
  var _onKeyDown = function onKeyDown(event) {
    if (isFocusable() && !props.allowCellSelection) {
      var target = event.target, row = event.currentTarget;
      switch (event.code) {
        case "ArrowDown":
          onArrowDownKey(row, event);
          break;
        case "ArrowUp":
          onArrowUpKey(row, event);
          break;
        case "Home":
          onHomeKey(row, event);
          break;
        case "End":
          onEndKey(row, event);
          break;
        case "Enter":
        case "NumpadEnter":
          onEnterKey(row, event, target);
          break;
        case "Space":
          onSpaceKey(row, event, target);
          break;
        case "Tab":
          onTabKey(row, event);
          break;
      }
    }
  };
  var onArrowDownKey = function onArrowDownKey2(row, event) {
    var nextRow = _findNextSelectableRow(row);
    if (nextRow) {
      changeTabIndex2(row, nextRow);
      nextRow.focus();
    }
    event.preventDefault();
  };
  var onArrowUpKey = function onArrowUpKey2(row, event) {
    var prevRow = _findPrevSelectableRow(row);
    if (prevRow) {
      changeTabIndex2(row, prevRow);
      prevRow.focus();
    }
    event.preventDefault();
  };
  var onHomeKey = function onHomeKey2(row, event) {
    var firstRow = findFirstSelectableRow(row);
    if (firstRow) {
      changeTabIndex2(row, firstRow);
      firstRow.focus();
    }
    event.preventDefault();
  };
  var onEndKey = function onEndKey2(row, event) {
    var lastRow = findLastSelectableRow(row);
    if (lastRow) {
      changeTabIndex2(row, lastRow);
      lastRow.focus();
    }
    event.preventDefault();
  };
  var onEnterKey = function onEnterKey2(row, event, target) {
    if (!DomHandler.isClickable(target)) {
      _onClick(event);
      event.preventDefault();
    }
  };
  var onSpaceKey = function onSpaceKey2(row, event, target) {
    if (!DomHandler.isClickable(target) && !target.readOnly) {
      _onClick(event);
      event.preventDefault();
    }
  };
  var onTabKey = function onTabKey2(row, event) {
    var parent = row.parentNode;
    var rows = DomHandler.find(parent, 'tr[data-p-selectable-row="true"]');
    if (event.code === "Tab" && rows && rows.length > 0) {
      var firstSelectedRow = DomHandler.findSingle(parent, 'tr[data-p-highlight="true"]');
      var focusedItem = DomHandler.findSingle(parent, 'tr[data-p-selectable-row="true"][tabindex="0"]');
      if (firstSelectedRow) {
        firstSelectedRow.tabIndex = "0";
        focusedItem && focusedItem !== firstSelectedRow && (focusedItem.tabIndex = "-1");
      } else {
        rows[0].tabIndex = "0";
        focusedItem !== rows[0] && (rows[props.rowIndex].tabIndex = "-1");
      }
    }
  };
  var _onMouseDown = function onMouseDown(event) {
    props.onRowMouseDown({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onMouseUp = function onMouseUp(event) {
    props.onRowMouseUp({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onDragStart = function onDragStart(event) {
    props.onRowDragStart({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onDragOver = function onDragOver(event) {
    props.onRowDragOver({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onDragLeave = function onDragLeave(event) {
    props.onRowDragLeave({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onDragEnd = function onDragEnd(event) {
    props.onRowDragEnd({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var _onDrop = function onDrop(event) {
    props.onRowDrop({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var onEditChange = function onEditChange2(e, isEditing) {
    if (props.onRowEditChange) {
      var editingRows;
      var dataKey = props.dataKey;
      var originalEvent = e.originalEvent, data = e.data, index2 = e.index, newData = e.newData;
      if (dataKey) {
        var dataKeyValue = String(ObjectUtils.resolveFieldData(data, dataKey));
        editingRows = props.editingRows ? _objectSpread$a({}, props.editingRows) : {};
        if (!isEditing) {
          delete editingRows[dataKeyValue];
          var newDataKeyValue = String(ObjectUtils.resolveFieldData(newData, dataKey));
          delete editingRows[newDataKeyValue];
        } else {
          editingRows[dataKeyValue] = true;
        }
      } else {
        var editingRowIndex = findIndex(props.editingRows, data);
        editingRows = props.editingRows ? _toConsumableArray(props.editingRows) : [];
        if (editingRowIndex !== -1) {
          editingRows = editingRows.filter(function(val, i) {
            return i !== editingRowIndex;
          });
        } else {
          editingRows.push(data);
        }
      }
      props.onRowEditChange({
        originalEvent,
        data: editingRows,
        index: index2
      });
    } else {
      setEditingState(isEditing);
    }
  };
  var onEditInit = function onEditInit2(e) {
    var event = e.originalEvent;
    if (props.onRowEditInit) {
      props.onRowEditInit({
        originalEvent: event,
        data: props.rowData,
        index: props.rowIndex
      });
    }
    onEditChange(e, true);
    event.preventDefault();
  };
  var onEditSave = function onEditSave2(e) {
    var event = e.originalEvent, newData = e.newData;
    var valid = props.rowEditValidator ? props.rowEditValidator(newData, {
      props: props.tableProps,
      rowIndex: props.rowIndex
    }) : true;
    if (props.onRowEditSave) {
      props.onRowEditSave({
        originalEvent: event,
        data: props.rowData,
        index: props.rowIndex,
        newData,
        valid
      });
    }
    if (valid) {
      if (props.onRowEditComplete) {
        props.onRowEditComplete(e);
      }
      onEditChange(e, false);
    }
    event.preventDefault();
  };
  var onEditCancel = function onEditCancel2(e) {
    var event = e.originalEvent;
    if (props.onRowEditCancel) {
      props.onRowEditCancel({
        originalEvent: event,
        data: props.rowData,
        index: props.rowIndex
      });
    }
    onEditChange(e, false);
    event.preventDefault();
  };
  var equalsDataCell = function equalsDataCell2(data) {
    return props.compareSelectionBy === "equals" ? data === props.rowData : ObjectUtils.equals(data, props.rowData, props.dataKey);
  };
  var equalsCell = function equalsCell2(selectedCell, field, colIndex) {
    return selectedCell && (selectedCell.rowIndex === props.rowIndex || equalsDataCell(selectedCell.rowData)) && (selectedCell.field === field || selectedCell.cellIndex === colIndex);
  };
  var findIndexCell = function findIndexCell2(collection, field, colIndex) {
    return (collection || []).findIndex(function(data) {
      return equalsCell(data, field, colIndex);
    });
  };
  var isCellSelected = function isCellSelected2(selection, field, colIndex) {
    return selection ? selection instanceof Array ? findIndexCell(selection, field, colIndex) > -1 : equalsCell(selection, field, colIndex) : false;
  };
  var onCheckboxChange = function onCheckboxChange2(event) {
    props.onCheckboxChange({
      originalEvent: event,
      data: props.rowData,
      index: props.rowIndex
    });
  };
  var editingKey = props.dataKey ? props.rowData && props.rowData[props.dataKey] || props.rowIndex : props.rowIndex;
  var getVirtualScrollerOption = React.useCallback(function(option) {
    return props.virtualScrollerOptions ? props.virtualScrollerOptions[option] : null;
  }, [props.virtualScrollerOptions]);
  var getEditingRowData = function getEditingRowData2() {
    return props.editingMeta && props.editingMeta[editingKey] ? props.editingMeta[editingKey].data : props.rowData;
  };
  var getTabIndexCell = React.useCallback(function(cellSelected, cellIndex) {
    return props.allowCellSelection ? cellSelected ? 0 : props.rowIndex === 0 && cellIndex === 0 ? props.tabIndex : -1 : null;
  }, [props.allowCellSelection, props.rowIndex, props.tabIndex]);
  var findNextSelectableCell = React.useCallback(function(cell) {
    var nextCell = cell.nextElementSibling;
    return nextCell ? DomHandler.getAttribute(nextCell, "data-p-selectable-cell") ? nextCell : findNextSelectableCell(nextCell) : null;
  }, []);
  var findPrevSelectableCell = React.useCallback(function(cell) {
    var prevCell = cell.previousElementSibling;
    return prevCell ? DomHandler.getAttribute(prevCell, "data-p-selectable-cell") ? prevCell : findPrevSelectableCell(prevCell) : null;
  }, []);
  var findDownSelectableCell = React.useCallback(function(cell, cellIndex) {
    var downRow = cell.parentElement.nextElementSibling;
    var downCell = downRow ? downRow.children[cellIndex] : null;
    return downRow && downCell ? DomHandler.getAttribute(downRow, "data-p-selectable-row") && DomHandler.getAttribute(downCell, "data-p-selectable-cell") ? downCell : findDownSelectableCell(downCell) : null;
  }, []);
  var findUpSelectableCell = React.useCallback(function(cell, cellIndex) {
    var upRow = cell.parentElement.previousElementSibling;
    var upCell = upRow ? upRow.children[cellIndex] : null;
    return upRow && upCell ? DomHandler.getAttribute(upRow, "data-p-selectable-row") && DomHandler.getAttribute(upCell, "data-p-selectable-cell") ? upCell : findUpSelectableCell(upCell) : null;
  }, []);
  var focusOnElement = React.useCallback(function(focusTimeoutRef, editingState2, elementRef, keyHelperRef) {
    clearTimeout(focusTimeoutRef.current);
    focusTimeoutRef.current = setTimeout(function() {
      if (editingState2) {
        var focusableEl = props.editMode === "cell" ? DomHandler.getFirstFocusableElement(elementRef.current, ':not([data-pc-section="editorkeyhelperlabel"])') : DomHandler.findSingle(elementRef.current, '[data-p-row-editor-save="true"]');
        focusableEl && focusableEl.focus();
      }
      keyHelperRef.current && (keyHelperRef.current.tabIndex = editingState2 ? -1 : 0);
    }, 1);
  }, [props.editMode]);
  var focusOnInit = React.useCallback(function(initFocusTimeoutRef, elementRef) {
    clearTimeout(initFocusTimeoutRef.current);
    initFocusTimeoutRef.current = setTimeout(function() {
      var focusableEl = props.editMode === "row" ? DomHandler.findSingle(elementRef.current, '[data-p-row-editor-init="true"]') : null;
      focusableEl && focusableEl.focus();
    }, 1);
  }, [props.editMode]);
  var updateStickyPosition = React.useCallback(function(elementRef, frozen, alignFrozen, styleObjectState, setStyleObjectState) {
    if (frozen) {
      var styleObject = _objectSpread$a({}, styleObjectState);
      if (alignFrozen === "right") {
        var right = 0;
        var next = elementRef.current && elementRef.current.nextElementSibling;
        if (next && next.classList.contains("p-frozen-column")) {
          right = DomHandler.getOuterWidth(next) + parseFloat(next.style.right || 0);
        }
        styleObject.right = right + "px";
      } else {
        var left = 0;
        var prev = elementRef.current && elementRef.current.previousElementSibling;
        while (prev) {
          if (prev.classList.contains("p-frozen-column")) {
            left = DomHandler.getOuterWidth(prev) + parseFloat(prev.style.left || 0);
            elementRef.current.style.left = left + "px";
            break;
          }
          prev = prev.previousElementSibling;
        }
        styleObject.left = left + "px";
      }
      var isSameStyle = styleObjectState.left === styleObject.left && styleObjectState.right === styleObject.right;
      !isSameStyle && setStyleObjectState(styleObject);
    }
  }, []);
  var onCellClick = function onCellClick2(event, params, isEditable, editingState2, setEditingState2, column, bindDocumentClickListener) {
    if (props.editMode !== "row" && isEditable && !editingState2 && (props.selectOnEdit || !props.selectOnEdit && props.isRowSelected)) {
      var onBeforeCellEditShow = getColumnProp(column, "onBeforeCellEditShow");
      var onCellEditInit = getColumnProp(column, "onCellEditInit");
      var cellEditValidatorEvent = getColumnProp(column, "cellEditValidatorEvent");
      if (onBeforeCellEditShow) {
        if (onBeforeCellEditShow(params) === false) {
          return;
        }
        if (event && event.defaultPrevented) {
          return;
        }
      }
      setTimeout(function() {
        setEditingState2(true);
        if (onCellEditInit) {
          if (onCellEditInit(params) === false) {
            return;
          }
          if (event && event.defaultPrevented) {
            return;
          }
        }
        if (cellEditValidatorEvent === "click") {
          bindDocumentClickListener();
        }
      }, 1);
    }
    if (props.allowCellSelection && props.onCellClick) {
      props.onCellClick(params);
    }
  };
  var createContent = function createContent2() {
    return props.columns.map(function(col, i) {
      if (shouldRenderBodyCell(props.value, col, props.index)) {
        var key = "".concat(props.rowIndex, "_").concat(getColumnProp(col, "columnKey") || getColumnProp(col, "field"), "_").concat(i);
        var rowSpan = props.rowGroupMode === "rowspan" ? calculateRowGroupSize(props.value, col, props.index) : null;
        var field = getColumnProp(col, "field") || "field_".concat(i);
        var resolveFieldData = function resolveFieldData2(data) {
          return ObjectUtils.resolveFieldData(data || props.rowData, field);
        };
        var selectionMode = getColumnProp(col, "selectionMode");
        var cellProps = mergeProps({
          hostName: props.hostName,
          allowCellSelection: props.allowCellSelection,
          cellMemo: props.cellMemo,
          cellMemoProps: props.cellMemoProps,
          cellMemoPropsDepth: props.cellMemoPropsDepth,
          cellClassName: props.cellClassName,
          checkIcon: props.checkIcon,
          collapsedRowIcon: props.collapsedRowIcon,
          field,
          resolveFieldData,
          column: col,
          cProps: props.colsProps[i],
          dataKey: props.dataKey,
          editMode: props.editMode,
          editing,
          editingMeta: props.editingMeta,
          onEditingMetaChange: props.onEditingMetaChange,
          editingKey,
          getEditingRowData,
          expanded: props.expanded,
          expandedRowIcon: props.expandedRowIcon,
          frozenRow: props.frozenRow,
          frozenCol: getColumnProp(col, "frozen"),
          alignFrozenCol: getColumnProp(col, "alignFrozen"),
          index: i,
          isSelectable: props.isSelectable,
          onCheckboxChange,
          onClick: onCellClick,
          onMouseDown: props.onCellMouseDown,
          onMouseUp: props.onCellMouseUp,
          onRadioChange: props.onRadioChange,
          onRowEditCancel: onEditCancel,
          onRowEditInit: onEditInit,
          onRowEditSave: onEditSave,
          onRowToggle: props.onRowToggle,
          responsiveLayout: props.responsiveLayout,
          rowData: props.rowData,
          rowEditorCancelIcon: props.rowEditorCancelIcon,
          rowEditorInitIcon: props.rowEditorInitIcon,
          rowEditorSaveIcon: props.rowEditorSaveIcon,
          rowIndex: props.rowIndex,
          rowSpan,
          selectOnEdit: props.selectOnEdit,
          isRowSelected,
          isCellSelected: isCellSelected(props.selection, field, i),
          selectionAriaLabel: props.tableProps.selectionAriaLabel,
          showRowReorderElement: props.showRowReorderElement,
          showSelectionElement: props.showSelectionElement,
          tabIndex: props.tabIndex,
          getTabIndex: getTabIndexCell,
          tableProps: props.tableProps,
          tableSelector: props.tableSelector,
          value: props.value,
          getVirtualScrollerOption,
          ptCallbacks: props.ptCallbacks,
          metaData: props.metaData,
          unstyled: props.unstyled,
          findNextSelectableCell,
          findPrevSelectableCell,
          findDownSelectableCell,
          findUpSelectableCell,
          focusOnElement,
          focusOnInit,
          updateStickyPosition
        });
        return /* @__PURE__ */ React.createElement(Fragment, {
          key
        }, selectionMode ? /* @__PURE__ */ React.createElement(RadioCheckCell, cellProps) : /* @__PURE__ */ React.createElement(BodyCell, cellProps));
      }
      return null;
    });
  };
  var rowClassName = ObjectUtils.getPropValue(props.rowClassName, props.rowData, {
    props: props.tableProps
  });
  var style = {
    height: props.virtualScrollerOptions ? props.virtualScrollerOptions.itemSize : void 0
  };
  var content = createContent();
  var tabIndex = getTabIndex();
  var rowProps = mergeProps({
    role: "row",
    tabIndex,
    className: classNames(cx("bodyRow", {
      rowProps: props
    })),
    style,
    onMouseDown: function onMouseDown(e) {
      return _onMouseDown(e);
    },
    onMouseUp: function onMouseUp(e) {
      return _onMouseUp(e);
    },
    onMouseEnter: function onMouseEnter(e) {
      return _onMouseEnter(e);
    },
    onMouseLeave: function onMouseLeave(e) {
      return _onMouseLeave(e);
    },
    onClick: function onClick(e) {
      return _onClick(e);
    },
    onDoubleClick: function onDoubleClick(e) {
      return _onDoubleClick(e);
    },
    onPointerDown: function onPointerDown(e) {
      return _onPointerDown(e);
    },
    onPointerUp: function onPointerUp(e) {
      return _onPointerUp(e);
    },
    onContextMenu: function onContextMenu(e) {
      return onRightClick(e);
    },
    onTouchEnd: function onTouchEnd(e) {
      return _onTouchEnd(e);
    },
    onKeyDown: function onKeyDown(e) {
      return _onKeyDown(e);
    },
    onDragStart: function onDragStart(e) {
      return _onDragStart(e);
    },
    onDragOver: function onDragOver(e) {
      return _onDragOver(e);
    },
    onDragLeave: function onDragLeave(e) {
      return _onDragLeave(e);
    },
    onDragEnd: function onDragEnd(e) {
      return _onDragEnd(e);
    },
    onDrop: function onDrop(e) {
      return _onDrop(e);
    },
    "aria-selected": props !== null && props !== void 0 && props.selectionMode ? props.selected : null,
    "data-p-selectable-row": props.allowRowSelection && props.isSelectable({
      data: props.rowData,
      index: props.rowIndex
    }),
    "data-p-highlight": props.selected,
    "data-p-highlight-contextmenu": props.contextMenuSelected
  }, getBodyRowPTOptions("bodyRow"), {
    className: classNames(rowClassName)
    // #5983 must be last so all unstyled merging takes place first
  });
  return /* @__PURE__ */ React.createElement("tr", rowProps, content);
});
BodyRow.displayName = "BodyRow";
function ownKeys$9(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$9(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$9(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$9(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var RowTogglerButton = /* @__PURE__ */ React.memo(function(props) {
  var mergeProps = useMergeProps();
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  _props$ptCallbacks.isUnstyled;
  var _onClick = function onClick(event) {
    props.onClick({
      originalEvent: event,
      data: props.rowData
    });
  };
  var getColumnProps = function getColumnProps2() {
    return ColumnBase.getCProps(props.column);
  };
  var getColumnPTOptions = function getColumnPTOptions2(key) {
    var cProps = getColumnProps();
    var columnMetaData = {
      props: getColumnProps(),
      parent: props.metaData,
      hostName: props.hostName
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(cProps, key, columnMetaData));
  };
  var rowGroupTogglerIconProps = mergeProps({
    className: cx("rowGroupTogglerIcon"),
    "aria-hidden": true
  }, ptm("rowGroupTogglerIcon"), getColumnPTOptions("rowGroupTogglerIcon"));
  var icon = props.expanded ? props.expandedRowIcon || /* @__PURE__ */ React.createElement(ChevronDownIcon, rowGroupTogglerIconProps) : props.collapsedRowIcon || /* @__PURE__ */ React.createElement(ChevronRightIcon, rowGroupTogglerIconProps);
  var togglerIcon = IconUtils.getJSXIcon(icon, _objectSpread$9({}, rowGroupTogglerIconProps), {
    props
  });
  var label = props.expanded ? ariaLabel$2("collapseLabel") : ariaLabel$2("expandLabel");
  var rowGroupTogglerProps = mergeProps({
    type: "button",
    onClick: function onClick(e) {
      return _onClick(e);
    },
    className: cx("rowGroupToggler"),
    tabIndex: props.tabIndex,
    "aria-label": label
  }, ptm("rowGroupToggler"), getColumnPTOptions("rowGroupToggler"));
  return /* @__PURE__ */ React.createElement("button", rowGroupTogglerProps, togglerIcon, /* @__PURE__ */ React.createElement(Ripple, null));
});
RowTogglerButton.displayName = "RowTogglerButton";
var _excluded$2 = ["originalEvent"];
function ownKeys$8(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$8(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$8(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$8(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var TableBody = /* @__PURE__ */ React.memo(/* @__PURE__ */ React.forwardRef(function(props, ref) {
  var mergeProps = useMergeProps();
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx, isUnstyled = _props$ptCallbacks.isUnstyled;
  var _React$useState = React.useState({}), _React$useState2 = _slicedToArray(_React$useState, 2), rowGroupHeaderStyleObjectState = _React$useState2[0], setRowGroupHeaderStyleObjectState = _React$useState2[1];
  var getColumnProps = function getColumnProps2(column) {
    return ColumnBase.getCProps(column);
  };
  var cProps = getColumnProps(props.column);
  var colsProps = props.columns ? props.columns.map(function(col) {
    return getColumnProps(col);
  }) : [];
  var getColumnPTOptions = function getColumnPTOptions2(key) {
    var columnMetaData = {
      props: cProps,
      parent: props.metaData,
      hostName: props.hostName,
      state: {
        rowGroupHeaderStyleObject: rowGroupHeaderStyleObjectState
      }
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(cProps, key, columnMetaData));
  };
  var elementRef = React.useRef(null);
  var refCallback = React.useCallback(function(el) {
    elementRef.current = el;
    props.virtualScrollerContentRef && props.virtualScrollerContentRef(el);
  }, [props]);
  var dragSelectionHelper = React.useRef(null);
  var initialDragPosition = React.useRef(null);
  var anchorRowIndex = React.useRef(null);
  var anchorCellIndex = React.useRef(null);
  var rangeRowIndex = React.useRef(null);
  var anchorRowFirst = React.useRef(null);
  var rowTouched = React.useRef(false);
  var rowDragging = React.useRef(false);
  var draggedRowIndex = React.useRef(null);
  var droppedRowIndex = React.useRef(null);
  var isSubheaderGrouping = props.rowGroupMode && props.rowGroupMode === "subheader";
  var isRadioSelectionMode = props.selectionMode === "radiobutton";
  var isCheckboxSelectionMode = props.selectionMode === "checkbox";
  var isRadioSelectionModeInColumn = props.selectionModeInColumn === "single";
  var isCheckboxSelectionModeInColumn = props.selectionModeInColumn === "multiple";
  var equals = function equals2(data1, data2) {
    if (allowCellSelection()) {
      return (data1.rowIndex === data2.rowIndex || data1.rowData === data2.rowData) && (data1.field === data2.field || data1.cellIndex === data2.cellIndex);
    }
    return props.compareSelectionBy === "equals" ? data1 === data2 : ObjectUtils.equals(data1, data2, props.dataKey);
  };
  var isSelectionEnabled = function isSelectionEnabled2() {
    return props.selectionMode || props.selectionModeInColumn !== null || props.columns && props.columns.some(function(col) {
      return col && !!getColumnProp(col, "selectionMode");
    });
  };
  var isSingleSelection = function isSingleSelection2() {
    return props.selectionMode === "single" && !isCheckboxSelectionModeInColumn || !isRadioSelectionMode && isRadioSelectionModeInColumn;
  };
  var isMultipleSelection = function isMultipleSelection2() {
    return props.selectionMode === "multiple" && !isRadioSelectionModeInColumn || isCheckboxSelectionModeInColumn;
  };
  var isRadioOnlySelection = function isRadioOnlySelection2() {
    return isRadioSelectionMode && isRadioSelectionModeInColumn;
  };
  var isCheckboxOnlySelection = function isCheckboxOnlySelection2() {
    return isCheckboxSelectionMode && isCheckboxSelectionModeInColumn;
  };
  var isSelected = function isSelected2(rowData) {
    if (rowData && props.selection) {
      return props.selection instanceof Array ? findIndex(props.selection, rowData) > -1 : equals(rowData, props.selection);
    }
    return false;
  };
  var isContextMenuSelected = function isContextMenuSelected2(rowData) {
    if (rowData && props.contextMenuSelection) {
      return equals(rowData, props.contextMenuSelection);
    }
    return false;
  };
  var isSelectable = function isSelectable2(options) {
    return props.isDataSelectable ? props.isDataSelectable(options) : true;
  };
  var isRowExpanded = function isRowExpanded2(rowData) {
    if (rowData && props.expandedRows) {
      if (isSubheaderGrouping && props.expandableRowGroups) {
        return isRowGroupExpanded(rowData);
      }
      if (props.dataKey) {
        var rowId = ObjectUtils.resolveFieldData(rowData, props.dataKey);
        var expanded = false;
        if (props.expandedRows) {
          if (Array.isArray(props.expandedRows)) {
            expanded = props.expandedRows.some(function(row) {
              return ObjectUtils.resolveFieldData(row, props.dataKey) === rowId;
            });
          } else {
            expanded = props.expandedRows[rowId] !== void 0;
          }
        }
        return expanded;
      }
      return findIndex(props.expandedRows, rowData) !== -1;
    }
    return false;
  };
  var isRowGroupExpanded = function isRowGroupExpanded2(rowData) {
    if (props.dataKey === props.groupRowsBy) {
      return Object.keys(props.expandedRows).some(function(data) {
        return ObjectUtils.equals(data, ObjectUtils.resolveFieldData(rowData, props.dataKey));
      });
    }
    return props.expandedRows.some(function(data) {
      return ObjectUtils.equals(data, rowData, props.groupRowsBy);
    });
  };
  var isRowEditing = function isRowEditing2(rowData) {
    if (props.editMode === "row" && rowData && props.editingRows) {
      if (props.dataKey) {
        return props.editingRows ? props.editingRows[ObjectUtils.resolveFieldData(rowData, props.dataKey)] !== void 0 : false;
      }
      return findIndex(props.editingRows, rowData) !== -1;
    }
    return false;
  };
  var allowDrag = function allowDrag2(event) {
    return props.dragSelection && isMultipleSelection() && !event.originalEvent.shiftKey;
  };
  var allowRowDrag = function allowRowDrag2(event) {
    return !allowCellSelection() && allowDrag(event) || props.reorderableRows;
  };
  var allowCellDrag = function allowCellDrag2(event) {
    return allowCellSelection() && allowDrag(event);
  };
  var allowSelection = function allowSelection2(event) {
    return !DomHandler.isClickable(event.originalEvent.target);
  };
  var metaKeySelectionRef = React.useRef(props.metaKeySelection);
  React.useEffect(function() {
    metaKeySelectionRef.current = props.metaKeySelection;
  }, [props.metaKeySelection]);
  var allowMetaKeySelection = function allowMetaKeySelection2(event) {
    return !rowTouched.current && (!metaKeySelectionRef.current || metaKeySelectionRef.current && (event.originalEvent.metaKey || event.originalEvent.ctrlKey));
  };
  var allowRangeSelection = function allowRangeSelection2(event) {
    return isMultipleSelection() && event.originalEvent.shiftKey && anchorRowIndex.current !== null;
  };
  var allowRowSelection = function allowRowSelection2() {
    return (props.selectionMode || props.selectionModeInColumn) && !isRadioOnlySelection() && !isCheckboxOnlySelection();
  };
  var allowCellSelection = function allowCellSelection2() {
    return props.cellSelection && !isRadioSelectionModeInColumn && !isCheckboxSelectionModeInColumn;
  };
  var getColumnsLength = function getColumnsLength2() {
    return props.columns ? props.columns.length : 0;
  };
  var getColumnProp = function getColumnProp2(column, name) {
    return ColumnBase.getCProp(column, name);
  };
  var getVirtualScrollerOption = function getVirtualScrollerOption2(option, options) {
    options = options || props.virtualScrollerOptions;
    return options ? options[option] : null;
  };
  var findIndex = function findIndex2(collection, rowData) {
    return (collection || []).findIndex(function(data) {
      return equals(rowData, data);
    });
  };
  var rowGroupHeaderStyle = function rowGroupHeaderStyle2() {
    if (props.scrollable) {
      return {
        top: rowGroupHeaderStyleObjectState.top
      };
    }
    return null;
  };
  var getRowKey = function getRowKey2(rowData, index2) {
    return props.dataKey ? ObjectUtils.resolveFieldData(rowData, props.dataKey) : index2;
  };
  var shouldRenderRowGroupHeader = function shouldRenderRowGroupHeader2(value, rowData, i) {
    var currentRowFieldData = ObjectUtils.resolveFieldData(rowData, props.groupRowsBy);
    var prevRowData = value[i - 1];
    if (prevRowData) {
      var previousRowFieldData = ObjectUtils.resolveFieldData(prevRowData, props.groupRowsBy);
      return !ObjectUtils.deepEquals(currentRowFieldData, previousRowFieldData);
    }
    return true;
  };
  var shouldRenderRowGroupFooter = function shouldRenderRowGroupFooter2(value, rowData, i, expanded) {
    if (props.expandableRowGroups && !expanded) {
      return false;
    }
    var currentRowFieldData = ObjectUtils.resolveFieldData(rowData, props.groupRowsBy);
    var nextRowData = value[i + 1];
    if (nextRowData) {
      var nextRowFieldData = ObjectUtils.resolveFieldData(nextRowData, props.groupRowsBy);
      return !ObjectUtils.deepEquals(currentRowFieldData, nextRowFieldData);
    }
    return true;
  };
  var updateFrozenRowStickyPosition = function updateFrozenRowStickyPosition2() {
    elementRef.current.style.top = DomHandler.getOuterHeight(elementRef.current.previousElementSibling) + "px";
  };
  var updateFrozenRowGroupHeaderStickyPosition = function updateFrozenRowGroupHeaderStickyPosition2() {
    var tableHeaderHeight = DomHandler.getOuterHeight(elementRef.current.previousElementSibling);
    var top = tableHeaderHeight + "px";
    if (rowGroupHeaderStyleObjectState.top !== top) {
      setRowGroupHeaderStyleObjectState({
        top
      });
    }
  };
  var selectionRef = React.useRef(props.selection);
  React.useEffect(function() {
    selectionRef.current = props.selection;
  }, [props.selection]);
  var onSingleSelection = function onSingleSelection2(_ref) {
    var originalEvent = _ref.originalEvent, data = _ref.data, index2 = _ref.index, toggleable = _ref.toggleable, type = _ref.type;
    if (!isSelectable({
      data,
      index: index2
    })) {
      return;
    }
    var selected = isSelected(data);
    var currentSelection = selectionRef.current || [];
    var newSelection = currentSelection;
    if (selected) {
      if (toggleable) {
        newSelection = null;
        onUnselect({
          originalEvent,
          data,
          type
        });
      }
    } else {
      newSelection = data;
      onSelect({
        originalEvent,
        data,
        type
      });
    }
    focusOnElement(originalEvent, true);
    if (props.onSelectionChange && newSelection !== currentSelection) {
      props.onSelectionChange({
        originalEvent,
        value: newSelection,
        type
      });
    }
  };
  var onMultipleSelection = function onMultipleSelection2(_ref2) {
    var originalEvent = _ref2.originalEvent, data = _ref2.data, index2 = _ref2.index, toggleable = _ref2.toggleable, type = _ref2.type;
    if (!isSelectable({
      data,
      index: index2
    })) {
      return;
    }
    var selected = isSelected(data);
    var currentSelection = selectionRef.current || [];
    var newSelection = currentSelection;
    if (selected) {
      if (toggleable) {
        var selectionIndex = findIndex(currentSelection, data);
        newSelection = currentSelection.filter(function(val, i) {
          return i !== selectionIndex;
        });
        onUnselect({
          originalEvent,
          data,
          type
        });
      } else if (currentSelection.length) {
        currentSelection.forEach(function(d) {
          return onUnselect({
            originalEvent,
            data: d,
            type
          });
        });
        newSelection = [data];
        onSelect({
          originalEvent,
          data,
          type
        });
      }
    } else {
      newSelection = ObjectUtils.isObject(currentSelection) ? [currentSelection] : currentSelection;
      newSelection = toggleable && isMultipleSelection() ? [].concat(_toConsumableArray(newSelection), [data]) : [data];
      onSelect({
        originalEvent,
        data,
        type
      });
    }
    if (props.onSelectionChange && newSelection !== currentSelection) {
      props.onSelectionChange({
        originalEvent,
        value: newSelection,
        type
      });
    }
  };
  var onRangeSelection = function onRangeSelection2(event, type) {
    DomHandler.clearSelection();
    rangeRowIndex.current = allowCellSelection() ? event.rowIndex : event.index;
    var selection = selectRange(event);
    if (props.onSelectionChange && selection !== props.selection) {
      props.onSelectionChange({
        originalEvent: event.originalEvent,
        value: selection,
        type
      });
    }
    anchorRowIndex.current = rangeRowIndex.current;
    anchorCellIndex.current = event.cellIndex;
  };
  var selectRange = function selectRange2(event) {
    var rangeStart;
    var rangeEnd;
    var isAllowCellSelection = allowCellSelection();
    if (rangeRowIndex.current > anchorRowIndex.current) {
      rangeStart = anchorRowIndex.current;
      rangeEnd = rangeRowIndex.current;
    } else if (rangeRowIndex.current < anchorRowIndex.current) {
      rangeStart = rangeRowIndex.current;
      rangeEnd = anchorRowIndex.current;
    } else {
      rangeStart = rangeEnd = rangeRowIndex.current;
    }
    return isAllowCellSelection ? selectRangeOnCell(event, rangeStart, rangeEnd) : selectRangeOnRow(event, rangeStart, rangeEnd);
  };
  var selectRangeOnRow = function selectRangeOnRow2(event, rowRangeStart, rowRangeEnd) {
    var selection = [];
    for (var i = rowRangeStart; i <= rowRangeEnd; i++) {
      var rangeRowData = props.processedData[i];
      if (!isSelectable({
        data: rangeRowData,
        index: i
      })) {
        continue;
      }
      selection.push(rangeRowData);
      onSelect({
        originalEvent: event.originalEvent,
        data: rangeRowData,
        type: "row"
      });
    }
    return selection;
  };
  var selectRangeOnCell = function selectRangeOnCell2(event, rowRangeStart, rowRangeEnd) {
    var cellRangeStart;
    var cellRangeEnd;
    var cellIndex = event.cellIndex;
    if (cellIndex > anchorCellIndex.current) {
      cellRangeStart = anchorCellIndex.current;
      cellRangeEnd = cellIndex;
    } else if (cellIndex < anchorCellIndex.current) {
      cellRangeStart = cellIndex;
      cellRangeEnd = anchorCellIndex.current;
    } else {
      cellRangeStart = cellRangeEnd = cellIndex;
    }
    var value = props.value;
    var selection = [];
    for (var i = rowRangeStart; i <= rowRangeEnd; i++) {
      var rowData = value[i];
      var columns = props.columns;
      var rowIndex = props.paginator ? i + props.first : i;
      for (var j = cellRangeStart; j <= cellRangeEnd; j++) {
        var field = getColumnProp(columns[j], "field");
        var _value = ObjectUtils.resolveFieldData(rowData, field);
        var rangeRowData = {
          value: _value,
          field,
          rowData,
          rowIndex,
          cellIndex: j,
          selected: true
        };
        if (!isSelectable({
          data: rangeRowData,
          index: i
        })) {
          continue;
        }
        selection.push(rangeRowData);
        onSelect({
          originalEvent: event.originalEvent,
          data: rangeRowData,
          type: "cell"
        });
      }
    }
    return selection;
  };
  var onSelect = function onSelect2(event) {
    if (allowCellSelection()) {
      props.onCellSelect && props.onCellSelect(_objectSpread$8(_objectSpread$8({
        originalEvent: event.originalEvent
      }, event.data), {}, {
        type: event.type
      }));
    } else {
      props.onRowSelect && props.onRowSelect(event);
    }
  };
  var onUnselect = function onUnselect2(event) {
    if (allowCellSelection()) {
      props.onCellUnselect && props.onCellUnselect(_objectSpread$8(_objectSpread$8({
        originalEvent: event.originalEvent
      }, event.data), {}, {
        type: event.type
      }));
    } else {
      props.onRowUnselect && props.onRowUnselect(event);
    }
  };
  var enableDragSelection = function enableDragSelection2(event) {
    if (props.dragSelection && !dragSelectionHelper.current) {
      dragSelectionHelper.current = document.createElement("div");
      dragSelectionHelper.current.setAttribute("p-datatable-drag-selection-helper", "true");
      !isUnstyled() && DomHandler.addClass(dragSelectionHelper.current, "p-datatable-drag-selection-helper");
      initialDragPosition.current = {
        x: event.clientX,
        y: event.clientY
      };
      dragSelectionHelper.current.style.top = "".concat(event.pageY, "px");
      dragSelectionHelper.current.style.left = "".concat(event.pageX, "px");
      bindDragSelectionEvents();
    }
  };
  var focusOnElement = function focusOnElement2(event, isFocused) {
    var target = event.currentTarget;
    if (!allowCellSelection() && props.selectionAutoFocus) {
      if (isCheckboxSelectionModeInColumn) {
        var checkbox = DomHandler.findSingle(target, 'td[data-p-selection-column="true"] [data-pc-section="checkbox"]');
        checkbox && checkbox.focus();
      } else if (isRadioSelectionModeInColumn) {
        var radio = DomHandler.findSingle(target, 'td[data-p-selection-column="true"] input[type="radio"]');
        radio && radio.focus();
      }
    }
    !isFocused && target && target.focus();
  };
  var changeTabIndex2 = function changeTabIndex3(event, type) {
    var target = event.currentTarget;
    var isSelectable2 = DomHandler.getAttribute(target, type === "cell" ? "data-p-selectable-cell" : "data-p-selectable-row") === true;
    if (isSelectable2) {
      var selector = type === "cell" ? "tr > td" : "tr";
      var tabbableEl = DomHandler.findSingle(elementRef.current, "".concat(selector, '[tabindex="').concat(props.tabIndex, '"]'));
      if (tabbableEl && target) {
        tabbableEl.tabIndex = -1;
        target.tabIndex = props.tabIndex;
      }
    }
  };
  var onRowClick = function onRowClick2(event) {
    if (event.defaultPrevented || event.originalEvent && event.originalEvent.defaultPrevented || allowCellSelection() || !allowSelection(event)) {
      return;
    }
    props.onRowClick && props.onRowClick(event);
    if (allowRowSelection()) {
      if (allowRangeSelection(event)) {
        onRangeSelection(event, "row");
      } else {
        var toggleable = isRadioSelectionModeInColumn || isCheckboxSelectionModeInColumn || allowMetaKeySelection(event);
        anchorRowIndex.current = event.index;
        rangeRowIndex.current = event.index;
        anchorRowFirst.current = props.first;
        if (isSingleSelection()) {
          onSingleSelection(_objectSpread$8(_objectSpread$8({}, event), {}, {
            toggleable,
            type: "row"
          }));
        } else {
          onMultipleSelection(_objectSpread$8(_objectSpread$8({}, event), {}, {
            toggleable,
            type: "row"
          }));
        }
      }
      changeTabIndex2(event.originalEvent, "row");
    } else {
      focusOnElement(event.originalEvent);
    }
    rowTouched.current = false;
  };
  var onRowDoubleClick = function onRowDoubleClick2(e) {
    var event = e.originalEvent;
    if (DomHandler.isClickable(event.target)) {
      return;
    }
    if (props.onRowDoubleClick) {
      props.onRowDoubleClick(e);
    }
  };
  var onRowPointerDown = function onRowPointerDown2(e) {
    var event = e.originalEvent;
    if (DomHandler.isClickable(event.target)) {
      return;
    }
    if (props.onRowPointerDown) {
      props.onRowPointerDown(e);
    }
  };
  var onRowPointerUp = function onRowPointerUp2(e) {
    var event = e.originalEvent;
    if (DomHandler.isClickable(event.target)) {
      return;
    }
    if (props.onRowPointerUp) {
      props.onRowPointerUp(e);
    }
  };
  var onRowRightClick = function onRowRightClick2(event) {
    if (props.onContextMenu || props.onContextMenuSelectionChange) {
      var hasSelection = ObjectUtils.isNotEmpty(props.selection);
      var data = event.data;
      if (hasSelection) {
        DomHandler.clearSelection();
      }
      if (props.onContextMenuSelectionChange) {
        props.onContextMenuSelectionChange({
          originalEvent: event.originalEvent,
          value: data,
          index: event.index
        });
      }
      if (props.onContextMenu) {
        props.onContextMenu({
          originalEvent: event.originalEvent,
          data,
          index: event.index
        });
      }
      event.originalEvent.preventDefault();
    }
  };
  var onRowMouseEnter = function onRowMouseEnter2(event) {
    props.onRowMouseEnter && props.onRowMouseEnter(event);
  };
  var onRowMouseLeave = function onRowMouseLeave2(event) {
    props.onRowMouseLeave && props.onRowMouseLeave(event);
  };
  var onRowTouchEnd = function onRowTouchEnd2() {
    rowTouched.current = true;
  };
  var onRowMouseDown = function onRowMouseDown2(e) {
    var event = e.originalEvent;
    var isDraggableHandle = isUnstyled() ? DomHandler.getAttribute(event.target, "data-pc-section") === "rowreordericon" || event.target.closest('[data-pc-section="rowreordericon"]') : DomHandler.hasClass(event.target, "p-datatable-reorderablerow-handle") || event.target.closest(".p-datatable-reorderablerow-handle");
    event.currentTarget.draggable = isDraggableHandle;
    if (allowRowDrag(e)) {
      enableDragSelection(event);
      anchorRowIndex.current = e.index;
      rangeRowIndex.current = e.index;
      anchorRowFirst.current = props.first;
    }
  };
  var onRowMouseUp = function onRowMouseUp2(event) {
    var isSameRow = event.index === anchorRowIndex.current;
    if (allowRowDrag(event) && !isSameRow) {
      onRangeSelection(event, "row");
    }
  };
  var expandedRowsRef = React.useRef(props.expandedRows);
  React.useEffect(function() {
    expandedRowsRef.current = props.expandedRows;
  }, [props.expandedRows]);
  var onRowToggle = function onRowToggle2(event) {
    var expandedRows;
    var dataKey = props.dataKey;
    var hasDataKey = props.groupRowsBy ? dataKey === props.groupRowsBy : !!dataKey;
    if (hasDataKey) {
      var dataKeyValue = String(ObjectUtils.resolveFieldData(event.data, dataKey));
      expandedRows = expandedRowsRef.current ? _objectSpread$8({}, expandedRowsRef.current) : {};
      if (expandedRows[dataKeyValue] != null) {
        delete expandedRows[dataKeyValue];
        if (props.onRowCollapse) {
          props.onRowCollapse({
            originalEvent: event,
            data: event.data
          });
        }
      } else {
        expandedRows[dataKeyValue] = true;
        if (props.onRowExpand) {
          props.onRowExpand({
            originalEvent: event,
            data: event.data
          });
        }
      }
    } else {
      var expandedRowIndex = findIndex(expandedRowsRef.current, event.data);
      expandedRows = expandedRowsRef.current ? _toConsumableArray(expandedRowsRef.current) : [];
      if (expandedRowIndex !== -1) {
        expandedRows = expandedRows.filter(function(_, i) {
          return i !== expandedRowIndex;
        });
        if (props.onRowCollapse) {
          props.onRowCollapse({
            originalEvent: event,
            data: event.data
          });
        }
      } else {
        expandedRows.push(event.data);
        if (props.onRowExpand) {
          props.onRowExpand({
            originalEvent: event,
            data: event.data
          });
        }
      }
    }
    if (props.onRowToggle) {
      props.onRowToggle({
        data: expandedRows
      });
    }
  };
  var onRowDragStart = function onRowDragStart2(e) {
    var event = e.originalEvent, index2 = e.index;
    if (allowRowDrag(e)) {
      rowDragging.current = true;
      draggedRowIndex.current = index2;
      event.dataTransfer.setData("text", "b");
    }
  };
  var onRowDragOver = function onRowDragOver2(e) {
    var event = e.originalEvent, index2 = e.index;
    if (!rowDragging.current) {
      return;
    }
    if (draggedRowIndex.current !== index2) {
      var rowElement = event.currentTarget;
      var rowY = DomHandler.getOffset(rowElement).top + DomHandler.getWindowScrollTop();
      var pageY = event.pageY + window.scrollY;
      var rowMidY = rowY + DomHandler.getOuterHeight(rowElement) / 2;
      var prevRowElement = rowElement.previousElementSibling;
      if (pageY < rowMidY) {
        rowElement.setAttribute("data-p-datatable-dragpoint-bottom", "false");
        !isUnstyled() && DomHandler.removeClass(rowElement, "p-datatable-dragpoint-bottom");
        droppedRowIndex.current = index2;
        if (prevRowElement) {
          prevRowElement.setAttribute("data-p-datatable-dragpoint-bottom", "true");
          !isUnstyled() && DomHandler.addClass(prevRowElement, "p-datatable-dragpoint-bottom");
        } else {
          rowElement.setAttribute("data-p-datatable-dragpoint-top", "true");
          !isUnstyled() && DomHandler.addClass(rowElement, "p-datatable-dragpoint-top");
        }
      } else {
        if (prevRowElement) {
          prevRowElement.setAttribute("data-p-datatable-dragpoint-bottom", "false");
          !isUnstyled() && DomHandler.removeClass(prevRowElement, "p-datatable-dragpoint-bottom");
        } else {
          rowElement.setAttribute("data-p-datatable-dragpoint-top", "true");
          !isUnstyled() && DomHandler.addClass(rowElement, "p-datatable-dragpoint-top");
        }
        if (index2 + 1 !== draggedRowIndex.current) droppedRowIndex.current = index2 + 1;
        rowElement.setAttribute("data-p-datatable-dragpoint-bottom", "true");
        !isUnstyled() && DomHandler.addClass(rowElement, "p-datatable-dragpoint-bottom");
      }
    }
    event.preventDefault();
  };
  var onRowDragLeave = function onRowDragLeave2(e) {
    var event = e.originalEvent;
    var rowElement = event.currentTarget;
    var prevRowElement = rowElement.previousElementSibling;
    if (prevRowElement) {
      prevRowElement.setAttribute("data-p-datatable-dragpoint-bottom", "false");
      !isUnstyled() && DomHandler.removeClass(prevRowElement, "p-datatable-dragpoint-bottom");
    }
    rowElement.setAttribute("data-p-datatable-dragpoint-bottom", "false");
    !isUnstyled() && DomHandler.removeClass(rowElement, "p-datatable-dragpoint-bottom");
    rowElement.setAttribute("data-p-datatable-dragpoint-top", "false");
    !isUnstyled() && DomHandler.removeClass(rowElement, "p-datatable-dragpoint-top");
  };
  var onRowDragEnd = function onRowDragEnd2(e) {
    var event = e.originalEvent;
    rowDragging.current = false;
    draggedRowIndex.current = null;
    droppedRowIndex.current = null;
    event.currentTarget.draggable = false;
  };
  var onRowDrop = function onRowDrop2(e) {
    var event = e.originalEvent;
    if (droppedRowIndex.current != null) {
      var dropIndex = draggedRowIndex.current > droppedRowIndex.current ? droppedRowIndex.current : droppedRowIndex.current === 0 ? 0 : droppedRowIndex.current - 1;
      var val = _toConsumableArray(props.tableProps.value);
      ObjectUtils.reorderArray(val, draggedRowIndex.current, dropIndex);
      if (props.onRowReorder) {
        props.onRowReorder({
          originalEvent: event,
          value: val,
          dragIndex: draggedRowIndex.current,
          dropIndex
        });
      }
    }
    onRowDragLeave(e);
    onRowDragEnd(e);
    event.preventDefault();
  };
  var onRadioChange = function onRadioChange2(event) {
    onSingleSelection(_objectSpread$8(_objectSpread$8({}, event), {}, {
      toggleable: true,
      type: "radio"
    }));
  };
  var onCheckboxChange = function onCheckboxChange2(event) {
    onMultipleSelection(_objectSpread$8(_objectSpread$8({}, event), {}, {
      toggleable: true,
      type: "checkbox"
    }));
  };
  var onDragSelectionMouseMove = function onDragSelectionMouseMove2(event) {
    var _initialDragPosition$ = initialDragPosition.current, x = _initialDragPosition$.x, y = _initialDragPosition$.y;
    var dx = event.clientX - x;
    var dy = event.clientY - y;
    if (dy < 0) {
      dragSelectionHelper.current.style.top = "".concat(event.pageY + 5, "px");
    }
    if (dx < 0) {
      dragSelectionHelper.current.style.left = "".concat(event.pageX + 5, "px");
    }
    dragSelectionHelper.current.style.height = "".concat(Math.abs(dy), "px");
    dragSelectionHelper.current.style.width = "".concat(Math.abs(dx), "px");
    event.preventDefault();
  };
  var _onDragSelectionMouseUp = function onDragSelectionMouseUp() {
    if (dragSelectionHelper.current) {
      dragSelectionHelper.current.remove();
      dragSelectionHelper.current = null;
    }
    document.removeEventListener("mousemove", onDragSelectionMouseMove);
    document.removeEventListener("mouseup", _onDragSelectionMouseUp);
  };
  var onCellClick = function onCellClick2(event) {
    if (!allowSelection(event)) {
      return;
    }
    props.onCellClick && props.onCellClick(event);
    if (allowCellSelection()) {
      if (allowRangeSelection(event)) {
        onRangeSelection(event, "cell");
      } else {
        var toggleable = allowMetaKeySelection(event);
        var originalEvent = event.originalEvent, data = _objectWithoutProperties(event, _excluded$2);
        anchorRowIndex.current = event.rowIndex;
        rangeRowIndex.current = event.rowIndex;
        anchorRowFirst.current = props.first;
        anchorCellIndex.current = event.cellIndex;
        if (isSingleSelection()) {
          onSingleSelection({
            originalEvent,
            data,
            index: event.rowIndex,
            toggleable,
            type: "cell"
          });
        } else {
          onMultipleSelection({
            originalEvent,
            data,
            index: event.rowIndex,
            toggleable,
            type: "cell"
          });
        }
      }
      changeTabIndex2(event.originalEvent, "cell");
    }
    rowTouched.current = false;
  };
  var onCellMouseDown = function onCellMouseDown2(event) {
    if (allowCellDrag(event)) {
      enableDragSelection(event.originalEvent);
      anchorRowIndex.current = event.rowIndex;
      rangeRowIndex.current = event.rowIndex;
      anchorRowFirst.current = props.first;
      anchorCellIndex.current = event.cellIndex;
    }
  };
  var onCellMouseUp = function onCellMouseUp2(event) {
    var isSameCell = event.rowIndex === anchorRowIndex.current && event.cellIndex === anchorCellIndex.current;
    if (allowCellDrag(event) && !isSameCell) {
      onRangeSelection(event, "cell");
    }
  };
  var bindDragSelectionEvents = function bindDragSelectionEvents2() {
    document.addEventListener("mousemove", onDragSelectionMouseMove);
    document.addEventListener("mouseup", _onDragSelectionMouseUp);
    document.body.appendChild(dragSelectionHelper.current);
  };
  var unbindDragSelectionEvents = function unbindDragSelectionEvents2() {
    _onDragSelectionMouseUp();
  };
  React.useEffect(function() {
    if (props.frozenRow) {
      updateFrozenRowStickyPosition();
    }
    if (props.scrollable && props.rowGroupMode === "subheader") {
      updateFrozenRowGroupHeaderStickyPosition();
    }
  });
  useUpdateEffect(function() {
    if (props.paginator && isMultipleSelection()) {
      anchorRowIndex.current = null;
    }
  }, [props.first]);
  useUnmountEffect(function() {
    if (props.dragSelection) {
      unbindDragSelectionEvents();
    }
  });
  var createEmptyContent = function createEmptyContent2() {
    var colSpan = getColumnsLength();
    var content2 = ObjectUtils.getJSXElement(props.emptyMessage, {
      props: props.tableProps,
      frozen: props.frozenRow
    }) || localeOption("emptyMessage");
    var emptyMessageProps = mergeProps({
      className: cx("emptyMessage"),
      role: "row"
    }, ptm("emptyMessage"));
    var bodyCellProps = mergeProps({
      colSpan,
      role: "cell"
    }, getColumnPTOptions("root"), getColumnPTOptions("bodyCell"));
    return /* @__PURE__ */ React.createElement("tr", emptyMessageProps, /* @__PURE__ */ React.createElement("td", bodyCellProps, content2));
  };
  var createGroupHeader = function createGroupHeader2(rowData, rowIndex, expanded, colSpan) {
    if (isSubheaderGrouping && shouldRenderRowGroupHeader(props.value, rowData, rowIndex - props.first)) {
      var style = rowGroupHeaderStyle();
      var toggler = props.expandableRowGroups && /* @__PURE__ */ React.createElement(RowTogglerButton, {
        hostName: props.hostName,
        onClick: onRowToggle,
        rowData,
        expanded,
        expandedRowIcon: props.expandedRowIcon,
        collapsedRowIcon: props.collapsedRowIcon,
        ptCallbacks: props.ptCallbacks,
        metaData: props.metaData,
        unstyled: isUnstyled()
      });
      var options = {
        index: rowIndex,
        props: props.tableProps,
        customRendering: false
      };
      var _content = ObjectUtils.getJSXElement(props.rowGroupHeaderTemplate, rowData, options);
      if (!options.customRendering) {
        var bodyCellProps = mergeProps({
          colSpan
        }, getColumnPTOptions("root"), getColumnPTOptions("bodyCell"));
        var rowGroupHeaderNameProps = mergeProps({
          className: cx("rowGroupHeaderName")
        }, ptm("rowGroupHeaderName"));
        _content = /* @__PURE__ */ React.createElement("td", bodyCellProps, toggler, /* @__PURE__ */ React.createElement("span", rowGroupHeaderNameProps, _content));
      }
      var rowGroupHeaderProps = mergeProps({
        className: cx("rowGroupHeader"),
        style,
        role: "row"
      }, ptm("rowGroupHeader"));
      return /* @__PURE__ */ React.createElement("tr", rowGroupHeaderProps, _content);
    }
    return null;
  };
  var createRow = function createRow2(rowData, rowIndex, index2, expanded) {
    if (!props.expandableRowGroups || expanded) {
      var selected = isSelectionEnabled() ? isSelected(rowData) : false;
      var contextMenuSelected = isContextMenuSelected(rowData);
      var _allowRowSelection = allowRowSelection();
      var _allowCellSelection = allowCellSelection();
      var editing = isRowEditing(rowData);
      return /* @__PURE__ */ React.createElement(BodyRow, {
        hostName: props.hostName,
        allowCellSelection: _allowCellSelection,
        allowRowSelection: _allowRowSelection,
        cellMemo: props.cellMemo,
        cellMemoProps: props.cellMemoProps,
        cellMemoPropsDepth: props.cellMemoPropsDepth,
        cellClassName: props.cellClassName,
        checkIcon: props.checkIcon,
        collapsedRowIcon: props.collapsedRowIcon,
        columns: props.columns,
        colsProps,
        compareSelectionBy: props.compareSelectionBy,
        contextMenuSelected,
        dataKey: props.dataKey,
        editMode: props.editMode,
        editing,
        editingMeta: props.editingMeta,
        editingRows: props.editingRows,
        expanded,
        expandedRowIcon: props.expandedRowIcon,
        frozenRow: props.frozenRow,
        groupRowsBy: props.groupRowsBy,
        index: index2,
        isSelectable,
        onCellClick,
        onCellMouseDown,
        onCellMouseUp,
        onCheckboxChange,
        onEditingMetaChange: props.onEditingMetaChange,
        onRadioChange,
        onRowClick,
        onRowDoubleClick,
        onRowPointerDown,
        onRowPointerUp,
        onRowDragEnd,
        onRowDragLeave,
        onRowDragOver,
        onRowDragStart,
        onRowDrop,
        onRowEditCancel: props.onRowEditCancel,
        onRowEditChange: props.onRowEditChange,
        onRowEditComplete: props.onRowEditComplete,
        onRowEditInit: props.onRowEditInit,
        onRowEditSave: props.onRowEditSave,
        onRowMouseDown,
        onRowMouseEnter,
        onRowMouseLeave,
        onRowMouseUp,
        onRowRightClick,
        onRowToggle,
        onRowTouchEnd,
        responsiveLayout: props.responsiveLayout,
        rowClassName: props.rowClassName,
        rowData,
        rowEditValidator: props.rowEditValidator,
        rowEditorCancelIcon: props.rowEditorCancelIcon,
        rowEditorInitIcon: props.rowEditorInitIcon,
        rowEditorSaveIcon: props.rowEditorSaveIcon,
        rowGroupMode: props.rowGroupMode,
        rowIndex,
        selectOnEdit: props.selectOnEdit,
        selected,
        selection: props.selection,
        selectionMode: props.selectionMode,
        selectionModeInColumn: props.selectionModeInColumn,
        showRowReorderElement: props.showRowReorderElement,
        showSelectionElement: props.showSelectionElement,
        tabIndex: props.tabIndex,
        tableProps: props.tableProps,
        tableSelector: props.tableSelector,
        value: props.value,
        virtualScrollerOptions: props.virtualScrollerOptions,
        ptCallbacks: props.ptCallbacks,
        metaData: props.metaData,
        unstyled: isUnstyled()
      });
    }
  };
  var createExpansion = function createExpansion2(rowData, rowIndex, expanded, colSpan) {
    if (expanded && !(isSubheaderGrouping && props.expandableRowGroups)) {
      var id = "".concat(props.tableSelector, "_content_").concat(rowIndex, "_expanded");
      var options = {
        index: rowIndex,
        customRendering: false
      };
      var _content2 = ObjectUtils.getJSXElement(props.rowExpansionTemplate, rowData, options);
      if (!options.customRendering) {
        var bodyCellProps = mergeProps({
          colSpan,
          role: "cell"
        }, getColumnPTOptions("root"), getColumnPTOptions("bodyCell"));
        _content2 = /* @__PURE__ */ React.createElement("td", bodyCellProps, _content2);
      }
      var rowExpansionProps = mergeProps({
        id,
        className: cx("rowExpansion"),
        role: "row"
      }, ptm("rowExpansion"));
      return /* @__PURE__ */ React.createElement("tr", rowExpansionProps, _content2);
    }
    return null;
  };
  var createGroupFooter = function createGroupFooter2(rowData, rowIndex, expanded, colSpan) {
    if (isSubheaderGrouping && shouldRenderRowGroupFooter(props.value, rowData, rowIndex - props.first, expanded)) {
      var _content3 = ObjectUtils.getJSXElement(props.rowGroupFooterTemplate, rowData, {
        index: rowIndex,
        colSpan,
        props: props.tableProps
      });
      var rowGroupFooterProps = mergeProps({
        className: cx("rowGroupFooter"),
        role: "row"
      }, ptm("rowGroupFooter"));
      return /* @__PURE__ */ React.createElement("tr", rowGroupFooterProps, _content3);
    }
    return null;
  };
  var createContent = function createContent2() {
    return props.value && props.value.map(function(rowData, index2) {
      var rowIndex = getVirtualScrollerOption("getItemOptions") ? getVirtualScrollerOption("getItemOptions")(index2).index : props.first + index2;
      var key = getRowKey(rowData, rowIndex);
      var expanded = isRowExpanded(rowData);
      var colSpan = getColumnsLength();
      var groupHeader = createGroupHeader(rowData, rowIndex, expanded, colSpan);
      var row = createRow(rowData, rowIndex, index2, expanded);
      var expansion = createExpansion(rowData, rowIndex, expanded, colSpan);
      var groupFooter = createGroupFooter(rowData, rowIndex, expanded, colSpan);
      return /* @__PURE__ */ React.createElement(React.Fragment, {
        key
      }, groupHeader, row, expansion, groupFooter);
    });
  };
  var content = props.empty ? createEmptyContent() : createContent();
  var ptKey = props.className === "p-datatable-virtualscroller-spacer" ? "virtualScrollerSpacer" : "tbody";
  var tbodyProps = mergeProps({
    style: props.style,
    className: cx(ptKey, {
      className: props.className
    }),
    role: " rowgroup"
  }, ptm(ptKey, {
    hostName: props.hostName
  }));
  return /* @__PURE__ */ React.createElement("tbody", _extends({
    ref: refCallback
  }, tbodyProps), content);
}));
TableBody.displayName = "TableBody";
var ColumnGroupBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "ColumnGroup",
    children: void 0
  },
  getCProp: function getCProp2(group, name) {
    return ObjectUtils.getComponentProp(group, name, ColumnGroupBase.defaultProps);
  },
  getCProps: function getCProps2(group) {
    return ObjectUtils.getComponentProps(group, ColumnGroupBase.defaultProps);
  }
});
var RowBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "Row",
    style: null,
    className: null,
    children: void 0
  },
  getCProp: function getCProp3(row, name) {
    return ObjectUtils.getComponentProp(row, name, RowBase.defaultProps);
  }
});
function ownKeys$7(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$7(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$7(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$7(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var FooterCell = /* @__PURE__ */ React.memo(function(props) {
  var _React$useState = React.useState({}), _React$useState2 = _slicedToArray(_React$useState, 2), styleObjectState = _React$useState2[0], setStyleObjectState = _React$useState2[1];
  var elementRef = React.useRef(null);
  var mergeProps = useMergeProps();
  var getColumnProps = function getColumnProps2() {
    return ColumnBase.getCProps(props.column);
  };
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  var getColumnPTOptions = function getColumnPTOptions2(key) {
    var cProps = getColumnProps();
    var columnMetaData = {
      props: cProps,
      parent: props.metaData,
      hostName: props.hostName,
      state: {
        styleObject: styleObjectState
      },
      context: {
        index: props.index,
        size: props.metaData.props.size,
        showGridlines: props.metaData.props.showGridlines
      }
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(cProps, key, columnMetaData));
  };
  var getColumnProp = function getColumnProp2(name) {
    return ColumnBase.getCProp(props.column, name);
  };
  var getStyle = function getStyle2() {
    var footerStyle = getColumnProp("footerStyle");
    var columnStyle = getColumnProp("style");
    return getColumnProp("frozen") ? Object.assign({}, columnStyle, footerStyle, styleObjectState) : Object.assign({}, columnStyle, footerStyle);
  };
  var updateStickyPosition = function updateStickyPosition2() {
    if (getColumnProp("frozen")) {
      var styleObject = _objectSpread$7({}, styleObjectState);
      var _align = getColumnProp("alignFrozen");
      if (_align === "right") {
        var right = 0;
        var next = elementRef.current && elementRef.current.nextElementSibling;
        if (next && next.classList.contains("p-frozen-column")) {
          right = DomHandler.getOuterWidth(next) + parseFloat(next.style.right || 0);
        }
        styleObject.right = right + "px";
      } else {
        var left = 0;
        var prev = elementRef.current && elementRef.current.previousElementSibling;
        while (prev) {
          if (prev && prev.classList.contains("p-frozen-column")) {
            left = DomHandler.getOuterWidth(prev) + parseFloat(prev.style.left || 0);
            elementRef.current.style.left = left + "px";
            break;
          }
          prev = prev.previousElementSibling;
        }
        styleObject.left = left + "px";
      }
      var isSameStyle = styleObjectState.left === styleObject.left && styleObjectState.right === styleObject.right;
      !isSameStyle && setStyleObjectState(styleObject);
    }
  };
  React.useEffect(function() {
    if (getColumnProp("frozen")) {
      updateStickyPosition();
    }
  });
  var style = getStyle();
  var align = getColumnProp("align");
  var colSpan = getColumnProp("colSpan");
  var rowSpan = getColumnProp("rowSpan");
  var content = ObjectUtils.getJSXElement(getColumnProp("footer"), {
    props: props.tableProps
  });
  var footerCellProps = mergeProps({
    style,
    className: classNames(getColumnProp("footerClassName"), getColumnProp("className"), cx("footerCell", {
      getColumnProp,
      align
    })),
    role: "cell",
    colSpan,
    rowSpan
  }, getColumnPTOptions("root"), getColumnPTOptions("footerCell"));
  return /* @__PURE__ */ React.createElement("td", _extends({
    ref: elementRef
  }, footerCellProps), content);
});
FooterCell.displayName = "FooterCell";
var _excluded$1 = ["unstyled", "__TYPE", "ptOptions"];
function ownKeys$6(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$6(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$6(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$6(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var TableFooter = /* @__PURE__ */ React.memo(function(props) {
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  var mergeProps = useMergeProps();
  var getRowProps = function getRowProps2(row) {
    return ColumnGroupBase.getCProps(row);
  };
  var context = React.useContext(PrimeReactContext);
  var getColumnGroupProps = function getColumnGroupProps2() {
    return props.footerColumnGroup ? ColumnGroupBase.getCProps(props.footerColumnGroup) : void 0;
  };
  var getRowPTOptions = function getRowPTOptions2(row, key) {
    var rProps = getRowProps(row);
    var rowMetaData = {
      props: rProps,
      parent: props.metaData,
      hostName: props.hostName
    };
    return mergeProps(ptm("row.".concat(key), {
      row: rowMetaData
    }), ptm("row.".concat(key), rowMetaData), ptmo(rProps, key, rowMetaData));
  };
  var getColumnGroupPTOptions = function getColumnGroupPTOptions2(key) {
    var cGProps = getColumnGroupProps();
    var columnGroupMetaData = {
      props: getColumnGroupProps(),
      parent: props.metaData,
      hostName: props.hostName
    };
    return mergeProps(ptm("columnGroup.".concat(key), {
      columnGroup: columnGroupMetaData
    }), ptm("columnGroup.".concat(key), columnGroupMetaData), ptmo(cGProps, key, columnGroupMetaData));
  };
  var hasFooter = function hasFooter2() {
    return props.footerColumnGroup ? true : props.columns ? props.columns.some(function(col) {
      return col && getColumnProp(col, "footer");
    }) : false;
  };
  var getColumnProp = function getColumnProp2(column, name) {
    return ColumnBase.getCProp(column, name);
  };
  var createGroupFooterCells = function createGroupFooterCells2(row) {
    var columns = React.Children.toArray(RowBase.getCProp(row, "children"));
    return createFooterCells(columns);
  };
  var createFooterCells = function createFooterCells2(columns) {
    return React.Children.map(columns, function(col, i) {
      var isVisible = col ? !getColumnProp(col, "hidden") : true;
      var key = col ? getColumnProp(col, "columnKey") || getColumnProp(col, "field") || i : i;
      return isVisible && /* @__PURE__ */ React.createElement(FooterCell, {
        hostName: props.hostName,
        key,
        tableProps: props.tableProps,
        column: col,
        ptCallbacks: props.ptCallbacks,
        metaData: props.metaData
      });
    });
  };
  var createContent = function createContent2() {
    if (props.footerColumnGroup) {
      var rows = React.Children.toArray(ColumnGroupBase.getCProp(props.footerColumnGroup, "children"));
      return rows.map(function(row, i) {
        var _RowBase$getProps = RowBase.getProps(row.props, context), unstyled = _RowBase$getProps.unstyled;
        _RowBase$getProps.__TYPE;
        _RowBase$getProps.ptOptions;
        var rest = _objectWithoutProperties(_RowBase$getProps, _excluded$1);
        var rootProps = mergeProps({
          role: "row"
        }, unstyled ? _objectSpread$6({
          unstyled
        }, rest) : rest, getRowPTOptions(row, "root"));
        return /* @__PURE__ */ React.createElement("tr", _extends({}, rootProps, {
          key: i
        }), createGroupFooterCells(row));
      });
    }
    var footerRowProps = mergeProps({
      role: "row"
    }, ptm("footerRow", {
      hostName: props.hostName
    }));
    return /* @__PURE__ */ React.createElement("tr", footerRowProps, createFooterCells(props.columns));
  };
  if (hasFooter()) {
    var content = createContent();
    var tfootProps = mergeProps({
      className: cx("tfoot"),
      role: "rowgroup"
    }, getColumnGroupPTOptions("root"), ptm("tfoot", {
      hostName: props.hostName
    }));
    return /* @__PURE__ */ React.createElement("tfoot", tfootProps, content);
  }
  return null;
});
TableFooter.displayName = "TableFooter";
function _createClass(e, r, t) {
  return Object.defineProperty(e, "prototype", {
    writable: false
  }), e;
}
function _classCallCheck(a, n) {
  if (!(a instanceof n)) throw new TypeError("Cannot call a class as a function");
}
var FilterMatchMode = Object.freeze({
  STARTS_WITH: "startsWith",
  CONTAINS: "contains",
  NOT_CONTAINS: "notContains",
  ENDS_WITH: "endsWith",
  EQUALS: "equals",
  NOT_EQUALS: "notEquals",
  IN: "in",
  NOT_IN: "notIn",
  LESS_THAN: "lt",
  LESS_THAN_OR_EQUAL_TO: "lte",
  GREATER_THAN: "gt",
  GREATER_THAN_OR_EQUAL_TO: "gte",
  BETWEEN: "between",
  DATE_IS: "dateIs",
  DATE_IS_NOT: "dateIsNot",
  DATE_BEFORE: "dateBefore",
  DATE_AFTER: "dateAfter",
  CUSTOM: "custom"
});
var PrimeReact2 = /* @__PURE__ */ _createClass(function PrimeReact3() {
  _classCallCheck(this, PrimeReact3);
});
_defineProperty(PrimeReact2, "ripple", false);
_defineProperty(PrimeReact2, "inputStyle", "outlined");
_defineProperty(PrimeReact2, "locale", "en");
_defineProperty(PrimeReact2, "appendTo", null);
_defineProperty(PrimeReact2, "cssTransition", true);
_defineProperty(PrimeReact2, "autoZIndex", true);
_defineProperty(PrimeReact2, "hideOverlaysOnDocumentScrolling", false);
_defineProperty(PrimeReact2, "nonce", null);
_defineProperty(PrimeReact2, "nullSortOrder", 1);
_defineProperty(PrimeReact2, "zIndex", {
  modal: 1100,
  overlay: 1e3,
  menu: 1e3,
  tooltip: 1100,
  toast: 1200
});
_defineProperty(PrimeReact2, "pt", void 0);
_defineProperty(PrimeReact2, "filterMatchModeOptions", {
  text: [FilterMatchMode.STARTS_WITH, FilterMatchMode.CONTAINS, FilterMatchMode.NOT_CONTAINS, FilterMatchMode.ENDS_WITH, FilterMatchMode.EQUALS, FilterMatchMode.NOT_EQUALS],
  numeric: [FilterMatchMode.EQUALS, FilterMatchMode.NOT_EQUALS, FilterMatchMode.LESS_THAN, FilterMatchMode.LESS_THAN_OR_EQUAL_TO, FilterMatchMode.GREATER_THAN, FilterMatchMode.GREATER_THAN_OR_EQUAL_TO],
  date: [FilterMatchMode.DATE_IS, FilterMatchMode.DATE_IS_NOT, FilterMatchMode.DATE_BEFORE, FilterMatchMode.DATE_AFTER]
});
_defineProperty(PrimeReact2, "changeTheme", function(currentTheme, newTheme, linkElementId, callback) {
  var _linkElement$parentNo;
  var linkElement = document.getElementById(linkElementId);
  if (!linkElement) {
    throw Error("Element with id ".concat(linkElementId, " not found."));
  }
  var newThemeUrl = linkElement.getAttribute("href").replace(currentTheme, newTheme);
  var newLinkElement = document.createElement("link");
  newLinkElement.setAttribute("rel", "stylesheet");
  newLinkElement.setAttribute("id", linkElementId);
  newLinkElement.setAttribute("href", newThemeUrl);
  newLinkElement.addEventListener("load", function() {
    if (callback) {
      callback();
    }
  });
  (_linkElement$parentNo = linkElement.parentNode) === null || _linkElement$parentNo === void 0 || _linkElement$parentNo.replaceChild(newLinkElement, linkElement);
});
var locales = {
  en: {
    accept: "Yes",
    addRule: "Add Rule",
    am: "AM",
    apply: "Apply",
    cancel: "Cancel",
    choose: "Choose",
    chooseDate: "Choose Date",
    chooseMonth: "Choose Month",
    chooseYear: "Choose Year",
    clear: "Clear",
    completed: "Completed",
    contains: "Contains",
    custom: "Custom",
    dateAfter: "Date is after",
    dateBefore: "Date is before",
    dateFormat: "mm/dd/yy",
    dateIs: "Date is",
    dateIsNot: "Date is not",
    dayNames: ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"],
    dayNamesMin: ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"],
    dayNamesShort: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
    emptyFilterMessage: "No results found",
    emptyMessage: "No available options",
    emptySearchMessage: "No results found",
    emptySelectionMessage: "No selected item",
    endsWith: "Ends with",
    equals: "Equals",
    fileChosenMessage: "{0} files",
    fileSizeTypes: ["B", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"],
    filter: "Filter",
    firstDayOfWeek: 0,
    gt: "Greater than",
    gte: "Greater than or equal to",
    lt: "Less than",
    lte: "Less than or equal to",
    matchAll: "Match All",
    matchAny: "Match Any",
    medium: "Medium",
    monthNames: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
    monthNamesShort: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
    nextDecade: "Next Decade",
    nextHour: "Next Hour",
    nextMinute: "Next Minute",
    nextMonth: "Next Month",
    nextSecond: "Next Second",
    nextYear: "Next Year",
    noFileChosenMessage: "No file chosen",
    noFilter: "No Filter",
    notContains: "Not contains",
    notEquals: "Not equals",
    now: "Now",
    passwordPrompt: "Enter a password",
    pending: "Pending",
    pm: "PM",
    prevDecade: "Previous Decade",
    prevHour: "Previous Hour",
    prevMinute: "Previous Minute",
    prevMonth: "Previous Month",
    prevSecond: "Previous Second",
    prevYear: "Previous Year",
    reject: "No",
    removeRule: "Remove Rule",
    searchMessage: "{0} results are available",
    selectionMessage: "{0} items selected",
    showMonthAfterYear: false,
    startsWith: "Starts with",
    strong: "Strong",
    today: "Today",
    upload: "Upload",
    weak: "Weak",
    weekHeader: "Wk",
    aria: {
      cancelEdit: "Cancel Edit",
      close: "Close",
      collapseLabel: "Collapse",
      collapseRow: "Row Collapsed",
      editRow: "Edit Row",
      expandLabel: "Expand",
      expandRow: "Row Expanded",
      falseLabel: "False",
      filterConstraint: "Filter Constraint",
      filterOperator: "Filter Operator",
      firstPageLabel: "First Page",
      gridView: "Grid View",
      hideFilterMenu: "Hide Filter Menu",
      jumpToPageDropdownLabel: "Jump to Page Dropdown",
      jumpToPageInputLabel: "Jump to Page Input",
      lastPageLabel: "Last Page",
      listLabel: "Option List",
      listView: "List View",
      moveAllToSource: "Move All to Source",
      moveAllToTarget: "Move All to Target",
      moveBottom: "Move Bottom",
      moveDown: "Move Down",
      moveToSource: "Move to Source",
      moveToTarget: "Move to Target",
      moveTop: "Move Top",
      moveUp: "Move Up",
      navigation: "Navigation",
      next: "Next",
      nextPageLabel: "Next Page",
      nullLabel: "Not Selected",
      otpLabel: "Please enter one time password character {0}",
      pageLabel: "Page {page}",
      passwordHide: "Hide Password",
      passwordShow: "Show Password",
      previous: "Previous",
      prevPageLabel: "Previous Page",
      removeLabel: "Remove",
      rotateLeft: "Rotate Left",
      rotateRight: "Rotate Right",
      rowsPerPageLabel: "Rows per page",
      saveEdit: "Save Edit",
      scrollTop: "Scroll Top",
      selectAll: "All items selected",
      selectLabel: "Select",
      selectRow: "Row Selected",
      showFilterMenu: "Show Filter Menu",
      slide: "Slide",
      slideNumber: "{slideNumber}",
      star: "1 star",
      stars: "{star} stars",
      trueLabel: "True",
      unselectAll: "All items unselected",
      unselectLabel: "Unselect",
      unselectRow: "Row Unselected",
      zoomImage: "Zoom Image",
      zoomIn: "Zoom In",
      zoomOut: "Zoom Out"
    }
  }
};
function ariaLabel(ariaKey, options) {
  if (ariaKey.includes("__proto__") || ariaKey.includes("prototype")) {
    throw new Error("Unsafe ariaKey detected");
  }
  var _locale = PrimeReact2.locale;
  try {
    var _ariaLabel = localeOptions(_locale).aria[ariaKey];
    if (_ariaLabel) {
      for (var key in options) {
        if (options.hasOwnProperty(key)) {
          _ariaLabel = _ariaLabel.replace("{".concat(key, "}"), options[key]);
        }
      }
    }
    return _ariaLabel;
  } catch (error) {
    throw new Error("The ".concat(ariaKey, " option is not found in the current locale('").concat(_locale, "')."));
  }
}
function localeOptions(locale) {
  var _locale = locale || PrimeReact2.locale;
  if (_locale.includes("__proto__") || _locale.includes("prototype")) {
    throw new Error("Unsafe locale detected");
  }
  return locales[_locale];
}
var styles = "";
var FocusTrapBase = ComponentBase.extend({
  defaultProps: {
    __TYPE: "FocusTrap",
    children: void 0
  },
  css: {
    styles
  },
  getProps: function getProps(props) {
    return ObjectUtils.getMergedProps(props, FocusTrapBase.defaultProps);
  },
  getOtherProps: function getOtherProps(props) {
    return ObjectUtils.getDiffProps(props, FocusTrapBase.defaultProps);
  }
});
function ownKeys$5(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$5(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$5(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$5(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var FocusTrap = /* @__PURE__ */ React__default.memo(/* @__PURE__ */ React__default.forwardRef(function(inProps, ref) {
  var targetRef = React__default.useRef(null);
  var firstFocusableElementRef = React__default.useRef(null);
  var lastFocusableElementRef = React__default.useRef(null);
  var context = React__default.useContext(PrimeReactContext);
  var props = FocusTrapBase.getProps(inProps, context);
  var metaData = {
    props
  };
  useStyle(FocusTrapBase.css.styles, {
    name: "focustrap"
  });
  var _FocusTrapBase$setMet = FocusTrapBase.setMetaData(_objectSpread$5({}, metaData));
  _FocusTrapBase$setMet.ptm;
  React__default.useImperativeHandle(ref, function() {
    return {
      props,
      getInk: function getInk() {
        return firstFocusableElementRef.current;
      },
      getTarget: function getTarget2() {
        return targetRef.current;
      }
    };
  });
  useMountEffect(function() {
    if (!props.disabled) {
      targetRef.current = getTarget();
      setAutoFocus(targetRef.current);
    }
  });
  var getTarget = function getTarget2() {
    return firstFocusableElementRef.current && firstFocusableElementRef.current.parentElement;
  };
  var setAutoFocus = function setAutoFocus2(target) {
    var _ref = props || {}, _ref$autoFocusSelecto = _ref.autoFocusSelector, autoFocusSelector = _ref$autoFocusSelecto === void 0 ? "" : _ref$autoFocusSelecto, _ref$firstFocusableSe = _ref.firstFocusableSelector, firstFocusableSelector = _ref$firstFocusableSe === void 0 ? "" : _ref$firstFocusableSe, _ref$autoFocus = _ref.autoFocus, autoFocus = _ref$autoFocus === void 0 ? false : _ref$autoFocus;
    var defaultAutoFocusSelector = "".concat(getComputedSelector(autoFocusSelector));
    var computedAutoFocusSelector = "[autofocus]".concat(defaultAutoFocusSelector, ", [data-pc-autofocus='true']").concat(defaultAutoFocusSelector);
    var focusableElement = DomHandler.getFirstFocusableElement(target, computedAutoFocusSelector);
    autoFocus && !focusableElement && (focusableElement = DomHandler.getFirstFocusableElement(target, getComputedSelector(firstFocusableSelector)));
    DomHandler.focus(focusableElement);
  };
  var getComputedSelector = function getComputedSelector2(selector) {
    return ':not(.p-hidden-focusable):not([data-p-hidden-focusable="true"])'.concat(selector !== null && selector !== void 0 ? selector : "");
  };
  var onFirstHiddenElementFocus = function onFirstHiddenElementFocus2(event) {
    var _targetRef$current;
    var currentTarget = event.currentTarget, relatedTarget = event.relatedTarget;
    var focusableElement = relatedTarget === currentTarget.$_pfocustrap_lasthiddenfocusableelement || !((_targetRef$current = targetRef.current) !== null && _targetRef$current !== void 0 && _targetRef$current.contains(relatedTarget)) ? DomHandler.getFirstFocusableElement(currentTarget.parentElement, getComputedSelector(currentTarget.$_pfocustrap_focusableselector)) : currentTarget.$_pfocustrap_lasthiddenfocusableelement;
    DomHandler.focus(focusableElement);
  };
  var onLastHiddenElementFocus = function onLastHiddenElementFocus2(event) {
    var _targetRef$current2;
    var currentTarget = event.currentTarget, relatedTarget = event.relatedTarget;
    var focusableElement = relatedTarget === currentTarget.$_pfocustrap_firsthiddenfocusableelement || !((_targetRef$current2 = targetRef.current) !== null && _targetRef$current2 !== void 0 && _targetRef$current2.contains(relatedTarget)) ? DomHandler.getLastFocusableElement(currentTarget.parentElement, getComputedSelector(currentTarget.$_pfocustrap_focusableselector)) : currentTarget.$_pfocustrap_firsthiddenfocusableelement;
    DomHandler.focus(focusableElement);
  };
  var createHiddenFocusableElements = function createHiddenFocusableElements2() {
    var _ref2 = props || {}, _ref2$tabIndex = _ref2.tabIndex, tabIndex = _ref2$tabIndex === void 0 ? 0 : _ref2$tabIndex;
    var createFocusableElement = function createFocusableElement2(inRef, onFocus, section) {
      return /* @__PURE__ */ React__default.createElement("span", {
        ref: inRef,
        className: "p-hidden-accessible p-hidden-focusable",
        tabIndex,
        role: "presentation",
        "aria-hidden": true,
        "data-p-hidden-accessible": true,
        "data-p-hidden-focusable": true,
        onFocus,
        "data-pc-section": section
      });
    };
    var firstFocusableElement = createFocusableElement(firstFocusableElementRef, onFirstHiddenElementFocus, "firstfocusableelement");
    var lastFocusableElement = createFocusableElement(lastFocusableElementRef, onLastHiddenElementFocus, "lastfocusableelement");
    if (firstFocusableElementRef.current && lastFocusableElementRef.current) {
      firstFocusableElementRef.current.$_pfocustrap_lasthiddenfocusableelement = lastFocusableElementRef.current;
      lastFocusableElementRef.current.$_pfocustrap_firsthiddenfocusableelement = firstFocusableElementRef.current;
    }
    return /* @__PURE__ */ React__default.createElement(React__default.Fragment, null, firstFocusableElement, props.children, lastFocusableElement);
  };
  return createHiddenFocusableElements();
}));
var FocusTrap$1 = FocusTrap;
function ownKeys$4(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$4(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$4(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$4(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var ColumnFilter = /* @__PURE__ */ React.memo(function(props) {
  var _React$useState = React.useState(false), _React$useState2 = _slicedToArray(_React$useState, 2), overlayVisibleState = _React$useState2[0], setOverlayVisibleState = _React$useState2[1];
  var overlayRef = React.useRef(null);
  var overlayId = React.useRef(null);
  var iconRef = React.useRef(null);
  var selfClick = React.useRef(false);
  var overlayEventListener = React.useRef(null);
  var mergeProps = useMergeProps();
  var getColumnProp = function getColumnProp2(name) {
    return ColumnBase.getCProp(props.column, name);
  };
  var getColumnProps = function getColumnProps2() {
    return ColumnBase.getCProps(props.column);
  };
  var context = React.useContext(PrimeReactContext);
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  var getColumnPTOptions = function getColumnPTOptions2(key, params) {
    var cProps = getColumnProps();
    var columnMetadata = _objectSpread$4({
      props: cProps,
      parent: props.metaData,
      hostName: props.hostName,
      state: {
        overlayVisible: overlayVisibleState
      }
    }, params);
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetadata
    }), ptm("column.".concat(key), columnMetadata), ptmo(cProps, key, columnMetadata));
  };
  var field = getColumnProp("filterField") || getColumnProp("field");
  var filterModel = props.filters[field];
  var filterStoreModel = props.filtersStore && props.filtersStore[field];
  var _useOverlayListener = useOverlayListener({
    target: iconRef,
    overlay: overlayRef,
    listener: function listener(event, _ref) {
      var type = _ref.type, valid = _ref.valid;
      if (valid) {
        if (type === "outside") {
          if (!selfClick.current && !isTargetClicked(event.target)) {
            hide();
          }
          selfClick.current = false;
        } else if (context.hideOverlaysOnDocumentScrolling) {
          hide();
        } else if (!DomHandler.isDocument(event.target)) {
          DomHandler.alignOverlay(overlayRef.current, iconRef.current, context && context.appendTo || PrimeReact$2.appendTo, false);
        }
      }
    },
    when: overlayVisibleState
  }), _useOverlayListener2 = _slicedToArray(_useOverlayListener, 2), bindOverlayListener = _useOverlayListener2[0], unbindOverlayListener = _useOverlayListener2[1];
  var hasFilter = function hasFilter2() {
    if (!filterStoreModel || !filterModel) {
      return false;
    }
    return filterStoreModel.operator ? !isFilterBlank(filterModel.constraints[0].value) : !isFilterBlank(filterModel.value);
  };
  var hasRowFilter = function hasRowFilter2() {
    return filterModel && !isFilterBlank(filterModel.value);
  };
  var isFilterBlank = function isFilterBlank2(filter) {
    return ObjectUtils.isEmpty(filter);
  };
  var isRowMatchModeSelected = function isRowMatchModeSelected2(matchMode) {
    return filterModel && filterModel.matchMode === matchMode;
  };
  var matchModes = function matchModes2() {
    return getColumnProp("filterMatchModeOptions") || context && context.filterMatchModeOptions[findDataType()].map(function(key) {
      return {
        label: localeOption(key),
        value: key
      };
    }) || PrimeReact$2.filterMatchModeOptions[findDataType()].map(function(key) {
      return {
        label: localeOption(key),
        value: key
      };
    });
  };
  var isShowMenuButton = function isShowMenuButton2() {
    return getColumnProp("showFilterMenu") && (props.display === "row" ? getColumnProp("dataType") !== "boolean" : true);
  };
  var isShowClearButton = function isShowClearButton2() {
    return getColumnProp("showClearButton") && props.display === "row";
  };
  var isShowMatchModes = function isShowMatchModes2() {
    return getColumnProp("dataType") !== "boolean" && getColumnProp("showFilterMatchModes") && matchModes() && getColumnProp("showFilterMenuOptions");
  };
  var isShowOperator = function isShowOperator2() {
    return getColumnProp("showFilterOperator") && filterModel && filterModel.operator && getColumnProp("showFilterMenuOptions");
  };
  var showRemoveIcon = function showRemoveIcon2() {
    return fieldConstraints().length > 1;
  };
  var isShowAddConstraint = function isShowAddConstraint2() {
    return getColumnProp("showAddButton") && filterModel && filterModel.operator && fieldConstraints() && fieldConstraints().length < getColumnProp("maxConstraints") && getColumnProp("showFilterMenuOptions");
  };
  var isOutsideClicked = function isOutsideClicked2(target) {
    return !isTargetClicked(target) && overlayRef.current && !(overlayRef.current.isSameNode(target) || overlayRef.current.contains(target));
  };
  var isTargetClicked = function isTargetClicked2(target) {
    return iconRef.current && (iconRef.current.isSameNode(target) || iconRef.current.contains(target));
  };
  var getDefaultConstraint = function getDefaultConstraint2() {
    if (filterStoreModel) {
      if (filterStoreModel.operator) {
        return {
          matchMode: filterStoreModel.constraints[0].matchMode,
          operator: filterStoreModel.operator
        };
      }
      return {
        matchMode: filterStoreModel.matchMode
      };
    }
  };
  var findDataType = function findDataType2() {
    var dataType = getColumnProp("dataType");
    var matchMode = getColumnProp("filterMatchMode");
    var hasMatchMode = function hasMatchMode2(key) {
      return context && context.filterMatchModeOptions[key].some(function(mode) {
        return mode === matchMode;
      }) || PrimeReact$2.filterMatchModeOptions[key].some(function(mode) {
        return mode === matchMode;
      });
    };
    if (matchMode === "custom" && !hasMatchMode(dataType)) {
      context && context.filterMatchModeOptions[dataType].push(FilterMatchMode$2.CUSTOM) || PrimeReact$2.filterMatchModeOptions[dataType].push(FilterMatchMode$2.CUSTOM);
      return dataType;
    } else if (matchMode) {
      return Object.keys(context && context.filterMatchModeOptions || PrimeReact$2.filterMatchModeOptions).find(function(key) {
        return hasMatchMode(key);
      }) || dataType;
    }
    return dataType;
  };
  var clearFilter = function clearFilter2() {
    var filterClearCallback = getColumnProp("onFilterClear");
    var defaultConstraint = getDefaultConstraint();
    var filters = _objectSpread$4({}, props.filters);
    if (filters[field].operator) {
      filters[field].constraints.splice(1);
      filters[field].operator = defaultConstraint.operator;
      filters[field].constraints[0] = {
        value: null,
        matchMode: defaultConstraint.matchMode
      };
    } else {
      filters[field].value = null;
      filters[field].matchMode = defaultConstraint ? defaultConstraint.matchMode : filters[field].matchMode;
    }
    filterClearCallback && filterClearCallback();
    props.onFilterChange(filters);
    props.onFilterApply();
    hide();
  };
  var applyFilter = function applyFilter2() {
    var filterApplyClickCallback = getColumnProp("onFilterApplyClick");
    filterApplyClickCallback && filterApplyClickCallback({
      field,
      constraints: filterModel
    });
    props.onFilterApply();
    hide();
  };
  var toggleMenu = function toggleMenu2() {
    setOverlayVisibleState(function(prevVisible) {
      return !prevVisible;
    });
  };
  var onToggleButtonKeyDown = function onToggleButtonKeyDown2(event) {
    switch (event.key) {
      case "Escape":
      case "Tab":
        hide();
        break;
      case "ArrowDown":
        if (overlayVisibleState) {
          var focusable = DomHandler.getFirstFocusableElement(overlayRef.current);
          focusable && focusable.focus();
          event.preventDefault();
        } else if (event.altKey) {
          setOverlayVisibleState(true);
          event.preventDefault();
        }
        break;
    }
  };
  var onContentKeyDown = function onContentKeyDown2(event) {
    if (event.key === "Escape") {
      hide();
      iconRef.current && iconRef.current.focus();
    }
  };
  var onInputChange = function onInputChange2(event, index2) {
    var filters = _objectSpread$4({}, props.filters);
    var value = event.target.value;
    var filterField = filters[field];
    if (props.display === "menu" && ObjectUtils.isNotEmpty(filterField.constraints)) {
      filterField.constraints[index2].value = value;
    } else {
      filterField.value = value;
    }
    props.onFilterChange(filters);
    if (!getColumnProp("showApplyButton") || props.display === "row") {
      props.onFilterApply();
    }
  };
  var onInputKeydown = function onInputKeydown2(event, _index) {
    if (event.key === "Enter") {
      if (!getColumnProp("showApplyButton") || props.display === "menu") {
        applyFilter();
      }
    }
  };
  var onRowMatchModeChange = function onRowMatchModeChange2(matchMode) {
    var _iconRef$current;
    var filterMatchModeChangeCallback = getColumnProp("onFilterMatchModeChange");
    var filters = _objectSpread$4({}, props.filters);
    filters[field].matchMode = matchMode;
    filterMatchModeChangeCallback && filterMatchModeChangeCallback({
      field,
      matchMode
    });
    props.onFilterChange(filters);
    props.onFilterApply();
    hide();
    (_iconRef$current = iconRef.current) === null || _iconRef$current === void 0 || _iconRef$current.focus();
  };
  var onRowMatchModeKeyDown = function onRowMatchModeKeyDown2(event, matchMode, clear) {
    var item = event.target;
    switch (event.key) {
      case "ArrowDown":
        var nextItem = _findNextItem(item);
        if (nextItem) {
          item.removeAttribute("tabindex");
          nextItem.tabIndex = 0;
          nextItem.focus();
        }
        event.preventDefault();
        break;
      case "ArrowUp":
        var prevItem = _findPrevItem(item);
        if (prevItem) {
          item.removeAttribute("tabindex");
          prevItem.tabIndex = 0;
          prevItem.focus();
        }
        event.preventDefault();
        break;
      case "Enter":
        clear ? clearFilter() : onRowMatchModeChange(matchMode.value);
        event.preventDefault();
        break;
    }
  };
  var onOperatorChange = function onOperatorChange2(e) {
    var filterOperationChangeCallback = getColumnProp("onFilterOperatorChange");
    var value = e.value;
    var filters = _objectSpread$4({}, props.filters);
    filters[field].operator = value;
    props.onFilterChange(filters);
    filterOperationChangeCallback && filterOperationChangeCallback({
      field,
      operator: value
    });
    if (!getColumnProp("showApplyButton")) {
      props.onFilterApply();
    }
  };
  var onMenuMatchModeChange = function onMenuMatchModeChange2(value, index2) {
    var filterMatchModeChangeCallback = getColumnProp("onFilterMatchModeChange");
    var filters = _objectSpread$4({}, props.filters);
    var filterField = filters[field];
    if (props.display === "menu" && ObjectUtils.isNotEmpty(filterField.constraints)) {
      filterField.constraints[index2].matchMode = value;
    } else {
      filterField.matchMode = value;
    }
    props.onFilterChange(filters);
    filterMatchModeChangeCallback && filterMatchModeChangeCallback({
      field,
      matchMode: value,
      index: index2
    });
    if (!getColumnProp("showApplyButton")) {
      props.onFilterApply();
    }
  };
  var addConstraint = function addConstraint2() {
    var filterConstraintAddCallback = getColumnProp("onFilterConstraintAdd");
    var defaultConstraint = getDefaultConstraint();
    var filters = _objectSpread$4({}, props.filters);
    var newConstraint = {
      value: null,
      matchMode: defaultConstraint.matchMode
    };
    filters[field].constraints.push(newConstraint);
    filterConstraintAddCallback && filterConstraintAddCallback({
      field,
      constraint: newConstraint
    });
    props.onFilterChange(filters);
    if (!getColumnProp("showApplyButton")) {
      props.onFilterApply();
    }
  };
  var removeConstraint = function removeConstraint2(index2) {
    var filterConstraintRemoveCallback = getColumnProp("onFilterConstraintRemove");
    var filters = _objectSpread$4({}, props.filters);
    var removedConstraint = filters[field].constraints.splice(index2, 1);
    filterConstraintRemoveCallback && filterConstraintRemoveCallback({
      field,
      constraint: removedConstraint
    });
    props.onFilterChange(filters);
    if (!getColumnProp("showApplyButton")) {
      props.onFilterApply();
    }
  };
  var _findNextItem = function findNextItem(item) {
    var nextItem = item.nextElementSibling;
    return nextItem ? DomHandler.getAttribute(nextItem, "data-p-column-filter-separator") === true ? _findNextItem(nextItem) : nextItem : item.parentElement.firstElementChild;
  };
  var _findPrevItem = function findPrevItem(item) {
    var prevItem = item.previousElementSibling;
    return prevItem ? DomHandler.getAttribute(prevItem, "data-p-column-filter-separator") === true ? _findPrevItem(prevItem) : prevItem : item.parentElement.lastElementChild;
  };
  var hide = function hide2() {
    setOverlayVisibleState(false);
  };
  var onContentClick = function onContentClick2(event) {
    selfClick.current = true;
    OverlayService.emit("overlay-click", {
      originalEvent: event,
      target: overlayRef.current
    });
  };
  var onContentMouseDown = function onContentMouseDown2() {
    selfClick.current = true;
  };
  var onOverlayEnter = function onOverlayEnter2() {
    ZIndexUtils.set("overlay", overlayRef.current, context && context.autoZIndex || PrimeReact$2.autoZIndex, context && context.zIndex.overlay || PrimeReact$2.zIndex.overlay);
    DomHandler.addStyles(overlayRef.current, {
      position: "absolute",
      top: "0",
      left: "0"
    });
    DomHandler.alignOverlay(overlayRef.current, iconRef.current, context && context.appendTo || PrimeReact$2.appendTo, false);
    overlayEventListener.current = function(e) {
      if (!isOutsideClicked(e.target)) {
        selfClick.current = true;
      }
    };
    OverlayService.on("overlay-click", overlayEventListener.current);
  };
  var onOverlayEntered = function onOverlayEntered2() {
    bindOverlayListener();
  };
  var onOverlayExit = function onOverlayExit2() {
    onOverlayHide();
  };
  var onOverlayExited = function onOverlayExited2() {
    ZIndexUtils.clear(overlayRef.current);
  };
  var onOverlayHide = function onOverlayHide2() {
    unbindOverlayListener();
    OverlayService.off("overlay-click", overlayEventListener.current);
    overlayEventListener.current = null;
    selfClick.current = false;
  };
  var fieldConstraints = function fieldConstraints2() {
    return filterModel ? filterModel.constraints || [filterModel] : [];
  };
  var operator = function operator2() {
    return filterModel.operator;
  };
  var operatorOptions = function operatorOptions2() {
    return [{
      label: localeOption("matchAll"),
      value: FilterOperator.AND
    }, {
      label: localeOption("matchAny"),
      value: FilterOperator.OR
    }];
  };
  var noFilterLabel = function noFilterLabel2() {
    return localeOption("noFilter");
  };
  var removeRuleButtonLabel = function removeRuleButtonLabel2() {
    return localeOption("removeRule");
  };
  var addRuleButtonLabel = function addRuleButtonLabel2() {
    return localeOption("addRule");
  };
  var clearButtonLabel = function clearButtonLabel2() {
    return localeOption("clear");
  };
  var applyButtonLabel = function applyButtonLabel2() {
    return localeOption("apply");
  };
  var filterCallback = function filterCallback2(value) {
    var index2 = arguments.length > 1 && arguments[1] !== void 0 ? arguments[1] : 0;
    var filters = _objectSpread$4({}, props.filters);
    var meta = filters[field];
    props.display === "menu" && meta && meta.operator ? filters[field].constraints[index2].value = value : filters[field].value = value;
    props.onFilterChange(filters);
  };
  var filterApplyCallback = function filterApplyCallback2() {
    for (var _len = arguments.length, args = new Array(_len), _key = 0; _key < _len; _key++) {
      args[_key] = arguments[_key];
    }
    args && filterCallback(args[0], args[1]);
    props.onFilterApply();
  };
  useUpdateEffect(function() {
    if (props.display === "menu" && overlayVisibleState) {
      DomHandler.alignOverlay(overlayRef.current, iconRef.current, context && context.appendTo || PrimeReact$2.appendTo, false);
    }
  });
  useMountEffect(function() {
    if (!overlayId.current) {
      overlayId.current = UniqueComponentId();
    }
  });
  useUnmountEffect(function() {
    if (overlayEventListener.current) {
      OverlayService.off("overlay-click", overlayEventListener.current);
      overlayEventListener.current = null;
    }
    if (overlayRef.current) {
      ZIndexUtils.clear(overlayRef.current);
      onOverlayHide();
    }
  });
  var createFilterElement = function createFilterElement2(model, index2) {
    var value = model ? model.value : null;
    return getColumnProp("filterElement") ? ObjectUtils.getJSXElement(getColumnProp("filterElement"), {
      field,
      index: index2,
      filterModel: model,
      value,
      filterApplyCallback,
      filterCallback
    }) : /* @__PURE__ */ React.createElement(InputText, {
      type: getColumnProp("filterType"),
      value: value || "",
      onChange: function onChange(e) {
        return onInputChange(e, index2);
      },
      onKeyDown: function onKeyDown(e) {
        return onInputKeydown(e);
      },
      className: "p-column-filter",
      placeholder: getColumnProp("filterPlaceholder"),
      maxLength: getColumnProp("filterMaxLength"),
      "aria-label": getColumnProp("filterPlaceholder"),
      unstyled: props.unstyled,
      __parentMetadata: {
        parent: props.metaData
      }
    });
  };
  var createRowFilterElement = function createRowFilterElement2() {
    if (props.display === "row") {
      var content = createFilterElement(filterModel, 0);
      var filterInputProps = mergeProps({
        className: cx("filterInput")
      }, getColumnPTOptions("filterInput"));
      return /* @__PURE__ */ React.createElement("div", filterInputProps, content);
    }
    return null;
  };
  var createMenuFilterElement = function createMenuFilterElement2(fieldConstraint, index2) {
    return props.display === "menu" ? createFilterElement(fieldConstraint, index2) : null;
  };
  var createMenuButton = function createMenuButton2() {
    if (!isShowMenuButton()) {
      return null;
    }
    var filterIconProps = mergeProps({
      "aria-hidden": true
    }, getColumnPTOptions("filterIcon"));
    var icon = props.filterIcon || /* @__PURE__ */ React.createElement(FilterIcon, filterIconProps);
    var columnFilterIcon = IconUtils.getJSXIcon(icon, _objectSpread$4({}, filterIconProps), {
      props
    });
    var label = overlayVisibleState ? ariaLabel("hideFilterMenu") : ariaLabel("showFilterMenu");
    var filterMenuButtonProps = mergeProps({
      type: "button",
      className: cx("filterMenuButton", {
        overlayVisibleState,
        hasFilter
      }),
      "aria-haspopup": true,
      "aria-expanded": overlayVisibleState,
      "aria-label": label,
      "aria-controls": overlayVisibleState ? overlayId.current : void 0,
      onClick: function onClick(e) {
        return toggleMenu();
      },
      onKeyDown: function onKeyDown(e) {
        return onToggleButtonKeyDown(e);
      }
    }, getColumnPTOptions("filterMenuButton", {
      context: {
        active: hasFilter()
      }
    }));
    return /* @__PURE__ */ React.createElement("button", _extends({
      ref: iconRef
    }, filterMenuButtonProps), columnFilterIcon, /* @__PURE__ */ React.createElement(Ripple, null));
  };
  var createClearButton = function createClearButton2() {
    if (!isShowClearButton()) {
      return null;
    }
    var filterClearIconProps = mergeProps({
      "aria-hidden": true
    }, getColumnPTOptions("filterClearIcon"));
    var icon = props.filterClearIcon || /* @__PURE__ */ React.createElement(FilterSlashIcon, filterClearIconProps);
    var filterClearIcon = IconUtils.getJSXIcon(icon, _objectSpread$4({}, filterClearIconProps), {
      props
    });
    var clearLabel = clearButtonLabel();
    var headerFilterClearButtonProps = mergeProps({
      className: cx("headerFilterClearButton", {
        hasRowFilter
      }),
      type: "button",
      onClick: function onClick(e) {
        return clearFilter();
      },
      "aria-label": clearLabel
    }, getColumnPTOptions("headerFilterClearButton", {
      context: {
        hidden: hasRowFilter()
      }
    }));
    return /* @__PURE__ */ React.createElement("button", headerFilterClearButtonProps, filterClearIcon, /* @__PURE__ */ React.createElement(Ripple, null));
  };
  var createRowItems = function createRowItems2() {
    if (isShowMatchModes()) {
      var _matchModes = matchModes();
      var _noFilterLabel = noFilterLabel();
      var filterSeparatorProps = mergeProps({
        className: cx("filterSeparator"),
        "data-p-column-filter-separator": true
      }, getColumnPTOptions("filterSeparator"));
      var filterRowItemProps = mergeProps({
        className: cx("filterRowItem", {
          isRowMatchModeSelected,
          isShowMatchModes
        }),
        onClick: function onClick(e) {
          return clearFilter();
        },
        onKeyDown: function onKeyDown(e) {
          return onRowMatchModeKeyDown(e, null, true);
        }
      }, getColumnPTOptions("filterRowItem"));
      var filterRowItemsProps = mergeProps({
        className: cx("filterRowItems")
      }, getColumnPTOptions("filterRowItems"));
      return /* @__PURE__ */ React.createElement("ul", filterRowItemsProps, _matchModes.map(function(matchMode, i) {
        var value = matchMode.value, label = matchMode.label;
        var tabIndex = i === 0 ? 0 : null;
        var filterRowItemProps2 = mergeProps({
          className: cx("filterRowItem", {
            isRowMatchModeSelected,
            isShowMatchModes,
            value
          }),
          onClick: function onClick() {
            return onRowMatchModeChange(value);
          },
          onKeyDown: function onKeyDown(e) {
            return onRowMatchModeKeyDown(e, matchMode);
          },
          tabIndex
        }, getColumnPTOptions("filterRowItem", {
          context: {
            highlighted: matchMode && isRowMatchModeSelected(value)
          }
        }));
        return /* @__PURE__ */ React.createElement("li", _extends({}, filterRowItemProps2, {
          key: label
        }), label);
      }), /* @__PURE__ */ React.createElement("li", filterSeparatorProps), /* @__PURE__ */ React.createElement("li", filterRowItemProps, _noFilterLabel));
    }
    return null;
  };
  var createOperator = function createOperator2() {
    if (isShowOperator()) {
      var options = operatorOptions();
      var value = operator();
      var filterOperatorProps = mergeProps({
        className: cx("filterOperator")
      }, getColumnPTOptions("filterOperator"));
      return /* @__PURE__ */ React.createElement("div", filterOperatorProps, /* @__PURE__ */ React.createElement(Dropdown, {
        options,
        value,
        onChange: onOperatorChange,
        className: "p-column-filter-operator-dropdown",
        pt: getColumnPTOptions("filterOperatorDropdown"),
        unstyled: props.unstyled,
        __parentMetadata: {
          parent: props.metaData
        },
        "aria-label": ariaLabel("filterOperator")
      }));
    }
    return null;
  };
  var createMatchModeDropdown = function createMatchModeDropdown2(constraint, index2) {
    if (isShowMatchModes()) {
      var options = matchModes();
      return /* @__PURE__ */ React.createElement(Dropdown, {
        options,
        value: constraint.matchMode,
        onChange: function onChange(e) {
          return onMenuMatchModeChange(e.value, index2);
        },
        className: "p-column-filter-matchmode-dropdown",
        pt: getColumnPTOptions("filterMatchModeDropdown"),
        unstyled: props.unstyled,
        __parentMetadata: {
          parent: props.metaData
        },
        "aria-label": ariaLabel("filterConstraint")
      });
    }
    return null;
  };
  var createRemoveButton = function createRemoveButton2(index2) {
    if (showRemoveIcon()) {
      var removeRuleLabel = removeRuleButtonLabel();
      return /* @__PURE__ */ React.createElement(Button, {
        type: "button",
        icon: props.filterRemoveIcon || /* @__PURE__ */ React.createElement(TrashIcon, null),
        className: "p-column-filter-remove-button p-button-text p-button-danger p-button-sm",
        onClick: function onClick() {
          return removeConstraint(index2);
        },
        label: removeRuleLabel,
        pt: getColumnPTOptions("filterRemoveButton"),
        unstyled: props.unstyled,
        __parentMetadata: {
          parent: props.metaData
        }
      });
    }
    return null;
  };
  var createConstraints = function createConstraints2() {
    var _fieldConstraints = fieldConstraints();
    var filterConstraintsProps = mergeProps({
      className: cx("filterConstraints")
    }, getColumnPTOptions("filterConstraints"));
    var filterConstraintProps = mergeProps({
      className: cx("filterConstraint")
    }, getColumnPTOptions("filterConstraint"));
    return /* @__PURE__ */ React.createElement("div", filterConstraintsProps, _fieldConstraints.map(function(fieldConstraint, i) {
      var matchModeDropdown = createMatchModeDropdown(fieldConstraint, i);
      var menuFilterElement = createMenuFilterElement(fieldConstraint, i);
      var removeButton = createRemoveButton(i);
      var filterRemoveProps = mergeProps(getColumnPTOptions("filterRemove"));
      return /* @__PURE__ */ React.createElement("div", _extends({}, filterConstraintProps, {
        key: i
      }), matchModeDropdown, menuFilterElement, /* @__PURE__ */ React.createElement("div", filterRemoveProps, removeButton));
    }));
  };
  var createAddRule = function createAddRule2() {
    if (isShowAddConstraint()) {
      var addRuleLabel = addRuleButtonLabel();
      var filterAddRuleProps = mergeProps({
        className: cx("filterAddRule")
      }, getColumnPTOptions("filterAddRule"));
      return /* @__PURE__ */ React.createElement("div", filterAddRuleProps, /* @__PURE__ */ React.createElement(Button, {
        type: "button",
        label: addRuleLabel,
        icon: props.filterAddIcon || /* @__PURE__ */ React.createElement(PlusIcon, null),
        className: "p-column-filter-add-button p-button-text p-button-sm",
        onClick: addConstraint,
        pt: getColumnPTOptions("filterAddRuleButton"),
        unstyled: props.unstyled,
        __parentMetadata: {
          parent: props.metaData
        }
      }));
    }
    return null;
  };
  var createFilterClearButton = function createFilterClearButton2() {
    if (getColumnProp("showClearButton")) {
      if (!getColumnProp("filterClear")) {
        var clearLabel = clearButtonLabel();
        return /* @__PURE__ */ React.createElement(Button, {
          type: "button",
          outlined: true,
          size: "small",
          onClick: clearFilter,
          label: clearLabel,
          pt: getColumnPTOptions("filterClearButton"),
          unstyled: props.unstyled,
          __parentMetadata: {
            parent: props.metaData
          }
        });
      }
      return ObjectUtils.getJSXElement(getColumnProp("filterClear"), {
        field,
        filterModel,
        filterClearCallback: clearFilter
      });
    }
    return null;
  };
  var createFilterApplyButton = function createFilterApplyButton2() {
    if (getColumnProp("showApplyButton")) {
      if (!getColumnProp("filterApply")) {
        var applyLabel = applyButtonLabel();
        return /* @__PURE__ */ React.createElement(Button, {
          type: "button",
          size: "small",
          onClick: applyFilter,
          label: applyLabel,
          pt: getColumnPTOptions("filterApplyButton"),
          unstyled: props.unstyled,
          __parentMetadata: {
            parent: props.metaData
          }
        });
      }
      return ObjectUtils.getJSXElement(getColumnProp("filterApply"), {
        field,
        filterModel,
        filterApplyCallback: applyFilter
      });
    }
    return null;
  };
  var createButtonBar = function createButtonBar2() {
    var clearButton2 = createFilterClearButton();
    var applyButton = createFilterApplyButton();
    var filterButtonbarProps = mergeProps({
      className: cx("filterButtonBar")
    }, getColumnPTOptions("filterButtonBar"));
    return /* @__PURE__ */ React.createElement("div", filterButtonbarProps, clearButton2, applyButton);
  };
  var createItems = function createItems2() {
    var operator2 = createOperator();
    var constraints = createConstraints();
    var addRule = createAddRule();
    var buttonBar = createButtonBar();
    return /* @__PURE__ */ React.createElement(React.Fragment, null, operator2, constraints, addRule, buttonBar);
  };
  var createOverlay = function createOverlay2() {
    var style = getColumnProp("filterMenuStyle");
    var filterHeader = ObjectUtils.getJSXElement(getColumnProp("filterHeader"), {
      field,
      filterModel,
      filterApplyCallback
    });
    var filterFooter = ObjectUtils.getJSXElement(getColumnProp("filterFooter"), {
      field,
      filterModel,
      filterApplyCallback
    });
    var items = props.display === "row" ? createRowItems() : createItems();
    var filterOverlayProps = mergeProps({
      style,
      className: cx("filterOverlay", {
        columnFilterProps: props,
        context,
        getColumnProp
      }),
      onKeyDown: function onKeyDown(e) {
        return onContentKeyDown(e);
      },
      onClick: function onClick(e) {
        return onContentClick(e);
      },
      onMouseDown: function onMouseDown(e) {
        return onContentMouseDown();
      },
      id: overlayId.current,
      "aria-modal": overlayVisibleState,
      role: "dialog"
    }, getColumnPTOptions("filterOverlay"));
    var transitionProps = mergeProps({
      classNames: cx("transition"),
      "in": overlayVisibleState,
      timeout: {
        enter: 120,
        exit: 100
      },
      unmountOnExit: true,
      onEnter: onOverlayEnter,
      onEntered: onOverlayEntered,
      onExit: onOverlayExit,
      onExited: onOverlayExited
    }, getColumnPTOptions("transition"));
    return /* @__PURE__ */ React.createElement(Portal, null, /* @__PURE__ */ React.createElement(CSSTransition, _extends({
      nodeRef: overlayRef
    }, transitionProps), /* @__PURE__ */ React.createElement("div", _extends({
      ref: overlayRef
    }, filterOverlayProps), /* @__PURE__ */ React.createElement(FocusTrap$1, {
      autoFocus: true
    }, filterHeader, items, filterFooter))));
  };
  var rowFilterElement = createRowFilterElement();
  var menuButton = createMenuButton();
  var clearButton = createClearButton();
  var overlay = createOverlay();
  var columnFilter2 = mergeProps({
    className: cx("columnFilter", {
      columnFilterProps: props
    })
  }, getColumnPTOptions("columnFilter"));
  return /* @__PURE__ */ React.createElement("div", columnFilter2, rowFilterElement, menuButton, clearButton, overlay);
});
ColumnFilter.displayName = "ColumnFilter";
function ownKeys$3(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$3(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$3(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$3(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var HeaderCheckbox = /* @__PURE__ */ React.memo(function(props) {
  var mergeProps = useMergeProps();
  var getColumnProps = function getColumnProps2() {
    return ColumnBase.getCProps(props.column);
  };
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  var getColumnPTOptions = function getColumnPTOptions2(key) {
    var cProps = getColumnProps();
    var columnMetaData = {
      props: cProps,
      parent: props.metaData,
      hostName: props.hostName,
      state: {},
      context: {
        checked: props.checked,
        disabled: props.disabled
      }
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(cProps, key, columnMetaData));
  };
  var onChange = function onChange2(event) {
    if (!props.disabled) {
      props.onChange({
        originalEvent: event,
        checked: !props.checked
      });
    }
  };
  var headerCheckboxIconProps = mergeProps({
    className: cx("checkIcon")
  }, getColumnPTOptions("headerCheckbox.icon"));
  var icon = props.checked ? props.checkIcon || /* @__PURE__ */ React.createElement(CheckIcon, headerCheckboxIconProps) : null;
  var checkIcon = IconUtils.getJSXIcon(icon, _objectSpread$3({}, headerCheckboxIconProps), {
    props
  });
  var tabIndex = props.disabled ? null : 0;
  var headerCheckboxProps = {
    role: "checkbox",
    "aria-label": props.checked ? ariaLabel("selectAll") : ariaLabel("unselectAll"),
    tabIndex,
    onChange,
    icon: checkIcon,
    checked: props.checked,
    disabled: props.disabled,
    unstyled: props.unstyled,
    pt: getColumnPTOptions("headerCheckbox")
  };
  return /* @__PURE__ */ React.createElement(Checkbox, headerCheckboxProps);
});
HeaderCheckbox.displayName = "HeaderCheckbox";
function ownKeys$2(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$2(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$2(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$2(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var HeaderCell = /* @__PURE__ */ React.memo(function(props) {
  var _React$useState = React.useState({}), _React$useState2 = _slicedToArray(_React$useState, 2), styleObjectState = _React$useState2[0], setStyleObjectState = _React$useState2[1];
  var elementRef = React.useRef(null);
  var prevColumn = usePrevious(props.column);
  var mergeProps = useMergeProps();
  var parentMetaData = props.metaData, ptCallbacks = props.ptCallbacks, index2 = props.index;
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  var params = {
    index: index2
  };
  var parentParams = _objectSpread$2(_objectSpread$2({}, parentMetaData), params);
  var getColumnProps = function getColumnProps2() {
    return ColumnBase.getCProps(props.column);
  };
  var getColumnPTOptions = function getColumnPTOptions2(key) {
    var cProps = getColumnProps();
    var columnMetaData = {
      props: cProps,
      parent: parentParams,
      hostName: props.hostName,
      state: {
        styleObject: styleObjectState
      },
      context: {
        index: props.index,
        sorted: getSortMeta().sorted,
        resizable: props.resizableColumns,
        size: props.metaData.props.size,
        showGridlines: props.metaData.props.showGridlines
      }
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(cProps, key, columnMetaData));
  };
  var isBadgeVisible = function isBadgeVisible2() {
    return props.multiSortMeta && props.multiSortMeta.length > 1;
  };
  var isSortableDisabled = function isSortableDisabled2() {
    return !getColumnProp("sortable") || getColumnProp("sortable") && (props.allSortableDisabled || getColumnProp("sortableDisabled"));
  };
  var getColumnProp = function getColumnProp2() {
    return props.column ? typeof (arguments.length <= 0 ? void 0 : arguments[0]) === "string" ? ColumnBase.getCProp(props.column, arguments.length <= 0 ? void 0 : arguments[0]) : ColumnBase.getCProp((arguments.length <= 0 ? void 0 : arguments[0]) || props.column, arguments.length <= 1 ? void 0 : arguments[1]) : null;
  };
  var getStyle = function getStyle2() {
    var headerStyle = getColumnProp("headerStyle");
    var columnStyle = getColumnProp("style");
    return getColumnProp("frozen") ? Object.assign({}, columnStyle, headerStyle, styleObjectState) : Object.assign({}, columnStyle, headerStyle);
  };
  var getMultiSortMetaIndex = function getMultiSortMetaIndex2() {
    return props.multiSortMeta.findIndex(function(meta) {
      return meta.field === getColumnProp("field") || meta.field === getColumnProp("sortField");
    });
  };
  var getSortMeta = function getSortMeta2() {
    var sorted = false;
    var sortOrder = 0;
    var metaIndex = -1;
    if (props.sortMode === "single") {
      sorted = props.sortField && (props.sortField === getColumnProp("field") || props.sortField === getColumnProp("sortField"));
      sortOrder = sorted ? props.sortOrder : 0;
    } else if (props.sortMode === "multiple") {
      metaIndex = getMultiSortMetaIndex();
      if (metaIndex > -1) {
        sorted = true;
        sortOrder = props.multiSortMeta[metaIndex].order;
      }
    }
    return {
      sorted,
      sortOrder,
      metaIndex
    };
  };
  var getAriaSort = function getAriaSort2(_ref) {
    var sorted = _ref.sorted, sortOrder = _ref.sortOrder;
    if (getColumnProp("sortable")) {
      if (sorted && sortOrder < 0) {
        return "descending";
      } else if (sorted && sortOrder > 0) {
        return "ascending";
      }
      return "none";
    }
    return null;
  };
  var updateStickyPosition = function updateStickyPosition2() {
    if (getColumnProp("frozen")) {
      var styleObject = _objectSpread$2({}, styleObjectState);
      var align = getColumnProp("alignFrozen");
      if (align === "right") {
        var right = 0;
        var next = elementRef.current && elementRef.current.nextElementSibling;
        if (next && next.classList.contains("p-frozen-column")) {
          right = DomHandler.getOuterWidth(next) + parseFloat(next.style.right || 0);
        }
        styleObject.right = right + "px";
      } else {
        var _elementRef$current, _elementRef$current2;
        var left = 0;
        var prev = (_elementRef$current = elementRef.current) === null || _elementRef$current === void 0 ? void 0 : _elementRef$current.previousElementSibling;
        var thead = (_elementRef$current2 = elementRef.current) === null || _elementRef$current2 === void 0 ? void 0 : _elementRef$current2.closest("thead");
        var scrollContainer = thead === null || thead === void 0 ? void 0 : thead.parentElement.parentElement;
        var scrollLeft = (scrollContainer === null || scrollContainer === void 0 ? void 0 : scrollContainer.scrollLeft) || 0;
        var isMultiRow = thead && thead.querySelectorAll("tr").length > 1;
        if (elementRef.current) {
          if (!isMultiRow) {
            while (prev) {
              if (prev.classList.contains("p-frozen-column")) {
                left = DomHandler.getOuterWidth(prev) + parseFloat(prev.style.left || 0);
                break;
              }
              prev = prev.previousElementSibling;
            }
          } else {
            var targetLeft = elementRef.current.offsetLeft - scrollLeft;
            var frozenCells = Array.from(thead.querySelectorAll("th.p-frozen-column"));
            var candidate = null;
            for (var _i = 0, _frozenCells = frozenCells; _i < _frozenCells.length; _i++) {
              var cell = _frozenCells[_i];
              var cellLeft = cell.offsetLeft - scrollLeft;
              var cellRight = cellLeft + DomHandler.getOuterWidth(cell);
              if (cellRight <= targetLeft && (!candidate || cellRight > candidate.offsetLeft + DomHandler.getOuterWidth(candidate))) {
                candidate = cell;
              }
            }
            if (candidate) {
              left = candidate.offsetLeft + DomHandler.getOuterWidth(candidate) - scrollLeft;
            }
          }
        }
        styleObject.left = left + "px";
      }
      var filterRow = elementRef.current.parentElement.nextElementSibling;
      if (filterRow) {
        var _index = DomHandler.index(elementRef.current);
        if (filterRow.children[_index]) {
          filterRow.children[_index].style.left = styleObject.left;
          filterRow.children[_index].style.right = styleObject.right;
        }
      }
      var isSameStyle = styleObjectState.left === styleObject.left && styleObjectState.right === styleObject.right;
      !isSameStyle && setStyleObjectState(styleObject);
    }
  };
  var updateSortableDisabled = function updateSortableDisabled2(prevColumn2) {
    if (getColumnProp(prevColumn2, "sortableDisabled") !== getColumnProp("sortableDisabled") || getColumnProp(prevColumn2, "sortable") !== getColumnProp("sortable")) {
      props.onSortableChange();
    }
  };
  var _onClick = function onClick(event) {
    if (!isSortableDisabled()) {
      var targetNode = event.target;
      if (DomHandler.getAttribute(targetNode, "data-p-sortable-column") === true || DomHandler.getAttribute(targetNode, "data-pc-section") === "headertitle" || DomHandler.getAttribute(targetNode, "data-pc-section") === "headercontent" || DomHandler.getAttribute(targetNode, "data-pc-section") === "sortIcon" || DomHandler.getAttribute(targetNode.parentElement, "data-pc-section") === "sortIcon" || targetNode.closest('[data-p-sortable-column="true"]') && !targetNode.closest('[data-pc-section="filtermenubutton"]')) {
        DomHandler.clearSelection();
        props.onSortChange({
          originalEvent: event,
          column: props.column,
          sortableDisabledFields: props.sortableDisabledFields
        });
      }
    }
  };
  var _onMouseDown = function onMouseDown(event) {
    props.onColumnMouseDown({
      originalEvent: event,
      column: props.column
    });
  };
  var _onKeyDown = function onKeyDown(event) {
    if ((event.code == "Enter" || event.code === "NumpadEnter" || event.code == "Space") && event.target === elementRef.current && DomHandler.getAttribute(event.currentTarget, "data-p-sortable-column") === true) {
      _onClick(event);
      event.preventDefault();
    }
  };
  var _onDragStart = function onDragStart(event) {
    props.onColumnDragStart({
      originalEvent: event,
      column: props.column
    });
  };
  var _onDragOver = function onDragOver(event) {
    props.onColumnDragOver({
      originalEvent: event,
      column: props.column
    });
  };
  var _onDragLeave = function onDragLeave(event) {
    props.onColumnDragLeave({
      originalEvent: event,
      column: props.column
    });
  };
  var _onDrop = function onDrop(event) {
    props.onColumnDrop({
      originalEvent: event,
      column: props.column
    });
  };
  var onResizeStart = function onResizeStart2(event) {
    props.onColumnResizeStart({
      originalEvent: event,
      column: props.column
    });
  };
  var onResizerClick = function onResizerClick2(event) {
    if (props.onColumnResizerClick) {
      props.onColumnResizerClick({
        originalEvent: event,
        element: event.currentTarget.parentElement,
        column: props.column
      });
      event.preventDefault();
    }
  };
  var onResizerDoubleClick = function onResizerDoubleClick2(event) {
    if (props.onColumnResizerDoubleClick) {
      props.onColumnResizerDoubleClick({
        originalEvent: event,
        element: event.currentTarget.parentElement,
        column: props.column
      });
      event.preventDefault();
    }
  };
  React.useEffect(function() {
    if (getColumnProp("frozen")) {
      updateStickyPosition();
    }
    updateSortableDisabled(prevColumn);
  });
  var createResizer = function createResizer2() {
    if (props.resizableColumns && !getColumnProp("frozen")) {
      var columnResizerProps = mergeProps({
        className: cx("columnResizer"),
        onMouseDown: function onMouseDown(e) {
          return onResizeStart(e);
        },
        onTouchStart: function onTouchStart(e) {
          return onResizeStart(e);
        },
        onClick: function onClick(e) {
          return onResizerClick(e);
        },
        onDoubleClick: function onDoubleClick(e) {
          return onResizerDoubleClick(e);
        }
      }, getColumnPTOptions("columnResizer"));
      return /* @__PURE__ */ React.createElement("span", columnResizerProps);
    }
    return null;
  };
  var createTitle = function createTitle2() {
    var title = ObjectUtils.getJSXElement(getColumnProp("header"), {
      props: props.tableProps
    });
    var headerTitleProps = mergeProps({
      className: cx("headerTitle")
    }, getColumnPTOptions("headerTitle"));
    return /* @__PURE__ */ React.createElement("span", headerTitleProps, title);
  };
  var createSortIcon = function createSortIcon2(_ref2) {
    var sorted = _ref2.sorted, sortOrder = _ref2.sortOrder;
    if (getColumnProp("sortable")) {
      var sortIconProps = mergeProps({
        className: cx("sortIcon")
      }, getColumnPTOptions("sortIcon"));
      var sortProps = mergeProps(getColumnPTOptions("sort"));
      var icon = sorted ? sortOrder < 0 ? /* @__PURE__ */ React.createElement(SortAmountDownIcon, sortIconProps) : /* @__PURE__ */ React.createElement(SortAmountUpAltIcon, sortIconProps) : /* @__PURE__ */ React.createElement(SortAltIcon, sortIconProps);
      var sortIcon = IconUtils.getJSXIcon(props.sortIcon || icon, _objectSpread$2({}, sortIconProps), {
        props,
        sorted,
        sortOrder
      });
      return /* @__PURE__ */ React.createElement("span", sortProps, sortIcon);
    }
    return null;
  };
  var createBadge = function createBadge2(_ref3) {
    var metaIndex = _ref3.metaIndex;
    if (metaIndex !== -1 && isBadgeVisible()) {
      var value = props.groupRowsBy && props.groupRowsBy === props.groupRowSortField ? metaIndex : metaIndex + 1;
      var sortBadgeProps = mergeProps({
        className: cx("sortBadge")
      }, getColumnPTOptions("root"), getColumnPTOptions("sortBadge"));
      return /* @__PURE__ */ React.createElement("span", sortBadgeProps, value);
    }
    return null;
  };
  var createCheckbox = function createCheckbox2() {
    if (props.showSelectAll && getColumnProp("selectionMode") === "multiple" && props.filterDisplay !== "row") {
      var allRowsSelected = props.allRowsSelected(props.value);
      return /* @__PURE__ */ React.createElement(HeaderCheckbox, {
        hostName: props.hostName,
        column: props.column,
        checked: allRowsSelected,
        onChange: props.onColumnCheckboxChange,
        disabled: props.empty,
        ptCallbacks,
        metaData: parentMetaData,
        unstyled: props.unstyled
      });
    }
    return null;
  };
  var createFilter = function createFilter2() {
    if (props.filterDisplay === "menu" && getColumnProp("filter")) {
      return /* @__PURE__ */ React.createElement(ColumnFilter, {
        hostName: props.hostName,
        display: "menu",
        column: props.column,
        filters: props.filters,
        onFilterChange: props.onFilterChange,
        onFilterApply: props.onFilterApply,
        filtersStore: props.filtersStore,
        filterIcon: props.filterIcon,
        filterClearIcon: props.filterClearIcon,
        ptCallbacks,
        metaData: parentMetaData,
        unstyled: props.unstyled
      });
    }
    return null;
  };
  var createHeader = function createHeader2(sortMeta) {
    var title = createTitle();
    var sortIcon = createSortIcon(sortMeta);
    var badge = createBadge(sortMeta);
    var checkbox = createCheckbox();
    var filter = createFilter();
    var headerContentProps = mergeProps({
      className: cx("headerContent")
    }, getColumnPTOptions("headerContent"));
    return /* @__PURE__ */ React.createElement("div", headerContentProps, title, sortIcon, badge, checkbox, filter);
  };
  var createElement = function createElement2() {
    var _isSortableDisabled = isSortableDisabled();
    var sortMeta = getSortMeta();
    var style = getStyle();
    var align = getColumnProp("alignHeader") || getColumnProp("align");
    var frozen = getColumnProp("frozen");
    var tabIndex = getColumnProp("sortable") && !_isSortableDisabled ? props.tabIndex : null;
    var colSpan = getColumnProp("colSpan");
    var rowSpan = getColumnProp("rowSpan");
    var ariaSort = getAriaSort(sortMeta);
    var headerTooltip = getColumnProp("headerTooltip");
    var headerClassName = getColumnProp("headerClassName");
    var hasTooltip = ObjectUtils.isNotEmpty(headerTooltip);
    var headerTooltipOptions = getColumnProp("headerTooltipOptions");
    var resizer = createResizer();
    var header = createHeader(sortMeta);
    var headerCellProps = mergeProps({
      className: classNames(headerClassName, cx("headerCell", {
        headerProps: props,
        frozen,
        sortMeta,
        align,
        _isSortableDisabled,
        getColumnProp
      })),
      style,
      role: "columnheader",
      onClick: function onClick(e) {
        return _onClick(e);
      },
      onKeyDown: function onKeyDown(e) {
        return _onKeyDown(e);
      },
      onMouseDown: function onMouseDown(e) {
        return _onMouseDown(e);
      },
      onDragStart: function onDragStart(e) {
        return _onDragStart(e);
      },
      onDragOver: function onDragOver(e) {
        return _onDragOver(e);
      },
      onDragLeave: function onDragLeave(e) {
        return _onDragLeave(e);
      },
      onDrop: function onDrop(e) {
        return _onDrop(e);
      },
      tabIndex,
      colSpan,
      rowSpan,
      "aria-sort": ariaSort,
      "data-p-sortable-column": getColumnProp("sortable"),
      "data-p-resizable-column": props.resizableColumns,
      "data-p-highlight": sortMeta.sorted,
      "data-p-filter-column": !props.metaData.props.headerColumnGroup && props.filterDisplay === "row",
      "data-p-frozen-column": getColumnProp("frozen"),
      "data-p-reorderable-column": props.reorderableColumns
    }, getColumnPTOptions("root"), getColumnPTOptions("headerCell"));
    return /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement("th", _extends({
      ref: elementRef
    }, headerCellProps), resizer, header), hasTooltip && /* @__PURE__ */ React.createElement(Tooltip, _extends({
      target: elementRef,
      content: headerTooltip,
      pt: getColumnPTOptions("tooltip"),
      unstyled: props.unstyled
    }, headerTooltipOptions)));
  };
  var element = createElement();
  return element;
});
HeaderCell.displayName = "HeaderCell";
var _excluded = ["unstyled", "__TYPE", "ptOptions"];
function ownKeys$1(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread$1(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys$1(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys$1(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var TableHeader = /* @__PURE__ */ React.memo(function(props) {
  var _React$useState = React.useState([]), _React$useState2 = _slicedToArray(_React$useState, 2), sortableDisabledFieldsState = _React$useState2[0], setSortableDisabledFieldsState = _React$useState2[1];
  var _React$useState3 = React.useState(false), _React$useState4 = _slicedToArray(_React$useState3, 2), allSortableDisabledState = _React$useState4[0], setAllSortableDisabledState = _React$useState4[1];
  var mergeProps = useMergeProps();
  var isSingleSort = props.sortMode === "single";
  var isMultipleSort = props.sortMode === "multiple";
  var isAllSortableDisabled = isSingleSort && allSortableDisabledState;
  var _props$ptCallbacks = props.ptCallbacks, ptm = _props$ptCallbacks.ptm, ptmo = _props$ptCallbacks.ptmo, cx = _props$ptCallbacks.cx;
  var context = React.useContext(PrimeReactContext);
  var getColumnProp = function getColumnProp2(column, name) {
    return ColumnBase.getCProp(column, name);
  };
  var getColumnProps = function getColumnProps2(column) {
    return ColumnBase.getCProps(column);
  };
  var getRowProps = function getRowProps2(row) {
    return ColumnGroupBase.getCProps(row);
  };
  var getColumnGroupProps = function getColumnGroupProps2() {
    return props.headerColumnGroup ? ptmo(ColumnGroupBase.getCProps(props.headerColumnGroup)) : void 0;
  };
  var getColumnGroupPTOptions = function getColumnGroupPTOptions2(key) {
    var cGProps = getColumnGroupProps();
    var columnGroupMetaData = {
      props: cGProps,
      parent: props.metaData,
      hostName: props.hostName,
      state: {
        sortableDisabledFields: sortableDisabledFieldsState,
        allSortableDisabled: allSortableDisabledState
      }
    };
    return mergeProps(ptm("columnGroup.".concat(key), {
      columnGroup: columnGroupMetaData
    }), ptm("columnGroup.".concat(key), columnGroupMetaData), ptmo(cGProps, key, columnGroupMetaData));
  };
  var getColumnPTOptions = function getColumnPTOptions2(column, key) {
    var cProps = getColumnProps(column);
    var columnMetaData = {
      props: cProps,
      parent: props.metaData,
      hostName: props.hostName,
      state: {
        sortableDisabledFields: sortableDisabledFieldsState,
        allSortableDisabled: allSortableDisabledState
      }
    };
    return mergeProps(ptm("column.".concat(key), {
      column: columnMetaData
    }), ptm("column.".concat(key), columnMetaData), ptmo(cProps, key, columnMetaData));
  };
  var getRowPTOptions = function getRowPTOptions2(row, key) {
    var rProps = getRowProps(row);
    var rowMetaData = {
      props: rProps,
      parent: props.metaData,
      hostName: props.hostName
    };
    return mergeProps(ptm("row.".concat(key), {
      row: rowMetaData
    }), ptm("row.".concat(key), rowMetaData), ptmo(rProps, key, rowMetaData));
  };
  var isColumnSorted = function isColumnSorted2(column) {
    return props.sortField !== null ? getColumnProp(column, "field") === props.sortField || getColumnProp(column, "sortField") === props.sortField : false;
  };
  var updateSortableDisabled = function updateSortableDisabled2() {
    if (isSingleSort || isMultipleSort && props.onSortChange) {
      var sortableDisabledFields = [];
      var allSortableDisabled = false;
      props.columns.forEach(function(column) {
        if (getColumnProp(column, "sortableDisabled")) {
          sortableDisabledFields.push(getColumnProp(column, "sortField") || getColumnProp(column, "field"));
          if (!allSortableDisabled && isColumnSorted(column)) {
            allSortableDisabled = true;
          }
        }
      });
      setSortableDisabledFieldsState(sortableDisabledFields);
      setAllSortableDisabledState(allSortableDisabled);
    }
  };
  var onSortableChange = function onSortableChange2() {
    updateSortableDisabled();
  };
  var onCheckboxChange = function onCheckboxChange2(e) {
    props.onColumnCheckboxChange(e, props.value);
  };
  useMountEffect(function() {
    updateSortableDisabled();
  });
  var createGroupHeaderCells = function createGroupHeaderCells2(row) {
    var columns = React.Children.toArray(RowBase.getCProp(row, "children"));
    return createHeaderCells(columns);
  };
  var createHeaderCells = function createHeaderCells2(columns) {
    return React.Children.map(columns, function(col, i) {
      var isVisible = col ? !getColumnProp(col, "hidden") : true;
      var key = col ? getColumnProp(col, "columnKey") || getColumnProp(col, "field") || i : i;
      return isVisible && /* @__PURE__ */ React.createElement(HeaderCell, {
        hostName: props.hostName,
        allRowsSelected: props.allRowsSelected,
        allSortableDisabled: isAllSortableDisabled,
        column: col,
        index: i,
        empty: props.empty,
        filterClearIcon: props.filterClearIcon,
        filterDisplay: props.filterDisplay,
        filterIcon: props.filterIcon,
        filters: props.filters,
        filtersStore: props.filtersStore,
        groupRowSortField: props.groupRowSortField,
        groupRowsBy: props.groupRowsBy,
        key,
        multiSortMeta: props.multiSortMeta,
        onColumnCheckboxChange: onCheckboxChange,
        onColumnDragLeave: props.onColumnDragLeave,
        onColumnDragOver: props.onColumnDragOver,
        onColumnDragStart: props.onColumnDragStart,
        onColumnDrop: props.onColumnDrop,
        onColumnMouseDown: props.onColumnMouseDown,
        onColumnResizeStart: props.onColumnResizeStart,
        onColumnResizerClick: props.onColumnResizerClick,
        onColumnResizerDoubleClick: props.onColumnResizerDoubleClick,
        onFilterApply: props.onFilterApply,
        onFilterChange: props.onFilterChange,
        onSortChange: props.onSortChange,
        onSortableChange,
        reorderableColumns: props.reorderableColumns,
        resizableColumns: props.resizableColumns,
        showSelectAll: props.showSelectAll,
        sortField: props.sortField,
        sortIcon: props.sortIcon,
        sortMode: props.sortMode,
        sortOrder: props.sortOrder,
        sortableDisabledFields: sortableDisabledFieldsState,
        tabIndex: props.tabIndex,
        tableProps: props.tableProps,
        value: props.value,
        ptCallbacks: props.ptCallbacks,
        metaData: props.metaData,
        unstyled: props.unstyled
      });
    });
  };
  var createCheckbox = function createCheckbox2(column, selectionMode) {
    if (props.showSelectAll && selectionMode === "multiple") {
      var allRowsSelected = props.allRowsSelected(props.value);
      return /* @__PURE__ */ React.createElement(HeaderCheckbox, {
        hostName: props.hostName,
        column,
        checked: allRowsSelected,
        onChange: onCheckboxChange,
        disabled: props.empty,
        ptCallbacks: props.ptCallbacks,
        metaData: props.metaData,
        unstyled: props.unstyled
      });
    }
    return null;
  };
  var createFilter = function createFilter2(column, filter) {
    if (filter) {
      return /* @__PURE__ */ React.createElement(ColumnFilter, {
        hostName: props.hostName,
        display: "row",
        column,
        filterClearIcon: props.filterClearIcon,
        filterIcon: props.filterIcon,
        filters: props.filters,
        filtersStore: props.filtersStore,
        metaData: props.metaData,
        onFilterApply: props.onFilterApply,
        onFilterChange: props.onFilterChange,
        ptCallbacks: props.ptCallbacks,
        unstyled: props.unstyled
      });
    }
    return null;
  };
  var createFilterCells = function createFilterCells2() {
    return React.Children.map(props.columns, function(col, i) {
      var isVisible = !getColumnProp(col, "hidden");
      if (isVisible) {
        var _ColumnBase$getCProps = ColumnBase.getCProps(col), filterHeaderStyle = _ColumnBase$getCProps.filterHeaderStyle, style = _ColumnBase$getCProps.style, filterHeaderClassName = _ColumnBase$getCProps.filterHeaderClassName, className = _ColumnBase$getCProps.className, frozen = _ColumnBase$getCProps.frozen, columnKey = _ColumnBase$getCProps.columnKey, field = _ColumnBase$getCProps.field, selectionMode = _ColumnBase$getCProps.selectionMode, filter = _ColumnBase$getCProps.filter;
        var colStyle = _objectSpread$1(_objectSpread$1({}, filterHeaderStyle || {}), style || {});
        var colKey = columnKey || field || i;
        var checkbox = createCheckbox(col, selectionMode);
        var filterRow = createFilter(col, filter);
        var headerCellProps = mergeProps({
          style: colStyle,
          className: classNames(filterHeaderClassName, className, cx("headerCell", {
            frozen,
            column: col
          }))
        }, getColumnPTOptions(col, "root"), getColumnPTOptions(col, "headerCell"));
        return /* @__PURE__ */ React.createElement("th", _extends({
          key: colKey
        }, headerCellProps), checkbox, filterRow);
      }
      return null;
    });
  };
  var createContent = function createContent2() {
    if (props.headerColumnGroup) {
      var rows = React.Children.toArray(ColumnGroupBase.getCProp(props.headerColumnGroup, "children"));
      return rows.map(function(row, i) {
        var _RowBase$getProps = RowBase.getProps(row.props, context), unstyled = _RowBase$getProps.unstyled;
        _RowBase$getProps.__TYPE;
        _RowBase$getProps.ptOptions;
        var rest = _objectWithoutProperties(_RowBase$getProps, _excluded);
        var headerRowProps2 = mergeProps({
          role: "row"
        }, unstyled ? _objectSpread$1({
          unstyled
        }, rest) : rest, getRowPTOptions(row, "root"));
        return /* @__PURE__ */ React.createElement("tr", _extends({}, headerRowProps2, {
          key: i
        }), createGroupHeaderCells(row));
      });
    }
    var headerRowProps = mergeProps({
      role: "row"
    }, ptm("headerRow", {
      hostName: props.hostName
    }));
    var headerRow = /* @__PURE__ */ React.createElement("tr", headerRowProps, createHeaderCells(props.columns));
    var filterRow = props.filterDisplay === "row" && /* @__PURE__ */ React.createElement("tr", headerRowProps, createFilterCells());
    return /* @__PURE__ */ React.createElement(React.Fragment, null, headerRow, filterRow);
  };
  var content = createContent();
  var theadProps = mergeProps({
    className: cx("thead"),
    role: "rowgroup"
  }, getColumnGroupPTOptions("root"), ptm("thead", {
    hostName: props.hostName
  }));
  return /* @__PURE__ */ React.createElement("thead", theadProps, content);
});
TableHeader.displayName = "TableHeader";
function _createForOfIteratorHelper(r, e) {
  var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"];
  if (!t) {
    if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e) {
      t && (r = t);
      var _n = 0, F = function F2() {
      };
      return { s: F, n: function n() {
        return _n >= r.length ? { done: true } : { done: false, value: r[_n++] };
      }, e: function e2(r2) {
        throw r2;
      }, f: F };
    }
    throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.");
  }
  var o, a = true, u = false;
  return { s: function s() {
    t = t.call(r);
  }, n: function n() {
    var r2 = t.next();
    return a = r2.done, r2;
  }, e: function e2(r2) {
    u = true, o = r2;
  }, f: function f() {
    try {
      a || null == t["return"] || t["return"]();
    } finally {
      if (u) throw o;
    }
  } };
}
function _unsupportedIterableToArray(r, a) {
  if (r) {
    if ("string" == typeof r) return _arrayLikeToArray(r, a);
    var t = {}.toString.call(r).slice(8, -1);
    return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0;
  }
}
function _arrayLikeToArray(r, a) {
  (null == a || a > r.length) && (a = r.length);
  for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e];
  return n;
}
function ownKeys(e, r) {
  var t = Object.keys(e);
  if (Object.getOwnPropertySymbols) {
    var o = Object.getOwnPropertySymbols(e);
    r && (o = o.filter(function(r2) {
      return Object.getOwnPropertyDescriptor(e, r2).enumerable;
    })), t.push.apply(t, o);
  }
  return t;
}
function _objectSpread(e) {
  for (var r = 1; r < arguments.length; r++) {
    var t = null != arguments[r] ? arguments[r] : {};
    r % 2 ? ownKeys(Object(t), true).forEach(function(r2) {
      _defineProperty(e, r2, t[r2]);
    }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function(r2) {
      Object.defineProperty(e, r2, Object.getOwnPropertyDescriptor(t, r2));
    });
  }
  return e;
}
var DataTable = /* @__PURE__ */ React.forwardRef(function(inProps, ref) {
  var context = React.useContext(PrimeReactContext);
  var mergeProps = useMergeProps();
  var props = DataTableBase.getProps(inProps, context);
  var _React$useState = React.useState(props.first), _React$useState2 = _slicedToArray(_React$useState, 2), firstState = _React$useState2[0], setFirstState = _React$useState2[1];
  var _React$useState3 = React.useState(props.rows), _React$useState4 = _slicedToArray(_React$useState3, 2), rowsState = _React$useState4[0], setRowsState = _React$useState4[1];
  var _React$useState5 = React.useState(props.sortField), _React$useState6 = _slicedToArray(_React$useState5, 2), sortFieldState = _React$useState6[0], setSortFieldState = _React$useState6[1];
  var _React$useState7 = React.useState(props.sortOrder), _React$useState8 = _slicedToArray(_React$useState7, 2), sortOrderState = _React$useState8[0], setSortOrderState = _React$useState8[1];
  var _React$useState9 = React.useState(props.multiSortMeta), _React$useState0 = _slicedToArray(_React$useState9, 2), multiSortMetaState = _React$useState0[0], setMultiSortMetaState = _React$useState0[1];
  var _React$useState1 = React.useState(props.filters), _React$useState10 = _slicedToArray(_React$useState1, 2), filtersState = _React$useState10[0], setFiltersState = _React$useState10[1];
  var _React$useState11 = React.useState([]), _React$useState12 = _slicedToArray(_React$useState11, 2), columnOrderState = _React$useState12[0], setColumnOrderState = _React$useState12[1];
  var _React$useState13 = React.useState(null), _React$useState14 = _slicedToArray(_React$useState13, 2), groupRowsSortMetaState = _React$useState14[0], setGroupRowsSortMetaState = _React$useState14[1];
  var _React$useState15 = React.useState({}), _React$useState16 = _slicedToArray(_React$useState15, 2), editingMetaState = _React$useState16[0], setEditingMetaState = _React$useState16[1];
  var _React$useState17 = React.useState({}), _React$useState18 = _slicedToArray(_React$useState17, 2), frozenEditingMetaState = _React$useState18[0], setFrozenEditingMetaState = _React$useState18[1];
  var _React$useState19 = React.useState(props.rows), _React$useState20 = _slicedToArray(_React$useState19, 2), d_rowsState = _React$useState20[0], setD_rowsState = _React$useState20[1];
  var _React$useState21 = React.useState({}), _React$useState22 = _slicedToArray(_React$useState21, 2), d_filtersState = _React$useState22[0], setD_filtersState = _React$useState22[1];
  var metaData = {
    props,
    state: {
      first: firstState,
      rows: rowsState,
      sortField: sortFieldState,
      sortOrder: sortOrderState,
      multiSortMeta: multiSortMetaState,
      filters: filtersState,
      columnOrder: columnOrderState,
      groupRowsSortMeta: groupRowsSortMetaState,
      editingMeta: editingMetaState,
      frozenEditingMeta: frozenEditingMetaState,
      d_rows: d_rowsState,
      d_filters: d_filtersState
    },
    context: {
      scrollable: props.scrollable
    }
  };
  var ptCallbacks = DataTableBase.setMetaData(metaData);
  useHandleStyle(DataTableBase.css.styles, ptCallbacks.isUnstyled, {
    name: "datatable"
  });
  var attributeSelector = React.useRef("");
  var elementRef = React.useRef(null);
  var tableRef = React.useRef(null);
  var wrapperRef = React.useRef(null);
  var bodyRef = React.useRef(null);
  var frozenBodyRef = React.useRef(null);
  var virtualScrollerRef = React.useRef(null);
  var reorderIndicatorUpRef = React.useRef(null);
  var reorderIndicatorDownRef = React.useRef(null);
  var colReorderIconWidth = React.useRef(null);
  var colReorderIconHeight = React.useRef(null);
  var resizeHelperRef = React.useRef(null);
  var draggedColumnElement = React.useRef(null);
  var draggedColumn = React.useRef(null);
  var dropPosition = React.useRef(null);
  var styleElement = React.useRef(null);
  var responsiveStyleElement = React.useRef(null);
  var beforeResizeStyleElement = React.useRef(null);
  var columnWidthsState = React.useRef(null);
  var tableWidthState = React.useRef(null);
  var resizeColumn = React.useRef(null);
  var resizeColumnElement = React.useRef(null);
  var columnResizing = React.useRef(false);
  var lastResizeHelperX = React.useRef(null);
  var columnSortable = React.useRef(false);
  var columnSortFunction = React.useRef(null);
  var columnField = React.useRef(null);
  var filterTimeout = React.useRef(null);
  var restoredRef = React.useRef(false);
  if (props.rows !== d_rowsState && !props.onPage) {
    setRowsState(props.rows);
    setD_rowsState(props.rows);
  }
  var columnResizeStartListener = function columnResizeStartListener2(event) {
    return columnResizing.current && onColumnResize(event);
  };
  var columnResizeEndListener = function columnResizeEndListener2() {
    return columnResizing.current && (columnResizing.current = false, onColumnResizeEnd());
  };
  var _useEventListener = useEventListener({
    type: "mousemove",
    listener: columnResizeStartListener
  }), _useEventListener2 = _slicedToArray(_useEventListener, 2), bindDocumentMouseMoveListener = _useEventListener2[0], unbindDocumentMouseMoveListener = _useEventListener2[1];
  var _useEventListener3 = useEventListener({
    type: "mouseup",
    listener: columnResizeEndListener
  }), _useEventListener4 = _slicedToArray(_useEventListener3, 2), bindDocumentMouseUpListener = _useEventListener4[0], unbindDocumentMouseUpListener = _useEventListener4[1];
  var _useEventListener5 = useEventListener({
    type: "touchmove",
    listener: columnResizeStartListener
  }), _useEventListener6 = _slicedToArray(_useEventListener5, 2), bindDocumentTouchMoveListener = _useEventListener6[0], unbindDocumentTouchMoveListener = _useEventListener6[1];
  var _useEventListener7 = useEventListener({
    type: "touchend",
    listener: columnResizeEndListener
  }), _useEventListener8 = _slicedToArray(_useEventListener7, 2), bindDocumentTouchEndListener = _useEventListener8[0], unbindDocumentTouchEndListener = _useEventListener8[1];
  var isCustomStateStorage = function isCustomStateStorage2() {
    return props.stateStorage === "custom";
  };
  var isStateful = function isStateful2() {
    return props.stateKey != null || isCustomStateStorage();
  };
  var isVirtualScrollerDisabled = function isVirtualScrollerDisabled2() {
    return ObjectUtils.isEmpty(props.virtualScrollerOptions) || !props.scrollable;
  };
  var isEquals = function isEquals2(data1, data2) {
    return props.compareSelectionBy === "equals" ? data1 === data2 : ObjectUtils.equals(data1, data2, props.dataKey);
  };
  var hasFilter = function hasFilter2() {
    return ObjectUtils.isNotEmpty(getFilters()) || props.globalFilter;
  };
  var getFirst = function getFirst2() {
    return props.onPage ? props.first : firstState;
  };
  var getRows = function getRows2() {
    return props.onPage ? props.rows : rowsState;
  };
  var getSortField = function getSortField2() {
    return props.onSort ? props.sortField : sortFieldState;
  };
  var getSortOrder = function getSortOrder2() {
    return props.onSort ? props.sortOrder : sortOrderState;
  };
  var getMultiSortMeta = function getMultiSortMeta2() {
    return (props.onSort ? props.multiSortMeta : multiSortMetaState) || [];
  };
  var getFilters = function getFilters2() {
    return props.onFilter ? props.filters : filtersState;
  };
  var getColumnProp = function getColumnProp2(column, name) {
    return ColumnBase.getCProp(column, name);
  };
  var getColumns = function getColumns2(ignoreReorderable) {
    var columns2 = React.Children.toArray(props.children);
    if (!columns2) {
      return null;
    }
    if (!ignoreReorderable && props.reorderableColumns && columnOrderState) {
      var orderedColumns = columnOrderState.reduce(function(arr, columnKey) {
        var column = findColumnByKey(columns2, columnKey);
        column && arr.push(column);
        return arr;
      }, []);
      return [].concat(_toConsumableArray(orderedColumns), _toConsumableArray(columns2.filter(function(col) {
        return orderedColumns.indexOf(col) < 0;
      })));
    }
    return columns2;
  };
  var saveState = function saveState2() {
    var state = {};
    if (props.paginator) {
      state.first = getFirst();
      state.rows = getRows();
    }
    var sortField = getSortField();
    if (sortField) {
      state.sortField = sortField;
      state.sortOrder = getSortOrder();
    }
    var multiSortMeta = getMultiSortMeta();
    if (multiSortMeta) {
      state.multiSortMeta = multiSortMeta;
    }
    if (hasFilter()) {
      state.filters = getFilters();
    }
    if (props.resizableColumns) {
      saveColumnWidths(state);
    }
    if (props.reorderableColumns) {
      state.columnOrder = columnOrderState;
    }
    if (props.expandedRows) {
      state.expandedRows = props.expandedRows;
    }
    if (props.selection && props.onSelectionChange) {
      state.selection = props.selection;
    }
    if (isCustomStateStorage()) {
      if (props.customSaveState) {
        props.customSaveState(state);
      }
    } else {
      var storage = getStorage(props.stateStorage);
      if (ObjectUtils.isNotEmpty(state)) {
        storage.setItem(props.stateKey, JSON.stringify(state));
      }
    }
    if (props.onStateSave) {
      props.onStateSave(state);
    }
  };
  var clearState = function clearState2() {
    var storage = getStorage(props.stateStorage);
    if (storage && props.stateKey) {
      storage.removeItem(props.stateKey);
    }
  };
  var restoreState = function restoreState2() {
    var restoredState = {};
    if (isCustomStateStorage()) {
      if (props.customRestoreState) {
        restoredState = props.customRestoreState();
      }
    } else {
      var storage = getStorage(props.stateStorage);
      var stateString = storage.getItem(props.stateKey);
      var dateFormat = /\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.\d{3}Z/;
      var reviver = function reviver2(key, value) {
        return typeof value === "string" && dateFormat.test(value) ? new Date(value) : value;
      };
      if (stateString) {
        restoredState = JSON.parse(stateString, reviver);
      }
    }
    _restoreState(restoredState);
  };
  var restoreTableState = function restoreTableState2(restoredState) {
    _restoreState(restoredState);
  };
  var _restoreState = function _restoreState2() {
    var restoredState = arguments.length > 0 && arguments[0] !== void 0 ? arguments[0] : {};
    if (ObjectUtils.isNotEmpty(restoredState)) {
      if (props.paginator) {
        if (props.onPage) {
          var getOnPageParams = function getOnPageParams2(first, rows) {
            var totalRecords2 = getTotalRecords(processedData());
            var pageCount = Math.ceil(totalRecords2 / rows) || 1;
            var page = Math.floor(first / rows);
            return {
              first,
              rows,
              page,
              pageCount
            };
          };
          props.onPage(createEvent(getOnPageParams(restoredState.first, restoredState.rows)));
        } else {
          setFirstState(restoredState.first);
          setRowsState(restoredState.rows);
        }
      }
      if (restoredState.sortField) {
        if (props.onSort) {
          props.onSort(createEvent({
            sortField: restoredState.sortField,
            sortOrder: restoredState.sortOrder
          }));
        } else {
          setSortFieldState(restoredState.sortField);
          setSortOrderState(restoredState.sortOrder);
        }
      }
      if (restoredState.multiSortMeta) {
        if (props.onSort) {
          props.onSort(createEvent({
            multiSortMeta: restoredState.multiSortMeta
          }));
        } else {
          var lastMeta = restoredState.multiSortMeta[restoredState.multiSortMeta.length - 1];
          var field = lastMeta && lastMeta.field ? lastMeta.field : null;
          if (field) {
            var sortColumn = findColumnByKey(getColumns(), field);
            if (sortColumn) {
              var sortFunction = getColumnProp(sortColumn, "sortFunction");
              var sortable = getColumnProp(sortColumn, "sortable");
              if (sortFunction && sortable) {
                columnSortFunction.current = sortFunction;
                columnSortable.current = sortable;
                columnField.current = field;
              }
            }
          }
          setMultiSortMetaState(restoredState.multiSortMeta);
        }
      }
      if (restoredState.filters) {
        var clonedFilters = cloneFilters(restoredState.filters);
        setD_filtersState(clonedFilters);
        if (props.onFilter) {
          props.onFilter(createEvent({
            filters: clonedFilters
          }));
        } else {
          setFiltersState(clonedFilters);
        }
      }
      if (props.resizableColumns) {
        columnWidthsState.current = restoredState.columnWidths;
        tableWidthState.current = restoredState.tableWidth;
        restoreColumnWidths();
      }
      if (props.reorderableColumns) {
        setColumnOrderState(restoredState.columnOrder);
      }
      if (restoredState.expandedRows && props.onRowToggle) {
        props.onRowToggle({
          data: restoredState.expandedRows
        });
      }
      if (restoredState.selection && props.onSelectionChange) {
        props.onSelectionChange({
          value: restoredState.selection
        });
      }
      if (props.onStateRestore) {
        props.onStateRestore(restoredState);
      }
    }
  };
  var saveColumnWidths = function saveColumnWidths2(state) {
    var widths = [];
    var headers = DomHandler.find(elementRef.current, '[data-pc-section="thead"] > tr > th');
    headers.forEach(function(header2) {
      return widths.push(DomHandler.getOuterWidth(header2));
    });
    state.columnWidths = widths.join(",");
    if (props.columnResizeMode === "expand") {
      state.tableWidth = DomHandler.getOuterWidth(tableRef.current) + "px";
    }
  };
  var addColumnWidthStyles = function addColumnWidthStyles2(widths) {
    createStyleElement();
    var innerHTML = "";
    var selector = '[data-pc-name="datatable"]['.concat(attributeSelector.current, '] > [data-pc-section="wrapper"] ').concat(isVirtualScrollerDisabled() ? "" : '> [data-pc-name="virtualscroller"]', ' > [data-pc-section="table"]');
    widths.forEach(function(width, index2) {
      var style = "width: ".concat(width, "px !important; max-width: ").concat(width, "px !important");
      innerHTML = innerHTML + "\n                ".concat(selector, ' > [data-pc-section="thead"] > tr > th:nth-child(').concat(index2 + 1, "),\n                ").concat(selector, ' > [data-pc-section="tbody"] > tr > td:nth-child(').concat(index2 + 1, "),\n                ").concat(selector, ' > [data-pc-section="tfoot"] > tr > td:nth-child(').concat(index2 + 1, ") {\n                    ").concat(style, "\n                }\n            ");
    });
    styleElement.current.innerHTML = innerHTML;
  };
  var restoreColumnWidths = function restoreColumnWidths2() {
    if (columnWidthsState.current) {
      var widths = columnWidthsState.current.split(",");
      if (props.columnResizeMode === "expand" && tableWidthState.current) {
        tableRef.current.style.width = tableWidthState.current;
        tableRef.current.style.minWidth = tableWidthState.current;
      }
      if (ObjectUtils.isNotEmpty(widths)) {
        addColumnWidthStyles(widths);
      }
    }
  };
  var findParentHeader = function findParentHeader2(element) {
    if (element.nodeName === "TH") {
      return element;
    }
    var parent = element.parentElement;
    while (parent.nodeName !== "TH") {
      parent = parent.parentElement;
      if (!parent) {
        break;
      }
    }
    return parent;
  };
  var getGroupRowSortField = function getGroupRowSortField2() {
    return props.sortMode === "single" ? props.sortField : groupRowsSortMetaState ? groupRowsSortMetaState.field : null;
  };
  var getSelectableData = function getSelectableData2(val) {
    if (props.showSelectionElement || props.isDataSelectable) {
      return val.filter(function(data2, index2) {
        var isSelectable = true;
        if (props.showSelectionElement) {
          isSelectable = props.showSelectionElement({
            rowIndex: index2,
            props
          });
        }
        if (props.isDataSelectable && isSelectable) {
          isSelectable = props.isDataSelectable({
            data: data2,
            index: index2
          });
        }
        return isSelectable;
      });
    }
    return val;
  };
  var allRowsSelected = function allRowsSelected2(processedData2) {
    if (props.onSelectAllChange) {
      return props.selectAll;
    }
    var data2 = props.selectionPageOnly ? dataToRender(processedData2) : processedData2;
    var val = ObjectUtils.isNotEmpty(props.frozenValue) ? [].concat(_toConsumableArray(props.frozenValue), _toConsumableArray(data2)) : data2;
    var selectableVal = getSelectableData(val);
    return ObjectUtils.isNotEmpty(selectableVal) && props.selection && selectableVal.every(function(sv) {
      return ObjectUtils.isArray(props.selection) && props.selection.some(function(s) {
        return isEquals(s, sv);
      });
    });
  };
  var getSelectionModeInColumn = function getSelectionModeInColumn2(columns2) {
    if (columns2) {
      var col = columns2.find(function(c) {
        return !!getColumnProp(c, "selectionMode");
      });
      return col ? getColumnProp(col, "selectionMode") : null;
    }
    return null;
  };
  var findColumnByKey = function findColumnByKey2(columns2, key) {
    return ObjectUtils.isNotEmpty(columns2) ? columns2.find(function(col) {
      return getColumnProp(col, "columnKey") === key || getColumnProp(col, "field") === key;
    }) : null;
  };
  var getTotalRecords = function getTotalRecords2(data2) {
    return props.lazy ? props.totalRecords : data2 ? data2.length : 0;
  };
  var onEditingMetaChange = function onEditingMetaChange2(e) {
    var rowData = e.rowData, field = e.field, editingKey = e.editingKey;
    e.rowIndex;
    var editing = e.editing;
    var editingMeta = _objectSpread({}, editingMetaState);
    var meta = editingMeta[editingKey];
    if (editing) {
      !meta && (meta = editingMeta[editingKey] = {
        data: _objectSpread({}, rowData),
        fields: []
      });
      meta.fields.push(field);
    } else if (meta) {
      var fields = meta.fields.filter(function(f) {
        return f !== field;
      });
      !fields.length ? delete editingMeta[editingKey] : meta.fields = fields;
    }
    setEditingMetaState(editingMeta);
  };
  var clearEditingMetaData = function clearEditingMetaData2() {
    if (props.editMode && ObjectUtils.isNotEmpty(editingMetaState)) {
      setEditingMetaState({});
    }
  };
  var onFrozenEditingMetaChange = function onFrozenEditingMetaChange2(e) {
    var rowData = e.rowData, field = e.field, editingKey = e.editingKey, editing = e.editing;
    var frozenEditingMeta = _objectSpread({}, frozenEditingMetaState);
    var meta = frozenEditingMeta[editingKey];
    if (editing) {
      !meta && (meta = frozenEditingMeta[editingKey] = {
        data: _objectSpread({}, rowData),
        fields: []
      });
      meta.fields.push(field);
    } else if (meta) {
      var fields = meta.fields.filter(function(f) {
        return f !== field;
      });
      !fields.length ? delete frozenEditingMeta[editingKey] : meta.fields = fields;
    }
    setFrozenEditingMetaState(frozenEditingMeta);
  };
  var clearFrozenEditingMetaData = function clearFrozenEditingMetaData2() {
    if (props.editMode && ObjectUtils.isNotEmpty(frozenEditingMetaState)) {
      setFrozenEditingMetaState({});
    }
  };
  var onColumnResizeStart = function onColumnResizeStart2(e) {
    createBeforeResizeStyleElement();
    var event = e.originalEvent, column = e.column;
    var containerLeft = DomHandler.getOffset(elementRef.current).left;
    resizeColumn.current = column;
    resizeColumnElement.current = event.currentTarget.parentElement;
    columnResizing.current = true;
    lastResizeHelperX.current = (event.type === "touchstart" ? event.changedTouches[0].clientX : event.pageX) - containerLeft + elementRef.current.scrollLeft;
    bindColumnResizeEvents();
  };
  var onColumnResize = function onColumnResize2(event) {
    var containerLeft = DomHandler.getOffset(elementRef.current).left;
    elementRef.current.setAttribute("data-p-unselectable-text", true);
    resizeHelperRef.current.style.height = elementRef.current.offsetHeight + "px";
    resizeHelperRef.current.style.top = "0px";
    resizeHelperRef.current.style.left = (event.type === "touchmove" ? event.changedTouches[0].clientX : event.pageX) - containerLeft + elementRef.current.scrollLeft + "px";
    resizeHelperRef.current.style.display = "block";
  };
  var onColumnResizeEnd = function onColumnResizeEnd2() {
    var delta = resizeHelperRef.current.offsetLeft - lastResizeHelperX.current;
    var columnWidth = resizeColumnElement.current.offsetWidth;
    var newColumnWidth = columnWidth + delta;
    var minWidth = resizeColumnElement.current.style.minWidth || 15;
    if (columnWidth + delta > parseInt(minWidth, 10)) {
      if (props.columnResizeMode === "fit") {
        var nextColumn = resizeColumnElement.current.nextElementSibling;
        var nextColumnWidth = nextColumn.offsetWidth - delta;
        if (newColumnWidth > 15 && nextColumnWidth > 15) {
          resizeTableCells(newColumnWidth, nextColumnWidth);
        }
      } else if (props.columnResizeMode === "expand") {
        var tableWidth = tableRef.current.offsetWidth + delta + "px";
        var updateTableWidth = function updateTableWidth2(el) {
          !!el && (el.style.width = el.style.minWidth = tableWidth);
        };
        resizeTableCells(newColumnWidth);
        updateTableWidth(tableRef.current);
        if (!isVirtualScrollerDisabled()) {
          updateTableWidth(bodyRef.current);
          updateTableWidth(frozenBodyRef.current);
          if (wrapperRef.current) {
            updateTableWidth(DomHandler.findSingle(wrapperRef.current, '[data-pc-name="virtualscroller"] > table > tbody'));
          }
        }
      }
      if (props.onColumnResizeEnd) {
        props.onColumnResizeEnd({
          element: resizeColumnElement.current,
          column: resizeColumn.current,
          delta
        });
      }
      if (isStateful()) {
        saveState();
      }
    }
    resizeHelperRef.current.style.display = "none";
    resizeColumn.current = null;
    resizeColumnElement.current = null;
    elementRef.current.setAttribute("data-p-unselectable-text", "true");
    destroyBeforeResizeStyleElement();
    unbindColumnResizeEvents();
  };
  var resizeTableCells = function resizeTableCells2(newColumnWidth, nextColumnWidth) {
    var widths = [];
    var colIndex = DomHandler.index(resizeColumnElement.current);
    var headers = DomHandler.find(tableRef.current, '[data-pc-section="thead"] > tr > th');
    headers.forEach(function(header2) {
      return widths.push(DomHandler.getOuterWidth(header2));
    });
    destroyStyleElement();
    createStyleElement();
    var innerHTML = "";
    var selector = '[data-pc-name="datatable"]['.concat(attributeSelector.current, '] > [data-pc-section="wrapper"] ').concat(isVirtualScrollerDisabled() ? "" : '> [data-pc-name="virtualscroller"]', ' > [data-pc-section="table"]');
    widths.forEach(function(width, index2) {
      var colWidth = index2 === colIndex ? newColumnWidth : nextColumnWidth && index2 === colIndex + 1 ? nextColumnWidth : width;
      var style = "width: ".concat(colWidth, "px !important; max-width: ").concat(colWidth, "px !important");
      innerHTML = innerHTML + "\n                 ".concat(selector, ' > [data-pc-section="thead"] > tr > th:nth-child(').concat(index2 + 1, "),\n                ").concat(selector, ' > [data-pc-section="tbody"] > tr > td:nth-child(').concat(index2 + 1, "),\n                ").concat(selector, ' > [data-pc-section="tfoot"] > tr > td:nth-child(').concat(index2 + 1, ") {\n                    ").concat(style, "\n                }\n            ");
    });
    styleElement.current.innerHTML = innerHTML;
  };
  var bindColumnResizeEvents = function bindColumnResizeEvents2() {
    bindDocumentMouseMoveListener();
    bindDocumentMouseUpListener();
    bindDocumentTouchMoveListener();
    bindDocumentTouchEndListener();
  };
  var unbindColumnResizeEvents = function unbindColumnResizeEvents2() {
    unbindDocumentMouseMoveListener();
    unbindDocumentMouseUpListener();
    unbindDocumentTouchMoveListener();
    unbindDocumentTouchEndListener();
  };
  var onColumnHeaderMouseDown = function onColumnHeaderMouseDown2(e) {
    DomHandler.clearSelection();
    var event = e.originalEvent, column = e.column;
    if (props.reorderableColumns && getColumnProp(column, "reorderable") !== false && !getColumnProp(column, "frozen")) {
      if (event.target.nodeName === "INPUT" || event.target.nodeName === "TEXTAREA" || DomHandler.getAttribute(event.target, '[data-pc-section="columnresizer"]')) {
        event.currentTarget.draggable = false;
      } else {
        event.currentTarget.draggable = true;
      }
    }
  };
  var onColumnHeaderCheckboxChange = function onColumnHeaderCheckboxChange2(e, processedData2) {
    if (props.onSelectAllChange) {
      props.onSelectAllChange(e);
    } else {
      var originalEvent = e.originalEvent, checked = e.checked;
      var _data = props.selectionPageOnly ? dataToRender(processedData2) : processedData2;
      var selection = props.selectionPageOnly && props.selection ? props.selection.filter(function(s) {
        return !_data.some(function(d) {
          return isEquals(s, d);
        });
      }) : [];
      if (checked) {
        selection = ObjectUtils.isNotEmpty(props.frozenValue) ? [].concat(_toConsumableArray(selection), _toConsumableArray(props.frozenValue), _toConsumableArray(_data)) : [].concat(_toConsumableArray(selection), _toConsumableArray(_data));
        selection = getSelectableData(selection);
        props.onAllRowsSelect && props.onAllRowsSelect({
          originalEvent,
          data: selection,
          type: "all"
        });
      } else {
        props.onAllRowsUnselect && props.onAllRowsUnselect({
          originalEvent,
          data: selection,
          type: "all"
        });
      }
      if (props.onSelectionChange) {
        props.onSelectionChange({
          originalEvent,
          value: selection,
          type: "all"
        });
      }
    }
  };
  var onColumnHeaderDragStart = function onColumnHeaderDragStart2(e) {
    var event = e.originalEvent, column = e.column;
    if (columnResizing.current) {
      event.preventDefault();
      return;
    }
    if (!props.reorderableColumns) {
      return;
    }
    colReorderIconWidth.current = DomHandler.getHiddenElementOuterWidth(reorderIndicatorUpRef.current);
    colReorderIconHeight.current = DomHandler.getHiddenElementOuterHeight(reorderIndicatorUpRef.current);
    draggedColumn.current = column;
    draggedColumnElement.current = findParentHeader(event.currentTarget);
    event.dataTransfer.setData("text", "b");
  };
  var onColumnHeaderDragOver = function onColumnHeaderDragOver2(e) {
    var event = e.originalEvent, column = e.column;
    var dropHeader = findParentHeader(event.currentTarget);
    if (props.reorderableColumns && draggedColumnElement.current && dropHeader && !getColumnProp(column, "frozen")) {
      event.preventDefault();
      if (draggedColumnElement.current !== dropHeader) {
        var containerOffset = DomHandler.getOffset(elementRef.current);
        var dropHeaderOffset = DomHandler.getOffset(dropHeader);
        var targetLeft = dropHeaderOffset.left - containerOffset.left;
        var columnCenter = dropHeaderOffset.left + dropHeader.offsetWidth / 2;
        var dragIndex = DomHandler.index(draggedColumnElement.current);
        var dropIndex = DomHandler.index(findParentHeader(event.currentTarget));
        reorderIndicatorUpRef.current.style.top = dropHeaderOffset.top - containerOffset.top - (colReorderIconHeight.current - 1) + "px";
        reorderIndicatorDownRef.current.style.top = dropHeaderOffset.top - containerOffset.top + dropHeader.offsetHeight + "px";
        if (event.pageX > columnCenter && dragIndex < dropIndex) {
          reorderIndicatorUpRef.current.style.left = targetLeft + dropHeader.offsetWidth - Math.ceil(colReorderIconWidth.current / 2) + "px";
          reorderIndicatorDownRef.current.style.left = targetLeft + dropHeader.offsetWidth - Math.ceil(colReorderIconWidth.current / 2) + "px";
          dropPosition.current = 1;
        } else if (dragIndex > dropIndex) {
          reorderIndicatorUpRef.current.style.left = targetLeft - Math.ceil(colReorderIconWidth.current / 2) + "px";
          reorderIndicatorDownRef.current.style.left = targetLeft - Math.ceil(colReorderIconWidth.current / 2) + "px";
          dropPosition.current = -1;
        }
        reorderIndicatorUpRef.current.style.display = "block";
        reorderIndicatorDownRef.current.style.display = "block";
      }
    }
  };
  var onColumnHeaderDragLeave = function onColumnHeaderDragLeave2(e) {
    var event = e.originalEvent;
    if (props.reorderableColumns && draggedColumnElement.current) {
      event.preventDefault();
      reorderIndicatorUpRef.current.style.display = "none";
      reorderIndicatorDownRef.current.style.display = "none";
    }
  };
  var onColumnHeaderDrop = function onColumnHeaderDrop2(e) {
    var event = e.originalEvent, column = e.column;
    event.preventDefault();
    if (draggedColumnElement.current) {
      var dragIndex = DomHandler.index(draggedColumnElement.current);
      var dropIndex = DomHandler.index(findParentHeader(event.currentTarget));
      var allowDrop = dragIndex !== dropIndex;
      if (allowDrop && (dropIndex - dragIndex === 1 && dropPosition.current === -1 || dragIndex - dropIndex === 1 && dropPosition.current === 1)) {
        allowDrop = false;
      }
      if (allowDrop) {
        var _columns = getColumns();
        var isSameColumn = function isSameColumn2(col1, col2) {
          return getColumnProp(col1, "columnKey") || getColumnProp(col2, "columnKey") ? ObjectUtils.equals(col1.props, col2.props, "columnKey") : ObjectUtils.equals(col1.props, col2.props, "field");
        };
        var dragColIndex = _columns.findIndex(function(child) {
          return isSameColumn(child, draggedColumn.current);
        });
        var dropColIndex = _columns.findIndex(function(child) {
          return isSameColumn(child, column);
        });
        var widths = [];
        var headers = DomHandler.find(tableRef.current, '[data-pc-section="thead"] > tr > th');
        headers.forEach(function(header2) {
          return widths.push(DomHandler.getOuterWidth(header2));
        });
        var movedItem = widths.find(function(items, index2) {
          return index2 === dragColIndex;
        });
        var remainingItems = widths.filter(function(items, index2) {
          return index2 !== dragColIndex;
        });
        var reorderedWidths = [].concat(_toConsumableArray(remainingItems.slice(0, dropColIndex)), [movedItem], _toConsumableArray(remainingItems.slice(dropColIndex)));
        addColumnWidthStyles(reorderedWidths);
        if (dropColIndex < dragColIndex && dropPosition.current === 1) {
          dropColIndex++;
        }
        if (dropColIndex > dragColIndex && dropPosition.current === -1) {
          dropColIndex--;
        }
        ObjectUtils.reorderArray(_columns, dragColIndex, dropColIndex);
        var columnOrder = _columns.reduce(function(orders, col) {
          orders.push(getColumnProp(col, "columnKey") || getColumnProp(col, "field"));
          return orders;
        }, []);
        setColumnOrderState(columnOrder);
        if (props.onColReorder) {
          props.onColReorder({
            originalEvent: event,
            dragIndex: dragColIndex,
            dropIndex: dropColIndex,
            columns: _columns
          });
        }
      }
      reorderIndicatorUpRef.current.style.display = "none";
      reorderIndicatorDownRef.current.style.display = "none";
      draggedColumnElement.current.draggable = false;
      draggedColumnElement.current = null;
      draggedColumn.current = null;
      dropPosition.current = null;
    }
  };
  var createBeforeResizeStyleElement = function createBeforeResizeStyleElement2() {
    beforeResizeStyleElement.current = DomHandler.createInlineStyle(context && context.nonce || PrimeReact$2.nonce, context && context.styleContainer);
    var innerHTML = '\n[data-pc-name="datatable"]['.concat(attributeSelector.current, "] {\n    user-select:none;\n}\n        ");
    beforeResizeStyleElement.current.innerHTML = innerHTML;
  };
  var createStyleElement = function createStyleElement2() {
    styleElement.current = DomHandler.createInlineStyle(context && context.nonce || PrimeReact$2.nonce, context && context.styleContainer);
  };
  var createResponsiveStyle = function createResponsiveStyle2() {
    if (!responsiveStyleElement.current) {
      responsiveStyleElement.current = DomHandler.createInlineStyle(context && context.nonce || PrimeReact$2.nonce, context && context.styleContainer);
      var tableSelector = ".p-datatable-wrapper ".concat(isVirtualScrollerDisabled() ? "" : "> .p-virtualscroller", " > .p-datatable-table");
      var selector = ".p-datatable[".concat(attributeSelector.current, "] > ").concat(tableSelector);
      var gridLinesSelector = ".p-datatable[".concat(attributeSelector.current, "].p-datatable-gridlines > ").concat(tableSelector);
      var innerHTML = "\n@media screen and (max-width: ".concat(props.breakpoint, ") {\n    ").concat(selector, " > .p-datatable-thead > tr > th,\n    ").concat(selector, " > .p-datatable-tfoot > tr > td {\n        display: none;\n    }\n\n    ").concat(selector, " > .p-datatable-tbody > tr > td {\n        display: flex;\n        width: 100%;\n        align-items: center;\n        justify-content: space-between;\n    }\n\n    ").concat(selector, " > .p-datatable-tbody > tr > td:not(:last-child) {\n        border: 0 none;\n    }\n\n    ").concat(gridLinesSelector, " > .p-datatable-tbody > tr > td:last-child {\n        border-top: 0;\n        border-right: 0;\n        border-left: 0;\n    }\n\n    ").concat(selector, " > .p-datatable-tbody > tr > td > .p-column-title {\n        display: block;\n    }\n}\n");
      responsiveStyleElement.current.innerHTML = innerHTML;
    }
  };
  var destroyResponsiveStyle = function destroyResponsiveStyle2() {
    responsiveStyleElement.current = DomHandler.removeInlineStyle(responsiveStyleElement.current);
  };
  var destroyStyleElement = function destroyStyleElement2() {
    styleElement.current = DomHandler.removeInlineStyle(styleElement.current);
  };
  var destroyBeforeResizeStyleElement = function destroyBeforeResizeStyleElement2() {
    beforeResizeStyleElement.current = DomHandler.removeInlineStyle(beforeResizeStyleElement.current);
  };
  var onPageChange = function onPageChange2(e) {
    clearEditingMetaData();
    clearFrozenEditingMetaData();
    if (props.onPage) {
      props.onPage(createEvent(e));
    } else {
      setFirstState(e.first);
      setRowsState(e.rows);
    }
    if (props.onValueChange) {
      props.onValueChange(processedData());
    }
  };
  var onSortChange = function onSortChange2(e) {
    clearEditingMetaData();
    clearFrozenEditingMetaData();
    var event = e.originalEvent, column = e.column, sortableDisabledFields = e.sortableDisabledFields;
    var sortField = getColumnProp(column, "sortField") || getColumnProp(column, "field");
    var sortOrder = props.defaultSortOrder;
    var multiSortMeta;
    var eventMeta;
    columnSortable.current = getColumnProp(column, "sortable");
    columnSortFunction.current = getColumnProp(column, "sortFunction");
    columnField.current = sortField;
    if (props.sortMode === "multiple") {
      var metaKey = event.metaKey || event.ctrlKey;
      multiSortMeta = _toConsumableArray(getMultiSortMeta());
      var sortMeta = multiSortMeta.find(function(sortMeta2) {
        return sortMeta2.field === sortField;
      });
      sortOrder = sortMeta ? getCalculatedSortOrder(sortMeta.order) : sortOrder;
      var newMetaData = {
        field: sortField,
        order: sortOrder
      };
      if (sortOrder) {
        multiSortMeta = metaKey ? multiSortMeta : multiSortMeta.filter(function(meta) {
          return sortableDisabledFields.some(function(field) {
            return field === meta.field;
          });
        });
        addSortMeta(newMetaData, multiSortMeta);
      } else if (props.removableSort) {
        removeSortMeta(newMetaData, multiSortMeta);
      }
      eventMeta = {
        multiSortMeta
      };
    } else {
      sortOrder = getSortField() === sortField ? getCalculatedSortOrder(getSortOrder()) : sortOrder;
      if (props.removableSort) {
        sortField = sortOrder ? sortField : null;
      }
      eventMeta = {
        sortField,
        sortOrder
      };
    }
    if (props.onSort) {
      props.onSort(createEvent(eventMeta));
    } else {
      setFirstState(0);
      setSortFieldState(eventMeta.sortField);
      setSortOrderState(eventMeta.sortOrder);
      setMultiSortMetaState(eventMeta.multiSortMeta);
    }
    if (props.onValueChange) {
      props.onValueChange(processedData({
        sortField,
        sortOrder,
        multiSortMeta
      }));
    }
  };
  var getCalculatedSortOrder = function getCalculatedSortOrder2(currentOrder) {
    return props.removableSort ? props.defaultSortOrder === currentOrder ? currentOrder * -1 : 0 : currentOrder * -1;
  };
  var compareValuesOnSort = function compareValuesOnSort2(value1, value2, comparator, order) {
    return ObjectUtils.sort(value1, value2, order, comparator, context && context.nullSortOrder || PrimeReact$2.nullSortOrder);
  };
  var addSortMeta = function addSortMeta2(meta, multiSortMeta) {
    var index2 = multiSortMeta.findIndex(function(sortMeta) {
      return sortMeta.field === meta.field;
    });
    if (index2 >= 0) {
      multiSortMeta[index2] = meta;
    } else {
      multiSortMeta.push(meta);
    }
  };
  var removeSortMeta = function removeSortMeta2(meta, multiSortMeta) {
    var index2 = multiSortMeta.findIndex(function(sortMeta) {
      return sortMeta.field === meta.field;
    });
    if (index2 >= 0) {
      multiSortMeta.splice(index2, 1);
    }
    multiSortMeta = multiSortMeta.length > 0 ? multiSortMeta : null;
  };
  var sortSingle = function sortSingle2(data2, field, order) {
    if (props.groupRowsBy && props.groupRowsBy === props.sortField) {
      var multiSortMeta = [{
        field: props.sortField,
        order: props.sortOrder || props.defaultSortOrder
      }];
      props.sortField !== field && multiSortMeta.push({
        field,
        order
      });
      return sortMultiple(data2, multiSortMeta);
    }
    var value = _toConsumableArray(data2);
    if (columnSortable.current && columnSortFunction.current) {
      value = columnSortFunction.current({
        data: data2,
        field,
        order
      });
    } else {
      var lookupMap = /* @__PURE__ */ new Map();
      var comparator = ObjectUtils.localeComparator(context && context.locale || PrimeReact$2.locale);
      var _iterator = _createForOfIteratorHelper(data2), _step;
      try {
        for (_iterator.s(); !(_step = _iterator.n()).done; ) {
          var item = _step.value;
          lookupMap.set(item, ObjectUtils.resolveFieldData(item, field));
        }
      } catch (err) {
        _iterator.e(err);
      } finally {
        _iterator.f();
      }
      value.sort(function(data1, data22) {
        var value1 = lookupMap.get(data1);
        var value2 = lookupMap.get(data22);
        return compareValuesOnSort(value1, value2, comparator, order);
      });
    }
    return value;
  };
  var sortMultiple = function sortMultiple2(data2) {
    var multiSortMeta = arguments.length > 1 && arguments[1] !== void 0 ? arguments[1] : [];
    if (props.groupRowsBy && (groupRowsSortMetaState || multiSortMeta.length && props.groupRowsBy === multiSortMeta[0].field)) {
      var groupRowsSortMeta = groupRowsSortMetaState;
      var firstSortMeta = multiSortMeta[0];
      if (!groupRowsSortMeta) {
        groupRowsSortMeta = firstSortMeta;
        setGroupRowsSortMetaState(groupRowsSortMeta);
      }
      if (firstSortMeta.field !== groupRowsSortMeta.field) {
        multiSortMeta = [groupRowsSortMeta].concat(_toConsumableArray(multiSortMeta));
      }
    }
    var value = _toConsumableArray(data2);
    if (columnSortable.current && columnSortFunction.current) {
      var meta = multiSortMeta.find(function(meta2) {
        return meta2.field === columnField.current;
      });
      var field = columnField.current;
      var order = meta ? meta.order : props.defaultSortOrder;
      value = columnSortFunction.current({
        data: data2,
        field,
        order,
        multiSortMeta
      });
    } else {
      var comparator = ObjectUtils.localeComparator(context && context.locale || PrimeReact$2.locale);
      value.sort(function(data1, data22) {
        return _multisortField(data1, data22, multiSortMeta, 0, comparator);
      });
    }
    return value;
  };
  var _multisortField = function multisortField(data1, data2, multiSortMeta, index2, comparator) {
    if (!multiSortMeta || !multiSortMeta[index2]) {
      return;
    }
    var value1 = ObjectUtils.resolveFieldData(data1, multiSortMeta[index2].field);
    var value2 = ObjectUtils.resolveFieldData(data2, multiSortMeta[index2].field);
    if (ObjectUtils.compare(value1, value2, comparator) === 0) {
      return multiSortMeta.length - 1 > index2 ? _multisortField(data1, data2, multiSortMeta, index2 + 1, comparator) : 0;
    }
    return compareValuesOnSort(value1, value2, comparator, multiSortMeta[index2].order);
  };
  var onFilterChange = function onFilterChange2(filters) {
    clearEditingMetaData();
    clearFrozenEditingMetaData();
    setD_filtersState(filters);
  };
  var onFilterApply = function onFilterApply2(filtersToApply) {
    clearTimeout(filterTimeout.current);
    filterTimeout.current = setTimeout(function() {
      var filters = cloneFilters(filtersToApply || d_filtersState);
      if (props.onFilter) {
        props.onFilter(createEvent({
          filters
        }));
      } else {
        setFirstState(0);
        setFiltersState(filters);
      }
      if (props.onValueChange) {
        props.onValueChange(processedData({
          filters
        }));
      }
    }, props.filterDelay);
  };
  var getActiveFilters = function getActiveFilters2(filters) {
    var removeEmptyFilters = function removeEmptyFilters2(_ref) {
      var _ref2 = _slicedToArray(_ref, 2), key = _ref2[0], value = _ref2[1];
      if (value.constraints) {
        var filteredConstraints = value.constraints.filter(function(constraint) {
          return constraint.value !== null;
        });
        if (filteredConstraints.length > 0) {
          return [key, _objectSpread(_objectSpread({}, value), {}, {
            constraints: filteredConstraints
          })];
        }
      } else if (value.value !== null) {
        return [key, value];
      }
      return void 0;
    };
    var filterValidEntries = function filterValidEntries2(entry) {
      return entry !== void 0;
    };
    var entries = Object.entries(filters).map(removeEmptyFilters).filter(filterValidEntries);
    return Object.fromEntries(entries);
  };
  var filterLocal = function filterLocal2(data2, filters) {
    if (!data2) {
      return;
    }
    var activeFilters = filters ? getActiveFilters(filters) : {};
    var columns2 = getColumns();
    var filteredValue = [];
    var isGlobalFilter = activeFilters.global || props.globalFilter;
    var globalFilterFieldsArray;
    if (isGlobalFilter) {
      globalFilterFieldsArray = props.globalFilterFields || columns2.filter(function(col) {
        return !getColumnProp(col, "excludeGlobalFilter");
      }).map(function(col) {
        return getColumnProp(col, "filterField") || getColumnProp(col, "field");
      });
    }
    for (var i = 0; i < data2.length; i++) {
      var localMatch = true;
      var globalMatch = false;
      var localFiltered = false;
      for (var prop in activeFilters) {
        if (prop === "null") {
          continue;
        }
        if (Object.prototype.hasOwnProperty.call(activeFilters, prop) && prop !== "global") {
          localFiltered = true;
          var filterField = prop;
          var filterMeta = activeFilters[filterField];
          if (filterMeta.operator) {
            for (var j = 0; j < filterMeta.constraints.length; j++) {
              var filterConstraint = filterMeta.constraints[j];
              localMatch = executeLocalFilter(filterField, data2[i], filterConstraint, j);
              if (filterMeta.operator === FilterOperator.OR && localMatch || filterMeta.operator === FilterOperator.AND && !localMatch) {
                break;
              }
            }
          } else {
            localMatch = executeLocalFilter(filterField, data2[i], filterMeta, 0);
          }
          if (!localMatch) {
            break;
          }
        }
      }
      if (localMatch && isGlobalFilter && !globalMatch && globalFilterFieldsArray) {
        for (var _j = 0; _j < globalFilterFieldsArray.length; _j++) {
          var globalFilterField = globalFilterFieldsArray[_j];
          var matchMode = activeFilters.global ? activeFilters.global.matchMode : props.globalFilterMatchMode;
          var value = activeFilters.global ? activeFilters.global.value : props.globalFilter;
          globalMatch = FilterService.filters[matchMode](ObjectUtils.resolveFieldData(data2[i], globalFilterField), value, props.filterLocale);
          if (globalMatch) {
            break;
          }
        }
      }
      var matches = void 0;
      if (isGlobalFilter) {
        matches = localFiltered ? localFiltered && localMatch && globalMatch : globalMatch;
      } else {
        matches = localFiltered && localMatch;
      }
      if (matches) {
        filteredValue.push(data2[i]);
      }
    }
    if (filteredValue.length === props.value.length || Object.keys(activeFilters).length === 0) {
      filteredValue = data2;
    }
    return filteredValue;
  };
  var executeLocalFilter = function executeLocalFilter2(field, rowData, filterMeta, index2) {
    var filterValue = filterMeta.value;
    var filterMatchMode = filterMeta.matchMode === "custom" ? "custom_".concat(field) : filterMeta.matchMode || FilterMatchMode$2.STARTS_WITH;
    var dataFieldValue = ObjectUtils.resolveFieldData(rowData, field);
    var filterConstraint = FilterService.filters[filterMatchMode];
    return ObjectUtils.isFunction(filterConstraint) && filterConstraint(dataFieldValue, filterValue, props.filterLocale, index2);
  };
  var cloneFilters = function cloneFilters2(filters) {
    filters = filters || props.filters;
    var cloned = {};
    if (filters) {
      Object.entries(filters).forEach(function(_ref3) {
        var _ref4 = _slicedToArray(_ref3, 2), prop = _ref4[0], value = _ref4[1];
        cloned[prop] = value.operator ? {
          operator: value.operator,
          constraints: value.constraints.map(function(constraint) {
            return _objectSpread({}, constraint);
          })
        } : _objectSpread({}, value);
      });
    } else {
      var _columns2 = getColumns();
      cloned = _columns2.reduce(function(filters2, col) {
        var field = getColumnProp(col, "filterField") || getColumnProp(col, "field");
        var filterFunction = getColumnProp(col, "filterFunction");
        var dataType = getColumnProp(col, "dataType");
        var matchMode = getColumnProp(col, "filterMatchMode") || (context && context.filterMatchModeOptions[dataType] || PrimeReact$2.filterMatchModeOptions[dataType] ? context && context.filterMatchModeOptions[dataType][0] || PrimeReact$2.filterMatchModeOptions[dataType][0] : FilterMatchMode$2.STARTS_WITH);
        var constraint = {
          value: null,
          matchMode
        };
        if (filterFunction) {
          FilterService.register("custom_".concat(field), function() {
            for (var _len = arguments.length, args = new Array(_len), _key = 0; _key < _len; _key++) {
              args[_key] = arguments[_key];
            }
            return filterFunction.apply(void 0, args.concat([{
              column: col
            }]));
          });
        }
        filters2[field] = props.filterDisplay === "menu" ? {
          operator: FilterOperator.AND,
          constraints: [constraint]
        } : constraint;
        return filters2;
      }, {});
    }
    return cloned;
  };
  var filter = function filter2(value, field, matchMode) {
    var index2 = arguments.length > 3 && arguments[3] !== void 0 ? arguments[3] : 0;
    var filters = _objectSpread({}, d_filtersState);
    var meta = filters[field];
    var constraint = meta && meta.operator ? meta.constraints[index2] : meta;
    constraint = meta ? {
      value,
      matchMode: matchMode || constraint.matchMode
    } : {
      value,
      matchMode
    };
    props.filterDisplay === "menu" && meta && meta.operator ? filters[field].constraints[index2] = constraint : filters[field] = constraint;
    setD_filtersState(filters);
    onFilterApply(filters);
  };
  var reset = function reset2() {
    setD_rowsState(props.rows);
    setD_filtersState(cloneFilters(props.filters));
    setGroupRowsSortMetaState(null);
    setEditingMetaState({});
    setFrozenEditingMetaState({});
    if (!props.onPage) {
      setFirstState(props.first);
      setRowsState(props.rows);
    }
    if (!props.onSort) {
      setSortFieldState(props.sortField);
      setSortOrderState(props.sortOrder);
      setMultiSortMetaState(props.multiSortMeta);
    }
    if (!props.onFilter) {
      setFiltersState(props.filters);
    }
    resetColumnOrder();
  };
  var resetScroll = function resetScroll2() {
    if (wrapperRef.current) {
      var scrollableContainer = !isVirtualScrollerDisabled() ? DomHandler.findSingle(wrapperRef.current, '[data-pc-name="virtualscroller"]') : wrapperRef.current;
      scrollableContainer.scrollTo(0, 0);
    }
  };
  var resetResizeColumnsWidth = function resetResizeColumnsWidth2() {
    destroyStyleElement();
  };
  var resetColumnOrder = function resetColumnOrder2() {
    var columns2 = getColumns(true);
    var columnOrder = [];
    if (columns2) {
      columnOrder = columns2.reduce(function(orders, col) {
        orders.push(getColumnProp(col, "columnKey") || getColumnProp(col, "field"));
        return orders;
      }, []);
    }
    setColumnOrderState(columnOrder);
  };
  var exportCSV = function exportCSV2(options) {
    var data2;
    var csv = "\uFEFF";
    if (options && options.selectionOnly) {
      data2 = props.selection || [];
    } else {
      data2 = [].concat(_toConsumableArray(props.frozenValue || []), _toConsumableArray(processedData() || []));
    }
    var exportableColumns = getColumns().filter(function(column) {
      var exportable = getColumnProp(column, "exportable");
      var field = getColumnProp(column, "field");
      return exportable !== false && field;
    });
    exportableColumns.forEach(function(column, i) {
      var _ref5 = [getColumnProp(column, "field"), getColumnProp(column, "header"), getColumnProp(column, "exportHeader")], field = _ref5[0], header2 = _ref5[1], exportHeader = _ref5[2];
      var columnHeader = String(exportHeader || header2 || field).replace(/"/g, '""').replace(/\n/g, "\u2028");
      csv = csv + ('"' + columnHeader + '"');
      if (i < exportableColumns.length - 1) {
        csv = csv + props.csvSeparator;
      }
    });
    data2.forEach(function(record) {
      csv = csv + "\n";
      exportableColumns.forEach(function(column, i) {
        var _ref6 = [getColumnProp(column, "field"), getColumnProp(column, "exportField")], colField = _ref6[0], exportField = _ref6[1];
        var field = exportField || colField;
        var cellData = ObjectUtils.resolveFieldData(record, field);
        if (cellData != null) {
          if (props.exportFunction) {
            cellData = props.exportFunction({
              data: cellData,
              field,
              rowData: record,
              column
            });
          } else {
            cellData = String(cellData).replace(/"/g, '""').replace(/\n/g, "\u2028");
          }
        } else {
          cellData = "";
        }
        csv = csv + ('"' + cellData + '"');
        if (i < exportableColumns.length - 1) {
          csv = csv + props.csvSeparator;
        }
      });
    });
    DomHandler.exportCSV(csv, props.exportFilename);
  };
  var closeEditingCell = function closeEditingCell2() {
    if (props.editMode !== "row") {
      document.body.click();
    }
  };
  var closeEditingRows = function closeEditingRows2() {
    DomHandler.find(document.body, '[data-pc-section="roweditorcancelbuttonprops"]').forEach(function(button, index2) {
      setTimeout(function() {
        button.click();
      }, index2 * 5);
    });
  };
  var createEvent = function createEvent2(event) {
    return _objectSpread({
      first: getFirst(),
      rows: getRows(),
      sortField: getSortField(),
      sortOrder: getSortOrder(),
      multiSortMeta: getMultiSortMeta(),
      filters: getFilters()
    }, event);
  };
  var processedData = function processedData2(localState) {
    var data2 = props.value || [];
    if (!props.lazy) {
      if (data2 && data2.length) {
        var filters = localState && localState.filters || getFilters();
        var sortField = localState && localState.sortField || getSortField();
        var sortOrder = localState && localState.sortOrder || getSortOrder();
        var multiSortMeta = localState && localState.multiSortMeta || getMultiSortMeta();
        var _columns3 = getColumns();
        var sortColumn = _columns3.find(function(col) {
          return getColumnProp(col, "field") === sortField;
        });
        if (sortColumn) {
          columnSortable.current = getColumnProp(sortColumn, "sortable");
          columnSortFunction.current = getColumnProp(sortColumn, "sortFunction");
        }
        if (ObjectUtils.isNotEmpty(filters) || props.globalFilter) {
          data2 = filterLocal(data2, filters);
        }
        if (sortField || ObjectUtils.isNotEmpty(multiSortMeta)) {
          if (props.sortMode === "single") {
            data2 = sortSingle(data2, sortField, sortOrder);
          } else if (props.sortMode === "multiple") {
            data2 = sortMultiple(data2, multiSortMeta);
          }
        }
      }
    }
    return data2;
  };
  var dataToRender = function dataToRender2(data2) {
    if (data2 && props.paginator) {
      var first = props.lazy ? 0 : getFirst();
      return data2.slice(first, first + getRows());
    }
    return data2;
  };
  useMountEffect(function() {
    if (elementRef.current) {
      attributeSelector.current = UniqueComponentId();
      elementRef.current.setAttribute(attributeSelector.current, "");
    }
    setD_filtersState(cloneFilters(props.filters));
  });
  useUpdateEffect(function() {
    if (props.responsiveLayout === "stack" && !props.scrollable) {
      createResponsiveStyle();
    }
    return function() {
      destroyResponsiveStyle();
    };
  }, [props.breakpoint]);
  useUpdateEffect(function() {
    if (!restoredRef.current && isStateful()) {
      restoredRef.current = true;
      restoreState();
      if (props.resizableColumns) {
        restoreColumnWidths();
      }
    }
  }, [context]);
  useUpdateEffect(function() {
    var filters = cloneFilters(props.filters);
    setFiltersState(filters);
    setD_filtersState(cloneFilters(props.filters));
    if (props.onValueChange) {
      props.onValueChange(processedData({
        filters
      }));
    }
  }, [props.filters]);
  useUpdateEffect(function() {
    if (isStateful()) {
      saveState();
    }
  });
  useUpdateEffect(function() {
    destroyResponsiveStyle();
    if (props.responsiveLayout === "stack" && !props.scrollable) {
      createResponsiveStyle();
    }
  }, [props.responsiveLayout, props.scrollable]);
  useUpdateEffect(function() {
    if (props.globalFilter) {
      filter(props.globalFilter, "global", props.globalFilterMatchMode);
    } else {
      if (d_filtersState["global"]) {
        var filters = _objectSpread({}, d_filtersState);
        delete filters["global"];
        setD_filtersState(filters);
        onFilterApply(filters);
      }
    }
  }, [props.globalFilter, props.globalFilterMatchMode]);
  useUnmountEffect(function() {
    unbindColumnResizeEvents();
    destroyStyleElement();
    destroyResponsiveStyle();
    destroyBeforeResizeStyleElement();
  });
  React.useImperativeHandle(ref, function() {
    return {
      props,
      clearState,
      closeEditingCell,
      closeEditingRows,
      exportCSV,
      filter,
      reset,
      resetColumnOrder,
      resetScroll,
      resetResizeColumnsWidth,
      restoreColumnWidths,
      restoreState,
      restoreTableState,
      saveState,
      getFilterMeta: function getFilterMeta() {
        return filtersState;
      },
      setFilterMeta: function setFilterMeta(filters) {
        return setFiltersState(filters);
      },
      getSortMeta: function getSortMeta() {
        return multiSortMetaState;
      },
      setSortMeta: function setSortMeta(sorts) {
        return setMultiSortMetaState(sorts);
      },
      getElement: function getElement() {
        return elementRef.current;
      },
      getTable: function getTable() {
        return tableRef.current;
      },
      getVirtualScroller: function getVirtualScroller() {
        return virtualScrollerRef.current;
      },
      getProcessedData: function getProcessedData() {
        return processedData();
      }
    };
  });
  var createLoader = function createLoader2() {
    if (props.loading) {
      var loadingIconProps = mergeProps({
        className: ptCallbacks.cx("loadingIcon")
      }, ptCallbacks.ptm("loadingIcon"));
      var icon = props.loadingIcon || /* @__PURE__ */ React.createElement(SpinnerIcon, _extends({}, loadingIconProps, {
        spin: true
      }));
      var loadingIcon = IconUtils.getJSXIcon(icon, _objectSpread({}, loadingIconProps), {
        props
      });
      var loadingOverlayProps = mergeProps({
        className: ptCallbacks.cx("loadingOverlay")
      }, ptCallbacks.ptm("loadingOverlay"));
      return /* @__PURE__ */ React.createElement("div", loadingOverlayProps, loadingIcon);
    }
    return null;
  };
  var createHeader = function createHeader2() {
    if (props.header) {
      var _content = ObjectUtils.getJSXElement(props.header, {
        props
      });
      var headerProps = mergeProps({
        className: ptCallbacks.cx("header")
      }, ptCallbacks.ptm("header"));
      return /* @__PURE__ */ React.createElement("div", headerProps, _content);
    }
    return null;
  };
  var createTableHeader = function createTableHeader2(options, empty2, _isVirtualScrollerDisabled) {
    if (props.showHeaders === false) {
      return null;
    }
    var sortField = getSortField();
    var sortOrder = getSortOrder();
    var multiSortMeta = _toConsumableArray(getMultiSortMeta());
    var groupRowSortField = getGroupRowSortField();
    var filters = d_filtersState;
    var filtersStore = !props.onFilter && props.filters || getFilters();
    var processedData2 = options.items, virtualScrollerProps = options.props, columns2 = options.columns;
    var data2 = _isVirtualScrollerDisabled || virtualScrollerProps.lazy ? processedData2 : virtualScrollerProps.items;
    return /* @__PURE__ */ React.createElement(TableHeader, {
      hostName: "DataTable",
      value: data2,
      tableProps: props,
      columns: columns2,
      tabIndex: props.tabIndex,
      empty: empty2,
      headerColumnGroup: props.headerColumnGroup,
      resizableColumns: props.resizableColumns,
      onColumnResizeStart,
      onColumnResizerClick: props.onColumnResizerClick,
      onColumnResizerDoubleClick: props.onColumnResizerDoubleClick,
      sortMode: props.sortMode,
      sortField,
      sortIcon: props.sortIcon,
      sortOrder,
      multiSortMeta,
      groupRowsBy: props.groupRowsBy,
      groupRowSortField,
      onSortChange,
      filterDisplay: props.filterDisplay,
      filters,
      filtersStore,
      filterIcon: props.filterIcon,
      filterClearIcon: props.filterClearIcon,
      onFilterChange,
      onFilterApply,
      showSelectAll: props.showSelectAll,
      allRowsSelected,
      onColumnCheckboxChange: onColumnHeaderCheckboxChange,
      onColumnMouseDown: onColumnHeaderMouseDown,
      onColumnDragStart: onColumnHeaderDragStart,
      onColumnDragOver: onColumnHeaderDragOver,
      onColumnDragLeave: onColumnHeaderDragLeave,
      onColumnDrop: onColumnHeaderDrop,
      rowGroupMode: props.rowGroupMode,
      reorderableColumns: props.reorderableColumns,
      ptCallbacks,
      metaData,
      unstyled: props.unstyled
    });
  };
  var createTableBody = function createTableBody2(options, selectionModeInColumn2, empty2, isVirtualScrollerDisabled2, processedData2) {
    var first = getFirst();
    var rows = options.rows, columns2 = options.columns, contentRef = options.contentRef, style = options.style, className = options.className, spacerStyle = options.spacerStyle, itemSize = options.itemSize;
    var frozenBody = ObjectUtils.isNotEmpty(props.frozenValue) && /* @__PURE__ */ React.createElement(TableBody, {
      hostName: "DataTable",
      ref: frozenBodyRef,
      cellMemo: props.cellMemo,
      cellMemoProps: props.cellMemoProps,
      cellMemoPropsDepth: props.cellMemoPropsDepth,
      cellClassName: props.cellClassName,
      cellSelection: props.cellSelection,
      checkIcon: props.checkIcon,
      className: "p-datatable-tbody p-datatable-frozen-tbody",
      collapsedRowIcon: props.collapsedRowIcon,
      columns: columns2,
      compareSelectionBy: props.compareSelectionBy,
      contextMenuSelection: props.contextMenuSelection,
      dataKey: props.dataKey,
      dragSelection: props.dragSelection,
      editMode: props.editMode,
      editingMeta: frozenEditingMetaState,
      editingRows: props.editingRows,
      emptyMessage: props.emptyMessage,
      expandableRowGroups: props.expandableRowGroups,
      expandedRowIcon: props.expandedRowIcon,
      expandedRows: props.expandedRows,
      first,
      frozenRow: true,
      groupRowsBy: props.groupRowsBy,
      isDataSelectable: props.isDataSelectable,
      isVirtualScrollerDisabled: true,
      lazy: props.lazy,
      loading: props.loading,
      metaKeySelection: props.metaKeySelection,
      onCellClick: props.onCellClick,
      onCellSelect: props.onCellSelect,
      onCellUnselect: props.onCellUnselect,
      onContextMenu: props.onContextMenu,
      onContextMenuSelectionChange: props.onContextMenuSelectionChange,
      onEditingMetaChange: onFrozenEditingMetaChange,
      onRowClick: props.onRowClick,
      onRowCollapse: props.onRowCollapse,
      onRowDoubleClick: props.onRowDoubleClick,
      onRowPointerDown: props.onRowPointerDown,
      onRowPointerUp: props.onRowPointerUp,
      onRowEditCancel: props.onRowEditCancel,
      onRowEditChange: props.onRowEditChange,
      onRowEditComplete: props.onRowEditComplete,
      onRowEditInit: props.onRowEditInit,
      onRowEditSave: props.onRowEditSave,
      onRowExpand: props.onRowExpand,
      onRowMouseEnter: props.onRowMouseEnter,
      onRowMouseLeave: props.onRowMouseLeave,
      onRowReorder: props.onRowReorder,
      onRowSelect: props.onRowSelect,
      onRowToggle: props.onRowToggle,
      onRowUnselect: props.onRowUnselect,
      onSelectionChange: props.onSelectionChange,
      paginator: props.paginator,
      processedData: processedData2,
      reorderableRows: props.reorderableRows,
      responsiveLayout: props.responsiveLayout,
      rowClassName: props.rowClassName,
      rowEditValidator: props.rowEditValidator,
      rowEditorCancelIcon: props.rowEditorCancelIcon,
      rowEditorInitIcon: props.rowEditorInitIcon,
      rowEditorSaveIcon: props.rowEditorSaveIcon,
      rowExpansionTemplate: props.rowExpansionTemplate,
      rowGroupFooterTemplate: props.rowGroupFooterTemplate,
      rowGroupHeaderTemplate: props.rowGroupHeaderTemplate,
      rowGroupMode: props.rowGroupMode,
      scrollable: props.scrollable,
      selectOnEdit: props.selectOnEdit,
      selection: props.selection,
      selectionAutoFocus: props.selectionAutoFocus,
      selectionMode: props.selectionMode,
      selectionModeInColumn: selectionModeInColumn2,
      showRowReorderElement: props.showRowReorderElement,
      showSelectionElement: props.showSelectionElement,
      tabIndex: props.tabIndex,
      tableProps: props,
      tableSelector: attributeSelector.current,
      value: props.frozenValue,
      virtualScrollerOptions: options,
      ptCallbacks,
      metaData,
      unstyled: props.unstyled
    });
    var body = /* @__PURE__ */ React.createElement(TableBody, {
      hostName: "DataTable",
      ref: bodyRef,
      cellMemo: props.cellMemo,
      cellMemoProps: props.cellMemoProps,
      cellMemoPropsDepth: props.cellMemoPropsDepth,
      cellClassName: props.cellClassName,
      cellSelection: props.cellSelection,
      checkIcon: props.checkIcon,
      className: classNames("p-datatable-tbody", className),
      collapsedRowIcon: props.collapsedRowIcon,
      columns: columns2,
      compareSelectionBy: props.compareSelectionBy,
      contextMenuSelection: props.contextMenuSelection,
      dataKey: props.dataKey,
      dragSelection: props.dragSelection,
      editMode: props.editMode,
      editingMeta: editingMetaState,
      editingRows: props.editingRows,
      empty: empty2,
      emptyMessage: props.emptyMessage,
      expandableRowGroups: props.expandableRowGroups,
      expandedRowIcon: props.expandedRowIcon,
      expandedRows: props.expandedRows,
      first,
      frozenRow: false,
      groupRowsBy: props.groupRowsBy,
      isDataSelectable: props.isDataSelectable,
      isVirtualScrollerDisabled: isVirtualScrollerDisabled2,
      lazy: props.lazy,
      loading: props.loading,
      metaKeySelection: props.metaKeySelection,
      onCellClick: props.onCellClick,
      onCellSelect: props.onCellSelect,
      onCellUnselect: props.onCellUnselect,
      onContextMenu: props.onContextMenu,
      onContextMenuSelectionChange: props.onContextMenuSelectionChange,
      onEditingMetaChange,
      onRowClick: props.onRowClick,
      onRowCollapse: props.onRowCollapse,
      onRowDoubleClick: props.onRowDoubleClick,
      onRowEditCancel: props.onRowEditCancel,
      onRowEditChange: props.onRowEditChange,
      onRowEditComplete: props.onRowEditComplete,
      onRowEditInit: props.onRowEditInit,
      onRowEditSave: props.onRowEditSave,
      onRowExpand: props.onRowExpand,
      onRowMouseEnter: props.onRowMouseEnter,
      onRowMouseLeave: props.onRowMouseLeave,
      onRowPointerDown: props.onRowPointerDown,
      onRowPointerUp: props.onRowPointerUp,
      onRowReorder: props.onRowReorder,
      onRowSelect: props.onRowSelect,
      onRowToggle: props.onRowToggle,
      onRowUnselect: props.onRowUnselect,
      onSelectionChange: props.onSelectionChange,
      paginator: props.paginator,
      processedData: processedData2,
      reorderableRows: props.reorderableRows,
      responsiveLayout: props.responsiveLayout,
      rowClassName: props.rowClassName,
      rowEditValidator: props.rowEditValidator,
      rowEditorCancelIcon: props.rowEditorCancelIcon,
      rowEditorInitIcon: props.rowEditorInitIcon,
      rowEditorSaveIcon: props.rowEditorSaveIcon,
      rowExpansionTemplate: props.rowExpansionTemplate,
      rowGroupFooterTemplate: props.rowGroupFooterTemplate,
      rowGroupHeaderTemplate: props.rowGroupHeaderTemplate,
      rowGroupMode: props.rowGroupMode,
      scrollable: props.scrollable,
      selectOnEdit: props.selectOnEdit,
      selection: props.selection,
      selectionAutoFocus: props.selectionAutoFocus,
      selectionMode: props.selectionMode,
      selectionModeInColumn: selectionModeInColumn2,
      showRowReorderElement: props.showRowReorderElement,
      showSelectionElement: props.showSelectionElement,
      style,
      tabIndex: props.tabIndex,
      tableProps: props,
      tableSelector: attributeSelector.current,
      value: dataToRender(rows),
      virtualScrollerContentRef: contentRef,
      virtualScrollerOptions: options,
      ptCallbacks,
      metaData,
      unstyled: props.unstyled
    });
    var spacerBody = ObjectUtils.isNotEmpty(spacerStyle) ? /* @__PURE__ */ React.createElement(TableBody, {
      hostName: "DataTable",
      style: {
        height: "calc(".concat(spacerStyle.height, " - ").concat(rows.length * itemSize, "px)")
      },
      className: "p-datatable-virtualscroller-spacer",
      ptCallbacks,
      metaData,
      unstyled: props.unstyled
    }) : null;
    return /* @__PURE__ */ React.createElement(React.Fragment, null, frozenBody, body, spacerBody);
  };
  var createTableFooter = function createTableFooter2(options) {
    var columns2 = options.columns;
    return /* @__PURE__ */ React.createElement(TableFooter, {
      hostName: "DataTable",
      tableProps: props,
      columns: columns2,
      footerColumnGroup: props.footerColumnGroup,
      ptCallbacks,
      metaData,
      unstyled: props.unstyled
    });
  };
  var createContent = function createContent2(processedData2, columns2, selectionModeInColumn2, empty2) {
    if (!columns2) {
      return;
    }
    var _isVirtualScrollerDisabled = isVirtualScrollerDisabled();
    var virtualScrollerOptions = props.virtualScrollerOptions || {};
    var wrapperProps = mergeProps({
      className: ptCallbacks.cx("wrapper"),
      style: _objectSpread(_objectSpread({}, ptCallbacks.sx("wrapper")), {}, {
        maxHeight: _isVirtualScrollerDisabled ? props.scrollHeight : null
      })
    }, ptCallbacks.ptm("wrapper"));
    return /* @__PURE__ */ React.createElement("div", _extends({
      ref: wrapperRef
    }, wrapperProps), /* @__PURE__ */ React.createElement(VirtualScroller, _extends({
      ref: virtualScrollerRef
    }, virtualScrollerOptions, {
      items: processedData2,
      columns: columns2,
      style: _objectSpread(_objectSpread({}, virtualScrollerOptions.style), {
        height: props.scrollHeight !== "flex" ? props.scrollHeight : void 0
      }),
      scrollHeight: props.scrollHeight !== "flex" ? void 0 : "100%",
      disabled: _isVirtualScrollerDisabled,
      loaderDisabled: true,
      inline: true,
      autoSize: true,
      pt: ptCallbacks.ptm("virtualScroller"),
      __parentMetadata: {
        parent: metaData
      },
      showSpacer: false,
      unstyled: props.unstyled,
      contentTemplate: function contentTemplate(options) {
        var ref2 = function ref3(el) {
          tableRef.current = el;
          options.spacerRef && options.spacerRef(el);
        };
        var tableHeader = createTableHeader(options, empty2, _isVirtualScrollerDisabled);
        var tableBody = createTableBody(options, selectionModeInColumn2, empty2, _isVirtualScrollerDisabled, processedData2);
        var tableFooter = createTableFooter(options);
        var tableProps = mergeProps({
          className: classNames(props.tableClassName, ptCallbacks.cx("table")),
          style: props.tableStyle,
          role: "table"
        }, ptCallbacks.ptm("table"));
        return /* @__PURE__ */ React.createElement("table", _extends({
          ref: ref2
        }, tableProps), tableHeader, tableBody, tableFooter);
      }
    })));
  };
  var createFooter = function createFooter2() {
    if (props.footer) {
      var _content2 = ObjectUtils.getJSXElement(props.footer, {
        props
      });
      var footerProps = mergeProps({
        className: ptCallbacks.cx("footer")
      }, ptCallbacks.ptm("footer"));
      return /* @__PURE__ */ React.createElement("div", footerProps, _content2);
    }
    return null;
  };
  var createPaginator = function createPaginator2(position, totalRecords2) {
    return /* @__PURE__ */ React.createElement(Paginator, {
      first: getFirst(),
      rows: getRows(),
      pageLinkSize: props.pageLinkSize,
      className: classNames(props.paginatorClassName, ptCallbacks.cx("paginator", {
        position
      })),
      onPageChange,
      template: props.paginatorTemplate,
      totalRecords: totalRecords2,
      rowsPerPageOptions: props.rowsPerPageOptions,
      currentPageReportTemplate: props.currentPageReportTemplate,
      leftContent: props.paginatorLeft,
      rightContent: props.paginatorRight,
      alwaysShow: props.alwaysShowPaginator,
      dropdownAppendTo: props.paginatorDropdownAppendTo,
      pt: ptCallbacks.ptm("paginator"),
      unstyled: props.unstyled,
      __parentMetadata: {
        parent: metaData
      }
    });
  };
  var createPaginatorTop = function createPaginatorTop2(totalRecords2) {
    if (props.paginator && props.paginatorPosition !== "bottom") {
      return createPaginator("top", totalRecords2);
    }
    return null;
  };
  var createPaginatorBottom = function createPaginatorBottom2(totalRecords2) {
    if (props.paginator && props.paginatorPosition !== "top") {
      return createPaginator("bottom", totalRecords2);
    }
    return null;
  };
  var createResizeHelper = function createResizeHelper2() {
    if (props.resizableColumns) {
      var resizeHelperProps = mergeProps({
        className: ptCallbacks.cx("resizeHelper"),
        style: ptCallbacks.sx("resizeHelper")
      }, ptCallbacks.ptm("resizeHelper"));
      return /* @__PURE__ */ React.createElement("div", _extends({
        ref: resizeHelperRef
      }, resizeHelperProps));
    }
    return null;
  };
  var createReorderIndicators = function createReorderIndicators2() {
    if (props.reorderableColumns) {
      var style = {
        position: "absolute",
        display: "none"
      };
      var reorderIndicatorUpProps = mergeProps({
        className: ptCallbacks.cx("reorderIndicatorUp"),
        style: ptCallbacks.sx("reorderIndicatorUp", {
          style
        })
      }, ptCallbacks.ptm("reorderIndicatorUp"));
      var reorderIndicatorUpIconProps = mergeProps(ptCallbacks.ptm("reorderIndicatorUpIcon"));
      var reorderIndicatorUpIcon = IconUtils.getJSXIcon(props.reorderIndicatorUpIcon || /* @__PURE__ */ React.createElement(ArrowDownIcon, reorderIndicatorUpIconProps), _objectSpread({}, reorderIndicatorUpIconProps), {
        props
      });
      var reorderIndicatorDownProps = mergeProps({
        className: ptCallbacks.cx("reorderIndicatorDown"),
        style: ptCallbacks.sx("reorderIndicatorDown", {
          style
        })
      }, ptCallbacks.ptm("reorderIndicatorDown"));
      var reorderIndicatorDownIconProps = mergeProps(ptCallbacks.ptm("reorderIndicatorDownIcon"));
      var reorderIndicatorDownIcon = IconUtils.getJSXIcon(props.reorderIndicatorDownIcon || /* @__PURE__ */ React.createElement(ArrowUpIcon, reorderIndicatorDownIconProps), _objectSpread({}, reorderIndicatorDownIconProps), {
        props
      });
      return /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement("span", _extends({
        ref: reorderIndicatorUpRef
      }, reorderIndicatorUpProps), reorderIndicatorUpIcon), /* @__PURE__ */ React.createElement("span", _extends({
        ref: reorderIndicatorDownRef
      }, reorderIndicatorDownProps), reorderIndicatorDownIcon));
    }
    return null;
  };
  var data = processedData();
  var columns = getColumns();
  var totalRecords = getTotalRecords(data);
  var empty = ObjectUtils.isEmpty(data);
  var selectionModeInColumn = getSelectionModeInColumn(columns);
  var selectable = props.selectionMode || selectionModeInColumn;
  var loader = createLoader();
  var header = createHeader();
  var paginatorTop = createPaginatorTop(totalRecords);
  var content = createContent(data, columns, selectionModeInColumn, empty);
  var paginatorBottom = createPaginatorBottom(totalRecords);
  var footer = createFooter();
  var resizeHelper = createResizeHelper();
  var reorderIndicators = createReorderIndicators();
  var rootProps = mergeProps({
    id: props.id,
    className: classNames(props.className, ptCallbacks.cx("root", {
      selectable
    })),
    style: props.style,
    "data-scrollselectors": ".p-datatable-wrapper",
    "data-showgridlines": props.showGridlines
  }, DataTableBase.getOtherProps(props), ptCallbacks.ptm("root"));
  return /* @__PURE__ */ React.createElement("div", _extends({
    ref: elementRef
  }, rootProps), loader, header, paginatorTop, content, paginatorBottom, footer, resizeHelper, reorderIndicators);
});
DataTable.displayName = "DataTable";
var Column = function Column2() {
};
Column.displayName = "Column";
function DefaultPagination({
  currentPage,
  lastPage,
  total,
  perPage,
  from,
  to,
  onPageChange,
  selectedCount = 0,
  itemLabel
}) {
  const { t } = useTranslation("resources");
  if (total === 0) return null;
  const first = (currentPage - 1) * perPage;
  function handlePageChange(e) {
    onPageChange(e.page + 1);
  }
  const hasRange = from !== null && to !== null;
  const resourceLabel = itemLabel ?? t("records", "records");
  return /* @__PURE__ */ jsx("div", { className: "martis-pagination-wrapper", children: /* @__PURE__ */ jsxs("div", { className: "martis-pagination-row", children: [
    /* @__PURE__ */ jsxs("span", { className: "martis-pagination-summary", children: [
      selectedCount > 0 && /* @__PURE__ */ jsxs(Fragment$1, { children: [
        /* @__PURE__ */ jsx("span", { className: "martis-pagination-selected", children: t("selected", "{{count}} selected", { count: selectedCount }) }),
        /* @__PURE__ */ jsx("span", { className: "martis-pagination-sep", "aria-hidden": "true", children: " · " })
      ] }),
      hasRange ? /* @__PURE__ */ jsxs(Fragment$1, { children: [
        from,
        "–",
        to,
        " ",
        t("of", "of"),
        " ",
        total,
        " ",
        /* @__PURE__ */ jsx("span", { className: "martis-pagination-label", children: resourceLabel })
      ] }) : /* @__PURE__ */ jsxs(Fragment$1, { children: [
        total,
        " ",
        /* @__PURE__ */ jsx("span", { className: "martis-pagination-label", children: resourceLabel })
      ] })
    ] }),
    lastPage > 1 && /* @__PURE__ */ jsx(
      Paginator,
      {
        first,
        rows: perPage,
        totalRecords: total,
        onPageChange: handlePageChange,
        template: "PrevPageLink PageLinks NextPageLink",
        className: "martis-paginator"
      }
    )
  ] }) });
}
if (!registry.has("component:Pagination")) {
  registry.register("component:Pagination", DefaultPagination);
}
function Pagination(props) {
  const Component = registry.resolve("component:Pagination", DefaultPagination);
  return /* @__PURE__ */ jsx(Component, { ...props });
}
function QueryErrorState({ error, onRetry, retrying = false, compact = false }) {
  const { t } = useTranslation("messages");
  const triage = triageError(error, t);
  const title = t("query_error_title", { defaultValue: "Records could not be loaded" });
  const detail = describeError(error);
  return /* @__PURE__ */ jsxs(
    "div",
    {
      role: "alert",
      "data-status": triage.status,
      className: compact ? "martis-query-error martis-query-error-compact" : "martis-query-error",
      children: [
        /* @__PURE__ */ jsx("div", { className: "martis-query-error-icon", "aria-hidden": "true", children: triage.icon }),
        /* @__PURE__ */ jsxs("div", { className: "martis-query-error-body", children: [
          /* @__PURE__ */ jsx("h3", { className: "martis-query-error-title", children: title }),
          /* @__PURE__ */ jsx("p", { className: "martis-query-error-desc", children: triage.description }),
          detail && /* @__PURE__ */ jsx("code", { className: "martis-query-error-detail", children: detail })
        ] }),
        onRetry && /* @__PURE__ */ jsxs(
          "button",
          {
            type: "button",
            className: "martis-btn-secondary martis-query-error-retry",
            onClick: onRetry,
            disabled: retrying,
            children: [
              /* @__PURE__ */ jsx(ArrowClockwiseIcon, { size: 14, weight: "bold" }),
              t("error_try_again", { defaultValue: "Try again" })
            ]
          }
        )
      ]
    }
  );
}
function triageError(error, t) {
  if (!(error instanceof ApiError)) {
    return {
      status: "network",
      icon: /* @__PURE__ */ jsx(PlugsConnectedIcon, { size: 22, weight: "regular" }),
      description: t("query_error_network", {
        defaultValue: "The request never reached the server. Check your connection and try again."
      })
    };
  }
  if (error.status === 403) {
    return {
      status: String(error.status),
      icon: /* @__PURE__ */ jsx(CompassIcon, { size: 22, weight: "regular" }),
      description: t("query_error_forbidden", {
        defaultValue: "You do not have permission to list these records."
      })
    };
  }
  if (error.status === 404) {
    return {
      status: String(error.status),
      icon: /* @__PURE__ */ jsx(CompassIcon, { size: 22, weight: "regular" }),
      description: t("query_error_not_found", { defaultValue: "This listing is not available." })
    };
  }
  if (error.status >= 500) {
    return {
      status: String(error.status),
      icon: /* @__PURE__ */ jsx(WarningIcon, { size: 22, weight: "regular" }),
      description: t("query_error_server", {
        defaultValue: "The server returned an error while loading these records. Check the application logs for the full stack trace, then try again."
      })
    };
  }
  return {
    status: String(error.status),
    icon: /* @__PURE__ */ jsx(WarningIcon, { size: 22, weight: "regular" }),
    description: t("query_error_generic", {
      defaultValue: "The request failed. Try again, or adjust the filters and search."
    })
  };
}
function describeError(error) {
  if (error instanceof ApiError) {
    const message = error.message.trim();
    return message ? `HTTP ${error.status} · ${message}` : `HTTP ${error.status}`;
  }
  if (error instanceof Error && error.message.trim()) return error.message.trim();
  return null;
}
function filterInlineActions(actions) {
  return actions.filter((a) => a.showInline);
}
function columnStyleFrom(meta) {
  if (!meta) return void 0;
  const style = {};
  if (meta.width) style.width = meta.width;
  if (meta.minWidth) style.minWidth = meta.minWidth;
  if (meta.maxWidth) style.maxWidth = meta.maxWidth;
  return Object.keys(style).length === 0 ? void 0 : style;
}
function SortIcon({ active, dir }) {
  if (!active) return null;
  return dir === "asc" ? /* @__PURE__ */ jsx(CaretUpIcon, { size: 12, weight: "bold", style: { opacity: 0.8 } }) : /* @__PURE__ */ jsx(CaretDownIcon, { size: 12, weight: "bold", style: { opacity: 0.8 } });
}
function isInlineGroup(item) {
  return "label" in item && "children" in item && !("uriKey" in item);
}
function buildInlineGroupTree(actions) {
  const ungrouped = [];
  const groupMap = /* @__PURE__ */ new Map();
  for (const action of actions) {
    if (!action.group) {
      ungrouped.push(action);
    } else {
      const existing = groupMap.get(action.group) ?? [];
      existing.push(action);
      groupMap.set(action.group, existing);
    }
  }
  const result = [...ungrouped];
  const topGroups = /* @__PURE__ */ new Map();
  for (const [key, items] of groupMap.entries()) {
    const parts = key.split(".");
    if (parts.length === 1) {
      if (!topGroups.has(parts[0])) {
        topGroups.set(parts[0], { label: parts[0], children: [] });
      }
      topGroups.get(parts[0]).children.push(...items);
    } else {
      const topKey = parts[0];
      const subLabel = parts.slice(1).join(".");
      if (!topGroups.has(topKey)) {
        topGroups.set(topKey, { label: topKey, children: [] });
      }
      const top = topGroups.get(topKey);
      let sub = top.children.find(
        (c) => isInlineGroup(c) && c.label === subLabel
      );
      if (!sub) {
        sub = { label: subLabel, children: [] };
        top.children.push(sub);
      }
      sub.children.push(...items);
    }
  }
  for (const group of topGroups.values()) {
    result.push(group);
  }
  return result;
}
function menuItems(menu) {
  return menu ? Array.from(menu.querySelectorAll(':scope > [role="menuitem"]:not([disabled]), :scope > div > [role="menuitem"]')) : [];
}
function moveMenuFocus(menu, key) {
  var _a;
  if (key !== "ArrowDown" && key !== "ArrowUp") return false;
  const items = menuItems(menu);
  if (items.length === 0) return true;
  const at = items.indexOf(document.activeElement);
  const next = key === "ArrowDown" ? (at + 1) % items.length : (at - 1 + items.length) % items.length;
  (_a = items[next]) == null ? void 0 : _a.focus();
  return true;
}
function openGroupOnKey(e) {
  if (e.key === "Enter" || e.key === " " || e.key === "ArrowRight") {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.parentElement.click();
  }
}
function InlineSubMenu({
  group,
  parentRect,
  onAction,
  onBack,
  row
}) {
  const [openChild, setOpenChild] = useState(null);
  const [childRects, setChildRects] = useState(/* @__PURE__ */ new Map());
  const closeTimer = useRef(null);
  const menuRef = useRef(null);
  const childRows = useRef(/* @__PURE__ */ new Map());
  useEffect(() => {
    var _a;
    (_a = menuItems(menuRef.current)[0]) == null ? void 0 : _a.focus();
  }, [parentRect]);
  function handleKey(e) {
    if (moveMenuFocus(menuRef.current, e.key)) {
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    if (e.key === "ArrowLeft" || e.key === "Escape") {
      e.preventDefault();
      e.stopPropagation();
      onBack();
    }
  }
  function clearCloseTimer() {
    if (closeTimer.current) {
      clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
  }
  function startCloseTimer(key) {
    closeTimer.current = setTimeout(() => {
      if (openChild === key) setOpenChild(null);
    }, 180);
  }
  if (!parentRect) return null;
  const menuWidth = 220;
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  let left = parentRect.right + 2;
  let top = parentRect.top;
  if (left + menuWidth > vw) left = parentRect.left - menuWidth - 2;
  if (left < 0) {
    left = Math.max(4, vw - menuWidth - 4);
    top = parentRect.bottom + 4;
  }
  if (top + 200 > vh) top = Math.max(4, vh - 200);
  return createPortal(
    /* @__PURE__ */ jsx(
      "div",
      {
        ref: menuRef,
        "data-action-submenu": "true",
        role: "menu",
        "aria-label": group.label,
        onKeyDown: handleKey,
        className: "rounded-lg border border-solid shadow-lg py-1",
        style: { position: "fixed", top, left, minWidth: 200, maxWidth: "calc(100vw - 16px)", zIndex: 9992, backgroundColor: "var(--martis-card)", borderColor: "var(--martis-border)" },
        onMouseEnter: clearCloseTimer,
        children: group.children.map((child, idx) => {
          if (isInlineGroup(child)) {
            const key = `isub-${child.label}-${idx}`;
            return /* @__PURE__ */ jsxs(
              "div",
              {
                className: "relative",
                onMouseEnter: (e) => {
                  clearCloseTimer();
                  const rect = e.currentTarget.getBoundingClientRect();
                  setOpenChild(key);
                  setChildRects((prev) => new Map(prev).set(key, rect));
                },
                onMouseLeave: () => startCloseTimer(key),
                onClick: (e) => {
                  e.stopPropagation();
                  const rect = e.currentTarget.getBoundingClientRect();
                  setChildRects((prev) => new Map(prev).set(key, rect));
                  setOpenChild((p) => p === key ? null : key);
                },
                children: [
                  /* @__PURE__ */ jsxs(
                    "div",
                    {
                      role: "menuitem",
                      tabIndex: -1,
                      "aria-haspopup": "menu",
                      "aria-expanded": openChild === key,
                      ref: (el) => {
                        if (el) childRows.current.set(key, el);
                      },
                      className: "flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors cursor-pointer",
                      style: { color: "var(--martis-text)" },
                      onKeyDown: openGroupOnKey,
                      onMouseEnter: (e) => e.currentTarget.style.backgroundColor = "var(--martis-hover)",
                      onMouseLeave: (e) => e.currentTarget.style.backgroundColor = "transparent",
                      children: [
                        /* @__PURE__ */ jsx("span", { className: "font-medium", children: child.label }),
                        /* @__PURE__ */ jsx(CaretRightIcon, { size: 12 })
                      ]
                    }
                  ),
                  openChild === key && /* @__PURE__ */ jsx(InlineSubMenu, { group: child, parentRect: childRects.get(key) ?? null, onAction, onBack: () => {
                    var _a;
                    setOpenChild(null);
                    (_a = childRows.current.get(key)) == null ? void 0 : _a.focus();
                  }, row })
                ]
              },
              key
            );
          }
          const isDisabled = !canRunInlineAction(row, child);
          return /* @__PURE__ */ jsxs(
            "button",
            {
              type: "button",
              role: "menuitem",
              disabled: isDisabled,
              onClick: (e) => {
                e.stopPropagation();
                if (!isDisabled) onAction(child, row);
              },
              className: "flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed",
              style: { color: isDisabled ? "var(--martis-text-muted)" : child.destructive ? "var(--martis-danger)" : "var(--martis-text)" },
              onMouseEnter: (e) => {
                if (!isDisabled) e.currentTarget.style.backgroundColor = "var(--martis-hover)";
              },
              onMouseLeave: (e) => {
                e.currentTarget.style.backgroundColor = "transparent";
              },
              children: [
                child.showIcon !== false && (child.icon ? /* @__PURE__ */ jsx(ResourceIcon, { iconName: child.icon, size: 16, color: child.iconColor ?? void 0 }) : child.destructive ? /* @__PURE__ */ jsx(WarningIcon, { size: 16, weight: "fill", color: child.iconColor ?? void 0 }) : /* @__PURE__ */ jsx(LightningIcon, { size: 16, color: child.iconColor ?? void 0 })),
                /* @__PURE__ */ jsx("span", { children: child.name })
              ]
            },
            child.uriKey
          );
        })
      }
    ),
    document.body
  );
}
function InlineActionMenu({
  actions,
  row,
  onAction
}) {
  var _a;
  const { t: tMsg } = useTranslation("messages");
  const [open, setOpen] = useState(false);
  const btnRef = useRef(null);
  const menuRef = useRef(null);
  const [openGroup, setOpenGroup] = useState(null);
  const [groupRects, setGroupRects] = useState(/* @__PURE__ */ new Map());
  const closeTimer = useRef(null);
  const groupRows = useRef(/* @__PURE__ */ new Map());
  function clearCloseTimer() {
    if (closeTimer.current) {
      clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
  }
  useEffect(() => {
    if (!open) return;
    function handleClick(e) {
      var _a2, _b;
      const target = e.target;
      if ((_a2 = menuRef.current) == null ? void 0 : _a2.contains(target)) return;
      if ((_b = btnRef.current) == null ? void 0 : _b.contains(target)) return;
      if (target.closest("[data-action-submenu]")) return;
      setOpen(false);
    }
    document.addEventListener("mousedown", handleClick);
    return () => {
      document.removeEventListener("mousedown", handleClick);
    };
  }, [open]);
  useEscapeLayer(open, () => {
    var _a2;
    setOpen(false);
    (_a2 = btnRef.current) == null ? void 0 : _a2.focus();
  });
  useEffect(() => {
    var _a2;
    if (!open) return;
    (_a2 = menuItems(menuRef.current)[0]) == null ? void 0 : _a2.focus();
  }, [open]);
  function handleMenuKey(e) {
    if (moveMenuFocus(menuRef.current, e.key)) e.preventDefault();
  }
  const rect = (_a = btnRef.current) == null ? void 0 : _a.getBoundingClientRect();
  const tree = buildInlineGroupTree(actions);
  return /* @__PURE__ */ jsxs(Fragment$1, { children: [
    /* @__PURE__ */ jsx(
      "button",
      {
        ref: btnRef,
        type: "button",
        onClick: (e) => {
          e.stopPropagation();
          setOpen(!open);
        },
        className: "martis-row-action-btn",
        style: open ? { backgroundColor: "var(--martis-hover)", color: "var(--martis-text)", borderColor: "var(--martis-border)" } : void 0,
        "data-pr-tooltip": tMsg("actions", "Actions"),
        "data-pr-position": "top",
        "aria-label": tMsg("actions", "Actions"),
        "aria-haspopup": "menu",
        "aria-expanded": open,
        children: /* @__PURE__ */ jsx(DotsThreeVerticalIcon, { size: 16, weight: "bold" })
      }
    ),
    open && rect && createPortal(
      /* @__PURE__ */ jsx(
        "div",
        {
          ref: menuRef,
          role: "menu",
          "aria-label": tMsg("actions", "Actions"),
          onKeyDown: handleMenuKey,
          className: "rounded-lg border border-solid shadow-lg py-1",
          style: { position: "fixed", top: rect.bottom + 4, left: Math.min(rect.left, window.innerWidth - 220), minWidth: 180, zIndex: 9991, backgroundColor: "var(--martis-card)", borderColor: "var(--martis-border)" },
          children: tree.map((item, idx) => {
            if (isInlineGroup(item)) {
              const key = `igrp-${item.label}-${idx}`;
              return /* @__PURE__ */ jsxs(
                "div",
                {
                  "data-action-submenu": "true",
                  className: "relative",
                  onMouseEnter: (e) => {
                    clearCloseTimer();
                    const rect2 = e.currentTarget.getBoundingClientRect();
                    setOpenGroup(key);
                    setGroupRects((prev) => new Map(prev).set(key, rect2));
                  },
                  onMouseLeave: () => {
                    closeTimer.current = setTimeout(() => setOpenGroup((prev) => prev === key ? null : prev), 180);
                  },
                  onClick: (e) => {
                    e.stopPropagation();
                    const rect2 = e.currentTarget.getBoundingClientRect();
                    setGroupRects((prev) => new Map(prev).set(key, rect2));
                    setOpenGroup((p) => p === key ? null : key);
                  },
                  children: [
                    /* @__PURE__ */ jsxs(
                      "div",
                      {
                        role: "menuitem",
                        tabIndex: -1,
                        "aria-haspopup": "menu",
                        "aria-expanded": openGroup === key,
                        ref: (el) => {
                          if (el) groupRows.current.set(key, el);
                        },
                        className: "flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors cursor-pointer",
                        style: { color: "var(--martis-text)" },
                        onKeyDown: openGroupOnKey,
                        onMouseEnter: (e) => e.currentTarget.style.backgroundColor = "var(--martis-hover)",
                        onMouseLeave: (e) => e.currentTarget.style.backgroundColor = "transparent",
                        children: [
                          /* @__PURE__ */ jsx("span", { className: "font-medium", children: item.label }),
                          /* @__PURE__ */ jsx(CaretRightIcon, { size: 12 })
                        ]
                      }
                    ),
                    openGroup === key && /* @__PURE__ */ jsx(InlineSubMenu, { group: item, parentRect: groupRects.get(key) ?? null, onAction: (a, r) => {
                      setOpen(false);
                      onAction(a, r);
                    }, onBack: () => {
                      var _a2;
                      setOpenGroup(null);
                      (_a2 = groupRows.current.get(key)) == null ? void 0 : _a2.focus();
                    }, row })
                  ]
                },
                key
              );
            }
            const isItemDisabled = !canRunInlineAction(row, item);
            return /* @__PURE__ */ jsxs(
              "button",
              {
                type: "button",
                role: "menuitem",
                disabled: isItemDisabled,
                onClick: (e) => {
                  e.stopPropagation();
                  if (!isItemDisabled) {
                    setOpen(false);
                    onAction(item, row);
                  }
                },
                className: "flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed",
                style: { color: isItemDisabled ? "var(--martis-text-muted)" : item.destructive ? "var(--martis-danger)" : "var(--martis-text)" },
                onMouseEnter: (e) => {
                  if (!isItemDisabled) e.currentTarget.style.backgroundColor = "var(--martis-hover)";
                },
                onMouseLeave: (e) => {
                  e.currentTarget.style.backgroundColor = "transparent";
                },
                children: [
                  item.showIcon !== false && (item.icon ? /* @__PURE__ */ jsx(ResourceIcon, { iconName: item.icon, size: 16, color: item.iconColor ?? void 0 }) : item.destructive ? /* @__PURE__ */ jsx(WarningIcon, { size: 16, weight: "fill", color: item.iconColor ?? void 0 }) : /* @__PURE__ */ jsx(LightningIcon, { size: 16, color: item.iconColor ?? void 0 })),
                  /* @__PURE__ */ jsx("span", { children: item.name })
                ]
              },
              item.uriKey
            );
          })
        }
      ),
      document.body
    )
  ] });
}
function rowActionAuthorization(row, action) {
  return ownEntry(row._actionAuthorization, action.uriKey);
}
function canRunInlineAction(row, action) {
  var _a, _b;
  const authorised = rowActionAuthorization(row, action);
  if (authorised !== void 0) return authorised;
  if (action.standalone) return true;
  if (action.destructive) return ((_a = row._authorization) == null ? void 0 : _a.authorizedToRunDestructiveAction) !== false;
  return ((_b = row._authorization) == null ? void 0 : _b.authorizedToRunAction) !== false;
}
function InlineRowActions({
  actions,
  row,
  onAction
}) {
  const ungrouped = actions.filter((a) => !a.group);
  const grouped = actions.filter((a) => !!a.group);
  return /* @__PURE__ */ jsxs(Fragment$1, { children: [
    ungrouped.map((action) => {
      const iconNode = action.showIcon !== false ? action.icon ? /* @__PURE__ */ jsx(ResourceIcon, { iconName: action.icon, size: 16, color: action.iconColor ?? void 0 }) : action.destructive ? /* @__PURE__ */ jsx(WarningIcon, { size: 16, weight: "fill", color: action.iconColor ?? void 0 }) : /* @__PURE__ */ jsx(LightningIcon, { size: 16, color: action.iconColor ?? void 0 }) : null;
      return /* @__PURE__ */ jsx(
        RowActionButton,
        {
          label: action.name,
          icon: iconNode,
          disabled: !canRunInlineAction(row, action),
          variant: action.destructive ? "destructive" : "primary",
          onClick: () => onAction(action, row)
        },
        action.uriKey
      );
    }),
    grouped.length > 0 && /* @__PURE__ */ jsx(InlineActionMenu, { actions: grouped, row, onAction })
  ] });
}
function RowActionButton({
  label,
  icon,
  disabled,
  variant = "primary",
  onClick
}) {
  const variantClass = variant === "destructive" ? "martis-row-action-btn--destructive" : variant === "primary" ? "martis-row-action-btn--primary" : "";
  return /* @__PURE__ */ jsx(
    "button",
    {
      type: "button",
      disabled,
      onClick: (e) => {
        e.stopPropagation();
        if (!disabled) onClick();
      },
      className: `martis-row-action-btn ${variantClass}`.trim(),
      "data-pr-tooltip": label,
      "data-pr-position": "top",
      "aria-label": label,
      children: icon
    }
  );
}
function DefaultTable({
  columns,
  rows,
  sortBy,
  sortDir,
  onSort,
  selectedIds,
  onToggleSelect,
  onToggleAll: _onToggleAll,
  onSetSelection,
  onClickRow,
  resourceKey,
  selectable = false,
  inlineActions = [],
  onInlineAction,
  defaultRowActions,
  onDefaultView,
  onDefaultEdit,
  onDefaultDelete,
  onDefaultRestore,
  onDefaultForceDelete,
  tableConfig,
  actionsColumnLabel,
  emptyMessage
}) {
  const { t } = useTranslation("resources");
  const { t: tMsg } = useTranslation("messages");
  const { t: tAct } = useTranslation("actions");
  const sortOrder = sortDir === "asc" ? 1 : -1;
  const striped = (tableConfig == null ? void 0 : tableConfig.striped) !== false;
  const gridlines = (tableConfig == null ? void 0 : tableConfig.showGridlines) === true;
  const size = (tableConfig == null ? void 0 : tableConfig.size) ?? "normal";
  const rowHover = (tableConfig == null ? void 0 : tableConfig.rowHover) !== false;
  const classNames2 = [
    "w-full",
    "martis-datatable",
    striped && "martis-datatable-striped",
    gridlines && "martis-datatable-gridlines",
    size === "small" && "martis-datatable-sm",
    size === "large" && "martis-datatable-lg",
    !rowHover && "martis-datatable-no-hover"
  ].filter(Boolean).join(" ");
  const selectedRows = rows.filter((r) => selectedIds.has(r.id));
  function handleSelectionChange(e) {
    const newSelection = e.value;
    const newIds = new Set(newSelection.map((r) => r.id));
    if (onSetSelection) {
      onSetSelection(newIds);
    } else {
      const currentIds = new Set(selectedIds);
      for (const id of newIds) {
        if (!currentIds.has(id)) onToggleSelect(id);
      }
      for (const id of currentIds) {
        if (!newIds.has(id)) onToggleSelect(id);
      }
    }
  }
  const showDefaults = (defaultRowActions == null ? void 0 : defaultRowActions.enabled) === true;
  const showView = showDefaults && (defaultRowActions == null ? void 0 : defaultRowActions.view) !== false && !!onDefaultView;
  const showEdit = showDefaults && (defaultRowActions == null ? void 0 : defaultRowActions.edit) !== false && !!onDefaultEdit;
  const showDelete = showDefaults && (defaultRowActions == null ? void 0 : defaultRowActions.delete) !== false && !!onDefaultDelete;
  const showRestore = showDefaults && !!onDefaultRestore;
  const showForceDelete = showDefaults && !!onDefaultForceDelete;
  const hasDefaults = showView || showEdit || showDelete;
  const hasActionsColumn = hasDefaults || inlineActions.length > 0;
  return /* @__PURE__ */ jsx(Fragment$1, { children: /* @__PURE__ */ jsxs(
    DataTable,
    {
      value: rows,
      dataKey: "id",
      removableSort: true,
      sortField: sortBy ?? void 0,
      sortOrder,
      onSort: (e) => {
        if (e.sortField) onSort(String(e.sortField));
      },
      onRowClick: (e) => {
        var _a;
        const target = (_a = e.originalEvent) == null ? void 0 : _a.target;
        if ((target == null ? void 0 : target.closest(".martis-select-column")) || (target == null ? void 0 : target.closest('[data-row-action="suppress"]'))) return;
        onClickRow == null ? void 0 : onClickRow(e.data);
      },
      rowClassName: (row) => {
        var _a;
        const classes2 = [];
        if (!onClickRow) classes2.push("martis-row-no-click");
        if (((_a = row._authorization) == null ? void 0 : _a.authorizedToView) === false) classes2.push("cursor-default opacity-70");
        if ("deleted_at" in row && row["deleted_at"] !== null) classes2.push("opacity-60");
        if (selectable && selectedIds.has(row.id)) classes2.push("martis-row-selected");
        return classes2.join(" ");
      },
      emptyMessage: emptyMessage === void 0 ? /* @__PURE__ */ jsx("div", { className: "py-8 text-center text-sm text-gray-400", children: t("no_records") }) : emptyMessage === null ? /* @__PURE__ */ jsx("div", { className: "py-8", "aria-hidden": "true" }) : emptyMessage,
      className: classNames2,
      tableClassName: "min-w-full",
      tableStyle: (tableConfig == null ? void 0 : tableConfig.layout) === "fixed" ? { tableLayout: "fixed" } : void 0,
      selection: selectable ? selectedRows : [],
      onSelectionChange: selectable ? handleSelectionChange : void 0,
      selectionMode: selectable ? "checkbox" : null,
      children: [
        selectable && /* @__PURE__ */ jsx(
          Column,
          {
            selectionMode: "multiple",
            headerStyle: { width: "3rem" },
            headerClassName: "martis-select-column",
            bodyClassName: "martis-select-column"
          }
        ),
        rows.some((r) => "deleted_at" in r && r["deleted_at"] !== null) && /* @__PURE__ */ jsx(
          Column,
          {
            header: "",
            body: (row) => "deleted_at" in row && row["deleted_at"] !== null ? /* @__PURE__ */ jsx("span", { className: "martis-badge martis-badge-danger", children: tMsg("archived") }) : null,
            style: { width: "5rem" }
          }
        ),
        columns.map(({ field }) => {
          const col = field.column;
          const style = columnStyleFrom(col);
          const truncateClass = (col == null ? void 0 : col.truncate) ? "martis-col-truncate" : void 0;
          return /* @__PURE__ */ jsx(
            Column,
            {
              field: field.attribute,
              header: field.sortable ? /* @__PURE__ */ jsxs(
                "button",
                {
                  type: "button",
                  className: "martis-th-sortable flex items-center gap-1 font-bold uppercase tracking-wider text-xs",
                  style: { color: "var(--martis-table-header-text)" },
                  onClick: () => onSort(field.attribute),
                  children: [
                    field.label,
                    sortBy === field.attribute ? /* @__PURE__ */ jsx(SortIcon, { active: true, dir: sortDir }) : (
                      // Discreet hint that the column is sortable. Low opacity
                      // at rest, slightly brighter when the header is hovered.
                      /* @__PURE__ */ jsx(CaretUpDownIcon, { size: 11, weight: "bold", className: "martis-th-sort-hint" })
                    )
                  ]
                }
              ) : /* @__PURE__ */ jsx("span", { className: "text-xs font-bold uppercase tracking-wider", style: { color: "var(--martis-table-header-text)" }, children: field.label }),
              body: (row) => (
                // A field the row hides (`_hidden`) leaves its cell empty.
                isHiddenOn(row, field.attribute) ? null : /* @__PURE__ */ jsx(FieldDisplay, { field, value: row[field.attribute], resourceKey, context: "index" })
              ),
              sortable: false,
              style,
              headerStyle: style,
              bodyClassName: truncateClass
            },
            field.attribute
          );
        }),
        hasActionsColumn && /* @__PURE__ */ jsx(
          Column,
          {
            header: (
              // `undefined` → localised fallback; `null` → hidden header.
              actionsColumnLabel === void 0 ? tAct("actions", { defaultValue: "Actions" }) : actionsColumnLabel ?? ""
            ),
            headerClassName: "text-right",
            body: (row) => {
              var _a, _b, _c;
              const viewDisabled = ((_a = row._authorization) == null ? void 0 : _a.authorizedToView) === false;
              const editDisabled = ((_b = row._authorization) == null ? void 0 : _b.authorizedToUpdate) === false;
              const deleteDisabled = ((_c = row._authorization) == null ? void 0 : _c.authorizedToDelete) === false;
              const isTrashed = "deleted_at" in row && row["deleted_at"] != null;
              const hasInline = inlineActions.length > 0;
              return /* @__PURE__ */ jsxs("div", { className: "martis-row-actions justify-end", onClick: (e) => e.stopPropagation(), children: [
                showView && /* @__PURE__ */ jsx(
                  RowActionButton,
                  {
                    label: tAct("view", "View"),
                    icon: /* @__PURE__ */ jsx(EyeIcon, { size: 16 }),
                    disabled: viewDisabled,
                    onClick: () => onDefaultView == null ? void 0 : onDefaultView(row)
                  }
                ),
                !isTrashed && showEdit && /* @__PURE__ */ jsx(
                  RowActionButton,
                  {
                    label: tAct("edit", "Edit"),
                    icon: /* @__PURE__ */ jsx(PencilSimpleIcon, { size: 16 }),
                    disabled: editDisabled,
                    onClick: () => onDefaultEdit == null ? void 0 : onDefaultEdit(row)
                  }
                ),
                !isTrashed && showDelete && /* @__PURE__ */ jsx(
                  RowActionButton,
                  {
                    label: tAct("delete", "Delete"),
                    icon: /* @__PURE__ */ jsx(TrashIcon$1, { size: 16 }),
                    disabled: deleteDisabled,
                    variant: "destructive",
                    onClick: () => onDefaultDelete == null ? void 0 : onDefaultDelete(row)
                  }
                ),
                isTrashed && showRestore && /* @__PURE__ */ jsx(
                  RowActionButton,
                  {
                    label: tAct("restore", "Restore"),
                    icon: /* @__PURE__ */ jsx(ArrowCounterClockwiseIcon, { size: 16 }),
                    disabled: false,
                    onClick: () => onDefaultRestore == null ? void 0 : onDefaultRestore(row)
                  }
                ),
                isTrashed && showForceDelete && /* @__PURE__ */ jsx(
                  RowActionButton,
                  {
                    label: tAct("force_delete", "Force delete"),
                    icon: /* @__PURE__ */ jsx(SkullIcon, { size: 16 }),
                    disabled: false,
                    variant: "destructive",
                    onClick: () => onDefaultForceDelete == null ? void 0 : onDefaultForceDelete(row)
                  }
                ),
                hasDefaults && hasInline && /* @__PURE__ */ jsx("span", { className: "martis-row-actions__divider", "aria-hidden": "true" }),
                /* @__PURE__ */ jsx(
                  InlineRowActions,
                  {
                    actions: inlineActions,
                    row,
                    onAction: (action, r) => onInlineAction == null ? void 0 : onInlineAction(action, r)
                  }
                )
              ] });
            },
            style: { width: "auto", textAlign: "right" }
          }
        )
      ]
    },
    resourceKey
  ) });
}
if (!registry.has("component:Table")) {
  registry.register("component:Table", DefaultTable);
}
function ActionDryRunPreview({ preview }) {
  const { t } = useTranslation("actions");
  const record = preview !== null && typeof preview === "object" && !Array.isArray(preview) ? preview : null;
  const text = record && typeof record.preview === "string" ? record.preview : typeof preview === "string" ? preview : null;
  const details = record ? Object.entries(record).filter(([key]) => key !== "preview") : [];
  const raw = record === null && text === null && preview !== null && preview !== void 0;
  return /* @__PURE__ */ jsxs(
    "div",
    {
      className: "martis-action-preview mt-4 rounded-md p-3 text-sm",
      role: "status",
      "aria-live": "polite",
      style: {
        background: "var(--martis-surface-alt, var(--martis-surface))",
        border: "1px solid var(--martis-border)",
        color: "var(--martis-text)"
      },
      children: [
        /* @__PURE__ */ jsx("p", { className: "mb-1 text-xs font-medium", style: { color: "var(--martis-text-muted)" }, children: t("preview_result", "Preview (nothing was changed)") }),
        text !== null && /* @__PURE__ */ jsx("p", { children: text }),
        details.length > 0 && /* @__PURE__ */ jsx("dl", { className: "mt-2 space-y-1", children: details.map(([key, value]) => /* @__PURE__ */ jsxs("div", { className: "flex gap-2", children: [
          /* @__PURE__ */ jsx("dt", { className: "font-medium", style: { color: "var(--martis-text-muted)" }, children: key }),
          /* @__PURE__ */ jsx("dd", { className: "min-w-0 break-words font-mono text-xs", children: typeof value === "string" ? value : JSON.stringify(value) })
        ] }, key)) }),
        raw && /* @__PURE__ */ jsx("pre", { className: "mt-2 whitespace-pre-wrap font-mono text-xs", children: JSON.stringify(preview, null, 2) })
      ]
    }
  );
}
function actionVisitTarget(path, params) {
  if (typeof path !== "string" || path === "") return null;
  const query = new URLSearchParams();
  if (params !== null && typeof params === "object") {
    for (const [key, value] of Object.entries(params)) {
      if (value === null || value === void 0 || value === false || value === "") continue;
      query.append(key, String(value));
    }
  }
  const target = `/${path.replace(/^\/+/, "")}`;
  const hashAt = target.indexOf("#");
  const fragment = hashAt === -1 ? "" : target.slice(hashAt);
  const beforeFragment = hashAt === -1 ? target : target.slice(0, hashAt);
  const queryAt = beforeFragment.indexOf("?");
  const pathname = queryAt === -1 ? beforeFragment : beforeFragment.slice(0, queryAt);
  const search = [queryAt === -1 ? "" : beforeFragment.slice(queryAt + 1), query.toString()].filter((part) => part !== "").join("&");
  return safeInternalPath(`${pathname}${search !== "" ? `?${search}` : ""}${fragment}`);
}
function answerData(value) {
  if (value === null || typeof value !== "object" || Array.isArray(value)) return {};
  const proto = Object.getPrototypeOf(value);
  return proto === Object.prototype || proto === null ? value : {};
}
function triggerDownload(url, filename) {
  const safe = safeNavigationUrl(url, { blob: true });
  if (safe === null) {
    console.error("[martis] action response: refused to download a URL that is not http(s) or a path", url);
    return;
  }
  const link = document.createElement("a");
  link.href = safe;
  link.setAttribute("download", filename ?? "");
  link.style.display = "none";
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
function handleActionResponse(response, ctx) {
  const data = (response == null ? void 0 : response.data) ?? {};
  const text = (value) => typeof value === "string" && value !== "" ? value : null;
  const success = (message) => ctx.addToast("success", text(message) ?? ctx.t("action_success"));
  const done = () => {
    ctx.hide();
    ctx.refresh();
  };
  switch (response == null ? void 0 : response.type) {
    case "message":
      success(data.message);
      done();
      return;
    case "danger":
      ctx.addToast("error", text(data.message) ?? ctx.t("action_failed"));
      done();
      return;
    case "redirect": {
      const url = text(data.url);
      if (url !== null) {
        const safe = safeNavigationUrl(url);
        if (safe === null) {
          console.error("[martis] action response: refused to redirect to a URL that is not http(s) or a path", url);
          break;
        }
        window.location.href = safe;
        return;
      }
      break;
    }
    case "visit": {
      const target = actionVisitTarget(data.path, data.params);
      if (target === null) {
        console.error("[martis] action response: refused to visit a path outside the app", data.path);
        break;
      }
      success();
      ctx.hide();
      ctx.navigate(target);
      return;
    }
    case "openInNewTab": {
      const url = text(data.url);
      if (url !== null) openExternal(url);
      done();
      return;
    }
    case "download": {
      const url = text(data.url);
      if (url !== null) triggerDownload(url, text(data.filename));
      success();
      done();
      return;
    }
    case "emit": {
      const event = text(data.event);
      if (event !== null) {
        martisEventBus.emit(event, answerData(data.data));
      }
      success();
      done();
      return;
    }
    case "modal": {
      const component = text(data.component) ?? "";
      success();
      ctx.hide();
      if (ctx.showModal !== null && component !== "" && ctx.showModal(component, answerData(data.data), ctx.refresh)) {
        return;
      }
      console.warn(`[martis] action response: no component is registered for "${component}"`);
      ctx.refresh();
      return;
    }
    case "openCreate": {
      const resource = text(data.resource);
      if (resource !== null && ctx.onOpenCreate) {
        ctx.hide();
        ctx.onOpenCreate(resource);
        return;
      }
      break;
    }
    case "openDetail":
    case "openUpdate": {
      const resource = text(data.resource);
      const open = (response == null ? void 0 : response.type) === "openDetail" ? ctx.onOpenDetail : ctx.onOpenUpdate;
      if (resource !== null && data.recordId != null && open) {
        ctx.hide();
        open(resource, data.recordId);
        return;
      }
      break;
    }
  }
  success();
  done();
}
function DefaultActionModal({ resource, action, selectedIds, visible, onHide, onSuccess, onOpenCreate, onOpenDetail, onOpenUpdate, via, lens }) {
  var _a, _b;
  const actionUrl = !action ? "" : lens ? apiPath`/api/resources/${resource}/lenses/${lens}/actions/${action.uriKey}` : apiPath`/api/resources/${resource}/actions/${action.uriKey}`;
  const { addToast } = useToast();
  const { t } = useTranslation("actions");
  const navigate = useNavigate();
  const showModal = useActionResponseModal();
  const [fieldValues, setFieldValues] = useState({});
  const [fieldErrors, setFieldErrors] = useState({});
  const [preview, setPreview] = useState(void 0);
  const [animVisible, setAnimVisible] = useState(false);
  const autoExecuted = useRef(false);
  useEffect(() => {
    if (visible) {
      requestAnimationFrame(() => setAnimVisible(true));
      autoExecuted.current = false;
    } else {
      setAnimVisible(false);
    }
  }, [visible]);
  const handleBackdropClose = useCallback(() => {
    setAnimVisible(false);
    setTimeout(onHide, 200);
  }, [onHide]);
  useEffect(() => {
    if (!visible) return;
    function handleKey(e) {
      if (e.key === "Escape") {
        e.preventDefault();
        onHide();
      }
    }
    document.addEventListener("keydown", handleKey);
    return () => document.removeEventListener("keydown", handleKey);
  }, [visible, onHide]);
  useModalHistoryLock(visible && !!action);
  const fieldsQuery = useQuery({
    queryKey: ["action-fields", resource, lens ?? null, action == null ? void 0 : action.uriKey],
    queryFn: () => api.get(`${actionUrl}/fields`),
    enabled: visible && !!action
  });
  const fields = ((_b = (_a = fieldsQuery.data) == null ? void 0 : _a.data) == null ? void 0 : _b.fields) ?? [];
  useEffect(() => {
    setFieldValues({});
    setFieldErrors({});
    setPreview(void 0);
  }, [action == null ? void 0 : action.uriKey, visible]);
  const executeMutation = useMutation({
    // Each run starts without the errors of the last one, as every form
    // clears its errors before a save.
    onMutate: () => setFieldErrors({}),
    mutationFn: (params) => api.post(
      actionUrl,
      {
        resources: selectedIds,
        fields: { ...fieldValues, ...params.extraFields ?? {} },
        dryRun: params.dryRun ?? false,
        ...via ?? {}
      }
    ),
    onSuccess: (res, params) => {
      var _a2;
      if (params.dryRun) {
        setPreview(((_a2 = res == null ? void 0 : res.data) == null ? void 0 : _a2.preview) ?? null);
        return;
      }
      martisEventBus.emit("martis:action-executed", { resourceKey: resource, action: (action == null ? void 0 : action.uriKey) ?? "", ids: selectedIds.map(String) });
      handleActionResponse(res == null ? void 0 : res.data, {
        t,
        addToast,
        navigate,
        hide: onHide,
        refresh: onSuccess,
        showModal,
        onOpenCreate,
        onOpenDetail,
        onOpenUpdate
      });
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        const mapped = {};
        for (const e of err.errors) {
          const fieldKey = e.field.replace(/^fields\./, "");
          if (!mapped[fieldKey]) {
            mapped[fieldKey] = e.message;
          }
        }
        if (Object.keys(mapped).length > 0) {
          setFieldErrors(mapped);
          addToast("error", err.message || t("action_failed"));
          return;
        }
      }
      addToast("error", (err instanceof ApiError ? err.message : err.message) ?? t("action_failed"));
    }
  });
  if (!visible || !action) return null;
  if (action.customComponent) {
    const CustomComp = componentRegistry.resolve(action.customComponent);
    if (CustomComp) {
      const C = CustomComp;
      const customProps = {
        action,
        resource,
        selectedIds,
        componentProps: action.customComponentProps ?? {},
        onFieldsChange: (newFields) => setFieldValues((prev) => ({ ...prev, ...newFields })),
        onExecute: (extraFields) => executeMutation.mutate({ extraFields }),
        onClose: onHide,
        isExecuting: executeMutation.isPending
      };
      return createPortal(
        /* @__PURE__ */ jsx("div", { style: { position: "fixed", inset: 0, zIndex: 9990 }, children: /* @__PURE__ */ jsx(C, { ...customProps }) }),
        document.body
      );
    }
    return null;
  }
  const hasFields = fields.length > 0;
  const needsConfirmation = action.withConfirmation || hasFields || action.supportsDryRun;
  if (!needsConfirmation && !autoExecuted.current && !executeMutation.isPending) {
    autoExecuted.current = true;
    setTimeout(() => executeMutation.mutate({}), 0);
    return null;
  }
  if (!needsConfirmation) return null;
  const modalWidth = action.modalSize === "fullscreen" ? "100vw" : action.modalSize === "7xl" ? "80rem" : action.modalSize === "6xl" ? "72rem" : action.modalSize === "5xl" ? "64rem" : action.modalSize === "4xl" ? "56rem" : action.modalSize === "3xl" ? "48rem" : action.modalSize === "2xl" ? "42rem" : action.modalSize === "xl" ? "36rem" : action.modalSize === "lg" ? "32rem" : action.modalSize === "sm" ? "20rem" : "28rem";
  const confirmButton = action.confirmButtonText ?? (action.destructive ? t("confirm_destructive") : t("run_action"));
  const cancelButton = action.cancelButtonText ?? t("cancel");
  function renderActionIcon() {
    if (action.icon) {
      return /* @__PURE__ */ jsx(ResourceIcon, { iconName: action.icon, size: 18, weight: "fill" });
    }
    if (action.destructive) {
      return /* @__PURE__ */ jsx(WarningIcon, { size: 18, weight: "fill", style: { color: "var(--martis-danger)" } });
    }
    return /* @__PURE__ */ jsx(LightningIcon, { size: 18, weight: "fill", style: { color: "var(--martis-accent)" } });
  }
  const content = /* @__PURE__ */ jsx(
    "div",
    {
      className: "martis-modal-scrim",
      style: { opacity: animVisible ? 1 : 0, transition: "opacity 200ms ease" },
      onClick: handleBackdropClose,
      children: /* @__PURE__ */ jsxs(
        "div",
        {
          role: "dialog",
          "aria-modal": "true",
          className: "martis-modal-surface",
          style: {
            maxWidth: modalWidth,
            transform: animVisible ? "scale(1)" : "scale(0.95)",
            transition: "transform 200ms ease",
            borderTop: action.destructive ? "3px solid var(--martis-danger)" : void 0
          },
          onClick: (e) => e.stopPropagation(),
          children: [
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-head", children: [
              /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-3", children: [
                renderActionIcon(),
                /* @__PURE__ */ jsx("h3", { className: "martis-modal-head-title", children: action.name })
              ] }),
              /* @__PURE__ */ jsx(
                "button",
                {
                  type: "button",
                  onClick: onHide,
                  className: "martis-modal-close",
                  "aria-label": cancelButton,
                  children: /* @__PURE__ */ jsx(XIcon, { size: 16 })
                }
              )
            ] }),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-body", children: [
              action.confirmText && /* @__PURE__ */ jsx("p", { className: "mb-4", children: action.confirmText }),
              hasFields && /* @__PURE__ */ jsx("div", { className: "space-y-4", children: fields.map((field) => /* @__PURE__ */ jsxs("div", { children: [
                /* @__PURE__ */ jsxs("label", { className: "mb-1 block text-sm font-medium", style: { color: "var(--martis-text)" }, children: [
                  field.label,
                  field.required && /* @__PURE__ */ jsx("span", { className: "ml-1", style: { color: "var(--martis-danger)" }, children: "*" })
                ] }),
                /* @__PURE__ */ jsx(
                  FieldInput,
                  {
                    field,
                    value: fieldValues[field.attribute] ?? "",
                    onChange: (val) => {
                      setFieldValues((prev) => ({ ...prev, [field.attribute]: val }));
                      setPreview(void 0);
                    },
                    ...fieldErrorProps(fieldErrors, field.attribute),
                    context: "create",
                    actionEndpoint: actionUrl
                  }
                )
              ] }, field.attribute)) }),
              preview !== void 0 && /* @__PURE__ */ jsx(ActionDryRunPreview, { preview })
            ] }),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-foot", children: [
              action.supportsDryRun && /* @__PURE__ */ jsx(
                "button",
                {
                  type: "button",
                  onClick: () => executeMutation.mutate({ dryRun: true }),
                  disabled: executeMutation.isPending,
                  className: "martis-btn-secondary",
                  children: t("preview")
                }
              ),
              /* @__PURE__ */ jsxs(
                "button",
                {
                  type: "button",
                  onClick: onHide,
                  disabled: executeMutation.isPending,
                  className: "martis-btn-secondary",
                  children: [
                    /* @__PURE__ */ jsx(XIcon, { size: 14 }),
                    cancelButton
                  ]
                }
              ),
              /* @__PURE__ */ jsxs(
                "button",
                {
                  type: "button",
                  onClick: () => executeMutation.mutate({}),
                  disabled: executeMutation.isPending,
                  className: action.destructive ? "martis-btn-danger" : "martis-btn-primary",
                  children: [
                    /* @__PURE__ */ jsx(LightningIcon, { size: 14 }),
                    executeMutation.isPending ? t("please_wait") : confirmButton
                  ]
                }
              )
            ] })
          ]
        }
      )
    }
  );
  return createPortal(content, document.body);
}
if (!registry.has("component:ActionModal")) {
  registry.register("component:ActionModal", DefaultActionModal);
}
function ActionModal(props) {
  const Component = registry.resolve(
    "component:ActionModal",
    DefaultActionModal
  );
  return /* @__PURE__ */ jsx(Component, { ...props });
}
function ActionDrawer({ type, resource, recordId, onClose, onSuccess, onSwitchTo }) {
  var _a, _b, _c;
  const { addToast } = useToast();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const schemaQuery = useQuery({
    queryKey: ["schema", resource],
    queryFn: () => api.get(apiPath`/api/resources/${resource}/schema`)
  });
  if (schemaQuery.isLoading) {
    return /* @__PURE__ */ jsx(MartisLoader, {});
  }
  const schema = (_a = schemaQuery.data) == null ? void 0 : _a.data;
  if (!schema) return null;
  const componentKey = type === "create" ? "martis:drawer-create" : type === "update" ? "martis:drawer-update" : "martis:drawer-detail";
  const DrawerComponent = componentRegistry.resolve(componentKey);
  if (!DrawerComponent) return null;
  const C = DrawerComponent;
  const overrideParams = ((_c = (_b = schema.overrides) == null ? void 0 : _b[type]) == null ? void 0 : _c.params) ?? {};
  const overrideProps = {
    schema,
    resource,
    params: overrideParams,
    record: null,
    recordId: recordId != null ? String(recordId) : null,
    navigate: (to) => navigate(to),
    onClose,
    onCreated: () => {
      var _a2;
      void qc.invalidateQueries({ queryKey: ["resources", resource] });
      addToast("success", ((_a2 = schema.messages) == null ? void 0 : _a2.created) ?? "Record created successfully.");
      onSuccess();
      onClose();
    },
    onUpdated: () => {
      var _a2;
      void qc.invalidateQueries({ queryKey: ["resources", resource] });
      addToast("success", ((_a2 = schema.messages) == null ? void 0 : _a2.updated) ?? "Record updated successfully.");
      onSuccess();
      onClose();
    },
    onDeleted: () => {
      void qc.invalidateQueries({ queryKey: ["resources", resource] });
      onSuccess();
      onClose();
    },
    onEdit: (id) => {
      if (id == null) return;
      if (onSwitchTo) {
        onSwitchTo({ type: "update", resource, recordId: id });
        return;
      }
      navigate(routePath`/resources/${resource}/${id}/edit`);
    },
    onView: (id) => {
      if (onSwitchTo) {
        onSwitchTo({ type: "detail", resource, recordId: id });
        return;
      }
      navigate(recordHref(resource, id));
    },
    addToast
  };
  return /* @__PURE__ */ jsx(C, { ...overrideProps });
}
function RelationshipTableShell(props) {
  var _a, _b, _c;
  const {
    title,
    relatedResource,
    relatedIcon,
    showRelationIcon = true,
    showRelationCount = true,
    collapsable = false,
    collapsedByDefault = false,
    queryKey,
    fetchUrl,
    deleteUrl,
    createUrl,
    editUrl,
    viewUrl,
    pivotFields,
    selectable,
    selectedRows,
    onSelectionChange,
    perPage: perPageDefault,
    perPageOptions,
    searchable,
    canCreate,
    canUpdate,
    canDelete,
    hideSearch,
    hideCreateButton,
    hidePerPageSelector,
    hideSoftDeleteToggle,
    hideViewAction,
    hideEditAction,
    hideDeleteAction,
    hideRestoreAction,
    hideForceDeleteAction,
    defaultTrashed,
    toolbarExtras,
    rowActionsExtras,
    rowActions
  } = props;
  const { t: tAct } = useTranslation("actions");
  const { t: tMsg } = useTranslation("messages");
  const { t: tRes } = useTranslation("resources");
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(perPageDefault);
  const [sort, setSort] = useState(null);
  const [direction, setDirection] = useState("asc");
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [forceDeleteTarget, setForceDeleteTarget] = useState(null);
  const [restoreTarget, setRestoreTarget] = useState(null);
  const [isCollapsed, setIsCollapsed] = useState(collapsedByDefault);
  const [trashed, setTrashed] = useState(defaultTrashed ?? "active");
  const [activeAction, setActiveAction] = useState(null);
  const [actionDrawer, setActionDrawer] = useState(null);
  const schemaQuery = useQuery({
    queryKey: ["schema", relatedResource],
    queryFn: () => api.get(apiPath`/api/resources/${relatedResource}/schema`),
    enabled: !!relatedResource
  });
  const recordsQuery = useQuery({
    queryKey: [...queryKey, { search, page, perPage, sort, direction, trashed }],
    queryFn: () => {
      const params = new URLSearchParams();
      if (search) params.set("search", search);
      params.set("page", String(page));
      params.set("per_page", String(perPage));
      if (sort) {
        params.set("sort", sort);
        params.set("direction", direction);
      }
      if (trashed !== "active") params.set("trashed", trashed);
      return api.get(fetchUrl(params));
    }
  });
  const deleteMutation = useMutation({
    mutationFn: (relatedId) => {
      if (!deleteUrl) return Promise.resolve(void 0);
      return api.delete(deleteUrl(relatedId));
    },
    onSuccess: (_res, relatedId) => {
      if (deleteUrl) emitRecordEvent("deleted", relatedResource, relatedId);
      void qc.invalidateQueries({ queryKey });
      setDeleteTarget(null);
    }
  });
  const restoreMutation = useMutation({
    mutationFn: (relatedId) => api.put(apiPath`/api/resources/${relatedResource}/${relatedId}/restore`),
    onSuccess: (_res, relatedId) => {
      emitRecordEvent("restored", relatedResource, relatedId);
      void qc.invalidateQueries({ queryKey });
      setRestoreTarget(null);
    }
  });
  const forceDeleteMutation = useMutation({
    mutationFn: (relatedId) => api.delete(apiPath`/api/resources/${relatedResource}/${relatedId}/force`),
    onSuccess: (_res, relatedId) => {
      emitRecordEvent("deleted", relatedResource, relatedId);
      void qc.invalidateQueries({ queryKey });
      setForceDeleteTarget(null);
    }
  });
  const schema = (_a = schemaQuery.data) == null ? void 0 : _a.data;
  const records = ((_b = recordsQuery.data) == null ? void 0 : _b.data) ?? [];
  const pagination = (_c = recordsQuery.data) == null ? void 0 : _c.meta;
  const recordsFailed = recordsQuery.isError && recordsQuery.data === void 0;
  const emptyMessage = recordsQuery.isSuccess ? /* @__PURE__ */ jsx("div", { className: "py-8 text-center text-sm", style: { color: "var(--martis-text-muted)" }, children: tMsg("no_records_available", "No records available.") }) : /* @__PURE__ */ jsx("div", { className: "py-8", "aria-hidden": "true" });
  const totalCount = (pagination == null ? void 0 : pagination.total) ?? records.length;
  const indexFields = (schema == null ? void 0 : schema.fieldsForIndex) ?? [];
  const inlineActions = rowActions ? filterInlineActions((schema == null ? void 0 : schema.actions) ?? []) : [];
  const softDeletes = !!(schema == null ? void 0 : schema.softDeletes);
  const showView = !hideViewAction;
  const showEdit = canUpdate && !hideEditAction && !!editUrl;
  const showDelete = canDelete && !hideDeleteAction && !!deleteUrl;
  const showRestore = softDeletes && !hideRestoreAction;
  const showForceDelete = softDeletes && !hideForceDeleteAction;
  const hasActions = showView || showEdit || showDelete || showRestore || showForceDelete || !!rowActionsExtras || inlineActions.length > 0;
  const showSearch = searchable && !hideSearch;
  const showCreate = canCreate && !hideCreateButton && !!createUrl;
  const showPerPage = !hidePerPageSelector && (perPageOptions == null ? void 0 : perPageOptions.length) > 0;
  const showSoftDeleteToggle = softDeletes && !hideSoftDeleteToggle;
  const searchPlaceholder = (schema == null ? void 0 : schema.searchPlaceholder) ?? tMsg("search", "Search...");
  const iconFromSchema = schema == null ? void 0 : schema.icon;
  const displayIcon = relatedIcon ?? iconFromSchema;
  function handleSort(attribute) {
    if (sort === attribute) {
      setDirection((d) => d === "asc" ? "desc" : "asc");
    } else {
      setSort(attribute);
      setDirection("asc");
    }
    setPage(1);
  }
  function SortIcon2({ active, dir }) {
    if (!active) return /* @__PURE__ */ jsx(CaretUpDownIcon, { size: 14, className: "text-gray-400" });
    return dir === "asc" ? /* @__PURE__ */ jsx(CaretUpIcon, { size: 14, style: { color: "var(--martis-accent)" } }) : /* @__PURE__ */ jsx(CaretDownIcon, { size: 14, style: { color: "var(--martis-accent)" } });
  }
  const showMeta = !isCollapsed && (showPerPage || showSoftDeleteToggle);
  return /* @__PURE__ */ jsxs("div", { className: "mt-6 space-y-3", children: [
    /* @__PURE__ */ jsxs("div", { className: "martis-relation-toolbar", children: [
      /* @__PURE__ */ jsxs(
        "h3",
        {
          className: `martis-relation-heading flex items-center gap-2 text-lg font-semibold ${collapsable ? "cursor-pointer select-none" : ""}`,
          style: { color: "var(--martis-text)" },
          onClick: collapsable ? () => setIsCollapsed((v) => !v) : void 0,
          children: [
            showRelationIcon && displayIcon && /* @__PURE__ */ jsx(ResourceIcon, { iconName: displayIcon, size: 20 }),
            collapsable && /* @__PURE__ */ jsx(
              CaretRightIcon,
              {
                size: 14,
                weight: "bold",
                style: {
                  transform: isCollapsed ? "rotate(0deg)" : "rotate(90deg)",
                  transition: "transform 0.15s",
                  color: "var(--martis-text-muted)"
                }
              }
            ),
            /* @__PURE__ */ jsx("span", { children: title }),
            showRelationCount && !recordsFailed && /* @__PURE__ */ jsx(
              "span",
              {
                className: "martis-badge",
                style: {
                  backgroundColor: "var(--martis-surface)",
                  color: "var(--martis-text-muted)",
                  borderColor: "var(--martis-border)"
                },
                children: totalCount
              }
            )
          ]
        }
      ),
      !isCollapsed && /* @__PURE__ */ jsxs("div", { className: "martis-relation-primary", children: [
        showSearch && /* @__PURE__ */ jsxs("div", { className: "relative flex-shrink-0", style: { width: "16rem" }, children: [
          /* @__PURE__ */ jsx("span", { className: "absolute inset-y-0 left-3 flex items-center pointer-events-none", children: /* @__PURE__ */ jsx(MagnifyingGlassIcon, { size: 14, style: { color: "var(--martis-text-muted)" } }) }),
          /* @__PURE__ */ jsx(
            "input",
            {
              type: "text",
              value: search,
              onChange: (e) => {
                setSearch(e.target.value);
                setPage(1);
              },
              placeholder: searchPlaceholder,
              className: "martis-resource-search block w-full rounded-md py-2 pl-9 pr-8 text-sm focus:outline-none focus:ring-1",
              style: {
                backgroundColor: "var(--martis-input-bg)",
                border: "1px solid var(--martis-border)",
                color: "var(--martis-text)"
              }
            }
          ),
          search && /* @__PURE__ */ jsx(
            "button",
            {
              type: "button",
              onClick: () => {
                setSearch("");
                setPage(1);
              },
              className: "absolute inset-y-0 right-2 flex items-center",
              style: { cursor: "pointer", background: "none", border: "none" },
              "data-pr-tooltip": tMsg("clear", "Clear"),
              "data-pr-position": "top",
              children: /* @__PURE__ */ jsx(XIcon, { size: 14, weight: "bold", style: { color: "var(--martis-danger)" } })
            }
          )
        ] }),
        showCreate && /* @__PURE__ */ jsxs(
          "button",
          {
            type: "button",
            onClick: () => navigate(createUrl),
            className: "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-martis-accent-contrast",
            style: { backgroundColor: "var(--martis-accent)" },
            children: [
              /* @__PURE__ */ jsx(PlusIcon$1, { size: 14, weight: "bold" }),
              tAct("create", "Create")
            ]
          }
        ),
        typeof toolbarExtras === "function" ? toolbarExtras({ selectedRows: selectedRows ?? [] }) : toolbarExtras
      ] }),
      showMeta && /* @__PURE__ */ jsxs(
        "div",
        {
          className: "martis-relation-meta",
          "data-has-trashed": showSoftDeleteToggle ? "true" : "false",
          children: [
            showPerPage && /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-2 flex-shrink-0", children: [
              /* @__PURE__ */ jsxs("label", { className: "text-xs martis-text-muted whitespace-nowrap", children: [
                tRes("per_page"),
                ":"
              ] }),
              /* @__PURE__ */ jsx(
                "select",
                {
                  value: perPage,
                  onChange: (e) => {
                    setPerPage(Number(e.target.value));
                    setPage(1);
                  },
                  className: "martis-perpage-select",
                  children: perPageOptions.map((opt) => /* @__PURE__ */ jsx("option", { value: opt, children: opt }, opt))
                }
              )
            ] }),
            showSoftDeleteToggle && /* @__PURE__ */ jsx("div", { className: "flex items-center gap-2 flex-shrink-0", children: /* @__PURE__ */ jsxs(
              "select",
              {
                value: trashed,
                onChange: (e) => {
                  setTrashed(e.target.value);
                  setPage(1);
                },
                className: "martis-perpage-select",
                "data-pr-tooltip": tMsg("trashed_filter_tooltip", "Soft-delete filter"),
                "data-pr-position": "top",
                children: [
                  /* @__PURE__ */ jsx("option", { value: "active", children: tAct("trashed_active", "Active") }),
                  /* @__PURE__ */ jsx("option", { value: "with", children: tAct("trashed_with", "With trashed") }),
                  /* @__PURE__ */ jsx("option", { value: "only", children: tAct("trashed_only", "Only trashed") })
                ]
              }
            ) })
          ]
        }
      )
    ] }),
    !isCollapsed && recordsFailed && /* @__PURE__ */ jsx(
      QueryErrorState,
      {
        compact: true,
        error: recordsQuery.error,
        onRetry: () => {
          void recordsQuery.refetch();
        },
        retrying: recordsQuery.isFetching
      }
    ),
    !isCollapsed && !recordsFailed && /* @__PURE__ */ jsxs(Fragment$1, { children: [
      /* @__PURE__ */ jsx("div", { className: "overflow-x-auto", children: /* @__PURE__ */ jsxs(
        DataTable,
        {
          value: records,
          loading: recordsQuery.isLoading,
          dataKey: "id",
          removableSort: true,
          sortField: sort ?? void 0,
          sortOrder: direction === "asc" ? 1 : -1,
          onSort: (e) => {
            if (e.sortField) handleSort(String(e.sortField));
          },
          selectionMode: selectable ? "multiple" : null,
          selection: selectable ? selectedRows ?? [] : [],
          onSelectionChange: selectable ? (e) => onSelectionChange == null ? void 0 : onSelectionChange(e.value) : void 0,
          rowClassName: (row) => row.deleted_at != null ? "opacity-60" : "",
          emptyMessage,
          className: "w-full martis-datatable martis-datatable-striped",
          tableClassName: "min-w-full",
          children: [
            selectable && /* @__PURE__ */ jsx(Column, { selectionMode: "multiple", headerStyle: { width: "3rem" } }),
            records.some((r) => r.deleted_at != null) && /* @__PURE__ */ jsx(
              Column,
              {
                header: "",
                style: { width: "5rem" },
                body: (row) => row.deleted_at != null ? /* @__PURE__ */ jsx("span", { className: "martis-badge martis-badge-danger", children: tMsg("archived", "Archived") }) : null
              }
            ),
            indexFields.map((f) => /* @__PURE__ */ jsx(
              Column,
              {
                field: f.attribute,
                header: f.sortable ? /* @__PURE__ */ jsxs(
                  "button",
                  {
                    type: "button",
                    className: "flex items-center gap-1 font-medium uppercase tracking-wider text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white",
                    onClick: () => handleSort(f.attribute),
                    children: [
                      f.label,
                      /* @__PURE__ */ jsx(SortIcon2, { active: sort === f.attribute, dir: direction })
                    ]
                  }
                ) : /* @__PURE__ */ jsx("span", { className: "text-xs font-medium uppercase tracking-wider text-gray-500", children: f.label }),
                body: (row) => {
                  var _a2;
                  return (
                    // A field the related record hides (`_hidden`) leaves its cell empty;
                    // the id links to the record only when its policy lets the user view it.
                    isHiddenOn(row, f.attribute) ? null : f.attribute === "id" && ((_a2 = row._authorization) == null ? void 0 : _a2.authorizedToView) !== false ? /* @__PURE__ */ jsx(
                      Link,
                      {
                        to: viewUrl ? viewUrl(row.id) : recordHref(relatedResource, row.id),
                        className: "font-medium no-underline",
                        style: { color: "var(--martis-accent)" },
                        children: /* @__PURE__ */ jsx(FieldDisplay, { field: f, value: row[f.attribute], resourceKey: relatedResource })
                      }
                    ) : /* @__PURE__ */ jsx(FieldDisplay, { field: f, value: row[f.attribute], resourceKey: relatedResource })
                  );
                },
                sortable: false
              },
              f.attribute
            )),
            pivotFields == null ? void 0 : pivotFields.map((pf) => /* @__PURE__ */ jsx(
              Column,
              {
                field: `_pivot.${pf.attribute}`,
                header: /* @__PURE__ */ jsx("span", { className: "text-xs font-medium uppercase tracking-wider text-gray-500", children: pf.label }),
                body: (row) => {
                  const pivot = row._pivot;
                  if (isHiddenOn(pivot, pf.attribute)) return null;
                  const val = (pivot == null ? void 0 : pivot[pf.attribute]) ?? null;
                  return /* @__PURE__ */ jsx(FieldDisplay, { field: pf, value: val, resourceKey: relatedResource });
                },
                sortable: false
              },
              `pivot_${pf.attribute}`
            )),
            hasActions && /* @__PURE__ */ jsx(
              Column,
              {
                header: /* @__PURE__ */ jsx("span", { className: "text-xs font-medium uppercase tracking-wider text-gray-500", children: tAct("actions", "Actions") }),
                body: (row) => {
                  const isTrashed = row.deleted_at != null;
                  const auth = row._authorization;
                  return /* @__PURE__ */ jsxs("div", { className: "flex items-center justify-end gap-1", children: [
                    showView && (auth == null ? void 0 : auth.authorizedToView) !== false && /* @__PURE__ */ jsx(
                      Link,
                      {
                        to: viewUrl ? viewUrl(row.id) : recordHref(relatedResource, row.id),
                        className: "rounded p-1.5 transition-colors no-underline",
                        style: { color: "var(--martis-text-muted)" },
                        "data-pr-tooltip": tAct("view", "View"),
                        "data-pr-position": "top",
                        onMouseEnter: (e) => e.currentTarget.style.color = "var(--martis-accent)",
                        onMouseLeave: (e) => e.currentTarget.style.color = "var(--martis-text-muted)",
                        children: /* @__PURE__ */ jsx(EyeIcon, { size: 16 })
                      }
                    ),
                    !isTrashed && showEdit && (auth == null ? void 0 : auth.authorizedToUpdate) !== false && /* @__PURE__ */ jsx(
                      Link,
                      {
                        to: editUrl(row.id),
                        className: "rounded p-1.5 transition-colors no-underline",
                        style: { color: "var(--martis-text-muted)" },
                        "data-pr-tooltip": tAct("edit", "Edit"),
                        "data-pr-position": "top",
                        onMouseEnter: (e) => e.currentTarget.style.color = "var(--martis-accent)",
                        onMouseLeave: (e) => e.currentTarget.style.color = "var(--martis-text-muted)",
                        children: /* @__PURE__ */ jsx(PencilSimpleIcon, { size: 16 })
                      }
                    ),
                    !isTrashed && showDelete && (auth == null ? void 0 : auth.authorizedToDelete) !== false && /* @__PURE__ */ jsx(
                      "button",
                      {
                        type: "button",
                        onClick: () => setDeleteTarget({ id: row.id }),
                        className: "rounded p-1.5 transition-colors",
                        style: { color: "var(--martis-text-muted)", background: "none", border: "none", cursor: "pointer" },
                        "data-pr-tooltip": tAct("delete", "Delete"),
                        "data-pr-position": "top",
                        onMouseEnter: (e) => e.currentTarget.style.color = "var(--martis-danger)",
                        onMouseLeave: (e) => e.currentTarget.style.color = "var(--martis-text-muted)",
                        children: /* @__PURE__ */ jsx(TrashIcon$1, { size: 16 })
                      }
                    ),
                    isTrashed && showRestore && (auth == null ? void 0 : auth.authorizedToRestore) !== false && /* @__PURE__ */ jsx(
                      "button",
                      {
                        type: "button",
                        onClick: () => setRestoreTarget({ id: row.id }),
                        className: "rounded p-1.5 transition-colors",
                        style: { color: "var(--martis-text-muted)", background: "none", border: "none", cursor: "pointer" },
                        "data-pr-tooltip": tAct("restore", "Restore"),
                        "data-pr-position": "top",
                        onMouseEnter: (e) => e.currentTarget.style.color = "var(--martis-success)",
                        onMouseLeave: (e) => e.currentTarget.style.color = "var(--martis-text-muted)",
                        children: /* @__PURE__ */ jsx(ArrowCounterClockwiseIcon, { size: 16 })
                      }
                    ),
                    isTrashed && showForceDelete && (auth == null ? void 0 : auth.authorizedToForceDelete) !== false && /* @__PURE__ */ jsx(
                      "button",
                      {
                        type: "button",
                        onClick: () => setForceDeleteTarget({ id: row.id }),
                        className: "rounded p-1.5 transition-colors",
                        style: { color: "var(--martis-text-muted)", background: "none", border: "none", cursor: "pointer" },
                        "data-pr-tooltip": tAct("force_delete", "Force delete"),
                        "data-pr-position": "top",
                        onMouseEnter: (e) => e.currentTarget.style.color = "var(--martis-danger)",
                        onMouseLeave: (e) => e.currentTarget.style.color = "var(--martis-text-muted)",
                        children: /* @__PURE__ */ jsx(SkullIcon, { size: 16 })
                      }
                    ),
                    rowActionsExtras == null ? void 0 : rowActionsExtras(row),
                    inlineActions.length > 0 && /* @__PURE__ */ jsx(
                      InlineRowActions,
                      {
                        actions: inlineActions,
                        row,
                        onAction: (action, target) => setActiveAction({ action, id: target.id })
                      }
                    )
                  ] });
                },
                style: { width: "6rem", textAlign: "right" }
              }
            )
          ]
        }
      ) }),
      pagination && /* @__PURE__ */ jsx(
        Pagination,
        {
          currentPage: pagination.current_page,
          lastPage: pagination.last_page,
          total: pagination.total,
          perPage: pagination.per_page ?? perPage,
          from: pagination.from ?? null,
          to: pagination.to ?? null,
          onPageChange: setPage
        }
      )
    ] }),
    /* @__PURE__ */ jsx(
      DeleteModal,
      {
        open: deleteTarget !== null,
        resourceLabel: (schema == null ? void 0 : schema.singularLabel) ?? "",
        isSoftDelete: (schema == null ? void 0 : schema.softDeletes) ?? false,
        onConfirm: async () => {
          if (deleteTarget) {
            await deleteMutation.mutateAsync(deleteTarget.id);
          }
        },
        onCancel: () => setDeleteTarget(null)
      }
    ),
    /* @__PURE__ */ jsx(
      DeleteModal,
      {
        open: forceDeleteTarget !== null,
        resourceLabel: (schema == null ? void 0 : schema.singularLabel) ?? "",
        isSoftDelete: false,
        onConfirm: async () => {
          if (forceDeleteTarget) {
            await forceDeleteMutation.mutateAsync(forceDeleteTarget.id);
          }
        },
        onCancel: () => setForceDeleteTarget(null)
      }
    ),
    /* @__PURE__ */ jsx(
      DeleteModal,
      {
        open: restoreTarget !== null,
        resourceLabel: (schema == null ? void 0 : schema.singularLabel) ?? "",
        isSoftDelete: false,
        variant: "restore",
        onConfirm: async () => {
          if (restoreTarget) {
            await restoreMutation.mutateAsync(restoreTarget.id);
          }
        },
        onCancel: () => setRestoreTarget(null)
      }
    ),
    activeAction !== null && /* @__PURE__ */ jsx(
      ActionModal,
      {
        resource: relatedResource,
        action: activeAction.action,
        selectedIds: activeAction.action.standalone ? [] : [activeAction.id],
        via: rowActions,
        visible: true,
        onHide: () => setActiveAction(null),
        onSuccess: () => {
          void qc.invalidateQueries({ queryKey });
          setActiveAction(null);
        },
        onOpenCreate: (res) => setActionDrawer({ type: "create", resource: res }),
        onOpenDetail: (res, rid) => setActionDrawer({ type: "detail", resource: res, recordId: rid }),
        onOpenUpdate: (res, rid) => setActionDrawer({ type: "update", resource: res, recordId: rid })
      }
    ),
    actionDrawer && /* @__PURE__ */ jsx(
      ActionDrawer,
      {
        type: actionDrawer.type,
        resource: actionDrawer.resource,
        recordId: actionDrawer.recordId,
        onClose: () => setActionDrawer(null),
        onSuccess: () => {
          void qc.invalidateQueries({ queryKey });
          setActionDrawer(null);
        },
        onSwitchTo: (next) => setActionDrawer(next)
      }
    ),
    /* @__PURE__ */ jsx("style", { children: `
        .relation-shell-search::placeholder {
          color: var(--martis-text-muted);
          opacity: 0.7;
        }
      ` })
  ] });
}
export {
  ActionDryRunPreview as A,
  Column as C,
  DataTable as D,
  Pagination as P,
  RelationshipTableShell as R,
  handleActionResponse as h
};
