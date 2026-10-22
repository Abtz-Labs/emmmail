<?php
/**
 * tests/js.php — execute the shared front-end toolkit in a real JS engine.
 * Run with: php tests/js.php   (or all suites: php tests/run.php)
 *
 * index.php's js_inline() is a single embedded <script> for every page. This
 * suite pulls that JS out, boots it inside Node against a minimal DOM/fetch
 * stub, drives the real event handlers, and asserts their observable effects
 * (sync flow, autocomplete, login toggle, markRead, srcdoc autosize, and the
 * core $ toolkit methods).
 *
 * Node is only a test-runner: the app keeps its zero-dependency guarantee.
 * If `node` is missing the suite skips cleanly, like smoke.php does without
 * mail credentials.
 */

require_once __DIR__ . '/_harness.php';
test_suite('js');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    skip('js: node is not installed');
    summary();
}

// Materialise the embedded script and the harness, run node, delete both.
$uniq = bin2hex(random_bytes(8));
$appFile = sys_get_temp_dir() . '/emmmail-js-' . $uniq . '.app.js';
$runFile = sys_get_temp_dir() . '/emmmail-js-' . $uniq . '.run.js';
$mdFile  = sys_get_temp_dir() . '/emmmail-js-' . $uniq . '.md.json';
file_put_contents($appFile, js_inline());

