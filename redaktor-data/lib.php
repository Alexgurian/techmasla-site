<?php

if (!function_exists('random_bytes')) {
    function random_bytes($n) {
        $out = '';
        for ($i = 0; $i < $n; $i++) $out .= chr(mt_rand(0, 255));
        return $out;
    }
}

function k_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function k_pages_list($root, $admin) {
    $sys = [$admin, 'admin-local.html', 'page.html'];
    $out = [];
    foreach (glob($root . '/*.html') as $f) {
        $b = basename($f);
        if (in_array($b, $sys, true)) continue;
        $out[] = $b;
    }
    sort($out);
    return $out;
}

function k_blocks($file) {
    $s = @file_get_contents($file);
    if ($s === false) return [];
    preg_match_all('/<!--k:([a-z0-9-]+)-->(.*?)<!--\/k:\1-->/s', $s, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $out[] = ['id' => $x[1], 'html' => $x[2]];
    }
    return $out;
}

function k_block_label($id) {
    if ($id === 'lead') return 'Подзаголовок в верхней части страницы';
    if ($id === 'about') return 'Основной текст страницы';
    if ($id === 'cta') return 'Текст в блоке «Контакты»';
    if (preg_match('/^block-[0-9]+(-[0-9]+)?$/', $id)) return 'Добавленный блок ' . preg_replace('/^block-/', '№', $id);
    return $id;
}

function k_templates() {
    $f = __DIR__ . '/templates.php';
    return is_file($f) ? require $f : [];
}

function k_backup_dir() {
    return __DIR__ . '/backups';
}

function k_backup($file) {
    $dir = k_backup_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_file($file)) {
        @copy($file, $dir . '/' . basename($file) . '.' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.bak');
    }
    $files = glob($dir . '/*.bak');
    if (count($files) > 200) {
        usort($files, function ($a, $b) { return filemtime($a) - filemtime($b); });
        for ($i = 0; $i < count($files) - 200; $i++) @unlink($files[$i]);
    }
}

function k_clean_html($html) {
    $html = str_replace(['<?', '<%'], '', $html);
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8"?><html><body><div id="kroot">' . $html . '</div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    if (!$ok) return '';
    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) return '';
    $allowed = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'small' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'h3' => [], 'h4' => [], 'blockquote' => [], 'hr' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
    ];
    $bad = ['script','style','iframe','object','embed','link','meta','form','input','button','textarea','select','svg','video','audio','base','frame','frameset','applet','noscript','template','math','head','body','html'];
    $recurse = null;
    $recurse = function (DOMNode $n) use (&$recurse, $allowed, $bad) {
        if (!($n instanceof DOMElement)) return;
        foreach (iterator_to_array($n->childNodes) as $c) $recurse($c);
        $tag = strtolower($n->tagName);
        $parent = $n->parentNode;
        if ($parent === null) return;
        if (in_array($tag, $bad, true)) {
            $parent->removeChild($n);
            return;
        }
        if (!isset($allowed[$tag])) {
            while ($n->firstChild) { $parent->insertBefore($n->firstChild, $n); }
            $parent->removeChild($n);
            return;
        }
        foreach (iterator_to_array($n->attributes) as $at) {
            $name = strtolower($at->nodeName);
            if (!in_array($name, $allowed[$tag], true)) { $n->removeAttributeNode($at); continue; }
            $val = isset($at->nodeValue) ? $at->nodeValue : '';
            if ($name === 'href' || $name === 'src') {
                $t = strtolower(preg_replace('/[\x00-\x20]+/', '', $val));
                $okurl = preg_match('~^(https?://|/|img/|#|\./|\.\./)~', $t)
                    || preg_match('~^(mailto:|tel:)~', $t)
                    || ($tag === 'img' && preg_match('~^data:image/~', $t));
                if (!$okurl) { $n->removeAttributeNode($at); continue; }
            }
            if (stripos($val, 'javascript:') !== false || stripos($val, 'data:text/html') !== false) {
                $n->removeAttributeNode($at);
            }
        }
    };
    foreach (iterator_to_array($root->childNodes) as $c) $recurse($c);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    $out = trim($out);
    $out = preg_replace_callback('/&#([0-9]+);/', function ($m) {
        $cp = (int)$m[1];
        if ($cp <= 0 || $cp > 0x10FFFF) return '';
        if (function_exists('mb_chr')) return mb_chr($cp, 'UTF-8');
        return html_entity_decode('&#' . $cp . ';', ENT_QUOTES, 'UTF-8');
    }, $out);
    return $out;
}

