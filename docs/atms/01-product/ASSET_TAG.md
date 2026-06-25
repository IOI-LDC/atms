# Asset Tag

Each ATMS-managed asset carries a human-readable, physically printable
**asset tag** — a short code that uniquely identifies the asset in the physical
world. The tag is designed to be printed on a label, read at a glance by field
staff, and encoded into a QR code for mobile scanning.

## Format

```
L - BBB - CCC - XXXX
│    │      │      └─ Serial suffix: last 4 chars of serialNo
│    │      └──────── Size code: encoded inch measurement, or 000 if N/A
│    └─────────────── Type code: 3-letter abbreviation from faSubclassCode
└──────────────────── Ownership: L (LDC-owned) / X (External)
```

Example output:

```
L-MTR-958-0011    LDC-owned Mud Motor, 9 5/8", serial suffix 0011
L-MTR-634-0021    LDC-owned Mud Motor, 6 3/4", serial suffix 0021
L-RTR-800-0011    LDC-owned Rotor, 8", serial suffix 0011
L-JRS-838-0010    LDC-owned Jar, 8 3/8", serial suffix 0010
X-MWD-000-0005    External MWD/LWD tool, no physical size
L-DHT-434-0120    LDC-owned Downhole Tool, 4 3/4", serial suffix 0120
L-MEQ-000-0102    LDC-owned machinery/equipment, no size
```

## Segment Rules

### A — Ownership (1 char)

| Code | Meaning | Maintenance responsibility |
|---|---|---|
| `L` | LDC-owned | LDC is responsible for maintaining this asset |
| `X` | External | Rented, third-party, or client-owned — LDC is NOT responsible |

### BBB — Type code (3 chars)

Derived from the ERP `faSubclassCode`. Admin maintains a mapping table.
Suggested defaults based on ERP data:

| faSubclassCode | Type code | Count |
|---|---|---|
| MUD MOTOR | `MTR` | 197 |
| — | `RTR` | Rotor component (detected by keyword in description) |
| — | `STR` | Stator component (detected by keyword in description) |
| MWD/LWD | `MWD` | 82 |
| DHT | `DHT` | 42 |
| NMDC | `NMD` | 22 |
| MACHEQ | `MEQ` | 18 |
| WHIPSTOCK | `WHP` | 16 |
| JARS | `JRS` | 14 |
| WIRELINE | `WRL` | 12 |
| SHOCK SUBS | `SKS` | 8 |
| FURNOFF | `FUR` | 3 |
| COMPLETION | `CMP` | 3 |
| GYRO | `GYR` | 2 |
| RTM | `RTM` | 2 |
| PROPPLT | `PRP` | 2 |
| VEH | `VEH` | 2 |
| ORGEXP | `ORG` | 2 |
| HOLEOPENER | `HOP` | 1 |
| COMPPER | `CPR` | 1 |

### CCC — Size code (3 chars)

Encoded from the first inch measurement found in the ERP `description` field.

| Description contains | CCC |
|---|---|
| `9 5/8"` | `958` |
| `6 3/4"` | `634` |
| `4 3/4"` | `434` |
| `8"` | `800` |
| `1.25"` or `1 1/4"` | `125` |
| No discernible physical size | `000` |

Encoding: strip spaces and `/`, pad or truncate to 3 chars. If the measurement
uses a decimal (e.g. `1.25"`), encode as `125`. If the size is a whole number
(e.g. `8"`), encode as `800`.

Admin may flag a subclass as "has no physical size" — all assets in that
subclass default CCC to `000` with no extraction attempted.

### XXXX — Serial suffix (4 chars)

> **Format confirmed 2026-06-25:** `L-BBB-CCC-XXXX` with a **dash between CCC and XXXX**.
> Total tag length: max 15 characters. Size codes >3 chars are truncated from the right.

Last 4 characters of the ERP `serialNo`, uppercased. If the serial is shorter
than 4 chars, pad with leading zeros. Characters are alphanumeric only —
special characters in the serial suffix are stripped.

| serialNo | XXXX |
|---|---|
| `M7-962-0011` | `0011` |
| `D153` | `D153` |
| `024` | `0024` |
| `A1` | `00A1` |
| `171496  171477` | `1477` |

91.8% of the current 340 serials produce a unique XXXX. For the 28 collisions,
Admin manually adjusts the tag.

## Rules

1. **Manual generation.** The system suggests a tag on asset create based on
   the rules above. Admin reviews and saves. Manual override is allowed.
2. **Immutable after save.** Once printed on a physical label, changing the tag
   would break the physical-to-digital link. Immutable unless explicitly
   overridden with an audited reason.
3. **Unique.** Database unique constraint. Enforced on create and update.
4. **Searchable.** Primary lookup field for asset identification. Scanning a QR
   code performs a `WHERE asset_tag = ?` query.
5. **Future QR code.** The tag is designed to be the value encoded in a QR
   code. No additional database columns are needed for QR support — generating
   a QR from the tag is a client-side operation.

## Data flow

```
ERP faSubclassCode ──→ Admin mapping table ──→ BBB
ERP serialNo ────────────────────────────────→ XXXX (last 4, zero-padded)
ERP description ──→ Size extraction (inch regex) ──→ CCC (or 000)

System suggests: {L|X}-{BBB}-{CCC}-{XXXX}  (dashes between every segment)
Admin reviews, adjusts if needed, saves.
```
