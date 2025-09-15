<?php

add_action('init', function () {
  add_shortcode('video_posts_live', 'mhd_video_posts_live_cb');
});

function mhd_video_posts_live_cb($atts = []) {
  $atts = shortcode_atts([
    // Back-compat: category_slug still supported if you pass a single slug
    'category_slugs'   => '',
    'category_slug'    => 'videos',
    'category_logic'   => 'or',   // or|and
    'include_children' => 'true', // true|false
    'per_page'         => 9
  ], $atts, 'video_posts_live');

  // Resolve slugs list
  $slugs_raw = trim($atts['category_slugs']) !== '' ? $atts['category_slugs'] : $atts['category_slug'];
  $slugs = array_filter(array_map(function ($s) { return sanitize_title(trim($s)); }, explode(',', $slugs_raw)));

  if (empty($slugs)) {
    return '<p><em>No category slugs provided.</em></p>';
  }

  // Collect root terms from slugs
  $root_terms = [];
  foreach ($slugs as $slug) {
    $t = get_term_by('slug', $slug, 'category');
    if ($t && !is_wp_error($t)) $root_terms[] = $t;
  }
  if (empty($root_terms)) {
    return '<p><em>None of the categories were found: '.esc_html(implode(', ', $slugs)).'</em></p>';
  }

  $include_children = filter_var($atts['include_children'], FILTER_VALIDATE_BOOLEAN);
  $root_ids = array_map(fn($t) => (int)$t->term_id, $root_terms);

  // Build children map + flat list
  $children_map = [];
  $all_child_ids = [];
  if ($include_children) {
    foreach ($root_terms as $rt) {
      $kids = get_terms([
        'taxonomy'   => 'category',
        'parent'     => (int)$rt->term_id,
        'hide_empty' => true,
      ]);
      if (!is_wp_error($kids) && $kids) {
        $children_map[$rt->term_id] = $kids;
        foreach ($kids as $k) { $all_child_ids[] = (int)$k->term_id; }
      }
    }
  }

  // Register empty handles so we can add inline assets
  wp_register_script('mhd-video-posts-live', false, [], '1.1', true);
  wp_register_style('mhd-video-posts-live', false, [], '1.1');


	// Build children map (IDs only) for quick lookup in JS
	$children_map_ids = [];
	foreach ($children_map as $parent_id => $kids) {
	  $children_map_ids[(int) $parent_id] = array_map(fn($k) => (int) $k->term_id, $kids);
	}

	// Expose config to JS
	wp_localize_script('mhd-video-posts-live', 'MHD_VIDEOS', [
	  'rest'             => esc_url_raw(get_rest_url(null, 'wp/v2/posts')),
	  'rootIds'          => $root_ids,
	  'childIds'         => $all_child_ids,         // flat list (for "All")
	  'childrenByParent' => $children_map_ids,      // map: parentId => [childIds...]
	  'perPage'          => (int) $atts['per_page'],
	  'logic'            => strtolower($atts['category_logic']) === 'and' ? 'and' : 'or',
	  'includeKids'      => $include_children,
	]);


  // Inline JS (multi-category aware + AND/OR)
  $inline_js = <<<'JS'
(function () {
  function setup(container) {
    const search   = container.querySelector('.mhd-video-search');
    const subcat   = container.querySelector('.mhd-video-subcat');
    const results  = container.querySelector('.mhd-video-results');
    const loadMore = container.querySelector('.mhd-video-loadmore');
    const chips    = container.querySelectorAll('.mhd-chip'); // ✅ now has container

    let page = 1;
    let q = '';
    let currentCat  = 'all'; // subcategory selection
    let currentRoot = 'all'; // chip selection

    const childMap = MHD_VIDEOS.childrenByParent || {};

    // Build the list of category IDs to query for the current state
    const categoryList = () => {
      // If a subcategory is selected, it takes precedence
      if (subcat && currentCat !== 'all') return [parseInt(currentCat, 10)];

      // If a root chip is selected, use that root (+ its children if allowed)
      if (currentRoot !== 'all') {
        const root = parseInt(currentRoot, 10);
        const ids = [root];
        if (MHD_VIDEOS.includeKids && childMap[currentRoot]) {
          ids.push(...childMap[currentRoot].map(n => parseInt(n, 10)));
        }
        return Array.from(new Set(ids)).filter(Number.isFinite);
      }

      // Default "All": all roots (+ all children if allowed)
      const ids = Array.isArray(MHD_VIDEOS.rootIds) ? MHD_VIDEOS.rootIds.map(n => parseInt(n, 10)) : [];
      if (MHD_VIDEOS.includeKids && Array.isArray(MHD_VIDEOS.childIds)) {
        ids.push(...MHD_VIDEOS.childIds.map(n => parseInt(n, 10)));
      }
      return Array.from(new Set(ids)).filter(Number.isFinite);
    };

    const buildUrl = (pageNum) => {
      const ids = categoryList();
      const params = new URLSearchParams();

      if (MHD_VIDEOS.logic === 'and') {
        if (ids.length) params.set('cat_and', ids.join(',')); // requires your PHP rest_post_query hook
      } else {
        // OR: single CSV value (important!)
        if (ids.length) params.set('categories', ids.join(','));
      }

      if (q.trim()) params.set('search', q);
      params.set('per_page', MHD_VIDEOS.perPage || 9);
      params.set('page', pageNum);
      params.set('_embed', '1');
      return MHD_VIDEOS.rest + '?' + params.toString();
    };

    const render = (posts, append = false) => {
      const frag = document.createDocumentFragment();
      posts.forEach(p => {
        const card = document.createElement('article');
        card.className = 'mhd-card';

        const title = p.title?.rendered || '';
        const date  = new Date(p.date).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
        const excerpt = p.excerpt?.rendered || '';
        const link  = p.link;

        let thumb = '';
        const media = p._embedded?.['wp:featuredmedia']?.[0];
        const sizes = media?.media_details?.sizes;
        if (sizes) thumb = (sizes.medium_large || sizes.medium || sizes.full)?.source_url || '';

        card.innerHTML = `
          ${thumb ? `<a class="mhd-thumb" href="${link}"><img src="${thumb}" alt=""></a>` : ''}
          <div class="mhd-card-body">
            <h3 class="mhd-title"><a href="${link}">${title}</a></h3>
            <time class="mhd-date">${date}</time>
            <div class="mhd-excerpt">${excerpt}</div>
          </div>
        `;
        frag.appendChild(card);
      });

      if (!append) results.innerHTML = '';
      results.appendChild(frag);
    };
	
const setBtnLoading = (state) => {
  if (!loadMore) return;
  if (state) {
    loadMore.dataset.originalText = loadMore.textContent || 'Load more';
    loadMore.textContent = 'Loading...';
    loadMore.disabled = true;
    loadMore.classList.add('is-loading');
    loadMore.setAttribute('aria-busy', 'true');
  } else {
    loadMore.textContent = loadMore.dataset.originalText || 'Load more';
    loadMore.disabled = false;
    loadMore.classList.remove('is-loading');
    loadMore.removeAttribute('aria-busy');
  }
};


const load = async (reset = false) => {
  if (reset) {
    page = 1;
    results.innerHTML = '<p class="mhd-loading">Loading…</p>';
  } else {
    setBtnLoading(true); // show "Loading..." on the button
  }

  try {
    const res = await fetch(buildUrl(page));
    const totalPages = parseInt(res.headers.get('X-WP-TotalPages') || '1', 10);
    const data = await res.json();

    if (reset && data.length === 0) {
      results.innerHTML = '<p class="mhd-empty">No posts found.</p>';
    } else {
      render(data, !reset);
    }

    // show/hide button
    loadMore.style.display = (page < totalPages) ? '' : 'none';
  } catch (e) {
    if (reset) {
      results.innerHTML = '<p class="mhd-error">Something went wrong. Please try again.</p>';
    }
    loadMore.style.display = 'none';
  } finally {
    if (!reset) setBtnLoading(false); // restore button after append
  }
};

    const debounce = (fn, d) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn.apply(null, args), d); }; };
    const searchNow = debounce(() => load(true), 300);

    if (search) search.addEventListener('input', e => { q = e.target.value; searchNow(); });
    if (subcat) subcat.addEventListener('change', e => { currentCat = e.target.value; searchNow(); });
    if (loadMore) loadMore.addEventListener('click', () => { page++; load(false); });

    // chip clicks
    chips.forEach(btn => {
      btn.addEventListener('click', () => {
        chips.forEach(b => { b.classList.remove('is-active'); b.setAttribute('aria-pressed', 'false'); });
        btn.classList.add('is-active');
        btn.setAttribute('aria-pressed', 'true');

        currentRoot = btn.dataset.root || 'all';
        if (subcat) { subcat.value = 'all'; currentCat = 'all'; }

        load(true);
      });
    });

    // initial
    load(true);
  }

  // init after defining setup
  document.querySelectorAll('.mhd-video-posts').forEach(setup);
})();
JS;

  // Inline CSS (unchanged)
  $inline_css = <<<'CSS'
