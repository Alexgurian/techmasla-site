(function () {
  window.kSha256 = function (str) {
    if (window.crypto && window.crypto.subtle && window.crypto.subtle.digest) {
      return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(str)).then(function (h) {
        return Array.prototype.map.call(new Uint8Array(h), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
      });
    }
    return Promise.reject(new Error('Браузер устарел'));
  };

  if (location.protocol !== 'file:') return;
  var PAGE = decodeURIComponent((location.pathname.split('/').pop() || 'index.html').toLowerCase());
  var P = 'km.';
  function jget(k, d) {
    try {
      var v = localStorage.getItem(P + k);
      return v === null ? d : JSON.parse(v);
    } catch (e) { return d; }
  }
  function jset(k, v) {
    try { localStorage.setItem(P + k, JSON.stringify(v)); return true; }
    catch (e) { alert('В браузере не хватает места для сохранения. Уменьшите картинку и попробуйте снова.'); return false; }
  }
  function kbgOf(html) {
    var m = /^<!--kbg:([a-z]+)-->/.exec(html || '');
    return m ? m[1] : 'white';
  }
  function stripKbg(html) {
    return (html || '').replace(/^<!--kbg:[a-z]+-->/, '');
  }
  function blockSection(id, html) {
    var kbg = kbgOf(html);
    var cls = 'section' + (kbg === 'gray' ? ' section-alt' : '') + (kbg === 'dark' || kbg === 'hero' ? ' section-dark' : '');
    var sec = document.createElement('section');
    sec.className = cls;
    sec.id = 'k' + id;
    sec.innerHTML = '<div class="container"><div class="about-text about-wide" data-k="' + id + '">' + stripKbg(html) + '</div></div>';
    return sec;
  }

  function applyContacts() {
    var c = jget('contacts', null);
    if (!c) return;
    var foot = document.querySelector('.footer');
    if (!foot) return;
    // адрес
    var addr = foot.querySelector('.contact-line span');
    if (addr && c.address) addr.textContent = 'Офис: ' + c.address;
    // перестроить список контактов
    var block = foot.querySelectorAll('.contact-line');
    var emailLine = null, globeline = null;
    block.forEach(function (line) {
      if (line.querySelector('a[href^="mailto:"]')) emailLine = line;
      if (line.querySelector('a[href*="techmasla.ru"]')) globeline = line;
    });
    // удалить старые телефонные строки
    block.forEach(function (line) {
      if (line.querySelector('a[href^="tel:"]')) line.remove();
    });
    var anchor = emailLine || globeline;
    (c.phones || []).forEach(function (p) {
      var div = document.createElement('div');
      div.className = 'contact-line';
      div.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-phone"/></svg><span><a href="tel:' + p.tel.replace(/[^+0-9]/g, '') + '">' + p.label + '</a>' + (p.note ? ' - ' + p.note : '') + '</span>';
      anchor.parentNode.insertBefore(div, anchor);
    });
    if (emailLine && c.email) {
      var ea = emailLine.querySelector('a');
      ea.setAttribute('href', 'mailto:' + c.email);
      ea.textContent = c.email;
    }
  }

  function init() {
    var reg = jget('reg', {}), ovr = jget('ovr', {}), virt = jget('virtual', {}), del = jget('deleted', []);

    if (del.indexOf(PAGE) !== -1) {
      document.title = 'Страница не найдена - ООО "Индустриальные ТехМасла"';
      document.body.innerHTML = '<main id="main" style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif">' +
        '<section class="page-hero"><div class="container">' +
        '<p class="hero-kicker">Ошибка 404 · techmasla.ru</p>' +
        '<h1>Страница не найдена</h1>' +
        '<p style="color:#c7d5e4;max-width:60ch;font-size:17px;line-height:1.6">Такой страницы на сайте нет — возможно, она была перемещена или архивирована. Проверьте адрес или вернитесь на главную.</p>' +
        '<div style="margin-top:22px;display:flex;gap:12px;flex-wrap:wrap">' +
        '<a href="index.html" style="background:#1f6fce;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700">На главную</a>' +
        '<a href="admin-local.html" style="border:1px solid rgba(255,255,255,.45);color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700">В редактор</a>' +
        '</div></div></section></main>';
      return;
    }

    var h1 = document.querySelector('h1');
    reg[PAGE] = reg[PAGE] || { title: (h1 ? h1.textContent.trim() : PAGE), blocks: {} };
    reg[PAGE].title = (h1 ? h1.textContent.trim() : PAGE);
    reg[PAGE].blocks = reg[PAGE].blocks || {};
    document.querySelectorAll('[data-k]').forEach(function (el) {
      reg[PAGE].blocks[el.getAttribute('data-k')] = el.innerHTML;
    });
    jset('reg', reg);

    var g = jget('global', {});
    document.querySelectorAll('[data-k]').forEach(function (el) {
      var id = el.getAttribute('data-k');
      if (el.hasAttribute('data-global')) {
        if (Object.prototype.hasOwnProperty.call(g, id)) el.innerHTML = g[id];
      } else if (Object.prototype.hasOwnProperty.call(ovr[PAGE] || {}, id)) {
        el.innerHTML = ovr[PAGE][id];
      }
    });

    var main = document.querySelector('main');
    if (main) {
      var cta = main.querySelector('.cta');
      var o = ovr[PAGE] || {};
      var added = Object.keys(o).filter(function (id) { return /^block-/.test(id); });
      var order = jget('order', {})[PAGE] || [];
      var ordered = order.filter(function (id) { return added.indexOf(id) >= 0; });
      added.forEach(function (id) { if (ordered.indexOf(id) < 0) ordered.push(id); });
      ordered.forEach(function (id) {
        if (!document.querySelector('[data-k="' + id + '"]')) {
          main.insertBefore(blockSection(id, o[id]), cta || null);
        }
      });
    }

    var porder = jget('pageOrder', []);
    var navUl = document.querySelector('.site-nav ul');
    if (navUl && porder.length) {
      var items = {};
      Array.prototype.slice.call(navUl.children).forEach(function (li) {
        var a = li.querySelector('a');
        if (!a) return;
        var base = (a.getAttribute('href') || '').split('#')[0].split('/').pop().toLowerCase();
        items[base] = li;
      });
      porder.forEach(function (f) {
        var key = f.toLowerCase();
        if (items[key]) navUl.appendChild(items[key]);
      });
      var contactsItem = null;
      Array.prototype.slice.call(navUl.children).forEach(function (li) {
        var a = li.querySelector('a');
        if (a && (a.getAttribute('href') || '').charAt(0) === '#') { contactsItem = li; }
      });
      if (contactsItem) navUl.appendChild(contactsItem);
    }
    var contactsLi = document.querySelector('.site-nav li:last-of-type');
    Object.keys(virt).sort().forEach(function (slug) {
      var link = slug + '.html';
      document.querySelectorAll('a[href="' + link + '"]').forEach(function (a) {
        a.setAttribute('href', 'page.html#' + slug);
      });
      if (contactsLi && !document.querySelector('.site-nav a[href="page.html#' + slug + '"]')) {
        var li = document.createElement('li');
        li.innerHTML = '<a href="page.html#' + slug + '">' + virt[slug].title + '</a>';
        contactsLi.parentNode.insertBefore(li, contactsLi);
      }
    });



    document.querySelectorAll('.site-nav li, .footer li').forEach(function (li) {
      var a = li.querySelector('a');
      if (!a) return;
      var href = a.getAttribute('href') || '';
      var base = href.split('#')[0].split('/').pop().toLowerCase();
      var hashSlug = href.split('#')[1] ? decodeURIComponent(href.split('#')[1]).toLowerCase() : '';
      var inDel = del.indexOf(base) !== -1 || (hashSlug && del.indexOf(hashSlug) !== -1) || (hashSlug && del.indexOf(hashSlug + '.html') !== -1);
      if (inDel && base !== PAGE.toLowerCase()) li.remove();
    });

        applyContacts();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  window.kLocal = {
    get: jget, set: jset, page: function () { return PAGE; },
    blockSection: blockSection
  };
})();