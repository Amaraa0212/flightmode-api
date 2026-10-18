# Issue reports (Асуудлын мэдэгдэл)

Хэрэглэгч back office / DCS / agency портал дээрх "?" тусламжийн самбараас тухайн хуудсанд гарсан асуудлыг мэдэгддэг. Энэ баримт тухайн мэдэгдлийг илгээх, жагсаах, шийдвэрлэх API-г тайлбарлана.

## Хүснэгт `issue_reports`

| Багана | Тайлбар |
|---|---|
| `user_id`, `airline_id`, `agency_id` | Илгээсэн хэрэглэгч ба түүний airline / agency (илгээх үед автоматаар бөглөгдөнө) |
| `page_path`, `page_title` | Асуудал гарсан хуудас (`/operations/flights` г.м.) |
| `message` | Хэрэглэгчийн бичсэн тайлбар |
| `user_agent` | Хөтчийн мэдээлэл |
| `status` | `open` · `in_progress` · `resolved` · `rejected` |
| `resolution_note` | Шийдвэрлэсэн тэмдэглэл |
| `resolved_by`, `resolved_at` | Хаасан (`resolved` / `rejected`) хэрэглэгч ба огноо; дахин нээхэд цэвэрлэгдэнө |

Migration: `2026_10_13_100100_create_issue_reports_table.php`, `2026_10_18_100000_add_resolution_to_issue_reports.php`.

## Endpoint-ууд

| Method | URI | Эрх | Тайлбар |
|---|---|---|---|
| POST | `/api/v1/issue-reports` | нэвтэрсэн дурын хэрэглэгч (минутад 10) | Мэдэгдэл илгээх |
| GET | `/api/v1/admin/issue-reports` | `issue_reports.view` | Жагсаалт |
| GET | `/api/v1/admin/issue-reports/{id}` | `issue_reports.view` | Дэлгэрэнгүй |
| PATCH | `/api/v1/admin/issue-reports/{id}` | `issue_reports.manage` | Статус / тэмдэглэл өөрчлөх |

### GET `/api/v1/admin/issue-reports`

Шинэ нь эхэндээ (`id desc`), стандарт Laravel pagination (`data`, `links`, `meta`).

| Параметр | Тайлбар |
|---|---|
| `page`, `per_page` | Хуудаслалт, `per_page` анхдагч 50 |
| `search` | `message`, `page_path`, `page_title`, илгээгчийн нэр / имэйлээс хайна |
| `status` | `open` · `in_progress` · `resolved` · `rejected` (өөр утга → 422) |
| `airline_id`, `agency_id`, `user_id` | Яг тэнцүү шүүлт |
| `page_path` | Эхлэлээр нь шүүнэ (`/operations` → бүх operations хуудас) |
| `from`, `to` | `created_at` огнооны хүрээ (`to` өдрийн төгсгөл хүртэл) |

Мөр бүр `user`, `airline`, `agency`, `resolved_by` холбоосыг eager load-оор агуулна.

Жишээ:

```
GET /api/v1/admin/issue-reports?status=open&search=seat&from=2026-10-01&per_page=20
```

### PATCH `/api/v1/admin/issue-reports/{id}`

```json
{ "status": "resolved", "resolution_note": "Seat map cache цэвэрлэсэн." }
```

`resolved` / `rejected` болгоход `resolved_by`, `resolved_at` автоматаар бөглөгдөнө, бусад статус руу шилжүүлэхэд цэвэрлэгдэнө. Өөрчлөлт activity log-д `issue_report.updated` нэрээр бичигдэнэ.

## Эрх ба хамрах хүрээ

- `issue_reports.view`, `issue_reports.manage` эрхүүд шинээр нэмэгдсэн, `airline-admin` рольд олгогдоно. Ажиллаж байгаа DB-д:
  ```bash
  php artisan db:seed --class=PermissionSeeder && php artisan db:seed --class=RoleSeeder
  ```
- System admin бүх мэдэгдлийг харна; бусад хэрэглэгч зөвхөн өөрийн airline-ийн (`airline_id`) мэдэгдлийг харна (`AccessScope::airlineOwned`). Өөр airline-ийн мэдэгдлийг show / update хийвэл 403.
- Agency хэрэглэгчийн илгээсэн мэдэгдэлд `airline_id` хоосон байдаг тул одоогоор зөвхөн system admin харна.

## Холбогдох файлууд

- `app/Http/Controllers/Api/V1/Shared/IssueReportController.php` — илгээх
- `app/Http/Controllers/Api/V1/Admin/IssueReportController.php` — жагсаах / харах / шинэчлэх
- `app/Http/Resources/IssueReportResource.php`
- `app/Models/IssueReport.php`
- `tests/Feature/Auth/IssueReportTest.php`, `tests/Feature/Admin/IssueReportListTest.php`
