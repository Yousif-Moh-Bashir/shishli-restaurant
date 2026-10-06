# Website CMS API

The four stable page keys are `home`, `about`, `contact`, and `gallery`.
All endpoints use the existing v1 API envelope. Public IDs and route parameters are UUIDs.
The static frontend directory in this checkout is empty: this module supplies APIs and mapping, not HTML pages.

## Setup and publication

```sh
php artisan migrate --no-interaction
php artisan db:seed --class=CmsPageSeeder --no-interaction
php artisan db:seed --class=RolePermissionSeeder --no-interaction
```

The seeder creates missing pages/sections without replacing editor changes. Initial pages are **drafts** with basic page headings; no invented history, phone numbers, pictures, branch schedules, or product details are seeded.
Publish through the admin PATCH endpoint with `status: published`. A nullable `published_at` means immediately published; a future date schedules publication. Use ISO 8601 dates with explicit offsets; database dates are UTC.
Public reads exclude drafts, future publication, deleted records, inactive sections, and sections outside the registry.

## Public endpoints

| Method | Endpoint | Behavior |
|---|---|---|
| GET | /api/v1/home?branch={uuid} | CMS page, hero/secondary banners, featured products, offers, gallery preview, settings, branches, hours, live branch status |
| GET | /api/v1/pages/{pageKey} | Published editorial content and resolved SEO |
| GET | /api/v1/banners?placement=home_hero | Active scheduled banners, ordered by sort order |
| GET | /api/v1/gallery?page=1&per_page=24 | Active published images; default 24, maximum 100 |

Known placements: `home_hero`, `home_secondary`. Banner boundaries are inclusive: start ≤ now ≤ end; absent bounds are unbounded.
Gallery images with null `published_at` are unpublished. Set a past/current timestamp to publish or a future timestamp to schedule.
Without branch context Home returns an empty `featured_products` array; it does not select an arbitrary branch or invent a price.
Products use the existing featured flag, MenuService and MenuProductResource. Offers use the same `Promotion::publicOffers` scope as the public Promotions API; displaying an offer does not guarantee checkout eligibility.
Contact content stays editorial. Fetch settings/branches/hours using their existing endpoints instead of copying their fields into CMS.

## Admin endpoints and permissions

All require Sanctum authentication and the corresponding permission.

| Resource | Endpoints | Permission |
|---|---|---|
| Pages | GET /admin/pages; GET /admin/pages/{page} | cms.pages.view |
| Pages | PATCH /admin/pages/{page} | cms.pages.update |
| Sections | GET /admin/pages/{page}/sections | cms.pages.view |
| Sections | PATCH /admin/pages/{page}/sections/{section} | cms.pages.update |
| Banners | GET list/detail, POST, PATCH, DELETE under /admin/banners | cms.banners.view/create/update/delete |
| Gallery | GET list/detail, POST, PATCH, DELETE under /admin/gallery | cms.gallery.view/create/update/delete |
| Reorder | PATCH /admin/banners/reorder; PATCH /admin/gallery/reorder | corresponding update permission |

Prefix every admin path above with `/api/v1`. Reorder payload:

```json
{"items":[{"id":"UUID","sort_order":1}]}
```

Reorder validates existing non-deleted distinct UUIDs and applies atomically. Detail routes precede no reorder route shadowing. Sections bind within their parent page.
There are no arbitrary page/section creation endpoints. Page `key`, section `key`/`type`, UUIDs and actor IDs cannot be changed through the API.
Page slug can change if unique; frontend lookup continues using the stable key.
Ten CMS permissions are granted to super_admin and manager; cashier, kitchen and customer receive none.
Contact Messages and their permissions are conditional on a real frontend form; none exists in this checkout, so that module is not introduced.

## Section registry and structured data

| Page | Known sections |
|---|---|
| home | hero, about_preview, features, featured_menu, offers, gallery_preview, location, cta |
| about | hero, story, values, quality, cta |
| contact | hero, contact_intro, location, working_hours |
| gallery | hero, intro |

Section updates accept nullable `title`, `subtitle`, `content`, structured `data`, `sort_order`, `is_active`.
Hero, CTA, and Image/Text data allow only primary/secondary button text and URL. Button text is limited to 100 characters.
Features/values data allow `items` (maximum 12), each with required `title` and optional `description`.
Featured-menu/offers data allow `limit` (1–24); gallery-preview limit is 1–12, default 6.
Other sections accept empty/null data; contact operational fields, copied product prices, arbitrary component names, HTML, handlers and scripts are rejected.
Sending `data` replaces editorial data, while retaining an existing managed section image unless the image is explicitly cleared.

## Images and SEO

Use the existing MediaService and `filesystems.media_disk`. Upload JPG/JPEG/PNG/WebP images up to 5 MiB. SVG and PDF are rejected. Stored filenames are random UUIDs independent of original names.
For PHP multipart updates, POST with `_method=PATCH` is supported; send nested data as `data[primary_button_url]`, etc. Ordinary editorial updates can use JSON PATCH.

