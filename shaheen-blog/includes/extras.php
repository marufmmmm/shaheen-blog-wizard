<?php
/**
 * Per-post extras: reference links (in-text hyperlinks + a "সূত্র" list), download buttons,
 * PDF viewers and video / social post embeds. Each can be placed at the top, after the Nth
 * paragraph, in the middle or at the end. All free (no AI, no credits).
 */
if (!defined('ABSPATH')) {
    exit;
}

/** "top" | "p:N" | "middle" | "end" */
function sb_clean_pos($pos, $n = 1) {
    $pos = (string) $pos;
    if ($pos === 'p') {
        return 'p:' . max(1, min(60, (int) $n));
    }
    if (preg_match('/^p:(\d{1,2})$/', $pos, $m)) {
        return 'p:' . max(1, (int) $m[1]);
    }
    return in_array($pos, array('top', 'middle', 'end'), true) ? $pos : 'end';
}

function sb_clean_extras($raw) {
    $raw = is_array($raw) ? $raw : array();
    $out = array('refs' => array(), 'dls' => array(), 'pdfs' => array(), 'embeds' => array(), 'social_pos' => '', 'social_ids' => null);
    foreach (array_slice(isset($raw['refs']) && is_array($raw['refs']) ? $raw['refs'] : array(), 0, 20) as $r) {
        $url = esc_url_raw(trim(isset($r['url']) ? $r['url'] : ''), array('http', 'https'));
        $label = sanitize_text_field(isset($r['label']) ? $r['label'] : '');
        $anchor = sanitize_text_field(isset($r['anchor']) ? $r['anchor'] : '');
        if ($url && ($label !== '' || $anchor !== '')) {
            $out['refs'][] = array('anchor' => $anchor, 'label' => $label !== '' ? $label : $anchor, 'url' => $url);
        }
    }
    foreach (array('dls' => 12, 'pdfs' => 6, 'embeds' => 10) as $key => $max) {
        foreach (array_slice(isset($raw[$key]) && is_array($raw[$key]) ? $raw[$key] : array(), 0, $max) as $r) {
            if ($key === 'embeds') {
                // The pasted link or embed code is kept as text only; it is never printed as HTML (see sb_embed_parse).
                $url = trim(wp_check_invalid_utf8(isset($r['url']) ? (string) $r['url'] : ''));
                $url = mb_substr($url, 0, 4000);
                if ($url !== '' && !sb_embed_parse($url)) {
                    $url = '';
                }
            } else {
                $url = esc_url_raw(trim(isset($r['url']) ? $r['url'] : ''), array('http', 'https'));
            }
            if (!$url) {
                continue;
            }
            $out[$key][] = array(
                'label' => sanitize_text_field(isset($r['label']) ? $r['label'] : ''),
                'url'   => $url,
                'pos'   => sb_clean_pos(isset($r['pos']) ? $r['pos'] : 'end', isset($r['n']) ? $r['n'] : 1),
            );
        }
    }
    $sp = isset($raw['social_pos']) ? (string) $raw['social_pos'] : '';
    $out['social_pos'] = ($sp === '' || $sp === 'off') ? $sp : sb_clean_pos($sp, isset($raw['social_n']) ? $raw['social_n'] : 1);
    if (isset($raw['social_ids']) && is_array($raw['social_ids'])) {
        $ids = array_values(array_filter(array_map('sanitize_key', $raw['social_ids'])));
        $out['social_ids'] = $ids ? $ids : null;
    }
    return $out;
}

function sb_get_extras($post_id) {
    $e = get_post_meta($post_id, '_sb_extras', true);
    return sb_clean_extras(is_array($e) ? $e : array());
}