// Markdown fixtures for the editor serializer. PHP renders each one exactly as
// the composer would receive it, and DOMDocument turns that into a plain JSON
// tree. The DOM stub has no HTML parser, and this way the round trip goes
// through the real md_to_html(), not a JS copy of it.
function md_dom_tree(DOMNode $n): array
{
    if ($n instanceof DOMText) {
        return ['t' => $n->nodeValue];
    }
    $attrs = [];
    foreach ($n->attributes ?? [] as $a) {
        $attrs[$a->nodeName] = $a->nodeValue;
    }
    $kids = [];
    foreach ($n->childNodes as $k) {
        if ($k instanceof DOMText || $k instanceof DOMElement) {
            $kids[] = md_dom_tree($k);
        }
    }
    return ['n' => strtoupper($n->nodeName), 'a' => (object)$attrs, 'c' => $kids];
}
function md_html_tree(string $html): array
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="md-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return md_dom_tree($doc->getElementById('md-root'));
}
// Canonical Markdown: what the serializer writes back must be byte for byte the
// source, or an untouched draft would change every time it is opened.
$mdCanonical = [
    'hello', "a\nb", "a\n\nb", "\n\nleading blank lines", '# Title', '###### T', "- a\n- b", "1. a\n2. b",
    "3. a\n4. b", "Shopping:\n- milk", "- a\n  more", "> a\n> b", "> a\n>\n> b", '> > a',
    "> new\n> > old", "```\n*a* <b>\nx\n```", '---', '**b**', '*i*', '~~x~~', '`c`', '`` a`b ``',
    'snake_case_name', '2 * 3 * 4', '**a *b* c**', '\*a\*', 'C:\path', '<b>x</b> & a < b', 'a  b',
    '[t](https://x.io/a)', '[mail](mailto:a@x.io)', '[**t**](https://x.io)',
    '[w](https://en.wikipedia.org/wiki/Foo_(bar))', '![a b](https://x.io/i.png)',
    'see https://x.io/a_b_c.', '(see https://x.io)', '**https://x.io**', '# *a*', '- `x`', '-- ',
    "# T\n\ntext\n- a\n- b\n\n> q\n\n---\n```\ncode\n```\nend",
];
// A real reply: escaped attribution, escaped hostile body, nested quotes.
$mdCanonical[] = reply_form_data([
    'from' => 'Vin <vin@pm.me>', 'date' => 'Wed, 09 Sep 2026', 'subject' => 'S', 'body_html' => '',
    'body_text' => "Hi,\n* note\n1. first\n# not a title\n![](https://tracker.io/px)\n[bank](https://phish.io)\n2 * 3 snake_case\n\n> earlier\n> > oldest",
    'from_address' => 'vin@pm.me', 'reply_to_address' => '', 'to' => 'me@x.io', 'cc' => '',
    'message_id' => '', 'references' => '',
], 'me@x.io', false)['body'];
// Literal text: the serializer must escape exactly as md_escape() does.
$mdLiterals = [
    'snake_case_name', '2 * 3 * 4', '*hi*', '**bold**', '_em_', '~~s~~', '`code`', '```',
    '# Title', '> quoted', '>no space', '- item', '* item', '• item', '1986. was a year',
    '1. one', '---', '***', '___', '- - -', '—-', 'C:\path', 'a\*b', '\\', '[t](x)', '![a](x.png)',
    '[ ] task', '<b>x</b> & y', '  - indented', ':-*', '#hashtag', 'a_b_ c', '_a_b', '*a*b*',
    '~~~', '\\`', 'go https://x.io/_a_',
];
$mdFixtures = ['canonical' => [], 'literal' => []];
foreach ($mdCanonical as $md) {
    $mdFixtures['canonical'][] = ['md' => $md, 'tree' => md_html_tree(md_to_html($md))];
}
foreach ($mdLiterals as $x) {
    $mdFixtures['literal'][] = ['src' => $x, 'expect' => md_escape($x),
        'tree' => md_html_tree('<div>' . h($x) . '</div>')];
}
file_put_contents($mdFile, json_encode($mdFixtures, JSON_UNESCAPED_UNICODE));
file_put_contents($runFile, <<<'JS'
'use strict';
// Node-based DOM/behavior harness for the front-end toolkit in index.php.
// Boots the app JS (from APP_JS_PATH) against a minimal document/window stub,
// drives events through the real handlers, and prints one JSON report line.
const fs = require('fs');
const appJs = fs.readFileSync(process.env.APP_JS_PATH, 'utf8');

const R = [];
function safe(v) { try { return JSON.stringify(v); } catch (e) { return String(v); } }
function eq(name, actual, expected) {
  const pass = actual === expected;
  R.push({ name, pass, detail: pass ? '' : 'expected ' + safe(expected) + ' got ' + safe(actual) });
}
function ok(name, cond) { R.push({ name, pass: !!cond }); }
function settle(ms) { return new Promise(r => (ms ? setTimeout(r, ms) : setImmediate(r))); }

const escHtml = s => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

function matches(el, sel) {
  const parts = sel.split(/(?=[#.\[])/);
  let tag = null, id = null;
  const classes = [], attrs = [];
  parts.forEach(part => {
    if (!part) return;
    if (part[0] === '#') id = part.slice(1);
    else if (part[0] === '.') classes.push(part.slice(1));
    else if (part[0] === '[') {
      const m = part.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
      if (m) attrs.push([m[1], m[2]]);
    } else tag = part.toLowerCase();
  });
  if (tag && el.tagName.toLowerCase() !== tag) return false;
  if (id !== null && el.id !== id) return false;
  for (const c of classes) if (!el.classList.contains(c)) return false;
  for (const [n, v] of attrs) {
    const av = el.getAttribute(n);
    if (av === null) return false;
    if (v !== undefined && av !== v) return false;
  }
  return true;
}

function register(node) {
  const all = node._doc ? node._doc._all : null;
  if (!all) return;
  if (all.indexOf(node) === -1) all.push(node);
  for (const c of node.children) register(c);
}

function makeEl(tag, doc) {
  const el = {
    nodeType: 1,
    tagName: tag.toUpperCase(),
    _doc: doc,
    className: '',
    id: '',
    attributes: {},
    style: {},
    value: '',
    children: [],
    parentNode: null,
    _handlers: {},
    _text: '',
    _innerHTML: '',
    scrollHeight: 0,
    contentWindow: undefined,
    checked: false,
    type: '',
    getAttribute(n) { return n in this.attributes ? this.attributes[n] : null; },
    hasAttribute(n) { return n in this.attributes; },
    setAttribute(n, v) {
      this.attributes[n] = String(v);
      if (n === 'id') this.id = String(v);
      if (n === 'class') this.className = String(v);
      if (n === 'type') this.type = String(v);
      if (n === 'checked' && v !== null && v !== 'false') this.checked = true;
      else if (n === 'checked') this.checked = false;
    },
    removeAttribute(n) { delete this.attributes[n]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    dispatch(type, target, init) {
      const ev = Object.assign({ type, target: target || this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }, init || {});
      let node = this;
      while (node) {
        for (const fn of node._handlers[type] || []) fn.call(node, ev);
        node = node.parentNode;
      }
      return ev;
    },
    // A real browser fires a synthetic button:0 click for link.click(), which is
    // what the keyboard layer uses to open the cursor's row.
    click() {
      const isCheckbox = this.tagName === 'INPUT' && this.type === 'checkbox';
      if (isCheckbox) {
        this.checked = !this.checked;
      }
      this.dispatch('click', this, { button: 0 });
      if (isCheckbox) {
        this.dispatch('change', this, {});
      }
    },
    focus() { this._focused = true; if (this._doc) this._doc.activeElement = this; },
    blur() { this._focused = false; if (this._doc && this._doc.activeElement === this) this._doc.activeElement = null; },
    appendChild(c) { c.parentNode = this; this.children.push(c); register(c); return c; },
    removeChild(c) {
      const i = this.children.indexOf(c);
      if (i >= 0) this.children.splice(i, 1);
      c.parentNode = null;
      return c;
    },
    insertAdjacentHTML(pos, html) { this._innerHTML += html; },
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; },
    querySelectorAll(sel) {
      const out = [];
      (function walk(n) {
        for (const c of n.children) { if (matches(c, sel)) out.push(c); walk(c); }
      })(this);
      return out;
    },
    matches(sel) { return matches(this, sel); },
    closest(sel) {
      for (let n = this; n && n.tagName; n = n.parentNode) if (matches(n, sel)) return n;
      return null;
    },
  };
  Object.defineProperty(el, 'textContent', {
    get() { return el._text; },
    set(v) { el._text = String(v); el._innerHTML = escHtml(el._text); },
  });
  Object.defineProperty(el, 'innerHTML', {
    get() { return el._innerHTML; },
    set(v) { el._innerHTML = String(v); if (v === '') el.children = []; },
  });
  const cls = () => el.className.split(/\s+/).filter(Boolean);
  el.classList = {
    add(c) { if (cls().indexOf(c) === -1) el.className = cls().concat(c).join(' '); },
    remove(c) { el.className = cls().filter(x => x !== c).join(' '); },
    contains(c) { return cls().indexOf(c) !== -1; },
    toggle(c, on) {
      const has = cls().indexOf(c) !== -1;
      const want = on === undefined ? !has : !!on;
      if (want !== has) { if (want) el.className = cls().concat(c).join(' '); else el.className = cls().filter(x => x !== c).join(' '); }
      return want;
    },
  };
  // Model <select>/<option> value semantics: the app sets opt.selected = true
  // (buildFolderRows) and then reads select.value (collectFolderMap). A select
  // without this wiring would report '' forever and the map would be empty.
  if (tag === 'option') {
    let _selected = false;
    Object.defineProperty(el, 'selected', {
      get() { return _selected; },
      set(v) {
        _selected = !!v;
        // The select's setter writes this back, so the write stops when the
        // select already reads this value -- otherwise the two setters recurse.
        if (v && el.parentNode && el.parentNode.tagName === 'SELECT'
            && el.parentNode.value !== el.value) {
          el.parentNode.value = el.value;
        }
      },
    });
  }
  if (tag === 'select') {
    let _value = el.value;
    Object.defineProperty(el, 'value', {
      get() {
        for (const c of el.children) {
          if (c.selected) return c.value;
        }
        return _value;
      },
      set(v) {
        _value = String(v);
        for (const c of el.children) {
          if (c.tagName === 'OPTION') c.selected = (c.value === _value);
        }
      },
    });
  }
  return el;
}

function makeDoc() {
  const all = [];
  const doc = {
    _all: all,
    activeElement: null,
    body: null,
    // Page visibility, so the poll loop's "only notify when the tab is in the
    // background" rule is testable. Settable per test.
    hidden: false,
    title: '',
    createElement(tag) { return makeEl(tag, this); },
    add(el) { register(el); },
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; },
    querySelectorAll(sel) { return all.filter(el => matches(el, sel)); },
    addEventListener(type, fn) { (this._handlers = this._handlers || {})[type] = (this._handlers[type] || []).concat(fn); },
    dispatch(type, init) {
      const ev = Object.assign({ type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }, init || {});
      for (const fn of this._handlers[type] || []) fn.call(this, ev);
      return ev;
    },
  };
  // A body for the keyboard layer's data-kb-map, wired into _all so queries
  // find nodes under it.
  const body = makeEl('body', doc);
  doc.body = body;
  all.push(body);
  doc.hasFocus = () => doc._focused !== false;
  return doc;
}

// A Notification constructor that records what the app asked for. The record is
// the instance itself, so a test can check both what was asked for and whether
// the app went on to close it. `throwOnNew` reproduces the Android Chrome case:
// permission is granted and the constructor still throws "Illegal constructor".
function makeNotification(opts) {
  opts = opts || {};
  const made = [];
  const N = function (title, init) {
    if (opts.throwOnNew) throw new TypeError("Failed to construct 'Notification'");
    const n = {
      _title: title,
      _init: init || {},
      _closed: false,
      close() { this._closed = true; },
      onclick: null,
    };
    made.push(n);
    return n;
  };
  N.permission = opts.permission || 'default';
  N.requested = 0;
  N.requestPermission = () => {
    N.requested++;
    const to = opts.grant || 'granted';
    N.permission = to;
    return Promise.resolve(to);
  };
  N.made = made;
  return N;
}

// A localStorage stand-in. `removeItem` matters: the notification flag is an
// opt-in, so switching it off is a removal, not an 'off' value.
function storageWith(obj) {
  const store = Object.assign({}, obj);
  return {
    getItem(k) { return k in store ? store[k] : null; },
    setItem(k, v) { store[k] = String(v); },
    removeItem(k) { delete store[k]; },
    _store: store,
  };
}

// A controllable clock for the poll scheduler. Swaps win.setTimeout /
// win.clearTimeout for a queue and moves Date.now with it, so a test can run two
// hours of polling in microseconds. Real timers keep working: settle() uses the
// module's own setImmediate, and every other test leaves this uninstalled.
function fakeTimers(win, start) {
  const realSetTimeout = win.setTimeout;
  const realClearTimeout = win.clearTimeout;
  const realNow = Date.now;
  let now = start || 1700000000000;
  let seq = 0;
  const queue = new Map();
  Date.now = () => now;
  win.setTimeout = (fn, ms) => { const id = ++seq; queue.set(id, { at: now + (ms || 0), fn }); return id; };
  win.clearTimeout = id => { queue.delete(id); };
  return {
    get now() { return now; },
    pending: () => queue.size,
    // Advance to `now + ms`, firing everything that comes due on the way. A
    // callback that reschedules inside the window fires in the same tick, so a
    // poll loop can be run to a fixed point.
    async tick(ms) {
      const until = now + ms;
      for (;;) {
        let next = null;
        queue.forEach((t, id) => { if (t.at <= until && (!next || t.at < next.t.at)) next = { id, t }; });
        if (!next) break;
        queue.delete(next.id);
        now = next.t.at;
        next.t.fn();
        await settle();
      }
      now = until;
      await settle();
    },
    restore() { win.setTimeout = realSetTimeout; win.clearTimeout = realClearTimeout; Date.now = realNow; },
  };
}

function makeWin() {
  const win = {
    _reloads: 0,
    // A poll that finds new mail reloads the current URL, which is a plain
    // href assignment. A plain object would hide both reads and writes, so
    // href is a real property and the writes are counted.
    _hrefs: 0,
    _focused: false,
    addEventListener(type, fn) { (this._handlers = this._handlers || {})[type] = (this._handlers[type] || []).concat(fn); },
    dispatch(type, init) {
      const ev = Object.assign({ type, target: this }, init || {});
      for (const fn of this._handlers[type] || []) fn.call(this, ev);
      return ev;
    },
    setTimeout, clearTimeout,
    getComputedStyle() { return {}; },
    // The notification click handler focuses the tab.
    focus() { this._focused = true; },
    scrollY: 0,
    isSecureContext: true,
  };
  let href;
  const loc = { reload() { win._reloads++; } };
  Object.defineProperty(loc, 'href', {
    get() { return href; },
    set(v) { href = v; win._hrefs++; },
    enumerable: true,
  });
  loc.search = '';
  win.location = loc;
  return win;
}

function boot(doc, win, route, calls) {
  const fn = new Function('window', 'document', 'fetch', 'URLSearchParams', appJs + '\n;return true;');
  win.fetch = (url, opts) => {
    if (calls) calls.push({ url, body: opts ? opts.body : '' });
    // A real fetch reports a network failure as a rejected promise, never as a
    // synchronous throw, and the app's .catch() branches depend on that.
    try { return Promise.resolve(route(url)); } catch (e) { return Promise.reject(e); }
  };
  fn(win, doc, win.fetch, URLSearchParams);
}

const TOKEN = 'csrfTOK';
function buildMeta(doc) {
  const m = doc.createElement('meta');
  m.setAttribute('name', 'csrf');
  m.setAttribute('content', TOKEN);
  doc.add(m);
}
function buildLogin(doc) {
  const box = doc.createElement('div');
  box.className = 'login-box';
  const sub = doc.createElement('p'); sub.className = 'sub'; sub.textContent = 'Sign in to your mailbox';
  const sel = doc.createElement('select'); sel.id = 'login-preset'; sel.value = 'migadu';
  const custom = doc.createElement('div'); custom.className = 'custom-servers'; custom.style.display = 'none';
  box.appendChild(sub);
  box.appendChild(sel);
  box.appendChild(custom);
  doc.add(box);
}
function buildSync(doc, folder) {
  const box = doc.createElement('div');
  box.className = 'sync';
  box.setAttribute('data-folder', folder);
  const btn = doc.createElement('button'); btn.className = 'sync-btn';
  const label = doc.createElement('span'); label.className = 'sync-text';
  box.appendChild(btn);
  box.appendChild(label);
  doc.add(box);
  return box;
}
// The topbar as render_page() emits it: on every signed-in page, and the only
// element carrying the poll period.
function buildTopbar(doc, period) {
  const bar = doc.createElement('header');
  bar.className = 'topbar';
  if (period !== undefined) bar.setAttribute('data-period', String(period));
  doc.add(bar);
  return bar;
}
// A signed-in folder listing: the topbar with its poll period, the sync chip,
// the sidebar Inbox badge, the mailbox section, and a toast container.
function buildPollPage(doc, folder, period) {
  buildMeta(doc);
  const bar = buildTopbar(doc, period);
  const chip = buildSync(doc, folder);
  const box = doc.createElement('section'); box.className = 'mailbox'; doc.add(box);
  const toasts = doc.createElement('div'); toasts.className = 'toast-container'; doc.add(toasts);
  const side = doc.createElement('div'); side.className = 'sidebar';
  const li = doc.createElement('li');
  const link = doc.createElement('a'); link.className = 'folder-link';
  link.setAttribute('href', '?page=folder&folder=' + folder);
  link.setAttribute('data-folder', folder);
  const cnt = doc.createElement('span'); cnt.className = 'folder-count';
  cnt.setAttribute('data-kind', 'unseen');
  cnt.setAttribute('hidden', ''); cnt.textContent = '0';
  link.appendChild(cnt); li.appendChild(link); side.appendChild(li); doc.add(side);
  return { bar, chip, box, cnt, side, link };
}

// A ?page=sync response. `status`/`redirected` drive the "signed out" stop,
// which is why the 401 body is JSON the client can still read.
function syncReply(o) {
  o = o || {};
  const status = o.status || 200;
  return {
    ok: status < 400,
    status,
    redirected: !!o.redirected,
    json: () => Promise.resolve(o.body || { ok: true, synced: 'updated', changed: 0, arrivals: 0 }),
  };
}

// A mailbox list as view_inbox() renders it: a head with the total, and rows
// carrying data-uid, so the optimistic helpers can find the one they changed.
// Row 1 is unread, the rest are read.
// `into` places the head, bar and list in an existing section instead of a new
// one. The bindings are delegated off the FIRST .mailbox in the document, so a
// second section is a list whose controls nothing is listening to.
// `uids` overrides the 1..n UIDs, in the DISPLAY order given. A newest-first
// listing is not in UID order, which is the whole reason pickUids() sorts.
function buildList(doc, folder, n, into, uids) {
  const box = into || doc.createElement('section');
  if (!into) { box.className = 'mailbox'; doc.add(box); }
  const head = doc.createElement('div');
  head.className = 'mailbox-head';
  const h2 = doc.createElement('h2');
  const count = doc.createElement('small');
  count.className = 'mail-count';
  count.textContent = '(' + n + ')';
  h2.appendChild(count);
  const selAll = doc.createElement('input');
  selAll.setAttribute('type', 'checkbox');
  selAll.className = 'mail-pick-all';
  selAll.setAttribute('data-pick-all', '');
  head.appendChild(selAll);
  // The label text is the count: "Select all" with nothing picked, "N selected" after.
  const bulkCount = doc.createElement('span');
  bulkCount.className = 'pick-all-text';
  bulkCount.setAttribute('data-bulk-count', '');
  head.appendChild(bulkCount);
  head.appendChild(h2);
  box.appendChild(head);
  const bulk = doc.createElement('div');
  bulk.className = 'bulk-bar hidden';
  bulk.setAttribute('role', 'status');
  bulk.setAttribute('data-bulk-folder', folder);
  bulk.setAttribute('data-bulk-delete-copy', 'Delete %d messages? They will move to Trash.');
  for (const act of ['read', 'unread', 'move', 'delete']) {
    const b = doc.createElement('button');
    b.setAttribute('type', 'button');
    b.className = 'btn btn-sm';
    b.setAttribute('data-bulk-action', act);
    bulk.appendChild(b);
  }
  box.appendChild(bulk);
  // Rendered by render_page(), a fixed container for floating toasts.
  const toasts = doc.createElement('div');
  toasts.className = 'toast-container';
  doc.add(toasts);
  const list = doc.createElement('div');
  list.className = 'mail-list';
  const rows = [];
  for (let i = 1; i <= n; i++) {
    const uid = uids ? uids[i - 1] : i;
    const row = doc.createElement('div');
    row.className = 'mail' + (i === 1 ? ' unread' : '');
    row.setAttribute('data-uid', String(uid));
    row.setAttribute('data-folder', folder);
    const avatar = doc.createElement('span');
    avatar.className = 'mail-avatar av-' + ((i - 1) % 10);
    const pick = doc.createElement('input');
    pick.setAttribute('type', 'checkbox');
    pick.className = 'mail-pick';
    pick.setAttribute('data-pick-uid', String(uid));
    avatar.appendChild(pick);
    row.appendChild(avatar);
    const actions = doc.createElement('span');
    actions.className = 'mail-actions';
    const btn = doc.createElement('button');
    btn.className = 'icon-btn tip';
    btn.setAttribute('data-read-toggle', '');
    const svg = doc.createElement('svg');
    const use = doc.createElement('use');
    use.setAttribute('href', i === 1 ? '#tabler-mail' : '#tabler-mail-opened');
    svg.appendChild(use);
    btn.appendChild(svg);
    actions.appendChild(btn);
    row.appendChild(actions);
    // The link that opens the message. Clicking it navigates, which is what the
    // click acknowledgment exists for.
    const link = doc.createElement('a');
    link.className = 'mail-main';
    link.setAttribute('href', '?page=read&uid=' + uid + '&folder=' + folder);
    row.appendChild(link);
    row._use = use;
    row._link = link;
    list.appendChild(row);
    rows.push(row);
  }
  box.appendChild(list);
  return { head, count, toasts, list, rows };
}

// The reader's action bar: links that navigate, an immediate POST (Spam), and two
// controls that open a dialog first (Move, Delete).
function buildReaderActions(doc) {
  const card = doc.createElement('div');
  card.className = 'card';
  const bar = doc.createElement('div');
  bar.className = 'actions-bar';
  const mk = (tag, cls, attrs) => {
    const el = doc.createElement(tag);
    el.className = cls;
    Object.keys(attrs || {}).forEach(k => el.setAttribute(k, attrs[k]));
    const svg = doc.createElement('svg');
    const use = doc.createElement('use');
    use.setAttribute('href', '#tabler-mail');
    svg.appendChild(use);
    el.appendChild(svg);
    el._use = use;
    bar.appendChild(el);
    return el;
  };
  const back = mk('a', 'btn btn-sm', { href: '?page=folder&folder=INBOX', 'data-tip': 'Back' });
  const reply = mk('a', 'btn btn-sm btn-primary', { href: '?page=reply&uid=7&folder=INBOX' });
  const unread = mk('button', 'btn btn-sm btn-icon', {});
  const spam = mk('button', 'btn btn-sm btn-icon btn-warn-soft', {});
  const move = mk('button', 'btn btn-sm btn-icon', { 'data-move': '' });
  const del = mk('button', 'btn btn-sm btn-icon btn-danger-soft', { 'data-delete': '' });
  card.appendChild(bar);
  // Rendered by render_page(), a fixed container for floating toasts.
  const toasts = doc.createElement('div');
  toasts.className = 'toast-container';
  doc.add(toasts);
  card.appendChild(toasts);
  doc.add(card);
  return { card, bar, back, reply, unread, spam, move, del, toasts };
}

// The search pill as view_inbox() renders it, plus the hint offering the slower
// server-side search.
function buildSearch(doc) {
  const form = doc.createElement('form');
  form.className = 'search';
  const input = doc.createElement('input');
  input.setAttribute('name', 'q');
  const btn = doc.createElement('button');
  btn.className = 'btn btn-sm search-btn tip';
  const svg = doc.createElement('svg');
  const use = doc.createElement('use');
  use.setAttribute('href', '#tabler-search');
  svg.appendChild(use);
  btn.appendChild(svg);
  btn._use = use;
  form.appendChild(input);
  form.appendChild(btn);
  doc.add(form);
  const hint = doc.createElement('div');
  hint.className = 'hint';
  const serverLink = doc.createElement('a');
  serverLink.setAttribute('href', '?page=search&server=1&folder=INBOX&q=x');
  hint.appendChild(serverLink);
  doc.add(hint);
  return { form, input, btn, hint, serverLink };
}

// The composer's action row: Discard is a link, attaching is a label over a file
// input, and Save draft and Send are both submit buttons.
function buildCompose(doc) {
  const form = doc.createElement('form');
  form.className = 'compose-form';
  const row = doc.createElement('div');
  row.className = 'compose-actions';
  const mk = (tag, cls, iconName) => {
    const el = doc.createElement(tag);
    el.className = cls;
    const svg = doc.createElement('svg');
    const use = doc.createElement('use');
    use.setAttribute('href', '#tabler-' + iconName);
    svg.appendChild(use);
    el.appendChild(svg);
    el._use = use;
    row.appendChild(el);
    return el;
  };
  const discard = mk('a', 'btn btn-sm compose-discard', 'arrow-back');
  discard.setAttribute('href', '?page=inbox');
  const attach = mk('label', 'btn btn-sm compose-attach-btn', 'paperclip');
  const file = doc.createElement('input');
  file.setAttribute('type', 'file'); file.setAttribute('name', 'attachments[]');
  attach.appendChild(file);
  const draft = mk('button', 'btn btn-sm', 'device-floppy');
  draft.setAttribute('type', 'submit'); draft.setAttribute('formaction', '?page=saveDraft');
  const send = mk('button', 'btn btn-primary', 'send');
  form.appendChild(row);
  doc.add(form);
  return { form, row, discard, attach, file, draft, send };
}

// The settings page: the main form (marked by .folder-map-json) with a Save
// button, plus the clear-cache action.
function buildSettings(doc) {
  const form = doc.createElement('form');
  form.setAttribute('action', '?page=settings');
  const marker = doc.createElement('input');
  marker.setAttribute('type', 'hidden'); marker.className = 'folder-map-json';
  form.appendChild(marker);
  const actions = doc.createElement('div'); actions.className = 'form-actions';
  const save = doc.createElement('button');
  save.className = 'btn btn-primary'; save.setAttribute('type', 'submit');
  actions.appendChild(save); form.appendChild(actions);
  doc.add(form);
  return { form, marker, save };
}

// The "This browser" card view_settings() ships outside the settings form, with
// a single button that acts at once (the way the ?-sheet's shortcut toggle does).
function buildNotifyCard(doc) {
  const card = doc.createElement('div');
  card.className = 'card notify-card';
  const btn = doc.createElement('button');
  btn.className = 'btn btn-pill'; btn.setAttribute('type', 'button');
  btn.setAttribute('data-notify-toggle', '');
  btn.textContent = 'Enable notifications';
  card.appendChild(btn);
  doc.add(card);
  return { card, btn };
}

function buildAutocomplete(doc) {  const wrap = doc.createElement('div');
  wrap.className = 'autocomplete-wrap';
  const input = doc.createElement('input');
  input.setAttribute('data-autocomplete', 'to');
  wrap.appendChild(input);
  doc.add(wrap);
}
function buildSrcdoc(doc) {
  const iframe = doc.createElement('iframe');
  iframe.className = 'body-iframe srcdoc';
  iframe.contentWindow = { document: { body: { scrollHeight: 123 }, documentElement: { scrollHeight: 123 } } };
  doc.add(iframe);
}
// A <dialog> with the children the modal code expects.
function buildDialog(doc, id, extras) {
  const dialog = doc.createElement('dialog');
  dialog.id = id;
  dialog.className = 'modal';
  dialog.showModal = function() { this.setAttribute('open', ''); };
  dialog.close = function() { this.removeAttribute('open'); };
  Object.defineProperty(dialog, 'open', {
    get() { return 'open' in this.attributes; },
    set(v) { if (v) this.attributes.open = ''; else delete this.attributes.open; }
  });
  const titleEl = doc.createElement('h3');
  titleEl.className = 'modal-title';
  dialog.appendChild(titleEl);
  // The real dialog has one (index.php renders <p class="modal-desc">), and
  // openConfirm() puts its warning there. Without it in the fixture the warning
  // went nowhere and S11 asserted against the title instead.
  const descEl = doc.createElement('p');
  descEl.className = 'modal-desc';
  dialog.appendChild(descEl);
  let input = null;
  if (extras && extras.input) {
    input = doc.createElement('input');
    input.className = 'folder-name';
    input.value = '';
    input.focus = function() { this._focused = true; };
    dialog.appendChild(input);
  }
  let select = null;
  if (extras && extras.select) {
    select = doc.createElement('select');
    select.className = 'move-dest';
    select.value = '';
    dialog.appendChild(select);
  }
  const cancelBtn = doc.createElement('button');
  cancelBtn.className = 'btn-cancel';
  dialog.appendChild(cancelBtn);
  const confirmBtn = doc.createElement('button');
  confirmBtn.className = 'btn-confirm';
  dialog.appendChild(confirmBtn);
  doc.add(dialog);
  return { dialog, titleEl, descEl, cancelBtn, confirmBtn, input, select };
}

async function main() {
  try {
  // S1 — page without any widgets: the script must boot cleanly and statics work.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const $ = (function () {
      const fn = new Function('window', 'document', 'fetch', 'URLSearchParams', appJs + '\n;return window.$;');
      win.fetch = () => Promise.resolve({ json: () => Promise.resolve({}) });
      return fn(win, doc, win.fetch, URLSearchParams);
    })();
    ok('S1 $ exposed on window', typeof $ === 'function');
    eq('S1 $.token reads csrf meta', $.token, TOKEN);
    eq('S1 $.escapeHtml escapes', $.escapeHtml('<b>&"'), '&lt;b&gt;&amp;&quot;');
    const p = $.param({ a: 1 });
    ok('S1 $.param carries csrf token', p.indexOf('csrf_token=' + TOKEN) === 0);
    ok('S1 $.param encodes data', p.indexOf('&a=1') !== -1);
  }

  // S2 — login preset toggle.
  {
    const doc = makeDoc(); buildMeta(doc); buildLogin(doc);
    const win = makeWin();
    boot(doc, win, () => ({ json: () => Promise.resolve({}) }));
    const sel = doc.querySelector('#login-preset');
    const custom = doc.querySelector('.custom-servers');
    eq('S2 fields hidden by default', custom.style.display, 'none');
    sel.value = 'custom'; sel.dispatch('change');
    eq('S2 custom preset reveals fields', custom.style.display, 'block');
    sel.value = 'migadu'; sel.dispatch('change');
    eq('S2 switching back hides fields', custom.style.display, 'none');
  }

  // S3 — chained DOM methods on a detached element.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    boot(doc, win, () => ({ json: () => Promise.resolve({}) }));
    const el = doc.createElement('div');
    const $el = win.$(el);
    el.className = 'foo bar';
    $el.addClass('baz').addClass(' qux ');
    eq('S3 addClass adds tokens', el.className.split(' ').sort().join(' '), 'bar baz foo qux');
    $el.removeClass('foo');
    ok('S3 removeClass drops token', !el.classList.contains('foo'));
    ok('S3 hasClass true', $el.hasClass('baz'));
    $el.toggleClass('baz');
    ok('S3 toggleClass turns off', !$el.hasClass('baz'));
    $el.text('Hello & World');
    eq('S3 text getter', $el.text(), 'Hello & World');
    eq('S3 text serializes escaped', el.innerHTML, 'Hello &amp; World');
    $el.html('<i>x</i>');
    eq('S3 html setter', $el.html(), '<i>x</i>');
    $el.css('display', 'block');
    eq('S3 css setter', el.style.display, 'block');
    $el.hide();
    eq('S3 hide sets display none', el.style.display, 'none');
    $el.show();
    eq('S3 show clears display', el.style.display, '');
    const ch = doc.createElement('div');
    el.appendChild(ch);
    eq('S3 append node parents child', ch.parentNode, el);
  }

  // S4 — sync: click drives POST, class + label updates, csrf in body.
  {
    const doc = makeDoc(); buildMeta(doc); buildSync(doc, 'INBOX');
    const calls = [];
    const win = makeWin();
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, synced: 'updated', changed: 0 }) }), calls);
    const box = doc.querySelector('.sync');
    const label = doc.querySelector('.sync-text');
    const btn = doc.querySelector('.sync-btn');
    btn.dispatch('click');
    ok('S4 click adds syncing class', box.classList.contains('syncing'));
    eq('S4 click labels syncing', label.textContent, 'Syncing...');
    await settle();
    eq('S4 POSTs sync endpoint', calls[0] && calls[0].url, '?page=sync');
    ok('S4 body has folder, force and csrf',
      calls[0].body.indexOf('folder=INBOX') !== -1 &&
      calls[0].body.indexOf('force=1') !== -1 &&
      calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
    ok('S4 sync clears busy class', !box.classList.contains('syncing'));
    eq('S4 sync updates label', label.textContent, 'updated');
  }

  // S5 — sync error path and recovery. `changed > 0` reloads the open listing
  // once the page is idle, because the list is painted from the local cache.
  {
    const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
    const calls = [];
    const win = makeWin();
    win.location.href = '?page=folder&folder=INBOX';
    const before = win._hrefs;
    let step = 0;
    boot(doc, win, () => {
      step++;
      if (step === 1) return { ok: true, json: () => Promise.resolve({ ok: false, error: 'boom' }) };
      return { ok: true, json: () => Promise.resolve({ ok: true, synced: 'updated', changed: 2, arrivals: 0 }) };
    }, calls);
    const box = doc.querySelector('.sync');
    const label = doc.querySelector('.sync-text');
    const btn = doc.querySelector('.sync-btn');
    btn.dispatch('click'); await settle();
    ok('S5 error adds error class', box.classList.contains('sync-error'));
    eq('S5 error label shown', label.textContent, 'boom');
    btn.dispatch('click'); await settle();
    ok('S5 next run clears error class', !box.classList.contains('sync-error'));
    eq('S5 new mail reloads the open listing', win._hrefs, before + 1);
    eq('S5 by staying on the URL it is on', win.location.href, '?page=folder&folder=INBOX');
  }

  // S6 — the read toggle posts and, deliberately, never reloads. It used to go
  // through win.post() which reloaded the page; that reload is what made the
  // stale flag visible before the background sync corrected it.
  {
    const doc = makeDoc(); buildMeta(doc);
    const calls = [];
    const win = makeWin();
    buildList(doc, 'INBOX', 1);
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true }) }), calls);
    win.toggleRead(1, 'INBOX');
    await settle();
    eq('S6 toggleRead posts endpoint', calls[0] && calls[0].url, '?page=markRead');
    ok('S6 toggleRead sends uid, folder and csrf',
      calls[0].body.indexOf('uid=1') !== -1 &&
      calls[0].body.indexOf('folder=INBOX') !== -1 &&
      calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
    eq('S6 toggleRead never reloads', win._reloads, 0);
    ok('S6 win.post is gone with the reload it existed for', typeof win.post === 'undefined');
  }

  // S7 — the first background sync belongs to the poll chain, not to a per-swap
  // idle callback. A listing page with a period schedules one, and the browser's
  // idle callback is not consulted.
  {
    const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
    const calls = [];
    const win = makeWin();
    let idle = 0;
    win.requestIdleCallback = () => { idle++; };
    const clock = fakeTimers(win);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, synced: 'updated', changed: 0, arrivals: 0 }) }), calls);
    eq('S7 no idle callback is used', idle, 0);
    await clock.tick(60000);
    eq('S7 the chain runs the first sync', calls.length, 1);
    eq('S7 as a sync POST', calls[0].url, '?page=sync');
    clock.restore();
  }

  // S8 — contact autocomplete renders and fills the input.
  {
    const doc = makeDoc(); buildMeta(doc); buildAutocomplete(doc);
    const calls = [];
    const win = makeWin();
    boot(doc, win, () => ({ json: () => Promise.resolve({ results: [{ name: 'Al', email: 'a@x.io' }, { name: 'Bo', email: 'b@x.io' }] }) }), calls);
    const input = doc.querySelector('[data-autocomplete]');
    input.value = 'al';
    input.dispatch('input');
    await settle(280);
    ok('S8 fetches contact suggestions', calls[0] && calls[0].url.indexOf('?page=contacts&q=al') === 0);
    const dd = input.parentNode.children[input.parentNode.children.length - 1];
    eq('S8 dropdown rendered two items', dd.children.length, 2);
    dd.children[1].dispatch('mousedown');
    eq('S8 selecting an item fills the input', input.value, 'b@x.io');
    eq('S8 dropdown hides after pick', dd.style.display, 'none');
  }

  // S9 — sandboxed email iframe is resized to its content on load.
  {
    const doc = makeDoc(); buildMeta(doc); buildSrcdoc(doc);
    const win = makeWin();
    boot(doc, win, () => ({ json: () => Promise.resolve({}) }));
    const iframe = doc.querySelector('iframe.srcdoc');
    iframe.dispatch('load');
    eq('S9 srcdoc iframe autosized on load', iframe.style.height, '153px');
    // The srcdoc load event can fire before this script ever runs, so window load
    // has to size it too or the frame is stuck at the browser default 150px.
    const doc2 = makeDoc(); buildMeta(doc2); buildSrcdoc(doc2);
    const win2 = makeWin();
    boot(doc2, win2, () => ({ json: () => Promise.resolve({}) }));
    win2.dispatch('load');
    eq('S9 window load also sizes the iframe', doc2.querySelector('iframe.srcdoc').style.height, '153px');
  }

  // S9b — a browser that refuses the measurement must not take the page with it.
  {
    const doc = makeDoc(); buildMeta(doc);
    const iframe = doc.createElement('iframe');
    iframe.className = 'body-iframe srcdoc';
    // What a cross-origin (opaque) iframe actually does when the parent looks in.
    Object.defineProperty(iframe, 'contentWindow', {
      get() { throw new Error('SecurityError: blocked a frame from accessing a cross-origin frame'); }
    });
    doc.add(iframe);
    const win = makeWin();
    boot(doc, win, () => ({ json: () => Promise.resolve({}) }));
    let threw = false;
    try { iframe.dispatch('load'); } catch (e) { threw = true; }
    ok('S9b a blocked measurement does not throw', !threw);
    // And the rest of the page still works afterwards.
    ok('S9b toolkit still usable', typeof win.$ === 'function');
  }

  // S9c — content that grows after load (images, fonts) is re-measured.
  {
    const doc = makeDoc(); buildMeta(doc); buildSrcdoc(doc);
    const observed = [];
    const win = makeWin();
    win.ResizeObserver = function(cb) {
      this.observe = function(target) { observed.push({ target, cb }); };
      this.disconnect = function() {};
    };
    boot(doc, win, () => ({ json: () => Promise.resolve({}) }));
    const iframe = doc.querySelector('iframe.srcdoc');
    iframe.dispatch('load');
    eq('S9c observes the inner body', observed.length, 1);
    if (observed.length) {
      // The body grew: firing the observer must resize the frame again.
      iframe.contentWindow.document.body.scrollHeight = 900;
      observed[0].cb();
      eq('S9c refits when the content grows', iframe.style.height, '930px');
    }
  }

  // S9d — HTML mail often puts the margins on <html>, so body.scrollHeight alone
  // under-reports and the tail of the message is cut off.
  {
    const doc = makeDoc(); buildMeta(doc); buildSrcdoc(doc);
    const win = makeWin();
    boot(doc, win, () => ({ json: () => Promise.resolve({}) }));
    const iframe = doc.querySelector('iframe.srcdoc');
    iframe.contentWindow.document.body.scrollHeight = 200;
    iframe.contentWindow.document.documentElement.scrollHeight = 640;
    iframe.dispatch('load');
    eq('S9d uses the taller of body and documentElement', iframe.style.height, '670px');
  }

  // S10 — delete confirmation dialog: confirmDelete opens the modal.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();

    // Build the dialog with the children confirmDelete expects.
    const dialog = doc.createElement('dialog');
    dialog.id = 'delete-dialog';
    dialog.className = 'modal';
    dialog._showModal = function() { this.setAttribute('open', ''); };
    dialog._close = function() { this.removeAttribute('open'); };
    dialog.showModal = dialog._showModal;
    dialog.close = dialog._close;
    Object.defineProperty(dialog, 'open', {
      get() { return 'open' in this.attributes; },
      set(v) { if (v) this.attributes.open = ''; else delete this.attributes.open; }
    });
    const titleEl = doc.createElement('h3');
    titleEl.className = 'modal-title';
    titleEl.textContent = 'Delete?';
    dialog.appendChild(titleEl);
    const cancelBtn = doc.createElement('button');
    cancelBtn.className = 'btn-cancel';
    dialog.appendChild(cancelBtn);
    const confirmBtn = doc.createElement('button');
    confirmBtn.className = 'btn-confirm';
    dialog.appendChild(confirmBtn);
    doc.add(dialog);

    boot(doc, win, () => ({ json: () => Promise.resolve({}) }));

    ok('S10 win.confirmDelete is a function', typeof win.confirmDelete === 'function');
    if (typeof win.confirmDelete === 'function') {
      eq('S10 dialog closed before confirmDelete', dialog.open, false);
      win.confirmDelete(42, 'INBOX', 'Delete this message?');
      eq('S10 dialog opened after confirmDelete', dialog.open, true);
      eq('S10 title set', titleEl.textContent, 'Delete this message?');
    }
  }

  // S11 — folder delete goes through the same confirm dialog, different endpoint.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildDialog(doc, 'delete-dialog');
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);

    ok('S11 win.deleteFolder is a function', typeof win.deleteFolder === 'function');
    if (typeof win.deleteFolder === 'function') {
      win.deleteFolder('Archive', 'Archive');
      eq('S11 dialog opened', parts.dialog.open, true);
      ok('S11 warns that the contents are destroyed',
        parts.descEl.textContent.indexOf('permanently deleted') !== -1);
      parts.confirmBtn.dispatch('click');
      await settle();
      eq('S11 POSTs the folder endpoint', calls[0] && calls[0].url, '?page=deleteFolder');
      ok('S11 body carries folder and csrf',
        calls[0].body.indexOf('folder=Archive') !== -1 &&
        calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
      // The message-delete path must still post where it always did.
      win.confirmDelete(42, 'INBOX', 'Delete this message?');
      parts.confirmBtn.dispatch('click');
      await settle();
      eq('S11 message delete still posts ?page=delete', calls[1] && calls[1].url, '?page=delete');
      ok('S11 message delete body carries the uid', calls[1].body.indexOf('uid=42') !== -1);
      // Cancel must not post anything.
      win.deleteFolder('Archive', 'Archive');
      parts.cancelBtn.dispatch('click');
      await settle();
      eq('S11 cancel closes without posting', calls.length, 2);
      eq('S11 cancel closed the dialog', parts.dialog.open, false);
    }
  }

  // S12 — new folder dialog: name is posted, an empty name is refused.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildDialog(doc, 'folder-dialog', { input: true });
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);

    ok('S12 win.newFolder is a function', typeof win.newFolder === 'function');
    if (typeof win.newFolder === 'function') {
      win.newFolder();
      eq('S12 dialog opened', parts.dialog.open, true);
      eq('S12 input focused', parts.input._focused, true);
      // An empty name must not create anything, and must not close the dialog.
      parts.confirmBtn.dispatch('click');
      await settle();
      eq('S12 empty name posts nothing', calls.length, 0);
      eq('S12 dialog stays open on an empty name', parts.dialog.open, true);
      // Whitespace only is still empty.
      parts.input.value = '   ';
      parts.confirmBtn.dispatch('click');
      await settle();
      eq('S12 blank name posts nothing', calls.length, 0);
      parts.input.value = 'Testing';
      parts.confirmBtn.dispatch('click');
      await settle();
      eq('S12 POSTs the create endpoint', calls[0] && calls[0].url, '?page=createFolder');
      ok('S12 body carries name and csrf',
        calls[0].body.indexOf('name=Testing') !== -1 &&
        calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
      eq('S12 dialog stays open with spinner', parts.dialog.open, true);
    }
  }

  // S13 — read view: mark unread must LEAVE the message, never reload it.
  // handle_read() marks the message read on every view, so a reload would
  // silently undo the action the user just asked for.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const calls = [];
    boot(doc, win, () => ({ json: () => Promise.resolve({}) }), calls);

    ok('S13 win.markUnreadBack is a function', typeof win.markUnreadBack === 'function');
    if (typeof win.markUnreadBack === 'function') {
      win.markUnreadBack(7, 'INBOX');
      await settle();
      eq('S13 posts the markUnread endpoint', calls[0] && calls[0].url, '?page=markUnread');
      ok('S13 body carries uid, folder and csrf',
        calls[0].body.indexOf('uid=7') !== -1 &&
        calls[0].body.indexOf('folder=INBOX') !== -1 &&
        calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
      eq('S13 never reloads the read page', win._reloads, 0);
      eq('S13 navigates to the folder listing', win.location.href, '?page=folder&folder=INBOX');
    }
  }

  // S14 — Empty Trash/Spam and the cache wipe reuse the one confirm dialog,
  // including its confirm-button label, so no native confirm() is left.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildDialog(doc, 'delete-dialog');
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);

    ok('S14 win.confirmEmpty is a function', typeof win.confirmEmpty === 'function');
    ok('S14 win.confirmClearCache is a function', typeof win.confirmClearCache === 'function');
    if (typeof win.confirmEmpty === 'function') {
      win.confirmEmpty('Spam', 'Spam');
      eq('S14 dialog opened', parts.dialog.open, true);
      ok('S14 title names the folder', parts.titleEl.textContent.indexOf('Spam') !== -1);
      eq('S14 confirm button is relabelled', parts.confirmBtn.textContent, 'Empty Spam');
      parts.confirmBtn.dispatch('click');
      await settle();
      eq('S14 POSTs the gated route', calls[0] && calls[0].url, '?page=emptyFolder');
      ok('S14 body carries folder and csrf',
        calls[0].body.indexOf('folder=Spam') !== -1 &&
        calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
    }
    if (typeof win.confirmClearCache === 'function') {
      win.confirmClearCache();
      parts.confirmBtn.dispatch('click');
      await settle();
      eq('S14 cache wipe posts settings', calls[1] && calls[1].url, '?page=settings');
      ok('S14 cache wipe carries the flag', calls[1].body.indexOf('clear_cache=1') !== -1);
    }
    // Cancelling must not post, whichever action opened the dialog.
    win.confirmEmpty('Trash', 'Trash');
    const before = calls.length;
    parts.cancelBtn.dispatch('click');
    await settle();
    eq('S14 cancel posts nothing', calls.length, before);
  }

  // S15 — reader Move to: picks a folder, posts the gated route, leaves for it.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildDialog(doc, 'move-dialog', { select: true });
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);

    ok('S15 win.moveTo is a function', typeof win.moveTo === 'function');
    if (typeof win.moveTo === 'function') {
      win.moveTo(7, 'INBOX');
      eq('S15 picker opened', parts.dialog.open, true);
      parts.select.value = 'Archive';
      parts.confirmBtn.dispatch('click');
      await settle();
      eq('S15 POSTs the move route', calls[0] && calls[0].url, '?page=move');
      ok('S15 body carries uid, folder, dest and csrf',
        calls[0].body.indexOf('uid=7') !== -1 &&
        calls[0].body.indexOf('folder=INBOX') !== -1 &&
        calls[0].body.indexOf('dest=Archive') !== -1 &&
        calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
      // The message is no longer in this folder, so going back to it is wrong.
      eq('S15 leaves for the destination', win.location.href, '?page=folder&folder=Archive');
      eq('S15 never reloads the message it just moved', win._reloads, 0);
      eq('S15 picker closed', parts.dialog.open, false);
      // Cancel must not post.
      win.moveTo(7, 'INBOX');
      parts.cancelBtn.dispatch('click');
      await settle();
      eq('S15 cancel posts nothing', calls.length, 1);
    }
  }

  // S16 — optimistic list actions: patch the row first, tell the server after.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildList(doc, 'INBOX', 3);
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);

    ok('S16 win.toggleRead is a function', typeof win.toggleRead === 'function');
    ok('S16 win.rowAction is a function', typeof win.rowAction === 'function');
    if (typeof win.toggleRead === 'function') {
      // Row 1 starts unread. Marking it read must show instantly, not after a reload.
      eq('S16 row starts unread', parts.rows[0].classList.contains('unread'), true);
      win.toggleRead(1, 'INBOX');
      eq('S16 flag flips before the server answers', parts.rows[0].classList.contains('unread'), false);
      eq('S16 never reloads', win._reloads, 0);
      await settle();
      eq('S16 posts markRead for an unread row', calls[0] && calls[0].url, '?page=markRead');
      ok('S16 body carries uid, folder and csrf',
        calls[0].body.indexOf('uid=1') !== -1 &&
        calls[0].body.indexOf('folder=INBOX') !== -1 &&
        calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
      // The same button now goes the other way, and the icon follows.
      eq('S16 icon swapped to opened', parts.rows[0]._use.getAttribute('href'), '#tabler-mail-opened');
      win.toggleRead(1, 'INBOX');
      await settle();
      eq('S16 posts markUnread for a read row', calls[1] && calls[1].url, '?page=markUnread');
      eq('S16 flag flips back', parts.rows[0].classList.contains('unread'), true);
      eq('S16 icon swapped back', parts.rows[0]._use.getAttribute('href'), '#tabler-mail');
    }
    if (typeof win.rowAction === 'function') {
      // Removal: hidden at once, detached only once the server confirms.
      win.rowAction(2, 'INBOX', '?page=delete', 'remove');
      eq('S16 row hidden immediately', parts.rows[1].classList.contains('row-gone'), true);
      eq('S16 count drops immediately', parts.count.textContent, '(2)');
      ok('S16 row still attached until confirmed', parts.rows[1].parentNode !== null);
      await settle();
      eq('S16 confirmed removal detaches the row', parts.rows[1].parentNode, null);
      eq('S16 posted the delete route', calls[2] && calls[2].url, '?page=delete');
      ok('S16 marks the request as ajax so the server answers JSON',
        calls[2].body.indexOf('ajax=1') !== -1);
    }
  }

  // S17 — a rejected action must put the row back. A UI that lies is worse.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildList(doc, 'INBOX', 3);
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: false, message: 'Could not move.' }) }), calls);

    win.rowAction(2, 'INBOX', '?page=delete', 'remove');
    eq('S17 row hidden optimistically', parts.rows[1].classList.contains('row-gone'), true);
    await settle();
    eq('S17 refusal un-hides the row', parts.rows[1].classList.contains('row-gone'), false);
    ok('S17 row is still in the list', parts.rows[1].parentNode !== null);
    eq('S17 count restored', parts.count.textContent, '(3)');
    const err = doc.querySelector('.toast-error');
    ok('S17 the failure is reported', err && err.textContent.indexOf('Could not move.') !== -1);
    // A flag flip must roll back too.
    win.toggleRead(1, 'INBOX');
    eq('S17 flag flipped optimistically', parts.rows[0].classList.contains('unread'), false);
    await settle();
    eq('S17 flag rolled back', parts.rows[0].classList.contains('unread'), true);
  }

  // S18 — a network failure rolls back exactly like a refusal.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildList(doc, 'INBOX', 2);
    boot(doc, win, () => Promise.reject(new Error('offline')), []);

    win.rowAction(1, 'INBOX', '?page=delete', 'remove');
    await settle();
    eq('S18 offline un-hides the row', parts.rows[0].classList.contains('row-gone'), false);
    ok('S18 row survives', parts.rows[0].parentNode !== null);
    const err = doc.querySelector('.toast-error');
    ok('S18 says it is offline', err && err.textContent.length > 0);
  }

  // S19 — the delete confirmation feeds the optimistic path, not a reload.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildDialog(doc, 'delete-dialog');
    const list = buildList(doc, 'INBOX', 2);
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);

    win.confirmDelete(1, 'INBOX', 'Delete this message?');
    eq('S19 dialog opened', parts.dialog.open, true);
    ok('S19 nothing changed before confirming', !list.rows[0].classList.contains('row-gone'));
    parts.confirmBtn.dispatch('click');
    eq('S19 row goes as soon as it is confirmed', list.rows[0].classList.contains('row-gone'), true);
    await settle();
    eq('S19 posted the delete route', calls[0] && calls[0].url, '?page=delete');
    ok('S19 as an ajax call', calls[0].body.indexOf('ajax=1') !== -1);
    eq('S19 never reloads', win._reloads, 0);
    eq('S19 count dropped', list.count.textContent, '(1)');
    // Folder delete has no row to patch, so it keeps the reload it needs.
    win.deleteFolder('Archive', 'Archive');
    parts.confirmBtn.dispatch('click');
    await settle();
    eq('S19 folder delete still posts its own route', calls[1] && calls[1].url, '?page=deleteFolder');
    eq('S19 folder delete still reloads the sidebar', win._reloads, 1);
  }

  // S20 — moving from a list row is optimistic: the row goes, nothing navigates.
  // Moving from the reader has no row, so it follows the message (S15).
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildDialog(doc, 'move-dialog', { select: true });
    const list = buildList(doc, 'INBOX', 3);
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);

    win.moveTo(2, 'INBOX');
    eq('S20 picker opened', parts.dialog.open, true);
    parts.select.value = 'Archive';
    parts.confirmBtn.dispatch('click');
    eq('S20 row goes at once', list.rows[1].classList.contains('row-gone'), true);
    eq('S20 count drops at once', list.count.textContent, '(2)');
    await settle();
    eq('S20 posted the move route', calls[0] && calls[0].url, '?page=move');
    ok('S20 body carries dest and the ajax flag',
      calls[0].body.indexOf('dest=Archive') !== -1 && calls[0].body.indexOf('ajax=1') !== -1);
    eq('S20 stays on the list', win.location.href, undefined);
    eq('S20 never reloads', win._reloads, 0);
    eq('S20 row detached once confirmed', list.rows[1].parentNode, null);
  }

  // S21 — a refused move from the list puts the row back.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildDialog(doc, 'move-dialog', { select: true });
    const list = buildList(doc, 'INBOX', 2);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: false, message: 'No such folder.' }) }), []);

    win.moveTo(1, 'INBOX');
    parts.select.value = 'Nope';
    parts.confirmBtn.dispatch('click');
    await settle();
    eq('S21 refusal restores the row', list.rows[0].classList.contains('row-gone'), false);
    eq('S21 count restored', list.count.textContent, '(2)');
    const err = doc.querySelector('.toast-error');
    ok('S21 reports why', err && err.textContent.indexOf('No such folder.') !== -1);
  }

  // S22 — clicking a message acknowledges the click in the same frame, because
  // the navigation that follows still costs a round trip.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const list = buildList(doc, 'INBOX', 2);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);

    list.rows[0]._link.dispatch('click');
    ok('S22 the clicked row shows it is opening', list.rows[0].classList.contains('opening'));
    ok('S22 the progress bar starts', bar.classList.contains('loading'));
    ok('S22 other rows are untouched', !list.rows[1].classList.contains('opening'));

    // Coming back through the browser cache must not leave a row spinning.
    win.dispatch('pageshow');
    ok('S22 pageshow clears the row', !list.rows[0].classList.contains('opening'));
    ok('S22 pageshow stops the bar', !bar.classList.contains('loading'));

    // A modified click opens a new tab and this page never navigates, so nothing
    // should be marked as opening.
    list.rows[1]._link.dispatch('click', null, { metaKey: true });
    ok('S22 a modified click is left alone', !list.rows[1].classList.contains('opening'));
    ok('S22 a modified click does not start the bar', !bar.classList.contains('loading'));
  }

  // S23 — a reader action is a round trip, so the control that was clicked spins
  // and the bar runs. Dialog-first controls must not: nothing is in flight yet.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const r = buildReaderActions(doc);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);

    r.reply.dispatch('click');
    ok('S23 the clicked action is marked busy', r.reply.classList.contains('busy'));
    eq('S23 its icon becomes a spinner', r.reply._use.getAttribute('href'), '#tabler-progress');
    eq('S23 the original icon is remembered', r.reply.getAttribute('data-icon-was'), '#tabler-mail');
    ok('S23 the bar runs', bar.classList.contains('loading'));
    ok('S23 other actions are untouched', !r.back.classList.contains('busy'));

    // Back from the browser cache: the reply form was opened and abandoned, and a
    // restored page must not show a control that is still working.
    win.dispatch('pageshow');
    ok('S23 pageshow clears busy', !r.reply.classList.contains('busy'));
    eq('S23 pageshow restores the icon', r.reply._use.getAttribute('href'), '#tabler-mail');
    ok('S23 pageshow stops the bar', !bar.classList.contains('loading'));

    // Move and Delete open a dialog that can be cancelled.
    r.move.dispatch('click');
    ok('S23 the move button does not claim to work yet', !r.move.classList.contains('busy'));
    r.del.dispatch('click');
    ok('S23 the delete button does not either', !r.del.classList.contains('busy'));
    ok('S23 and neither starts the bar', !bar.classList.contains('loading'));
  }

  // S24 — a refused reader action must stop claiming to work, and say why. Failures
  // float in as a toast, which lives in the shared chrome, not the reader itself.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const r = buildReaderActions(doc);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: false, message: 'No Spam folder on this account.' }) }), []);

    r.spam.dispatch('click');
    ok('S24 the spam button spins', r.spam.classList.contains('busy'));
    // The reader has no row, so this is the follow-the-message path.
    win.rowAction(7, 'INBOX', '?page=spam', 'remove');
    ok('S24 the bar runs while the post is in flight', bar.classList.contains('loading'));
    await settle();
    const errToast = doc.querySelector('.toast-error');
    ok('S24 the refusal is reported', errToast && errToast.textContent.indexOf('No Spam folder') !== -1);
    ok('S24 the toast is shown', !!errToast && !errToast.classList.contains('hidden'));
    ok('S24 the button stops spinning', !r.spam.classList.contains('busy'));
    eq('S24 its icon comes back', r.spam._use.getAttribute('href'), '#tabler-mail');
    ok('S24 the bar stops', !bar.classList.contains('loading'));
    eq('S24 and the reader stays put', win.location.href, undefined);
  }

  // S25 — search. Enter submits the form without ever clicking the button, which
  // is how search is actually used, so the submit is what has to be watched.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildSearch(doc);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);

    s.form.dispatch('submit');
    ok('S25 the search button spins on submit', s.btn.classList.contains('busy'));
    eq('S25 its icon becomes a spinner', s.btn._use.getAttribute('href'), '#tabler-progress');
    ok('S25 the bar runs', bar.classList.contains('loading'));

    // A mouse user fires click AND submit; the second must not overwrite the
    // remembered icon with the spinner it just installed.
    s.btn.dispatch('click');
    eq('S25 the original icon survives a double mark', s.btn.getAttribute('data-icon-was'), '#tabler-search');

    win.dispatch('pageshow');
    ok('S25 pageshow clears it', !s.btn.classList.contains('busy'));
    eq('S25 pageshow restores the icon', s.btn._use.getAttribute('href'), '#tabler-search');

    // The server-side search is a full IMAP SEARCH, and it is a plain link.
    s.serverLink.dispatch('click');
    ok('S25 the server-search offer reports its wait', s.serverLink.classList.contains('busy'));
    ok('S25 and starts the bar', bar.classList.contains('loading'));
  }

  // S26 — the composer. Whichever button submitted is the one that reports the
  // wait, and a browser that does not name it falls back to the primary action.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const c = buildCompose(doc);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);

    // Save draft: an IMAP append, not a send, so Send must not claim to be working.
    c.form.dispatch('submit', null, { submitter: c.draft });
    ok('S26 the button that submitted spins', c.draft.classList.contains('busy'));
    ok('S26 Send is left alone', !c.send.classList.contains('busy'));
    ok('S26 the bar runs', bar.classList.contains('loading'));

    win.dispatch('pageshow');
    ok('S26 pageshow clears it', !c.draft.classList.contains('busy'));
    eq('S26 pageshow restores the icon', c.draft._use.getAttribute('href'), '#tabler-device-floppy');

    // Safari before 15.4 has no ev.submitter, and Send is the primary action.
    c.form.dispatch('submit');
    ok('S26 without a named submitter the primary action spins', c.send.classList.contains('busy'));
    eq('S26 and its icon spins too', c.send._use.getAttribute('href'), '#tabler-progress');

    // Sending is a round trip whether or not Undo send is configured, so the
    // button must report the wait in BOTH modes. The undo feature used to return
    // before markBusy(), which left the default configuration (undo off) with no
    // spinner and no progress bar at all.
    win.dispatch('pageshow');
    const evOff = c.form.dispatch('submit');
    ok('S26 with undo off the submit still spins Send', c.send.classList.contains('busy'));
    ok('S26 and the browser still posts the form itself', !evOff.defaultPrevented);

    // A second send while the first is in flight is refused: the primary button
    // is already busy, so the browser is told to hold its horses.
    const evDup = c.form.dispatch('submit');
    ok('S26 a second send while Send is busy is refused', evDup.defaultPrevented);
  }

  // S27 — the editor's DOM back to Markdown. Trees from PHP are real
  // md_to_html() output; the hand-built ones are what a browser's editing
  // commands leave behind (<b>, <i>, &nbsp;, stray spans, nested lists).
  {
    const doc = makeDoc(); const win = makeWin();
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);
    const md = win.mdSerialize;
    ok('S27 the serializer is exposed', typeof md === 'function');
    const fx = JSON.parse(fs.readFileSync(process.env.MD_FIXTURES_PATH, 'utf8'));
    const node = j => ('t' in j)
      ? { nodeType: 3, nodeName: '#text', nodeValue: j.t, childNodes: [] }
      : { nodeType: 1, nodeName: j.n, childNodes: j.c.map(node), getAttribute: k => (k in j.a ? j.a[k] : null) };
    fx.canonical.forEach(f => eq('S27 round trip ' + JSON.stringify(f.md), md(node(f.tree)), f.md));
    fx.literal.forEach(f => eq('S27 literal text escapes like md_escape: ' + JSON.stringify(f.src), md(node(f.tree)), f.expect));

    const X = t => ({ t });
    const E = (n, c, a) => ({ n, c: c || [], a: a || {} });
    const T = (name, tree, expected) => eq('S27 ' + name, md(node(tree)), expected);
    T('Chrome lines are divs', E('DIV', [E('DIV', [X('a')]), E('DIV', [E('BR')]), E('DIV', [X('b')])]), 'a\n\nb');
    T('text typed before the first Enter sits at the root', E('DIV', [X('a'), E('DIV', [X('b')])]), 'a\nb');
    T('b and i are strong and em', E('DIV', [E('DIV', [E('B', [X('x')]), X(' '), E('I', [X('y')])])]), '**x** *y*');
    T('strike is ~~', E('DIV', [E('DIV', [E('STRIKE', [X('x')])])]), '~~x~~');
    T('edge spaces move outside the marker', E('DIV', [E('DIV', [X('a'), E('B', [X(' x ')]), X('b')])]), 'a **x** b');
    T('an empty bold leaves nothing', E('DIV', [E('DIV', [X('a'), E('B', [E('BR')])])]), 'a');
    T('&nbsp; is a space', E('DIV', [E('DIV', [X('a\u00a0\u00a0b\u00a0')])]), 'a  b ');
    T('the ZWSP after inline code is dropped', E('DIV', [E('DIV', [X('say '), E('CODE', [X('x')]), X('\u200by')])]), 'say `x`y');
    T('spans and fonts are transparent', E('DIV', [E('DIV', [E('SPAN', [X('a')], { style: 'font-weight:700' }), E('FONT', [X('b')])])]), 'ab');
    T('a line break inside a div is a new line', E('DIV', [E('DIV', [X('a'), E('BR'), X('b')])]), 'a\nb');
    T('an empty heading is a blank line', E('DIV', [E('H1', [E('BR')])]), '');
    T('nested lists flatten (L1)', E('DIV', [E('UL', [E('LI', [X('a'), E('UL', [E('LI', [X('b')])])]), E('LI', [X('c')])])]), '- a\n- b\n- c');
    T('a list keeps its start', E('DIV', [E('OL', [E('LI', [X('a')]), E('LI', [X('b')])], { start: '7' })]), '7. a\n8. b');
    T('an empty item keeps its marker', E('DIV', [E('UL', [E('LI', [E('BR')])])]), '- ');
    T('a line break in an item continues it', E('DIV', [E('OL', [E('LI', [X('a'), E('BR'), X('- b')])])]), '1. a\n   \\- b');
    T('a pre with line breaks', E('DIV', [E('PRE', [X('a'), E('BR'), X('*b*')])]), '```\na\n*b*\n```');
    T('a quote holds blocks', E('DIV', [E('BLOCKQUOTE', [E('H2', [X('t')]), E('DIV', [E('BR')]), E('DIV', [X('x')])])]), '> ## t\n>\n> x');
    T('a disallowed link keeps only its text', E('DIV', [E('DIV', [E('A', [X('t')], { href: 'javascript:alert(1)' })])]), 't');
    T('an http image is dropped', E('DIV', [E('DIV', [X('a'), E('IMG', [], { src: 'http://x.io/i.png' })])]), 'a');
    T('an image alt is escaped', E('DIV', [E('DIV', [E('IMG', [], { src: 'https://x.io/i.png', alt: 'a]b' })])]), '![a\\]b](https://x.io/i.png)');
    T('a link label is escaped', E('DIV', [E('DIV', [E('A', [X('a]_b_')], { href: 'https://x.io' })])]), '[a\\]\\_b\\_](https://x.io)');
    T('a URL label that would not autolink is explicit', E('DIV', [E('DIV', [X('x'), E('A', [X('https://x.io')], { href: 'https://x.io' })])]), 'x[https://x.io](https://x.io)');
    T('a URL with spaces is encoded', E('DIV', [E('DIV', [E('A', [X('t')], { href: 'https://x.io/a b' })])]), '[t](https://x.io/a%20b)');
    T('a URL with unbalanced parens is encoded', E('DIV', [E('DIV', [E('A', [X('t')], { href: 'https://x.io/a)' })])]), '[t](https://x.io/a%29)');
    T('a backslash before a marker is escaped', E('DIV', [E('DIV', [X('a\\'), E('B', [X('b')])])]), 'a\\\\**b**');
    T('a star next to a marker is escaped', E('DIV', [E('DIV', [X('a*'), E('B', [X('b')])])]), 'a\\***b**');
    T('a script is ignored', E('DIV', [E('DIV', [X('a'), E('SCRIPT', [X('x()')])])]), 'a');
    T('trailing blank lines are dropped', E('DIV', [E('DIV', [X('a')]), E('DIV', [E('BR')]), E('DIV', [E('BR')])]), 'a');
    T('inline code with a backtick', E('DIV', [E('DIV', [E('CODE', [X('a`b')])])]), '`` a`b ``');
  }

  // S28 — the editor takes over the textarea only when the browser can edit,
  // writes Markdown back only after a real edit, and always before a submit.
  {
    const mkEditor = (withExec) => {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      const c = buildCompose(doc);
      const body = doc.createElement('div'); body.className = 'compose-body';
      const ed = doc.createElement('div'); ed.className = 'md-editor hidden';
      ed.setAttribute('contenteditable', 'true');
      const ta = doc.createElement('textarea'); ta.setAttribute('name', 'body');
      ta.value = '**untouched** source_';
      body.appendChild(ed); body.appendChild(ta); c.form.appendChild(body);
      ed.focus = function() { this._focused = true; };
      doc.activeElement = ta;
      const cmds = [];
      if (withExec) doc.execCommand = (cmd, ui, arg) => { cmds.push([cmd, arg]); return true; };
      // What the editor shows: md_to_html('**new**'), as the browser holds it.
      ed.childNodes = [{ nodeType: 1, nodeName: 'DIV', getAttribute: () => null, childNodes: [
        { nodeType: 1, nodeName: 'STRONG', getAttribute: () => null, childNodes: [{ nodeType: 3, nodeValue: 'new', childNodes: [] }] }] }];
      ed.textContent = 'new';
      boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);
      return { doc, win, c, ed, ta, cmds };
    };

    const off = mkEditor(false);
    ok('S28 without execCommand the editor stays hidden', off.ed.classList.contains('hidden'));
    ok('S28 and the textarea stays', !off.ta.classList.contains('hidden'));

    const on = mkEditor(true);
    ok('S28 with execCommand the editor shows', !on.ed.classList.contains('hidden'));
    ok('S28 and the textarea hides', on.ta.classList.contains('hidden'));
    ok('S28 the focus moves from the textarea to the editor', on.ed._focused === true);
    ok('S28 Enter makes a div line', on.cmds.some(x => x[0] === 'defaultParagraphSeparator' && x[1] === 'div'));

    on.c.form.dispatch('submit', null, { submitter: on.c.draft });
    eq('S28 an untouched body is posted exactly as it came', on.ta.value, '**untouched** source_');

    on.ed.dispatch('input');
    eq('S28 an edit writes Markdown to the textarea', on.ta.value, '**new**');
    on.ta.value = 'stale';
    on.c.form.dispatch('submit', null, { submitter: on.c.send });
    eq('S28 a submit after an edit writes it again', on.ta.value, '**new**');

    on.ed.textContent = '';
    on.ed.dispatch('input');
    ok('S28 an empty editor shows its placeholder', on.ed.classList.contains('is-empty'));
    on.ed.textContent = 'x';
    on.ed.dispatch('input');
    ok('S28 typing hides it', !on.ed.classList.contains('is-empty'));

    // markdown v1 limitations, the keyboard and clipboard side.
    const key = (init) => on.ed.dispatch('keydown', null, init);
    ok('L3: Tab is not captured, it moves the focus', !key({ key: 'Tab' }).defaultPrevented);
    ok('L8: Cmd+U is blocked (Markdown has no underline)', key({ key: 'u', metaKey: true }).defaultPrevented);
    ok('L8: Ctrl+U too', key({ key: 'U', ctrlKey: true }).defaultPrevented);
    ok('L8: Cmd+K is not handled (no link dialog in v1)', !key({ key: 'k', metaKey: true }).defaultPrevented);
    ok('plain U types', !key({ key: 'u' }).defaultPrevented);
    const clip = (type, data) => ({ inputType: type, dataTransfer: { types: ['text/html', 'text/plain'],
      getData: t => (t === 'text/plain' ? data : '<b onclick="x()">' + data + '</b>') } });
    on.cmds.length = 0;
    const paste = on.ed.dispatch('beforeinput', null, clip('insertFromPaste', 'x *y*'));
    ok('L7: a rich paste is stopped', paste.defaultPrevented);
    eq('L7: and inserted as its plain text', JSON.stringify(on.cmds), JSON.stringify([['insertText', 'x *y*']]));
    on.cmds.length = 0;
    const drop = on.ed.dispatch('beforeinput', null, clip('insertFromDrop', 'd'));
    ok('L7: a rich drop is stopped too', drop.defaultPrevented);
    eq('L7: and inserted as plain text', JSON.stringify(on.cmds), JSON.stringify([['insertText', 'd']]));
    ok('typing is left to the browser', !on.ed.dispatch('beforeinput', null, { inputType: 'insertText', data: 'a' }).defaultPrevented);
  }

  // S30 — the Markdown help opens in a modal, and only where one can open.
  {
    const mkHelp = (withModal) => {
      const doc = makeDoc(); const win = makeWin();
      const row = doc.createElement('div'); row.className = 'md-help-row hidden';
      const btn = doc.createElement('button'); btn.className = 'md-help'; btn.setAttribute('data-md-help', '');
      row.appendChild(btn);
      const dlg = doc.createElement('dialog'); dlg.id = 'md-help-dialog'; dlg.setAttribute('id', 'md-help-dialog');
      const close = doc.createElement('button'); close.className = 'btn-cancel';
      dlg.appendChild(close);
      if (withModal) {
        dlg.open = false;
        dlg.showModal = function() { this.open = true; };
        dlg.close = function() { this.open = false; };
      }
      doc.add(row); doc.add(dlg);
      boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);
      return { btn, row, dlg, close };
    };
    const no = mkHelp(false);
    ok('S30 without <dialog> support the help stays hidden', no.row.classList.contains('hidden'));
    const h = mkHelp(true);
    ok('S30 with it the help shows', !h.row.classList.contains('hidden'));
    h.btn.dispatch('click');
    ok('S30 a click opens the modal', h.dlg.open === true);
    h.close.dispatch('click');
    ok('S30 Close closes it', h.dlg.open === false);
    h.btn.dispatch('click');
    h.dlg.dispatch('click', h.dlg);
    ok('S30 a click on the backdrop closes it', h.dlg.open === false);
    h.btn.dispatch('click');
    h.dlg.dispatch('click', { tagName: 'TD' });
    ok('S30 a click inside the table does not', h.dlg.open === true);
  }

  // S31 — the sticky action row shows its separator only while it is stuck.
  // With bottom:-1px, a stuck row is 1px outside the viewport: not fully visible.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const c = buildCompose(doc);
    let cb = null, watched = null, opts = null;
    win.IntersectionObserver = function(fn, o) { cb = fn; opts = o; this.observe = el => { watched = el; }; };
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);
    const row = c.form.querySelectorAll('.compose-actions')[0];
    ok('S31 the action row is observed', watched === row);
    eq('S31 against the full threshold', JSON.stringify(opts && opts.threshold), '[1]');
    cb([{ target: row, intersectionRatio: 0.97 }]);
    ok('S31 stuck: the separator shows', row.classList.contains('stuck'));
    cb([{ target: row, intersectionRatio: 1 }]);
    ok('S31 in place: it goes', !row.classList.contains('stuck'));

    const doc2 = makeDoc(); buildMeta(doc2);
    const win2 = makeWin();
    buildCompose(doc2);
    let crashed = false;
    try { boot(doc2, win2, () => ({ ok: true, json: () => Promise.resolve({}) }), []); } catch (e) { crashed = true; }
    ok('S31 without IntersectionObserver nothing breaks', !crashed);
  }

  // S32 — folder-map onboarding: opens pre-filled with the detected map, and
  // "Save & Continue" posts the route and reloads. "Later" dismisses without
  // posting.
  {
    function buildOnboardingDialog(doc, folders, defaultMap) {
      const dlg = doc.createElement('dialog');
      dlg.id = 'onboarding-dialog';
      dlg.className = 'modal';
      dlg.showModal = function() { this.setAttribute('open', ''); };
      dlg.close = function() { this.removeAttribute('open'); };
      Object.defineProperty(dlg, 'open', {
        get() { return 'open' in this.attributes; },
        set(v) { if (v) this.attributes.open = ''; else delete this.attributes.open; }
      });
      dlg.setAttribute('data-folders', JSON.stringify(folders));
      dlg.setAttribute('data-default-map', JSON.stringify(defaultMap));
      const rows = doc.createElement('div');
      rows.className = 'folder-map-rows';
      dlg.appendChild(rows);
      const cancel = doc.createElement('button'); cancel.className = 'btn-cancel'; dlg.appendChild(cancel);
      const save = doc.createElement('button'); save.className = 'btn-confirm-primary'; dlg.appendChild(save);
      doc.add(dlg);
      return { dlg, rows, cancel, save };
    }

    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const folders = [
      { name: 'INBOX', displayName: 'Inbox', delimiter: '/', attributes: 0 },
      { name: 'Sent Items', displayName: 'Sent Items', delimiter: '/', attributes: 0 },
      { name: 'Deleted Items', displayName: 'Deleted Items', delimiter: '/', attributes: 0 },
    ];
    const h = buildOnboardingDialog(doc, folders, { sent: 'Sent Items', trash: 'Deleted Items' });
    const calls = [];
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);

    ok('S32 win.openOnboarding is a function', typeof win.openOnboarding === 'function');
    ok('S32 win.buildFolderRows is a function', typeof win.buildFolderRows === 'function');
    ok('S32 win.collectFolderMap is a function', typeof win.collectFolderMap === 'function');

    win.openOnboarding();
    ok('S32 onboarding dialog opened', h.dlg.open === true);
    const selSent = h.dlg.querySelectorAll('.folder-map-select[data-role="sent"]')[0];
    ok('S32 sent role pre-filled from the detected map',
      selSent && selSent.value === 'Sent Items');
    const selTrash = h.dlg.querySelectorAll('.folder-map-select[data-role="trash"]')[0];
    ok('S32 trash role pre-filled from the detected map',
      selTrash && selTrash.value === 'Deleted Items');

    // "Later" closes without saving anything.
    h.cancel.dispatch('click');
    ok('S32 Later closes the dialog', h.dlg.open === false);
    eq('S32 Later posts nothing', calls.length, 0);

    // Save & Continue posts the map and reloads on success.
    win.openOnboarding();
    h.save.dispatch('click');
    await settle();
    eq('S32 posts saveOnboarding', calls[0] && calls[0].url, '?page=saveOnboarding');
    ok('S32 body carries the folder map',
      calls[0].body.indexOf('protected_folders=') !== -1 &&
      calls[0].body.indexOf('%22sent%22') !== -1);
    ok('S32 body carries csrf', calls[0].body.indexOf('csrf_token=' + TOKEN) !== -1);
    eq('S32 reloads after save', win._reloads, 1);

    // With an empty folder list (failed IMAP / cold start), Save is disabled.
    const doc2 = makeDoc(); buildMeta(doc2);
    const win2 = makeWin();
    const h2 = buildOnboardingDialog(doc2, [], {});
    boot(doc2, win2, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    win2.openOnboarding();
    ok('S32 empty folder list disables Save', h2.save.disabled === true);

    // The ready-block trigger: setting __openOnboarding before boot opens it.
    const doc3 = makeDoc(); buildMeta(doc3);
    const win3 = makeWin();
    const h3 = buildOnboardingDialog(doc3, folders, { sent: 'Sent Items' });
    win3.__openOnboarding = true;
    boot(doc3, win3, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    ok('S32 the __openOnboarding flag opens the dialog on ready', h3.dlg.open === true);
  }

  // S33 — login page: remembers the last email, focuses the right field, and
  // spins the Sign in button on submit (blocking a second one).
  {
    function buildLoginForm(doc, emailValue) {
      const form = doc.createElement('form');
      form.className = 'login-form';
      const email = doc.createElement('input');
      email.type = 'email'; email.name = 'email'; email.id = 'login-email';
      email.value = emailValue || '';
      email.focus = function() { this._focused = true; };
      const pass = doc.createElement('input');
      pass.type = 'password'; pass.name = 'password'; pass.id = 'login-password';
      pass.focus = function() { this._focused = true; };
      const btn = doc.createElement('button');
      btn.type = 'submit'; btn.className = 'btn btn-primary';
      const use = doc.createElement('use'); use.setAttribute('href', '#tabler-arrow-right');
      const svg = doc.createElement('svg'); svg.appendChild(use);
      btn.appendChild(svg);
      form.appendChild(email);
      form.appendChild(pass);
      form.appendChild(btn);
      doc.add(form);
      return { form, email, pass, btn, use };
    }
    // No remembered email: the email field is focused, nothing is prefilled.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    win.localStorage = storageWith({});
    const f = buildLoginForm(doc);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);
    ok('S33 blank email is focused', f.email._focused === true);
    ok('S33 password is not focused', f.pass._focused !== true);
    eq('S33 nothing prefilled', f.email.value, '');

    // A remembered email is prefilled and the password takes focus.
    const doc2 = makeDoc(); buildMeta(doc2);
    const win2 = makeWin();
    win2.localStorage = storageWith({ 'emmmail-last-email': 'you@example.com' });
    const f2 = buildLoginForm(doc2);
    boot(doc2, win2, () => ({ ok: true, json: () => Promise.resolve({}) }), []);
    eq('S33 remembered email prefilled', f2.email.value, 'you@example.com');
    ok('S33 password focused for a returning user', f2.pass._focused === true);
    ok('S33 email is not focused when prefilled', f2.email._focused !== true);

    // Submitting persists the email and marks the button busy.
    const doc3 = makeDoc(); buildMeta(doc3);
    const win3 = makeWin();
    win3.localStorage = storageWith({});
    const f3 = buildLoginForm(doc3, 'me@example.com');
    const calls = [];
    boot(doc3, win3, () => ({ ok: true, json: () => Promise.resolve({}) }), calls);
    f3.form.dispatch('submit');
    eq('S33 submit saves the email', win3.localStorage.getItem('emmmail-last-email'), 'me@example.com');
    ok('S33 submit marks the button busy', f3.btn.classList.contains('busy'));
    eq('S33 button icon becomes the spinner', f3.use.getAttribute('href'), '#tabler-progress');
    f3.form.dispatch('submit');
    ok('S33 a second submit stays busy', f3.btn.classList.contains('busy'));
  }

  // S34 — win.toast builds a floating toast in the fixed container, auto-dismisses
  // it after a while, and click-dismisses it on demand.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    win.__toastMs = { success: 30, error: 50 }; // shorten the real delays
    const toasts = doc.createElement('div'); toasts.className = 'toast-container'; doc.add(toasts);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);

    ok('S34 win.toast is a function', typeof win.toast === 'function');
    const el = win.toast('Hello', 'success');
    ok('S34 toast is appended to the container', el.parentNode === toasts);
    ok('S34 toast carries the success type', el.classList.contains('toast-success'));
    eq('S34 toast text', el.textContent, 'Hello');
    ok('S34 role marks a status toast', el.getAttribute('role'), 'status');

    // Click dismisses early: the toast fades (hiding) and then leaves the DOM.
    el.dispatch('click');
    ok('S34 click marks the toast as leaving', el.classList.contains('hiding'));
    await settle(240);
    ok('S34 the leaving toast is removed', el.parentNode === null);

    // Auto-dismiss: an error toast removes itself after its timeout.
    const err = win.toast('Oops', 'error');
    ok('S34 error toast is typed', err.classList.contains('toast-error'));
    ok('S34 role marks an alert toast', err.getAttribute('role'), 'alert');
    await settle(320);
    ok('S34 the error toast auto-dismissed', err.parentNode === null);
  }

  // S35 — clicking a sidebar folder link swaps its icon for a spinner and runs
  // the progress bar; pageshow restores the icon (Back from cache), and a
  // modified click marks nothing.
  {
    function buildSidebar(doc) {
      const side = doc.createElement('div');
      side.className = 'sidebar';
      const nav = doc.createElement('nav');
      const ul = doc.createElement('ul');
      const mk = (name) => {
        const li = doc.createElement('li');
        const a = doc.createElement('a');
        a.className = 'folder-link';
        a.setAttribute('href', '?page=folder&folder=' + name);
        const svg = doc.createElement('svg');
        const use = doc.createElement('use'); use.setAttribute('href', '#tabler-folder');
        svg.appendChild(use);
        a.appendChild(svg);
        a._use = use;
        li.appendChild(a);
        ul.appendChild(li);
        return a;
      };
      const inbox = mk('INBOX');
      const archive = mk('Archive');
      nav.appendChild(ul);
      side.appendChild(nav);
      doc.add(side);
      const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
      return { inbox, archive, bar };
    }

    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildSidebar(doc);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);

    s.inbox.dispatch('click');
    ok('S35 the clicked folder link is marked busy', s.inbox.classList.contains('busy'));
    eq('S35 its icon becomes the spinner', s.inbox._use.getAttribute('href'), '#tabler-progress');
    ok('S35 the progress bar runs', s.bar.classList.contains('loading'));
    ok('S35 other folder links are untouched', !s.archive.classList.contains('busy'));

    // Coming back through the browser cache must not leave the icon spinning.
    win.dispatch('pageshow');
    ok('S35 pageshow clears the busy link', !s.inbox.classList.contains('busy'));
    eq('S35 pageshow restores the icon', s.inbox._use.getAttribute('href'), '#tabler-folder');
    ok('S35 pageshow stops the bar', !s.bar.classList.contains('loading'));

    // A modified click opens a new tab and this page never navigates.
    s.archive.dispatch('click', null, { metaKey: true });
    ok('S35 a modified click is left alone', !s.archive.classList.contains('busy'));
    ok('S35 a modified click does not start the bar', !s.bar.classList.contains('loading'));
  }

  // S29 — which typed text becomes formatting. Pure: the DOM side runs only in a
  // real browser (the stub has no Selection), and is checked there.
  {
    const doc = makeDoc(); const win = makeWin();
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);
    const m = win.mdMatchRule;
    ok('S29 the matcher is exposed', typeof m === 'function');
    const R = (name, text, trig, expect) => eq('S29 ' + name, JSON.stringify(m(text, trig)), JSON.stringify(expect));
    // Block rules fire on the space after a marker typed at the start of a line.
    R('# is h1', '# ', ' ', { rule: 'block', cmd: 'formatBlock', arg: '<h1>', len: 2 });
    R('### is h3', '### ', ' ', { rule: 'block', cmd: 'formatBlock', arg: '<h3>', len: 4 });
    R('####### is text', '####### ', ' ', null);
    R('- is a bullet list', '- ', ' ', { rule: 'block', cmd: 'insertUnorderedList', len: 2 });
    R('* is a bullet list', '* ', ' ', { rule: 'block', cmd: 'insertUnorderedList', len: 2 });
    R('• is a bullet list', '• ', ' ', { rule: 'block', cmd: 'insertUnorderedList', len: 2 });
    R('1. is a numbered list', '1. ', ' ', { rule: 'block', cmd: 'insertOrderedList', len: 3 });
    R('1986. is text', '1986. ', ' ', null);
    R('> is a quote', '> ', ' ', { rule: 'block', cmd: 'formatBlock', arg: '<blockquote>', len: 2 });
    R('``` + space is a code block', '``` ', ' ', { rule: 'block', cmd: 'formatBlock', arg: '<pre>', len: 4 });
    R('a marker after text is text', 'x - ', ' ', null);
    R('an nbsp counts as the space', '#\u00a0', ' ', { rule: 'block', cmd: 'formatBlock', arg: '<h1>', len: 2 });
    // A rule fires on Enter.
    R('--- + Enter is a rule', '---', '\n', { rule: 'block', cmd: 'insertHorizontalRule', len: 3 });
    R('*** + Enter is a rule', '***', '\n', { rule: 'block', cmd: 'insertHorizontalRule', len: 3 });
    R('iOS —- + Enter is a rule', '\u2014-', '\n', { rule: 'block', cmd: 'insertHorizontalRule', len: 2 });
    R('--- + space is not', '--- ', ' ', null);
    // L2: repeated markers are not nested lists.
    R('L2: -- is text', '-- ', ' ', null);
    R('L2: ** is text', '** ', ' ', null);
    R('L2: •• is text', '•• ', ' ', null);
    // Inline rules fire on the closing mark.
    R('**x** is bold', 'say **x y**', '*', { rule: 'inline', cmd: 'bold', text: 'x y', len: 7 });
    R('*x* is italic', 'say *x*', '*', { rule: 'inline', cmd: 'italic', text: 'x', len: 3 });
    R('_x_ is italic', 'say _x_', '_', { rule: 'inline', cmd: 'italic', text: 'x', len: 3 });
    R('~~x~~ is strike', 'a ~~x~~', '~', { rule: 'inline', cmd: 'strikeThrough', text: 'x', len: 5 });
    R('`x` is code', 'a `x`', '`', { rule: 'inline', cmd: 'code', text: 'x', len: 3 });
    R('**x* waits for the second star', 'say **x*', '*', null);
    R('* x* is text', 'say * x*', '*', null);
    R('*x * is text', 'say *x *', '*', null);
    R('snake_case_ is text', 'snake_case_', '_', null);
    R('an escaped closer is text', 'a *x\\*', '*', null);
    R('~x~ is text', 'a ~x~', '~', null);
    R('[t](url) is a link', 'see [the docs](https://x.io/a)', ')',
      { rule: 'inline', cmd: 'link', text: 'the docs', url: 'https://x.io/a', len: 26 });
    R('a link waits for balanced parentheses', 'see [w](https://x.io/Foo_(bar)', ')', null);
    R('and fires when they balance', '[w](https://x.io/Foo_(bar))', ')',
      { rule: 'inline', cmd: 'link', text: 'w', url: 'https://x.io/Foo_(bar)', len: 27 });
    R('a javascript: link is text', '[t](javascript:alert(1))', ')', null);
    R('a relative link is text', '[t](/x)', ')', null);
    R('![a](url) is an image', 'x ![a b](https://x.io/i.png)', ')',
      { rule: 'inline', cmd: 'image', text: 'a b', url: 'https://x.io/i.png', len: 26 });
    R('L6: an http image is text', '![a](http://x.io/i.png)', ')', null);
    R('an image is not a link', '![a](https://x.io/i.png)', ')',
      { rule: 'inline', cmd: 'image', text: 'a', url: 'https://x.io/i.png', len: 24 });
  }

  // S36 — navigating between folders swaps the open listing in place instead of
  // reloading the page. The stub has no HTML parser, so the swap itself (the
  // innerHTML replacement) cannot complete here; the fetch, the interception
  // rules and the full-navigation fallback are what these tests pin.
  {
    function buildListing(doc) {
      const side = doc.createElement('div'); side.className = 'sidebar';
      const ul = doc.createElement('ul');
      const mk = (name) => {
        const li = doc.createElement('li');
        const a = doc.createElement('a');
        a.className = 'folder-link';
        a.setAttribute('href', '?page=folder&folder=' + name);
        const svg = doc.createElement('svg');
        const use = doc.createElement('use'); use.setAttribute('href', '#tabler-folder');
        svg.appendChild(use); a.appendChild(svg); a._use = use;
        li.appendChild(a); ul.appendChild(li); return a;
      };
      const inbox = mk('INBOX'); const archive = mk('Archive');
      side.appendChild(ul); doc.add(side);
      // The listing chrome view_inbox() ships: a sync chip and a mailbox section.
      buildSync(doc, 'INBOX');
      const box = doc.createElement('section'); box.className = 'mailbox'; doc.add(box);
      const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
      return { inbox, archive, box, bar };
    }

    // S36a — on a listing a folder click is intercepted and fetched as JSON.
    {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      const calls = [];
      const s = buildListing(doc);
      boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, html: '<section class="mailbox"></section>', title: 'Archive' }) }), calls);
      const ev = s.archive.dispatch('click');
      ok('S36a the folder click is acknowledged', s.archive.classList.contains('busy'));
      ok('S36a the click is intercepted', ev.defaultPrevented);
      eq('S36a it fetches the ajax listing', calls[0] && calls[0].url, '?page=folder&folder=Archive&ajax=1');
      await settle();
      eq('S36a an unswappable listing falls back to navigation', win.location.href, '?page=folder&folder=Archive');
    }

    // S36b — outside a listing (no .sync) a folder click stays a full navigation.
    {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      const calls = [];
      const side = doc.createElement('div'); side.className = 'sidebar';
      const ul = doc.createElement('ul'); const li = doc.createElement('li');
      const a = doc.createElement('a'); a.className = 'folder-link';
      a.setAttribute('href', '?page=folder&folder=Archive');
      li.appendChild(a); ul.appendChild(li); side.appendChild(ul); doc.add(side);
      const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
      boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), calls);
      const ev = a.dispatch('click');
      ok('S36b the click is still acknowledged', a.classList.contains('busy'));
      ok('S36b it is not intercepted', !ev.defaultPrevented);
      eq('S36b no ajax fetch', calls.length, 0);
    }

    // S36c — pagination into a folder is intercepted; search pagination is not.
    {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      const calls = [];
      const box = doc.createElement('section'); box.className = 'mailbox';
      const pag = doc.createElement('div'); pag.className = 'pagination';
      const next = doc.createElement('a'); next.setAttribute('href', '?page=folder&folder=INBOX&p=2');
      const search = doc.createElement('a'); search.setAttribute('href', '?page=search&q=x&folder=INBOX&p=2');
      pag.appendChild(next); pag.appendChild(search); box.appendChild(pag); doc.add(box);
      boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, html: '<section class="mailbox"></section>', title: 'Inbox' }) }), calls);
      const evN = next.dispatch('click');
      ok('S36c a folder pagination click is intercepted', evN.defaultPrevented);
      eq('S36c it fetches page 2', calls[0] && calls[0].url, '?page=folder&folder=INBOX&p=2&ajax=1');
      const evS = search.dispatch('click');
      ok('S36c a search pagination click is not intercepted', !evS.defaultPrevented);
      eq('S36c no extra fetch', calls.length, 1);
    }

    // S36d — Back/Forward through the in-place history swaps the listing.
    {
      const doc = makeDoc(); buildMeta(doc); buildSync(doc, 'INBOX');
      const win = makeWin();
      win.location.href = '?page=folder&folder=INBOX';
      const calls = [];
      boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, html: '<section class="mailbox"></section>', title: 'Inbox' }) }), calls);
      win.dispatch('popstate');
      await settle();
      eq('S36d Back on a listing fetches the current URL', calls[0] && calls[0].url, '?page=folder&folder=INBOX&ajax=1');
    }
  }

  // S37-S43 — the keyboard layer. One document-level keydown gated so it never
  // fires where it must not. Fixtures carry a .topbar (kbAuthed), a data-kb-map
  // on <body>, a nav bar, and the dialogs the actions open.
  function buildKbListing(doc, n, map) {
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    doc.body.setAttribute('data-kb-map', JSON.stringify(map || { i: 'INBOX', t: 'Sent', a: 'Archive' }));
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    const list = buildList(doc, 'INBOX', n);
    const kbd = buildDialog(doc, 'kb-help-dialog');
    const dlg = buildDialog(doc, 'delete-dialog');
    const mdlg = buildDialog(doc, 'move-dialog', { select: true });
    return { topbar, bar, list, kbd, dlg, mdlg };
  }
  function buildKbReader(doc, attrs) {
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    doc.body.setAttribute('data-kb-map', JSON.stringify({ i: 'INBOX', a: 'Archive' }));
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    const r = buildReaderActions(doc);
    Object.keys(attrs || {}).forEach(k => r.card.setAttribute(k, attrs[k]));
    const dlg = buildDialog(doc, 'delete-dialog');
    return { topbar, bar, dlg, card: r.card, r };
  }
  // A sidebar of folder links, so a keyboard folder jump can spin the same icon
  // a click would.
  function buildKbSidebar(doc, folders) {
    const side = doc.createElement('div'); side.className = 'sidebar';
    const nav = doc.createElement('nav'); const ul = doc.createElement('ul');
    const links = {};
    (folders || ['INBOX', 'Sent']).forEach(name => {
      const li = doc.createElement('li');
      const a = doc.createElement('a'); a.className = 'folder-link';
      a.setAttribute('href', '?page=folder&folder=' + encodeURIComponent(name));
      const svg = doc.createElement('svg'); const use = doc.createElement('use');
      use.setAttribute('href', '#tabler-folder'); svg.appendChild(use); a.appendChild(svg);
      li.appendChild(a); ul.appendChild(li); links[name] = a;
    });
    nav.appendChild(ul); side.appendChild(nav); doc.add(side);
    return links;
  }

  // S37 — the guards: the layer is inert where it must not act.
  {
    // Typing in an input never moves the cursor or opens anything.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    const input = doc.createElement('input'); input.setAttribute('name', 'q'); doc.add(input);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'j', target: input });
    ok('S37 keys while typing are inert', !s.list.rows[1].classList.contains('cursor'));
    ok('S37 the input value is untouched', input.value === '');
  }
  {
    // A modified key is never hijacked.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'j', metaKey: true });
    ok('S37 a meta key is left alone', !s.list.rows[1].classList.contains('cursor'));
    doc.dispatch('keydown', { key: 'c', ctrlKey: true });
    eq('S37 ctrl+c is left alone', win.location.href, undefined);
  }
  {
    // A modal dialog owns the keyboard.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    s.dlg.dialog.showModal();
    doc.dispatch('keydown', { key: 'j' });
    ok('S37 keys are inert while a dialog is open', !s.list.rows[1].classList.contains('cursor'));
  }
  {
    // The off-switch in localStorage silences the whole layer.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    win.localStorage = { getItem: () => 'off' };
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'j' });
    ok('S37 the off-flag silences the layer', !s.list.rows[1].classList.contains('cursor'));
  }
  {
    // The login page (no .topbar) must not run global keys.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildLogin(doc);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'c' });
    doc.dispatch('keydown', { key: '?' });
    eq('S37 the login page ignores global keys', win.location.href, undefined);
  }

  // S38 — the list cursor.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'j' });
    ok('S38 j starts the cursor on row 1', s.list.rows[0].classList.contains('cursor'));
    doc.dispatch('keydown', { key: 'j' });
    ok('S38 j moves to row 2', s.list.rows[1].classList.contains('cursor'));
    ok('S38 the previous row loses it', !s.list.rows[0].classList.contains('cursor'));
    doc.dispatch('keydown', { key: 'k' });
    ok('S38 k moves back up', s.list.rows[0].classList.contains('cursor'));
    doc.dispatch('keydown', { key: 'k' });
    ok('S38 k clamps at the top', s.list.rows[0].classList.contains('cursor'));
    doc.dispatch('keydown', { key: 'j' });
    doc.dispatch('keydown', { key: 'j' });
    doc.dispatch('keydown', { key: 'j' });
    ok('S38 j clamps at the bottom', s.list.rows[2].classList.contains('cursor'));
    ok('S38 the cursor focuses the row link', s.list.rows[2]._link._focused);
    doc.dispatch('keydown', { key: 'Escape' });
    ok('S38 Esc clears the cursor', !s.list.rows[2].classList.contains('cursor'));
  }
  {
    // Enter/o on the cursor row runs the same ack as a mouse click.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'j' });
    doc.dispatch('keydown', { key: 'o' });
    ok('S38 o opens the cursor row', s.list.rows[0].classList.contains('opening'));
    ok('S38 the bar runs', s.bar.classList.contains('loading'));
    win.dispatch('pageshow');
    doc.dispatch('keydown', { key: 'j' });
    doc.dispatch('keydown', { key: 'Enter' });
    ok('S38 Enter opens too', s.list.rows[0].classList.contains('opening'));
  }

  // S39 — row actions from the cursor.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const calls = [];
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, message: '' }) }), calls);
    doc.dispatch('keydown', { key: 'j' });
    // s toggles read on the cursor row (row 1 is unread -> markRead).
    doc.dispatch('keydown', { key: 's' });
    eq('S39 s posts the read toggle', calls[0] && calls[0].url, '?page=markRead');
    // ! reports spam and the cursor advances to the row that took its place.
    doc.dispatch('keydown', { key: '!' });
    eq('S39 ! posts spam', calls[1] && calls[1].url, '?page=spam');
    ok('S39 ! advances the cursor', s.list.rows[1].classList.contains('cursor'));
    // e archives into the data-kb-map archive folder and advances.
    doc.dispatch('keydown', { key: 'e' });
    const moveBody = calls[2] && calls[2].body;
    ok('S39 e posts a move to the mapped archive', typeof moveBody === 'string' && moveBody.indexOf('dest=Archive') !== -1);
    ok('S39 e advances the cursor past the filed row', s.list.rows[2].classList.contains('cursor'));
  }
  {
    // No archive folder -> a toast, not a move.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const calls = [];
    const s = buildKbListing(doc, 3, { i: 'INBOX' });
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);
    doc.dispatch('keydown', { key: 'j' });
    doc.dispatch('keydown', { key: 'e' });
    const errToast = doc.querySelector('.toast-error');
    ok('S39 e without an archive folder toasts', errToast && errToast.textContent.indexOf('No archive folder') !== -1);
    eq('S39 and posts nothing', calls.length, 0);
  }
  {
    // v opens the move dialog; # opens the confirm and its confirm advances.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const calls = [];
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);
    doc.dispatch('keydown', { key: 'j' });
    doc.dispatch('keydown', { key: 'v' });
    ok('S39 v opens the move dialog', s.mdlg.dialog.open);
    // Close it again: a modal owns the keyboard, so # must be tested with no
    // dialog open.
    s.mdlg.dialog.close();
    doc.dispatch('keydown', { key: '#' });
    ok('S39 # opens the confirm dialog', s.dlg.dialog.open);
    // Confirm -> the original _run runs (a delete post) and the cursor advances.
    s.dlg.confirmBtn.dispatch('click');
    eq('S39 the confirm deletes', calls[0] && calls[0].url, '?page=delete');
    ok('S39 the cursor advances after confirm', s.list.rows[1].classList.contains('cursor'));
  }

  // S40 — reader keys, against the open message.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const r = buildKbReader(doc, {
      'data-uid': '7', 'data-folder': 'INBOX',
      'data-reply': '?page=reply&uid=7&folder=INBOX',
      'data-replyall': '?page=replyAll&uid=7&folder=INBOX',
      'data-forward': '?page=forward&uid=7&folder=INBOX',
      'data-prev': '?page=read&uid=6&folder=INBOX',
      'data-next': '?page=read&uid=8&folder=INBOX',
    });
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    eq('S40 the reader carries its keyboard hooks', r.card.getAttribute('data-uid'), '7');
    doc.dispatch('keydown', { key: 'r' });
    eq('S40 r follows the reply link', win.location.href, '?page=reply&uid=7&folder=INBOX');
    doc.dispatch('keydown', { key: 'f' });
    eq('S40 f follows the forward link', win.location.href, '?page=forward&uid=7&folder=INBOX');
    doc.dispatch('keydown', { key: 'k' });
    eq('S40 k opens the row above (data-prev)', win.location.href, '?page=read&uid=6&folder=INBOX');
    doc.dispatch('keydown', { key: 'j' });
    eq('S40 j opens the row below (data-next)', win.location.href, '?page=read&uid=8&folder=INBOX');
    doc.dispatch('keydown', { key: '#' });
    ok('S40 # opens the delete confirm', r.dlg.dialog.open);
  }
  {
    // Reader s posts markUnread and leaves for the folder; Esc/u use the Back link.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const calls = [];
    const r = buildKbReader(doc, { 'data-uid': '7', 'data-folder': 'INBOX' });
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), calls);
    doc.dispatch('keydown', { key: 's' });
    eq('S40 s posts markUnread', calls[0] && calls[0].url, '?page=markUnread');
    await settle();
    eq('S40 s leaves for the folder', win.location.href, '?page=folder&folder=INBOX');
    doc.dispatch('keydown', { key: 'u' });
    eq('S40 u returns via the Back link', win.location.href, '?page=folder&folder=INBOX');
  }

  // S41 — navigation keys.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, html: '<section class="mailbox"></section>', title: 'x' }) }), []);
    doc.dispatch('keydown', { key: 'g' });
    doc.dispatch('keydown', { key: 'i' });
    await settle();
    eq('S41 g i goes to Inbox', win.location.href, '?page=folder&folder=INBOX');
    doc.dispatch('keydown', { key: 'g' });
    doc.dispatch('keydown', { key: 't' });
    await settle();
    eq('S41 g t goes to Sent', win.location.href, '?page=folder&folder=Sent');
  }
  {
    // An unknown folder after g toasts and resets the prefix.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3, { i: 'INBOX' });
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'g' });
    doc.dispatch('keydown', { key: 'd' });
    const errToast = doc.querySelector('.toast-error');
    ok('S41 g to an unmapped folder toasts', errToast && errToast.textContent.indexOf('No such folder') !== -1);
    // After the prefix reset, d no longer navigates.
    doc.dispatch('keydown', { key: 'g' });
    doc.dispatch('keydown', { key: 'd' });
    eq('S41 the prefix reset, so a stray d is inert', win.location.href, undefined);
  }
  {
    // A stale g times out instead of waiting forever.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    let savedTimer = null;
    win.setTimeout = (fn) => { savedTimer = fn; return 1; };
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'g' });
    savedTimer();
    doc.dispatch('keydown', { key: 'i' });
    eq('S41 a timed-out g leaves i inert', win.location.href, undefined);
  }
  {
    // g + punctuation falls through: g then / focuses search; / and u and c work.
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const search = buildSearch(doc);
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'g' });
    doc.dispatch('keydown', { key: '/' });
    ok('S41 g then / focuses search', search.input._focused);
    doc.dispatch('keydown', { key: '/' });
    ok('S41 / focuses search', search.input._focused);
    doc.dispatch('keydown', { key: 'u' });
    eq('S41 u on a listing goes to Inbox', win.location.href, '?page=inbox');
    doc.dispatch('keydown', { key: 'c' });
    eq('S41 c composes', win.location.href, '?page=compose');
  }

  // S42 — the ?-sheet opens, closes, and disables shortcuts.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    let stored = null;
    win.localStorage = { getItem: () => stored, setItem: (k, v) => { stored = v; } };
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: '?' });
    ok('S42 ? opens the sheet', s.kbd.dialog.open);
    s.kbd.cancelBtn.dispatch('click');
    ok('S42 Close closes the sheet', !s.kbd.dialog.open);
    // The disable link writes the off-flag and reloads.
    const disable = doc.createElement('a');
    disable.setAttribute('data-kb-disable', '');
    disable.setAttribute('href', '#');
    s.kbd.dialog.appendChild(disable);
    disable.dispatch('click');
    eq('S42 the disable link sets the off-flag', stored, 'off');
    ok('S42 and reloads', win._reloads > 0);
  }

  // S43 — composer Cmd+Enter sends, but only once per send.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const c = buildCompose(doc);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    let sendClicks = 0;
    c.send.click = () => { sendClicks++; };
    c.form.dispatch('keydown', null, { metaKey: true, key: 'Enter' });
    eq('S43 Cmd+Enter clicks Send', sendClicks, 1);
    c.form.dispatch('keydown', null, { ctrlKey: true, key: 'Enter' });
    eq('S43 Ctrl+Enter clicks Send too', sendClicks, 2);
    c.form.dispatch('keydown', null, { key: 'Enter' });
    eq('S43 a plain Enter does nothing', sendClicks, 2);
    c.form.dispatch('keydown', null, { metaKey: true, key: 'Enter', repeat: true });
    eq('S43 an auto-repeated Cmd+Enter is ignored', sendClicks, 2);
    c.send.classList.add('busy');
    c.form.dispatch('keydown', null, { metaKey: true, key: 'Enter' });
    eq('S43 a second send while busy is refused', sendClicks, 2);
  }

  // S44 — / focuses search and must not also type itself into the field it just
  // focused. The keydown default action would insert the character otherwise.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const search = buildSearch(doc);
    buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    const ev = doc.dispatch('keydown', { key: '/' });
    ok('S44 / focuses search', search.input._focused);
    ok('S44 / is prevented so it is not typed into the field', ev.defaultPrevented);
  }

  // S45 — the account-menu Help item opens the ?-sheet.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    const help = doc.createElement('button');
    help.setAttribute('data-kb-help', '');
    doc.add(help);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    help.dispatch('click');
    ok('S45 the Help menu item opens the ?-sheet', s.kbd.dialog.open);
  }

  // S46 — ArrowUp/ArrowDown are aliases for k/j, and they must not scroll the
  // page while they walk the cursor.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    const evDown = doc.dispatch('keydown', { key: 'ArrowDown' });
    ok('S46 ArrowDown starts the cursor', s.list.rows[0].classList.contains('cursor'));
    ok('S46 ArrowDown is prevented (no page scroll)', evDown.defaultPrevented);
    doc.dispatch('keydown', { key: 'ArrowDown' });
    ok('S46 ArrowDown moves down', s.list.rows[1].classList.contains('cursor'));
    const evUp = doc.dispatch('keydown', { key: 'ArrowUp' });
    ok('S46 ArrowUp moves back up', s.list.rows[0].classList.contains('cursor'));
    ok('S46 ArrowUp is prevented too', evUp.defaultPrevented);
    doc.dispatch('keydown', { key: 'ArrowUp' });
    ok('S46 ArrowUp clamps at the top', s.list.rows[0].classList.contains('cursor'));
  }

  // S47 — a user who disabled shortcuts must be able to get them back. The
  // ?-sheet stays reachable, and its toggle flips both ways.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    const toggle = doc.createElement('a');
    toggle.setAttribute('data-kb-disable', '');
    toggle.textContent = 'Disable shortcuts';
    s.kbd.dialog.appendChild(toggle);
    let stored = 'off'; // start disabled
    win.localStorage = {
      getItem: () => stored,
      setItem: (k, v) => { stored = v; },
      removeItem: () => { stored = null; },
    };
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'j' });
    ok('S47 j is inert while shortcuts are off', !s.list.rows[0].classList.contains('cursor'));
    doc.dispatch('keydown', { key: '?' });
    ok('S47 ? still opens the sheet when shortcuts are off', s.kbd.dialog.open);
    eq('S47 the toggle offers to re-enable', toggle.textContent, 'Enable shortcuts');
    toggle.dispatch('click');
    eq('S47 clicking it clears the off-flag', stored, null);
    ok('S47 and reloads', win._reloads > 0);
  }

  // S48 — a keyboard folder jump acknowledges the wait the way a sidebar click
  // does: the folder's own link spins, and the chrome bar runs.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    const links = buildKbSidebar(doc, ['INBOX', 'Sent']);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, html: '<section class="mailbox"></section>', title: 'x' }) }), []);
    doc.dispatch('keydown', { key: 'g' });
    doc.dispatch('keydown', { key: 't' });
    ok('S48 g t spins the Sent folder link', links.Sent.classList.contains('busy'));
    ok('S48 g t does not spin the Inbox link', !links.INBOX.classList.contains('busy'));
    ok('S48 g t runs the chrome bar', s.bar.classList.contains('loading'));
  }

  // S49 — with no sidebar (reader/settings), a folder jump still runs the bar.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const s = buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, html: '<section class="mailbox"></section>', title: 'x' }) }), []);
    doc.dispatch('keydown', { key: 'g' });
    doc.dispatch('keydown', { key: 'i' });
    ok('S49 g i with no sidebar still runs the bar', s.bar.classList.contains('loading'));
  }

  // S50 — reader navigation shortcuts spin the control a click would: Back for
  // u, the toolbar Reply for r, and the bar for a bare message jump.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const r = buildKbReader(doc, {
      'data-uid': '7', 'data-folder': 'INBOX',
      'data-reply': '?page=reply&uid=7&folder=INBOX',
      'data-prev': '?page=read&uid=6&folder=INBOX',
      'data-next': '?page=read&uid=8&folder=INBOX',
    });
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'u' });
    ok('S50 u spins the reader Back link', r.r.back.classList.contains('busy'));
    doc.dispatch('keydown', { key: 'r' });
    ok('S50 r spins the toolbar Reply link', r.r.reply.classList.contains('busy'));
    doc.dispatch('keydown', { key: 'j' });
    ok('S50 j runs the bar for a message jump', r.bar.classList.contains('loading'));
  }

  // S51 — Esc on the composer discards, like the Discard control.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const c = buildCompose(doc);
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: 'Escape' });
    eq('S51 Esc discards the composer', win.location.href, '?page=inbox');
  }

  // S52 — but an open suggestion dropdown is closed first, so Esc never discards
  // a message the user was only trying to stop completing an address.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildCompose(doc);
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    buildAutocomplete(doc);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    const dd = doc.querySelector('.ac-dropdown');
    dd.style.display = 'block';
    doc.dispatch('keydown', { key: 'Escape' });
    eq('S52 Esc closes an open suggestion dropdown', dd.style.display, 'none');
    eq('S52 and does not discard the composer', win.location.href, undefined);
  }

  // S53 — y triggers the sync button, the same force-sync a click runs.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    const bar = doc.createElement('div'); bar.className = 'nav-progress'; doc.add(bar);
    const calls = [];
    buildSync(doc, 'INBOX');
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true, changed: 0, synced: 'updated' }) }), calls);
    doc.dispatch('keydown', { key: 'y' });
    await settle();
    eq('S53 y triggers a sync', calls[0] && calls[0].url, '?page=sync');
  }

  // S54 — Esc unfocuses a focused field (the search box) instead of acting on
  // the page behind it.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const search = buildSearch(doc);
    buildKbListing(doc, 3);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    doc.dispatch('keydown', { key: '/' });
    ok('S54 / focuses search', search.input._focused);
    doc.dispatch('keydown', { key: 'Escape' });
    ok('S54 Esc unfocuses the search input', !search.input._focused);
    eq('S54 Esc does not navigate away', win.location.href, undefined);
  }

  // S55 — Cmd/Ctrl+S on the composer saves the draft.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const c = buildCompose(doc);
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    let drafts = 0; c.draft.click = () => { drafts++; };
    doc.dispatch('keydown', { metaKey: true, key: 's' });
    eq('S55 Cmd+S clicks Save draft', drafts, 1);
    doc.dispatch('keydown', { ctrlKey: true, key: 's' });
    eq('S55 Ctrl+S does too', drafts, 2);
  }

  // S56 — Cmd/Ctrl+Shift+A on the composer opens the file picker.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const c = buildCompose(doc);
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    let picks = 0; c.file.click = () => { picks++; };
    doc.dispatch('keydown', { metaKey: true, shiftKey: true, key: 'A' });
    eq('S56 Cmd+Shift+A opens the file picker', picks, 1);
  }

  // S57 — Cmd/Ctrl+S saves settings; Cmd/Ctrl+Shift+X clears the local cache.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    const s = buildSettings(doc);
    const dlg = buildDialog(doc, 'delete-dialog');
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({ ok: true }) }), []);
    let saves = 0; s.save.click = () => { saves++; };
    doc.dispatch('keydown', { metaKey: true, key: 's' });
    eq('S57 Cmd+S clicks Save', saves, 1);
    doc.dispatch('keydown', { metaKey: true, shiftKey: true, key: 'X' });
    ok('S57 Cmd+Shift+X opens the clear-cache confirm', dlg.dialog.open);
  }

  // Sidebar counters as view_sidebar() renders them: one link per folder, a
  // badge span only on the counted ones (hidden when 0).
  function buildCounters(doc, spec) {
    const side = doc.createElement('div'); side.className = 'sidebar';
    const out = {};
    Object.keys(spec).forEach(name => {
      const a = doc.createElement('a');
      a.className = 'folder-link';
      a.setAttribute('href', '?page=folder&folder=' + name);
      a.setAttribute('data-folder', name);
      if (spec[name]) {
        const b = doc.createElement('span');
        b.className = 'folder-count';
        b.setAttribute('data-kind', spec[name][0]);
        b.textContent = String(spec[name][1]);
        if (!spec[name][1]) b.setAttribute('hidden', '');
        a.appendChild(b);
        a._badge = b;
      }
      side.appendChild(a);
      out[name] = a;
    });
    doc.add(side);
    return out;
  }

  // S58 — marking read / unread bumps the folder badge at once; a failure undoes it.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const side = buildCounters(doc, { INBOX: ['unseen', 1] });
    buildList(doc, 'INBOX', 2);
    let fail = false;
    boot(doc, win, () => fail ? { json: () => Promise.resolve({ success: false }) }
                              : { json: () => Promise.resolve({ success: true }) }, []);
    const b = side.INBOX._badge;
    win.toggleRead(1, 'INBOX');
    eq('S58 reading the unread row decrements the badge', b.textContent, '0');
    ok('S58 a zero badge is hidden', b.hasAttribute('hidden'));
    await settle();
    win.toggleRead(1, 'INBOX');
    eq('S58 marking it unread increments the badge', b.textContent, '1');
    ok('S58 a non-zero badge is shown', !b.hasAttribute('hidden'));
    await settle();
    fail = true;
    win.toggleRead(1, 'INBOX');
    await settle(); await settle();
    eq('S58 a failed toggle restores the badge', b.textContent, '1');
  }

  // S59 — removing an unread row decrements the unseen badge; a read row does not.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const side = buildCounters(doc, { INBOX: ['unseen', 3] });
    buildList(doc, 'INBOX', 3);
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true }) }), []);
    win.rowAction(2, 'INBOX', '?page=delete', 'remove');
    eq('S59 removing a read row leaves the unseen badge', side.INBOX._badge.textContent, '3');
    win.rowAction(1, 'INBOX', '?page=delete', 'remove');
    eq('S59 removing an unread row decrements it', side.INBOX._badge.textContent, '2');
  }

  // S60 — a total badge (Drafts) drops on any remove and ignores read toggles.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const side = buildCounters(doc, { Drafts: ['total', 2] });
    buildList(doc, 'Drafts', 2);
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true }) }), []);
    win.toggleRead(1, 'Drafts');
    eq('S60 a read toggle leaves a total badge alone', side.Drafts._badge.textContent, '2');
    win.rowAction(2, 'Drafts', '?page=delete', 'remove');
    eq('S60 removing any row decrements a total badge', side.Drafts._badge.textContent, '1');
  }

  // S61 — the sync response repaints every badge from the server counts.
  {
    const doc = makeDoc(); buildMeta(doc); buildSync(doc, 'INBOX');
    const win = makeWin();
    const side = buildCounters(doc, { INBOX: ['unseen', 1], Junk: ['unseen', 0], Sent: null });
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({
      ok: true, synced: 'Just now', changed: 0,
      counts: { INBOX: 5, Junk: 2, Sent: 9 },
    }) }), []);
    doc.querySelector('.sync-btn').dispatch('click');
    await settle(); await settle();
    eq('S61 sync repaints the inbox badge', side.INBOX._badge.textContent, '5');
    eq('S61 sync repaints a hidden badge', side.Junk._badge.textContent, '2');
    ok('S61 and shows it', !side.Junk._badge.hasAttribute('hidden'));
    eq('S61 a folder without a badge gets none', side.Sent.children.length, 0);
  }

  // S62 — the poll scheduler. One chain for the page, the interval taken from
  // the topbar as sent, 0 = the check is off, a reschedule after success and
  // after failure, and a signed-out page that stops asking.
  {
    // a) the period, and only one chain, whichever way the page is wired.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const win = makeWin(); const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply(), calls);
      await clock.tick(59000);
      eq('S62 the chain waits for the whole period', calls.length, 0);
      await clock.tick(1000);
      eq('S62 the first tick lands on the minute', calls.length, 1);
      eq('S62 it is a sync POST', calls[0].url, '?page=sync');
      ok('S62 naming the open folder', calls[0].body.indexOf('folder=INBOX') !== -1);
      ok('S62 and not forcing it', calls[0].body.indexOf('force=1') === -1);
      await clock.tick(60000);
      eq('S62 the chain reschedules itself', calls.length, 2);
      doc.querySelector('.sync-btn').dispatch('click');
      await settle();
      eq('S62 a chip click is one request, not one chain', calls.length, 3);
      await clock.tick(60000);
      eq('S62 and one tick is one request', calls.length, 4);
      clock.restore();
    }

    // a2) the number on the topbar is the period, exactly. There is no floor in
    // the client: the server owns the interval, and a second copy of it here
    // would be a second thing to forget.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 300);
      const win = makeWin(); const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply(), calls);
      await clock.tick(120000);
      eq('S62 a five minute period is not shortened', calls.length, 0);
      await clock.tick(180000);
      eq('S62 it fires at five minutes', calls.length, 1);
      clock.restore();
    }

    // b) the check turned off, and a page with no topbar (the login page), never poll.
    for (const [name, period] of [['the check off', 0], ['no topbar', undefined]]) {
      const doc = makeDoc();
      if (period === undefined) { buildMeta(doc); buildSync(doc, 'INBOX'); }
      else buildPollPage(doc, 'INBOX', period);
      const win = makeWin(); const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply(), calls);
      await clock.tick(600000);
      eq('S62 ' + name + ' never polls', calls.length, 0);
      clock.restore();
    }

    // b2) Turning the check off stops the poll, not the chip: that button is the
    // only way left to check, so it must still work.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 0);
      const win = makeWin(); const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply(), calls);
      await clock.tick(600000);
      eq('S62 the check off never polls on its own', calls.length, 0);
      doc.querySelector('.sync-btn').dispatch('click');
      await settle();
      eq('S62 and the chip still syncs by hand', calls.length, 1);
      ok('S62 by forcing it', calls[0].body.indexOf('force=1') !== -1);
      await clock.tick(600000);
      eq('S62 with no chain left behind it', calls.length, 1);
      clock.restore();
    }

    // c) a failure is reported and the chain still runs.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const win = makeWin(); const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => { throw new Error('offline'); }, calls);
      await clock.tick(60000);
      eq('S62 an unreachable server is still one POST', calls.length, 1);
      eq('S62 and the chip says Offline', doc.querySelector('.sync-text').textContent, 'Offline');
      await clock.tick(60000);
      eq('S62 the chain survives the failure', calls.length, 2);
      clock.restore();
    }

    // d) a server-side refusal is reported and the chain still runs.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const win = makeWin(); const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: false, error: 'boom' } }), calls);
      await clock.tick(60000);
      eq('S62 a refused sync is reported', doc.querySelector('.sync-text').textContent, 'boom');
      await clock.tick(60000);
      eq('S62 and the chain still runs', calls.length, 2);
      clock.restore();
    }

    // e) signed out, three ways: 401, 403, and a redirect to the login page.
    // The first stops the poll for good, and the chip says why.
    for (const [name, reply] of [
      ['a 401', syncReply({ status: 401, body: { ok: false, error: 'Signed out.' } })],
      ['a 403', syncReply({ status: 403, body: { ok: false, error: 'Signed out.' } })],
      ['a redirect to the login page', syncReply({ status: 200, redirected: true, body: { ok: true, changed: 0, arrivals: 0 } })],
    ]) {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const win = makeWin(); const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => reply, calls);
      await clock.tick(60000);
      eq('S62 ' + name + ' is one POST', calls.length, 1);
      eq('S62 and the chip says Signed out', doc.querySelector('.sync-text').textContent, 'Signed out');
      await clock.tick(3600000);
      eq('S62 ' + name + ' stops the poll for good', calls.length, 1);
      clock.restore();
    }

    // f) a stale listing syncs the moment it opens. The cache survives logout, so
    // a fresh login paints old rows; waiting a whole period for them is the
    // stale-data bug. A fresh chip still waits its period (covered above).
    {
      const doc = makeDoc(); const page = buildPollPage(doc, 'INBOX', 60);
      page.chip.setAttribute('data-sync-stale', '1');
      const win = makeWin(); const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply(), calls);
      await settle();
      eq('S62 a stale listing syncs at boot', calls.length, 1);
      ok('S62 by naming the open folder', calls[0].body.indexOf('folder=INBOX') !== -1);
      ok('S62 without forcing it', calls[0].body.indexOf('force=1') === -1);
      await clock.tick(60000);
      eq('S62 and the chain carries on', calls.length, 2);
      clock.restore();
    }
  }

  // S63 — new mail reloads the open listing, but only when the page is idle.
  // A refresh that would throw away work is deferred, not dropped.
  {
    // a) idle, on the current URL: reload that URL, do not rebuild one.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const win = makeWin();
      win.location.href = '?page=folder&folder=INBOX';
      const before = win._hrefs;
      const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: '1 new', changed: 1, arrivals: 0 } }), calls);
      await clock.tick(60000);
      eq('S63 it reloads the URL it is already on', win.location.href, '?page=folder&folder=INBOX');
      eq('S63 by assigning href once', win._hrefs, before + 1);
      eq('S63 and does not re-fetch the listing', calls.length, 1);
      clock.restore();
    }

    // b) each state where a refresh would lose something. The tick is deferred
    // instead, and the next tick swaps even when it has nothing new to report.
    // Each entry returns the step that puts the page back, so the retry is
    // pinned to the guard and not to the passage of time.
    const guards = [
      ['a dialog is open', d => {
        const g = d.createElement('dialog'); g.setAttribute('open', ''); d.add(g);
        return () => g.removeAttribute('open');
      }],
      ['a row is mid-remove', d => {
        const r = d.createElement('div'); r.className = 'row-gone'; d.add(r);
        return () => r.classList.remove('row-gone');
      }],
      ['a navigation is in flight', d => {
        const a = d.createElement('a'); a.className = 'busy'; d.add(a);
        return () => a.classList.remove('busy');
      }],
      ['a row is opening', d => {
        const r = d.createElement('div'); r.className = 'opening'; d.add(r);
        return () => r.classList.remove('opening');
      }],
      ['the list is scrolled', null, w => {
        w.scrollY = 400;
        return () => { w.scrollY = 0; };
      }],
['page 2 is open', null, w => {
        w.location.search = '?page=folder&folder=INBOX&p=2';
        return () => { w.location.search = ''; };
      }],
      // Selected rows would be gone after the swap, along with the bar and the
      // count. Selected by real dispatched clicks, not by poking pickSet:
      // syncCanSwap() is module-private, so a direct call would test the
      // predicate and not the wiring that has to reach it.
      ['rows are selected', null, null, d => {
        buildList(d, 'INBOX', 3, d.querySelector('.mailbox'));
        d.querySelector('.mail-pick[data-pick-uid="2"]').click();
        return () => {
          const box = d.querySelector('.mail-pick[data-pick-uid="2"]');
          box.checked = false;
          box.dispatch('change', box, {});
        };
      }],
    ];
    // The optional fourth slot runs AFTER boot and returns the release. State that
    // the app reads by itself (a class, a scroll offset, the URL) can be set
    // before boot; state that only exists once the handlers are wired -- a
    // picked row -- cannot, or pickReset() wipes it on the way in.
    for (const [name, inDoc, inWin, postBoot] of guards) {
      const doc = makeDoc(); const page = buildPollPage(doc, 'INBOX', 60);
      const win = makeWin();
      win.location.href = '?page=folder&folder=INBOX';
      const before = win._hrefs;
      let release = inDoc ? inDoc(doc, page) : (inWin ? inWin(win) : null);
      const calls = [];
      let step = 0;
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: 'Just now', changed: ++step === 1 ? 1 : 0, arrivals: 0 } }), calls);
      if (postBoot) release = postBoot(doc, page);
      await clock.tick(60000);
      eq('S63 ' + name + ' is not refreshed over', win._hrefs, before);
      release();
      await clock.tick(60000);
      eq('S63 the deferred refresh lands on the next tick', win._hrefs, before + 1);
      await clock.tick(60000);
      eq('S63 and only once, not on every tick', win._hrefs, before + 1);
      clock.restore();
    }

    // c) the keyboard cursor counts as work in progress.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const list = doc.createElement('div'); list.className = 'mail-list';
      const row = doc.createElement('div'); row.className = 'mail unread';
      row.setAttribute('data-uid', '1');
      row.setAttribute('data-idx', '0');
      const link = doc.createElement('a'); link.className = 'mail-main';
      link.setAttribute('href', '?page=read&uid=1&folder=INBOX'); row.appendChild(link);
      list.appendChild(row); doc.add(list);
      const win = makeWin();
      win.location.href = '?page=folder&folder=INBOX';
      const before = win._hrefs;
      const calls = [];
      let step = 0;
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: 'Just now', changed: ++step === 1 ? 1 : 0, arrivals: 0 } }), calls);
      doc.dispatch('keydown', { key: 'j' });
      await clock.tick(60000);
      eq('S63 a keyboard cursor defers the refresh', win._hrefs, before);
      doc.dispatch('keydown', { key: 'Escape' });
      await clock.tick(60000);
      eq('S63 and the next tick gets it', win._hrefs, before + 1);
      clock.restore();
    }

    // d) a folder swap during a request must not drag the user back: the
    // response names the folder it synced, and the current one wins.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const win = makeWin();
      win.location.href = '?page=folder&folder=INBOX';
      const before = win._hrefs;
      const calls = [];
      let gate = null;
      const clock = fakeTimers(win);
      boot(doc, win, () => new Promise(res => { gate = () => res(syncReply({ body: { ok: true, synced: '1 new', changed: 1, arrivals: 0 } })); }), calls);
      await clock.tick(60000);
      eq('S63 the tick is in flight', calls.length, 1);
      // The user switches folders while it is out.
      doc.querySelector('.sync').setAttribute('data-folder', 'Archive');
      gate(); await settle(); await settle();
      eq('S63 a stale response never reloads the new folder', win._hrefs, before);
      clock.restore();
    }

    // e) a page with no open listing -- the reader, compose, settings -- has
    // nothing to refresh, and reloading the composer would throw away a draft.
    for (const [name, cls] of [['the composer', 'compose-form'], ['the reader', 'actions-bar'], ['settings', 'settings-form']]) {
      const doc = makeDoc();
      buildMeta(doc); buildTopbar(doc, 60);
      const body = doc.createElement('div'); body.className = cls; doc.add(body);
      const toasts = doc.createElement('div'); toasts.className = 'toast-container'; doc.add(toasts);
      const win = makeWin();
      win.location.href = '?page=compose';
      const before = win._hrefs;
      const calls = [];
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: '1 new', changed: 1, arrivals: 0 } }), calls);
      await clock.tick(60000);
      eq('S63 ' + name + ' polls', calls.length, 1);
      eq('S63 and is never reloaded', win._hrefs, before);
      await clock.tick(60000);
      eq('S63 nor on a later tick', win._hrefs, before);
      clock.restore();
    }
  }

  // S64 — the title carries the Inbox unread count, and applying it twice
  // changes nothing.
  {
    // a) the pure function, applied on top of a title that already has one.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const win = makeWin();
      boot(doc, win, () => syncReply(), []);
      eq('S64 a count prefixes the title', win.titleWithCount('Inbox — Emmmail', 3), '(3) Inbox — Emmmail');
      eq('S64 one message still reads naturally', win.titleWithCount('Inbox — Emmmail', 1), '(1) Inbox — Emmmail');
      eq('S64 zero is no prefix', win.titleWithCount('Inbox — Emmmail', 0), 'Inbox — Emmmail');
      eq('S64 an old count is replaced, not stacked', win.titleWithCount('(3) Inbox — Emmmail', 4), '(4) Inbox — Emmmail');
      eq('S64 and it is dropped at zero', win.titleWithCount('(3) Inbox — Emmmail', 0), 'Inbox — Emmmail');
    }

    // b) the live title: at start, from the server's counts, and from the
    // optimistic read toggle, which is the moment a user would notice.
    {
      const doc = makeDoc(); const p = buildPollPage(doc, 'INBOX', 60);
      doc.title = 'Inbox — Emmmail';
      const calls = [];
      const win = makeWin();
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: 'Just now', changed: 0, arrivals: 0, counts: { INBOX: 2 } } }), calls);
      eq('S64 no unread, no prefix', doc.title, 'Inbox — Emmmail');
      await clock.tick(60000);
      eq('S64 the count follows the server', doc.title, '(2) Inbox — Emmmail');
      doc.querySelector('.sync-btn').dispatch('click');
      await settle();
      eq('S64 and the badge behind it', p.cnt.textContent, '2');
      ok('S64 which is shown', !p.cnt.hasAttribute('hidden'));
      clock.restore();
    }

    // c) the same after an optimistic toggle, with no server round trip.
    {
      const doc = makeDoc(); const p = buildPollPage(doc, 'INBOX', 60);
      p.cnt.textContent = '1'; p.cnt.removeAttribute('hidden');
      doc.title = '(1) Inbox — Emmmail';
      buildList(doc, 'INBOX', 1);
      const win = makeWin();
      boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true }) }), []);
      win.toggleRead(1, 'INBOX');
      eq('S64 a read toggle drops the count', doc.title, 'Inbox — Emmmail');
      win.rowAction(1, 'INBOX', '?page=markUnread', 'flag');
      eq('S64 and marking unread brings it back', doc.title, '(1) Inbox — Emmmail');
    }
  }

  // S65 — notifications. Opt-in, Inbox only, a count and nothing else, and only
  // for a tab the user is not looking at.
  {
    // a) not opted in, nothing is sent.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = true;
      const N = makeNotification({ permission: 'granted' });
      const win = makeWin(); win.Notification = N; win.localStorage = storageWith({});
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: '1 new', changed: 1, arrivals: 1 } }), []);
      await clock.tick(60000);
      eq('S65 nothing without the opt-in', N.made.length, 0);
      clock.restore();
    }

    // b) opted in, granted, backgrounded: one notification, a fixed tag so a
    // second tab or a second poll replaces it instead of stacking.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = true;
      const N = makeNotification({ permission: 'granted' });
      const win = makeWin(); win.Notification = N;
      win.localStorage = storageWith({ 'emmmail-notify': 'on' });
      let arrivals = 1;
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: 'Just now', changed: 1, arrivals } }), []);
      await clock.tick(60000);
      eq('S65 one notification', N.made.length, 1);
      eq('S65 with a fixed tag', N.made[0]._init.tag, 'emmmail-inbox');
      eq('S65 and the count, nothing more', N.made[0]._init.body, '1 new message');
      arrivals = 2;
      await clock.tick(60000);
      eq('S65 a second poll replaces it', N.made.length, 2);
      ok('S65 by closing the first', N.made[0]._closed);
      eq('S65 and the total runs on', N.made[1]._init.body, '3 new messages');
      eq('S65 with the same tag', N.made[1]._init.tag, 'emmmail-inbox');
      clock.restore();
    }

    // c) the tab is only unfocused, not hidden: still a background tab.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = false; doc._focused = false;
      const N = makeNotification({ permission: 'granted' });
      const win = makeWin(); win.Notification = N;
      win.localStorage = storageWith({ 'emmmail-notify': 'on' });
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: '1 new', changed: 1, arrivals: 1 } }), []);
      await clock.tick(60000);
      eq('S65 an unfocused window counts as background', N.made.length, 1);
      clock.restore();
    }

    // d) a visible, focused tab gets nothing: the user is already looking at it.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = false; doc._focused = true;
      const N = makeNotification({ permission: 'granted' });
      const win = makeWin(); win.Notification = N;
      win.localStorage = storageWith({ 'emmmail-notify': 'on' });
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: '1 new', changed: 1, arrivals: 1 } }), []);
      await clock.tick(60000);
      eq('S65 a visible, focused tab is told nothing', N.made.length, 0);
      clock.restore();
    }

    // e) nothing arrived, so there is nothing to say.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = true;
      const N = makeNotification({ permission: 'granted' });
      const win = makeWin(); win.Notification = N;
      win.localStorage = storageWith({ 'emmmail-notify': 'on' });
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: 'Just now', changed: 0, arrivals: 0 } }), []);
      await clock.tick(60000);
      eq('S65 a quiet poll is silent', N.made.length, 0);
      clock.restore();
    }

    // f) the total resets when the user comes back, so it never reports mail
    // they have already seen.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = true;
      const N = makeNotification({ permission: 'granted' });
      const win = makeWin(); win.Notification = N;
      win.localStorage = storageWith({ 'emmmail-notify': 'on' });
      let arrivals = 4;
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: 'Just now', changed: 1, arrivals } }), []);
      await clock.tick(60000);
      eq('S65 a batch is a count, not a list', N.made[0]._init.body, '4 new messages');
      // The user reads the tab: the count is over and the banner goes away. The
      // poll that the tab coming back triggers finds nothing new, so it is quiet.
      arrivals = 0;
      doc.hidden = false; doc._focused = true;
      doc.dispatch('visibilitychange');
      await settle();
      ok('S65 coming back closes the notification', N.made[0]._closed);
      eq('S65 and a checked tab is told nothing', N.made.length, 1);
      // Back to work, and one more message lands.
      doc.hidden = true;
      arrivals = 1;
      await clock.tick(60000);
      eq('S65 and the count starts again', N.made[1]._init.body, '1 new message');
      clock.restore();
    }

    // g) a click focuses the tab, which is the whole point of the notification.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = true;
      const N = makeNotification({ permission: 'granted' });
      const win = makeWin(); win.Notification = N;
      win.localStorage = storageWith({ 'emmmail-notify': 'on' });
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: '1 new', changed: 1, arrivals: 1 } }), []);
      await clock.tick(60000);
      ok('S65 the notification is clickable', typeof N.made[0].onclick === 'function');
      N.made[0].onclick();
      ok('S65 and a click focuses the tab', win._focused);
      clock.restore();
    }

    // h) permission granted in the app but revoked by the browser, and no
    // Notification at all: the poll must not throw on either.
    for (const [name, N] of [
      ['revoked by the browser', makeNotification({ permission: 'denied' })],
      ['no Notification constructor', undefined],
    ]) {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = true;
      const win = makeWin();
      if (N) win.Notification = N;
      win.localStorage = storageWith({ 'emmmail-notify': 'on' });
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply({ body: { ok: true, synced: '1 new', changed: 1, arrivals: 1 } }), []);
      await clock.tick(60000);
      eq('S65 ' + name + ' is not fatal', doc.querySelector('.sync-text').textContent, '1 new');
      clock.restore();
    }
  }

  // S66 — a forgotten tab stops asking after two hours, so it does not keep the
  // session (and the password in it) alive for ever. The next input restarts it.
  {
    const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
    const calls = [];
    const win = makeWin();
    const clock = fakeTimers(win);
    boot(doc, win, () => syncReply(), calls);
    doc.dispatch('pointerdown');
    await clock.tick(2 * 60 * 60 * 1000 + 1000);
    eq('S66 it polls for two hours', calls.length, 120);
    await clock.tick(10 * 60 * 1000);
    eq('S66 then stops', calls.length, 120);
    doc.dispatch('keydown', { key: 'a' });
    await settle();
    eq('S66 the next input syncs at once', calls.length, 121);
    await clock.tick(60000);
    eq('S66 and the chain is back', calls.length, 122);
    clock.restore();
  }

  // S67 — a page from the back/forward cache, and a tab coming back to the
  // foreground. Both are moments the old page load never got to handle.
  {
    // a) a request left in flight when the page was frozen must not wedge the
    // chain for ever once the page is restored.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const calls = [];
      let gate = null;
      const win = makeWin();
      const clock = fakeTimers(win);
      boot(doc, win, () => new Promise(res => { gate = () => res(syncReply()); }), calls);
      await clock.tick(60000);
      eq('S67 the tick is in flight', calls.length, 1);
      win.dispatch('pageshow', { persisted: true });
      eq('S67 a restored page syncs now', calls.length, 2);
      clock.restore();
    }

    // b) an ordinary pageshow does not add a second chain.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      const calls = [];
      const win = makeWin();
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply(), calls);
      win.dispatch('pageshow', { persisted: false });
      await clock.tick(60000);
      eq('S67 a normal pageshow does not sync early', calls.length, 1);
      clock.restore();
    }

    // c) a tab that comes back to the foreground syncs once, without waiting.
    {
      const doc = makeDoc(); buildPollPage(doc, 'INBOX', 60);
      doc.hidden = true;
      const calls = [];
      const win = makeWin();
      const clock = fakeTimers(win);
      boot(doc, win, () => syncReply(), calls);
      doc.dispatch('visibilitychange');
      eq('S67 a hidden tab waits for the poll', calls.length, 0);
      doc.hidden = false;
      doc.dispatch('visibilitychange');
      eq('S67 a visible one syncs at once', calls.length, 1);
      await settle();
      await clock.tick(60000);
      eq('S67 and the chain continues, not doubles', calls.length, 2);
      clock.restore();
    }
  }

  // S68 — the "This browser" card. It is hidden where notifications cannot
  // work, and it never claims a permission it did not get.
  {
    function settingsPage(win) {
      const doc = makeDoc(); buildMeta(doc);
      const bar = buildTopbar(doc, 60);
      buildList(doc, 'INBOX', 1);
      const card = buildNotifyCard(doc);
      return { doc, bar, card: card.card, btn: card.btn };
    }

    // a) no API, or a page not on HTTPS: no card, because a switch that cannot
    // work is worse than no switch. The stub's query list does not prune a
    // detached node, so the card's own parent is what says whether it is gone.
    for (const [name, patch] of [
      ['no Notification', w => { w.Notification = undefined; }],
      ['not a secure context', w => { w.isSecureContext = false; }],
    ]) {
      const win = makeWin();
      win.Notification = makeNotification({ permission: 'default' });
      patch(win);
      win.localStorage = storageWith({});
      const { doc, bar, card } = settingsPage(win);
      boot(doc, win, () => syncReply(), []);
      eq('S68 the card is gone on ' + name, card.parentNode, null);
      ok('S68 and the poll still runs there', !!bar);
    }

    // b) supported and not yet on: one click asks, and the flag is only set on
    // a real grant, with a test notification to prove the API works at all.
    {
      const win = makeWin();
      const N = makeNotification({ permission: 'default' });
      win.Notification = N; win.localStorage = storageWith({});
      const { doc, btn } = settingsPage(win);
      boot(doc, win, () => syncReply(), []);
      eq('S68 it offers to switch on', btn.textContent, 'Enable notifications');
      btn.dispatch('click');
      await settle(); await settle();
      eq('S68 the browser is asked', N.requested, 1);
      eq('S68 and the flag is set', win.localStorage.getItem('emmmail-notify'), 'on');
      eq('S68 the button now switches off', btn.textContent, 'Turn off notifications');
      ok('S68 and a test notification proves it', N.made.length === 1);
      btn.dispatch('click');
      eq('S68 a second click switches it off', win.localStorage.getItem('emmmail-notify'), null);
      eq('S68 and the button offers it again', btn.textContent, 'Enable notifications');
    }

    // c) already on when the page loads: the button reflects that, and the poll
    // is running, so nothing has to be switched on to start getting mail.
    {
      const win = makeWin();
      const N = makeNotification({ permission: 'granted' });
      win.Notification = N; win.localStorage = storageWith({ 'emmmail-notify': 'on' });
      const { doc, btn } = settingsPage(win);
      boot(doc, win, () => syncReply(), []);
      eq('S68 it reflects the flag on arrival', btn.textContent, 'Turn off notifications');
    }

    // d) the user said no: the flag stays off and the page says where to change
    // it, instead of silently doing nothing on every click.
    {
      const win = makeWin();
      const N = makeNotification({ permission: 'default', grant: 'denied' });
      win.Notification = N; win.localStorage = storageWith({});
      const { doc, btn } = settingsPage(win);
      boot(doc, win, () => syncReply(), []);
      btn.dispatch('click');
      await settle(); await settle();
      eq('S68 a refusal sets no flag', win.localStorage.getItem('emmmail-notify'), null);
      eq('S68 and says where to unblock it', doc.querySelector('.toast').textContent, 'Blocked in browser settings');
      eq('S68 leaving the button on offer', btn.textContent, 'Enable notifications');
    }

    // e) permission granted, but the constructor throws (Android Chrome). The
    // flag must go back off, or every poll throws for the rest of the session.
    {
      const win = makeWin();
      const N = makeNotification({ permission: 'default', throwOnNew: true });
      win.Notification = N; win.localStorage = storageWith({});
      const { doc, btn } = settingsPage(win);
      boot(doc, win, () => syncReply(), []);
      btn.dispatch('click');
      await settle(); await settle();
      eq('S68 a constructor that throws sets no flag', win.localStorage.getItem('emmmail-notify'), null);
      eq('S68 and does not promise a notification', doc.querySelector('.toast').textContent, 'Not supported on this device');
      eq('S68 the button is still on offer', btn.textContent, 'Enable notifications');
    }
  }

  // S69 — the login form remembers the last preset, and with it the six custom
  // server fields, so a return visit signs in against the same servers.
  {
    const CUSTOM_FIELDS = ['imap_server', 'imap_port', 'imap_enc',
      'smtp_server', 'smtp_port', 'smtp_enc'];
    // The login box as view_login() renders it: the preset select, then a hidden
    // block of custom fields (the two encryption ones are selects there too).
    function buildLoginServers(doc, values) {
      const form = doc.createElement('form');
      const email = doc.createElement('input');
      email.type = 'email'; email.id = 'login-email'; email.setAttribute('name', 'email');
      email.value = 'me@example.com';
      const pass = doc.createElement('input');
      pass.type = 'password'; pass.id = 'login-password'; pass.setAttribute('name', 'password');
      pass.focus = function() { this._focused = true; };
      const preset = doc.createElement('select');
      preset.id = 'login-preset'; preset.setAttribute('name', 'preset');
      for (const o of ['gmail', 'yahoo', 'outlook', 'icloud', 'migadu', 'custom']) {
        const opt = doc.createElement('option'); opt.value = o; preset.appendChild(opt);
      }
      preset.value = 'migadu';
      const custom = doc.createElement('div');
      custom.className = 'custom-servers';
      custom.style.display = 'none';
      const fields = {};
      for (const name of CUSTOM_FIELDS) {
        const isSelect = /_enc$/.test(name);
        const el = doc.createElement(isSelect ? 'select' : 'input');
        el.setAttribute('name', name);
        if (isSelect) {
          for (const o of ['ssl', 'tls', 'notls']) {
            const opt = doc.createElement('option'); opt.value = o; el.appendChild(opt);
          }
        }
        el.value = (values && values[name]) || '';
        fields[name] = el;
        custom.appendChild(el);
      }
      const btn = doc.createElement('button');
      btn.type = 'submit'; btn.className = 'btn btn-primary';
      const use = doc.createElement('use'); use.setAttribute('href', '#tabler-arrow-right');
      const svg = doc.createElement('svg'); svg.appendChild(use);
      btn.appendChild(svg);
      form.appendChild(email); form.appendChild(pass); form.appendChild(preset);
      form.appendChild(custom); form.appendChild(btn);
      doc.add(form);
      return { form, email, pass, preset, custom, fields, btn };
    }
    const CUSTOM = { imap_server: 'imap.mail.example', imap_port: '1993', imap_enc: 'notls',
      smtp_server: 'smtp.mail.example', smtp_port: '465', smtp_enc: 'ssl' };
    const quiet = () => ({ ok: true, json: () => Promise.resolve({}) });

    // a) Nothing stored: the server's own selection stands, Custom stays shut.
    {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin(); win.localStorage = storageWith({});
      const f = buildLoginServers(doc);
      boot(doc, win, quiet, []);
      eq('S69 no memory leaves the server default', f.preset.value, 'migadu');
      eq('S69 and Custom stays hidden', f.custom.style.display, 'none');
    }

    // b) A named preset is remembered, and the custom fields stay out of the way.
    {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      win.localStorage = storageWith({ 'emmmail-last-preset': 'icloud' });
      const f = buildLoginServers(doc);
      boot(doc, win, quiet, []);
      eq('S69 the remembered preset is selected', f.preset.value, 'icloud');
      eq('S69 and its custom fields stay hidden', f.custom.style.display, 'none');
    }

    // c) Custom was used, so the preset comes back with all six values.
    {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      win.localStorage = storageWith({ 'emmmail-last-preset': 'custom',
        'emmmail-last-custom': JSON.stringify(CUSTOM) });
      const f = buildLoginServers(doc);
      boot(doc, win, quiet, []);
      eq('S69 Custom is selected', f.preset.value, 'custom');
      eq('S69 and the fields are revealed', f.custom.style.display, 'block');
      for (const k of CUSTOM_FIELDS) eq('S69 ' + k + ' comes back', f.fields[k].value, CUSTOM[k]);
    }

    // d) Submitting saves the preset and the six fields beside the email.
    {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin(); win.localStorage = storageWith({});
      const f = buildLoginServers(doc, CUSTOM);
      f.preset.value = 'custom';
      boot(doc, win, quiet, []);
      f.form.dispatch('submit');
      eq('S69 submit saves the preset', win.localStorage.getItem('emmmail-last-preset'), 'custom');
      const saved = JSON.parse(win.localStorage.getItem('emmmail-last-custom') || '{}');
      for (const k of CUSTOM_FIELDS) eq('S69 submit saves ' + k, saved[k], CUSTOM[k]);
    }

    // e) A locked page renders no preset and no custom fields: submitting must
    // leave the remembered servers alone, not overwrite them with blanks.
    {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      win.localStorage = storageWith({ 'emmmail-last-preset': 'custom',
        'emmmail-last-custom': JSON.stringify(CUSTOM) });
      const form = doc.createElement('form');
      const email = doc.createElement('input');
      email.type = 'email'; email.id = 'login-email'; email.setAttribute('name', 'email');
      email.value = 'me@example.com';
      const pass = doc.createElement('input');
      pass.type = 'password'; pass.id = 'login-password'; pass.setAttribute('name', 'password');
      pass.focus = function() { this._focused = true; };
      const btn = doc.createElement('button');
      btn.type = 'submit'; btn.className = 'btn btn-primary';
      form.appendChild(email); form.appendChild(pass); form.appendChild(btn);
      doc.add(form);
      boot(doc, win, quiet, []);
      form.dispatch('submit');
      eq('S69 a locked submit still saves the email', win.localStorage.getItem('emmmail-last-email'), 'me@example.com');
      eq('S69 a locked submit keeps the remembered preset', win.localStorage.getItem('emmmail-last-preset'), 'custom');
      eq('S69 a locked submit keeps the remembered servers', win.localStorage.getItem('emmmail-last-custom'), JSON.stringify(CUSTOM));
    }
  }

  // S92 — a page with a forced preset has no #login-preset select. The login
  // script must still remember the email, and must leave the previous preset
  // and custom fields alone.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    win.localStorage = storageWith({
      'emmmail-last-preset': 'icloud',
      'emmmail-last-custom': JSON.stringify({ imap_server: 'imap.example.org' })
    });
    const form = doc.createElement('form');
    const email = doc.createElement('input');
    email.type = 'email'; email.id = 'login-email'; email.setAttribute('name', 'email');
    email.value = 'me@abtz.co';
    email.focus = function() { this._focused = true; };
    const pass = doc.createElement('input');
    pass.type = 'password'; pass.id = 'login-password'; pass.setAttribute('name', 'password');
    pass.focus = function() { this._focused = true; };
    const btn = doc.createElement('button');
    btn.type = 'submit'; btn.className = 'btn btn-primary';
    btn.appendChild(doc.createElement('svg'));
    form.appendChild(email); form.appendChild(pass); form.appendChild(btn);
    doc.add(form);
    boot(doc, win, () => ({ ok: true, json: () => Promise.resolve({}) }), []);
    form.dispatch('submit');
    eq('S92 submit still saves the email', win.localStorage.getItem('emmmail-last-email'), 'me@abtz.co');
    eq('S92 a forced preset keeps the remembered preset',
        win.localStorage.getItem('emmmail-last-preset'), 'icloud');
    eq('S92 and keeps the remembered custom fields',
        win.localStorage.getItem('emmmail-last-custom'),
        JSON.stringify({ imap_server: 'imap.example.org' }));
  }

  // S70 — picking rows: the checkbox, the tri-state head, the bar and the count.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildList(doc, 'INBOX', 3);
    const dlg = buildDialog(doc, 'move-dialog', { select: true });
    buildDialog(doc, 'delete-dialog');
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 3 }) }), []);
    const bar = doc.querySelector('.bulk-bar');
    const all = doc.querySelector('[data-pick-all]');
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    const row = i => doc.querySelector('.mail[data-uid="' + i + '"]');

    eq('S70 the bar starts hidden', bar.classList.contains('hidden'), true);
    eq('S70 with nothing picked the label says Select all', doc.querySelector('[data-bulk-count]').textContent, 'Select all');

    box(2).click();
    eq('S70 one pick shows the bar', bar.classList.contains('hidden'), false);
    eq('S70 and the count is singular', doc.querySelector('[data-bulk-count]').textContent, '1 selected');
    eq('S70 the row is marked picked', row(2).classList.contains('picked'), true);
    eq('S70 the head is not yet ticked', all.checked, false);
    eq('S70 and the head is indeterminate', all.indeterminate, true);

    box(1).click();
    eq('S70 two picks count as two', doc.querySelector('[data-bulk-count]').textContent, '2 selected');
    all.click();
    eq('S70 select-all picks every row', doc.querySelector('[data-bulk-count]').textContent, '3 selected');
    eq('S70 and ticks the head', all.checked, true);
    eq('S70 with no partial state left', all.indeterminate, false);

    // Untick one: the head must go back to indeterminate, not stay ticked.
    box(1).click();
    eq('S70 un-ticking one row un-ticks the head', all.checked, false);
    eq('S70 and makes it indeterminate again', all.indeterminate, true);
    box(1).click();
    box(1).click();
    eq('S70 a second click on the same box takes it back out', doc.querySelector('[data-bulk-count]').textContent, '2 selected');
    // Shift-click extends from the last pick, in row order.
    box(3).dispatch('click', box(3), { shiftKey: true });
    box(3).dispatch('change', box(3), {});
    eq('S70 shift-click extends the range', doc.querySelector('[data-bulk-count]').textContent, '3 selected');
    ok('S70 and the whole bar is back', all.checked && !all.indeterminate);
    void dlg;
  }

  // S71 — the direction is explicit. A per-row toggle on a mixed set marks the
  // ALREADY-READ rows the other way, painted before the request goes out.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    // Deliberately NOT ascending: a newest-first listing shows 1003 before 1001,
    // so the display order is not the UID order. An ascending fixture would let
    // the body come out right by accident, from Object.keys()'s integer-key
    // enumeration rather than from a real sort.
    buildList(doc, 'INBOX', 3, null, [1003, 1001, 1002]);
    const side = buildCounters(doc, { INBOX: ['unseen', 2] });
    const calls = [];
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 3 }) }), calls);
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    const row = i => doc.querySelector('.mail[data-uid="' + i + '"]');
    box(1003).click(); box(1001).click(); box(1002).click();

    // "Mark read" on one unread and two read rows.
    doc.querySelector('[data-bulk-action="read"]').dispatch('click', doc.querySelector('[data-bulk-action="read"]'), {});
    eq('S71 every row is now read', JSON.stringify([1003, 1001, 1002].map(i => row(i).classList.contains('unread'))), '[false,false,false]');
    eq('S71 one POST for the batch', calls.length, 1);
    // Ascending and form-encoded, from a selection made in the display order.
    eq('S71 the POST names every uid, ascending', calls[0].body.indexOf('uids=1001%2C1002%2C1003') !== -1, true);
    ok('S71 and the mark-read route', calls[0].url.indexOf('markRead') !== -1);
    eq('S71 the unseen badge dropped by one', side.INBOX._badge.textContent, '1');
    // The selection goes with the repaint, not with the reply: rows that already
    // show the new state but stay ticked for the round trip look unfinished.
    eq('S71 the selection is dropped at once, before the reply', doc.querySelector('.bulk-bar').classList.contains('hidden'), true);
    eq('S71 and no row is still marked picked', [1003, 1001, 1002].some(i => row(i).classList.contains('picked')), false);
    // The box is its own state: clearing .picked left checked:true behind, so a
    // later hover painted a ticked box on a row that is no longer selected.
    eq('S71 and no row box is left ticked', [1003, 1001, 1002].map(i => box(i).checked).join(), 'false,false,false');
    await settle(); await settle();
    // A flag change does not take the message out of the folder, so the row stays.
    // Removing it was copy-paste from the delete/move path, and it left the list
    // shorter than the server's until a reload.
    eq('S71 a flag change keeps every row in the list', doc.querySelector('.mail-list').children.length, 3);
    eq('S71 and the rows keep the state the server accepted', JSON.stringify([1003, 1001, 1002].map(i => row(i).classList.contains('unread'))), '[false,false,false]');
    eq('S71 and the selection is dropped', doc.querySelector('.bulk-bar').classList.contains('hidden'), true);
  }

  // S72 — a short count is a lie from the server, not a display detail. A batch
  // naming one stale UID is refused as a unit, so NOTHING changed server-side.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildList(doc, 'INBOX', 3);
    const side = buildCounters(doc, { INBOX: ['unseen', 3] });
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 1 }) }), []);
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    const row = i => doc.querySelector('.mail[data-uid="' + i + '"]');
    box(1).click(); box(2).click(); box(3).click();

    doc.querySelector('[data-bulk-action="unread"]').dispatch('click', doc.querySelector('[data-bulk-action="unread"]'), {});
    eq('S72 the rows paint unread at once', JSON.stringify([1, 2, 3].map(i => row(i).classList.contains('unread'))), '[true,true,true]');
    // Row 1 was already unread, so only two rows moved the counter.
    eq('S72 and the badge rose by two', side.INBOX._badge.textContent, '5');
    await settle(); await settle();
    eq('S72 a short count puts every row back', JSON.stringify([1, 2, 3].map(i => row(i).classList.contains('unread'))), '[true,false,false]');
    eq('S72 and the badge returns to where it was', side.INBOX._badge.textContent, '3');
    eq('S72 nothing was removed', doc.querySelector('.mail-list').children.length, 3);
    eq('S72 and the selection is cleared, so nothing is left half-ticked', doc.querySelector('.bulk-bar').classList.contains('hidden'), true);
  }

  // S73 — badge deltas sum. One bumpBadge call cannot be exact: with an unseen
  // badge of 12 and five rows removed of which three were unread, the server
  // says 9, and a single bumpBadge(-5) would paint 7.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildList(doc, 'INBOX', 5);            // row 1 unread
    const side = buildCounters(doc, { INBOX: ['total', 5] });
    buildDialog(doc, 'delete-dialog');
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 5 }) }), []);
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    for (let i = 1; i <= 5; i++) box(i).click();
    win.bulkDelete.call(win);
    const dlg = doc.querySelector('#delete-dialog');
    eq('S73 the confirm is open', dlg.open, true);
    // The copy is the server's, with the count filled in.
    eq('S73 and it carries the count', dlg.querySelector('.modal-title').textContent, 'Delete 5 messages? They will move to Trash.');
    // Nothing is patched until the dialog is confirmed: the copy is the whole
    // point of asking, so the rows must still be there to look at.
    eq('S73 nothing is patched before the confirm', doc.querySelectorAll('.row-gone').length, 0);
    eq('S73 and the badge has not moved', side.INBOX._badge.textContent, '5');
    dlg.querySelector('.btn-confirm').dispatch('click', dlg.querySelector('.btn-confirm'), {});
    eq('S73 confirming patches every row at once', doc.querySelectorAll('.row-gone').length, 5);
    eq('S73 and drops the total badge by exactly five', side.INBOX._badge.textContent, '0');
    await settle(); await settle();
    eq('S73 the total badge stays at zero', side.INBOX._badge.textContent, '0');
    eq('S73 and the rows are gone from the list', doc.querySelector('.mail-list').children.length, 0);

  }

  // S74 — bulk delete posts uids, not uid, and the move reuses one dialog.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildList(doc, 'INBOX', 2);
    const parts = buildDialog(doc, 'move-dialog', { select: true });
    buildDialog(doc, 'delete-dialog');
    const calls = [];
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 2 }) }), calls);
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    box(1).click(); box(2).click();

    doc.querySelector('[data-bulk-action="move"]').dispatch('click', doc.querySelector('[data-bulk-action="move"]'), {});
    eq('S74 the move dialog opened', parts.dialog.open, true);
    parts.select.value = 'Archive';
    parts.confirmBtn.dispatch('click', parts.confirmBtn, {});
    await settle(); await settle();
    eq('S74 one POST for the batch', calls.length, 1);
    eq('S74 to the move route', calls[0].url, '?page=move');
    ok('S74 carrying uids, folder and dest',
      calls[0].body.indexOf('uids=1%2C2') !== -1 &&
      calls[0].body.indexOf('folder=INBOX') !== -1 &&
      calls[0].body.indexOf('dest=Archive') !== -1);
    eq('S74 and both rows are gone', doc.querySelector('.mail-list').children.length, 0);
  }

  // S75 — a mailbox swap drops the selection, and the sync must not steal it.
  {
    const doc = makeDoc(); buildMeta(doc); buildSync(doc, 'INBOX');
    const win = makeWin();
    buildList(doc, 'INBOX', 3);
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 3 }) }), []);
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    box(1).click(); box(2).click();
    eq('S75 two rows are picked', doc.querySelector('[data-bulk-count]').textContent, '2 selected');

    // The swap replaces the section wholesale; pickReset() runs inside it.
    const fresh = makeDoc(); buildMeta(fresh); buildSync(fresh, 'Archive');
    buildList(fresh, 'Archive', 2);
    const html = '<section class="mailbox"></section>';
    fresh.querySelector('.mailbox')._innerHTML = html;
    boot(fresh, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 2 }) }), []);
    eq('S75 a fresh boot starts with nothing picked', fresh.querySelector('[data-bulk-count]').textContent, 'Select all');
    eq('S75 and the bar is hidden again', fresh.querySelector('.bulk-bar').classList.contains('hidden'), true);
  }

  // S76 — x selects, Escape clears, and a picked checkbox does not eat j/k.
  {
    const doc = makeDoc(); buildMeta(doc);
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    const win = makeWin();
    buildList(doc, 'INBOX', 3);
    // x is a client-side selection, so it must cost no request at all: a POST
    // per keypress would mark nothing and would make the key feel broken.
    const calls = [];
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 3 }) }), calls);
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');

    // x on a fresh page: no cursor exists, so one is placed and the row picked.
    doc.dispatch('keydown', { key: 'x' });
    eq('S76 x picks the first row when there is no cursor', box(1).checked, true);
    eq('S76 and costs no request', calls.length, 0);
    eq('S76 and shows the bar', doc.querySelector('.bulk-bar').classList.contains('hidden'), false);
    doc.dispatch('keydown', { key: 'j' });
    doc.dispatch('keydown', { key: 'x' });
    eq('S76 x picks the cursor row', box(2).checked, true);
    eq('S76 and keeps the earlier pick', box(1).checked, true);
    eq('S76 and still costs no request', calls.length, 0);

    // The checkbox blurs itself on change, so j/k keep working after a click.
    box(3).dispatch('click', box(3), {});
    doc.dispatch('keydown', { key: 'j' });
    doc.dispatch('keydown', { key: 'k' });
    eq('S76 the keyboard still walks the list after a pick', doc.querySelectorAll('.cursor').length, 1);

    // Escape clears the selection before it clears the cursor.
    doc.dispatch('keydown', { key: 'Escape' });
    eq('S76 Escape drops the whole selection', doc.querySelector('.bulk-bar').classList.contains('hidden'), true);
  }

  // S79 — Space picks the cursor row, and only then. With no cursor, or with focus
  // on a button, Space keeps its native job (scroll the page, press the button):
  // taking those away would break the page for everybody who never pressed j.
  {
    const doc = makeDoc(); buildMeta(doc);
    const topbar = doc.createElement('div'); topbar.className = 'topbar'; doc.add(topbar);
    const win = makeWin();
    buildList(doc, 'INBOX', 3);
    const calls = [];
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 3 }) }), calls);
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');

    let ev = doc.dispatch('keydown', { key: ' ' });
    eq('S79 Space with no cursor picks nothing', box(1).checked, false);
    eq('S79 and leaves the native scroll alone', ev.defaultPrevented, false);

    doc.dispatch('keydown', { key: 'j' });
    ev = doc.dispatch('keydown', { key: ' ' });
    eq('S79 Space picks the cursor row', box(1).checked, true);
    eq('S79 and stops the page from scrolling', ev.defaultPrevented, true);
    eq('S79 and shows the bar', doc.querySelector('.bulk-bar').classList.contains('hidden'), false);
    eq('S79 and costs no request', calls.length, 0);
    doc.dispatch('keydown', { key: ' ' });
    eq('S79 a second Space takes the row back out', box(1).checked, false);

    // A focused button keeps Space: that is how a keyboard user presses it.
    const btn = doc.createElement('button');
    ev = doc.dispatch('keydown', { key: ' ', target: btn });
    eq('S79 Space on a focused button is not a pick', box(1).checked, false);
    eq('S79 and the button still gets its press', ev.defaultPrevented, false);
  }

  // S80 — a finished bulk action leaves no half-ticked head. The tri-state lives on
  // the DOM property, and clearing `checked` does not clear `indeterminate`: the head
  // kept showing its dash with nothing picked, so it looked like a partial selection
  // that no longer existed.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildList(doc, 'INBOX', 3);
    buildCounters(doc, { INBOX: ['unseen', 3] });
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 1 }) }), []);
    const all = doc.querySelector('[data-pick-all]');
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    box(1).click();
    eq('S80 one pick leaves the head indeterminate', all.indeterminate, true);
    doc.querySelector('[data-bulk-action="unread"]').dispatch('click', doc.querySelector('[data-bulk-action="unread"]'), {});
    await settle(); await settle();
    eq('S80 the action dropped the selection', doc.querySelector('.bulk-bar').classList.contains('hidden'), true);
    eq('S80 and the head is unchecked', all.checked, false);
    eq('S80 and the dash is gone', all.indeterminate, false);
    eq('S80 and the rows are all still listed', doc.querySelector('.mail-list').children.length, 3);
  }

  // S81 — a read change repaints the read button's icon and nothing else. The real
  // row puts the subject (and its paperclip) BEFORE the actions, and setReadIcon()
  // took the row's first <use>: on a message with an attachment it turned the
  // paperclip into an envelope, from the single toggle and the bulk bar alike.
  {
    for (const via of ['toggleRead', 'the bulk bar']) {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      const { rows } = buildList(doc, 'INBOX', 2);   // row 1 unread
      buildCounters(doc, { INBOX: ['unseen', 1] });
      boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 1 }) }), []);
      const row = rows[0];
      const subject = doc.createElement('div'); subject.className = 'mail-subject';
      const svg = doc.createElement('svg'); const clip = doc.createElement('use');
      clip.setAttribute('href', '#tabler-paperclip');
      svg.appendChild(clip); subject.appendChild(svg); row.appendChild(subject);
      // The stub has no insertBefore; reorder by hand to match view_mailbox().
      row.children.splice(row.children.indexOf(subject), 1);
      row.children.splice(1, 0, subject);
      if (via === 'toggleRead') {
        win.toggleRead(1, 'INBOX');
      } else {
        doc.querySelector('.mail-pick[data-pick-uid="1"]').click();
        doc.querySelector('[data-bulk-action="read"]').dispatch('click', doc.querySelector('[data-bulk-action="read"]'), {});
      }
      eq('S81 via ' + via + ' the paperclip stays a paperclip', clip.getAttribute('href'), '#tabler-paperclip');
      eq('S81 via ' + via + ' and the read button shows the opened envelope', row._use.getAttribute('href'), '#tabler-mail-opened');
      await settle(); await settle();
    }
  }

  // S82 — a refusal does not wipe a selection made while the request was out. The
  // bulk bar drops the old selection at once, so by the time a refusal arrives a
  // tick on screen is a NEW one, and the rollback must leave it alone.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildList(doc, 'INBOX', 3);
    buildCounters(doc, { INBOX: ['unseen', 1] });
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: false, success: false, message: 'Nope.' }) }), []);
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    const row = i => doc.querySelector('.mail[data-uid="' + i + '"]');
    box(1).click();
    doc.querySelector('[data-bulk-action="read"]').dispatch('click', doc.querySelector('[data-bulk-action="read"]'), {});
    box(3).click();
    await settle(); await settle();
    eq('S82 the refused row is put back', row(1).classList.contains('unread'), true);
    eq('S82 and the new pick survives the rollback', row(3).classList.contains('picked'), true);
    eq('S82 ticked in its box too', box(3).checked, true);
    eq('S82 with its bar still up', doc.querySelector('.bulk-bar').classList.contains('hidden'), false);
  }

  // S78 — two refusals, both rolled all the way back. An ok:false answer and a
  // short count are different lies: the first says nothing happened, the second
  // says less happened than the rows on screen claim. A rollback that leaves
  // one row patched is worse than no optimistic patch at all, because the next
  // sync cannot tell a real change from a ghost one.
  {
    for (const [name, reply] of [
      ['ok:false', { ok: false, success: false, message: 'Nope.' }],
      ['a short count', { ok: true, success: true, count: 1 }],
    ]) {
      const doc = makeDoc(); buildMeta(doc);
      const win = makeWin();
      buildList(doc, 'INBOX', 3);           // row 1 unread
      const side = buildCounters(doc, { INBOX: ['unseen', 3] });
      const parts = buildDialog(doc, 'move-dialog', { select: true });
      buildDialog(doc, 'delete-dialog');
      boot(doc, win, () => ({ json: () => Promise.resolve(reply) }), []);
      // Flat selectors only: the stub's matches() has no descendant combinator.
      const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
      const row = i => doc.querySelector('.mail[data-uid="' + i + '"]');
      box(1).click(); box(2).click(); box(3).click();
      const countBefore = doc.querySelector('.mail-count').textContent;

      doc.querySelector('[data-bulk-action="move"]').dispatch('click', doc.querySelector('[data-bulk-action="move"]'), {});
      parts.select.value = 'Archive';
      parts.confirmBtn.dispatch('click', parts.confirmBtn, {});
      await settle(); await settle();

      eq('S78 ' + name + ' puts every row back', JSON.stringify([1, 2, 3].map(i => row(i).classList.contains('row-gone'))), '[false,false,false]');
      eq('S78 ' + name + ' and the list still holds all three', doc.querySelector('.mail-list').children.length, 3);
      eq('S78 ' + name + ' and the header count is unchanged', doc.querySelector('.mail-count').textContent, countBefore);
      // Only row 1 was unread, so the unseen badge owed exactly one step back.
      eq('S78 ' + name + ' and the badge is back where it was', side.INBOX._badge.textContent, '3');
      eq('S78 ' + name + ' and the selection is cleared', doc.querySelector('.bulk-bar').classList.contains('hidden'), true);
    }
  }

  // S77 — with rows selected, a sync reload must not throw the selection away.
  {
    const doc = makeDoc(); buildMeta(doc); buildSync(doc, 'INBOX');
    const win = makeWin();
    buildList(doc, 'INBOX', 2);
    const topbar = doc.createElement('div'); topbar.className = 'topbar';
    topbar.setAttribute('data-period', '60'); doc.add(topbar);
    boot(doc, win, u => {
      if (u.indexOf('sync=') !== -1) return { json: () => Promise.resolve({ ok: true, synced: 'up to date', changed: 0, arrivals: 1 }) };
      return { json: () => Promise.resolve({ ok: true, success: true, count: 2 }) };
    }, []);
    // Flat selectors only: the stub's matches() has no descendant combinator.
    const box = i => doc.querySelector('.mail-pick[data-pick-uid="' + i + '"]');
    box(1).click(); box(2).click();
    doc.dispatch('visibilitychange', { });
    await settle();
    // A swap happens only when syncCanSwap() agrees, and it must not while rows
    // are picked: a reload throws the selection away mid-batch.
    eq('S77 a sync with rows picked does not reload the page', win._reloads, 0);
    eq('S77 and the selection is intact', doc.querySelector('[data-bulk-count]').textContent, '2 selected');
  }

  // S90 — a conversation row toggles the whole thread in one POST, and the badge
  // follows the server's count of flipped members.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    const parts = buildList(doc, 'INBOX', 2);
    const side = buildCounters(doc, { INBOX: ['unseen', 2] });
    const calls = [];
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 2 }) }), calls);
    // Row 1 stands for a three-message conversation; row 2 is a plain message.
    parts.rows[0].setAttribute('data-thread', '<root@x.io>');
    parts.rows[0].setAttribute('data-thread-uids', '1,7,9');
    eq('S90 the conversation row starts unread', parts.rows[0].classList.contains('unread'), true);
    win.toggleRead(1, 'INBOX');
    eq('S90 the row unbolds at once', parts.rows[0].classList.contains('unread'), false);
    await settle();
    eq('S90 one POST', calls.length, 1);
    eq('S90 to markRead', calls[0].url, '?page=markRead');
    ok('S90 carrying the thread, not a uid',
      calls[0].body.indexOf('thread=' + encodeURIComponent('<root@x.io>')) !== -1);
    ok('S90 and the folder', calls[0].body.indexOf('folder=INBOX') !== -1);
    eq('S90 the unseen badge drops by the flipped count', side.INBOX._badge.textContent, '0');
  }

  // S91 — a conversation's checkbox selects all of its in-folder members, so the
  // bulk actions carry the whole set.
  {
    const doc = makeDoc(); buildMeta(doc);
    const win = makeWin();
    buildList(doc, 'INBOX', 3);
    buildDialog(doc, 'delete-dialog');
    const calls = [];
    boot(doc, win, () => ({ json: () => Promise.resolve({ ok: true, success: true, count: 3 }) }), calls);
    const row = doc.querySelector('.mail[data-uid="2"]');
    row.setAttribute('data-thread', '<root@x.io>');
    row.setAttribute('data-thread-uids', '2,4,5');
    const box = doc.querySelector('.mail-pick[data-pick-uid="2"]');
    box.checked = true;
    box.dispatch('change', box, {});
    eq('S91 picking the conversation selects every member',
      doc.querySelector('[data-bulk-count]').textContent, '3 selected');
    doc.querySelector('[data-bulk-action="unread"]').dispatch('click', doc.querySelector('[data-bulk-action="unread"]'), {});
    await settle();
    eq('S91 the bulk POST names all three uids, ascending',
      calls[0].body.indexOf('uids=2%2C4%2C5') !== -1, true);
  }

  } catch (e) {
    R.push({ name: '<harness crashed mid-run>', pass: false, detail: (e && e.stack) || String(e) });
  }
  console.log('JSREPORT_START');
  console.log(JSON.stringify(R));
  // Exit explicitly: the app may still hold toast/sync timers, and leaving them
  // to fire naturally would stretch the suite by their full delay.
  process.exit(R.some(r => !r.pass) ? 1 : 0);
}

