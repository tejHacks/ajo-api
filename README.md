# Ajo API

Laravel 13 backend for **Ajo**, the Flutter app for running rotating savings groups (ajo / esusu): every member pays a fixed amount each cycle, and one member, in payout order, collects the whole pot.

The app works fully offline and keeps its own copy on the phone. When the user signs in, it syncs every change here. Groups created offline sync later, because group and member ids are made on the phone.

## Run it

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve --host 0.0.0.0 --port 8000   # the app's default server is http://10.0.2.2:8000 on the Android emulator
php artisan test                               # 19 feature tests
```

## API

All routes are under `/api`. Send `Authorization: Bearer <token>` on every route except register, login and health.

| Method | Route | What it does |
|---|---|---|
| GET | `/health` | Health check |
| POST | `/register` | `{name, email, password}` → `{token, user}` |
| POST | `/login` | `{email, password}` → `{token, user}` |
| POST | `/logout` | Revokes the current token |
| GET | `/me` | The signed-in user |
| GET | `/groups` | The user's groups |
| GET | `/groups/{id}` | One group |
| PUT | `/groups/{id}` | Create or replace a whole group (the app's sync). `201` created, `200` updated, `409` with the server copy if the server's copy is newer |
| DELETE | `/groups/{id}` | Delete a group and its records |
| GET | `/groups/{id}/schedule` | Due date, recipient, pot, money collected so far and who still owes, for every cycle. `?at=YYYY-MM-DD` to see it on another day |
| PUT / DELETE | `/groups/{id}/cycles/{n}/payments/{memberId}` | Mark a contribution paid or unpaid |
| PUT / DELETE | `/groups/{id}/cycles/{n}/payout` | Mark a cycle's pot collected or not |

### The group shape

The same JSON the app stores on the phone:

```json
{
  "id": "g-1700000000000",
  "name": "Market women",
  "amount": 5000,
  "frequency": "monthly",
  "start": "2026-01-31T00:00:00.000",
  "members": [{ "id": "m1", "name": "Bisi", "phone": "08030000001" }],
  "paid": { "0": ["m1"] },
  "collected": [0],
  "updatedAt": "2026-11-24T10:00:00.000Z"
}
```

- `members` is in payout order: the member at position *i* collects in cycle *i*.
- `paid` maps a cycle to the members who have paid for it.
- `collected` lists the cycles whose pot has been handed over.
- Monthly due dates fall on the same day each month, clamped to the 28th so every month has it.

### Sync rules

- **Last write wins, by `updatedAt`.** An older copy never overwrites a newer one: the API answers `409` and returns the server's copy for the app to keep.
- **Payment and payout changes made through the API count as the newest version.**
- **Users can only see and change their own groups.** Anyone else's group returns `404`.

## Data model

`users` → `ajo_groups` (string id from the phone) → `ajo_members` (payout `position`), `ajo_contributions` (one per member per cycle), `ajo_payouts` (one per cycle). Deleting a group deletes its records.