/* --------------------------------------------------------------- rendering */
/** Turn the first plain-text occurrence of each anchor into a hyperlink (never inside links, headings or scripts). */
function sb_link_refs($html, array $refs) {
    foreach ($refs as $r) {
        if ($r['anchor'] === '') {
            continue;
        }
        $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $skip = 0;
        $done = false;
        foreach ($parts as $i => $seg) {
            if ($seg !== '' && $seg[0] === '<') {
                if (preg_match('#^<(a|script|style|h[1-6]|button)\b#i', $seg)) {
                    $skip++;
                } elseif (preg_match('#^</(a|script|style|h[1-6]|button)>#i', $seg)) {
                    $skip = max(0, $skip - 1);
                }
                continue;
            }
            if ($done || $skip) {
                continue;
            }
            $pos = mb_strpos($seg, $r['anchor']);
            if ($pos !== false) {
                $parts[$i] = mb_substr($seg, 0, $pos)
                    . '<a class="sb-ref-link" href="' . esc_url($r['url']) . '" target="_blank" rel="noopener nofollow">' . esc_html($r['anchor']) . '</a>'
                    . mb_substr($seg, $pos + mb_strlen($r['anchor']));
                $done = true;
            }
        }
        $html = implode('', $parts);
    }
    return $html;
}

function sb_refs_list_html(array $refs) {
    if (!$refs) {
        return '';
    }
    $h = '<section class="sb-refs"><h2>📎 সূত্র / রেফারেন্স</h2><ul>';
    foreach ($refs as $r) {
        $host = preg_replace('/^www\./', '', (string) wp_parse_url($r['url'], PHP_URL_HOST));
        $h .= '<li><a href="' . esc_url($r['url']) . '" target="_blank" rel="noopener nofollow">' . esc_html($r['label']) . '</a> <small>(' . esc_html($host) . ')</small></li>';
    }
    return $h . '</ul></section>';
}

/** Hosts whose iframes may be embedded. */
function sb_embed_host_ok($url) {
    $h = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $h = preg_replace('/^(www|m|web)\./', '', $h);
    return in_array($h, array('facebook.com', 'youtube.com', 'youtube-nocookie.com', 'instagram.com'), true);
}

/**
 * Understand what the owner pasted: a link, or an embed code from Facebook (Page plugin / Like / Post / Video),
 * YouTube (iframe) or Instagram (blockquote). We never print the pasted code itself; we only read the address
 * out of it and build our own iframe, so no foreign scripts or HTML can get into a post.
 * Returns array('src' =>, 'h' =>, 'kind' =>) or array('link' => url) or null.
 */
