<?php
/**
 * Product catalogue with search, filters, sorting and pagination.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/product_query.php';

$result    = product_search();
$products  = $result['products'];
$filters   = $result['filters'];
$categories = categories_with_counts();

$priceRange = db_one('SELECT MIN(price) AS lo, MAX(price) AS hi FROM products WHERE is_active = 1') ?? ['lo' => 0, 'hi' => 1000];

$activeCount = 0;
foreach (['q' => 'Search', 'category' => 'Category', 'min' => 'Min price', 'max' => 'Max price', 'in_stock' => 'In stock only', 'featured' => 'Deals only'] as $key => $label) {
    if (!empty($filters[$key])) {
        $activeCount++;
    }
}

$page_title    = $filters['q'] !== '' ? 'Search: ' . $filters['q'] : 'Shop groceries';
$page_active   = 'shop';
require __DIR__ . '/includes/header.php';
?>

<div class="fc-container">
  <nav class="fc-crumbs" aria-label="Breadcrumb">
    <a href="index.php">Home</a> <i class="bi bi-chevron-right"></i> <span>Shop</span>
    <?php if ($filters['q'] !== ''): ?>
      <i class="bi bi-chevron-right"></i> <span>Search</span>
    <?php endif; ?>
  </nav>

  <div class="fc-section-head mb-3">
    <div>
      <span class="fc-eyebrow"><i class="bi bi-bag-check"></i> Catalogue</span>
      <h1 class="fc-section-title">
        <?php if ($filters['q'] !== ''): ?>
          Results for &ldquo;<?= h($filters['q']) ?>&rdquo;
        <?php else: ?>
          Shop groceries
        <?php endif; ?>
      </h1>
      <p class="fc-section-sub">
        <?= number_format($result['total']) ?> product<?= $result['total'] === 1 ? '' : 's' ?> available
        <?php if ($result['totalPages'] > 1): ?>
          &middot; page <?= $result['page'] ?> of <?= $result['totalPages'] ?>
        <?php endif; ?>
      </p>
    </div>

    <form class="d-flex gap-2" method="get" action="products.php">
      <?php if ($filters['q'] !== ''): ?>
        <input type="hidden" name="q" value="<?= h($filters['q']) ?>">
      <?php endif; ?>
      <?php if ($filters['category'] !== ''): ?>
        <input type="hidden" name="category" value="<?= h($filters['category']) ?>">
      <?php endif; ?>
      <?php if ($filters['in_stock'] !== ''): ?>
        <input type="hidden" name="in_stock" value="<?= h($filters['in_stock']) ?>">
      <?php endif; ?>
      <?php if ($filters['featured'] !== ''): ?>
        <input type="hidden" name="featured" value="<?= h($filters['featured']) ?>">
      <?php endif; ?>
      <label class="d-none" for="sort">Sort</label>
      <select class="fc-select" name="sort" id="sort" onchange="this.form.submit()">
        <?php
        $sortLabels = [
            'relevance'  => 'Recommended',
            'popular'    => 'Most popular',
            'newest'     => 'Newest first',
            'price_asc'  => 'Price: low to high',
            'price_desc' => 'Price: high to low',
            'rating'     => 'Top rated',
            'name'       => 'Name A-Z',
        ];
        foreach ($sortLabels as $key => $label): ?>
          <option value="<?= h($key) ?>"<?= $filters['sort'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="fc-chip-row mb-3">
    <a class="fc-chip<?= $filters['category'] === '' ? ' active' : '' ?>" href="<?= h(url_with('products.php', ['category' => null, 'page' => null])) ?>">
      All products
    </a>
    <?php foreach ($categories as $cat): ?>
      <a class="fc-chip<?= $filters['category'] === $cat['slug'] ? ' active' : '' ?>"
         href="<?= h(url_with('products.php', ['category' => $cat['slug'], 'page' => null])) ?>">
        <i class="bi bi-<?= h($cat['icon'] ?: 'basket') ?>"></i>
        <?= h($cat['name']) ?>
        <span class="opacity-75"><?= (int) $cat['product_count'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="row g-4">
    <aside class="col-lg-3">
      <form method="get" action="products.php" class="fc-card fc-card-pad fc-sticky-side">
        <?php if ($filters['q'] !== ''): ?>
          <input type="hidden" name="q" value="<?= h($filters['q']) ?>">
        <?php endif; ?>
        <?php if ($filters['category'] !== ''): ?>
          <input type="hidden" name="category" value="<?= h($filters['category']) ?>">
        <?php endif; ?>
        <input type="hidden" name="sort" value="<?= h($filters['sort']) ?>">

        <h4 class="mb-3" style="font-size:.98rem"><i class="bi bi-sliders"></i> Refine</h4>

        <div class="fc-field">
          <label class="fc-label" for="f-min">Price range</label>
          <div class="d-flex gap-2 align-items-center">
            <input class="fc-input" type="number" id="f-min" name="min" min="0" step="10"
                   placeholder="<?= (int) $priceRange['lo'] ?>" value="<?= h((string) $filters['min']) ?>">
            <span class="text-muted">&ndash;</span>
            <input class="fc-input" type="number" name="max" min="0" step="10"
                   placeholder="<?= (int) ceil((float) $priceRange['hi']) ?>" value="<?= h((string) $filters['max']) ?>">
          </div>
          <p class="fc-hint mb-0">Catalog <?= money($priceRange['lo']) ?> &ndash; <?= money($priceRange['hi']) ?></p>
        </div>

        <div class="d-flex flex-column gap-2 mb-3">
          <label class="d-flex align-items-center gap-2" style="font-size:.88rem">
            <input class="form-check-input mt-0" type="checkbox" name="in_stock" value="1"
                   <?= $filters['in_stock'] === '1' ? 'checked' : '' ?> style="width:1.05rem;height:1.05rem">
            In stock only
          </label>
          <label class="d-flex align-items-center gap-2" style="font-size:.88rem">
            <input class="form-check-input mt-0" type="checkbox" name="featured" value="1"
                   <?= $filters['featured'] === '1' ? 'checked' : '' ?> style="width:1.05rem;height:1.05rem">
            On offer only
          </label>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-green btn-sm flex-grow-1" type="submit">Apply</button>
          <?php if ($activeCount > 0): ?>
            <a class="btn btn-ghost btn-sm" href="products.php">Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </aside>

    <div class="col-lg-9">
      <?php if (!$products): ?>
        <div class="fc-card fc-empty">
          <div class="fc-empty-icon"><i class="bi bi-search"></i></div>
          <h3>No products matched</h3>
          <p>Try a different keyword, widen the price range or clear the filters.</p>
          <div class="d-flex gap-2 justify-content-center">
            <a class="btn btn-green" href="products.php">Show all products</a>
            <?php if ($filters['q'] !== ''): ?>
              <a class="btn btn-ghost" href="products.php?category=<?= h($filters['category']) ?>">Browse category</a>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="fc-grid fc-grid-3">
          <?php foreach ($products as $product): require __DIR__ . '/includes/product_card.php'; endforeach; ?>
        </div>

        <?= pagination('products.php', $result['page'], $result['totalPages']) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>