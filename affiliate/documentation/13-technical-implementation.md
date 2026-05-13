# 13. Technical Implementation (for Developers)

## Database Fields per Affiliate

| Field | Description |
|-------|-------------|
| `base_percentage` | 20 |
| `education_bonus` | 0–3.5 |
| `community_bonus` | 0–5 |
| `special_bonus` | 0–1.5 |
| `inactivity_penalty` | 0–5 |
| `responsibility_score` | 0–100 |
| `has_directs` | bool |
| `weekly_support_reports` | list |
| `monthly_quiz_completed` | bool |
| `monthly_update_completed` | bool |
| `monthly_growth_counter` | max 0.5 |
| `directs_count` | int |
| `last_activity_timestamp` | timestamp |

---

## Percentage Activation Logic

```
if has_directs:
    active_percentage = base + education + community + special - penalty
else:
    active_percentage = base
```

---

## Monthly Growth Logic

```
if weekly_support_reports >= 1 AND monthly_growth_counter < 0.5:
    community_bonus += 0.5
```

---

## Inactivity Logic

```
if NOT (quiz OR support OR update):
    inactivity_penalty += 1   // max -5%
```