function k_save_block($file, $id, $inner) {
    $id = strtolower(trim($id));
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $id)) return false;
    $s = @file_get_contents($file);
    if ($s === false) return false;
    $new = k_clean_html($inner);
    $rep = '<!--k:' . $id . '-->' . $new . '<!--/k:' . $id . '-->';
    $out = preg_replace_callback(
        '/<!--k:' . preg_quote($id, '/') . '-->.*?<!--\/k:' . preg_quote($id, '/') . '-->/s',
        function () use ($rep) { return $rep; },
        $s, 1, $cnt
    );
    if ($cnt !== 1) return false;
    k_backup($file);
    return file_put_contents($file, $out, LOCK_EX) !== false;
}

function k_add_block($file, $inner, &$newid) {
    $s = @file_get_contents($file);
    if ($s === false) return false;
    $new = k_clean_html($inner);
    if (trim($new) === '') return false;
    $ts = time();
    $id = 'block-' . $ts;
    $i = 0;
    while (strpos($s, '<!--k:' . $id . '-->') !== false) { $i++; $id = 'block-' . $ts . '-' . $i; }
    $sec = "\n  <section class=\"section\" id=\"k" . $ts . "\">\n    <div class=\"container\">\n      <div class=\"about-text about-wide\" data-k=\"" . $id . "\"><!--k:" . $id . "-->" . $new . "<!--/k:" . $id . "--></div>\n    </div>\n  </section>\n";
    $pos = strpos($s, '  <section class="cta"');
    if ($pos !== false) {
        $out = substr($s, 0, $pos) . ltrim($sec, "\n") . substr($s, $pos);
    } else {
        $pos2 = strrpos($s, '</main>');
        if ($pos2 === false) return false;
        $out = substr($s, 0, $pos2) . ltrim($sec, "\n") . "\n" . substr($s, $pos2);
    }
    k_backup($file);
    $newid = $id;
    return file_put_contents($file, $out, LOCK_EX) !== false;
}

function k_del_block($file, $id) {
    if (!preg_match('/^block-[0-9]+(-[0-9]+)?$/', $id)) return false;
    $s = @file_get_contents($file);
    if ($s === false || strpos($s, '<!--k:' . $id . '-->') === false) return false;
    if (!preg_match('/  <section class="section" id="k[0-9-]+">\n.*?<!--\/k:' . preg_quote($id, '/') . '--><\/div>\n    <\/div>\n  <\/section>/s', $s, $m)) return false;
    if (strpos($m[0], '<!--k:' . $id . '-->') === false) return false;
    $out = str_replace($m[0] . "\n", '', $s);
    if ($out === $s) $out = str_replace($m[0], '', $s);
    k_backup($file);
    return file_put_contents($file, $out, LOCK_EX) !== false;
}