.mhd-video-toolbar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:1rem}
.mhd-video-search,.mhd-video-subcat{padding:.55rem .7rem;border:1px solid #e5e7eb;border-radius:.5rem}
.mhd-video-results{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:25px}
.mhd-card{border:1px solid #e5e7eb;border-radius:0px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
.mhd-thumb img{width:100%;height:275px;object-fit:cover;display:block}
.mhd-card-body{padding:22px 24px;background-color:#eeeeee;}
.mhd-title{margin:0 0 8px;font-size:18px;font-weight:bold;line-height:1.3}
.mhd-title a{text-decoration:none;color:#444444;}
.mhd-date{display:block;font-size:.85rem;opacity:1;margin-bottom:0rem}
.mhd-loading,.mhd-empty,.mhd-error{text-align:center;padding:1rem;grid-column:1/-1}
.mhd-video-loadmore{margin:1rem auto;display:block;padding:.6rem 1rem;border-radius:0px;border:1px solid var( --e-global-color-primary );background:#f9fafb;cursor:pointer}
.mhd-video-loadmore:hover{background:#000000}
.mhd-excerpt{display: none;}
.mhd-video-termchips{display:flex;gap:.5rem;flex-wrap:wrap;margin:.25rem 0 1rem}
.mhd-chip{padding:.5rem .7rem;border:1px solid #e5e7eb;border-radius:0;background:#fff;cursor:pointer;font-size:15px;font-weight:500;line-height:1}
.mhd-chip.is-active{background:#111827;color:#fff;border-color:#111827}
.mhd-video-termchips button:hover {background-color:#000 !important;}
.mhd-chip:focus{outline:2px solid #111827;outline-offset:2px}
.mhd-video-loadmore[disabled]{opacity:.6;cursor:progress}
.mhd-video-loadmore.is-loading{position:relative}
.mhd-video-loadmore.is-loading::after{
  content:"";
  display:inline-block;
  width:1em;height:1em;
  border:2px solid currentColor;border-right-color:transparent;border-radius:50%;
  margin-left:.5em;vertical-align:-0.125em;
  animation:mhd-spin .8s linear infinite
}
@keyframes mhd-spin{to{transform:rotate(360deg)}}
CSS;

  // Enqueue + inject
  wp_enqueue_script('mhd-video-posts-live');
  wp_add_inline_script('mhd-video-posts-live', $inline_js, 'after');

  wp_enqueue_style('mhd-video-posts-live');
  wp_add_inline_style('mhd-video-posts-live', $inline_css);

  // Build a merged subcategory dropdown (children of each root category)
  ob_start(); ?>
  <div class="mhd-video-posts">
	  <div class="mhd-video-toolbar">
		  <input type="search" class="mhd-video-search" placeholder="Search…" />
		  <?php if ($include_children && !empty($children_map)) : ?>
		  <select class="mhd-video-subcat" aria-label="Filter by subcategory">
			  <option value="all">All subcategories</option>
			  <?php foreach ($root_terms as $rt):
	$kids = $children_map[$rt->term_id] ?? [];
	if (!$kids) continue; ?>
			  <optgroup label="<?php echo esc_attr($rt->name); ?>">
				  <?php foreach ($kids as $child) : ?>
				  <option value="<?php echo esc_attr($child->term_id); ?>">
					  <?php echo esc_html($child->name); ?>
				  </option>
				  <?php endforeach; ?>
			  </optgroup>
			  <?php endforeach; ?>
		  </select>
		  <?php endif; ?>
	  </div>

	<!-- 👉 NEW: clickable chips -->
	<div class="mhd-video-termchips" role="group" aria-label="Filter by category">
	  <button type="button" class="mhd-chip is-active" data-root="all" aria-pressed="true">All</button>
	  <?php foreach ($root_terms as $rt): ?>
		<button type="button"
				class="mhd-chip"
				data-root="<?php echo esc_attr($rt->term_id); ?>"
				aria-pressed="false">
		  <?php echo esc_html($rt->name); ?>
		</button>
	  <?php endforeach; ?>
	</div>
	  
    <div class="mhd-video-results" data-page="1" aria-live="polite"></div>
    <button class="mhd-video-loadmore" style="display:none;">Load more</button>
    <noscript><p>Please enable JavaScript to search/filter posts.</p></noscript>
  </div>
  <?php
  return ob_get_clean();
}