- Banners: required uploaded `image`, optional `mobile_image`; null mobile image clears it and public mobile URL falls back to desktop.
- Gallery: required uploaded `image`; clearing the primary image is rejected. Set `published_at` to publish; a newly uploaded item remains unpublished when that field is absent.
- Pages: optional uploaded `og_image`; null clears it.
- Hero/ImageText sections: optional uploaded `image`; null clears it.

Paths are grouped under `cms/banners/{uuid}`, `cms/gallery/{uuid}`, `cms/pages/{page_uuid}`. Public/admin resources return URLs generated by MediaService, never raw storage attributes.
Upload first, save metadata inside a transaction, clean old files after commit. Failed persistence cleans new files; failed old-file cleanup is reported without rolling back committed content. Banner/gallery deletion soft-deletes metadata, with cleanup after commit. Repeated cleanup failure can leave an orphan for operations to remove; soft deletion does not guarantee that an old public URL remains available.
Public files must be served as static images on the configured disk; retain existing storage/web-server configuration.

SEO: optional meta title ≤100, description ≤320, safe absolute HTTP(S) canonical URL, and uploaded OG image.
Resolved title defaults to page title + current restaurant name from settings. No separate robots, sitemap, or SEO engine is introduced.
Only plain text is accepted. Frontend must assign CMS text via `textContent` (or template escaping), never `innerHTML`.
Button URLs allow rooted internal paths or HTTP(S) URLs without credentials; protocol-relative URLs, scripts, data URLs, encoded bypasses, backslashes and traversal paths are rejected.

## Cache and dynamic data

CMS page and banner candidates cache for 300 seconds under `cms:page:{key}` and `cms:banners:{placement}`; publication windows are checked on every read so scheduling works with warm cache.
Gallery previews use bounded keys `cms:gallery:preview:{limit}` and expire at the next scheduled publication or after 300 seconds.
Model changes invalidate related fragments after commit. Read/fill and invalidation share cache locks to prevent a stale fill racing an edit.
Home responses are not cached: product stock, branch status, settings and offers remain live. Menu relationships and branch hours/closures are loaded in bulk.
Cache infrastructure failures are reported; recovering the store or clearing CMS keys removes any retained stale fragment.

## Existing HTML mapping

| HTML page | API |
|---|---|
| index.html | GET /api/v1/home?branch={uuid} |
| about.html | GET /api/v1/pages/about |
| gallery.html | GET /api/v1/pages/gallery + GET /api/v1/gallery |
| contact.html | GET /api/v1/pages/contact + GET /api/v1/settings/public + GET /api/v1/branches + GET /api/v1/branches/{uuid}/business-hours |
| menu.html | Existing GET /api/v1/menu?branch={uuid} |
| product.html | Existing Product/Menu Product API |
| cart.html | Existing Cart API |
| checkout.html | Existing Checkout API |
| order-success.html | Checkout response/order snapshot |
| track-order.html | Existing Order Tracking API |

Contact form storage, rate limiting and contact-message permissions should be added when an actual contact form is supplied; the conditional form module is not needed for editorial contact content.

## File manifest

New files (39; paths relative to this backend):

| Directory | Files |
|---|---|
| app/Enums | BannerPlacement.php, PageSectionType.php, PageStatus.php |
| app/Models | Page.php, PageSection.php, Banner.php, GalleryItem.php |
| app/Services | CmsSectionDefinition.php, CmsContentService.php, CmsWriteService.php, HomePageService.php |
| app/Rules | PlainText.php, SafeContentUrl.php |
| app/Http/Requests/Api/V1 | CmsRequest.php, CmsPageUpdateRequest.php, CmsSectionUpdateRequest.php, CmsBannerRequest.php, CmsGalleryRequest.php, CmsReorderRequest.php, CmsPublicRequest.php |
| app/Http/Controllers/Api/V1 | CmsController.php |
| app/Http/Controllers/Api/V1/Admin | CmsPageController.php, CmsBannerController.php, CmsGalleryController.php |
| app/Http/Resources/Api/V1 | PageResource.php, PageSectionResource.php, BannerResource.php, GalleryItemResource.php |
| database/factories | PageFactory.php, PageSectionFactory.php, BannerFactory.php, GalleryItemFactory.php |
| database/migrations | 2026_10_03_024009_create_website_cms_tables.php |
| database/seeders | CmsPageSeeder.php |
| tests/Feature | CmsPagesTest.php, CmsSecurityTest.php, CmsMediaTest.php, CmsHomeTest.php |
| Backend root | CMS_API.md |

Existing files changed for this module: app/Models/Promotion.php; app/Http/Controllers/Api/V1/PromotionController.php; database/seeders/DatabaseSeeder.php; database/seeders/RolePermissionSeeder.php; routes/api.php; tests/Feature/RolesAndPermissionsTest.php.
Pint also normalized spacing in two existing tracking test files. The local public/storage junction was repaired to target this backend's storage/app/public directory.
