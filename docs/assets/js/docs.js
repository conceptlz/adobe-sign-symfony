/*
 * Documentation runtime: theme, mobile navigation, heading anchors, table of contents, code
 * highlighting and copy buttons, search. No dependencies; pages stay readable without it.
 * Source of truth: tools/docs/assets/js/docs.js (copied into each docs/ by tools/docs/build.php).
 */
(function () {
  'use strict';

  var root = document.documentElement;
  root.classList.add('js');

  function store(key, value) {
    try {
      if (value === undefined) return window.localStorage.getItem(key);
      window.localStorage.setItem(key, value);
    } catch (e) {
      return null;
    }
    return null;
  }

  /* ---------- Theme ---------- */

  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    var button = document.querySelector('.theme-toggle');
    if (button) {
      button.setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    }
  }

  function initTheme() {
    var button = document.querySelector('.theme-toggle');
    applyTheme(root.getAttribute('data-theme') || 'light');
    if (button) {
      button.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        store('docs-theme', next);
      });
    }
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (event) {
        if (!store('docs-theme')) applyTheme(event.matches ? 'dark' : 'light');
      });
    }
  }

  /* ---------- Mobile navigation ---------- */

  function initNav() {
    var button = document.querySelector('.menu-button');
    var backdrop = document.querySelector('.nav-backdrop');
    if (!button) return;

    function setOpen(open) {
      document.body.classList.toggle('nav-open', open);
      button.setAttribute('aria-expanded', String(open));
      if (backdrop) backdrop.hidden = !open;
    }

    button.addEventListener('click', function () {
      setOpen(button.getAttribute('aria-expanded') !== 'true');
    });
    if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && document.body.classList.contains('nav-open')) {
        setOpen(false);
        button.focus();
      }
    });

    // Keep the current page visible in a long sidebar without scrolling the document itself.
    var sidebar = document.querySelector('.sidebar');
    var current = document.querySelector('.nav-list a[aria-current="page"]');
    if (sidebar && current && current.offsetTop > sidebar.clientHeight * 0.7) {
      sidebar.scrollTop = current.offsetTop - sidebar.clientHeight / 2;
    }
  }

  /* ---------- Headings and table of contents ---------- */

  function slugify(text) {
    return text.toLowerCase().trim()
      .replace(/[^\w\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-');
  }

  function initHeadings() {
    var article = document.querySelector('.doc');
    var toc = document.querySelector('.toc');
    if (!article) return;

    var headings = Array.prototype.slice.call(article.querySelectorAll('h2, h3'));
    var used = {};
    headings.forEach(function (heading) {
      if (!heading.id) {
        var base = slugify(heading.textContent) || 'section';
        var id = base;
        for (var n = 2; used[id] || document.getElementById(id); n++) id = base + '-' + n;
        heading.id = id;
      }
      used[heading.id] = true;
      if (!heading.querySelector('.heading-anchor')) {
        var anchor = document.createElement('a');
        anchor.className = 'heading-anchor';
        anchor.href = '#' + heading.id;
        anchor.setAttribute('aria-label', 'Link to this section');
        anchor.textContent = '#';
        heading.appendChild(anchor);
      }
    });

    if (!toc) return;
    var tocHeadings = headings.filter(function (h) { return !h.closest('.faq, .no-toc'); });
    if (tocHeadings.length < 2) {
      toc.hidden = true;
      return;
    }

    var title = document.createElement('p');
    title.className = 'toc-title';
    title.textContent = 'On this page';
    var list = document.createElement('ol');
    var links = [];
    tocHeadings.forEach(function (heading) {
      var item = document.createElement('li');
      if (heading.tagName === 'H3') item.className = 'toc-sub';
      var link = document.createElement('a');
      link.href = '#' + heading.id;
      link.textContent = heading.textContent.replace(/#$/, '').trim();
      item.appendChild(link);
      list.appendChild(item);
      links.push(link);
    });
    toc.appendChild(title);
    toc.appendChild(list);

    if (!('IntersectionObserver' in window)) return;
    var visible = new Map();
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) { visible.set(entry.target, entry.isIntersecting); });
      var active = null;
      for (var i = 0; i < tocHeadings.length; i++) {
        if (visible.get(tocHeadings[i])) { active = i; break; }
      }
      if (active === null) {
        for (var j = tocHeadings.length - 1; j >= 0; j--) {
          if (tocHeadings[j].getBoundingClientRect().top < 120) { active = j; break; }
        }
      }
      links.forEach(function (link, index) { link.classList.toggle('is-active', index === active); });
    }, { rootMargin: '-64px 0px -60% 0px' });
    tocHeadings.forEach(function (heading) { observer.observe(heading); });
  }

  /* ---------- Code: highlighting and copy ---------- */

  function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  var PHP_KEYWORDS = 'abstract|and|array|as|break|case|catch|class|clone|const|continue|declare|default|do|echo|else|elseif|enum|extends|final|finally|fn|for|foreach|function|global|if|implements|instanceof|interface|match|namespace|new|null|or|private|protected|public|readonly|require|require_once|return|self|static|switch|throw|trait|true|false|try|use|var|while|yield|from|int|string|bool|float|void|mixed|iterable|object|never|parent|print|isset|unset|empty|list|exit|include|include_once';

  var RULES = {
    php: [
      ['comment', /\/\*[\s\S]*?\*\/|\/\/[^\n]*|#(?!\[)[^\n]*/y],
      ['string', /'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"/y],
      ['punct', /<\?php|#\[|\?>/y],
      ['variable', /\$[A-Za-z_]\w*/y],
      ['number', /\b\d[\d_]*(?:\.\d+)?\b/y],
      ['keyword', new RegExp('\\b(?:' + PHP_KEYWORDS + ')\\b(?!\\s*:(?!:))', 'y')],
      ['function', /\b[a-z_]\w*(?=\s*\()/y],
      ['type', /\b[A-Z]\w*\b/y],
      ['punct', /->|::|=>|\?->|[{}()[\];,]/y]
    ],
    bash: [
      ['comment', /(^|\s)#[^\n]*/y],
      ['string', /'[^']*'|"(?:[^"\\]|\\.)*"/y],
      ['variable', /\$\{?[A-Za-z_]\w*\}?|\b[A-Z][A-Z0-9_]*(?==)/y],
      ['keyword', /\b(?:composer|php|artisan|git|cd|export|curl|gh|npm|cp|mkdir|ngrok|vendor\/bin\/phpunit|vendor\/bin\/phpstan)\b/y],
      ['number', /\b\d+\b/y],
      ['function', /--?[A-Za-z][\w-]*/y],
      ['punct', /[|&;\\]/y]
    ],
    json: [
      ['type', /"(?:[^"\\]|\\.)*"(?=\s*:)/y],
      ['string', /"(?:[^"\\]|\\.)*"/y],
      ['number', /-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/y],
      ['keyword', /\b(?:true|false|null)\b/y],
      ['punct', /[{}[\],:]/y]
    ],
    dotenv: [
      ['comment', /#[^\n]*/y],
      ['variable', /^[A-Z][A-Z0-9_]*(?==)/my],
      ['string', /"[^"\n]*"|'[^'\n]*'/y],
      ['punct', /=/y]
    ],
    http: [
      ['keyword', /\b(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS)\b/y],
      ['number', /\b[1-5]\d\d\b/y],
      ['type', /^[A-Za-z-]+(?=:)/my],
      ['string', /"(?:[^"\\]|\\.)*"/y],
      ['punct', /[{}[\],:]/y]
    ]
  };
  RULES.shell = RULES.bash;
  RULES.env = RULES.dotenv;

  function highlight(code, language) {
    var rules = RULES[language];
    if (!rules) return escapeHtml(code);
    var out = '';
    var plain = '';
    var i = 0;
    outer:
    while (i < code.length) {
      for (var r = 0; r < rules.length; r++) {
        var re = rules[r][1];
        re.lastIndex = i;
        var m = re.exec(code);
        if (m && m.index === i && m[0].length > 0) {
          var text = m[0];
          var lead = '';
          if (rules[r][0] === 'comment' && language !== 'php' && /^\s/.test(text)) {
            lead = text.match(/^\s+/)[0];
            text = text.slice(lead.length);
          }
          out += escapeHtml(plain + lead) + '<span class="tok-' + rules[r][0] + '">' + escapeHtml(text) + '</span>';
          plain = '';
          i += m[0].length;
          continue outer;
        }
      }
      // Advance over a whole word so rules don't match inside identifiers.
      var word = /[A-Za-z0-9_]+/y;
      word.lastIndex = i;
      var w = word.exec(code);
      if (w && w.index === i) {
        plain += w[0];
        i += w[0].length;
      } else {
        plain += code[i];
        i++;
      }
    }
    return out + escapeHtml(plain);
  }

  function copyText(text, button) {
    function done() {
      var label = button.textContent;
      button.textContent = 'Copied';
      button.setAttribute('data-copied', '');
      setTimeout(function () {
        button.textContent = label === 'Copied' ? 'Copy' : label;
        button.removeAttribute('data-copied');
      }, 1600);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
    } else {
      fallbackCopy(text);
      done();
    }
  }

  function fallbackCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); } catch (e) { /* nothing else to try */ }
    document.body.removeChild(area);
  }

  function initCode() {
    var blocks = document.querySelectorAll('.doc pre > code');
    Array.prototype.forEach.call(blocks, function (code) {
      var match = /language-(\w+)/.exec(code.className);
      var raw = code.textContent;
      if (match) code.innerHTML = highlight(raw, match[1]);

      var pre = code.parentNode;
      var holder = pre.closest('.code');
      if (!holder) {
        holder = document.createElement('div');
        holder.className = 'code';
        pre.parentNode.insertBefore(holder, pre);
        holder.appendChild(pre);
      }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'copy-button';
      button.textContent = 'Copy';
      button.setAttribute('aria-label', 'Copy code to clipboard');
      button.addEventListener('click', function () { copyText(raw.replace(/\n$/, ''), button); });
      holder.appendChild(button);
    });

    Array.prototype.forEach.call(document.querySelectorAll('.copy-inline'), function (button) {
      button.addEventListener('click', function () {
        copyText(button.getAttribute('data-copy') || '', button);
      });
    });
  }

  /* ---------- Search ---------- */

  function initSearch() {
    var input = document.querySelector('.search-input');
    var results = document.querySelector('.search-results');
    var index = window.DOCS_INDEX || [];
    if (!input || !results) return;

    var selected = -1;
    var items = [];

    function score(entry, terms) {
      var title = entry.title.toLowerCase();
      var page = entry.page.toLowerCase();
      var text = entry.text.toLowerCase();
      var total = 0;
      for (var i = 0; i < terms.length; i++) {
        var t = terms[i];
        var s = 0;
        if (title.indexOf(t) === 0) s += 12;
        else if (title.indexOf(t) > -1) s += 8;
        if (page.indexOf(t) > -1) s += 2;
        if (text.indexOf(t) > -1) s += 3;
        if (!s) return 0;
        total += s;
      }
      return total + (entry.level === 1 ? 2 : entry.level === 2 ? 1 : 0);
    }

    function mark(text, terms) {
      var safe = escapeHtml(text);
      terms.forEach(function (t) {
        if (t.length < 2) return;
        var re = new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
        safe = safe.replace(re, '<mark>$1</mark>');
      });
      return safe;
    }

    function snippet(text, terms) {
      var lower = text.toLowerCase();
      var at = -1;
      for (var i = 0; i < terms.length && at < 0; i++) at = lower.indexOf(terms[i]);
      var start = Math.max(0, at - 50);
      var cut = text.slice(start, start + 150);
      return (start > 0 ? '…' : '') + cut + (start + 150 < text.length ? '…' : '');
    }

    function render() {
      var query = input.value.trim().toLowerCase();
      selected = -1;
      if (query.length < 2) {
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        return;
      }
      var terms = query.split(/\s+/).filter(Boolean);
      var found = index
        .map(function (entry) { return { entry: entry, s: score(entry, terms) }; })
        .filter(function (r) { return r.s > 0; })
        .sort(function (a, b) { return b.s - a.s; })
        .slice(0, 12);

      results.innerHTML = '';
      items = [];
      if (!found.length) {
        var empty = document.createElement('li');
        empty.className = 'search-empty';
        empty.textContent = 'Nothing matches "' + input.value.trim() + '". Try a method name, an exception or a concept like "signing order".';
        results.appendChild(empty);
      }
      found.forEach(function (r, i) {
        var li = document.createElement('li');
        li.className = 'search-result';
        li.id = 'search-result-' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        var a = document.createElement('a');
        a.href = r.entry.url;
        a.innerHTML =
          '<span class="search-result-title">' + mark(r.entry.title, terms) + '</span>' +
          (r.entry.level > 1 ? '<span class="search-result-path">' + escapeHtml(r.entry.page) + '</span>' : '') +
          (r.entry.text ? '<span class="search-result-snippet">' + mark(snippet(r.entry.text, terms), terms) + '</span>' : '');
        li.appendChild(a);
        results.appendChild(li);
        items.push(li);
      });
      results.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function select(i) {
      if (!items.length) return;
      selected = (i + items.length) % items.length;
      items.forEach(function (li, n) { li.setAttribute('aria-selected', String(n === selected)); });
      input.setAttribute('aria-activedescendant', items[selected].id);
      items[selected].scrollIntoView({ block: 'nearest' });
    }

    input.addEventListener('input', render);
    input.addEventListener('focus', function () { if (input.value.trim().length > 1) render(); });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') { event.preventDefault(); select(selected + 1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); select(selected - 1); }
      else if (event.key === 'Enter' && items.length) {
        event.preventDefault();
        window.location.href = items[Math.max(selected, 0)].querySelector('a').href;
      } else if (event.key === 'Escape') {
        input.value = '';
        results.hidden = true;
        input.blur();
      }
    });
    document.addEventListener('click', function (event) {
      if (!event.target.closest('.search')) results.hidden = true;
    });
    document.addEventListener('keydown', function (event) {
      var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
      if ((event.key === '/' && !typing) || (event.key === 'k' && (event.metaKey || event.ctrlKey))) {
        event.preventDefault();
        input.focus();
        input.select();
      }
    });
  }

  /* ---------- Hero signature ---------- */

  function initSignature() {
    Array.prototype.forEach.call(document.querySelectorAll('.signature-stroke'), function (path) {
      if (path.getTotalLength) path.style.setProperty('--len', Math.ceil(path.getTotalLength()) + 1);
    });
  }

  /* ---------- Troubleshooting finder ---------- */

  function initFinder() {
    var box = document.querySelector('.finder-search');
    var input = document.querySelector('.finder-input');
    var status = document.querySelector('.finder-status');
    var issues = Array.prototype.slice.call(document.querySelectorAll('.issue'));
    if (!box || !input || !issues.length) return;
    box.hidden = false;

    var groups = Array.prototype.slice.call(document.querySelectorAll('.issue-group'));
    var texts = issues.map(function (issue) { return issue.textContent.toLowerCase(); });

    function apply() {
      var terms = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
      var shown = 0;
      issues.forEach(function (issue, i) {
        var match = terms.every(function (t) { return texts[i].indexOf(t) > -1; });
        issue.hidden = !match;
        if (match) shown++;
      });
      groups.forEach(function (group) {
        group.hidden = !group.querySelector('.issue:not([hidden])');
      });
      if (status) {
        status.textContent = terms.length
          ? (shown ? shown + ' of ' + issues.length + ' problems match.' : 'No problem matches. Try an error code (e.g. 412), an exception name or a word like "webhook".')
          : '';
      }
    }

    input.addEventListener('input', apply);
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { input.value = ''; apply(); }
    });
  }

  /* ---------- FAQ deep links ---------- */

  function initFaq() {
    function openFromHash() {
      if (!location.hash) return;
      var target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
      var details = target && (target.tagName === 'DETAILS' ? target : target.closest('details'));
      if (details) {
        details.open = true;
        details.scrollIntoView();
      }
    }
    openFromHash();
    window.addEventListener('hashchange', openFromHash);
  }

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    initTheme();
    initNav();
    initHeadings();
    initCode();
    initSearch();
    initSignature();
    initFinder();
    initFaq();
  });
})();