function sb_embed_parse($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $attr = function ($tag, $name) {
        return preg_match('/\b' . $name . '\s*=\s*["\']([^"\']*)["\']/i', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
    };
    if (strpos($raw, '<') !== false) {
        // 1) <iframe src="…">
        if (preg_match('/<iframe\b[^>]*>/i', $raw, $m)) {
            $src = $attr($m[0], 'src');
            if (strpos($src, '//') === 0) {
                $src = 'https:' . $src;
            }
            $src = esc_url_raw($src, array('https'));
            if ($src && sb_embed_host_ok($src)) {
                $h = (int) $attr($m[0], 'height');
                $w = (int) $attr($m[0], 'width');
                return array('src' => $src, 'h' => ($h >= 100 && $h <= 1400) ? $h : 0, 'w' => $w, 'kind' => sb_social_host($src));
            }
            return null;
        }
        // 2) Instagram blockquote
        if (preg_match('/data-instgrm-permalink\s*=\s*["\']([^"\']+)["\']/i', $raw, $m) && preg_match('#instagram\.com/(p|reel|tv)/([\w-]+)#', $m[1], $q)) {
            return array('src' => 'https://www.instagram.com/' . $q[1] . '/' . $q[2] . '/embed/', 'h' => 620, 'w' => 0, 'kind' => 'ig');
        }
        // 3) Facebook SDK divs: fb-page / fb-like / fb-post / fb-video
        if (preg_match('/<div\b[^>]*class\s*=\s*["\'][^"\']*\bfb-(page|like|post|video|comment-embed)\b[^"\']*["\'][^>]*>/i', $raw, $m)) {
            $href = esc_url_raw($attr($m[0], 'data-href'), array('https', 'http'));
            if ($href && sb_social_host($href) === 'fb') {
                $type = $m[1];
                $h = (int) $attr($m[0], 'data-height');
                $w = (int) $attr($m[0], 'data-width');
                $w = ($w >= 180 && $w <= 500) ? $w : 500;
                if ($type === 'page') {
                    $h = ($h >= 130 && $h <= 1000) ? $h : 500;
                    $tabs = preg_replace('/[^a-z,]/', '', strtolower($attr($m[0], 'data-tabs') ?: 'timeline'));
                    $src = 'https://www.facebook.com/plugins/page.php?href=' . rawurlencode($href) . '&tabs=' . rawurlencode($tabs) . '&width=' . $w . '&height=' . $h
                        . '&small_header=' . ($attr($m[0], 'data-small-header') === 'true' ? 'true' : 'false') . '&adapt_container_width=true&hide_cover=' . ($attr($m[0], 'data-hide-cover') === 'true' ? 'true' : 'false')
                        . '&show_facepile=' . ($attr($m[0], 'data-show-facepile') === 'false' ? 'false' : 'true');
                    return array('src' => $src, 'h' => $h, 'w' => 0, 'kind' => 'fb');
                }
                if ($type === 'like') {
                    $layout = preg_replace('/[^a-z_]/', '', strtolower($attr($m[0], 'data-layout') ?: 'button_count'));
                    $src = 'https://www.facebook.com/plugins/like.php?href=' . rawurlencode($href) . '&layout=' . rawurlencode($layout) . '&action=like&show_faces=false&share=true&width=' . $w;
                    return array('src' => $src, 'h' => 50, 'w' => 0, 'kind' => 'fblike');
                }
                $is_video = ($type === 'video');
                $src = 'https://www.facebook.com/plugins/' . ($is_video ? 'video' : 'post') . '.php?href=' . rawurlencode($href) . '&show_text=' . ($is_video ? 'false' : 'true') . '&width=' . $w;
                return array('src' => $src, 'h' => $is_video ? 320 : 560, 'w' => 0, 'kind' => 'fb');
            }
        }
        // 4) any link inside the pasted code
        if (preg_match('#https?://[^\s"\'<>]+#i', $raw, $m)) {
            $raw = html_entity_decode($m[0], ENT_QUOTES);
        } else {
            return null;
        }
    }
    $url = esc_url_raw($raw, array('http', 'https'));
    if ($url === '' || sb_social_host($url) === '') {
        return null;
    }
    $kind = sb_social_host($url);
    if ($kind === 'yt' && preg_match('#(?:v=|youtu\.be/|shorts/|embed/|live/)([\w-]{11})#', $url, $m)) {
        return array('src' => 'https://www.youtube-nocookie.com/embed/' . $m[1], 'h' => 0, 'w' => 0, 'kind' => 'yt');
    }
    if ($kind === 'fb') {
        // A Facebook *page* address gets the Page plugin (timeline + like button); a post/video address the matching plugin.
        $is_video = (bool) preg_match('#/videos?/|fb\.watch|/reel/|/watch/#', $url);
        $is_post = (bool) preg_match('#/posts/|/permalink|story_fbid|/photos?/|/share/#', $url);
        if (!$is_video && !$is_post) {
            return array('src' => 'https://www.facebook.com/plugins/page.php?href=' . rawurlencode($url) . '&tabs=timeline&width=500&height=500&small_header=false&adapt_container_width=true&hide_cover=false&show_facepile=true', 'h' => 500, 'w' => 0, 'kind' => 'fb');
        }
        return array('src' => 'https://www.facebook.com/plugins/' . ($is_video ? 'video' : 'post') . '.php?href=' . rawurlencode($url) . '&show_text=' . ($is_video ? 'false' : 'true') . '&width=500',
            'h' => $is_video ? 320 : 560, 'w' => 0, 'kind' => 'fb');
    }
    if ($kind === 'ig' && preg_match('#instagram\.com/(p|reel|tv)/([\w-]+)#', $url, $m)) {
        return array('src' => 'https://www.instagram.com/' . $m[1] . '/' . $m[2] . '/embed/', 'h' => 620, 'w' => 0, 'kind' => 'ig');
    }
    return array('link' => $url);
}

function sb_embed_html($raw) {
    $e = sb_embed_parse($raw);
    if (!$e) {
        return '';
    }
    if (isset($e['link'])) {
        return '<p class="sb-embed-link"><a href="' . esc_url($e['link']) . '" target="_blank" rel="noopener">🔗 ' . esc_html($e['link']) . '</a></p>';
    }
    $kind = $e['kind'];
    $cls = $kind === 'yt' ? 'sb-embed--16x9' : ($kind === 'ig' ? 'sb-embed--ig' : ($kind === 'fblike' ? 'sb-embed--like' : 'sb-embed--fb'));
    $style = ($e['h'] && $kind !== 'yt') ? ' style="--sb-h:' . (int) $e['h'] . 'px"' : '';
    return '<div class="sb-embed ' . $cls . '"' . $style . '><iframe src="' . esc_url($e['src']) . '" title="সোশ্যাল মিডিয়া" loading="lazy" scrolling="no" allow="encrypted-media; picture-in-picture; clipboard-write" allowfullscreen></iframe></div>';
}

function sb_pdf_html($url, $title) {
    $title = $title !== '' ? $title : 'PDF ফাইল';
    return '<div class="sb-pdf" data-src="' . esc_url($url) . '"><div class="sb-pdf-bar"><strong>📄 ' . esc_html($title) . '</strong>'
        . '<span><a href="' . esc_url($url) . '" target="_blank" rel="noopener">খুলুন ↗</a> <a href="' . esc_url($url) . '" download>⬇️ ডাউনলোড</a></span></div>'
        . '<div class="sb-pdf-pages" aria-live="polite"><p class="sb-pdf-wait">পিডিএফ লোড হচ্ছে…</p></div>'
        . '<div class="sb-pdf-more"><button type="button" hidden>আরও পাতা দেখুন</button></div></div>';
}

/** Items to place in the body: [[pos, html], ...] */
function sb_extras_items($post_id) {
    $e = sb_get_extras($post_id);
    $items = array();
    foreach ($e['dls'] as $d) {
        $label = $d['label'] !== '' ? $d['label'] : 'ডাউনলোড করুন';
        $items[] = array($d['pos'], '<p class="sb-dl"><a class="sb-dl-btn" href="' . esc_url($d['url']) . '" target="_blank" rel="noopener">⬇️ ' . esc_html($label) . '</a></p>');
    }
    foreach ($e['pdfs'] as $d) {
        $items[] = array($d['pos'], sb_pdf_html($d['url'], $d['label']));
    }
    foreach ($e['embeds'] as $d) {
        $items[] = array($d['pos'], sb_embed_html($d['url']));
    }
    return $items;
}

/** Insert chunks into formatted HTML at top / after Nth paragraph / middle / end. */
function sb_inject_at($html, array $items) {
    if (!$items) {
        return $html;
    }
    preg_match_all('#</p>#i', $html, $m, PREG_OFFSET_CAPTURE);
    $ends = array();
    foreach ($m[0] as $hit) {
        $ends[] = $hit[1] + 4;
    }
    $n = count($ends);
    $top = '';
    $end = '';
    $at = array();
    foreach ($items as $it) {
        list($pos, $chunk) = $it;
        if ($chunk === '') {
            continue;
        }
        if ($pos === 'top') {
            $top .= $chunk;
        } elseif ($pos === 'end' || $n === 0) {
            $end .= $chunk;
        } else {
            $k = $pos === 'middle' ? (int) floor(($n - 1) / 2) : min((int) substr($pos, 2), $n) - 1;
            $off = $ends[max(0, $k)];
            $at[$off] = (isset($at[$off]) ? $at[$off] : '') . $chunk;
        }
    }
    krsort($at);
    foreach ($at as $off => $chunk) {
        $html = substr($html, 0, $off) . $chunk . substr($html, $off);
    }
    return $top . $html . $end;
}

/* ---------------------------------------------------------------- pdf.js */
add_action('wp_enqueue_scripts', function () {
    if (is_singular('sb_post') && sb_get_extras(get_the_ID())['pdfs']) {
        wp_enqueue_script('sb-pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', array(), '3.11.174', true);
        wp_localize_script('sb-pdfjs', 'SBPDF', array('worker' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'));
    }
}, 20);
