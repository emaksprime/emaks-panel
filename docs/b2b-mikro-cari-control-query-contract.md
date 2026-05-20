# B2B Mikro Cari Control Query Contract

## Purpose

B2B Partner Directory must not guess Mikro customer tables or columns. This contract defines the SELECT-only discovery and candidate result shape needed before a live cari datasource can be approved.

## Hard Rules

- Run only SELECT statements.
- Do not run INSERT, UPDATE, DELETE, DROP, TRUNCATE, ALTER, MERGE, EXEC write procedures, or temp table writes.
- Do not create or update Mikro cari records.
- Do not create a new `panel.data_sources` or `query_template` entry from this task.
- Run discovery through the existing n8n MSSQL access path and bring back the result for review.
- Operator approval is required before creating/updating B2B partner records.
- MSSQL tarafinda INSERT/UPDATE/DELETE/DROP/TRUNCATE yoktur.
- Aday verisi gelmeden partner olusturma veya guncelleme yapilmaz.

## Discovery Queries

```sql
-- 1) Find cari-like tables.
SELECT TABLE_SCHEMA, TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_NAME LIKE '%CARI%'
ORDER BY TABLE_SCHEMA, TABLE_NAME;
```

```sql
-- 2) Find columns for cari-like tables.
SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME LIKE '%CARI%'
ORDER BY TABLE_SCHEMA, TABLE_NAME, ORDINAL_POSITION;
```

```sql
-- 3) Verify the expected Mikro cari table if present.
SELECT TOP 20
    cari_kod,
    cari_unvan1,
    cari_unvan2,
    cari_grup_kodu,
    cari_temsilci_kodu,
    cari_srm_merkezi,
    cari_CepTel,
    cari_EMail,
    cari_il,
    cari_ilce
FROM CARI_HESAPLAR
ORDER BY cari_kod;
```

```sql
-- 4) Search a specific cari code or title. Replace only the parameter value.
SELECT TOP 50
    cari_kod,
    cari_unvan1,
    cari_unvan2,
    cari_grup_kodu,
    cari_temsilci_kodu,
    cari_srm_merkezi,
    cari_CepTel,
    cari_EMail,
    cari_il,
    cari_ilce
FROM CARI_HESAPLAR
WHERE cari_kod LIKE N'%{{search}}%'
   OR cari_unvan1 LIKE N'%{{search}}%'
   OR cari_unvan2 LIKE N'%{{search}}%'
ORDER BY cari_kod;
```

## Candidate Output Contract

The approved datasource or n8n response must return rows normalized to this shape:

```json
{
  "mikro_cari_kodu": "320.001",
  "display_name": "Partner visible name",
  "mikro_cari_unvan": "Mikro title",
  "cari_grup_kodu": "BAYI",
  "responsibility_code": "SRM",
  "phone": "+905551112233",
  "email": "partner@example.com",
  "city": "Istanbul",
  "district": "Kadikoy",
  "suggested_capabilities": ["dealer"],
  "status": "new|changed|matched|review_required",
  "status_label": "Yeni|Guncellenecek|Esleşti|Kontrol gerekli"
}
```

## Capability Decision

Capability detection is advisory:

- `dealer`
- `locksmith`
- `dealer + locksmith`
- `unknown`

If Mikro fields do not make the role certain, return `unknown` or `review_required`. The operator must choose the final capability before importing.

## B2B Import Rules

- If `mikro_cari_kodu` does not exist in active B2B partners, create one partner only after operator selection.
- If `mikro_cari_kodu` already exists, update the existing partner snapshot or add the selected capability.
- Never create a second active partner for the same Mikro cari code.
- Never create a Mikro cari record.
- Never create a duplicate technical service technician.