main().catch(e => {
  console.error(e && (e.stack || e.message));
  process.exit(2);
});
JS
);
$cmd = 'APP_JS_PATH=' . escapeshellarg($appFile) . ' MD_FIXTURES_PATH=' . escapeshellarg($mdFile) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg($runFile) . ' 2>&1';
$out  = (string)shell_exec($cmd);
@unlink($appFile);
@unlink($runFile);
@unlink($mdFile);

// Node prints a JSREPORT_START marker line, then a single JSON array of results.
$report = null;
if (($pos = strrpos($out, 'JSREPORT_START')) !== false) {
    $report = json_decode(trim(substr($out, $pos + strlen('JSREPORT_START'))), true);
}
if (!is_array($report)) {
    failTest('js: node produced no report');
    $tail = trim(substr($out, -500));
    if ($tail !== '') {
        echo '    ' . colorRed(var_export($tail, true)) . "\n";
    }
    summary();
}

$crashed = false;
foreach ($report as $r) {
    if (!empty($r['name']) && $r['name'] === '<harness crashed mid-run>') {
        $crashed = true;
    }
    if (!empty($r['pass'])) {
        passTest($r['name']);
        continue;
    }
    failTest($r['name']);
    if (!empty($r['detail'])) {
        echo '    ' . colorRed(str_replace("\n", ' | ', $r['detail'])) . "\n";
    }
}
if ($crashed) {
    failTest('js: harness must not crash mid-run');
}

summary();