function k_new_page($root, $tpl, $slug, $title, $inner, $admin, $site, $tplId = '') {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,49}$/', $slug)) return [false, 'Адрес страницы: только латинские буквы, цифры и дефис, без пробелов'];
    if (is_file($root . '/' . $slug . '.html')) return [false, 'Страница с таким адресом уже существует'];
    $clean = k_clean_html($inner);
    $lead = '';
    $ht = k_h($title);
    $sectionsHtml = '';
    if ($tplId !== '') {
        $tpls = k_templates();
        if (!isset($tpls[$tplId])) return [false, 'Шаблон не найден'];
        $t = $tpls[$tplId];
        $lead = $t['lead'];
        $ts = time();
        foreach ($t['sections'] as $i => $sec) {
            $cls = 'section' . ($sec[0] === 'gray' ? ' section-alt' : '') . ($sec[0] === 'dark' ? ' section-dark' : '');
            $sid = 'sec-' . $ts . '-' . $i;
            $sectionsHtml .= "\n  <section class=\"" . $cls . "\" id=\"k" . $sid . "\">\n    <div class=\"container\">\n      <div class=\"about-text about-wide\" data-k=\"" . $sid . "\"><!--k:" . $sid . "-->" . $sec[1] . "<!--/k:" . $sid . "--></div>\n    </div>\n  </section>\n";
        }
    } else {
        if (trim($clean) === '') return [false, 'Текст страницы пуст'];
        $sid = 'sec-' . time();
        $sectionsHtml = "\n  <section class=\"section\" id=\"k" . $sid . "\">\n    <div class=\"container\">\n      <div class=\"about-text about-wide\" data-k=\"" . $sid . "\"><!--k:" . $sid . "-->" . $clean . "<!--/k:" . $sid . "--></div>\n    </div>\n  </section>\n";
        $lead = '';
    }
    $html = strtr($tpl, [
        '{{TITLE}}' => k_h($title . ' - ООО "Индустриальные ТехМасла"'),
        '{{DESC}}' => k_h($title . '. ООО "Индустриальные ТехМасла", Москва: поставка оборудования и товаров для промышленности.'),
        '{{CRUMB}}' => k_h($title),
        '{{H1}}' => $ht,
        '{{LEAD}}' => $lead,
        '{{CONTENT}}' => $clean,
        '<!--SECTIONS-->' => $sectionsHtml
    ]);
    if (file_put_contents($root . '/' . $slug . '.html', $html, LOCK_EX) === false) return [false, 'Не удалось записать файл'];
    $li_nav = '        <li><a href="' . $slug . '.html">' . $ht . '</a></li>';
    $li_foot = '          <li><a href="' . $slug . '.html">' . $ht . '</a></li>';
    foreach (k_pages_list($root, $admin) as $f) {
        $p = $root . '/' . $f;
        $s = @file_get_contents($p);
        if ($s === false) continue;
        $nav_anchor = '        <li><a href="#contacts">Контакты</a></li>';
        $s = str_replace($nav_anchor, $li_nav . "\n" . $nav_anchor, $s);
        $footer_anchor = '          <li><a href="tovary-dlya-stroitelstva.html">Товары для строительства</a></li>';
        $s = str_replace($footer_anchor, $footer_anchor . "\n" . $li_foot, $s);
        file_put_contents($p, $s, LOCK_EX);
    }
    $sm = $root . '/sitemap.xml';
    if (is_file($sm)) {
        $s = @file_get_contents($sm);
        if ($s !== false && strpos($s, $slug . '.html') === false) {
            $u = "  <url>\n    <loc>" . $site . '/' . $slug . ".html</loc>\n    <lastmod>" . date('Y-m-d') . "</lastmod>\n  </url>\n";
            k_backup($sm);
            file_put_contents($sm, str_replace('</urlset>', $u . '</urlset>', $s), LOCK_EX);
        }
    }
    return [true, ''];
}

function k_del_page($root, $slug, $admin) {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,49}\.html$/', $slug)) return [false, 'Некорректное имя страницы'];
    if ($slug === 'index.html' || $slug === '404.html' || $slug === 'admin-local.html' || $slug === 'page.html' || $slug === $admin) return [false, 'Эту страницу удалить нельзя'];
    $file = $root . '/' . $slug;
    if (!is_file($file)) return [false, 'Страница не найдена'];
    k_backup($file);
    if (!@unlink($file)) return [false, 'Не удалось удалить файл'];
    $li_re = '~^\s*<li><a href="' . $slug . '">.*?</a></li>\n~m';
    foreach (k_pages_list($root, $admin) as $f) {
        $p = $root . '/' . $f;
        $s = @file_get_contents($p);
        if ($s === false) continue;
        $s2 = preg_replace($li_re, '', $s);
        if ($s2 !== $s) file_put_contents($p, $s2, LOCK_EX);
    }
    $sm = $root . '/sitemap.xml';
    if (is_file($sm)) {
        $s = @file_get_contents($sm);
        if ($s !== false) {
            $s2 = preg_replace('~  <url>\n    <loc>[^<]*' . preg_quote($slug, '~') . '</loc>\n    <lastmod>[^<]*</lastmod>\n  </url>\n~', '', $s);
            if ($s2 !== $s) {
                k_backup($sm);
                file_put_contents($sm, $s2, LOCK_EX);
            }
        }
    }
    return [true, ''];
}

function k_move_block($file, $id, $dir) {
    $s = @file_get_contents($file);
    if ($s === false) return false;
    if (!preg_match('/^(sec|block)-[0-9ts-]+$/', $id)) return false;
    preg_match_all('/(\n  <section class="section(?: section-alt| section-dark)?" id="k[0-9ts-]+">\n[\s\S]*?<!--\/k:(sec-[0-9ts-]+|block-[0-9ts-]+)--><\/div>\n    <\/div>\n  <\/section>\n)/', $s, $m, PREG_SET_ORDER);
    if (count($m) < 2) return false;
    $ids = array_map(function ($x) { return $x[2]; }, $m);
    $i = array_search($id, $ids, true);
    if ($i === false) return false;
    if ($dir === 'up' && $i === 0) return false;
    if ($dir === 'down' && $i === count($ids) - 1) return false;
    $swap = $dir === 'up' ? $i - 1 : $i + 1;
    $t = $m[$i][0]; $m[$i][0] = $m[$swap][0]; $m[$swap][0] = $t;
    $spanStart = strpos($s, $m[0][0]);
    $lastStr = $m[count($m) - 1][0];
    $spanEnd = strpos($s, $lastStr) + strlen($lastStr);
    if ($spanStart === false || $spanEnd === false) return false;
    $parts = array_map(function ($x) { return $x[0]; }, $m);
    $newspan = implode('', $parts);
    $out = substr($s, 0, $spanStart) . $newspan . substr($s, $spanEnd);
    k_backup($file);
    return file_put_contents($file, $out, LOCK_EX) !== false;
}

function k_upload_img($imgdir, $imgurl, $f, $max) {
    if ($f === null || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) return [false, 'Ошибка загрузки файла'];
    if ($f['size'] > $max) return [false, 'Файл больше 4 МБ — сожмите фото и попробуйте снова'];
    $ext = strtolower(pathinfo((string)(isset($f['name']) ? $f['name'] : ''), PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, ['jpg', 'png', 'webp', 'gif', 'avif', 'svg'], true)) return [false, 'Разрешены: jpg, png, webp, gif, avif, svg'];
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $f['tmp_name']);
        finfo_close($fi);
    }
    $okm = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/svg+xml'];
    if ($mime !== '' && !in_array($mime, $okm, true)) return [false, 'Это не изображение'];
    $name = 'k-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $imgdir . '/' . $name)) return [false, 'Не удалось сохранить файл'];
    if ($ext === 'svg') {
        $p = $imgdir . '/' . $name;
        $c = @file_get_contents($p);
        if ($c !== false) {
            $c = preg_replace('/<script[\s\S]*?<\/script>/i', '', $c);
            $c = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $c);
            file_put_contents($p, $c, LOCK_EX);
        }
    }
    return [true, $imgurl . '/' . $name];
}

function k_img_name_ok($n) {
    return (bool)preg_match('/^k-[A-Za-z0-9.-]+\.(jpg|jpeg|png|webp|gif|avif|svg)$/', $n);
}

function k_restore($bkdir, $root, $bname) {
    if (!preg_match('/^[A-Za-z0-9._-]+\.bak$/', $bname)) return false;
    $real = realpath($bkdir);
    $path = realpath($bkdir . '/' . $bname);
    if ($real === false || $path === false || strpos($path, $real) !== 0) return false;
    if (!preg_match('/^(.+)\.(\d{8}-\d{6}-[a-f0-9]{6})\.bak$/', $bname, $m)) return false;
    $orig = basename($m[1]);
    $target = $root . '/' . $orig;
    if (!is_file($path)) return false;
    k_backup($target);
    return @copy($path, $target) && @chmod($target, 0644);
}